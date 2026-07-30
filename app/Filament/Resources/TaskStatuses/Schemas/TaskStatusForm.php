<?php

namespace App\Filament\Resources\TaskStatuses\Schemas;

use App\Models\TaskStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TaskStatusForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    // Derive the key from the label when creating; the key is
                    // what tasks store, so it must not change afterwards.
                    ->afterStateUpdated(function (?string $state, callable $set, string $operation): void {
                        if ($operation === 'create') {
                            $set('key', Str::snake(Str::lower($state ?? '')));
                        }
                    })
                    ->helperText('Shown on the board and in task forms.'),

                TextInput::make('key')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z][a-z0-9_]*$/')
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated()
                    ->helperText(fn (string $operation): string => $operation === 'edit'
                        ? 'Cannot be changed — existing tasks are stored against this key.'
                        : 'Lowercase letters, digits and underscores. Stored on each task.'),

                Select::make('color')
                    ->options(TaskStatus::colorOptions())
                    ->default('gray')
                    ->required()
                    ->native(false)
                    ->helperText('Colour of the column and status chip.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(fn (): int => (TaskStatus::max('sort_order') ?? 0) + 1)
                    ->required()
                    ->helperText('Left-to-right order of the board columns.'),

                Toggle::make('is_completed')
                    ->label('Counts as completed')
                    ->helperText('Tasks in this status count as finished for progress bars and reporting.'),

                Toggle::make('is_default')
                    ->label('Default for new tasks')
                    ->helperText('New tasks start in this status. Turning this on clears it from the other statuses.'),
            ]);
    }
}
