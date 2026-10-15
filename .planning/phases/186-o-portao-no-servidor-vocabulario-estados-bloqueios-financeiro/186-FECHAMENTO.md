# Fase 186 — O portão no servidor: o FECHAMENTO MEDIDO

**Escrito em 15/10/2026, depois dos 8 planos de execução**, com as quatro baselines **re-medidas
nesta sessão**, os **17 critérios de sucesso** provados um por um, e o território da fase conferido
**commit por commit**.

**Commit de partida da fase:** `d4aeaa30`.
**Commits da fase:** **58** em `d4aeaa30..HEAD` — **55** com escopo `(186-…)` e **3** do
orquestrador (`docs(v27)`, `docs(learnings)` ×2), todos os três **só em `.planning/`**.

---

## ⚠️ ANTES DE QUALQUER NÚMERO: NENHUM EXECUTOR VIU TELA RENDERIZADA

**Em nenhum dos 9 planos.** Não é modéstia, é a condição do ambiente, e ela está re-medida aqui:

| fato | medida |
|---|---|
| o banco local não tem empresa com `ml_token` | `meus()` faz `abort_unless($company->mlToken !== null, 404)` — a rota de Publicações devolveria **404** nesta máquina |
| `ml_acervo_itens` está vazia | 0 linhas (medido pelo plano 180-03 por `information_schema`; nada nesta fase a populou) |
| a leitura de produção está **BLOQUEADA** | nesta sessão e nas 8 anteriores (o classificador barra `plink`/`pscp`/`tinker` contra o VPS em subagente) |
| não há navegador no ambiente | todo render é `esbuild` + `react-dom/server` com **fixture sintético** |
| a suíte PHP roda em **SQLite em memória** | `phpunit.xml`; fixtures criadas e descartadas por teste — nada do que ela cria chega a uma tela |

➡️ **Toda afirmação desta fase sobre "a tela mostra X" significa "o HTML gerado contém X".** O que
os gates de JS medem é string de `renderToStaticMarkup` (sem CSS aplicado, sem layout) **mais** o
CSS construído em `public/build/assets/*.css` — que é a régua **GATE-02**, "dois gates por elemento:
CSS **e** HTML". Estrutura e literal, **nunca aparência**.

➡️ **Por isso a validação visual é um CHECKPOINT HUMANO, em produção, na conta 459.** O roteiro está
no fim deste documento.

---

## (A) AS LINHAS DE FORMA FIXA — re-medidas por mim, não copiadas das SUMMARYs

```
COMMIT: 63e59ca8
BUILD: exit 0
MANIFEST: 489 entradas / 200 paginas / isca ausente
JS: 4836 / 4835 pass / 1 fail / 0 skipped
PHP: 2346 / 20261 / 0
PROTOTIPO Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2
PROTOTIPO Publicacoes v2.dc.html: 659 linhas sha256 56e6c194b896151c360543adb0c46f07302e15099ce8508d4741ddb6893e841f
PROTOTIPO design_handoff_publicador/Visao Geral.dc.html: AUSENTE (skip declarado)
```

### A comparação com o `186-BASELINES.md`, mecânica

| linha | baseline (plano 186-01, `d4aeaa30`) | **RE-MEDIDO AGORA** | veredicto |
|---|---|---|---|
| `COMMIT` | `d4aeaa30` | `bf02dc43` | ✅ é o HEAD do momento da medição |
| `BUILD` | exit 0 em 58s | **exit 0 em 59s** | ✅ |
| `MANIFEST` | 479 entradas / 196 páginas / isca ausente | **479 / 196 / isca ausente** | ✅ **exato** |
| `JS` | 4482 / 4481 pass / 1 fail / 0 skipped | **4791 / 4790 pass / 1 fail / 0 skipped** | ✅ **+309 testes**, mesma falha única |
| `PHP` | 2103 / 16034 / 0 | **2346 / 20258 / 0** | ✅ **+243 testes / +4.224 asserções**, 0 falhando |
| `PROTOTIPO Aprovacao.dc.html` | 1432 linhas, `68624883…` | **1432 linhas, `68624883…`** | ✅ **sha256 idêntico** |
| `PROTOTIPO Publicacoes v2.dc.html` | 659 linhas, `56e6c194…` | **659 linhas, `56e6c194…`** | ✅ **sha256 idêntico** |

⚠️ **AS DUAS SUÍTES FORAM MEDIDAS DUAS VEZES, e isso é declarado.** Primeiro em `0f7e0013`
(**JS 4777 / PHP 2334 / 20013**) e de novo em `bf02dc43`, depois de este plano commitar os próprios
gates transversais — porque **o `COMMIT:` e as contagens têm de ser do MESMO commit**, senão a linha
de forma fixa mente sobre quando foi medida. A diferença é **exatamente** o que este plano
acrescentou: **+14 testes JS** e **+12 testes PHP / +245 asserções**. Nenhum número caiu.

⚠️ **`BUILD` e `MANIFEST` NÃO foram re-rodados em `bf02dc43`, e o motivo é verificável:**
`git show --name-only bf02dc43` devolve **3 arquivos, todos sob `tests/`** — zero arquivos de
produção, zero entradas do Vite. O bundle não muda, e o `manifest.json` no disco é o mesmo
(`sha256 2dbc5554…`). Re-rodar o build mediria a mesma coisa gastando 59 s.

⚠️ **Os dois `.dc.html` de autoridade não mudaram durante a fase** — `sha256` igual byte a byte ao
que o plano 186-01 mediu em 14/10. Logo nenhuma medida de fidelidade desta fase foi feita contra um
protótipo que se moveu no meio.

⚠️ **O terceiro caminho é `skip` EXPLÍCITO, não "conferido".** `design_handoff_publicador/Visao
Geral.dc.html` **NÃO EXISTE** — seria o 7º caminho fantasma deste projeto. O arquivo real é
`new_layout_publicador/Visao Geral.dc.html` (620 linhas, `sha256 bd3fee75…`, também inalterado), e os
dois diretórios de handoff **não são versionados**: na máquina de outro dev eles não existem.

### ⚠️ Contagem ACIMA da baseline é ESPERADA; ABAIXO seria regressão

