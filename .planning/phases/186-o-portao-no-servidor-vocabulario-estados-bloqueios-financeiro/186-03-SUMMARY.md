---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 03
subsystem: publicador
tags: [migration, schema, aprovacao, conjunto-compartilhado, fidelidade, defeitos, tdd, mutacao]
requires:
  - "pub_rascunhos, pub_imagens, pub_produtos, pub_rascunho_alvos (Fase 164, 2026_10_01_200000)"
  - "tests/gates/verificar-testes.sh (plano 186-01)"
  - "resources/js/.../aprovacao/vocabularioDoPortao.jsx (plano 186-02, lido SOMENTE LEITURA)"
provides:
  - "pub_aprovacao_conjuntos — o conjunto de fotos compartilhado, por (rascunho x grupo de variacao)"
  - "pub_aprovacao_imagens — o estado da imagem NA REVISAO, eixo novo e separado do upload (DADO-05)"
  - "pub_aprovacao_imagem_alertas — verificacoes automaticas com severidade, texto e x/y em % (DADO-06)"
  - "pub_aprovacao_fidelidade — o checklist dos 7 criterios (DADO-07)"
  - "pub_aprovacao_defeitos — os 6 chips de defeito (DADO-08)"
  - "Os 5 modelos com as constantes 5 / 2 / 3 / 7 / 6"
  - "PubAprovacaoConjunto::doRascunho() — a travessia 1:N que o bloqueio de fotos usa"
  - "tests/gates/contagens-186-03.txt — manifesto de 16 gates, toda contagem MEDIDA"
affects:
  - "Plano 186-04 (pub_aprovacao_ofertas): a oferta NAO ganha conjunto_id — le todos os conjuntos do rascunho"
  - "Plano 186-06 (pub_imagem_referencias, DADO-15): a relacao N:N de original de referencia nao mora aqui"
  - "Plano 186-09: o teste cruzado dos 7 criterios e 6 defeitos PHP x JS"
tech-stack:
  added: []
  patterns:
    - "Tabela de decisao de schema no docblock ANTES do codigo (MIG-07)"
    - "Nome de indice/FK nomeado a mao, com os caracteres CONTADOS no docblock (MIG-02)"
    - "Indexa-se o HASH, nunca o texto de 600 chars (precedente de pub_imagem_atribuicoes)"
    - "Sonda de proibicao le o CODIGO sem comentarios, pelo tokenizador do PHP"
    - "Toda sonda tem ISCA — um controle sintetico que ela TEM de acusar"
key-files:
  created:
    - database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php
    - database/migrations/2026_10_15_100100_create_pub_aprovacao_fidelidade_tables.php
    - app/Models/PubAprovacaoConjunto.php
    - app/Models/PubAprovacaoImagem.php
    - app/Models/PubAprovacaoImagemAlerta.php
    - app/Models/PubAprovacaoFidelidade.php
    - app/Models/PubAprovacaoDefeito.php
    - tests/Feature/Publicador/Aprovacao/ConjuntoCompartilhadoTest.php
    - tests/Feature/Publicador/Aprovacao/MigrationDoPortaoGuardaTest.php
    - tests/gates/contagens-186-03.txt
  modified: []
decisions:
  - "O compartilhamento Classico<->Premium e estrutural porque NAO EXISTE coluna onde divergir — e isso virou assercao, nao comentario"
  - "conjunto_id NAO entra em pub_aprovacao_ofertas (186-04): a relacao oferta->conjunto e 1:N e le-se por rascunho_id"
  - "Nenhum pivo N:N oferta x conjunto: teria sempre o mesmo conteudo do join por rascunho_id, e pivo que pode divergir e pior que nenhum"
  - "Sem contador denormalizado (fotos_aprovadas/fotos_no_conjunto): aprovadas()/pendentes()/comAlerta() CONTAM"
  - "comAlerta() conta so imagem PENDENTE — alerta de imagem decidida fica gravado (historico) e deixa de contar"
  - "A presenca da linha em pub_aprovacao_defeitos E a marcacao: sem coluna `marcado`, que permitiria duas verdades"
  - "down() de migration de `create` usa dropIfExists filha->mae; o par dropForeign/dropIndex e receita de migration ADITIVA"
  - "Sonda de proibicao (enum, try) le o codigo SEM comentarios: o docblock explica que nao usa a coisa e casava com o gate"
metrics:
  duration: "~3h"
  completed: 2026-10-14
  tasks: 3
  commits: 5
---

# Fase 186 Plano 03: Conjunto de fotos compartilhado, revisão por imagem, verificações com coordenada, fidelidade e defeitos — Summary

**2 migrations / 5 tabelas / 5 modelos**, e o ponto em que o compartilhamento Clássico↔Premium
deixa de ser combinação e passa a ser **estrutura**: a aprovação da foto é gravada **por conjunto**,
o conjunto é **por rascunho**, e **não existe coluna onde as duas ofertas pudessem divergir** —
o que agora é *conferível*, não afirmado.

