---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 01
subsystem: publicador-aprovacao
tags: [lightbox, baselines, gates, fid-01, inventario, mutacao]
wave: 1
requires: [188-FECHAMENTO.md, 188-01..13-SUMMARY.md, design_handoff_publicador/Aprovacao.dc.html]
provides:
  - 189-BASELINES.md (as 9 linhas de forma fixa, medidas no HEAD de partida)
  - 189-CONTRATO-188.md (o inventario medido: 13 itens + 7 achados)
  - 189-FID01.md §I preenchida (7 divergencias classificadas) e §J (as confirmacoes)
  - text-[18px] liberado nas DUAS listas (TAMANHOS_PERMITIDOS e LIBERADOS_DEPOIS_DA_187)
  - tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines189Test.php (3 gates)
  - tests/js/publicador-lightbox-tokens-189.test.js (3 gates)
  - tests/gates/contagens-189-01.txt (5 ids, bijecao exata)
affects: [189-02, 189-03, 189-04, 189-05, 189-06, 189-07, 189-08]
tech-stack:
  added: []
  patterns: [escopo-do-assunto, lista-constante, isca-nas-duas-direcoes, trava-de-premissa-inobservavel]
key-files:
  created:
    - .planning/phases/189-.../189-BASELINES.md
    - .planning/phases/189-.../189-CONTRATO-188.md
    - tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines189Test.php
    - tests/js/publicador-lightbox-tokens-189.test.js
    - tests/gates/contagens-189-01.txt
  modified:
    - .planning/phases/189-.../189-FID01.md
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-tokens-187.test.js
decisions:
  - "O 18 e o UNICO tamanho novo da fase, medido por script (:741/:742)"
  - "A PI por escopo do assunto recorta pelo ASSUNTO, porque `git log --grep` le o CORPO"
  - "O conserto do gate 188-01 NAO foi aplicado: e territorio da Fase 188 — DEVOLVIDO"
metrics:
  duration: ~4h
  completed: 2026-10-20
  tasks: 3
  commits: 5
  mutacoes: 9
---

# Fase 189 Plano 01: a abertura medida do lightbox Summary

Abertura da Fase 189 **medindo** em vez de afirmando: gate de entrada provando a 188 fechada (14 de
14), as baselines em forma fixa com o comando de cada linha, o **inventário do contrato real** que a
188 entregou (13 itens, 43 ponteiros conferidos, **7 achados** que mudam os planos 05/06/07), a
`189-FID01.md` **re-medida por script** (7 divergências classificadas, 0 omissões) e o **único**
tamanho novo da fase liberado nas duas metades da régua.

⚠️⚠️ **UMA PERGUNTA BLOQUEANTE FOI DEVOLVIDA, E A ÁRVORE ESTÁ COM 1 FALHA NOVA POR CAUSA DELA** —
§8 deste documento. **Não contorne, não "conserte" o gate alheio sem decisão.**

---

## §1 — A PARTIDA

| item | valor |
|---|---|
| **PARTIDA** (primeira ação da sessão) | **`e250f9fb`** (`e250f9fba0a0b7309b2836142af6812d05f4616d`) |
| HEAD ao fim deste plano | **`1c4ff10f`** |
| commits deste plano (escopo do assunto `(189-01)`) | **5** |

⚠️ A `PARTIDA` foi usada **só como referência de leitura** (`git show PARTIDA:<arquivo>`), **nunca**
como extremo de range de gate de território — range cru não passa nesta árvore (187-11: 111 commits
no range, **43 da sessão paralela**).

**Os 5 commits:**

| hash | assunto |
|---|---|
| `337f29c5` | `test(189-01)`: o gate de ENTRADA da fase e as baselines MEDIDAS do lightbox |
| `9200277d` | `docs(189-01)`: o INVENTARIO MEDIDO do contrato real da 188 — 13 itens e 7 achados |
| `1329345b` | `test(189-01)`: a FID-01 re-medida por script (7 divergencias) e o `18` liberado — UM valor |
| `b73819c5` | `fix(189-01)`: a PI do bloco 3 recorta por ASSUNTO — `git log --grep` le o CORPO |
| `1c4ff10f` | `test(189-01)`: a mutacao SOBREVIVENTE investigada — o recorte por assunto TRAVADO por comportamento |

---

## §2 — AS BASELINES MEDIDAS (e as falhas atribuídas por execução ISOLADA)

### 2.1 — No HEAD de PARTIDA, antes de qualquer edição

| baseline | medido | comando |
|---|---|---|
| **BUILD** | **exit 0 · 85 s** | `S=$(date +%s); npm run build; echo $?; E=$(date +%s)` |
| **MANIFEST** | **498 entradas / 203 páginas** · isca AUSENTE · controle PRESENTE | script `.cjs` lendo o **JSON** |
| **JS** | **5871 / 5870 pass / 1 fail / 0 skipped** | `verificar-testes.sh --suite js` |
| **PHP (suíte do Publicador)** | **2583 / 23684 / 1** | `verificar-testes.sh --suite php` |
| **PROTÓTIPO** | **1432 linhas · `sha256 68624883ce35547e`** | `wc -l` + `sha256sum \| cut -c1-16` |

### 2.2 — Ao FIM do plano (depois da campanha de mutação e do rebuild)

| baseline | medido | veredicto |
|---|---|---|
| **BUILD** | **exit 0 · 87 s** | OK |
| **MANIFEST** | **498 / 203** · isca AUSENTE · controle `assets/Aprovacao-BOrPh3wq.js` | **IGUAL** |
| **JS** | **5874 / 5872 pass / 2 fail / 0 skipped** | +3 testes (meus) · ⚠️ **+1 falha — §8** |
| **PHP `Publicador/Aprovacao/`** | **331 / 5719 / 1** | +3 testes (meus); a falha é a ALHEIA de 2.4 |
| **PHP (suíte do Publicador)** | **2586 / 23718 / 1** | **+3 testes (os meus) · +34 asserções · a MESMA 1 falha alheia de 2.4 — ZERO regressão PHP** |

