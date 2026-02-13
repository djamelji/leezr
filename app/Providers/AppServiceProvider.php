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
    }
}
