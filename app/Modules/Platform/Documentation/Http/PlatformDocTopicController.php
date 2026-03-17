<?php

namespace App\Modules\Platform\Documentation\Http;

use App\Core\Documentation\DocumentationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformDocTopicController
{
    public function index(Request $request): JsonResponse
    {
        $query = DocumentationTopic::query()
            ->withCount('articles')
            ->with('creator:id,first_name,last_name')
            ->orderBy('sort_order');

        if ($request->filled('audience')) {
            $query->where('audience', $request->input('audience'));
        }

        if ($request->filled('is_published')) {
            $query->where('is_published', filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('title', 'like', "%{$search}%");
        }

        $perPage = min((int) $request->input('per_page', 15), 50);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:100',
            'group_id' => 'nullable|exists:documentation_groups,id',
            'audience' => 'required|string|in:platform,company,public',
            'is_published' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $slug = Str::slug($validated['title']);
        $baseSlug = $slug;
        $counter = 1;

        while (DocumentationTopic::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $topic = DocumentationTopic::create([
            ...$validated,
            'slug' => $slug,
            'created_by_platform_user_id' => $request->user('platform')->id,
        ]);

        return response()->json($topic->load('creator:id,first_name,last_name'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $topic = DocumentationTopic::with([
            'creator:id,first_name,last_name',
            'articles' => fn ($q) => $q->orderBy('sort_order')
                ->withCount([
                    'feedbacks as helpful_count' => fn ($f) => $f->where('helpful', true),
                    'feedbacks as not_helpful_count' => fn ($f) => $f->where('helpful', false),
                ]),
        ])->findOrFail($id);

        return response()->json($topic);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $topic = DocumentationTopic::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:100',
            'group_id' => 'nullable|exists:documentation_groups,id',
            'audience' => 'sometimes|string|in:platform,company,public',
            'is_published' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $topic->update($validated);

        return response()->json($topic->fresh()->load('creator:id,first_name,last_name'));
    }

    public function destroy(int $id): JsonResponse
    {
        $topic = DocumentationTopic::findOrFail($id);
        $topic->delete();

        return response()->json(null, 204);
    }
}
