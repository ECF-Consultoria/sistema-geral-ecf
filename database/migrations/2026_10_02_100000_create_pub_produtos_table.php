<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 160 Plano 160-01 (D15) — âncora de produto do Publicador interno.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration existir.
 * Só tabela NOVA; o ALTER de `pub_rascunhos` mora na migration seguinte
 * (`2026_10_02_100100_add_produto_id_to_pub_rascunhos`), separada de propósito:
 * falha no meio não deixa "tabela criada sem índice + migration Pending".
 *
 * | coluna          | tipo                 | regra                                              |
 * |-----------------|----------------------|----------------------------------------------------|
 * | id              | bigint unsigned PK   |                                                    |
 * | mlb_empresa_id  | foreignId nullable   | FK `pubprod_empresa_fk` -> mlb_empresas, cascade   |
 * | company_id      | foreignId nullable   | FK `pubprod_company_fk` -> companies, cascade      |
 * | oferta_id       | foreignId nullable   | FK `pubprod_oferta_fk` -> estrutura_ofertas, SET NULL; unique `pubprod_oferta_uq` |
 * | sku             | string(120)          |                                                    |
 * | nome            | string(255)          |                                                    |
 * | origem          | string(12)           | default `publicador`; valores `portal` / `publicador` |
 * | timestamps      |                      |                                                    |
 *
 * Índices: `pubprod_empresa_sku_ix` (mlb_empresa_id, sku) NÃO único (SKU "Não tenho"
 * se repete no Portal; SKU repetido é só aviso) e `pubprod_company_ix` (company_id).
 *
 * `pubprod_oferta_fk` é `nullOnDelete` (D27): apagar a oferta no Portal SOLTA o
 * produto, que passa a se comportar como "cadastrado no Publicador", e não leva
 * rascunho nem histórico. O erro 1830 do MariaDB (SET NULL em coluna NOT NULL,
 * learnings `desempenho-bonificacao.md` §6) não se aplica: a coluna é anulável.
 * É o ÚNICO `nullOnDelete` da fase. NULL repetido passa em `unique` (MariaDB e
 * SQLite). Nomes curtos `pubprod_*` (limite de 64 chars, erro 1059). O programa
 * (Polos/Incubadora) NÃO é gravado (D13). "Pelo menos uma âncora" é regra do
 * serviço, sem CHECK. Rodar no MariaDB local com `--path` (o SQLite dos testes não
 * pega 1553/1059/1830).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pub_produtos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas', 'id', 'pubprod_empresa_fk')->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained('companies', 'id', 'pubprod_company_fk')->cascadeOnDelete();
            $t->foreignId('oferta_id')->nullable()->constrained('estrutura_ofertas', 'id', 'pubprod_oferta_fk')->nullOnDelete();
            $t->string('sku', 120);
            $t->string('nome', 255);
            $t->string('origem', 12)->default('publicador');
            $t->timestamps();

            $t->unique('oferta_id', 'pubprod_oferta_uq');
            $t->index(['mlb_empresa_id', 'sku'], 'pubprod_empresa_sku_ix');
            $t->index('company_id', 'pubprod_company_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pub_produtos');
    }
};
