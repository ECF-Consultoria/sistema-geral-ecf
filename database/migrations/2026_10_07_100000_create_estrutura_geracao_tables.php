<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 168 — geração de ofertas (Combo, Kit e Combit) a partir dos produtos. Só CREATE de
 * 4 tabelas NOVAS (D-11): nenhuma tabela existente — `estrutura_ofertas`, `estrutura_produtos`
 * e as outras da 167 — é alterada. A semente é a migration seguinte, separada.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2).
 * - estrutura_tipos_produto (GLOBAL, da ECF): slug varchar(40) único (`etp_slug_uq`), nome,
 *   plural, `palavras` varchar(255) (normalizadas, separadas por ", "), `qtd_combo` e
 *   `qtd_combit` varchar(40) null, `ordem` smallint.
 * - estrutura_tipo_pares (GLOBAL): par NÃO ordenado, guardado com tipo_a_id <= tipo_b_id;
 *   `combit_repete` varchar(8) null ('a' | 'b' | 'ambos'; null = só Kit). Único (a, b).
 *   FKs cascade nos dois lados: excluir o tipo leva os pares dele.
 * - estrutura_produto_geracao (por empresa, 1:1 com o produto): PK = produto_id. Existe em
 *   tabela própria e NÃO como coluna de `estrutura_produtos` para que nenhuma tabela da 167
 *   receba ALTER; excluir o produto leva o ajuste (cascade). `tipo_id` NULLABLE com SET NULL
 *   (nullable, então sem o erro 1830): excluir o tipo devolve o produto à inferência.
 * - estrutura_sugestoes_descartadas (por empresa): única por (company_id, chave).
 *
 * Por que qtd como texto: "2, 4, 6" é lista; '0' = nenhuma; string vazia vira null pelo
 * ConvertEmptyStringsToNull.
 *
 * Armadilhas de MariaDB evitadas (learnings §6): nenhum `enum`, nenhum `json`, nenhum
 * `timestamp()` solto (`timestamps()` é nullable), nomes de índice e FK explícitos e curtos
 * (< 64, erro 1059), `nullOnDelete` só em coluna nullable (1830), nenhum índice usado por FK é
 * dropado (1553).
 *
 * IDEMPOTENTE (BE-WR-08): cada CREATE é condicionado a `hasTable` e, no fim, índices e FKs que
 * faltarem são repostos pelo NOME, cada DDL num `Schema::table` separado. Sem try/catch em
 * volta de DDL.
 */
