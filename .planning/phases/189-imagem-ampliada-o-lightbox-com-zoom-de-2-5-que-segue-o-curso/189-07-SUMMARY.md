---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 07
subsystem: Publicador — Aprovação (painel de revisão simples / lightbox)
tags: [publicador, aprovacao, lightbox, escrita, versao-esperada, contexto-de-empilhamento, tdd]
requires:
  - "189-06 — `contextoDoLightbox`, `indiceNoContexto`, `aoFecharOLightbox`, `fecharLightboxDevolvendoFoco`, `FOCO_NO_BOTAO_DE_ABRIR`, `idPositivoDoPainel`"
  - "189-05 — `LateralDoLightbox` e `podeAgirNaFoto` (a regra ÚNICA de quem pode agir)"
  - "189-03 — `LightboxDaImagem` (portal próprio, `z-[41]`), `moverNoLightbox`, `indiceNoLightbox`"
  - "188-07 — `escreverNoPainel` (helper ÚNICO), `RaizDoPainel`, `resolverAtalhoDoPainel`, slot `modais`"
  - "188-08 — `escritasDaColuna` (oferta e versão POR PARÂMETRO) e `ROTA_APROVAR_IMAGEM`"
  - "188-12 — `ModalDeReprovarImagem` e o `onSubmeter` da página"
  - "188-05 — as rotas `…/aprovacao/{oferta}/imagem/{imagem}/aprovar` e `…/reprovar`"
provides:
  - "`acaoDoLightbox({tipo, contexto, indice, escrever})` — a oferta/versão/foto lidas DO CONTEXTO"
  - "`depoisDaAcaoNoLightbox({contexto, painelAtual})` → `{fechar, recarregarNaOferta}` — o D2"
  - "`propsDaLateralDoLightbox(…)` — a ÚNICA fonte das props da lateral; os handlers NASCEM funções"
  - "`TIPOS_DA_ACAO_DO_LIGHTBOX` — lista FECHADA nos dois tipos"
  - "o ramo do lightbox no `onSubmeter` do modal de reprovar, com a galeria e a versão DO CONTEXTO"
  - "`tests/js/publicador-lightbox-acoes-189.test.js` (25 casos) e `tests/gates/contagens-189-07.txt`"
affects:
  - "resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx (+222, −0)"
  - "resources/js/Pages/Mlb/Publicador/Aprovacao.jsx (+203, −27)"
tech-stack:
  added: []
  patterns:
    - "função PURA exportada + render REAL da peça com as props que ela devolve (o portal não é desenhado pelo renderizador de SERVIDOR: o teste do composto passaria por OMISSÃO)"
    - "prova de contexto de empilhamento por ÁRVORE DE ANCESTRAIS, com isca nas duas direções"
    - "ramo separado em vez de derivação compartilhada, quando o gate alheio cobra o caminho antigo byte a byte"
    - "veto POR SUBCONJUNTO em achado herdado (acusa o próximo, fica verde quando o herdado for consertado)"
key-files:
  created:
    - tests/js/publicador-lightbox-acoes-189.test.js
    - tests/gates/contagens-189-07.txt
    - .planning/phases/189-.../deferred-items.md
  modified:
    - resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
decisions:
  - "D2 da PERGUNTA-189-D: DEFAULT aplicado e DECLARADO (a pergunta segue ABERTA) — ação bem-sucedida com a oferta do painel diferente FECHA o lightbox; `recarregarNaOferta` sai `null` SEMPRE"
  - "reuso do CAMINHO (`escritasDaColuna` → `escreverNoPainel`) em vez de remontar rota/parâmetros: remontar seria a SEGUNDA cópia da mesma verdade (régua §2)"
  - "o caminho do PAINEL no modal de reprovar fica BYTE A BYTE: o gate do 188-12 cobra o literal e ESTÁ CERTO (o plano manda 'sem mudar nada para quem abre pelo painel')"
  - "`ModalDeReprovarImagem.jsx` NÃO foi tocado: medido que ele já recebe a imagem por prop e não escreve"
metrics:
  duration: "~4h20 (20/10/2026)"
  completed: 2026-10-20
  tasks: 2
  commits: 2
  files_changed: 4
---

# Fase 189 Plano 07: as ações da foto dentro do lightbox, com o CONTEXTO dele — Summary

**"Aprovar foto" e "Reprovar" passam a escrever com a oferta, a VERSÃO e a foto que o lightbox
capturou na ABERTURA** (PERGUNTA-189-B (b), decisão do usuário de 19/10/2026), reusando o caminho de
escrita que já existia (`escritasDaColuna` → `escreverNoPainel`, `versao_esperada` sempre, 422 nunca
engolido) — e o modal de reprovar abre **por cima** do lightbox, provado pela **árvore de
ancestrais**, não por número de `z-index`.

---

## §1 — A PARTIDA, e as DUAS escritas concorrentes que entraram em volta dela

**PARTIDA (capturada na PRIMEIRA ação):**

```
c23bab8de78ac0be9e2138825b59e7443aa818bf   c23bab8d
docs(189-06): a escrita concorrente se concretizou — o commit alheio entre os meus
```

⚠️⚠️ **A ESCRITA CONCORRENTE SE CONCRETIZOU DE NOVO — e desta vez por TRÊS vias.** O histórico de
`c23bab8d..HEAD`, medido:

```
dc8580c2  docs(coordenacao): a Fase 193 esta planejada — tres pontos a combinar com o Agente Publicador   <= ALHEIO
42fc4752  feat(189-07): as acoes da foto com o CONTEXTO do lightbox — …                                   <= MEU (tarefa 1)
1253cc86  feat(quick-261020-nsz): AG-01 do agente — configuracao, controle e execucao por loja            <= ALHEIO
95fb404f  test(189-07): o furo que o DESENHO DA CAMPANHA DE MUTACAO achou — …                             <= MEU (tarefa 2)
```

⚠️ **A prova de que `dc8580c2` e `1253cc86` não são meus é o ESCOPO DO ASSUNTO, nunca o autor** — as
três máquinas desta árvore commitam com o MESMO `user.name` (`MB.ECF-100376`), que é por isso que
conferir autor **não discriminaria nada** (a face 3 do limite).

### §1.1 — ⚠️ E a escrita concorrente **REPROVOU UM GATE DA 188 NO MEIO DA MINHA WAVE**

A terceira via foi a pior, porque ela entrou pela **ÁRVORE**, não pelo histórico: a outra sessão criou
`database/migrations/2026_10_20_100000_create_pub_agente_tables.php` às **17:09:56** (AG-01 do Agente
ECF Publicador, docblock citando `.planning/quick/261020-m6m-…/AG-00-BASELINE.md`). O gate
`publicador-painel-casca-188.test.js:1079` mede
`git status --porcelain -- database/migrations` — **a ÁRVORE, não território** — e passou a reprovar
com *"migration apareceu — esta fase NÃO tem migration"*.

| momento | `188-07 casca-do-painel` |
|---|---|
| 17:0x, antes do arquivo alheio existir | **45 / 45 / 0 / 0**, `exit=0` |
| 17:1x, com o arquivo alheio UNTRACKED | **45 / 44 / 1 / 0**, `exit=1` |
| depois de a outra sessão commitá-lo (`1253cc86`) | **45 / 45 / 0 / 0**, `exit=0` |

➡️ **Não é regressão de ninguém.** Esta fase **não tem migration** e eu não criei nenhuma.
**Não consertei o gate alheio** (fase em produção, fora do território desta fase) — está em
`deferred-items.md` como **D-189-07-B**, ao lado dos dois casos de `preco-188`/`rodape-188` que a
189-06 já tinha achado. ⚠️ **É a MESMA família**, agora com três ocorrências.

### §1.2 — O território, por ESCOPO DO ASSUNTO, com as TRÊS FACES declaradas

**PI(189-07)**, por `pi-189-07.sh` (escrito pela ferramenta de escrita), **colada**:

```
=== (1) test -e EM CADA ALVO (anti-fantasma: alvo inexistente REPROVA) ===
  alvos: 43  |  fantasmas: 0

=== (2) OS COMMITS DO ESCOPO (recorte no %s, nao na mensagem inteira) ===
42fc4752 feat(189-07): as acoes da foto com o CONTEXTO do lightbox — e o ramo separado que o gate do 188-12 exigiu

=== (3) PASSADA 1: git show --stat por commit ===
  resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
  resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
  tests/gates/contagens-189-07.txt
  tests/js/publicador-lightbox-acoes-189.test.js

=== (4) PASSADA 2 (independente): --name-only de uma vez ===
  OS DOIS CONJUNTOS SAO IDENTICOS (4 caminhos)

=== (5) INTERSECAO com a lista de alvos (TEM de ser VAZIA) ===
  INTERSECAO VAZIA

=== (6) ISCA (a): o MESMO script com um escopo que SABIDAMENTE tocou um alvo ===
  ACUSA   (188-07) tocou …/PainelDeRevisao.jsx            -> a sonda ENXERGA
  ACUSA   (188-08) tocou tests/gates/contagens-188-08.txt -> a sonda ENXERGA
  ACUSA   (188-12) tocou …/ModalDeReprovarImagem.jsx      -> a sonda ENXERGA

=== (7) ISCA (b): o escopo (189-07) NAO casa commit de assunto alheio ===
  commits do escopo (189-07) com assunto de OUTRA sessao/fase: 0  (0 = o recorte nao vaza)
```

⚠️ **A PI foi rodada ANTES do segundo commit** (`95fb404f`), e por isso ela lista UM commit. O segundo
toca **exatamente um** dos mesmos quatro caminhos (`tests/js/publicador-lightbox-acoes-189.test.js`),
e a interseção com a lista de 43 alvos continua **VAZIA** — conferido por `git show --name-only`.

**AS TRÊS FACES DO LIMITE, declaradas:**
1. **SOBRE-captura** — `git log --grep` lê a **MENSAGEM INTEIRA**. ➡️ o recorte é no **`%s`**
   (`git log --format='%h %s' | grep -F '(189-07)'`), e as mensagens dos meus dois commits citam
   `(188-12)`, `189-05`, `189-06` e `188-05` no CORPO sem contaminar o recorte.
2. **SUB-captura** — commit que toca meus arquivos **sem** o escopo no assunto. ➡️ **os vizinhos
   foram medidos À MÃO** (§1, `git show --name-only` de cada commit de `c23bab8d..HEAD`): `dc8580c2`
   toca **1** arquivo (`.planning/coordenacao/…`) e `1253cc86` toca **11**
   (`.planning/STATE.md`, a quick `261020-nsz`, 3 `app/Models/PubAgente*`, 3
   `app/Services/Publicador/Agente/*`, a migration e 1 teste). ⚠️⚠️ **`1253cc86` TOCOU
   `.planning/STATE.md`, `app/` E `database/migrations/` — três itens da minha lista de alvos** — e a
   PI continua VAZIA porque ela mede **os commits do MEU escopo**, nunca a árvore.
3. **O AUTOR NÃO DISCRIMINA** — `MB.ECF-100376` nas três máquinas.

⚠️ **Antes de CADA edição** eu conferi `git diff HEAD -- <arquivo>` nos meus alvos: **saída vazia em
todas**, nenhuma pegou WIP alheio. Commits por **pathspec**, mensagem por **`-F <arquivo>`** (heredoc
falha por CRLF nesta máquina), **sem `--amend`**, **sem `git add -A`**.

---

## §2 — AS BASELINES, RE-MEDIDAS POR MIM (e o que não é medível)

### §2.1 — JS

| momento | medida | `exit` |
|---|---|---|
| **PARTIDA** (`c23bab8d`) | **5980 / 5979 pass / 1 fail / 0 skipped** | **0** |
| **FIM** (`95fb404f`, depois do 2º `npm run build`) | **6005 / 6004 pass / 1 fail / 0 skipped** | **0** |

➡️ **Δ = +25 testes**, que são **exatamente** os 25 casos do gate novo. **A soma fecha.**

⚠️ A **única** falha é a **pré-existente da Fase 87**, e ela foi **atribuída por execução ISOLADA**:

