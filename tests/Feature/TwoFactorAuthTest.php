<?php

namespace Tests\Feature;

use App\Core\Auth\TwoFactorCredential;
use App\Core\Auth\TwoFactorService;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformUser;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private PlatformUser $platformAdmin;
    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        MarketRegistry::sync();

        $this->user = User::factory()->create(['password' => bcrypt('password')]);
        $this->company = Company::create([
            'name' => '2FA Test Co',
            'slug' => '2fa-test-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'starter',
        ]);
        Membership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        $this->platformAdmin = PlatformUser::create([
            'name' => 'Admin',
            'email' => '2fa-admin@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->google2fa = new Google2FA();
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function getValidCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    private function enableTwoFactor(User|PlatformUser $user): TwoFactorCredential
    {
        $service = app(TwoFactorService::class);
        $service->enable($user);

        $credential = TwoFactorCredential::where('authenticatable_type', get_class($user))
            ->where('authenticatable_id', $user->id)
            ->first();

        $code = $this->google2fa->getCurrentOtp($credential->secret);
        $service->confirm($user, $code);

        return $credential->fresh();
    }

    private function companyRequest(): static
    {
        return $this->actingAs($this->user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function platformRequest(): static
    {
        return $this->actingAs($this->platformAdmin, 'platform');
    }

    /**
     * Make a stateful API request (Sanctum needs Origin header to activate session).
     */
    private function stateful(): static
    {
        return $this->withHeader('Origin', 'https://leezr.test');
    }

    // ═══════════════════════════════════════════════════════════
    // Company 2FA Setup (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_enable_returns_secret_and_qr_and_backup_codes(): void
    {
        $response = $this->companyRequest()
            ->postJson('/api/2fa/enable');

        $response->assertOk()
            ->assertJsonStructure(['secret', 'qr_url', 'backup_codes']);

        $data = $response->json();
        $this->assertNotEmpty($data['secret']);
        $this->assertNotEmpty($data['qr_url']);
        $this->assertCount(10, $data['backup_codes']);
    }

    public function test_confirm_with_valid_code_enables_2fa(): void
    {
        // First enable
        $enableResponse = $this->companyRequest()->postJson('/api/2fa/enable');
        $secret = $enableResponse->json('secret');

        // Generate a valid TOTP code
        $code = $this->getValidCode($secret);

        $response = $this->companyRequest()
            ->postJson('/api/2fa/confirm', ['code' => $code]);

        $response->assertOk()
            ->assertJsonFragment(['message' => '2FA enabled successfully.']);

        // Verify credential is now enabled
        $credential = TwoFactorCredential::where('authenticatable_type', User::class)
            ->where('authenticatable_id', $this->user->id)
            ->first();

        $this->assertTrue($credential->enabled);
        $this->assertNotNull($credential->confirmed_at);
    }

    public function test_confirm_with_invalid_code_returns_422(): void
    {
        // First enable
        $this->companyRequest()->postJson('/api/2fa/enable');

        $response = $this->companyRequest()
            ->postJson('/api/2fa/confirm', ['code' => '000000']);

        $response->assertStatus(422);
    }

    public function test_confirm_already_enabled_returns_422(): void
    {
        // Enable and confirm 2FA
        $this->enableTwoFactor($this->user);

        // Try to confirm again
        $response = $this->companyRequest()
            ->postJson('/api/2fa/confirm', ['code' => '123456']);

        $response->assertStatus(422);
    }

    public function test_disable_with_valid_password(): void
    {
        $this->enableTwoFactor($this->user);

        $response = $this->companyRequest()
            ->deleteJson('/api/2fa', ['password' => 'password']);

        $response->assertOk()
            ->assertJsonFragment(['message' => '2FA disabled.']);

        $this->assertDatabaseMissing('two_factor_credentials', [
            'authenticatable_type' => User::class,
            'authenticatable_id' => $this->user->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Company 2FA Login (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_login_with_2fa_enabled_returns_requires_2fa(): void
    {
        $this->enableTwoFactor($this->user);

        $response = $this->stateful()
            ->postJson('/api/login', [
                'email' => $this->user->email,
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['requires_2fa' => true]);

        // Should NOT be fully authenticated yet
        $this->assertGuest('web');
    }

    public function test_verify_with_valid_totp_code_logs_in(): void
    {
        $credential = $this->enableTwoFactor($this->user);

        // Step 1: login to put 2fa_pending_user_id into session
        $this->stateful()->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        // Step 2: verify with valid TOTP (same session carried forward)
        $code = $this->getValidCode($credential->secret);

        $response = $this->stateful()
            ->postJson('/api/2fa/verify', ['code' => $code]);

        $response->assertOk()
            ->assertJsonStructure(['user']);

        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_verify_with_invalid_code_returns_422(): void
    {
        $this->enableTwoFactor($this->user);

        // Login first to get pending session
        $this->stateful()->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        // Verify with invalid code
        $response = $this->stateful()
            ->postJson('/api/2fa/verify', ['code' => '000000']);

        $response->assertStatus(422);
    }

    public function test_verify_with_backup_code_logs_in(): void
    {
        $credential = $this->enableTwoFactor($this->user);
        $backupCode = $credential->backup_codes[0];

        // Login first
        $this->stateful()->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        // Verify with backup code
        $response = $this->stateful()
            ->postJson('/api/2fa/verify', ['code' => $backupCode]);

        $response->assertOk()
            ->assertJsonStructure(['user']);

        $this->assertAuthenticatedAs($this->user, 'web');
    }

    public function test_verify_without_pending_session_returns_422(): void
    {
        // No prior login — no 2fa_pending_user_id in session
        $response = $this->stateful()
            ->postJson('/api/2fa/verify', ['code' => '123456']);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'No pending 2FA verification.']);
    }

    // ═══════════════════════════════════════════════════════════
    // Backup Codes (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_regenerate_backup_codes_returns_new_codes(): void
    {
        $credential = $this->enableTwoFactor($this->user);
        $originalCodes = $credential->backup_codes;

        $response = $this->companyRequest()
            ->postJson('/api/2fa/backup-codes');

        $response->assertOk()
            ->assertJsonStructure(['backup_codes']);

        $newCodes = $response->json('backup_codes');
        $this->assertCount(10, $newCodes);
        $this->assertNotEquals($originalCodes, $newCodes);
    }

    public function test_regenerate_without_2fa_enabled_returns_422(): void
    {
        // No 2FA enabled
        $response = $this->companyRequest()
            ->postJson('/api/2fa/backup-codes');

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => '2FA is not enabled.']);
    }

    public function test_backup_code_is_consumed_after_use(): void
    {
        $credential = $this->enableTwoFactor($this->user);
        $backupCode = $credential->backup_codes[0];

        // First use: login + verify with backup code
        $this->stateful()->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);
        $response = $this->stateful()
            ->postJson('/api/2fa/verify', ['code' => $backupCode]);
        $response->assertOk();

        // Logout
        $this->stateful()->postJson('/api/logout');

        // Second use: login again + try same backup code
        $this->stateful()->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);
        $response = $this->stateful()
            ->postJson('/api/2fa/verify', ['code' => $backupCode]);
        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // Status & Security (3 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_status_when_enabled_returns_true(): void
    {
        $this->enableTwoFactor($this->user);

        $response = $this->companyRequest()
            ->getJson('/api/2fa/status');

        $response->assertOk()
            ->assertJsonFragment(['enabled' => true]);
    }

    public function test_status_when_disabled_returns_false(): void
    {
        $response = $this->companyRequest()
            ->getJson('/api/2fa/status');

        $response->assertOk()
            ->assertJsonFragment(['enabled' => false]);
    }

    public function test_disable_with_wrong_password_returns_422(): void
    {
        $this->enableTwoFactor($this->user);

        $response = $this->companyRequest()
            ->deleteJson('/api/2fa', ['password' => 'wrong-password']);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Invalid password.']);

        // Credential should still exist
        $this->assertDatabaseHas('two_factor_credentials', [
            'authenticatable_type' => User::class,
            'authenticatable_id' => $this->user->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Platform 2FA (5 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_platform_enable_returns_secret(): void
    {
        $response = $this->platformRequest()
            ->postJson('/api/platform/2fa/enable');

        $response->assertOk()
            ->assertJsonStructure(['secret', 'qr_url', 'backup_codes']);

        $this->assertCount(10, $response->json('backup_codes'));
    }

    public function test_platform_confirm_with_valid_code(): void
    {
        $enableResponse = $this->platformRequest()
            ->postJson('/api/platform/2fa/enable');

        $secret = $enableResponse->json('secret');
        $code = $this->getValidCode($secret);

        $response = $this->platformRequest()
            ->postJson('/api/platform/2fa/confirm', ['code' => $code]);

        $response->assertOk()
            ->assertJsonFragment(['message' => '2FA enabled successfully.']);

        $credential = TwoFactorCredential::where('authenticatable_type', PlatformUser::class)
            ->where('authenticatable_id', $this->platformAdmin->id)
            ->first();

        $this->assertTrue($credential->enabled);
        $this->assertNotNull($credential->confirmed_at);
    }

    public function test_platform_login_with_2fa_returns_requires_2fa(): void
    {
        $this->enableTwoFactor($this->platformAdmin);

        $response = $this->stateful()
            ->postJson('/api/platform/login', [
                'email' => $this->platformAdmin->email,
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['requires_2fa' => true]);
    }

    public function test_platform_verify_with_valid_code(): void
    {
        $credential = $this->enableTwoFactor($this->platformAdmin);

        // Step 1: platform login
        $this->stateful()->postJson('/api/platform/login', [
            'email' => $this->platformAdmin->email,
            'password' => 'password',
        ]);

        // Step 2: verify
        $code = $this->getValidCode($credential->secret);

        $response = $this->stateful()
            ->postJson('/api/platform/2fa/verify', ['code' => $code]);

        $response->assertOk()
            ->assertJsonStructure(['user']);
    }

    public function test_platform_disable_with_password(): void
    {
        $this->enableTwoFactor($this->platformAdmin);

        $response = $this->platformRequest()
            ->deleteJson('/api/platform/2fa', ['password' => 'password']);

        $response->assertOk()
            ->assertJsonFragment(['message' => '2FA disabled.']);

        $this->assertDatabaseMissing('two_factor_credentials', [
            'authenticatable_type' => PlatformUser::class,
            'authenticatable_id' => $this->platformAdmin->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Rate Limiting (2 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_verify_rate_limited_after_5_attempts(): void
    {
        // Re-enable throttle middleware for this test
        $this->withMiddleware(ThrottleRequests::class);

        $this->enableTwoFactor($this->user);

        // Clear any existing rate limiter state
        app(RateLimiter::class)->clear(sha1('|127.0.0.1'));

        // Login to store pending user in session
        $this->stateful()->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        // Clear rate limiter again after login (login throttle shares IP key)
        app(RateLimiter::class)->clear(sha1('|127.0.0.1'));

        // Now send exactly 5 verify requests — should all be 422
        for ($i = 0; $i < 5; $i++) {
            $response = $this->stateful()
                ->postJson('/api/2fa/verify', ['code' => '000000']);
            $response->assertStatus(422, "Attempt #".($i + 1)." should be 422");
        }

        // 6th attempt — rate limited (429)
        $response = $this->stateful()
            ->postJson('/api/2fa/verify', ['code' => '000000']);
        $response->assertStatus(429);
    }

    public function test_platform_verify_rate_limited_after_5_attempts(): void
    {
        // Re-enable throttle middleware for this test
        $this->withMiddleware(ThrottleRequests::class);

        $this->enableTwoFactor($this->platformAdmin);

        // Clear any existing rate limiter state
        app(RateLimiter::class)->clear(sha1('|127.0.0.1'));

        // Platform login
        $this->stateful()->postJson('/api/platform/login', [
            'email' => $this->platformAdmin->email,
            'password' => 'password',
        ]);

        // Clear rate limiter again after login
        app(RateLimiter::class)->clear(sha1('|127.0.0.1'));

        // 5 verify attempts — should be 422
        for ($i = 0; $i < 5; $i++) {
            $response = $this->stateful()
                ->postJson('/api/platform/2fa/verify', ['code' => '000000']);
            $response->assertStatus(422, "Attempt #".($i + 1)." should be 422");
        }

        // 6th attempt — rate limited
        $response = $this->stateful()
            ->postJson('/api/platform/2fa/verify', ['code' => '000000']);
        $response->assertStatus(429);
    }

    // ═══════════════════════════════════════════════════════════
    // Data Integrity (2 tests)
    // ═══════════════════════════════════════════════════════════

    public function test_credentials_encrypted_in_database(): void
    {
        $this->enableTwoFactor($this->user);

        $credential = TwoFactorCredential::where('authenticatable_type', User::class)
            ->where('authenticatable_id', $this->user->id)
            ->first();

        // The decrypted secret should be a valid base32 string
        $this->assertNotEmpty($credential->secret);

        // Raw database value should be different from decrypted (i.e., encrypted)
        $rawSecret = $credential->getRawOriginal('secret');
        $this->assertNotEquals($credential->secret, $rawSecret);

        // backup_codes raw should also be encrypted (not plain JSON)
        $rawBackupCodes = $credential->getRawOriginal('backup_codes');
        $this->assertIsArray($credential->backup_codes);
        $this->assertIsString($rawBackupCodes);
        $this->assertNotEquals(json_encode($credential->backup_codes), $rawBackupCodes);
    }

    public function test_polymorphic_credentials_isolated(): void
    {
        // Enable 2FA for both User and PlatformUser
        $this->enableTwoFactor($this->user);
        $this->enableTwoFactor($this->platformAdmin);

        // Both should have separate credentials
        $userCredential = TwoFactorCredential::where('authenticatable_type', User::class)
            ->where('authenticatable_id', $this->user->id)
            ->first();

        $adminCredential = TwoFactorCredential::where('authenticatable_type', PlatformUser::class)
            ->where('authenticatable_id', $this->platformAdmin->id)
            ->first();

        $this->assertNotNull($userCredential);
        $this->assertNotNull($adminCredential);

        // Secrets should be different (each gets their own)
        $this->assertNotEquals($userCredential->secret, $adminCredential->secret);

        // Disabling one should not affect the other
        app(TwoFactorService::class)->disable($this->user);

        $this->assertNull(
            TwoFactorCredential::where('authenticatable_type', User::class)
                ->where('authenticatable_id', $this->user->id)
                ->first()
        );

        $this->assertNotNull(
            TwoFactorCredential::where('authenticatable_type', PlatformUser::class)
                ->where('authenticatable_id', $this->platformAdmin->id)
                ->first()
        );
    }
}
