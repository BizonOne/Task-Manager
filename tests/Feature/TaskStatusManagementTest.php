<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaskStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task statuses are admin-managed rows, not a hardcoded list: adding one makes
 * it a real board column that tasks can be moved into.
 */
class TaskStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaskStatusSeeder::class);

        $this->admin = User::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => bcrypt('secret')]);
        $this->admin->assignRole('super_admin');
        $this->project = Project::create(['user_id' => $this->admin->id, 'name' => 'P', 'status' => 'in_progress']);
    }

    private function task(string $status): Task
    {
        return Task::create([
            'user_id' => $this->admin->id,
            'project_id' => $this->project->id,
            'title' => 'T-'.$status,
            'priority' => 'medium',
            'status' => $status,
        ]);
    }

    public function test_the_seeder_installs_the_built_in_statuses_and_is_idempotent(): void
    {
        $this->assertSame(
            ['to_do', 'in_progress', 'on_hold', 'in_review', 'completed'],
            TaskStatus::keys()
        );

        // Re-running must not duplicate or overwrite customisations.
        TaskStatus::where('key', 'to_do')->update(['label' => 'Backlog']);
        $this->seed(TaskStatusSeeder::class);

        $this->assertSame(5, TaskStatus::count());
        $this->assertSame('Backlog', TaskStatus::find_by_key('to_do')->label);
    }

    public function test_a_custom_status_becomes_a_valid_status_for_tasks(): void
    {
        TaskStatus::create(['key' => 'blocked', 'label' => 'Blocked', 'color' => 'red', 'sort_order' => 6]);

        $this->assertContains('blocked', TaskStatus::keys());
        $this->assertStringContainsString('blocked', TaskStatus::validationRule());

        // The status column is no longer an ENUM, so the value persists.
        $task = $this->task('blocked');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'blocked']);
        $this->assertSame('Blocked', $task->fresh()->status_label);
    }

    public function test_a_task_can_be_moved_into_a_custom_status_through_the_board(): void
    {
        TaskStatus::create(['key' => 'blocked', 'label' => 'Blocked', 'color' => 'red', 'sort_order' => 6]);
        $task = $this->task('to_do');

        $this->actingAs($this->admin)
            ->postJson("/tasks/{$task->id}/update-status", ['status' => 'blocked'])
            ->assertSuccessful();

        $this->assertSame('blocked', $task->fresh()->status);
    }

    public function test_a_status_that_does_not_exist_is_rejected(): void
    {
        $task = $this->task('to_do');

        $this->actingAs($this->admin)
            ->postJson("/tasks/{$task->id}/update-status", ['status' => 'not_a_status'])
            ->assertStatus(422);

        $this->assertSame('to_do', $task->fresh()->status);
    }

    public function test_the_board_renders_a_column_for_every_status_including_new_ones(): void
    {
        TaskStatus::create(['key' => 'blocked', 'label' => 'Blocked', 'color' => 'red', 'sort_order' => 6]);
        $this->task('blocked');

        $this->actingAs($this->admin)->get('/tasks')
            ->assertSuccessful()
            ->assertSee('Blocked')
            ->assertSee('col-blocked', escape: false);
    }

    public function test_only_one_status_can_be_the_default(): void
    {
        $this->assertSame('to_do', TaskStatus::defaultKey());

        TaskStatus::find_by_key('in_progress')->fresh()->update(['is_default' => true]);
        TaskStatus::forgetCached();

        $this->assertSame('in_progress', TaskStatus::defaultKey());
        $this->assertSame(1, TaskStatus::where('is_default', true)->count());
    }

    public function test_completed_keys_drive_the_completed_scope(): void
    {
        $done = $this->task('completed');
        $open = $this->task('to_do');

        $this->assertTrue($done->isCompleted());
        $this->assertFalse($open->isCompleted());
        $this->assertEqualsCanonicalizing([$done->id], Task::completed()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$open->id], Task::notCompleted()->pluck('id')->all());

        // Marking another status as completed reclassifies its tasks.
        TaskStatus::find_by_key('in_review')->fresh()->update(['is_completed' => true]);
        TaskStatus::forgetCached();
        $review = $this->task('in_review');

        $this->assertTrue($review->isCompleted());
        $this->assertEqualsCanonicalizing([$done->id, $review->id], Task::completed()->pluck('id')->all());
    }

    public function test_a_deleted_status_still_renders_a_readable_label_on_its_tasks(): void
    {
        TaskStatus::create(['key' => 'blocked', 'label' => 'Blocked', 'color' => 'red', 'sort_order' => 6]);
        $task = $this->task('blocked');

        TaskStatus::where('key', 'blocked')->delete();
        TaskStatus::forgetCached();

        // Falls back to a prettified key rather than blowing up.
        $this->assertSame('Blocked', TaskStatus::labelFor('blocked'));
        $this->actingAs($this->admin)->get("/tasks/{$task->id}")->assertSuccessful();
    }

    public function test_the_admin_status_management_page_renders(): void
    {
        $this->actingAs($this->admin)->get('/admin/task-statuses')
            ->assertSuccessful()
            ->assertSee('In Progress')
            ->assertSee('Task Statuses');
    }
}
