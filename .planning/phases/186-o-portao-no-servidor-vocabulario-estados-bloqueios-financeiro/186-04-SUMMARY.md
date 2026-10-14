---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 04
subsystem: publicador
tags: [migration, schema, aprovacao, oferta, andamento, transicoes, versao, tdd, mutacao]
requires:
  - "pub_rascunho_alvos, pub_rascunhos, pub_produtos, users (Fase 164, 2026_10_01_200000)"
  - "pub_fila_publicacao_itens (2026_10_10_100000_create_pub_filas_publicacao_tables, batch 39 no local)"
  - "pub_aprovacao_conjuntos (plano 186-03) — o bloqueio de fotos le por rascunho_id"
  - "App\\Support\\Publicador\\RegraViolada (USADA, nunca alterada — TERR-03)"
  - "tests/gates/verificar-testes.sh (plano 186-01)"
  - "resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx (plano 186-02, SOMENTE LEITURA)"
provides:
  - "pub_aprovacao_ofertas — UMA LINHA POR pub_rascunho_alvos (alvo_id UNIQUE): a granularidade do critério 12, RESOLVIDA"
  - "PubAprovacaoOferta — os 8 estados de andamento interno, TRANSICOES (lista fechada), LIVE, ORDEM, CTA"
  - "versao / versao_aprovada / aprovacaoReaberta() — a prova do que foi aprovado (BLOQ-05)"
  - "AndamentoDaOfertaService — a ÚNICA porta de transição, com RegraViolada em transição ilegal"
  - "AndamentoDaOfertaService::filaDaConta() — o recorte por conta, que RECUSA consulta sem conta (DADO-14)"
  - "AndamentoDaOfertaService::ordenarFila() / faixaDeAndamento() — AND-08 e AND-09"
  - "Os pontos de extensão registrar() (186-05) e aoAprovar() (Fase 193)"
  - "tests/gates/contagens-186-04.txt — 33 ids, BIJETIVO com os 32 métodos, toda contagem MEDIDA"
affects:
  - "Plano 186-05: preenche registrar() e acrescenta eventos()/ajustes() ao modelo"
  - "Plano 186-06: acrescenta excecoes()/fretes() ao modelo"
  - "Plano 186-07: decide qual preço manda (pior caso, menor preço ativo do alvo) — o docblock APONTA para lá"
  - "Plano 186-08: a rotina das 8 mudanças relevantes que incrementa `versao` e REABRE a aprovação"
  - "Plano 186-09: o gate amplo de `grep` por escrita em `andamento` fora do serviço"
  - "Fase 193: liga aoAprovar() — aprovar JÁ PUBLICA"
  - "⚠️ Plano 186-03: o gate `sem-par-oferta-imagem` reprova POR CONTAGEM (5 -> 6) — PERGUNTA DEVOLVIDA, manifesto alheio NÃO ajustado"
tech-stack:
  added: []
  patterns:
    - "Tabela de decisão de schema no docblock ANTES do código (MIG-07)"
    - "Nome de índice/FK nomeado à mão, com os caracteres MEDIDOS por strlen (MIG-02)"
    - "Lista FECHADA de transições: numa máquina de estado cujo passo errado publica, o default é recusa"
    - "Sonda de proibição lê o CÓDIGO sem comentários, pelo tokenizador do PHP"
    - "Toda sonda tem ISCA — um controle que ela TEM de acusar"
    - "Gate de diff SEMPRE com RANGE (<partida>..HEAD) e com isca num arquivo que o plano MUDOU"
    - "$attributes do modelo espelha o default da COLUNA, com gate lendo o schema vivo"
key-files:
  created:
    - database/migrations/2026_10_15_100200_create_pub_aprovacao_ofertas_table.php
    - app/Models/PubAprovacaoOferta.php
    - app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
    - tests/Unit/Publicador/Aprovacao/AndamentoDaOfertaServiceTest.php
    - tests/Feature/Publicador/Aprovacao/OfertaDoPortaoTest.php
    - tests/gates/contagens-186-04.txt
  modified: []
decisions:
  - "A oferta do portão é TABELA NOVA com uma linha por pub_rascunho_alvos — extensão 1:1 de projeção que JÁ EXISTE, não uma terceira granularidade"
  - "conjunto_id NÃO entra: a relação é 1:N e o bloqueio de fotos vê TODOS os grupos do rascunho (achado S2 / decisão do usuário de 14/10)"
  - "São 4 COLUNAS + 1 VALOR DE STATUS: precisa_revisar nunca foi coluna, e a negativa dele seria vazia por construção"
  - "O preço não ganha coluna aqui — seria uma segunda verdade; só o aviso (preco_alterado) e preco_conferido_em"
  - "As chaves de dado são pt-BR nos dois lados, e os rótulos são espelhados BYTE A BYTE do .jsx por teste que LÊ o arquivo"
  - "Desconhecido devolve null no PHP e NUNCA null no .jsx — não é divergência de vocabulário, é diferença de responsabilidade"
  - "company_id / mlb_empresa_id SEM FK: a âncora é coordenada de recorte, não posse (convenção de pub_tarefas / pub_filas_publicacao)"
metrics:
  duration: "~3h"
  completed: 2026-10-14
  tasks: 3
  commits: 4
  arquivos: 6
  testes_novos: 32
  mutacoes: "10 aplicadas, 10 MORRERAM, 0 sobreviventes"
---

# Fase 186 Plano 04: A Oferta do Portão Summary

A oferta do portão existe como dado com `alvo_id` UNIQUE — uma linha por `pub_rascunho_alvos`, com os 8 estados de andamento interno em pt-BR espelhados byte a byte do módulo do 186-02, 12 transições em lista fechada, a `versao`/`versao_aprovada` que permite reabrir uma aprovação, e um serviço que é a única porta de transição.

## Commits

| # | Hash | O quê |
|---|------|-------|
| 1 | `0eaed5a9` | `feat` — a migration, com a decisão de granularidade e a tabela de decisão de schema no docblock |
| 2 | `587d772c` | `test` — RED dos 8 estados (10 métodos vermelhos com `Class ... not found`, o 11º verde porque não depende do modelo) |
| 3 | `637182df` | `feat` — `PubAprovacaoOferta` + o manifesto com os 10 primeiros ids MEDIDOS |
| 4 | `9d499a20` | `feat` — `AndamentoDaOfertaService`, os dois testes, o conserto do default em memória e o manifesto completo |

