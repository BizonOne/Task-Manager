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

    /**
     * The status row this task's `status` key points at.
     */
    public function taskStatus()
    {
        return $this->belongsTo(TaskStatus::class, 'status', 'key');
    }

    /**
     * Human label for this task's status.
     */
    public function getStatusLabelAttribute(): string
    {
        return TaskStatus::labelFor($this->status);
    }

    /**
     * Whether this task sits in a status that counts as finished.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, TaskStatus::completedKeys(), true);
    }

    /**
     * Limit to tasks that are finished.
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', TaskStatus::completedKeys());
    }

    /**
     * Limit to tasks that are not finished yet.
     */
    public function scopeNotCompleted($query)
    {
        return $query->whereNotIn('status', TaskStatus::completedKeys());
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
        return $user->isSuperAdmin()
            || $this->participants()->contains('id', $user->id);
    }

    /**
     * Whether the given user may edit or delete this task: its creator, or a
     * user who manages the parent project (owner or project manager).
     */
    public function isManageableBy(User $user): bool
    {
        return $user->isSuperAdmin()
            || $this->user_id === $user->id
            || ($this->project && $this->project->isManagedBy($user));
    }
}
