<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files were an island: uploaded in their own section, owned by one person and
 * connected to nothing. An attachment on a task — the reason people upload
 * anything here — had nowhere to live.
 *
 * Both columns are nullable, because a file uploaded from the Files page still
 * belongs to nothing in particular, and every existing row is exactly that.
 * A comment attachment carries both, so it shows in the discussion and in the
 * task's attachment list alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A comment that is just a file has nothing to say in words.
        Schema::table('task_comments', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('task_comment_id')->nullable()->after('task_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->text('body')->nullable(false)->change();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_comment_id');
            $table->dropConstrainedForeignId('task_id');
        });
    }
};
