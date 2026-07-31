<?php

namespace App\Filament\Resources\Notes\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class NoteForm
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
                TextInput::make('category')
                    ->maxLength(255),
                Toggle::make('is_favorite'),
                DatePicker::make('date'),
                TimePicker::make('time'),
                TagsInput::make('tags')
                    ->columnSpanFull(),
                RichEditor::make('content')
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
