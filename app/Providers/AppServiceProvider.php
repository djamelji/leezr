<?php

namespace App\Providers;

use App\Core\Billing\BillingManager;
use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\PaymentGatewayManager;
use App\Core\Billing\PaymentRegistry;
use App\Core\Ai\AiGatewayManager;
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

        // ADR-411: AI Gateway Manager singleton
        $this->app->singleton(AiGatewayManager::class);

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

        // ADR-412: Boot AI provider registry
        \App\Core\Ai\AiProviderRegistry::boot();

        // ADR-436: Register AI module contracts
        \App\Core\Ai\AiModuleContractRegistry::register(new \App\Core\Documents\DocumentAiModule());

        // ADR-437: Register workflow triggers (topics that can trigger company workflows)
        \App\Core\Automation\WorkflowTriggerRegistry::register('document.updated', 'Document mis à jour', [
            ['field' => 'ai_status', 'type' => 'string', 'label' => 'Statut AI'],
            ['field' => 'status', 'type' => 'string', 'label' => 'Statut document'],
        ], [
            ['type' => 'send_notification', 'label' => 'Envoyer notification'],
            ['type' => 'webhook', 'label' => 'Appeler webhook'],
        ]);
        \App\Core\Automation\WorkflowTriggerRegistry::register('members.changed', 'Membre modifié', [
            ['field' => 'action', 'type' => 'string', 'label' => 'Action'],
        ], [
            ['type' => 'send_notification', 'label' => 'Envoyer notification'],
            ['type' => 'webhook', 'label' => 'Appeler webhook'],
        ]);
        \App\Core\Automation\WorkflowTriggerRegistry::register('plan.changed', 'Plan modifié', [], [
            ['type' => 'send_notification', 'label' => 'Envoyer notification'],
            ['type' => 'log', 'label' => 'Écrire dans le log'],
        ]);
        \App\Core\Automation\WorkflowTriggerRegistry::register('modules.changed', 'Module activé/désactivé', [
            ['field' => 'action', 'type' => 'string', 'label' => 'Action'],
        ], [
            ['type' => 'send_notification', 'label' => 'Envoyer notification'],
        ]);
        \App\Core\Automation\WorkflowTriggerRegistry::register('rbac.changed', 'Rôle ou permission modifié', [], [
            ['type' => 'send_notification', 'label' => 'Envoyer notification'],
        ]);

        // ADR-149: Boot dashboard widget registry (convention-based discovery)
        \App\Modules\Dashboard\DashboardWidgetRegistry::boot();

        // ADR-149: Auto-inject widgets when module enabled for company
        \Illuminate\Support\Facades\Event::listen(
            \App\Core\Events\ModuleEnabled::class,
            \App\Modules\Dashboard\Listeners\InjectModuleWidgets::class,
        );

        // ADR-224: Addon billing — invoice on enable, credit on disable
        \Illuminate\Support\Facades\Event::listen(
            \App\Core\Events\ModuleEnabled::class,
            \App\Core\Billing\Listeners\AddonBillingListener::class,
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Core\Events\ModuleDisabled::class,
            \App\Core\Billing\Listeners\AddonCreditListener::class,
        );

        // ADR-446: Email event subscriber for delivery tracking
        \Illuminate\Support\Facades\Event::subscribe(
            \App\Core\Email\EmailEventSubscriber::class,
        );

        // ADR-318: Rate limiter for async reconciliation jobs
        \Illuminate\Support\Facades\RateLimiter::for('billing-reconcile', function (object $job) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10);
        });

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
