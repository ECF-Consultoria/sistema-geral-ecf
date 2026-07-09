<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v15.5 — Adiciona colunas de mapeamento Digisac na tabela `companies`.
 *
 * O envio automático de NPS via WhatsApp (Digisac) precisa saber, por empresa,
 * qual é o grupo (contato) correto para receber a mensagem. A fonte de verdade
 * do vínculo é o `contactId` retornado pela API do Digisac, NÃO o nome do
 * grupo (que pode ser renomeado pelo cliente sem quebrar o vínculo).
 *
 * Colunas:
 *  - digisac_service_id             — conexão WhatsApp usada (default cai em config('digisac.default_service_id'))
 *  - digisac_group_contact_id       — contactId do grupo (fonte de verdade)
 *  - digisac_group_name_snapshot    — nome do grupo no momento do mapeamento (só p/ UI)
 *  - digisac_group_mapped_at        — quando foi mapeado
 *  - digisac_group_verified_at      — última confirmação (ainda existe? nome mudou?)
 *  - digisac_group_mapping_status   — enum: not_mapped | mapped | needs_review | invalid
 *
 * Todas nullable — empresas sem grupo mapeado ficam com `not_mapped` e são
 * puladas no disparo mensal com status=skipped em `nps_digisac_envios`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('digisac_service_id', 100)
                ->nullable()
                ->after('email_cliente')
                ->comment('Digisac serviceId (conexao WhatsApp) usado para envio; null = usa default');

            $table->string('digisac_group_contact_id', 100)
                ->nullable()
                ->after('digisac_service_id')
                ->comment('contactId do grupo Digisac — fonte de verdade do vinculo');

            $table->string('digisac_group_name_snapshot', 255)
                ->nullable()
                ->after('digisac_group_contact_id')
                ->comment('Nome do grupo no momento do mapeamento (snapshot apenas visual)');

            $table->timestamp('digisac_group_mapped_at')
                ->nullable()
                ->after('digisac_group_name_snapshot')
                ->comment('Quando o admin vinculou a empresa a um grupo Digisac');

            $table->timestamp('digisac_group_verified_at')
                ->nullable()
                ->after('digisac_group_mapped_at')
                ->comment('Ultima vez que o vinculo foi confirmado (grupo ainda existe)');

            $table->string('digisac_group_mapping_status', 20)
                ->default('not_mapped')
                ->after('digisac_group_verified_at')
                ->comment('Status: not_mapped | mapped | needs_review | invalid');

            $table->index('digisac_group_mapping_status', 'idx_companies_digisac_mapping_status');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('idx_companies_digisac_mapping_status');
            $table->dropColumn([
                'digisac_service_id',
                'digisac_group_contact_id',
                'digisac_group_name_snapshot',
                'digisac_group_mapped_at',
                'digisac_group_verified_at',
                'digisac_group_mapping_status',
            ]);
        });
    }
};