Commit de partida: **`3b790d21`**. `git diff 3b790d21..HEAD --name-only` devolve **exatamente os 6 arquivos** do plano, com **0 deleções** em todos (`git diff --numstat`: nenhuma linha com segundo campo diferente de 0).

---

## 1. A DECISÃO DE GRANULARIDADE (critério 12 da fase), transcrita

**A oferta do portão é TABELA NOVA, com UMA LINHA POR `pub_rascunho_alvos` (`alvo_id` UNIQUE, índice `pubapof_alvo_uq`).**

Ou seja: **não é uma terceira granularidade** — é uma **extensão 1:1 da projeção `rascunho × listing_type_id` que JÁ EXISTE**. A medição das camadas de hoje, que é o que decide:

| camada de hoje | granularidade | o que guarda |
|---|---|---|
| `pub_produtos` | **um produto** — e o Combo ×N é um `pub_produtos` PRÓPRIO (`produto_base_id`, `quantidade_kit >= 2`, `origem = publicador`, `oferta_id` NULL) | SKU, nome, âncoras de conta, fase/quantidade do kit |
| `pub_rascunhos` | **um por produto** (unique `oferta_id`) | 6 estados **de PUBLICAÇÃO** — eixo diferente |
| **`pub_rascunho_alvos`** | **rascunho × `listing_type_id`** (unique `pubal_tipo_uq`) | **É EXATAMENTE a projeção que o desenho chama de OFERTA** |
| `pub_fila_publicacao_itens` | **um PRODUTO** (Clássico + Premium, todas as cores, numa publicação só) | `validacao_id`, `plano_hash`, `revisao`, `ciente`; `precisa_revisar` é VALOR do `status` |
| `pub_publicacao_itens` | **um `POST /items`** = oferta × variante | `PENDING → SENT → CREATED\|FAILED\|UNKNOWN` |

"Unidade × Combo ×N" não precisa de coluna: o kit já é um `pub_produtos` próprio, com rascunho, alvos e fotos próprios.

**Por que NÃO estender `pub_rascunho_alvos` direto:** aquela tabela é do **editor**, e os dois eixos não se misturam (D2). Acrescentar 17 colunas de aprovação nela misturaria "o que vou publicar" com "quem conferiu e em que versão", e o editor é território que esta milestone **não toca** (TERR-02).

Tudo isso está **registrado no docblock da migration** — é o lugar onde o critério 12 fica por escrito —, e o gate `guarda-sem-enum-sem-try` prova que o docblock vem **antes** do `Schema::create(` e contém a string `DECISÃO DE SCHEMA` e a frase `UMA LINHA POR \`pub_rascunho_alvos\``.

---

## 2. A TABELA DE DECISÃO DE SCHEMA

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `alvo_id` | unsignedBigInteger | FK `pubapof_alvo_fk` → `pub_rascunho_alvos`, **CASCADE** + **UNIQUE `pubapof_alvo_uq`**: é a extensão 1:1 |
| `rascunho_id` | unsignedBigInteger | FK `pubapof_rascunho_fk` → `pub_rascunhos`, CASCADE. Denormalizado DE PROPÓSITO: é por ele que o bloqueio de fotos alcança os N conjuntos em UMA consulta |
| `produto_id` | unsignedBigInteger NULL | FK `pubapof_produto_fk` → `pub_produtos`, **SET NULL**: produto apagado não apaga o histórico de aprovação |
| `company_id` | unsignedBigInteger NULL | âncora de conta, **SEM FK** (ver §5) |
| `mlb_empresa_id` | unsignedBigInteger NULL | âncora de conta, **SEM FK** |
| `andamento` | string(24) default `'preparando'` | os 8 estados, pt-BR. **Sem `enum`**. Maior = `aguardando_aprovacao` (20); 24 dá folga |
| `preparo_frase` | string(200) NULL | AUTO-01: a frase REAL do que falta, não um rótulo genérico |
| `origem` | string(10) default `'auto'` | AUTO-03: `auto` \| `manual` (maior = 6) |
| `responsavel_id` | unsignedBigInteger NULL | FK `pubapof_responsavel_fk` → `users`, SET NULL |
| `entrou_na_fila_em` | dateTime NULL | **a base do tempo de espera** (FILA-13). `dateTime`, nunca `timestamp()` |
| `versao` | unsignedInteger default 1 | BLOQ-05: incrementa a cada mudança relevante e REABRE a aprovação (rotina no 186-08) |
| `aprovado_por` | unsignedBigInteger NULL | FK `pubapof_aprovado_por_fk` → `users`, SET NULL |
| `aprovado_em` | dateTime NULL | gravado junto com os outros dois, na MESMA transação |
| `versao_aprovada` | unsignedInteger NULL | **em QUE versão a aprovação foi dada**. NULL = nunca aprovada |
| `tocado` | boolean default false | o `touched` do protótipo: alimenta o "PRÓXIMO A REVISAR" |
| `preco_conferido_em` | dateTime NULL | |
| `preco_alterado` | json NULL | de → para, quem, quando — o bloqueio `change`. **json SEM default** |
| `erro_publicacao` | string(1000) NULL | **PUB-05: o erro REAL do ML**; 1000 porque o ML concatena `cause[]` |
| `fila_item_id` | unsignedBigInteger NULL | FK `pubapof_fila_item_fk` → `pub_fila_publicacao_itens`, SET NULL — é por aqui que as 4 colunas são LIDAS |
| `ml_item_id` | string(20) NULL | **AND-04: gravado SÓ após confirmação do ML** |
| `timestamps` | | |

Conferido no schema vivo (`information_schema`, MariaDB 10.4.32): **23 colunas**, `alvo_id`/`rascunho_id` NOT NULL, as outras 4 de FK anuláveis, `preco_alterado` mapeada para `longtext` (como `pub_fila_publicacao_itens.resumo`).

---

## 3. NOMES DE ÍNDICE E DE FK, COM `strlen` MEDIDO

