<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fundação v16.0 (Phase 76 / DEC-A1) — dá à pivot `company_users` uma dimensão
 * de serviço: coluna `servico_id` (nullable, FK `servicos`), unique passa de
 * (company_id, user_id, role) para (company_id, user_id, role, servico_id), e as
 * linhas existentes são migradas para o `servico_id` do contrato performance
 * ativo da empresa (ou permanecem NULL = consolidado/legado — nunca inventar
 * serviço).
 *
 * INVARIANTE: `servico_id` NULL = responsável consolidado (comportamento de hoje).
 * Valor = responsável daquele serviço específico.
 *
 * Cross-driver em passos separados (o SQLite dos testes enforça constraints):
 *  1) ADD COLUMN nullable — nativo em MySQL E SQLite (sem ->constrained(): SQLite
 *     não adiciona FK em ALTER TABLE).
 *  2) FK apenas no branch MySQL/MariaDB.
 *  3) Swap do unique 3→4 colunas.
 *  4) Data-migration idempotente (whereNull).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Coluna nullable + índice. NÃO encadear ->constrained() aqui —
        //    SQLite não adiciona FK em ALTER TABLE (Pitfall 5 do RESEARCH).
        Schema::table('company_users', function (Blueprint $t) {
            $t->unsignedBigInteger('servico_id')->nullable()->after('role');
            $t->index('servico_id'); // acelera os filtros service-aware (Phase 78)
        });

        // 2) FK só no MySQL/MariaDB — SQLite dos testes dispensa (não valida
        //    integridade referencial de FK adicionada em alter).
        if (DB::getDriverName() === 'mysql') {
            Schema::table('company_users', function (Blueprint $t) {
                $t->foreign('servico_id')->references('id')->on('servicos')->nullOnDelete();
            });
        }

        // 3) Swap do unique. O nome derivado
        //    'company_users_company_id_user_id_role_unique' existe em ambos os
        //    drivers (a migração 2026_05_22 recriou o unique no branch SQLite).
        Schema::table('company_users', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'user_id', 'role']);
            $t->unique(['company_id', 'user_id', 'role', 'servico_id']);
        });

        // 4) Backfill idempotente das linhas existentes (depois do schema pronto).
        $this->migrarLinhasExistentes();
    }

    public function down(): void
    {
        // Ordem inversa: zera servico_id → drop unique 4-col → restaura 3-col →
        // (só MySQL) drop FK → drop índice/coluna.
        DB::table('company_users')->whereNotNull('servico_id')->update(['servico_id' => null]);

        Schema::table('company_users', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'user_id', 'role', 'servico_id']);
            $t->unique(['company_id', 'user_id', 'role']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('company_users', function (Blueprint $t) {
                $t->dropForeign(['servico_id']);
            });
        }

        Schema::table('company_users', function (Blueprint $t) {
            $t->dropIndex(['servico_id']);
            $t->dropColumn('servico_id');
        });
    }

    /**
     * Data-migration idempotente: para cada empresa com contrato performance
     * ATIVO, preenche o `servico_id` das linhas ainda consolidadas (whereNull).
     * Empresas sem performance ativo permanecem NULL (nunca inventar serviço).
     *
     * PÚBLICO de propósito: o teste de idempotência reinvoca este método após o
     * migrate para provar que a 2ª passada não altera nenhuma linha.
     *
     * @return int Total de linhas de `company_users` afetadas nesta passada.
     */
    public function migrarLinhasExistentes(): int
    {
        // 1 query: mapa company_id → servico_id do contrato performance ativo.
        // MIN() torna determinístico caso a empresa tenha >1 contrato performance ativo.
        // Aliases explícitos (selectRaw) evitam o mismatch de nome de propriedade
        // que o pluck() teria com DB::raw + chave qualificada ('ct.company_id').
        $rows = DB::table('contratos_servico as ct')
            ->join('servicos as s', 's.id', '=', 'ct.servico_id')
            ->where('ct.ativo', true)
            ->where('s.setor', 'performance')
            ->groupBy('ct.company_id')
            ->selectRaw('ct.company_id as company_id, MIN(ct.servico_id) as servico_id')
            ->get();

        $afetadas = 0;

        foreach ($rows as $r) {
            $afetadas += DB::table('company_users')
                ->where('company_id', $r->company_id)
                ->whereNull('servico_id')          // idempotente: só toca linhas ainda consolidadas
                ->update(['servico_id' => $r->servico_id]);
        }

        // Empresas SEM contrato performance ativo → servico_id permanece NULL
        // (consolidado/legado). NUNCA usar where('servico_id', null) — SQL = NULL
        // nunca casa (Pitfall 1).
        return $afetadas;
    }
};
