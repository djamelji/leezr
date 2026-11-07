<?php

namespace App\Core\Tenancy;

use RuntimeException;

/**
 * ADR-733 (P0-D) — Levée en mode strict quand une requête tenant-scoped s'exécute SANS contexte company
 * (donc sans filtrage par `company_id`), hors d'un bloc `CompanyContext::runGlobal()` explicitement global.
 * Signale un accès potentiellement inter-tenant : la requête doit être enveloppée dans
 * `CompanyContext::runFor($company, ...)` (opération tenant) ou `CompanyContext::runGlobal(...)`
 * (opération cross-tenant assumée), ou filtrer explicitement via `withoutGlobalScopes()->where('company_id', ...)`.
 */
class MissingTenantContextException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(
            "Tenant-scoped query on [{$model}] ran without a company context (strict tenancy). "
            .'Wrap it in CompanyContext::runFor($company, ...) for a tenant operation, '
            .'or CompanyContext::runGlobal(...) for an intentional cross-tenant operation.'
        );
    }
}