Nada foi contado à mão — o 186-03 mediu o plano dele errando em 1 num número destes, então aqui tudo saiu de `strlen` e o guarda **recalcula** por `strlen` a cada rodada.

**Nomeados à mão** (13 nomes; nenhum `->constrained()`):

| nome | chars | | nome | chars |
|---|---|---|---|---|
| `pubapof_alvo_uq` | 15 | | `pubapof_alvo_fk` | 15 |
| `pubapof_rascunho_ix` | 19 | | `pubapof_rascunho_fk` | 19 |
| `pubapof_produto_ix` | 18 | | `pubapof_produto_fk` | 18 |
| `pubapof_responsavel_ix` | 22 | | `pubapof_responsavel_fk` | 22 |
| `pubapof_fila_item_ix` | 20 | | `pubapof_aprovado_por_fk` | 23 |
| `pubapof_conta_andamento_ix` | **26** ← o maior | | `pubapof_fila_item_fk` | 20 |
| `pubapof_mlb_ix` | 14 | | | |

**Nomes PADRÃO do Laravel** (`<tabela>_<coluna>_foreign`) — contados porque são os que ESTOURAM, e **nenhum é usado**. `pub_aprovacao_ofertas` = **21**:

| nome padrão | chars |
|---|---|
| `pub_aprovacao_ofertas_alvo_id_foreign` | 37 |
| `pub_aprovacao_ofertas_rascunho_id_foreign` | 41 |
| `pub_aprovacao_ofertas_produto_id_foreign` | 40 |
| `pub_aprovacao_ofertas_responsavel_id_foreign` | **44** ← o maior; **o plano dizia 44, e MEDIDO é 44** |
| `pub_aprovacao_ofertas_aprovado_por_foreign` | 42 |
| `pub_aprovacao_ofertas_fila_item_id_foreign` | 42 |

⚠️ **`aprovado_por` é a única FK sem índice declarado à mão, e é deliberado:** ela nunca é filtro de consulta (a tela EXIBE quem aprovou). O MariaDB criou sozinho o índice de apoio com o nome da própria constraint — **conferido no `information_schema`: `pubapof_aprovado_por_fk`, 23 chars**. `responsavel_id` tem índice próprio porque a fila agrupa e filtra por ele.

O gate `guarda-nomes-64` **TRAVA os dois maiores** (26 e 44): mudar o schema sem reconferir este bloco reprova.

---

## 4. `migrate → rollback → migrate`, COM RECONSULTA À TABELA `migrations` ENTRE OS PASSOS

`tasklist | grep mysqld` antes de tudo: **`mysqld.exe` PID 11008** rodando. Toda invocação com `--path=` só da minha migration — nunca `artisan migrate` solto, que arrastaria as 9 pendentes de outras sessões.

| passo | comando | reconsulta ao banco |
|---|---|---|
| 0 | — | `max(batch) = 42`; `hasTable('pub_aprovacao_ofertas') = false` |
| 1 | `migrate --path=…100200…` → `DONE 359.39ms` | `max(batch) = 43`; **batch 43 contém 1 migration só** (a minha, id 354); `hasTable = true`; **9 índices / 6 FKs** lidos do `information_schema` |
| 2 | `migrate:rollback --step=1` → `DONE 14.85ms` | `max(batch) = 42`; linhas com `2026_10_15_100200` = **0**; `hasTable = false`; **as 5 tabelas do 186-03 INTACTAS** (`pub_aprovacao_conjuntos`, `_imagens`, `_imagem_alertas`, `_fidelidade`, `_defeitos`) |
| 3 | `migrate --path=…100200…` → `DONE 332.71ms` | `max(batch) = 43`; batch 43 com 1 migration; `hasTable = true`; 9 índices / 6 FKs |

⚠️ **A reconsulta do passo 1 é o que tornou o `rollback --step=1` seguro** com 9 pendentes de outras sessões no repositório: se o batch 43 tivesse mais de uma migration, o `--step=1` derrubaria trabalho alheio. Ele tinha **uma**.

Índices e FKs conferidos por `information_schema`, **nunca por stdout**:

```
pubapof_alvo_uq (15) UNIQUE alvo_id          pubapof_alvo_fk (15)         -> pub_rascunho_alvos        CASCADE
pubapof_conta_andamento_ix (26) company_id+andamento   pubapof_rascunho_fk (19)     -> pub_rascunhos            CASCADE
pubapof_rascunho_ix (19) / _produto_ix (18)  pubapof_produto_fk (18)      -> pub_produtos              SET NULL
pubapof_responsavel_ix (22) / _fila_item_ix (20)  pubapof_responsavel_fk (22)  -> users                    SET NULL
pubapof_mlb_ix (14) ml_item_id               pubapof_aprovado_por_fk (23) -> users                    SET NULL
pubapof_aprovado_por_fk (23, auto do MariaDB)     pubapof_fila_item_fk (20)    -> pub_fila_publicacao_itens SET NULL
```

**As 9 pendentes de outras sessões seguem pendentes** no fim (`migrate:status | grep -c Pending` = 9, igual ao início).

---

## 5. OS 8 ESTADOS, O MAPA PROTÓTIPO → NOSSO, E AS TRANSIÇÕES

### O mapa de conferência (as chaves de DADO são pt-BR nos dois lados)

| protótipo (`STM`/`ORD`/`ST`/`cta`) | nosso (chave de dado) | rótulo pt-BR (byte a byte do `.jsx`) | `ORDEM` | `cta` | `abreRevisao` |
|---|---|---|---|---|---|
| `preparing` | `preparando` | Preparando | 6 | Aguarde | false |
| `awaiting` | `aguardando_aprovacao` | Aguardando aprovação | **1** | Revisar | **true** |
| `ecf_review` | `em_revisao_ecf` | Em revisão pela ECF | **1** | Revisar | **true** |
| `changes` | `ajustes_solicitados` | Ajustes solicitados | 2 | Revisar | **true** |
| `approved` | `aprovado` | Aprovado | 3 | Ver | false |
| `publishing` | `publicando` | Publicando | 4 | Ver | false |
| `failed` | `falha` | Falha na publicação | **0** | Tentar de novo | false |
| `published` | `publicado` | Publicado | 5 | Ver | false |

