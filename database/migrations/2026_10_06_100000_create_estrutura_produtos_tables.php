<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 167 — catálogo de produtos do Mapeamento Estrutural (aba "Produtos" da
 * planilha 3Planejamento). Só CREATE: nenhuma tabela existente é tocada aqui
 * (o vínculo com `estrutura_ofertas` é a migration seguinte, separada).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2). Tudo ancorado em `company_id` (D-01).
 * - estrutura_familias / estrutura_ambientes: listas da empresa; unique (company_id, nome).
 * - estrutura_produtos: `codigo` (o "Grupo" da planilha) é nullable e unique por empresa
 *   (NULL repete); `familia_id` nullable com SET NULL (nullable, então sem erro 1830).
 * - estrutura_produto_ambiente: pivot N:N, PK composta, sem timestamps.
 * - estrutura_produto_variacoes: `company_id` denormalizado DE PROPÓSITO, só para o
 *   unique (company_id, codigo) valer por empresa; custo decimal(12,2) como em
 *   `estrutura_precificacoes.custo`.
 * - estrutura_produto_volumes: medidas e peso (kg) por volume. Peso total e nº de
 *   volumes NÃO são colunas: derivam dos volumes (uma verdade só).
 *
 * Armadilhas de MariaDB evitadas (learnings §6): nenhum `enum` (varchar + constante
 * no model), nenhum `json` (LONGTEXT no 10.4), nenhum `timestamp()` solto
 * (`timestamps()` é nullable), nomes de índice e FK explícitos e curtos (< 64, erro 1059).
 *
 * IDEMPOTENTE (BE-WR-08, learnings §6): no MariaDB o CREATE sai antes e cada índice e
 * FK entra num ALTER separado. Se um passo falhar (FK, engine, permissão), as tabelas
 * anteriores ficam criadas e a migration continua `Pending`; sem esta guarda a próxima
 * tentativa morria em "table already exists" e travava todo `deploy.sh` seguinte. Por
 * isso cada CREATE é condicionado a `hasTable` e, no fim, os índices e FKs que faltarem
 * são repostos pelo NOME (padrão da migration irmã 100100). Sem try/catch em volta de DDL.
 */
