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
 * IDEMPOTENTE + cross-driver (o SQLite dos testes enforça constraints; o MySQL/
 * MariaDB de produção tem o gotcha do índice usado por FK). Passos com guardas de
 * existência para ser re-executável sobre um estado PARCIAL (deploy 2026-07-14 que
 * criou coluna+FK mas falhou ao dropar o unique de 3 col):
 *  1) ADD COLUMN nullable + índice — só se a coluna não existir (SQLite não
 *     adiciona FK em ALTER, por isso a FK é passo separado).
 *  2) FK apenas no MySQL/MariaDB — só se ainda não existir.
 *  3) Swap do unique: adiciona o unique de 4 col ANTES de dropar o de 3 col
 *     (crítico no MySQL — a FK de company_id precisa de um índice de cobertura;
 *     com o unique de 4 col presente, a FK migra pra ele e o drop do de 3 col
 *     deixa de dar o erro 1553 "needed in a foreign key constraint").
 *  4) Data-migration idempotente (whereNull).
 */
return new class extends Migration
{
    private const IDX_UNIQUE_3 = 'company_users_company_id_user_id_role_unique';
    private const IDX_UNIQUE_4 = 'company_users_company_id_user_id_role_servico_id_unique';
    private const IDX_SERVICO   = 'company_users_servico_id_index';
    private const FK_SERVICO    = 'company_users_servico_id_foreign';

    public function up(): void
    {
        // 1) Coluna nullable + índice (só se ainda não existir — estado parcial).
        if (! Schema::hasColumn('company_users', 'servico_id')) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->unsignedBigInteger('servico_id')->nullable()->after('role');
                $t->index('servico_id'); // acelera os filtros service-aware (Phase 78)
            });
        }

        // 2) FK só no MySQL/MariaDB — só se ainda não existir.
        if (DB::getDriverName() === 'mysql' && ! $this->hasForeignKey('company_users', self::FK_SERVICO)) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->foreign('servico_id')->references('id')->on('servicos')->nullOnDelete();
            });
        }

        // 3) Swap do unique — ADICIONA o de 4 col ANTES de dropar o de 3 col.
        //    (No MySQL, o unique de 3 col é o índice de cobertura da FK de
        //    company_id; dropá-lo sem outro índice-prefixo dá o erro 1553. Com o
        //    unique de 4 col — company_id é o prefixo à esquerda — a FK migra pra
        //    ele e o drop passa.)
        if (! $this->hasIndex('company_users', self::IDX_UNIQUE_4)) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->unique(['company_id', 'user_id', 'role', 'servico_id']);
            });
        }
        if ($this->hasIndex('company_users', self::IDX_UNIQUE_3)) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->dropUnique(['company_id', 'user_id', 'role']);
            });
        }

        // 4) Backfill idempotente das linhas existentes (depois do schema pronto).
        $this->migrarLinhasExistentes();
    }

    public function down(): void
    {
        // Ordem inversa e simétrica ao up(): zera servico_id → restaura unique de
        // 3 col ANTES de dropar o de 4 col (mesmo gotcha da FK no MySQL) → (só
        // MySQL) drop FK → drop índice/coluna. Tudo com guardas.
        if (Schema::hasColumn('company_users', 'servico_id')) {
            DB::table('company_users')->whereNotNull('servico_id')->update(['servico_id' => null]);
        }

        if (! $this->hasIndex('company_users', self::IDX_UNIQUE_3)) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->unique(['company_id', 'user_id', 'role']);
            });
        }
        if ($this->hasIndex('company_users', self::IDX_UNIQUE_4)) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->dropUnique(['company_id', 'user_id', 'role', 'servico_id']);
            });
        }

        if (DB::getDriverName() === 'mysql' && $this->hasForeignKey('company_users', self::FK_SERVICO)) {
            Schema::table('company_users', function (Blueprint $t) {
                $t->dropForeign(['servico_id']);
            });
        }

        if (Schema::hasColumn('company_users', 'servico_id')) {
            Schema::table('company_users', function (Blueprint $t) {
                if ($this->hasIndex('company_users', self::IDX_SERVICO)) {
                    $t->dropIndex(['servico_id']);
                }
                $t->dropColumn('servico_id');
            });
        }
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

    /**
     * Índice existe? Cross-driver (information_schema no MySQL; PRAGMA no SQLite).
     */
    private function hasIndex(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        // SQLite: PRAGMA index_list retorna os índices nomeados da tabela.
        foreach (DB::select("PRAGMA index_list(" . DB::getPdo()->quote($table) . ")") as $row) {
            if (($row->name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * Foreign key existe? Só faz sentido no MySQL/MariaDB (SQLite não nomeia FK
     * adicionada em ALTER; o branch de FK só roda no MySQL de qualquer forma).
     */
    private function hasForeignKey(string $table, string $constraint): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