Commit de partida: **`28b45b40`**. Todo gate de diff usa o range **`28b45b40..HEAD`**.

## Commits

| # | hash | o que |
|---|---|---|
| 1 | `8db7c43d` | `feat(186-03)` migration 1: conjuntos + revisão por imagem + alertas com x/y |
| 2 | `27bd6d3e` | `feat(186-03)` migration 2: fidelidade + defeitos, e os 5 modelos |
| 3 | `27df50b9` | `test(186-03)` **RED** — os 2 testes + o manifesto (o gate do alerta reprova de propósito) |
| 4 | `f598bc0d` | `fix(186-03)` **GREEN** — `comAlerta()` passa a contar só a imagem não decidida |
| 5 | `e8226690` | `test(186-03)` gate `guarda-nomes-declarados`, **nascido de um sobrevivente da mutação** |

---

## TABELA DE DECISÃO DE SCHEMA — migration 1 (`2026_10_15_100000`)

### `pub_aprovacao_conjuntos` — um conjunto de fotos por (rascunho × grupo de variação)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `rascunho_id` | unsignedBigInteger | FK `pubapcj_rascunho_fk` → `pub_rascunhos`, **CASCADE**: rascunho apagado leva a revisão dele |
| `grupo_chave` | string(600) default `'GENERAL'` | o TEXTO do grupo, **SEM índice**; default = galeria geral, nunca NULL (espelha `pub_imagem_atribuicoes`) |
| `grupo_hash` | char(64) | sha256 do grupo — é o que ENTRA no índice |
| `principal_imagem_id` | unsignedBigInteger **NULL** | FK `pubapcj_principal_fk` → `pub_imagens`, **SET NULL**: a capa do grupo; imagem apagada não derruba o conjunto |
| `tocado` | boolean default false | o `touched` do protótipo: "alguém já olhou este grupo" |
| `timestamps` | | |

**`fotos_aprovadas` / `fotos_no_conjunto` NÃO EXISTEM**, de propósito — são derivados. Contador
denormalizado é a classe de bug que faz a tela dizer "3 de 4 aprovadas" com 5 fotos no banco.

**`grupo_chave` é string(600) e de propósito SEM índice:** 600 chars em utf8mb4 são até 2400 bytes e
estourariam o limite de 3072 bytes de chave do InnoDB. Precedente literal de
`pub_imagem_atribuicoes.grupo_chave` (`2026_10_01_200000:196`) e de `pub_grupo`
(`2026_10_04_120000`). **Indexa-se o HASH, nunca o texto.**

### `pub_aprovacao_imagens` — o estado da imagem NA REVISÃO (DADO-05)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `conjunto_id` | unsignedBigInteger | FK `pubapim_conjunto_fk` → `pub_aprovacao_conjuntos`, **CASCADE** |
| `imagem_id` | unsignedBigInteger | FK `pubapim_imagem_fk` → `pub_imagens`, **CASCADE**: foto apagada sai da revisão |
| `tipo` | string(10) | `original` \| `ia` — texto + constante no modelo (sem enum) |
| `estado` | string(14) default `'pending'` | `pending` \| `approved` \| `rejected` \| `regenerating` \| `replaced` (maior = 12) |
| `posicao` | unsignedSmallInteger **NULL** | ⚠️ **NULL = fora da publicação**; a principal é sempre a `posicao` 0 (IMG-07) |
| `fora` | boolean default false | tirada da publicação explicitamente |
| `nova` | boolean default false | o selo **NOVA** da tela |
| `quem_id` | unsignedBigInteger **NULL** | FK `pubapim_quem_fk` → `users`, **SET NULL** |
| `quando` | dateTime NULL | `dateTime`, nunca `timestamp()`, em data avulsa |
| `motivo` | string(500) NULL | obrigatório ao reprovar — regra do SERVIÇO (IMG-10), o schema aceita NULL |
| `timestamps` | | |

**NÃO é `pub_imagens.upload_status`.** Aquele eixo é de UPLOAD (`pending`/`uploaded`/`failed`);
este é de REVISÃO. Uma foto pode estar `uploaded` e `rejected` ao mesmo tempo, e isso é correto.

**NÃO há coluna de "vínculo com variação"** — a variação JÁ É o conjunto (`grupo_hash`).
**NÃO há coluna de "original de referência"** — ver a declaração sobre `pub_imagem_referencias` abaixo.
**NÃO há coluna para alcançar o criativo** — a ponte já existe e é indexada no outro sentido
(`ml_anuncio_criativos.pub_imagem_id`, `2026_10_04_120000` bloco (e), índice `ml_criativos_pubimg_idx`).

### `pub_aprovacao_imagem_alertas` — verificações automáticas (DADO-06)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `aprovacao_imagem_id` | unsignedBigInteger | FK `pubapal_imagem_fk` → `pub_aprovacao_imagens`, **CASCADE** |
| `severidade` | string(4) | `ok` \| `med` \| `high` — `high` ocupa os 4; sem enum |
| `texto` | string(500) | o que a verificação achou, em pt-BR, pronto para a tela |
| `x` | decimal(5,2) NULL | ⚠️ coordenada em **% da imagem** (0,00–100,00) |
| `y` | decimal(5,2) NULL | idem; o protótipo só manda x/y quando `sev !== 'ok'` |
| `origem` | string(20) default `'automatica'` | de onde veio o alerta |
| `timestamps` | | |

