<?php

// pt-BR: Migration da tabela de congelamento do fechamento mensal POR
// GRUPO (Fase 137, D-08 + D-10 + D-11). Mesma forma de
// `fechamento_snapshots`, trocando a granularidade de empresa para grupo
// do Comercial (`company_groups`) — 1 linha por
// `(company_group_id, mes_referencia)`.
//
// Nome de tabela CURTO de propósito, mesma razão da tabela irmã: evitar
// nome de índice acima do limite de 64 caracteres do MariaDB (erro 1059).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_grupo_snapshots')) {
            return;
        }

        Schema::create('fechamento_grupo_snapshots', function (Blueprint $table) {
            $table->id();

            // NOT NULL com cascade — snapshot de grupo apagado não tem
            // valor de auditoria, some junto (mesma razão de company_id em
            // fechamento_snapshots).
            $table->foreignId('company_group_id')->constrained('company_groups')->cascadeOnDelete();

            $table->date('mes_referencia');

            // Nome do grupo no instante do congelamento.
            $table->string('grupo_name')->nullable();

            $table->decimal('faturamento_ml', 16, 2)->nullable();
            $table->decimal('faturamento_shopee', 16, 2)->nullable();
            $table->decimal('faturamento_total', 16, 2)->nullable();

            // servico_id opcional — ->nullable() ANTES de ->nullOnDelete()
            // na mesma cadeia (erro 1830 em MariaDB se a ordem for
            // trocada; o SQLite dos testes não pega).
            $table->foreignId('servico_id')->nullable()->constrained('servicos')->nullOnDelete();

            $table->string('tabela_origem', 20)->nullable(); // 'propria' | 'servico'

            $table->unsignedSmallInteger('faixa_ordem')->nullable();
            $table->string('faixa_aplicada', 40)->nullable();
            $table->decimal('valor_faixa', 10, 2)->nullable();
            $table->boolean('valor_faixa_e_piso')->default(false);

            $table->decimal('faixa_limite_inferior', 16, 2)->nullable();
            $table->decimal('faixa_limite_superior', 16, 2)->nullable();

            $table->decimal('cobranca_mensal', 12, 2)->nullable();

            $table->string('evolucao', 10)->nullable(); // 'subiu' | 'desceu' | 'manteve'
            $table->string('estado', 20); // 'ok' | 'sem_faturamento' | 'sem_tabela' | 'sem_integracao'

            // Quantas empresas somaram o faturamento do grupo (D-10).
            $table->unsignedSmallInteger('empresas_count');

            // Empresa cuja tabela de faixas foi aplicada ao grupo —
            // ->nullable() ANTES de ->nullOnDelete() pela mesma armadilha
            // 1830 acima.
            $table->foreignId('empresa_ancora_id')->nullable()->constrained('companies')->nullOnDelete();

            // Alimenta o banner âmbar "Este grupo tem empresas com tabelas
            // diferentes" do UI-SPEC.
            $table->boolean('tabelas_divergentes')->default(false);

            $table->string('origem', 32);

            $table->timestamp('gerado_em');

            $table->timestamps();

            // Índices nomeados à mão, curtos de propósito (§6 da memória do
            // projeto).
            $table->unique(['company_group_id', 'mes_referencia'], 'fecha_gsnap_grupo_mes_unq');
            $table->index(['mes_referencia'], 'fecha_gsnap_mes_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_grupo_snapshots');
    }
};
