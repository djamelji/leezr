<?php

namespace App\Core\Workforce\Dsn\Gateway;

use App\Core\Workforce\Dsn\Gateway\NetEntreprises\DsnCredentialService;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\FakeNetEntreprisesClient;
use App\Core\Workforce\Dsn\Gateway\NetEntreprises\NetEntreprisesDsnGateway;
use Illuminate\Support\Manager;

/**
 * DSN gateway driver manager.
 *
 * Resolves the active DSN transmission gateway from config.
 * Default: 'null' (no-op gateway for dev/test).
 *
 * Config key: workforce.dsn.gateway
 *
 * Sprint 6.5 — ADR-523, Sprint 7.2 — ADR-530
 */
class DsnGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('workforce.dsn.gateway', 'null');
    }

    protected function createNullDriver(): NullDsnGateway
    {
        return new NullDsnGateway();
    }

    protected function createFileDriver(): FileDsnGateway
    {
        return new FileDsnGateway(
            disk: $this->config->get('workforce.dsn.file_disk', 'local'),
            targetDir: $this->config->get('workforce.dsn.file_target_dir', 'workforce/dsn/transmitted'),
        );
    }

    /**
     * Create Net-Entreprises gateway driver.
     *
     * Resolves NetEntreprisesClientInterface from the container:
     * - Production: NetEntreprisesHttpClient (bound in AppServiceProvider)
     * - Tests: FakeNetEntreprisesClient (injected directly or via container override)
     *
     * Sprint 7.2 — ADR-530, Sprint 8.1 — ADR-534
     */
    protected function createNetEntreprisesDriver(): NetEntreprisesDsnGateway
    {
        $client = $this->container->bound(NetEntreprises\NetEntreprisesClientInterface::class)
            ? $this->container->make(NetEntreprises\NetEntreprisesClientInterface::class)
            : new FakeNetEntreprisesClient();

        return new NetEntreprisesDsnGateway(
            client: $client,
            credentialService: $this->container->make(DsnCredentialService::class),
            disk: $this->config->get('workforce.dsn.file_disk', 'local'),
        );
    }
}
