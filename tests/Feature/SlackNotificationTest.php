<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\TaskAssignedNotification;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One bot, installed once in the workspace, rather than an OAuth dance per
 * person: everybody here is in the same Slack.
 *
 * What that costs is the lookup. A Slack account registered under a different
 * address than the one somebody uses here simply will not be found, and on the
 * real workspace that is already true of one person — so "not found" has to be
 * a fork in the road, not a dead end.
 */
class SlackNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $colleague;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->user = User::create(['name' => 'Sasha Slack', 'email' => 'sasha@example.com', 'password' => bcrypt('secret')]);
        $this->colleague = User::create(['name' => 'Cal Colleague', 'email' => 'cal@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->colleague->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach($this->user->id);

        config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);
    }

    /**
     * Stand in for Slack. One stub reading mutable state, because Http::fake()
     * merges registrations and the earliest match wins.
     */
    private function fakeSlack(bool $found = true, ?string $postError = null): object
    {
        $state = new \stdClass;
        $state->found = $found;
        $state->postError = $postError;
        $state->posted = [];

        Http::fake(function ($request) use ($state) {
            $url = $request->url();

            if (str_contains($url, 'users.lookupByEmail')) {
                return $state->found
                    ? Http::response(['ok' => true, 'user' => ['id' => 'U123', 'real_name' => 'Sasha Slack']])
                    : Http::response(['ok' => false, 'error' => 'users_not_found']);
            }

            if (str_contains($url, 'conversations.open')) {
                return Http::response(['ok' => true, 'channel' => ['id' => 'D999']]);
            }

            if (str_contains($url, 'chat.postMessage')) {
                $state->posted[] = $request->data();

                return $state->postError === null
                    ? Http::response(['ok' => true])
                    : Http::response(['ok' => false, 'error' => $state->postError]);
            }

            return Http::response(['ok' => false, 'error' => 'unexpected '.$url]);
        });

        return $state;
    }

    private function connected(): NotificationChannel
    {
        $this->actingAs($this->user)
            ->post(route('profile.notifications.slack'))
            ->assertRedirect();

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

    // --- connecting ------------------------------------------------------------

    public function test_connecting_finds_the_person_and_opens_their_dm(): void
    {
        $slack = $this->fakeSlack();

        $channel = $this->connected();

        $this->assertSame(NotificationChannel::SLACK, $channel->type);
        $this->assertSame('D999', $channel->target);
        $this->assertSame('Sasha Slack', $channel->label);
        $this->assertNotNull($channel->verified_at);

        // The proof, not the claim: a confirmation lands in the DM straight
        // away, so "connected" is something they saw rather than read.
        $this->assertCount(1, $slack->posted);
        $this->assertStringContainsString('Connected', $slack->posted[0]['text']);
    }

    public function test_somebody_slack_has_never_heard_of_is_offered_a_way_forward(): void
    {
        $this->fakeSlack(found: false);

        $this->actingAs($this->user)
            ->post(route('profile.notifications.slack'))
            ->assertSessionHasErrors('slack');

        $this->assertSame(0, $this->user->notificationChannels()->count());
    }

    public function test_a_different_address_can_be_given(): void
    {
        $this->fakeSlack();

        // Plenty of people are in Slack under a personal address; on the real
        // workspace one of them already is.
        $this->actingAs($this->user)
            ->post(route('profile.notifications.slack'), ['email' => 'personal@gmail.com'])
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'personal%40gmail.com')
            || str_contains($request->url(), 'personal@gmail.com'));

        $this->assertSame('personal@gmail.com', $this->user->notificationChannels()->first()->meta['email']);
    }

    public function test_connecting_twice_is_still_one_conversation(): void
    {
        $this->fakeSlack();

        $this->connected();
        $this->connected();

        $this->assertSame(1, $this->user->notificationChannels()->count());
    }

    public function test_nobody_connects_slack_while_signed_out(): void
    {
        $this->fakeSlack();

        $this->post(route('profile.notifications.slack'))->assertRedirect(route('login'));
    }

    // --- delivery ---------------------------------------------------------------

    public function test_the_message_reaches_the_dm(): void
    {
        $slack = $this->fakeSlack();
        $this->connected();

        $task = $this->assignTask();

        $sent = end($slack->posted);
        $this->assertSame('D999', $sent['channel']);
        $this->assertStringContainsString($task->title, $sent['text']);
        // Slack's mrkdwn, not Markdown: a link is <url|label>.
        $this->assertStringContainsString('|Open>', $sent['text']);
    }

    public function test_slack_is_a_copy_and_not_a_replacement(): void
    {
        $this->fakeSlack();
        $this->connected();

        $via = (new TaskAssignedNotification($this->assignTask(), $this->colleague))->via($this->user->fresh());

        $this->assertContains(SlackChannel::class, $via);
        $this->assertContains('mail', $via);
    }

    public function test_a_revoked_app_says_so_in_plain_words(): void
    {
        $this->fakeSlack(postError: 'invalid_auth');
        $channel = $this->connected();

        // Slack's error codes are for machines; the person reading their
        // settings page needs a sentence.
        $this->assertStringContainsString('no longer authorised', (string) $channel->fresh()->last_error);
    }

    public function test_muting_works_the_same_as_everywhere_else(): void
    {
        $this->fakeSlack();
        $channel = $this->connected();
        $channel->update(['muted_events' => ['task_assigned']]);

        $via = (new TaskAssignedNotification($this->assignTask(), $this->colleague))->via($this->user->fresh());

        $this->assertNotContains(SlackChannel::class, $via);
    }

    // --- the settings page --------------------------------------------------------

    public function test_the_page_offers_slack_and_the_fallback(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('Connect Slack')
            ->assertSee('My Slack uses a different email address');
    }

    public function test_the_page_spells_out_how_to_unblock_a_browser(): void
    {
        // "Allow it in your browser settings" is true and useless: the menu is
        // somewhere different in every browser, and somebody who has to go and
        // ask has already given up.
        $this->actingAs($this->user)
            ->get(route('profile.notifications'))
            ->assertSuccessful()
            ->assertSee('Chrome / Edge')
            ->assertSee('chrome://settings/content/notifications')
            ->assertSee('Add to Home Screen')
            ->assertSee('Do Not Disturb');
    }
}
