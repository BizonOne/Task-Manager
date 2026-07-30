<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label('Owner')
                    ->sortable(),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in_progress' => 'warning',
                        'in_review' => 'info',
                        'completed' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'to_do' => 'To do',
                        'in_progress' => 'In progress',
                        'on_hold' => 'On hold',
                        'in_review' => 'In review',
                        'completed' => 'Completed',
                    ]),
            ])
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
