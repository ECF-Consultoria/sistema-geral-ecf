<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 111 Plan 03 (HUB-SCHEMA-02) — Fundação handoff HubSpot (milestone v20.0).
 *
 * Adiciona 11 colunas de origem/valor HubSpot em `contratos_servico` — colunas
 * de PROVENIÊNCIA, sem uso ainda. `valor_contratado` segue como o valor
 * operacional (não mexer aqui); `hubspot_valor_original`/
 * `hubspot_valor_normalizado_mensal` só serão preenchidas e usadas pelo
 * `HubspotValueResolver` da Fase 112 (mensal x anual).
 *
 * Migration DEFENSIVA — cada coluna eh inserida apenas se ainda nao existir
 * (Schema::hasColumn), permitindo re-run sem erro. down() reversivel,
 * removendo somente as colunas adicionadas por esta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contratos_servico', function (Blueprint $table) {
            // ID do Line Item HubSpot que originou este contrato.
            if (! Schema::hasColumn('contratos_servico', 'hubspot_line_item_id')) {
                $table->string('hubspot_line_item_id', 255)->nullable()->index();
            }

            // ID do Product HubSpot associado ao line item.
            if (! Schema::hasColumn('contratos_servico', 'hubspot_product_id')) {
                $table->string('hubspot_product_id', 255)->nullable();
            }

            // Frequência de cobrança HubSpot: weekly/biweekly/monthly/quarterly/annually/...
            if (! Schema::hasColumn('contratos_servico', 'hubspot_billing_frequency')) {
                $table->string('hubspot_billing_frequency', 50)->nullable();
            }

            // Período de cobrança em formato ISO-8601 (ex: P1Y, P1M).
            if (! Schema::hasColumn('contratos_servico', 'hubspot_billing_period')) {
                $table->string('hubspot_billing_period', 20)->nullable();
            }

            // Moeda do valor HubSpot (hs_line_item_currency_code).
            if (! Schema::hasColumn('contratos_servico', 'hubspot_currency')) {
                $table->string('hubspot_currency', 10)->nullable();
            }

            // Valor ORIGINAL vindo do HubSpot, antes de qualquer normalização
            // (pode ser anual, unico, etc — ver hubspot_valor_original_tipo).
            if (! Schema::hasColumn('contratos_servico', 'hubspot_valor_original')) {
                $table->decimal('hubspot_valor_original', 12, 2)->nullable();
            }

            // Tipo/origem do valor original. Ex: unit_price, net_price, mrr, arr, deal_amount.
            if (! Schema::hasColumn('contratos_servico', 'hubspot_valor_original_tipo')) {
                $table->string('hubspot_valor_original_tipo', 50)->nullable();
            }

            // Valor NORMALIZADO para base mensal — calculado pelo HubspotValueResolver
            // da Fase 112 (ex: R$36.000/ano vira R$3.000/mes). Ainda sem uso aqui.
            if (! Schema::hasColumn('contratos_servico', 'hubspot_valor_normalizado_mensal')) {
                $table->decimal('hubspot_valor_normalizado_mensal', 12, 2)->nullable();
            }

            // Confiança da normalização de valor. Ex: high, medium, low.
            if (! Schema::hasColumn('contratos_servico', 'hubspot_valor_confidence')) {
                $table->string('hubspot_valor_confidence', 20)->nullable();
            }

            // Aviso/justificativa quando a normalização não é segura (baixa confiança).
            if (! Schema::hasColumn('contratos_servico', 'hubspot_valor_warning')) {
                $table->text('hubspot_valor_warning')->nullable();
            }

            // Snapshot bruto (JSON) do line item HubSpot — proveniência completa
            // para auditoria/replay (Fase 112+).
            if (! Schema::hasColumn('contratos_servico', 'hubspot_snapshot')) {
                $table->json('hubspot_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contratos_servico', function (Blueprint $table) {
            $cols = [
                'hubspot_line_item_id', 'hubspot_product_id', 'hubspot_billing_frequency',
                'hubspot_billing_period', 'hubspot_currency', 'hubspot_valor_original',
                'hubspot_valor_original_tipo', 'hubspot_valor_normalizado_mensal',
                'hubspot_valor_confidence', 'hubspot_valor_warning', 'hubspot_snapshot',
            ];

            foreach ($cols as $col) {
                if (Schema::hasColumn('contratos_servico', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
