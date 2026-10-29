<?php

namespace App\Modules\Workforce\Signature\Http;

use App\Core\Workforce\EmploymentContract;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Security\WorkforceAccess;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\ReadModels\SignatureReadModel;
use App\Modules\Workforce\Signature\Services\SignatureLevelCatalog;
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

    /** Preuves / journal d'audit — endpoint gated par workforce_signature.view_evidence. */
    public function evidence(Request $request, int $id): JsonResponse
    {
        $req = $this->findScoped($request, $id);
        $req->load(['signers', 'events', 'document.template']);

        return response()->json(SignatureReadModel::present($req, withEvents: true));
    }

    public function options(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $cfg = app(SignatureSettingsService::class)->resolve($companyId);
        $company = $request->attributes->get('company') ?? \App\Core\Models\Company::withoutGlobalScopes()->find($companyId);
        $market = $company?->market_key ?? 'FR';
        $providerKey = $cfg['provider'];
        $adapter = SignatureProviderManager::adapterFor($providerKey);

        return response()->json([
            'provider' => $adapter?->key(),
            'provider_certified' => $adapter?->isCertified() ?? false,
            'provider_dev_only' => $adapter?->isDevOnly() ?? false,
            'levels' => SignatureLevelCatalog::describe($market, $providerKey),
            'orders' => SignatureRequest::ORDERS,
            'default_level' => $cfg['default_level'],
            'default_order' => $cfg['default_order'],
        ]);
    }

    /** Documents RH émis éligibles à une demande (scopés, non signés, sans demande active). */
    public function eligibleDocuments(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
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
            ->filter(fn ($d) => $this->subjectInScope($request, $d->subject_type, (int) $d->subject_id))
            ->map(fn ($d) => [
                'id' => $d->id,
                'label' => $d->template?->name ?? 'Document',
                'subject_type' => $d->subject_type,
                'subject_id' => $d->subject_id,
                'generated_at' => $d->generated_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $docs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generated_document_id' => 'required|integer',
            'signers' => 'required|array|min:1',
            'signers.*.role' => 'required|in:' . implode(',', SignatureRequest::ORDERS) . ',company_representative,employee,external',
            'signers.*.name' => 'required|string|max:255',
            'signers.*.email' => 'required|email|max:255',
            'signers.*.order' => 'nullable|integer|min:1',
            'signers.*.party_type' => 'nullable|in:user,employee',
            'signers.*.party_id' => 'nullable|integer',
            'signature_level' => 'nullable|in:' . implode(',', SignatureRequest::LEVELS),
            'signing_order' => 'nullable|in:' . implode(',', SignatureRequest::ORDERS),
            'provider' => 'nullable|string|max:50',
        ]);

        // Vérifie l'accès au document source (scope collaborateur) AVANT création.
        $this->assertDocumentScope($request, (int) $data['generated_document_id']);

        $companyId = $this->companyId($request);
        $actorId = (int) $request->user()->id;

        $req = app(CreateSignatureRequestUseCase::class)->execute(
            companyId: $companyId,
            actorId: $actorId,
            generatedDocumentId: (int) $data['generated_document_id'],
            signers: $data['signers'],
            level: $data['signature_level'] ?? null,
            signingOrder: $data['signing_order'] ?? null,
            provider: $data['provider'] ?? null,
        );

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

    public function downloadSigned(Request $request, int $id): StreamedResponse
    {
        $req = $this->findScoped($request, $id);
        abort_unless($req->signed_document_path, 404, 'No signed document available.');
        $disk = config('documents.generated_disk', 'local');
        abort_unless(Storage::disk($disk)->exists($req->signed_document_path), 404, 'Signed document file not found.');

        return Storage::disk($disk)->download($req->signed_document_path, 'signed-' . $id . '.pdf');
    }

    public function getSettings(Request $request): JsonResponse
    {
        return response()->json(app(SignatureSettingsService::class)->resolve($this->companyId($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'nullable|string|max:50',
            'default_level' => 'nullable|in:' . implode(',', SignatureRequest::LEVELS),
            'validity_days' => 'nullable|integer|min:1|max:365',
            'reminder_days' => 'nullable|integer|min:1|max:365',
            'default_order' => 'nullable|in:' . implode(',', SignatureRequest::ORDERS),
            'notify_enabled' => 'nullable|boolean',
            'send_signed_copy_to_employee' => 'nullable|boolean',
        ]);

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

    private function assertSubjectScope(Request $request, ?string $subjectType, int $subjectId): void
    {
        if ($subjectType === 'employee') {
            abort_unless(WorkforceAccess::canViewEmployeeData($request, $subjectId, 'employee'), 403, 'Not authorized for this employee.');
        } elseif ($subjectType === 'contract') {
            $employeeId = EmploymentContract::withoutGlobalScopes()
                ->where('company_id', $this->companyId($request))
                ->where('id', $subjectId)->value('employee_id');
            if ($employeeId) {
                abort_unless(WorkforceAccess::canViewEmployeeData($request, (int) $employeeId, 'employee'), 403, 'Not authorized for this contract.');
            }
        }
    }

}
