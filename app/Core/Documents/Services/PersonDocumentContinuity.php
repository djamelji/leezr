<?php

namespace App\Core\Documents\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentRequestEvent;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Support\Facades\DB;

/**
 * ADR-769 / ADR-770 (M7 LOT 3-4) — SUBJECT TRANSITION of a person's documents.
 *
 * A person is ONE document subject at a time (ADR-761) : `employee:{id}` while her HR record has no
 * account, `user:{id}` once an account is explicitly linked (`employee.user_id`). Every reader and
 * writer of the DMS (file, self-service, review, compliance, lifecycle, campaigns) is keyed on that
 * canonical subject. The rows must therefore FOLLOW the person when the canonical subject changes —
 * a de-duplication at display time does not make the review, the compliance or the reminders right.
 *
 * WRITTEN RULE — one current document, one request, one history per person and document type :
 *
 *  1. ADOPTION (record gets an account) : rows of `employee:{E}` move to `user:{U}`. Same row, same
 *     file, same versions, same events — nothing copied.
 *  2. RELEASE (access removed, record unlinked or linked elsewhere) : rows held by the former
 *     account for that record move back to `employee:{E}` — the company keeps the person's file.
 *     They are stamped with the account that held them and are NEVER handed over to ANOTHER account
 *     linked later (the administrator keeps them on the person's file ; the same account gets them
 *     back if it is linked again).
 *  3. CONFLICT (the destination already holds a row of the same type) :
 *      - DOCUMENT : the row already at the destination is the current one (it was written after the
 *        subject changed). The incoming document becomes its HISTORY : its file and its versions
 *        become versions of the current document (re-pointed, never copied), then the emptied row
 *        is removed.
 *      - REQUEST : same rule, except when the destination request is a pristine « to provide »
 *        placeholder (never submitted) and no document conflict exists — then the incoming request,
 *        which carries the real workflow, survives and the placeholder is folded into it (its
 *        message and due date are kept). The folded request's events are re-pointed to the survivor.
 *     Every fold records a `merged` event holding a snapshot of the folded row.
 *  4. Every transition is audited (`document.subject_transition`) and idempotent ; nothing is ever
 *     guessed : only an existing `employee.user_id` (or its removal) is followed, inside ONE company.
 *
 * Entry points : `adopt()` (one person — called by every per-person path), `reconcile()` (one
 * company — called by company-wide readers and the scheduled lifecycle), `release()` (explicit).
 * In the normal case each costs a couple of existence checks and writes nothing.
 */
class PersonDocumentContinuity
{
    /** Bring the rows of the person behind this account onto her canonical subject. */
    public static function adopt(Company $company, User $user): int
    {
        $moved = self::releaseStale($company, userId: $user->id);

        $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $user->id)->first();
        if ($employee === null) {
            return $moved;
        }

