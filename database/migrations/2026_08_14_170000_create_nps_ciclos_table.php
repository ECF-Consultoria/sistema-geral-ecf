<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `nps_ciclos` — fechamento MANUAL do ciclo de NPS (spec 2026-08-14, item 2).
 *
 * ─── Decisão de schema (registrada ANTES do código, disciplina do CLAUDE.md) ──
 *
 * Até aqui "ciclo fechado" não era um ESTADO: era uma conta de datas em
 * `NpsJanelaResolver::fechada()` (`hoje >= último dia do mês de coleta`).
 * Ninguém conseguia encerrar antes, e não havia registro de quem encerrou.
 *
 * Por que TABELA NOVA e não coluna em `nps_surveys`:
 *  - o fechamento é do CICLO (um mês de coleta inteiro), não de cada survey.
 *    Uma coluna por survey precisaria ser escrita em N linhas e poderia ficar
 *    inconsistente entre elas — o mesmo mês meio fechado, meio aberto;
 *  - `nps_surveys` tem dado de produção; tabela nova é ADITIVA e sai sem
 *    deixar rastro se o desenho se provar errado (CLAUDE.md §6 — migration em
 *    tabela existente com dado em produção é o caso caro de desfazer);
 *  - o ciclo precisa existir mesmo em mês SEM nenhum survey (fechar um mês
 *    vazio é legítimo), e uma coluna em `nps_surveys` não teria onde morar.
 *
 * Colunas:
 *  - `mes_coleta` DATE, sempre dia 1º, UNIQUE. É o mês em que a pesquisa é
 *    respondida — a MESMA grandeza de `nps_surveys.month_reference` e de
 *    `?mes=` na tela. A competência avaliada é `mes_coleta - 1 mês` e NÃO é
 *    gravada: derivar evita as duas ficarem divergentes (a armadilha do campo
 *    `competencia_nps`, que guarda o mês de coleta apesar do nome).
 *  - `fechado_em` DATETIME e `fechado_por` FK users — a trilha que a spec pede.
 *    `fechado_por` é nullable com `nullOnDelete`: apagar o usuário não pode
 *    derrubar o registro de que o ciclo foi fechado.
 *
 * A linha só existe quando alguém fecha: ausência = ciclo aberto. Não há
 * "reabrir" nesta fase — se for preciso, o caminho é apagar a linha, e isso
 * fica explícito em vez de virar um botão que ninguém sabe quem apertou.
 *
 * @see app/Models/NpsCiclo.php
 * @see app/Services/Nps/NpsJanelaResolver.php (passa a consultar este estado)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_ciclos', function (Blueprint $table) {
            $table->id();

            // Mês de COLETA (dia 1º). Unique: um ciclo por mês, sem exceção.
            $table->date('mes_coleta')->unique();

            $table->dateTime('fechado_em');

            // Índice nomeado à mão — MariaDB corta nome auto-gerado longo
            // (learnings §6, cicatriz de schema conhecida do projeto).
            $table->foreignId('fechado_por')
                ->nullable()
                ->constrained('users', indexName: 'nps_ciclos_fechado_por_fk')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_ciclos');
    }
};