⚠️ As chaves inglesas **não são chave de dado, prop, valor de URL nem texto de tela**, e o teste `chave-adversa` as inclui entre as 13 chaves adversas: `rotuloDe('awaiting')` TEM de devolver `null`. **Nenhum `||` aceita as duas formas** — isso é o bug dos dois idiomas com outra roupa.

⚠️ O rótulo não foi copiado à mão: o teste **LÊ `andamentoDoPortao.jsx`** e compara os 8 pares nas **duas direções** (estado só no PHP e estado só no JS), com isca de caminho e de regex. A mutação M2 (encurtar "Em revisão pela ECF" para "Em revisão") **morreu** nesse gate.

### As transições: 10 regras / **12 pares**, lista FECHADA

Aceitas: `preparando→aguardando_aprovacao` · `aguardando_aprovacao→em_revisao_ecf` · `aguardando_aprovacao→ajustes_solicitados` · `em_revisao_ecf→ajustes_solicitados` · `ajustes_solicitados→aguardando_aprovacao` · **qualquer `live`→`aprovado`** (3 pares) · `aprovado→publicando` · `publicando→publicado` · `publicando→falha` · **`falha→publicando`** ("Tentar de novo").

Recusadas, com asserção explícita: `preparando→aprovado` · **`aprovado→publicado`** (tem de passar por `publicando`, onde o `ml_item_id` chega) · `publicado→qualquer` (8 pares; `publicado` é terminal **por estrutura**, lista vazia, não por exceção).

A **CONTAGEM está travada em 12**: sem ela, acrescentar uma transição nova não moveria número nenhum. A mutação M1 (`aprovado → publicado`) **morreu**.

**Por que lista fechada e não laço de proibições:** aprovar JÁ PUBLICA e não há desfazer. Numa máquina de estado cujo passo errado publica anúncio não conferido, tudo que ninguém lembrou de proibir não pode ficar permitido (T-186-11).

---

## 6. OS DOIS EIXOS, NAS DUAS DIREÇÕES

O README é explícito: *"o andamento interno nunca aparece na lista de Publicadas, e o status do ML nunca aparece na fila"*. A asserção mede as duas metades:

- **Direção 1** — nenhum dos 6 estados de PUBLICAÇÃO de `pub_rascunhos` (`DRAFT`, `VALIDATED`, `PUBLISHING`, `PUBLISHED`, `PARTIALLY_PUBLISHED`, `FAILED`) nem dos 3 de UPLOAD de `pub_imagens` (`pending`, `uploaded`, `failed`) é andamento interno.
- **Direção 2** — nenhum dos 8 andamentos internos é estado de publicação nem de upload.
- **E a disjunção por contagem**, que é o que pega colisão nova: **8 + 6 + 3 = 17 literais distintos**, com isca que acusa repetição de verdade.

Medir uma direção só provaria metade do invariante. `publicando` (minúsculo, pt-BR) e `PUBLISHING` são de eixos diferentes, e isso está asseverado.

---

## 7. AS PROVAS DE AUSÊNCIA, CADA UMA COM ISCA

### `conjunto_id` NÃO existe (achado S2) — **medição real, não vazio por construção**

A tabela é criada **por este plano**, então `hasColumn('pub_aprovacao_ofertas','conjunto_id') === false` é medição de verdade. A isca é `hasColumn(…,'versao') === true`: se a sonda estivesse cega (tabela ausente, driver mudo), ela acharia que nada existe e o `=== false` passaria por engano.

Mais a varredura estrutural: **nenhuma** tabela `pub_aprovacao*` declara o par `(oferta_id, imagem_id)` — com **isca da varredura** (ela achou as **6** tabelas do portão, 5 do 186-03 + esta, e `assertGreaterThanOrEqual(6, …)`), porque laço que roda sobre zero tabelas passa sem medir nada.

**A razão, por escrito:** `ResolvedorGruposImagem::chaveDoGrupo()` dá um grupo por valor de eixo que define foto **mais o `GENERAL`** (3 cores = 4 grupos). Com `conjunto_id` único, as fotos dos outros grupos e as `GENERAL` ficariam **FORA** do bloqueio `photos` e a oferta seria aprovável com foto que ninguém olhou — e **aprovar já publica, sem desfazer**. Decisão do usuário de 14/10: **aprovação cor por cor foi oferecida e RECUSADA**.

⚠️ **E é a ausência desta coluna que torna o compartilhamento Clássico↔Premium ESTRUTURAL** (a afirmação que o plano proibia repetir sem prova): a aprovação é gravada por CONJUNTO, o conjunto é por RASCUNHO, o rascunho tem os dois alvos → **não existe coluna onde as duas ofertas pudessem divergir**. Com `conjunto_id` aqui, o unique da oferta é só em `alvo_id`, nada forçaria as duas ofertas ao mesmo conjunto, e o teste do 186-03 passava por **FIXTURE**. Os dois gates que sustentam a afirmação agora existem (`sem-conjunto-id` neste plano, `sem-par-oferta-imagem` no 186-03), e a mutação M4 (devolver `conjunto_id`) **morreu**.

### As **4 COLUNAS** da fila não foram copiadas (DADO-03) — com **duas** iscas

| coluna | está em `pub_aprovacao_ofertas`? | está em `pub_fila_publicacao_itens`? |
|---|---|---|
| `validacao_id` | **false** | **true** (isca) |
| `plano_hash` | **false** | **true** (isca) |
| `revisao` | **false** | **true** (isca) |
| `ciente` | **false** | **true** (isca) |
| `versao` (isca positiva) | **true** | — |
| `fila_item_id` (isca positiva) | **true** | — |

⚠️ A **segunda** isca é a que fecha o buraco: sem ela, "não está aqui" seria indistinguível de "não está em lugar nenhum", e a prova não seria de que a oferta **APONTA** — seria de que ninguém guarda. E o apontamento foi exercitado: as **duas** ofertas do produto leem o MESMO `fila_item_id` (unique `pubfilai_produto_ativo_uq` = 1 fila por produto), e `filaItem->revisao` devolve 7 e `filaItem->ciente` devolve `true`.

