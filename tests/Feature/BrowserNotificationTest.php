<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\TaskAssignedNotification;
use App\Support\Notifications\SubscriptionGone;
use App\Support\Notifications\WebPush;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A push subscription is not a person, it is one browser on one machine.
 * Somebody with a laptop and a phone allows it twice and has two — that is the
 * web's model, and everything here follows from it.
 */
class BrowserNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $colleague;

    private Project $project;

    /** A real VAPID pair, so nothing here is testing against a fake shape. */
    private static array $keys;

    protected function tearDown(): void
    {
        WebPush::sendNormally();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        self::$keys ??= WebPush::generateKeys();

        $this->user = User::create(['name' => 'Wilma Web', 'email' => 'wilma@example.com', 'password' => bcrypt('secret')]);
        $this->colleague = User::create(['name' => 'Cal Colleague', 'email' => 'cal@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->colleague->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach($this->user->id);

        config([
            'services.webpush.public_key' => self::$keys['publicKey'],
            'services.webpush.private_key' => self::$keys['privateKey'],
            'services.webpush.subject' => 'mailto:ops@example.com',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                // A real P-256 public point and a 16-byte auth secret: the
                // library rejects anything else out of hand.
                'p256dh' => 'BA1Hxzyi1RUM1b5wjxsn7nGxAszw2u61m164i3MrAIxHF6YK5h4SDYic-dRuU_RCPCfA5aq9ojSwk5Y2EmClBPs',
                'auth' => 'zqbxT6JKstKSY9JKMbhJ3g',
            ],
            'encoding' => 'aes128gcm',
            'label' => 'Chrome',
        ];
    }

    private function subscribed(): NotificationChannel
    {
        $this->actingAs($this->user)
            ->postJson(route('profile.notifications.browser'), $this->subscription())
            ->assertSuccessful();

        return $this->user->notificationChannels()->first();
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

    // --- subscribing ----------------------------------------------------------

    public function test_a_browser_that_says_yes_is_remembered(): void
    {
        $channel = $this->subscribed();

        $this->assertSame(NotificationChannel::WEBPUSH, $channel->type);
        $this->assertStringStartsWith('https://fcm.googleapis.com/', $channel->target);
        // The keys are the message's envelope: without them the browser will
        // not accept anything we send.
        $this->assertArrayHasKey('p256dh', $channel->meta);
        $this->assertArrayHasKey('auth', $channel->meta);
        // The browser already agreed; there is no second step to wait for.
        $this->assertNotNull($channel->verified_at);
    }

    public function test_allowing_it_twice_in_one_browser_is_still_one_subscription(): void
    {
        $this->subscribed();
        $this->subscribed();

        $this->assertSame(1, $this->user->notificationChannels()->count());
    }

    public function test_a_second_machine_is_a_second_subscription(): void
    {
        $this->subscribed();

        $this->actingAs($this->user)
            ->postJson(route('profile.notifications.browser'), $this->subscription('https://updates.push.services.mozilla.com/wpush/v2/xyz'))
            ->assertSuccessful();

        // A laptop and a phone are two answers, and one being wiped must not
        // silence the other.
        $this->assertSame(2, $this->user->notificationChannels()->count());
    }

    public function test_a_subscription_without_its_keys_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('profile.notifications.browser'), ['endpoint' => 'https://example.com/push'])
            ->assertStatus(422);
    }

    public function test_nobody_can_subscribe_while_signed_out(): void
    {
        $this->postJson(route('profile.notifications.browser'), $this->subscription())
            ->assertUnauthorized();
    }

    // --- delivery --------------------------------------------------------------

    public function test_a_subscribed_browser_is_one_of_the_channels(): void
    {
        $this->subscribed();

        $via = (new TaskAssignedNotification($this->assignTask(), $this->colleague))->via($this->user->fresh());

        $this->assertContains(WebPushChannel::class, $via);
        // Still a second copy, never a replacement.
        $this->assertContains('mail', $via);
    }

    public function test_it_is_off_entirely_without_a_key_pair(): void
    {
        $this->subscribed();
        config(['services.webpush.private_key' => null]);

        // Half-configured must mean off, not a crash on every notification.
        $this->assertFalse(WebPush::configured());

        $via = (new TaskAssignedNotification($this->assignTask(), $this->colleague))->via($this->user->fresh());

        $this->assertContains('mail', $via);
    }

    public function test_the_message_carries_the_task_and_a_way_back_to_it(): void
    {
        $this->subscribed();

        $sent = [];
        WebPush::sendUsing(function ($channel, $message) use (&$sent) {
            $sent[] = $message;
        });

        $task = $this->assignTask();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString($task->title, $sent[0]->title);
        // A notification you cannot act on is a notification that wasted an
        // interruption.
        $this->assertStringContainsString('/tasks/'.$task->id, (string) $sent[0]->url);
    }

    public function test_a_dead_subscription_is_forgotten_rather_than_retried_forever(): void
    {
        $this->subscribed();

        // 404 and 410 from a push service mean this browser threw the
        // subscription away — cleared its data, revoked permission. Nothing is
        // listening and nothing ever will be again, so retrying it is sending
        // to nobody forever.
        WebPush::sendUsing(fn () => throw new SubscriptionGone('410 Gone'));

        $this->assignTask();

        $this->assertSame(0, $this->user->notificationChannels()->count());
    }

    public function test_an_ordinary_failure_keeps_the_subscription_and_says_why(): void
    {
        $channel = $this->subscribed();

        WebPush::sendUsing(fn () => throw new \RuntimeException('push service unavailable'));

        $this->assignTask();

        // Still there — a push service having a bad afternoon is not a reason
        // to make somebody allow notifications again.
        $this->assertNotNull($channel->fresh());
        $this->assertStringContainsString('unavailable', (string) $channel->fresh()->last_error);
    }

    public function test_one_dead_browser_does_not_stop_another(): void
    {
        $laptop = $this->subscribed();

        $this->actingAs($this->user)
            ->postJson(route('profile.notifications.browser'), $this->subscription('https://updates.push.services.mozilla.com/wpush/v2/xyz'))
            ->assertSuccessful();

        WebPush::sendUsing(function ($channel) {
            if (str_contains((string) $channel->target, 'mozilla')) {
                throw new SubscriptionGone('410 Gone');
            }
        });

        $this->assignTask();

        $this->assertNotNull($laptop->fresh()->last_sent_at);
        $this->assertSame(1, $this->user->notificationChannels()->count());
    }

    // --- the settings page ------------------------------------------------------

    public function test_the_page_offers_it_and_says_where_it_will_not_work(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('Enable in this browser')
            // Apple's rule, and a person on an iPhone deserves to know before
            // pressing a button that will not work.
            ->assertSee('added to the');
    }

    public function test_the_page_explains_a_browser_that_is_blocking_it(): void
    {
        // Finding out by pressing a button that can only fail teaches nothing
        // about how to fix it, so the page says it up front and says where to
        // look — including the operating system, which is the other half of
        // why a browser goes quiet.
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('will not even ask', false)
            ->assertSee('padlock next to the address')
            ->assertSee('Do Not Disturb');
    }

    public function test_the_button_is_absent_until_the_keys_are(): void
    {
        config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertDontSee('Enable in this browser');

        $this->postJson(route('profile.notifications.browser'), $this->subscription());
    }

    public function test_the_browser_button_stays_offered_even_when_another_machine_is_subscribed(): void
    {
        $this->subscribed();

        // A subscription belongs to one browser on one machine. Hiding the
        // button because *some* browser is subscribed would strand somebody on
        // their second laptop — which browser this is is a question only the
        // browser can answer, so the page hands it the endpoints and lets it.
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('Enable in this browser')
            ->assertSee('fcm.googleapis.com', false);
    }

    public function test_the_service_worker_is_served_from_the_root(): void
    {
        // A service worker can only act for pages under its own path, so one
        // served from /js/ could not cover the site.
        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString('showNotification', file_get_contents(public_path('sw.js')));
    }

    public function test_every_notification_is_its_own_notification(): void
    {
        // The comments in that file discuss both of these at length, so read
        // the code without them.
        $code = preg_replace('#^\s*//.*$#m', '', file_get_contents(public_path('sw.js')));

        // Notifications were grouped by task, which is tidier and cost two
        // rounds of "nothing arrived": one replacing an earlier one with the
        // same tag is silent by default, and the operating system may fold it
        // away even when told not to. A tidy list is worth nothing next to a
        // notification that shows up.
        $this->assertStringNotContainsString('tag:', $code);
        // And no tag means no renotify: Chrome throws on renotify without one,
        // and a throw in the push handler means no notification at all.
        $this->assertStringNotContainsString('renotify', $code);
    }

    public function test_a_test_message_is_different_every_time_it_is_sent(): void
    {
        $channel = $this->subscribed();

        $seen = [];
        WebPush::sendUsing(function ($channel, $message) use (&$seen) {
            $seen[] = $message->toText();
        });

        $this->actingAs($this->user)->post(route('profile.notifications.test', $channel));
        $this->travel(1)->minutes();
        $this->actingAs($this->user)->post(route('profile.notifications.test', $channel));

        // Two identical messages are indistinguishable from one that never
        // arrived — which is the exact confusion this button exists to settle.
        $this->assertCount(2, $seen);
        $this->assertNotSame($seen[0], $seen[1]);
    }

    public function test_the_service_worker_takes_over_as_soon_as_it_changes(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        // Otherwise a fix in here waits for every tab on the site to close,
        // which for a tool people leave open all day is never.
        $this->assertStringContainsString('skipWaiting', $worker);
        $this->assertStringContainsString('clients.claim', $worker);
    }
}
