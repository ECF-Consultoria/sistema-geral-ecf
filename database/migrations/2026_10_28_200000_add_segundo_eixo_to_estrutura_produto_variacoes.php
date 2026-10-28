<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segundo eixo da variação (28/10/2026): "Verde · 34-39" deixa de ser um valor só, de eixo "Outro".
 * Como no Mercado Livre, a variação passa a ter Cor E Tamanho — o 1º eixo (`eixo`/`valor`) é o que
 * agrupa (a cor, dona das fotos) e o 2º (`eixo_2`/`valor_2`) o que varia dentro dele (o tamanho).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2):
 * - `eixo_2` varchar(30) NULL e `valor_2` varchar(80) NULL: os mesmos tipos e a mesma lista fechada
 *   de `eixo`/`valor` (EstruturaProdutoVariacao::EIXOS; MAX_VALOR 80). NULL = a variação tem um eixo
 *   só — o caso de TODAS as variações existentes, que não mudam.
 * - Colunas no FIM da tabela (sem `after`): nada de reordenar uma tabela com dado em produção.
 * - SEM backfill, sem índice e sem FK: é dado da linha, nunca filtro. A unicidade continua sendo a
 *   do código (Ref) por empresa.
 * - Duas colunas, e não uma tabela de eixos: o Publicador aceita até 3 eixos, mas o Portal só
 *   precisa de dois (cor e tamanho, como o ML na moda); um 3º eixo vira outra coluna se um dia vier.
 *
 * Idempotente (hasColumn) e sem tratamento de exceção em volta de DDL. Rodar no MariaDB local
 * com `--path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estrutura_produto_variacoes', 'eixo_2')) {
            Schema::table('estrutura_produto_variacoes', function (Blueprint $t) {
                $t->string('eixo_2', 30)->nullable();
            });
        }
        if (! Schema::hasColumn('estrutura_produto_variacoes', 'valor_2')) {
            Schema::table('estrutura_produto_variacoes', function (Blueprint $t) {
                $t->string('valor_2', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['valor_2', 'eixo_2'] as $coluna) {
            if (Schema::hasColumn('estrutura_produto_variacoes', $coluna)) {
                Schema::table('estrutura_produto_variacoes', function (Blueprint $t) use ($coluna) {
                    $t->dropColumn($coluna);
                });
            }
        }
    }
};
