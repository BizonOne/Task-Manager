<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\Task;
use App\Models\User;

/**
 * Turning whatever a person pasted into a task.
 *
 * People do not hand an agent "78" — they hand it a link from the address
 * bar, or the TASK-0078 key printed on the page. All three name the same
 * row, so all three work.
 */
trait ResolvesTasks
{
    /**
     * Find the task a reference names, if its viewer may see it.
     *
     * One answer for "no such task" and "not yours to see", on purpose: an
     * agent probing ids should not learn which of the two it hit.
     */
    protected function resolveTask(string $reference, User $user): ?Task
    {
        // A URL names the task in its path even when it does not end there
        // (/tasks/78/edit); anything else — TASK-0078, plain 78 — ends in
        // the number.
        if (! preg_match('~/tasks/(\d+)~', $reference, $m)
            && ! preg_match('/(\d+)\s*$/', trim($reference), $m)) {
            return null;
        }

        $task = Task::find((int) $m[1]);

        return $task && $task->isAccessibleBy($user) ? $task : null;
    }
}
