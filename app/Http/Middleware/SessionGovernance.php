<?php

namespace App\Http\Middleware;

use App\Core\Settings\SessionSettingsPayload;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SessionGovernance
{
    public function handle(Request $request, Closure $next): Response
    {
        // ── Guard 1 : database driver only (sessions.last_activity required) ──
        if (config('session.driver') !== 'database') {
            return $next($request);
        }

        // ── Guard 2 : session ID must exist ──
        $sessionId = $request->session()->getId();
        if (! $sessionId) {
            return $next($request);
        }

        // ── Guard 3 : skip logout routes (avoid 401 on expired-session logout) ──
        if (str_ends_with($request->path(), '/logout')) {
            return $next($request);
        }

        // ── Read sessions.last_activity from DB (sole source of truth) ──
        $row = DB::table('sessions')->where('id', $sessionId)->first();

        if (! $row) {
            return $next($request);
        }

        // No cache — admin setting changes take effect immediately
        $idle = SessionSettingsPayload::fromSettings()->idleTimeout * 60; // min → sec

        $remaining = ($row->last_activity + $idle) - time();

        // Expired → invalidate + 401 (server-side enforcement)
        if ($remaining <= 0) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Session expired due to inactivity.',
            ], 401);
        }

        // ── Process request ──
        $response = $next($request);

        // ── X-Session-TTL header ──
        // DESIGN DECISION: this request IS activity. Laravel database session
        // driver writes last_activity = time() at session close (after response).
        // Effective TTL post-request = idle_timeout seconds.
        // No DB re-read needed (predictable result = idle).
        $response->headers->set('X-Session-TTL', (string) $idle);

        return $response;
    }
}
