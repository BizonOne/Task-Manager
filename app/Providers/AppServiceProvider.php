<?php

namespace App\Providers;

use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

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
        // Spatie's Role model lives in the vendor namespace, so Laravel's
        // policy auto-discovery can't map it — register it explicitly.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