⚠️ **`npm run build` foi rodado DEPOIS da campanha de mutação e ANTES de medir a suíte JS** — o
`cp -p` atualiza o `mtime` e a camada de CSS dos gates **PULA**, produzindo `skipped` que **não é
regressão**. Medido: **0 skipped** nas duas pontas. ⚠️ E antes de cada build, `git diff HEAD -- resources/`
saiu **VAZIO** (nada de `.jsx` alheio meio-salvo).

### 2.3 — Comparação com a referência de 19/10 (nota de coordenação §8)

| número | 19/10 | re-medido 20/10 | veredicto |
|---|---|---|---|
| JS testes | 5498 | **5871** | ACIMA (+373) — esperado |
| JS falhas | 1 | **1** | IGUAL |
| PHP testes | 2510 | **2583** | ACIMA (+73) |
| PHP asserções | 22264 | **23684** | ACIMA — ⚠️ asserção **não é invariante**; compara-se testes e falhas |
| PHP falhas | 0 | **1** | ⚠️ **ACHADO** — alheia, §2.4 |
| MANIFEST entradas / páginas | 495 / 202 | **498 / 203** | ACIMA |

⚠️ **O briefing do orquestrador trazia outros números** (JS 5848/5847/1/0 · PHP 2577/23666/2 ·
MANIFEST 495/202). Os meus estão **acima** — esperado: o outro dev enviou 6 commits com testes novos.
**REGISTREI, NÃO AJUSTEI.**

### 2.4 — As falhas ALHEIAS, nomeadas por SEMÂNTICA, atribuídas por execução ISOLADA, **não corrigidas**

| falha | atribuição | execução isolada |
|---|---|---|
| **JS** `tests/js/estrutura-grade-glide.test.js` — *"Características secundárias nasce recolhido"* | **Fase 87**, pré-existente; é o `REF_JS_FALHAS=1` do harness | **19 / 18 pass / 1 fail / 0 skipped** — exatamente o que o briefing previa |
| **PHP** `tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php` → `test_a_galeria_final_ordenada_traz_url_do_arquivo_nos_DOIS_casos_188_03`, em `:609` | **gate da 188-03 × commit `cd582f83` do OUTRO dev** | **1 failed (33 assertions)** — falha **também sozinha**, não é ordem nem corrida |

⚠️ **A dupla de `CockpitDaContaTest` (Fase 179) está VERDE hoje:** `50 passed (2394 assertions)` em
isolamento. O briefing a esperava vermelha (ela oscila com a hora do dia). ⚠️ E o caminho
`tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` **NÃO EXISTE** — o real está **FORA** de
`Aprovacao/`. ⚠️ As duas de `SincronizaPortalCompletoTest` **não apareceram**.

⚠️ **NÃO criei `REF_PHP_FALHAS`.** ⚠️ **A régua segue: qualquer OUTRA falha É DA FASE.**

#### ⚠️⚠️ A falha PHP alheia, investigada até a causa — e é uma classe de fragilidade

O gate 188-03 **fixa o NÚMERO DE LINHA** dos cinco leitores de `EditorEmUso::`. Medido por script
(`deslocados-189-01.cjs`): **quatro certos, UM deslocou +1**.

| ponteiro fixado | ocorrências REAIS hoje | veredicto |
|---|---|---|
| `app/Services/Publicador/Fila/AgendadorDaFila.php:337` | `337` | OK |
| `app/Services/Publicador/PreparoIaDoRascunhoService.php:131` | **`132`, `181`, `367`** | ⚠️ **+1** |
| `app/Support/Publicador/GarantiaPadrao.php:192` | `192` | OK |
| `app/Services/Publicador/ExcluirProdutoService.php:195` | `195`, `260` | OK |
| `app/Http/Controllers/MlbPublicadorController.php:405` | `405` | OK |

**Quem moveu:** commit **`cd582f83`** — *"feat(portal): título de venda separado do nome…"* (19/10),
**do outro dev**, acrescentou uma linha acima de `:131`.
**Prova de que não é minha:** `git diff HEAD --stat -- app/ tests/ routes/ resources/ config/ database/`
saiu **VAZIO** no início da wave — este plano não editou **uma linha** de código de produção.

⚠️⚠️ **E isto corrige o BRIEFING desta sessão**, que cita
`PreparoIaDoRascunhoService:131`/`:180`/`:366`. **Medido: `:132`/`:181`/`:367`** — os três +1.

⚠️ **NÃO corrigida, de propósito:** o conserto é somar `+1` num gate da **Fase 188**. O conserto de
RAIZ não é o `+1` — é o gate procurar `EditorEmUso::` **por semântica** em vez de por número de linha.
**Decisão do dono da 188.**

---

## §3 — ⚠️⚠️ O INVARIANTE FALSO, MEDIDO: componente novo **NÃO** entra no manifest

**Medido pelo JSON de `public/build/manifest.json`, nas duas pontas do plano:**

```
COMPONENTE_NO_MANIFEST[PainelDeRevisao.jsx]      = NAO
COMPONENTE_NO_MANIFEST[ImagemGrandeDoPainel.jsx] = NAO
CONTROLE  resources/js/Pages/Mlb/Publicador/Aprovacao.jsx = PRESENTE -> assets/Aprovacao-BOrPh3wq.js
ISCA      resources/js/Pages/Mlb/NaoExiste9z7.jsx          = AUSENTE
ENTRADAS=498  PAGINAS=203
```

A Fase 188 criou **19 `.jsx`** e **nenhum** componente de `aprovacao/` está no manifest.
➡️ **Componente aninhado é FUNDIDO no chunk da PÁGINA; só a PÁGINA aparece.**

