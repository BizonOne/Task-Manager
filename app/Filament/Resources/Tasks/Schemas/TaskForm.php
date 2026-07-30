<?php

namespace App\Filament\Resources\Tasks\Schemas;

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
                    ->options([
                        'to_do' => 'To do',
                        'in_progress' => 'In progress',
                        'on_hold' => 'On hold',
                        'in_review' => 'In review',
                        'completed' => 'Completed',
                    ])
                    ->default('to_do')
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
