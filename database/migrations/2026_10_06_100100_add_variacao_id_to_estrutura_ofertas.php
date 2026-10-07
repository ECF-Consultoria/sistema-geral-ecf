<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 167 — `estrutura_ofertas` ganha o vínculo com a variação do produto. É o ÚNICO
 * ALTER da fase em tabela com dado em produção.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2):
 * - `variacao_id` bigint unsigned NULL PARA SEMPRE (ofertas antigas e combos não têm variação).
 * - unique `eo_variacao_uq`: no máximo uma oferta por variação (NULL repete).
 * - FK `eo_variacao_fk` -> estrutura_produto_variacoes.id com `nullOnDelete`: a coluna é
 *   nullable (sem erro 1830). `restrict` daria 1451 na exclusão em cascata de uma Company
 *   (companies -> variacoes e companies -> ofertas não têm ordem garantida). A proteção real
 *   é de serviço e do unique.
 * - SEM backfill (D-09): nenhuma oferta existente muda.
 * - `eo_company_idx`/`eo_company_fk` NÃO são tocados (família do erro 1553).
 * - Nomes curtos explícitos (< 64, erro 1059).
 *
 * Idempotente: cada DDL num `Schema::table` separado, sob checagem de existência. Proibido
 * tratamento de exceção em volta de DDL. down(): só tira o VÍNCULO, nunca a oferta.
 *
 * Rodar no MariaDB local com `--path`; o SQLite dos testes não pega 1553/1059/1830.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estrutura_ofertas', 'variacao_id')) {
            Schema::table('estrutura_ofertas', function (Blueprint $t) {
                $t->unsignedBigInteger('variacao_id')->nullable()->after('company_id');
            });
        }

        if (! $this->hasIndex('estrutura_ofertas', 'eo_variacao_uq')) {
            Schema::table('estrutura_ofertas', function (Blueprint $t) {
                $t->unique('variacao_id', 'eo_variacao_uq');
            });
        }

        if (! $this->hasForeignKey('estrutura_ofertas', 'eo_variacao_fk')) {
            Schema::table('estrutura_ofertas', function (Blueprint $t) {
                $t->foreign('variacao_id', 'eo_variacao_fk')->references('id')->on('estrutura_produto_variacoes')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('estrutura_ofertas', 'variacao_id')) {
            return;
        }

        // FK primeiro (usa o unique como índice de apoio), depois o unique, depois a coluna.
        // No SQLite a FK não tem nome: cai pela forma de coluna.
        if ($this->hasForeignKey('estrutura_ofertas', 'eo_variacao_fk')) {
            Schema::table('estrutura_ofertas', function (Blueprint $t) {
                $t->dropForeign($this->emMysql() ? 'eo_variacao_fk' : ['variacao_id']);
            });
        }
        if ($this->hasIndex('estrutura_ofertas', 'eo_variacao_uq')) {
            Schema::table('estrutura_ofertas', function (Blueprint $t) {
                $t->dropUnique('eo_variacao_uq');
            });
        }
        Schema::table('estrutura_ofertas', function (Blueprint $t) {
            $t->dropColumn('variacao_id');
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
            if (($row->from ?? null) === 'variacao_id') {
                return true;
            }
        }

        return false;
    }
};
