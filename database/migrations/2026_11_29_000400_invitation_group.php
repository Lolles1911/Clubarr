<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An invitation (or template) can put the new member in a group.
        Schema::table('invitations', fn (Blueprint $table) => $table->foreignId('member_group_id')->nullable()->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_group_id');
        });
    }
};
