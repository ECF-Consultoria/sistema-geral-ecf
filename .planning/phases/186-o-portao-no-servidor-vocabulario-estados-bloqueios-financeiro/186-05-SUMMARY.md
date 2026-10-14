---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 05
subsystem: publicador
tags: [migration, schema, aprovacao, ajustes, historico, excecao, auditoria, tdd, mutacao]
requires:
  - "pub_aprovacao_ofertas (plano 186-04, batch 43 no local) — as 3 FKs CASCADE apontam para ela"
  - "pub_aprovacao_conjuntos (plano 186-03, batch 41) — a âncora do escopo `set`"
  - "pub_produtos, users (Fase 164) — as âncoras `nullOnDelete`"
  - "AndamentoDaOfertaService (plano 186-04) — a ÚNICA porta de transição; o gancho registrar() era nomeado para este plano"
  - "App\\Support\\Publicador\\RegraViolada (USADA, nunca alterada — TERR-03)"
  - "tests/gates/verificar-testes.sh (plano 186-01)"
  - "resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx (plano 186-02, SOMENTE LEITURA) — a fonte das 11 chaves pt-BR"
provides:
  - "pub_aprovacao_ajustes — o ajuste com escopo offer/set/product, com TRÊS âncoras nulas em vez de polimórfica"
  - "pub_aprovacao_eventos — o histórico APPEND-ONLY por oferta, 8 tipos, autor NULL = Sistema"
  - "pub_aprovacao_excecoes — a exceção autorizada: tipo pt-BR, motivo obrigatório, quem liberou, quando, em que versão"
  - "PubAprovacaoEvento::TIPOS — os 8 tipos com ícone + rótulo pt-BR + cor, espelhados do HK do protótipo"
  - "PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA = 10 e LIBERA = [resultado_negativo, margem_abaixo_do_minimo] (BLOQ-08)"
  - "PubAprovacaoAjuste::escopoValido()/ancoraValida()/destinoLegivel() — DADO-09 e VOC-06"
  - "HistoricoDaOfertaService — a ÚNICA porta de escrita do histórico, e a LIGAÇÃO do gancho do 186-04"
  - "AjustesDaOfertaService — pedir()/resolver()/abertosQueAfetam()/aplicarExcecao()"
  - "tests/gates/contagens-186-05.txt — 29 ids, BIJETIVO com os 27 métodos, toda contagem MEDIDA"
affects:
  - "Plano 186-07: lê PubAprovacaoExcecao::vigentesDa() para suprimir os bloqueios `resultado_negativo`/`margem_abaixo_do_minimo`, e abertosQueAfetam() para `ajuste_em_aberto`"
  - "Plano 186-08: AND-06 (saveReview: aguardando_aprovacao -> em_revisao_ecf) e a rotina das 8 mudanças que incrementa `versao`"
  - "Plano 186-09: o gate transversal de ausência do estado de espera — NÃO é substituído pelo daqui, e não o substitui"
  - "Fases 187-194: a camada A do gate de ausência varre a área por GLOB, então já cobre os arquivos que elas acrescentarem"
  - "⚠️ Plano 186-04: o gate `sem-conjunto-id` REPROVA por asserção OVER-BROAD (`oferta_id` em qualquer tabela do portão) — PERGUNTA DEVOLVIDA, arquivo alheio NÃO tocado"
tech-stack:
  added: []
  patterns:
    - "Tabela de decisão de schema no docblock ANTES do código (MIG-07)"
    - "Nome de índice/FK nomeado à mão, com os caracteres MEDIDOS por strlen (MIG-02)"
    - "migrate:rollback SEMPRE com --path, nunca --step: o banco local é compartilhado entre sessões"
    - "Tabela append-only declarada, sem rotina de limpeza e sem coluna de ocultar (T-186-18)"
    - "Ausência DELIBERADA escrita no docblock com a DATA e a RAZÃO da decisão do usuário"
    - "Gate de ausência em TRÊS camadas: sujeito por glob (área), vocabulário na CASA, e o SCHEMA"
    - "Sonda de proibição lê o CÓDIGO sem comentários, pelo tokenizador do PHP"
    - "Gate cuja contagem NÃO depende de quantos arquivos existem: glob exige >= 1 e coleta infratores numa asserção única"
    - "Nome de método de teste com sufixo do plano, para a contagem não depender de arquivos de outros planos"
key-files:
  created:
    - database/migrations/2026_10_15_100300_create_pub_aprovacao_ajustes_tables.php
    - app/Models/PubAprovacaoAjuste.php
    - app/Models/PubAprovacaoEvento.php
    - app/Models/PubAprovacaoExcecao.php
    - app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php
    - app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php
    - tests/Feature/Publicador/Aprovacao/AjustesEHistoricoTest.php
    - tests/Feature/Publicador/Aprovacao/ExcecaoAutorizadaTest.php
    - tests/gates/contagens-186-05.txt
  modified:
    - app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
decisions:
  - "O tipo da exceção é pt-BR (resultado_negativo / margem_abaixo_do_minimo), NÃO loss/margin: são 2 das 11 chaves de BLOQUEIO que o 186-02 traduziu — divergência do plano, declarada"
  - "A exceção TEM tipo e uma não libera a outra — divergência do protótipo, que usa um `!o.exc` único (:1093-1094)"
  - "escopo e tipo de evento FICAM com os literais do protótipo: são namespaces novos sem contraparte (a regra complementar do 186-02, vocabularioDoPortao.jsx:175-180)"
  - "product ganha de prod (DADO-09), e `prod` é RECUSADO — nunca um `||` aceitando as duas formas"
  - "Três âncoras nulas em vez de coluna polimórfica: a consulta de bloqueio dos 3 escopos precisa de ÍNDICE e de FK"
  - "A regra da âncora coerente é do MODELO, não CHECK de banco (o CHECK é enforçado no SQLite dos testes)"
  - "Transição de estado é evento `sys`; `adjust`/`reject`/`save` pertencem a quem narra o ATO — senão sai evento duplicado"
  - "O ajuste NÃO move ofertas fora de LIVE: `aprovado -> ajustes_solicitados` não existe na lista fechada, e não deve existir"
