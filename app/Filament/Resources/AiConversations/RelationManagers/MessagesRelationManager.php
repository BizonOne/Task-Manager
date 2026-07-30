<?php

namespace App\Filament\Resources\AiConversations\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Messages';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->columns([
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'assistant' ? 'info' : 'gray'),
                TextColumn::make('content')
                    ->wrap()
                    ->limit(300),
                TextColumn::make('model')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'user' => 'User',
                        'assistant' => 'Assistant',
                    ]),
            ])
            ->headerActions([])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
