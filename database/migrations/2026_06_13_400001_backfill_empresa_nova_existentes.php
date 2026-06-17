<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 35 Plan 35-01 (D-01) — Backfill defensivo de `empresa_nova` em empresas
 * pré-existentes.
 *
 * Contexto: a migration `2026_06_12_300001_add_close_fields_to_companies_table.php`
 * criou a coluna `empresa_nova` com default = true. Em produção isso marcou as
 * 168 empresas ativas como "novas", o que não é desejado — a tag só faz sentido
 * para empresas cadastradas A PARTIR de 2026-06-13.
 *
 * Estratégia: zera somente registros criados ANTES de 2026-06-13 e que ainda
 * estão com `empresa_nova=true` (idempotência — re-run = no-op em registros
 * já zerados). Empresas novas criadas depois desta data continuam sendo
 * marcadas via default da coluna.
 *
 * `empresa_nova_visto_por = null` documenta que foi backfill automático
 * (admin humano grava o próprio user_id quando clica em "Marcar como visto").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->where('created_at', '<', '2026-06-13 00:00:00')
            ->where('empresa_nova', true)
            ->update([
                'empresa_nova'           => false,
                'empresa_nova_visto_em'  => now(),
                'empresa_nova_visto_por' => null,
            ]);
    }

    public function down(): void
    {
        // No-op intencional — após o backfill rodar, não é possível distinguir
        // empresas que foram "marcadas como vistas" por humanos das que vieram
        // zeradas via backfill. Reverter perderia esse estado humano.
    }
};
