<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15/10/2026 — Fase 186, o portão no servidor: **A OFERTA DO PORTÃO** (`pub_aprovacao_ofertas`).
 * Uma tabela: o andamento interno da aprovação, a versão, os campos de `aprovado_*` e o aviso de
 * preço alterado.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2 / MIG-07), escrita ANTES de o código existir.
 * Só CRIA uma tabela; nenhuma tabela existente com dado muda, nenhuma linha é apagada e nenhuma
 * coluna é renomeada (MIG-09). Sem `enum()` (o CHECK é enforçado no SQLite dos testes e quebraria
 * a suíte — MIG-04), sem `->change()`, sem backfill.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 1. A TENSÃO DE GRANULARIDADE, E A DECISÃO (critério 12 da fase)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * O desenho trabalha por **OFERTA** (Clássico e Premium têm andamento, preço, resultado e versão
 * SEPARADOS), enquanto a fila de publicação de hoje trabalha por **PRODUTO**. Decidir errado aqui
 * duplica o conceito de conferência em dois lugares, e os dois ficam livres para divergir.
 *
 * ⚠️ E tem peso de SEGURANÇA: o usuário decidiu em 14/10/2026 que **aprovar JÁ PUBLICA**, sem
 * segundo clique e **sem desfazer**. Toda a reversibilidade mora ANTES do clique — nos bloqueios e
 * na `versao` desta tabela. A `versao` é a única coisa entre "alguém mexeu na foto" e "foi ao ar
 * errado".
 *
 * A medição que decide, nas camadas que JÁ EXISTEM:
 *
 * | camada de hoje              | granularidade                                             | o que guarda |
 * |-----------------------------|-----------------------------------------------------------|--------------|
 * | `pub_produtos`              | **um produto** — e o Combo ×N é um `pub_produtos` PRÓPRIO (`produto_base_id`, `quantidade_kit >= 2`, `origem = publicador`, `oferta_id` NULL) | SKU, nome, âncoras de conta, fase/quantidade do kit |
 * | `pub_rascunhos`             | **um por produto** (unique `oferta_id`)                   | 6 estados **de PUBLICAÇÃO** (`DRAFT`, `VALIDATED`, `PUBLISHING`, `PUBLISHED`, `PARTIALLY_PUBLISHED`, `FAILED`) — **EIXO DIFERENTE** |
 * | **`pub_rascunho_alvos`**    | **rascunho × `listing_type_id`** (unique `pubal_tipo_uq`) | **É EXATAMENTE a projeção que o desenho chama de OFERTA** (`titulo` próprio, `ativo`, `posicao`) |
 * | `pub_fila_publicacao_itens` | **um PRODUTO** (Clássico + Premium, todas as cores, numa publicação só) | `validacao_id`, `plano_hash`, `revisao`, `ciente`; `precisa_revisar` é VALOR do `status` |
 * | `pub_publicacao_itens`      | **um `POST /items`** = uma oferta × variante               | `PENDING → SENT → CREATED\|FAILED\|UNKNOWN`, RN-93 |
 *
 * `EstruturaPublicacao::LISTING_TYPES` = Clássico → `gold_special`, Premium → `gold_pro`.
 *
 * ➡️ **A DECISÃO: a oferta do portão é TABELA NOVA, com UMA LINHA POR `pub_rascunho_alvos`**
 * (`alvo_id` UNIQUE, índice `pubapof_alvo_uq`).
 *
 * Ou seja: **não é uma terceira granularidade** — é uma **extensão 1:1 da projeção
 * `rascunho × listing_type_id` que JÁ EXISTE**. "Unidade × Combo ×N" não precisa de coluna: o kit
 * já é um `pub_produtos` próprio, com rascunho, alvos e fotos próprios.
 *
 * **Por que NÃO estender `pub_rascunho_alvos` direto:** aquela tabela é do **editor**, e os dois
 * eixos não se misturam (D2). Acrescentar 17 colunas de aprovação nela misturaria "o que vou
 * publicar" com "quem conferiu e em que versão", e o editor é território que esta milestone **não
 * toca** (TERR-02).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 2. COMO LIGA À FILA SEM DUPLICAR — SÃO 4 COLUNAS + 1 VALOR DE STATUS (DADO-03)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `fila_item_id` (FK nullable, `nullOnDelete`) aponta para o `pub_fila_publicacao_itens` que está
 * publicando o PRODUTO desta oferta. As 4 colunas de conferência **NÃO SÃO COPIADAS** — são LIDAS
 * pelo `fila_item_id`, e a semântica delas não muda:
 *
 *   `validacao_id` · `plano_hash` · `revisao` · `ciente`   →  ficam em `pub_fila_publicacao_itens`
 *
 * Um produto está em UMA fila por vez (o unique `pubfilai_produto_ativo_uq`), então as DUAS ofertas
 * do produto apontam para o MESMO item de fila — e isso é CORRETO: a fila publica o produto
 * inteiro, as duas ofertas e todas as cores numa publicação só.
 *
 * ⚠️⚠️ **`precisa_revisar` NÃO É COLUNA EM LUGAR NENHUM.** É um **VALOR** do `status`
 * `varchar(20)` de `pub_fila_publicacao_itens` (`2026_10_10_100000_create_pub_filas_publicacao_
 * tables.php:45`, constante `PubFilaPublicacaoItem::PRECISA_REVISAR`). **CONFERIDO NO SCHEMA VIVO**
 * por `information_schema` em 14/10/2026: aquela tabela tem `ciente`, `plano_hash`, `revisao`,
 * `status`, `validacao_id` — e **não** tem `precisa_revisar`.
 *
 * ⚠️ Por isso um gate que afirme `hasColumn(..., 'precisa_revisar') === false` é **VAZIO POR
 * CONSTRUÇÃO** (a coluna nunca existiu em tabela nenhuma, então a asserção é verdadeira por
 * ausência e não mede nada). **A prova que VALE** é que aquele `status` segue sendo a ÚNICA fonte
 * do valor, **com isca em `'ciente'`** — que É coluna de verdade e TEM de ser achada por
 * `hasColumn` na tabela da fila, senão a sonda está cega.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 3. ⚠️⚠️ POR QUE A OFERTA **NÃO TEM `conjunto_id`** (achado S2 / decisão do usuário de 14/10)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `ResolvedorGruposImagem::chaveDoGrupo()` (`app/Support/Publicador/Imagem/
 * ResolvedorGruposImagem.php:32-50`) devolve **um grupo por valor de eixo que define foto**
 * (`definesPicture`), mais o **`GENERAL`**. Logo: rascunho com 3 cores que definem foto = **4
 * conjuntos** em `pub_aprovacao_conjuntos` (3 de cor + o GERAL).
 *
 * ⚠️ **DECISÃO DO USUÁRIO, 14/10/2026: o bloqueio de fotos considera TODOS os grupos do
 * rascunho.** A oferta só é aprovável com as fotos de **todas** as variações **e** as **gerais**
 * conferidas. **Aprovação cor por cor foi oferecida e RECUSADA**, pela razão que amarra a
 * milestone: aprovar já publica e não há desfazer, então foto que ninguém olhou não pode ir ao ar.
 *
 * ➡️ A relação oferta→conjunto é **1:N**, e **`conjunto_id` NÃO EXISTE nesta tabela**. Com ela
 * (como estava na primeira redação do plano), as fotos dos outros grupos e as `GENERAL` ficariam
 * **FORA** do bloqueio `photos` e uma oferta poderia ser aprovada com foto não conferida.
 *
 * ➡️ Nem pivô N:N: ele teria SEMPRE o mesmo conteúdo do `join` por `rascunho_id` e poderia
 * divergir da verdade — e pivô que pode divergir é pior que nenhum.
 *
 * ➡️ **O bloqueio lê `PubAprovacaoConjunto::doRascunho($oferta->rascunho_id)`**, em DUAS consultas
 * que **não crescem** com o número de grupos (provado no plano 186-03).
 *
 * ⚠️ E é a ausência desta coluna que torna o **compartilhamento Clássico↔Premium ESTRUTURAL** em
 * vez de combinado: a aprovação da foto é gravada por CONJUNTO, o conjunto é por RASCUNHO, e o
 * rascunho tem os dois alvos — **não existe coluna onde as duas ofertas pudessem divergir**.
 * Enquanto havia `conjunto_id` aqui, aquela afirmação era **FALSA**: o unique da oferta é só em
 * `alvo_id`, nada forçaria as duas ofertas ao mesmo conjunto, e o teste do 186-03 passava por
 * **FIXTURE**. Por isso a ausência da coluna é **asserção de teste com isca**, e não comentário.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 4. `pub_aprovacao_ofertas` — A TABELA DE DECISÃO DE SCHEMA
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * | coluna             | tipo                            | regra / por quê                                                                  |
 * |--------------------|---------------------------------|----------------------------------------------------------------------------------|
 * | id                 | bigint unsigned PK              |                                                                                  |
 * | alvo_id            | unsignedBigInteger              | FK `pubapof_alvo_fk` -> pub_rascunho_alvos, **CASCADE** + **UNIQUE `pubapof_alvo_uq`**: é a extensão 1:1; alvo apagado leva o portão dele |
 * | rascunho_id        | unsignedBigInteger              | FK `pubapof_rascunho_fk` -> pub_rascunhos, CASCADE. Denormalizado DE PROPÓSITO: é por ele que o bloqueio de fotos alcança os N conjuntos em UMA consulta, sem `join` no alvo |
 * | produto_id         | unsignedBigInteger NULL         | FK `pubapof_produto_fk` -> pub_produtos, **SET NULL**: produto apagado não apaga o histórico de aprovação (mesma razão do CR-B02) |
 * | company_id         | unsignedBigInteger NULL         | âncora de conta, **SEM FK** — ver §5                                              |
 * | mlb_empresa_id     | unsignedBigInteger NULL         | âncora de conta, **SEM FK** — ver §5                                              |
 * | andamento          | string(24) default 'preparando' | os 8 estados internos, em pt-BR. **Sem `enum`** (MIG-04). O maior é `aguardando_aprovacao` = 20; 24 dá folga para um estado futuro sem ALTER |
 * | preparo_frase      | string(200) NULL                | AUTO-01: a frase REAL do que falta ("calculando frete e composição de preço"), não um rótulo genérico |
 * | origem             | string(10) default 'auto'       | AUTO-03: `auto` \| `manual` (maior = 6; 10 de folga). Sem `enum`                   |
 * | responsavel_id     | unsignedBigInteger NULL         | FK `pubapof_responsavel_fk` -> users, **SET NULL**: usuário apagado não apaga a oferta |
 * | entrou_na_fila_em  | dateTime NULL                   | **a base do tempo de espera** (FILA-13: o "há X", âmbar acima de 24 h). `dateTime`, nunca `timestamp()`, em data avulsa (convenção de `2026_10_01_200000`) |
 * | versao             | unsignedInteger default 1       | **BLOQ-05**: incrementa a cada mudança relevante e REABRE a aprovação            |
 * | aprovado_por       | unsignedBigInteger NULL         | FK `pubapof_aprovado_por_fk` -> users, SET NULL                                   |
 * | aprovado_em        | dateTime NULL                   | gravado junto com `aprovado_por` e `versao_aprovada`, na MESMA transação          |
 * | versao_aprovada    | unsignedInteger NULL            | **em QUE versão a aprovação foi dada**. NULL = nunca aprovada                     |
 * | tocado             | boolean default false           | o `touched` do protótipo: alimenta a escolha do "PRÓXIMO A REVISAR"               |
 * | preco_conferido_em | dateTime NULL                   | quando alguém conferiu o preço                                                    |
 * | preco_alterado     | json NULL                       | de → para, quem, quando — o bloqueio `change`. ⚠️ **json SEM default** (convenção do módulo; MariaDB/MySQL não aceitam default em json) |
 * | erro_publicacao    | string(1000) NULL               | **PUB-05: o erro REAL do ML**, não "algo deu errado". 1000 porque a mensagem do ML vem com `cause[]` concatenado |
 * | fila_item_id       | unsignedBigInteger NULL         | FK `pubapof_fila_item_fk` -> pub_fila_publicacao_itens, **SET NULL**: é por aqui que as 4 colunas de conferência são LIDAS em vez de copiadas (§2) |
 * | ml_item_id         | string(20) NULL                 | ⚠️ **AND-04: gravado SÓ após confirmação do ML**. `MLB` + 10 a 12 dígitos; 20 de folga |
 * | timestamps         |                                 |                                                                                  |
 *
 * ⚠️ **O PREÇO NÃO GANHA COLUNA AQUI**, e isso é decisão. O preço efetivo é LIDO
 * (`DadosEfetivosService` / `pub_variante_precos`); a tabela guarda só o **aviso** de alteração não
 * conferida (`preco_alterado`) e `preco_conferido_em`. Duplicar preço criaria uma **segunda
 * verdade**, e este projeto já pagou por isso (`hs_mrr = 0` zerou três contratos de R$ 3.000).
 *
 * ⚠️ `pub_variante_precos` é unique **(`variante_id`, `alvo_id`)** — CONFERIDO por
 * `information_schema` em 14/10/2026 (índice `pubvp_uq`, 2 colunas). Logo um alvo pode ter VÁRIOS
 * preços (um por variante) enquanto a composição produz UM resultado por oferta. **Qual preço
 * manda está decidido no plano 186-07: o PIOR CASO — o MENOR preço ativo do alvo** (decisão do
 * usuário de 14/10: se a variação mais barata dá prejuízo, a oferta TRAVA; a tela nomeia a
 * variação, "pior caso entre N variações"). Este docblock **aponta para lá** em vez de repetir a
 * regra, para não haver duas redações da mesma decisão.
 *
 * ⚠️ **NÃO HÁ COLUNA DE CONTADOR DE FOTO** (`fotos_aprovadas` / `fotos_no_conjunto`): são
 * DERIVADOS, contados do conjunto. Mesmo raciocínio já escrito em `2026_10_15_100000_...`:
 * contador denormalizado é a classe de bug que faz a tela dizer "3 de 4 aprovadas" com 5 fotos no
 * banco.
 *
 * ⚠️ **NÃO HÁ NADA DE "CONFIRMAR PUBLICAÇÃO", "DESFAZER", "SEGUNDO PASSO" OU "CANCELAR
 * APROVAÇÃO"** — nem coluna, nem estado, nem flag. **Aprovar JÁ PUBLICA** (decisão do usuário de
 * 14/10), e o rótulo "Aprovar para publicação" fica inalterado. O ponto de extensão da Fase 193 é
 * só o `aoAprovar()` do serviço.
 *
 * ⚠️ **NÃO HÁ NADA DE "EXCEÇÃO AGUARDANDO AUTORIZAÇÃO"** — aquele estado NÃO EXISTE nesta
 * milestone, por decisão do usuário de 14/10 (BLOQ-09: o controle é auditoria DEPOIS, não barreira
 * ANTES).
 *
 * ⚠️ **NENHUMA COLUNA DECIMAL AQUI, E ISSO NÃO É DESCUIDO.** A armadilha de precisão deste
 * projeto (estourar `decimal(p,s)` dá **erro 1264 no MySQL/MariaDB** e **grava CALADO, truncado,
 * no SQLite dos testes** — modo de falhar assimétrico, e já medimos **+20738%** por aqui) não tem
 * onde morder nesta tabela porque **não há percentual nem dinheiro gravado**: o preço é lido, e o
 * `preco_alterado` guarda o par de→para em **json**, sem casa decimal declarada. Se um dia alguém
 * acrescentar aqui um percentual, tem de voltar a este parágrafo e dar folga de uma ordem de
 * grandeza.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 5. DADO-14 — O RECORTE POR CONTA, E POR QUE AS DUAS ÂNCORAS SÃO **SEM FK**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `company_id` e `mlb_empresa_id` são as âncoras de conta, na convenção **já usada** por
 * `pub_tarefas` e `pub_filas_publicacao` (`2026_10_10_100000_...`: "âncora da conta (sem FK, como
 * `pub_tarefas`)").
 *
 * **Por que SEM FK:** a conta que publica é resolvida por `ProgramasPublicadorService::resolver`
 * como `MlbEmpresa` **antes** de `Company`, e as duas vivem em tabelas diferentes com ciclos de
 * vida diferentes — a âncora é uma COORDENADA de recorte, não uma posse. Uma FK CASCADE aqui
 * apagaria histórico de aprovação ao mexer no cadastro; uma FK SET NULL deixaria a linha sem
 * recorte e **invisível** na fila de todas as contas (vazamento ao contrário: a oferta
 * desapareceria). E `mlb_empresa_id` aponta para `MlbEmpresa`, que é a "empresa polo" — outra
 * entidade que não é `Company` (learnings: `painel-polos-status-e-meta`).
 *
 * ⚠️ **Diferença deliberada em relação a `2026_10_15_100000_...`**, que NÃO tem âncora de conta
 * nenhuma: lá o recorte vem por `rascunho_id -> pub_rascunhos.produto_id -> pub_produtos`, porque
 * aquelas três tabelas são lidas sempre A PARTIR de um rascunho já conhecido. Aqui a consulta
 * principal é a **FILA DA CONTA** ("todas as ofertas aguardando aprovação desta empresa"), que
 * começa SEM rascunho; fazê-la por dois `join` até `pub_produtos` em cada abertura de tela é o que
 * o índice `pubapof_conta_andamento_ix` existe para evitar. O risco de divergência (o produto troca
 * de âncora e a linha do portão fica com a antiga) é real e é tratado onde se trata: quem filtra
 * confere, e a oferta é recriada por alvo.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 6. NOMES DE ÍNDICE E DE FK, COM OS CARACTERES **MEDIDOS POR `strlen`** (MIG-02)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O MariaDB recusa nome acima de **64** (erro **1059**), o SQLite aceita, e a migration fica
 * **`Pending` com a tabela JÁ criada** — o pior estado possível
 * (`project_mariadb_nome_indice_64` nos learnings). O 186-03 mediu o plano dele errando em 1 num
 * destes números, então aqui **nada é contado à mão**: os valores abaixo saíram de `strlen` em
 * 14/10/2026, e o guarda recalcula por `strlen` a cada rodada.
 *
 *   NOMEADOS À MÃO (padrão do módulo — nenhum `->constrained()`):
 *     pubapof_alvo_uq ............... 15      pubapof_alvo_fk .............. 15
 *     pubapof_rascunho_ix ........... 19      pubapof_rascunho_fk .......... 19
 *     pubapof_produto_ix ............ 18      pubapof_produto_fk ........... 18
 *     pubapof_responsavel_ix ........ 22      pubapof_responsavel_fk ....... 22
 *     pubapof_fila_item_ix .......... 20      pubapof_aprovado_por_fk ...... 23
 *     pubapof_conta_andamento_ix .... 26      pubapof_fila_item_fk ......... 20
 *     pubapof_mlb_ix ................ 14
 *
 *   NOMES **PADRÃO DO LARAVEL** (`<tabela>_<coluna>_foreign`) — contados porque são os que
 *   ESTOURAM, e nenhum é usado (toda FK aqui é nomeada à mão). `pub_aprovacao_ofertas` = 21:
 *     pub_aprovacao_ofertas_alvo_id_foreign .......... 37
 *     pub_aprovacao_ofertas_rascunho_id_foreign ...... 41
 *     pub_aprovacao_ofertas_produto_id_foreign ....... 40
 *     pub_aprovacao_ofertas_responsavel_id_foreign ... 44   ← o maior; passa
 *     pub_aprovacao_ofertas_aprovado_por_foreign ..... 42
 *     pub_aprovacao_ofertas_fila_item_id_foreign ..... 42
 *
 * ⚠️ **`aprovado_por` é a única FK SEM índice declarado à mão, e é deliberado:** ela nunca é
 * filtro de consulta (a tela EXIBE quem aprovou, não busca por isso), e o MariaDB cria sozinho o
 * índice de apoio da FK com o nome da própria constraint (`pubapof_aprovado_por_fk`, **23**, já
 * contado acima). `responsavel_id` TEM índice próprio porque a fila agrupa e filtra por ele
 * ("quem está revisando").
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 7. A DISCIPLINA RESTANTE (MIG-01..09), ITEM A ITEM
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * - **MIG-01** — **produção é MySQL 8.0.46, o local é MariaDB 10.4.32, os testes são SQLite**, e o
 *   SQLite é o que MENOS pega. Por isso a prova de `migrate → rollback → migrate` é no MariaDB
 *   local, com `--path` só desta migration, e os índices/FKs são conferidos por
 *   `information_schema` — nunca por stdout.
 * - **MIG-03** — toda FK `nullOnDelete()` tem `->nullable()` **EXPLÍCITO** (erro **1830** do
 *   MariaDB, invisível no SQLite): vale para `produto_id`, `responsavel_id`, `aprovado_por` e
 *   `fila_item_id`. As duas CASCADE (`alvo_id`, `rascunho_id`) são NOT NULL de propósito — oferta
 *   sem alvo não é oferta.
 * - **MIG-04** — sem `enum()`: `andamento` e `origem` são `string` + constantes no modelo.
 * - **MIG-05** — `up()` idempotente por `Schema::hasTable`: se o deploy morrer no meio, a
 *   re-execução completa só o que faltou. **Sem `try/catch` em volta de DDL** — neste projeto um
 *   1553 ficou engolido por 2 meses dentro de um catch, e um gate que CONTA a palavra `try`
 *   reprovaria por esta própria frase, então o guarda lê o CÓDIGO sem comentários, pelo
 *   tokenizador do PHP.
 * - **MIG-08** — os índices são declarados **ANTES** das FKs: o MariaDB usa como apoio o índice
 *   que a coluna lidera.
 * - **MIG-09** — nenhuma linha apagada, nenhuma coluna renomeada, nenhum backfill.
 * - `down()` derruba a **TABELA** (`dropIfExists`), que subsume o par
 *   `dropForeign(['coluna'])`/`dropIndex` — aquela é a receita de migration **ADITIVA**
 *   (`2026_10_04_120000_...`), não de `create`. **Não há `dropIndex` aqui, e é por isso que não há
 *   `dropForeign` tampouco** (a sonda de ordem `dropForeign` → `dropIndex` rodaria sobre ZERO
 *   casos e seria gate vazio por construção; o invariante que VALE aqui tem gate próprio).
 *   Sendo UMA tabela só, não há ordem filha→mãe a respeitar — mas ela é a FILHA de
 *   `pub_rascunho_alvos`, `pub_rascunhos`, `pub_produtos`, `users` e
 *   `pub_fila_publicacao_itens`, e nenhuma delas é derrubada aqui.
 *
 * ⚠️ **`pub_fila_publicacao_itens` TEM de existir no banco antes desta migration** (a FK
 * `pubapof_fila_item_fk` aponta para ela). Ela vem de
 * `2026_10_10_100000_create_pub_filas_publicacao_tables.php`, e está aplicada no local (batch 39).
 * Se um dia esta FK falhar por tabela ausente, a resposta **não é tirar a FK**: é aplicar a
 * migration da fila primeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_aprovacao_ofertas')) {
            Schema::create('pub_aprovacao_ofertas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('alvo_id');
                $t->unsignedBigInteger('rascunho_id');
                $t->unsignedBigInteger('produto_id')->nullable();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('mlb_empresa_id')->nullable();
                $t->string('andamento', 24)->default('preparando');
                $t->string('preparo_frase', 200)->nullable();
                $t->string('origem', 10)->default('auto');
                $t->unsignedBigInteger('responsavel_id')->nullable();
                $t->dateTime('entrou_na_fila_em')->nullable();
                $t->unsignedInteger('versao')->default(1);
                $t->unsignedBigInteger('aprovado_por')->nullable();
                $t->dateTime('aprovado_em')->nullable();
                $t->unsignedInteger('versao_aprovada')->nullable();
                $t->boolean('tocado')->default(false);
                $t->dateTime('preco_conferido_em')->nullable();
                $t->json('preco_alterado')->nullable();
                $t->string('erro_publicacao', 1000)->nullable();
                $t->unsignedBigInteger('fila_item_id')->nullable();
                $t->string('ml_item_id', 20)->nullable();
                $t->timestamps();

                // ⚠️ Índices ANTES das FKs (MIG-08).
                $t->unique('alvo_id', 'pubapof_alvo_uq');
                $t->index('rascunho_id', 'pubapof_rascunho_ix');
                $t->index('produto_id', 'pubapof_produto_ix');
                $t->index('responsavel_id', 'pubapof_responsavel_ix');
                $t->index('fila_item_id', 'pubapof_fila_item_ix');
                $t->index(['company_id', 'andamento'], 'pubapof_conta_andamento_ix');
                $t->index('ml_item_id', 'pubapof_mlb_ix');

                $t->foreign('alvo_id', 'pubapof_alvo_fk')->references('id')->on('pub_rascunho_alvos')->cascadeOnDelete();
                $t->foreign('rascunho_id', 'pubapof_rascunho_fk')->references('id')->on('pub_rascunhos')->cascadeOnDelete();
                $t->foreign('produto_id', 'pubapof_produto_fk')->references('id')->on('pub_produtos')->nullOnDelete();
                $t->foreign('responsavel_id', 'pubapof_responsavel_fk')->references('id')->on('users')->nullOnDelete();
                $t->foreign('aprovado_por', 'pubapof_aprovado_por_fk')->references('id')->on('users')->nullOnDelete();
                $t->foreign('fila_item_id', 'pubapof_fila_item_fk')->references('id')->on('pub_fila_publicacao_itens')->nullOnDelete();
            });
        }
    }

    /** Uma tabela só; ela é FILHA de cinco e mãe de nenhuma (as filhas vêm nos planos 186-05/06). */
    public function down(): void
    {
        Schema::dropIfExists('pub_aprovacao_ofertas');
    }
};