A fase acrescentou **+243 testes PHP** e **+309 testes JS**. A referência **não foi "ajustada"**: o
harness (`REF_JS_TESTS`, `REF_PHP_TESTS`) segue nos números de `d4aeaa30` e **aceita acima, recusa
abaixo**. A diferença está registrada e explicada aqui, que é a regra.

### A única falha de JS — nomeada, localizada por SEMÂNTICA e **NÃO corrigida**

```
not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)
```

Localizada por **semântica** (o texto do `test()`, por `grep -rln`), não por número de linha:
**`tests/js/estrutura-grade-glide.test.js`**. É a falha **pré-existente** da Fase 87, anterior a
esta fase e fora do escopo de qualquer plano dela. ⚠️ **NÃO CORRIGIDA, de propósito.** Nenhuma outra
falha; **0 skipped**.

### O manifest, **pelo JSON** — nunca por grep

```
ENTRADAS=479
PAGINAS=196
PRESENTE resources/js/app.jsx                                    -> assets/app-EVrrL0Z-.js
PRESENTE resources/js/Pages/Mlb/Publicador/Alavancas.jsx         -> assets/Alavancas-Cpu86_Cu.js
PRESENTE resources/js/Pages/Mlb/Publicador/Configuracoes.jsx     -> assets/Configuracoes-Ua29uJaO.js
PRESENTE resources/js/Pages/Mlb/Publicador/Editor.jsx            -> assets/Editor-BUdZDSne.js
PRESENTE resources/js/Pages/Mlb/Publicador/Produto.jsx           -> assets/Produto-BI36y3pW.js
PRESENTE resources/js/Pages/Mlb/Publicador/Produtos.jsx          -> assets/Produtos-DJMcUbs6.js
PRESENTE resources/js/Pages/Mlb/Publicador/PublicacaoEmLote.jsx  -> assets/PublicacaoEmLote-C2OyYKj0.js
PRESENTE resources/js/Pages/Mlb/Publicador/Tarefas.jsx           -> assets/Tarefas-Buz5adYN.js
PRESENTE resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx        -> assets/VisaoGeral-C6EcFSJ9.js
PRESENTE resources/js/Pages/Mlb/MeusAnuncios.jsx                 -> assets/MeusAnuncios-C5eMrpxU.js
ISCA ausente, ok  resources/js/Pages/Mlb/NaoExiste9z7.jsx
```

⚠️ **O hash do `app.jsx` é `EVrrL0Z-` — com HÍFEN no fim.** É exatamente por isso que o manifest se
confere **pelo JSON** (`require` + `hasOwnProperty`) e nunca por `grep`: um
`grep -E "app-[A-Za-z0-9]*\.js"` pode não casar, e **página React fora do manifest SOME da aplicação
sem erro nenhum**. A **isca** (entrada que não existe) saiu **ausente** ⇒ a sonda não está cega.

⚠️ **O CSS também é achado pelo manifest, não por nome fixo** (`app-BhcXX3y8.css`, 223.893 bytes):
o gate de render deste plano lê o CSS construído e prova que **as 20+ classes `pub-*` pedidas pelos
módulos novos estão lá**. É o segundo gate do par CSS × HTML, e sem ele um mapa de classe num módulo
`.js` sairia **sem cor, em silêncio** (o `content` do `tailwind.config.js` varre `*.jsx` e **não**
`*.js`).

---

⚠️ **AS LINHAS ACIMA FORAM RE-MEDIDAS PELO ORQUESTRADOR EM 15/10/2026, DEPOIS DO MERGE da
`origin/main`.** As que o executor do 186-09 havia escrito valiam para o commit `bf02dc43` e ficaram
**desatualizadas** por dois motivos legítimos: os **quatro consertos de gate** feitos depois dele, e
o **merge dos 12 commits da sessão paralela** (Polos, Planejador FULL, MCP e o refactor da Shopee).
Um fechamento que afirma número velho é pior que um fechamento sem número.

⚠️ **E o merge rendeu a QUINTA ocorrência da família "o gate é mais largo que o invariante", a mais
perigosa das cinco:** a suíte foi a **2346 / 20250 / 1**, e a falha **não era regressão**. O gate
transversal `andamento-um-lugar-so` varria o `app/` **inteiro** procurando `'andamento' =>`, e
`app/Mcp/Shopee/LeitorShopee.php` usa `['andamento' => 'ongoing', 'agendadas' => 'upcoming', …]`
como **status de promoção da Shopee** — outro domínio, uso legítimo. **O gate barrou o deploy por
uma palavra do português**, e cobrava das outras sessões um vocabulário que só faz sentido neste
módulo. Isso não se sustenta numa árvore compartilhada.
➡️ **Conserto (commit `63e59ca8`):** o alcance do `app/` inteiro **fica** — é o valor do gate — e
entrou um 2º filtro: só é escritor quem mexe em `andamento` **E** referencia o portão (pelo
**modelo** ou pela **tabela**, porque `DB::table('pub_aprovacao_ofertas')->update([…])` não nomeia o
modelo). **Três iscas**, e a terceira é a que vale para o futuro: ela lê o arquivo real da Shopee e
**avisa se um dia ele passar a referenciar o portão**, porque aí deixa de ser falso positivo.

⚠️ **Este documento é MEDIÇÃO DATADA, não gate perene** (o próprio
`FechamentoDaFase186Test` só exige que ele **exista**, e isso é de propósito): os números valem para
o commit `63e59ca8`, e **não devem ser "atualizados" por sessão futura** — quem quiser o número de
hoje, mede hoje.

---

## (B) OS 17 CRITÉRIOS DE SUCESSO — um por um, em LINHA DE FORMA FIXA

⚠️ **Formato obrigatório, 17 linhas, uma por critério.** Elas estão em forma fixa, e não em prosa,
por um achado da verificação deste plano em 14/10: na primeira redação os 17 ficavam atrás de um
`grep -c` de **uma frase de cabeçalho**, e as 4 baselines podiam ser **inventadas** sem nenhum gate
notar. **Qualquer `REPROVA` nestas 17 linhas faz o plano REPROVAR** — o fechamento não "registra"
reprovação, ele para.

