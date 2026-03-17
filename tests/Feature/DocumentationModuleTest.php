<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Documentation\DocumentationArticle;
use App\Core\Documentation\DocumentationFeedback;
use App\Core\Documentation\DocumentationGroup;
use App\Core\Documentation\DocumentationSearchLog;
use App\Core\Documentation\DocumentationTopic;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationModuleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $companyUser;
    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Doc Test Co',
            'slug' => 'doc-test-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        // Enable all modules
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // Company user with admin role
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'admin',
            'name' => 'Admin',
            'is_administrative' => true,
        ]);
        $role->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        $this->companyUser = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->companyUser->id,
            'role' => 'owner',
        ]);

        // Platform admin
        $this->admin = PlatformUser::create([
            'first_name' => 'Doc',
            'last_name' => 'Admin',
            'email' => 'doc-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ── Module registration ─────────────────────────────

    public function test_documentation_modules_are_registered(): void
    {
        $companyModules = ModuleRegistry::forScope('company');
        $platformModules = ModuleRegistry::forScope('admin');

        $this->assertArrayHasKey('core.documentation', $companyModules);
        $this->assertArrayHasKey('platform.documentation', $platformModules);
    }

    public function test_company_module_declares_documentation_permission(): void
    {
        $manifest = ModuleRegistry::forScope('company')['core.documentation'];
        $permKeys = array_column($manifest->permissions, 'key');

        $this->assertContains('documentation.view', $permKeys);
    }

    public function test_platform_module_declares_manage_permission(): void
    {
        $manifest = ModuleRegistry::forScope('admin')['platform.documentation'];
        $permKeys = array_column($manifest->permissions, 'key');

        $this->assertContains('manage_documentation', $permKeys);
    }

    // ── Platform CRUD: Topics ───────────────────────────

    public function test_platform_can_create_topic(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/documentation/topics', [
                'title' => 'Getting Started',
                'description' => 'How to begin using the platform',
                'icon' => 'tabler-rocket',
                'audience' => 'company',
                'is_published' => true,
                'sort_order' => 1,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['title' => 'Getting Started']);
        $this->assertDatabaseHas('documentation_topics', [
            'title' => 'Getting Started',
            'slug' => 'getting-started',
            'audience' => 'company',
        ]);
    }

    public function test_platform_can_list_topics(): void
    {
        DocumentationTopic::create([
            'title' => 'Topic A',
            'slug' => 'topic-a',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/documentation/topics');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Topic A']);
    }

    public function test_platform_can_update_topic(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Old Title',
            'slug' => 'old-title',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->putJson("/api/platform/documentation/topics/{$topic->id}", [
                'title' => 'New Title',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('documentation_topics', ['id' => $topic->id, 'title' => 'New Title']);
    }

    public function test_platform_can_delete_topic(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'To Delete',
            'slug' => 'to-delete',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->deleteJson("/api/platform/documentation/topics/{$topic->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('documentation_topics', ['id' => $topic->id]);
    }

    // ── Platform CRUD: Articles ─────────────────────────

    public function test_platform_can_create_article(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Topic',
            'slug' => 'topic',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/documentation/articles', [
                'topic_id' => $topic->id,
                'title' => 'How to Login',
                'content' => '<p>Go to the login page and enter your credentials.</p>',
                'excerpt' => 'Learn how to login',
                'audience' => 'company',
                'is_published' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('documentation_articles', [
            'title' => 'How to Login',
            'slug' => 'how-to-login',
            'topic_id' => $topic->id,
        ]);
    }

    public function test_platform_can_list_articles(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'T',
            'slug' => 't',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Art 1',
            'slug' => 'art-1',
            'content' => 'Content',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson("/api/platform/documentation/articles?topic_id={$topic->id}");

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Art 1']);
    }

    public function test_platform_can_update_article(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'T',
            'slug' => 't2',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $article = DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Old',
            'slug' => 'old',
            'content' => 'Old content',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->putJson("/api/platform/documentation/articles/{$article->id}", [
                'title' => 'Updated',
                'content' => 'New content',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('documentation_articles', ['id' => $article->id, 'title' => 'Updated']);
    }

    public function test_platform_can_delete_article(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'T',
            'slug' => 't3',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $article = DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Del',
            'slug' => 'del',
            'content' => 'To delete',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->deleteJson("/api/platform/documentation/articles/{$article->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('documentation_articles', ['id' => $article->id]);
    }

    // ── Help Center: Anonymous (public) access ──────────

    public function test_anonymous_sees_only_public_topics(): void
    {
        DocumentationTopic::create([
            'title' => 'Public Topic',
            'slug' => 'public-topic',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationTopic::create([
            'title' => 'Company Only',
            'slug' => 'company-only',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationTopic::create([
            'title' => 'Platform Secret',
            'slug' => 'platform-secret',
            'audience' => 'platform',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/help-center');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Public Topic']);
        $response->assertJsonMissing(['title' => 'Company Only']);
        $response->assertJsonMissing(['title' => 'Platform Secret']);
        $response->assertJsonPath('audience', 'public');
    }

    public function test_anonymous_can_view_public_topic_with_articles(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Public Help',
            'slug' => 'public-help',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Public Guide',
            'slug' => 'public-guide',
            'content' => '<p>Public how-to</p>',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/help-center/topic/public-help');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Public Help']);
        $this->assertEquals('Public Guide', $response->json('articles.0.title'));
    }

    public function test_anonymous_can_read_public_article_detail(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Public T',
            'slug' => 'public-t',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Public Article',
            'slug' => 'public-article',
            'content' => '<p>Public content</p>',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/help-center/article/public-t/public-article');

        $response->assertOk();
        $response->assertJsonPath('article.title', 'Public Article');
        $response->assertJsonStructure(['article', 'topic', 'feedback', 'siblings']);
    }

    public function test_anonymous_cannot_see_draft_topics(): void
    {
        DocumentationTopic::create([
            'title' => 'Published Public',
            'slug' => 'published-public',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationTopic::create([
            'title' => 'Draft Public',
            'slug' => 'draft-public',
            'audience' => 'public',
            'is_published' => false,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/help-center');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Published Public']);
        $response->assertJsonMissing(['title' => 'Draft Public']);
    }

    // ── Help Center: Company user access ────────────────

    public function test_company_user_sees_company_and_public_topics(): void
    {
        DocumentationTopic::create([
            'title' => 'Company Only',
            'slug' => 'co-only',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationTopic::create([
            'title' => 'Public Visible',
            'slug' => 'pub-visible',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationTopic::create([
            'title' => 'Platform Secret',
            'slug' => 'plat-secret',
            'audience' => 'platform',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->companyUser, 'web')
            ->getJson('/api/help-center');

        $response->assertOk();
        $response->assertJsonPath('audience', 'company');

        $allTitles = collect($response->json('ungrouped_topics'))->pluck('title')->toArray();
        $this->assertContains('Company Only', $allTitles);
        $this->assertContains('Public Visible', $allTitles);
        $this->assertNotContains('Platform Secret', $allTitles);
    }

    public function test_company_user_cannot_see_platform_only_articles(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Public Topic',
            'slug' => 'pub-topic',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Public Art',
            'slug' => 'public-art',
            'content' => 'For everyone',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Platform Art',
            'slug' => 'platform-art',
            'content' => 'For platform only',
            'audience' => 'platform',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->companyUser, 'web')
            ->getJson('/api/help-center/topic/pub-topic');

        $response->assertOk();
        $articles = collect($response->json('articles'));
        $this->assertTrue($articles->contains('title', 'Public Art'));
        $this->assertFalse($articles->contains('title', 'Platform Art'));
    }

    public function test_company_user_can_view_topic_with_articles(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Company Help',
            'slug' => 'company-help',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Company Guide',
            'slug' => 'company-guide',
            'content' => '<p>Company how-to</p>',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->companyUser, 'web')
            ->getJson('/api/help-center/topic/company-help');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Company Help']);
        $this->assertEquals('Company Guide', $response->json('articles.0.title'));
    }

    public function test_company_user_can_read_article_detail(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'T',
            'slug' => 'topic-art',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Details',
            'slug' => 'details',
            'content' => '<p>Detail content</p>',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->companyUser, 'web')
            ->getJson('/api/help-center/article/topic-art/details');

        $response->assertOk();
        $response->assertJsonPath('article.title', 'Details');
        $response->assertJsonStructure(['article', 'topic', 'feedback', 'siblings']);
    }

    // ── Help Center: Platform admin access ──────────────

    public function test_platform_admin_sees_only_platform_topics(): void
    {
        DocumentationTopic::create([
            'title' => 'Platform Docs',
            'slug' => 'platform-docs',
            'audience' => 'platform',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationTopic::create([
            'title' => 'Company Docs',
            'slug' => 'company-docs',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);
        DocumentationTopic::create([
            'title' => 'Public Docs',
            'slug' => 'public-docs',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/help-center');

        $response->assertOk();
        $response->assertJsonPath('audience', 'platform');

        $allTitles = collect($response->json('ungrouped_topics'))->pluck('title')->toArray();
        $this->assertContains('Platform Docs', $allTitles);
        $this->assertNotContains('Company Docs', $allTitles);
        $this->assertNotContains('Public Docs', $allTitles);
    }

    // ── Feedback via Help Center API ────────────────────

    public function test_company_user_can_submit_feedback(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'FB',
            'slug' => 'fb',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $article = DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'FB Art',
            'slug' => 'fb-art',
            'content' => 'Feedback test',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->companyUser, 'web')
            ->postJson("/api/help-center/article/{$article->id}/feedback", [
                'helpful' => true,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('documentation_feedbacks', [
            'article_id' => $article->id,
            'user_type' => 'company_user',
            'user_id' => $this->companyUser->id,
            'helpful' => true,
        ]);
    }

    public function test_anonymous_cannot_submit_feedback(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'No FB',
            'slug' => 'no-fb',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $article = DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'No FB Art',
            'slug' => 'no-fb-art',
            'content' => 'No feedback for anon',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->postJson("/api/help-center/article/{$article->id}/feedback", [
            'helpful' => true,
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseMissing('documentation_feedbacks', [
            'article_id' => $article->id,
        ]);
    }

    public function test_feedback_upserts_on_second_submission(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'UP',
            'slug' => 'up',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $article = DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'UP Art',
            'slug' => 'up-art',
            'content' => 'Upsert test',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        // First submission: helpful
        $this->actingAs($this->companyUser, 'web')
            ->postJson("/api/help-center/article/{$article->id}/feedback", [
                'helpful' => true,
            ]);

        // Second submission: not helpful with comment
        $this->actingAs($this->companyUser, 'web')
            ->postJson("/api/help-center/article/{$article->id}/feedback", [
                'helpful' => false,
                'comment' => 'Missing details',
            ]);

        // Should have only one record
        $this->assertEquals(1, DocumentationFeedback::where('article_id', $article->id)->count());
        $this->assertDatabaseHas('documentation_feedbacks', [
            'article_id' => $article->id,
            'helpful' => false,
            'comment' => 'Missing details',
        ]);
    }

    // ── Search via Help Center API ──────────────────────

    public function test_anonymous_search_returns_public_results(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Srch',
            'slug' => 'srch',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'How to reset password',
            'slug' => 'reset-password',
            'content' => 'Go to settings and click reset',
            'audience' => 'public',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/help-center/search?q=reset');

        $response->assertOk();
        $response->assertJsonStructure(['results', 'has_support_module']);
        $this->assertNotEmpty($response->json('results'));
    }

    public function test_company_user_search_returns_company_and_public_results(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Search Topic',
            'slug' => 'search-topic',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'How to configure settings',
            'slug' => 'configure-settings',
            'content' => 'Go to settings page and configure your preferences.',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->companyUser, 'web')
            ->getJson('/api/help-center/search?q=configure');

        $response->assertOk();
        $this->assertNotEmpty($response->json('results'));
    }

    public function test_search_logs_query_with_audience(): void
    {
        $this->getJson('/api/help-center/search?q=nonexistent');

        $this->assertDatabaseHas('documentation_search_logs', [
            'query' => 'nonexistent',
            'audience' => 'public',
            'results_count' => 0,
        ]);
    }

    public function test_company_search_logs_with_company_audience(): void
    {
        $this->actingAs($this->companyUser, 'web')
            ->getJson('/api/help-center/search?q=missing');

        $this->assertDatabaseHas('documentation_search_logs', [
            'query' => 'missing',
            'audience' => 'company',
            'results_count' => 0,
        ]);
    }

    // ── Feedback stats (platform) ───────────────────────

    public function test_platform_can_view_feedback_stats(): void
    {
        $topic = DocumentationTopic::create([
            'title' => 'Stats',
            'slug' => 'stats',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $article = DocumentationArticle::create([
            'topic_id' => $topic->id,
            'title' => 'Popular',
            'slug' => 'popular',
            'content' => 'Popular article',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        // Create some feedback
        DocumentationFeedback::create([
            'article_id' => $article->id,
            'user_type' => 'company_user',
            'user_id' => 999,
            'helpful' => true,
        ]);
        DocumentationFeedback::create([
            'article_id' => $article->id,
            'user_type' => 'company_user',
            'user_id' => 998,
            'helpful' => false,
            'comment' => 'Needs more detail',
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/documentation/feedback-stats');

        $response->assertOk();
    }

    // ── Slug dedup ──────────────────────────────────────

    public function test_topic_slug_is_deduplicated(): void
    {
        DocumentationTopic::create([
            'title' => 'Duplicate',
            'slug' => 'duplicate',
            'audience' => 'company',
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/documentation/topics', [
                'title' => 'Duplicate',
                'audience' => 'company',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('documentation_topics', ['slug' => 'duplicate-1']);
    }

    // ── Platform CRUD: Groups ───────────────────────────

    public function test_platform_can_crud_groups(): void
    {
        // Create
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/documentation/groups', [
                'title' => 'Getting Started',
                'icon' => 'tabler-rocket',
                'audience' => 'public',
                'is_published' => true,
                'sort_order' => 1,
            ]);

        $response->assertStatus(201);
        $groupId = $response->json('id');
        $this->assertDatabaseHas('documentation_groups', ['title' => 'Getting Started', 'audience' => 'public']);

        // List
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/documentation/groups');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Getting Started']);

        // Update
        $response = $this->actingAs($this->admin, 'platform')
            ->putJson("/api/platform/documentation/groups/{$groupId}", [
                'title' => 'Updated Group',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('documentation_groups', ['id' => $groupId, 'title' => 'Updated Group']);

        // Delete
        $response = $this->actingAs($this->admin, 'platform')
            ->deleteJson("/api/platform/documentation/groups/{$groupId}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('documentation_groups', ['id' => $groupId]);
    }

    public function test_platform_can_view_search_misses(): void
    {
        // Create some search logs with 0 results
        DocumentationSearchLog::create([
            'query' => 'billing help',
            'results_count' => 0,
            'audience' => 'public',
        ]);
        DocumentationSearchLog::create([
            'query' => 'billing help',
            'results_count' => 0,
            'audience' => 'company',
        ]);
        DocumentationSearchLog::create([
            'query' => 'how to export',
            'results_count' => 0,
            'audience' => 'public',
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/documentation/search-misses');

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);
        // billing help should have count 2
        $billingHelp = collect($data)->firstWhere('query', 'billing help');
        $this->assertEquals(2, $billingHelp['search_count']);
    }

    public function test_topic_can_be_assigned_to_group(): void
    {
        $group = DocumentationGroup::create([
            'title' => 'Test Group',
            'slug' => 'test-group',
            'audience' => 'company',
            'is_published' => true,
            'created_by_platform_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/documentation/topics', [
                'title' => 'Grouped Topic',
                'audience' => 'company',
                'group_id' => $group->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('documentation_topics', [
            'title' => 'Grouped Topic',
            'group_id' => $group->id,
        ]);
    }

    public function test_platform_can_create_public_audience_topic(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/documentation/topics', [
                'title' => 'Public FAQ',
                'audience' => 'public',
                'is_published' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('documentation_topics', ['title' => 'Public FAQ', 'audience' => 'public']);
    }
}
