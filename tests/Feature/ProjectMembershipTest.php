<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AddedToProjectNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $manager;

    private User $outsider;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $this->member = User::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => bcrypt('secret')]);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Outsider', 'email' => 'outsider@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Team Project', 'status' => 'in_progress']);
        $this->project->users()->attach($this->member->id, ['role' => 'member']);
        $this->project->users()->attach($this->manager->id, ['role' => 'manager']);
    }

    public function test_members_see_the_project_in_their_list_but_outsiders_do_not(): void
    {
        $this->actingAs($this->member)->get('/projects')->assertSuccessful()->assertSee('Team Project');
        $this->actingAs($this->outsider)->get('/projects')->assertSuccessful()->assertDontSee('Team Project');
    }

    public function test_member_can_open_the_project_but_outsider_cannot(): void
    {
        $this->actingAs($this->member)->get("/projects/{$this->project->slug}")->assertSuccessful();
        $this->actingAs($this->outsider)->get("/projects/{$this->project->slug}")->assertForbidden();
    }

    public function test_member_sees_the_whole_project_board_not_just_their_own_tasks(): void
    {
        Task::create(['user_id' => $this->owner->id, 'project_id' => $this->project->id, 'title' => 'Owner task', 'priority' => 'high', 'status' => 'to_do']);

        $this->actingAs($this->member)
            ->get("/projects/{$this->project->slug}/tasks")
            ->assertSuccessful()
            ->assertSee('Owner task');

        $this->actingAs($this->outsider)->get("/projects/{$this->project->slug}/tasks")->assertForbidden();
    }

    public function test_member_can_create_a_task_in_the_project(): void
    {
        $this->actingAs($this->member)->post("/projects/{$this->project->slug}/tasks", [
            'project_id' => $this->project->id,
            'user_id' => $this->member->id,
            'title' => 'Member task',
            'priority' => 'medium',
            'status' => 'to_do',
        ])->assertRedirect();

        $this->assertDatabaseHas('tasks', ['title' => 'Member task', 'user_id' => $this->member->id, 'project_id' => $this->project->id]);
    }

    public function test_outsider_cannot_create_a_task_in_the_project(): void
    {
        $this->actingAs($this->outsider)->post("/projects/{$this->project->slug}/tasks", [
            'project_id' => $this->project->id,
            'user_id' => $this->outsider->id,
            'title' => 'Sneaky',
            'priority' => 'medium',
            'status' => 'to_do',
        ])->assertForbidden();
    }

    public function test_manager_can_edit_the_project_but_plain_member_cannot(): void
    {
        $this->actingAs($this->manager)->get("/projects/{$this->project->slug}/edit")->assertSuccessful();
        $this->actingAs($this->member)->get("/projects/{$this->project->slug}/edit")->assertForbidden();
    }

    public function test_only_the_owner_can_delete_the_project(): void
    {
        $this->actingAs($this->manager)->delete("/projects/{$this->project->slug}")->assertForbidden();
        $this->actingAs($this->owner)->delete("/projects/{$this->project->slug}")->assertRedirect();
    }

    public function test_owner_can_add_a_member_who_is_notified(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)->post('/project/team', [
            'project_id' => $this->project->id,
            'user_id' => $this->outsider->id,
            'role' => 'member',
        ])->assertRedirect();

        $this->assertTrue($this->project->users()->where('users.id', $this->outsider->id)->exists());
        Notification::assertSentTo($this->outsider, AddedToProjectNotification::class);
    }

    public function test_plain_member_cannot_add_or_remove_members(): void
    {
        $this->actingAs($this->member)->post('/project/team', [
            'project_id' => $this->project->id,
            'user_id' => $this->outsider->id,
        ])->assertForbidden();

        $this->actingAs($this->member)
            ->delete("/projects/{$this->project->slug}/members/{$this->manager->id}")
            ->assertForbidden();
    }

    public function test_manager_can_remove_a_member(): void
    {
        $this->actingAs($this->manager)
            ->delete("/projects/{$this->project->slug}/members/{$this->member->id}")
            ->assertRedirect();

        $this->assertFalse($this->project->users()->where('users.id', $this->member->id)->exists());
    }
}