```
CRIT-01: OK — rename nos 3 rótulos renderizados, medido no protótipo (:461 e :534) — SeloDeStatusDaPublicacao.jsx:44, visaogeral/acoes.js:546, MlbAnuncioController.php:995; gate `gate-rename` 58/58 e `abas-do-servidor` 1/22
CRIT-02: OK — "Em revisão" CRU em zero corpos renderizados; as 3 ocorrências restantes são COMENTÁRIO, e "Em revisão pela ECF" (qualificado) é legítimo — gate `gate-rename`, recortado ao corpo, com isca
CRIT-03: OK — os dois eixos não se misturam nas DUAS direções, com isca em cada uma — gates `dois-eixos` (186-04, 1/29), `eixos-nao-se-misturam` (186-03, 1/5) e `dois-eixos-transversal` (186-09, app inteiro)
CRIT-04: OK — os 8 bloqueios por oferta e a cadeia exclusiva freight->loss->margin provada nos 3 casos + o caso que decide a exclusividade — gates `cadeia-exclusiva` (186-08, 4/65) e `kinds-aprovavel-rodape` (4/53)
CRIT-05: RESSALVA — custo/frete ausente devolve null e "Incompleto" (gate `incompleto-nulo`, 186-06); a EMBALAGEM é exceção DELIBERADA e ÚNICA (decisão 7 do usuário, 14/10) e NÃO anula o resultado — gate `excecao-e-regra` (186-07) prova que ela não anula e que o frete ANULA no mesmo cenário
CRIT-06: OK — zero literais de taxa (0.12 / 0.17 / 6.75) nos arquivos novos, com os dois controles (o CNPJ que contém 6.75 passa; o literal sintético reprova) — gate `sem-literal-de-taxa` (186-07, 9/93)
CRIT-07: OK — as 7 linhas na ordem, os rótulos, a propagação de null, fixedSum e as duas fórmulas conferidas nos 10 estados do protótipo, com os NÚMEROS das fontes reais — gates `tabelado-o1-a-o5` (5/174) e `tabelado-o6-a-o10` (5/175)
CRIT-08: OK — PROVADO que há fonte antes de dizer "Pendente": a linha nasce Confirmado/Estimado nas origens vivas e o frete informado à mão vence a precificação — gates `fin-07-origens-vivas` (186-06, 1/21) e `informado-vence` (1/7)
CRIT-09: OK — aprovar grava quem/quando/em que versão na MESMA transação, e as mudanças relevantes incrementam a versão e REABREM com registro; foto reabre TODAS as ofertas do conjunto — gates `as-oito-mudancas` (186-08, 3/99), `aprovar-e-transacao` (3/39), `img06-reabre-o-conjunto` (2/70)
CRIT-10: OK — aprovar a foto uma vez vale para Clássico e Premium, e é ESTRUTURAL (não existe coluna onde divergir) — gates `conjunto-compartilhado` (186-03, 1/6), `sem-conjunto-id` (186-04, 1/11), `fronteira-base-kit` (1/6)
CRIT-11: OK — o vínculo durável existe, é NOSSO, não se apoia no efêmero de 48h, e `app/Services/Creative/` tem diff VAZIO em d4aeaa30..HEAD — gates `vinculo-n-para-n` (186-06, 1/9), `terr-11-por-range` (1/30) e a medição de território da seção (D)
CRIT-12: OK — granularidade resolvida no docblock; as 4 COLUNAS da fila não estão na oferta e `precisa_revisar` segue sendo VALOR do status; o eixo VARIANTE bloqueia TODOS os grupos — gates `quatro-colunas-da-fila` (1/14), `precisa-revisar-e-status` (1/56), `achado-s2-todos-os-grupos` (2/32)
CRIT-13: OK — as 6 migrations obedecem MIG-01..09 (nomes <= 64 por strlen, nullable explícito, sem enum, sem try/catch em DDL, up() idempotente, down() na ordem inversa) e migrate->rollback->migrate provado no MariaDB 10.4.32 local nos 4 planos de migration, com reconsulta à tabela `migrations` entre os passos
CRIT-14: OK — harness `--autoteste` 14 casos / 0 divergência (reconferido agora), 9 manifestos / 160 ids, 131 mutações com 5 sobreviventes TODAS investigadas, ANSI removido antes de filtrar e contagem comparada em todo gate
CRIT-15: OK — `aprovacao/` entrou no GATE-03 e os 3 arquivos novos em ARQUIVOS_NOVOS, no plano que os cria, com a omissão provada por mutação (M8 é o CONTROLE) — gate `voc-07-gate-03` (186-02, 399 testes)
CRIT-16: OK — baselines respeitadas (PHP 2334/20013/0 e JS 4777/4776/1/0, ambas ACIMA da referência), `npm run build` exit 0 e manifest conferido PELO JSON com isca ausente
CRIT-17: RESSALVA — a tabela FID-01 existe com a declaração SCRIPT x LEITURA nos planos que medem o protótipo (186-01 por SCRIPT, 186-02 14 medidas por SCRIPT, 186-07/08 por LEITURA do .dc.html + fixture real); nos 4 planos de SCHEMA (186-03..06) não há seção A de protótipo para medir e a declaração é de LEITURA do schema vivo — o 186-01 já registrou o critério como "fechado em parte"
```

### As duas RESSALVAS, em português claro

**CRIT-05 — a embalagem.** A regra da fase é *"custo ou frete ausente nunca vira zero: o resultado é
`null` e a tela diz Incompleto"*. Há **uma** exceção, e ela foi **decidida pelo usuário em 14/10**
(decisão 7): "Embalagem" nasce **Pendente visível** e **não anula** o resultado — o resultado sai
marcado como **estimado por baixo**. Sem essa exceção o painel diria "Incompleto" em **100%** das
ofertas e **nada poderia ser aprovado**. ⚠️ **E o corolário obrigatório está implementado:**
`loss`/`margin` **disparam** sobre o valor estimado por baixo (gate `estimado-por-baixo`, 186-08,
1/14), porque o resultado é otimista, a margem real é menor, e **aprovar já publica sem desfazer**.

**CRIT-17 — a tabela FID-01 nos planos de schema.** Os planos 186-03 a 186-06 criam **tabelas**, e
não há seção do protótipo contra a qual medi-las: a "fidelidade" deles é contra o **schema vivo**
(`information_schema` do MariaDB 10.4.32), e está declarada como **LEITURA**. A exigência de FID-02
("declarar se mediu por SCRIPT ou por LEITURA") está **cumprida nos 8 planos**; o que não existe nos
quatro de schema é uma tabela de **divergências contra o `.dc.html`**, porque não havia o que
divergir. O plano 186-01 já tinha registrado isso como "fechado em parte".

