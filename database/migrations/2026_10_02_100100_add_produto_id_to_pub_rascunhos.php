<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 160 Plano 160-01 (D15, D27) — `pub_rascunhos` passa a ser de um PRODUTO
 * (`pub_produtos`), com backfill dos rascunhos que já existem em produção.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2). Tabela com dado real (#459):
 * - `produto_id` bigint unsigned, NOT NULL no fim, unique `pubr_produto_uq`, FK
 *   `pubr_produto_fk` -> pub_produtos com cascade. SEM `nullOnDelete`: a coluna é
 *   NOT NULL e SET NULL em coluna NOT NULL dá o erro 1830 do MariaDB.
 * - `oferta_id` passa a aceitar NULL e vira coluna LEGADA DORMENTE (D27, opção a):
 *   nada mais a grava depois do 160-02; o vínculo com a oferta mora em
 *   `pub_produtos.oferta_id` (SET NULL ao apagar a oferta). `pubr_oferta_fk` e
 *   `pubr_oferta_uq` NÃO se dropam nem se recriam (DDL em FK de tabela com dado é
 *   a família do erro 1553); com a coluna NULL a cascata não leva rascunho, e NULL
 *   repetido passa no unique. Coluna dormente: não dropar, não recriar.
 * - Nomes curtos explícitos (limite de 64 chars, erro 1059).
 *
 * Ordem do up(), cada passo idempotente: (1) coluna nullable; (2) `oferta_id`
 * nullable; (3) backfill por `DB::table`: cria/reaproveita o `pub_produtos` da
 * oferta (origem `portal`) e, no mesmo update, grava `produto_id` e zera `oferta_id`;
 * (4) recusa se sobrar rascunho sem produto; (5) NOT NULL, depois unique e FK em
 * `Schema::table` separados, sob checagem de existência. Proibido try/catch em
 * volta de DDL (o 1553 da 2026_07_09_140001 foi engolido assim por 2 meses).
 *
 * down(): devolve `oferta_id` aos rascunhos a partir do produto; recusa se ainda
 * restar rascunho sem oferta (não dá para voltar NOT NULL sem perder dado); depois
 * FK, unique e coluna, nessa ordem. Nunca apaga `pub_produtos` (é o down() da A).
 *
 * Rodar no MariaDB local com `--path`; o SQLite dos testes não pega 1553/1059/1830.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Coluna nova, ainda anulável, para o backfill poder rodar.
        if (! Schema::hasColumn('pub_rascunhos', 'produto_id')) {
            Schema::table('pub_rascunhos', function (Blueprint $t) {
                $t->unsignedBigInteger('produto_id')->nullable()->after('id');
            });
        }

        // 2) oferta_id aceita NULL (coluna legada dormente). Índice e FK ficam como estão.
        Schema::table('pub_rascunhos', function (Blueprint $t) {
            $t->unsignedBigInteger('oferta_id')->nullable()->change();
        });

        // 3) Backfill: um produto de origem portal por oferta; o vínculo passa para o produto.
        $agora = now();
        $legados = DB::table('pub_rascunhos')->whereNull('produto_id')->get(['id', 'oferta_id']);
        foreach ($legados as $rascunho) {
            $oferta = $rascunho->oferta_id === null ? null : DB::table('estrutura_ofertas')->where('id', $rascunho->oferta_id)->first();
            if ($oferta === null) {
                continue; // sem oferta não há de onde tirar o produto: o passo 4 recusa
            }

            $produtoId = DB::table('pub_produtos')->where('oferta_id', $oferta->id)->value('id');
            if ($produtoId === null) {
                $produtoId = DB::table('pub_produtos')->insertGetId([
                    'company_id' => $oferta->company_id,
                    'mlb_empresa_id' => null,
                    'oferta_id' => $oferta->id,
                    'sku' => $oferta->sku,
                    'nome' => $oferta->nome ?: $oferta->sku,
                    'origem' => 'portal',
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }

            DB::table('pub_rascunhos')->where('id', $rascunho->id)->update([
                'produto_id' => $produtoId,
                'oferta_id' => null,
            ]);
        }

        // 4) Nada de engolir: rascunho sem produto derruba a migration antes do NOT NULL.
        $sobrou = DB::table('pub_rascunhos')->whereNull('produto_id')->count();
        if ($sobrou > 0) {
            throw new \RuntimeException("Backfill incompleto: {$sobrou} rascunho(s) em pub_rascunhos continuam sem produto_id (oferta ausente).");
        }

        // 5) NOT NULL, depois unique e FK.
        Schema::table('pub_rascunhos', function (Blueprint $t) {
            $t->unsignedBigInteger('produto_id')->nullable(false)->change();
        });

        if (! $this->hasIndex('pub_rascunhos', 'pubr_produto_uq')) {
            Schema::table('pub_rascunhos', function (Blueprint $t) {
                $t->unique('produto_id', 'pubr_produto_uq');
            });
        }

        if (! $this->hasForeignKey('pub_rascunhos', 'pubr_produto_fk')) {
            Schema::table('pub_rascunhos', function (Blueprint $t) {
                $t->foreign('produto_id', 'pubr_produto_fk')->references('id')->on('pub_produtos')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pub_rascunhos', 'produto_id')) {
            return;
        }

        // Devolve o vínculo à coluna a partir do produto, antes de qualquer DDL.
        $semOferta = DB::table('pub_rascunhos')->whereNull('oferta_id')->get(['id', 'produto_id']);
        foreach ($semOferta as $rascunho) {
            $ofertaId = DB::table('pub_produtos')->where('id', $rascunho->produto_id)->value('oferta_id');
            if ($ofertaId !== null) {
                DB::table('pub_rascunhos')->where('id', $rascunho->id)->update(['oferta_id' => $ofertaId]);
            }
        }

        $restantes = DB::table('pub_rascunhos')->whereNull('oferta_id')->count();
        if ($restantes > 0) {
            throw new \RuntimeException(
                'Rollback recusado: há rascunho de produto sem oferta; não dá para voltar oferta_id a NOT NULL sem perder dado '
                ."({$restantes} rascunho(s))."
            );
        }

        // FK primeiro (ela usa o unique como índice de apoio), depois o unique, depois a coluna.
        // No SQLite a FK não tem nome: cai pela forma de coluna (reconstrói a tabela).
        if ($this->hasForeignKey('pub_rascunhos', 'pubr_produto_fk')) {
            Schema::table('pub_rascunhos', function (Blueprint $t) {
                $t->dropForeign(DB::getDriverName() === 'mysql' ? 'pubr_produto_fk' : ['produto_id']);
            });
        }
        if ($this->hasIndex('pub_rascunhos', 'pubr_produto_uq')) {
            Schema::table('pub_rascunhos', function (Blueprint $t) {
                $t->dropUnique('pubr_produto_uq');
            });
        }
        Schema::table('pub_rascunhos', function (Blueprint $t) {
            $t->dropColumn('produto_id');
        });

        Schema::table('pub_rascunhos', function (Blueprint $t) {
            $t->unsignedBigInteger('oferta_id')->nullable(false)->change();
        });
    }

    /** Índice existe? Cross-driver (information_schema no MySQL/MariaDB; PRAGMA no SQLite). */
    private function hasIndex(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        foreach (DB::select('PRAGMA index_list('.DB::getPdo()->quote($table).')') as $row) {
            if (($row->name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    /** FK existe? information_schema no MySQL/MariaDB; no SQLite, pela coluna de origem (as FKs não têm nome lá). */
    private function hasForeignKey(string $table, string $fk): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $fk)
                ->exists();
        }

        foreach (DB::select('PRAGMA foreign_key_list('.DB::getPdo()->quote($table).')') as $row) {
            if (($row->from ?? null) === 'produto_id') {
                return true;
            }
        }

        return false;
    }
};
