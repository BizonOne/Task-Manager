<?php

namespace App\Filament\Resources\TaskStatuses\Tables;

use App\Models\Task;
use App\Models\TaskStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaskStatusesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->weight('semibold'),
                TextColumn::make('key')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->description('Stored on each task'),
                TextColumn::make('color')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('tasks_count')
                    ->label('Tasks')
                    ->counts('tasks')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray'),
                IconColumn::make('is_completed')
                    ->label('Completed')
                    ->boolean(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // Deleting a status would orphan its tasks, so make the
                    // admin say where those tasks should go.
                    ->schema(fn (TaskStatus $record): array => $record->tasks()->count() === 0 ? [] : [
                        Select::make('move_to')
                            ->label('Move these tasks to')
                            ->options(fn () => TaskStatus::where('key', '!=', $record->key)
                                ->orderBy('sort_order')
                                ->pluck('label', 'key'))
                            ->required(),
                    ])
                    ->modalDescription(function (TaskStatus $record): string {
                        $count = $record->tasks()->count();

                        return $count === 0
                            ? "Delete the \"{$record->label}\" status?"
                            : "{$count} task(s) are in \"{$record->label}\". Choose where to move them.";
                    })
                    ->action(function (TaskStatus $record, array $data): void {
                        if ($record->is_default && TaskStatus::count() > 1) {
                            Notification::make()
                                ->title('Cannot delete the default status')
                                ->body('Make another status the default for new tasks first.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (TaskStatus::count() === 1) {
                            Notification::make()
                                ->title('Cannot delete the last status')
                                ->body('The task board needs at least one status.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (filled($data['move_to'] ?? null)) {
                            Task::where('status', $record->key)->update(['status' => $data['move_to']]);
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Status deleted')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
