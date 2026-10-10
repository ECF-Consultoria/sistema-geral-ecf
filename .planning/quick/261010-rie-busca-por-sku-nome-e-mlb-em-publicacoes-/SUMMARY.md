---
quick_id: 261010-rie
plan: 01
tipo: execute
concluido_em: 2026-10-10
requisitos: [RIE-01, RIE-02, RIE-03, RIE-04]
ponto_de_partida: 62d71b89
commit: 210efb88
---

# Busca por SKU, nome e MLB em Publicações — e "Em revisão" com filtro próprio

A busca de `/mlb/anuncios/meus/{company}` passou a cobrir os **três** identificadores que o usuário
tem na mão — **SKU**, **nome do anúncio** e **código MLB** — no mesmo campo; o filtro de status ganhou
a opção **"Em revisão"**, que isola `under_review`; e `ml_acervo_itens` ganhou a coluna `skus`,
alimentada pela camada barata de coleta **que já existia** (nenhum caminho de escrita novo no acervo).

Falas literais do usuário em 10/10/2026: *"Quero poder buscar por SKU, Nome do Anuncio ou MLB"* e
*"Em revisão quero poder isolar"*.

> ⚠️ **Nota de procedência deste documento:** o executor foi bloqueado pelo harness de gravar arquivo
> de relatório e devolveu o conteúdo como texto; **quem gravou foi o orquestrador**. Os gates abaixo
> foram **remedidos pelo orquestrador** depois do commit — ver §6.

---

## 1. O `ALTER TABLE` e o que esperar no deploy

A sentença que roda em produção é uma só:

    ALTER TABLE ml_acervo_itens ADD COLUMN skus LONGTEXT NULL

**Motor de produção: MySQL 8.0.46** (`8.0.46-0ubuntu0.24.04.4`). ⚠️ **Não é MariaDB** — o MariaDB
10.4.32 é o banco **LOCAL** do XAMPP, apesar de o caminho do cliente dizer `C:/xampp/mysql/bin/`.
Sete arquivos de `.planning/learnings/` chamavam a produção de "MariaDB" até 10/10/2026; o learning
`banco-producao-e-mysql8-local-e-mariadb.md` corrige isso.

**Tamanho da tabela em produção** (medição do orquestrador na VPS, 10/10/2026 — o executor é
bloqueado de consultar produção e **não** conferiu estes números):

| Medida | Valor |
|---|---|
| linhas | **1.080.206** |
| dados | **1.776 MB** |
| índices | **196 MB** |
| engine | InnoDB |

**Expectativa de deploy:** no MySQL 8.0.46, uma coluna **nullable, sem default, sem índice e sem
CHECK** é acrescentada **por metadado (INSTANT)** — não reescreve as 1.080.206 linhas, não copia os
1,7 GB e não trava escrita por tempo perceptível. Não há índice nem CHECK novo que force rebuild.

⚠️ **Mesmo assim, rodar fora da janela das 11:35**, quando o `mlb:sync-acervo` está escrevendo na
mesma tabela.

**Por que o tipo é `longText` no fim da tabela:** é **portabilidade**, não otimização para um motor
que não é o de produção. Há **três** motores em jogo — MySQL 8 em produção, MariaDB 10.4 no local,
SQLite na suíte — e `longText` nullable é o mesmo `LONGTEXT NULL` nos três, sem depender de versão.
`json()` não é equivalente: no MariaDB vira `longtext` + CHECK `json_valid()`, no MySQL 8 vira tipo
JSON nativo. E entrar **no fim** (sem `after()`) vale nos dois: no MySQL 8 o instant add funciona em
qualquer posição desde o 8.0.29, mas no MariaDB 10.4 só na última coluna. A serialização é do cast
`array` do Eloquent, como a aplicação já faz com `variations`/`tags`.

### 1b. ⚠️ O teste local NÃO prova produção — e por que, NESTE caso, é aceitável

A migration foi provada no **MariaDB 10.4.32 local** (ida → volta → ida, por `--path`). Isso prova
que `up()`/`down()` executam, que são idempotentes e que a coluna nasce certa. **Não prova DDL no
MySQL 8 nem duração de nada.**

Para **esta** migration a diferença entre os motores é irrelevante, e **por um motivo nomeável**:
`ADD COLUMN` de coluna **nullable, sem default, sem índice e sem CHECK** é equivalente nos dois.
**É exatamente essa equivalência — e nada mais — que torna o teste local suficiente aqui.**

