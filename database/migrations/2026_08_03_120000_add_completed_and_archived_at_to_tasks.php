<?php

use App\Models\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // When the task entered a completed status. Not the same as
            // updated_at, which moves on any edit — which is exactly why
            // "finished 30 days ago" could not be asked before.
            $table->timestamp('completed_at')->nullable()->after('status')->index();

            // When it left the boards. Null means live.
            $table->timestamp('archived_at')->nullable()->after('completed_at')->index();
        });

        $this->backfillCompletedAt();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['completed_at', 'archived_at']);
        });
    }

    /**
     * Give the tasks that are already finished a completion date.
     *
     * The activity log records status changes, so for anything touched since
     * the timeline shipped this is the real moment. Everything older falls back
     * to updated_at — the best guess available, and stated as such rather than
     * left null, which would keep those tasks out of the archive forever.
     */
    private function backfillCompletedAt(): void
    {
        $completedKeys = TaskStatus::completedKeys();

        if ($completedKeys === []) {
            return;
        }

        $tasks = DB::table('tasks')
            ->whereIn('status', $completedKeys)
            ->whereNull('completed_at')
            ->get(['id', 'updated_at']);

        foreach ($tasks as $task) {
            $fromHistory = Schema::hasTable('task_activities')
                ? DB::table('task_activities')
                    ->where('task_id', $task->id)
                    ->where('event', 'updated')
                    ->where('field', 'status')
                    ->whereIn('new_value', $completedKeys)
                    ->orderByDesc('id')
                    ->value('created_at')
                : null;

            DB::table('tasks')
                ->where('id', $task->id)
                ->update(['completed_at' => $fromHistory ?? $task->updated_at]);
        }
    }
};
