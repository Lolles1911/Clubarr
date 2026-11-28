<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.10: extra users for a member's family, movie nights, challenges, and members pausing themselves. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_server_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id', 100);
            $table->string('remote_username', 100);
            $table->string('label', 60);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['media_server_id', 'remote_id']);
        });
        Schema::create('movie_nights', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->timestamp('starts_at');
            $table->foreignId('media_server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->boolean('reminded')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('movie_night_user', function (Blueprint $table) {
            $table->foreignId('movie_night_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['movie_night_id', 'user_id']);
        });
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('metric', 20); // movies | episodes | hours
            $table->unsignedInteger('target');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('note', 1000)->nullable();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('self_paused_at')->nullable();
            $table->timestamp('self_pause_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['self_paused_at', 'self_pause_until']));
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('movie_night_user');
        Schema::dropIfExists('movie_nights');
        Schema::dropIfExists('extra_users');
    }
};
