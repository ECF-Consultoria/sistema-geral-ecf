<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imagens por variação do produto (Mapeamento Estrutural) — a galeria de cada cor, na
 * ordem que o cliente escolheu (a 1ª é a capa). Só CREATE de UMA tabela nova: nenhuma
 * tabela existente é tocada.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2). Uma linha por imagem de uma variação:
 * - `caminho` varchar(255): onde o arquivo está no disco PRIVADO (`local`), relativo ao
 *   disco. Nunca é URL e nunca é mostrado ao cliente; a tela usa a rota autenticada.
 * - `nome_original` varchar(255): o nome que o cliente deu ao arquivo (só para exibir).
 * - `mime` varchar(100), `tamanho` int unsigned (bytes), `largura`/`altura` int unsigned
 *   NULL (quando o arquivo permite ler as dimensões).
 * - `ordem` smallint unsigned: 0 é a capa. Sem unique em (variacao_id, ordem): a reordenação
 *   regrava vários valores no mesmo comando e um unique barraria a troca no meio do caminho.
 * - `company_id` e `produto_id` denormalizados DE PROPÓSITO (como em
 *   `estrutura_produto_atributos`): toda leitura é escopada pela empresa, e o caminho do
 *   arquivo carrega os dois ids.
 * - Cascata nas três FKs: excluir a variação, o produto ou a empresa apaga as linhas
 *   (os ARQUIVOS saem pelo gancho do model da variação; ver `EstruturaProdutoVariacao`).
 *
 * Armadilhas de MariaDB evitadas (learnings §6): nenhum `enum`, nenhum `json`, nenhum
 * `timestamp()` solto (`timestamps()` é nullable), nomes de índice e FK explícitos e curtos
 * (< 64, erro 1059), cada FK com índice próprio de apoio (erro 1553): `company_id` abre o
 * índice composto; `variacao_id` e `produto_id` têm o seu.
 *
 * IDEMPOTENTE (BE-WR-08): o CREATE é condicionado a `hasTable` e, no fim, índices e FKs que
 * faltarem são repostos pelo NOME, cada DDL num `Schema::table` separado. Sem try/catch em
 * volta de DDL.
 */
return new class extends Migration
{
    private const TABELA = 'estrutura_produto_variacao_imagens';

    /** nome do índice => [tipo, colunas] — o que o CREATE define. */
    private const INDICES = [
        'epvi_company_var_ordem_idx' => ['index', ['company_id', 'variacao_id', 'ordem']],
        'epvi_variacao_idx'          => ['index', ['variacao_id']],
        'epvi_produto_idx'           => ['index', ['produto_id']],
    ];

    /**
     * nome da FK => [coluna, tabela referida]. Reposição só no MySQL/MariaDB: no SQLite a FK
     * só nasce no CREATE (e lá ela não tem nome). Todas ON DELETE CASCADE.
     */
    private const FKS = [
        'epvi_variacao_fk' => ['variacao_id', 'estrutura_produto_variacoes'],
        'epvi_produto_fk'  => ['produto_id', 'estrutura_produtos'],
        'epvi_company_fk'  => ['company_id', 'companies'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELA)) {
            Schema::create(self::TABELA, function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'epvi_company_fk')->cascadeOnDelete();
                $table->foreignId('produto_id')->constrained('estrutura_produtos', 'id', 'epvi_produto_fk')->cascadeOnDelete();
                $table->foreignId('variacao_id')->constrained('estrutura_produto_variacoes', 'id', 'epvi_variacao_fk')->cascadeOnDelete();
                $table->string('caminho', 255);
                $table->string('nome_original', 255);
                $table->string('mime', 100);
                $table->unsignedInteger('tamanho');
                $table->unsignedInteger('largura')->nullable();
                $table->unsignedInteger('altura')->nullable();
                $table->unsignedSmallInteger('ordem')->default(0);
                $table->timestamps();

                $table->index(['company_id', 'variacao_id', 'ordem'], 'epvi_company_var_ordem_idx');
                $table->index('variacao_id', 'epvi_variacao_idx');
                $table->index('produto_id', 'epvi_produto_idx');
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
