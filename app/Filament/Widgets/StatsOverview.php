<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $totalTasks = Task::count();
        $completedTasks = Task::where('status', 'completed')->count();
        $completionRate = $totalTasks > 0 ? round($completedTasks / $totalTasks * 100) : 0;

        return [
            Stat::make('Users', User::count())
                ->description('Registered accounts')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),
            Stat::make('Projects', Project::count())
                ->description(Project::where('status', 'in_progress')->count().' in progress')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('info'),
            Stat::make('Tasks', $totalTasks)
                ->description($completedTasks.' completed')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('warning'),
            Stat::make('Completion rate', $completionRate.'%')
                ->description('Tasks marked completed')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($completionRate >= 50 ? 'success' : 'gray'),
        ];
    }
}
