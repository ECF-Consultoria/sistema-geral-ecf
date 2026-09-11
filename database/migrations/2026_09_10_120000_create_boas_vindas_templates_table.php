<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 153 (COMUNIC-01/02/03) — templates da mensagem de boas-vindas, um por
 * serviço mais um genérico.
 *
 * Tabela DEDICADA, e não uma chave nova em `mlb_configuracoes.implementacao_defaults`
 * (D-C): `MlbImplementacaoController::salvarPadroes()` faz
 * `update(['implementacao_defaults' => $validated])`, que SUBSTITUI o JSON inteiro
 * pelas chaves validadas — qualquer chave nova ali seria apagada em silêncio no
 * próximo save da tela de Padrões, por quem não fez nada errado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boas_vindas_templates', function (Blueprint $table) {
            $table->id();

            // NULL = template genérico, usado por serviço que não tem o seu.
            // Sem coluna-sentinela e sem string mágica: "template do serviço X" e
            // "template padrão" são a mesma coisa com o FK preenchido ou não.
            //
            // ⚠️ MariaDB permite N linhas com NULL num índice único — o índice
            // abaixo NÃO garante genérico único. Quem garante é a aplicação, em
            // `BoasVindasTemplate::salvarGenerico()`. Escrito aqui porque o SQLite
            // dos testes não pega essa diferença.
            $table->foreignId('servico_id')->nullable()->constrained('servicos')->cascadeOnDelete();

            $table->text('texto');

            // Autoria da última edição. SET NULL: remover o usuário não pode
            // apagar o template. (Diferente do histórico da Fase 150, onde
            // cascade seria perda de insumo de SLA — aqui não há histórico a
            // preservar, é configuração corrente.)
            $table->foreignId('atualizado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Índice NOMEADO à mão: a Fase 152 já levou um erro 1059 por nome
            // gerado longo demais. Nomear é barato e remove a classe inteira.
            $table->unique('servico_id', 'bvt_servico_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boas_vindas_templates');
    }
};
