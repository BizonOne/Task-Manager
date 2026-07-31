<?php

namespace App\Notifications;

use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Support\Brand;
use App\Support\RichText;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class MentionedInCommentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public TaskComment $comment,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->comment->task;
        $author = $this->comment->user;
        $project = $task->project;
        $taskUrl = route('tasks.show', $task->id);

        return (new MailMessage)
            // Name the person and the task in the subject — that is what makes
            // the mail scannable in a busy inbox.
            ->subject($author->name.' mentioned you on "'.Str::limit($task->title, 60).'" · '.Brand::name())
            ->view('emails.mentioned-in-comment', [
                'recipient' => $notifiable,
                'comment' => $this->comment,
                'author' => $author,
                'task' => $task,
                'project' => $project,
                'taskUrl' => $taskUrl,
                'projectUrl' => $project ? route('projects.show', $project) : null,
                'boardUrl' => $project ? route('projects.tasks.index', $project) : route('tasks.index'),
                'rows' => [
                    ['label' => 'Task', 'value' => $task->title, 'url' => $taskUrl],
                    ['label' => 'Project', 'value' => $project?->name, 'url' => $project ? route('projects.show', $project) : null],
                    ['label' => 'Status', 'value' => TaskStatus::labelFor($task->status)],
                    ['label' => 'Priority', 'value' => ucfirst((string) $task->priority)],
                    ['label' => 'Due date', 'value' => $task->due_date
                        ? Carbon::parse($task->due_date)->format('D, j M Y')
                        : null],
                ],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $task = $this->comment->task;
        $author = $this->comment->user;

        return [
            'type' => 'comment_mention',
            'task_id' => $task->id,
            'task_title' => $task->title,
            'comment_id' => $this->comment->id,
            'by' => $author->name,
            'excerpt' => RichText::toText($this->comment->body, 120),
            'message' => "{$author->name} mentioned you on \"{$task->title}\"",
            'url' => route('tasks.show', $task->id),
        ];
    }
}
