<?php

namespace App\Core\Modules;

/**
 * Contract for module manifest declarations.
 *
 * Each module's XxxModule.php implements this interface,
 * providing its manifest to the ModuleRegistry aggregator.
 */
interface ModuleDefinition
{
    public static function manifest(): ModuleManifest;
}
