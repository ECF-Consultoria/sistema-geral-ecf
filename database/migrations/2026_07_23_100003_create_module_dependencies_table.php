<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 97 (Fundação do Module Registry) — tabela `module_dependencies`.
 *
 * Auto-referência de `modules` (um módulo depende de outro). O nome de coluna
 * não-convencional `depends_on_module_id` exige passar 'modules' explícito
 * em `constrained()` — Laravel não infere a tabela pelo nome da coluna aqui.
 *
 * Consumido a partir da Fase 101 (dependências no board) — nesta fase só
 * existe schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('depends_on_module_id')->constrained('modules')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['module_id', 'depends_on_module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_dependencies');
    }
};
