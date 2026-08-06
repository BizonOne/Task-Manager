<?php

namespace App\Support\Notifications;

use App\Models\NotificationChannel;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush as Pusher;
use RuntimeException;

/**
 * Notifications the browser shows even when the tab is closed.
 *
 * Push is not a message to a person, it is a message to one browser on one
 * machine. Somebody with a laptop and a phone has two subscriptions and has to
 * allow it twice — that is the web's model, not a shortcoming here.
 *
 * The push service (Google's, Mozilla's, Apple's — whichever the browser
 * picked) relays the message without being able to read it: the payload is
 * encrypted to keys the browser generated, and our VAPID key pair is what
 * proves the message came from this application.
 */
class WebPush
{
    /**
     * What actually talks to the push service.
     *
     * A seam, so the delivery rules can be tested without a network: which
     * failures forget a subscription and which merely record one is exactly
     * the behaviour worth pinning down, and it is unreachable if the only way
     * in is a live push service.
     *
     * @var (callable(NotificationChannel, ChatMessage): void)|null
     */
    private static $sender = null;

    /**
     * @param  callable(NotificationChannel, ChatMessage): void  $sender
     */
    public static function sendUsing(callable $sender): void
    {
        self::$sender = $sender;
    }

    public static function sendNormally(): void
    {
        self::$sender = null;
    }

    public static function configured(): bool
    {
        return self::publicKey() !== null && trim((string) config('services.webpush.private_key')) !== '';
    }

    public static function publicKey(): ?string
    {
        $key = trim((string) config('services.webpush.public_key'));

        return $key === '' ? null : $key;
    }

    /**
     * A fresh VAPID pair, for `php artisan webpush:keys`.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generateKeys(): array
    {
        return VAPID::createVapidKeys();
    }

    /**
     * Send to one subscription.
     *
     * @throws SubscriptionGone when the browser has thrown the subscription
     *                          away and it should be forgotten
     * @throws RuntimeException on anything else
     */
    public static function send(NotificationChannel $channel, ChatMessage $message): void
    {
        if (self::$sender !== null) {
            (self::$sender)($channel, $message);

            return;
        }

        if (! self::configured()) {
            throw new RuntimeException('Browser notifications are not configured.');
        }

        $meta = (array) $channel->meta;

        $subscription = Subscription::create([
            'endpoint' => (string) $channel->target,
            'publicKey' => (string) ($meta['p256dh'] ?? ''),
            'authToken' => (string) ($meta['auth'] ?? ''),
            'contentEncoding' => (string) ($meta['encoding'] ?? 'aesgcm'),
        ]);

        $pusher = new Pusher(['VAPID' => [
            // Who to complain to if this application misbehaves — the push
            // services want a way to reach us, and refuse the message without
            // one.
            'subject' => (string) (config('services.webpush.subject') ?: config('app.url')),
            'publicKey' => self::publicKey(),
            'privateKey' => trim((string) config('services.webpush.private_key')),
        ]]);

        $report = $pusher->sendOneNotification($subscription, json_encode([
            'title' => $message->title,
            'body' => implode("\n", $message->lines),
            'url' => $message->url,
        ], JSON_UNESCAPED_UNICODE));

        if ($report->isSuccess()) {
            return;
        }

        // 404 and 410 mean this browser has thrown the subscription away —
        // cleared its data, uninstalled, revoked permission. Retrying it
        // forever would be sending to nobody.
        if ($report->isSubscriptionExpired()) {
            throw new SubscriptionGone($report->getReason());
        }

        throw new RuntimeException($report->getReason());
    }
}
