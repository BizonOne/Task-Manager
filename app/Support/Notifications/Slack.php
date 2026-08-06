<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The handful of Slack Web API calls this app makes.
 *
 * One bot, installed once in the workspace by an administrator — not an OAuth
 * dance per person. Everybody here is in the same Slack, so asking each of
 * them to authorise an app individually would be ceremony for its own sake.
 *
 * What that costs is the lookup: the bot has to find somebody by email, and a
 * Slack account registered under a different address than the one they use
 * here simply will not be found. That is why connecting can also be told the
 * address to look for.
 */
class Slack
{
    public static function configured(): bool
    {
        return self::token() !== null;
    }

    /**
     * Find a person by email address.
     *
     * @return array{id: string, name: string}|null null when Slack has never
     *                                              heard of that address
     */
    public static function lookupByEmail(string $email): ?array
    {
        $response = self::call('users.lookupByEmail', ['email' => $email], post: false);

        if (($response['ok'] ?? false) !== true) {
            if (($response['error'] ?? '') === 'users_not_found') {
                return null;
            }

            throw new RuntimeException(self::explain($response['error'] ?? 'unknown error'));
        }

        $user = $response['user'] ?? [];

        return [
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['real_name'] ?? $user['name'] ?? $email),
        ];
    }

    /**
     * Open the direct message channel with somebody, and say which it is.
     *
     * Opening it is not a message and does not disturb anybody; it is how a
     * bot gets a channel id to write to.
     */
    public static function openConversation(string $userId): string
    {
        $response = self::call('conversations.open', ['users' => $userId, 'return_im' => true]);

        if (($response['ok'] ?? false) !== true) {
            throw new RuntimeException(self::explain($response['error'] ?? 'unknown error'));
        }

        return (string) ($response['channel']['id'] ?? '');
    }

    /**
     * @throws RuntimeException when Slack refuses the message
     */
    public static function postMessage(string $channel, string $markdown): void
    {
        $response = self::call('chat.postMessage', [
            'channel' => $channel,
            'text' => $markdown,
            // Slack renders a link preview of our own login page otherwise.
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]);

        if (($response['ok'] ?? false) !== true) {
            throw new RuntimeException(self::explain($response['error'] ?? 'unknown error'));
        }
    }

    /**
     * Slack's error codes are for machines. This is for the person reading
     * their settings page.
     */
    private static function explain(string $error): string
    {
        return match ($error) {
            'users_not_found' => 'Slack has no account with that email address.',
            'invalid_auth', 'not_authed', 'token_revoked' => 'The Slack app is no longer authorised for this workspace.',
            'missing_scope' => 'The Slack app is missing a permission it needs to send direct messages.',
            'channel_not_found' => 'That Slack conversation no longer exists.',
            'is_bot' => 'That Slack account is a bot, not a person.',
            'account_inactive' => 'That Slack account has been deactivated.',
            default => 'Slack said: '.$error,
        };
    }

    private static function token(): ?string
    {
        $token = trim((string) config('services.slack.notifications.bot_user_oauth_token'));

        return $token === '' ? null : $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function call(string $method, array $payload = [], bool $post = true): array
    {
        $token = self::token();

        if ($token === null) {
            throw new RuntimeException('Slack is not configured. Set SLACK_BOT_USER_OAUTH_TOKEN.');
        }

        $request = Http::withToken($token)->timeout(10)->retry(2, 300, throw: false);
        $url = 'https://slack.com/api/'.$method;

        $response = $post
            ? $request->asJson()->post($url, $payload)
            : $request->get($url, $payload);

        return $response->json() ?? [];
    }
}
