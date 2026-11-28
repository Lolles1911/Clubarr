<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private notes about a member, for admins only.
        Schema::table('users', fn (Blueprint $table) => $table->text('admin_note')->nullable());

        // Groups of members ("Family", "Friends") with their own rules; a rule left empty is not set by the group.
        Schema::create('member_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('color', 16)->default('gray');
            $table->boolean('no_end')->default(false);
            $table->unsignedSmallInteger('max_streams')->nullable();
            $table->unsignedSmallInteger('request_limit')->nullable();
            $table->boolean('seerr_access')->nullable();
            $table->timestamps();
        });
        Schema::create('member_group_user', function (Blueprint $table) {
            $table->foreignId('member_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['member_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_group_user');
        Schema::dropIfExists('member_groups');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_note'));
    }
};
