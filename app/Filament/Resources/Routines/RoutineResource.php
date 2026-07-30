<?php

namespace App\Filament\Resources\Routines;

use App\Filament\Resources\Routines\Pages\CreateRoutine;
use App\Filament\Resources\Routines\Pages\EditRoutine;
use App\Filament\Resources\Routines\Pages\ListRoutines;
use App\Filament\Resources\Routines\Schemas\RoutineForm;
use App\Filament\Resources\Routines\Tables\RoutinesTable;
use App\Models\Routine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RoutineResource extends Resource
{
    protected static ?string $model = Routine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Productivity';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return RoutineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoutinesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoutines::route('/'),
            'create' => CreateRoutine::route('/create'),
            'edit' => EditRoutine::route('/{record}/edit'),
        ];
    }
}
