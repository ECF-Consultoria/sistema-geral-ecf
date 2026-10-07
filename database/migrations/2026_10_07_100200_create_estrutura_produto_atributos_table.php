<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha técnica do produto (Mapeamento Estrutural) — os campos dinâmicos da categoria,
 * preenchidos pelo cliente. Só CREATE de UMA tabela nova: nenhuma tabela existente é tocada.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2). Uma linha por (produto, atributo):
 * - `atributo_id` varchar(80): o id do atributo no catálogo da categoria (ex. `MATERIAL`).
 *   Interno: gravado para o publicador usar depois, NUNCA mostrado ao cliente.
 * - `atributo_nome` varchar(160): o rótulo em pt-BR que o cliente viu ao preencher. Fica
 *   gravado para a linha continuar legível se o catálogo da categoria mudar depois.
 * - `valor` text null: o que foi preenchido (texto, número, "Sim"/"Não" ou o nome da opção).
 * - `valor_id` varchar(40) null: o id da opção, só quando o campo é uma lista.
 * - `unidade` varchar(20) null: só para número com unidade (cm, kg...).
 * - `company_id` denormalizado DE PROPÓSITO (como em `estrutura_produto_variacoes`): toda
 *   leitura é escopada pela empresa e o unique (company_id, produto_id, atributo_id) impede
 *   o mesmo atributo duas vezes no mesmo produto.
 *
 * Armadilhas de MariaDB evitadas (learnings §6): nenhum `enum`, nenhum `json`, nenhum
 * `timestamp()` solto (`timestamps()` é nullable), nomes de índice e FK explícitos e curtos
 * (< 64, erro 1059), FK de `produto_id` com índice próprio (o unique começa em `company_id`).
 *
 * IDEMPOTENTE (BE-WR-08): o CREATE é condicionado a `hasTable` e, no fim, índices e FKs que
 * faltarem são repostos pelo NOME, cada DDL num `Schema::table` separado. Sem try/catch em
 * volta de DDL.
 */
return new class extends Migration
{
    private const TABELA = 'estrutura_produto_atributos';

    /** nome do índice => [tipo, colunas] — o que o CREATE define. */
    private const INDICES = [
        'epat_company_prod_atr_uq' => ['unique', ['company_id', 'produto_id', 'atributo_id']],
        'epat_produto_idx'         => ['index', ['produto_id']],
    ];

    /**
     * nome da FK => [coluna, tabela referida]. Reposição só no MySQL/MariaDB: no SQLite a FK
     * só nasce no CREATE (e lá ela não tem nome). Ambas ON DELETE CASCADE.
     */
    private const FKS = [
        'epat_produto_fk' => ['produto_id', 'estrutura_produtos'],
        'epat_company_fk' => ['company_id', 'companies'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELA)) {
            Schema::create(self::TABELA, function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'epat_company_fk')->cascadeOnDelete();
                $table->foreignId('produto_id')->constrained('estrutura_produtos', 'id', 'epat_produto_fk')->cascadeOnDelete();
                $table->string('atributo_id', 80);
                $table->string('atributo_nome', 160);
                $table->text('valor')->nullable();
                $table->string('valor_id', 40)->nullable();
                $table->string('unidade', 20)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'produto_id', 'atributo_id'], 'epat_company_prod_atr_uq');
                $table->index('produto_id', 'epat_produto_idx');
            });
        }

        // Retomada de uma execução parcial. Índices antes das FKs (a FK usa o índice como apoio — erro 1553).
        foreach (self::INDICES as $nome => [$tipo, $colunas]) {
            if (! $this->hasIndex(self::TABELA, $nome)) {
                Schema::table(self::TABELA, function (Blueprint $t) use ($tipo, $colunas, $nome) {
                    $tipo === 'unique' ? $t->unique($colunas, $nome) : $t->index($colunas, $nome);
                });
            }
        }

        if ($this->emMysql()) {
            foreach (self::FKS as $nome => [$coluna, $referida]) {
                if (! $this->hasForeignKey(self::TABELA, $nome)) {
                    Schema::table(self::TABELA, function (Blueprint $t) use ($coluna, $referida, $nome) {
                        $t->foreign($coluna, $nome)->references('id')->on($referida)->cascadeOnDelete();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABELA);
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
