<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 97 (Fundação do Module Registry) — pivot `module_user`.
 *
 * Favoritos/pin/beta opt-in por dev, análogo ao pivot `user_setores`
 * (`2026_05_20_200004_create_user_setores_table.php`): FKs + unique composto
 * evitando linha duplicada por par (user, module).
 *
 * Consumido a partir da Fase 100 (board em /dev) — nesta fase só existe schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->boolean('favorito')->default(false);
            $table->unsignedSmallInteger('pin_ordem')->nullable();
            $table->boolean('beta_optin')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_user');
    }
};
