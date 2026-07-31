<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Support\RichText;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Discussion';

    protected static ?string $recordTitleAttribute = 'body';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('Author')
                ->relationship('user', 'name')
                ->searchable()
                ->preload()
                ->required(),
            RichEditor::make('body')
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike'],
                    ['h2', 'h3', 'bulletList', 'orderedList'],
                    ['blockquote', 'codeBlock', 'link'],
                    ['undo', 'redo'],
                ])
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Author')
                    ->sortable(),
                TextColumn::make('body')
                    // Comments are stored as HTML; a table cell wants the
                    // words, not the markup.
                    ->formatStateUsing(fn (?string $state): string => RichText::toText($state))
                    ->wrap()
                    ->limit(200),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
