<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AddedToProjectNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Project $project,
        public User $addedBy,
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
            ->subject('You were added to a project · '.Brand::name())
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->addedBy->name} added you to the project \"{$this->project->name}\".")
            ->action('Open project', route('projects.show', $this->project))
            ->line('You can now see the project and work on its tasks.');
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