metrics:
  duration: "~3h"
  completed: 2026-10-15
---

# Fase 186 Plano 05: Ajustes com Escopo, Histórico e Exceção Autorizada — Summary

Ajuste com escopo `offer`/`set`/`product` que move o conjunto certo de ofertas, histórico
**append-only** por oferta com 8 tipos e autor "Sistema" como ator próprio, e a **exceção
autorizada aplicada direto** — com motivo obrigatório, quem liberou e quando, e **sem nenhum
estado de espera**, porque o controle é auditoria depois e não barreira antes.

## O que ficou de pé

| Entrega | Onde |
|---|---|
| 3 tabelas (`pub_aprovacao_ajustes`, `_eventos`, `_excecoes`) | `database/migrations/2026_10_15_100300_create_pub_aprovacao_ajustes_tables.php` |
| 3 modelos com as contagens **3 escopos / 8 tipos / 2 liberados** | `app/Models/PubAprovacaoAjuste.php`, `PubAprovacaoEvento.php`, `PubAprovacaoExcecao.php` |
| A única porta de escrita do histórico + a ligação do gancho | `app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php` |
| `pedir()` / `resolver()` / `abertosQueAfetam()` / `aplicarExcecao()` | `app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php` |
| 27 métodos de teste, 29 ids de gate, bijeção provada por script | `tests/gates/contagens-186-05.txt` |

## A TABELA DE DECISÃO DE SCHEMA

Está escrita no docblock da migration **antes do código** (MIG-07), em 7 seções. O resumo:

### `pub_aprovacao_ajustes` (DADO-09)

| coluna | tipo | regra / por quê |
|---|---|---|
| `escopo` | string(8) | `offer` \| `set` \| `product`. Maior = `product` = 7. Sem `enum` (MIG-04) |
| `oferta_id` | uBigInt **NULL** | FK `pubapaj_oferta_fk` → `pub_aprovacao_ofertas`, **CASCADE** |
| `conjunto_id` | uBigInt **NULL** | FK `pubapaj_conjunto_fk` → `pub_aprovacao_conjuntos`, **CASCADE** |
| `produto_id` | uBigInt **NULL** | FK `pubapaj_produto_fk` → `pub_produtos`, **SET NULL** |
| `item` | string(200) | o alvo nomeado ("Imagem #3 · Ambientada", "Preço Premium") |
| `destino` | string(120) | **legível, nunca chave** (VOC-06). Maior medido = **39 bytes / 38 caracteres** |
| `texto` | text | o pedido em palavras; MOD-01 só proíbe VAZIO |
| `autor_id` | uBigInt **NULL** | FK `pubapaj_autor_fk` → `users`, SET NULL |
| `aberto` | boolean default **true** | default repetido no `$attributes` (o Eloquent não lê o do banco) |
| `resolvido_por` / `resolvido_em` | uBigInt NULL / dateTime NULL | FK SET NULL; `dateTime`, nunca `timestamp()` |

⚠️ **Três âncoras nulas, não uma coluna polimórfica:** a consulta de bloqueio (`ajuste_em_aberto`)
junta os 3 escopos numa leitura **com índice** — é o `openC` do protótipo
(`design_handoff_publicador/Aprovacao.dc.html:1086`). Âncora polimórfica não aproveitaria índice
**nem teria FK** (nem CASCADE nem SET NULL).
⚠️ A regra "exatamente UMA âncora preenchida" é de `PubAprovacaoAjuste::ancoraValida()`, **não é
CHECK de banco** — o CHECK é enforçado no SQLite dos testes (mesma razão do `enum`).

### `pub_aprovacao_eventos` (DADO-10)

| coluna | tipo | regra / por quê |
|---|---|---|
| `oferta_id` | uBigInt **NOT NULL** | FK `pubapev_oferta_fk`, CASCADE. Evento sem oferta não é histórico de nada |
| `tipo` | string(8) | os 8 literais do `HK` (`:1386`). Maior = 6 |
| `texto` | string(500) | a frase REAL, com os valores dentro |
| `autor_id` | uBigInt **NULL** | FK SET NULL. ⚠️ **NULL = Sistema** |
| `ator_sistema` | boolean default false | **duas colunas e não uma**: `autor_id` nulo sozinho é ambíguo (o SET NULL também deixa nulo) |
| `versao` | uInt NULL | em que versão o fato aconteceu |
| `ocorrido_em` | dateTime **NOT NULL** | a data REAL do servidor (COL-05) |
| `timestamps` | | `created_at` é quando a LINHA nasceu; `ocorrido_em` é quando o FATO aconteceu |

### `pub_aprovacao_excecoes` (DADO-11)

| coluna | tipo | regra / por quê |
|---|---|---|
| `oferta_id` | uBigInt **NOT NULL** | FK `pubapex_oferta_fk`, CASCADE |
| `tipo` | string(**32**) | **pt-BR**. O maior tem **23** (`margem_abaixo_do_minimo`) — ver a divergência abaixo |
| `justificativa` | string(500) | **OBRIGATÓRIA, ≥ 10** depois do `trim()` |
| `liberada_por` | uBigInt NULL | FK SET NULL — **QUEM** liberou (T-186-16) |
| `liberada_em` | dateTime **NOT NULL** | **QUANDO** |
| `versao` | uInt **NOT NULL** | **em QUE versão** (BLOQ-05) |
| `ativa` | boolean default **true** | nasce **valendo**; default repetido no `$attributes` |

⚠️ **NENHUMA coluna decimal nas três tabelas, e isso não é descuido.** O modo de falhar de
`decimal(p,s)` aqui é **assimétrico** (erro **1264** no MySQL/MariaDB, grava **CALADO e truncado**
no SQLite dos testes) e a escala não é teórica — este projeto já mediu **+20738%**, que estoura
`decimal(7,3)`. O prejuízo e a margem que a exceção libera são **LIDOS** da composição (186-07); o
valor em R$ só aparece como **texto já formatado** em `eventos.texto`. Está escrito no §4 do
docblock, com o aviso de voltar lá se alguém acrescentar um percentual.

