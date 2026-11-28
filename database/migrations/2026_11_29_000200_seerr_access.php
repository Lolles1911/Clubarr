<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Requests (Overseerr/Jellyseerr) are the admin's choice, per invitation and per member.
        Schema::table('invitations', fn (Blueprint $table) => $table->boolean('seerr_access')->default(false));
        Schema::table('users', fn (Blueprint $table) => $table->boolean('seerr_access')->default(false));
        // Members from before keep what they had.
        DB::table('users')->update(['seerr_access' => true]);
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('seerr_access'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('seerr_access'));
    }
};
