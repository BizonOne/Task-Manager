<?php

namespace App\Support;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\Auth;

/**
 * Putting someone on a task, or taking them off it.
 *
 * The front-end panel and the admin relation manager both do this, and they
 * used to disagree: one wrote history and sent the notification, the other
 * quietly changed the pivot row. One place, one behaviour.
 */
class TaskAssignment
{
    /**
     * Attach the assignee and announce it.
     *
     * @return bool false when they were already on the task
     */
    public static function attach(Task $task, User $assignee): bool
    {
        // syncWithoutDetaching keeps existing assignees and avoids duplicates.
        $changed = $task->assignees()->syncWithoutDetaching([$assignee->id]);

        if (empty($changed['attached'])) {
            return false;
        }

        self::announceAttached($task, $assignee);
        self::syncPrimary($task);

        return true;
    }

    /**
     * Detach the assignee and record it.
     *
     * @return bool false when they were not on the task to begin with
     */
    public static function detach(Task $task, User $assignee): bool
    {
        if ($task->assignees()->detach($assignee->id) === 0) {
            return false;
        }

        self::announceDetached($task, $assignee);
        self::syncPrimary($task);

        return true;
    }

    /**
     * Keep "Assigned to" pointing at somebody who is actually on the task.
     *
     * The task used to carry two independent ideas of assignment: `user_id`,
     * set once on the create form and never touched again, and the assignee
     * list. They could name different people, and on a real task they did —
     * the page said one name while the Assignees card said another.
     *
     * The list is the truth now. `user_id` is its first entry, kept in step
     * here. Written quietly: the pivot change has already been recorded in the
     * history and already told the person, and a second notification for the
     * same act is noise.
     */
    public static function syncPrimary(Task $task): void
    {
        $task->load('assignees');
        $ids = $task->assignees->pluck('id');

        // Nobody left on it: keep the last known name rather than leaving the
        // task showing no one at all.
        if ($ids->isEmpty()) {
            return;
        }

        if ($ids->contains($task->user_id)) {
            return;
        }

        $task->user_id = $ids->first();
        $task->saveQuietly();
    }

    /**
     * Record and notify for an attachment that has already happened — the
     * admin panel's own action writes the pivot row before we get a say.
     */
    public static function announceAttached(Task $task, User $assignee): void
    {
        TaskActivity::record($task, TaskActivity::EVENT_ASSIGNED, [
            'meta' => ['name' => $assignee->name, 'user_id' => $assignee->id],
        ]);

        $actor = Auth::user();

        // Never self-notify, and never mail anyone for a change made outside a
        // request (a seeder or a console command).
        if ($actor !== null && $assignee->id !== $actor->id) {
            Notifier::send($assignee, new TaskAssignedNotification($task, $actor));
        }
    }

    public static function announceDetached(Task $task, User $assignee): void
    {
        TaskActivity::record($task, TaskActivity::EVENT_UNASSIGNED, [
            'meta' => ['name' => $assignee->name, 'user_id' => $assignee->id],
        ]);
    }
}
