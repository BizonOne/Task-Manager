<?php

namespace App\Filament\Resources\Reminders\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ReminderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Owner')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Select::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ])
                    ->default('medium')
                    ->required(),
                TextInput::make('category')
                    ->maxLength(255),
                DatePicker::make('date'),
                TimePicker::make('time'),
                TextInput::make('location')
                    ->maxLength(255),
                Toggle::make('is_completed'),
                Toggle::make('is_recurring')
                    ->live(),
                Select::make('recurrence_type')
                    ->options([
                        'none' => 'None',
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                    ])
                    ->default('none')
                    ->visible(fn ($get): bool => (bool) $get('is_recurring')),
                TextInput::make('recurrence_interval')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->visible(fn ($get): bool => (bool) $get('is_recurring')),
                DateTimePicker::make('completed_at'),
                DateTimePicker::make('snooze_until'),
                TagsInput::make('tags')
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