```
tests/js/estrutura-grade-glide.test.js   ->  # tests 19  # pass 18  # fail 1
not ok 17 - Características secundárias nasce recolhido (é o grupo que mais infla)
```

**Não corrigida, não contada como regressão.**

⚠️ **Houve uma MEDIÇÃO INTERMEDIÁRIA com 2 falhas** (6005 / 6003 / 2), e a segunda era o gate
`188_07` reprovado pela **migration alheia untracked** (§1.1). Ela desapareceu sozinha quando a outra
sessão commitou. **Registrada em vez de escondida.**

### §2.2 — PHP: pelo **RECORTE**, porque a suíte inteira **não é medível nesta máquina**

| momento | recorte `tests/Unit/Publicador` + `tests/Feature/Publicador` |
|---|---|
| **PARTIDA** | **2595 passed / 23.878 assertions / 0 falhas** (744,19 s), `exit=0` |
| **FIM** | **2609 passed / 23.931 assertions / 0 falhas** (981,71 s), `exit=0` |

⚠️⚠️ **O Δ de +14 testes NÃO É MEU — este plano não cria teste PHP nenhum — e está ATRIBUÍDO.** Ele é
o arquivo que a **outra sessão** acrescentou DENTRO do meu recorte no commit alheio `1253cc86`:

```
1253cc86  feat(quick-261020-nsz): AG-01 do agente — configuracao, controle e execucao por loja
          tests/Feature/Publicador/Agente/ConfiguracaoEExecucaoDoAgenteTest.php
```

**Medido:** aquele arquivo tem **14** métodos `test_*`, e `2595 + 14 = 2609`. **A soma fecha.**
(O diretório `tests/Feature/Publicador/Agente/` tem **22** testes no total; os outros **8**, de
`EntradaDoPreparoDoAgenteTest.php`, já estavam na baseline da PARTIDA.)
⚠️ **ZERO falhas nas duas pontas**, e **nenhuma falha nova dentro do recorte** — *"qualquer OUTRA
falha no recorte é DA FASE"*, e não houve nenhuma.

⚠️⚠️ **A SUÍTE PHP COMPLETA NÃO É MEDÍVEL NESTA MÁQUINA**, e isso é dito em vez de contornado:
`artisan test` **aborta com Fatal de memória** em `Illuminate\Http\Testing\FileFactory.php:87` a ~6%
de 10.653 testes, e **`-d memory_limit=-1` NÃO resolve** (o estouro é em processo **FILHO**).
➡️ **Medi pelo RECORTE, e digo isso.**

⚠️ **Falhas conhecidas FORA do recorte, nenhuma desta fase** (não rodadas, registradas como herança):
`CalcularFaixaTest` (9) · `CompanyServiceTypeTest::test_service_type_aceita_polo` (1) · `Phase39` (2) ·
os dois de `tests/Feature/Publicador/CockpitDaContaTest.php`, que **OSCILAM COM A HORA DO DIA**
(provado por waves anteriores) · e a corrida de `storage/framework/testing` entre suítes simultâneas.
⚠️ **Não criei `REF_PHP_FALHAS`.**

⚠️ **Asserções do PHP NÃO são invariante** — comparei **TESTES** e **FALHAS**.

### §2.3 — As contagens alheias, conferidas IGUAIS (nunca subidas)

| gate | manifesto declara | medido no FIM | `exit` |
|---|---|---|---|
| `188-07 casca-do-painel` | `45,41` | **45 / 45 / 0 / 0** | 0 |
| `188-08 coluna-de-fotos` | `32,28` | **32 / 32 / 0 / 0** | 0 |
| `188-12 modal-de-reprovar` | `19,17` | **19 / 19 / 0 / 0** | 0 |
| `188-12 modal-de-frete` | `22,18` | **22 / 22 / 0 / 0** | 0 |
| `188-12 aviso-de-versao` | `12,10` | **12 / 12 / 0 / 0** | 0 |
| `187-06 filtros-render` | `29,19` | **29 / 29 / 0 / 0** | 0 |
| `189-03 lightbox-casca` | — | **27 / 27 / 0 / 0** | 0 |
| `189-04 camadas-e-css` | — | **16 / 16 / 0 / 0** | 0 |
| `189-05 lateral-do-lightbox` | — | **27 / 27 / 0 / 0** | 0 |
| `189-06 lightbox-no-painel` | `22,22` | **22 / 22 / 0 / 0** | 0 |

⚠️ **`casca-do-painel` e `filtros-render` passaram pelos DOIS REGIMES durante a wave** (41/40/1 e
19/18/1 **antes** do `npm run build`, 45/45/0 e 29/29/0 **depois**). Os dois regimes são **do
manifesto deles**, pela camada de CSS construído que só roda quando o `mtime` do CSS supera o dos
`.jsx` — **não é regressão**, e os dois estão declarados como tal nos manifestos de origem.

**INTOCÁVEIS, conferidos:** `publicador-header-zindex-ordem.test.js` **7 / 7 / 0** ·
`estrutura-meus-anuncios.test.js` (D-11/D-22) **30 / 30 / 0**.

---

## §3 — ⚠️⚠️ A PROVA DE QUE A FONTE É O CONTEXTO, E NÃO O PAINEL

### §3.1 — No CÓDIGO: `contextoDoLb.oferta_id` / `contextoDoLb.versao`, NUNCA `ofertaDoPainel`/`versaoDoPainel`

O adaptador que `acaoDoLightbox` chama (em `Aprovacao.jsx`), recortado por **ÂNCORA DE TEXTO** pelo
próprio gate:

```js
const escritas = escritasDaColuna({
    empresaId,
    ofertaId: alvo.oferta,      // <= alvo.oferta  = contexto.oferta_id (lido por acaoDoLightbox)
    versao: alvo.versao,        // <= alvo.versao  = contexto.versao
    onConflito: registrarConflito,
});
```

e a lateral é montada com o contexto vivo:

```jsx
<LateralDoLightbox
    {...propsDaLateralDoLightbox({
        contexto: contextoDoLb,          // <= o CONTEXTO, nunca o painel
        indice: indiceDoLb,
        …
        escrever: escreverPeloLightbox,
    })}
/>
```

**Medido por gate (com isca nas duas direções), no recorte do adaptador:**

| sonda | resultado |
|---|---|
| `ofertaId: alvo.oferta` presente | **sim** |
| `versao: alvo.versao` presente | **sim** |
| `escritasDaColuna(` presente | **sim** |
| `ofertaDoPainel` no recorte | **0** |
| `versaoDoPainel` no recorte | **0** |
| `ofertaDoPainel` no RESTO da página (para a ausência não ser vazia por construção) | **> 10** |

⚠️ **A última linha existe porque sonda de ausência sobre texto que não existe em lugar nenhum é
vazia por construção.** O gate cobra que a página **continue** usando `ofertaDoPainel` nos caminhos do
painel — se ela parasse, a ausência no recorte não provaria nada.

### §3.2 — No COMPORTAMENTO: a fixture **A v3 × B v7**, com isca

| fixture | oferta | versão | imagens | `ao_vivo` |
|---|---|---|---|---|
| **A** | `501` | **3** | **4** (`9101..9104`) | `true` |
| **B** | `777` | **7** | **2** (`8201, 8202`) | `false` |

⚠️ **A 3ª foto (índice 2) SÓ EXISTE NA A** — a fixture discrimina em **quatro** campos.

**Os casos, pelos nomes registrados:**

| caso | o que prova |
|---|---|
| `⚠️⚠️ FONTE: abrir na A (v3), descer para a B (v7) e aprovar a 3ª foto escreve com A/3/9103 189_07` | abrir na A → aplicar o payload da B → `acaoDoLightbox(indice: 2)` → o espião recebe `oferta === 501`, `versao === 3`, `imagemId === 9103` e `imagem === IMAGENS_A[2]` **por IDENTIDADE**; e **nada da B vazou** (id, versão e as duas imagens dela conferidas uma a uma) |
| `⚠️⚠️ FONTE — A ISCA: o contexto refrescado da B À FORÇA dá os dados da B (a sonda DISTINGUE) 189_07` | o MESMO caminho com o contexto da B dá `777 / 7 / 8202`; e o índice 2 numa galeria de 2 **não escreve nada** |
| `⚠️⚠️ FONTE: com A = B (sem ter navegado) a ação usa a versão REFRESCADA do payload 189_07` | painel volta na MESMA oferta com versão **9** → o contexto refresca → a ação escreve com **9**, não com 3 |

⚠️ **Asserção por IGUALDADE campo a campo, nunca `includes`** (que casa PREFIXO e já mordeu cinco
vezes neste projeto).

### §3.3 — Nenhuma escrita nova

| arquivo (fonte **SEM comentário**) | `router.post(` na PARTIDA | no FIM |
|---|---|---|
| `PainelDeRevisao.jsx` | **1** (o próprio `escreverNoPainel`) | **1** |
| `Aprovacao.jsx` | **1** | **1** |
| `LateralDoLightbox.jsx` / `LightboxDaImagem.jsx` / `ModalDeReprovarImagem.jsx` | **0** | **0** |

⚠️ **ISCA nas duas direções:** `ocorrencias('router.post(rota, corpo, opcoes)', 'router.post(') === 1`
(a sonda enxerga) e `ocorrencias('escreverNoPainel({ rota, versao })', 'router.post(') === 0` (ela não
acusa o helper único). ⚠️ **Contagem de OCORRÊNCIAS, nunca `grep -c`**, que conta LINHAS e já deu 12
para uma ocorrência só.

---

## §4 — ⚠️⚠️ `casca-188:1035` — a ARMADILHA A2, medida ANTES e DEPOIS

`publicador-painel-casca-188.test.js:1034`-`:1035` varre a **FONTE** de `PainelDeRevisao.jsx`,
`TopoDoPainel.jsx` e `IdentificacaoDaOferta.jsx` **SEM COMENTÁRIO** por `/⤢/` e `/Ampliar/`. **Eu
acrescentei 222 linhas àquele arquivo.**

⚠️ **O "ANTES" foi medido POR MIM, não citado da 189-06** — `red-189-07.sh` restaurou as duas fontes
como estão em `c23bab8d` (por **`git show <ref>:<path>`**, leitura pura — ⚠️ **`git checkout` NUNCA**),
rodou, e restaurou **da cópia**:

| medida na fonte SEM comentário de `PainelDeRevisao.jsx` | ANTES (`c23bab8d`, medido por mim) | DEPOIS |
|---|---|---|
| `Ampliar` | **0** | **0** |
| o glifo `⤢` | **0** | **0** |
| `/Desfazer/i` (também proibido na mesma lista) | **0** | **0** |
| `router.post(` | **1** | **1** |
| `188-07 casca-do-painel` | **41 / 40 / 0 / 1**, `exit=0` | **45 / 45 / 0 / 0**, `exit=0` |

⚠️ **O `41` do ANTES é o regime MUTADO, e a razão é a ferramenta, não o código:** `git show > arquivo`
atualiza o `mtime` do `.jsx`, e a camada de CSS construído daquele gate **PULA** quando o `mtime` do
CSS não a supera. **Os dois regimes são do manifesto dele (`45,41`) e os dois saem `exit=0`** — a
189-06 mediu `45/45/0` no mesmo commit, com o CSS já construído. **Não é regressão.**

⚠️ **A contagem `45,41` do manifesto da 188-07 ficou INALTERADA, e nenhuma linha dele foi tocada.**

### §4.3 — ⚠️⚠️ O **RED MEDIDO** (a tarefa 1 é `tdd="true"`, e o RED não foi fingido)

⚠️ **Divergência de PROCESSO, declarada:** eu escrevi a implementação e o gate **na mesma passada**,
então **não existe um commit RED honesto**. ➡️ Em vez de fabricar um, **eu MEDI o RED**: o mesmo
`red-189-07.sh` rodou o gate **NOVO** contra as fontes **da PARTIDA**, e depois restaurou.

