<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration da tabela `contrato_assinaturas` — Fase 125
// (DADOS-01/DADOS-04). É o continente onde o processo de assinatura de cada
// empresa passa a viver: estado atual + datas de envio/assinatura/liberação.
// As Fases 126 (Clicksign), 129 (webhook) e 130 (liberação manual) preenchem
// as colunas nascidas vazias aqui; a Fase 131 lê tudo isso na tela.
//
// Decisões travadas aplicadas (ver 125-CONTEXT.md):
//   D-01 — no máximo um contrato em ANDAMENTO por empresa. Garantia dupla:
//   coluna auxiliar `company_id_em_andamento` + índice único no banco
//   (garantia real, sobrevive a duplo clique e retry de fila) + guard no
//   model (mensagem amigável, Task 2).
//   D-02 — reemissão cria registro novo; contrato encerrado permanece como
//   histórico. O schema PERMITE vários contratos por empresa.
//   D-03 — `expira_em` NÃO entra nesta fase (é a Fase 127). Nenhuma coluna
//   de prazo é criada aqui.
//   D-04 — `status` é STRING + constantes públicas no model, nunca `enum`
//   de banco. Este projeto já apanhou de `enum` + SQLite: o CHECK derruba a
//   suíte de testes e adicionar valor novo depois exige migration com
//   branch separado por driver.
//   D-05 — `liberado` não é estado; é a data `liberado_em`. Um contrato
//   `assinado` com `liberado_em` nulo é caso legítimo (é o que o alerta da
//   REDE-02 precisa enxergar).
//   D-06 — 7 estados possíveis, `recusado`/`expirado` nunca colapsam em
//   `cancelado`/`erro`.
//   D-10 — serviços e valores contratados congelados em JSON no instante da
//   geração — não são relidos ao vivo de `contratos_servico`. Precedente
//   concreto e vivido: um `hs_mrr = 0` do HubSpot já zerou 3 contratos de
//   R$ 3.000 neste projeto (memória do projeto).
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver <pitfalls> do
// 125-CONTEXT.md) — todas evitadas por construção nesta migration:
//   1. `enum` + SQLite — nenhuma coluna `$table->enum(` aqui.
//   2. FK `nullOnDelete()` sem `->nullable()` (erro 1830) — a única FK desta
//      tabela é `company_id`, NOT NULL de propósito, `cascadeOnDelete()`
//      (contrato sem empresa não faz sentido). Não há `nullOnDelete` aqui.
//   3. Nome de índice acima de 64 caracteres (erro 1059) — a falha é
//      SILENCIOSA: cria a tabela SEM o índice e deixa a migration `Pending`.
//      Todo índice desta migration é nomeado à mão, curto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato_assinaturas', function (Blueprint $table) {
            $table->id();

            // NOT NULL de propósito — contrato sem empresa não tem
            // significado jurídico próprio (ver T-125-06 no threat model).
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // STRING, nunca `enum` de banco (D-04). Ver comentário no topo
            // do arquivo — CHECK de enum quebra a suíte no SQLite e exige
            // migration por driver pra crescer.
            $table->string('status', 40)->default('rascunho');

            // Coluna auxiliar da D-01: espelha `company_id` SOMENTE enquanto
            // o status é de andamento (rascunho | aguardando_assinaturas) e
            // fica NULL fora disso. É espelho, não relacionamento — de
            // propósito SEM FK própria: uma segunda FK só traria mais um
            // identificador longo pra caber em 64 caracteres, sem ganho
            // (a integridade referencial já é coberta pela FK de
            // `company_id` acima). O índice único sobre esta coluna, lá
            // embaixo, é a garantia real da D-01.
            $table->unsignedBigInteger('company_id_em_andamento')->nullable();

            // Preenchidos pela Fase 126 (integração Clicksign).
            $table->string('clicksign_envelope_id', 64)->nullable();
            $table->string('clicksign_document_id', 64)->nullable();

            // DADOS-01 — datas do ciclo de vida do contrato.
            $table->timestamp('enviado_em')->nullable();
            // Preenchido pela Fase 129 (webhook Clicksign).
            $table->timestamp('assinado_em')->nullable();
            // D-05 — é DATA, não estado. `assinado` com `liberado_em` nulo é
            // o caso legítimo que o alerta da REDE-02 precisa enxergar.
            // Preenchido pela Fase 130 (liberação manual).
            $table->timestamp('liberado_em')->nullable();

            // DADOS-04 — detalhe da falha TÉCNICA (status = erro), separado
            // de recusa do cliente (status = recusado).
            $table->text('erro_mensagem')->nullable();

            // D-10 — congelamento dos serviços e valores contratados no
            // instante da geração. NÃO é relido ao vivo de
            // `contratos_servico` — o `hs_mrr = 0` do HubSpot já zerou 3
            // contratos de R$ 3.000 neste projeto por depender de valor
            // vivo (memória do projeto).
            $table->json('servicos_snapshot')->nullable();

            $table->timestamps();

            // Garantia REAL da D-01: MariaDB e SQLite tratam múltiplos NULL
            // como distintos, então contratos encerrados (coluna NULL) nunca
            // colidem entre si, e dois contratos em andamento da mesma
            // empresa colidem sempre. Nome curto de propósito — armadilha
            // 1059 (a que o Laravel geraria sozinho já estouraria bem menos
            // aqui, mas nomear à mão é a disciplina desta fase).
            $table->unique('company_id_em_andamento', 'ca_company_andamento_uniq');

            // Um envelope da Clicksign nunca pertence a dois contratos.
            // Múltiplos NULL seguem permitidos (contratos ainda não
            // enviados não têm envelope).
            $table->unique('clicksign_envelope_id', 'ca_clicksign_envelope_uniq');

            // Leitura "histórico de contratos desta empresa".
            $table->index(['company_id', 'status'], 'ca_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_assinaturas');
    }
};
