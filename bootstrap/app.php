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
            Route::middleware(['api', 'auth:sanctum', 'company.context'])
                ->prefix('api')
                ->group(base_path('routes/company.php'));

            Route::middleware(['api', 'auth:sanctum', 'platform.admin'])
                ->prefix('api/platform')
                ->group(base_path('routes/platform.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company.context' => \App\Company\Http\Middleware\SetCompanyContext::class,
            'company.role' => \App\Company\Http\Middleware\EnsureRole::class,
            'platform.admin' => \App\Platform\Http\Middleware\EnsurePlatformAdmin::class,
            'module.active' => \App\Core\Modules\EnsureModuleActive::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
