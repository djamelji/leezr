<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['api', 'auth:sanctum', 'company.context', 'session.governance'])
                ->prefix('api')
                ->group(base_path('routes/company.php'));

            Route::middleware(['api'])
                ->prefix('api/platform')
                ->group(base_path('routes/platform.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA: redirect unauthenticated non-JSON requests to /login (avoids Route [login] not defined)
        $middleware->redirectGuestsTo('/login');
        $middleware->alias([
            'company.context' => \App\Company\Http\Middleware\SetCompanyContext::class,
            'company.access' => \App\Company\Http\Middleware\EnsureCompanyAccess::class,

            // Deprecated — use company.access instead
            'company.role' => \App\Company\Http\Middleware\EnsureRole::class,
            'company.permission' => \App\Company\Http\Middleware\EnsureCompanyPermission::class,
            'module.active' => \App\Core\Modules\EnsureModuleActive::class,

            'platform.permission' => \App\Platform\Http\Middleware\EnsurePlatformPermission::class,

            'session.governance' => \App\Http\Middleware\SessionGovernance::class,
        ]);

        $middleware->appendToGroup('api', \App\Http\Middleware\AddBuildVersion::class);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
