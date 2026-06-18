<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 37 Plan 37-02 (REQ-37-02) — tabela de mapeamento line_item HubSpot → Servico do catálogo.
 *
 * Editável pelo admin via UI /sistema/hubspot-line-items (Plan 37-07) — permite cadastrar
 * mapeamentos novos sem deploy quando o Comercial cria nomes de line item novos no HubSpot.
 * Match case-insensitive via LOWER comparison no Model (HubspotLineItemMapping::paraNome).
 *
 * Consumido pelo HubspotWebhookController (Plan 37-04) ao processar line items de deals
 * closedwon: para cada line_item.name retornado pelo HubSpot, resolve o Servico do catalogo
 * antes de criar o ContratoServico.
 *
 * Threat T-37-03 (Tampering): UNIQUE em line_item_name + FK cascade em servico_id +
 * admin-only (Plan 37-07) mitigam manipulacao do mapping.
 *
 * Migration idempotente: re-rodar nao recria tabela ja existente (guard Schema::hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hubspot_line_item_mapping')) {
            return;
        }

        Schema::create('hubspot_line_item_mapping', function (Blueprint $table) {
            $table->id();

            // Nome do line item como vem do HubSpot. UNIQUE: 1 nome -> 1 servico.
            // Capitalizacao arbitraria — match no Model usa LOWER comparison.
            $table->string('line_item_name', 255)->unique();

            // FK obrigatoria para o catalogo de servicos. Cascade: deletar Servico
            // apaga o mapping junto (mapping sem servico nao faz sentido).
            $table->foreignId('servico_id')
                ->constrained('servicos')
                ->cascadeOnDelete();

            // Admin pode desativar mapping sem deletar — preserva auditoria + permite
            // reativar depois sem refazer a configuracao.
            $table->boolean('ativo')->default(true);

            // Notas opcionais — ex: "MAP = Mentoria Acelerada Premium".
            $table->text('observacoes')->nullable();

            $table->timestamps();

            // Index composto para alimentar o lookup do webhook (Plan 37-04):
            // WHERE ativo=1 AND LOWER(line_item_name) = LOWER(?).
            $table->index(['ativo', 'line_item_name'], 'hsli_ativo_nome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_line_item_mapping');
    }
};