A mutação M5 (copiar `ciente` para a oferta) **morreu**.

### `precisa_revisar` segue sendo VALOR do `status` — com isca em `'ciente'`

⚠️ **A asserção que este plano NÃO faz** é `hasColumn(…,'precisa_revisar') === false`: aquela coluna **nunca existiu em tabela nenhuma**, então a negativa é verdadeira por ausência e **vazia por construção**. Conferido no **schema vivo** por `information_schema` em 14/10/2026 — `pub_fila_publicacao_itens` tem `ciente`, `plano_hash`, `revisao`, `status` (`varchar(20)`), `validacao_id`, e **não** tem `precisa_revisar`.

As três provas que VALEM:

1. o valor fica guardado no `status` da fila (`PubFilaPublicacaoItem::PRECISA_REVISAR === 'precisa_revisar'`, item gravado e reconsultado, `vivo() === false`, pertence a `SEM_PUBLICAR`);
2. **nenhuma** coluna do portão o guarda — medido sobre **TODAS** as colunas de `pub_aprovacao_ofertas` por `Schema::getColumnListing()`, não sobre lista escrita à mão (`assertNotEmpty` primeiro, senão o laço rodaria sobre nada);
3. o literal **não aparece no CÓDIGO** do modelo nem do serviço, lido pelo tokenizador.

**Iscas:** `hasColumn('pub_fila_publicacao_itens','ciente') === true` e `… 'status' === true` (coluna de verdade, que a sonda TEM de achar), mais a isca do docblock — o literal **tem** de estar no docblock do modelo, senão "não achei no código" seria verdade pelo motivo errado (o arquivo não falar do assunto).

### Nada de "desfazer" / "confirmar publicação"

Nenhum estado, coluna, método ou aviso de `desfazer`, `confirmarPublicacao`, `cancelarAprovacao`, `segundo_passo` ou `aguardando_autorizacao` (BLOQ-09) — lido do **CÓDIGO sem comentários**, porque o docblock EXPLICA a ausência e um gate que contasse a palavra no arquivo inteiro reprovaria pelo próprio conserto. Com isca do tokenizador nos dois sentidos, e com a prova de que o ponto de extensão **`aoAprovar()` FICA** (o gancho da Fase 193).

---

## 8. O QUE A OFERTA DELIBERADAMENTE NÃO TEM, E O MOTIVO DE CADA ITEM

| não tem | por quê |
|---|---|
| `conjunto_id` | relação 1:N; com FK única as fotos dos outros grupos e as GERAIS sairiam do bloqueio e a oferta seria aprovável com foto não conferida — e não há desfazer (achado S2) |
| pivô N:N oferta×conjunto | teria SEMPRE o mesmo conteúdo do `join` por `rascunho_id`, e pivô que pode divergir da verdade é pior que nenhum |
| `validacao_id`, `plano_hash`, `revisao`, `ciente` | vivem na fila e são LIDOS por `fila_item_id`; copiar criaria duas verdades sobre a MESMA conferência (T-186-15) |
| `precisa_revisar` | não é coluna em lugar nenhum — é VALOR do `status` da fila |
| o preço | é lido (`DadosEfetivosService` / `pub_variante_precos`); duplicar criaria uma segunda verdade (este projeto já pagou por isso com `hs_mrr = 0`) |
| contadores de foto (`fotos_aprovadas`/`fotos_no_conjunto`) | são DERIVADOS, contados do conjunto; contador denormalizado é o bug que faz a tela dizer "3 de 4" com 5 fotos no banco |
| "confirmar publicação" / "desfazer" / "segundo passo" / "cancelar aprovação" | **aprovar JÁ PUBLICA** (decisão do usuário de 14/10); o rótulo "Aprovar para publicação" fica inalterado, e o único ponto de extensão é `aoAprovar()` (Fase 193) |
| "exceção aguardando autorização" | aquele estado NÃO EXISTE nesta milestone (BLOQ-09: o controle é auditoria DEPOIS, não barreira ANTES) |
| `publicado_em` | seria uma segunda verdade ao lado da transição; o recorte "Publicado hoje" usa `updated_at`, que na oferta publicada é o instante da transição — a última escrita do serviço naquela linha |
| FK em `company_id`/`mlb_empresa_id` | a âncora é COORDENADA de recorte, não posse (§5 do docblock); CASCADE apagaria histórico ao mexer no cadastro e SET NULL deixaria a oferta **invisível** na fila de toda conta (vazamento ao contrário) |
| qualquer coluna decimal | não há percentual nem dinheiro gravado aqui, então a armadilha de precisão (**1264** no MySQL/MariaDB, **grava CALADO truncado no SQLite**) não tem onde morder — e o docblock diz que acrescentar percentual obriga a voltar ao parágrafo e dar folga de uma ordem de grandeza |
| relações `eventos()`, `ajustes()`, `excecoes()`, `fretes()` | as tabelas são dos planos **186-05** e **186-06**; declarar relação para tabela inexistente quebraria o teste sem provar nada. Está escrito no docblock para ninguém achar que faltou |

---

## 9. A ÚNICA PORTA, E AS DUAS RECUSAS

`AndamentoDaOfertaService::mover()` é o único lugar que escreve `andamento`. As duas recusas têm **código distinto** porque a tela precisa dizer coisas diferentes:

- `REGRA_TRANSICAO_ILEGAL` (`AND-02-TRANSICAO-ILEGAL`) — o par não está na lista fechada. **No-op completo:** a linha inteira é comparada antes e depois, **inclusive `updated_at`**, e a isca prova que a transição LEGAL do mesmo ponto de partida passa (senão um `mover()` que lançasse sempre passaria).
- `REGRA_SEM_MLB` (`AND-04-SEM-MLB-CONFIRMADO`) — `publicado` sem `ml_item_id` (T-186-12), com isca: COM o MLB a mesma transição passa. E o toast "Publicado e confirmado" **não sai deste serviço** (verificado no código sem comentários; nem a palavra `toast` existe nele).
- `REGRA_FILA_SEM_CONTA` (`DADO-14-FILA-SEM-CONTA`) — **acréscimo por Rule 2**: `filaDaConta()` sem nenhuma âncora devolveria tudo, que é leitura cruzando empresas (T-186-14) — o tipo de vazamento que o schema abençoaria, porque as duas colunas são anuláveis por construção.

