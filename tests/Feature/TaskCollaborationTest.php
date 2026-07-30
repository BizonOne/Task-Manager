<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $collaborator;

    private User $outsider;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Alice Anderson', 'email' => 'alice@example.com', 'password' => bcrypt('secret')]);
        $this->collaborator = User::create(['name' => 'Bob Brown', 'email' => 'bob@example.com', 'password' => bcrypt('secret')]);
        $this->outsider = User::create(['name' => 'Eve Evans', 'email' => 'eve@example.com', 'password' => bcrypt('secret')]);
        $this->project = Project::create(['user_id' => $this->owner->id, 'name' => 'Collab', 'status' => 'in_progress']);
        $this->task = Task::create([
            'user_id' => $this->owner->id,
            'project_id' => $this->project->id,
            'title' => 'Ship it',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    public function test_owner_can_assign_a_collaborator_who_is_then_notified(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)
            ->postJson("/tasks/{$this->task->id}/assignees", ['user_id' => $this->collaborator->id])
            ->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertTrue($this->task->assignees()->where('users.id', $this->collaborator->id)->exists());
        Notification::assertSentTo($this->collaborator, TaskAssignedNotification::class);
    }

    public function test_non_owner_cannot_assign(): void
    {
        $this->actingAs($this->collaborator)
            ->postJson("/tasks/{$this->task->id}/assignees", ['user_id' => $this->outsider->id])
            ->assertForbidden();
    }

    public function test_assigned_collaborator_can_view_the_task(): void
    {
        $this->task->assignees()->attach($this->collaborator->id);

        $this->actingAs($this->collaborator)->get("/tasks/{$this->task->id}")->assertSuccessful();
    }

    public function test_outsider_cannot_view_or_comment(): void
    {
        $this->actingAs($this->outsider)->get("/tasks/{$this->task->id}")->assertForbidden();
        $this->actingAs($this->outsider)
            ->postJson("/tasks/{$this->task->id}/comments", ['body' => 'sneaky'])
            ->assertForbidden();
    }

    public function test_participant_can_comment(): void
    {
        $this->task->assignees()->attach($this->collaborator->id);

        $this->actingAs($this->collaborator)
            ->postJson("/tasks/{$this->task->id}/comments", ['body' => 'On it!'])
            ->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $this->task->id,
            'user_id' => $this->collaborator->id,
            'body' => 'On it!',
        ]);
    }

    public function test_mentioning_a_participant_notifies_them(): void
    {
        Notification::fake();
        $this->task->assignees()->attach($this->collaborator->id);

        // Bob Brown -> handle "bob-brown" or first name "bob".
        $this->actingAs($this->owner)
            ->postJson("/tasks/{$this->task->id}/comments", ['body' => 'Please review @bob'])
            ->assertSuccessful();

        Notification::assertSentTo($this->collaborator, MentionedInCommentNotification::class);
    }

    public function test_mention_does_not_notify_the_comment_author(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)
            ->postJson("/tasks/{$this->task->id}/comments", ['body' => 'Note to self @alice'])
            ->assertSuccessful();

        Notification::assertNothingSentTo($this->owner);
    }

    public function test_author_can_delete_own_comment_but_outsider_cannot(): void
    {
        $this->task->assignees()->attach($this->collaborator->id);
        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->collaborator->id,
            'body' => 'temp',
        ]);

        $this->actingAs($this->outsider)->deleteJson("/comments/{$comment->id}")->assertForbidden();
        $this->actingAs($this->collaborator)->deleteJson("/comments/{$comment->id}")->assertSuccessful();
        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    public function test_notifications_page_and_mark_read(): void
    {
        $this->task->assignees()->attach($this->collaborator->id);
        $this->collaborator->notify(new TaskAssignedNotification($this->task, $this->owner));

        $this->assertSame(1, $this->collaborator->unreadNotifications()->count());

        $this->actingAs($this->collaborator)->get('/notifications')->assertSuccessful();

        $this->actingAs($this->collaborator)->post('/notifications/read-all')->assertRedirect();
        $this->assertSame(0, $this->collaborator->fresh()->unreadNotifications()->count());
    }
}
