<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setStatuses(['to_do', 'in_progress', 'on_hold', 'in_review', 'completed']);
    }

    public function down(): void
    {
        // Move any on_hold/in_review tasks back to to_do before shrinking the ENUM
        DB::table('tasks')->whereIn('status', ['on_hold', 'in_review'])->update(['status' => 'to_do']);

        $this->setStatuses(['to_do', 'in_progress', 'completed']);
    }

    /**
     * MODIFY COLUMN is MySQL-only syntax; other drivers (sqlite locally, pgsql)
     * need Laravel's portable column change instead.
     */
    private function setStatuses(array $statuses): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $values = implode(',', array_map(fn ($status) => "'{$status}'", $statuses));

            DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM({$values}) NOT NULL DEFAULT 'to_do'");

            return;
        }

        Schema::table('tasks', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('to_do')->change();
        });
    }
};
