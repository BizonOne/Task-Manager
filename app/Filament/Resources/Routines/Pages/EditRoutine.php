<?php

namespace App\Filament\Resources\Routines\Pages;

use App\Filament\Resources\Routines\RoutineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRoutine extends EditRecord
{
    protected static string $resource = RoutineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
