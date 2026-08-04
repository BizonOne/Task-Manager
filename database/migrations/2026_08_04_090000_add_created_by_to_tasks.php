<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Who raised the task. `user_id` is who it is *for*, which is a
            // different person whenever a manager creates work for someone —
            // and until now the person who raised it was not recorded at all,
            // so they could not edit what they had just written.
            $table->foreignId('created_by')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }

    /**
     * The activity log records who created each task, so for everything raised
     * since the timeline shipped this is the real author. Older rows fall back
     * to the assignee — the best available guess, and the same person the old
     * rule already trusted.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('task_activities')) {
            DB::table('tasks')->whereNull('created_by')->update(['created_by' => DB::raw('user_id')]);

            return;
        }

        foreach (DB::table('tasks')->whereNull('created_by')->get(['id', 'user_id']) as $task) {
            $author = DB::table('task_activities')
                ->where('task_id', $task->id)
                ->where('event', 'created')
                ->whereNotNull('user_id')
                ->orderBy('id')
                ->value('user_id');

            DB::table('tasks')->where('id', $task->id)
                ->update(['created_by' => $author ?? $task->user_id]);
        }
    }
};
