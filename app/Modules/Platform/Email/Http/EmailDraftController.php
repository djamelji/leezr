<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailComposeService;
use App\Core\Email\EmailDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailDraftController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:500',
            'body' => 'nullable|string|max:50000',
            'cc' => 'nullable|string|max:1000',
            'bcc' => 'nullable|string|max:1000',
        ]);

        $result = app(EmailDraftService::class)->persist($validated);

        return response()->json($result);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:500',
            'body' => 'nullable|string|max:50000',
            'cc' => 'nullable|string|max:1000',
            'bcc' => 'nullable|string|max:1000',
        ]);

        $result = app(EmailDraftService::class)->persist(array_merge($validated, ['draft_id' => $id]));

        return response()->json($result);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string|max:500',
            'body' => 'required|string|max:50000',
            'cc' => 'nullable|string|max:1000',
            'bcc' => 'nullable|string|max:1000',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'integer',
        ]);

        $result = app(EmailDraftService::class)->send($id, $validated);

        return response()->json($result);
    }

    public function destroy(int $id): JsonResponse
    {
        app(EmailDraftService::class)->delete($id);

        return response()->json(['message' => 'Draft deleted.']);
    }
}
