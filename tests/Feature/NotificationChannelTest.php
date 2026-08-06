<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\TaskAssignedNotification;
use App\Support\Notifications\Delivery;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Email is where notifications go to be missed. Nobody sits in their inbox all
 * day; they sit in Telegram. So each person says where they want to be told,
 * and the same news goes everywhere they said.
 *
 * The rule underneath: connected channels are a second copy, never a
 * replacement. Unhooking one must not be a way to stop hearing about your own
 * work by accident.
 */
class NotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $colleague;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->user = User::create(['name' => 'Nadia Notify', 'email' => 'nadia@example.com', 'password' => bcrypt('secret')]);
        $this->colleague = User::create(['name' => 'Cal Colleague', 'email' => 'cal@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->colleague->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach($this->user->id);

        config([
            'services.telegram.token' => '123:test-token',
            'services.telegram.bot' => 'test_bot',
            'services.telegram.webhook_secret' => 'shhh',
        ]);
    }

    private function connected(array $overrides = []): NotificationChannel
    {
        $channel = $this->user->notificationChannels()->create(array_merge([
            'type' => NotificationChannel::TELEGRAM,
            'enabled' => true,
        ], $overrides));

        if (! array_key_exists('verified_at', $overrides)) {
            $channel->complete('55501', '@nadia');
        }

        return $channel->fresh();
    }

    private function assignTask(): Task
    {
        $this->actingAs($this->colleague);

        return Task::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    // --- where a notification goes -------------------------------------------

    public function test_the_bell_and_email_go_out_whatever_is_connected(): void
    {
        $notification = new TaskAssignedNotification($this->assignTask(), $this->colleague);

        $this->assertSame(['database', 'mail'], $notification->via($this->user));
    }

    public function test_a_connected_channel_gets_a_copy(): void
    {
        $this->connected();

        $notification = new TaskAssignedNotification($this->assignTask(), $this->colleague);
        $via = $notification->via($this->user->fresh());

        // A second copy, not a replacement — email stays.
        $this->assertContains('mail', $via);
        $this->assertContains(TelegramChannel::class, $via);
    }

    public function test_a_paused_channel_gets_nothing(): void
    {
        $this->connected(['enabled' => false]);

        $notification = new TaskAssignedNotification($this->assignTask(), $this->colleague);

        $this->assertNotContains(TelegramChannel::class, $notification->via($this->user->fresh()));
    }

    public function test_a_half_finished_connection_gets_nothing(): void
    {
        // The row exists from the moment Connect is pressed; it means nothing
        // until the person presses Start.
        $this->connected(['verified_at' => null]);

        $notification = new TaskAssignedNotification($this->assignTask(), $this->colleague);

        $this->assertNotContains(TelegramChannel::class, $notification->via($this->user->fresh()));
    }

    public function test_muting_one_kind_of_event_leaves_the_others(): void
    {
        $this->connected(['muted_events' => ['task_assigned']]);
        $user = $this->user->fresh();

        $assigned = new TaskAssignedNotification($this->assignTask(), $this->colleague);
        $this->assertNotContains(TelegramChannel::class, $assigned->via($user));

        // Something else entirely still arrives.
        $channel = $user->notificationChannels()->first();
        $this->assertTrue($channel->wants('task_commented'));
    }

    public function test_the_event_key_comes_from_the_notification_itself(): void
    {
        // So a notification written next month is mutable on the settings page
        // the day it is written, with nothing to register.
        $this->assertSame(
            'task_assigned',
            Delivery::eventKey(new TaskAssignedNotification($this->assignTask(), $this->colleague))
        );
    }

    // --- actually sending -----------------------------------------------------

    public function test_the_message_reaches_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->connected();

        $task = $this->assignTask();

        Http::assertSent(function ($request) use ($task) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '55501'
                && str_contains($request['text'], $task->title);
        });
    }

    public function test_a_chat_that_blocked_the_bot_is_told_about_it_rather_than_left_silent(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bot was blocked by the user'])]);
        $channel = $this->connected();

        $this->assignTask();

        // A person whose notifications stopped needs to know why, on the page
        // where they would go looking.
        $this->assertStringContainsString('blocked', (string) $channel->fresh()->last_error);
    }

    public function test_one_broken_chat_does_not_stop_another(): void
    {
        $good = $this->connected();
        $bad = $this->user->notificationChannels()->create(['type' => NotificationChannel::TELEGRAM, 'enabled' => true]);
        $bad->complete('99999', '@other');

        Http::fake(function ($request) {
            return $request['chat_id'] === '99999'
                ? Http::response(['ok' => false, 'description' => 'chat not found'])
                : Http::response(['ok' => true]);
        });

        $this->assignTask();

        $this->assertNotNull($good->fresh()->last_sent_at);
        $this->assertStringContainsString('chat not found', (string) $bad->fresh()->last_error);
    }

    public function test_a_broken_channel_never_breaks_the_request(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('', 500)]);
        $this->connected();

        // The person doing the assigning must not see an error because
        // somebody else's chat is broken.
        $this->actingAs($this->colleague)->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
        ])->assertRedirect();
    }

    // --- connecting ------------------------------------------------------------

    public function test_connecting_hands_out_a_link_with_a_one_time_code(): void
    {
        $response = $this->actingAs($this->user)->post(route('profile.notifications.telegram'));

        $channel = $this->user->notificationChannels()->first();

        $this->assertNotNull($channel->connect_token);
        $this->assertNull($channel->verified_at);
        $response->assertRedirect('https://t.me/test_bot?start='.$channel->connect_token);
    }

    public function test_pressing_start_finishes_the_connection(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->actingAs($this->user)->post(route('profile.notifications.telegram'));
        $channel = $this->user->notificationChannels()->first();

        $this->webhook(['message' => [
            'chat' => ['id' => 42424, 'username' => 'nadia', 'first_name' => 'Nadia'],
            'text' => '/start '.$channel->connect_token,
        ]])->assertSuccessful();

        $channel = $channel->fresh();

        $this->assertSame('42424', $channel->target);
        $this->assertSame('@nadia', $channel->label);
        $this->assertNotNull($channel->verified_at);
        // Spent, so the same link cannot be replayed by anybody who sees it.
        $this->assertNull($channel->connect_token);
    }

    public function test_an_expired_code_connects_nobody(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $channel = $this->user->notificationChannels()->create(['type' => NotificationChannel::TELEGRAM]);
        $token = $channel->startConnecting();
        // forceFill, because the expiry is deliberately not fillable: only
        // startConnecting() gets to decide how long a code lives.
        $channel->forceFill(['connect_expires_at' => now()->subMinute()])->save();

        $this->webhook(['message' => ['chat' => ['id' => 42424], 'text' => '/start '.$token]])->assertSuccessful();

        $this->assertNull($channel->fresh()->verified_at);
    }

    public function test_the_webhook_ignores_anyone_without_the_secret(): void
    {
        $channel = $this->user->notificationChannels()->create(['type' => NotificationChannel::TELEGRAM]);
        $token = $channel->startConnecting();

        // The endpoint is public because Telegram will not sign in; the secret
        // is the whole of its security.
        $this->postJson(route('telegram.webhook'), [
            'message' => ['chat' => ['id' => 1], 'text' => '/start '.$token],
        ])->assertNotFound();

        $this->assertNull($channel->fresh()->verified_at);
    }

    // --- the settings page ------------------------------------------------------

    public function test_the_page_offers_telegram_until_it_is_connected(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('Connect Telegram');
    }

    public function test_the_page_lists_a_connected_channel_and_what_it_can_be_muted_from(): void
    {
        $this->connected();

        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('@nadia')
            ->assertSee('A task is given to me');
    }

    public function test_a_connected_channel_stops_being_offered(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('Connect Telegram');

        $this->connected();

        // Offering to connect something that is already connected is how a
        // settings page teaches people to distrust it.
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertDontSee('Connect Telegram');
    }

    public function test_a_half_finished_connection_does_not_count_as_connected(): void
    {
        $this->connected(['verified_at' => null]);

        // Somebody who opened the bot and never pressed Start needs the button
        // to still be there.
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('Connect Telegram');
    }

    public function test_nobody_touches_anybody_elses_channels(): void
    {
        $mine = $this->connected();

        // Somebody's Telegram is theirs; a notification arriving in a chat you
        // did not choose is a surprise nobody wants.
        $this->actingAs($this->colleague)
            ->delete(route('profile.notifications.destroy', $mine))
            ->assertForbidden();

        $this->assertNotNull($mine->fresh());
    }

    public function test_a_test_message_says_whether_it_arrived(): void
    {
        // One stub reading a flag, because Http::fake() merges what it is
        // given with what is already there and the earliest match wins — a
        // second registration would simply be ignored.
        // An object, because an arrow function captures by value and the whole
        // point is that the answer changes between the two requests.
        $telegram = new \stdClass;
        $telegram->works = false;
        Http::fake(fn () => $telegram->works
            ? Http::response(['ok' => true])
            : Http::response(['ok' => false, 'description' => 'chat not found']));

        $channel = $this->connected();

        $this->actingAs($this->user)
            ->post(route('profile.notifications.test', $channel))
            ->assertSessionHasErrors('channel');

        $telegram->works = true;

        $this->actingAs($this->user)
            ->post(route('profile.notifications.test', $channel))
            ->assertSessionHas('success');
    }

    public function test_notifications_are_queued(): void
    {
        Notification::fake();

        $this->assignTask();

        Notification::assertSentTo($this->user, TaskAssignedNotification::class, function ($notification) {
            // Three channels' worth of HTTP inside the request would make
            // posting a comment take seconds.
            return $notification instanceof ShouldQueue;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shhh')
            ->postJson(route('telegram.webhook'), $payload);
    }
}
