<?php

namespace App\Filament\Resources\TaskStatuses\Pages;

use App\Filament\Resources\TaskStatuses\TaskStatusResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTaskStatuses extends ManageRecords
{
    protected static string $resource = TaskStatusResource::class;

    public function getSubheading(): ?string
    {
        return 'Statuses are the columns of the task board. Drag to reorder them.';
    }
}
