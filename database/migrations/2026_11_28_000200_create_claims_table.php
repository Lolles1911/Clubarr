<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A personal one-time link for a user that already exists on a media
        // server: whoever opens it makes their Clubarr account and is linked
        // to that user. The code is stored only as a hash.
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('media_server_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id');
            $table->string('remote_username');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['media_server_id', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
