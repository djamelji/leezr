<?php

namespace Tests\Feature;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Settings\MaintenanceSettingsPayload;
use App\Modules\Platform\Audience\AudienceConfirmService;
use App\Modules\Platform\Audience\AudienceSubscribeService;
use App\Modules\Platform\Audience\AudienceToken;
use App\Modules\Platform\Audience\AudienceUnsubscribeService;
use App\Modules\Platform\Audience\MailingList;
use App\Modules\Platform\Audience\MailingListSubscription;
use App\Modules\Platform\Audience\Subscriber;
use App\Platform\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class AudienceModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ── Helpers ──────────────────────────────────────────

    private function createEnabledList(string $slug = 'beta-launch', bool $doubleOptIn = true): MailingList
    {
        return MailingList::create([
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'purpose' => 'Test list',
            'double_opt_in' => $doubleOptIn,
            'is_enabled' => true,
        ]);
    }

    private function createDisabledList(string $slug = 'disabled-list'): MailingList
    {
        return MailingList::create([
            'slug' => $slug,
            'name' => 'Disabled List',
            'purpose' => 'Disabled test list',
            'double_opt_in' => false,
            'is_enabled' => false,
        ]);
    }

    // ── Module registration ─────────────────────────────

    public function test_audience_module_is_registered(): void
    {
        $definitions = ModuleRegistry::definitions();

        $this->assertArrayHasKey('platform.audience', $definitions);
        $this->assertEquals('Audience', $definitions['platform.audience']->name);
        $this->assertEquals('admin', $definitions['platform.audience']->scope);
        $this->assertEquals('internal', $definitions['platform.audience']->type);
    }

    public function test_audience_module_has_platform_module_row(): void
    {
        $pm = PlatformModule::where('key', 'platform.audience')->first();

        $this->assertNotNull($pm);
        $this->assertTrue($pm->is_enabled_globally);
    }

    // ── Subscribe endpoint — single opt-in ──────────────

    public function test_subscribe_single_opt_in_creates_confirmed_subscriber(): void
    {
        $list = $this->createEnabledList('newsletter', doubleOptIn: false);

        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'newsletter',
            'email' => 'user@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['message' => "You're on the list. Confirmation emails will be enabled soon."]);

        $this->assertDatabaseHas('subscribers', [
            'email' => 'user@example.com',
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('mailing_list_subscriptions', [
            'list_id' => $list->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_subscribe_double_opt_in_creates_pending_subscriber(): void
    {
        $list = $this->createEnabledList('beta-list', doubleOptIn: true);

        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'beta-list',
            'email' => 'pending@example.com',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('subscribers', [
            'email' => 'pending@example.com',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('mailing_list_subscriptions', [
            'list_id' => $list->id,
            'status' => 'pending',
        ]);

        // A confirm token should have been created
        $subscriber = Subscriber::where('email', 'pending@example.com')->first();
        $this->assertDatabaseHas('audience_tokens', [
            'subscriber_id' => $subscriber->id,
            'list_id' => $list->id,
            'type' => 'confirm',
        ]);
    }

    public function test_subscribe_creates_token_for_double_opt_in(): void
    {
        $this->createEnabledList('doi-list', doubleOptIn: true);

        $result = AudienceSubscribeService::handle(
            listSlug: 'doi-list',
            email: 'tokentest@example.com',
        );

        $this->assertEquals('pending', $result['status']);
        $this->assertTrue($result['needs_confirmation']);
        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
    }

    public function test_subscribe_single_opt_in_returns_confirmed_status(): void
    {
        $this->createEnabledList('soi-list', doubleOptIn: false);

        $result = AudienceSubscribeService::handle(
            listSlug: 'soi-list',
            email: 'soi@example.com',
        );

        $this->assertEquals('confirmed', $result['status']);
        $this->assertFalse($result['needs_confirmation']);
    }

    // ── Subscribe validation ────────────────────────────

    public function test_subscribe_requires_email(): void
    {
        $this->createEnabledList('val-list');

        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'val-list',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_subscribe_requires_valid_email(): void
    {
        $this->createEnabledList('val-list2');

        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'val-list2',
            'email' => 'not-an-email',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_subscribe_requires_list_slug(): void
    {
        $response = $this->postJson('/api/audience/subscribe', [
            'email' => 'user@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['list_slug']);
    }

    public function test_subscribe_fails_for_unknown_list(): void
    {
        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'nonexistent-list',
            'email' => 'user@example.com',
        ]);

        $response->assertNotFound();
    }

    public function test_subscribe_fails_for_disabled_list(): void
    {
        $this->createDisabledList('disabled');

        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'disabled',
            'email' => 'user@example.com',
        ]);

        $response->assertNotFound();
    }

    // ── Honeypot ────────────────────────────────────────

    public function test_subscribe_honeypot_returns_no_content_for_bots(): void
    {
        $this->createEnabledList('honeypot-list');

        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'honeypot-list',
            'email' => 'bot@spam.com',
            'hp_field' => 'i-am-a-bot',
        ]);

        $response->assertNoContent();

        $this->assertDatabaseMissing('subscribers', [
            'email' => 'bot@spam.com',
        ]);
    }

    // ── Duplicate handling ──────────────────────────────

    public function test_subscribe_same_email_twice_is_idempotent(): void
    {
        $this->createEnabledList('dup-list', doubleOptIn: false);

        // First subscribe
        $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'dup-list',
            'email' => 'dup@example.com',
        ]);

        // Second subscribe — should not create a duplicate
        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'dup-list',
            'email' => 'dup@example.com',
        ]);

        $response->assertOk();
        $this->assertEquals(1, Subscriber::where('email', 'dup@example.com')->count());
    }

    public function test_subscribe_already_confirmed_returns_already_subscribed(): void
    {
        $this->createEnabledList('already-list', doubleOptIn: false);

        // Subscribe (auto-confirms with SOI)
        AudienceSubscribeService::handle(listSlug: 'already-list', email: 'already@example.com');

        // Subscribe again
        $result = AudienceSubscribeService::handle(listSlug: 'already-list', email: 'already@example.com');

        $this->assertEquals('already_subscribed', $result['status']);
        $this->assertFalse($result['needs_confirmation']);
    }

    // ── Confirm endpoint ────────────────────────────────

    public function test_confirm_with_valid_token_confirms_subscription(): void
    {
        $this->createEnabledList('confirm-list', doubleOptIn: true);

        $subscribeResult = AudienceSubscribeService::handle(
            listSlug: 'confirm-list',
            email: 'confirm@example.com',
        );

        $rawToken = $subscribeResult['token'];

        $response = $this->postJson('/api/audience/confirm', [
            'token' => $rawToken,
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'confirmed']);
        $response->assertJsonFragment(['email' => 'confirm@example.com']);

        $this->assertDatabaseHas('subscribers', [
            'email' => 'confirm@example.com',
            'status' => 'confirmed',
        ]);
    }

    public function test_confirm_with_invalid_token_returns_invalid(): void
    {
        $response = $this->postJson('/api/audience/confirm', [
            'token' => 'this-is-a-bad-token',
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'invalid']);
    }

    public function test_confirm_requires_token(): void
    {
        $response = $this->postJson('/api/audience/confirm', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['token']);
    }

    public function test_confirm_marks_token_as_used(): void
    {
        $this->createEnabledList('used-token-list', doubleOptIn: true);

        $result = AudienceSubscribeService::handle(listSlug: 'used-token-list', email: 'usedtoken@example.com');
        $rawToken = $result['token'];
        $tokenHash = hash('sha256', $rawToken);

        AudienceConfirmService::handle($rawToken);

        $token = AudienceToken::where('token_hash', $tokenHash)->first();
        $this->assertNotNull($token->used_at);
    }

    public function test_confirm_expired_token_returns_invalid(): void
    {
        $this->createEnabledList('expired-list', doubleOptIn: true);

        $result = AudienceSubscribeService::handle(listSlug: 'expired-list', email: 'expired@example.com');
        $rawToken = $result['token'];
        $tokenHash = hash('sha256', $rawToken);

        // Expire the token
        AudienceToken::where('token_hash', $tokenHash)->update([
            'expires_at' => now()->subHour(),
        ]);

        $confirmResult = AudienceConfirmService::handle($rawToken);

        $this->assertEquals('invalid', $confirmResult['status']);
    }

    public function test_confirm_already_used_token_returns_invalid(): void
    {
        $this->createEnabledList('reused-list', doubleOptIn: true);

        $result = AudienceSubscribeService::handle(listSlug: 'reused-list', email: 'reuse@example.com');
        $rawToken = $result['token'];

        // Use token once
        AudienceConfirmService::handle($rawToken);

        // Try to use again
        $secondResult = AudienceConfirmService::handle($rawToken);

        $this->assertEquals('invalid', $secondResult['status']);
    }

    // ── Unsubscribe endpoint ────────────────────────────

    public function test_unsubscribe_with_valid_token_unsubscribes(): void
    {
        $list = $this->createEnabledList('unsub-list', doubleOptIn: true);

        // Subscribe and confirm
        $subscribeResult = AudienceSubscribeService::handle(listSlug: 'unsub-list', email: 'unsub@example.com');
        AudienceConfirmService::handle($subscribeResult['token']);

        $subscriber = Subscriber::where('email', 'unsub@example.com')->first();

        // Create an unsubscribe token
        $rawUnsubToken = bin2hex(random_bytes(32));
        AudienceToken::create([
            'subscriber_id' => $subscriber->id,
            'list_id' => $list->id,
            'type' => 'unsubscribe',
            'token_hash' => hash('sha256', $rawUnsubToken),
            'expires_at' => now()->addHours(48),
        ]);

        $response = $this->postJson('/api/audience/unsubscribe', [
            'token' => $rawUnsubToken,
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'unsubscribed']);
        $response->assertJsonFragment(['email' => 'unsub@example.com']);

        $this->assertDatabaseHas('subscribers', [
            'email' => 'unsub@example.com',
            'status' => 'unsubscribed',
        ]);

        $this->assertDatabaseHas('mailing_list_subscriptions', [
            'list_id' => $list->id,
            'subscriber_id' => $subscriber->id,
            'status' => 'unsubscribed',
        ]);
    }

    public function test_unsubscribe_with_invalid_token_returns_invalid(): void
    {
        $response = $this->postJson('/api/audience/unsubscribe', [
            'token' => 'bad-unsubscribe-token',
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'invalid']);
    }

    public function test_unsubscribe_requires_token(): void
    {
        $response = $this->postJson('/api/audience/unsubscribe', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['token']);
    }

    // ── Maintenance page endpoint ───────────────────────

    public function test_maintenance_page_returns_default_settings(): void
    {
        $response = $this->getJson('/api/audience/maintenance-page');

        $response->assertOk();
        $response->assertJsonStructure([
            'app_name',
            'enabled',
            'headline',
            'subheadline',
            'description',
            'cta_text',
            'list_slug',
        ]);
    }

    public function test_maintenance_page_returns_custom_settings(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'maintenance' => [
                'enabled' => true,
                'headline' => 'Custom Headline',
                'subheadline' => 'Custom Sub',
                'description' => 'We are upgrading.',
                'cta_text' => 'Get Notified',
                'list_slug' => 'maintenance-custom',
            ],
        ]);

        $response = $this->getJson('/api/audience/maintenance-page');

        $response->assertOk();
        $response->assertJsonFragment(['enabled' => true]);
        $response->assertJsonFragment(['headline' => 'Custom Headline']);
        $response->assertJsonFragment(['list_slug' => 'maintenance-custom']);
    }

    // ── Module gate — disabled module ───────────────────

    public function test_subscribe_blocked_when_module_disabled(): void
    {
        PlatformModule::where('key', 'platform.audience')->update([
            'is_enabled_globally' => false,
        ]);

        $response = $this->postJson('/api/audience/subscribe', [
            'list_slug' => 'any',
            'email' => 'blocked@example.com',
        ]);

        $response->assertForbidden();
        $response->assertJsonFragment(['message' => 'Module is not active.']);
    }

    public function test_maintenance_page_blocked_when_module_disabled(): void
    {
        PlatformModule::where('key', 'platform.audience')->update([
            'is_enabled_globally' => false,
        ]);

        $response = $this->getJson('/api/audience/maintenance-page');

        $response->assertForbidden();
    }

    // ── Model relationships ─────────────────────────────

    public function test_subscriber_has_many_subscriptions(): void
    {
        $list = $this->createEnabledList('rel-list', doubleOptIn: false);

        AudienceSubscribeService::handle(listSlug: 'rel-list', email: 'rel@example.com');

        $subscriber = Subscriber::where('email', 'rel@example.com')->first();
        $this->assertCount(1, $subscriber->subscriptions);
        $this->assertInstanceOf(MailingListSubscription::class, $subscriber->subscriptions->first());
    }

    public function test_mailing_list_has_many_subscriptions(): void
    {
        $list = $this->createEnabledList('ml-rel', doubleOptIn: false);

        AudienceSubscribeService::handle(listSlug: 'ml-rel', email: 'sub1@example.com');
        AudienceSubscribeService::handle(listSlug: 'ml-rel', email: 'sub2@example.com');

        $list->refresh();
        $this->assertCount(2, $list->subscriptions);
    }

    public function test_subscription_belongs_to_subscriber_and_list(): void
    {
        $list = $this->createEnabledList('bt-list', doubleOptIn: false);

        AudienceSubscribeService::handle(listSlug: 'bt-list', email: 'bt@example.com');

        $subscription = MailingListSubscription::where('list_id', $list->id)->first();

        $this->assertInstanceOf(Subscriber::class, $subscription->subscriber);
        $this->assertInstanceOf(MailingList::class, $subscription->mailingList);
        $this->assertEquals('bt@example.com', $subscription->subscriber->email);
    }

    public function test_audience_token_belongs_to_subscriber_and_list(): void
    {
        $list = $this->createEnabledList('tk-list', doubleOptIn: true);

        AudienceSubscribeService::handle(listSlug: 'tk-list', email: 'tk@example.com');

        $subscriber = Subscriber::where('email', 'tk@example.com')->first();
        $token = AudienceToken::where('subscriber_id', $subscriber->id)->first();

        $this->assertInstanceOf(Subscriber::class, $token->subscriber);
        $this->assertInstanceOf(MailingList::class, $token->mailingList);
    }

    // ── Token valid scope ───────────────────────────────

    public function test_audience_token_valid_scope_excludes_used_tokens(): void
    {
        $list = $this->createEnabledList('scope-list', doubleOptIn: true);
        AudienceSubscribeService::handle(listSlug: 'scope-list', email: 'scope@example.com');

        $subscriber = Subscriber::where('email', 'scope@example.com')->first();
        $token = AudienceToken::where('subscriber_id', $subscriber->id)->first();
        $token->update(['used_at' => now()]);

        $validTokens = AudienceToken::valid()->where('subscriber_id', $subscriber->id)->count();
        $this->assertEquals(0, $validTokens);
    }

    public function test_audience_token_valid_scope_excludes_expired_tokens(): void
    {
        $list = $this->createEnabledList('exp-scope-list', doubleOptIn: true);
        AudienceSubscribeService::handle(listSlug: 'exp-scope-list', email: 'expscope@example.com');

        $subscriber = Subscriber::where('email', 'expscope@example.com')->first();
        AudienceToken::where('subscriber_id', $subscriber->id)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $validTokens = AudienceToken::valid()->where('subscriber_id', $subscriber->id)->count();
        $this->assertEquals(0, $validTokens);
    }

    // ── Subscriber metadata ─────────────────────────────

    public function test_subscribe_stores_ip_and_user_agent_in_metadata(): void
    {
        $this->createEnabledList('meta-list', doubleOptIn: false);

        $result = AudienceSubscribeService::handle(
            listSlug: 'meta-list',
            email: 'meta@example.com',
            ip: '192.168.1.1',
            userAgent: 'TestAgent/1.0',
        );

        $subscriber = Subscriber::where('email', 'meta@example.com')->first();
        $this->assertEquals('192.168.1.1', $subscriber->metadata['ip']);
        $this->assertEquals('TestAgent/1.0', $subscriber->metadata['user_agent']);
    }
}
