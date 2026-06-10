<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31 Plan 01 — Adiciona companies.email_cliente.
 *
 * Esta coluna é o destinatário do email NPS mensal automatizado (D-04
 * do CONTEXT da Phase 31). Nullable porque empresas cadastradas antes
 * desta migration entram sem email e serão preenchidas on-demand via UI
 * de edição (Companies/Show ou Comercial/Empresas). O comando
 * `nps:disparar-mensal` (Plan 31-02) pula silenciosamente empresas sem
 * `email_cliente` — é estado esperado, não erro.
 *
 * Posicionada após `notes` para manter agrupamento "metadados textuais"
 * no schema. Não entra no `logOnly()` do Spatie ActivityLog em
 * `app/Models/Company.php` por decisão deste plan: o campo pode ser
 * editado livremente via UI e o histórico de mudanças poluiria o feed.
 *
 * @see .planning/phases/31-nps-mensal-automatizado/31-CONTEXT.md (D-04)
 * @see .planning/phases/31-nps-mensal-automatizado/31-01-PLAN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('email_cliente', 255)
                ->nullable()
                ->after('notes')
                ->comment('Destinatário do email NPS mensal — Phase 31 D-04');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('email_cliente');
        });
    }
};