---

## (C) AS 12 ESTRUTURAS — e o destino de cada uma

| # | estrutura | tabela / coluna | plano | **ESCRITA nesta fase?** | se não, **fase dona** |
|---|---|---|---|---|---|
| 1 | os 8 estados de andamento interno | `pub_aprovacao_ofertas.andamento` | 186-04 | **sim** (`AndamentoDaOfertaService`, a única porta) | — |
| 2 | estado de aprovação por imagem | `pub_aprovacao_imagens.estado` | 186-03 | **sim** (186-08, `RevisaoDeImagemService`) | — |
| 3 | conjunto de fotos compartilhado | `pub_aprovacao_conjuntos` | 186-03 | **sim** | — |
| 4 | checklist de fidelidade (7 critérios) | `pub_aprovacao_fidelidade` | 186-03 | **NÃO** | **Fase 191** (revisão detalhada) |
| 5 | defeitos marcados (6) | `pub_aprovacao_defeitos` | 186-03 | **NÃO** | **Fase 191** |
| 6 | ajuste com escopo | `pub_aprovacao_ajustes` | 186-05 | **sim** (`AjustesDaOfertaService`) | — |
| 7 | histórico por oferta (8 tipos) | `pub_aprovacao_eventos` | 186-05 | **sim** (`HistoricoDaOfertaService`, append-only) | — |
| 8 | `versao` / `versao_aprovada` | `pub_aprovacao_ofertas` | 186-04 | **sim** (186-08, as mudanças relevantes) | — |
| 9 | exceção autorizada | `pub_aprovacao_excecoes` | 186-05 | **sim** (`aplicarExcecao`, imediata) | — |
| 10 | origem por linha + composição congelada | `pub_aprovacao_composicoes`, `pub_aprovacao_fretes` | 186-06/07 | **sim** | — |
| 11 | verificações automáticas x/y | `pub_aprovacao_imagem_alertas` | 186-03 | **NÃO** | **Fase 192** (regeneração) |
| 12 | vínculo durável com a original | `pub_imagem_referencias` | 186-06 | **NÃO** | **Fase 192** / **Fase 194** |

⚠️ **As 4 não preenchidas foram provadas sem escrita POR CÓDIGO, não por "0 linhas na tabela".** A
verificação de 14/10 reprovou uma asserção que exigia `pub_imagem_referencias` com 0 linhas — isso é
**vazio por construção** com `RefreshDatabase` e passaria sem provar nada. A prova certa é
**ausência de ESCRITA**: um `grep` dos verbos de persistência do Eloquent (`create|insert|
updateOrCreate|firstOrCreate|upsert|forceCreate|insertGetId`) sobre `app/` **inteiro**, lido **sem
comentário** pelo tokenizador, com **isca de dois lados** — e a isca do lado positivo usa
`PubAprovacaoEvento`, que **É** escrito nesta fase e **é achado** pela mesma sonda.

### ⚠️ DÍVIDA DIV-01 — 3 das 4 estruturas declaram a fase dona só FORA do código

**Achado deste fechamento.** O gate (a) pedia a fase dona no **docblock da migration**. Medido:

