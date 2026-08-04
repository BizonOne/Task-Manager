<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a record came from, when it came from somewhere else.
 *
 * An import that cannot recognise what it already brought over can only ever
 * be run once — the second run makes a second copy of everything. Keeping the
 * other system's own id beside the record is what turns a re-run into "bring
 * the changes across" instead of "do the whole thing again", and it is also
 * the only way back to the original issue once people start asking where a
 * task came from.
 */
return new class extends Migration
{
    private const TABLES = ['projects', 'tasks', 'task_comments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->string('external_source', 32)->nullable();
                $t->string('external_key', 64)->nullable();
                $t->string('external_url')->nullable();

                // Two records can't claim the same Jira issue. Both columns are
                // null for everything raised here, and MySQL and SQLite both
                // allow any number of nulls in a unique index.
                $t->unique(['external_source', 'external_key'], $table.'_external_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_external_unique');
                $t->dropColumn(['external_source', 'external_key', 'external_url']);
            });
        }
    }
};