**Não generalizar.** Qualquer DDL futuro com **índice, CHECK, enum, FK ou `change()`** precisa ser
reconferido contra o MySQL 8 antes de ir. E **nenhum tempo medido no local serve como previsão de
deploy**, por dois motivos somados: a tabela local tem **0 linha** (contra 1.080.206 em produção) e o
motor é outro. Os tempos abaixo são **DDL em tabela vazia noutro motor** — não são previsão de nada.

| Passo | Resultado |
|---|---|
| `migrate --path=…` | `DONE` — 54,23 ms |
| `SHOW COLUMNS … LIKE 'skus'` | `skus / longtext / Null=YES / Default=NULL` |
| posição da coluna | `ORDINAL_POSITION = 40` — **a última** |
| linhas na tabela local | **0** |
| `migrate:rollback --path=…` | `DONE` — 27,14 ms; `SHOW COLUMNS LIKE 'skus'` voltou **vazio** |
| `migrate --path=…` (de novo) | `DONE` — 33,76 ms; coluna de volta como `longtext NULL` |

---

## 2. O SKU precisa de backfill? **Não** — e isto é decisão, não esquecimento

Linha existente fica com `skus = NULL` até a próxima passada da camada barata. Três razões:

1. **`mlb:sync-acervo` roda todo dia às 11:35 e varre o acervo INTEIRO da conta** — não é rotação por
   fatias, é cobertura completa diária (docblock de `MlAcervoService`).
2. **`skus` está dentro de `COLUNAS_CAMADA_BARATA`** (3º argumento do `upsert()`), então essa passada
   **ATUALIZA** a linha existente. Sem isso o upsert não tocaria na coluna e as 1,08 milhão de linhas
   nunca receberiam SKU — **em silêncio**. É a armadilha nº 1 do plano, com teste dedicado
   (`linha_pre_existente_sem_sku_recebe_o_sku_na_coleta_seguinte`).
3. **Um backfill teria de chamar o mesmo multiget** — o SKU só existe na resposta da API. Seria a
   própria varredura diária, de novo, ~20 mil chamadas, para antecipar menos de um dia.

**A janela:** até a próxima varredura (11:35), ou **imediato por empresa** clicando em "Atualizar
agora", que já existe. É precisamente essa janela que o **RIE-04** cobre na tela.

---

## 3. Como o SKU de anúncio com variações foi guardado

**A coluna guarda a LISTA de todos os SKUs distintos do anúncio** — o do item-pai primeiro (quando
existe), depois os das variações na ordem em que o ML devolve.

**Por que não foi colapsado em um só:** `ml_acervo_itens` tem **uma linha por ANÚNCIO** (D-17) e, em
anúncio com variações, **o SKU é por variação**. Guardar um só exigiria escolher qual — e qualquer
escolha mentiria sobre as outras, ou devolveria `null` e tornaria o anúncio **inachável**. Como o
objetivo é *achar o anúncio por qualquer um dos seus SKUs*, a lista é a resposta honesta.

**A diferença deliberada em relação a `AnunciosMercadoLivreService::skuDoAnuncio()`** (L626/L632, a
fonte da receita, que **não foi tocada** — é gate de diff): lá, variações com SKUs **divergentes**
devolvem `null`, porque uma linha da colagem da planilha é um SKU só e SKU ambíguo é pior que SKU
nenhum. Aqui **todas** entram, porque o propósito é busca. A precedência é idêntica: atributo
`SELLER_SKU` primeiro, `seller_custom_field` como fallback, a mesma regra por variação.

**O que a tela mostra quando há mais de um:** o primeiro SKU **e quantos são** — `SKU V1 +2`, com
`title` dizendo `3 SKUs (um por variação): V1, V2, V3`. Nunca apresenta um SKU de variação como se
fosse "o" SKU do anúncio.

**Por que uma lista cabe nessa linha:** a MESMA linha já guarda `variations` com o payload **bruto**
das variações, ordens de grandeza maior que a lista de SKUs extraída dele.

---

## 4. Efeito confirmado nos chips (D-09) e na ordenação (D-12)

A opção `Em revisão` **acrescenta** — a lista fechada passou de 5 para **6** valores (`acionaveis`,
`ativos`, `pausados`, **`em_revisao`**, `encerrados`, `todos`) e `acionaveis` **segue o default**,
cobrindo `active` + `paused` + `under_review`. As emendas de 10/08/2026 (`paused`) e 10/10/2026
(`under_review`) ficaram intactas.

