<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 172 (agrupamento do D-06) — `pub_produtos.estrutura_produto_id`: liga o produto do
 * Publicador ao produto da ficha do Portal. É o ÚNICO ALTER da fase em tabela com dado em
 * produção (rascunhos ancorados da #459).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2):
 * - `estrutura_produto_id` bigint unsigned NULL PARA SEMPRE (produto cadastrado no Publicador
 *   ou ligado só por oferta não tem produto do Portal).
 * - unique `pubprod_eprod_uq`: no máximo um pub_produto por produto do Portal (NULL repete).
 * - FK `pubprod_eprod_fk` -> estrutura_produtos.id com `nullOnDelete`: a coluna é anulável
 *   (sem erro 1830). Apagar o produto no Portal SOLTA o pub_produto e preserva rascunho e
 *   histórico de publicação (mesma filosofia do CR-B02). `restrict` daria 1451 na exclusão
 *   em cascata de uma Company.
 * - SEM backfill: nenhum pub_produto existente muda (continuam com NULL).
 * - Índices/FKs existentes de `pub_produtos` NÃO são tocados (família do erro 1553).
 * - Nomes curtos explícitos (< 64, erro 1059).
 *
 * Idempotente: cada DDL num `Schema::table` separado, sob checagem de existência. Proibido
 * tratamento de exceção em volta de DDL. down(): só tira o VÍNCULO, nunca o pub_produto.
 *
 * Rodar no MariaDB local com `--path`; o SQLite dos testes não pega 1553/1059/1830.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pub_produtos', 'estrutura_produto_id')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->unsignedBigInteger('estrutura_produto_id')->nullable()->after('oferta_id');
            });
        }

        if (! $this->hasIndex('pub_produtos', 'pubprod_eprod_uq')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->unique('estrutura_produto_id', 'pubprod_eprod_uq');
            });
        }

        if (! $this->hasForeignKey('pub_produtos', 'pubprod_eprod_fk')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->foreign('estrutura_produto_id', 'pubprod_eprod_fk')->references('id')->on('estrutura_produtos')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pub_produtos', 'estrutura_produto_id')) {
            return;
        }

        // FK primeiro (usa o unique como índice de apoio), depois o unique, depois a coluna (1553).
        // No SQLite a FK não tem nome: cai pela forma de coluna.
        if ($this->hasForeignKey('pub_produtos', 'pubprod_eprod_fk')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->dropForeign($this->emMysql() ? 'pubprod_eprod_fk' : ['estrutura_produto_id']);
            });
        }
        if ($this->hasIndex('pub_produtos', 'pubprod_eprod_uq')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->dropUnique('pubprod_eprod_uq');
            });
        }
        Schema::table('pub_produtos', function (Blueprint $t) {
            $t->dropColumn('estrutura_produto_id');
        });
    }

    /** MySQL OU MariaDB (WR-B06): o driver `mariadb` do Laravel 11+ não pode cair no PRAGMA do SQLite. */
    private function emMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Índice existe? Cross-driver (information_schema no MySQL/MariaDB; PRAGMA no SQLite). */
    private function hasIndex(string $table, string $index): bool
    {
        if ($this->emMysql()) {
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
        if ($this->emMysql()) {
            return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $fk)
                ->exists();
        }

        foreach (DB::select('PRAGMA foreign_key_list('.DB::getPdo()->quote($table).')') as $row) {
            if (($row->from ?? null) === 'estrutura_produto_id') {
                return true;
            }
        }

        return false;
    }
};
