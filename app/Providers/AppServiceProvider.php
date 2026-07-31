<?php

namespace App\Providers;

use App\Models\Task;
use App\Models\TaskComment;
use App\Observers\TaskCommentObserver;
use App\Observers\TaskObserver;
use App\Policies\RolePolicy;
use Illuminate\Pagination\Paginator;
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

        // Record every task change, wherever it comes from.
        Task::observe(TaskObserver::class);
        TaskComment::observe(TaskCommentObserver::class);

        // The front-end is built on Bootstrap, so paginators should be too.
        Paginator::useBootstrapFive();
    }
}
