<?php

namespace App\Modules\Workforce\Signature\Http;

use App\Core\Workforce\EmploymentContract;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\ReadModels\SignatureReadModel;
use App\Modules\Workforce\Signature\Services\SignatureSettingsService;
use App\Modules\Workforce\Signature\UseCases\CancelSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\CreateSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\RemindSignatureRequestUseCase;
use App\Modules\Workforce\Signature\UseCases\SendSignatureRequestUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W-SIGNATURE-1 (ADR-651) — API Company des demandes de signature.
 */
class SignatureController extends Controller
{
    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('company_id');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'provider', 'search', 'per_page']);

        return response()->json(SignatureReadModel::list($request, $this->companyId($request), $filters));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $req = $this->findScoped($request, $id);

        return response()->json(SignatureReadModel::present($req));
    }

    /**
     * W-SIGNATURE-13 (§3) — export du DOSSIER DE PREUVES vérifiable (document signé + manifeste + intégrité).
     * Jamais de clé privée/token/OTP/session. Gated view_evidence, company-scoped.
     */
    public function evidencePackage(Request $request, int $id): JsonResponse
    {
        $req = $this->findScoped($request, $id);
        $req->load(['signers', 'events', 'document.template']);
        $package = app(\App\Modules\Workforce\Signature\Services\EvidencePackageService::class)->build($req);

        return response()->json($package)
            ->header('Content-Disposition', 'attachment; filename="evidence-' . $req->id . '.json"');
    }

    /**
     * W-SIGNATURE-13 (§5) — diagnostics de RÉCONCILIATION (non destructifs, lecture seule). Listes bornées
     * et non sensibles d'anomalies d'exploitation. Gated view_evidence, company-scoped.
     */
    public function reconciliation(Request $request): JsonResponse
    {
        return response()->json(\App\Modules\Workforce\Signature\ReadModels\SignatureOperationsReadModel::reconciliation($this->companyId($request))); // ADR-848
    }

    /** Preuves / journal d'audit — endpoint gated par workforce_signature.view_evidence. */
    public function evidence(Request $request, int $id): JsonResponse
    {
        $req = $this->findScoped($request, $id);
        $req->load(['signers', 'events', 'document.template']);

        return response()->json(SignatureReadModel::present($req, withEvents: true));
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(app(SignatureSettingsService::class)->options($this->companyId($request), $request->attributes->get('company'))); // ADR-848
    }

    /** Demandes de signature liées à un collaborateur (sujet employé OU ses contrats), scopées. */
    public function byEmployee(Request $request, int $employeeId): JsonResponse
    {
        abort_unless(WorkforceAccess::canViewEmployeeData($request, $employeeId, 'employee'), 403);
        $companyId = $this->companyId($request);

        return response()->json(\App\Modules\Workforce\Signature\ReadModels\SignatureOperationsReadModel::forEmployee($this->companyId($request), $employeeId)); // ADR-848
    }

    /** Documents RH émis éligibles à une demande (scopés, non signés, sans demande active). */
    public function eligibleDocuments(Request $request): JsonResponse
    {
        $docs = \App\Modules\Workforce\Signature\ReadModels\SignatureOperationsReadModel::eligibleDocuments(
            $this->companyId($request), fn ($d) => $this->subjectInScope($request, $d->subject_type, (int) $d->subject_id),
        ); // ADR-848

        return response()->json(['data' => $docs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(CreateSignatureRequestUseCase::rules()); // ADR-848

        // Vérifie l'accès au document source (scope collaborateur) AVANT création.
        $this->assertDocumentScope($request, (int) $data['generated_document_id']);

        $companyId = $this->companyId($request);
        $actorId = (int) $request->user()->id;

        $req = app(CreateSignatureRequestUseCase::class)->executeValidated($companyId, $actorId, $data); // ADR-848

        // Confirmer = créer puis envoyer (invitations).
        $req = app(SendSignatureRequestUseCase::class)->execute($companyId, $actorId, $req->id);

        return response()->json(SignatureReadModel::present($req->load(['signers', 'document.template'])), 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->findScoped($request, $id);
        $req = app(CancelSignatureRequestUseCase::class)->execute(
            $this->companyId($request), (int) $request->user()->id, $id, $request->input('reason'),
        );

        return response()->json(SignatureReadModel::present($req->load(['signers', 'document.template'])));
    }

    public function remind(Request $request, int $id): JsonResponse
    {
        $this->findScoped($request, $id);
        $count = app(RemindSignatureRequestUseCase::class)->execute(
            $this->companyId($request), (int) $request->user()->id, $id,
        );

        return response()->json(['reminded' => $count]);
    }

    /**
     * W-SIGNATURE-9 (§3) — réinitialisation de la clé d'un signataire par l'émetteur (clé perdue, externe
     * bloqué). Révoque la clé actuelle et renvoie une invitation pour que le signataire ré-enrôle SA propre
     * nouvelle clé. L'émetteur ne signe JAMAIS à la place du signataire. Gated par workforce_signature.manage.
     */
    public function resetSignerKey(Request $request, int $id, int $signerId): JsonResponse
    {
        $req = $this->findScoped($request, $id);
        $signer = \App\Modules\Workforce\Signature\Models\SignatureSigner::withoutGlobalScopes()
            ->where('signature_request_id', $req->id)->where('id', $signerId)->firstOrFail();

        app(\App\Modules\Workforce\Signature\Services\SignerEnrollmentService::class)->adminResetKeyAndReinvite($req, $signer, (int) $request->user()->id); // ADR-848

        return response()->json(['reset' => true]);
    }

    /**
     * Vérification cryptographique INDÉPENDANTE du document final (§7) — ADR-848 : SignatureVerificationReport.
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        [$payload, $status] = \App\Modules\Workforce\Signature\ReadModels\SignatureVerificationReport::for($this->findScoped($request, $id));

        return response()->json($payload, $status);
    }

    public function downloadSigned(Request $request, int $id): StreamedResponse
    {
        $req = $this->findScoped($request, $id);
        abort_unless($req->signed_document_path, 404, 'No signed document available.');
        $disk = config('documents.generated_disk', 'local');
        abort_unless(Storage::disk($disk)->exists($req->signed_document_path), 404, 'Signed document file not found.');

        return Storage::disk($disk)->download($req->signed_document_path, 'signed-' . $id . '.pdf');
    }

    /**
     * W-SIGNATURE-12 (§4) — supervision OPÉRATIONNELLE (company-scoped, agrégée). Métriques non sensibles
     * pour piloter l'exploitation : demandes en attente/expirées, échecs, préparations expirées, éléments
     * à réconcilier. Aucun contenu documentaire ni token exposé. Gated par la permission de supervision.
     */
    public function supervision(Request $request): JsonResponse
    {
        return response()->json(\App\Modules\Workforce\Signature\ReadModels\SignatureOperationsReadModel::supervision($this->companyId($request))); // ADR-848
    }

    /**
     * W-SIGNATURE-12 (§6) — registre des certificats de signature (company-scoped). Aucune clé privée
     * (les certs n'en contiennent pas) ; AUCUNE action de signature. Lecture seule, gated.
     */
    public function certificates(Request $request): JsonResponse
    {
        return response()->json(\App\Modules\Workforce\Signature\ReadModels\SignatureOperationsReadModel::certificates($this->companyId($request))); // ADR-848
    }

    public function getSettings(Request $request): JsonResponse
    {
        return response()->json(app(SignatureSettingsService::class)->withFormats($this->companyId($request), $request->attributes->get('company'))); // ADR-848
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(SignatureSettingsService::rules()); // ADR-848

        return response()->json(app(SignatureSettingsService::class)->update($this->companyId($request), $data));
    }

    // ── Scope helpers ──

    private function findScoped(Request $request, int $id): SignatureRequest
    {
        $req = SignatureRequest::withoutGlobalScopes()
            ->where('company_id', $this->companyId($request))
            ->with(['signers', 'document.template'])
            ->findOrFail($id);
        $this->assertSubjectScope($request, $req->subject_type, (int) $req->subject_id);

        return $req;
    }

    private function assertDocumentScope(Request $request, int $generatedDocumentId): void
    {
        $doc = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $this->companyId($request))
            ->findOrFail($generatedDocumentId);
        $this->assertSubjectScope($request, $doc->subject_type, (int) $doc->subject_id);
    }

    private function subjectInScope(Request $request, ?string $subjectType, int $subjectId): bool
    {
        if ($subjectType === 'employee') {
            return WorkforceAccess::canViewEmployeeData($request, $subjectId, 'employee');
        }
        if ($subjectType === 'contract') {
            $employeeId = EmploymentContract::withoutGlobalScopes()
                ->where('company_id', $this->companyId($request))
                ->where('id', $subjectId)->value('employee_id');

            return ! $employeeId || WorkforceAccess::canViewEmployeeData($request, (int) $employeeId, 'employee');
        }

        return true;
    }

    /** Same rule as subjectInScope (ADR-848 : was a second copy of it), with the 403 message of the subject type. */
    private function assertSubjectScope(Request $request, ?string $subjectType, int $subjectId): void
    {
        abort_unless($this->subjectInScope($request, $subjectType, $subjectId), 403, $subjectType === 'contract' ? 'Not authorized for this contract.' : 'Not authorized for this employee.');
    }
}