**A folga do decimal e o modo de falhar assimétrico:** `decimal(5,2)` aceita até **999,99**, 10× a
faixa esperada. Estourar a precisão dá **erro 1264 no MySQL/MariaDB** e **grava truncado em
silêncio no SQLite dos testes** — a suíte não pega. Por isso a faixa está escrita no docblock da
migration e do modelo, e x/y são castados para `float`.

---

## TABELA DE DECISÃO DE SCHEMA — migration 2 (`2026_10_15_100100`)

### `pub_aprovacao_fidelidade` — o checklist de 7 critérios (DADO-07)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `aprovacao_imagem_id` | unsignedBigInteger | FK `pubapfd_imagem_fk` → `pub_aprovacao_imagens`, **CASCADE** |
| `criterio` | string(16) | `cor`, `formato`, `material`, `textura`, `proporcoes`, `quantidade`, `acessorios` (maior = 10) |
| `resultado` | string(8) | `confere` \| `diverge` (7 cada) |
| `quem_id` | unsignedBigInteger **NULL** | FK `pubapfd_quem_fk` → `users`, **SET NULL** |
| `quando` | dateTime NULL | |
| `timestamps` | | |

unique (`aprovacao_imagem_id`, `criterio`) — **um veredicto por critério por imagem**: é o unique
que impede a tela de acumular dois "cor" contraditórios na mesma foto.

`quantidade` ("Quantidade do kit") só se aplica em combo, e a regra é do **SERVIÇO**
(`aplicavel(bool $ehCombo)`), não do schema: "Unidade" e "Combo ×N" são `pub_produtos` diferentes,
a flag não alcança esta tabela sem join de 3 saltos, e **CHECK com join não existe em MariaDB 10.4**.

### `pub_aprovacao_defeitos` — os 6 chips (DADO-08)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `aprovacao_imagem_id` | unsignedBigInteger | FK `pubapdf_imagem_fk` → `pub_aprovacao_imagens`, **CASCADE** |
| `defeito` | string(16) | `deform`, `dup`, `falta`, `texto`, `recorte`, `inventado` (maior = 9) |
| `quem_id` | unsignedBigInteger **NULL** | FK `pubapdf_quem_fk` → `users`, **SET NULL** |
| `quando` | dateTime NULL | |
| `timestamps` | | |

unique (`aprovacao_imagem_id`, `defeito`) — **a presença da linha É a marcação**. Sem coluna
`marcado` booleana, que permitiria duas verdades (linha ausente × `marcado = false`) para o mesmo
fato. E sem `resultado`: o critério de fidelidade tem dois veredictos, o defeito tem um.

### DADO-14 — o recorte por conta/empresa

Nenhuma das 5 tabelas tem `company_id`/`mlb_empresa_id`, e é **decisão**: o recorte vem por
`rascunho_id → pub_rascunhos.produto_id → pub_produtos.company_id/.mlb_empresa_id`. Coluna
duplicada de conta pode divergir do produto (o produto troca de âncora, a linha do portão fica
com a antiga) e seria uma via de vazamento entre empresas **que o schema abençoaria**.

---

## NOMES DE ÍNDICE E DE FK, COM OS CARACTERES CONTADOS

Todos conferidos por `strlen` (nunca por número copiado à mão) e **todos conferidos LANDADOS no
MariaDB** por `information_schema.statistics` / `.table_constraints` — ou seja, nenhum caiu em
nome padrão do Laravel.

### Nomeados à mão — migration 1

| nome | chars | | nome | chars |
|---|---|---|---|---|
| `pubapcj_grupo_uq` | 16 | | `pubapim_estado_ix` | 17 |
| `pubapcj_rasc_ix` | 15 | | `pubapim_imagem_ix` | 17 |
| `pubapcj_principal_ix` | 20 | | `pubapim_quem_ix` | 15 |
| `pubapcj_rascunho_fk` | 19 | | `pubapim_conjunto_fk` | 19 |
| `pubapcj_principal_fk` | 20 | | `pubapim_imagem_fk` | 17 |
| `pubapim_img_uq` | 14 | | `pubapim_quem_fk` | 15 |
| `pubapim_ordem_ix` | 16 | | `pubapal_sev_ix` | 14 |
| | | | `pubapal_imagem_fk` | 17 |

### Nomeados à mão — migration 2

| nome | chars | | nome | chars |
|---|---|---|---|---|
| `pubapfd_crit_uq` | 15 | | `pubapdf_def_uq` | 14 |
| `pubapfd_quem_ix` | 15 | | `pubapdf_quem_ix` | 15 |
| `pubapfd_imagem_fk` | 17 | | `pubapdf_imagem_fk` | 17 |
| `pubapfd_quem_fk` | 15 | | `pubapdf_quem_fk` | 15 |

