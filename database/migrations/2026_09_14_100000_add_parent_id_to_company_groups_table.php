<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 143 (T1) — `parent_id` em `company_groups`: o grupo de COBRANÇA
 * acima dos subgrupos.
 *
 * Por que existe (143-CONTEXT, D-01/D-03): os grupos de hoje foram criados
 * pensando em NPS, porque `nps_group_surveys` tem
 * `unique(company_group_id, template_id, month_reference)` — um link por
 * grupo, por modelo, por mês. Para mandar NPS a dois subgrupos no mesmo mês
 * a única saída era cadastrar cada subgrupo como grupo separado. Foi o que
 * fizeram, e por isso o fechamento cobra o cliente em quatro pedaços em vez
 * de um (caso MPozenato + DRossi + Gran Belo + Lyam).
 *
 * A solução é ADITIVA: os grupos que existem ficam como estão, com os
 * mesmos ids, e ganham a possibilidade de pendurar num grupo-pai. Quem
 * agrega a cobrança passa a ser a RAIZ da árvore.
 *
 * ⛔ O NPS não pode sentir nada — `nps_group_surveys`, a unicidade dela e
 * `NpsGrupoCoberturaService` ficam INTOCADOS. É essa condição que torna a
 * fase possível.
 *
 * ⚠️ A coluna NASCE NULA nos 15 grupos e ninguém ganha pai nesta entrega.
 * Com `parent_id` nulo em todo mundo, a raiz de cada grupo é ele mesmo e o
 * comportamento do fechamento é IDÊNTICO ao de hoje (regressão zero,
 * provada em `Phase143RegressaoSemPaiTest`).
 *
 * Armadilhas de MariaDB já pagas neste projeto (o SQLite dos testes não
 * pega nenhuma das três):
 *
 * 1. `nullOnDelete()` exige `nullable()` ANTES — erro 1830 (Fase 79). A
 *    ordem abaixo está correta; não inverta.
 * 2. Nome de índice acima de 64 caracteres é recusado — erro 1059 (Fase
 *    122) — e a migration fica `Pending` com a coluna criada SEM índice.
 *    Daí o nome curto explícito `company_groups_parent_idx` (25 chars) em
 *    vez do gerado automaticamente.
 * 3. Nada de coluna de tipo enumerado — quebra o SQLite dos testes.
 *
 * Excluir um grupo-pai NÃO apaga os subgrupos: `nullOnDelete()` só desfaz o
 * vínculo, e cada subgrupo volta a ser raiz de si mesmo (mesma semântica do
 * `companies.company_group_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: reexecução em base que já tem a coluna não quebra.
        if (Schema::hasColumn('company_groups', 'parent_id')) {
            return;
        }

        Schema::table('company_groups', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('color')
                ->constrained('company_groups', indexName: 'company_groups_parent_idx')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_groups', 'parent_id')) {
            return;
        }

        Schema::table('company_groups', function (Blueprint $table) {
            // Nome explícito porque foi explícito no up() — o default
            // gerado (`company_groups_parent_id_foreign`) não existe aqui.
            $table->dropForeign('company_groups_parent_idx');
            $table->dropColumn('parent_id');
        });
    }
};
