<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use App\Support\Notifications\ChatMessage;
use App\Support\Notifications\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What Telegram sends us.
 *
 * The only update worth anything here is `/start <code>`: it is how a person
 * finishes connecting, and it is the moment we learn their chat id — the one
 * thing a bot cannot look up for itself.
 *
 * This endpoint is public, because Telegram will not sign in. What makes it
 * safe is the secret token: Telegram sends it back on every update, and
 * anything without it is somebody else knocking.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $expected = trim((string) config('services.telegram.webhook_secret'));

        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(404);
        }

        try {
            $this->handle($request->input('message') ?? []);
        } catch (Throwable $e) {
            // Never answer Telegram with an error: it retries, and a retry
            // loop on a bug is worse than a lost update.
            Log::warning('Telegram webhook failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handle(array $message): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === '' || ! str_starts_with($text, '/start')) {
            return;
        }

        $token = trim(mb_substr($text, strlen('/start')));

        if ($token === '') {
            $this->say($chatId, new ChatMessage(
                title: 'Almost there',
                lines: ['Open '.config('app.name').' → Profile → Notifications and press Connect. The link there carries the code that ties this chat to your account.'],
            ));

            return;
        }

        $channel = NotificationChannel::awaiting($token);

        if ($channel === null) {
            // Deliberately vague: an expired code and a made-up one should
            // look the same to whoever is trying them.
            $this->say($chatId, new ChatMessage(
                title: 'That link has expired',
                lines: ['Press Connect again in your profile to get a fresh one.'],
            ));

            return;
        }

        $name = trim(($message['chat']['first_name'] ?? '').' '.($message['chat']['last_name'] ?? ''));
        $username = $message['chat']['username'] ?? null;

        $channel->complete($chatId, $username ? '@'.$username : ($name ?: null));

        $this->say($chatId, new ChatMessage(
            title: 'Connected',
            lines: ['You will get your '.config('app.name').' notifications here. Turn them off any time in Profile → Notifications.'],
        ));
    }

    private function say(string $chatId, ChatMessage $message): void
    {
        try {
            Telegram::sendMessage($chatId, $message->toTelegramHtml());
        } catch (Throwable $e) {
            Log::warning('Telegram reply failed', ['error' => $e->getMessage()]);
        }
    }
}