### Nomes PADRÃO do Laravel (`<tabela>_<coluna>_foreign`) — contados porque são os que ESTOURAM

| nome padrão | chars |
|---|---|
| `pub_aprovacao_conjuntos_rascunho_id_foreign` | 43 |
| `pub_aprovacao_conjuntos_principal_imagem_id_foreign` | 51 |
| `pub_aprovacao_imagens_conjunto_id_foreign` | 41 |
| `pub_aprovacao_imagens_imagem_id_foreign` | 39 |
| `pub_aprovacao_imagens_quem_id_foreign` | 37 |
| **`pub_aprovacao_imagem_alertas_aprovacao_imagem_id_foreign`** | **56** |
| `pub_aprovacao_fidelidade_aprovacao_imagem_id_foreign` | 52 |
| `pub_aprovacao_fidelidade_quem_id_foreign` | 40 |
| `pub_aprovacao_defeitos_aprovacao_imagem_id_foreign` | 50 |
| `pub_aprovacao_defeitos_quem_id_foreign` | 38 |

⚠️ **O 186-03-PLAN.md:228 dizia 57 para o mais longo. MEDIDO: 56.** O gate
`guarda-nomes-64` **TRAVA o 56**, então mudar o schema sem reconferir o docblock reprova.
Nenhum desses nomes é usado: toda FK é nomeada à mão (gate prova que nenhum `->constrained()`
aparece e que toda `->foreign()` tem o 2º argumento).

---

## A PROVA `migrate → rollback → migrate` (MariaDB 10.4.32 local)

`tasklist | grep mysqld` conferido antes: `mysqld.exe  11008`. Driver `mysql`, banco `ecf_admin`,
`version()` = **10.4.32-MariaDB**.

⚠️ Todo `migrate` usou **`--path` só do arquivo em questão** — o banco local tem **9 migrations
pendentes de outras sessões** e um `migrate` sem `--path` rodaria todas. O `rollback --step=1` foi
seguro porque cada `migrate --path` abriu um **batch próprio com 1 migration** (41 e 42),
conferido por reconsulta à tabela `migrations` entre os passos.

### Migration 1 (batch 41, 1 migration)

```
2026_10_15_100000_create_pub_aprovacao_conjuntos_tables ....... 387.35ms DONE
### BATCH criado: 41 com 1 migration(s)
  INFO  Rolling back migrations.
2026_10_15_100000_create_pub_aprovacao_conjuntos_tables ........ 28.79ms DONE
  INFO  Running migrations.
2026_10_15_100000_create_pub_aprovacao_conjuntos_tables ....... 385.70ms DONE
===> GATE TASK 1: PASSOU (exit 0 na cadeia inteira)
```

Índices/FKs conferidos por `information_schema` depois: `pubapcj_grupo_uq` (UNIQUE),
`pubapcj_rasc_ix`, `pubapcj_principal_ix`, FKs `pubapcj_principal_fk` e `pubapcj_rascunho_fk`;
`pubapim_img_uq` (UNIQUE), `pubapim_ordem_ix`, `pubapim_estado_ix`, `pubapim_imagem_ix`,
`pubapim_quem_ix`, FKs `pubapim_conjunto_fk`/`pubapim_imagem_fk`/`pubapim_quem_fk`;
`pubapal_sev_ix`, FK `pubapal_imagem_fk`. **15 de 15, todos com o nome à mão.**

### Migration 2 (batch 42, 1 migration)

```
2026_10_15_100100_create_pub_aprovacao_fidelidade_tables ...... 217.66ms DONE
### BATCH: 42 com 1 migration(s)
  INFO  Rolling back migrations.
2026_10_15_100100_create_pub_aprovacao_fidelidade_tables ....... 17.45ms DONE
  INFO  Running migrations.
2026_10_15_100100_create_pub_aprovacao_fidelidade_tables ...... 280.09ms DONE
===> GATE TASK 2: PASSOU (exit 0 na cadeia inteira)
```

Conferidos: `pubapfd_crit_uq` (UNIQUE), `pubapfd_quem_ix`, FKs `pubapfd_imagem_fk`/`pubapfd_quem_fk`;
`pubapdf_def_uq` (UNIQUE), `pubapdf_quem_ix`, FKs `pubapdf_imagem_fk`/`pubapdf_quem_fk`.
**10 de 10.**

---

## A PROVA DO EIXO VARIANTE (o achado S2)

**Decisão do usuário, 14/10/2026:** o bloqueio de fotos considera **TODOS** os grupos do rascunho.
Aprovação cor por cor foi oferecida e **RECUSADA**, pela razão que amarra a milestone: aprovar já
publica e não há desfazer.

