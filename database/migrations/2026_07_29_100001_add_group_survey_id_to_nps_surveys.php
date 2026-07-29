<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 119.1 Plan 05 — Coluna `group_survey_id` em `nps_surveys`: vínculo de
 * AUDITORIA do survey-espelho até o link de grupo que o originou.
 *
 * Importante (documentado aqui e no model): esta coluna é SÓ de
 * auditoria/UI. Nenhum consumidor de agregação deve filtrar por ela — do
 * ponto de vista de quem calcula média (área NPS, Desempenho/bônus), um
 * survey-espelho de grupo é um survey normal, indistinguível dos demais nas
 * duas réguas de dedupe já existentes.
 *
 * Nullable obrigatório — a esmagadora maioria dos surveys não vem de grupo
 * (mesmo requisito do MySQL 1830: FK com nullOnDelete() exige nullable()
 * explícito, senão o deploy quebra no MariaDB).
 *
 * -----------------------------------------------------------------------------
 * FIX (2026-07-29) — regressão no índice parcial de dedup (SQLite/testes)
 * -----------------------------------------------------------------------------
 *
 *   `Schema::table()` com `foreignId(...)->constrained()` obriga o SQLite a
 *   RECONSTRUIR a tabela inteira (cria tabela nova, copia linhas, dropa a
 *   antiga, renomeia) — é assim que o driver SQLite do Laravel implementa
 *   "adicionar FK numa tabela existente", já que SQLite não suporta
 *   `ALTER TABLE ADD CONSTRAINT` nativo.
 *
 *   Essa reconstrução recria os índices da tabela, mas SEM o predicado
 *   `WHERE` do índice único PARCIAL de dedup criado na Fase 68
 *   (`nps_surveys_dedup_uniq`, ver 2026_07_07_100005_add_dedup_key_to_nps_surveys.php).
 *   O parcial (só bloqueia `status = 'completed'`) degrada silenciosamente
 *   para um UNIQUE TOTAL em (company_id, month_reference, template_id) —
 *   passando a bloquear também surveys `pending`/`expired`, o que quebra a
 *   idempotência do `nps:disparar-mensal` e 6 testes da suíte.
 *
 *   Produção NÃO é afetada: no MySQL/MariaDB o dedup usa uma coluna gerada
 *   virtual (`dedup_key`) + UNIQUE INDEX na coluna, e `ALTER TABLE ADD COLUMN`
 *   não reconstrói a tabela nem os índices existentes. O ramo MySQL abaixo
 *   permanece intocado.
 *
 *   Fix: no ramo SQLite, após a reconstrução, dropamos o índice degradado e
 *   recriamos o parcial ORIGINAL da Fase 68, com o mesmo predicado exato.
 *   Idempotente (DROP INDEX IF EXISTS + CREATE sempre reflete o estado
 *   desejado).
 *
 * @see database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php
 * @see tests/Feature/Phase119_1/NpsSurveysDedupParcialSobreviveMigrationTest.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nps_surveys', 'group_survey_id')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                $table->foreignId('group_survey_id')
                    ->nullable()
                    ->after('template_id')
                    ->constrained('nps_group_surveys')
                    ->nullOnDelete();
            });

            $this->restaurarIndiceParcialDedupSeSqlite();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nps_surveys', 'group_survey_id')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                $table->dropConstrainedForeignId('group_survey_id');
            });

            $this->restaurarIndiceParcialDedupSeSqlite();
        }
    }

    /**
     * Restaura o índice único PARCIAL de dedup (Fase 68) após qualquer
     * `Schema::table()` que force reconstrução de tabela no SQLite.
     *
     * No-op em MySQL/MariaDB — o dedup lá é a coluna gerada `dedup_key`,
     * que não reconstrói e não precisa de reparo.
     */
    private function restaurarIndiceParcialDedupSeSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS nps_surveys_dedup_uniq');

        DB::statement("CREATE UNIQUE INDEX nps_surveys_dedup_uniq
            ON nps_surveys(company_id, month_reference, template_id)
            WHERE status = 'completed' AND completed_at IS NOT NULL");

        Log::info('[Fase 119.1 Plan 05] Índice parcial nps_surveys_dedup_uniq restaurado apos reconstrucao de tabela (SQLite)');
    }
};
