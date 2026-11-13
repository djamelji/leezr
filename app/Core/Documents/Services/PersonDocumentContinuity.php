<?php

namespace App\Core\Documents\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Support\Facades\DB;

/**
 * ADR-769 (M7 LOT 3) — continuity of a PERSON's documents when an account is linked to an HR record.
 *
 * Documents (and their requests) filed for an HR record WITHOUT account live under the subject
 * `employee:{id}`. Once the account is explicitly linked (`employee.user_id`), the canonical subject
 * of the person becomes `user:{id}` (PersonSubjectResolver) and every self-service path is keyed on
 * the account. Without this step the person no longer finds her own documents, and a new upload
 * creates a second document of the same type.
 *
 * `adopt()` re-homes those rows onto the account, ONCE : same row, same file, same versions, same
 * hash — nothing is copied, nothing is deleted. A type the account already holds is left untouched
 * (no overwrite, no duplicate created ; the administrative view keeps merging it). Idempotent.
 * The link itself is never guessed here : only an existing `employee.user_id` is followed.
 */
class PersonDocumentContinuity
{
    public static function adopt(Company $company, User $user): int
    {
        $employeeIds = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $user->id)->pluck('id');
        if ($employeeIds->isEmpty()) {
            return 0;
        }

        $pending = MemberDocument::where('company_id', $company->id)->whereNull('user_id')
            ->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->whereIn('document_subject_id', $employeeIds);
        $pendingRequests = DocumentRequest::where('company_id', $company->id)->whereNull('user_id')
            ->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->whereIn('document_subject_id', $employeeIds);
        if (! (clone $pending)->exists() && ! (clone $pendingRequests)->exists()) {
            return 0; // the normal case : one cheap existence check, no write
        }

        return DB::transaction(function () use ($company, $user, $pending, $pendingRequests) {
            $adopted = 0;
            $subject = ['user_id' => $user->id, 'document_subject_type' => DocumentSubject::TYPE_USER, 'document_subject_id' => $user->id];

            $heldTypes = MemberDocument::where('company_id', $company->id)->where('user_id', $user->id)->pluck('document_type_id')->all();
            foreach ($pending->lockForUpdate()->get() as $doc) {
                if (in_array($doc->document_type_id, $heldTypes, true)) {
                    continue;
                }
                $doc->forceFill($subject)->save();
                $heldTypes[] = $doc->document_type_id;
                $adopted++;
            }

            $requestedTypes = DocumentRequest::where('company_id', $company->id)->where('user_id', $user->id)->pluck('document_type_id')->all();
            foreach ($pendingRequests->lockForUpdate()->get() as $request) {
                if (in_array($request->document_type_id, $requestedTypes, true)) {
                    continue;
                }
                $request->forceFill($subject)->save();
                $requestedTypes[] = $request->document_type_id;
                $adopted++;
            }

            if ($adopted > 0) {
                app(AuditLogger::class)->logCompany($company->id, 'document.subject_adopted', 'user', (string) $user->id, [
                    'actorType' => 'system', 'metadata' => ['adopted' => $adopted],
                ]);
            }

            return $adopted;
        });
    }
}
