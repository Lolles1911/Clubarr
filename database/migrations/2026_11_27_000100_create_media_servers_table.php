<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_servers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('name');
            // How Clubarr reaches the server, and the address members open.
            $table->string('url');
            $table->string('public_url')->nullable();
            // Encrypted by the model; never shown again after saving.
            $table->text('api_key');
            $table->json('settings')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_server_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('kind', 32)->nullable();
            $table->timestamps();
            $table->unique(['media_server_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libraries');
        Schema::dropIfExists('media_servers');
    }
};
