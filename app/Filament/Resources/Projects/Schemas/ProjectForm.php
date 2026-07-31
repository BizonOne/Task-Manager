<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProjectForm
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
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('The slug is generated automatically from the name.'),
                Select::make('status')
                    ->options([
                        'not_started' => 'Not started',
                        'in_progress' => 'In progress',
                        'completed' => 'Completed',
                        'closed' => 'Closed',
                    ])
                    ->default('not_started')
                    ->required(),
                DatePicker::make('start_date'),
                DatePicker::make('end_date'),
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
}
