<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Documentation\DocumentationArticle;
use App\Core\Documentation\DocumentationFeedback;
use App\Core\Documentation\DocumentationGroup;
use App\Core\Documentation\DocumentationSearchLog;
use App\Core\Documentation\DocumentationTopic;
use App\Core\Documentation\HelpCenterAccess;
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

    public function index(Request $request): JsonResponse
    {
        $audience = $this->detectAudience();
        $access = HelpCenterAccess::fromRequest($request, $audience); // ADR-787

        $groups = DocumentationGroup::query()
            ->published()
            ->forAudience($audience)
            ->with(['publishedTopics' => function ($q) use ($audience) {
                $q->forAudience($audience)
                    ->orderBy('sort_order')
                    ->with(['publishedArticles' => function ($q2) use ($audience) {
                        $q2->forAudience($audience)
                            ->orderBy('sort_order')
                            ->select(['id', 'topic_id', 'title', 'slug', 'required_permissions', 'required_modules']);
                    }])
                    ->select(['id', 'uuid', 'title', 'slug', 'description', 'icon', 'group_id', 'required_permissions', 'required_modules']);
            }])
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'title', 'slug', 'icon']);

        $groups->each(function ($group) use ($access) {
            $group->setRelation('publishedTopics', $group->publishedTopics->filter(fn ($t) => $access->allows($t))->values());
            $group->publishedTopics->each(function ($topic) use ($access) {
                $all = $this->visibleArticles($access, $topic, $topic->publishedArticles);
                $topic->setAttribute('articles_count', $all->count());
                $topic->setAttribute('top_articles', $all->take(5)->values());
                $topic->unsetRelation('publishedArticles');
            });
        });

        $ungroupedTopics = DocumentationTopic::query()
            ->published()
            ->forAudience($audience)
            ->whereNull('group_id')
            ->with(['publishedArticles' => function ($q) use ($audience) {
                $q->forAudience($audience)
                    ->orderBy('sort_order')
                    ->select(['id', 'topic_id', 'title', 'slug', 'required_permissions', 'required_modules']);
            }])
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'title', 'slug', 'description', 'icon', 'required_permissions', 'required_modules']);

        $ungroupedTopics = $ungroupedTopics->filter(fn ($t) => $access->allows($t))->values();
        $ungroupedTopics->each(function ($topic) use ($access) {
            $all = $this->visibleArticles($access, $topic, $topic->publishedArticles);
            $topic->setAttribute('articles_count', $all->count());
            $topic->setAttribute('top_articles', $all->take(5)->values());
            $topic->unsetRelation('publishedArticles');
        });

        return response()->json([
            'groups' => $groups->filter(fn ($g) => $g->publishedTopics->isNotEmpty())->values(),
            'ungrouped_topics' => $ungroupedTopics,
            'audience' => $audience,
            'can_contact_support' => $access->canContactSupport(),
        ]);
    }

    /** ADR-787 — articles of a topic the requester may read ; rule fields never leave the server. */
    private function visibleArticles(HelpCenterAccess $access, $topic, $articles)
    {
        $topic->makeHidden(['required_permissions', 'required_modules']);

        return $articles->filter(fn ($a) => $access->allows($topic, $a))
            ->each(fn ($a) => $a->makeHidden(['required_permissions', 'required_modules']))->values();
    }

    public function topic(Request $request, string $slug): JsonResponse
    {
        $audience = $this->detectAudience();

        $topic = DocumentationTopic::query()
            ->published()
            ->forAudience($audience)
            ->where('slug', $slug)
            ->firstOrFail();
        $access = HelpCenterAccess::fromRequest($request, $audience);
        abort_unless($access->allows($topic), 404); // ADR-787 : jamais par URL directe

        $articles = $this->visibleArticles($access, $topic, $topic->articles()
            ->published()
            ->forAudience($audience)
            ->orderBy('sort_order')
            ->get(['id', 'uuid', 'title', 'slug', 'excerpt', 'required_permissions', 'required_modules']));

        return response()->json([
            'topic' => $topic->only(['id', 'uuid', 'title', 'slug', 'description', 'icon']),
            'articles' => $articles, 'can_contact_support' => $access->canContactSupport(),
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
        $access = HelpCenterAccess::fromRequest($request, $audience);
        abort_unless($access->allows($topic, $article), 404); // ADR-787
        $article->makeHidden(['required_permissions', 'required_modules']);

        $helpfulCount = $article->feedbacks()->where('helpful', true)->count();
        $notHelpfulCount = $article->feedbacks()->where('helpful', false)->count();

        $userFeedback = null;
        $user = Auth::guard('web')->user() ?? Auth::guard('platform')->user();
        if ($user) {
            $userType = Auth::guard('platform')->check() ? 'platform_admin' : 'company_user';
            $userFeedback = DocumentationFeedback::where('article_id', $article->id)
                ->where('user_type', $userType)
                ->where('user_id', $user->id)
                ->first(['helpful', 'comment']);
        }

        $siblings = $this->visibleArticles($access, $topic, $topic->articles()
            ->published()
            ->forAudience($audience)
            ->where('id', '!=', $article->id)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'slug', 'required_permissions', 'required_modules']));

        return response()->json([
            'topic' => $topic->only(['id', 'title', 'slug', 'icon']),
            'article' => $article,
            'feedback' => [
                'helpful_count' => $helpfulCount,
                'not_helpful_count' => $notHelpfulCount,
                'user_feedback' => $userFeedback,
            ],
            'siblings' => $siblings, 'can_contact_support' => $access->canContactSupport(),
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
            ->with('topic:id,title,slug,icon,required_permissions,required_modules')
            ->limit(100)
            ->get(['id', 'uuid', 'topic_id', 'title', 'slug', 'excerpt', 'required_permissions', 'required_modules']);
        $access = HelpCenterAccess::fromRequest($request, $audience); // ADR-787 : la recherche suit les mêmes règles
        $articles = $articles->filter(fn ($a) => $a->topic && $access->allows($a->topic, $a))->take(20)
            ->each(fn ($a) => $a->makeHidden(['required_permissions', 'required_modules'])->topic->makeHidden(['required_permissions', 'required_modules']))->values();

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
            'has_support_module' => $audience === 'public' || $access->canContactSupport(),
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
        abort_unless($article->topic && HelpCenterAccess::fromRequest($request, $audience)->allows($article->topic, $article), 404);

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
