<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The handful of Telegram Bot API calls this app makes.
 *
 * Worth knowing about Telegram: a bot cannot write to somebody who has never
 * written to it. That is why connecting is a link the person presses rather
 * than a username they type — pressing Start is the permission.
 */
class Telegram
{
    public static function configured(): bool
    {
        return trim((string) config('services.telegram.token')) !== '';
    }

    public static function botUsername(): ?string
    {
        $username = trim((string) config('services.telegram.bot'));

        return $username === '' ? null : ltrim($username, '@');
    }

    /**
     * The link that connects a person: it opens the bot with their one-time
     * code already in the Start button.
     */
    public static function connectLink(string $token): ?string
    {
        $bot = self::botUsername();

        return $bot === null ? null : 'https://t.me/'.$bot.'?start='.$token;
    }

    /**
     * @throws RuntimeException when Telegram refuses the message
     */
    public static function sendMessage(string $chatId, string $html): void
    {
        $response = self::call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            // A task link would otherwise drag a preview card of our own login
            // page underneath every message.
            'disable_web_page_preview' => true,
        ]);

        if (($response['ok'] ?? false) !== true) {
            throw new RuntimeException($response['description'] ?? 'Telegram refused the message.');
        }
    }

    /**
     * Point Telegram at our webhook. Idempotent — calling it again with the
     * same values changes nothing.
     */
    public static function setWebhook(string $url, string $secret): array
    {
        return self::call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            // Anything that queued up while the webhook was unset is stale by
            // definition; a connect code has a half-hour life.
            'drop_pending_updates' => true,
        ]);
    }

    public static function webhookInfo(): array
    {
        return self::call('getWebhookInfo');
    }

    public static function me(): array
    {
        return self::call('getMe');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function call(string $method, array $payload = []): array
    {
        $token = trim((string) config('services.telegram.token'));

        if ($token === '') {
            throw new RuntimeException('Telegram is not configured. Set TELEGRAM_BOT_TOKEN.');
        }

        return Http::timeout(10)
            ->retry(2, 300, throw: false)
            ->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload)
            ->json() ?? [];
    }
}
