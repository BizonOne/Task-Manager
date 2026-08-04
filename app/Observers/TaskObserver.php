<?php

namespace App\Observers;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Writes a task's history and tells the owner when work lands on them.
 *
 * Living on the model's events means every edit is captured — the front-end
 * forms, the kanban drag-and-drop and the admin panel alike — instead of each
 * controller having to remember to log and notify.
 */
class TaskObserver
{
    /**
     * Fields worth tracking. Anything else (timestamps, slugs) is noise.
     */
    private const TRACKED = [
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'estimated_hours',
        'project_id',
        'user_id',
    ];

    /**
     * Keep `completed_at` honest, in the same write as the change that moved it.
     *
     * The column exists because `updated_at` cannot answer "finished 30 days
     * ago" — it moves on any edit at all.
     */
    public function saving(Task $task): void
    {
        if (! $task->isDirty('status')) {
            return;
        }

        $isCompleted = in_array($task->status, TaskStatus::completedKeys(), true);

        if ($isCompleted) {
            $task->completed_at ??= CarbonImmutable::now();

            return;
        }

        $task->completed_at = null;

        // Work that was reopened belongs back on the board. Leaving it archived
        // would mean a task in progress that nobody can see.
        $task->archived_at = null;
    }

    public function creating(Task $task): void
    {
        // Who raised it, recorded once. Deliberately not fillable, so a form
        // cannot claim authorship of somebody else's task.
        $task->created_by ??= Auth::id();
    }

    public function created(Task $task): void
    {
        TaskActivity::record($task, TaskActivity::EVENT_CREATED);

        // A task raised for somebody else is the moment they need to hear about
        // it — waiting for them to notice it on a board is not a handover.
        $this->notifyOwner($task);
    }

    public function updated(Task $task): void
    {
        // Reassignment is the same handover, later in the task's life.
        if ($task->wasChanged('user_id')) {
            $this->notifyOwner($task);
        }

        // A reopened task comes back from the archive above; say so in the
        // history, or it silently reappears on the board.
        if ($task->wasChanged('archived_at') && $task->archived_at === null) {
            TaskActivity::record($task, TaskActivity::EVENT_UNARCHIVED);
        }

        if ($task->wasChanged('status')) {
            $this->announceStatusChange($task);
        }

        foreach (self::TRACKED as $field) {
            if (! $task->wasChanged($field)) {
                continue;
            }

            $old = $task->getOriginal($field);
            $new = $task->getAttribute($field);

            // Dates come back as Carbon; store a stable string either way.
            TaskActivity::record($task, TaskActivity::EVENT_UPDATED, [
                'field' => $field,
                'old_value' => $this->stringify($old),
                'new_value' => $this->stringify($new),
            ]);
        }
    }

    /**
     * Tell the people waiting on this task that it moved.
     *
     * The person who raised the work is the point of this: they asked for it
     * and otherwise have no way of knowing it is done short of opening the
     * board and looking. Whoever it is assigned to hears too, for the same
     * reason in reverse.
     */
    private function announceStatusChange(Task $task): void
    {
        $actor = Auth::user();

        // No actor means a seeder or a console command; nobody moved anything.
        if ($actor === null) {
            return;
        }

        $task->loadMissing('assignees');

        $recipients = collect([$task->created_by, $task->user_id])
            ->merge($task->assignees->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actor->id);

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new TaskStatusChangedNotification(
            $task,
            $actor,
            $task->getOriginal('status'),
            $task->status,
        );

        foreach (User::whereIn('id', $recipients)->get() as $person) {
            Notifier::send($person, $notification);
        }
    }

    /**
     * Tell the task's owner that it is theirs.
     */
    private function notifyOwner(Task $task): void
    {
        $actor = Auth::user();

        // No signed-in actor means a seeder, a console command or a test
        // factory — nobody handed this to anyone, so nobody gets an email.
        if ($actor === null || $task->user_id === null || $task->user_id === $actor->id) {
            return;
        }

        // Read the owner by id rather than through the relation: on an update
        // the loaded relation still holds the *previous* owner.
        $owner = User::find($task->user_id);

        if ($owner !== null) {
            Notifier::send($owner, new TaskAssignedNotification($task, $actor));
        }
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
