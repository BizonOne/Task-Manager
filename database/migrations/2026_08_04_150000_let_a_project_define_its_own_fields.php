<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields a project invents for itself.
 *
 * Every team keeps something on a task that no other team needs. The project
 * this was written for tracked which acquirer each onboarding was for; the
 * next one will track something else entirely. Rather than guessing at columns
 * nobody asked for, a project says what it wants to record and its tasks
 * answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Stable across renames: filters and imported data point at this,
            // so calling the field something else does not orphan its values.
            $table->string('key', 64);
            $table->string('type', 32)->default('select');
            // The choices, for the pick-one and pick-many kinds.
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('show_on_card')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::create('task_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_field_id')->constrained()->cascadeOnDelete();
            // Always a list, even for a field that holds one thing: it keeps
            // the reading side from caring which kind of field it came from.
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'project_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_field_values');
        Schema::dropIfExists('project_fields');
    }
};
