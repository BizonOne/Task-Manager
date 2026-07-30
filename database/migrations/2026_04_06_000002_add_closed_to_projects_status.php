<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setStatuses(['not_started', 'in_progress', 'completed', 'closed']);
    }

    public function down(): void
    {
        DB::table('projects')->where('status', 'closed')->update(['status' => 'completed']);

        $this->setStatuses(['not_started', 'in_progress', 'completed']);
    }

    /**
     * MODIFY COLUMN is MySQL-only syntax; other drivers (sqlite locally, pgsql)
     * need Laravel's portable column change instead.
     */
    private function setStatuses(array $statuses): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $values = implode(',', array_map(fn ($status) => "'{$status}'", $statuses));

            DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM({$values}) NOT NULL DEFAULT 'not_started'");

            return;
        }

        Schema::table('projects', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->default('not_started')->change();
        });
    }
};
