<?php

namespace App\Modules\Core\Members\Http;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Company\Http\Requests\UpdatePasswordRequest;
use App\Company\Http\Requests\UpdateProfileRequest;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Fields\FieldDefinition;
use App\Core\Workforce\ContractualIdentityLock;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UserProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $membership = $company->memberships()->with('companyRole:id,key')->where('user_id', $request->user()->id)->first();
        $roleKey = $membership?->companyRole?->key;

        // ADR-169: show all categories (not just base) — aligned with member detail
        return response()->json(CompanyUserProfileReadModel::get(
            $request->user(), $company, $roleKey,
        ) + ['profile_lock' => ContractualIdentityLock::describe($request->user())]); // ADR-787
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $validated = $request->validated();

        // ADR-787 : after an established contract, identity / HR fields are read-only for the person
        // (server-side, field by field) ; refused BEFORE any write.
        // Nom (compte) : verrou ADR-787 inchangé ; champs dynamiques : écrivain canonique (ADR-795.1),
        // qui applique le même verrou sur la valeur CANONIQUE (fiche RH ou compte) et refuse les données RH.
        if ($violations = ContractualIdentityLock::violations($request->user(), array_diff_key($validated, ['dynamic_fields' => true]))) {
            throw ValidationException::withMessages($violations);
        }

        $changedCodes = [];
        $before = $request->user()->only('first_name', 'last_name', 'email');
        if (isset($validated['dynamic_fields'])) { // en premier : un refus n'applique rien
            $changedCodes = (new \App\Core\Fields\PersonFieldWriter(new \App\Core\Fields\EmployeeFieldStore($company)))->write(
                null, $request->user(), $validated['dynamic_fields'], $request->user(), \App\Core\Fields\PersonFieldWriter::CONTEXT_SELF,
            )['changed'];
        }
        $request->user()->update(array_intersect_key($validated, array_flip(['first_name', 'last_name', 'email'])));

        app(AuditLogger::class)->logCompany(
            $company->id, AuditAction::USER_PROFILE_UPDATED, 'user', (string) $request->user()->id,
            // ADR-787 : traceability of the changed profile fields (codes only, never sensitive values).
            ['diffBefore' => $before, 'diffAfter' => $request->user()->only('first_name', 'last_name', 'email'), 'metadata' => ['fields_changed' => $changedCodes]],
        );

        $membership = $company->memberships()->with('companyRole:id,key')->where('user_id', $request->user()->id)->first();
        $roleKey = $membership?->companyRole?->key;

        return response()->json(CompanyUserProfileReadModel::get(
            $request->user()->fresh(), $company, $roleKey,
        ) + ['profile_lock' => ContractualIdentityLock::describe($request->user())]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        app(AuditLogger::class)->logCompany(
            $company->id, AuditAction::USER_PASSWORD_CHANGED, 'user', (string) $request->user()->id,
        );

        return response()->json([
            'message' => 'Password updated.',
        ]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $path = $request->file('avatar')->store('avatars', 'public');

        $company = $request->attributes->get('company');
        $request->user()->update([
            'avatar' => $path,
        ]);

        app(AuditLogger::class)->logCompany(
            $company->id, AuditAction::USER_AVATAR_UPDATED, 'user', (string) $request->user()->id,
        );

        return response()->json([
            'user' => $request->user()->fresh(),
        ]);
    }
}
