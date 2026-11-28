<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.8: answers on applications, a moderator role for admins, member
 * invitations that wait for approval, and planned maintenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_requests', fn (Blueprint $table) => $table->json('answers')->nullable()->after('message'));
        // null: a full admin; 'moderator': members and requests, not settings or servers.
        Schema::table('users', fn (Blueprint $table) => $table->string('admin_role', 20)->nullable()->after('is_admin'));
        Schema::table('invitations', function (Blueprint $table) {
            $table->boolean('awaiting_approval')->default(false);
            // A member's invitation waiting for approval: its code and the friend's email, encrypted, until it is decided.
            $table->text('pending_code')->nullable();
            $table->string('pending_email', 190)->nullable();
        });
        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('note', 500)->nullable();
            $table->boolean('told')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenances');
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn(['awaiting_approval', 'pending_code', 'pending_email']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_role'));
        Schema::table('access_requests', fn (Blueprint $table) => $table->dropColumn('answers'));
    }
};
