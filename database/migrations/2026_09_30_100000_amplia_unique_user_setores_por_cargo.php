<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 159 Plano 159-01 (D-01) — DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita
 * POR ESCRITO no plano `159-01-PLAN.md` antes desta migration existir. Copiada aqui
 * como docblock da classe, em pt-BR, conforme exigido:
 *
 * - Tabela: `user_setores` (dado em produção). Nenhuma coluna nova, nenhuma coluna
 *   removida.
 * - Unique antigo: `user_setores_user_id_setor_id_unique` sobre (user_id, setor_id) —
 *   36 chars.
 * - Unique novo: `user_setores_user_id_setor_id_cargo_id_unique` sobre (user_id,
 *   setor_id, cargo_id) — 45 chars, abaixo do limite de 64 do MariaDB (learnings §6,
 *   erro 1059). Nome EXPLÍCITO como segundo argumento de `$t->unique([...], IDX)`.
 * - Ordem do up(): (1) cria o unique novo se `hasIndex` disser que não existe; (2) só
 *   depois dropa o antigo se `hasIndex` disser que existe. Motivo: no MariaDB o
 *   unique antigo é o índice de apoio da FK `user_setores_user_id_foreign` (o índice
 *   implícito da FK é descartado pelo MariaDB quando outro índice com user_id à
 *   esquerda passa a existir); dropá-lo primeiro dá o erro 1553 — exatamente o
 *   incidente da migration 2026_07_09_140001 (learnings §10.1). Com o novo criado
 *   antes, user_id continua prefixo à esquerda e a FK migra para ele.
 * - Idempotência: só por `hasIndex()` (helper cross-driver copiado literalmente do
 *   molde `2026_07_14_000001_add_servico_id_to_company_users.php`: information_schema
 *   no MySQL/MariaDB, PRAGMA index_list no SQLite). PROIBIDO qualquer try/catch em
 *   volta de Schema::table — erro de banco tem de derrubar a migration (learnings
 *   §10.1: o try/catch da 140001 engoliu o 1553 e deixou os dois uniques vivos por 2
 *   meses, sem ninguém perceber).
 * - `cargo_id` NULL: MariaDB e SQLite tratam NULL como distinto dentro de unique,
 *   então o banco NÃO impede duas linhas (user, setor, NULL). A defesa passa a ser
 *   de aplicação: `UserController` (Task 3 deste plano) recusa dois vínculos no
 *   mesmo setor quando algum não tem cargo, e `SetorMembroController` (plano 159-02)
 *   converte a linha sem cargo em vez de criar outra. Limitação conhecida, registrada
 *   aqui de propósito — não é bug, é escolha de onde mora a defesa.
 * - `is_principal`: coluna mantida sem mudança. Regra: no máximo UMA linha principal
 *   por USUÁRIO (já é o que `UserController::syncVinculos` e
 *   `SetorMembroController::storeMembro` fazem). Com duas linhas no mesmo setor, a
 *   principal passa a indicar também qual é o CARGO principal — lida por
 *   `User::cargoDesempenhoSlug()` (orderByDesc is_principal) e, por consequência, por
 *   `User::dimensaoNpsDesempenho()` (D-09). Dupla leitura da mesma coluna registrada
 *   aqui de propósito.
 * - down(): antes de tocar em qualquer índice, conta grupos (user_id, setor_id) com
 *   mais de uma linha; havendo algum, lança RuntimeException — "Rollback recusado: N
 *   pessoa(s) com dois cargos no mesmo setor em user_setores. Remova o cargo
 *   excedente pela tela antes do rollback — a migration não apaga vínculo." Sem
 *   duplicidade: recria o unique antigo (se ausente) ANTES de dropar o novo (se
 *   presente) — mesma ordem espelhada do up().
 *
 * A verificação real no MariaDB (`SHOW INDEX FROM user_setores`) acontece no plano
 * 159-07 — o SQLite dos testes não exige índice de apoio de FK e não reproduz o 1553.
 */
return new class extends Migration
{
    private const IDX_UNIQUE_2 = 'user_setores_user_id_setor_id_unique';
    private const IDX_UNIQUE_3 = 'user_setores_user_id_setor_id_cargo_id_unique';

    public function up(): void
    {
        // 1) Cria o unique de 3 colunas ANTES de dropar o de 2 — user_id continua
        //    como prefixo à esquerda nos dois, então a FK de user_id nunca fica sem
        //    índice de cobertura (evita o 1553 do learnings §6/§10.1).
        if (! $this->hasIndex('user_setores', self::IDX_UNIQUE_3)) {
            Schema::table('user_setores', function (Blueprint $t) {
                $t->unique(['user_id', 'setor_id', 'cargo_id'], self::IDX_UNIQUE_3);
            });
        }

        // 2) Só depois dropa o unique antigo, se ele ainda existir.
        if ($this->hasIndex('user_setores', self::IDX_UNIQUE_2)) {
            Schema::table('user_setores', function (Blueprint $t) {
                $t->dropUnique(self::IDX_UNIQUE_2);
            });
        }
    }

    public function down(): void
    {
        // Rollback recusado quando existe gente com dois cargos no mesmo setor —
        // apagar o índice de 3 colunas sem isso destruiria a garantia que o D-01
        // pediu. Contagem ANTES de tocar em qualquer índice.
        $duplicados = DB::table('user_setores')
            ->select('user_id', 'setor_id')
            ->groupBy('user_id', 'setor_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicados > 0) {
            throw new \RuntimeException(
                "Rollback recusado: {$duplicados} pessoa(s) com dois cargos no mesmo setor em " .
                'user_setores. Remova o cargo excedente pela tela antes do rollback — a migration ' .
                'não apaga vínculo.'
            );
        }

        // Sem duplicidade: recria o unique antigo ANTES de dropar o novo — mesma
        // ordem espelhada do up() (user_id nunca fica sem índice de cobertura da FK).
        if (! $this->hasIndex('user_setores', self::IDX_UNIQUE_2)) {
            Schema::table('user_setores', function (Blueprint $t) {
                $t->unique(['user_id', 'setor_id'], self::IDX_UNIQUE_2);
            });
        }

        if ($this->hasIndex('user_setores', self::IDX_UNIQUE_3)) {
            Schema::table('user_setores', function (Blueprint $t) {
                $t->dropUnique(self::IDX_UNIQUE_3);
            });
        }
    }

    /**
     * Índice existe? Cross-driver (information_schema no MySQL/MariaDB; PRAGMA no
     * SQLite). Copiado literalmente do molde
     * `2026_07_14_000001_add_servico_id_to_company_users.php`.
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
        foreach (DB::select('PRAGMA index_list(' . DB::getPdo()->quote($table) . ')') as $row) {
            if (($row->name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