⚠️⚠️ **Portanto: se algum gate da `189-REGUA.md` ou de um plano 189-02..189-08 afirmar que o
componente novo entra no manifest, ele é FALSO** — vazio por construção (se só conferir ausência) ou
**reprova trabalho correto** (se exigir presença). **O que se confere no manifest é a PÁGINA.** Os
`.jsx` do lightbox (`LightboxDaImagem.jsx`, `LateralDoLightbox.jsx`) **não vão aparecer**, e isso é o
comportamento certo. ⚠️ E o manifest é lido **pelo JSON**, nunca por `grep`: o hash sai às vezes com
hífen.

---

## §4 — A FID-01 RE-MEDIDA POR SCRIPT

**Como:** script `.cjs` **escrito por ferramenta** (`fid01-189-01.cjs`) extraindo **todo** atributo
`style="…"` das faixas `604`-`607` e `726`-`772`, com `cursor`, `white-space` e `text-overflow` **em
colunas próprias** e a **1ª propriedade** de cada `style`; cada ponteiro reconfirmado por
`sed -n '<N>p'`. **Diretório citado: `design_handoff_publicador/`** (`new_layout_publicador/Aprovacao.dc.html`
**NÃO existe** — medido).
⚠️ **Nunca por heredoc:** medido — o heredoc do shell **come a barra invertida** e a regex sai
quebrada (meu primeiro script morreu assim).

### 4.1 — A tabela medida (`cursor` / `white-space` / `text-overflow` em colunas próprias)

| ponteiro | cursor | white-space | text-overflow | 1ª propriedade |
|---|---|---|---|---|
| `:604` | — | — | — | `position:absolute` |
| `:605#1` | — | `nowrap` | — | `white-space:nowrap` |
| `:605#2` | — | — | — | `width:10px` |
| `:606#1` | `pointer` | `nowrap` | — | `white-space:nowrap` |
| `:606#2` | — | — | — | `color:#FACC15` |
| `:607` | **`pointer`** | **`nowrap`** | — | `white-space:nowrap` |
| `:727` | — | — | — | `position:fixed` |
| `:728` | — | — | — | `position:relative` |
| `:729#1` | — | — | — | `display:flex` |
| `:729#2` | — | — | — | `color:#F4F4F5` |
| `:730` | **`{{ LB.cursor }}`** | — | — | `position:relative` |
| `:731` | — | — | — | `position:absolute` |
| `:733` | — | — | — | `position:absolute` |
| `:736` | — | — | — | `display:flex` |
| `:738#1` | — | — | — | `position:relative` |
| `:738#2` | **`pointer`** | — | — | `position:absolute` |
| `:741` | **`pointer`** | — | — | `position:absolute` |
| `:742` | **`pointer`** | — | — | `position:absolute` |
| `:744` | — | — | — | `background:#0D0D0D` |
| `:745#1` | — | — | — | `display:flex` |
| `:745#2` | **`pointer`** | **`nowrap`** | — | `white-space:nowrap` |
| `:746` | — | — | — | `display:grid` |
| `:747#1` | — | — | — | `color:#71717A` |
| `:747#2` | — | — | — | `font-weight:600` |
| `:748` | — | — | — | `color:#71717A` |
| `:749` | — | — | — | `color:#71717A` |
| `:750#1` | — | — | — | `color:#71717A` |
| `:750#2` | — | — | — | `color:{{ LB.stColor }}` |
| `:753` | — | — | — | `display:flex` |
| `:754` | — | — | — | `font-size:12px` |
| `:755` | — | — | — | `position:relative` |
| `:756` *(§G, Fase 190)* | **`pointer`** | **`nowrap`** | — | `white-space:nowrap` |
| `:760#1` | — | — | — | `display:flex` |
| `:760#2` | — | — | — | `width:16px` |
| `:762#1` *(§G, Fase 192)* | **`pointer`** | **`nowrap`** | — | `white-space:nowrap` |
| `:762#2` *(§G, Fase 192)* | — | — | — | `color:#FACC15` |
| `:763` | **`pointer`** | **`nowrap`** | — | `white-space:nowrap` |
| `:765` | — | — | — | `display:flex` |
| `:766` | **`pointer`** | **`nowrap`** | — | `white-space:nowrap` |
| `:767` | **`pointer`** | **`nowrap`** | — | `white-space:nowrap` |

**`text-overflow`: NENHUMA ocorrência em nenhuma das faixas.** ⚠️ `cursor:pointer` aparece em **8**
atributos e `white-space:nowrap` em **8** — e em **6** deles o `nowrap` é a **PRIMEIRA propriedade da
linha**, que é exatamente onde a omissão mais aconteceu em fases anteriores.

### 4.2 — As 7 divergências, classificadas (§I da `189-FID01.md`)

**Placar: 0 `OMISSÃO` · 3 `REFERÊNCIA` · 4 `VALOR` · 0 de medida de estilo.**

| § | classe | o que a tabela dizia | o que o disco diz |
|---|---|---|---|
| I.1 | `OMISSÃO` | — | **nenhuma**: `NAO_CITADOS=[]` sobre os 40 atributos × 70 ponteiros |
| I.2 | `VALOR` | "33 **linhas** de tabela em escopo" | **33 ATRIBUTOS em 31 LINHAS** (C5 e C8 pareiam dois cada) |
| I.3 | `VALOR` | "+2 em `604`/`607`" | a faixa tem **SEIS** atributos; os 4 de `:605`/`:606` são de outras fases |
| I.4 | `VALOR` | `pub-fase1` "precedente: 9 usos" | **32 ocorrências em 19 arquivos** |
| I.5 | `VALOR` | `rounded-[8px]` "precedente: 8 usos" | **27 ocorrências em 15 arquivos** |
| I.6 | `REFERÊNCIA` | MOD-06 em `:821`-`:831` | `:821` está no ramo **`M.isReject`** (OUTRO modal); `M.isReplace` é **`:866`-`:875`**, título em **`:1422`** |
| I.7 | `REFERÊNCIA` | `ROADMAP.md:4717` | a linha real é **L4730** |
| I.8 | `REFERÊNCIA` | Coverage Map `:5025` | a linha real é **L5038** |

