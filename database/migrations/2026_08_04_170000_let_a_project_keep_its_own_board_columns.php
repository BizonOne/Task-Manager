<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board columns, per project.
 *
 * Statuses have been shared by every board in the app, which is fine until two
 * teams work differently — and they always do. An onboarding board runs
 * Submitted → Company → Acquirer → Complete; a delivery board runs To Do → In
 * Progress → Done. Neither wants the other's columns on its screen.
 *
 * A row with no project is a shared column, used by every project that has not
 * said otherwise. A project that customises gets its own copies and stops
 * looking at the shared ones — so nothing changes for anyone until somebody
 * asks for it to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('task_statuses', function (Blueprint $table) {
            // A key is unique within its board, not across the whole app: two
            // projects are both allowed a column called "review".
            $table->dropUnique('task_statuses_key_unique');
            $table->unique(['project_id', 'key']);
        });
    }

    public function down(): void
    {
        // Project columns cannot survive the column going away; their tasks
        // keep their key and fall back to the shared set.
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'key']);
        });

        DB::table('task_statuses')->whereNotNull('project_id')->delete();

        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->unique('key');
        });
    }
};
