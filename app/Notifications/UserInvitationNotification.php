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
        $inviter = $this->invitedBy?->name;

        return (new MailMessage)
            ->subject("You're invited to join ".$brand)
            ->greeting("Hi {$notifiable->name},")
            ->line($inviter
                ? "{$inviter} invited you to join {$brand}."
                : "You've been invited to join {$brand}.")
            ->line('Choose your own password to activate your account.')
            ->action('Accept invitation', route('invitation.show', $this->token))
            ->line('This link is personal to you — please do not share it.');
    }
}