⚠️ **I.7 e I.8 deslocaram pelo MESMO valor (+13) e pelo MESMO motivo: o `ROADMAP.md` é documento VIVO
e cresceu** (o fechamento da 188 acrescentou bloco aditivo). ➡️ **Ponteiro de linha para documento
vivo apodrece sozinho.** A notação foi corrigida junto: **`:N` é EXCLUSIVO do protótipo**; linha de
outro arquivo vai como **`L<N>`**.

⚠️ **I.4/I.5 ficaram com a ressalva escrita** de que são afirmação de **existência de precedente**,
não número-régua — para nenhuma fase futura "atualizá-las" só para o gate passar.

### 4.3 — O que a re-medição **CONFIRMOU** (§J), e por que vale registrar

- **J1 — os DOZE ponteiros do view-model (`:1014`-`:1330`) estão TODOS CERTOS.** ⚠️ **Isto contraria
  o aviso do briefing**, que diz que aquela região *"está PIOR"* e que a 188-02 achou lá **6**
  deslocados (−1 a −10). **Nesta tabela: zero.** A diferença é que a §E nasceu medida por `sed`.
- **J2 — as seis varreduras `awk`/`grep` da §A batem byte a byte** (`cursor` em
  `730 738 741 742 745 756 762 763 766 767`; `nowrap` em `745 756 762 763 766 767`; `text-overflow`
  nenhuma; `font-size` `9.5 11.5 12 12.5 13 18`; `border-radius` `7px 8px 12px 50%`; `font-weight`
  `500 600 800`; **34** atributos em **27** linhas em `726-772`).
- **J3 — a §T confere INTEIRA:** os **20 hexes** e os **4 raios**, com isca nas duas direções
  (`txt` → `#F4F4F5`; `txt` **não** casa `txt-2`; chave inventada → `null`). `INFRATORES=[]`.
- **J4 — o protótipo não se moveu** (1432 linhas, `sha256 68624883ce35547e`).
- **J5 — confirmações que o 189-05/189-06 consomem:** `:604` e `:607` exatos; `:633` (painel) **tem**
  `align-items:flex-start` e `:760#1` (lightbox) **não** — a §C12 está certa; `:369` byte a byte;
  `:732` `fit="contain"`; `:752`/`:764`; `:317`-`:320` (§G, certo).

⚠️ **Nenhum QUARTO/QUINTO conflito protótipo × regra do projeto apareceu nesta wave.**

---

## §5 — O INVENTÁRIO DO CONTRATO REAL DA 188: 13 itens e **7 achados**

`189-CONTRATO-188.md` — **13 itens** em forma fixa, **43 ponteiros únicos** (54 citações) **todos**
apontando para dentro do arquivo, cada item com o **comando** ao lado.
⚠️ A primeira redação citava **22 ponteiros por NOME NU** de arquivo e **o próprio gate os acusou** —
nome nu não é resolvível, e é dessa ambiguidade que saíram os dez caminhos fantasma da milestone.

| achado | o que muda |
|---|---|
| **A1** (INV-04 = **`FORA`**) | O `ModalDeReprovarImagem` já é **IRMÃO do `<aside>`** — medido por **profundidade estrutural**: `<aside>` abre em `PainelDeRevisao.jsx:711`, **fecha em `:715`**, e o slot `modais` está em **`:717`**. ➡️ **O AVISO-189-3 foi atendido e o 189-07 NÃO precisa mover a montagem.** |
| **A2** | O gate de ausência de "Ampliar" **não é um: são NOVE em JS e DOIS em PHP**. Oito das nove são recortadas a `FONTES_DO_PLANO`. ➡️ O 189-06 inverte **só** `publicador-painel-fotos-188.test.js:639`/`:640` (render); `:641` (Gerar novamente) **fica**; e **`publicador-painel-casca-188.test.js:1035` varre a FONTE de `PainelDeRevisao.jsx`** — ⚠️ **uma prop chamada `onAmpliar` reprovaria** (o docblock é seguro: a fonte é lida por `lerSemComentarios`). |
| **A3** (INV-10) | **NÃO existe rótulo de variação desenhado.** A §C7 fica sem fonte, e a régua §2 resolve (*"a 189 usa o que o 188-08 usar"* = nada). Lacuna **já decidida pelo usuário** — **não é um quinto conflito**. |
| **A4** | Os comentários da 188-08 (`BlocoDaImagemSelecionada.jsx:68`/`:297`) mandam ver o constante **`VARIACAO`**, que **não existe** no arquivo. Erro de REFERÊNCIA em docblock alheio — registrado, não consertado. |
| **A5** (INV-05) | O wrapper `:604` **existe** como named export `CLASSES_OVERLAY` (`ImagemGrandeDoPainel.jsx:205`), com o estilo exato do protótipo, mas **só nasce com `regenerating`** (`:372`). ➡️ O 189-06 **afrouxa a CONDIÇÃO**, não cria o wrapper. |
| **A6** (INV-03) | `escritasDaColuna` (`ColunaDeFotos.jsx:209`) é **named export** e recebe `ofertaId`/`versao` **por parâmetro**. ➡️ A **PERGUNTA-189-B** se resolve **sem rota nova e sem helper novo**. |
| **A7** (INV-01) | O degrau do lightbox no `Escape` **não existe no código** — está só **declarado em prosa** (`PainelDeRevisao.jsx:479`, `:560`). ➡️ O 189-06 **acrescenta o parâmetro**. |

**Outros dois itens que destravam planos seguintes:**
`INV-12` = `vocabulario-tamanhos` **681** (igual à 188-12: este plano **não cria `.jsx`**, e acrescentar
valor a um `Set` **não cria sub-teste**) · `INV-13` = **não há** asserção de conjunto fechado nas
props-raiz (as duas `assertSame(array_keys(…))` de `FilaDeAprovacaoRotaTest.php:503`/`:505` são sobre
chaves **aninhadas**) ➡️ **o 189-02 pode acrescentar a prop-raiz `lightbox`**.

---

## §6 — AS LISTAS CONSTANTES: a que mexi, e as que NÃO mexi (com a razão)

### A ÚNICA que este plano tocou