**Uma `DB::transaction` só** (contada no código sem comentários: `substr_count(..., 'DB::transaction(') === 1`), com `registrar()` e `aoAprovar()` **dentro** dela. A razão é "não há desfazer": falhar no meio deixaria oferta aprovada sem prova do que foi aprovado (T-186-13).

**BLOQ-11 provado:** `falha` preserva `aprovado_por`, `aprovado_em` e `versao_aprovada`, e `aprovacaoReaberta()` continua `false`. `falha → publicando` zera `erro_publicacao` e **não mexe na `versao`** — incrementar ali reabriria uma aprovação em que ninguém mexeu. As mutações M8, M9 e M10 **morreram** nesses gates.

**AND-08:** `ordenarFila()` ordena por degrau e, dentro do degrau, **maior espera primeiro** (ancorado com `Carbon::setTestNow('2026-10-15 09:00:00')`). O teste prova também que **a espera não atravessa degraus** (a oferta com a maior espera de todas, em `preparando`, fica por último) e que `entrou_na_fila_em` nulo vai para o fim do próprio degrau — espera desconhecida não vira espera infinita.

**AND-09:** `faixaDeAndamento()` devolve **9** recortes, e o último é **"Publicado hoje"**, recorte DO DIA. Provado com a oferta publicada **ONTEM PREENCHIDA**: sem ela, "estado inteiro" e "recorte do dia" dariam o mesmo número e o gate seria vazio por construção. `todas` = 6, soma dos oito recortes de estado = 5, e é essa diferença que prova que o último não é o estado inteiro.

---

## 10. GATES — 33 ids, E A CONFERÊNCIA DE QUE **CADA TESTE TEM id**

`tests/gates/contagens-186-04.txt`: **33 ids**, todos com a contagem **MEDIDA** uma invocação por método em 14/10/2026, e com a resposta de *"o que precisaria estar errado no mundo para ele falhar?"* escrita em cada entrada.

⚠️ **A conferência que a wave 1 ensinou** (o sobrevivente da mutação do 186-03 era um teste que existia no arquivo e **não tinha id no manifesto**, e por isso **nunca rodava**):

```
ids individuais no manifesto : 32
métodos nos 2 arquivos       : 32
método SEM id no manifesto   : (vazio)
id no manifesto SEM método   : (vazio)
ids duplicados               : (vazio)
```

**Bijeção confirmada.** O 33º id é `oferta-do-portao` — o id que o `<verify>` da Task 3 declara —, e ele é um filtro por alternância que roda os **três** métodos que o `<done>` da tarefa nomeia (DADO-03 com isca, o diff por RANGE do D-11, e a única porta): **3 / 39 MEDIDO**. Os três **também** têm id próprio, para dizer QUAL caiu quando o composto reprova.

Os dois gates que o plano declara:

```
PASSOU: gate 'transicoes'         (1 passed, 27 assertions)
PASSOU: gate 'oferta-do-portao'   (3 passed, 39 assertions)
```

E **os 33 rodados um por um depois da campanha de mutação: 33 passaram, 0 reprovaram.**

O helper do 186-01 foi auto-testado antes de eu confiar nele: `--autoteste` → **14 casos, 0 divergência**. Nenhum `<automated>` deste plano chama `artisan test` ou `node --test` direto.

### As quatro sondas cegas da wave 1, e o que fiz com cada uma

| sonda cega | como este plano a evita |
|---|---|
| teste sem id no manifesto nunca roda | conferência de **bijeção por script**, acima |
| `try` no docblock reprova pelo próprio conserto | todas as sondas de PROIBIÇÃO leem o CÓDIGO pelo **tokenizador do PHP**, com isca que prova que o tokenizador **tira comentário e preserva código** — e com a isca inversa, de que as palavras **estão** no docblock |
| regex de FK que casa o prefixo errado | o regex de FK é **testado contra um caso que DEVE casar e um que NÃO deve**, e a sonda tem de achar as **6** FKs antes de parear |
| `dropForeign` antes de `dropIndex` roda sobre zero casos | a sonda fica **com isca** (num texto sintético ADITIVO ela tem de achar os dois e ver a ordem) e o invariante que VALE para `create` tem **asserção própria**: o `down()` derruba a TABELA, não derruba coluna, e não derruba nenhuma das 5 tabelas-MÃES (que EXISTEM, conferido) |

E a quinta, que o plano destacou: **gate de diff sem range é vazio por construção.** O TERR-07 usa `git diff 3b790d21..HEAD --name-only -- tests/js/estrutura-meus-anuncios.test.js` → **vazio**, com **ISCA DO DIFF**: o MESMO comando na migration deste plano tem de sair **NÃO vazio** (senão um `git` que falhasse em silêncio devolveria string vazia para tudo e o gate seria verde permanente). Mais a conferência por SEMÂNTICA de que as asserções do D-11 (`router.put/delete/patch`, `useForm`, `router.visit`) e do D-22 (`de 86`) seguem no arquivo — **o gate não foi estendido nem afrouxado**.

### Guarda PRÓPRIO da migration — e por que ele era obrigatório

`MigrationDoPortaoGuardaTest::MIGRATIONS` (plano 186-03) declara **só as duas migrations daquele plano**. A minha fica **FORA** do guarda dele; sem guarda próprio, a disciplina MIG-01..09 estaria "coberta" por um gate que **nunca a lê** — a definição de passar por omissão. Daí os 4 gates `guarda-*` neste plano.

---

## 11. A CAMPANHA DE MUTAÇÃO — 10 aplicadas, **10 MORRERAM, 0 SOBREVIVENTES**

Disciplina: ocorrência **TRAVADA ANTES** (o script aborta a campanha inteira se a agulha não aparecer exatamente 1 vez), **linha conferida** depois (arquivo:linha impresso), restauração verificada por **sha256** e árvore conferida limpa a cada passo.