| o que foi provado | como | gate |
|---|---|---|
| 3 valores de eixo que definem foto ⇒ **4 conjuntos** (3 + `GENERAL`) | conferido contra `ResolvedorGruposImagem::chaveDoGrupo()` **REAL** e `::GERAL`, não contra literal copiado | `quatro-conjuntos` |
| **as duas** ofertas veem **os 4** | `doRascunho()` por alvo, `grupo_chave` ordenado, comparado alvo a alvo | `quatro-conjuntos` |
| a união sai em **2 consultas** e **não cresce** com o nº de grupos | `DB::enableQueryLog()`; uma por grupo daria 5. Mais asserção de que a 2ª usa `in (` | `quatro-conjuntos` |
| pendente num grupo que **não é o primeiro** ⇒ **as duas** ofertas não-aprováveis | `aprovavel()` por alvo; o grupo escolhido é asserido `!== $chaves[0]` | `eixo-variante-bloqueia` |
| **ISCA**: aprovar a última faz o bloqueio **desaparecer** nas duas | sem isso, `aprovavel()` sempre falso passaria | `eixo-variante-bloqueia` |
| pendente no **`GENERAL`** ⇒ idem, com isca | `$pendente->conjunto->ehGeral()` asserido antes | `eixo-variante-geral` |

É este par de gates que pega o achado S2: com uma FK `conjunto_id` única na oferta, as fotos dos
outros grupos e as `GENERAL` ficariam **fora** do bloqueio.

**Mutação que confirma:** `doRascunho()` com `->limit(1)` — o bug S2 em código — **MATA**
`eixo-variante-bloqueia`.

---

## A PROVA DE QUE `conjunto_id` NÃO EXISTE, E DE QUE NÃO HÁ `(oferta_id, imagem_id)`

⚠️ **Divergência honesta, e ela muda a forma da prova.** O plano pedia
`Schema::hasColumn('pub_aprovacao_ofertas', 'conjunto_id') === false` com isca em
`versao === true`. **`pub_aprovacao_ofertas` ainda NÃO EXISTE** (é do plano 186-04; conferido por
`information_schema`: `NAO EXISTE`). Nessa condição:

- o `=== false` seria **verdade por ausência de tabela** — vazio por construção;
- a isca (`versao === true`) **reprovaria** sem medir nada.

➡️ O método virou **`skip` NOMEADO** (`test_estrutural_pub_aprovacao_ofertas_nao_tem_conjunto_id_com_isca`),
dizendo por extenso que a tabela é do 186-04 e apontando para a prova que vale agora. Ele **não
tem id no manifesto** de propósito: `Tests: 1 skipped` não casa `N passed (A assertions)` e
abortaria o helper. Quando o 186-04 criar a tabela, o método passa a medir sozinho.

**A prova que VALE AGORA é textual, e é non-vacuous** (gate `sem-par-oferta-imagem`): nenhuma
migration do portão declara `'oferta_id'` **e** `'imagem_id'` no **mesmo** bloco de
`Schema::create`. Com **duas** iscas:

1. a mesma sonda, num texto sintético que **tem** o par, precisa **ACHAR**;
2. as duas colunas em tabelas **diferentes** precisam **não** contar (a sonda olha por BLOCO).

⚠️ **A isca 1 pegou a sonda CEGA de verdade:** a primeira redação casava o fim do bloco por
indentação (12 espaços antes do `});`) e **nunca casava nada** — teria sido gate verde permanente.
A sonda foi reescrita para partir o texto em blocos por `Schema::create(`.

**Mutação que confirma:** pôr `$t->unsignedBigInteger('oferta_id');` ao lado de `'imagem_id'` em
`pub_aprovacao_imagens` **MATA** `sem-par-oferta-imagem`.

E a razão de **não** haver pivô N:N oferta × conjunto está escrita no docblock: ele teria **sempre**
o mesmo conteúdo do `join` por `rascunho_id`, e um pivô que pode divergir da verdade é pior que
nenhum.

---

## A CAMPANHA DE MUTAÇÃO

Disciplina de cada mutação: **contagem de ocorrências travada ANTES** (o script aborta se ≠ 1) ·
**qual linha mudou** impressa por `git diff --unified=0` depois · **restauração conferida por
sha256** contra o original · **árvore conferida limpa** no fim.

| # | mutação | gate | resultado |
|---|---|---|---|
| 1 | tirar `->nullable()` do `quem_id` (erro 1830) | `guarda-nullable` | **MORTA** |
| 2 | `string('estado')` → `enum()` (MIG-04) | `guarda-sem-enum` | **MORTA** |
| 3 | nome de índice com **65** chars (erro 1059) | `guarda-nomes-64` | ⚠️ **SOBREVIVEU** |
| 4 | inverter a ordem do `down()`: mãe antes da filha | `guarda-down-ordem` | **MORTA** |
| 5 | **reprise da #3** contra o gate NOVO | `guarda-nomes-declarados` | **MORTA** |
| 6 | indexar `grupo_chave` (texto de 600) em vez do hash | `guarda-hash-indexado` | **MORTA** |
| 7 | pôr `'oferta_id'` ao lado de `'imagem_id'` | `sem-par-oferta-imagem` | **MORTA** |
| 8 | **o achado S2**: `doRascunho()` lê UM conjunto só | `eixo-variante-bloqueia` | **MORTA** |
| 9 | `comAlerta()` volta a contar imagem já decidida | `alerta-conta-so-pendente` | **MORTA** |