## OS NOMES DE ÍNDICE E FK, MEDIDOS POR `strlen`

**18 nomes declarados à mão** (9 índices + 9 FKs), **nenhum** `->constrained()`:

```
pubapaj_oferta_ix 17   pubapaj_conjunto_ix 19   pubapaj_produto_ix 18
pubapaj_autor_ix  16   pubapaj_resolvido_ix 20  pubapev_linha_ix   16
pubapev_tipo_ix   15   pubapev_autor_ix     16  pubapex_oferta_ix  17
pubapaj_oferta_fk 17   pubapaj_conjunto_fk  19  pubapaj_produto_fk 18
pubapaj_autor_fk  16   pubapaj_resolvido_fk 20  pubapev_oferta_fk  17
pubapev_autor_fk  16   pubapex_oferta_fk    17  pubapex_liberada_fk 19
```
➡️ **maior declarado = 20** (`pubapaj_resolvido_ix` / `_fk`), travado no gate.

**Nomes PADRÃO do Laravel** (os que estouram; nenhum é usado):
`..._ajustes_oferta_id_foreign` 39 · `..._ajustes_conjunto_id_foreign` **41** ·
`..._ajustes_produto_id_foreign` **40** · `..._ajustes_autor_id_foreign` **38** ·
`..._ajustes_resolvido_por_foreign` **43 ← o maior** · `..._eventos_oferta_id_foreign` 39 ·
`..._eventos_autor_id_foreign` **38** · `..._excecoes_oferta_id_foreign` 40 ·
`..._excecoes_liberada_por_foreign` 43.

⚠️ **QUATRO destes números estavam ERRADOS por 1 na minha primeira redação** (41/40/38/38 escritos
como 42/41/39/39) e foram corrigidos **pela medição `strlen`**, não por recontagem à mão. É o mesmo
erro de ±1 que o 186-03 mediu no plano dele, e é a razão pela qual o guarda **recalcula** em vez de
ler estes números.

**Conferido no `information_schema`** (nunca por stdout), em 15/10/2026:
- 9 índices + PRIMARY nas 3 tabelas, com as colunas e a ordem exatas;
- 9 FKs com as regras certas: 4 `CASCADE` (as 3 `oferta_id` + `conjunto_id`) e 5 `SET NULL`;
- `pubapex_liberada_fk` (19) aparece como índice de apoio criado pelo MariaDB — exatamente como o
  docblock previu (é a única FK sem índice declarado à mão, deliberadamente);
- **`*_foreign` encontrados: 0** (esperado 0).

## A PROVA `migrate → rollback → migrate`, COM RECONSULTA ENTRE OS PASSOS

`tasklist | grep mysqld` → `mysqld.exe PID 11008`, no ar. Local = **MariaDB 10.4.32**,
`DB=ecf_admin`.

| passo | comando | reconsulta à tabela `migrations` |
|---|---|---|
| 1 | `migrate --path=...100300...` | id=359 **batch 45**; as 3 tabelas **EXISTEM** |
| 2 | `migrate:rollback --path=...100300...` | a linha **AUSENTE**; as 3 tabelas **ausentes** (o `down()` rodou inteiro) |
| 3 | `migrate --path=...100300...` | id=360 **batch 45**; as 3 tabelas **EXISTEM** |

**As 9 pendentes de outras sessões seguem pendentes** (conferido depois): `2026_10_08_140000`,
`2026_10_08_150000` (×2), `2026_10_08_150100`, `2026_10_08_150200`, `2026_10_08_160000`,
`2026_10_09_180000`, `2026_10_10_090000`, `2026_10_10_210000`.

### ⚠️⚠️ INCIDENTE MEDIDO: `migrate:rollback --step=1` NÃO É SEGURO COM DUAS SESSÕES

Na primeira tentativa usei `--step=1` (a forma que o plano pedia) e **ela derrubou a migration do
plano 186-06**, que roda em paralelo — `2026_10_15_100400_create_pub_aprovacao_financeiro_tables`,
que estava no batch 44. Simetricamente, a minha (batch 45) também foi derrubada. **O banco local é
compartilhado entre as sessões**, e `--step` escolhe "o topo da tabela `migrations`" **no instante
em que roda** — e o topo pode ser de outra sessão.

- **Dano:** a migration do 186-06 ficou `Pending` e as tabelas dela saíram do banco local por alguns
  minutos. **Nada das waves 1-2 foi afetado** (batches 41-43 intactos, conferido).
- **Eu NÃO reapliquei a migration alheia** (arquivo de outro plano, e eles podiam estar no meio de
  uma edição). Na última conferência ela está **`Ran` no batch 46** — a própria sessão 186-06 a
  reaplicou.
- **A suíte não dependia disso**: os testes rodam em **SQLite** com `RefreshDatabase`, que aplica as
  migrations dos ARQUIVOS. O estrago ficou restrito ao artefato de prova do outro plano.
- **O conserto, que vale para as fases seguintes:** `migrate:rollback --path=<o seu arquivo>` — alvo
  explícito, corrida eliminada. Está escrito no §7/MIG-01 do docblock da migration.

## ⚠️ A AUSÊNCIA DELIBERADA DO ESTADO DE ESPERA (BLOQ-09) — COM ISCA E ANCORADA NA FORMA

**A decisão (usuário, 14/10/2026):** a exceção é aplicada **direto** por quem revisa — marca,
escreve o motivo (obrigatório), e fica gravado quem foi e quando. **Aprovação em dois passos foi
OFERECIDA e RECUSADA.** O controle é **auditoria DEPOIS, não barreira ANTES**, porque aprovar já
publica e não há desfazer.

