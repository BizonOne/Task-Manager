<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\Invitations;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Whether this user should be emailed an invitation instead of being given
     * a password by the admin. Captured before the form state is dehydrated.
     */
    private bool $shouldInvite = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->shouldInvite = (bool) ($this->data['send_invitation'] ?? false);

        // An invited user has no password until they accept.
        if ($this->shouldInvite) {
            $data['password'] = null;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->shouldInvite) {
            return;
        }

        Invitations::send($this->record, Auth::user());

        Notification::make()
            ->title('Invitation sent')
            ->body("We emailed an invitation to {$this->record->email}.")
            ->success()
            ->send();
    }
}
