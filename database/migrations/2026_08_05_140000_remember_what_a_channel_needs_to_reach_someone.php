<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for whatever a channel needs beyond an address.
 *
 * Telegram needs one string — the chat id — and that fits in `target`. A push
 * subscription is an endpoint *and* two keys the browser generated, without
 * which the message cannot be encrypted and the browser will not accept it.
 * Rather than a column per service, one place for the bits that differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
