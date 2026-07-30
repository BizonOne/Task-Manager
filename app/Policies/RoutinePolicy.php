<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Routine;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RoutinePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Routine');
    }

    public function view(AuthUser $authUser, Routine $routine): bool
    {
        return $authUser->can('View:Routine');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Routine');
    }

    public function update(AuthUser $authUser, Routine $routine): bool
    {
        return $authUser->can('Update:Routine');
    }

    public function delete(AuthUser $authUser, Routine $routine): bool
    {
        return $authUser->can('Delete:Routine');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Routine');
    }

    public function restore(AuthUser $authUser, Routine $routine): bool
    {
        return $authUser->can('Restore:Routine');
    }

    public function forceDelete(AuthUser $authUser, Routine $routine): bool
    {
        return $authUser->can('ForceDelete:Routine');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Routine');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Routine');
    }

    public function replicate(AuthUser $authUser, Routine $routine): bool
    {
        return $authUser->can('Replicate:Routine');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Routine');
    }
}
