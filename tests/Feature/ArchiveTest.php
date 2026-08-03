<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Support\Archive;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finished work leaves the boards on a timer, and stays findable afterwards.
 *
 * Archiving is not deleting: the task still opens, still holds its discussion
 * and links, and still counts in reports.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $outsider;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Olive Owner', 'email' => 'olive@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Otto Outside', 'email' => 'otto@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Delivery', 'status' => 'in_progress']);
    }

    private function task(string $status = 'to_do'): Task
    {
        return Task::create([
            'user_id' => $this->owner->id,
            'project_id' => $this->project->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
            'status' => $status,
        ]);
    }

    // --- completed_at -------------------------------------------------------

    public function test_finishing_a_task_records_when(): void
    {
        $task = $this->task();
        $this->assertNull($task->completed_at);

        Carbon::setTestNow('2026-08-03 10:00:00');
        $task->update(['status' => 'completed']);
        Carbon::setTestNow();

        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame('2026-08-03 10:00:00', $task->fresh()->completed_at->toDateTimeString());
    }

    public function test_a_task_created_already_finished_gets_a_completion_date(): void
    {
        $this->assertNotNull($this->task('completed')->completed_at);
    }

    public function test_editing_a_finished_task_does_not_move_its_completion_date(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00');
        $task = $this->task('completed');
        Carbon::setTestNow('2026-08-20 15:00:00');
        $task->update(['title' => 'A better title']);
        Carbon::setTestNow();

        // This is the whole reason the column exists: updated_at would have moved.
        $this->assertSame('2026-08-03 10:00:00', $task->fresh()->completed_at->toDateTimeString());
    }

    public function test_reopening_a_task_clears_its_completion_date(): void
    {
        $task = $this->task('completed');

        $task->update(['status' => 'in_progress']);

        $this->assertNull($task->fresh()->completed_at);
    }

    // --- the sweep ----------------------------------------------------------

    public function test_the_sweep_archives_work_finished_longer_ago_than_the_window(): void
    {
        // Take the real clock first: once it is frozen, now() returns the
        // frozen time and "now" and "40 days ago" become the same moment.
        $realNow = Carbon::now();

        Carbon::setTestNow($realNow->copy()->subDays(40));
        $old = $this->task('completed');

        Carbon::setTestNow($realNow);
        $recent = $this->task('completed');

        Carbon::setTestNow();

        $this->assertSame(1, Archive::sweep(30));

        $this->assertTrue($old->fresh()->isArchived());
        $this->assertFalse($recent->fresh()->isArchived());
    }

    public function test_the_sweep_leaves_unfinished_work_alone(): void
    {
        Carbon::setTestNow(now()->subDays(200));
        $task = $this->task('in_progress');
        Carbon::setTestNow();

        $this->assertSame(0, Archive::sweep(30));
        $this->assertFalse($task->fresh()->isArchived());
    }

    public function test_running_the_sweep_twice_changes_nothing_the_second_time(): void
    {
        Carbon::setTestNow(now()->subDays(40));
        $this->task('completed');
        Carbon::setTestNow();

        $this->assertSame(1, Archive::sweep(30));
        $this->assertSame(0, Archive::sweep(30));
    }

    public function test_a_zero_window_turns_automatic_archiving_off(): void
    {
        Carbon::setTestNow(now()->subDays(400));
        $task = $this->task('completed');
        Carbon::setTestNow();

        Archive::setAfterDays(null);

        $this->assertNull(Archive::afterDays());
        $this->assertSame(0, Archive::sweep());
        $this->assertFalse($task->fresh()->isArchived());
    }

    public function test_the_command_reports_what_it_would_do_without_doing_it(): void
    {
        Carbon::setTestNow(now()->subDays(40));
        $task = $this->task('completed');
        Carbon::setTestNow();

        $this->artisan('tasks:archive --dry-run --days=30')
            ->expectsOutputToContain('1 task(s)')
            ->assertSuccessful();

        $this->assertFalse($task->fresh()->isArchived());
    }

    // --- reopening ----------------------------------------------------------

    public function test_reopening_an_archived_task_brings_it_back(): void
    {
        $task = $this->task('completed');
        Archive::archive($task);

        $task->update(['status' => 'in_progress']);

        // A task in progress that nobody can see on a board is a bug, not a
        // feature.
        $this->assertFalse($task->fresh()->isArchived());
        $this->assertTrue($task->activities()->where('event', TaskActivity::EVENT_UNARCHIVED)->exists());
    }

    // --- what archiving hides, and what it does not -------------------------

    public function test_archived_tasks_leave_the_board(): void
    {
        $task = $this->task('completed');

        $this->actingAs($this->owner)->get('/tasks')->assertSee($task->title);

        Archive::archive($task);

        $this->actingAs($this->owner)->get('/tasks')->assertDontSee($task->title);
    }

    public function test_an_archived_task_still_opens_from_its_own_link(): void
    {
        $task = $this->task('completed');
        Archive::archive($task);

        $this->actingAs($this->owner)
            ->get("/tasks/{$task->id}")
            ->assertSuccessful()
            ->assertSee('Archived');
    }

    public function test_reports_count_archived_work_by_default(): void
    {
        $task = $this->task('completed');
        Archive::archive($task);

        // Filing a task away does not un-do it.
        $this->actingAs($this->owner)
            ->get('/reports')
            ->assertSuccessful()
            ->assertSee($task->title);
    }

    public function test_reports_can_be_narrowed_to_live_work(): void
    {
        $archived = $this->task('completed');
        Archive::archive($archived);

        $this->actingAs($this->owner)
            ->get('/reports?archive=active')
            ->assertSuccessful()
            ->assertDontSee($archived->title);
    }

    // --- the archive section ------------------------------------------------

    public function test_the_archive_section_lists_archived_work_and_nothing_else(): void
    {
        $archived = $this->task('completed');
        Archive::archive($archived);
        $live = Task::create([
            'user_id' => $this->owner->id, 'project_id' => $this->project->id,
            'title' => 'Still going', 'priority' => 'low', 'status' => 'in_progress',
        ]);

        $this->actingAs($this->owner)
            ->get('/archive')
            ->assertSuccessful()
            ->assertSee($archived->title)
            ->assertDontSee($live->title);
    }

    public function test_the_archive_cannot_be_talked_into_showing_live_tasks(): void
    {
        $live = $this->task('in_progress');

        // The archive shows the archive; the query string does not get a vote.
        $this->actingAs($this->owner)
            ->get('/archive?archive=all')
            ->assertSuccessful()
            ->assertDontSee($live->title);
    }

    public function test_the_archive_only_shows_work_the_viewer_may_see(): void
    {
        $task = $this->task('completed');
        Archive::archive($task);

        $this->actingAs($this->outsider)
            ->get('/archive')
            ->assertSuccessful()
            ->assertDontSee($task->title);
    }

    // --- the buttons --------------------------------------------------------

    public function test_someone_who_can_edit_a_task_can_archive_and_restore_it(): void
    {
        $task = $this->task('completed');

        $this->actingAs($this->owner)->post("/tasks/{$task->id}/archive")->assertRedirect();
        $this->assertTrue($task->fresh()->isArchived());

        $this->actingAs($this->owner)->delete("/tasks/{$task->id}/archive")->assertRedirect();
        $this->assertFalse($task->fresh()->isArchived());
    }

    public function test_an_outsider_cannot_archive_someone_elses_task(): void
    {
        $task = $this->task('completed');

        $this->actingAs($this->outsider)->post("/tasks/{$task->id}/archive")->assertForbidden();
        $this->assertFalse($task->fresh()->isArchived());
    }

    public function test_archiving_is_written_into_the_task_history(): void
    {
        $task = $this->task('completed');

        $this->actingAs($this->owner)->post("/tasks/{$task->id}/archive");

        $this->assertTrue($task->activities()->where('event', TaskActivity::EVENT_ARCHIVED)->exists());
    }

    public function test_archive_state_cannot_be_set_through_the_edit_form(): void
    {
        $task = $this->task('completed');

        // The edit form passes the whole request into update(); archived_at and
        // completed_at are not fillable, and this is why.
        $this->actingAs($this->owner)->put("/tasks/{$task->id}", [
            'title' => $task->title,
            'priority' => 'high',
            'status' => 'completed',
            'archived_at' => now()->toDateTimeString(),
            'completed_at' => '2000-01-01 00:00:00',
        ])->assertRedirect();

        $this->assertFalse($task->fresh()->isArchived());
        $this->assertNotSame('2000-01-01 00:00:00', $task->fresh()->completed_at->toDateTimeString());
    }
}
