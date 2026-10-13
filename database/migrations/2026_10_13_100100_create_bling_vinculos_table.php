<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 184 (BLING-02) — vínculo entre o produto/variação do Bling e a
 * variação do Portal (`estrutura_produto_variacoes`).
 *
 * Por que é TABELA DE VÍNCULO e não colunas novas em
 * `estrutura_produto_variacoes`: alterar uma tabela existente com dado em
 * produção obriga fase GSD própria, e assim o Portal continua sem saber o
 * que é ERP — a mesma separação que `MlToken` já faz para o Mercado Livre.
 *
 * `bling_produto_id` nasce aqui porque a **Fase 185** precisa dele para
 * montar `componentes[].produto.id` do kit (D-04 do CONTEXT) — não é campo
 * de preparo solto, é dependência declarada de outra fase.
 *
 * O vínculo casa pelo `id` do Bling, **nunca pelo SKU** (lição de
 * `EstruturaOferta`: SKU não identifica de forma estável).
 *
 * Quatro valores de `situacao`: `vinculado`, `ignorado`, `erro`,
 * `removido_no_bling` — este último significa "o produto saiu do Bling e
 * **nada** foi apagado no Portal" (CONTEXT §4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bling_vinculos')) {
            Schema::create('bling_vinculos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('bling_produto_id', 64);
                $table->string('bling_pai_id', 64)->nullable();
                $table->foreignId('variacao_id')->nullable()->constrained('estrutura_produto_variacoes')->nullOnDelete();
                $table->string('codigo_bling', 120)->nullable();
                $table->string('formato', 1)->nullable();
                $table->string('gtin', 20)->nullable();
                $table->text('escrito')->nullable();
                $table->integer('saldo')->nullable();
                $table->dateTime('saldo_lido_em')->nullable();
                $table->string('situacao', 20)->default('vinculado');
                $table->string('motivo', 255)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'bling_produto_id'], 'blvin_company_prod_uq');
                $table->unique('variacao_id', 'blvin_variacao_uq');
                $table->index('bling_pai_id', 'blvin_pai_idx');
                $table->index('situacao', 'blvin_situacao_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bling_vinculos');
    }
};
