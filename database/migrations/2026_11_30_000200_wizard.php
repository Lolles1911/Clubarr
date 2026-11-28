<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The wizard: steps new members see before and after joining, per server or in a bundle. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wizard_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamps();
        });
        Schema::create('wizard_steps', function (Blueprint $table) {
            $table->id();
            // null: every server (in a bundle: every server of the invitation).
            $table->foreignId('media_server_id')->nullable()->constrained()->cascadeOnDelete();
            // null: the standard steps; set: only for invitations with this bundle.
            $table->foreignId('wizard_bundle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phase', 10)->default('after'); // before | after
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->string('button_label', 60)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::table('invitations', fn (Blueprint $table) => $table->foreignId('wizard_bundle_id')->nullable()->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropConstrainedForeignId('wizard_bundle_id'));
        Schema::dropIfExists('wizard_steps');
        Schema::dropIfExists('wizard_bundles');
    }
};