```
=== (1) GREEN: o estado de AGORA ===
  gate novo: # tests 25 # pass 25 # fail 0

=== (3) O RED MEDIDO: o gate NOVO contra as fontes da PARTIDA ===
  gate novo: # tests 25 # pass 12 # fail 13
  os casos que REPROVAM sem a implementacao:
    not ok 2  - ENTRADA: os TRÊS named exports deste plano existem e são FUNÇÕES
    not ok 3  - FONTE: abrir na A (v3), descer para a B (v7) e aprovar a 3ª foto escreve com A/3/9103
    not ok 4  - FONTE — A ISCA: o contexto refrescado da B À FORÇA dá os dados da B
    not ok 5  - FONTE: com A = B (sem ter navegado) a ação usa a versão REFRESCADA do payload
    not ok 6  - GUARDAS: nenhuma escrita com `undefined`
    not ok 7  - D2: oferta do contexto ≠ oferta do painel → o lightbox FECHA; igual → fica aberto
    not ok 9  - BOTÃO: com `ao_vivo` e `pending`, a lateral MOSTRA Aprovar foto e Reprovar
    not ok 10 - BOTÃO: sem destino os handlers saem `null` — e aí a lateral NÃO desenha
    not ok 11 - CLIQUE: o handler escreve com A/3/9103 e IGNORA o argumento que recebe
    not ok 16 - FONTE: o fio do lightbox chama `escritasDaColuna` com o CONTEXTO
    not ok 17 - FONTE: a lateral é montada com `propsDaLateralDoLightbox(`, e NÃO com handler `null`
    not ok 18 - FONTE: o modal aberto pelo lightbox busca a foto na galeria DO CONTEXTO
    not ok 21 - ADVERSO: cada campo adverso, a prop inteira nula, E NO CLIQUE

=== (5) RESTAURACAO da copia, e sha256 contra `git show HEAD:` ===
  IGUAL  …/aprovacao/PainelDeRevisao.jsx  (3d99727411e75fe3)
  IGUAL  …/Pages/Mlb/Publicador/Aprovacao.jsx  (86ed026dfeb8b136)

=== (6) GREEN de novo, para fechar o ciclo ===
  gate novo: # tests 25 # pass 25 # fail 0
```

➡️ **13 dos 25 casos REPROVAM sem a implementação; 25/25 com ela.** ⚠️ Os **12** que passam nas duas
pontas são os que medem coisa **alheia** (anti-fantasma, território, manifesto, bijeção, protótipo,
CSS, build, Escape — que é do 189-06 — e o VETO de TDZ) e **estão certos em passar**. ⚠️ **O `# tests`
é 25 nas duas pontas** — se caísse, o arquivo não teria carregado e nem o RED nem o GREEN valeriam.
⚠️ **E a campanha de mutação (§14) é mais forte que um RED**: ela prova que cada peça da implementação
é cobrada **individualmente**.

### §4.1 — Os NOMES de prop/export escolhidos, e a razão — **sem citar o botão**

Os quatro nomes novos em `PainelDeRevisao.jsx`:

| nome | por que ele e não outro |
|---|---|
| `TIPOS_DA_ACAO_DO_LIGHTBOX` | lista **FECHADA** nos dois tipos; um terceiro tipo **não escreve** |
| `acaoDoLightbox` | nomeia **a ação**, não o botão que a dispara |
| `depoisDaAcaoNoLightbox` | nomeia **o degrau depois da escrita** (o D2) |
| `propsDaLateralDoLightbox` | nomeia **a peça que recebe as props** (a lateral), não o gesto |

E no `Aprovacao.jsx`: `escreverPeloLightbox`, `contextoDaFotoNoModal`, `ofertaDaEscritaDoModal`,
`versaoDaEscritaDoModal`, `galeriaDoModal`, e o campo de estado `contextoDaFoto`.

⚠️⚠️ **NENHUM dos dez cita "Ampliar", e isso é DELIBERADO.** O 189-06 já havia medido que uma prop
`onAmpliar` naquele arquivo **REPROVARIA**, e que o token de foco teve de ser `'botao-que-abriu'`
porque `'ampliar'` **minúsculo passaria POR SORTE** — *"silêncio que depende de UMA LETRA é sorte"*.
➡️ Aqui o padrão foi **seguido**: nomes descritivos da **função**, não do **gesto**, e o gate medido
nas duas pontas.

### §4.2 — `fotos-188:639`/`:640` seguem INVERTIDOS, e os outros SETE (+2 PHP) VERDES e INTOCADOS

| arquivo | medido no FIM | este plano tocou? |
|---|---|---|
| `publicador-painel-fotos-188.test.js` (`:639`/`:640` **invertidos** pelo 189-06; `:641` da Fase 192 **NÃO** afrouxado; `:1361` asserção POSITIVA do protótipo intacta) | **32 / 32 / 0** | **não** |
| `publicador-painel-casca-188.test.js` (`:1035`) | **41 / 40 / 0 / 1** → **45 / 45 / 0 / 0** | **não** |
| `publicador-painel-preco-188.test.js` (`:960`) | **48 / 48 / 0** | **não** |
| `publicador-painel-rodape-188.test.js` (`:1118`) | **42 / 42 / 0** | **não** |
| `publicador-painel-modais-a-188.test.js` (`:895`) | **27 / 27 / 0** | **não** |
| `publicador-painel-modal-excecao-188.test.js` (`:867`) | **23 / 23 / 0** | **não** |
| `publicador-painel-modais-b-188.test.js` (`:616`) | **22 / 22 / 0** | **não** |
| `publicador-painel-modal-reprovar-188.test.js` (`:685`) | **19 / 19 / 0** | **não** |
| `publicador-painel-aviso-versao-188.test.js` (`:742`) | **12 / 12 / 0** | **não** |
| `FotosDoPainelServiceTest.php` (`:1611`) + `PrecoDoPainelServiceTest.php` (`:1471`) | **24 passed / 763 assertions / 0** | **não** |

⚠️ **Não reinverti nada e não afrouxei nada.**

---

## §5 — ⚠️⚠️ A SUÍTE COMPLETA RODADA, E O **DÉCIMO-SEGUNDO** GATE: ele NÃO apareceu, e o que apareceu foi OUTRA COISA

A 189-06 achou dois gates (o 10º e o 11º) que barravam a fase **sem casar `/Ampliar/`**, e o 11º
(`filtros-187:684`) **só apareceu na SUÍTE COMPLETA**. ➡️ **Rodei a suíte completa, não só os meus
ids** — duas vezes, e também os nove arquivos de ausência um por um.

**Resultado: NÃO há um décimo-segundo gate fora do inventário A2.** O que a suíte completa encontrou
foi **duas** coisas, nenhuma delas um gate de ausência:

1. **`publicador-painel-modal-reprovar-188.test.js:581`** — e ele **NÃO É** um gate "que barra o que
   esta fase entrega": ele cobra, literalmente, que o caminho do **PAINEL** escreva com
   `{ company: empresaId, oferta: ofertaDoPainel, imagem: imagemId }`. ⚠️⚠️ **Ele está CERTO, e quem
   estava errado era a minha primeira redação** (ver §6). ➡️ **Nenhum reaponte, nenhuma edição de
   gate alheio, nenhuma contagem alheia remedida** — o conserto foi **no meu código**.
2. **`publicador-painel-casca-188.test.js:1079`** — reprovado pela **migration alheia untracked**
   (§1.1). **Não é um gate de ausência e não é desta fase.**

⚠️ **Nenhuma lista constante alheia foi tocada por este plano**, e cada uma está declarada com a razão
no cabeçalho de `tests/gates/contagens-189-07.txt`:

| lista | razão de NÃO ter sido tocada | medido |
|---|---|---|
| `ARQUIVOS_NOVOS` / `JSX_DEPOIS_DA_187` | **este plano não cria `.jsx`** (cria um `.test.js`) | `vocabulario-tamanhos` **695** |
| `TAMANHOS_PERMITIDOS` / `LIBERADOS_DEPOIS_DA_187` | **nenhum tamanho novo**: o plano LIGA handlers, não desenha elemento | idem |
| `ESTADO_LOCAL_DA_PAGINA` | **nenhum `useState` novo**: `contextoDaFoto` é **campo** do estado `modalDoPainel`, já escriturado | `filtros-render` **29/29/0** |
| `ACOES` / `ACOES_DEPOIS_DA_188_07` | **nenhuma ação nova de teclado**: o degrau do `Escape` é do 189-06 e este plano só o **EXERCE** | `casca-do-painel` **45/45/0** |
| `SLOTS_DE_NO_DA_187` / `SLOTS_DE_NO_DEPOIS_DA_187` | **nenhum slot de NÓ novo**: a lateral entra pelo slot `lateral` do 189-03 | — |
| `FONTES_DO_PLANO` do `modal-reprovar-188` | **`ModalDeReprovarImagem.jsx` NÃO foi tocado** | `modal-de-reprovar` **19/19/0** |
| `QUEM_USA_SHADOW_PUB_PAINEL`, `DA_FASE_180`, `DO_PORTAO_DA_FASE_186` | nenhuma sombra, token ou frase nova | — |
| `contagens-186-02.txt:83` | achado **HERDADO**, já consertado pelo orquestrador em 20/10 (`tests=>=399`) | não encostei |

---

## §6 — ⚠️⚠️ ONDE O GATE ALHEIO ME REPROVOU — e ele estava CERTO

**O que eu fiz primeiro (ERRADO):** troquei, no `onSubmeter` do `ModalDeReprovarImagem`, o
`oferta: ofertaDoPainel` / `versao: versaoDoPainel` por derivações
(`ofertaDaEscritaDoModal` / `versaoDaEscritaDoModal`), para o MESMO bloco servir aos dois caminhos.

**O que o gate disse:**

```
not ok 8 - ⚠️⚠️ B2: o "Reprovar" do bloco da imagem foi RELIGADO, com a guarda e pelo helper 188_12
  error: 'a rota de reprovar perdeu o `{imagem}` do CAMINHO'
  (publicador-painel-modal-reprovar-188.test.js:581)
```

**Por que ele estava CERTO, e não frágil.** O próprio `<action>` deste plano manda *"uma prop opcional
de contexto (padrão: o do painel, **sem mudar nada** para quem abre pelo painel)"*. A minha derivação
**mudava** o caminho do painel — comportamento equivalente, fonte diferente. O gate do 188-12 estava
enforçando exatamente a instrução do meu plano.

**O conserto: RAMO SEPARADO, no meu código.**

```js
if (contextoDaFotoNoModal !== null) {   // <= o caminho do LIGHTBOX, ANTES
    …escreverNoPainel({ parametros: { company: empresaId, oferta: ofertaId, imagem: imagemId }, versao: versaoId, … })
    return;
}
// o caminho do PAINEL, BYTE A BYTE como o 188-12 o entregou:
escreverNoPainel({ parametros: { company: empresaId, oferta: ofertaDoPainel, imagem: imagemId }, versao: versaoDoPainel, … })
```

➡️ **Nenhum gate alheio editado. Nenhuma contagem alheia remedida.** `modal-de-reprovar` voltou a
**19 / 19 / 0** com o `tests=19,17` que o manifesto da 188-12 já declarava.
⚠️ **O literal da rota passou a aparecer DUAS vezes** — e isso é *"segunda cópia da mesma verdade"*,
que a régua §2 só permite **com teste de igualdade**. ➡️ O gate desta wave cobra que as **duas** sejam
**IDÊNTICAS** (`new Set(nomesDaRota).size === 1`, com `length === 2`) e que a rota **exista pelo nome**
em `routes/mlb_anuncios.php`.

⚠️ **Eu considerei "PARE e devolva", que é o que o plano manda quando um gate da 188 reprova pela
mudança — e devolver NÃO era a resposta certa aqui**, porque o gate não estava barrando o que a fase
entrega: ele estava apontando um desvio MEU em relação ao próprio plano. **Onde o gate alheio e eu
divergimos, o gate ganhou, e o conserto foi no meu lado.**

---

## §7 — O MODAL ACIMA DO LIGHTBOX: a PROFUNDIDADE pelo **INVARIANTE**, nunca pelo índice

⚠️⚠️ `z-index` **não escapa** de um contexto de empilhamento, e o `<aside>` do painel é
`fixed z-[40]` → **ele CRIA um**. Montado DENTRO dele, o `z-[42]` da casca valeria **dentro do 40**, e
o modal aberto a partir do lightbox (`z-[41]`, portal próprio para o `document.body`) ficaria
**ATRÁS** dele: a pessoa clica "Reprovar" e **nada aparece** (T-189-53).

**INV-04 / ACHADO A1 do inventário mediu `FORA`.** Este plano **não moveu montagem nenhuma** e **prova
que continua fora**, por **ÁRVORE DE ANCESTRAIS** do HTML real de `RaizDoPainel` com o
`ModalDeReprovarImagem` aberto no slot `modais`.

### §7.1 — ⚠️⚠️ O índice absoluto é FUNÇÃO DA FIXTURA; a profundidade + irmandade NÃO

Medido com **duas fixturas de tamanho diferente** (`profundidade-189-07.mjs`):

```
fixtura com 1 "foto(s)" na coluna:
  INDICE ABSOLUTO do fundo z-[42] = 12   (<= MUDA com a fixtura)
  PROFUNDIDADE do fundo           = 0    (<= INVARIANTE)
  PROFUNDIDADE do <aside>         = 0
  ancestrais do fundo             = []
  ancestrais do <aside>           = []
  o <aside> e ANCESTRAL do fundo? = false
  IRMANDADE (mesma profundidade e mesmos ancestrais) = true

fixtura com 4 "foto(s)" na coluna:
  INDICE ABSOLUTO do fundo z-[42] = 15   (<= MUDOU: 12 -> 15)
  PROFUNDIDADE do fundo           = 0    (<= NÃO MUDOU)
  PROFUNDIDADE do <aside>         = 0
  ancestrais do fundo             = []
  ancestrais do <aside>           = []
  o <aside> e ANCESTRAL do fundo? = false
  IRMANDADE (mesma profundidade e mesmos ancestrais) = true
```

➡️ **O gate ancora no INVARIANTE, em três asserções:**
1. o `<aside>` `z-[40]` **não está** entre os `ancestrais` do elemento `z-[42]`;
2. as **profundidades** são iguais;
3. os **ancestrais** são `deepEqual` (irmandade).

⚠️ **O índice absoluto é usado apenas como TOKEN DE IDENTIDADE dentro do array de ancestrais** — nunca
como valor asserido. E as contagens são travadas (`fundos.length === 1`, `asides.length === 1`) para o
gate saber **qual** elemento mede, em vez de confiar em `els[0]` (o erro de **quatro** gates deste
projeto).

### §7.2 — As DUAS ISCAS OPOSTAS da árvore, e a do token

| isca | esperado | medido |
|---|---|---|
| árvore sintética com a casca **DENTRO** do `<aside>` | o `<aside>` **É** ancestral → **ACUSA** | ✅ |
| árvore sintética com a casca **IRMÃ** | o `<aside>` **não é** ancestral → **PASSA** | ✅ |
| `<div class="z-[4] z-[420]">` lido por `z-[42]` | **0** achados (`includes` casaria) | ✅ |
| `<div class="a z-[42] b">` lido por `z-[42]` | **1** achado (a sonda não é cega) | ✅ |

### §7.3 — O z-index pela ORDEM, com duas iscas opostas

| medida | valor |
|---|---|
| protótipo: lightbox (`:727`) / modal (`:775`) | **90** / **100** → `modal > lightbox` **true** |
| projeto: `LightboxDaImagem` / `CascaDeModal.CLASSES_BACKDROP` | **41** / **42** → `casca > lightbox` **true** |
| o número do protótipo **recusado** para a peça | `41 !== 100` asserido |

⚠️ **PROVA PELA ORDEM, nunca por lista larga de números** — `45` é o número do popover e o toast
legitimamente ficou em `z-[45]`. ⚠️ `publicador-header-zindex-ordem.test.js` **7 / 7 / 0** e
`estrutura-meus-anuncios.test.js` **30 / 30 / 0**, **intactos e verdes**.

---

## §8 — O RENDER ADVERSO DE CADA AÇÃO, **INCLUINDO NO CLIQUE**

⚠️⚠️ A **TELA PRETA** voltou **SEIS** vezes nesta milestone, **a última NO CLIQUE** — e esta wave é a
**das ações**.

**A matriz, no caso `⚠️⚠️ ADVERSO: cada campo objeto/nulo/ausente/NaN/array, a prop inteira nula, E NO
CLIQUE 189_07`:**

- **8 campos** (`contexto`, `indice`, `zoom`, `onFechar`, `onAlternarZoom`, `escrever`, `tipo`,
  `painelAtual`) × **12 valores** (`null`, `undefined`, `{}`, `[]`, `NaN`, `0`, `-1`, `''`, `'x'`,
  `{toString: null}`, `true`, `1.5`) = **96 combinações**, e para cada uma:
  1. `acaoDoLightbox` **não lança**;
  2. `depoisDaAcaoNoLightbox` **não lança**;
  3. `propsDaLateralDoLightbox` **não lança**;
  4. ⚠️ **o RENDER REAL** de `<LateralDoLightbox {...props}/>` **não lança** e
     `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`;
  5. ⚠️⚠️ **E O CLIQUE**: `props.onAprovarFoto(valor)` e `props.onReprovar(valor)` **não lançam**.
- **a prop INTEIRA** adversa (`null`, `undefined`, `'x'`, `7`, `[]`, `{toString: null}`) nas três
  funções e no render.
- **campos da IMAGEM** adversos **dentro** do contexto (`estado`, `aceita_acao`, `rotulo`,
  `url_arquivo`, `verificacoes`) e `originais` adversa — o render não lança e não vaza.

**O clique, com argumento ADVERSO, no caso
`⚠️⚠️ CLIQUE: o handler da lateral escreve com A/3/9103 e IGNORA o argumento que recebe 189_07`:**
`props.onAprovarFoto({ id: 99999, toString: null })` → o espião recebe **501 / 3 / 9103**. ➡️ **O
handler ignora o argumento e lê do CONTEXTO** — confiar no argumento tornaria o nó desenhado a fonte
da verdade, que é justamente o que a PERGUNTA-189-B decide. E `onAprovarFoto()` / `onReprovar()` **sem
argumento nenhum** também escrevem certo (`{a = null} = {}` só cobre `undefined`, **não `null`**).

⚠️ **Nunca `String()` nem `JSON.stringify()` na mensagem** (os dois **LANÇAM** com
`{toString: null}`), e o gate da 187-04 que **PROÍBE `String(` na página** está **CERTO** e continua
verde (medido: `String(` = **0** na página sem comentário).

⚠️ **`id = 0` passa pela guarda de "inteiro"** → `idPositivoDoPainel` (inteiro **POSITIVO**) em
oferta, versão e imagem; medido por caso próprio (`oferta ZERO`, `versão ZERO`, `imagem com id ZERO`
→ **nenhuma escrita**).

⚠️ **ISCA DA SONDA DE VAZAMENTO:** ela acha `[object Object]` e `NaN` em HTML sintético e **não** acusa
HTML limpo. Sem isso as 96×5 asserções seriam vazias por construção.

---

## §9 — O **D2** DA PERGUNTA-189-D: o que eu decidi, e o que NÃO decidi

⚠️ **`PERGUNTA-189-D: ABERTA, NÃO BLOQUEIA` — conferido por `grep` na primeira ação** (`= 1`), e o
estado dela **não mudou**. A 189-06 aplicou o **default D1** e declarou; o **D2 era meu**.

**O caso:** a pessoa abre o lightbox na oferta **A**, navega ↓ para a **B** (↑/↓ continuam navegando a
fila por baixo — 189-B (b)) e age na foto da A. O recarregamento parcial do Inertia traz o painel
**da B**, e o contexto da A fica **VELHO**: a foto que ela acabou de aprovar continuaria aparecendo
*"A conferir"*, **com os botões**, e um segundo clique tentaria aprovar o que já está aprovado
(T-189-54).

**DEFAULT APLICADO (e DECLARADO como default de pergunta ABERTA, no docblock e aqui):** o lightbox
**FECHA** depois de uma escrita bem-sucedida quando `contexto.oferta_id !== painel.oferta_id`, com o
foco seguindo a regra do **D1** (`aoFecharOLightbox` + `fecharLightboxDevolvendoFoco`). Com a **MESMA**
oferta ele **continua aberto**, e o contexto se refresca sozinho.

**A ALTERNATIVA, NOMEADA E NÃO IMPLEMENTADA:** recarregar o painel na oferta A (um `router.get` com
`?oferta=A`) e manter o lightbox aberto, atualizado. ⚠️ Ela **move o painel por baixo da pessoa** sem
ela ter pedido, e **desfaz a navegação ↑/↓ que foi ação explícita dela**. ➡️ `recarregarNaOferta` sai
**`null` SEMPRE**, e **é essa a asserção que distingue o default da alternativa** — o mesmo contrato do
`voltarParaAOferta` do D1.

⚠️ **NÃO devolvi a pergunta**, porque o D2 **não exigiu decisão nova do usuário**: a régua §6 já
registra o default (*"o lightbox FECHA depois da ação"*) e a alternativa, e o plano manda aplicar o
default e declarar. ➡️ **Aplicado, declarado, e a pergunta segue ABERTA para o fechamento (189-08).**

**Provado em ambos os ramos, mais os adversos:** `fechar: true` com 501 × 777, `fechar: false` com
501 × 501, `fechar: false` em sete formas adversas (sem contexto, sem painel, contexto sem oferta,
painel sem oferta, `'501'` string, pedido nulo, tudo adverso), e **uma isca** provando que a sonda
**não está cega** (`{oferta_id: 1}` × `{oferta_id: 2}` → `fechar: true`).

⚠️ **O D2 vale TAMBÉM pelo caminho do modal de reprovar** (reprovar tira a foto do conjunto final), e
**sem lightbox aberto `contextoDoLb` é `null` e `fechar` sai `false`: nada muda para quem reprova pelo
painel.**

---

## §10 — O BOTÃO NASCE DE VERDADE (o achado do plan-checker), e os TRÊS casos nomeados

O 189-06 montou a lateral com `onAprovarFoto={null}` e `onReprovar={null}`, e `podeAgirNaFoto`
(189-05) **só desenha com handler FUNÇÃO**. ⚠️⚠️ **Se este plano provasse apenas funções puras, todo
teste passaria e o botão nunca nasceria** — e o lightbox é **portal**, que o renderizador de
**SERVIDOR** do React **não desenha**, então o teste do composto passaria **por OMISSÃO**.

➡️ O fio atravessa `propsDaLateralDoLightbox` (named export), e o gate **renderiza a
`LateralDoLightbox` REAL** com essas props:

| caso (nome registrado) | `ao_vivo` | estado / `aceita_acao` | HTML contém `Aprovar foto` / `Reprovar` |
|---|---|---|---|
| CASO 1 do `⚠️⚠️ BOTÃO: com `ao_vivo` e `pending`, a lateral renderizada com ESTAS props MOSTRA Aprovar foto e Reprovar 189_07` | `true` | `pending` / `true` | **SIM / SIM** |
| CASO 2 do mesmo caso (`regenerating`) | `true` | `regenerating` / `false` | **não / não** |
| CASO 3 do mesmo caso (fora do `live`) | `false` | `pending` / `true` | **não / não** |

⚠️ Cada um dos três é precedido de uma asserção de **tamanho mínimo do HTML** (`> 500` / `> 300`
bytes) para a sonda de ausência **não medir zero casos**.

**Mais o caso `⚠️⚠️ BOTÃO: sem destino os handlers saem `null` — e aí a lateral NÃO desenha 189_07`**,
com **oito** formas sem destino (sem `escrever`, `escrever` não-função, contexto nulo, contexto sem
oferta, contexto sem versão, índice fora, imagem sem id, pedido nulo) — nas oito os dois handlers são
`null` **e** o HTML não tem os rótulos.

⚠️⚠️ **`propsDaLateralDoLightbox` NÃO repete a regra de "pode agir".** Quem decide se o botão nasce é
`podeAgirNaFoto` (`ao_vivo` **e** `pending` **e** `aceita_acao` **e** os dois handlers função) — **uma
regra só**, no arquivo do 189-05. Aqui se decide outra coisa: **se existe DESTINO**. Duplicar
`estado`/`aceita_acao` seria a segunda verdade que a régua §2 proíbe.

**E o gate de fonte** prova que a lateral é montada **espalhando** a função
(`/<LateralDoLightbox\s*\{\.\.\.propsDaLateralDoLightbox\(/`), que `onAprovarFoto={null}` e
`onReprovar={null}` **não voltaram**, com **isca nas duas direções** (uma fonte sintética com handler
`null` é **ACUSADA**; o espalhamento de verdade **não** é), e que `lerSemComentarios` **não é cega**
(o CRU tem `PERGUNTA-189-B` e a fonte sem comentário **não**).

---

## §11 — O QUE **NÃO** É PROVADO SEM NAVEGADOR, e a premissa travada como PROXY DE FONTE

⚠️ Não há navegador nesta máquina, o `mysqld` local está **PARADO** (medido), o banco não tem empresa
com `ml_token` (a rota dá **404**), `pub_aprovacao_ofertas` está **VAZIA** e **nada em `app/` cria
oferta** (quem cria é a **Fase 194**). ➡️ ***"A tela mostra X"* aqui significa SEMPRE *"o HTML gerado
contém X"*.**

**NÃO provado, e declarado:**

| o que | como ficou |
|---|---|
| o `onClick` disparar no navegador | **não provado** — os handlers são chamados **À MÃO** (`renderToStaticMarkup` não dispara evento nenhum) |
| o `focus()` acontecer | **não provado** — o 189-06 o travou com **STUB que conta chamadas**, e esta wave só reusa `fecharLightboxDevolvendoFoco` |
| a anexação da `ref` | **não provado** |
| o portal **portar** | **não provado** — e é por isso que a lateral é renderizada **direto**, pela função de props |
| a animação / transição | **não provado** (e esta wave não desenha transição nenhuma) |
| o `router.post` chegar ao servidor | **não provado** — o que está provado é que a escrita **atravessa o helper único com a oferta e a versão certas** |

**A premissa INOBSERVÁVEL desta wave, TRAVADA POR IGUALDADE DE FONTE e DECLARADA como PROXY:** *"o
`escrever` que o componente passa a `propsDaLateralDoLightbox` é o adaptador que chama
`escritasDaColuna` com a oferta e a versão do contexto"*. Isso não é observável sem navegador (o
componente nunca é instanciado), então o gate o trava **pela FONTE**, com recorte por **âncora de
texto** no corpo do adaptador (§3.1) — e **declara no teste que é proxy de fonte**. ⚠️ A **mutação
(i)** e a **(ii)** da campanha existem exatamente para provar que esse proxy **morde**: as duas
**MORRERAM**.

