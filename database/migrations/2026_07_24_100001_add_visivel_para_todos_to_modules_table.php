<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MVP Cargo Dev — visibilidade de módulos no menu.
 *
 * Adiciona `visivel_para_todos` (bool default TRUE) à tabela `modules`. É um
 * campo de CICLO DE VIDA (mutável em runtime pela tela de controle do Dev em
 * `/dev/modulos`), não estrutural: `modules:sync` NÃO o toca — nasce `true`
 * pelo default do banco na criação e é preservado depois. Default `true`
 * garante ZERO regressão: nenhum item some do menu até o Dev ocultar de propósito.
 *
 * Semântica: `true` = visível para todos os papéis; `false` = só o Admin Dev
 * (`users.is_dev`, Fase 97) vê. O gate é aplicado no menu lateral via shared
 * props (`auth.modulos_ocultos`) + `AppLayout::itemVisivel()` — camada de menu
 * (cosmética); a fronteira de rota no servidor fica para a Fase 99.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->boolean('visivel_para_todos')->default(true)->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('visivel_para_todos');
        });
    }
};
