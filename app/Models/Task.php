<?php

namespace App\Models;

use App\Support\Permissions;
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

    protected $casts = [
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who raised the task — not necessarily who it is for.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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
        return TaskStatus::labelFor($this->status, $this->project_id);
    }

    /**
     * Whether this task sits in a status that counts as finished.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, TaskStatus::completedKeys($this->project_id), true);
    }

    /**
     * Limit to the tasks a person may see: their own, ones they are assigned
     * to, and everything in projects they own or belong to.
     *
     * isAccessibleBy() answers this for a single task; a report needs it as a
     * WHERE clause, or it would load every row in the database to filter in PHP.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('tasks.user_id', $user->id)
                ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $user->id))
                ->orWhereHas('project', fn ($p) => $p
                    ->where('projects.user_id', $user->id)
                    ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id)));
        });
    }

    /**
     * Limit to the tasks a person is personally on: they raised it, or they
     * were assigned to it.
     *
     * Narrower than visibleTo() on purpose — it leaves out the rest of the
     * work in projects they merely belong to.
     */
    public function scopeInvolving($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('tasks.user_id', $user->id)
                ->orWhere('tasks.created_by', $user->id)
                ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $user->id));
        });
    }

    /**
     * What a person's reports and archive cover.
     *
     * A member accounts for their own work — what they raised and what they
     * were given — not for everything happening in a project they are on.
     * Belonging to a project is a reason to see its board, not a reason to
     * pull colleagues' tasks into your report.
     *
     * Admins oversee, so they keep the wider view.
     */
    public function scopeAccountableTo($query, User $user)
    {
        return $user->oversees()
            ? $query->visibleTo($user)
            : $query->involving($user);
    }

    /**
     * Limit to tasks that are finished.
     */
    public function scopeCompleted($query)
    {
        // Every board's idea of finished: a query spanning projects cannot ask
        // each one in turn, and a completed task left out of "completed" is
        // worse than one wrongly counted in.
        return $query->whereIn('status', TaskStatus::completedKeysEverywhere());
    }

    /**
     * Limit to tasks that are not finished yet.
     */
    public function scopeNotCompleted($query)
    {
        return $query->whereNotIn('status', TaskStatus::completedKeysEverywhere());
    }

    /**
     * Limit to tasks that are still on the boards.
     *
     * This is a scope rather than a global one on purpose: a global scope would
     * 404 an archived task opened by its own link, and would quietly drop
     * archived work out of reports. The boards ask for `active()`; everything
     * else keeps seeing everything.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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
     * The words somebody has written on this task.
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'task_tag')->orderBy('name');
    }

    /**
     * This task's answers to the fields its project defined.
     */
    public function fieldValues()
    {
        return $this->hasMany(TaskFieldValue::class);
    }

    /**
     * The project's fields paired with this task's answers, in the project's
     * own order — including the ones left blank, so a form can render them all.
     *
     * @return Collection<int, array{field: ProjectField, value: array<int, string>}>
     */
    public function fieldAnswers(): Collection
    {
        $this->loadMissing(['project.fields', 'fieldValues']);

        $answers = $this->fieldValues->keyBy('project_field_id');

        return collect($this->project?->fields ?? [])->map(fn (ProjectField $field) => [
            'field' => $field,
            'value' => $answers->get($field->id)?->list() ?? [],
        ])->values();
    }

    /**
     * What a card should show: the answered fields the project wants on one.
     *
     * @return Collection<int, array{name: string, text: string}>
     */
    public function fieldChips(): Collection
    {
        return $this->fieldAnswers()
            ->filter(fn (array $a) => $a['value'] !== [] && $a['field']->show_on_card)
            ->map(fn (array $a) => ['name' => $a['field']->name, 'text' => implode(', ', $a['value'])])
            ->values();
    }

    /**
     * Everything attached to this task, including files posted in the
     * discussion — the task page is the one place to look for them.
     */
    public function files()
    {
        return $this->hasMany(File::class)->latest();
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
        $this->loadMissing(['activities.user', 'comments.user', 'comments.files']);

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
                    'files' => $comment->files,
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
                'files' => $c->files,
            ]);

        return $orphans->isEmpty()
            ? $entries
            : $entries->concat($orphans)->sortBy('at')->values();
    }

    /**
     * Links created from this task.
     */
    public function outgoingLinks()
    {
        return $this->hasMany(TaskLink::class, 'task_id');
    }

    /**
     * Links other tasks created towards this one.
     */
    public function incomingLinks()
    {
        return $this->hasMany(TaskLink::class, 'linked_task_id');
    }

    /**
     * Every relation this task takes part in, worded from its own side and
     * grouped by that wording — "blocks", "is blocked by", "relates to".
     *
     * Only links to tasks the viewer may see are returned: a relation is not a
     * way to learn the titles of work on projects you have no part in.
     *
     * @return Collection<string, Collection<int, array{link: TaskLink, task: Task}>>
     */
    public function groupedLinks(User $viewer): Collection
    {
        $this->loadMissing([
            'outgoingLinks.linkedTask.project',
            'incomingLinks.task.project',
        ]);

        return $this->outgoingLinks
            ->concat($this->incomingLinks)
            ->map(fn (TaskLink $link) => [
                'link' => $link,
                'label' => $link->labelFor($this),
                'task' => $link->otherEnd($this),
            ])
            ->filter(fn (array $entry) => $entry['task'] !== null
                && $entry['task']->isAccessibleBy($viewer))
            ->sortBy(fn (array $entry) => $entry['task']->id)
            ->groupBy('label');
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
        return Permissions::allows($user, 'view', $this);
    }

    /**
     * Whether the given user may edit or delete this task: its creator, or a
     * user who manages the parent project (owner or project manager).
     */
    public function isManageableBy(User $user): bool
    {
        // A project manager keeps their say over the project's work regardless
        // of the matrix — managing the project is what the role is for.
        return Permissions::allows($user, 'edit', $this)
            || ($this->project && $this->project->isManagedBy($user));
    }
}
