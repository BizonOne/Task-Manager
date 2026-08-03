<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCommentedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Being given a task, and a discussion moving on, are things a person has to
 * be told about — not things they are expected to find by opening a board.
 */
class TaskNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $worker;

    private User $second;

    private User $bystander;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::create(['name' => 'Mary Manager', 'email' => 'mary@example.com', 'password' => bcrypt('secret')]);
        $this->worker = User::create(['name' => 'Walt Worker', 'email' => 'walt@example.com', 'password' => bcrypt('secret')]);
        $this->second = User::create(['name' => 'Sam Second', 'email' => 'sam@example.com', 'password' => bcrypt('secret')]);
        $this->bystander = User::create(['name' => 'Ben Bystander', 'email' => 'ben@example.com', 'password' => bcrypt('secret')]);

        $this->project = Project::create(['user_id' => $this->manager->id, 'name' => 'Delivery', 'status' => 'in_progress']);
        $this->project->users()->attach([$this->worker->id, $this->second->id, $this->bystander->id]);
    }

    private function task(?User $owner = null): Task
    {
        return Task::create([
            'user_id' => ($owner ?? $this->manager)->id,
            'project_id' => $this->project->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
            'status' => 'to_do',
        ]);
    }

    public function test_creating_a_task_for_someone_notifies_them(): void
    {
        Notification::fake();

        $this->actingAs($this->manager)->post('/tasks', [
            'project_id' => $this->project->id,
            'user_id' => $this->worker->id,
            'title' => 'Ship the invoice run',
            'priority' => 'high',
            'status' => 'to_do',
        ])->assertRedirect();

        Notification::assertSentTo($this->worker, TaskAssignedNotification::class);
    }

    public function test_creating_a_task_for_yourself_notifies_nobody(): void
    {
        Notification::fake();

        $this->actingAs($this->manager)->post('/tasks', [
            'project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'title' => 'My own errand',
            'priority' => 'low',
            'status' => 'to_do',
        ])->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_reassigning_a_task_notifies_the_new_owner(): void
    {
        $task = $this->task();
        Notification::fake();

        $this->actingAs($this->manager)->put("/tasks/{$task->id}", [
            'title' => $task->title,
            'priority' => 'high',
            'status' => 'to_do',
            'user_id' => $this->worker->id,
        ])->assertRedirect();

        $this->assertSame($this->worker->id, $task->fresh()->user_id);
        Notification::assertSentTo($this->worker, TaskAssignedNotification::class);
    }

    public function test_editing_a_task_without_changing_the_owner_notifies_nobody(): void
    {
        $task = $this->task($this->worker);
        Notification::fake();

        $this->actingAs($this->manager)->put("/tasks/{$task->id}", [
            'title' => 'A new title',
            'priority' => 'low',
            'status' => 'to_do',
            'user_id' => $this->worker->id,
        ])->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_a_task_created_outside_a_request_notifies_nobody(): void
    {
        Notification::fake();

        // Seeders and console commands have no actor — nobody handed this over.
        $this->task($this->worker);

        Notification::assertNothingSent();
    }

    public function test_a_comment_notifies_the_owner_and_the_assignees(): void
    {
        $task = $this->task($this->worker);
        $task->assignees()->attach($this->second->id);
        Notification::fake();

        $this->actingAs($this->manager)
            ->postJson("/tasks/{$task->id}/comments", ['body' => '<p>Any progress?</p>'])
            ->assertSuccessful();

        Notification::assertSentTo($this->worker, TaskCommentedNotification::class);
        Notification::assertSentTo($this->second, TaskCommentedNotification::class);
    }

    public function test_a_comment_does_not_notify_its_own_author(): void
    {
        $task = $this->task($this->worker);
        Notification::fake();

        $this->actingAs($this->worker)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'Working on it'])
            ->assertSuccessful();

        Notification::assertNotSentTo($this->worker, TaskCommentedNotification::class);
    }

    public function test_a_comment_does_not_notify_project_members_who_are_not_on_the_task(): void
    {
        $task = $this->task($this->worker);
        Notification::fake();

        $this->actingAs($this->manager)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'Bumping this'])
            ->assertSuccessful();

        // Ben is on the project but has never touched this task.
        Notification::assertNotSentTo($this->bystander, TaskCommentedNotification::class);
    }

    public function test_someone_who_commented_earlier_is_kept_in_the_loop(): void
    {
        $task = $this->task($this->manager);

        $this->actingAs($this->second)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'Question about scope'])
            ->assertSuccessful();

        Notification::fake();

        $this->actingAs($this->manager)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'Answered below'])
            ->assertSuccessful();

        Notification::assertSentTo($this->second, TaskCommentedNotification::class);
    }

    public function test_working_out_the_followers_never_mixes_distinct_with_an_order(): void
    {
        // MySQL refuses "select distinct user_id ... order by created_at" and
        // SQLite waves it through, so this only ever broke in production. The
        // assertion is on the SQL because the test database cannot fail on it.
        $task = $this->task($this->worker);

        $this->actingAs($this->second)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'First'])
            ->assertSuccessful();

        $seen = [];
        DB::listen(function ($query) use (&$seen): void {
            $seen[] = strtolower($query->sql);
        });

        $this->actingAs($this->manager)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'Second'])
            ->assertSuccessful();

        foreach ($seen as $sql) {
            $this->assertFalse(
                str_contains($sql, 'distinct') && str_contains($sql, 'order by'),
                'This query is a 500 on MySQL: '.$sql
            );
        }
    }

    public function test_a_mentioned_person_gets_the_mention_and_not_a_second_notification(): void
    {
        $task = $this->task($this->worker);
        Notification::fake();

        // Walt owns the task *and* is named in the comment. One notification.
        $this->actingAs($this->manager)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'Over to you @walt'])
            ->assertSuccessful();

        Notification::assertSentTo($this->worker, MentionedInCommentNotification::class);
        Notification::assertNotSentTo($this->worker, TaskCommentedNotification::class);
    }

    public function test_an_attachment_only_comment_still_notifies(): void
    {
        $task = $this->task($this->worker);
        Notification::fake();

        $this->actingAs($this->manager)->postJson("/tasks/{$task->id}/comments", [
            'attachments' => [UploadedFile::fake()->create('spec.pdf', 12, 'application/pdf')],
        ])->assertSuccessful();

        Notification::assertSentTo($this->worker, TaskCommentedNotification::class);
    }

    public function test_the_comment_email_says_who_said_what_and_where(): void
    {
        $task = $this->task($this->worker);

        $this->actingAs($this->manager)
            ->postJson("/tasks/{$task->id}/comments", ['body' => '<p>Blocked on <strong>billing</strong></p>'])
            ->assertSuccessful();

        $comment = $task->comments()->latest('id')->first();
        $mail = (new TaskCommentedNotification($comment))->toMail($this->worker);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Mary Manager', $mail->subject);
        $this->assertStringContainsString('Ship the invoice run', $mail->subject);
        // The formatting the author applied survives into the email.
        $this->assertStringContainsString('<strong>billing</strong>', $html);
        $this->assertStringContainsString(route('tasks.show', $task->id), $html);
    }

    public function test_an_attachment_only_comment_email_does_not_render_an_empty_quote(): void
    {
        $task = $this->task($this->worker);

        $this->actingAs($this->manager)->postJson("/tasks/{$task->id}/comments", [
            'attachments' => [UploadedFile::fake()->create('spec.pdf', 12, 'application/pdf')],
        ])->assertSuccessful();

        $comment = $task->comments()->latest('id')->first();
        $html = (string) (new TaskCommentedNotification($comment))->toMail($this->worker)->render();

        $this->assertStringContainsString('attached a file', $html);
    }

    public function test_the_bell_lists_a_comment_notification(): void
    {
        $task = $this->task($this->worker);

        $this->actingAs($this->manager)
            ->postJson("/tasks/{$task->id}/comments", ['body' => 'Please take a look'])
            ->assertSuccessful();

        $this->actingAs($this->worker)
            ->get('/notifications')
            ->assertSuccessful()
            ->assertSee('Mary Manager commented on "Ship the invoice run"');
    }
}
