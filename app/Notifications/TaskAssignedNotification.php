<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public User $assignedBy,
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
        return (new MailMessage)
            ->subject('You were assigned to a task · '.Brand::name())
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->assignedBy->name} assigned you to the task \"{$this->task->title}\".")
            ->action('View task', route('tasks.show', $this->task->id))
            ->line('You can view the task, update its status and join the discussion.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_assigned',
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'by' => $this->assignedBy->name,
            'message' => "{$this->assignedBy->name} assigned you to \"{$this->task->title}\"",
            'url' => route('tasks.show', $this->task->id),
        ];
    }
}
