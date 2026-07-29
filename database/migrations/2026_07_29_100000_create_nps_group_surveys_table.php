<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 119.1 Plan 05 — Tabela `nps_group_surveys`: registro-âncora do link de
 * NPS de GRUPO (D3/D4/D6 do CONTEXT). Um link só, a nota vale para as
 * empresas do grupo cujo estrategista E analista batem no(s) serviço(s)
 * cobertos pelo modelo (calculado por `NpsGrupoCoberturaService`).
 *
 * Arquitetura travada pela pesquisa (RESEARCH C7 + PATTERNS item 3): este
 * registro é só a ÂNCORA — a resposta de fato é replicada em N surveys
 * REAIS (`nps_surveys`), um por empresa coberta, criados no momento da
 * resposta (Plan 06). Isso evita tocar nas duas réguas de dedupe já
 * existentes (área NPS e Desempenho/bônus) e nos ~11 call-sites de
 * agregação — do ponto de vista de quem calcula média, um espelho de grupo
 * é um survey normal.
 *
 * Duas armadilhas de produção endereçadas nominalmente:
 *  - MySQL 1830 — toda FK com `nullOnDelete()` PRECISA de `->nullable()`
 *    explícito (SQLite não pega; o deploy quebra no MariaDB). As duas FKs
 *    opcionais abaixo (`template_id`, `generated_by`) já vêm nullable.
 *  - tipo enumerado + SQLite — `status` é `string(20)`, nunca o tipo
 *    enumerado do schema builder. Migrations que mexem nesse tipo precisam
 *    de branch SQLite e quebram Feature test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_group_surveys', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();

            // Grupo dono do link. Apagar o grupo apaga o link de grupo
            // (o survey-espelho real em nps_surveys sobrevive — FK própria).
            $table->foreignId('company_group_id')
                ->constrained('company_groups')
                ->cascadeOnDelete();

            // Modelo NPS aplicado — nullable (mesma régua de nps_surveys.template_id):
            // rollback intermediário não pode deixar o link quebrado, e apagar o
            // template não corrompe o histórico (snapshot per-row é a fonte real).
            $table->foreignId('template_id')
                ->nullable()
                ->constrained('nps_templates')
                ->nullOnDelete();

            // Quem gerou o link — nullable (mesmo motivo de nps_surveys.generated_by):
            // apagar o usuário não pode quebrar o registro de auditoria.
            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('month_reference');

            // string(20), NUNCA o tipo enumerado do schema builder — esse tipo
            // + SQLite quebra Feature test (precisaria de branch dedicado);
            // string cobre o mesmo domínio (pending/completed/expired) sem
            // esse risco.
            $table->string('status', 20)->default('pending');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Snapshot da resposta única do link de grupo (réplica do que os
            // N espelhos vão registrar) — mesmo shape de nps_response_v15
            // simplificado; suficiente para auditoria/exibição.
            $table->string('respondent_name')->nullable();
            $table->text('comment')->nullable();

            $table->timestamps();

            // Impede dois links de grupo do MESMO modelo no MESMO mês para o
            // MESMO grupo — o análogo de grupo do bloqueio de duplicidade do
            // Plan 02 (disparo individual).
            $table->unique(
                ['company_group_id', 'template_id', 'month_reference'],
                'nps_group_surveys_dedup_uniq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_group_surveys');
    }
};