---

## §12 — A tabela **FID-01 por SCRIPT**, com `cursor`/`white-space`/`text-overflow` em colunas próprias

**Diretório citado: `design_handoff_publicador/`** — ⚠️ **somente leitura e NÃO versionado** (D-189-2),
e `new_layout_publicador/Aprovacao.dc.html` **NÃO EXISTE** (reconfirmado agora, pela terceira vez).
➡️ Gate que depende dele degrada para **`skip` EXPLÍCITO e NOMEADO**; nesta máquina ele existe e o
bloco **RODOU**.

**COMO MEDIDA: `SCRIPT`** (`fid01-189-07.cjs`, escrito pela ferramenta de escrita), saída colada:

```
test -e design_handoff_publicador/Aprovacao.dc.html -> EXISTE
test -e new_layout_publicador/Aprovacao.dc.html     -> AUSENTE (confirmado)
```

| id | linha | literal medido (recorte) | cursor | white-space | text-overflow |
|---|---|---|---|---|---|
| E11 — ações do lightbox | `:1330` | `canAct: !!liveO && im.isPending, canRegen: …` | — | — | — |
| E12 — Escape (cadeia) | `:1014` | `if (e.key === 'Escape') { if (S.modal) …; if (S.lb) …` | — | — | — |
| E13 — setas | `:1016` | `if (S.lb && (e.key === 'ArrowRight' \|\| …)) { … lbMove …` | — | — | — |
| E14 — fila por baixo | `:1017` | `if (S.panelId && !S.modal && (e.key === 'ArrowDown' …))` | — | — | — |
| D1 — wrapper do canto | `:604` | `position:absolute;right:10px;bottom:10px;display:flex;gap:6px` | — | — | — |
| D2 — o botão de abrir | `:607` | `white-space:nowrap;height:30px;padding:0 11px;border:none;border-radius:8px;ba…` | **pointer** | **nowrap** | — |
| camada do lightbox | `:727` | `position:fixed;inset:0;z-index:90;background:rgba(5,5,5,0.94);…` | — | — | — |
| camada do modal | `:775` | `position:fixed;inset:0;z-index:100;background:rgba(0,0,0,0.65);…` | — | — | — |

