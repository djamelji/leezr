<?php

namespace App\Core\Tenancy;

use App\Core\Models\Company;

/**
 * ADR-733 (P0-D) — Hygiène du contexte tenant autour de CHAQUE job (worker ou sync).
 *
 * Avant un job : sauvegarde le contexte ambiant (celui d'une requête HTTP qui aurait dispatché en sync)
 * puis le NETTOIE → le job démarre toujours sur une ardoise vierge et DOIT établir son propre contexte
 * (via InteractsWithTenant / CompanyContext::runFor). Après le job (succès/échec/exception) : restaure
 * le contexte ambiant.
 *
 * Garanties (ADR-733 §4) : deux jobs successifs ne partagent jamais un contexte résiduel ; un job
 * échoué/retryé ne réutilise pas un ancien tenant ; une requête HTTP qui dispatche un job en sync
 * récupère son contexte intact après. En worker async, le contexte ambiant est nul → clear/restore = no-op.
 */
class QueueTenancyHygiene
{
    /** @var array<int, Company|null> pile LIFO pour supporter les dispatch imbriqués */
    private static array $stack = [];

    public static function before(): void
    {
        self::$stack[] = CompanyContext::current();
        CompanyContext::clear();
    }

    public static function after(): void
    {
        $previous = array_key_exists(0, self::$stack) ? array_pop(self::$stack) : null;
        if ($previous instanceof Company) {
            app()->instance('company.context', $previous);
        } else {
            CompanyContext::clear();
        }
    }
}
