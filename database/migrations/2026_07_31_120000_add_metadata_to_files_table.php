<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files only stored a display name and a storage path, so a download had
     * no original filename to offer and no MIME type to decide whether the
     * browser should show it inline. Nullable throughout — existing rows
     * predate all of it.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('name');
            $table->string('mime_type')->nullable()->after('path');
            $table->unsignedBigInteger('size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'mime_type', 'size']);
        });
    }
};
