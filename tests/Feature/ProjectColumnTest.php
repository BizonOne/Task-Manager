<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\BoardColumns;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two teams always work differently. An onboarding board runs Submitted →
 * Company → Acquirer → Complete; a delivery board runs To Do → In Progress →
 * Done. Until now every board in the app shared one set of columns, so one
 * team's column was an empty column on everybody else's screen.
 *
 * The promise underneath all of this: no task is ever left standing in a
 * column that does not exist. A task whose status names nothing appears on no
 * board at all, which is the same as losing it.
 */
class ProjectColumnTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Project $project;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaskStatusSeeder::class);

        $this->manager = User::create(['name' => 'Mara Manager', 'email' => 'mara@example.com', 'password' => bcrypt('secret')]);
        $this->member = User::create(['name' => 'Mel Member', 'email' => 'mel@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->manager->id, 'name' => 'Onboarding', 'status' => 'in_progress']);
        $this->project->users()->attach($this->member->id);
        $this->other = Project::create(['user_id' => $this->manager->id, 'name' => 'Delivery', 'status' => 'in_progress']);
    }

    private function task(string $status = 'to_do', ?Project $project = null): Task
    {
        $this->actingAs($this->manager);

        return Task::create([
            'user_id' => $this->manager->id,
            'project_id' => ($project ?? $this->project)->id,
            'title' => 'Onboard Acme',
            'priority' => 'medium',
            'status' => $status,
        ]);
    }

    // --- taking the board over ------------------------------------------------

    public function test_a_board_starts_on_the_shared_columns(): void
    {
        $this->assertFalse(TaskStatus::isCustomised($this->project->id));
        $this->assertSame(
            TaskStatus::ordered()->pluck('key')->all(),
            TaskStatus::ordered($this->project->id)->pluck('key')->all(),
        );
    }

    public function test_taking_the_board_over_copies_what_was_there(): void
    {
        $this->actingAs($this->manager)
            ->post(route('projects.columns.adopt', $this->project))
            ->assertRedirect();

        // Customising must change nothing until something is edited — every
        // task keeps the column it was in, because the keys are the same.
        $this->assertTrue(TaskStatus::isCustomised($this->project->id));
        $this->assertSame(
            TaskStatus::ordered()->pluck('key')->all(),
            TaskStatus::ordered($this->project->id)->pluck('key')->all(),
        );
    }

    public function test_only_whoever_manages_the_project_may(): void
    {
        $this->actingAs($this->member)
            ->post(route('projects.columns.adopt', $this->project))
            ->assertForbidden();

        $this->assertFalse(TaskStatus::isCustomised($this->project->id));
    }

    // --- rearranging ----------------------------------------------------------

    public function test_a_new_column_lands_on_this_board_and_no_other(): void
    {
        BoardColumns::adopt($this->project);

        $this->actingAs($this->manager)->post(route('projects.columns.store', $this->project), [
            'label' => 'Rejected',
            'color' => 'red',
            'is_completed' => '0',
        ])->assertRedirect();

        $this->assertContains('rejected', TaskStatus::keys($this->project->id));
        // The entire point: nobody else's screen changed.
        $this->assertNotContains('rejected', TaskStatus::keys($this->other->id));
        $this->assertNotContains('rejected', TaskStatus::keys());
    }

    public function test_adding_a_column_to_a_shared_board_takes_a_copy_first(): void
    {
        $this->actingAs($this->manager)->post(route('projects.columns.store', $this->project), [
            'label' => 'Rejected',
            'color' => 'red',
        ])->assertRedirect();

        // Otherwise the board would hold this one column and every task on it
        // would be standing nowhere.
        $this->assertContains('to_do', TaskStatus::keys($this->project->id));
        $this->assertSame(6, TaskStatus::ordered($this->project->id)->count());
    }

    public function test_renaming_a_column_does_not_move_anybody(): void
    {
        BoardColumns::adopt($this->project);
        $task = $this->task('in_review');
        $column = $this->project->statuses()->where('key', 'in_review')->first();

        $this->actingAs($this->manager)->put(route('projects.columns.update', $column), [
            'label' => 'With the acquirer',
            'color' => 'teal',
        ])->assertRedirect();

        $this->assertSame('in_review', $task->fresh()->status);
        $this->assertSame('With the acquirer', TaskStatus::labelFor('in_review', $this->project->id));
        // The shared column of the same key is untouched.
        $this->assertSame('In Review', TaskStatus::labelFor('in_review'));
    }

    public function test_removing_a_column_moves_what_was_in_it(): void
    {
        BoardColumns::adopt($this->project);
        $task = $this->task('on_hold');
        $column = $this->project->statuses()->where('key', 'on_hold')->first();

        $this->actingAs($this->manager)
            ->delete(route('projects.columns.destroy', $column))
            ->assertRedirect();

        // Not left standing in a column that no longer exists.
        $this->assertSame('to_do', $task->fresh()->status);
        $this->assertNotContains('on_hold', TaskStatus::keys($this->project->id));
    }

    public function test_the_last_column_cannot_be_removed(): void
    {
        BoardColumns::adopt($this->project);

        foreach ($this->project->statuses()->get()->skip(1) as $column) {
            BoardColumns::remove($column);
        }

        $this->actingAs($this->manager)
            ->delete(route('projects.columns.destroy', $this->project->statuses()->first()))
            ->assertSessionHasErrors('column');

        $this->assertSame(1, $this->project->statuses()->count());
    }

    public function test_a_shared_column_cannot_be_touched_from_a_project(): void
    {
        $shared = TaskStatus::whereNull('project_id')->first();

        // Shared columns belong to the whole app and are the admin panel's
        // business; a project may only rearrange its own.
        $this->actingAs($this->manager)
            ->delete(route('projects.columns.destroy', $shared))
            ->assertForbidden();
    }

    public function test_going_back_to_the_shared_columns_rehomes_the_stranded(): void
    {
        BoardColumns::adopt($this->project);
        $this->project->statuses()->create([
            'key' => 'rejected', 'label' => 'Rejected', 'color' => 'red', 'sort_order' => 9,
        ]);
        TaskStatus::forgetCached();

        $task = $this->task('rejected');

        $this->actingAs($this->manager)
            ->delete(route('projects.columns.release', $this->project))
            ->assertRedirect();

        $this->assertFalse(TaskStatus::isCustomised($this->project->id));
        $this->assertSame('to_do', $task->fresh()->status);
    }

    // --- what the rest of the app does with them ------------------------------

    public function test_the_board_shows_this_projects_columns(): void
    {
        BoardColumns::adopt($this->project);
        $column = $this->project->statuses()->where('key', 'in_review')->first();
        $column->update(['label' => 'With the acquirer']);

        // The board only draws its columns once it has something to put in
        // them; an empty project shows the empty state instead.
        $this->task('to_do');

        $this->actingAs($this->manager)
            ->get(route('projects.tasks.index', $this->project))
            ->assertSuccessful()
            ->assertSee('With the acquirer')
            ->assertDontSee('In Review');
    }

    public function test_my_tasks_shows_the_union_so_nothing_is_hidden(): void
    {
        BoardColumns::adopt($this->project);
        $this->project->statuses()->create([
            'key' => 'rejected', 'label' => 'Rejected', 'color' => 'red', 'sort_order' => 9,
        ]);
        TaskStatus::forgetCached();

        $rejected = $this->task('rejected');
        $ordinary = $this->task('to_do', $this->other);

        // A board mixing work from everywhere needs every column that work
        // stands in — a column missing there is a task nobody can see.
        $this->actingAs($this->manager)
            ->get(route('tasks.index'))
            ->assertSuccessful()
            ->assertSee('Rejected')
            ->assertSee('data-open="'.route('tasks.show', $rejected->id).'"', false)
            ->assertSee('data-open="'.route('tasks.show', $ordinary->id).'"', false);
    }

    public function test_a_status_this_board_does_not_have_is_refused(): void
    {
        BoardColumns::adopt($this->project);
        $this->project->statuses()->where('key', 'on_hold')->delete();
        TaskStatus::forgetCached();

        $this->actingAs($this->manager)->post(route('tasks.store'), [
            'project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'title' => 'Onboard Acme',
            'priority' => 'medium',
            'status' => 'on_hold',
        ])->assertSessionHasErrors('status');
    }

    public function test_moving_a_task_to_a_board_without_its_column_puts_it_somewhere_real(): void
    {
        BoardColumns::adopt($this->project);
        $this->project->statuses()->create([
            'key' => 'rejected', 'label' => 'Rejected', 'color' => 'red', 'sort_order' => 9,
        ]);
        TaskStatus::forgetCached();

        $task = $this->task('rejected');

        $task->project_id = $this->other->id;
        $task->save();

        $this->assertSame('to_do', $task->fresh()->status);
    }

    public function test_finished_means_what_this_board_says_it_means(): void
    {
        BoardColumns::adopt($this->project);
        $this->project->statuses()->where('key', 'in_review')->update(['is_completed' => true]);
        TaskStatus::forgetCached();

        $task = $this->task('to_do');
        $task->status = 'in_review';
        $task->save();

        // On the shared board "in review" is work in progress; on this one it
        // is done, and the archive counts from that.
        $this->assertTrue($task->fresh()->isCompleted());
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_the_settings_page_offers_the_columns(): void
    {
        $this->actingAs($this->manager)
            ->get(route('projects.edit', $this->project))
            ->assertSuccessful()
            ->assertSee('Task board columns')
            ->assertSee('Give this board its own columns');

        BoardColumns::adopt($this->project);

        $this->actingAs($this->manager)
            ->get(route('projects.edit', $this->project))
            ->assertSuccessful()
            ->assertSee('Use the shared columns again')
            // Opened by its button, not sitting on the page as an empty row.
            ->assertSee('id="addColumnForm" hidden', false);
    }

    public function test_the_project_own_status_is_not_the_same_thing_and_says_so(): void
    {
        // Two sections on one page both called "Status" is a question nobody
        // should have to work out from the chips underneath.
        $this->actingAs($this->manager)
            ->get(route('projects.edit', $this->project))
            ->assertSuccessful()
            ->assertSee('Project status')
            ->assertSee('Where the project itself stands')
            ->assertSee('Task board columns');
    }
}
