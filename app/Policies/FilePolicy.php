<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\File;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FilePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:File');
    }

    public function view(AuthUser $authUser, File $file): bool
    {
        return $authUser->can('View:File');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:File');
    }

    public function update(AuthUser $authUser, File $file): bool
    {
        return $authUser->can('Update:File');
    }

    public function delete(AuthUser $authUser, File $file): bool
    {
        return $authUser->can('Delete:File');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:File');
    }

    public function restore(AuthUser $authUser, File $file): bool
    {
        return $authUser->can('Restore:File');
    }

    public function forceDelete(AuthUser $authUser, File $file): bool
    {
        return $authUser->can('ForceDelete:File');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:File');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:File');
    }

    public function replicate(AuthUser $authUser, File $file): bool
    {
        return $authUser->can('Replicate:File');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:File');
    }
}
