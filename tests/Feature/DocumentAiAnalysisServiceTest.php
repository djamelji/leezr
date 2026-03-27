<?php

namespace Tests\Feature;

use App\Core\Ai\AiGatewayManager;
use App\Core\Ai\Contracts\AiProviderAdapter;
use App\Core\Ai\DTOs\AiCapability;
use App\Core\Ai\DTOs\AiResponse;
use App\Core\Ai\PlatformAiModule;
use App\Core\Documents\DocumentAnalysisResult;
use App\Modules\Core\Documents\Services\DocumentAiAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests for DocumentAiAnalysisService cascade:
 * MRZ (truth) -> AI Vision (enrichment) -> OCR (fallback) -> none.
 */
class DocumentAiAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpImage;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a minimal valid PNG (1x1 pixel, transparent)
        $this->tmpImage = tempnam(sys_get_temp_dir(), 'doc_test_') . '.png';
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($this->tmpImage, $png);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpImage)) {
            @unlink($this->tmpImage);
        }

        parent::tearDown();
    }

    /**
     * Test 1: With NullAiAdapter (default, no AI configured) and no MRZ in the text,
     * the cascade falls through to the 'none' result with confidence 0.
     */
    public function test_analyze_returns_none_source_when_no_ai_available(): void
    {
        $service = app(DocumentAiAnalysisService::class);

        // Pass null OCR text so the cascade skips MRZ and OCR fallback,
        // and NullAiAdapter (key='null') is skipped in tryAiVision → returns empty().
        $result = $service->analyze(
            imagePath: $this->tmpImage,
            ocrText: null,
        );

        $this->assertInstanceOf(DocumentAnalysisResult::class, $result);
        $this->assertEquals('none', $result->source);
        $this->assertEquals(0.0, $result->confidence);
        $this->assertNull($result->detectedType);
        $this->assertEmpty($result->fields);
        $this->assertNull($result->expiryDate);
    }

    /**
     * Test 2: When OCR text contains valid MRZ lines (TD3 passport format),
     * the cascade stops at step 1 with source='mrz' and confidence=1.0.
     *
     * Uses the ICAO 9303 standard test passport MRZ (Eriksson, Anna Maria, Utopia).
     */
    public function test_analyze_with_mrz_text_returns_mrz_source(): void
    {
        $service = app(DocumentAiAnalysisService::class);

        // ICAO standard test passport MRZ — valid check digits, 2x44 chars (TD3)
        $ocrText = <<<'MRZ'
Some random text before
P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<
L898902C36UTO7408122F1204159ZE184226B<<<<<10
Some text after
MRZ;

        $result = $service->analyze(
            imagePath: $this->tmpImage,
            ocrText: $ocrText,
        );

        $this->assertInstanceOf(DocumentAnalysisResult::class, $result);
        $this->assertEquals('mrz', $result->source);
        $this->assertEquals(1.0, $result->confidence);
        $this->assertEquals('passport', $result->detectedType);
        $this->assertNotEmpty($result->fields);

        // Verify extracted MRZ fields (mapped via MrzResult::fromParsed)
        $this->assertArrayHasKey('last_name', $result->fields);
        $this->assertArrayHasKey('first_name', $result->fields);
        $this->assertEquals('ERIKSSON', $result->fields['last_name']);
        $this->assertEquals('ANNA MARIA', $result->fields['first_name']);
    }

    /**
     * Test 3: When an AI adapter with Vision capability is available,
     * and no MRZ is found, the cascade uses AI vision and returns source='ai'.
     *
     * Creates a PlatformAiModule DB record and extends AiGatewayManager
     * with a custom driver that returns the mock adapter.
     */
    public function test_analyze_with_ai_vision_mock(): void
    {
        // Build the structured JSON that the AI adapter would return
        $aiStructuredData = [
            'document_type' => 'cni',
            'fields' => [
                'last_name' => 'MARTIN',
                'first_name' => 'SOPHIE',
                'birth_date' => '1990-05-15',
                'document_number' => 'CNI-123456',
                'expiry_date' => '2030-12-31',
            ],
            'expiry_date' => '2030-12-31',
            'confidence' => 0.92,
        ];

        $aiResponse = new AiResponse(
            text: json_encode($aiStructuredData),
            structuredData: $aiStructuredData,
            confidence: 0.92,
            tokensUsed: 150,
            latencyMs: 1200,
            model: 'test-vision-model',
            provider: 'test-provider',
        );

        // Create a mock adapter that supports Vision capability
        $mockAdapter = Mockery::mock(AiProviderAdapter::class);
        $mockAdapter->shouldReceive('key')->andReturn('test-vision');
        $mockAdapter->shouldReceive('capabilities')->andReturn([AiCapability::Vision]);
        $mockAdapter->shouldReceive('isAvailable')->andReturn(true);
        $mockAdapter->shouldReceive('vision')
            ->once()
            ->withArgs(function (string $imagePath, string $prompt, array $options) {
                return $imagePath !== '' && str_contains($prompt, 'Analyze this document image');
            })
            ->andReturn($aiResponse);

        // Register the test-vision driver in the AiGatewayManager
        // so that adapterFor('test-vision') returns our mock adapter.
        /** @var AiGatewayManager $manager */
        $manager = app(AiGatewayManager::class);
        $manager->extend('test-vision', fn () => $mockAdapter);

        // Create a PlatformAiModule DB record so adapterForCapability finds it
        PlatformAiModule::create([
            'provider_key' => 'test-vision',
            'name' => 'Test Vision Provider',
            'is_installed' => true,
            'is_active' => true,
            'sort_order' => 100,
        ]);

        $service = app(DocumentAiAnalysisService::class);

        // Pass null OCR text so the cascade skips MRZ + OCR fallback and hits AI vision
        $result = $service->analyze(
            imagePath: $this->tmpImage,
            ocrText: null,
        );

        $this->assertInstanceOf(DocumentAnalysisResult::class, $result);
        $this->assertEquals('ai', $result->source);
        $this->assertEquals(0.92, $result->confidence);
        $this->assertEquals('cni', $result->detectedType);
        $this->assertNotEmpty($result->fields);
        $this->assertEquals('MARTIN', $result->fields['last_name']);
        $this->assertEquals('SOPHIE', $result->fields['first_name']);
        $this->assertEquals('2030-12-31', $result->expiryDate);
        $this->assertEmpty($result->validationErrors);
    }
}
