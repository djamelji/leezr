<?php

namespace App\Providers;

use App\Core\Billing\BillingManager;
use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\PaymentRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\Adapters\NullRealtimePublisher;
use App\Core\Realtime\Adapters\SseRealtimePublisher;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\Contracts\StreamTransport;
use App\Core\Realtime\Transports\PollingTransport;
use App\Core\Realtime\Transports\PubSubTransport;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BillingManager::class);

        $this->app->bind(BillingProvider::class, function ($app) {
            return $app->make(BillingManager::class)->driver();
        });

        $this->app->singleton(PaymentGatewayManager::class);

        // ADR-125: Bind realtime publisher (sse or null)
        $this->app->singleton(RealtimePublisher::class, function () {
            return match (config('realtime.driver')) {
                'sse' => new SseRealtimePublisher(),
                default => new NullRealtimePublisher(),
            };
        });

        // ADR-128: Bind stream transport based on config
        $this->app->singleton(StreamTransport::class, function () {
            $connection = config('realtime.redis_connection', 'default');

            return match (config('realtime.transport', 'polling')) {
                'pubsub' => new PubSubTransport($connection),
                default => new PollingTransport($connection),
            };
        });

        // ADR-130: Audit logger singleton
        $this->app->singleton(AuditLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'company' => Company::class,
            'platform_user' => PlatformUser::class,
        ]);

        // ADR-124: Boot payment module registry
        PaymentRegistry::boot();

        // ADR-081: Remove Vite hot file outside local — prevents production
        // from loading dev server assets (:5173) if the file leaks.
        if (! $this->app->environment('local')) {
            $hotFile = public_path('hot');

            if (file_exists($hotFile)) {
                @unlink($hotFile);
            }
        }

        // ADR-098: Load module-local migrations and routes
        $this->loadModuleAssets();
    }

    /**
     * Load migrations and routes from module directories (new pattern only).
     * Existing modules keep centralized migrations/routes — this enables
     * new modules to colocate their own.
     */
    private function loadModuleAssets(): void
    {
        $modulesBase = app_path('Modules');

        // Module-local migrations (app/Modules/**/database/migrations/)
        foreach (glob($modulesBase . '/*/*/database/migrations') ?: [] as $path) {
            $this->loadMigrationsFrom($path);
        }

        // Module-local routes (app/Modules/**/routes/company.php or platform.php)
        // Each file declares its own middleware — matches patterns from bootstrap/app.php
        foreach (['company', 'platform'] as $type) {
            foreach (glob($modulesBase . "/*/*/routes/{$type}.php") ?: [] as $routeFile) {
                require_once $routeFile;
            }
        }
    }
}
