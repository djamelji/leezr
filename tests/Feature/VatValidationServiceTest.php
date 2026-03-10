<?php

namespace Tests\Feature;

use App\Core\Billing\VatCheck;
use App\Modules\Core\Billing\Services\VatValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-310: VIES VAT validation cache tests.
 */
class VatValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        VatValidationService::$testSoapOverride = null;
        parent::tearDown();
    }

    public function test_cache_hit_returns_cached_result(): void
    {
        VatCheck::create([
            'vat_number' => '123456789',
            'country_code' => 'FR',
            'is_valid' => true,
            'name' => 'Cached Company',
            'address' => 'Paris',
            'checked_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $result = VatValidationService::validate('FR123456789', 'FR');

        $this->assertTrue($result['valid']);
        $this->assertEquals('Cached Company', $result['name']);
        $this->assertTrue($result['cached']);
    }

    public function test_expired_cache_calls_vies(): void
    {
        VatCheck::create([
            'vat_number' => '999888777',
            'country_code' => 'DE',
            'is_valid' => false,
            'name' => 'Old Company',
            'checked_at' => now()->subDays(10),
            'expires_at' => now()->subDay(), // expired
        ]);

        // Mock VIES to return valid
        VatValidationService::$testSoapOverride = fn ($vatNumber, $cc) => (object) [
            'valid' => true,
            'name' => 'Fresh Company',
            'address' => 'Berlin',
        ];

        $result = VatValidationService::validate('999888777', 'DE');

        $this->assertFalse($result['cached']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('Fresh Company', $result['name']);
    }

    public function test_stores_result_in_cache_on_vies_call(): void
    {
        // Mock VIES to return valid
        VatValidationService::$testSoapOverride = fn ($vatNumber, $cc) => (object) [
            'valid' => true,
            'name' => 'New Company',
            'address' => 'Rome',
        ];

        $result = VatValidationService::validate('111222333', 'IT');

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['cached']);

        // Check DB cache entry was created
        $cached = VatCheck::where('vat_number', '111222333')
            ->where('country_code', 'IT')
            ->first();

        $this->assertNotNull($cached);
        $this->assertTrue($cached->is_valid);
        $this->assertEquals('New Company', $cached->name);
        $this->assertFalse($cached->isExpired());
    }

    public function test_vies_unavailable_falls_back_to_valid(): void
    {
        // Mock VIES as unavailable (returns null → throws)
        VatValidationService::$testSoapOverride = fn () => null;

        $result = VatValidationService::validate('777888999', 'FR');

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['cached']);
    }

    public function test_strips_country_prefix(): void
    {
        VatCheck::create([
            'vat_number' => '555666777',
            'country_code' => 'FR',
            'is_valid' => true,
            'name' => 'Stripped',
            'checked_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        // Pass with country prefix — should strip it
        $result = VatValidationService::validate('FR555666777', 'FR');

        $this->assertTrue($result['cached']);
        $this->assertEquals('Stripped', $result['name']);
    }
}
