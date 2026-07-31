<?php

namespace App\Models;

use App\Support\RichText;
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
     * Task descriptions are rich text, stored as HTML and rendered unescaped.
     *
     * Living on the model means every write is covered — the web forms, the
     * admin panel and the assistant's tools alike.
     */
    public function setDescriptionAttribute(?string $value): void
    {
        $this->attributes['description'] = RichText::clean($value);
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
     * The task's audit trail, oldest first.
     */
    public function activities()
    {
        return $this->hasMany(TaskActivity::class)->oldest();
    }

    /**
     * Activities and comments merged into one chronological history — what a
     * person means by "the timeline of this task".
     *
     * Ordering follows the activity log's own sequence rather than wall-clock
     * timestamps: several changes plus a comment can land in the same second,
     * and only the log's autoincrement tells them apart reliably.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function timeline(): Collection
    {
        $this->loadMissing(['activities.user', 'comments.user']);

        $commentsById = $this->comments->keyBy('id');
        $shownCommentIds = [];

        $entries = $this->activities
            ->sortBy('id')
            ->map(function (TaskActivity $activity) use ($commentsById, &$shownCommentIds): ?array {
                $entry = [
                    'type' => 'activity',
                    'at' => $activity->created_at,
                    'actor' => $activity->actor_name,
                    'text' => $activity->description,
                    'icon' => $activity->icon,
                    'body' => null,
                ];

                if ($activity->event !== TaskActivity::EVENT_COMMENTED) {
                    return $entry;
                }

                // Show the comment itself in place of the bare marker. A
                // comment that has since been deleted leaves only its
                // "comment deleted" entry, so drop this one.
                $comment = $commentsById->get($activity->meta['comment_id'] ?? null);
                if ($comment === null) {
                    return null;
                }

                $shownCommentIds[] = $comment->id;

                return [
                    'type' => 'comment',
                    'at' => $comment->created_at,
                    'actor' => $comment->user?->name ?? 'Unknown',
                    'text' => 'commented',
                    'icon' => 'chat-dots',
                    'body' => $comment->body,
                ];
            })
            ->filter()
            ->values();

        // Comments made before the activity log existed have no matching entry;
        // fold them in by timestamp so old discussions still show up.
        $orphans = $this->comments
            ->reject(fn (TaskComment $c) => in_array($c->id, $shownCommentIds, true))
            ->map(fn (TaskComment $c) => [
                'type' => 'comment',
                'at' => $c->created_at,
                'actor' => $c->user?->name ?? 'Unknown',
                'text' => 'commented',
                'icon' => 'chat-dots',
                'body' => $c->body,
            ]);

        return $orphans->isEmpty()
            ? $entries
            : $entries->concat($orphans)->sortBy('at')->values();
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
