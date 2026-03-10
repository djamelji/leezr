<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-311: Correlation ID middleware tests.
 */
class CorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    public function test_response_contains_correlation_id_header(): void
    {
        $response = $this->getJson('/api/public/theme');

        $response->assertHeader('X-Correlation-Id');
    }

    public function test_provided_correlation_id_is_propagated(): void
    {
        $id = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->getJson('/api/public/theme', ['X-Correlation-Id' => $id]);

        $response->assertHeader('X-Correlation-Id', $id);
    }

    public function test_generated_correlation_id_is_uuid(): void
    {
        $response = $this->getJson('/api/public/theme');

        $correlationId = $response->headers->get('X-Correlation-Id');

        $this->assertNotNull($correlationId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $correlationId,
        );
    }
}
