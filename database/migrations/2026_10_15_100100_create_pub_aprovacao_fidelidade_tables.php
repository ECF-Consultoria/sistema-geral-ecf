<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15/10/2026 — Fase 186, o portão no servidor: o **checklist de fidelidade** (DADO-07) e os
 * **defeitos marcados** (DADO-08) de cada imagem na revisão. Migration de **assunto próprio**,
 * separada da `2026_10_15_100000_...` de propósito: duas migrations pequenas por assunto, em
 * vez de uma monolítica, porque DDL que falha no meio deixa tabela alterada e migration
 * `Pending` — e quanto menor o escopo de cada uma, menor o estado parcial possível (T-186-08).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2 / MIG-07), escrita ANTES do código. Só CRIA
 * tabelas; nenhuma tabela existente com dado muda, nenhuma linha é apagada, nenhuma coluna é
 * renomeada (MIG-09). Sem `enum()` (o CHECK é enforçado no SQLite dos testes — MIG-04), sem
 * `->change()`, sem backfill.
 *
 * ⚠️ **DEPENDÊNCIA DE ORDEM:** as duas tabelas são filhas de `pub_aprovacao_imagens`, criada
 * pela `2026_10_15_100000_...`. O Laravel roda por nome de arquivo (`100000` antes de
 * `100100`) e desfaz na ordem inversa do batch, então o `down()` daqui corre ANTES do dela.
 * Rodar esta migration isolada num banco sem a `100000` falha na FK — e falhar é o certo:
 * criar a tabela sem a FK "para passar" é o que produz dado órfão em produção.
 *
 * ═══ `pub_aprovacao_fidelidade` — o checklist de 7 critérios (DADO-07) ═════════════════════
 * | coluna              | tipo                      | regra / por quê                                                       |
 * |---------------------|---------------------------|-----------------------------------------------------------------------|
 * | id                  | bigint unsigned PK        |                                                                       |
 * | aprovacao_imagem_id | unsignedBigInteger        | FK `pubapfd_imagem_fk` -> pub_aprovacao_imagens, CASCADE: imagem fora da revisão leva o checklist dela |
 * | criterio            | string(16)                | os 7 literais `cor`, `formato`, `material`, `textura`, `proporcoes`, `quantidade`, `acessorios` (maior = 10); sem enum |
 * | resultado           | string(8)                 | `confere` \| `diverge` (7 chars cada); sem enum                       |
 * | quem_id             | unsignedBigInteger NULL   | FK `pubapfd_quem_fk` -> users, SET NULL: usuário apagado não apaga o que ele conferiu |
 * | quando              | dateTime NULL             | `dateTime`, nunca `timestamp()`, em data avulsa (convenção de `2026_10_01_200000`) |
 * | timestamps          |                           |                                                                       |
 *
 * unique (`aprovacao_imagem_id`, `criterio`) — **um veredicto por critério por imagem**. É o
 * unique que impede a tela de acumular dois "cor" contraditórios na mesma foto.
 *
 * ⚠️ **`quantidade` ("Quantidade do kit") só se aplica em COMBO, e essa regra é do SERVIÇO,
 * não do schema.** O schema aceita a linha; quem decide se o critério é aplicável é
 * `PubAprovacaoFidelidade::aplicavel(bool $ehCombo)`. A razão de não travar aqui: "Unidade" e
 * "Combo ×N" são produtos DIFERENTES (`pub_produtos` próprio, `produto_base_id` apontando para
 * o base, `quantidade_kit >= 2` — docblock de `PubProduto`), logo rascunhos diferentes, e o
 * schema desta tabela não alcança o produto sem um join de 3 saltos. CHECK que precisa de join
 * não existe em MariaDB 10.4, e replicar a flag aqui seria a coluna que diverge do produto.
 *
 * ═══ `pub_aprovacao_defeitos` — os 6 chips de defeito (DADO-08) ════════════════════════════
 * | coluna              | tipo                      | regra / por quê                                                      |
 * |---------------------|---------------------------|----------------------------------------------------------------------|
 * | id                  | bigint unsigned PK        |                                                                      |
 * | aprovacao_imagem_id | unsignedBigInteger        | FK `pubapdf_imagem_fk` -> pub_aprovacao_imagens, CASCADE             |
 * | defeito             | string(16)                | os 6 literais `deform`, `dup`, `falta`, `texto`, `recorte`, `inventado` (maior = 9); sem enum |
 * | quem_id             | unsignedBigInteger NULL   | FK `pubapdf_quem_fk` -> users, SET NULL                              |
 * | quando              | dateTime NULL             | idem acima                                                           |
 * | timestamps          |                           |                                                                      |
 *
 * unique (`aprovacao_imagem_id`, `defeito`) — **o chip está marcado ou não está**. A presença
 * da linha É a marcação; não há coluna `marcado` booleana, porque ela permitiria duas verdades
 * (linha ausente × linha com `marcado = false`) para o mesmo fato.
 *
 * ⚠️ **Não há `resultado` aqui, ao contrário da fidelidade**, e a diferença é de semântica: o
 * critério de fidelidade tem DOIS veredictos possíveis (`confere`/`diverge`) e o não-conferido
 * é a ausência da linha; o defeito tem UM (está presente), e a ausência é "não vi defeito".
 *
 * ═══ DADO-14 — o recorte por conta/empresa ════════════════════════════════════════════════
 * Nenhuma das duas tem `company_id`/`mlb_empresa_id`, pela mesma decisão da migration
 * anterior: o recorte vem por `aprovacao_imagem_id -> pub_aprovacao_imagens.conjunto_id ->
 * pub_aprovacao_conjuntos.rascunho_id -> pub_rascunhos.produto_id -> pub_produtos.company_id /
 * .mlb_empresa_id`. Coluna duplicada de conta pode divergir do produto e seria uma via de
 * vazamento entre empresas **que o schema abençoaria**.
 *
 * ═══ NOMES DE ÍNDICE E DE FK, COM OS CARACTERES CONTADOS (MIG-02) ══════════════════════════
 * O MariaDB recusa nome acima de **64** (erro **1059**), o SQLite aceita, e a migration fica
 * **`Pending` com a tabela JÁ criada** (`project_mariadb_nome_indice_64` nos learnings).
 *
 *   NOMEADOS À MÃO (padrão do módulo):
 *     pubapfd_crit_uq ....... 15      pubapdf_def_uq ........ 14
 *     pubapfd_quem_ix ....... 15      pubapdf_quem_ix ....... 15
 *     pubapfd_imagem_fk ..... 17      pubapdf_imagem_fk ..... 17
 *     pubapfd_quem_fk ....... 15      pubapdf_quem_fk ....... 15
 *
 *   NOMES PADRÃO DO LARAVEL (`<tabela>_<coluna>_foreign`) — contados porque são os que
 *   ESTOURAM, e todos passam folgados (nenhum é usado: toda FK aqui é nomeada à mão):
 *     pub_aprovacao_fidelidade_aprovacao_imagem_id_foreign ... 52
 *     pub_aprovacao_fidelidade_quem_id_foreign ............... 40
 *     pub_aprovacao_defeitos_aprovacao_imagem_id_foreign ..... 50
 *     pub_aprovacao_defeitos_quem_id_foreign ................. 38
 *
 * ⚠️ O guarda (`MigrationDoPortaoGuardaTest`) calcula esses números por `strlen`, nunca por
 * número escrito à mão — número copiado à mão é exatamente o que não se confere. Foi assim que
 * se mediu que o 186-03-PLAN.md errava em 1 o nome mais longo da migration anterior (dizia 57,
 * são 56).
 *
 * ═══ A DISCIPLINA RESTANTE ═════════════════════════════════════════════════════════════════
 * - **MIG-03** — toda FK `nullOnDelete()` tem `->nullable()` **EXPLÍCITO** (erro **1830** do
 *   MariaDB, invisível no SQLite): vale para os dois `quem_id`.
 * - **MIG-05** — `up()` idempotente por `Schema::hasTable`. **Sem `try/catch` em volta de
 *   DDL** — neste projeto um 1553 ficou engolido por 2 meses dentro de um catch.
 * - Os índices são declarados **ANTES** das FKs (o MariaDB usa como apoio o índice que a
 *   coluna lidera).
 * - `down()`: as duas tabelas são irmãs, sem FK entre si, então a ordem entre elas é
 *   indiferente — mas as duas saem ANTES de qualquer coisa que a `100000` derrube, o que o
 *   batch do Laravel já garante. Sendo uma migration que só CRIA, o `dropIfExists` da tabela
 *   inteira subsume o par `dropForeign(['coluna'])`/`dropIndex`, que é a receita de migration
 *   **ADITIVA** (`2026_10_04_120000_...`), não de `create`. **Não há `dropIndex` aqui, e é por
 *   isso que não há `dropForeign` tampouco.**
 * - Prova no MariaDB local com `--path` só desta migration: o SQLite dos testes não pega
 *   1059 / 1830 / 1553.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_aprovacao_fidelidade')) {
            Schema::create('pub_aprovacao_fidelidade', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('aprovacao_imagem_id');
                $t->string('criterio', 16);
                $t->string('resultado', 8);
                $t->unsignedBigInteger('quem_id')->nullable();
                $t->dateTime('quando')->nullable();
                $t->timestamps();

                $t->unique(['aprovacao_imagem_id', 'criterio'], 'pubapfd_crit_uq');
                $t->index('quem_id', 'pubapfd_quem_ix');

                $t->foreign('aprovacao_imagem_id', 'pubapfd_imagem_fk')->references('id')->on('pub_aprovacao_imagens')->cascadeOnDelete();
                $t->foreign('quem_id', 'pubapfd_quem_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_aprovacao_defeitos')) {
            Schema::create('pub_aprovacao_defeitos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('aprovacao_imagem_id');
                $t->string('defeito', 16);
                $t->unsignedBigInteger('quem_id')->nullable();
                $t->dateTime('quando')->nullable();
                $t->timestamps();

                $t->unique(['aprovacao_imagem_id', 'defeito'], 'pubapdf_def_uq');
                $t->index('quem_id', 'pubapdf_quem_ix');

                $t->foreign('aprovacao_imagem_id', 'pubapdf_imagem_fk')->references('id')->on('pub_aprovacao_imagens')->cascadeOnDelete();
                $t->foreign('quem_id', 'pubapdf_quem_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /** Irmãs sem FK entre si; as duas saem antes do que a `100000` derruba (o batch garante). */
    public function down(): void
    {
        Schema::dropIfExists('pub_aprovacao_defeitos');
        Schema::dropIfExists('pub_aprovacao_fidelidade');
    }
};
