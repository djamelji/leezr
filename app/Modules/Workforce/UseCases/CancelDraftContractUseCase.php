<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\GeneratedDocument;
use App\Core\Models\User;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\UseCases\CancelSignatureRequestUseCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-773 — a DRAFT contract can be abandoned (`cancelled`) by an authorised person.
 *
 * A draft never took effect : it is neither « terminated » (that is the end of a contract in force)
 * nor activated just to be closed. Abandoning it is a minimal, additive, audited transition :
 *   draft → cancelled (terminal), `is_current` cleared.
 * Nothing is deleted : the contract stays in the history, its generated documents stay in the file,
 * and a signature still in progress on them is cancelled THROUGH the signature engine (traceable).
 * A contract in force is refused here — it follows the termination rules. Payroll is not touched :
 * it only reads contracts in force.
 */
class CancelDraftContractUseCase
{
    public function execute(EmploymentContract $contract, ?User $actor, string $reason): EmploymentContract
    {
        if (! $contract->canTransitionTo(EmploymentContract::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'contract' => ["Seul un contrat en brouillon peut être abandonné (statut actuel : « {$contract->status} »)."],
            ]);
        }

        return DB::transaction(function () use ($contract, $actor, $reason) {
            $contract->transitionTo(EmploymentContract::STATUS_CANCELLED);
            $contract->is_current = false;
            $contract->save();

            // Signatures still in progress on the documents of this contract : cancelled by the engine.
            $documentIds = GeneratedDocument::withoutGlobalScopes()->where('company_id', $contract->company_id)
                ->where('subject_type', 'contract')->where('subject_id', $contract->id)->pluck('id');
            $cancelled = 0;
            if ($documentIds->isNotEmpty() && $actor !== null) {
                $open = SignatureRequest::withoutGlobalScopes()->where('company_id', $contract->company_id)
                    ->whereIn('generated_document_id', $documentIds)
                    ->whereIn('status', [SignatureRequest::STATUS_PENDING, SignatureRequest::STATUS_PARTIALLY_SIGNED])->pluck('id');
                foreach ($open as $requestId) {
                    app(CancelSignatureRequestUseCase::class)->execute($contract->company_id, (int) $actor->id, (int) $requestId, 'Contrat abandonné : '.$reason);
                    $cancelled++;
                }
            }

            app(AuditLogger::class)->logCompany($contract->company_id, 'contract.cancelled', 'contract', (string) $contract->id, [
                'actorId' => $actor?->id, 'actorType' => $actor ? 'user' : 'system', 'severity' => 'warning',
                'metadata' => ['employee_id' => $contract->employee_id, 'reason' => $reason, 'signature_requests_cancelled' => $cancelled, 'documents' => $documentIds->all()],
            ]);

            return $contract;
        });
    }
}