| lista | arquivo | o que entrou |
|---|---|---|
| **`LIBERADOS_DEPOIS_DA_187`** | `tests/js/publicador-fila-tokens-187.test.js` | **uma LINHA PRÓPRIA** com o `18`, o plano e a medida (`Aprovacao.dc.html:741/:742`). ⚠️ A linha do **`21`** (188-01) ficou **byte a byte**, com asserção cobrando. |

E a outra metade da liberação: **`TAMANHOS_PERMITIDOS`** (`tests/js/publicador-vocabulario-novo.test.js`),
com o `18` **em ordem numérica** e a medida no docblock.
⚠️ **Medido: a faixa do lightbox usa `9.5 11.5 12 12.5 13 18`** — os outros cinco **já estavam**.
**Nada liberado "por precaução": só o 18.**
⚠️ **`verificar-testes.sh 187-01 tokens-da-fila` continua verde sem retoque** (`# tests 17`,
contagens aceitas `17,10`).

### As que **NÃO** mexi, e a razão MEDIDA de cada uma

| lista | razão |
|---|---|
| `ARQUIVOS_NOVOS` (`publicador-vocabulario-novo.test.js`) | **este plano não cria `.jsx`**. `DIRETORIOS_FECHADOS` = `[identidade, visaogeral, publicacoes, aprovacao]` e o GATE-03 compara por `deepEqual` os `.js`/`.jsx` do DISCO — sem arquivo novo sob `resources/`, nada a declarar. **GATE-03 verde** (`ok 1 — nenhum arquivo … fica FORA de ARQUIVOS_NOVOS`). |
| `JSX_DEPOIS_DA_187` (`publicador-fila-fechamento-187.test.js`) | idem. São **duas** escriturações de propósito (um conserto da 188 **recusou** criar a terceira); as duas ficam intocadas. |
| `SLOTS_DE_NO_DEPOIS_DA_187` (3 pontas) | este plano **não abre slot de NÓ** e **não toca** `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx`. |
| `ESTADO_LOCAL_DA_PAGINA` (`publicador-fila-filtros-187.test.js`) | este plano **não acrescenta `useState`** à página. |
| `QUEM_USA_SHADOW_PUB_PAINEL` (`publicador-fila-tokens-187.test.js`) | nenhum arquivo deste plano escreve `shadow-pub-painel`. |
| `VARRIDOS_DECLARADOS` (`publicador-rename-em-analise-ml.test.js`) | **MEDIDO:** ela declara **três** diretórios (`publicacoes`, `identidade`, `visaogeral`) e **não** inclui `tests/js` nem `aprovacao/` — o `.test.js` novo **não entra nela**. |

⚠️ **Nenhum id alheio foi levantado.** O único id remedido é o desta fase.

---

## §7 — OS GATES: manifesto, bijeção, homônimo e a campanha de mutação

### 7.1 — O `--autoteste`, colado

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA >=: 2 verdes com >=2 (igual ao minimo) -> PASSA (como esperado)
  OK   caso 5: ISCA >=: 2 verdes com >=1 (acima do minimo, teste novo so soma) -> PASSA (como esperado)
  OK   caso 6: ISCA >=: 2 verdes com >=5 (encolheu ou nao carregou) -> REPROVA (como esperado)
  OK   caso 7: ISCA >=: falha nao e perdoada pelo >= (o '# fail 0' vem ANTES) -> REPROVA (como esperado)
  OK   caso 8: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 9: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 10: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 11: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 12: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 13: No tests found. -> REPROVA (como esperado)
  OK   caso 14: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 16: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 18: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 19: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 20: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

### 7.2 — Os 5 ids, todos verdes, chamados com `189-01` como 1º argumento

| id | modo | contagem MEDIDA |
|---|---|---|
| `baselines-medidas` | php | `tests=1 assertions=11` |
| `contrato-188-inventariado` | php | `tests=1 assertions=10` |
| `fid01-189-remedida` | php | `tests=1 assertions=13` |
| `tokens-e-tamanhos-189` | js | `tests=3` |
| `vocabulario-tamanhos` | js | **`tests=>=681`** — em **LINHA PRÓPRIA**, forma `>=`, N medido nesta tarefa |

⚠️ **O 1º argumento é `189-01`, nunca `01`** (o número nu resolve `contagens-186-01.txt`, a fase
errada, em silêncio — e os casos 15/16 do `--autoteste` guardam essa compatibilidade de propósito).

### 7.3 — Bijeção id ↔ `<verify>`, nos dois sentidos, por script

```
IDS_NO_MANIFESTO=5   IDS_NO_VERIFY=5
ORFAOS (no manifesto, fora do verify)=[]
SEM_MANIFESTO (no verify, fora do manifesto)=[]
DUPLICADOS_NO_MANIFESTO=[]
METODOS_NO_ARQUIVO=3   SEM_ID=[]   SEM_METODO=[]
metodos por id: baselines-medidas=1 · contrato-188-inventariado=1 · fid01-189-remedida=1   (max 3)
```

⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA, com uma CORREÇÃO MEDIDA ao briefing.** O briefing diz que
`/test_[A-Za-z0-9_]+/` *"NÃO casa `test_(a|b|c)`"*. **Medido: ele casa ZERO**, não "um" — a regex
ingênua fica **totalmente cega** diante de um manifesto só com alternância, e é por isso que o furo
produziu `SEM_ID=14`. O expansor lê a alternância corretamente (isca:
`test_a_galeria_vazia_e_o_clamp_nas_DUAS_pontas_188_03` é encontrado dentro de um `test_(a|b|c)`).

### 7.4 — Homônimo contra as Fases 186, 187 e 188 (todas EM PRODUÇÃO)

```
ARQUIVOS=1670  METODOS=7879  MEUS=3
HOMONIMOS=0  PREFIXOS=0
ISCA_ACUSA[test_territorio_e_ausencias_do_plano]=7
ISCA_ACUSA[test_as_baselines_da_fase_188_estao_medidas_e_nao_afirmadas]=1
ISCA_ACUSA[test_a_tabela_fid01_declara_como_mediu_e_lista_as_divergencias]=1
ISCA_TOTAL_ACUSADO=9 (a sonda VÊ homônimo)
ISCA_INVENTADO=0
```

