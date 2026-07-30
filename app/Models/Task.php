<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'description',
        'due_date',
        'priority',
        'status',
        'estimated_hours',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function getStatusColorAttribute()
    {
        switch ($this->status) {
            case 'to_do':
                return 'primary';
            case 'in_progress':
                return 'warning';
            case 'on_hold':
                return 'secondary';
            case 'in_review':
                return 'info';
            case 'completed':
                return 'success';
            default:
                return 'secondary';
        }
    }

    public function checklistItems()
    {
        return $this->hasMany(ChecklistItem::class);
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class)->oldest();
    }

    /**
     * Users assigned to collaborate on this task.
     */
    public function assignees()
    {
        return $this->belongsToMany(User::class, 'task_user')->withTimestamps();
    }

    /**
     * Everyone who can take part in this task: its owner, the project owner,
     * the project team, and the assignees. Used to scope @mentions and access.
     *
     * @return Collection<int, User>
     */
    public function participants()
    {
        $this->loadMissing(['user', 'assignees', 'project.user', 'project.users']);

        return collect([$this->user, $this->project?->user])
            ->merge($this->assignees)
            ->merge($this->project?->users ?? collect())
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Whether the given user may view and comment on this task.
     */
    public function isAccessibleBy(User $user): bool
    {
        return $this->participants()->contains('id', $user->id);
    }
}
