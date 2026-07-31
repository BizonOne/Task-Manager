<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Models\TaskActivity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The task's full history, newest first. Read-only by design — an audit trail
 * you can edit is not an audit trail.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user')->reorder('created_at', 'desc'))
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->since()
                    ->tooltip(fn (TaskActivity $record): string => $record->created_at->toDayDateTimeString())
                    ->sortable(),
                TextColumn::make('actor_name')
                    ->label('Who')
                    ->weight('semibold'),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        TaskActivity::EVENT_CREATED => 'success',
                        TaskActivity::EVENT_ASSIGNED => 'info',
                        TaskActivity::EVENT_UNASSIGNED, TaskActivity::EVENT_COMMENT_DELETED => 'danger',
                        TaskActivity::EVENT_COMMENTED => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()),
                TextColumn::make('description')
                    ->label('What changed')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->options([
                        TaskActivity::EVENT_CREATED => 'Created',
                        TaskActivity::EVENT_UPDATED => 'Updated',
                        TaskActivity::EVENT_ASSIGNED => 'Assigned',
                        TaskActivity::EVENT_UNASSIGNED => 'Unassigned',
                        TaskActivity::EVENT_COMMENTED => 'Commented',
                        TaskActivity::EVENT_COMMENT_DELETED => 'Comment deleted',
                    ]),
                SelectFilter::make('user_id')
                    ->label('Who')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
