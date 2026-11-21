<?php

namespace App\Modules\Workforce\Signature\ReadModels;

use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-848 (Leezr 4.2 M9) — operational reads of the signature module, moved verbatim from SignatureController :
 * reconciliation diagnostics (W-SIGNATURE-13 §5), supervision metrics (W-SIGNATURE-12 §4), certificate register
 * (§6), requests linked to an employee. Read only, company-scoped, nothing sensitive (no token, no private key).
 */
class SignatureOperationsReadModel
{
    public static function reconciliation(int $cid): array
    {
        $now = now();
        $disk = config('documents.generated_disk', 'local');

        // Demandes completed sans artefact vérifiable (fichier manquant).
        $completedNoArtifact = [];
        $completed = SignatureRequest::withoutGlobalScopes()->where('company_id', $cid)
            ->where('status', SignatureRequest::STATUS_COMPLETED)->get(['id', 'signed_document_path']);
        foreach ($completed as $r) {
            if (! $r->signed_document_path || ! Storage::disk($disk)->exists($r->signed_document_path)) {
                $completedNoArtifact[] = $r->id;
            }
        }

        // Certificats expirant sous 30 jours (actifs, non révoqués).
        $expiringSoon = \App\Modules\Workforce\Signature\Models\SignatureCertificate::withoutGlobalScopes()
            ->where('company_id', $cid)->where('status', 'issued')
            ->whereBetween('not_after', [$now, $now->copy()->addDays(30)])->count();

        // Préparations abandonnées (expirées, jamais consommées).
        $abandonedPreps = \App\Modules\Workforce\Signature\Models\SignatureSigner::withoutGlobalScopes()
            ->where('company_id', $cid)->whereNotNull('signing_prep')
            ->whereNotNull('signing_prep_expires_at')->where('signing_prep_expires_at', '<', $now)->count();

        return [
            'as_of' => $now->toIso8601String(),
            'completed_without_artifact' => ['count' => count($completedNoArtifact), 'ids' => array_slice($completedNoArtifact, 0, 100)],
            'certificates_expiring_30d' => $expiringSoon,
            'abandoned_preparations' => $abandonedPreps,
            'note' => 'Diagnostics lecture seule, non destructifs. Toute réparation doit être une action explicite, autorisée, tracée et idempotente.',
        ];
    }

    /** Demandes de signature liées à un collaborateur (sujet employé OU ses contrats). */
    public static function forEmployee(int $companyId, int $employeeId): array
    {
        $contractIds = EmploymentContract::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)->pluck('id')->all();

