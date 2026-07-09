<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 74 — Adiciona coluna `mes_referencia` + reorganiza unique key da tabela
 * `desempenho_score_snapshots` para suportar as DUAS modalidades de snapshot
 * (diário e mensal fechado) coexistindo no mesmo storage.
 *
 * Motivação (decisões locked no 74-CONTEXT.md):
 *   • D-01 · Evoluir a tabela existente em vez de criar uma nova — zero perda
 *     do histórico v1 (Phase 46) que já habita `desempenho_score_snapshots`.
 *   • D-02 · Uma tabela, DUAS semânticas por row:
 *       – `mes_referencia = NULL`         → snapshot DIÁRIO legado (Phase 46).
 *       – `mes_referencia = YYYY-MM-01`   → snapshot MENSAL FECHADO (Phase 74).
 *     Não backfillar valor — rows históricas continuam com NULL válido.
 *   • D-03 · Unique key evolutiva: drop `(user_id, ref_date)`, cria
 *     `(user_id, ref_date, mes_referencia)`. Isso permite a mesma dupla
 *     (user, data) aparecer 1x como diário (mes_referencia NULL) e 1x como
 *     mensal fechado (mes_referencia = '2026-07-01'), sem duplicata real.
 *
 * Semântica dos índices resultantes:
 *   • unique  `desempenho_score_snapshots_user_ref_mes_unique`
 *       → idempotência do `updateOrCreate(['user_id','ref_date','mes_referencia'])`
 *   • index   `desempenho_score_snapshots_mes_score_idx (mes_referencia, score)`
 *       → acelera ranking mensal (Performance/Dashboard.jsx, PerformanceController).
 *   • index   `desempenho_score_snapshots_ref_date_score_index (ref_date, score)`
 *       → preservado da migration original (ranking histórico diário).
 *
 * Migration idempotente: rerun não recria coluna existente nem recria unique/index
 * duplicado. Todos os drops/creates de índice envolvem `try/catch \Throwable` para
 * tolerar rodadas parciais anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard 1: só age se a tabela existe (idempotência entre ambientes).
        if (!Schema::hasTable('desempenho_score_snapshots')) {
            return;
        }

        // Guard 2: adiciona coluna `mes_referencia` (D-01/D-02) apenas se
        // ainda não estiver presente. Sem default — semântica NULL = diário legado.
        if (!Schema::hasColumn('desempenho_score_snapshots', 'mes_referencia')) {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->date('mes_referencia')->nullable()->after('ref_date');
            });
        }

        // Drop do unique legado `(user_id, ref_date)` — nome canônico Laravel:
        // `desempenho_score_snapshots_user_id_ref_date_unique`. Envolvido em
        // try/catch para tolerar rerun em ambientes onde o índice já foi
        // substituído por esta mesma migration (D-03).
        try {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'ref_date']);
            });
        } catch (\Throwable $e) {
            // Idempotência: índice pode não existir mais em reruns.
        }

        // Cria unique novo `(user_id, ref_date, mes_referencia)` — nome curto
        // custom para respeitar limite de 64 chars do MariaDB (D-03).
        try {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->unique(
                    ['user_id', 'ref_date', 'mes_referencia'],
                    'desempenho_score_snapshots_user_ref_mes_unique'
                );
            });
        } catch (\Throwable $e) {
            // Idempotência: unique já criado em rerun.
        }

        // Índice de suporte ao ranking mensal (`WHERE mes_referencia = ? ORDER BY score DESC`),
        // consumido por PerformanceController::index/dashboardCarteira e pelo comando
        // `desempenho:consolidar-mes` (Plan 74-04).
        try {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->index(
                    ['mes_referencia', 'score'],
                    'desempenho_score_snapshots_mes_score_idx'
                );
            });
        } catch (\Throwable $e) {
            // Idempotência: índice já criado em rerun.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('desempenho_score_snapshots')) {
            return;
        }

        // Drop dos índices/uniques novos (reverse ordem do up).
        try {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->dropIndex('desempenho_score_snapshots_mes_score_idx');
            });
        } catch (\Throwable $e) {
            // Idempotência.
        }

        try {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->dropUnique('desempenho_score_snapshots_user_ref_mes_unique');
            });
        } catch (\Throwable $e) {
            // Idempotência.
        }

        // Restaura unique legado `(user_id, ref_date)` antes de remover coluna.
        try {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->unique(['user_id', 'ref_date']);
            });
        } catch (\Throwable $e) {
            // Idempotência: pode já existir se down foi rodado parcialmente.
        }

        // Finalmente remove a coluna nova.
        if (Schema::hasColumn('desempenho_score_snapshots', 'mes_referencia')) {
            Schema::table('desempenho_score_snapshots', function (Blueprint $table) {
                $table->dropColumn('mes_referencia');
            });
        }
    }
};