| estrutura | docblock da migration | docblock do modelo | ROADMAP / este arquivo |
|---|---|---|---|
| `pub_imagem_referencias` | ✅ **Fase 192** e **Fase 194** | ✅ **Fase 192**/**194** | ✅ |
| `pub_aprovacao_fidelidade` | ❌ | ❌ | ✅ Fase 191 |
| `pub_aprovacao_defeitos` | ❌ | ❌ | ✅ Fase 191 |
| `pub_aprovacao_imagem_alertas` | ❌ | ❌ | ✅ Fase 192 |

⚠️ **PUB-10 está cumprido** — nenhuma estrutura ficou **sem destino declarado em artefato
versionado** —, mas em três delas a declaração vive no `ROADMAP.md` e neste arquivo, e **não** no
docblock onde quem abrir a migration vai procurar. **Não consertei:** são docblocks de migrations do
plano **186-03**, código de produção de outro plano, e conserto no fechamento é **achado, não
tarefa**. ➡️ **Três linhas de docblock, para o orquestrador autorizar** (ou para a Fase 191 trazer
junto). O gate transversal deste plano aceita a declaração em qualquer dos três lugares **de
propósito** — aceitar só o docblock faria o gate **reprovar hoje**, e aceitar menos faria ele
reprovar quando alguém fizesse o trabalho certo.

### Nada fica sem destino na fronteira (PUB-10 / D14)

A fase **entrega valor sozinha**: o portão existe como **dado e regra** conferidos, e o rename já
está **na tela que está no ar**. Nenhuma rota, botão ou coluna fica pendurada — a fila é a **Fase
187**, a revisão detalhada a **188/191**, a regeneração a **192**, a automação a **194**.

---

## (D) O TERRITÓRIO — por range **e** commit por commit

⚠️ **O autor NÃO é discriminador.** `git log --format=%an d4aeaa30..HEAD | sort -u` devolve **um
valor só** (`MB.ECF-100376`): as duas sessões e o outro dev usam o **mesmo** `user.name`. Um gate
comparando `%an` seria **vazio por construção**. O discriminador é o **escopo do assunto**.

### Prova 1 — diff **VAZIO** por range `d4aeaa30..HEAD`, com `test -e` em cada alvo ANTES

| alvo | existe? | diff |
|---|---|---|
| `app/Services/Creative/` | ✅ | **VAZIO** |
| `resources/js/Pages/Mlb/AnunciarML.jsx` | ✅ | **VAZIO** |
| `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` | ✅ | **VAZIO** |
| `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` | ✅ | **VAZIO** |
| `app/Services/Publicador/Criativos/` (os 3 serviços, inclusive `…AprovacaoService` e `…ReferenciaService`) | ✅ | **VAZIO** |
| `app/Http/Controllers/MlbPublicadorCriativoController.php` | ✅ | **VAZIO** |
| `app/Console/Commands/LimparReferenciasCriativos.php` (a retenção de 48 h) | ✅ | **VAZIO** |
| `resources/js/Pages/Mlb/Publicador/Editor.jsx` | ✅ | **VAZIO** |
| `resources/js/Components/Publicador/Mesa/` (**sem** o `Mlb`) | ✅ | **VAZIO** |
| `app/Services/Mlb/Publicacao/MlPublicacaoService.php` | ✅ | **VAZIO** |
| `resources/js/Layouts/AppLayout.jsx` | ✅ | **VAZIO** |
| `app/Support/Publicador/` | ✅ | **VAZIO** |
| `app/Models/Bling*` (`BlingConexao`, `BlingVinculo`) · `app/Services/Bling/` · `app/Services/Portal/` | ✅ | **VAZIO** |
| `tests/js/estrutura-meus-anuncios.test.js` (o gate D-11/D-22) | ✅ | **VAZIO** |
| `tests/js/publicador-header-zindex-ordem.test.js` | ✅ | **VAZIO** |
| `package.json` · `package-lock.json` · `composer.json` · `composer.lock` | ✅ | **VAZIO** — **T-186-SC: a fase não instalou nada** |

⚠️ **Três caminhos do plano eram FANTASMA e foram corrigidos antes de medir**, nunca "conferidos":
`PainelCriativosIa.jsx` e `KitCriativosGrade.jsx` vivem em `resources/js/Pages/Mlb/components/` (não
em `Components/Mlb/`), e `PublicadorCriativoAprovacaoService.php` vive em
`app/Services/Publicador/Criativos/`. Alvo inexistente é **ERRO DO GATE**, e este projeto já
acumulou **seis** "territórios intocados" falsos por isso.

### Prova 2 — commit por commit

Varredura dos **57** commits de `d4aeaa30..HEAD`, listando os arquivos de cada um e casando contra a
lista de territórios vetados: **0 violações**. Conferido **duas vezes**, por dois caminhos
independentes (laço por commit com `git show --name-only`, e uma passada única de
`git log --name-only`): os dois devolveram `COMMITS=57 VIOLACOES=0`.

### Os endpoints `criativo*` do `MlbAnuncioController`, por **SEMÂNTICA**

⚠️ `app/Http/Controllers/MlbAnuncioController.php` **FOI tocado na fase** — `14 1` no `numstat`. E
está correto: é a **3ª das três ocorrências do rename**, no `meus()`, hunk único em **:979-:999**
(1 linha de código + 13 de comentário). Os **12** endpoints `criativo*` daquele arquivo vivem em
**:2335-:3255** e **nenhum** aparece no diff. O plano antecipou isso: *"o `:982` deste plano É
daquele arquivo"*.

### `visaogeral/` — o caso ESPECIAL, e um gate do plano que estava com o número errado

- `visaogeral/` **excluindo `acoes.js`**: diff **VAZIO** (0 arquivos).
- `visaogeral/acoes.js`: `numstat` = **`14 1`**.

⚠️⚠️ **O gate do plano exigia `1  1` e REPROVARIA.** Medido: as 14 linhas acrescentadas são
**1 de CÓDIGO + 13 de COMENTÁRIO**. O diff de código é, literalmente:

```
- em_revisao: 'Em revisão',
+ em_revisao: 'Em análise pelo Mercado Livre',
```

➡️ **A medida certa é `+1 / -1` de CÓDIGO** (comentários removidos), e é essa que vale: um único
rótulo mudou, a chave `em_revisao` continua intacta (2 ocorrências no arquivo), e **um único commit**
tocou o arquivo — `060aacdc feat(186-01)`. O `1 1` cru do plano era um número escrito **antes** de a
convenção de docblock pt-BR deste projeto ser aplicada ao arquivo; cobrá-lo faria o gate **reprovar
pelo próprio zelo**, que é a armadilha medida nesta fase mais de uma vez. **Nada da Fase 179 foi
arrastado junto.**

---

## (E) OS NOVE MANIFESTOS, RODADOS

| manifesto | ids |
|---|---|
| `contagens-186-01.txt` | 3 |
| `contagens-186-02.txt` | 3 |
| `contagens-186-03.txt` | 16 |
| `contagens-186-04.txt` | 33 |
| `contagens-186-05.txt` | 29 |
| `contagens-186-06.txt` | 27 |
| `contagens-186-07.txt` | 15 |
| `contagens-186-08.txt` | 22 |
| `contagens-186-09.txt` (este plano) | 12 |
| **total** | **160** |

O resultado da execução dos 148 ids dos manifestos 01-08 e dos 12 do 09 está na seção
**"(H) O resultado da rodada dos 9 manifestos"**, no fim deste documento.

⚠️ **O `--autoteste` do harness foi reconferido nesta sessão: `14 casos, 0 divergencia`.** Ele prova
que as duas formas **REPROVAM quando devem** — inclusive a **isca G1** (tudo verde com o reporter
padrão do Node tem de REPROVAR, porque o `spec` emite `ℹ tests N` e não `# tests N`) e a **isca do
ANSI** (o mesmo caso que passa limpo tem de reprovar **sem** a limpeza).

### ⚠️ As duas dívidas do `deferred-items.md` estão RESOLVIDAS

O `deferred-items.md` da fase está **desatualizado**: as duas entradas que ele registra como abertas
foram fechadas pelo commit **`0f7e0013`**, e eu reconferi as duas no disco:

1. **A isca com validade de UM plano** (gate `ligacao-e-so-ligacao`, 186-05): o sujeito da isca é
   agora `app/Support/Publicador/RegraViolada.php` (`AjustesEHistoricoTest.php:439`) — **dentro de
   território VETADO** pela milestone inteira (TERR-03), com gate próprio exigindo diff vazio ali.
   ➡️ Enquanto aquele gate existir, **esta isca não pode cair**. Era isca de range aberto cuja
   premissa morria no primeiro plano seguinte.
2. **O filtro que media dois arquivos achando que media um** (gate `guarda-sem-enum-sem-try`,
   186-04): o método foi renomeado para `…_da_oferta_…` e o filtro do manifesto agora casa
   **exatamente um** método. ⚠️ **O número que o manifesto esperava estava CERTO desde o começo
   (1/20)** — era o filtro que apontava para o lugar errado. Elevar para 2/44 seria o conserto
   **errado**.

➡️ **Para o orquestrador:** as duas entradas do `deferred-items.md` podem ser marcadas como
resolvidas, com o commit `0f7e0013` ao lado.

---

## (F) A TABELA FID-01 CONSOLIDADA, e a declaração SCRIPT × LEITURA

⚠️ **FID-03: a tabela de fidelidade não erra VALOR — ela erra por OMISSÃO e por REFERÊNCIA.** Neste
projeto `cursor:pointer` foi **omitido onze vezes** numa única fase. É por isso que cada plano
declara **como** mediu, e por que a conferência é por **semântica** (localizar o identificador) e
nunca pelo número de linha que o plano citou.

| plano | o que foi medido | **SCRIPT ou LEITURA** | medidas |
|---|---|---|---|
| **186-01** | o rename, contra `Publicacoes v2.dc.html` (`:461` `ST.pending`, `:534` `tabsDef`) | **SCRIPT** (`diff` entre as duas versões do protótipo + `grep -n` dos dois literais) | **5**, com a #5 marcada **FORA DO PROTÓTIPO** (a faixa da Visão Geral é decisão do usuário, não medida de design) |
| **186-02** | `STM`/`CRIT`/`DEF`/`BLK`/`SHORT`/`SRC`/`ST`/`ORD`/`cta`/a pill, em `Aprovacao.dc.html` | **SCRIPT** (`grep -nE` dos identificadores + `sed -n` nas faixas) | **14**, e **as 10 referências que o plano citava foram todas conferidas** — 10/10 ✔ |
| **186-03** | as 5 tabelas contra o **schema vivo** (`information_schema`, MariaDB 10.4.32) | **LEITURA** do schema vivo | 14 linhas de decisão de schema |
| **186-04** | `pub_aprovacao_ofertas`: 23 colunas, 9 índices, 6 FKs, lidos do `information_schema` | **LEITURA** do schema vivo | 8 |
| **186-05** | ajustes + histórico + exceção; os 8 tipos de evento contra o `HK` do protótipo | **SCRIPT** no protótipo, **LEITURA** no schema | 3 |
| **186-06** | frete + composição + vínculo; o tipo `numeric` no SQLite virou asserção medida | **LEITURA** (schema vivo e `DadosEfetivosService::fretesDaLinha()` medido, não presumido) | 15 |
| **186-07** | os 10 estados de `fin()`: a **ORDEM** lida por regex do `.dc.html`; os números de **FIXTURE REAL** | **LEITURA** do `.dc.html` + conferência aritmética pela MESMA fórmula; tarifas de fixture real medida | 10 estados × invariantes = **349 asserções** |
| **186-08** | os 10 estados de `info()` + os 8 bloqueios + as 11 regras de imagem | **LEITURA** do `.dc.html`, um `test()` por estado | 10 estados + 24 linhas de tabela |

⚠️ **A divergência mais importante da fase, e ela é a favor do PROTÓTIPO perder nos NÚMEROS:**
*"o protótipo ganha"* vale para a **FÓRMULA**, os **RÓTULOS** e a **ORDEM** — **nunca para os
NÚMEROS**. As taxas de `fin()` são **ficção declarada pelo próprio protótipo**
(`Aprovacao.dc.html:1064-1066`: *"taxa de exemplo"*, `src:'est'`). Com as fontes reais o exemplo `o1`
sai de **+R$ 70,82 / 14,46%** para **+R$ 12,03 / 2,46%** e **inverte de aprovável para bloqueado** —
e obedecer os números forçaria hardcodear `0.12`/`0.17`/`6.75`, que o critério 6 **proíbe**.

⚠️ **E a seção F do README é PROSA, não especificação:** só `info()` decide bloqueio. O estado `o3`,
que a prosa chama de *"margem baixa"*, dá **prejuízo** pela cadeia exclusiva — e o gate
`estados-o1-a-o5` (186-08) tem um `test()` com esse nome inteiro justamente para a divergência ficar
nomeada: *"o3 do protótipo dá prejuízo e não margem baixa como diz a prosa do README"*.

---

## (G) A CAMPANHA DE MUTAÇÃO CONSOLIDADA

**Disciplina comum aos 8 planos:** ocorrência **TRAVADA EM 1 ANTES** (o script aborta se a agulha não
aparecer exatamente uma vez) · **linha conferida depois** (`arquivo:linha` impresso) · restauração de
**cópia guardada FORA do repositório**, conferida por **`sha256`** contra `git show HEAD:` · dentro
de **`trap EXIT`/`finally`** (o script do 186-04 crashou no meio e deixou arquivo mutado no disco) ·
**commit antes de mutar** · ⚠️ **NUNCA `git checkout`** — uma vez nesta fase ele **comeu 90 linhas de
gate**.

| plano | mutações | mortas | sobreviventes |
|---|---|---|---|
| 186-01 | 3 | 3 | 0 |
| 186-02 | 11 | 10 | **1** — `M8`, o **CONTROLE por construção** |
| 186-03 | 9 | 8 | **1** — **LACUNA REAL** |
| 186-04 | 10 | 10 | 0 |
| 186-05 | 24 | 24 | 0 |
| 186-06 | 18 | 18 | 0 |
| 186-07 | 14 | 12 | **2** — as duas **LACUNA REAL**, fechadas |
| 186-08 | 42 | 41 | **1** — mutante **EQUIVALENTE**, investigado |
| **total** | **131** | **126** | **5** |

⚠️ **Divergência declarada:** o briefing deste plano fala em **6** sobreviventes. A soma das 8
SUMMARYs dá **5**. Não "escolhi" um dos dois: registro os dois e a aritmética
(`131 − (3+10+8+10+24+18+12+41) = 5`). Quem souber do 6º pode acrescentá-lo aqui.

### ⚠️ SOBREVIVENTE É INVESTIGADA — nunca presumida equivalente

Das 5, **3 eram lacuna REAL** — e é por isso que a campanha existe:

1. **186-03 — o gate estava apontado para o MÉTODO ERRADO.** `guarda-nomes-64` filtrava
   `test_os_nomes_padrao_do_laravel_tambem_cabem_em_64_…`, que mede os nomes **PADRÃO** (computados
   por `strlen`). Quem mede os nomes **DECLARADOS no texto** é
   `test_nenhum_nome_de_indice_ou_fk_declarado_passa_de_64_com_isca` — e ele **existia no arquivo e
   NÃO tinha id no manifesto**, logo **nenhum gate o executava**. Era justamente a verificação do
   **erro 1059**, o que deixa a migration `Pending` com a tabela **já criada** em produção.
   ➡️ Gate novo `guarda-nomes-declarados` (`e8226690`), e a **mesma** mutação **MORRE** contra ele.
   ⚠️ **É a armadilha 1 desta fase em estado puro:** teste em arquivo sem id no manifesto **nunca
   roda**.
2. **186-07 (M11) — a linha informativa entrando na soma era INVISÍVEL.** O gate usava a informativa
   com `valor = null`, e `null ?? 0.0` soma **zero**: trocar o filtro por `array_values($linhas)`
   não mudava nada. Lacuna fechada.
3. **186-07 (M12) — `c2` virando arredondamento em vez de TETO.** Lacuna fechada.

As outras duas:

4. **186-02 (M8) é o CONTROLE do plano, e a sobrevivência dela é a MEDIÇÃO do furo que VOC-07
   fecha.** `M7` e `M8` são a **mesma** situação de mundo (um arquivo não declarado em `aprovacao/`
   com `text-sm` e hex cru), com e **sem** a linha que põe `DIR_APROV` na lista fechada. M7 reprova;
   M8 fica `# pass 398 / # fail 0`. Sem M8, VOC-07 seria argumentado em vez de verificável.
5. **186-08 (M1) é mutante EQUIVALENTE, e foi provado — não presumido.** Trocar o `elseif` do elo do
   prejuízo por um `if` independente sobreviveu a tudo porque `ComposicaoDaOferta::montar()` já
   garante o invariante que protege o 1º elo da cadeia. ➡️ O plano **acrescentou o teste do
   invariante** (`6dd2d07e`) em vez de declarar equivalência e seguir.

### Os dois regimes de contagem (GATE-10)

Os gates com sonda de `skip` por `mtime` reportam contagens **diferentes** com o arquivo pristino e
com ele mutado, e por isso o manifesto aceita **dois** números (`tests=180,169`). Sem isso a campanha
não rodaria sem mexer no gate — e **mexer no gate durante a mutação invalida a mutação**.

---

## (H) O RESULTADO DA RODADA DOS 9 MANIFESTOS

⚠️ **Rodada pelo ORQUESTRADOR em 15/10/2026.** O executor do 186-09 caiu por **erro de rede**
(ENOTFOUND) exatamente nesta seção, com o documento já escrito e a árvore limpa. Como isto é
**medição e não autoria**, o orquestrador rodou em vez de retomar o agente.

**163 ids rodados · 161 passaram · 2 reprovaram.**

| manifesto | ids |
|---|---|
| `contagens-186-01.txt` | 3 |
| `contagens-186-02.txt` | 3 |
| `contagens-186-03.txt` | 16 |
| `contagens-186-04.txt` | 33 |
| `contagens-186-05.txt` | 29 |
| `contagens-186-06.txt` | 27 |
| `contagens-186-07.txt` | 15 |
| `contagens-186-08.txt` | 23 |
| `contagens-186-09.txt` | 14 |
| **total** | **163** |

### ⚠️ As 2 reprovações — e as duas eram a MESMA armadilha, pela QUARTA vez na fase

`terr-11-por-range` e `territorio-e-vinculo` (os dois do 186-06, o segundo é composto e contém o
primeiro). **Os dois PASSAVAM o teste** (`1 passed`) e reprovavam **só pela CONTAGEM**: 31 asserções
contra as 30 do manifesto. ⚠️ **O território estava INTACTO** — nenhuma violação.

**A causa:** o teste fazia **uma asserção POR COMMIT** do range `<partida>..HEAD` que tocou arquivos
do plano. **Range aberto cresce** — e cresceu até com o **próprio commit de SUMMARY do 186-06**,
feito depois de a linha do manifesto ser escrita.

⚠️ **É a QUARTA ocorrência da mesma classe nesta fase**, e nas quatro o **invariante estava CERTO**
enquanto a **contagem era frágil por construção**:
| # | o que contava | onde |
|---|---|---|
| 1 | uma asserção por **arquivo do glob** `*pub_aprovacao*` | `ConjuntoCompartilhadoTest` (186-03) |
| 2 | uma asserção por **tabela com `oferta_id`** | `OfertaDoPortaoTest` (186-04) |
| 3 | **duas** asserções por **arquivo varrido** pela camada B | `AndamentoDaOfertaServiceTest` (186-04) |
| 4 | uma asserção por **commit do range** | `VinculoDuravelDaOriginalTest` (186-06) |

**Conserto, nas quatro: os infratores são COLETADOS e a asserção é UMA, de contagem FIXA.** Não se
perde nada e se ganha — a mensagem **nomeia todos** de uma vez em vez de parar no primeiro.
⚠️ **E em nenhuma das quatro a régua foi simplesmente elevada**, pela razão escrita em
`feedback_gate_que_reprova_pelo_proprio_conserto`: elevar número repetidamente **treina o executor
seguinte a ajustar réguas quando elas incomodam**, e um dia ele ajusta uma que importava.

**Cada conserto foi PROVADO por mutação**, com restauração **de cópia fora do repositório** e
`sha256` conferido contra `git show HEAD:` — ⚠️ **`git checkout` não foi usado** (ver `§4c.1` do
learnings: o orquestrador o usou uma vez num arquivo com trabalho não commitado e **perdeu 90 linhas
de gate**). Na última, a mutação trocou o escopo por um que nenhum commit tem, e a asserção
**nomeou os 5 commits de uma vez**, com a contagem ao lado.

**Depois dos consertos: os 163 ids passam.** Commits: `1c16d9e9`, `c116e8b4`, `0ba69bf3`, `0f7e0013`
e `e08bb96c`.

---

## (I) O QUE FICOU PARA DEPOIS, DECLARADO

### As 4 estruturas não preenchidas

Fases **191** (checklist + defeitos), **192** (verificações x/y + o vínculo durável) e **194** (a
automação). ⚠️ **DADO-15 não tem backfill possível:** na estreia **nenhuma** imagem antiga terá
original de referência, porque o vínculo que existia era o efêmero de 48 h do Creative Engine e ele
**já expirou**. A comparação lado a lado mostra o estado declarado **"original de referência não
disponível"** (IMG-13) — nunca painel vazio, nunca a imagem de IA repetida no lugar da original.

### ⚠️ As TRÊS perguntas que NÃO são mais pendência — o fechamento as registra como RESOLVIDAS

1. **O vocabulário.** As **três** ocorrências renderizadas de "Em revisão" foram renomeadas
   (decisão do usuário, 14/10), inclusive a 3ª em `visaogeral/acoes.js`, que **não estava na lista
   original**. ➡️ **O critério 2 passa limpo, sem ressalva.**
2. **A margem mínima.** É o **parâmetro da empresa quando > 0** e a **config quando é 0 ou não
   existe** — e **o zero NÃO desliga o bloqueio**. Gate `margem-minima` (186-07, 3/31), com os
   quatro casos mudando o preço-alvo e a nota **de forma observável**.
3. **O split da tarifa do ML.** Medido em **fixture real** (`sale_fee_details` com `percentage_fee`,
   `fixed_fee`, `gross_amount`): o painel sai com as **7 linhas** do protótipo e **não há** caminho
   de linha única no fluxo esperado. Gate `split-da-tarifa` (186-07, 4/40). ⚠️ Das 17 ocorrências
   medidas, **16 têm `fixed_fee: 0`** — e é por isso que o gate tem um caso com `fixed_fee ≠ 0`.

### A decisão 13, EM ABERTO e FORA DE ESCOPO

`PUBLICADO => []` é **terminal**: não há transição saindo de "Publicado". A decisão 12 (o 13º par,
`aprovado → aguardando_aprovacao`) cobre a janela **entre aprovar e publicar**, que é o que o BLOQ-06
pede. **Mexer na foto de um anúncio que JÁ ESTÁ NO AR é outro fluxo**, e quem decide é a **Fase
193**. ⚠️ **Não é pendência desta fase** — está registrado aqui para ninguém descobrir depois.

### Dívidas abertas por este fechamento

| # | dívida | dona |
|---|---|---|
| **DIV-01** | 3 das 4 estruturas não preenchidas declaram a fase dona só no `ROADMAP.md`/neste arquivo, e não no docblock da migration. Três linhas de docblock | **orquestrador** (autorizar) ou **Fase 191** |
| **DIV-02** | o `deferred-items.md` da fase está desatualizado: as 2 entradas foram resolvidas por `0f7e0013` | **orquestrador** |
| **DIV-03** | divergência de contagem da campanha de mutação: briefing diz 6 sobreviventes, a soma das SUMMARYs dá 5 | **orquestrador** |

---

## (J) O QUE PRECISA SER MARCADO À MÃO

⚠️ **`roadmap update-plan-progress` reporta sucesso e NÃO escreve nada**;
`requirements.mark-complete` dá `not_found` para IDs de `REQUIREMENTS-vNN.md`; e
`gsd-sdk query state.advance-plan` **corrompeu o `STATE.md` seis vezes**. ➡️ **É à mão, com
`numstat` conferido.**

1. **`.planning/ROADMAP.md` — o Coverage Map** das famílias da Fase 186 (`VOC`, `ABA`, `AND`, `DADO`,
   `BLOQ`, `FIN`, `MIG`, e os transversais `FID`/`GATE`/`REND`/`TERR`), hoje em `Pending`.
2. **`.planning/ROADMAP.md` — a linha `**Plans**:` da Fase 186** ⚠️ **JÁ ESTÁ preenchida** (os 9
   planos em 6 waves estão lá). **Não reescrever** — só complementar com o resultado do fechamento
   se o orquestrador quiser.
3. **`.planning/STATE.md`** — bloco **aditivo**, à mão, **0 remoções**, conferido por `numstat`.

⚠️ `ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` **não pertencem a nenhum plano** (GATE-07) e
**não foram editados por este plano**.

---

## (K) O CHECKPOINT HUMANO — a conferência visual, em produção

### O que esta fase entregou

O portão de aprovação passou a existir como **dado e regra**: **12 estruturas** em **6 migrations por
assunto**, os **8 estados** de andamento interno com as **13 transições** declaradas, os **8
bloqueios** por oferta com texto e ação de correção, a **composição financeira** com origem por
linha, a **exceção registrada** e o **vínculo durável** com a foto original.

### ⚠️ O que esta fase NÃO entrega de tela

**Nada desta fase desenha tela nova.** Ela é majoritariamente **servidor**. A fila "Aguardando
aprovação" é a **Fase 187**; o painel de revisão é a **188**; a revisão detalhada (checklist e
defeitos) é a **191**. ➡️ **O único conferível na tela agora é o rename.**

### O roteiro — 6 itens

⚠️ **A conferência é EM PRODUÇÃO, na conta 459, e depende de um deploy que SÓ O USUÁRIO AUTORIZA.**
O banco local não tem empresa com `ml_token` e `ml_acervo_itens` está vazia: a rota de Publicações
devolveria **404** aqui.

1. Abrir `/mlb/anuncios` na conta 459 e conferir que a célula de status de um anúncio em revisão do
   ML diz **"Em análise pelo Mercado Livre"** (com o anel aberto do lado, como antes).
2. No trilho de abas de status, conferir o rótulo **"Em análise pelo ML"** com a contagem.
3. Clicar naquela aba e conferir que a lista filtra igual a antes, e que a **URL continua
   `?status=em_revisao`** — a chave de dado **não mudou**.
4. Colar uma URL antiga com `?status=em_revisao` e conferir que ela **ainda funciona**.
5. Conferir que **nenhum** lugar da tela diz "Em revisão" sozinho. ⚠️ **"Em revisão pela ECF" é
   legítimo** — ele diz de quem é a revisão, e é o estado interno novo.
6. ⚠️ **As 6 migrations desta fase precisam rodar no deploy** (`php artisan migrate --force`):
   conferir no fim do deploy que **nenhuma** migration ficou `Pending`. O MariaDB deixa a tabela
   **já alterada** quando o nome de índice estoura 64 caracteres (erro 1059), e o sintoma é
   exatamente esse. ⚠️ Há **4 guardas por migration** medindo os nomes por `strlen`, e a prova
   `migrate → rollback → migrate` rodou no MariaDB **10.4.32 local** — mas o banco de produção é
   outro, e `Pending` silencioso é a classe de falha que mais custou neste projeto.
