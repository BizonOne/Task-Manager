<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use App\Models\TaskStatus;
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
            ->query(fn (): Builder => Task::query()->with(['project', 'user'])->latest())
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->wrap(),
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
