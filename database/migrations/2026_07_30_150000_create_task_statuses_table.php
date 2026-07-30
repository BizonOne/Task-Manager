<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task statuses become admin-manageable rows instead of a hardcoded ENUM.
     *
     * tasks.status keeps holding the status *key* as a plain string, so no task
     * data moves and every existing query keeps working — but the column is no
     * longer an ENUM, which is what made adding a status require a migration.
     */
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('color', 32)->default('gray');
            $table->unsignedInteger('sort_order')->default(0);
            // Marks the column that finished work lands in, so "completed"
            // reporting keeps working when statuses are renamed or added.
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $this->relaxStatusColumn();
    }

    public function down(): void
    {
        Schema::dropIfExists('task_statuses');

        // Restore the original ENUM, parking unknown statuses on to_do first.
        $known = ['to_do', 'in_progress', 'on_hold', 'in_review', 'completed'];
        DB::table('tasks')->whereNotIn('status', $known)->update(['status' => 'to_do']);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $values = implode(',', array_map(fn ($s) => "'{$s}'", $known));
            DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM({$values}) NOT NULL DEFAULT 'to_do'");

            return;
        }

        Schema::table('tasks', function (Blueprint $table) use ($known) {
            $table->enum('status', $known)->default('to_do')->change();
        });
    }

    /**
     * Widen tasks.status from ENUM to a plain string.
     */
    private function relaxStatusColumn(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tasks MODIFY COLUMN status VARCHAR(64) NOT NULL DEFAULT 'to_do'");

            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status', 64)->default('to_do')->change();
        });
    }
};
