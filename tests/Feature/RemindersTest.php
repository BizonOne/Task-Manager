<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Rem', 'email' => 'rem@example.com', 'password' => bcrypt('secret')]);
    }

    private function reminder(array $overrides = []): Reminder
    {
        return Reminder::create(array_merge([
            'user_id' => $this->user->id,
            'title' => 'Ping the vendor',
            'date' => today()->toDateString(),
            'time' => '09:00',
            'priority' => 'high',
        ], $overrides));
    }

    public function test_the_reminders_page_loads(): void
    {
        // The overdue scope used CONCAT()/NOW(), which are MySQL-only and made
        // this page throw "no such function: NOW" on every other driver.
        $this->reminder();

        $this->actingAs($this->user)->get('/reminders')
            ->assertSuccessful()
            ->assertSee('Ping the vendor');
    }

    public function test_the_overdue_scope_matches_past_reminders_only(): void
    {
        $yesterday = $this->reminder(['title' => 'Yesterday', 'date' => today()->subDay()->toDateString(), 'time' => '09:00']);
        $tomorrow = $this->reminder(['title' => 'Tomorrow', 'date' => today()->addDay()->toDateString(), 'time' => '09:00']);
        $earlierToday = $this->reminder(['title' => 'Earlier today', 'date' => today()->toDateString(), 'time' => '00:01']);
        $laterToday = $this->reminder(['title' => 'Later today', 'date' => today()->toDateString(), 'time' => '23:59']);
        $done = $this->reminder(['title' => 'Done', 'date' => today()->subDay()->toDateString(), 'is_completed' => true]);

        $overdue = Reminder::overdue()->pluck('id');

        $this->assertContains($yesterday->id, $overdue);
        $this->assertContains($earlierToday->id, $overdue);
        $this->assertNotContains($tomorrow->id, $overdue);
        $this->assertNotContains($laterToday->id, $overdue);
        $this->assertNotContains($done->id, $overdue, 'A completed reminder is never overdue.');
    }

    public function test_a_reminder_with_no_time_counts_from_midnight(): void
    {
        $today = $this->reminder(['title' => 'No time today', 'date' => today()->toDateString(), 'time' => null]);
        $future = $this->reminder(['title' => 'No time later', 'date' => today()->addDay()->toDateString(), 'time' => null]);

        $overdue = Reminder::overdue()->pluck('id');

        $this->assertContains($today->id, $overdue);
        $this->assertNotContains($future->id, $overdue);
    }

    public function test_completing_a_reminder_works(): void
    {
        $reminder = $this->reminder();

        $this->actingAs($this->user)
            ->post("/reminders/{$reminder->id}/toggle-complete")
            ->assertSuccessful();

        $this->assertTrue((bool) $reminder->fresh()->is_completed);
    }

    public function test_snoozing_a_reminder_works(): void
    {
        $reminder = $this->reminder();

        $this->actingAs($this->user)
            ->post("/reminders/{$reminder->id}/snooze", ['minutes' => 30])
            ->assertSuccessful();

        $this->assertNotNull($reminder->fresh()->snooze_until);
    }

    public function test_the_layout_exposes_the_csrf_token_the_ajax_actions_need(): void
    {
        // Complete/Snooze on reminders and the notes actions all read this meta
        // tag; without it their scripts threw before sending the request.
        $this->actingAs($this->user)->get('/reminders')
            ->assertSuccessful()
            ->assertSee('name="csrf-token"', escape: false);
    }

    public function test_signing_in_lands_on_the_dashboard_not_a_404(): void
    {
        // The dashboard is served at '/', so redirecting to the literal path
        // 'dashboard' produced a 404 on a direct sign-in.
        $response = $this->post('/login', [
            'email' => $this->user->email,
            'password' => 'secret',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->followRedirects($response)->assertSuccessful();
    }
}
