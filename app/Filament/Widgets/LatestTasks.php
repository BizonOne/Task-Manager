<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\TaskStatus;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestTasks extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest tasks';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Task::query()->active()->with(['project', 'user'])->latest())
            ->defaultPaginationPageOption(5)
            // Clicking a row opens the task — previously the dashboard was a
            // dead end and you had to go via Projects to reach a task.
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Task $record): string => TaskResource::getUrl('edit', ['record' => $record])),
            ])
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->wrap()
                    ->weight('semibold')
                    ->description(fn (Task $record): ?string => $record->project?->name),
                TextColumn::make('project.name')
                    ->label('Project')
                    ->toggleable(),
                TextColumn::make('user.name')
                    ->label('Owner')
                    ->toggleable(),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => TaskStatus::filamentColorFor($state))
                    ->formatStateUsing(fn (string $state): string => TaskStatus::labelFor($state)),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
