<?php

namespace App\Modules\Workforce\Signature\ReadModels;

use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Illuminate\Http\Request;

/**
 * W-SIGNATURE-1 (ADR-651) — lecture des demandes de signature (liste + détail), scope manager inclus.
 */
class SignatureReadModel
{
    /** Requête de base scopée : RH/global voit tout ; sinon seulement ses collaborateurs + ses propres initiations. */
    public static function scopedQuery(Request $request, int $companyId)
    {
        $q = SignatureRequest::withoutGlobalScopes()->where('company_id', $companyId);

        if (WorkforceAccess::hasViewAll($request, 'employee')) {
            return $q;
        }

        $visibleEmployeeIds = WorkforceAccess::visibleEmployeeIds($request, 'employee');
        $actorId = $request->user()?->id;

        return $q->where(function ($sub) use ($visibleEmployeeIds, $companyId, $actorId) {
            $sub->where('initiator_id', $actorId)
                ->orWhere(function ($s) use ($visibleEmployeeIds) {
                    $s->where('subject_type', 'employee')->whereIn('subject_id', $visibleEmployeeIds ?: [-1]);
                })
                ->orWhere(function ($s) use ($visibleEmployeeIds, $companyId) {
                    $contractIds = EmploymentContract::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->whereIn('employee_id', $visibleEmployeeIds ?: [-1])
                        ->pluck('id')->all();
                    $s->where('subject_type', 'contract')->whereIn('subject_id', $contractIds ?: [-1]);
                });
        });
    }

    public static function list(Request $request, int $companyId, array $filters = []): array
    {
        $q = self::scopedQuery($request, $companyId)->with(['signers', 'document.template']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['provider'])) {
            $q->where('provider', $filters['provider']);
        }
        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->whereHas('signers', fn ($s) => $s->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        $perPage = min(100, max(5, (int) ($filters['per_page'] ?? 15)));
        $page = $q->orderByDesc('created_at')->paginate($perPage);

        return [
            'data' => array_map(fn ($r) => self::present($r), $page->items()),
            'meta' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ];
    }

    public static function present(SignatureRequest $r, bool $withEvents = false): array
    {
        $data = [
            'id' => $r->id,
            'generated_document_id' => $r->generated_document_id,
            'document_label' => $r->document?->template?->name,
            'subject_type' => $r->subject_type,
            'subject_id' => $r->subject_id,
            'provider' => $r->provider,
            'signature_level' => $r->signature_level,
            'signing_order' => $r->signing_order,
            'status' => $r->status,
            'is_terminal' => $r->isTerminal(),
            'sent_at' => $r->sent_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'expires_at' => $r->expires_at?->toIso8601String(),
            'has_signed_document' => $r->signed_document_path !== null,
            'certified' => (bool) ($r->metadata['certified'] ?? ($r->document?->signature_metadata['certified'] ?? false)),
            'created_at' => $r->created_at?->toIso8601String(),
            'signers' => $r->signers->map(fn ($s) => [
                'id' => $s->id,
                'order' => $s->order,
                'role' => $s->role,
                'name' => $s->name,
                'email' => $s->email,
                'status' => $s->status,
                'auth_method' => $s->auth_method,
                'required_auth_method' => $s->required_auth_method,
                'invited_at' => $s->invited_at?->toIso8601String(),
                'acted_at' => $s->acted_at?->toIso8601String(),
            ])->all(),
        ];

        if ($withEvents) {
            $data['events'] = $r->events->map(fn ($e) => [
                'type' => $e->type,
                'actor_type' => $e->actor_type,
                'verification_result' => $e->verification_result,
                'document_sha256' => $e->document_sha256,
                'provider_reference' => $e->provider_reference,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'payload' => $e->payload,
            ])->all();
        }

        return $data;
    }
}