        $rows = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['signers', 'document.template'])
            ->where(function ($q) use ($employeeId, $contractIds) {
                $q->where(fn ($s) => $s->where('subject_type', 'employee')->where('subject_id', $employeeId));
                if ($contractIds) {
                    $q->orWhere(fn ($s) => $s->where('subject_type', 'contract')->whereIn('subject_id', $contractIds));
                }
            })
            ->orderByDesc('created_at')->get()
            ->map(fn ($r) => SignatureReadModel::present($r));

        return ['data' => $rows];
    }

    public static function supervision(int $cid): array
    {
        $now = now();
        $base = fn () => SignatureRequest::withoutGlobalScopes()->where('company_id', $cid);
        $evt = fn (string $type) => \App\Modules\Workforce\Signature\Models\SignatureEvent::withoutGlobalScopes()
            ->where('company_id', $cid)->where('type', $type)->where('occurred_at', '>=', $now->copy()->subDays(30))->count();

        $pending = (clone $base())->whereIn('status', [SignatureRequest::STATUS_PENDING, SignatureRequest::STATUS_PARTIALLY_SIGNED])->count();
        // À réconcilier : actives dont l'échéance est dépassée mais pas encore marquées expirées ; ou terminées sans artefact.
        $staleActive = (clone $base())->whereIn('status', [SignatureRequest::STATUS_PENDING, SignatureRequest::STATUS_PARTIALLY_SIGNED])
            ->whereNotNull('expires_at')->where('expires_at', '<', $now)->count();
        $completedNoArtifact = (clone $base())->where('status', SignatureRequest::STATUS_COMPLETED)->whereNull('signed_document_path')->count();
        $expiredPreps = \App\Modules\Workforce\Signature\Models\SignatureSigner::withoutGlobalScopes()
            ->where('company_id', $cid)->whereNotNull('signing_prep')->whereNotNull('signing_prep_expires_at')
            ->where('signing_prep_expires_at', '<', $now)->count();

        return [
            'as_of' => $now->toIso8601String(),
            'requests' => [
                'pending' => $pending,
                'expired' => (clone $base())->where('status', SignatureRequest::STATUS_EXPIRED)->count(),
                'failed' => (clone $base())->where('status', SignatureRequest::STATUS_FAILED)->count(),
                'completed' => (clone $base())->where('status', SignatureRequest::STATUS_COMPLETED)->count(),
                'declined' => (clone $base())->where('status', SignatureRequest::STATUS_DECLINED)->count(),
                'cancelled' => (clone $base())->where('status', SignatureRequest::STATUS_CANCELLED)->count(),
            ],
            'failures_30d' => [
                'verification_failed' => $evt(\App\Modules\Workforce\Signature\Models\SignatureEvent::TYPE_VERIFICATION_FAILED),
                'request_failed' => $evt(\App\Modules\Workforce\Signature\Models\SignatureEvent::TYPE_FAILED),
                'certificate_revoked' => $evt(\App\Modules\Workforce\Signature\Models\SignatureEvent::TYPE_CERTIFICATE_REVOKED),
            ],
            'operational' => [
                'expired_preparations' => $expiredPreps,
                'needs_reconciliation' => $staleActive + $completedNoArtifact,
                'stale_active_past_expiry' => $staleActive,
                'completed_without_artifact' => $completedNoArtifact,
            ],
            'certificates' => [
                'issued' => \App\Modules\Workforce\Signature\Models\SignatureCertificate::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'issued')->count(),
                'revoked' => \App\Modules\Workforce\Signature\Models\SignatureCertificate::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'revoked')->count(),
            ],
        ];
    }

    public static function certificates(int $cid): array
    {
        $rows = \App\Modules\Workforce\Signature\Models\SignatureCertificate::withoutGlobalScopes()
            ->where('company_id', $cid)->with('signer:id,name,role,identity_assurance,reenrollment_count')
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'signer_name' => $c->signer?->name ?? $c->subject_cn,
                'role' => $c->signer?->role,
                'serial' => $c->serial,
                'fingerprint_sha256' => $c->fingerprint_sha256,
                'public_key_id' => $c->public_key_id,
                'issuer' => $c->issuer_dn,
                'not_before' => $c->not_before?->toIso8601String(),
                'not_after' => $c->not_after?->toIso8601String(),
                'status' => $c->status,
                'revoked_at' => $c->revoked_at?->toIso8601String(),
                'revoked_reason' => $c->revoked_reason,
                'auth_method' => $c->auth_method,
                'identity_assurance' => $c->identity_verification,
                'reenrollment_count' => $c->signer?->reenrollment_count ?? 0,
                'ca_recognized' => false,
                'eidas' => 'not_demonstrated',
            ]);

        return ['data' => $rows];
    }

    /** Documents RH émis éligibles à une demande (non signés, sans demande active), filtrés par le périmètre du lecteur. */
    public static function eligibleDocuments(int $companyId, callable $inScope)
    {
        $activeDocIds = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotIn('status', SignatureRequest::TERMINAL_STATUSES)
            ->pluck('generated_document_id')->all();

        $docs = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('signature_status', '!=', \App\Core\Documents\GeneratedDocument::SIGNATURE_SIGNED)
            ->whereNotIn('id', $activeDocIds ?: [-1])
            ->with('template')
            ->orderByDesc('generated_at')
            ->limit(100)->get()
            ->filter($inScope)
            ->map(fn ($d) => [
                'id' => $d->id,
                'label' => $d->template?->name ?? 'Document',
                'subject_type' => $d->subject_type,
                'subject_id' => $d->subject_id,
                'generated_at' => $d->generated_at?->toIso8601String(),
            ])->values();


        return $docs;
    }
}
