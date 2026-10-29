<?php

namespace App\Modules\Workforce\Signature\Providers;

use App\Modules\Workforce\Signature\Providers\Adapters\InternalSignatureAdapter;
use App\Modules\Workforce\Signature\Providers\Contracts\SignatureProviderAdapter;
use Illuminate\Support\Manager;

/**
 * W-SIGNATURE-1 (ADR-651) — résolution du prestataire de signature (pattern Manager, cf. Billing).
 *
 * Un nouveau prestataire s'ajoute en définissant createXxxDriver(). La garde PROD empêche qu'un
 * adaptateur DEV-only (interne) soit utilisé comme prestataire actif en production.
 */
class SignatureProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        // Hors production, l'interne est le défaut pour exercer les workflows ; en PROD, aucun défaut
        // tant qu'un vrai prestataire n'est pas intégré et configuré.
        return $this->isProduction() ? '' : InternalSignatureAdapter::KEY;
    }

    protected function createInternalDriver(): InternalSignatureAdapter
    {
        return new InternalSignatureAdapter();
    }

    /** Résout un adaptateur par clé, en appliquant la garde PROD (DEV-only bloqué en production). */
    public static function adapterFor(?string $providerKey): ?SignatureProviderAdapter
    {
        $manager = app(self::class);
        $key = $providerKey ?: $manager->getDefaultDriver();
        if ($key === '') {
            return null;
        }

        try {
            $driver = $manager->driver($key);
        } catch (\Throwable) {
            return null;
        }

        if (! $driver instanceof SignatureProviderAdapter) {
            return null;
        }

        // Garde PROD : un prestataire DEV-only ne peut JAMAIS être actif en production.
        if ($driver->isDevOnly() && $manager->isProduction()) {
            return null;
        }

        return $driver;
    }

    /** Liste les clés de prestataires disponibles dans l'environnement courant. */
    public static function availableProviderKeys(): array
    {
        $manager = app(self::class);
        $keys = [InternalSignatureAdapter::KEY];

        return array_values(array_filter($keys, function (string $key) use ($manager) {
            $driver = null;
            try {
                $driver = $manager->driver($key);
            } catch (\Throwable) {
                return false;
            }

            return ! ($driver->isDevOnly() && $manager->isProduction());
        }));
    }

    protected function isProduction(): bool
    {
        return app()->environment('production');
    }
}
