<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Documents\Services\PersonSubjectResolver;
use App\Modules\Core\People\PersonRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-762 (DMS M5) — consultation des bulletins PUBLIÉS (série par année/période), côté fiche
 * Collaborateur et self-service. Jamais exposé via `documents.view` générique : chaque accès passe
 * par DocumentAccessPolicy (catégorie payroll). Un collaborateur ne voit QUE ses bulletins.
 */
class PublishedPayslipController extends Controller
{
    /** Liste (métadonnées) des bulletins d'une personne — fiche Collaborateur. */
    public function person(Request $request, string $personRef, DocumentAccessPolicy $policy): JsonResponse
    {
        $company = $request->attributes->get('company');
        [$subject, $subjects] = $this->resolve($company, $personRef);

        // Lister les bulletins d'autrui = accès payroll (pas documents.view générique).
        if (! $policy->canAccess($request->user(), $company, $subject, 'payslip', DocumentAccessPolicy::OP_VIEW_METADATA)) {
            abort(403);
        }

        return response()->json(['payslips' => $this->listFor($company->id, $subject, $request->user(), $company, $policy, $subjects)]);
    }

    public function downloadPerson(Request $request, string $personRef, int $id, DocumentAccessPolicy $policy, AuditLogger $audit)
    {
        $company = $request->attributes->get('company');
        [$subject, $subjects] = $this->resolve($company, $personRef);
        $payslip = $this->ofSubjects(PublishedPayslip::where('company_id', $company->id), $subjects)->findOrFail($id);

        abort_if($payslip->revoked_at !== null, 410, 'This payslip has been revoked.'); // retrait : plus consultable
        if (! $policy->canAccess($request->user(), $company, $subject, 'payslip', DocumentAccessPolicy::OP_DOWNLOAD)) {
            abort(403, 'Not authorized to download this payslip.');
        }
        $audit->logCompany($company->id, 'payslip.download', 'published_payslip', (string) $payslip->id, ['severity' => 'notice', 'metadata' => ['actor' => $request->user()?->id]]);

        return Storage::disk('local')->download($payslip->file_path, $payslip->file_name);
    }

    /** Self-service : mes propres bulletins. */
    public function self(Request $request, DocumentAccessPolicy $policy): JsonResponse
    {
        $company = $request->attributes->get('company');
        $subject = DocumentSubject::user((int) $request->user()->id);

        return response()->json(['payslips' => $this->listFor($company->id, $subject, $request->user(), $company, $policy, $this->ownSubjects($request))]);
    }

    public function downloadSelf(Request $request, int $id)
    {
        $company = $request->attributes->get('company');
        // Self : rattaché au sujet user de l'acteur (jamais celui d'un autre).
        $payslip = $this->ofSubjects(PublishedPayslip::where('company_id', $company->id), $this->ownSubjects($request))->findOrFail($id);
        abort_if($payslip->revoked_at !== null, 410, 'This payslip has been revoked.');

        return Storage::disk('local')->download($payslip->file_path, $payslip->file_name);
    }

    /**
     * ADR-769 : the payslips of a PERSON, whatever subject they were published under. A payslip
     * published to an HR record before its account existed (subject `employee`) still belongs to the
     * person once the account is linked (canonical subject `user`) — it must not vanish.
     *
     * @param  list<DocumentSubject>|null  $subjects
     */
    private function listFor(int $companyId, DocumentSubject $subject, $actor, $company, DocumentAccessPolicy $policy, ?array $subjects = null): array
    {
        $canDownload = $policy->canAccess($actor, $company, $subject, 'payslip', DocumentAccessPolicy::OP_DOWNLOAD);

        return $this->ofSubjects(PublishedPayslip::where('company_id', $companyId), $subjects ?: [$subject])
            ->current()
            ->orderByDesc('period')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'period' => $p->period,
                'year' => substr((string) $p->period, 0, 4),
                'version_no' => $p->version_no,
                'source' => $p->source,
                'status' => $p->revoked_at ? 'revoked' : 'available',
                'can_download' => $canDownload && $p->revoked_at === null,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** @param list<DocumentSubject> $subjects */
    private function ofSubjects($query, array $subjects)
    {
        return $query->where(function ($q) use ($subjects) {
            foreach ($subjects as $s) {
                $q->orWhere(fn ($w) => $w->where('document_subject_type', $s->type)->where('document_subject_id', $s->id));
            }
        });
    }

    /** Subjects of the CONNECTED person in this company : her account + the HR record explicitly linked to it. */
    private function ownSubjects(Request $request): array
    {
        $company = $request->attributes->get('company');
        $employee = \App\Core\Workforce\Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('user_id', $request->user()->id)->first();

        return PersonSubjectResolver::resolve($company, $request->user(), $employee)['subjects'];
    }

    /** @return array{0:DocumentSubject, 1:list<DocumentSubject>} */
    private function resolve($company, string $personRef): array
    {
        $ref = PersonRef::parse($personRef);
        abort_if($ref === null, 422, 'Invalid person reference.');
        $ids = PersonSubjectResolver::identify($company, $ref->type, $ref->id);
        $resolved = PersonSubjectResolver::resolve($company, $ids['user'], $ids['employee']);
        abort_if($resolved['canonical'] === null, 422);

        return [$resolved['canonical'], $resolved['subjects']];
    }
}
