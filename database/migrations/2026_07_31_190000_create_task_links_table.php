<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links between tasks: "blocks", "duplicates", "relates to" and so on.
 *
 * A link is stored once, from the side it was created on. The other task reads
 * the same row and shows the inverse wording — mirroring the row instead would
 * mean two records to keep in step, and they drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The same pair cannot carry the same relation twice.
            $table->unique(['task_id', 'linked_task_id', 'type']);
            $table->index('linked_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
