<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 151 (plano 03, COMERC-02, D-08/D-09) — três colunas ADITIVAS em
 * `companies` para o campo "responsável comercial" e a data da venda, que
 * hoje não existem no sistema.
 *
 * `hubspot_owner_id`   — id numérico do owner do deal no HubSpot, como string.
 * `hubspot_owner_nome` — nome de exibição resolvido por `HubspotOwnerResolver`.
 * `data_venda`         — data de fechamento do deal (`closedate`), como `date`
 *   (não `dateTime`): medido em `HubspotDealHandoffService::parseDataHubspot()`
 *   que a property chega como string `'Y-m-d'`, e a Fase 156 vai ler isto
 *   como evento datado.
 *
 * Puramente ADITIVA — nenhuma coluna existente de `companies` (~500 registros
 * em produção) é tocada, nenhuma ganha `default()`, nenhum índice é criado e
 * nenhum backfill roda aqui: o retroativo (D-10) é comando manual do plano
 * 151-04. Cada coluna entra dentro do próprio `Schema::hasColumn` (molde da
 * migration `2026_07_24_111001_add_hubspot_fields_to_companies_table.php`,
 * Fase 111), e o `down()` percorre as três num `foreach` — nunca mistura
 * criação de coluna com rename no mesmo `up()`/`down()`, o anti-padrão já
 * registrado no repositório (`2026_05_25_100001_add_status_to_companies.php`,
 * citado também no docblock de `2026_09_01_110000_add_etapa_to_companies_table.php`).
 *
 * Baseline pré-migration exigida pelo `CLAUDE.md` já registrada pelo plano
 * 151-01 em `.planning/phases/138-.../151-BASELINE-TESTES.md` antes desta
 * migration ser criada — gate cumprido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'hubspot_owner_id')) {
                $table->string('hubspot_owner_id', 255)->nullable();
            }

            if (! Schema::hasColumn('companies', 'hubspot_owner_nome')) {
                $table->string('hubspot_owner_nome', 255)->nullable();
            }

            if (! Schema::hasColumn('companies', 'data_venda')) {
                $table->date('data_venda')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $cols = ['hubspot_owner_id', 'hubspot_owner_nome', 'data_venda'];

            foreach ($cols as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
