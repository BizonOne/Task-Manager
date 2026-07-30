<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Brand;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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
        $task = $this->task;
        $project = $task->project;
        $taskUrl = route('tasks.show', $task->id);
        $due = $task->due_date ? Carbon::parse($task->due_date) : null;

        return (new MailMessage)
            ->subject($this->assignedBy->name.' assigned you to "'.Str::limit($task->title, 60).'" · '.Brand::name())
            ->view('emails.task-assigned', [
                'recipient' => $notifiable,
                'author' => $this->assignedBy,
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
                    // Flag an overdue task rather than showing a bare past date.
                    ['label' => 'Due date', 'value' => $due
                        ? $due->format('D, j M Y').($due->isPast() && ! $task->isCompleted() ? ' — overdue' : '')
                        : null],
                    ['label' => 'Estimate', 'value' => $task->estimated_hours ? $task->estimated_hours.' h' : null],
                    ['label' => 'Assigned by', 'value' => $this->assignedBy->name],
                ],
            ]);
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
