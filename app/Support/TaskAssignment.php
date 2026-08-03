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

        return true;
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
