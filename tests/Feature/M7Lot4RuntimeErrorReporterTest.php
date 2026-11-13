<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/**
 * ADR-770 (M7 LOT 4) — the frontend error reporter posts with `navigator.sendBeacon`, which cannot
 * carry the X-XSRF-TOKEN header. From the SPA origin (a Sanctum "stateful" origin) the public,
 * throttled, write-only log sink answered 419 : every client error report was silently lost.
 *
 * Laravel skips CSRF verification while running unit tests, so the 419 itself is reproduced over
 * real HTTP (see ADR-770) ; this test pins the CONFIGURATION that fixes it and its narrow scope.
 */
class M7Lot4RuntimeErrorReporterTest extends TestCase
{
    public function test_only_the_error_sink_is_exempt_from_csrf(): void
    {
        $excluded = app(ValidateCsrfToken::class)->getExcludedPaths();

        $this->assertContains('api/runtime-error', $excluded);
        foreach ($excluded as $path) {
            $this->assertStringNotContainsString('*', $path, 'no wildcard exemption');
        }
        $this->assertSame(['api/runtime-error'], array_values(array_filter($excluded, fn ($p) => str_starts_with($p, 'api/'))));
    }

    public function test_the_sink_stays_public_validated_throttled_and_stateless(): void
    {
        config(['logging.channels.runtime' => ['driver' => 'single', 'path' => $log = sys_get_temp_dir().'/runtime-'.uniqid().'.log']]);

        // Exactly what sendBeacon sends from the SPA : JSON body, origin headers, NO X-XSRF-TOKEN.
        $origin = rtrim((string) config('app.url'), '/');
        $this->withHeaders(['Origin' => $origin, 'Referer' => $origin.'/home'])
            ->postJson('/api/runtime-error', ['type' => 'chunk_load_failure', 'message' => 'boom-adr770'])->assertNoContent();
        $this->assertStringContainsString('boom-adr770', (string) file_get_contents($log));

        $this->postJson('/api/runtime-error', ['type' => 'js_error'])->assertStatus(422);
        $this->assertGuest();

        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === 'api/runtime-error');
        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
    }
}