**Chips — `pausado` e `sem_estoque` ficam necessariamente em 0 nesse filtro, e isso é a VERDADE
daquele universo, não um bug.** `AnuncioSaudeService::triagem()` só carimba `MOTIVO_PAUSADO` com
`status === 'paused'` e `MOTIVO_SEM_ESTOQUE` com `status === 'active'`. Ficha/foto/catálogo continuam
contando. Provado por **T27**: com um pausado e um sem-estoque existindo na empresa, o filtro
`em_revisao` devolve `pausado = 0`, `sem_estoque = 0`, `ficha_incompleta = 1` e `total = 1`. A
contagem dos **outros** filtros não mudou (T26).

**Ordenação — nenhuma mudança.** `meus()` ordena por `severidade` → `nota_ecf IS NULL` → `nota_ecf` →
`ml_item_id`, e **nenhum** desses critérios olha `status`. Provado por **T31**.

---

## 5. Os três estados de `skus`, e como o RIE-04 usa isso

| Estado | Significado | Quem grava |
|---|---|---|
| `NULL` | **ainda não coletado** — linha gravada antes desta mudança | ninguém; é o default da coluna |
| `[]` | **coletado, e o anúncio não tem SKU** | a camada barata, via `json_encode([])` |
| lista não vazia | o dado | a camada barata |

Mesma disciplina do D-18 do acervo: ausência de coleta é estado de primeira classe, nunca zero
inventado. É o que torna o **RIE-04 exato em vez de chutado**: a prop `skuNaoColetado` só é `true`
quando há busca, o resultado é zero **e** nenhuma linha da empresa tem `skus` não-nulo. Nesse caso a
tela diz que o SKU ainda não foi coletado e que **uma busca por SKU não acharia nada mesmo que o
anúncio exista**, com o "Atualizar agora". Com ao menos uma linha coletada, o "não achei" é verdade e
nada é ressalvado (T32/T33/T34).

A prop é barata: `$anuncios->total()` vem do `count` que o `paginate()` já fez, e o `exists()` só roda
no caminho de zero resultado com busca — nunca no caminho normal da tela.

---

## 6. Gates — antes e depois

As colunas "depois" foram **remedidas pelo orquestrador** após o commit `210efb88`.

