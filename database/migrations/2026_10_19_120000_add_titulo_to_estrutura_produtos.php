<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 19/10/2026 — `estrutura_produtos.titulo`: o título de venda, separado do nome do produto.
 *
 * O cliente dá o NOME (como a empresa chama o produto) e monta o TÍTULO com os termos mais
 * buscados da categoria. O Publicador lê o título daqui na hora (`DadosEfetivosService`), como já
 * lê o nome: não há cópia nem tabela paralela.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2; trabalho direto autorizado pelo usuário em 19/10):
 * - `titulo` varchar(255) NULL, sem default: produto sem título é NULL (fica pendente no Portal),
 *   nunca string vazia inventada. 255 é o teto do campo; o limite de verdade é o da categoria
 *   (`max_title_length`, 60 na maioria), conferido ao gravar.
 * - SEM backfill: nenhum produto existente muda; os que já existem ficam sem título (pendentes).
 * - Sem índice: texto livre, nunca filtro.
 * - Só ADD COLUMN nullable no fim lógico (depois de `nome`): no MariaDB 10.4 é ALTER sem
 *   reescrever dado de outra coluna; nada de enum/json/timestamp (learnings §6).
 *
 * Idempotente (hasColumn) e sem tratamento de exceção em volta de DDL. Provar no MariaDB local
 * com `--path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estrutura_produtos', 'titulo')) {
            Schema::table('estrutura_produtos', function (Blueprint $t) {
                $t->string('titulo', 255)->nullable()->after('nome');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('estrutura_produtos', 'titulo')) {
            Schema::table('estrutura_produtos', function (Blueprint $t) {
                $t->dropColumn('titulo');
            });
        }
    }
};
