<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 W5-T1 — Persiste o status do mapeamento cust_id por empresa.
 *
 * Motivação: o diagnose W4-T1 (executado em prod 2026-06-02) revelou 32
 * empresas INVALIDO_CONFIRMADO sem fallback ML ativo; o `--fix` automático
 * seria inseguro nessas empresas. Decisão W5: marcar visualmente como
 * "Cust ID Inválido" na UI para que operadores identifiquem e tratem.
 *
 * Esta coluna é apenas metadado (não muda `cust_id`, `adman_account_id`
 * nem `ml_store_id`). É populada pelo comando `dashboard:mark-custid-status`
 * (W5-T2) e consumida pelos controllers em W5-T3 / UI em W5-T4.
 *
 * Default 'desconhecido' = backfill instantâneo em MariaDB (sem lock longo);
 * empresas novas cadastradas após esta migration ficam neutras (sem badge)
 * até o operador rodar `mark-custid-status`.
 *
 * Domínio dos valores (ENUM):
 *  - 'ok'             → cust_id valida na Adman OU teve sync recente OK
 *  - 'invalido'       → Adman retornou 500/400/404 (cadastro corrompido)
 *  - 'desconhecido'   → ainda não diagnosticado (default)
 *  - 'nao_aplicavel'  → empresa sem `cust_id` (nem adman_account_id nem ml_store_id)
 *
 * @see .planning/phases/18-dashboard-precisao-filtros/PLAN.md (W5-T1)
 * @see app/Console/Commands/MarkCustIdStatus.php (W5-T2)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('cust_id_status', ['ok', 'invalido', 'desconhecido', 'nao_aplicavel'])
                ->default('desconhecido')
                ->after('adman_account_id')
                ->comment('Status do mapeamento cust_id; preenchido por dashboard:mark-custid-status (Phase 18 W5)');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('cust_id_status');
        });
    }
};
