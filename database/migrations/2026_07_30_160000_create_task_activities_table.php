<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for tasks: who changed what, when. Rendered together with
     * comments as a single timeline on the task page.
     */
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            // Null when the change wasn't made by a signed-in user (console,
            // seeder, scheduled job) — the row still has to survive.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('field', 40)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            // Free-form extras (e.g. the affected assignee's name).
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
