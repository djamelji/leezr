<?php

namespace Tests\Feature;

use App\Core\Ai\Adapters\NullAiAdapter;
use App\Core\Ai\AiGatewayManager;
use App\Core\Ai\DTOs\AiCapability;
use App\Core\Ai\PlatformAiModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiGatewayManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_driver_is_null_when_no_config(): void
    {
        $manager = app(AiGatewayManager::class);

        $adapter = $manager->driver();

        $this->assertInstanceOf(NullAiAdapter::class, $adapter);
        $this->assertSame('null', $adapter->key());
    }

    public function test_null_adapter_returns_empty_response(): void
    {
        $manager = app(AiGatewayManager::class);
        $adapter = $manager->driver();

        $completeResponse = $adapter->complete('test prompt');
        $this->assertSame('', $completeResponse->text);
        $this->assertSame(0.0, $completeResponse->confidence);
        $this->assertSame('null', $completeResponse->provider);

        $visionResponse = $adapter->vision('/tmp/fake.jpg', 'describe this');
        $this->assertSame('', $visionResponse->text);
        $this->assertSame(0.0, $visionResponse->confidence);
        $this->assertSame('null', $visionResponse->provider);

        $extractResponse = $adapter->extractText('/tmp/fake.jpg');
        $this->assertSame('', $extractResponse->text);
        $this->assertSame(0.0, $extractResponse->confidence);
        $this->assertSame('null', $extractResponse->provider);
    }

    public function test_null_adapter_health_check_is_healthy(): void
    {
        $manager = app(AiGatewayManager::class);
        $adapter = $manager->driver();

        $health = $adapter->healthCheck();

        $this->assertTrue($health->isHealthy());
        $this->assertSame('healthy', $health->status);
        $this->assertNotEmpty($health->message);
    }

    public function test_adapter_for_capability_returns_null_when_no_capable_adapter(): void
    {
        // NullAiAdapter has no capabilities, so no adapter supports Vision
        $adapter = AiGatewayManager::adapterForCapability(AiCapability::Vision);

        // Falls back to NullAiAdapter when no capable adapter found
        $this->assertInstanceOf(NullAiAdapter::class, $adapter);
        $this->assertSame('null', $adapter->key());
        $this->assertEmpty($adapter->capabilities());
    }

    public function test_available_providers_returns_active_modules(): void
    {
        PlatformAiModule::create([
            'provider_key' => 'ollama',
            'name' => 'Ollama',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
            'sort_order' => 10,
        ]);

        PlatformAiModule::create([
            'provider_key' => 'null',
            'name' => 'None (disabled)',
            'is_installed' => true,
            'is_active' => false,
            'health_status' => null,
            'sort_order' => 0,
        ]);

        $providers = AiGatewayManager::availableProviders();

        $this->assertCount(2, $providers);

        // Sorted by sort_order DESC, so ollama (10) comes first
        $this->assertSame('ollama', $providers[0]['key']);
        $this->assertSame('Ollama', $providers[0]['name']);
        $this->assertTrue($providers[0]['is_active']);
        $this->assertSame('healthy', $providers[0]['health_status']);

        $this->assertSame('null', $providers[1]['key']);
        $this->assertSame('None (disabled)', $providers[1]['name']);
        $this->assertFalse($providers[1]['is_active']); // is_active=false
    }

}
