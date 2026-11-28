<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A message from the admins to members: by mail, Discord and/or a banner on their page.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            // null: every member; else only members with access to this server.
            $table->foreignId('media_server_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('by_mail')->default(false);
            $table->boolean('by_discord')->default(false);
            $table->boolean('as_banner')->default(false);
            $table->timestamp('banner_until')->nullable();
            $table->unsignedInteger('mailed')->default(0);
            $table->unsignedInteger('discorded')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
