<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldWriteService;
use App\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-426: Apply AI-extracted suggestions to member profile fields.
 *
 * Separated from MemberDocumentController to respect the 250-line limit.
 */
class MemberDocumentAiSuggestionController extends Controller
{
    /**
     * Apply selected AI suggestions to a member's profile fields.
     *
     * POST /company/members/{membershipId}/documents/{documentCode}/apply-suggestions
     * Body: { "fields": ["first_name", "last_name"] }
     */
    public function apply(Request $request, int $membershipId, string $documentCode): JsonResponse
    {
        $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');
        $membership = $company->memberships()->findOrFail($membershipId);
        $user = User::findOrFail($membership->user_id);

        $type = DocumentType::where('code', $documentCode)->firstOrFail();

        $document = MemberDocument::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        $suggestions = $document->ai_suggestions;
        if (empty($suggestions)) {
            return response()->json(['message' => 'No AI suggestions available.'], 422);
        }

        // Index suggestions by field code
        $suggestionsByField = collect($suggestions)->keyBy('field');
        $requestedFields = $request->input('fields');

        // Build the dynamic fields array for FieldWriteService
        $dynamicFields = [];
        $appliedFields = [];

        foreach ($requestedFields as $fieldCode) {
            $suggestion = $suggestionsByField->get($fieldCode);
            if (! $suggestion) {
                continue;
            }

            $dynamicFields[$suggestion['field']] = $suggestion['value'];
            $appliedFields[] = [
                'field' => $suggestion['field'],
                'value' => $suggestion['value'],
            ];
        }

        if (empty($dynamicFields)) {
            return response()->json(['message' => 'No matching suggestions found.'], 422);
        }

        // Write the values to the member's profile using FieldWriteService
        FieldWriteService::upsert(
            $user,
            $dynamicFields,
            FieldDefinition::SCOPE_COMPANY_USER,
            $company->id,
            $company->market_key,
        );

        return response()->json([
            'message' => 'Suggestions applied.',
            'applied' => $appliedFields,
        ]);
    }
}