**E11 por PARTE — é a linha que esta wave implementa:**

```
canAct (condicao da acao)      -> !!liveO && im.isPending
onApprove (conjunto e foto)    -> () => this.approveImg(lb.set, ord[i])
onReject (modal POR CIMA)      -> () => this.openModal('reject', { set: lb.set, img: ord[i], reason: '', tags: {} …
```

➡️ **O protótipo age sobre `lb.set` e `ord[i]` — O CONJUNTO E A FOTO DO LIGHTBOX.** A implementação é
**fiel**, e a PERGUNTA-189-B (b) é exatamente a reprodução disso.
⚠️ **Uma divergência, já DECLARADA pela 189-05 e preservada aqui:** o `canAct` do protótipo é
`!!liveO && im.isPending`, e a tela acrescenta **`aceita_acao`** (IMG-09, verdade do SERVIDOR —
`regenerating` não aceita e o 188-05 devolve **422**). **Não é um quinto conflito protótipo × regra:**
é a tela **não oferecendo** o que o servidor recusaria, e a trava REAL é do servidor.

⚠️⚠️ **NENHUM QUINTO CONFLITO protótipo × regra do projeto apareceu nesta wave.** O placar segue
**~146 a 4**, com as quatro derrotas do protótipo **todas por decisão do USUÁRIO**.

### §12.1 — O CSS das classes do §D e do §C, **pelo CORPO da regra**

Saída do `css-189-07.cjs` (depois do **segundo** `npm run build`), colada:

```
ISCA DO ESCAPE: escapar("h-[30px]")          = "h-\\[30px\\]"
ISCA DO ESCAPE: escapar("bg-pub-page/[0.85]") = "bg-pub-page\\/\\[0\\.85\\]"
arquivos css: 2 | bytes: 242377

ACHOU  bg-pub-page/[0.85]
ACHOU  px-[11px]
ACHOU  h-[30px]
ACHOU  bottom-[10px]
ACHOU  right-[10px]
ACHOU  gap-[6px]
ACHOU  border-pub-danger/[0.45]
--- iscas (TEM de FALTAR) ---
ACHOU  bg-pub-page/[0.86]      <== ⚠️ ISCA ERRADA, ver abaixo
falta   px-[1234px]
falta   gap-[61px]
falta   z-[420]
falta   z-[4]

--- CORPO das regras (pelo CORPO, nao pela presenca do seletor) ---
bg-pub-page/[0.85]         -> .bg-pub-page\/\[0\.85\]{background-color:#0a0a0ad9}
border-pub-danger/[0.45]   -> .border-pub-danger\/\[0\.45\]{border-color:#ef444473}
h-[30px]                   -> .h-\[30px\]{height:30px}
px-[11px]                  -> .px-\[11px\]{padding-left:11px;padding-right:11px}
gap-[6px]                  -> .gap-\[6px\]{gap:6px}
bottom-[10px]              -> .bottom-\[10px\]{bottom:10px}
right-[10px]               -> .right-\[10px\]{right:10px}
```

⚠️ **A opacidade em `background` sai HEX DE 8 DÍGITOS, como o plano previu, e a forma foi LIDA antes de
escrever a regex:** `rgba(10,10,10,0.85)` do protótipo → **`#0a0a0ad9`**; e `0.45` →
**`#ef444473`**.

⚠️ **A sonda de AUSÊNCIA de classe é feita na FONTE SEM COMENTÁRIO, nunca no CSS** — o **scanner do
Tailwind LÊ COMENTÁRIO**, e classe citada em docblock já virou regra de CSS **real** neste projeto.
⚠️ E `tailwind.config.js` varre **só** `resources/views/**/*.blade.php` e `resources/js/**/*.jsx`:
medido, e é por isso que `z-[4]`/`z-[420]`, escritos como isca **no meu `.test.js`**, **NÃO** chegaram
ao CSS (`falta` nos dois). ⚠️ A extensão do Tailwind no editor **lintou** os dois como classe — **o
editor é mais largo que o build**, e isso fica registrado.

---

## §13 — ⚠️⚠️ AS SONDAS QUE EU MESMO FUREI, e como cada uma foi consertada

**Quatro furos próprios, todos achados por MEDIÇÃO e consertados antes de qualquer conclusão:**

| # | furo | como apareceu | conserto |
|---|---|---|---|
| 1 | **`espiao(retorno)` com PARÂMETRO DEFAULT** (`retorno = true`): `espiao(undefined)` devolvia `true`, e o caso `undefined` acusava a função por defeito **da sonda** | o gate reprovou com *"a ação foi tida por disparada com `escrever` devolvendo undefined"* | retorno **FIXADO sem default** (`const devolve = () => retorno`). ⚠️ É a MESMA família do `{a = null} = {}` que só cobre `undefined` |
| 2 | **`split(/\s+/)` no manifesto** devolvia entrada **VAZIA** no fim de cada linha (as colunas são separadas por DOIS espaços), e o `Set` as colapsava → acusava *"o mesmo arquivo em dois ids"* num manifesto correto | o gate reprovou com `expected 4, actual 3` | `.trim()` + `.filter(a => a !== '')`, **mais** uma isca sintética provando que a sonda **ENXERGA** a duplicata de verdade. ⚠️ É a família *"gate que reprova pelo próprio conserto"* |
| 3 | ⚠️⚠️ **A ISCA DE CSS ERA UMA CLASSE REAL DO PROJETO:** eu escolhi `bg-pub-page/[0.86]` *"porque não existe"*. **Ela EXISTE** — é a classe do `PublicadorHeader.jsx:174` (o header global, **INTOCÁVEL**) | o gate reprovou com `expected false, actual true` | as três iscas foram **MEDIDAS com `grep -r` em `resources/` E no CSS** (como o plano manda e eu não havia feito): `bg-pub-page/[0.87]`, `px-[1234px]`, `gap-[61px]` → **0 nas duas pontas**. ⚠️ E a classe do header ficou como **prova de que a sonda NÃO é cega** |
| 4 | **DUPLO escape para RegExp:** para `String.includes` basta a forma CSS-escapada (`bg-pub-page\/\[0\.85\]`); para `RegExp` aquelas barras invertidas precisam ser escapadas **DE NOVO**. Sem isso o padrão casava o texto **SEM** barra, que não existe no CSS | o probe reportava **"(sem corpo)"** para regras que ESTÃO no CSS | `paraRegex = (c) => escapar(c).replace(…)`, de **UMA fonte só**, com **isca nas duas direções** (acha `h-[30px]`, **não** acha `h-[307px]`) |

⚠️ **E um quinto furo, achado pelo DESENHO DA CAMPANHA DE MUTAÇÃO antes de rodá-la** (§14): a asserção
da escrita do modal cobrava **a existência do NOME** (`includes('const ofertaDaEscritaDoModal')`), e
uma mutação que trocasse o **corpo** por `= ofertaDoPainel` **SOBREVIVERIA** com o gate verde. ➡️
Consertado: a **DERIVAÇÃO** passou a ser cobrada (o ternário inteiro), nas duas — oferta e versão —,
com isca nas duas direções em cada. **Commit `95fb404f`.**

