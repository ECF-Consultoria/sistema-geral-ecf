<?php

// pt-BR: Migration da linha CORRENTE por anúncio do acervo Mercado Livre —
// a tela "Meus Anúncios" (Fase 134) lê exclusivamente daqui (D-05), nunca
// chamando o ML no caminho do request. Upsert por (company_id, ml_item_id) a
// cada sync diário (D-06/D-07a). Ordem de grandeza esperada: ~500 mil linhas
// (406.932 itens ativos medidos em 74 contas na sondagem do 134-RESEARCH.md).
//
// D-17 — anúncio com variação ocupa UMA linha: o ML já devolve
// available_quantity/sold_quantity agregados no item-pai; `variations` guarda
// o payload bruto, mesma filosofia de `ml_anuncio_rascunhos.payload`.
// D-18 — `buybox_status`/`visitas_30d` nascem `null` e ficam `null` até a
// rotação da camada cara cobrir o item — "não avaliado" nunca é um default
// inventado, é a ausência real de coleta.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_acervo_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('ml_item_id', 20); // MLBxxxxxxxxxx

            // ─── Dados do anúncio (camada barata — multiget /items?ids=) ───
            $table->string('title', 512)->nullable();
            $table->string('category_id', 32)->nullable();
            $table->string('status', 20)->nullable(); // active | paused | closed | under_review — STRING, não enum (armadilha item 6 do learning)
            $table->json('sub_status')->nullable();
            $table->string('listing_type_id', 32)->nullable(); // gold_special = Clássico | gold_pro = Premium
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('available_quantity')->nullable(); // D-17: agregado que o ML já devolve no item-pai
            $table->integer('sold_quantity')->nullable(); // vitalício, não "vendas do dia"
            $table->string('permalink', 512)->nullable(); // já vem pronto da API — não reconstruir
            $table->string('thumbnail', 512)->nullable();
            $table->unsignedInteger('fotos_count')->default(0);
            $table->boolean('has_variations')->default(false);
            $table->json('variations')->nullable(); // D-17: payload bruto
            $table->boolean('catalog_listing')->default(false);
            $table->string('catalog_product_id', 32)->nullable();
            $table->json('shipping')->nullable();
            $table->json('tags')->nullable();

            // ─── Nota ECF e triagem (D-09/D-10/D-12/D-22) ───
            $table->unsignedTinyInteger('nota_ecf')->nullable(); // 0..86, NUNCA renormalizada para 100 (D-22)
            $table->json('nota_sinais')->nullable(); // breakdown que TEM que somar nota_ecf
            $table->json('motivos')->nullable(); // ['pausado','sem_estoque','ficha_incompleta','perdendo_catalogo','foto_insuficiente']
            $table->unsignedTinyInteger('severidade')->default(0); // 2=crítico, 1=atenção, 0=saudável/não avaliado
            $table->string('origem', 8)->default('legado'); // ecf | time | legado (D-04)

            // rascunho_id SEM foreignId/constrained de propósito: uma FK com
            // nullOnDelete exigiria nullable() (erro 1830, learning item 6) e
            // criaria dependência de ordem de drop entre ml_anuncio_rascunhos
            // e esta tabela. O vínculo é lógico — `origem` já registra a
            // ligação semanticamente; quem precisar do rascunho consulta por
            // este id sem JOIN obrigatório.
            $table->unsignedBigInteger('rascunho_id')->nullable();

            $table->integer('publicacao_vendas_qty')->nullable(); // snapshot de Publicacao.vendas_qty
            $table->boolean('publicacao_desconsiderado')->default(false); // snapshot de Publicacao.desconsiderado

            // ─── Saúde do ML — D-21 (veredicto DISPONÍVEL, ver 134-01-SUMMARY.md) ───
            // ATENÇÃO: três escalas de "saúde" convivem nesta tabela, cada
            // uma com origem, custo e regra de null diferentes. NUNCA
            // converter uma na outra nem preencher uma a partir da outra
            // quando faltar dado — é o modo de falha que este projeto já
            // viveu com nps_medio != pontos_componentes.nps
            // (.planning/learnings/desempenho-bonificacao.md).
            //   health_ml          0.00-1.00  campo `health` do multiget (grátis, camada barata)
            //   performance_score  0-100      GET /item/{id}/performance   (1 call/item, camada cara)
            //   nota_ecf           0-86       AnuncioSaudeService (plano 134-03, derivada, grátis)
            $table->decimal('health_ml', 3, 2)->nullable(); // decimal, não float — igualdade em teste com float é fonte de flake
            $table->unsignedInteger('visitas_30d')->nullable(); // camada cara — null = não avaliado (D-18)
            $table->string('buybox_status', 24)->nullable(); // winning|sharing_first_place|losing|listed — null = não avaliado (D-18)
            $table->unsignedTinyInteger('performance_score')->nullable(); // escala 0..100, != health_ml e != nota_ecf
            $table->string('performance_level', 16)->nullable(); // good|medium|low|incomplete — vem pronto do ML
            $table->json('performance_acoes')->nullable(); // variables com status=PENDING: key + title já em pt-BR do ML

            // ─── Frescor (D-08 — degradação graciosa, nunca tela em branco) ───
            $table->timestamp('detalhe_coletado_em')->nullable(); // frescor PRÓPRIO da camada cara (nível de campo)
            $table->timestamp('coletado_em')->nullable(); // frescor da camada barata
            $table->string('coleta_erro', 255)->nullable(); // motivo da última falha (banner D-08)

            $table->timestamps();

            // ─── Índices nomeados à mão (prefixo mai_) ───────────────────────
            // "ml_acervo_itens" já é nome longo — QUALQUER índice composto
            // automático estoura os 64 caracteres do MariaDB (erro 1059,
            // learning item 6). Nomear tudo manualmente, sempre.
            $table->unique(['company_id', 'ml_item_id'], 'mai_company_item_unq'); // chave de upsert
            $table->index(['company_id', 'status'], 'mai_company_status_idx'); // filtro "só ativos" (D-03)
            $table->index(['company_id', 'severidade', 'nota_ecf'], 'mai_company_sev_nota_idx'); // ordenação por gravidade (D-12)
            $table->index(['company_id', 'detalhe_coletado_em'], 'mai_company_detalhe_idx'); // seleção da fatia da rotação cara (D-23)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_acervo_itens');
    }
};
