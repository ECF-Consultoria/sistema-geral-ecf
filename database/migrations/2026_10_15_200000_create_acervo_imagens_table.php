<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v28.0 Fase 197 (ATV-01/ATV-02/ATV-05) — o acervo de imagens da conta do Mercado Livre.
 *
 * Decisões de schema (REQUIREMENTS-v28 D1/D2/D7):
 * - Uma linha por IMAGEM por CONTA (`dono_chave` = `m{mlb_empresa_id}` ou `c{company_id}`, na mesma
 *   ordem de `PubProduto::ancoraComToken()`), única por (`dono_chave`, `sha256`): a mesma foto em dois
 *   anúncios da mesma conta é UM objeto físico.
 * - `pub_imagens` continua existindo e ganha só `acervo_imagem_id` (anulável). As FKs da v27.0 para
 *   `pub_imagens` (`pub_aprovacao_imagens`, `pub_imagem_referencias`, `pub_aprovacao_conjuntos`) não mudam.
 * - Âncoras e produto são `nullOnDelete` (colunas nullable — MariaDB 1830): apagar empresa/produto não
 *   apaga o registro do arquivo; o objeto órfão é tratado pela exclusão segura (Fase 199), nunca em cascata.
 * - `criativo_id` sem FK: `ml_anuncio_criativos` apaga em cascata com a empresa; aqui é só rastro.
 * - `sku`/`produto_nome` são retrato do momento em que a imagem entrou (o produto pode mudar ou sumir).
 * - Índices nomeados à mão (≤ 64 chars, MariaDB 1059) e criados antes das FKs (MariaDB 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acervo_imagens')) {
            Schema::create('acervo_imagens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('mlb_empresa_id')->nullable();
                $t->string('dono_chave', 24);
                $t->string('ml_user_id', 40)->nullable();

                $t->string('disco', 20);
                $t->string('caminho', 255);
                $t->char('sha256', 64);
                $t->string('mime', 40);
                $t->unsignedInteger('bytes');
                $t->unsignedInteger('largura')->nullable();
                $t->unsignedInteger('altura')->nullable();
                $t->string('miniatura_caminho', 255)->nullable();

                $t->string('origem', 10)->default('upload');
                $t->string('modelo_ia', 120)->nullable();
                $t->unsignedBigInteger('criativo_id')->nullable();
                $t->string('nome_original', 255)->nullable();

                $t->unsignedBigInteger('produto_id')->nullable();
                $t->string('sku', 120)->nullable();
                $t->string('produto_nome', 255)->nullable();

                $t->string('status', 12)->default('disponivel');
                $t->unsignedTinyInteger('exclusao_tentativas')->default(0);
                $t->text('exclusao_erro')->nullable();
                $t->dateTime('exclusao_pedida_em')->nullable();
                $t->timestamps();

                $t->unique(['dono_chave', 'sha256'], 'acvi_dono_sha_uq');
                $t->index(['dono_chave', 'status', 'created_at'], 'acvi_dono_status_ix');
                $t->index('company_id', 'acvi_company_ix');
                $t->index('mlb_empresa_id', 'acvi_mlbemp_ix');
                $t->index('produto_id', 'acvi_produto_ix');
                $t->index('criativo_id', 'acvi_criativo_ix');

                $t->foreign('company_id', 'acvi_company_fk')->references('id')->on('companies')->nullOnDelete();
                $t->foreign('mlb_empresa_id', 'acvi_mlbemp_fk')->references('id')->on('mlb_empresas')->nullOnDelete();
                $t->foreign('produto_id', 'acvi_produto_fk')->references('id')->on('pub_produtos')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('pub_imagens', 'acervo_imagem_id')) {
            Schema::table('pub_imagens', function (Blueprint $t) {
                $t->unsignedBigInteger('acervo_imagem_id')->nullable()->after('caminho');
                $t->index('acervo_imagem_id', 'pubim_acervo_ix');
                $t->foreign('acervo_imagem_id', 'pubim_acervo_fk')->references('id')->on('acervo_imagens')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pub_imagens', 'acervo_imagem_id')) {
            Schema::table('pub_imagens', function (Blueprint $t) {
                $t->dropForeign('pubim_acervo_fk');
                $t->dropIndex('pubim_acervo_ix');
                $t->dropColumn('acervo_imagem_id');
            });
        }

        Schema::dropIfExists('acervo_imagens');
    }
};
