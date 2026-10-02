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
 * | mlb_empresa_id  | foreignId nullable   | FK `pubprod_empresa_fk` -> mlb_empresas, SET NULL  |
 * | company_id      | foreignId nullable   | FK `pubprod_company_fk` -> companies, SET NULL     |
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
 *
 * `pubprod_empresa_fk` e `pubprod_company_fk` também são `nullOnDelete` (CR-B02 do
 * code review, decisão do usuário em 02/10/2026): excluir a `MlbEmpresa` (gestor de
 * Polos, `DELETE /mlb/empresas/{empresa}`) ou a `Company` NÃO apaga o histórico. Em
 * CASCADE a exclusão levava produto → rascunho → publicações → itens, com o
 * `ml_item_id`, o payload enviado e a resposta crua do ML de anúncios que seguem no
 * ar — para produto de Polos sem Portal, o ÚNICO registro do que a ECF publicou. Com
 * as duas âncoras nulas o produto fica órfão: some das telas (`empresaDoProduto()`
 * devolve null → 404) e `conta()` lança V-ACC-01, mas o histórico fica. Mesma escolha
 * do motor antigo (`2026_07_13_100001…`, "preserva o rascunho se a empresa for
 * hard-deletada"). Banco onde esta migration já rodou com CASCADE (o MariaDB local)
 * é consertado por `2026_10_02_200000_pub_produtos_ancoras_sem_cascata`.
 *
 * NULL repetido passa em `unique` (MariaDB e
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
            // SET NULL (CR-B02): excluir a empresa não apaga o histórico de publicação.
            $t->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas', 'id', 'pubprod_empresa_fk')->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained('companies', 'id', 'pubprod_company_fk')->nullOnDelete();
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
