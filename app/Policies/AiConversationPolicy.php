<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiConversation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AiConversationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AiConversation');
    }

    public function view(AuthUser $authUser, AiConversation $aiConversation): bool
    {
        return $authUser->can('View:AiConversation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AiConversation');
    }

    public function update(AuthUser $authUser, AiConversation $aiConversation): bool
    {
        return $authUser->can('Update:AiConversation');
    }

    public function delete(AuthUser $authUser, AiConversation $aiConversation): bool
    {
        return $authUser->can('Delete:AiConversation');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AiConversation');
    }

    public function restore(AuthUser $authUser, AiConversation $aiConversation): bool
    {
        return $authUser->can('Restore:AiConversation');
    }

    public function forceDelete(AuthUser $authUser, AiConversation $aiConversation): bool
    {
        return $authUser->can('ForceDelete:AiConversation');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AiConversation');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AiConversation');
    }

    public function replicate(AuthUser $authUser, AiConversation $aiConversation): bool
    {
        return $authUser->can('Replicate:AiConversation');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AiConversation');
    }
}
