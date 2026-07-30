<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reminder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ReminderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Reminder');
    }

    public function view(AuthUser $authUser, Reminder $reminder): bool
    {
        return $authUser->can('View:Reminder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Reminder');
    }

    public function update(AuthUser $authUser, Reminder $reminder): bool
    {
        return $authUser->can('Update:Reminder');
    }

    public function delete(AuthUser $authUser, Reminder $reminder): bool
    {
        return $authUser->can('Delete:Reminder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Reminder');
    }

    public function restore(AuthUser $authUser, Reminder $reminder): bool
    {
        return $authUser->can('Restore:Reminder');
    }

    public function forceDelete(AuthUser $authUser, Reminder $reminder): bool
    {
        return $authUser->can('ForceDelete:Reminder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Reminder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Reminder');
    }

    public function replicate(AuthUser $authUser, Reminder $reminder): bool
    {
        return $authUser->can('Replicate:Reminder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Reminder');
    }
}
