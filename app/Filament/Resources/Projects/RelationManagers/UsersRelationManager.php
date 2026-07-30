<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Team';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Roles a member can hold inside a project (stored on the project_teams pivot).
     */
    protected static array $projectRoles = [
        'member' => 'Member',
        'manager' => 'Manager',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role')
                    ->options(static::$projectRoles)
                    ->default('member')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('pivot.role')
                    ->label('Project role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::$projectRoles[$state] ?? ucfirst((string) $state)),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->options(static::$projectRoles)
                            ->default('member')
                            ->required(),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
