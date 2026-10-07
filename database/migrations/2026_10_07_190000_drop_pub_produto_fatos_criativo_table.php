<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 261007-rmv — reversão deliberada do bloco "pontos fortes e medidas
 * digitados à mão" (Fase 169, Planos 01/02/03): decisão do usuário, ciente
 * da limitação descoberta tarde (`CreativePlanner::validarTexto()` só aceita
 * tópico idêntico ao VALOR de um atributo), de ficar só com a quarta etapa
 * de imagens (169-04) — sem o cadastro manual.
 *
 * A migration `2026_10_07_150000_create_pub_produto_fatos_criativo_table.php`
 * (batch 167 em produção) NÃO é apagada do histórico — já rodou. Esta
 * migration nova dropa a tabela (vazia em produção, nenhum operador chegou
 * a usá-la) de forma aditiva: `up()` idempotente (`Schema::hasTable` antes
 * de dropar), `down()` recria a MESMA estrutura (nunca restaura dados —
 * reversão de migration nunca grava de volta o que foi apagado).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_produto_fatos_criativo')) {
            return;
        }

        Schema::drop('pub_produto_fatos_criativo');
    }

    public function down(): void
    {
        if (Schema::hasTable('pub_produto_fatos_criativo')) {
            return;
        }

        Schema::create('pub_produto_fatos_criativo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pub_produto_id')->nullable()->constrained('pub_produtos', 'id', 'pubfc_produto_fk')->nullOnDelete();
            $t->string('tipo', 10);
            $t->string('texto', 300);
            $t->foreignId('confirmado_por_id')->nullable()->constrained('users', 'id', 'pubfc_user_fk')->nullOnDelete();
            $t->timestamps();

            $t->index(['pub_produto_id', 'tipo'], 'pubfc_produto_tipo_ix');
        });
    }
};
