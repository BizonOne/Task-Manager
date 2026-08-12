<?php

namespace App\Providers;

use App\Models\ChecklistItem;
use App\Models\Task;
use App\Models\TaskComment;
use App\Observers\ChecklistItemObserver;
use App\Observers\TaskCommentObserver;
use App\Observers\TaskObserver;
use App\Policies\RolePolicy;
use App\Support\Dates;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
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
        ChecklistItem::observe(ChecklistItemObserver::class);

        // The front-end is built on Bootstrap, so paginators should be too.
        Paginator::useBootstrapFive();

        // Filament formats its own columns, so it needs telling where the
        // reader is — otherwise the admin panel shows UTC while every page
        // outside it shows local time.
        FilamentTimezone::set(Dates::timezone());

        // And how to write it down. Filament's own defaults carry seconds
        // ("Aug 3, 2026 14:03:27"), which nobody reads off a table.
        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat(Dates::DATE)
            ->defaultDateTimeDisplayFormat(Dates::DATE_TIME)
            ->defaultTimeDisplayFormat(Dates::TIME));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat(Dates::DATE)
            ->defaultDateTimeDisplayFormat(Dates::DATE_TIME)
            ->defaultTimeDisplayFormat(Dates::TIME));
    }
}