⚠️ **A primeira isca que escrevi devolveu ZERO** (os meus nomes sem o sufixo não casavam nada,
porque são longos e novos). **"Zero" numa isca é ACHADO, não tranquilidade** — troquei por nomes-base
que eu **sei** serem substring de métodos reais, e aí a sonda acusou **9**. Só então `HOMONIMOS=0`
passou a significar algo.

### 7.5 — A campanha de mutação (`mutar-189-01.sh` — nome **com o plano**)

⚠️ **O nome carrega o plano porque o scratchpad é COMPARTILHADO entre subagentes paralelos** — na 187
um script de nome genérico foi sobrescrito e a campanha teve de ser refeita inteira.
**Disciplina em cada mutação:** commit ANTES · `cp -p` para o scratchpad · ocorrência **travada em 1**
por **string exata** (`grep -o | wc -l`, porque `grep -c` conta **linhas**) · linha conferida depois ·
aplicação provada por **divergência de `sha256`** (e não por "a âncora sumiu") · `php -l` quando PHP ·
restauração **DA CÓPIA** em `trap EXIT` · `sha256` contra `git show HEAD:` com **`tr -d '\r'` nos dois
lados**, idêntico ANTES e DEPOIS. ⚠️ **`git checkout` NUNCA.**

| # | mutação | gate | veredicto |
|---|---|---|---|
| i | `189-BASELINES.md`: os números do JS trocados por travessões | `baselines-medidas` | **MORREU** |
| ii | `FechamentoDeBaselines189Test.php`: `188-FECHAMENTO.md` → nome inexistente | `baselines-medidas` | **MORREU** (no gate de ENTRADA) |
| iii | `189-CONTRATO-188.md`: `INV-04` apagado | `contrato-188-inventariado` | **MORREU** |
| iv | `189-CONTRATO-188.md`: `INV-04` apontando para a linha `99999` | `contrato-188-inventariado` | **MORREU** (*"fora do arquivo (ele tem 863 linhas) — erro de REFERÊNCIA"*) |
| v | `publicador-fila-tokens-187.test.js`: `18` fora de `LIBERADOS_DEPOIS_DA_187` | `tokens-e-tamanhos-189` | **MORREU** |
| vi | `publicador-vocabulario-novo.test.js`: `18` fora de `TAMANHOS_PERMITIDOS` | `tokens-e-tamanhos-189` | **MORREU** |
| vii | `189-FID01.md`: a **§I** apagada | `fid01-189-remedida` | **MORREU** (*"a §I das divergências não existe"*) |
| viii | `publicador-lightbox-tokens-189.test.js`: escopo `(189-01)` → `(187-01)` | `tokens-e-tamanhos-189` | **MORREU** (acusou `tailwind.config.js`) |
| ix | `publicador-lightbox-tokens-189.test.js`: o recorte por ASSUNTO apagado | `tokens-e-tamanhos-189` | ⚠️ **SOBREVIVEU** → investigada → **agora MORRE** |

**9 mutações · 1 evento de sobrevivência · investigado até a causa · lacuna REAL fechada.**

⚠️ **E a mutação (ix) achou um defeito de verdade, em duas camadas:**

1. **`git log --grep` casa a MENSAGEM INTEIRA (assunto + corpo), não só o assunto.** Prova medida:
   `git log --grep='(186-01)' --fixed-strings` devolve `e1fa96d3` e `90dfa946`, cujo assunto é
   **`docs(188-13)`** — eles casam porque **citam `(186-01)` no CORPO**.
   ➡️ Sem recorte por assunto, bastaria uma fase futura **mencionar `(189-01)` no corpo** para os
   arquivos daquele commit entrarem na interseção e o gate **REPROVAR TRABALHO CORRETO**. Consertado
   em `b73819c5`.
   ⚠️⚠️ **E isto CORRIGE o briefing**, que dizia que um commit da 188 carregando escopo `(186-01)`
   *"NÃO é capturado pelo filtro da fase"*. **É o contrário: o filtro SOBRE-captura.**
2. **A proteção do recorte é INOBSERVÁVEL no escopo desta fase** (hoje nenhum commit cita `(189-01)`
   só no corpo), e foi por isso que a mutação sobreviveu. ➡️ *"Premissa inobservável se TRAVA, não se
   descarta"*: travada **por COMPORTAMENTO** em `1c4ff10f`, no escopo `(186-01)`, onde a diferença
   **existe e é medida** — `--grep` cru devolve **mais** commits que o recorte, e cada commit
   "só no corpo" tem de ficar **FORA**. Com a trava, a mutação (ix) **MORRE**.

### 7.6 — Outros furos das minhas próprias sondas, achados e consertados nesta wave

| furo | como apareceu |
|---|---|
| o **heredoc come a barra invertida** → regex quebrada | `SyntaxError: Invalid regular expression` no 1º script. ➡️ **todo `.cjs` passou a ser escrito por FERRAMENTA** |
| `\z` **não existe em JS** (é PCRE) | a regex de recorte de seção **nem compilou**. ➡️ recorte **por linha** em JS; `\z` só no PHP |
| `$` sob `/m` casa **fim de linha** → corpo da seção **VAZIO** | **a minha própria isca `CORPO_G_NAO_VAZIO` pegou** |
| sonda de token **cega** (regex errada para a forma do config) | acusou **20 infratores** que existiam. ➡️ recorte do bloco `pub: {` por **contagem de profundidade** + iscas nas duas direções |
| **22 ponteiros por NOME NU** no inventário | **o meu próprio gate os acusou** |
| 3 ponteiros do `ROADMAP` escritos com a notação do protótipo | **o meu próprio gate de `:N <= 1432` os acusou** |
| o literal `VERIFICAÇÃO AUTOMÁTICA · APOIO` **ausente** do contrato de UI | **o meu próprio gate FID-01 acusou** |

