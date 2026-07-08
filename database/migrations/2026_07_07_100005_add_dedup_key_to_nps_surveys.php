<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 68 Plan 04 — Dedup key composto para bloquear duplicata mensal por template.
 *
 * REQ atendido: NPS-A-01 (parte 2/2 — schema completo)
 * Guard downstream: Phase 69 NpsController vai capturar QueryException 23000 (NPS-B-03)
 *                   e redirecionar o cliente pra tela "Já respondida no mês".
 *
 * -----------------------------------------------------------------------------
 * Regra de dedup (semântica idêntica nos 2 drivers)
 * -----------------------------------------------------------------------------
 *
 *   Uma survey CONTA pra dedup mensal SOMENTE quando:
 *     status = 'completed' AND completed_at IS NOT NULL
 *
 *   Enquanto pending / expired, várias surveys da mesma
 *   (company_id, month_reference, template_id) podem coexistir — o
 *   `nps:disparar-mensal` continua idempotente. Só a "completada" trava.
 *
 * -----------------------------------------------------------------------------
 * Split por driver (research §2)
 * -----------------------------------------------------------------------------
 *
 *   MySQL 5.7+ / MariaDB 10.2+  ->  virtual generated column `dedup_key`
 *                                   VARCHAR(64) + UNIQUE INDEX na coluna.
 *                                   Quando a survey NÃO conta pra dedup, a
 *                                   coluna é NULL. NULL não colide com NULL
 *                                   em UNIQUE (comportamento nativo do MySQL),
 *                                   então surveys pending livres.
 *
 *   SQLite 3.31+                ->  partial UNIQUE INDEX nativo, sem coluna
 *                                   nova. É mais expressivo e evita o bug
 *                                   conhecido de Schema::table()->virtualAs()
 *                                   emitir sintaxe incompatível com SQLite
 *                                   in-memory (research §2).
 *
 * Por que virtual column em vez de real column?
 *   - Real column obrigaria triggers para manter em sincronia com status /
 *     completed_at (ou um UPDATE explícito no controller). Virtual generated
 *     resolve de graça — o MySQL recalcula na leitura, sem custo em disco.
 *
 * Por que DATE_FORMAT(month_reference, '%Y-%m') no CONCAT?
 *   - `month_reference` é DATE (YYYY-MM-DD) no MySQL. Se concatenássemos
 *     direto, o dia entraria no dedup_key. O NpsController do Phase 31
 *     grava sempre YYYY-MM-01, então na prática dia=01, mas o formato
 *     `%Y-%m` deixa a chave imune a variação futura (defensivo).
 *
 * -----------------------------------------------------------------------------
 * Ordem timestamp: 100005
 * -----------------------------------------------------------------------------
 *
 *   Esta migration roda APÓS `2026_07_07_100004` (seed retro-associativo do
 *   Plan 68-03). Ordem CRÍTICA em prod: se rodasse ANTES, surveys legadas
 *   ainda estariam com template_id=NULL e o CONCAT geraria "1|2026-07|" (sem
 *   template) — não colidiria com nada, mas assim que o seed rodasse depois
 *   e populasse template_id, a virtual column recalcularia e PODERIA gerar
 *   duplicata em surveys históricas mal formadas. Rodando DEPOIS do seed,
 *   qualquer conflito legado é detectado pelo seed (guard app-level) antes
 *   do índice entrar em vigor.
 *
 * -----------------------------------------------------------------------------
 * Idempotência
 * -----------------------------------------------------------------------------
 *
 *   - SQLite: query `sqlite_master` antes do CREATE UNIQUE INDEX; skip com
 *     Log::info se já existe.
 *   - MySQL/MariaDB: `Schema::hasColumn('nps_surveys', 'dedup_key')` antes
 *     do ALTER TABLE ADD COLUMN; skip com Log::info se já existe.
 *
 * @see .planning/research/v15-nps-templates-schema.md §2
 * @see .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-04-PLAN.md
 * @see database/migrations/2026_07_07_100002_alter_nps_surveys_add_template_id.php (fonte de template_id)
 * @see REQ NPS-A-01 (schema dedup completo) + NPS-B-03 (guard controller Phase 69)
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // ─── SQLite 3.31+: partial UNIQUE INDEX nativo ───
            // Sem coluna nova — o WHERE do índice filtra direto na semântica
            // de "survey completa". Idempotente via sqlite_master.
            $existe = DB::selectOne(
                "SELECT name FROM sqlite_master WHERE type='index' AND name='nps_surveys_dedup_uniq'"
            );

            if ($existe) {
                Log::info('[Phase 68 Plan 04] Índice nps_surveys_dedup_uniq já existe (SQLite) — skip');
                return;
            }

            DB::statement("CREATE UNIQUE INDEX nps_surveys_dedup_uniq
                ON nps_surveys(company_id, month_reference, template_id)
                WHERE status = 'completed' AND completed_at IS NOT NULL");

            return;
        }

        // ─── MySQL / MariaDB: virtual generated column + UNIQUE INDEX ───
        // Idempotente via Schema::hasColumn. NULL da coluna não colide com
        // outros NULLs em UNIQUE, então pending / expired ficam livres.
        if (Schema::hasColumn('nps_surveys', 'dedup_key')) {
            Log::info('[Phase 68 Plan 04] Coluna dedup_key já existe (MySQL/MariaDB) — skip');
            return;
        }

        DB::statement("ALTER TABLE nps_surveys ADD COLUMN dedup_key VARCHAR(64)
            GENERATED ALWAYS AS (
                CASE
                    WHEN status = 'completed' AND completed_at IS NOT NULL
                    THEN CONCAT(company_id, '|', DATE_FORMAT(month_reference, '%Y-%m'), '|', COALESCE(template_id, 0))
                END
            ) VIRTUAL");

        DB::statement("ALTER TABLE nps_surveys ADD UNIQUE INDEX nps_surveys_dedup_uniq (dedup_key)");
    }

    /**
     * Reverte a estrutura de dedup.
     *
     * SQLite  : só dropa o partial index (nenhuma coluna foi criada).
     * MySQL   : dropa UNIQUE INDEX primeiro, depois a coluna virtual
     *           (ordem obrigatória — MySQL bloqueia DROP COLUMN se ainda
     *            houver índice usando ela).
     *
     * Idempotente: guard antes de cada DROP.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS nps_surveys_dedup_uniq");
            return;
        }

        // MySQL/MariaDB — drop unique primeiro, depois a coluna.
        // Try/catch defensivo no drop do índice: se a coluna existe mas o
        // índice foi removido manualmente, o DROP INDEX falharia; a gente
        // continua pro DROP COLUMN mesmo assim.
        try {
            DB::statement("ALTER TABLE nps_surveys DROP INDEX nps_surveys_dedup_uniq");
        } catch (\Throwable $e) {
            Log::warning('[Phase 68 Plan 04] DROP INDEX nps_surveys_dedup_uniq falhou (provavelmente já removido): ' . $e->getMessage());
        }

        if (Schema::hasColumn('nps_surveys', 'dedup_key')) {
            DB::statement("ALTER TABLE nps_surveys DROP COLUMN dedup_key");
        }
    }
};
