<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Company context of the request (or of a job, via runAs()).
        $this->app->singleton(CurrentCompany::class);

        // Application permission models: they guard the exclusive
        // `ai.vault.access` permission (never attached to a role).
        // Deliberately no Gate::before: Súper Admin must not bypass it.
        config([
            'permission.models.permission' => Permission::class,
            'permission.models.role' => Role::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);
    }
}
