<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('oidc_group');
            // Snipe-IT's permission_groups.id is an unsigned INT (increments()).
            // If `php artisan migrate` fails on the FK with a type mismatch,
            // switch this to unsignedBigInteger to match a bigIncrements id.
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

    public function down(): void
    {
        Schema::dropIfExists('oidc_group_mappings');
    }
};
