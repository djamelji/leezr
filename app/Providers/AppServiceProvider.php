<?php

namespace App\Providers;

use App\Core\Models\Company;
use App\Core\Models\User;
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
        //
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

        // ADR-081: Remove Vite hot file outside local — prevents production
        // from loading dev server assets (:5173) if the file leaks.
        if (! $this->app->environment('local')) {
            $hotFile = public_path('hot');

            if (file_exists($hotFile)) {
                @unlink($hotFile);
            }
        }
    }
}
