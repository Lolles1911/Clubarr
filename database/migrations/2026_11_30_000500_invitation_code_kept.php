<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The invitation code, kept encrypted (with /config/app.key) so admins can see and copy it again. Lookups still use the hash. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->text('code_secret')->nullable());
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('code_secret'));
    }
};
