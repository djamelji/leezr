<?php

namespace App\Modules\Platform\Documentation\Http;

use App\Core\Documentation\DocumentationArticle;
use App\Core\Documentation\DocumentationSearchLog;
use App\Core\Documentation\DocumentationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformDocArticleController
{
    public function index(Request $request): JsonResponse
    {
        $query = DocumentationArticle::query()
            ->with('topic:id,title,slug')
            ->with('creator:id,first_name,last_name')
            ->withCount([
                'feedbacks as helpful_count' => fn ($f) => $f->where('helpful', true),
                'feedbacks as not_helpful_count' => fn ($f) => $f->where('helpful', false),
            ])
            ->orderBy('sort_order');

        if ($request->filled('topic_id')) {
            $query->where('topic_id', $request->input('topic_id'));
        }

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
            'topic_id' => 'required|exists:documentation_topics,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'excerpt' => 'nullable|string|max:500',
            'audience' => 'required|string|in:platform,company,public',
            'is_published' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $slug = Str::slug($validated['title']);
        $baseSlug = $slug;
        $counter = 1;

        while (DocumentationArticle::where('topic_id', $validated['topic_id'])->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $article = DocumentationArticle::create([
            ...$validated,
            'slug' => $slug,
            'created_by_platform_user_id' => $request->user('platform')->id,
        ]);

        return response()->json($article->load(['topic:id,title,slug', 'creator:id,first_name,last_name']), 201);
    }

    public function show(int $id): JsonResponse
    {
        $article = DocumentationArticle::with([
            'topic:id,title,slug,icon',
            'creator:id,first_name,last_name',
        ])->withCount([
            'feedbacks as helpful_count' => fn ($f) => $f->where('helpful', true),
            'feedbacks as not_helpful_count' => fn ($f) => $f->where('helpful', false),
        ])->findOrFail($id);

        // Recent feedbacks with comments
        $recentFeedbacks = $article->feedbacks()
            ->whereNotNull('comment')
            ->latest('created_at')
            ->limit(10)
            ->get(['helpful', 'comment', 'created_at']);

        return response()->json([
            'article' => $article,
            'recent_feedbacks' => $recentFeedbacks,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $article = DocumentationArticle::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'excerpt' => 'nullable|string|max:500',
            'audience' => 'sometimes|string|in:platform,company,public',
            'is_published' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $article->update($validated);

        return response()->json($article->fresh()->load(['topic:id,title,slug', 'creator:id,first_name,last_name']));
    }

    public function destroy(int $id): JsonResponse
    {
        $article = DocumentationArticle::findOrFail($id);
        $article->delete();

        return response()->json(null, 204);
    }

    public function searchMisses(): JsonResponse
    {
        $misses = DocumentationSearchLog::query()
            ->where('results_count', 0)
            ->select('query')
            ->selectRaw('count(*) as search_count')
            ->selectRaw('max(created_at) as last_searched_at')
            ->groupBy('query')
            ->orderByDesc('search_count')
            ->limit(50)
            ->get();

        return response()->json($misses);
    }

    public function feedbackStats(): JsonResponse
    {
        $articles = DocumentationArticle::query()
            ->with('topic:id,title,slug')
            ->withCount([
                'feedbacks as helpful_count' => fn ($f) => $f->where('helpful', true),
                'feedbacks as not_helpful_count' => fn ($f) => $f->where('helpful', false),
                'feedbacks as total_feedback_count',
            ])
            ->get(['id', 'topic_id', 'title', 'slug', 'audience', 'is_published'])
            ->filter(fn ($a) => $a->total_feedback_count > 0)
            ->sortByDesc(fn ($a) => $a->total_feedback_count > 0
                ? $a->not_helpful_count / $a->total_feedback_count
                : 0)
            ->take(20)
            ->values();

        return response()->json($articles);
    }
}