### A sobrevivente foi INVESTIGADA, não presumida equivalente — e era lacuna REAL

`guarda-nomes-64` aponta para `test_os_nomes_padrao_do_laravel_tambem_cabem_em_64_...`, que mede só
os nomes **PADRÃO** (computados por `strlen`) e a convenção de nomear à mão. Quem mede os nomes
**DECLARADOS no texto** é `test_nenhum_nome_de_indice_ou_fk_declarado_passa_de_64_com_isca` — ele
**existia no arquivo de teste e NÃO tinha id no manifesto**, logo nenhum gate o executava. Era
justamente a verificação do **erro 1059**, o que deixa a migration `Pending` com a tabela JÁ criada.

➡️ Gate novo `guarda-nomes-declarados` (1 / 50, medido), commit `e8226690`. A **mesma** mutação
**MORRE** contra ele (reprise #5). É exatamente a classe de buraco que a campanha existe para achar.

### Isca do próprio manifesto

| caso | esperado | obtido |
|---|---|---|
| contagem errada (`assertions=99`) | reprovar | **EXIT 1** + `REPROVOU: nao casou 'Tests: 1 passed (99 assertions)'` |
| id ausente do manifesto | abortar | **EXIT 2** + `ABORTOU: id ... nao esta em ... (manifesto incompleto)` |

---

## GATES E CONTAGENS (`tests/gates/contagens-186-03.txt`)

**16 gates, todos no modo `php`, todos por MÉTODO, toda contagem MEDIDA em 14/10/2026** —
uma invocação por método, com o ANSI tirado antes de ler a linha `Tests:`.
**Nenhum método deste plano tem sonda com `skip` por `mtime`**, por isso não há segundo regime:
pristino e mutado dão o mesmo número, e a campanha de mutação rodou **sem tocar no manifesto**.

| gate | método | tests / assertions |
|---|---|---|
| `conjunto-compartilhado` | `test_aprovar_a_foto_uma_vez_vale_para_classico_e_premium_sem_propagacao` | 1 / 6 |
| `sem-par-oferta-imagem` | `test_estrutural_nenhuma_tabela_do_portao_tem_o_par_oferta_id_imagem_id` | 1 / 5 |
| `quatro-conjuntos` | `test_tres_cores_que_definem_foto_rendem_quatro_conjuntos_...` | 1 / 7 |
| `eixo-variante-bloqueia` | `test_uma_foto_pendente_num_grupo_que_nao_e_o_primeiro_...` | 1 / 10 |
| `eixo-variante-geral` | `test_uma_foto_pendente_no_grupo_GERAL_...` | 1 / 8 |
| `fronteira-base-kit` | `test_aprovar_a_foto_do_produto_base_nao_afeta_o_conjunto_do_kit` | 1 / 6 |
| `eixos-nao-se-misturam` | `test_o_eixo_de_upload_e_o_de_revisao_nao_se_misturam_nas_duas_direcoes` | 1 / 5 |
| `alerta-conta-so-pendente` | `test_alerta_alto_em_imagem_pendente_conta_como_com_alerta_e_em_aprovada_nao` | 1 / 8 |
| `guarda-nullable` | `test_toda_fk_nullondelete_tem_coluna_nullable_explicito_com_isca` | 1 / 8 |
| `guarda-nomes-declarados` | `test_nenhum_nome_de_indice_ou_fk_declarado_passa_de_64_com_isca` | 1 / 50 |
| `guarda-nomes-64` | `test_os_nomes_padrao_do_laravel_tambem_cabem_em_64_e_toda_fk_e_nomeada_a_mao` | 1 / 28 |
| `guarda-sem-enum` | `test_nenhuma_migration_do_portao_usa_enum` | 1 / 4 |
| `guarda-sem-try` | `test_nenhuma_migration_do_portao_tem_try_catch` | 1 / 3 |
| `guarda-tokenizador` | `test_isca_o_tokenizador_tira_comentario_e_preserva_codigo` | 1 / 4 |
| `guarda-down-ordem` | `test_o_down_das_migrations_de_create_derruba_tabelas_filha_antes_da_mae` | 1 / 8 |
| `guarda-hash-indexado` | `test_grupo_chave_de_600_chars_nunca_entra_num_indice` | 1 / 15 |

**Os 16 rodados pelo harness: 16 PASSOU, 0 reprovado.** Nenhum `<automated>` chamou
`artisan test` direto.

---

## OS DOIS GATES QUE NASCERAM QUEBRADOS, E POR QUÊ IMPORTA

### 1. O gate que reprovava **pelo próprio conserto**

`guarda-sem-try` reprovou na primeira rodada **pelo motivo errado**: a palavra `try` aparece no
**docblock** das duas migrations, na frase em pt-BR que explica que try/catch **NÃO** é usado. É a
classe de falha registrada em `feedback_gate_que_reprova_pelo_proprio_conserto` (e a mesma do
`/disabled/` que casava com `disabled:opacity-40`).

⚠️ **O conserto barato seria tirar a frase do docblock** — perdendo justamente a explicação que a
próxima sessão precisa. O conserto certo foi ler o **CÓDIGO sem comentários**, pelo tokenizador do
PHP (`token_get_all`, descartando `T_COMMENT`/`T_DOC_COMMENT` e preservando as quebras de linha
para não colar statements). As sondas de **proibição** (enum, change, try, dropColumn, nomes,
índices, ordem do `down()`) leem daí; só as de **presença de docblock** leem o texto cru.

E essa sonda tem **gate próprio** (`guarda-tokenizador`), porque "a palavra não aparece" poderia
significar "o tokenizador comeu o arquivo inteiro": ele prova que o comentário sai **e que o código
fica**.

### 2. A sonda de pareamento `nullOnDelete` × `nullable` estava CEGA

O molde da Fase 165 pareia por `explode(';')` porque **lá** a FK e a coluna são o mesmo statement
(`foreignId('x')->nullable()->constrained()->nullOnDelete()`). Nas migrations **deste** plano a
coluna e a FK são statements **separados**
(`unsignedBigInteger('quem_id')->nullable();` + `foreign('quem_id', 'pubapim_quem_fk')->...->nullOnDelete();`),
e o pareamento tem de ser **por semântica do nome da coluna**.

A isca pegou um bug meu de regex: `->foreignId?\(` casa `foreignI` + `d` opcional, ou seja casa
`foreignI`/`foreignId` e **NÃO** `foreign(`. A sonda não achava a coluna de nenhuma FK deste plano.
Corrigido para `->foreign(?:Id)?\(`. São **4 iscas** agora: sem nullable / com nullable / forma
encadeada / encadeada sem nullable.

---

## Desvios do plano (todos registrados, nenhum "ajustado para passar")

### 1. [Rule 1 — Bug] `comAlerta()` contava imagem já decidida — RED→GREEN

**Achado em:** Task 3, primeira execução do teste.
**Medido:** `Failed asserting that 1 is identical to 0` — o `<behavior>` do plano exige que o mesmo
alerta `high` numa imagem **APROVADA** não conte, e `comAlerta()` contava.
**Conserto:** filtro `->where('estado', PENDENTE)`, com o **motivo** no docblock: quem olhou já
decidiu com o alerta na tela, e manter o contador aceso faria o painel pedir para sempre uma
revisão que já aconteceu. O alerta **continua gravado** (é histórico) — só deixa de contar, e há
asserção provando que não é apagado.
**Commits:** RED `27df50b9` (sem o conserto) → GREEN `f598bc0d`.

### 2. [Divergência de medição] o nome padrão mais longo é **56**, não 57

`186-03-PLAN.md:228` diz que `pub_aprovacao_imagem_alertas_aprovacao_imagem_id_foreign` = **57**.
**MEDIDO por `strlen`: 56.** O gate calcula por `strlen` e **TRAVA o 56**.

### 3. [Divergência de ambiente] `pub_fila_publicacao_itens` **EXISTE** no banco local

O briefing de execução afirmava que ela **não** existe e que havia **11** migrations pendentes.
**MEDIDO por `information_schema`:** `pub_filas_publicacao` e `pub_fila_publicacao_itens`
**EXISTEM** (migration `2026_10_10_100000` aplicada, **batch 39**), e há **9** pendentes, não 11.
Não afeta este plano (nenhuma FK minha aponta para elas), mas afeta quem for executar o 186-04.

### 4. [Gate vazio por construção, recusado] o par `dropForeign([...])` → `dropIndex`

O plano pedia o guarda item 5: "`dropForeign([` em ARRAY aparece antes de cada `dropIndex`". ⚠️ As
duas migrations deste plano **só CRIAM**, então o `down()` usa `dropIfExists` da tabela inteira —
que **subsume** o par (ele é receita de migration **ADITIVA**, molde `2026_10_04_120000`, não de
`create`). Resultado: **zero `dropIndex`**, e a asserção rodaria sobre zero casos — gate vazio por
construção.

➡️ Resolvido em **duas** partes, nenhuma delas afrouxando nada:
- a sonda de ordem **existe e tem 3 iscas** (ordem errada → acusa; ordem certa → passa;
  `dropForeign` por **NOME** em vez de ARRAY → acusa, porque o SQLite recusa essa forma), de modo
  que ela está **provada funcional** mesmo achando zero no código atual — e passa a valer de graça
  no dia em que uma migration aditiva entrar na série;
- o invariante que **VALE** para migration de `create` ganhou gate próprio
  (`guarda-down-ordem`): o `down()` derruba **TABELAS**, **filha antes da mãe**, na ordem exata, e
  **não** derruba coluna. Com isca de ordem invertida. A mutação #4 confirma que ele mata.

### 5. [Seguido literalmente] `tipo` sem default

O plano especifica default para `estado`, `fora`, `nova`, `origem`, `grupo_chave` e `tocado`, e
**não** para `tipo`. Mantido NOT NULL sem default (o projeto já faz isso em
`pub_fila_publicacao_itens.posicao`): a origem da foto é informação explícita, não presumível.

### 6. [Contagem de arquivos] 10, não 9

O briefing dizia "exatamente os 9 arquivos"; o `files_modified` do plano lista **10**
(2 migrations + 5 modelos + 2 testes + 1 manifesto) — e **10** foram entregues, nada além.

---

## Baselines medidas no fim (regra: acima da referência passa, abaixo é regressão)

| suíte | referência (186-01) | **medido agora** | leitura |
|---|---|---|---|
| PHP (`tests/Unit/Publicador` + `tests/Feature/Publicador`) | 2103 / 16.034 / **0** | **2130 / 16.270 / 0** | exatamente `2103 + 27` testes e `16034 + 236` asserções — **os meus**. Zero falhas. |
| JS | 4482 (ref. do harness) · 4539 (briefing) | **4777 / 4776 pass / 1 fail / 0 skipped** | acima da referência (o 186-02 acrescentou). **Registrado, não ajustado.** |

A **única** falha de JS é a **pré-existente** `tests/js/estrutura-grade-glide.test.js`
("Características secundárias nasce recolhido"), último commit a tocá-la `5aca9673` (Fase 87) —
**não corrigida**, conforme instrução.

Eu **não** toquei em JS neste plano.

---

## Território (conferido por RANGE, não por árvore)

`git show --name-only` dos **meus** commits (`8db7c43d 27bd6d3e 27df50b9 f598bc0d e8226690`)
devolve **exatamente** os 10 arquivos de `files_modified`. O filtro de território proibido
(`app/Services/Creative/`, `PublicadorCriativoAprovacaoService`, `Components/Publicador/Mesa/`,
`MlPublicacaoService`, `app/Support/Publicador/`, `AppLayout.jsx`, `app/Models/Bling*`,
`app/Services/Bling/`, `app/Services/Portal/`, `Components/Mlb/Publicador/aprovacao/`,
`publicador-vocabulario-novo.test.js`, `estrutura-meus-anuncios.test.js`,
`estrutura-grade-glide.test.js`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`,
`KitCriativosGrade.jsx`, `Publicador/Editor.jsx`, `ROADMAP.md`, `REQUIREMENTS-v27.md`,
`STATE.md`, `composer.json`, `composer.lock`) sai **VAZIO**.

- `vocabularioDoPortao.jsx` (186-02) foi lido **somente leitura**, para espelhar os 7 critérios e
  os 6 defeitos byte a byte (`:182-190` e `:193-200`).
- `app/Support/Publicador/` **não** foi tocado (nem subdiretório novo): `git diff` nele sai vazio
  no range. `ResolvedorGruposImagem` foi apenas **lido e chamado** pelo teste.
- **T-186-SC:** este plano **não instala pacote nenhum** — `composer.json`/`composer.lock` não
  aparecem no range.
- `ml_acervo_itens` **não** é encostada por nenhuma estrutura deste plano.
- `tests/Feature/CompanyPortfolioAccessTest.php` segue `??` (de 24/07, de outra sessão) — **deixado
  em paz**.
- Não houve deploy, push, acesso ao VPS nem leitura de produção.

---

## Declaração sobre o que NÃO é deste plano (para ninguém achar que faltou)

- **`pub_imagem_referencias` (DADO-15) é do plano 186-06**, e só é **ESCRITA nas Fases 192/194.** A
  relação "original de referência de uma imagem gerada" é **N:N** (uma gerada pode referenciar
  várias originais) e por isso **não** é coluna em `pub_aprovacao_imagens`. Está escrito no
  docblock da migration e do modelo.
- **`pub_aprovacao_ofertas` é do plano 186-04**, e **não** ganha `conjunto_id` (ver acima).
  `PubAprovacaoConjunto::ofertas()` **não existe** de propósito: a travessia certa é por
  `rascunho_id`.
- **O teste cruzado dos 7 critérios e 6 defeitos PHP × JS é do plano 186-09.** Não há enum
  compartilhado entre PHP e JS neste projeto — a sincronia é **manual**, é a convenção declarada no
  `CLAUDE.md`, e está escrita no docblock dos dois modelos.

## Conferência visual

⚠️ Nada aqui renderiza tela, e **eu não vi tela renderizada**. A conferência visual é do usuário,
**em produção, conta 459**.

## Self-Check: PASSED

Os 10 arquivos conferidos por `test -e`; os 5 commits conferidos por `git log --oneline`
(`8db7c43d`, `27bd6d3e`, `27df50b9`, `f598bc0d`, `e8226690`); as 5 tabelas e os 25 índices/FKs
conferidos por reconsulta a `information_schema` (nunca por stdout de migration); as 16 contagens
conferidas pelo harness.
