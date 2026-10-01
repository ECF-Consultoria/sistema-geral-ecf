<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precificação do Mapeamento Estrutural — duas tabelas NOVAS; nenhuma tabela com
 * dado em produção é alterada. Decisão de schema:
 * `.planning/adrs/PORTAL-02-precificacao-do-mapeamento.md`.
 *
 * Todo percentual é PONTO PERCENTUAL (`11.50` = 11,5%) — uma unidade só, ao
 * contrário do onboarding, onde decimal e ponto percentual convivem no mesmo
 * objeto (`precificacao-onboarding-duas-telas.md` §2).
 *
 * MariaDB: índices e FKs com nome curto explícito, nenhum `nullOnDelete`,
 * nenhum `timestamp()` fora do `timestamps()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // O conjunto da empresa. Sem linha, valem os padrões (os de Calculadora.jsx).
        Schema::create('estrutura_precificacao_parametros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'epp_company_fk')->cascadeOnDelete();
            $table->decimal('comissao_classico', 5, 2)->default(11.50);
            $table->decimal('comissao_premium', 5, 2)->default(16.50);
            $table->decimal('imposto', 5, 2)->default(19.00);
            $table->decimal('margem_contribuicao', 5, 2)->default(0);
            $table->decimal('lucro_liquido', 5, 2)->default(0);
            $table->decimal('acrescimo', 5, 2)->default(20.00);
            $table->timestamps();

            $table->unique('company_id', 'estr_precif_param_company_uq');
        });

        // O produto. Custo NULL em combo/kit/combit = calculado pelos componentes;
        // percentuais NULL = vale o da empresa.
        Schema::create('estrutura_precificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'ep_oferta_fk')->cascadeOnDelete();
            $table->decimal('custo', 12, 2)->nullable();
            $table->decimal('frete_classico', 10, 2)->nullable();
            $table->decimal('frete_premium', 10, 2)->nullable();
            $table->decimal('comissao_classico', 5, 2)->nullable();
            $table->decimal('comissao_premium', 5, 2)->nullable();
            $table->decimal('imposto', 5, 2)->nullable();
            $table->decimal('margem_contribuicao', 5, 2)->nullable();
            $table->decimal('lucro_liquido', 5, 2)->nullable();
            $table->timestamps();

            $table->unique('oferta_id', 'estr_precif_oferta_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estrutura_precificacoes');
        Schema::dropIfExists('estrutura_precificacao_parametros');
    }
};
