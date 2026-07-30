<?php

namespace App\Notifications;

use App\Models\TaskComment;
use App\Support\Brand;
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

        return (new MailMessage)
            ->subject('You were mentioned in a comment · '.Brand::name())
            ->greeting("Hi {$notifiable->name},")
            ->line("{$author->name} mentioned you on the task \"{$task->title}\":")
            ->line('"'.Str::limit($this->comment->body, 200).'"')
            ->action('View discussion', route('tasks.show', $task->id));
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
            'excerpt' => Str::limit($this->comment->body, 120),
            'message' => "{$author->name} mentioned you on \"{$task->title}\"",
            'url' => route('tasks.show', $task->id),
        ];
    }
}
