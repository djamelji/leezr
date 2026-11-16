<?php

namespace App\Modules\Workforce\Onboarding\Http;

use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\OnboardingInvitationService;
use App\Modules\Workforce\Onboarding\OnboardingPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * ADR-796 (L6) — HR side of onboarding files (workforce.manage, route). Sensitive candidate values are
 * shown only with workforce.sensitive_read / members.sensitive_read.
 */
class OnboardingController extends Controller
{
    private function service(Request $request): OnboardingInvitationService
    {
        return new OnboardingInvitationService($request->attributes->get('company'), $request->user());
    }

    private function canSensitive(Request $request): bool
    {
        $c = $request->attributes->get('company');

        return $request->user()->hasCompanyPermission($c, 'workforce.sensitive_read') || $request->user()->hasCompanyPermission($c, 'members.sensitive_read');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['invitations' => $this->service($request)->index()]);
    }

    public function proposal(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $request->validate(['job_role_id' => 'nullable|integer']);
        $job = $request->filled('job_role_id') ? JobRole::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($request->integer('job_role_id')) : null;

        return response()->json(['items' => (new OnboardingPlanner($company))->propose($job),
            'purposes' => Inv::PURPOSES, 'legal_bases' => Inv::LEGAL_BASES]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'nullable|integer',
            'first_name' => 'required_without:employee_id|nullable|string|max:100',
            'last_name' => 'required_without:employee_id|nullable|string|max:100',
            'email' => 'nullable|email|max:191',
            'hire_date' => 'required_without:employee_id|nullable|date',
            'candidate_email' => 'nullable|email|max:191',
            'job_role_id' => 'nullable|integer',
            'items' => 'required|array|min:1|max:80',
            'items.*.kind' => 'required|in:field,document',
            'items.*.code' => 'required|string|max:100',
            'items.*.required' => 'sometimes|boolean',
            'purpose' => ['required', Rule::in(Inv::PURPOSES)],
            'legal_basis' => ['nullable', Rule::in(Inv::LEGAL_BASES)],
            'notice_text' => 'nullable|string|max:4000',
            'retention_days' => 'nullable|integer|min:1|max:365',
        ]);
        $inv = $this->service($request)->create($data);

        return response()->json($this->service($request)->detail($inv, $this->canSensitive($request)), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $s = $this->service($request);

        return response()->json($s->detail($s->find($id), $this->canSensitive($request)));
    }

    public function link(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['days' => 'nullable|integer|min:1|max:30']);
        $s = $this->service($request);

        return response()->json(['url' => $s->issueLink($s->find($id), $data['days'] ?? 7)]); // affiché une seule fois
    }

    private const ITEMS_RULES = ['items' => 'required|array|min:1|max:80', 'items.*.kind' => 'required|in:field,document', 'items.*.code' => 'required|string|max:100', 'items.*.required' => 'sometimes|boolean'];

    public function revise(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(self::ITEMS_RULES + ['message' => 'nullable|string|max:2000']);
        $s = $this->service($request);

        return response()->json($s->detail($s->revise($s->find($id), $data['items'], $data['message'] ?? null), $this->canSensitive($request)));
    }

    public function previewRevision(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(self::ITEMS_RULES);
        $s = $this->service($request);

        return response()->json($s->previewRevision($s->find($id), $data['items']));
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['days' => 'nullable|integer|min:1|max:30']);
        $s = $this->service($request);
        $result = $s->sendInvitation($s->find($id), $data['days'] ?? 7); // le lien ne revient jamais dans la réponse

        return response()->json($result + ['file' => $s->detail($s->find($id), $this->canSensitive($request))], $result['delivered'] ? 200 : 502);
    }

    public function contact(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['candidate_email' => 'required|email|max:191']);
        $s = $this->service($request);
        $s->updateContact($s->find($id), $data['candidate_email']);

        return response()->json($s->detail($s->find($id), $this->canSensitive($request)));
    }

    public function rescan(Request $request, int $id, int $upload): JsonResponse
    {
        $s = $this->service($request);
        $s->retryScan($s->find($id), $upload);

        return response()->json($s->detail($s->find($id), $this->canSensitive($request)));
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $s = $this->service($request);
        $s->revoke($s->find($id));

        return response()->json(['revoked' => true]);
    }

    public function requestChanges(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|max:2000']);
        $s = $this->service($request);
        $s->requestChanges($s->find($id), $data['message']);

        return response()->json($s->detail($s->find($id), $this->canSensitive($request)));
    }

    public function validateFile(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decisions' => 'present|array', 'decisions.*' => 'in:accept,keep', 'confirm_contractual' => 'sometimes|boolean']);
        $s = $this->service($request);
        $result = $s->validate($s->find($id), $data['decisions'], (bool) ($data['confirm_contractual'] ?? false));

        return response()->json($result + ['file' => $s->detail($s->find($id), $this->canSensitive($request))]);
    }
}
