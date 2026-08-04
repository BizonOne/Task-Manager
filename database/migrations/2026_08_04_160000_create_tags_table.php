<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags: the loose end of labelling.
 *
 * A project's fields are structured — a question with a fixed set of answers.
 * Tags are the opposite and exist for the same reason a notebook has margins:
 * somebody needs to write "urgent-legal" on four tasks across three projects
 * right now, and inventing a field for it would take longer than the work.
 *
 * Shared across every project on purpose. A tag that only meant something on
 * one board could not answer "everything tagged compliance, wherever it is",
 * which is the whole point of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            // What makes "Urgent Legal", "urgent legal" and "urgent-legal" the
            // same tag instead of three.
            $table->string('slug', 60)->unique();
            $table->timestamps();
        });

        Schema::create('task_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->unique(['task_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_tag');
        Schema::dropIfExists('tags');
    }
};
