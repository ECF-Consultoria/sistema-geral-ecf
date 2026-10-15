<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planejador FULL — reposição do estoque no Fulfillment do Mercado Livre.
 *
 * Substitui o upload diário de duas planilhas do ML ("Relatório geral de estoque" FULL e
 * "Relatório de desempenho das publicações") por uma coleta diária na API, por empresa com
 * token ML ativo e anúncio no FULL. O cálculo (venda/dia, cobertura, ruptura, sugestão de
 * envio e de compra) roda na tela, em resources/js/lib/planejadorFull.js.
 *
 * Decisões de schema (escritas na conversa ANTES desta migration — CLAUDE.md):
 *
 *  - O que a COLETA sobrescreve (full_produtos, full_vendas_diarias) fica separado do que a
 *    PESSOA digita (full_configuracoes, full_skus, full_itens, full_envios). Uma coleta nunca
 *    apaga lead time, galpão ou ajuste.
 *  - A chave do produto é o `inventory_id` do ML (o "Código ML" da planilha, ex.: OKFW19104).
 *    Um mesmo Código ML pode estar em vários anúncios; anúncio com variação tem um Código ML
 *    por variação.
 *  - "Entrada pendente" NÃO existe na API para vendedor do Brasil (medido em 15/10/2026: a
 *    planilha dizia 6.000 pendentes em LJCQ24460 e a API devolvia 0). Por isso o envio é
 *    registrado aqui (full_envios + full_envio_itens) e a coleta abate o que já chegou pelas
 *    operações INBOUND_RECEPTION do ML. Pendente = enviado − recebido dos envios a caminho.
 *  - Status são string, não enum (enum de banco precisa de branch para o SQLite dos testes).
 *  - FKs de users `nullable + nullOnDelete` (erro 1830 do MariaDB sem nullable). Índices
 *    compostos com nome curto e explícito (limite de 64 caracteres, erro 1059).
 *
 * Migration idempotente: re-rodar não recria tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Retrato diário de cada Código ML, sobrescrito pela coleta.
        if (! Schema::hasTable('full_produtos')) {
            Schema::create('full_produtos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('inventory_id', 30);
                $table->string('sku', 120)->nullable();
                $table->string('titulo', 255);
                // Atributo BRAND do anúncio — vira o fornecedor sugerido quando o SKU não tem um.
                $table->string('marca', 120)->nullable();
                // MLB ids dos anúncios que vendem este Código ML.
                $table->json('anuncios')->nullable();
                // Melhor status entre os anúncios: active > paused > closed.
                $table->string('anuncio_status', 20)->nullable();
                $table->integer('aptas')->default(0);
                $table->integer('transferencia')->default(0);
                // Demais indisponíveis (perdido, retirada, processo interno...) — só exibição.
                $table->integer('indisponivel')->default(0);
                $table->json('estoque_detalhe')->nullable();
                // Quando o estoque deste Código ML foi lido no endpoint do FULL. Nulo = não
                // consultado (anúncio encerrado, sem estoque e sem venda).
                $table->dateTime('estoque_em')->nullable();
                $table->dateTime('coletado_em')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'inventory_id'], 'full_produtos_emp_inv_unique');
            });
        }

        // Venda por dia e por Código ML, a partir dos pedidos pagos.
        if (! Schema::hasTable('full_vendas_diarias')) {
            Schema::create('full_vendas_diarias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('inventory_id', 30);
                $table->date('data');
                $table->integer('unidades')->default(0);
                $table->decimal('faturamento', 14, 2)->default(0);

                $table->unique(['company_id', 'inventory_id', 'data'], 'full_vendas_emp_inv_data_unique');
                $table->index(['company_id', 'data'], 'full_vendas_emp_data_idx');
            });
        }

        // Ajustes do planejador por empresa: lead times, segurança, fornecedores.
        if (! Schema::hasTable('full_configuracoes')) {
            Schema::create('full_configuracoes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
                $table->json('config')->nullable();
                $table->json('fornecedores')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Dados por SKU que o ML não conhece: galpão, pedido em aberto, compra.
        if (! Schema::hasTable('full_skus')) {
            Schema::create('full_skus', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('sku', 120);
                // Nulo = não informado (diferente de zero no galpão).
                $table->integer('galpao')->nullable();
                $table->integer('aberto')->nullable();
                $table->date('aberto_data')->nullable();
                $table->string('fornecedor', 120)->nullable();
                $table->unsignedSmallInteger('multiplo')->nullable();
                $table->unsignedSmallInteger('lead_time')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'sku'], 'full_skus_emp_sku_unique');
            });
        }

        // Ajustes por Código ML: demanda, quantidade manual, fora do envio.
        if (! Schema::hasTable('full_itens')) {
            Schema::create('full_itens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('inventory_id', 30);
                // Fração: 0,20 = +20% sobre a venda/dia.
                $table->decimal('ajuste', 6, 3)->nullable();
                $table->integer('qtd_manual')->nullable();
                $table->boolean('fora')->default(false);
                $table->string('observacao', 255)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'inventory_id'], 'full_itens_emp_inv_unique');
            });
        }

        // Envio ao FULL registrado no planejador (ou importado da planilha do ML).
        if (! Schema::hasTable('full_envios')) {
            Schema::create('full_envios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->date('data_despacho');
                // Número do envio no ML (inbound_id). Com ele, só as chegadas desse envio contam.
                $table->string('inbound_ml', 30)->nullable();
                // A partir de quando as chegadas contam para este envio. Nulo = início do dia do
                // despacho. A importação da planilha grava o horário do relatório ("Atualizado em
                // 15 de outubro às 08h45"): o que chegou antes já estava fora do pendente dele.
                $table->dateTime('chegadas_desde')->nullable();
                // a_caminho | recebido | cancelado
                $table->string('status', 20)->default('a_caminho');
                // planejador | planilha
                $table->string('origem', 20)->default('planejador');
                $table->string('observacao', 255)->nullable();
                $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('recebido_em')->nullable();
                // Última vez que a coleta conferiu as chegadas deste envio.
                $table->dateTime('conferido_em')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status'], 'full_envios_emp_status_idx');
            });
        }

        if (! Schema::hasTable('full_envio_itens')) {
            Schema::create('full_envio_itens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('envio_id')->constrained('full_envios')->cascadeOnDelete();
                $table->string('inventory_id', 30);
                $table->integer('quantidade');
                $table->integer('recebido')->default(0);
                $table->timestamps();

                $table->unique(['envio_id', 'inventory_id'], 'full_envio_itens_env_inv_unique');
            });
        }

        // Uma linha por execução da coleta — a tela mostra quando atualizou e por que falhou.
        if (! Schema::hasTable('full_coletas')) {
            Schema::create('full_coletas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                // agendada | manual
                $table->string('origem', 20)->default('agendada');
                // rodando | ok | sem_full | erro
                $table->string('status', 20)->default('rodando');
                $table->dateTime('iniciado_em');
                $table->dateTime('concluido_em')->nullable();
                $table->integer('produtos')->nullable();
                $table->integer('pedidos')->nullable();
                $table->text('erro')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'iniciado_em'], 'full_coletas_emp_inicio_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('full_coletas');
        Schema::dropIfExists('full_envio_itens');
        Schema::dropIfExists('full_envios');
        Schema::dropIfExists('full_itens');
        Schema::dropIfExists('full_skus');
        Schema::dropIfExists('full_configuracoes');
        Schema::dropIfExists('full_vendas_diarias');
        Schema::dropIfExists('full_produtos');
    }
};
