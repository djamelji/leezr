<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ADR-760 (DMS M3) — orchestrateur UNIFIÉ du cycle de vie documentaire : renouvellement à
 * l'expiration + relances configurables. Remplace la logique dupliquée des trois commandes.
 *
 * Garanties : IDEMPOTENT (dédup déterministe via les événements `reminded`/`renewed` + garde de
 * demande active), CHUNKÉ, VERROUILLÉ (lock cache cross-commandes → pas d'exécution concurrente
 * ni d'envoi en double), horloge serveur (Carbon), observable (compteurs + log). Les délais de
 * relance viennent de `CompanyDocumentSetting::reminderPolicy()` — aucune valeur codée en dur.
 *
 * Account-less : un Employee sans compte n'a pas de canal applicatif → la relance devient un
 * SIGNAL destiné aux gestionnaires Company (on ne prétend jamais l'avoir notifié).
 */
class DocumentLifecycleOrchestrator
{
    private const LOCK_KEY = 'documents:lifecycle:orchestrator';

    public function __construct(private readonly DocumentRequestService $requests)
    {
    }

    /**
     * Exécute les phases demandées sous un lock partagé (sérialise les 3 commandes entre elles).
     *
     * @param  list<string>  $phases  sous-ensemble de ['renew','remind']
     * @return array{renewed:int,reminded:int,skipped:int,companies:int}
     */
    public function run(array $phases = ['renew', 'remind'], bool $dryRun = false): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        if (! $lock->get()) {
            Log::info('[dms.lifecycle] skipped — another run holds the lock');

            return ['renewed' => 0, 'reminded' => 0, 'skipped' => 0, 'companies' => 0, 'locked_out' => true];
        }

        try {
            return $this->execute($phases, $dryRun);
        } finally {
            optional($lock)->release();
        }
    }

    private function execute(array $phases, bool $dryRun): array
    {
        $stats = ['renewed' => 0, 'reminded' => 0, 'skipped' => 0, 'companies' => 0];

        // ADR-771 (tenant strict, ADR-733) : la SEULE lecture inter-tenant est l'énumération des
        // entreprises configurées, DÉCLARÉE par `runGlobal` (ids uniquement, par pages). Tout le reste
        // — réconciliation, renouvellements, relances, notifications — s'exécute DANS le contexte de
        // l'entreprise (`runFor`) : le scope tenant filtre, et une requête qui sortirait de
        // l'entreprise lèverait en mode strict au lieu de lire les autres.
        $cursor = 0;
        do {
            $page = CompanyContext::runGlobal(fn () => CompanyDocumentSetting::query()
                ->where('id', '>', $cursor)->orderBy('id')->limit(100)->get(['id', 'company_id']));

            foreach ($page as $row) {
                $cursor = (int) $row->id;
                $company = Company::withoutGlobalScopes()->find($row->company_id);
                if ($company === null) {
                    continue; // réglage orphelin : aucune entreprise à traiter
                }

                CompanyContext::runFor($company, function (Company $company) use (&$stats, $phases, $dryRun) {
                    $setting = CompanyDocumentSetting::where('company_id', $company->id)->first();
                    if ($setting === null) {
                        return;
                    }
                    $stats['companies']++;
                    // ADR-770 : relances et renouvellements s'adressent au sujet CANONIQUE de la personne.
                    if (! $dryRun) {
                        PersonDocumentContinuity::reconcile($company);
                    }
                    if (in_array('renew', $phases, true) && $setting->auto_renew_enabled) {
                        $stats['renewed'] += $this->renewPhase($setting, $dryRun);
                    }
                    if (in_array('remind', $phases, true) && $setting->auto_remind_enabled) {
                        $stats['reminded'] += $this->remindPhase($setting, $dryRun);
                    }
                });
            }
        } while ($page->count() === 100);

        Log::info('[dms.lifecycle] run complete', $stats + ['dry_run' => $dryRun]);

        return $stats;
    }

    /**
     * RENOUVELLEMENT : pour chaque document arrivant à expiration (fenêtre renew_days_before),
     * ouvre une demande si aucune n'est active. Idempotent (open refuse si déjà active). Le
     * document courant et ses versions sont préservés (remplacement non destructif existant).
     */
    private function renewPhase(CompanyDocumentSetting $setting, bool $dryRun): int
    {
        $windowEnd = Carbon::now()->addDays((int) $setting->renew_days_before);
        $count = 0;

        MemberDocument::where('company_id', $setting->company_id)
            ->whereNotNull('expires_at')
            ->whereNotNull('document_type_id')
            ->where('expires_at', '>', Carbon::now())
            ->where('expires_at', '<=', $windowEnd)
            ->chunkById(200, function ($docs) use (&$count, $dryRun) {
                foreach ($docs as $doc) {
                    $type = DocumentType::find($doc->document_type_id);
                    if (! $type) {
                        continue;
                    }
                    $subject = DocumentSubject::fromMemberDocument($doc);

                    // ADR-770 : pièce détenue par un PRÉCÉDENT compte d'une fiche aujourd'hui liée à un autre
                    // compte — archive du dossier, jamais relancée auprès de la nouvelle personne.
                    if ($doc->previous_user_id !== null && $subject->is(DocumentSubject::TYPE_EMPLOYEE)
                        && \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('id', $subject->id)->whereNotNull('user_id')->exists()) {
                        continue;
                    }

                    // Garde anti-doublon : ne rouvre pas si une demande est déjà active.
                    $existing = $this->requests->findExisting($doc->company_id, $subject, $type->id);
                    $active = [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_IN_REVIEW, DocumentRequest::STATUS_TO_REPLACE];
                    if ($existing && in_array($existing->status, $active, true)) {
                        continue;
                    }

                    if (! $dryRun) {
                        $req = $this->requests->open($doc->company_id, $subject, $type, null, null, $doc->expires_at?->toDateString(), true);
                        DocumentRequestEvent::create([
                            'company_id' => $doc->company_id,
                            'document_request_id' => $req->id,
                            'event' => DocumentRequestEvent::E_RENEWED,
                            'created_at' => Carbon::now(),
                            'metadata' => ['reason' => 'expiring', 'expires_at' => $doc->expires_at?->toDateString()],
                        ]);
                        $this->notify($doc->company_id, $subject, $type, 'documents.request_new', null);
                    }
                    $count++;
                }
            });

        return $count;
    }

    /**
     * RELANCES : pour chaque demande active, calcule les tiers DUS (politique configurable),
     * ancrés sur l'expiration du document (exp-*) et sur l'échéance de la demande (due-*). Chaque
     * tier ne part qu'UNE FOIS par cycle (dédup via événement `reminded` depuis `requested_at`).
     */
    private function remindPhase(CompanyDocumentSetting $setting, bool $dryRun): int
    {
        $policy = $setting->reminderPolicy();
        $count = 0;
        $activeStates = [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_IN_REVIEW, DocumentRequest::STATUS_TO_REPLACE];

        DocumentRequest::where('company_id', $setting->company_id)
            ->whereIn('status', $activeStates)
            ->chunkById(200, function ($requests) use (&$count, $policy, $dryRun) {
                foreach ($requests as $req) {
                    foreach ($this->dueTiers($req, $policy) as $tier) {
                        if ($this->alreadyReminded($req, $tier)) {
                            continue;
                        }
                        if (! $dryRun) {
                            $this->sendReminder($req, $tier);
                        }
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Tiers de relance DUS pour une demande, à partir de la politique. Ancrage :
     *  - exp-{n} / exp0 / exp+{n} sur l'expiration du document courant (si présent) ;
     *  - due-{n} / due+{n} sur l'échéance de la demande (due_date).
     *
     * @return list<string>  identifiants de tiers dus (ex. "exp-7", "due+1")
     */
    private function dueTiers(DocumentRequest $req, array $policy): array
    {
        $now = Carbon::now();
        $tiers = [];

        $doc = $this->documentFor($req);
        $expiresAt = $doc?->expires_at;

        if ($expiresAt) {
            foreach ((array) ($policy['expiration_before_days'] ?? []) as $d) {
                // Dû si on est entré dans la fenêtre J-d (mais pas encore expiré).
                if ($now->lt($expiresAt) && $now->gte($expiresAt->copy()->subDays((int) $d))) {
                    $tiers[] = "exp-{$d}";
                }
            }
            if (($policy['expiration_at'] ?? false) && $now->gte($expiresAt) && $now->lt($expiresAt->copy()->addDay())) {
                $tiers[] = 'exp0';
            }
            foreach ((array) ($policy['expiration_after_days'] ?? []) as $d) {
                if ($now->gte($expiresAt->copy()->addDays((int) $d))) {
                    $tiers[] = "exp+{$d}";
                }
            }
        }

        if ($req->due_date) {
            foreach ((array) ($policy['request_due_before_days'] ?? []) as $d) {
                if ($now->lt($req->due_date) && $now->gte($req->due_date->copy()->subDays((int) $d))) {
                    $tiers[] = "due-{$d}";
                }
            }
            foreach ((array) ($policy['request_due_after_days'] ?? []) as $d) {
                if ($now->gte($req->due_date->copy()->addDays((int) $d))) {
                    $tiers[] = "due+{$d}";
                }
            }
        }

        return array_values(array_unique($tiers));
    }

    /** Un tier a-t-il déjà été relancé pour le CYCLE courant (depuis requested_at) ? */
    private function alreadyReminded(DocumentRequest $req, string $tier): bool
    {
        return DocumentRequestEvent::where('document_request_id', $req->id)
            ->where('event', DocumentRequestEvent::E_REMINDED)
            ->where('created_at', '>=', $req->requested_at ?? $req->created_at)
            ->where('metadata->tier', $tier)
            ->exists();
    }

    private function sendReminder(DocumentRequest $req, string $tier): void
    {
        $type = DocumentType::find($req->document_type_id);
        if (! $type) {
            return;
        }
        $subject = $req->document_subject_type && $req->document_subject_id
            ? new DocumentSubject((string) $req->document_subject_type, (int) $req->document_subject_id)
            : DocumentSubject::user((int) $req->user_id);

        $topic = str_starts_with($tier, 'exp+') ? 'documents.expired'
            : (str_starts_with($tier, 'exp') ? 'documents.expiring_soon' : 'documents.request_new');

        $this->notify($req->company_id, $subject, $type, $topic, $tier);

        $req->forceFill(['last_reminded_at' => Carbon::now()])->save();
        DocumentRequestEvent::create([
            'company_id' => $req->company_id,
            'document_request_id' => $req->id,
            'event' => DocumentRequestEvent::E_REMINDED,
            'created_at' => Carbon::now(),
            'metadata' => ['tier' => $tier],
        ]);
    }

    /**
     * Notifie : le collaborateur (si sujet=user, canal applicatif + préférences) ET/OU les
     * gestionnaires Company. Pour un sujet SANS compte, on ne notifie QUE les gestionnaires
     * (signal), sans prétendre avoir joint la personne.
     */
    private function notify(int $companyId, DocumentSubject $subject, DocumentType $type, string $topic, ?string $tier): void
    {
        $entityKey = "document_request:{$subject}:{$type->code}";
        $payload = [
            'document_type' => $type->label,
            'document_code' => $type->code,
            'subject' => (string) $subject,
            'reminder_tier' => $tier,
            'link' => '/company/documents/requests',
        ];

        // Collaborateur avec compte → canal direct (préférences respectées par le dispatcher).
        if ($subject->is(DocumentSubject::TYPE_USER)) {
            $user = User::find($subject->id);
            if ($user) {
                NotificationDispatcher::send(
                    topicKey: $topic,
                    recipients: [$user],
                    payload: $payload + ['link' => '/account-settings/documents'],
                    company: Company::withoutGlobalScopes()->find($companyId),
                    entityKey: $entityKey,
                );
            }
        }

        // Gestionnaires Company (toujours pour un sujet sans compte ; sinon en copie de suivi).
        $admins = $this->companyManagers($companyId, $subject);
        if ($admins->isNotEmpty()) {
            NotificationDispatcher::send(
                topicKey: $topic,
                recipients: $admins,
                payload: $payload + ['manager_signal' => $subject->is(DocumentSubject::TYPE_USER) ? false : true],
                company: Company::withoutGlobalScopes()->find($companyId),
                entityKey: $entityKey.':managers',
            );
        }
    }

    /** Gestionnaires Company (owners + rôles administratifs), hors la personne concernée. */
    private function companyManagers(int $companyId, DocumentSubject $subject): \Illuminate\Support\Collection
    {
        $excludeUserId = $subject->is(DocumentSubject::TYPE_USER) ? $subject->id : null;

        $ids = Membership::where('company_id', $companyId)
            ->when($excludeUserId, fn ($q) => $q->where('user_id', '!=', $excludeUserId))
            ->where(function ($q) {
                $q->where('role', 'owner')
                    ->orWhereHas('companyRole', fn ($r) => $r->where('is_administrative', true));
            })
            ->pluck('user_id');

        return User::whereIn('id', $ids)->get();
    }

    /** Document courant lié à une demande (par sujet, tolérant aux lignes legacy). */
    private function documentFor(DocumentRequest $req): ?MemberDocument
    {
        return MemberDocument::where('company_id', $req->company_id)
            ->where('document_type_id', $req->document_type_id)
            ->where(function ($w) use ($req) {
                $w->where(function ($a) use ($req) {
                    $a->where('document_subject_type', $req->document_subject_type)->where('document_subject_id', $req->document_subject_id);
                });
                if ($req->user_id) {
                    $w->orWhere('user_id', $req->user_id);
                }
            })
            ->first();
    }
}
