<?php

namespace App\Modules\Platform\Documentation\Http;

use App\Core\Documentation\DocumentationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformDocGroupController
{
    public function index(Request $request): JsonResponse
    {
        $query = DocumentationGroup::query()
            ->withCount('topics')
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
            'icon' => 'nullable|string|max:100',
            'audience' => 'required|string|in:platform,company,public',
            'is_published' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $slug = Str::slug($validated['title']);
        $baseSlug = $slug;
        $counter = 1;

        while (DocumentationGroup::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $group = DocumentationGroup::create([
            ...$validated,
            'slug' => $slug,
            'created_by_platform_user_id' => $request->user('platform')->id,
        ]);

        return response()->json($group->load('creator:id,first_name,last_name'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $group = DocumentationGroup::with([
            'creator:id,first_name,last_name',
            'topics' => fn ($q) => $q->orderBy('sort_order')->withCount('articles'),
        ])->findOrFail($id);

        return response()->json($group);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = DocumentationGroup::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'icon' => 'nullable|string|max:100',
            'audience' => 'sometimes|string|in:platform,company,public',
            'is_published' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $group->update($validated);

        return response()->json($group->fresh()->load('creator:id,first_name,last_name'));
    }

    public function destroy(int $id): JsonResponse
    {
        $group = DocumentationGroup::findOrFail($id);
        // Topics become orphans (group_id = null), not deleted
        $group->topics()->update(['group_id' => null]);
        $group->delete();

        return response()->json(null, 204);
    }
}
