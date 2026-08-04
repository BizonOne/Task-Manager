<?php

namespace App\Filament\Resources\TaskStatuses;

use App\Filament\Resources\TaskStatuses\Pages\ManageTaskStatuses;
use App\Filament\Resources\TaskStatuses\Schemas\TaskStatusForm;
use App\Filament\Resources\TaskStatuses\Tables\TaskStatusesTable;
use App\Models\TaskStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TaskStatusResource extends Resource
{
    protected static ?string $model = TaskStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Project Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $navigationLabel = 'Task Statuses';

    protected static ?string $modelLabel = 'shared task status';

    /**
     * Only the columns shared by every board.
     *
     * A project that keeps its own columns manages them on the project itself,
     * where the person who arranged that board can see them. Mixing both sets
     * into one admin list would make every rename look like it changed
     * everybody's board.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('project_id');
    }

    public static function form(Schema $schema): Schema
    {
        return TaskStatusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaskStatusesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTaskStatuses::route('/'),
        ];
    }
}