---

## §8 — ⚠️⚠️ PERGUNTA BLOQUEANTE DEVOLVIDA: o gate 188-01 reprova o `18` MEDIDO

**O plano 189-01 manda liberar o `18`** (tarefa 3b). **Eu liberei, pela via canônica do projeto.**
**E um gate da Fase 188 reprova isso.**

**A colisão, medida:**

`tests/js/publicador-painel-tokens-188.test.js:126` tem uma **blocklist LITERAL de "valores sem
medição"** que inclui o **18**:

```js
const naoMedidos = [18, 19, 21.5, 23, 25, 26].filter((v) => lista.includes(v));
assert.deepEqual(naoMedidos, [], `valores sem medição em TAMANHOS_PERMITIDOS: ${naoMedidos.join(', ')}`);
```

**Resultado:** `TOK-188-01 — '21' está em TAMANHOS_PERMITIDOS, e '23' NÃO está (isca invertida)`
falha com `valores sem medição em TAMANHOS_PERMITIDOS: 18`. Aquele arquivo fica **8/9**.

⚠️⚠️ **O BRIEFING DESTA SESSÃO ESTAVA ERRADO SOBRE ISSO.** Ele diz: *"`publicador-painel-tokens-188.test.js`
exige `21` presente e `23` AUSENTE — o 18 não colide com nenhuma das duas asserções."* **Colide com
uma TERCEIRA asserção, no mesmo caso de teste, que o briefing não menciona.**

