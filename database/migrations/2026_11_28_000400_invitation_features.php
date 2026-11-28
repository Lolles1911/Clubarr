<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            // Shown on the invitation page and in its mail.
            $table->text('greeting')->nullable();
            // A saved set-up to make invitations from; can't be used itself.
            $table->boolean('is_template')->default(false);
            // Who it was sent to, for the reminder; the link is kept encrypted
            // only until the reminder is sent or the invitation is used.
            $table->string('sent_to', 190)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->text('reminder_link')->nullable();
            // Made by a member for a friend (counts against their quota).
            $table->boolean('by_member')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn(['greeting', 'is_template', 'sent_to', 'sent_at', 'reminded_at', 'reminder_link', 'by_member']));
    }
};
