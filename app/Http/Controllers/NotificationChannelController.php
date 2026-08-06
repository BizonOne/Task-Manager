<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use App\Support\Notifications\ChatMessage;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Telegram;
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

        return view('profile.notifications', [
            'user' => $user,
            'channels' => $user->notificationChannels()->latest()->get(),
            'events' => Delivery::events(),
            'telegramReady' => Telegram::configured() && Telegram::botUsername() !== null,
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
