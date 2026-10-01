<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeamento Estrutural — o mecanismo da planilha do Projeto Polos como módulo
 * do Portal do Cliente. Decisão de schema: `.planning/adrs/PORTAL-01-mapeamento-estrutural-schema.md`.
 *
 * Prefixo `estrutura_` e não `mapeamento_`: `onboarding_mapeamentos` já existe
 * e é outra coisa.
 *
 * Armadilhas de MariaDB observadas aqui:
 * - nenhum `enum` — varchar + constante no model;
 * - nenhum `timestamp()` NOT NULL: no MariaDB a primeira coluna TIMESTAMP NOT
 *   NULL sem default ganha `ON UPDATE CURRENT_TIMESTAMP` sozinha, e o SQLite
 *   dos testes não reproduz (`portal-do-cliente.md` §18). `concluida_em` é
 *   `dateTime`; `timestamps()` é nullable;
 * - nomes de índice e FK explícitos e curtos (limite de 64 caracteres).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Uma linha da aba "Lista SKUs". Quem identifica é o `id`, NUNCA o SKU:
        // cliente digita "Não tenho" no SKU de todos os produtos
        // (`precificacao-onboarding-duas-telas.md` §3).
        Schema::create('estrutura_ofertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'eo_company_fk')->cascadeOnDelete();
            $table->string('sku', 120);
            $table->string('fase', 10);
            $table->string('nome', 255)->nullable();
            $table->string('logistica', 30)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index('company_id', 'eo_company_idx');
        });

        // Composição de combo/kit/combit. `restrict` no componente: não se
        // apaga a Cadeira 01 enquanto um combo a usa.
        Schema::create('estrutura_oferta_componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'eoc_oferta_fk')->cascadeOnDelete();
            $table->foreignId('componente_id')->constrained('estrutura_ofertas', 'id', 'eoc_componente_fk')->restrictOnDelete();
            $table->unsignedSmallInteger('quantidade');

            $table->unique(['oferta_id', 'componente_id'], 'eoc_oferta_comp_unq');
        });

        // Uma linha da aba "Anúncios". Sem `company_id`: a empresa vem pela
        // oferta, e duplicar a coluna criaria duas verdades.
        Schema::create('estrutura_anuncios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'ea_oferta_fk')->cascadeOnDelete();
            $table->string('tipo', 10);
            $table->boolean('catalogo')->default(false);
            $table->boolean('kit_virtual')->default(false);
            $table->string('status', 10)->default('ativo');
            $table->string('codigo_mlb', 20)->nullable();
            $table->string('titulo', 255)->nullable();
            $table->timestamps();

            $table->index('oferta_id', 'ea_oferta_idx');
            $table->index('codigo_mlb', 'ea_mlb_idx');
        });

        // Colado sem oferta: SKU que não casa (ou casa com mais de uma, ou nem
        // veio). Tabela separada para que nenhuma régua do painel precise
        // lembrar de filtrar o que está em espera.
        Schema::create('estrutura_anuncios_espera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'eae_company_fk')->cascadeOnDelete();
            $table->string('sku_colado', 120)->nullable();
            $table->string('motivo', 12);
            $table->string('tipo', 10);
            $table->boolean('catalogo')->default(false);
            $table->boolean('kit_virtual')->default(false);
            $table->string('status', 10)->default('ativo');
            $table->string('codigo_mlb', 20)->nullable();
            $table->string('titulo', 255)->nullable();
            $table->timestamps();

            $table->index('company_id', 'eae_company_idx');
        });

        // Uma linha da aba "Planejamento". A Publicação não guarda estado: ela
        // está feita quando a oferta tem os anúncios. `concluida_em` é só da
        // Jardinagem.
        Schema::create('estrutura_agenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'eag_oferta_fk')->cascadeOnDelete();
            $table->date('data');
            $table->string('acao', 12);
            $table->dateTime('concluida_em')->nullable();
            $table->timestamps();

            $table->index(['oferta_id', 'data'], 'eag_oferta_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estrutura_agenda');
        Schema::dropIfExists('estrutura_anuncios_espera');
        Schema::dropIfExists('estrutura_anuncios');
        Schema::dropIfExists('estrutura_oferta_componentes');
        Schema::dropIfExists('estrutura_ofertas');
    }
};