        return $moved + self::adoptPair($company, $employee, (int) $user->id);
    }

    /** Company-wide : release what belongs to unlinked records, adopt what belongs to linked ones. */
    public static function reconcile(Company $company): int
    {
        $moved = self::releaseStale($company);

        $linked = Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('user_id')->pluck('user_id', 'id');
        if ($linked->isEmpty()) {
            return $moved;
        }

        $pendingIds = collect()
            ->merge(self::employeeRows(MemberDocument::query(), $company, $linked->keys()->all())->pluck('document_subject_id'))
            ->merge(self::employeeRows(DocumentRequest::query(), $company, $linked->keys()->all())->pluck('document_subject_id'))
            ->merge(DocumentCampaignRecipient::where('company_id', $company->id)->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->whereIn('document_subject_id', $linked->keys())->pluck('document_subject_id'))
            ->unique();
        // Rows written for a linked account but not yet stamped with their HR record : stamped
        // SET-BASED (two statements for the whole company, whatever its size). Doing it pair by
        // pair made the first company-wide read cost ~8 queries per linked person (N+1).
        foreach ([(new MemberDocument)->getTable(), (new DocumentRequest)->getTable()] as $table) {
            DB::table($table)->where('company_id', $company->id)->whereNull('origin_employee_id')->whereIn('user_id', $linked->values()->all())
                ->update(['origin_employee_id' => DB::raw(
                    '(select e.id from workforce_employees e where e.company_id = '.$table.'.company_id and e.user_id = '.$table.'.user_id limit 1)'
                )]);
        }

        // Only the HR records that still hold rows of their own need a real transition.
        foreach ($linked as $employeeId => $userId) {
            if ($pendingIds->contains($employeeId)) {
                $employee = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('id', $employeeId)->get()->first();
                $moved += $employee ? self::adoptPair($company, $employee, (int) $userId) : 0;
            }
        }

        return $moved;
    }

    /** Settle ONE person addressed by her HR record and/or her account (admin paths by personRef). */
    public static function settle(Company $company, ?User $user, ?Employee $employee): int
    {
        if ($user !== null) {
            return self::adopt($company, $user);
        }

        // HR record without account : rows a former account still holds for it come back.
        return $employee !== null ? self::releaseStale($company, employeeId: (int) $employee->id) : 0;
    }

    /** Settle the person behind a document subject (paths that only know the subject). */
    public static function settleSubject(int $companyId, DocumentSubject $subject): int
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        if ($company === null) {
            return 0;
        }
        if ($subject->is(DocumentSubject::TYPE_USER)) {
            $user = User::find($subject->id);

            return $user ? self::adopt($company, $user) : 0;
        }
        if ($subject->is(DocumentSubject::TYPE_EMPLOYEE)) {
            return self::releaseStale($company, employeeId: (int) $subject->id);
        }

        return 0;
    }

    /** Explicit release, for a caller that removes the link itself. */
    public static function release(Company $company, int $employeeId, int $userId): int
    {
        return self::releaseStale($company, userId: $userId, employeeId: $employeeId, force: true);
    }

    // ─── Adoption ───────────────────────────────────────────────────────────

    private static function adoptPair(Company $company, Employee $employee, int $userId): int
    {
        $to = DocumentSubject::user($userId);
        $eligible = fn ($q) => $q->where(fn ($w) => $w->whereNull('previous_user_id')->orWhere('previous_user_id', $userId));

        $hasDocs = $eligible(self::employeeRows(MemberDocument::query(), $company, [$employee->id]))->exists();
        $hasRequests = $eligible(self::employeeRows(DocumentRequest::query(), $company, [$employee->id]))->exists();
        $hasRecipients = DocumentCampaignRecipient::where('company_id', $company->id)
            ->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->where('document_subject_id', $employee->id)->exists();
        $needsStamp = MemberDocument::where('company_id', $company->id)->where('user_id', $userId)->whereNull('origin_employee_id')->exists()
            || DocumentRequest::where('company_id', $company->id)->where('user_id', $userId)->whereNull('origin_employee_id')->exists();

        if (! $hasDocs && ! $hasRequests && ! $hasRecipients && ! $needsStamp) {
            return 0; // the normal case : nothing to do, nothing written
        }

        return DB::transaction(function () use ($company, $employee, $userId, $to, $eligible) {
            $moved = 0;
            $docConflictTypes = [];

            foreach ($eligible(self::employeeRows(MemberDocument::query(), $company, [$employee->id]))->lockForUpdate()->get() as $doc) {
                $current = self::documentAt($company, $to, (int) $doc->document_type_id);
                if ($current) {
                    self::foldDocument($doc, $current);
                    $docConflictTypes[] = (int) $doc->document_type_id;
                } else {
                    $doc->forceFill(['user_id' => $userId, 'document_subject_type' => $to->type, 'document_subject_id' => $to->id, 'origin_employee_id' => $employee->id, 'previous_user_id' => null])->save();
                }
                $moved++;
            }

            foreach ($eligible(self::employeeRows(DocumentRequest::query(), $company, [$employee->id]))->lockForUpdate()->get() as $request) {
                $survivor = self::moveRequest($company, $request, $to, $userId, $employee->id, in_array((int) $request->document_type_id, $docConflictTypes, true));
                self::repointRecipients($company, $request, $survivor, DocumentSubject::employee((int) $employee->id), $to);
                $moved++;
            }

            // Campaign recipients without a request of their own still follow the person.
            $moved += self::moveRecipients($company, DocumentSubject::employee((int) $employee->id), $to);

            // Rows written for the account after the link belong to the same HR record.
            MemberDocument::where('company_id', $company->id)->where('user_id', $userId)->whereNull('origin_employee_id')->update(['origin_employee_id' => $employee->id]);
            DocumentRequest::where('company_id', $company->id)->where('user_id', $userId)->whereNull('origin_employee_id')->update(['origin_employee_id' => $employee->id]);

            if ($moved > 0) {
                self::audit($company, 'adopt', $employee->id, $userId, $moved);
            }

            return $moved;
        });
    }

    // ─── Release ────────────────────────────────────────────────────────────

    /**
     * Rows keyed on an account for an HR record that is no longer linked to THAT account return to
     * the HR record. Detected from `origin_employee_id` (no call site of the unlink is required).
     */
    private static function releaseStale(Company $company, ?int $userId = null, ?int $employeeId = null, bool $force = false): int
    {
        $stale = function (string $model) use ($company, $userId, $employeeId) {
            return $model::query()->where('company_id', $company->id)->whereNotNull('origin_employee_id')->whereNotNull('user_id')
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
                ->when($employeeId !== null, fn ($q) => $q->where('origin_employee_id', $employeeId));
        };

        $pairs = collect()
            ->merge($stale(MemberDocument::class)->get(['origin_employee_id', 'user_id']))
            ->merge($stale(DocumentRequest::class)->get(['origin_employee_id', 'user_id']))
            ->map(fn ($r) => [(int) $r->origin_employee_id, (int) $r->user_id])->unique()->values();
        if ($pairs->isEmpty()) {
            return 0;
        }

        $links = Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('id', $pairs->pluck(0))->pluck('user_id', 'id');
        $moved = 0;

        foreach ($pairs as [$eId, $uId]) {
            $stillLinked = $links->has($eId) && (int) $links[$eId] === $uId;
            if (! $links->has($eId) || ($stillLinked && ! $force)) {
                continue; // record gone (rows stay with the account) or link intact
            }
            $moved += DB::transaction(function () use ($company, $eId, $uId) {
                $to = DocumentSubject::employee($eId);
                $n = 0;
                $docConflictTypes = [];

                foreach (MemberDocument::where('company_id', $company->id)->where('user_id', $uId)->where('origin_employee_id', $eId)->lockForUpdate()->get() as $doc) {
                    $current = self::documentAt($company, $to, (int) $doc->document_type_id);
                    if ($current) {
                        self::foldDocument($doc, $current);
                        $docConflictTypes[] = (int) $doc->document_type_id;
                    } else {
                        $doc->forceFill(['user_id' => null, 'document_subject_type' => $to->type, 'document_subject_id' => $to->id, 'origin_employee_id' => null, 'previous_user_id' => $uId])->save();
                    }
                    $n++;
                }
                foreach (DocumentRequest::where('company_id', $company->id)->where('user_id', $uId)->where('origin_employee_id', $eId)->lockForUpdate()->get() as $request) {
                    $survivor = self::moveRequest($company, $request, $to, null, null, in_array((int) $request->document_type_id, $docConflictTypes, true), $uId);
                    self::repointRecipients($company, $request, $survivor, DocumentSubject::user($uId), $to);
                    $n++;
                }
                if ($n > 0) {
                    self::audit($company, 'release', $eId, $uId, $n);
                }

                return $n;
            });
        }

        return $moved;
    }

    // ─── Moves and folds ────────────────────────────────────────────────────

    private static function employeeRows($query, Company $company, array $employeeIds)
    {
        return $query->where('company_id', $company->id)->whereNull('user_id')
            ->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->whereIn('document_subject_id', $employeeIds);
    }

    private static function documentAt(Company $company, DocumentSubject $subject, int $typeId): ?MemberDocument
    {
        return MemberDocument::where('company_id', $company->id)->where('document_type_id', $typeId)
            ->where(function ($w) use ($subject) {
                $w->where(fn ($a) => $a->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id));
                if ($subject->is(DocumentSubject::TYPE_USER)) {
                    $w->orWhere('user_id', $subject->id); // legacy rows without subject columns
                }
            })->lockForUpdate()->first();
    }

    /**
     * The incoming document becomes the HISTORY of the current one : its own versions and its
     * current file are attached to the current document as versions (same files, no copy), ordered
     * by date ; references to the folded row are re-pointed ; the emptied row is removed.
     */
    private static function foldDocument(MemberDocument $incoming, MemberDocument $current): void
    {
        $morph = $current->getMorphClass();
        $subject = DocumentSubject::fromMemberDocument($current);

        DocumentVersion::where('documentable_type', $morph)->where('documentable_id', $incoming->id)
            ->update(['documentable_id' => $current->id, 'member_document_id' => $current->id, 'subject_type' => $subject->type, 'subject_id' => $subject->id, 'version_no' => DB::raw('version_no + 1000000')]);

        if (! empty($incoming->file_path) && $incoming->file_path !== $current->file_path) {
            $version = new DocumentVersion([
                'company_id' => $current->company_id, 'documentable_type' => $morph, 'documentable_id' => $current->id,
                'member_document_id' => $current->id, 'subject_type' => $subject->type, 'subject_id' => $subject->id,
                'version_no' => 2000000, 'file_path' => $incoming->file_path, 'file_name' => $incoming->file_name,
                'file_size_bytes' => $incoming->file_size_bytes, 'mime_type' => $incoming->mime_type, 'file_sha256' => $incoming->file_sha256,
                'source' => DocumentVersion::SOURCE_UPLOAD, 'uploaded_by' => $incoming->uploaded_by,
                'metadata' => ['archived_from' => 'subject_transition', 'folded_member_document_id' => $incoming->id, 'expires_at' => $incoming->expires_at?->toDateString()],
            ]);
            $version->created_at = $incoming->updated_at ?? now();
            $version->save();
        }

        // Chronological, gap-free numbering of the whole history of the current document.
        $no = 0;
        foreach (DocumentVersion::where('documentable_type', $morph)->where('documentable_id', $current->id)->orderBy('created_at')->orderBy('version_no')->get() as $v) {
            $v->forceFill(['version_no' => 3000000 + (++$no)])->save();
        }
        DocumentVersion::where('documentable_type', $morph)->where('documentable_id', $current->id)->where('version_no', '>', 3000000)
            ->update(['version_no' => DB::raw('version_no - 3000000')]);

        DB::table('generated_documents')->where('member_document_id', $incoming->id)->update(['member_document_id' => $current->id]);
        DB::table('member_documents')->where('parent_document_id', $incoming->id)->update(['parent_document_id' => $current->id]);

        app(AuditLogger::class)->logCompany($current->company_id, 'document.subject_merged', 'member_document', (string) $current->id, [
            'actorType' => 'system',
            'metadata' => ['folded' => $incoming->only(['id', 'user_id', 'document_subject_type', 'document_subject_id', 'document_type_id', 'file_path', 'file_name', 'file_sha256', 'expires_at', 'uploaded_by'])],
        ]);
        $incoming->delete();
    }

    /**
     * Moves a request to the destination subject. On conflict, decides the survivor (rule 3) and
     * folds the other one. Returns the surviving request.
     */
    private static function moveRequest(Company $company, DocumentRequest $incoming, DocumentSubject $to, ?int $userId, ?int $originEmployeeId, bool $documentConflict, ?int $previousUserId = null): DocumentRequest
    {
        $key = ['user_id' => $userId, 'document_subject_type' => $to->type, 'document_subject_id' => $to->id, 'origin_employee_id' => $originEmployeeId, 'previous_user_id' => $previousUserId];
        $existing = app(DocumentRequestService::class)->findExisting($company->id, $to, (int) $incoming->document_type_id);
        if ($existing && $existing->id === $incoming->id) {
            $existing = null;
        }

        if ($existing === null) {
            $from = $incoming->document_subject_type.':'.$incoming->document_subject_id;
            $incoming->forceFill($key)->save();
            self::event($incoming, DocumentRequestEvent::E_SUBJECT_MOVED, ['from' => $from, 'to' => (string) $to]);

            return $incoming;
        }

        $incomingWins = ! $documentConflict && self::isPristine($existing) && ! self::isPristine($incoming);
        if (! $incomingWins) {
            self::foldRequest($incoming, $existing);

            return $existing;
        }

        // The destination only holds a placeholder : keep what the administrator wrote on it.
        $carry = ['message' => $incoming->message ?: $existing->message, 'due_date' => $incoming->due_date ?: $existing->due_date];
        self::foldRequest($existing, $incoming); // frees the destination key
        $from = $incoming->document_subject_type.':'.$incoming->document_subject_id;
        $incoming->forceFill($key + $carry)->save();
        self::event($incoming, DocumentRequestEvent::E_SUBJECT_MOVED, ['from' => $from, 'to' => (string) $to]);

        return $incoming;
    }

    /** A « to provide » placeholder nobody acted on. */
    private static function isPristine(DocumentRequest $request): bool
    {
        return $request->status === DocumentRequest::STATUS_REQUESTED && $request->submitted_at === null && $request->reviewed_at === null;
    }

    private static function foldRequest(DocumentRequest $folded, DocumentRequest $survivor): void
    {
        DocumentRequestEvent::where('document_request_id', $folded->id)->update(['document_request_id' => $survivor->id]);
        DocumentCampaignRecipient::where('company_id', $folded->company_id)->where('document_request_id', $folded->id)->update(['document_request_id' => $survivor->id]);
        self::event($survivor, DocumentRequestEvent::E_MERGED, ['merged_request' => $folded->only([
            'id', 'user_id', 'document_subject_type', 'document_subject_id', 'status', 'message', 'due_date', 'requested_at', 'submitted_at', 'reviewed_at', 'reviewer_id', 'review_note',
        ])]);
        $folded->delete();
    }

    /** Recipients that pointed at the moved request now designate the person's new subject. */
    private static function repointRecipients(Company $company, DocumentRequest $moved, DocumentRequest $survivor, DocumentSubject $from, DocumentSubject $to): void
    {
        DocumentCampaignRecipient::where('company_id', $company->id)->where('document_request_id', $moved->id)->update(['document_request_id' => $survivor->id]);
        self::moveRecipients($company, $from, $to);
    }

    private static function moveRecipients(Company $company, DocumentSubject $from, DocumentSubject $to): int
    {
        $moved = 0;
        $rows = DocumentCampaignRecipient::where('company_id', $company->id)->where('document_subject_type', $from->type)->where('document_subject_id', $from->id)->get();
        foreach ($rows as $row) {
            $twin = DocumentCampaignRecipient::where('document_campaign_id', $row->document_campaign_id)
                ->where('document_subject_type', $to->type)->where('document_subject_id', $to->id)->first();
            if ($twin) {
                // The person is already a recipient of this campaign under her new subject.
                if ($twin->document_request_id === null && $row->document_request_id !== null) {
                    $twin->forceFill(['document_request_id' => $row->document_request_id])->save();
                }
                $row->delete();
            } else {
                $row->forceFill(['document_subject_type' => $to->type, 'document_subject_id' => $to->id])->save();
            }
            $moved++;
        }

        return $moved;
    }

    private static function event(DocumentRequest $request, string $event, array $metadata): void
    {
        DocumentRequestEvent::create([
            'company_id' => $request->company_id, 'document_request_id' => $request->id, 'event' => $event,
            'actor_id' => null, 'metadata' => $metadata, 'created_at' => now(),
        ]);
    }

    private static function audit(Company $company, string $direction, int $employeeId, int $userId, int $rows): void
    {
        app(AuditLogger::class)->logCompany($company->id, 'document.subject_transition', 'employee', (string) $employeeId, [
            'actorType' => 'system',
            'metadata' => ['direction' => $direction, 'employee_id' => $employeeId, 'user_id' => $userId, 'rows' => $rows],
        ]);
    }
}
