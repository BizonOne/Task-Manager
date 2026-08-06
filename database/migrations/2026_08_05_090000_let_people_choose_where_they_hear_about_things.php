<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a person wants to be told.
 *
 * Everything went to email and the bell, and email is where notifications go
 * to be missed. Nobody sits in their inbox all day; they sit in Telegram. So
 * each person says where they want to hear about their work, and the same
 * notification goes to all of them.
 *
 * A row per connection, not a column per service: one person can have Telegram
 * on their phone and Slack at their desk, and adding a service later must not
 * mean another migration on the users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            // Whatever the service needs to reach this person: a Telegram chat
            // id, a Slack user id, later a push endpoint.
            $table->string('target')->nullable();
            // What to call it on screen — "@deniss", "Ksenija Skokova".
            $table->string('label')->nullable();

            // A connection nobody has completed is not a connection. Telegram
            // hands us the chat id only when the person presses Start, so a row
            // exists unverified in between.
            $table->timestamp('verified_at')->nullable();
            $table->string('connect_token', 64)->nullable()->unique();
            $table->timestamp('connect_expires_at')->nullable();

            // Switched off without being forgotten — going quiet for a week
            // should not mean connecting again afterwards.
            $table->boolean('enabled')->default(true);
            // Event keys this channel does not want. Empty means everything.
            $table->json('muted_events')->nullable();

            // Said out loud on the settings page rather than buried in a log:
            // a person who blocked the bot needs to know why it went quiet.
            $table->text('last_error')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
