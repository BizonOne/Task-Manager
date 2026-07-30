<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites someone to the workspace. Mail only — an invited user has no account
 * to sign in to yet, so there is no bell for a database notification.
 */
class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public ?User $invitedBy = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = Brand::name();
        $inviter = $this->invitedBy;

        // Roles are what the invitee actually gains, so name them.
        $roles = $notifiable->roles?->pluck('name')
            ->map(fn (string $r) => str_replace('_', ' ', $r))
            ->implode(', ');

        return (new MailMessage)
            ->subject($inviter
                ? $inviter->name.' invited you to join '.$brand
                : "You're invited to join ".$brand)
            ->view('emails.user-invitation', [
                'recipient' => $notifiable,
                'author' => $inviter,
                'brandName' => $brand,
                'acceptUrl' => route('invitation.show', $this->token),
                'rows' => [
                    ['label' => 'Workspace', 'value' => $brand, 'url' => url('/')],
                    ['label' => 'Your email', 'value' => $notifiable->email],
                    ['label' => 'Access level', 'value' => $roles !== '' ? $roles : null],
                    ['label' => 'Invited by', 'value' => $inviter?->name],
                ],
            ]);
    }
}
