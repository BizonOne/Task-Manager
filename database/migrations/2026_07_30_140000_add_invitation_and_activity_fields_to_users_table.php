<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the invitation + activity lifecycle columns the admin panel reports
     * on, and drops the profile fields that are no longer used.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('invitation_token', 64)->nullable()->unique()->after('remember_token');
            $table->timestamp('invited_at')->nullable()->after('invitation_token');
            $table->foreignId('invited_by_id')->nullable()->after('invited_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('invitation_accepted_at')->nullable()->after('invited_by_id');
            $table->timestamp('last_active_at')->nullable()->after('invitation_accepted_at');
        });

        // An invited user has no password until they accept the invitation.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['bio', 'phone', 'location', 'website'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_id');
            $table->dropColumn([
                'invitation_token',
                'invited_at',
                'invitation_accepted_at',
                'last_active_at',
            ]);

            $table->text('bio')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('location')->nullable();
            $table->string('website')->nullable();
        });
    }
};
