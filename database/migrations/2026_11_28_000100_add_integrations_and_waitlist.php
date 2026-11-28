<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Streams at the same time (Tracearr) and requests per 7 days
            // (Seerr); null = no limit. Set from the invitation.
            $table->unsignedInteger('max_streams')->nullable();
            $table->unsignedInteger('request_limit')->nullable();
            $table->unsignedBigInteger('seerr_user_id')->nullable();
            $table->string('discord_id', 32)->nullable()->unique();
            $table->string('discord_username')->nullable();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->unsignedInteger('max_streams')->nullable();
            $table->unsignedInteger('request_limit')->nullable();
            // Made by the waiting list (one person, 48 hours).
            $table->boolean('from_waitlist')->default(false);
        });

        Schema::table('media_servers', function (Blueprint $table) {
            // Members at most; null = no limit.
            $table->unsignedInteger('capacity')->nullable();
        });

        Schema::create('media_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->unsignedBigInteger('tmdb_id');
            $table->string('title');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            // waiting, invited, joined, expired
            $table->string('status', 16)->default('waiting')->index();
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
        Schema::dropIfExists('media_requests');
        Schema::table('media_servers', fn (Blueprint $table) => $table->dropColumn('capacity'));
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn(['max_streams', 'request_limit', 'from_waitlist']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['discord_id']);
            $table->dropColumn(['max_streams', 'request_limit', 'seerr_user_id', 'discord_id', 'discord_username']);
        });
    }
};
