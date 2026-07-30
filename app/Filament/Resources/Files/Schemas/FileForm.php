<?php

namespace App\Filament\Resources\Files\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FileForm
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
                    ->maxLength(255),
                Select::make('type')
                    ->options([
                        'project' => 'Project',
                        'docs' => 'Docs',
                        'txt' => 'Text',
                        'code' => 'Code',
                        'image' => 'Image',
                    ])
                    ->required(),
                TextInput::make('path')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Storage path relative to the public disk.')
                    ->columnSpanFull(),
            ]);
    }
}
