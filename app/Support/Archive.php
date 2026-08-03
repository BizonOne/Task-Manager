<?php

namespace App\Support;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Moving finished work off the boards without losing it.
 *
 * Archiving is not deleting. An archived task still opens, still holds its
 * links, files and discussion, and still counts in reports — it just stops
 * taking up room in front of people working today.
 */
class Archive
{
    /** The settings key holding how long a finished task stays on the board. */
    public const SETTING = 'archive.after_days';

    public const DEFAULT_DAYS = 30;

    /**
     * Days a task stays visible after it is finished. Null means never archive
     * on a timer — the buttons still work.
     */
    public static function afterDays(): ?int
    {
        $value = Brand::get(self::SETTING);

        if ($value === null || trim($value) === '') {
            return self::DEFAULT_DAYS;
        }

        $days = (int) $value;

        return $days > 0 ? $days : null;
    }

    public static function setAfterDays(?int $days): void
    {
        Brand::set(self::SETTING, $days === null ? '0' : (string) $days);
    }

    /**
     * Put a task in the archive. Returns false if it was already there.
     */
    public static function archive(Task $task): bool
    {
        if ($task->archived_at !== null) {
            return false;
        }

        $task->archived_at = CarbonImmutable::now();
        $task->saveQuietly();

        TaskActivity::record($task, TaskActivity::EVENT_ARCHIVED);

        return true;
    }

    /**
     * Take it back out.
     */
    public static function restore(Task $task): bool
    {
        if ($task->archived_at === null) {
            return false;
        }

        $task->archived_at = null;
        $task->saveQuietly();

        TaskActivity::record($task, TaskActivity::EVENT_UNARCHIVED);

        return true;
    }

    /**
     * The tasks a sweep would take. Split out so `--dry-run` counts exactly
     * what the real run would move, rather than something close to it.
     */
    public static function due(?int $afterDays = null): Builder
    {
        $days = $afterDays ?? self::afterDays() ?? 0;

        return Task::query()
            ->whereNull('archived_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', CarbonImmutable::now()->subDays($days))
            // Belt and braces: a task whose status was changed without going
            // through the observer should not be archived while still open.
            ->whereIn('status', TaskStatus::completedKeys());
    }

    /**
     * The scheduled sweep: archive everything finished longer ago than the
     * configured window.
     *
     * Deliberately runs without an actor — Auth::id() is null in the scheduler,
     * so the history reads "System archived this task", which is the truth.
     *
     * @return int how many tasks were archived
     */
    public static function sweep(?int $afterDays = null): int
    {
        $days = $afterDays ?? self::afterDays();

        if ($days === null) {
            return 0;
        }

        $archived = 0;

        self::due($days)
            ->chunkById(200, function ($tasks) use (&$archived) {
                foreach ($tasks as $task) {
                    if (self::archive($task)) {
                        $archived++;
                    }
                }
            });

        return $archived;
    }

    /**
     * Whether the signed-in user may archive or restore this task. Same rule as
     * editing it — archiving is an edit, not a deletion.
     */
    public static function isManageableBy(Task $task): bool
    {
        $user = Auth::user();

        return $user !== null && $task->isManageableBy($user);
    }
}