**Declarada por escrito, com data e razão**, em `...100300...php` §0 e em `PubAprovacaoExcecao.php`
(e os dois dizem explicitamente "para que a próxima sessão não 'complete' o fluxo achando que
faltou", com "reabrir **com o usuário**").

### ⚠️⚠️ A SONDA DO PLANO NÃO SERVIA, E ISSO FOI MEDIDO

O plano pedia o par
`/(aguardand|pendent|em[_ ]?analise|solicit).{0,24}(autoriza|gestor|aprovad)/i` + o simétrico.
Medi em 15/10/2026, sobre a área nova, e achei **duas falhas independentes**:

1. **O par produz 10 ocorrências LEGÍTIMAS** quando o radical cobre a palavra pt-BR:
   `'Aguardando aprovação'` (o rótulo VOC-02 — em `PubAprovacaoOferta.php` e em 2 pontos de
   `andamentoDoPortao.jsx`), `AJUSTES_SOLICITADOS => [..., self::APROVADO]` (as transições do
   186-04, 3 ocorrências), `PubAprovacaoImagem::PENDENTE` ao lado de `APROVADA` (3), e o toast
   `'Aprovado — aguardando publicação'` de `vocabularioDoPortao.jsx`. **O domínio fala "aguardando
   aprovação" por toda parte, e está CORRETO** — é a OFERTA esperando a ECF conferir.
2. **O par escrito no plano ficava calado por ACIDENTE DE GRAFIA:** o radical `aprovad` **não casa a
   palavra `aprovação`/`aprovacao`** (c, não d). Ou seja: a sonda só não acusava aquelas 10 porque
   uma letra não bateu. **Sonda cujo silêncio depende de uma letra não está ancorada na forma — é
   sorte**, e é "gate vazio por construção" com outra roupa.

➡️ **O discriminador real é o SUJEITO:** o que não pode existir é **A EXCEÇÃO** esperando alguém
autorizar. Daí **três camadas**, cada uma com isca:

| camada | alcance | sonda | por que existe |
|---|---|---|---|
| **A** | a **área toda por GLOB** (`app/Services/Publicador/Aprovacao/*.php`, `app/Models/PubAprovacao*.php`, `.../aprovacao/*.jsx`, `database/migrations/2026_10_15_10*.php`) | sujeito `exce[cç][aã]o\|exc\|excecoes` × vocabulário de espera, nos dois sentidos, janela de 40 | é a que **vale para as Fases 187-194**: varre por glob, então já cobre o que elas acrescentarem |
| **B** | a **CASA da exceção** (`PubAprovacaoExcecao.php`, `AjustesDaOfertaService.php`) | o vocabulário de espera **inteiro**, sem precisar de sujeito | ali não há assunto "andamento" que justifique uma palavra de espera. É o que pega `aguardando_autorizacao`, `pendente_aprovacao`, `solicitar_autorizacao` e `aguardandoGestor`, que a camada A não casaria sozinha |
| **C** | o **SCHEMA**, sobre **TODAS** as colunas de `pub_aprovacao_excecoes` | nome de coluna com a forma de segundo passo | é a camada que de fato **BINDA**: sem coluna, o estado não tem onde morar |

**As iscas (16 casos de regex, nos dois sentidos — todos medidos):**
- **TÊM de casar:** `excecaoPendenteDeAprovacao`, `excecao_aguardando_autorizacao`, `exc_pendente`,
  `excecao_em_analise`, `excecaoAguardandoGestor`, `solicitarExcecao`, `aguardando_excecao` (camada
  A); `aguardando_autorizacao`, `pendente_aprovacao`, `solicitar_autorizacao`, `aguardandoGestor`,
  `exc_status`, `aprovada_por` (camada B).
- **NÃO podem casar:** `aplicarExcecao`, `liberada_por`, `justificativaValida`,
  `aguardando_aprovacao`, `Aguardando aprovação`, `AJUSTES_SOLICITADOS`,
  `PubAprovacaoImagem::PENDENTE`, `Exceção autorizada por`, `excecao->ativa`, `ativa`.
- ⚠️ **Uma das iscas reprovou de verdade e achou um buraco real:** `\bexc\b` **não casa
  `exc_pendente`** (o `_` é caractere de palavra, logo não há fronteira). Virou
  `(?<![a-z])exc(?![a-z])`, que casa `exc_pendente` e continua **não** casando `aplicarExcecao`.
- **Isca de conteúdo:** `aplicarExcecao` **tem de ser achado** no código da área, senão "nenhum
  infrator" poderia significar "li string vazia de tudo". **22 arquivos lidos.**
- ⚠️ **Lido SEM comentários pelo tokenizador do PHP** (e por recorte conservador no `.jsx`): os meus
  docblocks **contêm** a frase "EXCEÇÃO AGUARDANDO AUTORIZAÇÃO" ao explicar a ausência, e um gate
  que lesse o arquivo inteiro **reprovaria pelo próprio conserto**.
- ⚠️ **A contagem NÃO depende de quantos arquivos existem:** cada glob só exige **≥ 1** arquivo, e os
  infratores vão numa **asserção única**. Não escrevi gate do tipo que a fase já consertou na raiz.
- **A exempção é única, nomeada e GATEADA:** `AGUARDANDO_APROVACAO` (constante de ANDAMENTO do
  186-04, outro namespace) é recortada antes da camada B, e há isca exigindo que o recorte **tenha
  batido** — exempção que não encontra nada é exempção que esconde a sonda.

⚠️ **Este gate não substitui o transversal do 186-09, e não é substituído por ele** — declarado nos
docblocks dos dois lados: aquele mede a área inteira no fim da fase (inclusive telas e rotas); este
falha na hora em que alguém "completar" o fluxo.

## A EXCEÇÃO: MOTIVO OBRIGATÓRIO, E SÓ PREJUÍZO E MARGEM

- **Exceção sem motivo é RECUSADA:** 9 caracteres → `RegraViolada(BLOQ-09-EXCECAO-SEM-MOTIVO)`, e
  **nada é gravado** (nem exceção nem evento — contagem conferida antes e depois). "Só espaço"
  também (o `trim()` vem antes de contar, como no protótipo). 10 → aceita. **A fronteira é medida
  nos dois lados**, porque fronteira já tirou o bônus de alguém neste projeto sem mudança de código.
- **O efeito é IMEDIATO:** medido pela consulta de vigência, sem passo adicional.
- **A exceção aplicada aparece no histórico** com tipo `exc`, glifo `§`, rótulo "Exceção
  autorizada", **autor** ("Ricardo Gomes"), **carimbo de tempo** (`15/10/2026 17:05`) e **versão** —
  com isca de outro evento preenchido na mesma linha do tempo.
- **BLOQ-08, lista fechada:** `LIBERA = ['resultado_negativo', 'margem_abaixo_do_minimo']`,
  **espelhada do `BLOQUEIO_DO_PROTOTIPO` lido do `.jsx`**. O negativo roda sobre os **outros 9
  bloqueios pt-BR** (do MESMO namespace) e `aplicarExcecao()` recusa cada um com código próprio.
  ⚠️ Fiz questão de **não** usar as chaves inglesas como negativo: com `LIBERA` em pt-BR,
  `libera('photos')` seria `false` **por não existir**, não por ser recusado — gate vazio. As chaves
  inglesas têm asserção **separada**, que prova que **não existe `||` aceitando as duas formas**.

## DIVERGÊNCIAS, TODAS COM `arquivo:linha`

### 1. ⚠️ O `!o.exc` ÚNICO DO PROTÓTIPO (achado S7) — divergência deliberada

`design_handoff_publicador/Aprovacao.dc.html:1093-1094`:
```js
else if (f.result < 0 && !o.exc)        B.push({ k: 'loss',   … })
else if (f.margin < this.MIN && !o.exc) B.push({ k: 'margin', … })
```
**Um único `!o.exc` suprime `loss` E `margin`** — lá a exceção é um **flag**, não um registro
tipado (o `kind` de `submitException`, `:1203`, só monta a frase do histórico; não decide o que é
liberado).

➡️ **Aqui ela tem `tipo`, e uma não libera a outra.** Justificativa: **(a) BLOQ-08** — tipada, o
conjunto liberado é exatamente o autorizado; **(b) BLOQ-09** — liberar "resultado negativo" e
liberar "margem abaixo do mínimo" são **dois atos, com justificativas diferentes**, e **não há
desfazer**. Com o flag único, autorizar margem liberaria prejuízo de graça.
**Testado nas duas direções, com a outra oferta PREENCHIDA.** Nosso comportamento é **mais
restrito** que o do protótipo, nunca mais permissivo. A regra "o protótipo ganha" continua valendo
para a **FORMA** e os **RÓTULOS** (a frase é a dele, `:1142`, com o glifo `§` de `HK.exc`, `:1386`);
o que divergiu é a **granularidade de um registro de auditoria**, que não é forma nem rótulo.

### 2. `product` × `prod`

Protótipo: `'prod'` em `Aprovacao.dc.html:1187`, `:1188`, `:1189` (e lido em `:1086`).
README / **DADO-09**: `product`. ➡️ **`product` ganha**, e **`prod` é RECUSADO** por
`escopoValido()`, com teste. O mapa de conferência fica em
`PubAprovacaoAjuste::ESCOPO_DO_PROTOTIPO`, num lugar só.

### 3. ⚠️ O `tipo` da exceção em pt-BR — DIVERGÊNCIA DO PLANO 186-05

O plano escreveu `tipo ∈ {loss, margin}`; gravei `resultado_negativo` /
`margem_abaixo_do_minimo`. **Razão:** `loss` e `margin` **são 2 das 11 chaves de BLOQUEIO**, e
aquelas 11 foram traduzidas para pt-BR pela decisão do 186-02
(`resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx:155-168`). O `tipo` da
exceção **nomeia qual bloqueio ela libera** — é chave estrangeira **por valor** naquele namespace;
gravar `loss` recriaria o defeito que a wave 1 consertou (a mesma verdade em dois idiomas). A
tradução mora em **um lugar só** (`TIPO_DO_PROTOTIPO`) e há teste que **LÊ o `.jsx`**.
Consequência de schema: `tipo` virou `string(32)` (o maior tem 23), não `string(8)`.

⚠️ **E a assimetria é deliberada:** `escopo` e `eventos.tipo` **ficam** com os literais do protótipo,
porque são **namespaces novos sem contraparte** — é a regra complementar que o próprio 186-02
registra em `vocabularioDoPortao.jsx:175-180` ("inventar chave nova aqui seria churn sem nenhum bug
para resolver", sobre as 13 chaves de `CRIT`/`DEF`, que ficaram em inglês).

### 4. O ajuste não move oferta `aprovado`

`Aprovacao.dc.html:1198` move também as de status `'approved'`. Aqui
`aprovado → ajustes_solicitados` **não existe** em `PubAprovacaoOferta::TRANSICOES` (lista fechada
do 186-04) — e não deve existir: como aprovar já publica, `aprovado` é passagem para `publicando`,
não um estado em que a oferta fica parada. As ofertas fora de `LIVE` recebem **o evento** (o fato
aconteceu) e **não** mudam de andamento. O caminho de volta delas é a `versao` (186-08).

### 5. A resolução entra como `adjust`, não como `ok`

`Aprovacao.dc.html:1202` grava a resolução como `'ok'`. Aqui é `adjust`, porque é o mesmo assunto
do pedido e o filtro por tipo tem de mostrar a ida **e** a volta juntas; `ok` tornaria "resolveu um
ajuste" indistinguível de "aprovou uma foto".

### 6. `abertosQueAfetam()` cobre os 3 escopos; o protótipo esquece um

O `resolveComment` do protótipo (`:1202`) conta os abertos restantes olhando só `offer` e `set` —
**o escopo `prod` fica de fora**, então lá uma oferta poderia voltar para a fila com um ajuste de
cadastro ainda aberto. A nossa consulta cobre os três.

## AS LINHAS DO DIFF NO `AndamentoDaOfertaService` (ligado, não reescrito)

`git diff 1c16d9e9..HEAD -U0` dá **4 hunks**, e de **código executável** apenas **duas regiões**:

| hunk | linhas | o que é |
|---|---|---|
| `@@ -49,3 +49,6 @@` | `+49..+54` | docblock: o gancho passou de "no-op" para "LIGADO em 15/10/2026" |
| `@@ -79,0 +83,3 @@` | **`+83..+85`** | **o construtor** — `public function __construct(private HistoricoDaOfertaService $historico) {}` |
| `@@ -299,3 +305,4 @@` + `@@ -303,2 +310,2 @@` | `+305..+311` | docblock do gancho |
| `@@ -308 +315,4 @@` | `+315..+317` comentário, **`+318`** | **a única linha de corpo**: `$this->historico->registrarTransicao($oferta, $origem, $destino, $quem, $motivo);` |

**Gate com RANGE e duas iscas de diff** (`ligacao-e-so-ligacao`, 46 asserções): nenhuma linha
**removida** toca `TRANSICOES`, `DB::transaction`, `mover()` ou `aoAprovar()`; os dois ganchos
seguem com a MESMA assinatura; o gancho segue sendo chamado **dentro** da transação única.
⚠️ O mapa "qual tipo de evento e qual frase para cada destino" ficou em
`HistoricoDaOfertaService::registrarTransicao()` **de propósito** — pôr aquele `match` no arquivo do
186-04 seria reescrever a máquina de estado, que é o que o acordo evita.

## A BIJEÇÃO ids DO MANIFESTO ↔ MÉTODOS (conferida por script)

```
METODOS nos 2 arquivos: 27 (unicos: 27)
IDS no manifesto: 29
COMPOSTOS: excecao=5  ajustes-e-historico=6
METODOS SEM ID: 0 (nenhum)
METODOS SEM ID PROPRIO: 0 (nenhum)
```
29 = 27 individuais + 2 compostos (os dois `<verify>` do plano). Zero id duplicado, zero id órfão,
zero método sem id **próprio**. Teste sem id **nunca roda** — foi o sobrevivente da mutação do
186-03, e era lacuna real.

⚠️ **Os 4 guardas de migration levam `_guarda_05_` no nome, e não é estilo:** os nomes genéricos
**COLIDIAM** com os homónimos de `OfertaDoPortaoTest` (186-04) e de `FreteInformadoTest` (186-06,
**em paralelo**) — o `--filter` casava 2 e 3 métodos. O helper só aceita filtro por MÉTODO, então
nome compartilhado faria a contagem deste manifesto **depender de quantos arquivos de outros planos
existem**: exatamente a classe de gate frágil por construção que esta fase já consertou na raiz.

## A CAMPANHA DE MUTAÇÃO: 24/24 MORTAS

Disciplina: ocorrência **travada antes** (≠1 aborta a campanha), **linha conferida**, restauração
por **sha256** **dentro de `finally`** (o script do 186-04 crashou no meio e deixou arquivo mutado
no disco), e "árvore limpa" por `git diff HEAD -- <os meus arquivos>` — **nunca `git status`**, que
abortaria pelos `??` de outra sessão e pelos arquivos do 186-06.

| # | mutação | linha | gates que reprovaram |
|---|---|---|---|
| M01 | `LIBERA` passa a incluir `fotos_a_conferir` | 86 | `libera-so-dois`, `excecao` |
| M02 | `MINIMO_JUSTIFICATIVA` cai para 1 | 66 | `minimo-justificativa`, `excecao-nove-e-dez` |
| M03 | `tipo` volta à chave inglesa `loss` | 69 | `libera-so-dois` |
| M04 | `prod` passa a ser aceito ao lado de `product` | 62 | `escopo-e-ancora`, `texto-vazio-nao-envia` |
| M05 | o ajuste nasce FECHADO em memória | 105 | `default-nasce-valendo` |
| M06 | autor Sistema vira string vazia | 109 | `autor-sistema`, `excecao` |
| M07 | um NONO tipo de evento, sem rótulo | 105 | `oito-tipos-de-evento` |
| M08 | a linha do tempo ordena por `id` | 196 | `linha-do-tempo-em-ordem` |
| M09 | a data sai CRUA para o cliente | 214 | `linha-do-tempo-em-ordem` |
| M10 | tipo fora dos 8 passa a ser aceito | 77 | `tipo-fora-dos-oito` |
| M11 | escopo `set` alcança só a oferta pedida | 359 | `escopo-set`, `ajustes-e-historico` |
| M12 | resolver 1 de 2 devolve a oferta | 207 | `voltar-so-no-ultimo` |
| M13 | texto vazio passa a enviar | 120 | `texto-vazio-nao-envia` |
| M14 | a consulta perde o recorte do produto | 261 | `tres-escopos-com-recorte` |
| M15 | a exceção nasce INATIVA | 315 | `excecao-nove-e-dez` |
| M16 | a exceção não entra no histórico | 324 | `excecao-no-historico` |
| M17 | aparece o ESTADO DE ESPERA na casa da exceção | 104 | `sem-estado-de-espera` |
| M18 | o gancho do 186-04 é desligado | 318 | `ligacao-e-so-ligacao`, `evento-por-pedido-e-resolucao` |
| M19 | nome de índice acima de 64 | 351 | `guarda-05-nomes-64` |
| M20 | FK `nullOnDelete` sem `nullable()` | 341 | `guarda-05-nullable` |
| M21 | a migration passa a usar `enum()` | 330 | `guarda-05-sem-enum-sem-try` |
| M22 | o `down()` derruba uma tabela-MÃE alheia | 412 | `guarda-05-down-create` |
| M23 | aparece `->delete(` no histórico | 191 | `historico-append-only` |
| M24 | escrita DIRETA de `andamento` | 156 | `unica-porta-nos-ajustes` |

**0 sobreviventes.** Árvore limpa e sha256 conferido em todas as 24.
O `--autoteste` do helper foi reconferido: **14 casos, 0 divergência**.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Dois eventos `adjust` por pedido de ajuste**
- **Found during:** Task 3 (GREEN)
- **Issue:** `registrarTransicao()` mapeava `ajustes_solicitados => adjust`, e saíam **dois** eventos
  `adjust` por pedido: o do ato humano ("Solicitou ajuste em …") e o genérico da transição. A linha
  do tempo contava o mesmo fato duas vezes e o filtro por tipo dobrava a contagem de ajustes.
- **Fix:** conserto na **raiz**, não no gate: `adjust`/`reject`/`save` são atos de PESSOA e
  pertencem a quem narra o ato; transição de estado é fato de `sys`. É o que o protótipo faz
  (`:1198` gera **um** evento `adjust`, e a mudança de `status` não gera evento separado).
- **Files modified:** `app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php`
- **Commit:** `153ba3d8`

**2. [Rule 1 - Bug] A sonda da única porta acusava uma COMPARAÇÃO legítima**
- **Found during:** Task 3 (GREEN)
- **Issue:** `assertStringNotContainsString('->andamento =')` reprovava
  `$afetada->andamento === PubAprovacaoOferta::AJUSTES_SOLICITADOS`, porque `===` contém `=` como
  substring. Sonda que acusa `===` como se fosse `=` empurra o executor seguinte a **reescrever
  código correto — ou a apagar o gate**.
- **Fix:** regex `/->andamento\s*=(?!=)/` **com isca nos quatro casos** (atribuição com e sem
  espaço têm de casar; `===` e `!==` não podem). **Não foi afrouxada:** a atribuição de verdade
  continua sendo pega, e a mutação **M24** prova isso.
- **Files modified:** `tests/Feature/Publicador/Aprovacao/AjustesEHistoricoTest.php`
- **Commit:** `153ba3d8`

**3. [Rule 1 - Bug] `\bexc\b` não casava `exc_pendente`**
- **Found during:** Task 3 (RED) — **pela própria isca do gate**
- **Issue:** `_` é caractere de palavra, então não há fronteira entre `exc` e `_`. A camada A do gate
  de ausência estaria **cega** para `exc_status`, `exc_pendente` e afins.
- **Fix:** `(?<![a-z])exc(?![a-z])`, que casa `exc_pendente` e continua **não** casando
  `aplicarExcecao`. 16 iscas de regex conferidas nos dois sentidos.
- **Commit:** `153ba3d8`

**4. [Rule 2 - Correção de medição] Quatro números do docblock errados por 1**
- **Found during:** Task 1
- **Issue:** os nomes padrão do Laravel `..._ajustes_conjunto_id_foreign`,
  `..._ajustes_produto_id_foreign`, `..._ajustes_autor_id_foreign` e `..._eventos_autor_id_foreign`
  estavam escritos como 42/41/39/39 e valem **41/40/38/38**.
- **Fix:** corrigidos pela medição `strlen`, e o guarda recalcula em vez de ler o número.
- **Commit:** `776d8988`

**5. [Rule 3 - Blocking] `migrate:rollback --step=1` derrubou a migration do plano paralelo**
- Ver a seção do incidente acima. Troquei a forma por `--path` e documentei no docblock. **Não
  reapliquei o arquivo alheio**; a sessão 186-06 o reaplicou (batch 46).

### Desvios de redação do plano (declarados, não silenciosos)

- **`tipo` da exceção em pt-BR** e `string(32)` em vez de `string(8)` — divergência 3 acima.
- **`escopoValido()` virou dois métodos:** `escopoValido()` (a chave) e `ancoraValida()` (a linha). O
  plano atribuía as duas perguntas ao mesmo nome; são perguntas distintas e cada uma tem gate.
- **O gate de ausência ficou em três camadas**, não no par de regex do plano — porque o par do plano
  não distingue o legítimo do proibido (medido: 10 ocorrências legítimas).

## ⚠️ PERGUNTAS DEVOLVIDAS (não mexi em arquivo alheio)

### 1. O gate `sem-conjunto-id` do plano 186-04 reprova — e a asserção dele é OVER-BROAD

`tests/Feature/Publicador/Aprovacao/OfertaDoPortaoTest.php:399`:
```php
$this->assertSame([], $comOferta, 'apareceu tabela do portão com `oferta_id` — …');
```
Ele afirma que **nenhuma** tabela do portão tem `oferta_id`. Agora reprova com **5** tabelas:
`pub_aprovacao_ajustes`, `pub_aprovacao_eventos`, `pub_aprovacao_excecoes` (minhas) e
`pub_aprovacao_composicoes`, `pub_aprovacao_fretes` (**do 186-06, em paralelo**).

**Medi o invariante S2 de verdade** no schema vivo (11 tabelas do portão, 15/10/2026):

| tabela | `oferta_id` | `imagem_id` |
|---|---|---|
| `pub_aprovacao_ajustes` / `_eventos` / `_excecoes` / `_composicoes` / `_fretes` | SIM | — |
| `pub_aprovacao_imagens` | — | SIM |
| `pub_aprovacao_conjuntos` / `_defeitos` / `_fidelidade` / `_imagem_alertas` / `_ofertas` | — | — |

➡️ **Tabelas com o PAR `(oferta_id, imagem_id)`: NENHUMA.** O achado S2 é sobre o **par** — é o par
que deixaria as duas ofertas divergirem na aprovação de uma foto. `oferta_id` **sozinho** é legítimo
e é exigido por DADO-10 (histórico **por oferta**) e por DADO-11 (a exceção é por oferta: o meu
próprio teste prova que ela **não** atravessa para a irmã). E o ajuste de foto usa `conjunto_id`,
**não** `oferta_id` — honrando S2 exatamente.

A asserção do 186-04 era **vazia por construção** quando foi escrita (não existia tabela-filha
nenhuma) e passou a ser falso positivo no instante em que **qualquer** plano criou uma filha
por-oferta. **Não toquei naquele arquivo.** Sugestão (decisão do orquestrador): trocar a asserção de
"nenhuma tabela tem `oferta_id`" para "nenhuma tabela tem **o par** `oferta_id` + `imagem_id`" —
que é o invariante que o próprio nome do método (`..._tem_oferta_id_com_imagem_id`) já descreve.

### 2. As outras duas falhas da suíte são do 186-06, em pleno TDD

- `FreteInformadoTest > os defaults do modelo e os defaults das colunas…` (`:654`) — `vigente` nasce
  `null` em memória: é o `$attributes` de `PubAprovacaoFrete` ainda não escrito (RED deles).
- `VinculoDuravelDaOriginalTest > todo metodo deste plano tem id no manifesto…` (`:578`) — método
  deles sem id no manifesto **deles** (`test_nenhuma_relacao_destes_modelos_colide_com_propriedade_do_eloquent`).

Nenhuma das duas toca código meu. **Não mexi.**

## Os commits, e o escopo de arquivos conferido

Commit de **partida**, capturado na primeira ação: **`1c16d9e9`**.

| commit | o quê |
|---|---|
| `776d8988` | **Task 1** — a migration (3 tabelas) e a ausência deliberada no docblock |
| `d5b5fe0f` | **Task 2 RED** — 9 métodos sobre os 3 modelos e o histórico |
| `cd32e677` | **Task 2 GREEN** — os 3 modelos, o `HistoricoDaOfertaService` e o manifesto (10 ids) |
| `7653d361` | **Task 3 RED** — ajustes, exceção aplicada e o gate de ausência em 3 camadas |
| `153ba3d8` | **Task 3 GREEN** — o `AjustesDaOfertaService` e o gancho do 186-04 **ligado** |
| `5bcdb6b4` | os 19 ids da Task 3 no manifesto, com as contagens medidas |

`git show --name-only` dos **seis** commits dá **exatamente os 10 arquivos do plano** (9 criados + o
`AndamentoDaOfertaService` modificado) — nada a mais.

⚠️ **O `git diff 1c16d9e9..HEAD --name-only` cru devolve 14 arquivos, e isso é esperado, não
desvio:** a branch é **compartilhada** e o plano **186-06 commitou 3 vezes no meio** dos meus
commits (`26520d6f`, `cd4e260c`, `2c942992`), trazendo `...100400...`, `...100500...`,
`FreteInformadoTest.php` e `VinculoDuravelDaOriginalTest.php`. **Nenhum deles aparece em commit
meu** — o recorte certo com branch compartilhada é por COMMIT, não pelo range. Registro isso aqui
porque a verificação do plano pedia "exatamente os 8 arquivos", e o número cru do range **nunca**
poderia bater numa wave paralela.

**Nenhuma deleção:** `git diff --diff-filter=D` entre cada par de commits meus sai **vazio**.

## Baselines

| suíte | referência do briefing | medido agora | leitura |
|---|---|---|---|
| **JS** | 4777 / 4776 pass / 1 fail | **4777 / 4776 pass / 1 fail / 0 skipped** | **idêntico**. A falha é a pré-existente da Fase 87 (`estrutura-grade-glide`, "Características secundárias nasce recolhido") — **não corrigida**, como mandado. Não toquei em JS |
| **PHP** | 2163 / 16.808 / 0 | **2216 / 17.901 / 3** | +53 testes e +1.093 asserções (os meus 27 + os do 186-06, que roda em paralelo e sobe a contagem). **As 3 falhas estão diagnosticadas acima: 1 é gate over-broad do 186-04 disparado pelos DOIS planos da wave 3, e 2 são RED em andamento do 186-06. Nenhuma é regressão deste plano** |

⚠️ Registrei a divergência de contagem em vez de "ajustar" qualquer referência.

## Known Stubs

Nenhum. Todas as colunas, constantes e métodos entregues têm leitura ou escrita real e teste.
O que **não** existe aqui é ausência **deliberada e declarada** (o estado de espera da exceção, o
`conjunto_id` na oferta, coluna de ocultar evento), não stub.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. As 6 ameaças com disposição `mitigate`
foram cobertas: T-186-16 (`liberada_por` + `liberada_em` + `versao` na mesma escrita + evento `exc`
append-only + teste de recusa sem motivo), T-186-17 (`LIBERA` fechada + negativo nos 9 outros
bloqueios pt-BR, com fixture preenchida), T-186-18 (append-only declarado, sem rotina de limpeza,
sem coluna de ocultar, gate com isca), T-186-19 (texto e item são string renderizada pelo React;
nenhum `dangerouslySetInnerHTML` — o gate amplo é do 186-09), T-186-20 (a consulta dos 3 escopos é
ancorada nos ids da oferta, com a outra empresa PREENCHIDA nos 3 escopos e nas 2 direções), T-186-SC
(**nenhum pacote instalado** — `git diff 1c16d9e9..HEAD -- composer.json composer.lock
package.json package-lock.json` sai **VAZIO**).

## Self-Check: PASSED

- Os **10** arquivos declarados existem no disco (conferido um a um).
- Os **6** commits existem (`git log --oneline --all | grep`).
- Os **2** gates do plano passam pelo helper: `excecao` 5/152, `ajustes-e-historico` 6/128.
- `--autoteste` do helper: **14 casos, 0 divergência**.
- Mutação **24/24 mortas**, árvore limpa, sha256 conferido.
- Bijeção manifesto ↔ métodos: **0 órfãos nos dois sentidos**.
- `information_schema`: 9 índices, 9 FKs, **0** nomes padrão do Laravel.
- `migrate → rollback → migrate` provado com reconsulta; **9 pendentes alheias intactas**.
- T-186-SC: diff de `composer.*`/`package*.json` por range sai **VAZIO** (com isca do diff).
- ⚠️ **3 falhas na suíte PHP, todas diagnosticadas e nenhuma deste plano** — ver "Perguntas
  devolvidas".

## STATE.md / ROADMAP.md / REQUIREMENTS-v27.md

**Não tocados, por instrução explícita do briefing** (e `state.advance-plan` não foi executado). A
atualização de posição e de requisitos fica para o orquestrador.

## Verificação visual

⚠️ **Não vi tela renderizada e não afirmo nada sobre render.** Este plano é servidor: migration,
modelos e serviços. A conferência visual é do usuário, em produção, na conta 459.
