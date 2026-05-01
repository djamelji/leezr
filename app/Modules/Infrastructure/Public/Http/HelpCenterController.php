<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Documentation\DocumentationArticle;
use App\Core\Documentation\DocumentationFeedback;
use App\Core\Documentation\DocumentationGroup;
use App\Core\Documentation\DocumentationSearchLog;
use App\Core\Documentation\DocumentationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HelpCenterController
{
    private function detectAudience(): string
    {
        if (Auth::guard('platform')->check()) {
            return 'platform';
        }
        if (Auth::guard('web')->check()) {
            return 'company';
        }

        return 'public';
    }

    public function index(): JsonResponse
    {
        $audience = $this->detectAudience();

        $groups = DocumentationGroup::query()
            ->published()
            ->forAudience($audience)
            ->with(['publishedTopics' => function ($q) use ($audience) {
                $q->forAudience($audience)
                    ->orderBy('sort_order')
                    ->withCount(['publishedArticles as articles_count' => function ($q2) use ($audience) {
                        $q2->forAudience($audience);
                    }])
                    ->with(['publishedArticles' => function ($q2) use ($audience) {
                        $q2->forAudience($audience)
                            ->orderBy('sort_order')
                            ->limit(5)
                            ->select(['id', 'topic_id', 'title', 'slug']);
                    }])
                    ->select(['id', 'uuid', 'title', 'slug', 'description', 'icon', 'group_id']);
            }])
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'title', 'slug', 'icon']);

        // Rename relation for frontend: published_articles → top_articles
        $groups->each(function ($group) {
            $group->publishedTopics->each(function ($topic) {
                $topic->setAttribute('top_articles', $topic->publishedArticles->take(5)->values());
                $topic->unsetRelation('publishedArticles');
            });
        });

        $ungroupedTopics = DocumentationTopic::query()
            ->published()
            ->forAudience($audience)
            ->whereNull('group_id')
            ->withCount(['publishedArticles as articles_count' => function ($q) use ($audience) {
                $q->forAudience($audience);
            }])
            ->with(['publishedArticles' => function ($q) use ($audience) {
                $q->forAudience($audience)
                    ->orderBy('sort_order')
                    ->limit(5)
                    ->select(['id', 'topic_id', 'title', 'slug']);
            }])
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'title', 'slug', 'description', 'icon']);

        $ungroupedTopics->each(function ($topic) {
            $topic->setAttribute('top_articles', $topic->publishedArticles->take(5)->values());
            $topic->unsetRelation('publishedArticles');
        });

        return response()->json([
            'groups' => $groups,
            'ungrouped_topics' => $ungroupedTopics,
            'audience' => $audience,
        ]);
    }

    public function topic(string $slug): JsonResponse
    {
        $audience = $this->detectAudience();

        $topic = DocumentationTopic::query()
            ->published()
            ->forAudience($audience)
            ->where('slug', $slug)
            ->firstOrFail();

        $articles = $topic->articles()
            ->published()
            ->forAudience($audience)
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'title', 'slug', 'excerpt']);

        return response()->json([
            'topic' => $topic->only(['id', 'uuid', 'title', 'slug', 'description', 'icon']),
            'articles' => $articles,
        ]);
    }

    public function article(Request $request, string $topicSlug, string $articleSlug): JsonResponse
    {
        $audience = $this->detectAudience();

        $topic = DocumentationTopic::query()
            ->published()
            ->forAudience($audience)
            ->where('slug', $topicSlug)
            ->firstOrFail();

        $article = $topic->articles()
            ->published()
            ->forAudience($audience)
            ->where('slug', $articleSlug)
            ->firstOrFail();

        $helpfulCount = $article->feedbacks()->where('helpful', true)->count();
        $notHelpfulCount = $article->feedbacks()->where('helpful', false)->count();

        // User's own feedback (if authenticated)
        $userFeedback = null;
        $user = Auth::guard('web')->user() ?? Auth::guard('platform')->user();
        if ($user) {
            $userType = Auth::guard('platform')->check() ? 'platform_admin' : 'company_user';
            $userFeedback = DocumentationFeedback::where('article_id', $article->id)
                ->where('user_type', $userType)
                ->where('user_id', $user->id)
                ->first(['helpful', 'comment']);
        }

        $siblings = $topic->articles()
            ->published()
            ->forAudience($audience)
            ->where('id', '!=', $article->id)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'slug']);

        return response()->json([
            'topic' => $topic->only(['id', 'title', 'slug', 'icon']),
            'article' => $article,
            'feedback' => [
                'helpful_count' => $helpfulCount,
                'not_helpful_count' => $notHelpfulCount,
                'user_feedback' => $userFeedback,
            ],
            'siblings' => $siblings,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $audience = $this->detectAudience();
        $q = $request->input('q', '');

        if (strlen($q) < 2) {
            return response()->json(['results' => [], 'has_support_module' => false]);
        }

        $articles = DocumentationArticle::query()
            ->published()
            ->forAudience($audience)
            ->where(function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhere('excerpt', 'like', "%{$q}%")
                    ->orWhere('content', 'like', "%{$q}%");
            })
            ->with('topic:id,title,slug,icon')
            ->limit(20)
            ->get(['id', 'uuid', 'topic_id', 'title', 'slug', 'excerpt']);

        // Log the search
        $user = Auth::guard('web')->user() ?? Auth::guard('platform')->user();
        DocumentationSearchLog::create([
            'query' => mb_substr($q, 0, 255),
            'results_count' => $articles->count(),
            'audience' => $audience,
            'user_type' => $user ? (Auth::guard('platform')->check() ? 'platform_admin' : 'company_user') : null,
            'user_id' => $user?->id,
        ]);

        return response()->json([
            'results' => $articles,
            'has_support_module' => true,
        ]);
    }

    public function feedback(Request $request, int $id): JsonResponse
    {
        $user = Auth::guard('web')->user() ?? Auth::guard('platform')->user();

        if (! $user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        $audience = $this->detectAudience();

        $article = DocumentationArticle::query()
            ->published()
            ->forAudience($audience)
            ->findOrFail($id);

        $validated = $request->validate([
            'helpful' => 'required|boolean',
            'comment' => 'nullable|string|max:1000',
        ]);

        $userType = Auth::guard('platform')->check() ? 'platform_admin' : 'company_user';

        $feedback = DocumentationFeedback::updateOrCreate(
            [
                'article_id' => $article->id,
                'user_type' => $userType,
                'user_id' => $user->id,
            ],
            [
                'helpful' => $validated['helpful'],
                'comment' => $validated['comment'] ?? null,
            ],
        );

        return response()->json($feedback);
    }
}
