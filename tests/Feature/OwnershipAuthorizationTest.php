<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Project;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the front-end controllers against horizontal privilege escalation:
 * a signed-in user must not be able to reach another user's records by URL.
 */
class OwnershipAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $this->intruder = User::create(['name' => 'Intruder', 'email' => 'intruder@example.com', 'password' => bcrypt('secret')]);
    }

    public function test_a_user_cannot_view_another_users_project(): void
    {
        $project = Project::create([
            'user_id' => $this->owner->id,
            'name' => 'Secret Project',
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->intruder)->get("/projects/{$project->slug}")->assertForbidden();
        $this->actingAs($this->owner)->get("/projects/{$project->slug}")->assertSuccessful();
    }

    public function test_a_user_cannot_delete_another_users_project(): void
    {
        $project = Project::create([
            'user_id' => $this->owner->id,
            'name' => 'Secret Project',
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->intruder)->delete("/projects/{$project->slug}")->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_a_user_cannot_view_another_users_task(): void
    {
        $project = Project::create(['user_id' => $this->owner->id, 'name' => 'P', 'status' => 'in_progress']);
        $task = Task::create([
            'user_id' => $this->owner->id,
            'project_id' => $project->id,
            'title' => 'Secret Task',
            'priority' => 'high',
            'status' => 'to_do',
        ]);

        $this->actingAs($this->intruder)->get("/tasks/{$task->id}")->assertForbidden();
    }

    public function test_a_user_cannot_change_the_status_of_another_users_task(): void
    {
        $project = Project::create(['user_id' => $this->owner->id, 'name' => 'P', 'status' => 'in_progress']);
        $task = Task::create([
            'user_id' => $this->owner->id,
            'project_id' => $project->id,
            'title' => 'Secret Task',
            'priority' => 'high',
            'status' => 'to_do',
        ]);

        $this->actingAs($this->intruder)
            ->post("/tasks/{$task->id}/update-status", ['status' => 'completed'])
            ->assertForbidden();

        $this->assertSame('to_do', $task->fresh()->status);
    }

    public function test_a_user_cannot_delete_another_users_file(): void
    {
        $file = File::create([
            'user_id' => $this->owner->id,
            'name' => 'secret.pdf',
            'path' => 'uploads/secret.pdf',
            'type' => 'docs',
        ]);

        $this->actingAs($this->intruder)->delete("/files/{$file->id}")->assertForbidden();
        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_a_user_cannot_edit_another_users_routine(): void
    {
        $routine = Routine::create([
            'user_id' => $this->owner->id,
            'title' => 'Morning Routine',
            'frequency' => 'daily',
            'start_time' => '07:00',
            'end_time' => '08:00',
        ]);

        $this->actingAs($this->intruder)->get("/routines/{$routine->id}/edit")->assertForbidden();
    }
}
