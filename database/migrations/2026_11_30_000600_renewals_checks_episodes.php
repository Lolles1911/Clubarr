<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.12: asking for a new invitation link, server uptime, and new-episode messages. */
return new class extends Migration
{
    public function up(): void
    {
        // An application that asks for a new link for an invitation that ended or was used.
        Schema::table('access_requests', fn (Blueprint $table) => $table->foreignId('renew_of')->nullable()->constrained('invitations')->nullOnDelete());
        Schema::create('server_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_server_id')->constrained()->cascadeOnDelete();
            $table->boolean('up');
            $table->timestamp('checked_at')->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('episode_alerts')->default(true);
            $table->timestamp('episodes_told_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['episode_alerts', 'episodes_told_at']));
        Schema::dropIfExists('server_checks');
        Schema::table('access_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('renew_of'));
    }
};
