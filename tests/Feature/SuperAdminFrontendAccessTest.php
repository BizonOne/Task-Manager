<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A super admin oversees the whole workspace and can view and act on every
 * project and task in the front-end — even ones they neither own nor belong to.
 */
class SuperAdminFrontendAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $owner;

    private User $outsider;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->superAdmin = User::create(['name' => 'Super Admin', 'email' => 'super@example.com', 'password' => bcrypt('secret')]);
        $this->superAdmin->assignRole('super_admin');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Outsider', 'email' => 'outsider@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Private Project', 'status' => 'in_progress']);
        $this->task = Task::create(['user_id' => $this->owner->id, 'project_id' => $this->project->id, 'title' => 'Private Task', 'priority' => 'high', 'status' => 'to_do']);
    }

    public function test_super_admin_sees_every_project_including_ones_they_do_not_belong_to(): void
    {
        $this->actingAs($this->superAdmin)->get('/projects')->assertSuccessful()->assertSee('Private Project');

        // A plain outsider still sees nothing.
        $this->actingAs($this->outsider)->get('/projects')->assertSuccessful()->assertDontSee('Private Project');
    }

    public function test_super_admin_can_open_and_manage_any_project(): void
    {
        $this->actingAs($this->superAdmin)->get("/projects/{$this->project->slug}")->assertSuccessful();
        $this->actingAs($this->superAdmin)->get("/projects/{$this->project->slug}/edit")->assertSuccessful();

        // Outsider is still locked out.
        $this->actingAs($this->outsider)->get("/projects/{$this->project->slug}")->assertForbidden();
    }

    public function test_super_admin_can_delete_any_project(): void
    {
        $this->actingAs($this->superAdmin)->delete("/projects/{$this->project->slug}")->assertRedirect();
        $this->assertDatabaseMissing('projects', ['id' => $this->project->id]);
    }

    public function test_super_admin_sees_every_task_on_the_global_board(): void
    {
        $this->actingAs($this->superAdmin)->get('/tasks')->assertSuccessful()->assertSee('Private Task');
    }

    public function test_super_admin_can_open_and_edit_any_task(): void
    {
        $this->actingAs($this->superAdmin)->get("/tasks/{$this->task->id}")->assertSuccessful();
        $this->actingAs($this->superAdmin)->get("/tasks/{$this->task->id}/edit")->assertSuccessful();

        $this->actingAs($this->outsider)->get("/tasks/{$this->task->id}")->assertForbidden();
    }

    public function test_super_admin_can_comment_on_any_task(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/tasks/{$this->task->id}/comments", ['body' => 'Stepping in to help.'])
            ->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('task_comments', ['task_id' => $this->task->id, 'user_id' => $this->superAdmin->id]);
    }

    public function test_super_admin_can_change_status_of_any_task(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/tasks/{$this->task->id}/update-status", ['status' => 'in_progress'])
            ->assertSuccessful();

        $this->assertDatabaseHas('tasks', ['id' => $this->task->id, 'status' => 'in_progress']);
    }
}
