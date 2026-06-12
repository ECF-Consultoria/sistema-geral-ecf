<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 34 Plan 34-01 — Cadastro Comercial Otimizado + HubSpot.
 *
 * Adiciona 9 colunas em companies para capturar informacoes do close comercial
 * (nicho, dor, vende_ml, faturamento_mensal, marketplaces_extras, email_colaborador)
 * + 3 colunas para a tag "Empresa nova" (empresa_nova, empresa_nova_visto_em,
 * empresa_nova_visto_por).
 *
 * Migration DEFENSIVA — cada coluna eh inserida apenas se ainda nao existir
 * (Schema::hasColumn), permitindo re-run sem erro mesmo em ambientes onde uma
 * ou outra coluna ja foi adicionada manualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Nicho de mercado capturado no close (ex: "Moda feminina", "Auto pecas").
            if (! Schema::hasColumn('companies', 'nicho')) {
                $table->string('nicho', 255)->nullable()->after('telefone');
            }

            // Dor/pain do cliente — texto livre.
            if (! Schema::hasColumn('companies', 'dor')) {
                $table->text('dor')->nullable()->after('nicho');
            }

            // Vende ou nao no ML hoje? null=desconhecido, 1=sim, 0=nao.
            if (! Schema::hasColumn('companies', 'vende_ml')) {
                $table->tinyInteger('vende_ml')->nullable()->after('dor');
            }

            // Faturamento mensal estimado em R$ (decimal 12,2 cobre ate 999 bilhoes).
            if (! Schema::hasColumn('companies', 'faturamento_mensal')) {
                $table->decimal('faturamento_mensal', 12, 2)->nullable()->after('vende_ml');
            }

            // Array de marketplaces extras: ["shopee","amazon","magalu","temu","tiktok"].
            if (! Schema::hasColumn('companies', 'marketplaces_extras')) {
                $table->json('marketplaces_extras')->nullable()->after('faturamento_mensal');
            }

            // Email criado pela ECF para acesso colaborador no ML — distinto de email_cliente
            // (que eh o email do proprietario, usado pelo NPS — Phase 31).
            if (! Schema::hasColumn('companies', 'email_colaborador')) {
                $table->string('email_colaborador', 255)->nullable()->after('marketplaces_extras');
            }

            // Tag "Empresa nova": 1 = ainda nao foi vista pelo admin (default).
            if (! Schema::hasColumn('companies', 'empresa_nova')) {
                $table->tinyInteger('empresa_nova')->default(1)->after('email_colaborador');
            }

            // Quando o admin marcou como visto.
            if (! Schema::hasColumn('companies', 'empresa_nova_visto_em')) {
                $table->timestamp('empresa_nova_visto_em')->nullable()->after('empresa_nova');
            }

            // Quem marcou (FK users — nullOnDelete preserva auditoria mesmo se o user sumir).
            if (! Schema::hasColumn('companies', 'empresa_nova_visto_por')) {
                $table->foreignId('empresa_nova_visto_por')
                    ->nullable()
                    ->after('empresa_nova_visto_em')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Drop FK antes da coluna (Laravel >= 7).
            if (Schema::hasColumn('companies', 'empresa_nova_visto_por')) {
                $table->dropConstrainedForeignId('empresa_nova_visto_por');
            }

            $cols = [
                'nicho', 'dor', 'vende_ml', 'faturamento_mensal',
                'marketplaces_extras', 'email_colaborador',
                'empresa_nova', 'empresa_nova_visto_em',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
