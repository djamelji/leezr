<?php

namespace Tests\Feature;

use App\Core\Ai\AiPolicy;
use App\Core\Ai\DTOs\AiDecisionResult;
use App\Core\Ai\DTOs\AiInsight;
use App\Core\Documents\DocumentAnalysisResult;
use App\Modules\Core\Documents\Services\DocumentAiDecisionService;
use Tests\TestCase;

class DocumentAiDecisionServiceTest extends TestCase
{
    private DocumentAiDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentAiDecisionService;
    }

    public function test_auto_fill_when_policy_allows(): void
    {
        $policy = new AiPolicy(
            analysisEnabled: true,
            ocrEnabled: true,
            autoFillExpiry: true,
            autoRejectTypeMismatch: false,
            notifyExpiryDetected: false,
            notifyValidationErrors: false,
            minConfidenceThreshold: 0.5,
        );

        $result = new DocumentAnalysisResult(
            detectedType: 'cni',
            fields: ['firstName' => 'Jean'],
            expiryDate: '2028-06-15',
            confidence: 0.85,
            source: 'mrz',
        );

        $decision = $this->service->evaluate($policy, $result, 'cni', false);

        $this->assertTrue($decision->shouldAutoFillExpiry);
        $this->assertEquals('2028-06-15', $decision->detectedExpiryDate);
        $this->assertFalse($decision->shouldAutoReject);

        $types = array_map(fn (AiInsight $i) => $i->type, $decision->insights);
        $this->assertContains('auto_filled', $types);
    }

    public function test_skip_auto_fill_when_policy_disables(): void
    {
        $policy = new AiPolicy(
            analysisEnabled: true,
            ocrEnabled: true,
            autoFillExpiry: false,
            autoRejectTypeMismatch: false,
            notifyExpiryDetected: false,
            notifyValidationErrors: false,
            minConfidenceThreshold: 0.5,
        );

        $result = new DocumentAnalysisResult(
            detectedType: 'cni',
            fields: [],
            expiryDate: '2028-06-15',
            confidence: 0.85,
            source: 'ai',
        );

        $decision = $this->service->evaluate($policy, $result, 'cni', false);

        $this->assertFalse($decision->shouldAutoFillExpiry);
        $this->assertNull($decision->detectedExpiryDate);

        // Should still report detection as insight
        $types = array_map(fn (AiInsight $i) => $i->type, $decision->insights);
        $this->assertContains('expiry_detected', $types);
    }

    public function test_auto_reject_type_mismatch(): void
    {
        $policy = new AiPolicy(
            analysisEnabled: true,
            ocrEnabled: true,
            autoFillExpiry: false,
            autoRejectTypeMismatch: true,
            notifyExpiryDetected: false,
            notifyValidationErrors: false,
            minConfidenceThreshold: 0.5,
        );

        $result = new DocumentAnalysisResult(
            detectedType: 'passport',
            fields: [],
            expiryDate: null,
            confidence: 0.9,
            source: 'ai',
        );

        $decision = $this->service->evaluate($policy, $result, 'cni', false);

        $this->assertTrue($decision->shouldAutoReject);
        $this->assertStringContainsString('passport', $decision->autoRejectReason);
        $this->assertStringContainsString('cni', $decision->autoRejectReason);

        $types = array_map(fn (AiInsight $i) => $i->type, $decision->insights);
        $this->assertContains('auto_rejected', $types);
    }

    public function test_no_action_when_confidence_below_threshold(): void
    {
        $policy = new AiPolicy(
            analysisEnabled: true,
            ocrEnabled: true,
            autoFillExpiry: true,
            autoRejectTypeMismatch: true,
            notifyExpiryDetected: true,
            notifyValidationErrors: true,
            minConfidenceThreshold: 0.7,
        );

        $result = new DocumentAnalysisResult(
            detectedType: 'passport',
            fields: [],
            expiryDate: '2028-01-01',
            confidence: 0.3,
            source: 'ocr',
        );

        $decision = $this->service->evaluate($policy, $result, 'cni', false);

        // All actions should be skipped due to low confidence
        $this->assertFalse($decision->shouldAutoFillExpiry);
        $this->assertFalse($decision->shouldAutoReject);
        $this->assertFalse($decision->shouldNotifyExpiry);
        $this->assertFalse($decision->shouldNotifyErrors);
        $this->assertFalse($decision->hasAnyAction());

        $types = array_map(fn (AiInsight $i) => $i->type, $decision->insights);
        $this->assertContains('low_confidence', $types);
    }

    public function test_insights_generated_correctly(): void
    {
        $policy = new AiPolicy(
            analysisEnabled: true,
            ocrEnabled: true,
            autoFillExpiry: true,
            autoRejectTypeMismatch: false,
            notifyExpiryDetected: true,
            notifyValidationErrors: true,
            minConfidenceThreshold: 0.5,
        );

        $result = new DocumentAnalysisResult(
            detectedType: 'passport',
            fields: ['firstName' => 'Jean'],
            expiryDate: '2028-06-15',
            confidence: 0.85,
            source: 'mrz',
            validationErrors: ['Invalid check digit'],
        );

        $decision = $this->service->evaluate($policy, $result, 'cni', false);

        // Should have: analysis_complete, auto_filled, type_mismatch (not auto-reject since disabled), validation_errors
        $types = array_map(fn (AiInsight $i) => $i->type, $decision->insights);
        $this->assertContains('analysis_complete', $types);
        $this->assertContains('auto_filled', $types);
        $this->assertContains('type_mismatch', $types);
        $this->assertContains('validation_errors', $types);

        // Verify insight structure
        foreach ($decision->insights as $insight) {
            $this->assertNotEmpty($insight->type);
            $this->assertContains($insight->severity, ['info', 'warning', 'error', 'success']);
            $this->assertNotEmpty($insight->messageKey);
        }
    }

    public function test_no_action_when_all_disabled(): void
    {
        $policy = AiPolicy::disabled();

        $result = new DocumentAnalysisResult(
            detectedType: 'passport',
            fields: [],
            expiryDate: '2028-01-01',
            confidence: 0.95,
            source: 'mrz',
        );

        // Confidence threshold is 1.0 on disabled policy → noAction
        $decision = $this->service->evaluate($policy, $result, 'cni', false);

        $this->assertFalse($decision->shouldAutoFillExpiry);
        $this->assertFalse($decision->shouldAutoReject);
        $this->assertFalse($decision->hasAnyAction());
    }

    public function test_no_db_writes_in_decision_service(): void
    {
        // Verify that DecisionService performs ZERO database operations.
        // We count queries before and after — must be identical.
        $policy = new AiPolicy(
            analysisEnabled: true,
            ocrEnabled: true,
            autoFillExpiry: true,
            autoRejectTypeMismatch: true,
            notifyExpiryDetected: true,
            notifyValidationErrors: true,
            minConfidenceThreshold: 0.5,
        );

        $result = new DocumentAnalysisResult(
            detectedType: 'passport',
            fields: ['firstName' => 'Jean'],
            expiryDate: '2028-06-15',
            confidence: 0.9,
            source: 'mrz',
            validationErrors: ['Test error'],
        );

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        $this->service->evaluate($policy, $result, 'cni', false);

        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertEmpty($queries, 'DecisionService must NOT perform any DB queries');
    }

    public function test_skip_auto_fill_when_expiry_already_exists(): void
    {
        $policy = new AiPolicy(
            analysisEnabled: true,
            ocrEnabled: true,
            autoFillExpiry: true,
            autoRejectTypeMismatch: false,
            notifyExpiryDetected: true,
            notifyValidationErrors: false,
            minConfidenceThreshold: 0.5,
        );

        $result = new DocumentAnalysisResult(
            detectedType: 'cni',
            fields: [],
            expiryDate: '2028-06-15',
            confidence: 0.85,
            source: 'mrz',
        );

        // hasExpiryDate = true → should NOT auto-fill
        $decision = $this->service->evaluate($policy, $result, 'cni', true);

        $this->assertFalse($decision->shouldAutoFillExpiry);
        $this->assertNull($decision->detectedExpiryDate);
    }
}
