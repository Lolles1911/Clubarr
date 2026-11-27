<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            // Random and long; looked up by its SHA-256 hash, so a leaked
            // database does not hand out working codes.
            $table->string('code_hash', 64)->unique();
            // The first characters, so admins can tell invitations apart.
            $table->string('code_hint', 16);
            $table->string('label')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable()->default(1);
            $table->unsignedInteger('uses')->default(0);
            // How long the access lasts from redeeming; null = until removed.
            $table->unsignedInteger('access_days')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invitation_media_server', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_server_id')->constrained()->cascadeOnDelete();
            // External library IDs; null = all libraries.
            $table->json('library_ids')->nullable();
            $table->unique(['invitation_id', 'media_server_id']);
        });

        Schema::create('server_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
            // The user's ID and name on the media server; null while a Silo
            // claim link or Plex share is not accepted yet.
            $table->string('remote_id')->nullable();
            $table->string('remote_username')->nullable();
            $table->string('status', 16)->default('active');
            $table->json('library_ids')->nullable();
            // Silo: the one-time claim link (encrypted) until it is used.
            $table->text('claim_url')->nullable();
            $table->string('claim_id')->nullable();
            $table->timestamps();
            $table->unique(['media_server_id', 'remote_id']);
            $table->unique(['user_id', 'media_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_accounts');
        Schema::dropIfExists('invitation_media_server');
        Schema::dropIfExists('invitations');
    }
};
