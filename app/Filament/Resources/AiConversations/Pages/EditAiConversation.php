<?php

namespace App\Filament\Resources\AiConversations\Pages;

use App\Filament\Resources\AiConversations\AiConversationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiConversation extends EditRecord
{
    protected static string $resource = AiConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
