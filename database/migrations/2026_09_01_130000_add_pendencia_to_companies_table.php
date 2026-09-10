<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 150 (plano 04, ETAPA-04, D-17/D-18) — pendência PARALELA à etapa:
 * um sinalizador declarado à mão que convive com qualquer etapa e nunca a
 * move. Não confundir com os portões que impedem avanço (ETAPA-06,
 * derivados dentro do `EtapaTransicaoService`) — pendência comunica um
 * bloqueio A UMA PESSOA, não uma regra de máquina de estados.
 *
 * D-18 travou a cardinalidade (uma pendência aberta por vez: booleano +
 * motivo + autor + timestamp) e deixou o lugar físico em aberto. Decisão
 * de planejamento do 150-04-PLAN.md: 4 colunas em `companies`, não tabela
 * própria — mesma forma do precedente `problema`/`problema_desconsidera_meta`
 * em `mlb_empresas` que o ROADMAP (D6) mandou seguir. Motivo completo no
 * objective do plano.
 *
 * Puramente ADITIVA — só as 4 colunas novas + índice em `pendencia_aberta`.
 * `status` e `etapa` NÃO são tocados por esta migration.
 *
 * `pendencia_aberta` NOT NULL default `false` é deliberado (não boolean de
 * três estados): pendência ausente é o estado normal de toda empresa, e
 * um `NULL` obrigaria todo leitor a decidir o que significa — origem do
 * bug documentado em `.planning/learnings/painel-polos-status-e-meta.md` §1.
 *
 * `down()` derruba só as 4 colunas de pendência e nada mais (mesma
 * disciplina de propósito único da migration irmã `..._110000_add_etapa...`).
 * Atenção MariaDB (`.planning/learnings/desempenho-bonificacao.md` §6): a
 * FK de `pendencia_por` precisa ser solta ANTES do `dropColumn`, senão o
 * rollback falha nesta base — `dropColumn` sozinho não remove a constraint.
 *
 * Timestamp fixo `130000`: depois de `110000` (coluna `etapa`, plano 150-02)
 * e `120000` (tabela de transições, plano 150-03), que nascem em planos
 * paralelos na mesma wave — reservado desde o 150-02-SUMMARY.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('pendencia_aberta')->default(false)->after('etapa');
            $table->text('pendencia_motivo')->nullable()->after('pendencia_aberta');
            $table->foreignId('pendencia_por')->nullable()->after('pendencia_motivo')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pendencia_em')->nullable()->after('pendencia_por');

            // WHERE pendencia_aberta = ? — usado pelo filtro ?com_pendencia=1 do plano 150-07.
            $table->index('pendencia_aberta');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // MariaDB: soltar a FK antes de derrubar a coluna, senão o rollback falha.
            $table->dropForeign(['pendencia_por']);
            $table->dropColumn(['pendencia_aberta', 'pendencia_motivo', 'pendencia_por', 'pendencia_em']);
        });
    }
};