⚠️ **Ferramentas:** **TODO** script desta wave foi escrito pela **FERRAMENTA DE ESCRITA**
(`baseline-189-07.cjs`, `tdz-189-07.mjs`, `tdz-real-189-07.cjs`, `css-189-07.cjs`,
`mutar-189-07.sh`, `mutar-189-07-vii.sh`, `pi-189-07.sh`, `bijecao-189-07.cjs`, `fid01-189-07.cjs`,
`profundidade-189-07.mjs`) — e o motivo foi **re-confirmado na prática**: um `node -e` desta sessão
**morreu com `SyntaxError`** porque o shell **comeu a barra invertida** da regex de escape. ⚠️ `grep -c`
não foi usado para contar ocorrências em lugar nenhum (`indexOf` em loop). ⚠️ O `exit` lido é o **do
harness**, nunca de um `tail` na frente. ⚠️ **Nunca li
`${TMPDIR:-/tmp}/ultima-suite-php.log`** durante a execução (caminho FIXO E COMPARTILHADO); as suítes
foram para arquivos próprios no scratchpad **com o nome do plano**.

---

## §14 — A CAMPANHA DE MUTAÇÃO (`mutar-189-07.sh`)

**Commit ANTES:** `42fc4752`. **`cp -p` para fora do repo**, ocorrência **travada em 1 ANTES** por
string exata (lida de ARQUIVO, para nenhuma camada comer barra/acento), aplicação por **divergência de
`sha256`**, restauração **DA CÓPIA** em `trap EXIT`, **`git checkout` NUNCA**.
**`sha256` do próprio script: `344f13ac424364ccd426d7376b975ec8`** (⚠️ o scratchpad é COMPARTILHADO —
na Fase 187 um script foi SOBRESCRITO e a campanha teve de ser refeita inteira).

**PRISTINO: `# tests = 25`.** ⚠️⚠️ **O `# tests` do MUTADO foi conferido IGUAL ao do pristino nas
treze rodadas** — se cair, o arquivo **não carregou** e a morte **não é da asserção**.

```
=== AS MUTACOES (todas TEM de MORRER) ===
  MORREU   (i)    a FONTE DA VERSAO trocada para o PAINEL                      (# tests 25, # fail 1)
  MORREU   (ii)   a OFERTA lida do PAINEL                                      (# tests 25, # fail 1)
  MORREU   (iii)  a IMAGEM: a PRIMEIRA do contexto em vez da ABERTA            (# tests 25, # fail 3)
  MORREU   (iv)   o modal de reprovar montado DENTRO do <aside>                (# tests 25, # fail 1)
  MORREU   (v)    o Escape fechando o LIGHTBOX junto com o modal               (# tests 25, # fail 1)
  MORREU   (vi)   o D2 DESLIGADO (nunca fecha)                                 (# tests 25, # fail 1)
  SOBREVIVEU (vii) um router.post( DIRETO no fio do lightbox                   (# tests 25, # fail 0)  <== INVESTIGAR
  MORREU   (viii) propsDaLateralDoLightbox com os handlers NULL                (# tests 25, # fail 2)
  MORREU   (ix)   a galeria do MODAL lida do PAINEL em vez do contexto         (# tests 25, # fail 1)
  MORREU   (x)    a OFERTA da escrita do MODAL sempre a do painel              (# tests 25, # fail 1)
  MORREU   (xi)   a VERSAO da escrita do MODAL sempre a do painel              (# tests 25, # fail 1)
  MORREU   (xii)  a lateral montada SEM a funcao de props (handlers a mao)     (# tests 25, # fail 1)

RESULTADO: 11 mortas, 1 sobreviventes, 0 abortadas

=== RESTAURACAO: sha256 contra `git show HEAD:` (com tr -d '\r') ===
  IGUAL  …/aprovacao/PainelDeRevisao.jsx  (3d99727411e75fe3)
  IGUAL  …/Pages/Mlb/Publicador/Aprovacao.jsx  (86ed026dfeb8b136)
```

### §14.1 — ⚠️⚠️ A SOBREVIVENTE, **INVESTIGADA** — e ela acusa a MUTAÇÃO, não o gate

**Por que a (vii) sobreviveu:** a mutação trocava a linha por
`typeof router.post === 'function' ? false : …` — que **não contém a string `router.post(`** (é
`router.post` **seguido de espaço**, acesso a propriedade, **sem parêntese**). ➡️ **A mutação NÃO
EXPRESSAVA A AMEAÇA** (T-189-52: escrita direta, sem `versao_esperada`), e sobreviveu
**legitimamente**.

**A investigação, em `mutar-189-07-vii.sh` (`sha256 9bfb6b07c571da5139299a7533844705`):**

```
PRISTINO: # tests = 25

    (router.post( na fonte SEM comentario depois da mutacao: 2)
  MORREU   (vii-b) router.post( no fio do lightbox, na PAGINA         (# tests 25, # fail 1)
    (router.post( na fonte SEM comentario depois da mutacao: 2)
  MORREU   (vii-c) router.post( no PAINEL, dentro da funcao de props  (# tests 25, # fail 1)

=== RESTAURACAO: sha256 contra `git show HEAD:` (com tr -d '\r') ===
  IGUAL  …/aprovacao/PainelDeRevisao.jsx  (3d99727411e75fe3)
  IGUAL  …/Pages/Mlb/Publicador/Aprovacao.jsx  (86ed026dfeb8b136)
```

➡️ **As duas MORRERAM**, e o probe confirma que a contagem de `router.post(` na fonte **sem
comentário** foi de **1 → 2** nas duas pontas. **O gate trava a ameaça real; a mutação original é que
estava mal desenhada.**

**PLACAR FINAL: 13 mutações rodadas · 12 expressam a ameaça e as 12 MORRERAM · 1 (a vii original) não
expressava a ameaça, foi INVESTIGADA e substituída por duas que morrem · 0 abortadas · `sha256`
restaurado IGUAL nos dois arquivos.**

⚠️ **`npm run build` DEPOIS da campanha** (`cp -p` atualiza o `mtime` e a camada de CSS **PULA**, dando
`skipped` que **NÃO é regressão**) — e **`git diff HEAD -- resources/` conferido ANTES de cada um dos
dois builds: VAZIO nas duas vezes.**

---

## §15 — Os CRITÉRIOS DE ACEITAÇÃO que eu RODEI, e o que cada um mediu

⚠️ *"O que precisaria estar errado no mundo para este gate falhar?"* — **"Nada" é ACHADO.** Rodei
**todos** os critérios do meu plano antes de aceitá-los.

| critério do plano | rodado | veredicto |
|---|---|---|
| `grep -cE "^export (function\|const) (acaoDoLightbox\|depoisDaAcaoNoLightbox\|propsDaLateralDoLightbox)\b"` = **3** | **0 → 3** | OK (medido ANTES = 0, DEPOIS = 3) |
| o caso "A v3 × B v7" prova por igualdade campo a campo; a isca dá os da B; **nomes registrados** | sim | OK — §3.2 |
| o teste de árvore acha o `z-[42]` e prova que nenhum ancestral é o `<aside>` `z-[40]`; **as duas iscas registradas** | sim | OK — §7 |
| `router.post(` em `PainelDeRevisao.jsx`, SEM comentário, igual ao HEAD de partida | sim | **1 = 1** |
| se o modal ou a montagem mudou: ids do `contagens-188-12.txt` em 0 com a contagem que ele declara, e **PI(189-07) ∩ `contagens-188-12.txt` = ∅** | sim | ⚠️ **o modal NÃO mudou** (medido: já recebe a imagem por prop e não escreve). Os três ids do 188-12 em `exit=0` com `19,17` / `22,18` / `12,10` **inalterados**; PI com interseção **VAZIA** e a **isca (a) `(188-12)` ACUSA** |
| o render de `<LateralDoLightbox {...props}/>` com `ao_vivo`+`pending` tem os dois rótulos, e com `regenerating` / fora do `live` não — **os três casos nomeados** | sim | OK — §10 |
| os dois ids do `<verify>` saem 0 | sim | `acoes-do-lightbox` **exit=0**, `vocabulario-tamanhos` **exit=0** |
| `npm run build` exit 0; manifest pelo JSON com a página e **sem** a isca | sim | **exit=0** nas duas vezes; a página no manifest, a isca ausente |
| a sonda de CSS acha `bg-pub-page/[0.85]` e `border-pub-danger/[0.45]` com os valores reais | sim | **`#0a0a0ad9`** e **`#ef444473`** — §12.1 |
| `--suite js` ≥ baseline com exatamente 1 falha, a `estrutura-grade-glide` | sim | **6005 / 6004 / 1**, `exit=0` — §2.1 |
| a tabela da campanha (≥8 mutações; a (i) e a (viii) MORRERAM; `sha256` restaurado) | sim | **13 rodadas**, §14 |

### §15.1 — ⚠️⚠️ UM critério do meu plano saiu **VAZIO POR CONSTRUÇÃO**, e eu o troquei

> *"se o modal ou a montagem dele mudou: … **PI(189-07)** — interseção VAZIA com
> `tests/gates/contagens-188-12.txt`"*

⚠️ **Ele é CONDICIONAL a uma premissa que a medição derrubou:** o `ModalDeReprovarImagem.jsx` **não
precisou mudar** (A1/INV-04 já mediu `FORA`, e o modal recebe a imagem por prop e **não escreve**).
➡️ Com o antecedente falso, o critério não mede **nada**. **Em vez de declará-lo "satisfeito"**, eu
rodei o consequente **de todo jeito** (os três ids do 188-12 + a PI sobre o manifesto dele + a isca
(a) com o escopo `(188-12)`, que **ACUSA**) — e **registro aqui que o antecedente é falso.**

⚠️ **E um segundo:** o `<behavior>` do plano dizia *"aberto pelo PAINEL (não pelo lightbox), o modal
continua escrevendo com a oferta/versão do painel, **como o 188-12 entregou** — nada muda para quem
não usa o lightbox"*. **Isso NÃO era automático:** a minha primeira redação o violou e **um gate da
188 o pegou** (§6). ➡️ A asserção que faltava foi **acrescentada ao meu gate** (o literal
`parametros: { company: empresaId, oferta: ofertaDoPainel, imagem: imagemId }` cobrado na fonte da
página), para que o próximo executor não refaça o mesmo desvio.

---

## §16 — O MANIFESTO, a BIJEÇÃO com EXPANSOR, e o `--autoteste`

`tests/gates/contagens-189-07.txt` **nasceu na tarefa 1**, ANTES do primeiro `verify` (manifesto
incompleto **ABORTA**), com os dois ids e os dois regimes:

```
acoes-do-lightbox  js  tests/js/publicador-lightbox-acoes-189.test.js  ::  tests=25,25
vocabulario-tamanhos  js  tests/js/publicador-vocabulario-novo.test.js  ::  tests=>=695
```

⚠️ **Os DOIS regimes COINCIDEM (`25,25`), e a razão é medida:** os três blocos que degradam para
`skip` (o protótipo, o CSS construído e o `manifest.json`) **contam como teste** no `# tests` do TAP,
e **nenhuma camada deste gate depende de `mtime`** — `cp -p` atualiza o `mtime` sem mexer na contagem.
**Confirmado nas treze rodadas da campanha: `# tests = 25` em todas.**

⚠️ **`vocabulario-tamanhos` em LINHA PRÓPRIA, forma `>=`, com N REMEDIDO AGORA: `695`** (`node --test
--test-reporter=tap`, ANSI tirado ANTES de ler `# tests`) — o mesmo que o 189-05 remediu e o 189-06
re-mediu, e **este plano não cria `.jsx`**, então o número **não sobe**.
⚠️ **O 1º argumento é `189-07`**, nunca `07` (que resolve `contagens-186-07.txt`, a fase ERRADA, em
silêncio).

**Bijeção, por script (`bijecao-189-07.cjs`), colada:**

```
ids no MANIFESTO : ["acoes-do-lightbox","vocabulario-tamanhos"]
ids nos <verify> : ["acoes-do-lightbox","vocabulario-tamanhos"]
ORFAOS (id no manifesto FORA de todo <verify>) : []
SEM_ID (<verify> sem linha no manifesto)       : []

casos no gate: 25
casos SEM o sufixo `189_07` : []

ISCA do expansor: expandir("test_(a|b)_189_07") = ["test_a_189_07","test_b_189_07"]
ISCA do expansor: /test_[A-Za-z0-9_]+/ em "test_(a|b)" casa -> null
  (o segundo mostra o FURO que o expansor conserta)

HOMONIMOS (nome de caso deste gate em OUTRO arquivo): []
arquivos de teste ALHEIOS que contem `189_07`: 0 (0 = sufixo exclusivo)
```

