<?php

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Models\Task;
use App\Models\User;
use App\Support\TaskAssignment;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class AssigneesRelationManager extends RelationManager
{
    protected static string $relationship = 'assignees';

    protected static ?string $title = 'Assignees';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function getTask(): Task
    {
        /** @var Task $task */
        $task = $this->getOwnerRecord();

        return $task;
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
            ])
            ->headerActions([
                // Filament writes the pivot row itself, so the history entry
                // and the notification hang off the action. Without this an
                // assignment made here reached the person's board silently.
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->after(function (User $record): void {
                        TaskAssignment::announceAttached($this->getTask(), $record);
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->after(function (User $record): void {
                        TaskAssignment::announceDetached($this->getTask(), $record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->after(function (Collection $records): void {
                            foreach ($records as $record) {
                                TaskAssignment::announceDetached($this->getTask(), $record);
                            }
                        }),
                ]),
            ]);
    }
}
