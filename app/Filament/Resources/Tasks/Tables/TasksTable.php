<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Support\Archive;
use App\Support\Dates;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('user.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low' => 'gray',
                        'medium' => 'warning',
                        'high' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline())
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => TaskStatus::filamentColorFor($state))
                    ->formatStateUsing(fn (string $state): string => TaskStatus::labelFor($state))
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('archived_at')
                    ->label('Archived')
                    ->boolean()
                    ->tooltip(fn (Task $record): ?string => $record->archived_at
                        ? 'Archived '.Dates::dateTime($record->archived_at)
                        : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => TaskStatus::options()),
                SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                    ]),
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload(),
                // Hidden by default rather than gone: the archive is meant to be
                // reachable, just not in the way.
                TernaryFilter::make('archived')
                    ->label('Archive')
                    ->placeholder('Live tasks')
                    ->trueLabel('Archived only')
                    ->falseLabel('Live and archived')
                    ->queries(
                        true: fn (Builder $query) => $query->archived(),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->active(),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Move to archive')
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->modalDescription('They keep their discussion, files and links, and can be restored one by one.')
                        ->action(fn (Collection $records) => $records->each(fn (Task $task) => Archive::archive($task)))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('restore')
                        ->label('Bring back from archive')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->action(fn (Collection $records) => $records->each(fn (Task $task) => Archive::restore($task)))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
