<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The weekly "new on the server" mail (the member's own choice).
            $table->boolean('digest')->default(false);
            $table->timestamp('digest_sent_at')->nullable();
            // Last time the member watched anything (from the servers or Tracearr), for "inactive members".
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('inactive_warned_at')->nullable();
            // Single sign-on: the identity provider's subject, once linked.
            $table->string('oidc_sub', 191)->nullable()->unique();
        });

        // "Can I have more time?" from a member; an admin approves or declines.
        Schema::create('extension_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('days')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // "Something doesn't work" from a member, with the admin's answer.
        Schema::create('problem_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 150);
            $table->text('body');
            $table->string('status', 16)->default('open');
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
        });

        // "May I join?" from a visitor; an admin approves (an invitation is mailed) or declines.
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 190);
            $table->text('message')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
        Schema::dropIfExists('problem_reports');
        Schema::dropIfExists('extension_requests');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['digest', 'digest_sent_at', 'last_seen_at', 'inactive_warned_at', 'oidc_sub']));
    }
};