| # | mutação | arquivo:linha | gate | veredicto |
|---|---|---|---|---|
| M1 | transição a mais: `aprovado → publicado` (pula `publicando`) | `PubAprovacaoOferta.php:153` | `transicoes` | **MORREU** |
| M2 | rótulo encurtado: "Em revisão pela ECF" → "Em revisão" | `PubAprovacaoOferta.php:123` | `oito-estados` | **MORREU** |
| M3 | empate de `ORDEM` "arrumado": `em_revisao_ecf` 1 → 2 | `PubAprovacaoOferta.php:177` | `ordem-da-fila` | **MORREU** |
| M4 | `conjunto_id` de volta na oferta (achado S2) | `…100200….php:275` | `sem-conjunto-id` | **MORREU** |
| M5 | `ciente` copiado da fila para a oferta (DADO-03) | `…100200….php:288` | `quatro-colunas-da-fila` | **MORREU** |
| M6 | nome de índice com 65 caracteres (erro 1059) | `…100200….php:302` | `guarda-nomes-64` | **MORREU** |
| M7 | `->nullable()` tirado de `fila_item_id` (erro 1830) | `…100200….php:292` | `guarda-nullable` | **MORREU** |
| M8 | "Publicado hoje" virou o estado inteiro (`whereDate` fora) | `AndamentoDaOfertaService.php:289` | `faixa-nove-recortes` | **MORREU** |
| M9 | recorte por conta tirado da fila (DADO-14) | `AndamentoDaOfertaService.php:193` | `recorte-por-conta` | **MORREU** |
| M10 | `falha` apaga a versão aprovada (BLOQ-11) | `AndamentoDaOfertaService.php:136` | `falha-preserva` | **MORREU** |

⚠️ **Divergência da 1ª rodada, registrada porque é a classe de erro que deixa lixo no disco:** o script crashou ao decodificar a saída do Collision (`UnicodeDecodeError`, cp1252 do Windows) **depois** de aplicar M3 e **antes** de restaurar, deixando `app/Models/PubAprovacaoOferta.php` **mutado no disco**. Restaurado na hora por `git checkout -- <o arquivo específico>` (conferido: `EM_REVISAO_ECF => 1,` de volta na linha 177, `git diff` vazio). O script foi corrigido em dois pontos: `encoding="utf-8", errors="replace"` na captura, e a restauração passou a acontecer em **`finally`** — nem exceção deixa mutação no disco. A campanha foi então rodada inteira do zero.

⚠️ **Segunda divergência:** a conferência de "árvore limpa" por `git status --short -- app database tests` abortou a campanha antes de começar, porque lista `?? tests/Feature/CompanyPortfolioAccessTest.php` — que é de **24 de julho, de outra sessão**. A conferência passou a ser `git diff --name-only HEAD` sobre **os meus 6 arquivos**: a árvore é compartilhada, e não se mexe no que não é meu.

---

## 12. BASELINES MEDIDAS NO FIM

| suíte | medida agora | baseline da wave 1 | delta |
|---|---|---|---|
| PHP (`tests/Unit/Publicador` + `tests/Feature/Publicador`) | **2163 / 16808 / 0** | 2130 / 16270 / 0 | +33 testes, +538 asserções |
| JS (`tests/js/**`) | **4777 / 4776 pass / 1 fail / 0 skipped** | 4777 / 4776 / 1 | **idêntica** |

**Os +33 testes, contados um por um:** 32 métodos novos meus + **1** método do 186-03 que **saiu do `skip`**. Aquele método (`ConjuntoCompartilhadoTest::test_estrutural_pub_aprovacao_ofertas_nao_tem_conjunto_id_com_isca`) tinha `markTestSkipped` condicionado a `Schema::hasTable('pub_aprovacao_ofertas')`; com a tabela criada por este plano ele passou a **MEDIR**, e mede verde (**1 passed, 2 assertions**). Era exatamente o que o manifesto do 186-03 previa ("o método em `skip` não entra em gate nenhum").

**As +538 asserções:** 301 (Unit) + 234 (Feature) + 2 (o método que saiu do skip) + **1** (ver §13) = 538. ✔

A falha única do JS é a **pré-existente** de `tests/js/estrutura-grade-glide.test.js` (`not ok 191 — Características secundárias nasce recolhido`, commit `5aca9673`, Fase 87). **Não corrigida**, como mandado. Nenhum arquivo `.jsx`/`.js` foi tocado por este plano (diff por range vazio em `resources/js/` e `tests/js/`).

---

## 13. ⚠️⚠️ PERGUNTA DEVOLVIDA — UM GATE DO PLANO 186-03 REPROVA **POR CONTAGEM**

**Não ajustei o manifesto alheio.** Registro o fato medido e devolvo a decisão.

```
gate 'sem-par-oferta-imagem' (php): --filter=test_estrutural_nenhuma_tabela_do_portao_tem_o_par_oferta_id_imagem_id,
                                    esperado 1 passed / 5 assertions
  REPROVOU: nao casou 'Tests: 1 passed (5 assertions)'.
  medido agora: Tests: 1 passed (6 assertions)     EXIT = 1
```

**O teste PASSA** — `1 passed`, **zero** `failed`/`error`. É só a **contagem** do manifesto que ficou velha.

**Causa, com arquivo:linha:** `tests/Feature/Publicador/Aprovacao/ConjuntoCompartilhadoTest.php:269` faz
`glob(base_path('database/migrations/*pub_aprovacao*.php'))` e assevera **uma asserção por arquivo**. O glob tinha 2 arquivos e agora tem **3**:

```
database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php
database/migrations/2026_10_15_100100_create_pub_aprovacao_fidelidade_tables.php
database/migrations/2026_10_15_100200_create_pub_aprovacao_ofertas_table.php   <- a minha
```

É **a mesma classe de evento** que fez o 186-01 elevar a régua do manifesto dele de 55→58 e 817→820 quando o 186-02 criou 3 arquivos: **o número cresce com arquivo novo no diretório varrido**. E é exatamente o caso que o meu briefing manda **PARAR e devolver**, em vez de ajustar manifesto que não é meu.

**O conserto, quando autorizado, é de uma linha** em `tests/gates/contagens-186-03.txt`:

```
-sem-par-oferta-imagem  php  test_estrutural_nenhuma_tabela_do_portao_tem_o_par_oferta_id_imagem_id  ::  tests=1 assertions=5
+sem-par-oferta-imagem  php  test_estrutural_nenhuma_tabela_do_portao_tem_o_par_oferta_id_imagem_id  ::  tests=1 assertions=6
```

