<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_x_content_type_options_header_present(): void
    {
        $response = $this->getJson('/api/public/version');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_referrer_policy_header_present(): void
    {
        $response = $this->getJson('/api/public/version');

        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_permissions_policy_header_present(): void
    {
        $response = $this->getJson('/api/public/version');

        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_content_security_policy_header_present(): void
    {
        $response = $this->getJson('/api/public/version');

        $response->assertHeader('Content-Security-Policy');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_hsts_header_present_in_production(): void
    {
        // Simulate production environment
        app()->detectEnvironment(fn () => 'production');

        $response = $this->getJson('/api/public/version');

        $response->assertHeader('Strict-Transport-Security');
        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
    }

    public function test_hsts_header_absent_in_non_production(): void
    {
        // Testing environment (default) should NOT have HSTS
        $response = $this->getJson('/api/public/version');

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_csp_allows_stripe(): void
    {
        $response = $this->getJson('/api/public/version');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://js.stripe.com', $csp);
        $this->assertStringContainsString('https://api.stripe.com', $csp);
    }

    public function test_security_headers_on_webhook_route(): void
    {
        // Security headers should be present on all API-group routes
        $response = $this->postJson('/api/webhooks/payments/stripe', []);

        // Even on error responses, headers should be present
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }
}
