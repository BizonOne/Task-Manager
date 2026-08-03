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

/**
 * Someone posted in a discussion the recipient is part of.
 *
 * The mention notification is the louder of the two — it says "you, specifically".
 * This is the quieter "the conversation moved on" note, and nobody ever gets
 * both for the same comment.
 */
class TaskCommentedNotification extends Notification
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
            ->subject($author->name.' commented on "'.Str::limit($task->title, 60).'" · '.Brand::name())
            ->view('emails.task-commented', [
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
            'type' => 'task_commented',
            'task_id' => $task->id,
            'task_title' => $task->title,
            'comment_id' => $this->comment->id,
            'by' => $author->name,
            'excerpt' => RichText::toText($this->comment->body, 120),
            'message' => "{$author->name} commented on \"{$task->title}\"",
            'url' => route('tasks.show', $task->id),
        ];
    }
}
