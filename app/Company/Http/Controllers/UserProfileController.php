<?php

namespace App\Company\Http\Controllers;

use App\Company\Fields\ReadModels\CompanyUserProfileReadModel;
use App\Company\Http\Requests\UpdatePasswordRequest;
use App\Company\Http\Requests\UpdateProfileRequest;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UserProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(CompanyUserProfileReadModel::get($request->user(), $company));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $validated = $request->validated();

        $request->user()->update(array_intersect_key($validated, array_flip(['first_name', 'last_name', 'email'])));

        if (isset($validated['dynamic_fields'])) {
            FieldWriteService::upsert(
                $request->user(),
                $validated['dynamic_fields'],
                FieldDefinition::SCOPE_COMPANY_USER,
                $company->id,
            );
        }

        return response()->json(CompanyUserProfileReadModel::get($request->user()->fresh(), $company));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

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

        $request->user()->update([
            'avatar' => $path,
        ]);

        return response()->json([
            'user' => $request->user()->fresh(),
        ]);
    }
}
