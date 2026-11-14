<?php

namespace App\Modules\Core\Documents\Search;

use App\Core\Documents\DocumentLifecycleService as Lifecycle;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Search\DocumentSearchQuery;
use App\Core\Documents\Search\DocumentSearchRow;
use App\Core\Documents\Search\DocumentSearchSource;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Home\HomeContext;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ADR-778 (UX3) — documents of PEOPLE (members and employees without account). Every row goes
 * through DocumentAccessPolicy with ITS subject and ITS type : a confidential piece is never
 * returned because it matches the term. A refused row is dropped entirely (no redacted line).
 */
class PersonDocumentsSearchSource implements DocumentSearchSource
{
    public function key(): string
    {
        return 'person';
    }

    public function search(HomeContext $viewer, DocumentSearchQuery $query, int $limit): array
    {
        if (! $viewer->can('documents.view') || in_array($query->status, ['signed', 'pending_signature'], true)) {
            return ['rows' => [], 'truncated' => false];
        }
        $companyId = (int) $viewer->company->id;
        $like = $query->like();

        $builder = MemberDocument::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereNotNull('file_path')->whereNull('previous_user_id')->with('documentType:id,code,label')
            ->when($query->typeCode, fn ($q, $code) => $q->whereHas('documentType', fn ($t) => $t->where('code', $code)))
            ->tap(fn ($q) => self::applyDates($q, $query));

        if ($query->personRef !== null && preg_match('/^(employee|user)-(\d+)$/', $query->personRef, $m)) {
            $builder->where(fn ($w) => $w->where(fn ($a) => $a->where('document_subject_type', $m[1])->where('document_subject_id', (int) $m[2]))
                ->when($m[1] === 'user', fn ($o) => $o->orWhere(fn ($b) => $b->whereNull('document_subject_type')->where('user_id', (int) $m[2]))));
        }
        if ($like !== null) {
            $users = User::query()->where(fn ($w) => self::matchName($w, $query->term))->select('id');
            $employees = DB::table('workforce_employees')->where('company_id', $companyId)->where(fn ($w) => self::matchName($w, $query->term))->select('id');
            $builder->where(fn ($w) => $w->where('file_name', 'like', $like)
                ->orWhereHas('documentType', fn ($t) => $t->where('label', 'like', $like)->orWhere('code', 'like', $like))
                ->orWhere(fn ($u) => $u->where(fn ($s) => $s->where('document_subject_type', DocumentSubject::TYPE_USER)->orWhereNull('document_subject_type'))->whereIn('user_id', $users))
                ->orWhere(fn ($e) => $e->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->whereIn('document_subject_id', $employees)));
        }

        // Authorisation happens row by row : read a bounded window, keep what the viewer may see.
        $docs = $builder->latest('updated_at')->limit($limit * 3 + 1)->get();
        $policy = app(DocumentAccessPolicy::class);
        $allowed = [];
        foreach ($docs->take($limit * 3) as $doc) {
            $subject = DocumentSubject::fromMemberDocument($doc);
            if ($policy->canAccess($viewer->user, $viewer->company, $subject, $doc->documentType?->code, DocumentAccessPolicy::OP_VIEW_METADATA)) {
                $allowed[] = [$doc, $subject];
            }
            if (count($allowed) >= $limit) {
                break;
            }
        }

        $names = self::names($companyId, $allowed);
        $rows = [];
        foreach ($allowed as [$doc, $subject]) {
            $ref = "{$subject->type}-{$subject->id}";
            $code = $doc->documentType?->code;
            $rows[] = DocumentSearchRow::make('person', (int) $doc->id, $doc->documentType?->label ?: (string) $doc->file_name, $code, $doc->documentType?->label,
                ['ref' => $ref, 'name' => $names[$ref] ?? ''], self::status(Lifecycle::computeStatus(['expires_at' => $doc->expires_at])),
                $doc->expires_at?->toDateString(), $doc->updated_at?->toIso8601String(),
                ['name' => 'company-people-person-ref', 'params' => ['personRef' => $ref], 'query' => ['tab' => 'documents']],
                $code ? "/company/people/{$ref}/documents/{$code}/download" : null);
        }

        return ['rows' => $rows, 'truncated' => $docs->count() > $limit * 3 || count($allowed) >= $limit];
    }

    /** Period, expiry and lifecycle status — shared by the sources reading `expires_at`. */
    public static function applyDates(Builder $q, DocumentSearchQuery $query): void
    {
        $soon = now()->addDays(Lifecycle::DEFAULT_EXPIRING_SOON_DAYS);
        $q->when($query->from, fn ($w, $d) => $w->where('updated_at', '>=', $d))
            ->when($query->to, fn ($w, $d) => $w->where('updated_at', '<=', $d))
            ->when($query->expiresBefore, fn ($w, $d) => $w->whereNotNull('expires_at')->where('expires_at', '<=', $d))
            ->when($query->status === 'expired', fn ($w) => $w->whereNotNull('expires_at')->where('expires_at', '<', now()))
            ->when($query->status === 'expiring', fn ($w) => $w->whereNotNull('expires_at')->where('expires_at', '>=', now())->where('expires_at', '<=', $soon))
            ->when($query->status === 'valid', fn ($w) => $w->where(fn ($v) => $v->whereNull('expires_at')->orWhere('expires_at', '>', $soon)));
    }

    /** A person matches when EVERY word of the term is in her first or last name (portable SQL, no concatenation). */
    public static function matchName($query, string $term): void
    {
        foreach (preg_split('/\s+/', trim($term)) as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
            $query->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }
    }

    public static function status(string $lifecycle): string
    {
        return $lifecycle === Lifecycle::STATUS_EXPIRING_SOON ? 'expiring' : $lifecycle;
    }

    /** @return array<string, string> personRef => display name (one query per subject kind) */
    private static function names(int $companyId, array $allowed): array
    {
        $ids = ['user' => [], 'employee' => []];
        foreach ($allowed as [, $subject]) {
            $ids[$subject->type][] = $subject->id;
        }
        $names = [];
        foreach (User::whereIn('id', array_unique($ids['user']))->get(['id', 'first_name', 'last_name']) as $u) {
            $names["user-{$u->id}"] = trim("{$u->first_name} {$u->last_name}");
        }
        foreach (DB::table('workforce_employees')->where('company_id', $companyId)->whereIn('id', array_unique($ids['employee']))->get(['id', 'first_name', 'last_name']) as $e) {
            $names["employee-{$e->id}"] = trim("{$e->first_name} {$e->last_name}");
        }

        return $names;
    }
}
