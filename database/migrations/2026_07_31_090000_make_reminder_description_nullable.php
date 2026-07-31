<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The form, the controller's validation and the model's own scopes all
     * treat a reminder's description, date and time as optional — the overdue
     * scope even branches on `time IS NULL` — but all three columns were
     * NOT NULL with no default, so those branches were unreachable.
     *
     * The HTML form happened to submit empty strings and hid the problem;
     * anything creating a reminder programmatically got an integrity
     * constraint violation instead.
     */
    private const COLUMNS = ['description', 'date', 'time'];

    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
            $table->string('date')->nullable()->change();
            $table->string('time')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing NULLs would violate the restored NOT NULL constraint.
        foreach (self::COLUMNS as $column) {
            DB::table('reminders')->whereNull($column)->update([$column => '']);
        }

        Schema::table('reminders', function (Blueprint $table) {
            $table->text('description')->nullable(false)->change();
            $table->string('date')->nullable(false)->change();
            $table->string('time')->nullable(false)->change();
        });
    }
};
