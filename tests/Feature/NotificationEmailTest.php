<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\AddedToProjectNotification;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\TaskAssignedNotification;
use App\Support\Brand;
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

    public function test_subjects_name_the_person_and_the_thing(): void
    {
        ['owner' => $owner, 'member' => $member, 'project' => $project, 'task' => $task] = $this->scaffold();
        $comment = TaskComment::create(['task_id' => $task->id, 'user_id' => $owner->id, 'body' => 'ping @member']);

        $assigned = (new TaskAssignedNotification($task, $owner))->toMail($member);
        $this->assertInstanceOf(MailMessage::class, $assigned);
        $this->assertStringContainsString('Owner', $assigned->subject);
        $this->assertStringContainsString('Do it', $assigned->subject);

        $mentioned = (new MentionedInCommentNotification($comment))->toMail($member);
        $this->assertStringContainsString('Owner', $mentioned->subject);
        $this->assertStringContainsString('Do it', $mentioned->subject);

        $added = (new AddedToProjectNotification($project, $owner))->toMail($member);
        $this->assertStringContainsString('Owner', $added->subject);
        $this->assertStringContainsString('Proj', $added->subject);

        // Every subject carries the (brandable) app name.
        foreach ([$assigned, $mentioned, $added] as $mail) {
            $this->assertStringContainsString(Brand::name(), $mail->subject);
        }
    }

    public function test_emails_are_branded_and_link_to_the_task_and_project(): void
    {
        ['owner' => $owner, 'member' => $member, 'project' => $project, 'task' => $task] = $this->scaffold();
        $comment = TaskComment::create(['task_id' => $task->id, 'user_id' => $owner->id, 'body' => 'ping @member']);

        $mails = [
            'assigned' => (new TaskAssignedNotification($task, $owner))->toMail($member),
            'mentioned' => (new MentionedInCommentNotification($comment))->toMail($member),
            'added' => (new AddedToProjectNotification($project, $owner))->toMail($member),
        ];

        foreach ($mails as $name => $mail) {
            $html = (string) $mail->render();

            // Branding: name, primary colour and the recipient addressed by name.
            $this->assertStringContainsString(Brand::name(), $html, $name);
            $this->assertStringContainsString(Brand::primaryColor(), $html, $name);
            $this->assertStringContainsString('Member', $html, $name);

            // Context: who did it and which project.
            $this->assertStringContainsString('Owner', $html, $name);
            $this->assertStringContainsString('Proj', $html, $name);

            // Links: the project is always reachable from the mail.
            $this->assertStringContainsString(route('projects.show', $project), $html, $name);
            $this->assertStringContainsString(route('projects.tasks.index', $project), $html, $name);
        }

        // The task-centric emails link the task itself.
        foreach (['assigned', 'mentioned'] as $name) {
            $html = (string) $mails[$name]->render();
            $this->assertStringContainsString(route('tasks.show', $task->id), $html, $name);
            $this->assertStringContainsString('Do it', $html, $name);
        }
    }

    public function test_mention_email_quotes_the_comment_and_escapes_it(): void
    {
        ['owner' => $owner, 'member' => $member, 'task' => $task] = $this->scaffold();
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $owner->id,
            'body' => "Please check <script>alert(1)</script> line 2\nand the second line @member",
        ]);

        $html = (string) (new MentionedInCommentNotification($comment))->toMail($member)->render();

        // The employee can read what was actually said, without opening the app.
        $this->assertStringContainsString('Please check', $html);
        $this->assertStringContainsString('and the second line', $html);
        // Newlines survive as <br>, but the markup in the comment is escaped.
        $this->assertStringContainsString('<br', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_added_to_project_email_states_the_recipients_role(): void
    {
        ['owner' => $owner, 'member' => $member, 'project' => $project] = $this->scaffold();

        $project->users()->attach($member->id, ['role' => 'manager']);
        $html = (string) (new AddedToProjectNotification($project, $owner))->toMail($member)->render();
        $this->assertStringContainsString('Manager', $html);

        $project->users()->updateExistingPivot($member->id, ['role' => 'member']);
        $html = (string) (new AddedToProjectNotification($project, $owner))->toMail($member->fresh())->render();
        $this->assertStringContainsString('Member', $html);
        $this->assertStringNotContainsString('can edit the project and manage members', $html);
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
