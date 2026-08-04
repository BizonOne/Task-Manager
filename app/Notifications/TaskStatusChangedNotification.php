<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Brand;
use App\Support\Dates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Somebody moved a task to a different status.
 *
 * The person who raised the work is the one waiting to hear this — they asked
 * for it and then have no way of knowing it is done short of opening the board
 * and looking.
 */
class TaskStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public User $changedBy,
        public ?string $from,
        public string $to,
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
        $task = $this->task;
        $project = $task->project;
        $taskUrl = route('tasks.show', $task->id);

        return (new MailMessage)
            ->subject($this->changedBy->name.' moved "'.Str::limit($task->title, 60).'" to '.$this->toLabel().' · '.Brand::name())
            ->view('emails.task-status-changed', [
                'recipient' => $notifiable,
                'author' => $this->changedBy,
                'task' => $task,
                'project' => $project,
                'fromLabel' => $this->fromLabel(),
                'toLabel' => $this->toLabel(),
                'isFinished' => in_array($this->to, TaskStatus::completedKeys($this->task->project_id), true),
                'taskUrl' => $taskUrl,
                'projectUrl' => $project ? route('projects.show', $project) : null,
                'boardUrl' => $project ? route('projects.tasks.index', $project) : route('tasks.index'),
                'rows' => [
                    ['label' => 'Task', 'value' => $task->title, 'url' => $taskUrl],
                    ['label' => 'Project', 'value' => $project?->name, 'url' => $project ? route('projects.show', $project) : null],
                    ['label' => 'Status', 'value' => $this->fromLabel()
                        ? $this->fromLabel().' → '.$this->toLabel()
                        : $this->toLabel()],
                    ['label' => 'Assigned to', 'value' => $task->user?->name],
                    ['label' => 'Changed by', 'value' => $this->changedBy->name],
                    ['label' => 'When', 'value' => Dates::dateTime(now())],
                ],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_status_changed',
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'from' => $this->from,
            'to' => $this->to,
            'by' => $this->changedBy->name,
            'message' => "{$this->changedBy->name} moved \"{$this->task->title}\" to {$this->toLabel()}",
            'url' => route('tasks.show', $this->task->id),
        ];
    }

    private function fromLabel(): ?string
    {
        return $this->from === null ? null : TaskStatus::labelFor($this->from);
    }

    private function toLabel(): string
    {
        return TaskStatus::labelFor($this->to);
    }
}
