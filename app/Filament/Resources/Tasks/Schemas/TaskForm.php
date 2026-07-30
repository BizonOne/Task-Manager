<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\TaskStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('user_id')
                    ->label('Owner')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                    ])
                    ->default('medium')
                    ->required(),
                Select::make('status')
                    ->options(fn (): array => TaskStatus::options())
                    ->default(fn (): string => TaskStatus::defaultKey())
                    ->required(),
                DatePicker::make('due_date'),
                TextInput::make('estimated_hours')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('h'),
                Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }
}
