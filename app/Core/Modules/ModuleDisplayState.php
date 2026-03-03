<?php

namespace App\Core\Modules;

/**
 * ADR-163: Semantic display state for module marketplace.
 *
 * Computed by ModuleDisplayStateResolver. The frontend renders based
 * on this single value — no entitlement logic in the UI layer.
 */
enum ModuleDisplayState: string
{
    case SYSTEM = 'system';               // Hidden or globally disabled — never sent to frontend
    case INCLUDED = 'included';           // Core module or included via jobdomain defaults
    case ACTIVE = 'active';               // Explicitly activated by company
    case AVAILABLE = 'available';         // Entitled, not yet activated — can be turned on
    case LOCKED_PLAN = 'locked_plan';     // Requires plan upgrade
    case LOCKED_ADDON = 'locked_addon';   // Requires addon purchase
    case CONTACT_SALES = 'contact_sales'; // Requires contacting sales
}
