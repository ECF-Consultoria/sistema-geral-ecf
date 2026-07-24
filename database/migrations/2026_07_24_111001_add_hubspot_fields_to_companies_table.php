<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 111 Plan 03 (HUB-SCHEMA-01) — Fundação handoff HubSpot (milestone v20.0).
 *
 * Adiciona 8 colunas de origem HubSpot em `companies` (IDs, contato principal,
 * observação e snapshot bruto). Colunas NASCEM SEM USO — base de auditoria/
 * proveniência que a Fase 112+ vai popular. Nenhum comportamento do
 * webhook/legado (Fases 34-37) muda aqui.
 *
 * Migration DEFENSIVA — cada coluna eh inserida apenas se ainda nao existir
 * (Schema::hasColumn), permitindo re-run sem erro. down() reversivel,
 * removendo somente as colunas adicionadas por esta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // ID do Deal HubSpot que originou/atualizou a empresa.
            if (! Schema::hasColumn('companies', 'hubspot_deal_id')) {
                $table->string('hubspot_deal_id', 255)->nullable()->index();
            }

            // ID da Company (objeto) no HubSpot.
            if (! Schema::hasColumn('companies', 'hubspot_company_id')) {
                $table->string('hubspot_company_id', 255)->nullable()->index();
            }

            // ID do Contact (contato principal) associado no HubSpot.
            if (! Schema::hasColumn('companies', 'hubspot_contact_id')) {
                $table->string('hubspot_contact_id', 255)->nullable()->index();
            }

            // Nome do contato principal (snapshot do HubSpot no momento do close).
            if (! Schema::hasColumn('companies', 'nome_contato')) {
                $table->string('nome_contato', 255)->nullable();
            }

            // Cargo/jobtitle do contato principal.
            if (! Schema::hasColumn('companies', 'cargo_contato')) {
                $table->string('cargo_contato', 255)->nullable();
            }

            // Domínio da company no HubSpot (propriedade "domain").
            if (! Schema::hasColumn('companies', 'hubspot_domain')) {
                $table->string('hubspot_domain', 255)->nullable();
            }

            // Observação/nota livre capturada do deal HubSpot (ex: observacao/description).
            if (! Schema::hasColumn('companies', 'hubspot_observacao')) {
                $table->text('hubspot_observacao')->nullable();
            }

            // Snapshot bruto (JSON) das propriedades HubSpot no momento do handoff —
            // proveniência completa para auditoria/replay (Fase 112+).
            if (! Schema::hasColumn('companies', 'hubspot_snapshot')) {
                $table->json('hubspot_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $cols = [
                'hubspot_deal_id', 'hubspot_company_id', 'hubspot_contact_id',
                'nome_contato', 'cargo_contato', 'hubspot_domain',
                'hubspot_observacao', 'hubspot_snapshot',
            ];

            foreach ($cols as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
