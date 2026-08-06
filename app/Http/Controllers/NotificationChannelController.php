<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use App\Support\Notifications\ChatMessage;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Slack;
use App\Support\Notifications\Telegram;
use App\Support\Notifications\WebPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Where a person says they want to be told about their work.
 *
 * Nobody manages anybody else's channels here — not even an administrator.
 * Somebody's Telegram account is theirs, and a notification arriving in a chat
 * they did not choose is a surprise nobody wants.
 */
class NotificationChannelController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $channels = $user->notificationChannels()->latest()->get();

        return view('profile.notifications', [
            'user' => $user,
            'channels' => $channels,
            // What is already set up, so the page stops offering it. Telegram
            // and Slack are one per person; a browser is one per browser, so
            // that one is answered on the client — this list is what it
            // compares its own subscription against.
            'connectedTypes' => $channels->filter->isLive()->pluck('type')->unique()->all(),
            'subscribedEndpoints' => $channels
                ->where('type', NotificationChannel::WEBPUSH)
                ->filter->isLive()
                ->pluck('target')
                ->values()
                ->all(),
            'events' => Delivery::events(),
            'telegramReady' => Telegram::configured() && Telegram::botUsername() !== null,
            'webPushReady' => WebPush::configured(),
            'slackReady' => Slack::configured(),
            'vapidPublicKey' => WebPush::publicKey(),
        ]);
    }

    /**
     * Start connecting Telegram: hand out a one-time code and send the person
     * to the bot with it.
     *
     * The link is the whole flow. A bot cannot write to somebody who has never
     * written to it, so pressing Start *is* the permission — and it carries
     * the code, which means nothing to type and nothing to get wrong.
     */
    public function connectTelegram()
    {
        abort_unless(Telegram::configured() && Telegram::botUsername() !== null, 404);

        $channel = Auth::user()->notificationChannels()->create([
            'type' => NotificationChannel::TELEGRAM,
            'enabled' => true,
        ]);

        return redirect()->away(Telegram::connectLink($channel->startConnecting()));
    }

    /**
     * Connect Slack by finding the person in the workspace.
     *
     * One bot, installed once by an administrator, rather than an OAuth dance
     * per person: everybody here is in the same Slack. What that costs is the
     * lookup — somebody whose Slack account uses a different address than the
     * one they have here will not be found, which is why this will take an
     * address to look for.
     */
    public function connectSlack(Request $request)
    {
        abort_unless(Slack::configured(), 404);

        $user = Auth::user();
        // validate() returns only the keys that were actually sent, so the
        // ordinary case — pressing Connect with no address — has no key at all.
        $validated = $request->validate(['email' => 'nullable|email']);
        $email = trim((string) ($validated['email'] ?? '')) ?: $user->email;

        try {
            $found = Slack::lookupByEmail($email);

            if ($found === null) {
                return back()->withErrors(['slack' => 'Slack has no account for '.$email
                    .'. If your Slack uses a different address, type it below and try again.']);
            }

            $conversation = Slack::openConversation($found['id']);
        } catch (Throwable $e) {
            return back()->withErrors(['slack' => $e->getMessage()]);
        }

        $channel = $user->notificationChannels()->firstOrNew([
            'type' => NotificationChannel::SLACK,
            'target' => $conversation,
        ]);
        $channel->type = NotificationChannel::SLACK;
        $channel->enabled = true;
        $channel->meta = ['slack_user_id' => $found['id'], 'email' => $email];
        $channel->save();
        $channel->complete($conversation, $found['name']);

        // The proof, not the claim: if this arrives, it works.
        try {
            Slack::postMessage($conversation, (new ChatMessage(
                title: 'Connected',
                lines: ['You will get your '.config('app.name').' notifications here.'],
                url: route('profile.notifications'),
            ))->toSlackMarkdown());
            $channel->recordDelivery();
        } catch (Throwable $e) {
            $channel->recordFailure($e->getMessage());
        }

        return back()->with('success', 'Slack connected for '.$found['name'].'.');
    }

    /**
     * Remember a browser that has agreed to show notifications.
     *
     * Sent by the page after the browser has said yes. One subscription per
     * browser per machine, keyed by its endpoint — allowing it twice from the
     * same browser must update that subscription, not collect another.
     */
    public function subscribeBrowser(Request $request)
    {
        abort_unless(WebPush::configured(), 404);

        $data = $request->validate([
            'endpoint' => 'required|string|max:1000|url',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'encoding' => 'nullable|string|max:32',
            'label' => 'nullable|string|max:120',
        ]);

        $channel = Auth::user()->notificationChannels()->firstOrNew([
            'type' => NotificationChannel::WEBPUSH,
            'target' => $data['endpoint'],
        ]);

        $channel->type = NotificationChannel::WEBPUSH;
        $channel->enabled = true;
        $channel->meta = [
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'encoding' => $data['encoding'] ?? 'aesgcm',
        ];
        $channel->save();

        // The browser said yes; there is no second step to wait for.
        $channel->complete($data['endpoint'], $data['label'] ?? 'This browser');

        return response()->json(['ok' => true]);
    }

    /**
     * Switch a channel on or off, or stop it hearing about some things.
     */
    public function update(Request $request, NotificationChannel $channel)
    {
        $this->authorizeOwn($channel);

        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'muted_events' => 'sometimes|array',
            'muted_events.*' => 'string|in:'.implode(',', array_keys(Delivery::events())),
        ]);

        $channel->update([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'muted_events' => array_values($data['muted_events'] ?? []),
        ]);

        return back()->with('success', 'Notification settings saved.');
    }

    public function destroy(NotificationChannel $channel)
    {
        $this->authorizeOwn($channel);

        $label = $channel->typeLabel();
        $channel->delete();

        return back()->with('success', $label.' disconnected.');
    }

    /**
     * Send a message to this channel now.
     *
     * The only honest way to answer "is this working?" — a settings page that
     * says "connected" is a claim, and a message that arrives is a fact.
     */
    public function test(NotificationChannel $channel)
    {
        $this->authorizeOwn($channel);

        if (! $channel->isLive()) {
            return back()->withErrors(['channel' => 'That channel is not connected yet.']);
        }

        $message = new ChatMessage(
            title: 'Test message',
            lines: ['Notifications from '.config('app.name').' will arrive here.'],
            url: route('tasks.index'),
        );

        try {
            match ($channel->type) {
                NotificationChannel::TELEGRAM => Telegram::sendMessage($channel->target, $message->toTelegramHtml()),
                NotificationChannel::WEBPUSH => WebPush::send($channel, $message),
                NotificationChannel::SLACK => Slack::postMessage($channel->target, $message->toSlackMarkdown()),
                default => throw new \RuntimeException('Nothing knows how to send to that channel yet.'),
            };
        } catch (Throwable $e) {
            $channel->recordFailure($e->getMessage());

            return back()->withErrors(['channel' => 'It did not arrive: '.$e->getMessage()]);
        }

        $channel->recordDelivery();

        return back()->with('success', 'Sent. It should be in your '.$channel->typeLabel().' now.');
    }

    private function authorizeOwn(NotificationChannel $channel): void
    {
        abort_unless($channel->user_id === Auth::id(), 403);
    }
}