| Gate | Antes | Depois |
|---|---|---|
| `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1728 / 0 falha** | **1738 / 0 falha** (+10) |
| `npm run test:js` | **2141 / 2140 pass / 1 fail** | **2151 / 2150 pass / 1 fail** (+10) |
| `phpunit tests/Unit/Phase134 tests/Feature/Phase134` | **87 / 0 falha** (medido antes de editar) | **111 / 0 falha** (+24) |
| `phpunit tests/Feature/Phase135` | **243 / 0 falha** (medido antes de editar) | **243 / 0 falha** (inalterado) |

A **única** falha de JS é a pré-existente `estrutura-grade-glide` — *"Características secundárias nasce
recolhido (é o grupo que mais infla)"*. **Não corrigida de propósito**: não é desta quick.

**Manifest do Vite** (pelo JSON, nunca por grep — o hash sai com hífen):

| | `resources/js/Pages/Mlb/MeusAnuncios.jsx` |
|---|---|
| antes | `assets/MeusAnuncios-Dxunbzrh.js` |
| **depois** | **`assets/MeusAnuncios-CUkRCwpY.js`** |

⚠️ `public/build` está no `.gitignore` (linha 41) — os artefatos do build **não** entram no commit; o
deploy reconstrói.

**Gates de diff, medidos do ponto de partida `62d71b89`** — sem o range, depois de commitar, o
`git diff --stat -- <dir>` sai vazio **por construção** e não prova nada:

    git diff --stat 62d71b89..HEAD -- app/Support/Publicador app/Services/Portal/Estrutura   → VAZIO ✓

Escopo real em `62d71b89..HEAD`: `MlbAnuncioController.php` (+31), `MlAcervoService.php` (+28),
`SkusDoAnuncio.php` (+107, novo), `AcervoTriagemService.php` (+48), `MeusAnuncios.jsx` (+104).
Deleções: nenhuma.

**Gate do D-RIE-03 na migration:** o teste `migration_nao_usa_after_nem_json` assert por regex
`/->after\(/`, `/->json\(/` e `/->index\(/` sobre a fonte — **nenhum casa**. ⚠️ Um `grep` cru por
`"after(\|->json("` **tem** 2 resultados, mas as duas são **prosa do docblock** (a tabela de decisão
diz, em português, "sem `after()`" e "nunca `json()`"). O gate honesto é o do nível do código:
`grep -- "->after(\|->json("` sai vazio. **O docblock não foi mutilado para satisfazer um grep que
casa com comentário.**

---

## O que foi tocado

**Novos:**
- `database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php` — coluna `skus` longText
  NULL, última da tabela, idempotente por `hasColumn`, sem try/catch em DDL, com a tabela de decisão
  de schema e a nota de deploy nomeando o motor.
- `app/Services/Mlb/Acervo/SkusDoAnuncio.php` — extrator da lista de SKUs (pai + variações).
- `tests/Unit/Phase134/SkuDoAcervoTest.php` — 17 testes.
- `tests/js/publicador-meus-anuncios-sku-render.test.js` — 10 testes de render real.

**Editados:**
- `app/Models/MlAcervoItem.php` — `skus` no `$fillable` e cast `array`.
- `app/Services/Mlb/Acervo/MlAcervoService.php` — `seller_custom_field` no `ATRIBUTOS_MULTIGET`,
  `'skus'` em `COLUNAS_CAMADA_BARATA` (com o porquê no código) e `json_encode($skus)` no array de
  `$linhas` do `processarLote()` que já existia. **Nenhum `upsert`/`update` novo**: o SKU passa pelo
  mesmo `AcervoEscritaLock::naEmpresa()` de hoje, e **`MlAcervoDetalheService` (camada CARA) não
  escreve `skus`** — adicionar lá recriaria a geometria dos ~20 deadlocks/dia (E2 do
  `acervo-deadlock-upsert.md`).
- `app/Services/Publicador/AcervoTriagemService.php` — arm `'em_revisao' => ['under_review']` e um
  terceiro `orWhere('skus', …)` **dentro do mesmo `where(function …)`** (fora dele anularia o escopo
  por empresa — T-134-01). O ramo do SKU é **pulado** quando o termo contém `"`, `[` ou `]`: a
  pontuação do próprio JSON casaria com toda linha coletada e devolveria o acervo inteiro como se
  fosse resultado (T24).
- `app/Http/Controllers/MlbAnuncioController.php` — whitelist com 6 valores, prop `skus` por linha
  (array sempre, nunca `null`) e prop `skuNaoColetado`.
- `resources/js/Pages/Mlb/MeusAnuncios.jsx` — opção "Em revisão", placeholder dos três campos,
  `CelulaSku`, `VazioDaListagem`, e o **comentário desatualizado da L36 corrigido** (dizia "ativos +
  pausados", falso desde a quick `261010-nke`).

**Jargão:** `under_review` **não** aparece na tela. O rótulo é "Em revisão".

⚠️ **Nenhuma tela foi vista renderizada.** Não há navegador aqui: o que existe é render de componente
no servidor (`react-dom/server`, com `skus` e `busca` chegando como **objeto, nulo e ausente**) e
gates de fonte. **A conferência visual é do usuário.**

---

## 7. Fora de escopo / perguntas abertas

1. **Backfill retroativo do SKU** — decidido não fazer. Se o usuário quiser o SKU em todo o acervo
   **antes** da varredura de amanhã às 11:35, o caminho é "Atualizar agora" por empresa; um comando
   com `--dry-run` e a ordem materializar → conferir → comparar → aplicar só se ele pedir.
   **Pergunta: há empresa em que esperar até as 11:35 incomoda?**
2. **Índice para a busca** — não criado: `LIKE '%termo%'` não usa índice, e um índice novo em
   1.080.206 linhas é construção de índice no deploy, com benefício zero. Se a busca ficar lenta nas
   contas grandes, o caminho honesto é full-text ou prefixo (`LIKE 'termo%'`), que **muda o
   comportamento** — decisão do usuário.
3. **Status `inactive`** — `encerrados` cobre só `['closed']`; item `inactive` só aparece em "Todos".
   Buraco **pré-existente**, não tocado.
4. **SKU na busca da Visão geral / outras telas** — só a aba Publicações foi pedida.
5. **SKU no modal de detalhe (`ModalDetalheAnuncio.jsx`)** — não pedido. **Pergunta: faz falta?**
6. **Coluna de status por linha na tabela** — segue fora; o isolamento do "Em revisão" é pelo filtro.
7. **Precificação e o card "Quanto você recebe"** (`7a6f752c`/`ad86541b`) — não tocados.

**Não deployado, não pushado, nada executado no VPS** — o usuário sobe quando quiser.
