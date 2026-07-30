<?php

namespace App\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Sends a notification without letting a delivery failure break the request.
 *
 * The database channel is listed first on every notification, so the in-app
 * (bell) notification is always persisted before the mail channel runs. If the
 * mail transport (Resend) then fails, we log it and carry on rather than 500
 * the user's action.
 */
class Notifier
{
    public static function send(object $notifiable, Notification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Notification delivery failed', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