**Por que é contradição real, e não erro meu:**
- o `18` **TEM medição**: `Aprovacao.dc.html:741`/`:742`, `font-size:18px` com `cursor:pointer`,
  medido por script e **declarado em `LIBERADOS_DEPOIS_DA_187` com o plano e a medida** — que é
  **exatamente o mecanismo que o docblock daquela lista prescreve** (*"QUEM AMPLIAR A RÉGUA
  ACRESCENTA UMA LINHA AQUI"*);
- o gate 188-01 diz *"18 **NÃO** pode estar em `TAMANHOS_PERMITIDOS`"* e o plano 189-01 diz
  *"18 **TEM** de estar em `TAMANHOS_PERMITIDOS`"*. **Não há conserto do meu lado:** os dois falam do
  **mesmo literal**, em direções opostas. Deixar o `18` fora derrubaria a tarefa 3 e obrigaria o
  lightbox a arredondar as setas para 17px ou 20px — a aproximação que **FID-02/FID-03 vetam**.

**⚠️ NÃO EDITEI `tests/js/publicador-painel-tokens-188.test.js`** — ele **não está** na lista
"PODE editar" da `189-REGUA.md` §8, e *"ajustar régua alheia para o meu trabalho passar"* é
precisamente o hábito que `feedback_gate_que_reprova_pelo_proprio_conserto` existe para impedir.
A instrução desta sessão é explícita: **"PARE e devolva a pergunta se um gate alheio reprovar e o
conserto certo não for do seu lado."**

### As duas opções, com a MEDIÇÃO de cada uma

| opção | mudança | medido |
|---|---|---|
| **A (mínima)** | tirar `18` da blocklist literal: `[19, 21.5, 23, 25, 26]` | **MEDIDO: o arquivo fica 9/9 verde.** Apliquei **temporariamente**, medi e **restaurei da cópia** — `sha256` idêntico ao `HEAD` |
| **B (raiz, recomendada)** | derivar a blocklist de `LIBERADOS_DEPOIS_DA_187`: um valor **sai** da lista de "não medidos" quando uma fase o declara **com plano e medida** | não medida em código (minha 1ª simulação usou `require` num módulo **ESM** e falhou — defeito da simulação, não da ideia). ⚠️ É a que remove a fragilidade **para as Fases 190-194**, e não só para o 18 |

**Minha recomendação: B**, porque a semântica da lista é *"sem medição"* e a medição agora existe;
A resolve hoje e deixa a mesma armadilha para a próxima fase que medir um tamanho novo.
⚠️ **Nenhuma das duas foi aplicada.**

### O estado da árvore, declarado

⚠️⚠️ **O `HEAD` (`1c4ff10f`) tem 1 falha JS NOVA, e ela é esta colisão** (a outra é a herdada da
Fase 87). **Árvore vermelha no HEAD pode BARRAR DEPLOY** — por isso isto está no topo deste
documento. **Não contorne o gate; decida entre A e B.**

---

## §9 — TERRITÓRIO: PI(189-01) por ESCOPO DO ASSUNTO

⚠️ **`git diff --numstat -- <alvos>` "sai vazio" NÃO foi usado**: depois do commit ele compara a
ÁRVORE com o índice e sai vazio **sempre** — gate **vazio por construção**. ⚠️ **Nem range**
(187-11: 111 commits, 43 da sessão paralela). ⚠️ **Nem autor** (as três máquinas usam o mesmo
`user.name`).

```
(1) ANTI-FANTASMA: ALVOS=24  INEXISTENTES=0
(2) COMMITS_DO_ESCOPO=3 (na 1ª rodada) → 5 ao fim; todos com (189-01) no ASSUNTO
(3) PASSADA 1 (git show --name-only por commit): CAMINHOS_P1=8
(4) PASSADA 2 (um git log --name-only só):       CAMINHOS_P2=8   → AS DUAS PASSADAS CONCORDAM
    INTERSECAO com os 24 alvos vetados = VAZIA
(5a) ISCA QUE PROVA QUE A SONDA VE:
     OK (187-01) ACUSA tailwind.config.js
     OK (188-07) ACUSA .../aprovacao/PainelDeRevisao.jsx
     OK (188-08) ACUSA tests/js/publicador-painel-fotos-188.test.js
(5b) ISCA DA DIRECAO CONTRARIA: VAZAMENTO=0; nenhum commit do meu escopo casa
     '(188-', '(190-', '(portal' ou '(polos'
PI(189-01) OK
```

**Os 8 caminhos que este plano tocou** — e **só** eles:
`189-BASELINES.md` · `189-CONTRATO-188.md` · `189-FID01.md` ·
`tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines189Test.php` ·
`tests/gates/contagens-189-01.txt` · `tests/js/publicador-fila-tokens-187.test.js` ·
`tests/js/publicador-lightbox-tokens-189.test.js` · `tests/js/publicador-vocabulario-novo.test.js`.

⚠️ **O LIMITE do escopo do assunto, medido:** ele **sobre-captura** (o `--grep` lê o corpo) — ver
§7.5. É **melhor que range**, não perfeito, e a trava do recorte por assunto é o que o torna seguro.

⚠️ **Intocados, provados:** `tests/js/publicador-header-zindex-ordem.test.js` · `.planning/ROADMAP.md`
· `.planning/REQUIREMENTS-v27.md` · `.planning/STATE.md` · `AppLayout.jsx` · `tailwind.config.js` ·
todos os `.jsx` de `aprovacao/` · os serviços das 186/187/188 · `MlbPublicadorAprovacaoController` ·
`tests/js/publicador-painel-fotos-188.test.js` · `estrutura-meus-anuncios.test.js` ·
`contagens-186-09.txt` · os 4 arquivos de dependência (**este plano não instalou nada**).
⚠️ **Os 27 `stash` foram apenas LISTADOS** — `git stash` não foi rodado em forma nenhuma.
⚠️ Os dois untracked alheios (`tests/Feature/CompanyPortfolioAccessTest.php`,
`tests/js/.pub-lote187-inertia-stub-9672.mjs`) seguem **intocados**.

---

## §10 — O QUE ESTE PLANO **NÃO** PROVA

⚠️ **Não vi tela renderizada, e não poderia:** não há navegador; o `mysqld` local **não está em
execução**; o banco não tem empresa com `ml_token` (a rota dá **404**); `pub_aprovacao_ofertas` está
**vazia**; e a fila só ganha oferta na **Fase 194**. Este plano **não renderizou nada** — ele mede
documentos, fontes, o manifest e o histórico.
⚠️ **As conferências visuais das Fases 187 e 188 seguem ABERTAS e separadas.** Não as presumi feitas.
⚠️ **Nenhum comportamento de runtime do lightbox foi provado** (ele ainda não existe): o zoom que
segue o cursor é dos planos 189-03/189-05, e **o movimento do cursor não é provável nesta máquina**.

---

## §11 — DIVERGÊNCIAS E CORREÇÕES AO BRIEFING / AOS DOCUMENTOS (todas medidas)

| # | onde | o briefing/documento dizia | o disco diz |
|---|---|---|---|
| 1 | briefing, §harness | `publicador-painel-tokens-188.test.js` *"exige 21 presente e 23 AUSENTE — o 18 não colide"* | **COLIDE** com uma terceira asserção (`naoMedidos`), `:126` — **§8** |
| 2 | briefing, §território | `PreparoIaDoRascunhoService:131`/`:180`/`:366` | **`:132`/`:181`/`:367`** (+1 cada) |
| 3 | briefing, §harness | um commit da 188 com escopo `(186-01)` *"NÃO é capturado pelo filtro da fase"* | **É capturado** — `--grep` lê o CORPO; o filtro **sobre-captura** |
| 4 | briefing, §harness | `/test_[A-Za-z0-9_]+/` *"NÃO casa `test_(a\|b\|c)`"* | casa **ZERO**, não "um" — cegueira total, não subcontagem |
| 5 | briefing, §baselines | as 2 falhas de `CockpitDaContaTest` | **VERDES hoje** (50 passed) — e surgiu **outra** falha alheia, §2.4 |
| 6 | briefing, §estado | *"zero migrations pendentes… site em HTTP 200"* | **não verificado** por mim: nada nesta wave toca produção, e a leitura de produção não foi exercida |
| 7 | `189-FID01.md` §A | "33 linhas de tabela" | 33 **atributos** em 31 **linhas** |
| 8 | `189-FID01.md` §T | precedentes "9 usos" e "8 usos" | **32/19** e **27/15** |
| 9 | `189-FID01.md` §G | MOD-06 em `:821`-`:831`; `ROADMAP:4717`; `:5025` | `:866`-`:875` (+ `:1422`); **L4730**; **L5038** |
| 10 | `BlocoDaImagemSelecionada.jsx:68`/`:297` (188-08) | *"ver a divergência escrita em `VARIACAO`"* | **não existe** `VARIACAO` naquele arquivo |
| 11 | `contagens-189-01.txt` (cabeçalho, meu) | `ARQUIVOS=1667 METODOS=7876` | **1670 / 7879** ao fim (os +3 são meus; é prosa de comentário, não régua) |

---

## §12 — PARA O 189-02 (e para quem fechar a fase)

1. **Leia `189-CONTRATO-188.md`, não a `189-REGUA.md` §2**, quando os dois divergirem — é o que a
   própria régua manda.
2. **A prop-raiz `lightbox` é segura** (INV-13: não há conjunto fechado).
3. **`PERGUNTA-189-B` não precisa de rota nova** (A6: `escritasDaColuna` já recebe oferta e versão).
4. **O 189-07 não move montagem de modal** (A1: já é irmão do `<aside>`).
5. **O 189-06 afrouxa a CONDIÇÃO do wrapper `:604`**, não o cria (A5) — e **não escreve o literal
   `⤢ Ampliar` nem a palavra `Ampliar` em CÓDIGO de `PainelDeRevisao.jsx`** (A2: o `casca-188:1035`
   varre a fonte sem comentários; **`onAmpliar` reprovaria**).
6. **A linha "Variação" da §C7 não é desenhada** (A3), e isso **não** é um quinto conflito.
7. **Nenhum `.jsx` do lightbox vai aparecer no manifest** (§3). Não escreva gate dizendo o contrário.
8. ⚠️ **Resolva a §8 antes de seguir:** com o gate 188-01 vermelho, a árvore está vermelha.
