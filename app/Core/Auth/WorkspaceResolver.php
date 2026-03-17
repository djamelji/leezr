<?php

namespace App\Core\Auth;

/**
 * ADR-357: Resolves the workspace for a given role archetype.
 *
 * This is a business rule — only the backend decides.
 * The frontend reads the resolved workspace, no logic.
 *
 * Workspace determines the user's landing page:
 *   'dashboard' → /dashboard (management, operations center, individual business)
 *   'home'      → /home (field workers)
 */
class WorkspaceResolver
{
    /** Archetypes that land on /home. All others → /dashboard. */
    private const HOME_ARCHETYPES = ['field_worker'];

    public static function resolve(?string $archetype): string
    {
        if ($archetype !== null && in_array($archetype, self::HOME_ARCHETYPES, true)) {
            return 'home';
        }

        return 'dashboard';
    }
}