return new class extends Migration
{
    /** tabela => [nome do índice => [tipo, colunas]] — o que o CREATE de cada tabela define. */
    private const INDICES = [
        'estrutura_tipos_produto'         => ['etp_slug_uq' => ['unique', ['slug']]],
        'estrutura_tipo_pares'            => [
            'etpar_uq'     => ['unique', ['tipo_a_id', 'tipo_b_id']],
            'etpar_b_idx'  => ['index', ['tipo_b_id']],
        ],
        'estrutura_produto_geracao'       => [
            'epg_company_idx' => ['index', ['company_id']],
            'epg_tipo_idx'    => ['index', ['tipo_id']],
        ],
        'estrutura_sugestoes_descartadas' => ['esd_company_chave_uq' => ['unique', ['company_id', 'chave']]],
    ];

    /**
     * tabela => [nome da FK => [coluna, tabela referida, ao apagar]]. Reposição só no
     * MySQL/MariaDB: no SQLite a FK só nasce no CREATE (e lá ela não tem nome).
     */
    private const FKS = [
        'estrutura_tipo_pares'            => [
            'etpar_a_fk' => ['tipo_a_id', 'estrutura_tipos_produto', 'cascade'],
            'etpar_b_fk' => ['tipo_b_id', 'estrutura_tipos_produto', 'cascade'],
        ],
        'estrutura_produto_geracao'       => [
            'epg_produto_fk' => ['produto_id', 'estrutura_produtos', 'cascade'],
            'epg_company_fk' => ['company_id', 'companies', 'cascade'],
            'epg_tipo_fk'    => ['tipo_id', 'estrutura_tipos_produto', 'null'],
        ],
        'estrutura_sugestoes_descartadas' => ['esd_company_fk' => ['company_id', 'companies', 'cascade']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('estrutura_tipos_produto')) {
            Schema::create('estrutura_tipos_produto', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 40);
                $table->string('nome', 60);
                $table->string('plural', 60);
                $table->string('palavras', 255);
                $table->string('qtd_combo', 40)->nullable();
                $table->string('qtd_combit', 40)->nullable();
                $table->unsignedSmallInteger('ordem')->default(0);
                $table->timestamps();

                $table->unique('slug', 'etp_slug_uq');
            });
        }

        if (! Schema::hasTable('estrutura_tipo_pares')) {
            Schema::create('estrutura_tipo_pares', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tipo_a_id');
                $table->unsignedBigInteger('tipo_b_id');
                $table->string('combit_repete', 8)->nullable();
                $table->timestamps();

                $table->unique(['tipo_a_id', 'tipo_b_id'], 'etpar_uq');
                $table->index('tipo_b_id', 'etpar_b_idx');
                $table->foreign('tipo_a_id', 'etpar_a_fk')->references('id')->on('estrutura_tipos_produto')->cascadeOnDelete();
                $table->foreign('tipo_b_id', 'etpar_b_fk')->references('id')->on('estrutura_tipos_produto')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('estrutura_produto_geracao')) {
            Schema::create('estrutura_produto_geracao', function (Blueprint $table) {
                $table->unsignedBigInteger('produto_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('tipo_id')->nullable();
                $table->string('qtd_combo', 40)->nullable();
                $table->string('qtd_combit', 40)->nullable();
                $table->timestamps();

                $table->primary('produto_id', 'epg_pk');
                $table->index('company_id', 'epg_company_idx');
                $table->index('tipo_id', 'epg_tipo_idx');
                $table->foreign('produto_id', 'epg_produto_fk')->references('id')->on('estrutura_produtos')->cascadeOnDelete();
                $table->foreign('company_id', 'epg_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('tipo_id', 'epg_tipo_fk')->references('id')->on('estrutura_tipos_produto')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('estrutura_sugestoes_descartadas')) {
            Schema::create('estrutura_sugestoes_descartadas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('chave', 100);
                $table->string('fase', 10);
                $table->timestamps();

                $table->unique(['company_id', 'chave'], 'esd_company_chave_uq');
                $table->foreign('company_id', 'esd_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        // Retomada de uma execução parcial: o que o CREATE de cada tabela define e faltou.
        // Índices antes das FKs (a FK usa o índice como apoio — família do erro 1553).
        foreach (self::INDICES as $tabela => $indices) {
            foreach ($indices as $nome => [$tipo, $colunas]) {
                if (! $this->hasIndex($tabela, $nome)) {
                    Schema::table($tabela, function (Blueprint $t) use ($tipo, $colunas, $nome) {
                        $tipo === 'unique' ? $t->unique($colunas, $nome) : $t->index($colunas, $nome);
                    });
                }
            }
        }

        if ($this->emMysql()) {
            foreach (self::FKS as $tabela => $fks) {
                foreach ($fks as $nome => [$coluna, $referida, $aoApagar]) {
                    if (! $this->hasForeignKey($tabela, $nome)) {
                        Schema::table($tabela, function (Blueprint $t) use ($coluna, $referida, $aoApagar, $nome) {
                            $fk = $t->foreign($coluna, $nome)->references('id')->on($referida);
                            $aoApagar === 'null' ? $fk->nullOnDelete() : $fk->cascadeOnDelete();
                        });
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estrutura_sugestoes_descartadas');
        Schema::dropIfExists('estrutura_produto_geracao');
        Schema::dropIfExists('estrutura_tipo_pares');
        Schema::dropIfExists('estrutura_tipos_produto');
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

    /** FK existe? Só MySQL/MariaDB, pelo nome em information_schema. */
    private function hasForeignKey(string $table, string $fk): bool
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $fk)
            ->exists();
    }
};
