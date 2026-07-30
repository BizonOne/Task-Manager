<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Str;

/**
 * Issues and revokes user invitations. An invited user exists in the database
 * with no password; they set one themselves through the emailed invite link.
 */
class Invitations
{
    /**
     * Issue (or re-issue) an invitation for the user and email them the link.
     * Returns the plain token so callers can build the URL if they need to.
     */
    public static function send(User $user, ?User $invitedBy = null): string
    {
        $token = Str::random(64);

        $user->forceFill([
            'invitation_token' => $token,
            'invited_at' => now(),
            'invited_by_id' => $invitedBy?->id ?? $user->invited_by_id,
            'invitation_accepted_at' => null,
        ])->save();

        Notifier::send($user, new UserInvitationNotification($token, $invitedBy));

        return $token;
    }

    /**
     * Accept an invitation: set the password, clear the token and mark the
     * email as verified — clicking the emailed link proves the address works.
     */
    public static function accept(User $user, string $password): void
    {
        $user->forceFill([
            'password' => bcrypt($password),
            'invitation_token' => null,
            'invitation_accepted_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }

    /**
     * Look up a user by a pending invitation token.
     */
    public static function findByToken(string $token): ?User
    {
        return User::where('invitation_token', $token)
            ->whereNull('invitation_accepted_at')
            ->first();
    }
}