⚠️ **O EXPANSOR DE ALTERNÂNCIA está travado com isca**: `/test_[A-Za-z0-9_]+/` casa **`null`** em
`test_(a|b)` — é o furo que deu `SEM_ID=14` **acusando trabalho correto** numa wave anterior.
⚠️ **Nenhum id com mais de TRÊS métodos** (no modo `js` o harness roda o arquivo inteiro: **um id por
arquivo**, e há asserção cobrando que nenhum arquivo apareça em dois ids daqui).
⚠️ **ZERO órfão**, nos dois sentidos.
⚠️ **Este plano não tem id `php`**, e está certo: ele é só de tela, teclado e fio de escrita no
cliente. **Não há homônimo de `--filter` a conferir** (o `--filter` do PHPUnit não roda sobre arquivo
JS, e é ele que SOMA contagens) — mas os **25** nomes terminam em `189_07` de todo modo, porque o
`--filter` é regex **NÃO ANCORADO** e as Fases 186/187/188 numeram de 01 em diante.

**`--autoteste`, colado:**

```
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.   (exit=0)
```

---

## §17 — Os PONTEIROS de `.planning/`, RECONFIRMADOS por `sed`

⚠️ **Ponteiro de `.planning/` APODRECE SOZINHO**, e o commit `0607f5a9` do orquestrador já empurrou
linhas antes. Reconferi **cada** ponteiro que eu precisava:

| ponteiro | escrito | medido HOJE por `sed` | veredicto |
|---|---|---|---|
| `189-FID01.md` §G → `.planning/ROADMAP.md` **L5038** (`MOD-06 (1) \| Phase 190`) | L5038 | **L5132** | ⚠️ apodrecido (igual ao que a 189-06 mediu: **L5038 → L5081 → L5132**); **não se moveu de novo** |
| `189-FID01.md` §G → `.planning/ROADMAP.md` **L4730** (`Requirements: … MOD-06`) | L4730 | **L4752** | ⚠️⚠️ **NOVO apodrecimento, achado por mim.** L4730 hoje é outro texto, e `grep -n "Requirements:.*MOD-06"` devolve **ZERO** na forma citada |
| `189-CONTRATO-188.md` A1/INV-04 → `PainelDeRevisao.jsx:711`/`:715`/`:717` | `FORA` | **conferido pelo render real**: o `z-[42]` é **IRMÃO** do `<aside>`, não descendente | ✅ e o conserto é **reancorar na ÁRVORE**, não na linha |
| `189-REGUA.md` §6 → `PERGUNTA-189-B/C/D` | — | `B: RESPONDIDA` = **1** · `C: RESPONDIDA` = **1** · `D: ABERTA, NÃO BLOQUEIA` = **1** | ✅ |

⚠️ **NÃO reescrevi o `189-FID01.md`** (CINCO gates o leem), **nem o estendi** — o registro dos dois
ponteiros apodrecidos ficou em `deferred-items.md` (**D-189-07-C**) e aqui, para o **189-08**.
⚠️ **Não toquei** `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md`, `STATE.md` (GATE-07), `189-REGUA.md`,
`189-COBERTURA.md`, `189-CONTRATO-188.md` nem nenhum `189-NN-PLAN.md` — **provado pela PI**.

---

## §18 — ⚠️⚠️ O ACHADO MAIS GRAVE DESTA WAVE, e ele **NÃO é dela**: QUATRO TELAS PRETAS por TDZ

Está inteiro em **`deferred-items.md` → D-189-07-A**, com a prova em duas partes. **O resumo:**

O corpo de `export default function Aprovacao(…)` lê **QUATRO** `const` em posição **EAGER** antes de
elas serem inicializadas — ou seja **TDZ**, ou seja **`ReferenceError`**, ou seja **TELA PRETA** no
instante em que o painel abre com payload:

| símbolo | lido em | declarado | fio |
|---|---|---|---|
| `abrirModalDeReprovar` | `onReprovar={abrirModalDeReprovar}` (dentro do `const colunaDeFotos`) | **depois** | 188-12 |
| `registrarConflito` | `onConflito={registrarConflito}` (mesmo bloco) | **depois** | 188-12 |
| `abrirModalDoPainel` | `onAbrirModal={abrirModalDoPainel}` | **depois** | 188-11 |
| `precoComSimulacao` | `preco={precoComSimulacao}` (dentro do `const colunaDePreco`) | **depois** | 188-09 |

**A prova, em DUAS partes, nenhuma presumida:**
1. **a semântica da linguagem**, medida: o padrão exato **não lança** no ramo em que a JSX não é
   avaliada e **LANÇA `ReferenceError: Cannot access 'b' before initialization`** no ramo em que ela é;
2. **a ocorrência no arquivo**, medida por script sobre a fonte **sem comentário**, com **isca nas duas
   direções** (casa `onReprovar={alvo}` e `{...alvo}`; **não** casa `onReprovar={() => alvo(1)}`).

**Por que NENHUM gate pegou:** medido — **ninguém renderiza o `default export` da página**. Os gates
montam a página por `esbuild` e exercem **só named exports**; o componente **nunca é instanciado**.

**O que EU fiz:** **nada além de medir, travar e devolver.**
- ⚠️ **NÃO consertei** — são quatro fios de três waves da Fase 188, fora do que este plano causou;
- ⚠️ **as minhas edições não acrescentaram um quinto caso**, e isso está **medido e travado por
  gate**: o caso `⚠️⚠️ TDZ: VETO contra um QUINTO uso-antes-da-declaração …` é um **VETO POR
  SUBCONJUNTO** — ele **ACUSA um quinto** e **fica VERDE quando os quatro forem consertados**. ⚠️
  Igualdade (`deepEqual(infratores, HERDADOS)`) seria **gate que reprova pelo próprio conserto**;
- ⚠️ a sonda **NÃO é cega, e a prova é que ela ACHA os quatro** — *"isca que devolve ZERO é ACHADO"*:
  aqui ela devolve **QUATRO**;
- ⚠️ e o gate cobra que os quatro nomes **continuem existindo**, para a lista `HERDADOS` não passar a
  esconder um caso novo com nome diferente.

➡️ **DEVOLVIDO ao usuário / orquestrador.** **Não há fase dona:** a 188 está encerrada e as 190/191
estão **⛔ fora de escopo desde 20/10/2026**. ⚠️ **O conserto precisa de duas coisas:** a reordenação
dos quatro blocos **e** o gate que falta (um render do `default export` da página com `painel` de
payload — hoje **nada** o instancia).

---

## §19 — A FRONTEIRA desta wave, declarada

- ⚠️⚠️ **APROVAR ENFILEIRA, NÃO PUBLICA.** A escrita no Mercado Livre é da **Fase 193** (que, com o
  corte da 190/191, virou a **PRÓXIMA**; ordem: **189 → 193 → 194 → 192**). Este plano **não cria rota
  nenhuma**, **não toca `PublicacaoService`** (⚠️ que é o publicador **real** do Publicador, em
  `:442`, e é da 193) e **não toca `MlPublicacaoService`** (⚠️ que nem está no caminho do Publicador —
  ele recebe `MlAnuncioRascunho`, do assistente **antigo**).
- ⚠️ **Nada de "confirmar publicação", "desfazer", "segundo passo" ou "cancelar aprovação"** — gate de
  ausência **COM ISCA** recortado à **lista CONSTANTE** dos arquivos desta fase
  (`LightboxDaImagem.jsx`, `LateralDoLightbox.jsx`, `LightboxDoPainelService.php`), com as 14 regex
  conferidas contra um **cebo** (zero cegas) e a prova de que o recorte é **SUBCONJUNTO ESTRITO** do
  diretório `aprovacao/` (o painel inteiro legitimamente tem *"Aprovar para publicação"*).
- ⚠️ **"✦ Gerar novamente com IA" (AMPL-07) NÃO foi antecipado.** Dono: **Fase 192**, a **ÚLTIMA** da
  milestone. **Dono que AINDA EXISTE.**
- ⚠️⚠️ **"Comparar lado a lado na revisão detalhada" (AMPL-05) está SEM DONO (190/191 fora de escopo em
  20/10/2026)** — **nunca** *"fica para a Fase 190"*.
- ⚠️ A linha **"Variação"** continua **não desenhada** (INV-10/A3, decidido pelo usuário em 19/10): a
  chave do payload é **canônica** (`COLOR=id:52049`) e o rótulo humano está em território vetado.
  **Não reabri, e não vazei a chave canônica para a tela.**
- ⚠️ **`refLabel`** (protótipo `:1111`) continua **sem fonte** — item DEVOLVIDO pela 189-05. O conserto
  seria 1 linha **naquele serviço**, e a régua §3 manda **PARAR**. **Não toquei.**
- ⚠️ `publicador-lightbox-casca-189.test.js:1352` (`includes('createPortal')` mais largo que o
  invariante) continua **ABERTO**, **não consertado** — decisão do **189-08**.
- ⚠️ **Não usei a rota `mlb.anuncios.publicador.fotos.arquivo`** nesta wave (o `url_arquivo` já vem no
  payload), então **não acionei** o efeito colateral do `EditorEmUso::marcar()` por 3 minutos.
- ⚠️ **Nenhum `localStorage`/`sessionStorage`**, nenhum token novo, nenhum tamanho novo, nenhuma
  classe nova, nenhum hex cru, nenhum sétimo toast. **Nada nasce decorativo.**

---

## §20 — Onde o BRIEFING divergiu da minha MEDIÇÃO

| o briefing disse | a medição disse | o que eu fiz |
|---|---|---|
| *"Chame `escritasDaColuna({… ofertaId: contextoDoLb.oferta_id, versao: contextoDoLb.versao …})`"* | ✅ **confirmado** e é o que está no código | obedeci — §3.1 |
| *"`onAprovarFoto` e `onReprovar` estão `null` em `Aprovacao.jsx`"* | ✅ **confirmado** (`onAprovarFoto={null}` medido) | liguei os dois |
| *"o ESTADO DO LIGHTBOX MORA NA PÁGINA"* | ✅ **confirmado** | ➡️ **a função vive no painel, o JSX que a espalha vive na PÁGINA** — divergência declarada da prosa do plano (*"o JSX do painel"*) |
| *"INV-04 foi medido FORA … não refaça"* | ✅ **confirmado por render real** (árvore de ancestrais) | **não movi montagem nenhuma** |
| *"se um gate da 188 reprovar pela mudança, PARE e devolva"* (plano) | ⚠️ **o gate que reprovou estava CERTO e apontava um desvio MEU em relação ao próprio plano** | **não devolvi: consertei no meu lado**, com ramo separado, sem editar gate alheio — §6 |
| o plano: *"`acaoDoLightbox` monta `{rota, parametros, corpo, versao}` e entrega a `escreverNoPainel`"* | ⚠️ remontar a rota seria a **SEGUNDA CÓPIA** de `escritasDaColuna.aprovarImagem` (régua §2), e `company` **não está no contexto** | `acaoDoLightbox` **LÊ** os três do contexto e entrega `{tipo, oferta, versao, imagem, imagemId}` a um adaptador que chama `escritasDaColuna` → `escreverNoPainel`. **Declarado no docblock e aqui** |
| *"o índice absoluto depende da FIXTURA; use o INVARIANTE"* | ✅ **confirmado e MEDIDO nesta wave**: o índice do `z-[42]` é **12** com 1 foto e **15** com 4; a profundidade e a irmandade **não mudam** | gate ancorado em **profundidade + ancestrais**, nunca no índice — §7.1 |
| *"meça a isca de CSS com `grep -r`"* | ⚠️ **eu não medi na primeira redação**, e a isca escolhida (`bg-pub-page/[0.86]`) **É classe real do header** | medi as três com `grep -r` em `resources/` **e** no CSS: **0 nas duas pontas** — §13 furo 3 |
| *"pode aparecer um 12º gate fora do inventário A2; rode a suíte completa"* | ⚠️ **rodei, e NÃO há um 12º gate de ausência.** O que a suíte achou foi um gate **CERTO** (188-12) e um gate reprovado por **arquivo alheio** (188-07) | nenhum reaponte, nenhuma contagem alheia mexida — §5 |
| *"o `ResumoRapidoService` está em `Fila/`, não em `Aprovacao/Fila/`"* | não precisei dele | — |
| *"a árvore tem 27 `stash`"* | ✅ **27**, apenas **CONTADOS** | **não rodei `git stash` em forma nenhuma** |

