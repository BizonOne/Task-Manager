<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\AddedToProjectNotification;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\TaskAssignedNotification;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('secret')]);
        $member = User::create(['name' => 'Member', 'email' => 'member@example.com', 'password' => bcrypt('secret')]);
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Proj', 'status' => 'in_progress']);
        $task = Task::create(['user_id' => $owner->id, 'project_id' => $project->id, 'title' => 'Do it', 'priority' => 'high', 'status' => 'to_do']);

        return compact('owner', 'member', 'project', 'task');
    }

    public function test_all_collaboration_notifications_use_database_and_mail(): void
    {
        ['owner' => $owner, 'member' => $member, 'project' => $project, 'task' => $task] = $this->scaffold();
        $comment = TaskComment::create(['task_id' => $task->id, 'user_id' => $owner->id, 'body' => 'hi @member']);

        $notifications = [
            new TaskAssignedNotification($task, $owner),
            new MentionedInCommentNotification($comment),
            new AddedToProjectNotification($project, $owner),
        ];

        foreach ($notifications as $notification) {
            $this->assertEqualsCanonicalizing(['database', 'mail'], $notification->via($member), $notification::class);
        }
    }

    public function test_mail_messages_render_with_subject_and_action(): void
    {
        ['owner' => $owner, 'member' => $member, 'project' => $project, 'task' => $task] = $this->scaffold();
        $comment = TaskComment::create(['task_id' => $task->id, 'user_id' => $owner->id, 'body' => 'ping @member']);

        $assigned = (new TaskAssignedNotification($task, $owner))->toMail($member);
        $this->assertInstanceOf(MailMessage::class, $assigned);
        $this->assertStringContainsString('assigned', strtolower($assigned->subject));
        $this->assertSame(route('tasks.show', $task->id), $assigned->actionUrl);

        $mentioned = (new MentionedInCommentNotification($comment))->toMail($member);
        $this->assertStringContainsString('mention', strtolower($mentioned->subject));
        $this->assertSame(route('tasks.show', $task->id), $mentioned->actionUrl);

        $added = (new AddedToProjectNotification($project, $owner))->toMail($member);
        $this->assertStringContainsString('project', strtolower($added->subject));
        $this->assertSame(route('projects.show', $project), $added->actionUrl);
    }

    public function test_notifier_swallows_delivery_failures(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => bcrypt('secret')]);

        // A notification routed to a non-existent channel throws when sent;
        // Notifier::send must catch it so the caller's action still succeeds.
        $throwing = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['a-channel-that-does-not-exist'];
            }
        };

        Notifier::send($user, $throwing);

        $this->assertTrue(true, 'Notifier::send did not propagate the delivery failure.');
    }
}
