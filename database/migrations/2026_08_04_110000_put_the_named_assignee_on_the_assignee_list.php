<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A task carried two independent ideas of who it was on: `user_id`, set
     * once on the create form, and the assignee list. They could name
     * different people, and on real tasks they did.
     *
     * The list is the truth from now on, so the person the task already named
     * joins it. Deliberately *adding* rather than moving the named assignee:
     * somebody being added to a task is not the same as the first person
     * leaving it, and quietly reassigning live work would be worse than the
     * inconsistency this fixes.
     */
    public function up(): void
    {
        $missing = DB::table('tasks')
            ->whereNotNull('user_id')
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('task_user')
                ->whereColumn('task_user.task_id', 'tasks.id')
                ->whereColumn('task_user.user_id', 'tasks.user_id'))
            ->get(['id', 'user_id']);

        $now = now();

        foreach ($missing->chunk(200) as $chunk) {
            DB::table('task_user')->insert($chunk->map(fn ($task) => [
                'task_id' => $task->id,
                'user_id' => $task->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        // Leaving the rows in place: which of them this migration added is not
        // recoverable, and removing a real assignment would be worse than
        // leaving a redundant one.
    }
};
