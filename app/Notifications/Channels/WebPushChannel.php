<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\SubscriptionGone;
use App\Support\Notifications\WebPush;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a notification to a person's browsers.
 *
 * One subscription per browser per machine, so somebody with a laptop and a
 * phone has two — and a laptop that has been wiped must not stop the phone
 * hearing about anything.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'notificationChannels') || ! WebPush::configured()) {
            return;
        }

        $event = Delivery::eventKey($notification);
        $message = Delivery::chatMessage($notification, $notifiable);

        $subscriptions = $notifiable->notificationChannels()
            ->ofType(NotificationChannel::WEBPUSH)
            ->live()
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! $subscription->wants($event) || $subscription->target === null) {
                continue;
            }

            try {
                WebPush::send($subscription, $message);
                $subscription->recordDelivery();
            } catch (SubscriptionGone) {
                // Nothing is listening and nothing ever will be again. Leaving
                // it would put a dead row on the settings page for somebody to
                // wonder about.
                $subscription->delete();
            } catch (Throwable $e) {
                $subscription->recordFailure($e->getMessage());

                Log::warning('Web push failed', [
                    'channel_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
