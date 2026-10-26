<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Derruba o unique LEGADO `(user_id, ref_date)` de `desempenho_score_snapshots`
 * — o que faz `desempenho:consolidar-mes` estourar 1062 desde julho/2026.
 *
 * ### O que acontecia
 * A migration `2026_07_09_140001` (Fase 74, D-03) já mandava trocar o unique
 * `(user_id, ref_date)` pelo `(user_id, ref_date, mes_referencia)`, para o
 * snapshot DIÁRIO (`mes_referencia` NULL) e o MENSAL FECHADO
 * (`mes_referencia = YYYY-MM-01`) poderem coexistir na mesma data. Ela rodou
 * (batch 73), mas **na ordem errada**: dropou o antigo ANTES de criar o novo.
 * Nesse instante `(user_id, ref_date)` era o único índice prefixado por
 * `user_id`, e a FK `desempenho_score_snapshots_user_id_foreign` depende de um
 * — o MariaDB recusou com 1553, e o `try/catch (\Throwable)` de idempotência
 * engoliu a recusa em silêncio. É exatamente a armadilha registrada em
 * `.planning/learnings/desempenho-bonificacao.md` §6 ("adicione o índice novo
 * ANTES de dropar o antigo").
 *
 * Resultado em produção: os dois uniques convivendo. Como o legado ignora
 * `mes_referencia`, a linha diária de 01/07 (gravada por
 * `desempenho:snapshot-scores` em 2026-07-01 13:30) ocupa `(user, 2026-07-01)`
 * e o `updateOrCreate(['user_id','mes_referencia'])` do `consolidar-mes` — que
 * busca por uma chave onde a diária não bate (`mes_referencia` NULL) — tenta
 * INSERT e colide. Medido em 2026-08-31: **11 dos 12 profissionais** com linha
 * diária em 01/07 falharam nas rodadas de 27/08, 28/08 e 31/08; só passou quem
 * não tinha diária naquela data. Junho escapou por acaso — a diária de
 * 01/06 já tinha saído pela retenção (a mais antiga viva é de 30/06).
 *
 * ### Por que dropar agora é seguro
 * `desempenho_score_snapshots_user_ref_mes_unique` (user_id, ref_date,
 * mes_referencia) já existe e também começa em `user_id`, então a FK passa a
 * se apoiar nele e o 1553 não se repete. A unicidade real não afrouxa: o
 * legado é um PREFIXO do novo, ou seja, tudo que ele barrava e ainda deve ser
 * barrado (duas diárias do mesmo user na mesma data) segue barrado pelo novo.
 * O que deixa de ser barrado é justamente o que a Fase 74 queria permitir.
 *
 * Idempotente: só age se o índice legado existir.
 */
return new class extends Migration
{
    private const TABELA = 'desempenho_score_snapshots';
    private const INDICE_LEGADO = 'desempenho_score_snapshots_user_id_ref_date_unique';
    private const INDICE_NOVO   = 'desempenho_score_snapshots_user_ref_mes_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELA)) {
            return;
        }

        if (! $this->indiceExiste(self::INDICE_LEGADO)) {
            return;
        }

        // Guarda dura: sem o índice substituto, dropar o legado deixaria a FK
        // sem índice de apoio e o MariaDB recusaria (1553) — o mesmo 1553 que
        // criou este problema. Recriar o substituto aqui, em vez de abortar,
        // mantém a migration executável num banco que só rodou a 140001 pela
        // metade.
        if (! $this->indiceExiste(self::INDICE_NOVO)) {
            Schema::table(self::TABELA, function (Blueprint $table) {
                $table->unique(['user_id', 'ref_date', 'mes_referencia'], self::INDICE_NOVO);
            });
        }

        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->dropUnique(self::INDICE_LEGADO);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABELA) || $this->indiceExiste(self::INDICE_LEGADO)) {
            return;
        }

        // Recriar o legado só é possível se não houver par (user_id, ref_date)
        // repetido — e depois de uma consolidação bem-sucedida ele HAVERÁ
        // (diária + mensal na mesma data). O down é, portanto, best-effort:
        // falhar aqui é informação, não acidente.
        Schema::table(self::TABELA, function (Blueprint $table) {
            $table->unique(['user_id', 'ref_date'], self::INDICE_LEGADO);
        });
    }

    /**
     * `SHOW INDEX` em vez de `doctrine/dbal` — o projeto não tem o dbal
     * instalado, e `Schema::hasIndex()` só existe no Laravel 11+ para alguns
     * drivers. Em SQLite (testes) o `PRAGMA index_list` responde o equivalente.
     */
    private function indiceExiste(string $nome): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA index_list(' . self::TABELA . ')') as $i) {
                if (($i->name ?? null) === $nome) {
                    return true;
                }
            }

            return false;
        }

        foreach (DB::select('SHOW INDEX FROM ' . self::TABELA) as $i) {
            if (($i->Key_name ?? null) === $nome) {
                return true;
            }
        }

        return false;
    }
};
