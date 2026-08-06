<?php

namespace App\Support\Notifications;

use App\Models\NotificationChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Which ways a given notification goes out.
 *
 * The one place that answers it. Every notification used to name its own
 * channels, which meant adding Telegram would have been six edits and one
 * forgotten file — and the forgotten one is always the one somebody was
 * waiting on.
 */
class Delivery
{
    /**
     * Always sent, whatever anybody has connected.
     *
     * The bell because it is the record, and email because it is the address
     * we already have for everyone. The connected channels are a second copy,
     * not a replacement — somebody who unhooks Telegram must not silently stop
     * hearing about their work.
     *
     * @var array<int, string>
     */
    private const ALWAYS = ['database', 'mail'];

    /**
     * @var array<string, class-string>
     */
    private const DRIVERS = [
        NotificationChannel::TELEGRAM => TelegramChannel::class,
        NotificationChannel::WEBPUSH => WebPushChannel::class,
        NotificationChannel::SLACK => SlackChannel::class,
    ];

    /**
     * @return array<int, string>
     */
    public static function via(object $notifiable, Notification $notification): array
    {
        $channels = self::ALWAYS;

        // Never let working out where to send something stop it being sent.
        // A missing table mid-deploy, a driver that has gone away — the bell
        // and the email still go.
        try {
            $event = self::eventKey($notification);

            foreach (self::connections($notifiable) as $connection) {
                $driver = self::DRIVERS[$connection->type] ?? null;

                if ($driver !== null && $connection->wants($event) && ! in_array($driver, $channels, true)) {
                    $channels[] = $driver;
                }
            }
        } catch (Throwable) {
            return self::ALWAYS;
        }

        return $channels;
    }

    /**
     * What kind of thing happened, as a stable string.
     *
     * Taken from the notification's own class name, so a new notification is
     * mutable on the settings page the day it is written, with nothing to
     * register.
     */
    public static function eventKey(Notification $notification): string
    {
        return Str::snake(Str::replaceLast('Notification', '', class_basename($notification)));
    }

    /**
     * Every event a person can choose not to hear about, and what to call it.
     *
     * @return array<string, string>
     */
    public static function events(): array
    {
        return [
            'task_assigned' => 'A task is given to me',
            'task_status_changed' => 'A task of mine moves',
            'task_commented' => 'Someone comments on a task I follow',
            'mentioned_in_comment' => 'Someone mentions me',
            'added_to_project' => 'I am added to a project',
        ];
    }

    /**
     * The chat version of a notification: whatever it wrote for itself, or the
     * payload it already stores for the bell.
     */
    public static function chatMessage(Notification $notification, object $notifiable): ChatMessage
    {
        if (method_exists($notification, 'toChat')) {
            return $notification->toChat($notifiable);
        }

        return ChatMessage::fromPayload(
            method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : []
        );
    }

    /**
     * @return iterable<NotificationChannel>
     */
    private static function connections(object $notifiable): iterable
    {
        if (! method_exists($notifiable, 'notificationChannels')) {
            return [];
        }

        return $notifiable->notificationChannels()->live()->get();
    }
}
