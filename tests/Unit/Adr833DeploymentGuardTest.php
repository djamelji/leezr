<?php

namespace Tests\Unit;

use App\Core\System\DeploymentGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** ADR-833 — retired webhook pipeline and demo seeding guards (pure decisions). */
class Adr833DeploymentGuardTest extends TestCase
{
    public function test_legacy_release_paths_are_recognised_and_ci_releases_are_not(): void
    {
        $this->assertTrue(DeploymentGuard::isLegacyRelease('/var/www/clients/client1/web3/releases/20261119_162750'));
        $this->assertTrue(DeploymentGuard::isLegacyRelease('/var/www/clients/client1/web2/releases/20261108_190953/'));

        foreach ([
            '/var/www/clients/client1/web3/releases/20261119163003_82e3541', // GitHub Actions, with sha
            '/var/www/clients/client1/web3/releases/20261119163003',         // GitHub Actions, no .build-version
            '/var/www/clients/client1/web3/current',
            '/Users/dev/Sites/leezr',
            '/var/www/clients/client1/web3/releases/20261119_162750/vendor',
        ] as $path) {
            $this->assertFalse(DeploymentGuard::isLegacyRelease($path), $path);
        }
    }

    public function test_artisan_is_refused_from_a_legacy_release(): void
    {
        DeploymentGuard::assertNotLegacyRelease('/var/www/clients/client1/web3/releases/20261119163003_82e3541', 'migrate');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADR-833');
        DeploymentGuard::assertNotLegacyRelease('/var/www/clients/client1/web3/releases/20261119_162750', 'migrate');
    }

    public function test_demo_seed_decision(): void
    {
        // refused: populated remote DEV (the 2026-11-19 incident), production whatever the state
        $this->assertNotSame([], DeploymentGuard::demoSeedRefusals('staging', 3, false));
        $this->assertNotSame([], DeploymentGuard::demoSeedRefusals('production', 0, false));
        $this->assertNotSame([], DeploymentGuard::demoSeedRefusals('production', 3, true));

        // allowed: local/testing, empty database (first population / dev:reset), marked recipe clone
        $this->assertSame([], DeploymentGuard::demoSeedRefusals('local', 3, false));
        $this->assertSame([], DeploymentGuard::demoSeedRefusals('testing', 3, false));
        $this->assertSame([], DeploymentGuard::demoSeedRefusals('staging', 0, false));
        $this->assertSame([], DeploymentGuard::demoSeedRefusals('staging', 3, true));
    }
}
