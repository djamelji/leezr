<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentCampaign;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-761 (DMS M4) — pilotage des campagnes documentaires. Réutilise DocumentRequestService :
 * chaque destinataire reçoit une DEMANDE INDIVIDUELLE. Lancement FIABLE : par chunks, idempotent
 * (ré-exécutable), verrouillé (pas de concurrence), reprenable (destinataires pending/failed),
 * sans demande ni notification en double.
 */
class DocumentCampaignService
{
    public function __construct(private readonly DocumentRequestService $requests)
    {
    }

    private const ACTIVE_STATES = [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_IN_REVIEW, DocumentRequest::STATUS_TO_REPLACE];

    /** Prévisualise la population réelle (après déduplication). */
    public function preview(Company $company, array $targeting): array
    {
        $people = CampaignTargetingResolver::resolve($company, $targeting);

        return [
            'total' => $people->count(),
            'account_less' => $people->where('account_less', true)->count(),
            'with_account' => $people->where('account_less', false)->count(),
            'sample' => $people->take(20)->map(fn ($p) => [
                'subject' => (string) $p['subject'],
                'person_ref' => $p['subject']->type.'-'.$p['subject']->id,
                'account_less' => $p['account_less'],
                'department_id' => $p['department_id'],
            ])->values(),
        ];
    }

    /**
     * Crée la campagne (brouillon) et FIGE ses destinataires (snapshot). La population résolue au
     * lancement est conservée — pas d'ajout automatique de salariés ultérieurs.
     */
    public function create(Company $company, User $actor, string $documentCode, array $targeting, array $options = []): DocumentCampaign
    {
        $type = DocumentType::where('code', $documentCode)
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->active()
            ->firstOrFail();

        $people = CampaignTargetingResolver::resolve($company, $targeting);

        $campaign = DocumentCampaign::create([
            'company_id' => $company->id,
            'document_type_id' => $type->id,
            'created_by' => $actor->id,
            'name' => $options['name'] ?? ($type->label.' — '.now()->format('Y-m-d')),
            'message' => $options['message'] ?? null,
            'due_date' => $options['due_date'] ?? null,
            'requires_validation' => (bool) ($options['requires_validation'] ?? true),
            'targeting' => $targeting,
            'reminder_policy' => $options['reminder_policy'] ?? null,
            'status' => DocumentCampaign::STATUS_DRAFT,
            'recipients_total' => $people->count(),
        ]);

        // Snapshot FIGÉ : une ligne par sujet (unicité DB → anti-doublon).
        foreach ($people as $p) {
            $subject = $p['subject'];
            DocumentCampaignRecipient::firstOrCreate(
                [
                    'document_campaign_id' => $campaign->id,
                    'document_subject_type' => $subject->type,
                    'document_subject_id' => $subject->id,
                ],
                ['company_id' => $company->id, 'state' => DocumentCampaignRecipient::STATE_PENDING],
            );
        }

        return $campaign->fresh();
    }

    /**
     * Lance (ou reprend) la campagne : crée une demande individuelle par destinataire non encore
     * traité. VERROUILLÉ, CHUNKÉ, IDEMPOTENT (une demande active existante est liée, pas recréée),
     * REPRENABLE. La campagne n'est « active » que si TOUS les destinataires ont une demande.
     */
    public function launch(DocumentCampaign $campaign, ?User $actor = null): array
    {
        $lock = Cache::lock('documents:campaign:launch:'.$campaign->id, 300);
        if (! $lock->get()) {
            return ['created' => 0, 'failed' => 0, 'locked_out' => true];
        }

        try {
            if ($campaign->status === DocumentCampaign::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['status' => ['Campaign is cancelled.']]);
            }

            $campaign->update(['status' => DocumentCampaign::STATUS_LAUNCHING, 'launched_at' => $campaign->launched_at ?? now()]);
            $type = $campaign->documentType;
            $created = 0;
            $failed = 0;

            DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)
                ->whereIn('state', [DocumentCampaignRecipient::STATE_PENDING, DocumentCampaignRecipient::STATE_FAILED])
                ->chunkById(200, function ($recipients) use (&$created, &$failed, $campaign, $type, $actor) {
                    foreach ($recipients as $recipient) {
                        try {
                            $subject = $recipient->subject();
                            // Idempotence : réutilise une demande active existante, sinon ouvre.
                            $existing = $this->requests->findExisting($campaign->company_id, $subject, $type->id);
                            if ($existing && in_array($existing->status, self::ACTIVE_STATES, true)) {
                                $req = $existing;
                            } else {
                                $req = $this->requests->open(
                                    $campaign->company_id, $subject, $type, $actor,
                                    $campaign->message, $campaign->due_date?->toDateString(), $campaign->requires_validation,
                                );
                            }
                            $recipient->update(['document_request_id' => $req->id, 'state' => DocumentCampaignRecipient::STATE_CREATED, 'error' => null]);
                            $created++;
                        } catch (\Throwable $e) {
                            $recipient->update(['state' => DocumentCampaignRecipient::STATE_FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
                            $failed++;
                        }
                    }
                });

            // « Active » seulement si AUCUN destinataire en échec/attente (sinon reste lançable).
            $remaining = DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)
                ->whereIn('state', [DocumentCampaignRecipient::STATE_PENDING, DocumentCampaignRecipient::STATE_FAILED])
                ->count();
            $campaign->update(['status' => $remaining === 0 ? DocumentCampaign::STATUS_ACTIVE : DocumentCampaign::STATUS_LAUNCHING]);

            Log::info('[dms.campaign] launch', ['campaign' => $campaign->id, 'created' => $created, 'failed' => $failed, 'remaining' => $remaining]);

            return ['created' => $created, 'failed' => $failed, 'remaining' => $remaining, 'status' => $campaign->status];
        } finally {
            optional($lock)->release();
        }
    }

    /** Annulation contrôlée : la campagne et ses demandes non finalisées sont annulées. */
    public function cancel(DocumentCampaign $campaign, ?User $actor = null): void
    {
        $campaign->recipients()->whereNotNull('document_request_id')->with('request')->chunkById(200, function ($recipients) use ($actor) {
            foreach ($recipients as $recipient) {
                $req = $recipient->request;
                if ($req && in_array($req->status, [DocumentRequest::STATUS_REQUESTED], true)) {
                    $this->requests->cancel($req, $actor);
                }
            }
        });

        $campaign->update(['status' => DocumentCampaign::STATUS_CANCELLED, 'closed_at' => now()]);
    }
}
