<?php

namespace App\Core\Home;

/**
 * ADR-776 — a module CONTRIBUTES the things that need the viewer's intervention on the company
 * home (« À traiter »). Declared in the module manifest (`Capabilities::$priorityProviders`).
 *
 * Contract of a contribution :
 *   - REAL        : a count read from the module's own source of truth (never a duplicate rule) ;
 *   - AUTHORISED  : only what THIS viewer may see and act on (the provider checks `$home->can()`) ;
 *   - ACTIONABLE  : `to` opens the right treatment (a filtered list, the object), not a module home ;
 *   - TENANT-SAFE : scoped to `$home->company`.
 *
 * @see HomePriority::make() for the item shape
 */
interface HomePriorityProvider
{
    /** @return array<int, array<string, mixed>> */
    public function priorities(HomeContext $home): array;
}
