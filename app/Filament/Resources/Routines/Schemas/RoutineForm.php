<?php

namespace App\Filament\Resources\Routines\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;

class RoutineForm
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
                Select::make('frequency')
                    ->options([
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                    ])
                    ->required(),
                TimePicker::make('start_time')
                    ->required(),
                TimePicker::make('end_time')
                    ->required(),
                // days/weeks/months are stored as JSON strings by the front-end
                // (no model cast), so convert JSON <-> array in the form layer
                // only and keep the stored format byte-for-byte compatible.
                ...self::jsonListField('days'),
                ...self::jsonListField('weeks'),
                ...self::jsonListField('months'),
                RichEditor::make('description')
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'strike'],
                        ['h2', 'h3', 'bulletList', 'orderedList'],
                        ['blockquote', 'codeBlock', 'link'],
                        ['undo', 'redo'],
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, TagsInput>
     */
    private static function jsonListField(string $name): array
    {
        return [
            TagsInput::make($name)
                ->afterStateHydrated(function (TagsInput $component, $state): void {
                    if (is_string($state)) {
                        $component->state(json_decode($state, true) ?: []);
                    }
                })
                ->dehydrateStateUsing(fn ($state): string => json_encode($state ?: [])),
        ];
    }
}
