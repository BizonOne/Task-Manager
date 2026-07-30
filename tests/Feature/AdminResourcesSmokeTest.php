<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Smoke test: every admin resource index page (and the widget-bearing
 * dashboard) renders without error for a super admin. Catches broken
 * resource/form/table/widget configuration before it reaches production.
 */
class AdminResourcesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::create([
            'name' => 'Super',
            'email' => 'super@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->assignRole('super_admin');

        return $user;
    }

    public static function resourcePages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'users' => ['/admin/users'],
            'projects' => ['/admin/projects'],
            'tasks' => ['/admin/tasks'],
            'notes' => ['/admin/notes'],
            'reminders' => ['/admin/reminders'],
            'routines' => ['/admin/routines'],
            'files' => ['/admin/files'],
            'ai-conversations' => ['/admin/ai-conversations'],
        ];
    }

    #[DataProvider('resourcePages')]
    public function test_admin_page_renders_for_super_admin(string $path): void
    {
        $this->actingAs($this->superAdmin())->get($path)->assertSuccessful();
    }

    public function test_task_edit_page_renders_with_collaboration_relation_managers(): void
    {
        $admin = $this->superAdmin();
        $project = Project::create(['user_id' => $admin->id, 'name' => 'P', 'status' => 'in_progress']);
        $task = Task::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'title' => 'Edit me',
            'priority' => 'high',
            'status' => 'to_do',
        ]);

        // Edit page mounts the Checklist, Assignees and Discussion relation managers.
        $this->actingAs($admin)->get("/admin/tasks/{$task->id}/edit")->assertSuccessful();
    }

    public function test_routine_edit_page_renders_with_json_encoded_lists(): void
    {
        $admin = $this->superAdmin();

        $routine = Routine::create([
            'user_id' => $admin->id,
            'title' => 'Morning Routine',
            'frequency' => 'weekly',
            'days' => json_encode(['monday', 'wednesday', 'friday']),
            'start_time' => '07:00',
            'end_time' => '08:00',
        ]);

        // Exercises the JSON <-> array hydration in RoutineForm.
        $this->actingAs($admin)->get("/admin/routines/{$routine->id}/edit")->assertSuccessful();
    }
}
