<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15/10/2026 — Fase 186, o portão no servidor: **O FINANCEIRO AUDITÁVEL DO PORTÃO**.
 * Duas tabelas: `pub_aprovacao_fretes` (o frete do vendedor informado à mão na revisão) e
 * `pub_aprovacao_composicoes` (a composição CONGELADA no momento do ato).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2 / MIG-07), escrita ANTES de o código existir.
 * Só CRIA duas tabelas; nenhuma tabela existente com dado muda, nenhuma linha é apagada e nenhuma
 * coluna é renomeada (MIG-09). Sem `enum()` (o CHECK é enforçado no SQLite dos testes e quebraria
 * a suíte — MIG-04), sem `->change()`, sem backfill.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 1. ⚠️ FIN-07 — HÁ FONTE DE FRETE, E POR ISSO A LINHA **NÃO** NASCE "Pendente"
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Antes de declarar qualquer coisa "Pendente", a fonte foi MEDIDA (14/10/2026). Existe, e tem
 * nome: `DadosEfetivosService::fretesDoProduto()` devolve
 * `por_tipo[listing_type] = {valor, origem, oferta_id}`, e a `origem` já vem **em palavras** de
 * `App\Support\Publicador\ConferenciaDeFrete::ORIGENS` (`:28-31`, conferido):
 *
 *     digitado ... 'digitado no Portal'                conta ...... 'cotado na conta pelo Portal'
 *     tabela ..... 'estimado pela tabela'              outro_tipo . 'o mesmo do outro tipo de anúncio'
 *
 * `valor = null` é o ÚNICO caso em que não há fonte ("a Precificação está sem frete"). Há ainda
 * `FreteParaAPrecificacaoService`, `FreteDoPublicadoService` e
 * `App\Services\Portal\Estrutura\Produtos\TabelaFreteEcf` — todos conferidos no disco.
 *
 * ➡️ **O mapa origem → estado da linha** (DADO-13 / VOC-04), e por quê:
 *
 * | origem viva   | estado da linha | por quê                                                      |
 * |---------------|-----------------|--------------------------------------------------------------|
 * | `digitado`    | **Confirmado**  | alguém digitou no Portal: é número de gente, não estimativa   |
 * | `conta`       | **Confirmado**  | cotado na conta do ML: é o número que o ML respondeu          |
 * | `tabela`      | **Estimado**    | estimado pela tabela: aproximação declarada                   |
 * | `outro_tipo`  | **Estimado**    | emprestado do outro tipo de anúncio: aproximação declarada    |
 * | `valor = null`| **Pendente**    | **o único** caso sem fonte                                    |
 *
 * ⚠️ A `nota` **NOMEIA a fonte** — não é texto decorativo. Ela é montada a partir de
 * `ConferenciaDeFrete::ORIGENS`, que é **LIDO sem alteração** (`App\Support\Publicador` é
 * território que esta milestone não toca): repetir aquelas quatro frases aqui criaria duas
 * redações da mesma verdade, livres para divergir.
 *
 * ➡️ **`pub_aprovacao_fretes` é o frete INFORMADO À MÃO na revisão** (MOD-03), e ele **vence** o
 * da Precificação e nasce **Confirmado**. As três origens do modal "Informar frete do vendedor"
 * (medidas no protótipo, `design_handoff_publicador/Aprovacao.dc.html:1401`) são
 * `Cotação da transportadora` · `Tabela do cliente` · `Simulador de frete`.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 2. `pub_aprovacao_fretes` — HISTÓRICO, NUNCA SOBRESCRITA (DADO-12 / T-186-21)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * | coluna        | tipo                          | regra / por quê                                                                 |
 * |---------------|-------------------------------|---------------------------------------------------------------------------------|
 * | id            | bigint unsigned PK            |                                                                                 |
 * | oferta_id     | unsignedBigInteger            | FK `pubapfr_oferta_fk` -> pub_aprovacao_ofertas, **CASCADE**: oferta apagada leva o frete dela |
 * | valor         | decimal(12,2)                 | dinheiro. 12,2 = até R$ 9.999.999.999,99 — frete não chega perto, e a folga é de graça |
 * | origem        | string(28)                    | `cotacao_transportadora` \| `tabela_cliente` \| `simulador`. **Sem `enum`** (MIG-04). O maior é `cotacao_transportadora` = 22; 28 dá folga para uma origem futura sem ALTER |
 * | nota          | string(200)                   | a nota de fonte LEGÍVEL, **com a data** ("Tabela do cliente · 15/10/2026 10:00"). ⚠️ **NOMEIA a fonte** (DADO-13/VOC-04) |
 * | informado_por | unsignedBigInteger NULL       | FK `pubapfr_autor_fk` -> users, **SET NULL**: usuário apagado não apaga a prova do que valia |
 * | informado_em  | dateTime                      | `dateTime`, nunca `timestamp()` (convenção do módulo, e `timestamp` tem o teto de 2038) |
 * | versao        | unsignedInteger               | a versão da oferta **DEPOIS** do incremento — ver §4                             |
 * | vigente       | boolean default true          | **UMA só vigente por oferta**; informar de novo marca a anterior `false`         |
 * | timestamps    |                               |                                                                                 |
 *
 * ⚠️⚠️ **POR QUE HISTÓRICO E NÃO UMA COLUNA NA OFERTA:** **aprovar JÁ PUBLICA e não há desfazer**
 * (decisão do usuário, 14/10/2026). Saber **qual** frete valia quando a oferta foi aprovada é a
 * única forma de auditar depois — e com sobrescrita aquela informação estaria destruída
 * exatamente no caso em que ela importa. Informar frete de novo **cria linha nova** e marca a
 * anterior `vigente = false`.
 *
 * ⚠️ `vigente` é **boolean + regra**, e não um unique parcial: MariaDB/MySQL não têm índice
 * parcial, e um unique em (`oferta_id`, `vigente`) proibiria DUAS linhas históricas da mesma
 * oferta (todas elas têm `vigente = false`). A regra mora em `PubAprovacaoFrete::informar()`,
 * dentro de uma transação, e tem teste.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 3. `pub_aprovacao_composicoes` — O CONGELAMENTO NO ATO (DADO-13 / T-186-22)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️⚠️ **A DECISÃO DE DADO-13:** a origem por linha é **DERIVADA da fonte viva** no serviço de
 * composição (plano **186-07**) — ela **NÃO** é persistida de forma contínua, porque custo e frete
 * mudam todo dia e uma cópia contínua ficaria velha e **mentiria**. O que é persistido é o
 * **congelamento no momento do ato** (aprovação ou exceção). Isso satisfaz *"persistida **ou**
 * derivada de forma auditável"* pelos **dois** lados, e é a única coisa que torna a
 * auditoria-depois de BLOQ-09 possível, já que **não existe desfazer** (PUB-09).
 *
 * | coluna               | tipo                     | regra / por quê                                                                 |
 * |----------------------|--------------------------|---------------------------------------------------------------------------------|
 * | id                   | bigint unsigned PK       |                                                                                 |
 * | oferta_id            | unsignedBigInteger       | FK `pubapcp_oferta_fk` -> pub_aprovacao_ofertas, **CASCADE**                      |
 * | versao               | unsignedInteger          | a versão da oferta no momento do ato — é o que amarra "o que foi aprovado"        |
 * | momento              | string(12)               | `aprovacao` \| `excecao`. **Sem `enum`**. O maior é `aprovacao` = 9; 12 de folga  |
 * | preco                | decimal(12,2)            | o preço vigente no ato (o **pior caso**, regra do plano 186-07)                   |
 * | linhas               | json                     | as **7** linhas, cada uma com **valor, origem e nota** — ver §3.1. ⚠️ **json SEM default** (MariaDB/MySQL não aceitam) |
 * | total                | decimal(12,2) NULL       | soma das 7. **NULL quando `incompleto`** — nunca zero                            |
 * | resultado            | decimal(12,2) NULL       | "Resultado estimado por venda" = `preco - total`. **NULL quando `incompleto`**    |
 * | margem               | **decimal(12,3)** NULL   | "Margem de contribuição estimada", em %. ⚠️ **NÃO `decimal(7,3)`** — ver §3.2    |
 * | incompleto           | boolean default false    | falta item que ANULA o resultado — ver §3.1                                      |
 * | estimado_por_baixo   | boolean default false    | resultado calculado **SEM** o custo de embalagem: o real é MENOR — ver §3.1       |
 * | margem_minima        | decimal(5,2)             | o mínimo que valia no ato (teto 999,99 — é percentual, e 12,00 é o de hoje)       |
 * | margem_minima_origem | string(24)               | `parametro_da_empresa` \| `config`. ⚠️ **de QUAL LADO do fallback** o mínimo veio |
 * | timestamps           |                          |                                                                                 |
 *
 * unique (`oferta_id`, `versao`, `momento`) = `pubapcp_versao_uq`: um congelamento por ato e por
 * versão. Sem ele, duas linhas da mesma aprovação poderiam contar histórias diferentes.
 *
 * ⚠️ **Não há `vigente` aqui, e é deliberado:** composição não tem "a atual" — a atual é
 * DERIVADA, viva, calculada pelo serviço do plano 186-07. Esta tabela guarda só os atos.
 *
 * ─── 3.1 AS 7 LINHAS, QUEM ANULA O RESULTADO, E A ÚNICA EXCEÇÃO ──────────────────────────────
 *
 * As 7 linhas (medidas no `fin()` do protótipo, `Aprovacao.dc.html:1061-1079`):
 * **custo do produto** · **comissão** · **tarifa fixa por venda** · **frete pago pelo vendedor** ·
 * **impostos** · **embalagem** · **outros custos**.
 *
 * ⚠️ `resultado`, `margem` e `total` são **nullable DE PROPÓSITO**: `incompleto = true` ⇒ os três
 * são `null`, **NUNCA zero** (FIN-05/D4). Zero é um número e a tela o mostraria como se fosse
 * verdade; `null` é a ausência, e a tela diz "Incompleto".
 *
 * ⚠️ **QUEM ANULA O RESULTADO são só CINCO:** custo, comissão, tarifa fixa, frete e imposto.
 *
 * ⚠️⚠️ **E A ÚNICA EXCEÇÃO DELIBERADA À REGRA — decisão do usuário de 14/10/2026:**
 * **"Embalagem" sem fonte nasce `Pendente` VISÍVEL e NÃO anula o resultado.** O resultado existe
 * e vem com **`estimado_por_baixo = true`** (o real é MENOR, porque falta um custo). E
 * **"Outros custos" nasce R$ 0,00 `Confirmado`** — é o que o protótipo faz
 * (`other: {v: 0, src: 'conf', note: 'Cadastro do produto'}`), porque "nenhum outro custo
 * informado" é uma AFIRMAÇÃO do cadastro, não uma lacuna.
 *
 * ⚠️ **A RAZÃO, e por que ela tem de estar escrita aqui:** sem esta exceção o painel diria
 * "Incompleto" em **100%** das ofertas — a embalagem raramente está cadastrada —, e a fase
 * inteira ficaria inerte. ⚠️ **Está escrito para ninguém "uniformizar" a regra depois.** A regra
 * completa (quem calcula, quando) é do plano **186-07**; aqui o schema **tem de ter a coluna**
 * para ela ser gravável.
 *
 * ─── 3.2 ⚠️⚠️ `margem` É `decimal(12,3)`, E ISSO É BUG CORRIGIDO, NÃO PRECIOSISMO ───────────
 *
 * `decimal(7,3)` comporta no máximo **±9999,999**, e **este projeto já mediu +20738%** numa
 * carteira (empresa com baseline ≈ R$ 0 — `project_gate_roll_delta_aceito_ressalvas` nos
 * learnings). Margem de contribuição em percentual estoura por muito menos: basta preço baixo com
 * custo quase nulo numa linha de teste.
 *
 * ⚠️ **O MODO DE FALHAR É ASSIMÉTRICO E SILENCIOSO:** no **MySQL 8.0.46** (produção) estourar
 * `decimal(p,s)` dá **erro 1264 (out of range)** e **derruba a gravação**; no **SQLite dos testes
 * grava CALADO**, truncado. A suíte passa verde e o deploy quebra — exatamente a classe de
 * armadilha que MIG-01 existe para pegar, e a razão de a prova de migration ser no MariaDB local
 * e os índices serem conferidos por `information_schema`, nunca por stdout.
 *
 * ➡️ `decimal(12,3)` = ±999.999.999,999: folga de **cinco ordens de grandeza** sobre o +20738%
 * já medido. A alternativa aceitável era **não guardar `margem`** e derivá-la de
 * `resultado / preco` na leitura; ela foi RECUSADA porque o ato congelado tem de poder ser lido
 * sem recalcular nada (é essa a diferença entre auditoria e recálculo). O que não se aceita é uma
 * coluna que estoura.
 *
 * ⚠️ `margem_minima` é `decimal(5,2)` (teto 999,99) e isso é suficiente **por ser um limite
 * configurado por gente** — ninguém configura mínimo de 1000%. A margem MEDIDA é que estoura, e é
 * ela que tem 12,3.
 *
 * ─── 3.3 ⚠️ O VOCABULÁRIO, E UM CAMPO HOMÔNIMO QUE **NÃO** SE EXPÕE ─────────────────────────
 *
 * Os dois nomes travados: **"Resultado estimado por venda"** (`resultado`) e **"Margem de
 * contribuição estimada"** (`margem`). ⚠️ **Nunca** o outro nome, aquele que promete descontar
 * despesa fixa e devolução — nada aqui as desconta, e usá-lo seria afirmação falsa (VOC-03).
 *
 * ⚠️⚠️ E a armadilha concreta: **existe um campo REAL com aquele nome** em
 * `EstruturaPrecificacaoParametros` (`:26`, `:30`, `:37`), legítimo, do Portal. Ele **não é
 * exposto por esta estrutura**, e o `daEmpresa()` daquele modelo **não deve ser repassado
 * inteiro** — ele devolve `array_map('floatval', ...)` de **todas** as `PADROES`, e repassar o
 * array completo vazaria o campo homônimo para dentro de `linhas`. ➡️ **Selecionar as chaves**
 * (`comissao_classico`, `comissao_premium`, `imposto`, `margem_contribuicao`), nunca o array.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 4. A VERSÃO: QUEM INCREMENTA, E ONDE ESTE SCHEMA ENTRA
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `pub_aprovacao_fretes.versao` guarda a versão da oferta **DEPOIS** do incremento, porque
 * confirmar o frete **É uma das 8 mudanças relevantes** e portanto **REABRE a aprovação**
 * (BLOQ-05). O protótipo faz exatamente isso (`submitFreight`, `:1204`: `x.version++` e o evento
 * "Confirmou o frete do vendedor: R$ … (origem) · versão N").
 *
 * ⚠️ `AndamentoDaOfertaService` **não** incrementa a versão (docblock dele, `:59-63`) — e **este
 * plano não encosta naquele serviço**. O incremento do caso do frete mora em
 * `PubAprovacaoFrete::informar()`, na MESMA transação que grava a linha nova e derruba a anterior.
 * ⚠️ **A rotina GERAL das 8 mudanças é do plano 186-08**, e quando ela chegar o caminho certo é
 * `informar()` **delegar** a ela, nunca uma segunda cópia da regra.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 5. NOMES DE ÍNDICE E DE FK, **MEDIDOS POR `strlen`** (MIG-02)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O MariaDB recusa nome acima de **64** (erro **1059**), o SQLite aceita, e a migration fica
 * **`Pending` com a tabela JÁ criada** — o pior estado possível
 * (`project_mariadb_nome_indice_64`). O plano 186-03 mediu o plano dele errando em 1 num número
 * destes, então aqui **nada é contado à mão**: os valores abaixo saíram de `strlen` em 15/10/2026,
 * e o guarda recalcula por `strlen` a cada rodada.
 *
 *   NOMEADOS À MÃO (padrão do módulo — nenhum `->constrained()`):
 *     pubapfr_oferta_ix ..... 17      pubapfr_oferta_fk ..... 17
 *     pubapfr_autor_ix ...... 16      pubapfr_autor_fk ...... 16
 *     pubapcp_versao_uq ..... 17      pubapcp_oferta_fk ..... 17
 *
 *   NOMES **PADRÃO DO LARAVEL** (`<tabela>_<coluna>_foreign`) — contados porque são os que
 *   ESTOURAM, e nenhum é usado (toda FK aqui é nomeada à mão):
 *     pub_aprovacao_fretes = 20:
 *       pub_aprovacao_fretes_oferta_id_foreign ........... 38
 *       pub_aprovacao_fretes_informado_por_foreign ....... 42
 *     pub_aprovacao_composicoes = 25:
 *       pub_aprovacao_composicoes_oferta_id_foreign ...... 43   <- o maior; passa
 *
 * ⚠️ **`pubapcp_versao_uq` lidera por `oferta_id`, e é ele que apoia a FK** — por isso
 * `pub_aprovacao_composicoes` **não tem índice avulso de `oferta_id`**: seria um segundo índice
 * com o mesmo prefixo, custo de escrita de graça. Em `pub_aprovacao_fretes` o
 * `pubapfr_oferta_ix` é (`oferta_id`, `vigente`) justamente para apoiar a FK **e** servir a
 * consulta real ("o frete vigente desta oferta") com um índice só.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 6. A DISCIPLINA RESTANTE (MIG-01..09), ITEM A ITEM
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * - **MIG-01** — **produção é MySQL 8.0.46, o local é MariaDB 10.4.32, os testes são SQLite**, e o
 *   SQLite é o que MENOS pega. Por isso a prova de `migrate → rollback → migrate` é no MariaDB
 *   local, com `--path` só desta migration, e os índices/FKs são conferidos por
 *   `information_schema` — nunca por stdout.
 * - **MIG-03** — toda FK `nullOnDelete()` tem `->nullable()` **EXPLÍCITO** (erro **1830** do
 *   MariaDB, invisível no SQLite): aqui é só `informado_por`. As duas `oferta_id` são CASCADE e
 *   NOT NULL de propósito — frete ou composição sem oferta não é nada.
 * - **MIG-04** — sem `enum()`: `origem`, `momento` e `margem_minima_origem` são `string` +
 *   constantes no modelo.
 * - **MIG-05** — `up()` idempotente por `Schema::hasTable`, **uma guarda por tabela**: se o deploy
 *   morrer entre as duas, a re-execução completa só o que faltou. **Sem `try/catch` em volta de
 *   DDL** — neste projeto um 1553 ficou engolido por 2 meses dentro de um catch, e um gate que
 *   CONTA a palavra `try` reprovaria por esta própria frase, então o guarda lê o CÓDIGO sem
 *   comentários, pelo tokenizador do PHP.
 * - **MIG-08** — os índices são declarados **ANTES** das FKs: o MariaDB usa como apoio o índice
 *   que a coluna lidera.
 * - **MIG-09** — nenhuma linha apagada, nenhuma coluna renomeada, nenhum backfill.
 * - `down()` derruba as **TABELAS** (`dropIfExists`), **filha antes de mãe**: as duas são filhas
 *   de `pub_aprovacao_ofertas` e de `users`, e **nenhuma é mãe da outra**, então a ordem entre
 *   elas é livre — mas **nenhuma das duas MÃES é derrubada aqui**. Não há `dropIndex` (o
 *   `dropIfExists` da tabela inteira subsume o par `dropForeign`/`dropIndex`, que é a receita de
 *   migration **ADITIVA**), e por isso não há `dropForeign` tampouco.
 *
 * ⚠️ **`pub_aprovacao_ofertas` TEM de existir antes desta migration** (as duas FKs
 * `*_oferta_fk` apontam para ela). Ela vem de
 * `2026_10_15_100200_create_pub_aprovacao_ofertas_table.php`, do plano 186-04, aplicada no local.
 * Se um dia uma destas FKs falhar por tabela ausente, a resposta **não é tirar a FK**: é aplicar
 * a migration da oferta primeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_aprovacao_fretes')) {
            Schema::create('pub_aprovacao_fretes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('oferta_id');
                $t->decimal('valor', 12, 2);
                $t->string('origem', 28);
                $t->string('nota', 200);
                $t->unsignedBigInteger('informado_por')->nullable();
                $t->dateTime('informado_em');
                $t->unsignedInteger('versao');
                $t->boolean('vigente')->default(true);
                $t->timestamps();

                // ⚠️ Índices ANTES das FKs (MIG-08).
                $t->index(['oferta_id', 'vigente'], 'pubapfr_oferta_ix');
                $t->index('informado_por', 'pubapfr_autor_ix');

                $t->foreign('oferta_id', 'pubapfr_oferta_fk')->references('id')->on('pub_aprovacao_ofertas')->cascadeOnDelete();
                $t->foreign('informado_por', 'pubapfr_autor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_aprovacao_composicoes')) {
            Schema::create('pub_aprovacao_composicoes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('oferta_id');
                $t->unsignedInteger('versao');
                $t->string('momento', 12);
                $t->decimal('preco', 12, 2);
                $t->json('linhas');
                $t->decimal('total', 12, 2)->nullable();
                $t->decimal('resultado', 12, 2)->nullable();
                // ⚠️ 12,3 e não 7,3: ±9999,999 estoura, e este projeto já mediu +20738% (§3.2).
                $t->decimal('margem', 12, 3)->nullable();
                $t->boolean('incompleto')->default(false);
                $t->boolean('estimado_por_baixo')->default(false);
                $t->decimal('margem_minima', 5, 2);
                $t->string('margem_minima_origem', 24);
                $t->timestamps();

                // ⚠️ Índice ANTES da FK (MIG-08). O unique lidera por `oferta_id` e apoia a FK.
                $t->unique(['oferta_id', 'versao', 'momento'], 'pubapcp_versao_uq');

                $t->foreign('oferta_id', 'pubapcp_oferta_fk')->references('id')->on('pub_aprovacao_ofertas')->cascadeOnDelete();
            });
        }
    }

    /**
     * Filha antes de mãe. As duas são filhas de `pub_aprovacao_ofertas` e de `users`, e nenhuma é
     * mãe da outra — a ordem entre elas é livre, e nenhuma das MÃES é derrubada aqui.
     */
    public function down(): void
    {
        Schema::dropIfExists('pub_aprovacao_composicoes');
        Schema::dropIfExists('pub_aprovacao_fretes');
    }
};
