<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Notifications\Concerns\GoesWherePeopleAre;
use App\Support\Brand;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AddedToProjectNotification extends Notification implements ShouldQueue
{
    use GoesWherePeopleAre, Queueable;

    public function __construct(
        public Project $project,
        public User $addedBy,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->project;

        // The recipient's role on this project decides what they may do, so it
        // belongs in the email rather than being something they discover later.
        $role = $project->users()
            ->where('users.id', $notifiable->getKey())
            ->first()?->pivot?->role ?? 'member';
        $isManager = $role === 'manager';

        $openTasks = $project->tasks()->notCompleted()->count();
        $totalTasks = $project->tasks()->count();

        return (new MailMessage)
            ->subject($this->addedBy->name.' added you to "'.Str::limit($project->name, 60).'" · '.Brand::name())
            ->view('emails.added-to-project', [
                'recipient' => $notifiable,
                'author' => $this->addedBy,
                'project' => $project,
                'isManager' => $isManager,
                'projectUrl' => route('projects.show', $project),
                'boardUrl' => route('projects.tasks.index', $project),
                'rows' => [
                    ['label' => 'Project', 'value' => $project->name, 'url' => route('projects.show', $project)],
                    ['label' => 'Your role', 'value' => $isManager ? 'Manager — can edit the project and manage members' : 'Member'],
                    ['label' => 'Owner', 'value' => $project->user?->name],
                    ['label' => 'Open tasks', 'value' => $totalTasks > 0 ? $openTasks.' of '.$totalTasks : 'No tasks yet'],
                    ['label' => 'Deadline', 'value' => $project->end_date
                        ? Carbon::parse($project->end_date)->format('D, j M Y')
                        : null],
                    ['label' => 'Added by', 'value' => $this->addedBy->name],
                ],
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project_added',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'by' => $this->addedBy->name,
            'message' => "{$this->addedBy->name} added you to the project \"{$this->project->name}\"",
            'url' => route('projects.show', $this->project),
        ];
    }
}
