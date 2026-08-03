<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Dates;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timestamps are stored in UTC and read somewhere else. Both halves of that
 * sentence have to hold, or a task created at 16:15 reads as 13:15.
 */
class DatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Riga']);
    }

    public function test_the_clock_is_twenty_four_hour(): void
    {
        // 23:59, not 11:59 PM.
        $this->assertSame('23:59', Dates::time(Carbon::parse('2026-08-03 20:59:00', 'UTC')));
        $this->assertSame('Aug 03, 2026 23:59', Dates::dateTime(Carbon::parse('2026-08-03 20:59:00', 'UTC')));
    }

    public function test_an_instant_is_shown_in_the_display_timezone(): void
    {
        $utc = Carbon::parse('2026-08-03 13:15:38', 'UTC');

        // Riga is UTC+3 in August.
        $this->assertSame('Aug 03, 2026 16:15', Dates::dateTime($utc));
        $this->assertSame('16:15', Dates::time($utc));
    }

    public function test_a_wall_clock_time_is_never_converted(): void
    {
        // A routine that runs at 09:00 runs at 09:00. Putting it through a
        // timezone would move it to 12:00 and be plainly wrong.
        $this->assertSame('09:00', Dates::clock('09:00:00'));
        $this->assertSame('18:30', Dates::clock('18:30'));
    }

    public function test_the_conversion_does_not_mutate_the_carbon_it_was_given(): void
    {
        $utc = Carbon::parse('2026-08-03 13:15:38', 'UTC');

        Dates::dateTime($utc);

        $this->assertSame('UTC', $utc->timezone->getName());
        $this->assertSame('13:15', $utc->format('H:i'));
    }

    public function test_nothing_in_gives_nothing_out(): void
    {
        $this->assertNull(Dates::dateTime(null));
        $this->assertNull(Dates::clock(null));
        $this->assertNull(Dates::date(''));
        $this->assertNull(Dates::clock('not a time'));
    }

    public function test_an_empty_display_timezone_falls_back_to_the_app_one(): void
    {
        config(['app.display_timezone' => null, 'app.timezone' => 'UTC']);

        $this->assertSame('UTC', Dates::timezone());
        $this->assertSame('13:15', Dates::time(Carbon::parse('2026-08-03 13:15:00', 'UTC')));
    }

    public function test_the_task_page_shows_the_hour_a_task_was_created(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Ops', 'status' => 'in_progress']);

        Carbon::setTestNow(Carbon::parse('2026-08-03 13:15:38', 'UTC'));
        $task = Task::create([
            'user_id' => $owner->id,
            'project_id' => $project->id,
            'title' => 'Ship it',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
        Carbon::setTestNow();

        $this->actingAs($owner)
            ->get("/tasks/{$task->id}")
            ->assertSuccessful()
            // The whole point of the change: the day was never the question.
            ->assertSee('Aug 03, 2026 16:15');
    }
}
