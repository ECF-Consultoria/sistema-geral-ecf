<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 37 / Plan 37-01 (REQ-37-03) — adiciona coluna `setor` ao catálogo de serviços.
 *
 * Habilita o filtro de "Performance" em `/companies` (whereHas via
 * `contratos_servico.servico` setor='performance') e a categorização visual
 * na listagem Comercial (Plan 37-05) SEM criar coluna paralela em `companies`.
 *
 * Domínio do enum:
 *  - 'performance' → Gestão, Mentoria (Plan 37-06 filtra por aqui em /companies)
 *  - 'publicacao'  → Publicação (Plan 37-05 categoriza visualmente na listagem)
 *  - 'outros'      → demais serviços do catálogo (default — Polos, Assessoria,
 *                    Incubadora, Publicidade, e qualquer novo serviço sem
 *                    setor explícito)
 *
 * Default 'outros' garante que inserções sem `setor` não falhem por NOT NULL
 * e empresas com serviços novos não geram pendência comercial até o setor ser
 * mapeado manualmente.
 *
 * Migration idempotente via guard `Schema::hasColumn` — re-rodar não cria
 * coluna duplicada (T-37-02 mitigation).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('servicos', 'setor')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->enum('setor', ['performance', 'publicacao', 'outros'])
                    ->default('outros')
                    ->after('tipo_cobranca')
                    ->comment('Phase 37 — categoria de setor para filtros /companies (performance) e categorização visual /comercial/empresas/listagem');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servicos', 'setor')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->dropColumn('setor');
            });
        }
    }
};
