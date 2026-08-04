<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use RuntimeException;

/**
 * A project taking its board into its own hands, and giving it back.
 *
 * Everything here exists to keep one promise: no task is ever left standing in
 * a column that does not exist. A task whose status names nothing appears on
 * no board and in no column, which is the same as losing it.
 */
class BoardColumns
{
    /**
     * Give the project its own copy of the shared columns.
     *
     * Copied rather than switched to an empty set, so customising a board
     * changes nothing at all until somebody edits something. Every task keeps
     * the column it was in, because the keys are the same.
     */
    public static function adopt(Project $project): void
    {
        if (TaskStatus::isCustomised($project->id)) {
            return;
        }

        foreach (TaskStatus::ordered() as $shared) {
            TaskStatus::create([
                'project_id' => $project->id,
                'key' => $shared->key,
                'label' => $shared->label,
                'color' => $shared->color,
                'sort_order' => $shared->sort_order,
                'is_completed' => $shared->is_completed,
                'is_default' => $shared->is_default,
            ]);
        }

        TaskStatus::forgetCached();
    }

    /**
     * Put the project back on the shared columns.
     */
    public static function release(Project $project): void
    {
        if (! TaskStatus::isCustomised($project->id)) {
            return;
        }

        TaskStatus::where('project_id', $project->id)->delete();
        TaskStatus::forgetCached();

        // Anything that was in a column this project invented has nowhere to
        // stand now.
        self::rehome($project, TaskStatus::keys(), TaskStatus::defaultKey());
    }

    /**
     * Remove one of a project's columns, and move whatever was in it.
     *
     * @throws RuntimeException when it is the last column on the board
     */
    public static function remove(TaskStatus $status): void
    {
        if (! $status->isPrivate()) {
            throw new RuntimeException('Shared columns are managed in the admin panel, not on a project.');
        }

        $project = $status->project;
        $remaining = TaskStatus::ordered($status->project_id)->where('id', '!=', $status->id)->values();

        if ($remaining->isEmpty()) {
            throw new RuntimeException('A board needs at least one column.');
        }

        $target = ($remaining->firstWhere('is_default', true) ?? $remaining->first())->key;

        $status->delete();
        TaskStatus::forgetCached();

        if ($project !== null) {
            self::rehome($project, $remaining->pluck('key')->all(), $target);
        }
    }

    /**
     * Where a task lands when it arrives on a board that has never heard of
     * the column it was in.
     */
    public static function keyFor(Task $task): string
    {
        $keys = TaskStatus::keys($task->project_id);

        return in_array($task->status, $keys, true)
            ? $task->status
            : TaskStatus::defaultKey($task->project_id);
    }

    /**
     * Move every task in this project that is standing on a key no longer on
     * its board.
     *
     * Written straight to the database rather than through the model: this is
     * bookkeeping after somebody rearranged the board, and thirty people do
     * not need an email saying their task moved.
     *
     * @param  array<int, string>  $keep
     */
    private static function rehome(Project $project, array $keep, string $target): void
    {
        $stranded = Task::where('project_id', $project->id)->whereNotIn('status', $keep);

        if ($stranded->clone()->doesntExist()) {
            return;
        }

        $finished = in_array($target, TaskStatus::completedKeys($project->id), true);

        $stranded->update([
            'status' => $target,
            // Kept honest by hand, since the observer is not watching: the
            // archive counts from this and a wrong date files work away early.
            'completed_at' => $finished ? now() : null,
        ]);
    }
}
