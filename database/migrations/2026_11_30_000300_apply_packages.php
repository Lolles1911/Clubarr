<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What an applicant chose: a package (the admin's, made from a template) and its servers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->string('package', 80)->nullable()->after('answers');
            $table->foreignId('template_id')->nullable()->after('package')->constrained('invitations')->nullOnDelete();
            $table->json('server_ids')->nullable()->after('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn(['package', 'server_ids']);
        });
    }
};
