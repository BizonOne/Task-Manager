<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Telegram;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a notification to whatever Telegram chats a person has connected.
 *
 * One person can have more than one — a phone and a desktop signed into
 * different accounts — so this sends to each of them and treats them
 * separately: a chat that has blocked the bot must not stop the others.
 */
class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'notificationChannels') || ! Telegram::configured()) {
            return;
        }

        $event = Delivery::eventKey($notification);
        $message = Delivery::chatMessage($notification, $notifiable)->toTelegramHtml();

        $chats = $notifiable->notificationChannels()
            ->ofType(NotificationChannel::TELEGRAM)
            ->live()
            ->get();

        foreach ($chats as $chat) {
            if (! $chat->wants($event) || $chat->target === null) {
                continue;
            }

            try {
                Telegram::sendMessage($chat->target, $message);
                $chat->recordDelivery();
            } catch (Throwable $e) {
                // Said out loud on the person's settings page rather than only
                // in a log: somebody who blocked the bot needs to know that is
                // why it went quiet.
                $chat->recordFailure($e->getMessage());

                Log::warning('Telegram notification failed', [
                    'channel_id' => $chat->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