return new class extends Migration
{
    /** tabela => [nome do índice => [tipo, colunas]] — o que o CREATE de cada tabela define. */
    private const INDICES = [
        'estrutura_familias'          => ['efam_company_nome_uq' => ['unique', ['company_id', 'nome']]],
        'estrutura_ambientes'         => ['eamb_company_nome_uq' => ['unique', ['company_id', 'nome']]],
        'estrutura_produtos'          => [
            'epr_company_cod_uq' => ['unique', ['company_id', 'codigo']],
            'epr_familia_idx'    => ['index', ['familia_id']],
        ],
        'estrutura_produto_ambiente'  => ['epa_ambiente_idx' => ['index', ['ambiente_id']]],
        'estrutura_produto_variacoes' => [
            'epv_company_cod_uq' => ['unique', ['company_id', 'codigo']],
            'epv_produto_idx'    => ['index', ['produto_id', 'ordem']],
        ],
        'estrutura_produto_volumes'   => ['epvol_variacao_ordem_uq' => ['unique', ['variacao_id', 'ordem']]],
    ];

    /**
     * tabela => [nome da FK => [coluna, tabela referida, ao apagar]]. Reposição só no
     * MySQL/MariaDB: no SQLite a FK só nasce no CREATE (e lá ela não tem nome).
     */
    private const FKS = [
        'estrutura_familias'          => ['efam_company_fk' => ['company_id', 'companies', 'cascade']],
        'estrutura_ambientes'         => ['eamb_company_fk' => ['company_id', 'companies', 'cascade']],
        'estrutura_produtos'          => [
            'epr_company_fk' => ['company_id', 'companies', 'cascade'],
            'epr_familia_fk' => ['familia_id', 'estrutura_familias', 'null'],
        ],
        'estrutura_produto_ambiente'  => [
            'epa_produto_fk'  => ['produto_id', 'estrutura_produtos', 'cascade'],
            'epa_ambiente_fk' => ['ambiente_id', 'estrutura_ambientes', 'cascade'],
        ],
        'estrutura_produto_variacoes' => [
            'epv_produto_fk' => ['produto_id', 'estrutura_produtos', 'cascade'],
            'epv_company_fk' => ['company_id', 'companies', 'cascade'],
        ],
        'estrutura_produto_volumes'   => ['epvol_variacao_fk' => ['variacao_id', 'estrutura_produto_variacoes', 'cascade']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('estrutura_familias')) {
            Schema::create('estrutura_familias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'efam_company_fk')->cascadeOnDelete();
                $table->string('nome', 80);
                $table->timestamps();

                $table->unique(['company_id', 'nome'], 'efam_company_nome_uq');
            });
        }

        if (! Schema::hasTable('estrutura_ambientes')) {
            Schema::create('estrutura_ambientes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'eamb_company_fk')->cascadeOnDelete();
                $table->string('nome', 80);
                $table->timestamps();

                $table->unique(['company_id', 'nome'], 'eamb_company_nome_uq');
            });
        }

        if (! Schema::hasTable('estrutura_produtos')) {
            Schema::create('estrutura_produtos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'epr_company_fk')->cascadeOnDelete();
                $table->string('codigo', 120)->nullable();
                $table->string('nome', 255);
                $table->unsignedBigInteger('familia_id')->nullable();
                $table->string('categoria_ml_id', 20)->nullable();
                $table->string('categoria_ml_nome', 255)->nullable();
                $table->text('categoria_ml_caminho')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'codigo'], 'epr_company_cod_uq');
                $table->index('familia_id', 'epr_familia_idx');
                $table->foreign('familia_id', 'epr_familia_fk')->references('id')->on('estrutura_familias')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('estrutura_produto_ambiente')) {
            Schema::create('estrutura_produto_ambiente', function (Blueprint $table) {
                $table->unsignedBigInteger('produto_id');
                $table->unsignedBigInteger('ambiente_id');

                $table->primary(['produto_id', 'ambiente_id'], 'epa_pk');
                $table->index('ambiente_id', 'epa_ambiente_idx');
                $table->foreign('produto_id', 'epa_produto_fk')->references('id')->on('estrutura_produtos')->cascadeOnDelete();
                $table->foreign('ambiente_id', 'epa_ambiente_fk')->references('id')->on('estrutura_ambientes')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('estrutura_produto_variacoes')) {
            Schema::create('estrutura_produto_variacoes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('produto_id')->constrained('estrutura_produtos', 'id', 'epv_produto_fk')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies', 'id', 'epv_company_fk')->cascadeOnDelete();
                $table->unsignedSmallInteger('ordem')->default(1);
                $table->string('codigo', 120);
                $table->string('eixo', 30)->nullable();
                $table->string('valor', 80)->nullable();
                $table->decimal('custo', 12, 2)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'codigo'], 'epv_company_cod_uq');
                $table->index(['produto_id', 'ordem'], 'epv_produto_idx');
            });
        }

        if (! Schema::hasTable('estrutura_produto_volumes')) {
            Schema::create('estrutura_produto_volumes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variacao_id')->constrained('estrutura_produto_variacoes', 'id', 'epvol_variacao_fk')->cascadeOnDelete();
                $table->unsignedSmallInteger('ordem');
                $table->decimal('comprimento', 7, 2);
                $table->decimal('largura', 7, 2);
                $table->decimal('altura', 7, 2);
                $table->decimal('peso', 8, 3);

                $table->unique(['variacao_id', 'ordem'], 'epvol_variacao_ordem_uq');
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
        Schema::dropIfExists('estrutura_produto_volumes');
        Schema::dropIfExists('estrutura_produto_variacoes');
        Schema::dropIfExists('estrutura_produto_ambiente');
        Schema::dropIfExists('estrutura_produtos');
        Schema::dropIfExists('estrutura_ambientes');
        Schema::dropIfExists('estrutura_familias');
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
