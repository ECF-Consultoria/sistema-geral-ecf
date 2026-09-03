<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 137 (D-01) — Cria `servico_faixas_faturamento`: a tabela progressiva
 * de faturamento vira DADO estruturado por serviço, em vez de constante PHP
 * duplicada (`AdminController::FAIXAS`) e texto dentro dos modelos de
 * contrato .docx.
 *
 * Uma linha = uma faixa da tabela do serviço: até `limite_superior` (NULL =
 * faixa aberta, sem teto), a mensalidade é `valor`.
 *
 * Coluna `valor_e_piso`: marca a faixa cujo `valor` no contrato é "a partir
 * de R$ X", não um preço fechado — é o caso da última faixa de Gestão e de
 * Brigada ("acima de R$ 5.000.000 → a partir de R$ 12.000", D-02b). Sem essa
 * coluna o sistema cobraria exatamente R$ 12.000 de quem deveria pagar mais.
 *
 * Índice nomeado à mão: o MariaDB de produção recusa nome de índice acima de
 * 64 caracteres (erro 1059) — o SQLite dos testes não pega isso, e a falha
 * em produção deixa a tabela criada com a migration marcada como rodada e o
 * índice faltando (armadilha registrada em
 * .planning/learnings/desempenho-bonificacao.md §6).
 *
 * Migration idempotente: guard `Schema::hasTable` evita recriação em rerun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('servico_faixas_faturamento')) {
            return;
        }

        Schema::create('servico_faixas_faturamento', function (Blueprint $table) {
            $table->id();

            $table->foreignId('servico_id')->constrained('servicos')->cascadeOnDelete();

            // Posição da faixa dentro da tabela do serviço (1, 2, 3...).
            $table->unsignedSmallInteger('ordem');

            // NULL = faixa aberta (sem teto) — NÃO usar 0 nem 999999999 como
            // sentinela. É o caso da última faixa de Gestão/Brigada.
            $table->decimal('limite_superior', 16, 2)->nullable();

            $table->decimal('valor', 10, 2);

            // true = o valor da faixa é "a partir de", não preço fechado.
            // Ver nota de topo do arquivo — sem isso o sistema cobra a menos
            // de quem deveria pagar mais na última faixa aberta.
            $table->boolean('valor_e_piso')->default(false);

            $table->timestamps();

            // Nome curto e explícito de propósito — o nome que o Laravel
            // geraria sozinho ultrapassaria o limite de 64 caracteres do
            // MariaDB em cenários de tabela com nome longo (armadilha do §6
            // da memória do projeto).
            $table->unique(['servico_id', 'ordem'], 'sff_servico_ordem_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servico_faixas_faturamento');
    }
};
