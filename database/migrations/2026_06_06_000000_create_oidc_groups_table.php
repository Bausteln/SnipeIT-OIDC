<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('sync_enabled')->default(false);
            // Matches permission_groups.id (unsigned INT via increments()).
            $table->unsignedInteger('snipe_group_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('snipe_group_id')
                ->references('id')->on('permission_groups')
                ->nullOnDelete();
        });

        // Replaces the v0.5.0 mapping table.
        Schema::dropIfExists('oidc_group_mappings');
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_groups');

        // Recreate the v0.5.0 table so the migration is reversible.
        Schema::create('oidc_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('oidc_group');
            $table->unsignedInteger('snipe_group_id');
            $table->boolean('grants_superuser')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['oidc_group', 'snipe_group_id']);
            $table->index('oidc_group');
            $table->foreign('snipe_group_id')
                ->references('id')->on('permission_groups')
                ->onDelete('cascade');
        });
    }
};
