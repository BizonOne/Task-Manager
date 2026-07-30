<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'budget')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('budget');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'budget')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->decimal('budget', 15, 2)->nullable();
            });
        }
    }
};