### §20.1 — ⚠️ Um `key_link` do frontmatter do plano é satisfeito **só por COMENTÁRIO**

O plano declara:

```yaml
- from: "…/aprovacao/PainelDeRevisao.jsx"
  to: "ModalDeReprovarImagem"
  via: "o Reprovar do lightbox abre o MESMO modal do 188-12, com o contexto do lightbox, por cima"
  pattern: "ModalDeReprovarImagem"
```

**Medido:** `grep -c "ModalDeReprovarImagem"` em `PainelDeRevisao.jsx` devolve **1**, e a única
ocorrência é **um COMENTÁRIO** (`:1137`, a declaração que o 188-11 escreveu citando o AVISO-189-3).
Em `Aprovacao.jsx` ele devolve **3** — é lá que o modal é **importado, montado e submetido**
(INV-04: `Aprovacao.jsx:1249`, dentro do array `noModaisDoPainel`, ligado ao slot `modais`).

➡️ **O `from` do `key_link` é erro de REFERÊNCIA do plano**, da mesma família da divergência do
*"JSX do painel"* (§20): o painel **não** monta o modal, ele o recebe por **slot de NÓ**. ⚠️ E um
`pattern` satisfeito por **comentário** é a família *"palavra no docblock passa pelo motivo errado"* —
por isso ele está declarado aqui em vez de marcado como atendido em silêncio. **O elo REAL existe e
está provado por render** (§7): o `z-[42]` do modal nasce como **irmão** do `<aside>` do painel.

| `key_link` | `from` escrito | `from` REAL (medido) | elo provado? |
|---|---|---|---|
| `escreverNoPainel` | `PainelDeRevisao.jsx` | ✅ **correto** (4 ocorrências, e é lá que o helper vive) | sim |
| `ModalDeReprovarImagem` | `PainelDeRevisao.jsx` | ⚠️ **`Aprovacao.jsx`** (no painel só há 1 ocorrência, em COMENTÁRIO) | sim, por **render de árvore** |

---

## §21 — Threat flags

| threat | disposição | como ficou |
|---|---|---|
| **T-189-50** (escrita com a versão/oferta de BAIXO) | **mitigado** | `acaoDoLightbox` lê do **CONTEXTO**; fixture **A v3 × B v7** com isca; gate de fonte com recorte por âncora de texto; **mutações (i), (ii), (x) e (xi) MATAM** |
| **T-189-51** (aprovar a foto errada) | **mitigado** | imagem de `contexto.imagens[indice]`, com `idPositivoDoPainel`; a 3ª foto **não existe na B**; **mutações (iii) e (ix) MATAM** |
| **T-189-52** (escrita sem `versao_esperada`) | **mitigado** | **só** `escritasDaColuna` → `escreverNoPainel`; `router.post(` travado em **1** nas duas fontes, com isca; **mutações (vii-b) e (vii-c) MATAM** |
| **T-189-53** (modal invisível atrás do lightbox) | **mitigado** | prova por **ÁRVORE DE ANCESTRAIS** com **duas iscas opostas** + isca de token; montagem **não movida** (A1/INV-04); **mutação (iv) MATA** |
| **T-189-54** (contexto velho mostrando foto aprovada como pendente) | **mitigado** | **D2** (default declarado, pergunta ABERTA), nos dois caminhos (ação direta e modal); **mutação (vi) MATA** |
| **T-189-55** ("aprovar" virando publicação/segundo passo) | **mitigado** | gate de ausência **COM ISCA** recortado à lista CONSTANTE da fase; **aprovar ENFILEIRA** (a Fase 193 escreve no ML) |
| **T-189-SC** (npm installs) | **mitigado** | **este plano não instalou nada** — `package.json`/`package-lock.json` com `numstat` **ZERO** (PI + gate do 188-07) |

**Nenhuma superfície de segurança nova:** este plano **não cria rota**, **não toca `app/`**, **não
toca `routes/`**, **não lê banco** e **não chama o Mercado Livre**.

**Threat flag NOVO, fora do `<threat_model>` do plano:** ⚠️⚠️ **`threat_flag: availability`** —
`resources/js/Pages/Mlb/Publicador/Aprovacao.jsx`: **quatro leituras TDZ que derrubam a página
inteira** quando o painel abre com payload (**D-189-07-A**). ⚠️ **Pré-existente, não desta wave,
devolvido** — mas é indisponibilidade total de uma tela, e por isso vai flagueado.

---

## §22 — O que eu **NÃO** posso afirmar

1. **Não vi tela renderizada** (§11). *"A tela mostra X"* = *"o HTML gerado contém X"*.
2. **Não medi a suíte PHP completa** — ela **não é medível** nesta máquina (Fatal de memória em
   processo **FILHO**; `-d memory_limit=-1` **não** resolve). Medi **pelo RECORTE**, e digo isso.
3. **Não deployei, não fiz push, não toquei a VPS, não chamei a API do ML.** Nenhuma migration
   (**esta fase não tem** — e a que apareceu na árvore é de **outra sessão**, §1.1).
4. **Não rodei `state.advance-plan`** e **não toquei** `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md`
   nem `STATE.md` (GATE-07) — por instrução, e **provado pela PI**. Também **não editei**
   `189-REGUA.md`, `189-FID01.md`, `189-COBERTURA.md`, `189-CONTRATO-188.md` nem nenhum
   `189-NN-PLAN.md`.
5. **Não rodei `git stash` em forma nenhuma** (27 stashes, apenas **contados**), nem `git checkout`,
   nem `git reset`, nem `git clean`, nem `git commit --amend`, nem `git add -A`. **Não criei
   worktree** e **não rodei `pint`**.
6. **Não provei** que o clique, o `focus()`, a anexação da `ref` ou o portal portando funcionam no
   navegador — a premissa inobservável está **TRAVADA POR IGUALDADE DE FONTE e DECLARADA como PROXY DE
   FONTE** (§11), e as mutações (i)/(ii) provam que o proxy **morde**.
7. **Não consertei** nenhum dos itens de `deferred-items.md`, inclusive o **D-189-07-A**, que é grave.
8. ⚠️ **A `PERGUNTA-VISUAL-189` segue ABERTA**, e as conferências visuais das Fases **187** e **188**
   também — **as três separadas**, nenhuma presumida feita.

---

## Self-Check: PASSED

**Arquivos — `test -e`:**

```
FOUND  tests/js/publicador-lightbox-acoes-189.test.js                        (1430 linhas, 25 casos)
FOUND  tests/gates/contagens-189-07.txt                                      (138 linhas, 2 ids)
FOUND  .../aprovacao/PainelDeRevisao.jsx                                     (1354 linhas, era 1133)
FOUND  resources/js/Pages/Mlb/Publicador/Aprovacao.jsx                       (1827 linhas, era 1652)
FOUND  .../aprovacao/ModalDeReprovarImagem.jsx                               (444 linhas, INTOCADO)
FOUND  .planning/phases/189-.../deferred-items.md                            (114 linhas)
FOUND  .planning/phases/189-.../189-07-SUMMARY.md                            (este arquivo)
```

**Mínimo de linha do plano:** `publicador-lightbox-acoes-189.test.js` **1430 >= 240** ✅

**Commits — `git log --oneline --all | grep -q`:**

```
FOUND  42fc4752  feat(189-07): as acoes da foto com o CONTEXTO do lightbox — e o ramo separado que o gate do 188-12 exigiu
FOUND  95fb404f  test(189-07): o furo que o DESENHO DA CAMPANHA DE MUTACAO achou — nome do nome, nao a derivacao
```

**Nenhum dos dois apaga arquivo:** `git diff --diff-filter=D --name-only HEAD~1 HEAD` **vazio** nos
dois.

**Gates (exit code REAL do harness, não de um `tail` na frente):**

```
189-07 acoes-do-lightbox      -> exit=0   (25 / 25 / 0 / 0)
189-07 vocabulario-tamanhos   -> exit=0   (695, >=695)
188-07 casca-do-painel        -> exit=0   (45 / 45 / 0 / 0, contagem 45,41 INALTERADA)
188-08 coluna-de-fotos        -> exit=0   (32 / 32 / 0 / 0, contagem 32,28 INALTERADA)
188-12 modal-de-reprovar      -> exit=0   (19 / 19 / 0 / 0, contagem 19,17 INALTERADA)
188-12 modal-de-frete         -> exit=0   (22 / 22 / 0 / 0)
188-12 aviso-de-versao        -> exit=0   (12 / 12 / 0 / 0)
187-06 filtros-render         -> exit=0   (29 / 29 / 0 / 0, contagem 29,19 INALTERADA)
189-03 lightbox-casca         -> exit=0   (27 / 27 / 0 / 0)
189-04 camadas-e-css          -> exit=0   (16 / 16 / 0 / 0)
189-05 lateral-do-lightbox    -> exit=0   (27 / 27 / 0 / 0)
189-06 lightbox-no-painel     -> exit=0   (22 / 22 / 0 / 0)
```

**INTOCÁVEIS:** `publicador-header-zindex-ordem.test.js` **7 / 7 / 0** ·
`estrutura-meus-anuncios.test.js` (D-11/D-22) **30 / 30 / 0** · os **nove** gates JS e os **dois** PHP
de ausência de "Ampliar" **todos verdes e INTOCADOS**.

**Outros:** `--autoteste` **20 casos / 0 divergência**, `exit=0` ✅ · **bijeção** `ORFAOS=[]`,
`SEM_ID=[]`, **25/25** nomes com sufixo `_189_07`, **0** homônimos, **0** colisões ✅ ·
**PI(189-07)** 43 alvos, **0 fantasmas**, duas passadas **idênticas**, interseção **VAZIA**, **3 iscas
(a) ACUSAM**, isca (b) **limpa**, as **TRÊS faces declaradas** e os vizinhos medidos **À MÃO** ✅ ·
**mutação 13 rodadas / 12 que expressam a ameaça MORTAS / 1 investigada e substituída / 0 abortadas**,
`# tests` do mutado **IGUAL** ao pristino nas treze, `sha256` restaurado **IGUAL** nos dois arquivos ✅ ·
`npm run build` **exit 0 duas vezes**, com `git diff HEAD -- resources/` conferido **VAZIO antes de
cada uma**, e o **CSS provado pelo CORPO da regra** ✅

**Suítes:** JS **6005 / 6004 / 1 / 0**, `exit=0` (a única falha é a herdada da **Fase 87**, atribuída
por execução **ISOLADA**: 19 / 18 / 1) · PHP **RECORTE** (`tests/Unit/Publicador` +
`tests/Feature/Publicador`) **2609 / 23.931 assertions / 0 falhas**, `exit=0` — contra **2595 /
23.878 / 0** na PARTIDA, e o **Δ de +14 é o arquivo de teste da OUTRA SESSÃO** (`1253cc86`),
**medido e atribuído** (§2.2). ⚠️ A suíte PHP **completa não é medível** nesta máquina.

**Os cinco gates JS desta fase, rodados JUNTOS:** **117 / 117 / 0 / 0**
(`acoes-189` 25 + `painel-189` 22 + `lateral-189` 27 + `casca-189` 27 + `camadas-189` 16).

**Entrada morta:** `idDaRevisaoNoLb` e `originalDoLb` **saíram** de `Aprovacao.jsx` quando a mesma
regra passou a morar dentro de `propsDaLateralDoLightbox` (a ÚNICA fonte das props da lateral) —
*entrada morta é linha morta*, e duas leituras da mesma `originais` divergiriam. No estado final, todo
`const` e todo import dos dois arquivos é usado, e **nenhum `const` do gate é inútil**.
