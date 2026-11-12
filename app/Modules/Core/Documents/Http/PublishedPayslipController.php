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
        [$subject] = $this->resolve($company, $personRef);

        // Lister les bulletins d'autrui = accès payroll (pas documents.view générique).
        if (! $policy->canAccess($request->user(), $company, $subject, 'payslip', DocumentAccessPolicy::OP_VIEW_METADATA)) {
            abort(403);
        }

        return response()->json(['payslips' => $this->listFor($company->id, $subject, $request->user(), $company, $policy)]);
    }

    public function downloadPerson(Request $request, string $personRef, int $id, DocumentAccessPolicy $policy, AuditLogger $audit)
    {
        $company = $request->attributes->get('company');
        [$subject] = $this->resolve($company, $personRef);
        $payslip = PublishedPayslip::where('company_id', $company->id)
            ->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id)
            ->findOrFail($id);

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

        return response()->json(['payslips' => $this->listFor($company->id, $subject, $request->user(), $company, $policy)]);
    }

    public function downloadSelf(Request $request, int $id)
    {
        $company = $request->attributes->get('company');
        // Self : rattaché au sujet user de l'acteur (jamais celui d'un autre).
        $payslip = PublishedPayslip::where('company_id', $company->id)
            ->where('document_subject_type', DocumentSubject::TYPE_USER)
            ->where('document_subject_id', $request->user()->id)
            ->findOrFail($id);

        return Storage::disk('local')->download($payslip->file_path, $payslip->file_name);
    }

    private function listFor(int $companyId, DocumentSubject $subject, $actor, $company, DocumentAccessPolicy $policy): array
    {
        $canDownload = $policy->canAccess($actor, $company, $subject, 'payslip', DocumentAccessPolicy::OP_DOWNLOAD);

        return PublishedPayslip::where('company_id', $companyId)
            ->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id)
            ->current()
            ->orderByDesc('period')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'period' => $p->period,
                'year' => substr((string) $p->period, 0, 4),
                'version_no' => $p->version_no,
                'source' => $p->source,
                'can_download' => $canDownload,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** @return array{0:DocumentSubject} */
    private function resolve($company, string $personRef): array
    {
        $ref = PersonRef::parse($personRef);
        abort_if($ref === null, 422, 'Invalid person reference.');
        $ids = PersonSubjectResolver::identify($company, $ref->type, $ref->id);
        $resolved = PersonSubjectResolver::resolve($company, $ids['user'], $ids['employee']);
        abort_if($resolved['canonical'] === null, 422);

        return [$resolved['canonical']];
    }
}