**Os outros 18 gates da wave 1 foram rodados e passam** (3 do 186-01, 3 do 186-02, 15 dos 16 do 186-03). Nenhum outro foi afetado.

---

## Desvios do plano

### 1. [Rule 1 — Bug] `conjunto()` BelongsTo não existe; é `conjuntos()` HasMany

- **Onde:** Task 2, `app/Models/PubAprovacaoOferta.php`
- **O plano** listava `conjunto()` entre as relações a declarar — resquício da primeira redação.
- **Por quê:** um `belongsTo` chamado `conjunto()` exigiria a coluna `conjunto_id`, que o **mesmo plano proíbe** (achado S2, e há gate de ausência). O `<interfaces>` do plano já diz que a relação é **1:N**.
- **Feito:** `conjuntos(): HasMany` ligada por `rascunho_id` **nos dois lados** — que é o que torna o compartilhamento Clássico↔Premium estrutural. Asseverado: `conjuntos()->getForeignKeyName() === 'rascunho_id'`.
- **Commit:** `637182df`

### 2. [Rule 1 — Bug] O default do modelo não existia: `create()` devolvia `andamento` NULL em memória

- **Onde:** Task 3 (achado no GREEN), `app/Models/PubAprovacaoOferta.php`
- **O defeito:** o Eloquent **não lê o default do banco**. Criar a oferta sem `andamento` devolvia um objeto com `andamento` **null** enquanto a linha nascia `'preparando'`. A primeira transição lia origem `''` e era **recusada como ilegal**. "A oferta nasce em `preparando`" só era verdade **depois de um `fresh()`**, e ninguém que chamasse `create()` adivinharia isso.
- **Feito:** bloco `$attributes` espelhando os 4 defaults da coluna + gate `default-do-modelo`, que compara o `$attributes` com o default lido do **SCHEMA VIVO** (nunca com outra constante do PHP — comparar PHP com PHP não acusaria divergência nenhuma), com isca de que a sonda leu os 4 e isca inversa de que ela não inventa default para coluna que não tem.
- **Commit:** `9d499a20`

### 3. [Rule 2 — Funcionalidade crítica ausente] `filaDaConta()` sem conta devolveria tudo

- **Onde:** Task 3, `AndamentoDaOfertaService::filaDaConta()`
- **Por quê:** DADO-14/T-186-14. Com as duas âncoras nulas a consulta devolveria as ofertas de **todas** as empresas — vazamento que o schema abençoaria, porque as duas colunas são anuláveis por construção.
- **Feito:** `REGRA_FILA_SEM_CONTA` recusa a consulta sem recorte.
- **Commit:** `9d499a20`

### 4. [Rule 2] Guarda próprio da migration (MIG-01..09)

- **Por quê:** `MigrationDoPortaoGuardaTest` do 186-03 declara só as duas migrations daquele plano; a minha ficaria "coberta" por um gate que nunca a lê.
- **Feito:** 4 gates `guarda-*` em `OfertaDoPortaoTest`, todos com isca.
- **Commit:** `9d499a20`

### 5. [Decisão de gate — NÃO é afrouxamento] A sonda da "única porta" reprovou pelo próprio conserto

- **O que houve:** o `$attributes` do desvio 2 casa `'andamento' =>`, e a sonda acusou o modelo.
- **O que NÃO foi feito:** estreitar a sonda para a forma `update([...])`. Isso deixaria de casar a **escrita de verdade** (o serviço monta as mudanças numa VARIÁVEL, `$mudancas`) e o gate ficaria **vazio por construção** — trocar um gate que reprova demais por um que nunca reprova.
- **Feito:** a sonda ficou **ampla** e entrou um **contrato exato**: o modelo tem direito a **UMA** ocorrência, **dentro do `$attributes`**, com isca que prova que **FORA do bloco não sobra nenhuma** e com `->andamento =` proibido no modelo.
- **Commit:** `9d499a20`

### 6. [Interpretação do `<verify>`] O id `oferta-do-portao` roda três métodos

- **Por quê:** o helper roda **um** filtro por id, e o `<done>` da Task 3 nomeia **três** provas. Um método só não cobriria o `<done>`.
- **Feito:** filtro por alternância (continua começando com `test_`, então o helper não o recusa), **3 / 39 MEDIDO**, e os três métodos **também** têm id próprio, para dizer qual caiu.

Nenhum outro desvio. **Nada de deploy, nada de push, nada no VPS, nenhuma leitura de produção** — tudo medido no **MariaDB 10.4.32 local** com `--path`, e no SQLite dos testes.

---

## Self-Check: PASSED

- 6 arquivos criados, todos presentes no disco (`test -e` em cada).
- 4 commits (`0eaed5a9`, `587d772c`, `637182df`, `9d499a20`) presentes em `git log`.
- `git diff 3b790d21..HEAD --name-only` = exatamente os 6 arquivos; **0 deleções** (`--numstat`).
- Território: diff vazio em `app/Support/Publicador/`, `resources/js/`, `app/Services/Creative/`, `tests/js/`, `.planning/ROADMAP.md`, `.planning/STATE.md`, `.planning/REQUIREMENTS-v27.md`, `contagens-186-01/02/03.txt`, `ConjuntoCompartilhadoTest.php`, `MigrationDoPortaoGuardaTest.php`, as 2 migrations do 186-03, `composer.json`, `composer.lock`.
- `tests/Feature/CompanyPortfolioAccessTest.php` segue `??`, intocado (é de 24/07, de outra sessão).
- 33/33 gates do plano 04 passam depois da campanha de mutação.
- Banco local conferido no fim: `pub_aprovacao_ofertas` aplicada (batch 43), **sem** `conjunto_id`, **sem** `ciente`; **9** pendentes de outras sessões seguem pendentes.
- ⚠️ **1 gate ALHEIO reprova por contagem** (§13), devolvido como pergunta e **não** ajustado.
- ⚠️ Nenhuma tela foi renderizada nem conferida visualmente por mim — a conferência visual é do usuário, em produção.
