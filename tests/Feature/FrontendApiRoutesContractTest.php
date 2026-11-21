<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ADR-837 (Leezr 4.2 M1, UXC-01) — every API endpoint the frontend calls with a LITERAL path must exist as a
 * backend route. A wrong path (e.g. `/company/billing/timeline` instead of `/billing/timeline`) used to fail
 * silently as an empty screen.
 *
 * Scanned : `$api('…')` and `useApi('…')` (base /api), `$platformApi('…')` (base /api/platform), with plain
 * quotes or template literals (`${…}` = one path segment). Dynamic paths built from variables are out of scope.
 */
class FrontendApiRoutesContractTest extends TestCase
{
    /** Paths deliberately called although they do not exist as routes (none today — justify any addition). */
    private const ALLOWED_MISSING = [];

    public function test_every_literal_frontend_api_path_matches_a_backend_route(): void
    {
        // route URIs with their parameters as a neutral token : a call segment `X` (template variable) matches
        // a literal segment OR a parameter, a literal call segment must match exactly.
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (str_starts_with($uri, '/api/')) {
                $routes[] = preg_replace('#\{[^/]+?\}#', '{}', $uri);
            }
        }
        $patterns = $routes;
        $this->assertGreaterThan(500, count($patterns));

        $calls = [];
        $finder = (new Finder)->files()->in(resource_path('js'))->name(['*.js', '*.vue']);
        foreach ($finder as $file) {
            $src = $file->getContents();
            // literal = '…' / "…" / `…` (template : ${…} may contain quotes)
            preg_match_all('/(\$platformApi|\$api|useApi)\(\s*(?:\'([^\'\n]*)\'|"([^"\n]*)"|`((?:[^`\\\\$]|\\\\.|\$\{[^}]*\}|\$(?!\{))*)`)/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($m as $hit) {
                $client = $hit[1][0];
                $raw = ($hit[2][0] ?? '') !== '' ? $hit[2][0] : ((($hit[3][0] ?? '') !== '') ? $hit[3][0] : ($hit[4][0] ?? ''));
                if ($raw === '' || ! str_starts_with($raw, '/')) {
                    continue; // relative or fully dynamic
                }
                // `/${id}` = one segment ; `…${qs}` glued to a segment = query string / suffix
                $path = preg_replace('#/\$\{[^}]*\}#', '/X', $raw);
                $path = preg_replace('/\$\{[^}]*\}/', '', $path);
                $path = rtrim(strtok($path, '?') ?: '', '/');
                $full = ($client === '$platformApi' ? '/api/platform' : '/api').$path;
                $line = substr_count(substr($src, 0, $hit[0][1]), "\n") + 1;
                $calls[$full][] = $file->getRelativePathname().':'.$line;
            }
        }
        $this->assertGreaterThan(300, count($calls), 'the scan must find the frontend calls');

        $missing = [];
        foreach ($calls as $path => $where) {
            if (in_array($path, self::ALLOWED_MISSING, true)) {
                continue;
            }
            // a call segment `X` matches anything ; a literal segment matches itself or a route parameter
            $rx = '#^'.implode('/', array_map(
                fn ($seg) => $seg === 'X' ? '[^/]+' : '(?:'.preg_quote($seg, '#').'|\\{\\})',
                explode('/', $path),
            )).'/?$#';
            $ok = false;
            foreach ($patterns as $uri) {
                if (preg_match($rx, $uri)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                $missing[] = $path.'  ←  '.implode(', ', array_slice($where, 0, 3));
            }
        }

        $this->assertSame([], $missing, "Frontend calls endpoints that have no backend route:\n".implode("\n", $missing));
    }
}
