<?php

namespace App\Core\Tenancy;

use App\Core\Models\Company;
use Closure;

/**
 * ADR-733 (P0-D) — Autorité centrale du CONTEXTE TENANT, valable dans TOUS les contextes d'exécution
 * (HTTP, jobs, queues, CLI, scheduler, listeners). Le `CompanyScope` (ADR-432) filtre `company_id`
 * uniquement si `company.context` est bindé ; HORS HTTP ce binding est absent → scope no-op. Ce service
 * fournit un binding/débinding sûr, ré-entrant (sauvegarde/restauration du contexte précédent) et nettoyé.
 *
 * Contrats (ADR-733 §3) :
 *  - runFor(company, fn)   : opération Company-scoped (A). Le scope filtre pendant l'exécution.
 *  - runGlobal(fn)         : opération Platform/maintenance cross-tenant EXPLICITEMENT autorisée (B/C).
 *  - current()/has()/id()  : lecture du contexte courant.
 *  - $strict (opt-in)      : fail-closed — une requête tenant sans contexte lève plutôt que de lire
 *                            tous les tenants. Jamais activé globalement (casserait les jobs legacy qui
 *                            s'appuient sur le no-op) ; réservé aux tests et aux déploiements migrés.
 */
class CompanyContext
{
    private const KEY = 'company.context';

    /** Fail-closed : si true, une requête tenant-scoped sans contexte (hors runGlobal) lève. Opt-in. */
    public static bool $strict = false;

    /** Profondeur des blocs runGlobal() imbriqués : à l'intérieur, l'absence de contexte est VOULUE. */
    private static int $globalDepth = 0;

    public static function current(): ?Company
    {
        return app()->bound(self::KEY) ? app(self::KEY) : null;
    }

    public static function has(): bool
    {
        return app()->bound(self::KEY);
    }

    public static function id(): ?int
    {
        return self::current()?->id;
    }

    public static function isGlobal(): bool
    {
        return self::$globalDepth > 0;
    }

    /** Exécute $fn avec le contexte de $company lié ; restaure le contexte précédent ensuite (ré-entrant). */
    public static function runFor(Company|int $company, Closure $fn): mixed
    {
        $company = $company instanceof Company
            ? $company
            : Company::withoutGlobalScopes()->findOrFail($company);

        $previous = self::current();
        $wasGlobal = self::$globalDepth;
        app()->instance(self::KEY, $company);
        self::$globalDepth = 0; // à l'intérieur d'un tenant, on n'est plus en mode global

        try {
            return $fn($company);
        } finally {
            self::$globalDepth = $wasGlobal;
            self::restore($previous);
        }
    }

    /** Exécute $fn SANS contexte tenant (opération globale assumée) ; restaure ensuite. */
    public static function runGlobal(Closure $fn): mixed
    {
        $previous = self::current();
        app()->forgetInstance(self::KEY);
        self::$globalDepth++;

        try {
            return $fn();
        } finally {
            self::$globalDepth--;
            self::restore($previous);
        }
    }

    /** Nettoyage inconditionnel (hygiène de queue entre deux jobs). */
    public static function clear(): void
    {
        app()->forgetInstance(self::KEY);
    }

    private static function restore(?Company $previous): void
    {
        if ($previous) {
            app()->instance(self::KEY, $previous);
        } else {
            app()->forgetInstance(self::KEY);
        }
    }
}
