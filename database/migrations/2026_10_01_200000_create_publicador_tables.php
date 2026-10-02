<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicador do Anunciar (portal) — as entidades do `04` da especificação, com
 * o desenho decidido em `.planning/publicador-ml-spec/16-analise-do-portal.md`
 * §4 (revisão de 01/10/2026). Só tabelas NOVAS: nenhuma tabela com dado em
 * produção é alterada (`estrutura_publicacoes` fica só para leitura).
 *
 * MariaDB: FK e unique com nome curto explícito; nenhum `nullOnDelete`; nenhum
 * `timestamp()` fora do `timestamps()` (datas avulsas são `dateTime`); chaves
 * longas viram sha256 em `char(64)`; e `NULL` não se repete em `unique` — por
 * isso a galeria geral é `grupo_chave = 'GENERAL'`, nunca NULL. Rodar no
 * MariaDB local com `--path` (o SQLite dos testes não pega essas armadilhas).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cache do schema de categoria: as 4 respostas cruas do ML (RN-22). Nunca guarda falha.
        Schema::create('ml_categoria_schemas', function (Blueprint $t) {
            $t->string('category_id', 20)->primary();
            $t->string('domain_id', 80)->nullable();
            $t->json('categoria');
            $t->json('atributos');
            $t->json('technical_specs')->nullable();
            $t->json('sale_terms')->nullable();
            $t->char('schema_hash', 64);
            $t->dateTime('fetched_at');
            $t->timestamps();
        });

        // O rascunho: um por oferta do Mapeamento (decisão do usuário, 01/10).
        Schema::create('pub_rascunhos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'pubr_oferta_fk')->cascadeOnDelete();
            $t->string('status', 24)->default('DRAFT');
            $t->unsignedInteger('revisao')->default(1);
            $t->json('step_state')->nullable();
            $t->json('identificacao')->nullable();
            $t->string('categoria_id', 20)->nullable();
            $t->string('dominio_id', 80)->nullable();
            $t->char('schema_hash', 64)->nullable();
            $t->string('condicao', 12)->default('new');
            $t->text('descricao')->nullable();
            $t->json('envio')->nullable();
            $t->json('garantia')->nullable();
            $t->boolean('fotos_por_variante')->default(false);
            $t->boolean('incluir_geral_nas_variantes')->default(true);
            $t->string('modelo_publicacao', 16)->nullable();
            $t->dateTime('conta_checada_em')->nullable();
            $t->json('ator')->nullable();
            $t->timestamps();

            $t->unique('oferta_id', 'pubr_oferta_uq');
        });

        // D1: Clássico e/ou Premium do mesmo rascunho, cada um com o seu título.
        Schema::create('pub_rascunho_alvos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rascunho_id')->constrained('pub_rascunhos', 'id', 'pubal_rascunho_fk')->cascadeOnDelete();
            $t->string('listing_type_id', 20);
            $t->string('titulo', 255)->nullable();
            $t->boolean('ativo')->default(true);
            $t->unsignedSmallInteger('posicao')->default(0);
            $t->timestamps();

            $t->unique(['rascunho_id', 'listing_type_id'], 'pubal_tipo_uq');
        });

        // Atributos do PRODUTO (papel PRODUCT). Atributo que é eixo não tem linha aqui (RN-51).
        Schema::create('pub_rascunho_atributos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rascunho_id')->constrained('pub_rascunhos', 'id', 'pubat_rascunho_fk')->cascadeOnDelete();
            $t->string('attribute_id', 80);
            $t->string('value_id', 100)->nullable();
            $t->string('value_name', 255)->nullable();
            $t->decimal('value_number', 16, 4)->nullable();
            $t->string('value_unit', 20)->nullable();
            $t->json('values_multi')->nullable();
            $t->string('origem', 10)->default('user');
            $t->boolean('revisar')->default(false);
            $t->timestamps();

            $t->unique(['rascunho_id', 'attribute_id'], 'pubat_attr_uq');
        });

        // Eixos de variação. attribute_id nulo = eixo customizado (no máximo um: V-VAR-02, na validação).
        Schema::create('pub_eixos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rascunho_id')->constrained('pub_rascunhos', 'id', 'pubei_rascunho_fk')->cascadeOnDelete();
            $t->string('attribute_id', 80)->nullable();
            $t->string('nome', 120);
            $t->unsignedTinyInteger('posicao')->default(0);
            $t->boolean('defines_picture')->default(false);
            // Eixo tirado da tela fica enquanto uma variante órfã apontar para ele.
            $t->boolean('removido')->default(false);
            $t->timestamps();

            $t->unique(['rascunho_id', 'attribute_id'], 'pubei_attr_uq');
        });

        // Valores de eixo. Nunca apagados enquanto houver variante órfã apontando: `removido`.
        Schema::create('pub_eixo_valores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('eixo_id')->constrained('pub_eixos', 'id', 'pubev_eixo_fk')->cascadeOnDelete();
            $t->string('value_id', 100)->nullable();
            $t->string('value_name', 255);
            // `id:52049` ou `txt:<texto>`: texto livre pode passar de 191 — o índice é o hash.
            $t->string('chave', 300);
            $t->char('chave_hash', 64);
            $t->unsignedSmallInteger('posicao')->default(0);
            $t->boolean('removido')->default(false);
            $t->timestamps();

            $t->unique(['eixo_id', 'chave_hash'], 'pubev_chave_uq');
        });

        // Variantes (combinações). Sempre ≥ 1: produto simples = `__single__`. Ids do ML NÃO moram aqui (D5).
        Schema::create('pub_variantes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rascunho_id')->constrained('pub_rascunhos', 'id', 'pubva_rascunho_fk')->cascadeOnDelete();
            $t->text('combinacao_chave');
            $t->char('combinacao_hash', 64);
            $t->boolean('ativa')->default(true);
            $t->boolean('orfa')->default(false);
            $t->boolean('publicada')->default(false);
            $t->integer('estoque')->nullable();
            $t->json('estoque_depositos')->nullable();
            $t->unsignedSmallInteger('posicao')->default(0);
            $t->timestamps();

            $t->unique(['rascunho_id', 'combinacao_hash'], 'pubva_combo_uq');
        });

        Schema::create('pub_variante_eixo_valores', function (Blueprint $t) {
            $t->foreignId('variante_id')->constrained('pub_variantes', 'id', 'pubvev_variante_fk')->cascadeOnDelete();
            $t->foreignId('eixo_id')->constrained('pub_eixos', 'id', 'pubvev_eixo_fk')->cascadeOnDelete();
            $t->foreignId('eixo_valor_id')->constrained('pub_eixo_valores', 'id', 'pubvev_valor_fk')->cascadeOnDelete();

            $t->primary(['variante_id', 'eixo_id'], 'pubvev_pk');
        });

        // Dados da variante (VARIANT_DATA): SELLER_SKU, GTIN, EMPTY_GTIN_REASON…
        Schema::create('pub_variante_atributos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('variante_id')->constrained('pub_variantes', 'id', 'pubvat_variante_fk')->cascadeOnDelete();
            $t->string('attribute_id', 80);
            $t->string('value_id', 100)->nullable();
            $t->string('value_name', 255)->nullable();
            $t->decimal('value_number', 16, 4)->nullable();
            $t->string('value_unit', 20)->nullable();
            $t->timestamps();

            $t->unique(['variante_id', 'attribute_id'], 'pubvat_attr_uq');
        });

        // Preço por variante × alvo. Nulo = herda o preço da Precificação (dados efetivos, ADR PORTAL-02).
        Schema::create('pub_variante_precos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('variante_id')->constrained('pub_variantes', 'id', 'pubvp_variante_fk')->cascadeOnDelete();
            $t->foreignId('alvo_id')->constrained('pub_rascunho_alvos', 'id', 'pubvp_alvo_fk')->cascadeOnDelete();
            $t->decimal('preco', 12, 2)->nullable();
            $t->timestamps();

            $t->unique(['variante_id', 'alvo_id'], 'pubvp_uq');
        });

        // Fotos. `caminho` nulo = foto que veio do Anunciar antigo (só o id do ML, sem arquivo).
        Schema::create('pub_imagens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rascunho_id')->constrained('pub_rascunhos', 'id', 'pubim_rascunho_fk')->cascadeOnDelete();
            $t->string('caminho', 255)->nullable();
            $t->char('sha256', 64)->nullable();
            $t->string('mime', 40)->nullable();
            $t->unsignedInteger('bytes')->nullable();
            $t->unsignedSmallInteger('largura')->nullable();
            $t->unsignedSmallInteger('altura')->nullable();
            $t->string('ml_picture_id', 100)->nullable();
            $t->string('ml_url', 500)->nullable();
            $t->string('upload_status', 10)->default('pending');
            $t->json('upload_erro')->nullable();
            $t->timestamps();

            $t->unique(['rascunho_id', 'sha256'], 'pubim_sha_uq');
        });

        // Atribuição da foto a um grupo (GENERAL ou a chave do grupo — `06` §3).
        Schema::create('pub_imagem_atribuicoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('imagem_id')->constrained('pub_imagens', 'id', 'pubia_imagem_fk')->cascadeOnDelete();
            // GENERAL ou a chave do grupo (dois eixos de texto livre passam de 191): índice pelo hash.
            $t->string('grupo_chave', 600)->default('GENERAL');
            $t->char('grupo_hash', 64);
            $t->unsignedSmallInteger('posicao')->default(0);
            $t->timestamps();

            $t->unique(['imagem_id', 'grupo_hash'], 'pubia_grupo_uq');
        });

        // Uma validação vale para UMA revisão (D4: os problemas em JSON, no formato do `04` §2.12).
        Schema::create('pub_validacoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rascunho_id')->constrained('pub_rascunhos', 'id', 'pubval_rascunho_fk')->cascadeOnDelete();
            $t->unsignedInteger('revisao');
            $t->string('camada', 4);
            $t->char('plano_hash', 64)->nullable();
            $t->string('resultado', 12);
            $t->json('issues')->nullable();
            $t->json('respostas_ml')->nullable();
            $t->timestamps();

            $t->index(['rascunho_id', 'revisao'], 'pubval_revisao_ix');
        });

        Schema::create('pub_publicacoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rascunho_id')->constrained('pub_rascunhos', 'id', 'pubpu_rascunho_fk')->cascadeOnDelete();
            $t->unsignedInteger('revisao');
            $t->string('modelo_publicacao', 16);
            $t->json('conta_snapshot')->nullable();
            $t->char('plano_hash', 64)->nullable();
            $t->string('status', 24)->default('RUNNING');
            $t->char('chave_idempotencia', 36);
            $t->dateTime('iniciada_em')->nullable();
            $t->dateTime('concluida_em')->nullable();
            $t->json('ator')->nullable();
            $t->timestamps();

            $t->unique('chave_idempotencia', 'pubpu_chave_uq');
        });

        // Um POST /items. Payload e resposta BRUTOS ficam (V11); o MLB é gravado no instante do 201 (RN-92).
        Schema::create('pub_publicacao_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('publicacao_id')->constrained('pub_publicacoes', 'id', 'pubpi_publicacao_fk')->cascadeOnDelete();
            $t->unsignedSmallInteger('indice');
            $t->string('listing_type_id', 20);
            $t->text('variante_chave');
            $t->string('caminho', 24)->default('items');
            $t->json('payload')->nullable();
            $t->char('payload_hash', 64)->nullable();
            $t->string('status', 10)->default('PENDING');
            $t->unsignedTinyInteger('tentativas')->default(0);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->json('resposta')->nullable();
            $t->string('ml_item_id', 20)->nullable();
            $t->string('ml_user_product_id', 30)->nullable();
            $t->json('avisos')->nullable();
            $t->string('descricao_status', 8)->default('NONE');
            $t->dateTime('enviado_em')->nullable();
            $t->dateTime('criado_em')->nullable();
            $t->timestamps();

            $t->unique(['publicacao_id', 'indice'], 'pubpi_indice_uq');
            $t->index('ml_item_id', 'pubpi_mlb_ix');
        });
    }

    public function down(): void
    {
        foreach ([
            'pub_publicacao_itens', 'pub_publicacoes', 'pub_validacoes', 'pub_imagem_atribuicoes', 'pub_imagens',
            'pub_variante_precos', 'pub_variante_atributos', 'pub_variante_eixo_valores', 'pub_variantes',
            'pub_eixo_valores', 'pub_eixos', 'pub_rascunho_atributos', 'pub_rascunho_alvos', 'pub_rascunhos',
            'ml_categoria_schemas',
        ] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
