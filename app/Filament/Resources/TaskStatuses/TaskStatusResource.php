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
use UnitEnum;

class TaskStatusResource extends Resource
{
    protected static ?string $model = TaskStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Project Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $navigationLabel = 'Task Statuses';

    protected static ?string $modelLabel = 'task status';

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
