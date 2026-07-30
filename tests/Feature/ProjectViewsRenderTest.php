<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the project Blade pages (which reference no budget field anymore)
 * and the login page against render errors.
 */
class ProjectViewsRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_pages_and_login_render_without_budget(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Website',
            'status' => 'in_progress',
            'start_date' => now(),
            'end_date' => now()->addWeek(),
        ]);

        $this->get('/login')->assertSuccessful()->assertDontSee('Arafat');

        $this->actingAs($user)->get('/projects/create')->assertSuccessful()->assertDontSee('Budget');
        $this->actingAs($user)->get("/projects/{$project->slug}")->assertSuccessful()->assertDontSee('Budget');
        $this->actingAs($user)->get("/projects/{$project->slug}/edit")->assertSuccessful()->assertDontSee('Budget');
        $this->actingAs($user)->get('/projects')->assertSuccessful();
    }

    public function test_creating_a_project_ignores_any_budget_input(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner2@example.com', 'password' => bcrypt('secret')]);

        $this->actingAs($user)->post('/projects', [
            'name' => 'No Budget Project',
            'status' => 'not_started',
            'budget' => 9999,
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', ['name' => 'No Budget Project', 'user_id' => $user->id]);
    }
}
