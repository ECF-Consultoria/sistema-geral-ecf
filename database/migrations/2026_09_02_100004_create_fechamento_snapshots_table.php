<?php

// pt-BR: Migration da tabela de congelamento do fechamento mensal POR
// EMPRESA (Fase 137, D-11). Mesmo molde de
// `desempenho_company_score_snapshots` (Fase 122): 1 linha por
// `(company_id, mes_referencia)`, escrita pelo writer do plano 05 — nunca
// por um controller direto.
//
// Nome de tabela CURTO de propósito: a pesquisa mediu que
// `fechamento_faturamento_snapshots` com unique default gera nome de
// índice de 65 caracteres, 1 acima do limite do MariaDB (erro 1059 — ver
// .planning/learnings/desempenho-bonificacao.md §6). Todos os índices
// abaixo são nomeados à mão por segurança extra, mesmo com o nome curto.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_snapshots')) {
            return;
        }

        Schema::create('fechamento_snapshots', function (Blueprint $table) {
            $table->id();

            // NOT NULL com cascade — deliberado, sem ->nullable(): snapshot
            // de empresa apagada não tem valor de auditoria, então some
            // junto (mesma decisão de desempenho_company_score_snapshots).
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Sempre o 1º dia do mês da competência.
            $table->date('mes_referencia');

            // Nome no instante do congelamento — a empresa pode mudar de
            // nome depois e a auditoria precisa preservar o que valia então.
            $table->string('company_name')->nullable();

            $table->decimal('faturamento_ml', 16, 2)->nullable();
            $table->decimal('faturamento_shopee', 16, 2)->nullable();
            $table->decimal('faturamento_total', 16, 2)->nullable();

            // FK opcional — ->nullable() vem ANTES de ->nullOnDelete() na
            // mesma cadeia. Sem essa ordem o MariaDB de produção recusa com
            // erro 1830 (a coluna nasce NOT NULL antes do nullOnDelete ser
            // aplicado); o SQLite dos testes não pega essa armadilha.
            $table->foreignId('company_group_id')->nullable()->constrained('company_groups')->nullOnDelete();
            $table->foreignId('servico_id')->nullable()->constrained('servicos')->nullOnDelete();

            // STRING sempre — nunca enum(): o CHECK gerado é enforçado no
            // SQLite dos testes e quebra ao surgir valor novo (armadilha
            // registrada na memória do projeto).
            $table->string('tabela_origem', 20)->nullable(); // 'propria' | 'servico'

            $table->unsignedSmallInteger('faixa_ordem')->nullable();
            $table->string('faixa_aplicada', 40)->nullable();
            $table->decimal('valor_faixa', 10, 2)->nullable();

            // Marca "a partir de R$ X" da faixa aplicada (piso, não valor
            // fechado) — sem isso o congelado promete preço fechado onde o
            // contrato prevê piso (última faixa de Gestão/Brigada: D-02b).
            $table->boolean('valor_faixa_e_piso')->default(false);

            $table->decimal('faixa_limite_inferior', 16, 2)->nullable();
            $table->decimal('faixa_limite_superior', 16, 2)->nullable();

            // Faixa + contratos — o número que a tela já mostra hoje.
            $table->decimal('cobranca_mensal', 12, 2)->nullable();

            $table->string('evolucao', 10)->nullable(); // 'subiu' | 'desceu' | 'manteve'
            $table->string('estado', 20); // 'ok' | 'sem_faturamento' | 'sem_tabela' | 'sem_integracao'

            // 'consolidar_mes' | outras origens futuras — STRING, mesma
            // razão de tabela_origem/evolucao/estado acima.
            $table->string('origem', 32);

            $table->timestamp('gerado_em');

            $table->timestamps();

            // Nomes de índice EXPLÍCITOS e curtos de propósito — o nome que
            // o Laravel geraria sozinho para o unique multi-coluna
            // ultrapassaria o limite de 64 caracteres do MariaDB (erro 1059,
            // §6 da memória do projeto).
            $table->unique(['company_id', 'mes_referencia'], 'fecha_snap_empresa_mes_unq');
            $table->index(['mes_referencia'], 'fecha_snap_mes_idx');
            $table->index(['company_group_id', 'mes_referencia'], 'fecha_snap_grupo_mes_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_snapshots');
    }
};
