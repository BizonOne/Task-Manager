<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Slack;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a notification to somebody's Slack direct messages.
 */
class SlackChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'notificationChannels') || ! Slack::configured()) {
            return;
        }

        $event = Delivery::eventKey($notification);
        $message = Delivery::chatMessage($notification, $notifiable)->toSlackMarkdown();

        $conversations = $notifiable->notificationChannels()
            ->ofType(NotificationChannel::SLACK)
            ->live()
            ->get();

        foreach ($conversations as $conversation) {
            if (! $conversation->wants($event) || $conversation->target === null) {
                continue;
            }

            try {
                Slack::postMessage($conversation->target, $message);
                $conversation->recordDelivery();
            } catch (Throwable $e) {
                $conversation->recordFailure($e->getMessage());

                Log::warning('Slack notification failed', [
                    'channel_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
