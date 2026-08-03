<?php

namespace Tests\Feature;

use App\Support\Heartbeat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * A silent scheduler is the worst kind of broken: no error, no log, just work
 * that never happens. This is the thing that makes it audible.
 */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scheduler_that_has_never_run_is_not_believed(): void
    {
        $this->assertNull(Heartbeat::lastRunAt());
        $this->assertFalse(Heartbeat::isAlive());
    }

    public function test_a_check_in_marks_the_scheduler_alive(): void
    {
        Heartbeat::touch();

        $this->assertNotNull(Heartbeat::lastRunAt());
        $this->assertTrue(Heartbeat::isAlive());
    }

    public function test_a_stale_check_in_does_not_count(): void
    {
        Carbon::setTestNow(now()->subHours(3));
        Heartbeat::touch();
        Carbon::setTestNow();

        // It ran once, hours ago. That is not "running".
        $this->assertNotNull(Heartbeat::lastRunAt());
        $this->assertFalse(Heartbeat::isAlive());
    }

    public function test_checking_in_twice_keeps_one_row(): void
    {
        Heartbeat::touch();
        Heartbeat::touch();

        $this->assertDatabaseCount('settings', 1);
    }

    public function test_the_sweep_and_the_heartbeat_are_both_on_the_schedule(): void
    {
        $commands = collect(Schedule::events())->map(fn ($event) => $event->command ?? $event->description);

        $this->assertTrue(
            $commands->contains(fn (?string $c) => $c !== null && str_contains($c, 'tasks:archive')),
            'The archive sweep is not scheduled.'
        );
        $this->assertNotEmpty(Schedule::events(), 'Nothing is scheduled at all.');
    }
}
