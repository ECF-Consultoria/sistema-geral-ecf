# Fase 187 — O FECHAMENTO MEDIDO

> ⚠️⚠️ **Este documento é MEDIÇÃO DATADA, não gate perene.** Cada número vale para o commit escrito
> ao lado dele e **não deve ser "atualizado" por sessão futura** — quem quiser o número de hoje,
> **mede hoje**. O gate PHP deste plano (`fechamento-15-criterios`) exige que o documento **exista**
> e confere a **FORMA** das linhas; ele **não** confere nenhum número. Isso é de propósito: régua que
> acompanha o documento é régua que o executor seguinte ajusta.
>
> **Medido em 18/10/2026**, na máquina de desenvolvimento (`C:/xampp`), pelo executor do plano
> **187-11**.

---

## ⚠️⚠️ §0 — A FASE ESTÁ EM PRODUÇÃO DESDE 18/10/2026

Medido pelo orquestrador na VPS, com autorização explícita do usuário:

| o quê | medida |
|---|---|
| commit no ar | **`7791d7aa`** |
| migrations | **"Nothing to migrate"** · zero pendentes (**esta fase não tem migration**) |
| workers | **9 RUNNING** — o `queue:restart` pegou `high`, `creative` e `ia`, não só `ecf-worker:*` |
| site | **HTTP 200 em 0,53 s** |

➡️ No **Coverage Map** do `ROADMAP.md`, a Fase 187 é **"em produção (18/10/2026, commit
`7791d7aa`)"**. ⚠️ **NÃO é a forma da Fase 186**, que diz *"Entregue no CÓDIGO — NÃO DEPLOYADO"*:
aquilo era verdade em 15/10 e **deixou de ser** (a 186 foi deployada em `a4c4187f`).

⚠️⚠️ **A CONFERÊNCIA VISUAL DO USUÁRIO ESTÁ PENDENTE.** Ela é **em produção, na conta 459**, e o
roteiro está em **§7**. ⚠️ **Nada neste documento afirma que a tela foi conferida.**

---

## ⚠️⚠️ §1 — NENHUM EXECUTOR DESTA FASE VIU TELA RENDERIZADA

Esta seção abre o documento, antes de qualquer número, pela mesma razão que abriu o da Fase 186: sem
ela as 11 SUMMARYs desta fase parecem relatos de tela, e **não são**. Cada fato foi **medido**, não
presumido:

| fato | como foi medido | consequência |
|---|---|---|
| o banco local **não tem empresa com `ml_token`** | `meus()` faz `abort_unless(…, 404)`; a rota de Publicações devolve **404** nesta máquina | nenhuma página da fase abre aqui |
| **`ml_acervo_itens` está vazia** (0 linhas) | contagem no banco local | nenhuma foto real para a galeria |
| **`pub_aprovacao_ofertas` está vazia**, e **nada nesta fase cria oferta** | quem cria é a **Fase 194** | a fila nasce vazia, e isso é honesto (decisão 3 do usuário) |
| a **leitura de produção está BLOQUEADA** nesta sessão | o classificador barra `plink`/`tinker`, e barra também o MCP em subagente | nenhum número desta fase veio de produção |
| **não há navegador** | todo render é `esbuild` + `react-dom/server` com fixture sintético; todo CSS é o construído em `public/build/assets/*.css` | nenhum `useEffect`, `setTimeout` ou `onClick` do browser rodou |
| a suíte PHP roda em **SQLite em memória** | `phpunit.xml` | armadilha de MariaDB não aparece aqui (e esta fase não tem migration, então não há o que aparecer) |

➡️ ⚠️ **Toda afirmação desta fase sobre "a tela mostra X" significa "o HTML gerado contém X".**

➡️ E duas limitações nomeadas, que seguem valendo:
- **O runtime do timer dos toasts NÃO é provado.** Provados, pela **função pura**: o número
  **3800**, a ordem **cancelar → agendar** e a robustez a **relógio adverso**. O que acontece no
  navegador depois de 3,8 s **não foi observado por ninguém**. (Ver **CRIT-12**, marcado
  **RESSALVA** por isso.)
- **Nenhum clique de browser foi disparado.** Onde o clique importava, o handler foi **exportado
  como função pura** e exercido pelo gate (padrão do 187-08/09) — o que prova a função, não o
  evento.

---

## §2 — AS OITO LINHAS DE FORMA FIXA, RE-MEDIDAS

⚠️ **RE-MEDIDAS nesta sessão, não copiadas das 10 SUMMARYs.** O fechamento da Fase 186 teve de
refazer exatamente isto: *"um fechamento que afirma número velho é pior que um fechamento sem
número."*

```
COMMIT: A-MEDIR
BUILD: exit 0 em 63s
MANIFEST: 495 entradas / 202 paginas / isca ausente
JS: A-MEDIR
PHP: A-MEDIR
PROTOTIPO design_handoff_publicador/Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e
PROTOTIPO design_handoff_publicador/Publicacoes v2.dc.html: 659 linhas sha256 56e6c194b896151c
PROTOTIPO new_layout_publicador/Publicacoes v2.dc.html: 656 linhas sha256 6dcbb2f9ff8782e0
```

### Como cada uma foi medida, nesta sessão

| linha | comando |
|---|---|
| `COMMIT` | `git rev-parse --short HEAD` — **primeira ação do executor** foi capturar a PARTIDA (`7791d7aa`); esta linha traz o HEAD do commit em que as suítes rodaram |
| `BUILD` | `npm run build` cronometrado com `date +%s` — **exit 0 em 63 s** |
| `MANIFEST` | `node <script>.cjs public/build/manifest.json`, lendo o JSON com `require` + `Object.prototype.hasOwnProperty.call` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` |
| `PROTOTIPO` | `wc -l` + `sha256sum`, com `test -e` antes dos três |

⚠️ **O `MANIFEST` foi lido pelo JSON, nunca por `grep`** — e o motivo é medido: o hash do bundle sai
**com hífen** (nesta rodada, `assets/app--5yuaubs.js`), e um `grep -E "app-[A-Za-z0-9]*\.js"` **não
casaria**. A **isca** é a entrada inexistente `resources/js/Pages/Mlb/NaoExiste9z7.jsx`: ela saiu
**ausente**, então a sonda não está cega. E o **controle positivo**:
`resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` **está presente**, apontando para
`assets/Aprovacao-CMeVENIK.js` — é a prova de build de que a página da fila **existe e compila**
(entra no **CRIT-03**).

⚠️ **`resources/css/app.css` NÃO é chave do manifest** (divergência N5 do `187-BASELINES.md`,
reconfirmada): o CSS entra por `manifest['resources/js/app.jsx'].css` → nesta rodada
`assets/app-DtSI74zM.css`.

⚠️ **`BUILD` e `MANIFEST` NÃO foram reaproveitados.** O plano permitia reusar se nenhum arquivo sob
`resources/` estivesse no commit medido — e de fato **este plano não escreve nada em `resources/`**.
Rodei o build de todo jeito, porque reaproveitar mediria o build de **outra** wave. `sha256` do
`manifest.json` medido: **`f554c2797e0d14de`**.

⚠️ **Os três `.dc.html` estão byte a byte iguais aos do `187-BASELINES.md`** — **nenhum protótipo se
moveu durante a fase**, e os dois primeiros batem também com os hashes que o fechamento da Fase 186
registrou em 15/10/2026. ⚠️ `design_handoff_publicador/` **não é versionado** (`??`): na máquina de
outro dev ele não existe, e qualquer gate que dependa dele degrada para **`skip` EXPLÍCITO e
NOMEADO**, nunca para "conferido".

### A tabela mecânica contra o `187-BASELINES.md`

| linha | baseline (`75b9f82f`, 15/10) | re-medido (18/10) | veredicto |
|---|---|---|---|
| `BUILD` | exit 0 em 162 s | **exit 0 em 63 s** | **OK** — exit igual; o tempo é da máquina, não régua |
| `MANIFEST` entradas | 489 | **495** | **OK** — ACIMA é esperado (+6: a página da fila + 5 da sessão paralela) |
| `MANIFEST` páginas | 200 | **202** | **OK** — ACIMA é esperado (+1 `Aprovacao.jsx` + 1 alheia) |
| `MANIFEST` isca | ausente | **ausente** | **OK** |
| `JS` testes | 4836 | *(ver §2.1)* | — |
| `JS` falhas | 1 | *(ver §2.1)* | — |
| `PHP` testes | 2346 | *(ver §2.1)* | — |
| `PHP` asserções | 20243 | *(ver §2.1)* | — |
| `Aprovacao.dc.html` | 1432 / `68624883ce35547e` | **1432 / `68624883ce35547e`** | **OK** — não se moveu |
| `design_handoff/Publicacoes v2` | 659 / `56e6c194b896151c` | **659 / `56e6c194b896151c`** | **OK** — não se moveu |
| `new_layout/Publicacoes v2` | 656 / `6dcbb2f9ff8782e0` | **656 / `6dcbb2f9ff8782e0`** | **OK** — não se moveu |

⚠️ **Contagem ACIMA da baseline é ESPERADA; ABAIXO é regressão.** A referência **não se "ajusta"**.
A sessão paralela acrescenta testes e a régua sobe sozinha — e por isso o `--suite` do harness aceita
ACIMA e recusa ABAIXO, nunca o contrário.

---

## §2.1 — AS FALHAS, NOMEADAS POR SEMÂNTICA E **NÃO CORRIGIDAS**

### JS — a única falha é a pré-existente da Fase 87

```
not ok … - Características secundárias nasce recolhido (é o grupo que mais infla)
```

Localizada por **semântica** (`grep -rln` do texto do `test()`), não por número de linha:
**`tests/js/estrutura-grade-glide.test.js`**. ⚠️ **NÃO corrigida, de propósito** — é a falha que
`REF_JS_FALHAS=1` aceita desde a Fase 186, e o arquivo está na lista **ALVO-INTOCADO** (`numstat`
ZERO no range, medido).

⚠️ **E os `skipped` zeraram na wave 09**: eram `pub-float` e `pub-float-line` dormindo na camada de
tokens, acordados pela barra de lote e pelo toast (`Aprovacao.dc.html:897`/`:905`).

### ⚠️⚠️ PHP — DUAS falhas ALHEIAS que vão e voltam com a HORA DO DIA

Arquivo: **`tests/Feature/Publicador/CockpitDaContaTest.php`** — **Fase 179, EM PRODUÇÃO**.

⚠️⚠️ **E O CAMINHO É O NONO FANTASMA DA MILESTONE.** O briefing desta sessão cita
`tests/Feature/Publicador/**Aprovacao/**CockpitDaContaTest.php`, que **não existe**
(`ls` → *"No such file or directory"*). O real é
`tests/Feature/Publicador/CockpitDaContaTest.php`, **fora** de `Aprovacao/` — e é o que o próprio
`deferred-items.md` da wave 08 já registrava corretamente. ➡️ Nove caminhos fantasmas nesta
milestone, **e esta é a razão de a régua exigir `test -e` em CADA caminho citado, inclusive os
citados pelo orquestrador.**

| linha | o que espera | o que obtém |
|---|---|---|
| `:966` | `serie[29] == 12` (*"a última posição é HOJE"*) | **`0`** |
| `:1318` | `'hoje'` na primeira chave dos grupos | **`'ontem'`** |

**Diagnóstico medido, não presumido:** é **fragilidade de FRONTEIRA DE DIA**. O arquivo usa o relógio
**52 vezes** (`grep -c "now()"` = **52**; contando também `setTestNow`/`today()`, **53**) — ⚠️ o
briefing diz **54**, e a medida é 52/53 —, e um dos últimos commits dele é literalmente
**`0edc4462` — `test(179): ancora o relogio no caso de agrupamento da Atividade`**: alguém **já**
consertou **um** caso desta família e deixou **estes dois**. Histórico desta fase: **VERMELHAS** na
wave 08, **VERDES** nas waves 09 e 10, e **vermelhas outra vez hoje**, porque o dia virou.

⚠️ **NÃO são desta fase, e isso foi MEDIDO:** nenhum arquivo de cockpit está no range de commits da
Fase 187 (ver §3, `PASSADA1_VIOLACOES=0`).

⚠️ **NÃO corrigidas, por três razões:**
1. o arquivo é de outra fase, **em produção**;
2. o conserto certo é **ancorar o relógio nos dois casos** (`Carbon::setTestNow` no corpo do teste),
   **sem mexer no serviço** — e isso é trabalho de quem for dono daquela suíte;
3. **não existe `REF_PHP_FALHAS`, e não deve passar a existir.** Criar um limiar de falhas aceito
   para calar isto seria abrir a porta dos fundos que o harness existe para fechar.

➡️ ⚠️ **PENDÊNCIA DE OUTRA FASE, registrada aqui porque vai poluir toda baseline futura:** ancorar o
relógio em `CockpitDaContaTest.php:966` e `:1318`. **Dono: quem mantiver a Fase 179** (ou o
orquestrador, numa quick task).

⚠️⚠️ **E a régua para quem medir depois:** **2** falhas PHP nesta família é o *antes* herdado. **Se a
contagem passar de 2, ou se falhar um teste DIFERENTE, isso É da fase — e tem de ser
investigado.**

---

## §3 — O TERRITÓRIO, PELOS DOIS CAMINHOS

### ⚠️⚠️ §3.0 — O AUTOR NÃO DISCRIMINA. O ESCOPO DO ASSUNTO DISCRIMINA.

```
COMMITS_NO_RANGE = 111   (75b9f82f..HEAD)
COMMITS_DA_FASE  =  68   (assunto `feat|fix|test|docs(187-NN)` ou `(187)`)
ALHEIOS          =  43   (escopos 197, 198, 199, 200, 201, v27, v28, portal,
                          planejador-full, coordenacao, 186-06, quick-261016-*,
                          261016-*, e 2 merges de origin/main)
```

⚠️ `git log --format=%an` devolve **um valor só** (`MB.ECF-100376`) para as três máquinas desta
sessão e das paralelas. Um gate comparando `%an` é **vazio por construção** aqui — é a família
*"gate vazio por construção"* do learnings.

### ⚠️⚠️ §3.1 — PROVA ZERO: o RANGE CRU **ACUSA**, e por isso não serve

Rodei, de propósito, o que o plano prescrevia como "Prova 1" — `git diff --numstat
75b9f82f..HEAD -- <alvo>` sobre os **60** alvos vetados. Resultado medido:

```
RANGE_CRU_ALVOS_ACUSADOS = 12
```

Os **12** acusados, e quem os tocou (medido por `git log --format='%h %s' -- <alvo>`):

| alvo acusado pelo range | tocado por |
|---|---|
| `app/Services/Creative/` (3 arquivos) | sessão paralela — Creative Engine |
| `app/Services/Publicador/Criativos/` (3 arquivos) | sessão paralela |
| `app/Http/Controllers/MlbPublicadorCriativoController.php` | sessão paralela |
| `app/Models/MlAnuncioCriativoKit.php` | sessão paralela |
| `resources/js/Pages/Mlb/Publicador/Editor.jsx` | sessão paralela |
| `resources/js/Components/Publicador/Mesa/` (4 arquivos) | sessão paralela |
| `resources/js/Components/Publicador/useCriativosDoPublicador.js` | `edf67f8d` e `8565896a` — `fix(261016-mgv)` e `fix(261016-ibm)` |
| `app/Support/Publicador/Imagem/ArquivosDeImagem.php` | sessão paralela — v28.0 (acervo/R2) |
| `resources/js/Layouts/AppLayout.jsx` | sessão paralela |
| `app/Services/Portal/` (2 arquivos) | sessão paralela |
| `app/Services/Publicador/ImagemAssetService.php` | sessão paralela — v28.0 |
| `database/migrations/` (3 arquivos) | sessão paralela — `full_compartilhamentos`, `acervo_imagens` |

➡️ ⚠️⚠️ **ACHADO, e ele contradiz a prescrição do próprio plano 187-11:** *"Prova 1 — diff VAZIO por
range"* **não pode passar nesta fase**, e não por defeito da fase. Escrever esse gate deixaria **12
vermelhos**, e o conserto barato seria **tirar alvos da lista** ou **estreitar o range** — que é
exatamente o treinamento que o learnings §4b.2 manda evitar. ⚠️ **NÃO reescrevi o critério para ele
caber:** registrei a medida e substituí a prova por uma que **mede o invariante certo**.

⚠️ E é por isso que `FechamentoDaFase187Test.php` **não usa range de git** — mesma decisão que o
`FechamentoDaFase186Test` já havia tomado (item 10 da lista de armadilhas dele), aqui **re-medida**
em vez de herdada.

### ⚠️ §3.2 — PROVA 1' e PROVA 2: por ESCOPO DO ASSUNTO, dois caminhos independentes

```
ALVOS=60  FANTASMAS=0  COMMITS_DA_FASE=68
PASSADA1_VIOLACOES=0   (laco com `git show --name-only --format=` por commit)
PASSADA2_VIOLACOES=0   (uma passada de `git log --name-only --format='@@%h %s'`)
```

⚠️ **`test -e` em CADA um dos 60 alvos ANTES de qualquer diff** — e é o que achou os quatro
fantasmas de §3.3. **FANTASMAS=0** depois da correção.

⚠️ **ISCA NAS DUAS DIREÇÕES** (sem ela a varredura seria *"Nada precisaria estar errado para este
gate falhar"*, que é ACHADO):

| isca | medida | leitura |
|---|---|---|
| **A** — pôr no veto um caminho que a fase **TOCOU** (`MlbPublicadorAprovacaoController.php`) | disparou em **10** commits da fase | a varredura **vê** |
| **B** — rodar o veto contra os **43 commits ALHEIOS** | **30** acertos | o veto **casa** o que ele descreve |

### ⚠️⚠️ §3.3 — OS QUATRO CAMINHOS FANTASMAS, e o oitavo da milestone

⚠️ `git diff -- <caminho que não existe>` sai **VAZIO**, e vazio é **indistinguível de "intocado"**.
Quatro caminhos citados em briefing/plano **não existem onde foram citados** — e os quatro têm o
arquivo REAL em outro lugar, o que é **pior** que a ausência: a conclusão *"território intocado"*
seria tirada sobre o arquivo errado.

| # | caminho CITADO (fantasma) | caminho **REAL**, medido por `git ls-files` |
|---|---|---|
| 5 | `app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` | **`app/Services/Publicador/Fila/ResumoRapidoService.php`** |
| 6 | `app/Services/Publicador/SimuladorVoceRecebe.php` | **`app/Support/Publicador/Validacao/SimuladorVoceRecebe.php`** |
| 7 | `app/Services/Publicador/AnaliseAlavancasService.php` | **`app/Services/Publicador/Alavancas/AnaliseAlavancasService.php`** |
| 8 | `resources/js/Pages/Mlb/Publicador/useCriativosDoPublicador.js` | **`resources/js/Components/Publicador/useCriativosDoPublicador.js`** |

⚠️⚠️ **CORREÇÃO AO BRIEFING, medida:** o briefing desta sessão diz que
`…/Aprovacao/Fila/ResumoRapidoService.php` é fantasma **e que "o real é `FilaDeAprovacaoService.php`"**.
A primeira metade está certa; **a segunda não**. `ResumoRapidoService` **existe** — em
`app/Services/Publicador/Fila/` — e **não é** o `FilaDeAprovacaoService`: é o serviço da **visão
rápida do lote**, aquele cujo `ResumoRapidoService:384-391` a **decisão 9 do usuário** cita como o
lugar onde a margem usa o **parâmetro da Precificação** (e não a tarifa real do ML). São dois
arquivos com dois papéis; confundi-los apagaria uma decisão do usuário.

➡️ **É exatamente a razão de a régua exigir `test -e` em CADA caminho citado**, antes de qualquer
conclusão. O gate `territorio-e-fid` (método
`test_territorio_187_os_alvos_citados_existem_no_disco`) torna isso **perene**: cada linha
`ALVO-INTOCADO:` deste documento é conferida no disco, com isca de caminho inventado.

### §3.4 — A LISTA DOS 60 ALVOS (conferida no disco, uma por uma)

<!-- ⚠️ Linhas de forma fixa: o prefixo abre a linha, e o gate `territorio-e-fid` as lê ancoradas
     em `^`. Menção no MEIO de uma frase NÃO conta (há isca provando). NÃO editar sem rodar
     `sh tests/gates/verificar-testes.sh 187-11 territorio-e-fid`. -->

ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/LinhaFinanceira.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/VocabularioDoPortao.php
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx
ALVO-INTOCADO: app/Services/Creative/
ALVO-INTOCADO: app/Services/Publicador/Criativos/
ALVO-INTOCADO: app/Http/Controllers/MlbPublicadorCriativoController.php
ALVO-INTOCADO: app/Console/Commands/LimparReferenciasCriativos.php
ALVO-INTOCADO: app/Models/MlAnuncioCriativoKit.php
ALVO-INTOCADO: resources/js/Pages/Mlb/AnunciarML.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/components/PainelCriativosIa.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/components/KitCriativosGrade.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/Publicador/Editor.jsx
ALVO-INTOCADO: resources/js/Components/Publicador/Mesa/
ALVO-INTOCADO: resources/js/Components/Publicador/useCriativosDoPublicador.js
ALVO-INTOCADO: app/Support/Publicador/
ALVO-INTOCADO: app/Services/Mlb/Publicacao/MlPublicacaoService.php
ALVO-INTOCADO: resources/js/Layouts/AppLayout.jsx
ALVO-INTOCADO: app/Services/Bling/
ALVO-INTOCADO: app/Services/Portal/
ALVO-INTOCADO: app/Mcp/
ALVO-INTOCADO: app/Services/Publicador/ImagemAssetService.php
ALVO-INTOCADO: app/Services/Publicador/Fila/ResumoRapidoService.php
ALVO-INTOCADO: app/Services/Publicador/DadosEfetivosService.php
ALVO-INTOCADO: app/Services/Publicador/Alavancas/AnaliseAlavancasService.php
ALVO-INTOCADO: app/Models/EstruturaPrecificacaoParametros.php
ALVO-INTOCADO: app/Services/Publicador/EditorRascunhoService.php
ALVO-INTOCADO: tests/js/estrutura-meus-anuncios.test.js
ALVO-INTOCADO: tests/js/publicador-header-zindex-ordem.test.js
ALVO-INTOCADO: tests/js/estrutura-grade-glide.test.js
ALVO-INTOCADO: database/migrations/
ALVO-INTOCADO: package.json
ALVO-INTOCADO: package-lock.json
ALVO-INTOCADO: composer.json
ALVO-INTOCADO: composer.lock
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/identidade/
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/visaogeral/
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/header/
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/AbasDeStatus.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/BuscaDePublicacoes.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/CabecalhoDaTabela.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/CelulasDaLinha.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/ChipsDeFiltro.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/ChipsDeTriagem.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/FaixaDeSucesso.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/LinhaDeControles.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/LinhaDeGrupoDeProduto.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/LinhaDePublicacao.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/MenuDeFiltro.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/RodapeDaTabela.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/publicacoes/VaziosDaLista.jsx

⚠️ **`ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` NÃO estão na lista, e isso é deliberado**
(GATE-07): eles **não pertencem a nenhum plano** — quem os edita é o orquestrador, e um gate de
território que os incluísse **acusaria o orquestrador**.

⚠️ **"Árvore limpa" por `git status` ABORTA aqui** pelo `??` de outras sessões (há mais de 30
arquivos não rastreados no working tree, inclusive `tests/Feature/CompanyPortfolioAccessTest.php`,
que **não é meu**). A conferência é `git diff HEAD` nos **meus** arquivos.

### §3.5 — T-187-SC: a fase não instalou nada, provado por DUAS vias

| via | medida |
|---|---|
| `numstat` no range inteiro | `package.json`, `package-lock.json`, `composer.json`, `composer.lock` → **VAZIO nos quatro**, inclusive contando os 43 commits alheios |
| **FORMA** (gate perene, sem git) | os **11** PHP novos usam **só** `App\` (47), `Illuminate\` (22) e `Inertia\` (1); os **20** `.jsx` novos importam **só** `react`, `@inertiajs/react`, o alias `@/` e caminhos relativos |

⚠️ A via da FORMA é a que vale como gate: `numstat` de range **não discrimina a sessão paralela**; o
import está **dentro** dos arquivos desta fase. E a lista de pacotes permitidos **é conferida contra
o `package.json`**, para ela não ser a própria porta dos fundos (learnings §4c: *"o que impede
alguém de entrar na lista de exceções?"*).

### §3.6 — A contagem FIXA de arquivos da fase, medida UMA vez AQUI

⚠️ **Uma asserção, aqui** — e **não** uma por plano: contagem que sobe a cada wave é frágil por
construção e **treina o executor seguinte a ajustar réguas quando elas incomodam** (learnings §4b.2;
**quatro** gates caíram nisso na Fase 186, e **nove** ocorrências da família já foram medidas nesta
milestone).

| medida | número | como |
|---|---|---|
| arquivos **novos** nos 68 commits da fase | **71** | `git show --diff-filter=A --name-only` por commit, `sort -u` |
| ↳ `.php` | 20 | 11 de produção + 9 de teste |
| ↳ `.jsx` | **20** | **todos de produção** |
| ↳ `.md` | 12 | planejamento |
| ↳ `.txt` | 10 | os manifestos `contagens-187-01..10.txt` |
| ↳ `.js` | 9 | **todos em `tests/js/`** |
| `.jsx` de produção, por DOIS caminhos independentes | **20 = 20** | (a) git `--diff-filter=A` sob `resources/`; (b) as entradas da Fase 187 em `ARQUIVOS_NOVOS` |
| `.jsx` no diretório `aprovacao/` | **21** | 18 da Fase 187 + 3 da Fase 186, `deepEqual` contra o disco |

⚠️⚠️ **CORREÇÃO AO PLANO, medida.** O plano 187-11 prescreve, no Teste 4: *"nenhum arquivo novo da
fase é `.js` — todos são `.jsx`"*. **Isso é literalmente FALSO**: há **9** arquivos `.js` novos, e
todos são **testes** (`tests/js/*.test.js`), que o `content` do Tailwind não varre e não precisa
varrer. ➡️ **O invariante certo, e o que foi implementado:** *nenhuma peça nova sob `resources/` é
`.js`* — porque `tailwind.config.js` varre `**/*.jsx` e **não** `**/*.js`, então classe num `.js`
**não compilaria**, com todo teste verde. **Gate mais largo que o invariante é a família de DEZ
ocorrências desta milestone**; escrevi o estreito, com isca.

---

## §4 — OS 15 CRITÉRIOS DE SUCESSO, UM POR UM

⚠️⚠️ **São 15, NÃO 17.** Contados por mim, no `ROADMAP.md`, em 18/10/2026:
`awk '/^### Phase 187:/,/^### Phase 188:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "` devolve
**15**, e os números extraídos são **1 2 3 4 5 6 7 8 9 10 11 12 13 14 15** — sem buraco e sem
repetição. **17 é a contagem da Fase 186.**

⚠️⚠️ **A FORMA FIXA É O QUE IMPEDE O "AJUSTE" SILENCIOSO.** Critério que não passa recebe
**RESSALVA** ou **REPROVA** com a razão — **nunca** é reescrito para caber. (Na Fase 186 o critério 7
**foi** reescrito, e com razão declarada: ele **contradizia o 6** e era **aritmeticamente
impossível**. Nada dessa natureza apareceu na 187; se aparecesse, estaria escrito aqui e devolvido ao
usuário, não consertado por conta própria.)

CRIT-01: OK — o subtítulo saiu do cabeçalho de Publicações e entraram as duas abas alinhadas à base (`gap:20px`, `mb:-1px`, 40px/14px), com `border-bottom:1px solid #1F1F1F` no `<header>` e a coluna do H1 em `gap:14px` — medido contra `design_handoff_publicador/Publicacoes v2.dc.html:31`-`:37` — gate cabecalho-sem-subtitulo, 86 testes + gate abas-da-pagina, 111 testes
CRIT-02: RESSALVA — a pílula amarela (`#FACC15`, `#0A0A0A`, 11.5px/700, h20, min-w22) traz o contador do SERVIDOR (`aguardando_aprovacao`, soma de `awaiting`+`ecf_review`+`changes` em uma consulta, nunca somado no cliente) e navega para a rota nova; ⚠️ **a ressalva é a decisão 17, medida pelo 187-02:** o recorte usa `company_id AND mlb_empresa_id`, e `mlb_empresa_id` é ANULÁVEL — oferta gravada com nulo ficaria INVISÍVEL e a pílula diria `0` com trabalho esperando. **Hoje não é bug** (nada cria oferta); **dono: Fase 194** — gate contador-e-territorio, 8/102 + gate abas-da-pagina, 111 testes
CRIT-03: RESSALVA — a aba não aponta para o vazio: a rota `mlb.anuncios.meus.aprovacao` nasceu nesta mesma fase, responde **200** com as props montadas no servidor, e a página está no manifest do Vite (`assets/Aprovacao-CMeVENIK.js`); ⚠️ **a ressalva é o que NÃO pôde ser provado:** "abre a fila com dado real" vale para o CONTRATO do servidor, nunca para uma oferta de verdade na tela — `pub_aprovacao_ofertas` está vazia e **nada nesta fase cria oferta** (decisão 3 do usuário, honesta; dono do dado: **Fase 194**) — gate fila-rota, 7/53 + gate pagina-rota, 6/171
CRIT-04: OK — a lista de Publicações continua mostrando só ofertas que existem no Mercado Livre (oferta em preparação ou em aprovação aparece apenas na área nova), e busca, filtros, agrupamento, painel, lote, atalhos e header não mudaram — medido por `numstat` ZERO nos 13 outros `.jsx` de `publicacoes/` e nos 3 diretórios `identidade/`, `visaogeral/`, `header/` — gate montagem-e-territorio, 111 testes + gate contador-e-territorio, 8/102
CRIT-05: OK — a faixa é `repeat(9,minmax(0,1fr))` com `gap:6px`, dot, rótulo 11.5px e número 22px/600; clicar filtra e leva a etapa à URL; o ativo tem `#181818` e borda `#3F3F46`; ⚠️ **as contagens saem sobre os DEMAIS filtros** e não sobre o recorte por etapa — invariante que a mutação achou FURADO em duas rodadas (fixture com uma oferta só não distinguia) e que só ficou provado com fixture de três ofertas e asserções pelas PROPS — gate faixa-de-andamento, 99 testes + gate tres-escopos, 4/47
CRIT-06: OK — "PRÓXIMO A REVISAR" escolhe pela regra literal de três degraus (a `awaiting` não tocada com maior espera → a primeira `live` não tocada → a primeira `live`), com a ORDEM delegada a `ordenarFila()` e nunca reescrita; faixa `#14120A` / borda `rgba(250,204,21,0.35)`, "Pronto para aprovar" ou "N pendências" e o botão amarelo "Revisar agora" apontando para `?oferta=` (rota que existe e responde 200) — gate proximo-servico, 14/90 + gate proximo-render, 76 testes
CRIT-07: OK — busca por produto, título e SKU **no servidor**, ignorando acento e caixa (normalização em PHP, nos dois lados, por um mapa só; collation e cadeia de `REPLACE` em SQL foram RECUSADAS por medição); 2 segmentados, os 2 popovers com contagem e tint amarelo quando ativos, "Limpar filtros" e "N de M ofertas"; ⚠️ o custo do filtro de motivo é resolvido **fora do request**, pré-aquecendo a tarifa do ML — gate busca-sem-acento, 4/84 + gate filtros-fechados, 4/50 + gate filtros-render, 29 testes + gate custo-do-ml, 2/13 + gate aquecimento-custo-e-chave, 3/33
CRIT-08: OK — o grid literal `32px 24px minmax(170px,1fr) 104px 118px 92px 108px 76px 112px 164px 96px` é CONSTANTE EXPORTADA (cabeçalho e linha não podem divergir); linha de produto 52px (`#151515`, tag PRODUTO, "N ofertas"), sub-linha de conjunto 38px (`#0F0F0F`, recuo 62px, quadrados de 10px, "x/y aprovadas", o pill "Mesmas fotos em Clássico e Premium · aprovação compartilhada" e "Revisar fotos do conjunto"), e a linha da oferta com `min-h-[64px]` — ancorado no começo da classe, porque `min-h-[64px]` CONTÉM `h-[64px]` — gate tabela-niveis-1-e-2, 27 testes + gate tres-niveis, 3/19 + gate cadastro-e-sublinha, 3/14
CRIT-09: OK — as 11 células batem com o protótipo: anel nos 4 estados com `title` SEMPRE não-vazio (chave desconhecida cai no NEUTRO, nunca em `parcial`), título em peso 600 quando não analisada, meta em uma linha com ellipsis (SKU · ⚡/✎ · vN · EXCEÇÃO), ◆ no Premium, "N fotos · N IA" com quadrados de 12px e resumo colorido, "Incompleto" em âmbar, margem 0–40% com o traço em `min(40,max(0,minimo))/40` (FÓRMULA, não o `30%` literal), responsável em âmbar acima de 24 h, pill de andamento com a sub-linha do primeiro bloqueio e a ação certa por estado — gate anel-e-meta, 2/20 + gate dinheiro-da-linha, 3/41 + gate margem-e-preco, 2/38 + gate fotos-e-espera, 2/21 + gate andamento-e-acao, 2/32 + gate linha-da-oferta-render, 23 testes
CRIT-10: OK — a ordenação é a literal de `ORD` (falha → aguardando/em revisão → ajustes → aprovado → publicando → publicado → preparando) e, dentro de cada grupo, **maior espera primeiro** — delegada ao módulo da Fase 186, com gate provando DELEGAÇÃO PURA (nenhuma lógica própria no serviço-fachada) — gate fila-servico, 10/46
CRIT-11: OK — marcar linhas mostra a barra flutuante "N selecionadas · Aprovar K para publicação · ×"; o checkbox do cabeçalho marca todas as visíveis; o modal de lote lista TODAS as selecionadas com ✓/× **e o motivo** (esconder as inaptas foi oferecido e RECUSADO pelo usuário), e aprova **só as aptas, cada uma na versão indicada** — a trava real é `aprovarEmLote` no servidor (BLOQ-04), não a camada de tela — gate lote-veredicto, 3/50 + gate lote-escrita, 3/26 + gate lote-rota, 3/23
CRIT-12: RESSALVA — os toasts ficam no **topo central** (irmãos do header, sem portal — render de servidor não desenha portal e o gate passaria POR OMISSÃO), com os **6 literais do módulo** (`TOASTS` da Fase 186) e "Publicado e confirmado no Mercado Livre · MLB…" **só** com MLB confirmado; ⚠️ **a ressalva é o RUNTIME dos ~3,8 s, que NÃO é provado** — não há navegador: provados, pela função pura, o número **3800**, a ordem **cancelar → agendar** e a robustez a relógio adverso — gate lote-render, 26 testes
CRIT-13: OK — **zero `localStorage`** e **nenhum "Restaurar dados de exemplo"** (gate de ausência com isca por FORMA, e a mutação que os devolveu MORREU); filtros e seleção vivem na **URL** (`?etapa=`, `?sel=`), com a seleção **saneada contra a conta** — e a mutação M16 provou que um `assert.match` sobre o TEXTO do controller era **proxy de fonte**, não comportamento — gate filtros-na-url, 2/48 + gate selecao-na-url, 1/13 + gate lote-render, 26 testes
CRIT-14: OK — render adverso de **todo** campo novo (objeto, nulo, ausente; coleção nula/string/número; prop inteira nula), sem `[object Object]`/`NaN`/`undefined`/`Invalid Date`; e **nenhuma flag fora do callback do `.map()`** — com a correção medida na wave 09: expressão de `.map()` é aceita quando tudo que ela lê é parâmetro ou nome de MÓDULO, que o Rollup nunca elide — gate linha-da-oferta-render, 23 testes + gate cabecalho-da-fila, 99 testes + gate tabela-niveis-1-e-2, 27 testes
CRIT-15: OK — a tabela FID-01 da fila foi medida contra `design_handoff_publicador/Aprovacao.dc.html` (sha256 `68624883ce35547e`, não se moveu) e **declara como mediu**, plano por plano, em §5 — **8 de 10 por SCRIPT e 2 SEM-PROTOTIPO (servidor e infraestrutura, onde não há `.dc.html` a medir); ZERO por LEITURA**, com `cursor` em coluna própria e as referências RE-MEDIDAS por `grep -n` (os planos erraram 9/16, 7/12, 8/9, 13/20, 11/17 e 19/25 ponteiros) — gate vocabulario-tamanhos, 443 testes + gate territorio-e-fid, 3/32

### Placar

| veredicto | quantos | quais |
|---|---|---|
| **OK** | **12** | 01, 04, 05, 06, 07, 08, 09, 10, 11, 13, 14, 15 |
| **RESSALVA** | **3** | **02** (decisão 17, dono Fase 194) · **03** (fila nasce vazia, dono do dado Fase 194) · **12** (runtime do timer não provado, sem navegador) |
| **REPROVA** | **0** | — |

⚠️ **Nenhum `REPROVA`.** As três ressalvas são **limites declarados do ambiente e do escopo**, com
dono nomeado — nunca trabalho faltando dentro desta fase.

---

## §5 — A TABELA FID-01 CONSOLIDADA, COM `SCRIPT` OU `LEITURA` DECLARADO

> *"Tabela derivada de SCRIPT é confiável; tabela derivada de LEITURA não é."* — medido neste
> projeto em 13/10/2026.
>
> ⚠️⚠️ **FID-03: a tabela não erra VALOR — erra por OMISSÃO e por REFERÊNCIA.** Neste projeto
> `cursor:pointer` foi omitido **onze vezes** numa única fase. Por isso a conferência é por
> **SEMÂNTICA** (localizar o identificador), **nunca** pelo número de linha que o plano citou.

<!-- ⚠️ Forma fixa lida pelo gate `territorio-e-fid`. Uma linha por plano, 01 a 10. -->

FID-01-PLANO-01: SCRIPT — o E1 INTEIRO (`design_handoff_publicador/Aprovacao.dc.html:26`-`:205`, 180 linhas, sha256 `68624883ce35547e`), 21 varreduras + `cursor` em coluna própria (16 ocorrências, uma por linha); o script está colado inteiro no `187-BASELINES.md`
FID-01-PLANO-02: SEM-PROTOTIPO — plano de SERVIDOR (rota, escopo da conta, contador, faixa, ordenação): zero `.dc.html` a medir; o que foi medido por script foi o CÓDIGO (`app/Http/Controllers/MlbAnuncioController.php` — 19 props em `meus()`, não as "20" que plano e briefing afirmavam)
FID-01-PLANO-03: SCRIPT — a seção A1 de `design_handoff_publicador/Publicacoes v2.dc.html:31`-`:37`, com `cursor` em coluna própria; e o `diff` contra `new_layout_publicador/Publicacoes v2.dc.html` medido em **4 hunks** (dois deles da seção **A2**, não da A1 — divergência N1)
FID-01-PLANO-04: SCRIPT — o cabeçalho da fila e os 9 cards (`design_handoff_publicador/Aprovacao.dc.html:29`-`:52`), incluindo o ⓘ de `:39` (um `<span>` de 16px com a letra `i`, não um ícone — D4)
FID-01-PLANO-05: SCRIPT — a faixa "PRÓXIMO A REVISAR" (`design_handoff_publicador/Aprovacao.dc.html:56`-`:64`, mais a regra de `:1220`-`:1264`), com o rótulo da espera medido como `' d'` e não `' dias'`
FID-01-PLANO-06: SCRIPT — a barra de filtros (`design_handoff_publicador/Aprovacao.dc.html:66`-`:106`), os dois popovers (`:82`/`:95`) e a lupa SVG de `:68`; o `z-index` **remapeado** e nunca copiado (D1)
FID-01-PLANO-07: SCRIPT — a tabela, níveis 1 e 2 (`design_handoff_publicador/Aprovacao.dc.html:108`-`:132`), com o grid de 11 colunas e `cursor` em coluna própria; o plano errava **11 de 17** ponteiros
FID-01-PLANO-08: SCRIPT — a linha da oferta, as 11 células (`design_handoff_publicador/Aprovacao.dc.html:133`-`:177`), com `cursor`, `white-space` e `text-overflow` em colunas próprias; inclui o `cursor:pointer` do `<div>` de `:133`, o único do E1 fora de `<button>` (N3)
FID-01-PLANO-09: SCRIPT — lote, toasts, rodapé e os dois vazios (`design_handoff_publicador/Aprovacao.dc.html:180`-`:205`, mais `:897`/`:905`), varrido por `python`, com `cursor`/`white-space`/`text-overflow` em colunas próprias; **cinco omissões da tabela do plano achadas por script**
FID-01-PLANO-10: SEM-PROTOTIPO — plano de INFRAESTRUTURA (pré-aquecimento da tarifa): não há tela nem `.dc.html`; o que foi medido por script foi `app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php` (a chave de cache **não é recopiada**: quem a constrói é o próprio `para()`)

⚠️ **10 linhas, 8 por SCRIPT e 2 SEM-PROTOTIPO** — e as duas sem protótipo são de servidor e de
infraestrutura, onde não existe `.dc.html` a medir. **Nenhuma por LEITURA.**

### ⚠️ A REGRA DE OURO, e a exceção declarada

**Quando a tabela discordar do protótipo, MEÇA no `.dc.html` e o PROTÓTIPO GANHA**; a divergência vai
registrada com `arquivo:linha`.

⚠️ **EXCEÇÃO:** o protótipo ganha na **FÓRMULA**, nos **RÓTULOS** e na **ORDEM** — **NUNCA nos
NÚMEROS**. As taxas de `fin()` são ficção do próprio protótipo (`Aprovacao.dc.html:1064`-`:1066`,
"taxa de exemplo", `src` igual a `est`), e a seção F do `README-v2.md` é **prosa**: só `info()`
decide bloqueio.

### ⚠️⚠️ OS DOIS CASOS DESTA FASE EM QUE O PROTÓTIPO **PERDEU** — registrados juntos

Sozinho, cada um parece arbitrário. Juntos, eles delimitam a exceção à regra de ouro:

| # | o que estava em jogo | o que ficou | por quê |
|---|---|---|---|
| 1 | a prosa do **plano** dizia `' dias'` por extenso; o **protótipo** diz `' d'` (`Aprovacao.dc.html:1054`) | **`' d'`** | ⚠️ aqui o protótipo **GANHOU** — quem perdeu foi a prosa do plano. Está aqui para o par ficar completo e ninguém trocar os dois casos |
| 2 | o **protótipo** diz **"N ofertas aprovadas"** no toast do lote (`Aprovacao.dc.html:1167`) | **`TOASTS.aprovado`** — "Aprovado — aguardando publicação", com a contagem no sub | ⚠️ **o protótipo PERDEU, por DECISÃO DO USUÁRIO (17-18/10/2026)**: adotar a frase dele criaria uma **7ª frase fora do vocabulário travado** dos 6 `TOASTS` (VOC-08, com gate). O vocabulário é o invariante; a frase é redação |

➡️ **A regra "o protótipo ganha" vale para FÓRMULA, RÓTULO e ORDEM do DESENHO.** Ela **não** vale
contra um **vocabulário de produto já travado e gateado** — e essa é a **única** exceção que esta
fase exerceu.

### As 10 divergências do `187-BASELINES.md` (D1..D10) — veredicto de cada uma

| # | divergência | veredicto no fim da fase |
|---|---|---|
| **D1** | a escala de `z-index` do protótipo (40..120) é incompatível com a travada do projeto (filtros 10 < header 30 < menu/painel 40) | **REMAPEADA, nunca copiada** (187-06): overlay 10, popover 20, toast `z-[45]` na escala daqui. A mutação que subia o popover para `z-40` MORREU. A ordem travada de `publicador-header-zindex-ordem.test.js` **não** foi tocada (`numstat` ZERO) |
| **D2** | o `main` da fila é `padding:24px 36px 120px; gap:16px`; o de Publicações é `28px 36px 110px; gap:18px` | **NÃO harmonizados** — a mutação que tirava o `gap-[16px]` MORREU |
| **D3** | linha com FOCO é `#1A1A1A` na fila e `#1C1C1C` em Publicações (4 ramos contra 5 ramos) | **NÃO unificados** |
| **D4** | o ⓘ da margem mínima não é ícone: é um `span` redondo de 16px com a letra `i` | **reproduzido** (187-04, `:39` — a referência do plano, `:38`, era o `div` que o embrulha) |
| **D5** | a lupa é SVG inline `viewBox="0 0 16 16"` com `stroke-width:1.6` (Lucide é `0 0 24 24`) | **SVG inline**, não Lucide (187-06, `:68`) |
| **D6** | o popover de Motivo tem `hint-placeholder-count` igual a 6, mas `BLK` declara **8** motivos | **O DADO GANHOU DO PLACEHOLDER** — 8 motivos (187-06) |
| **D7** | o `title` do mini-quadrado em `regenerating` diz **"a conferir"**, embora a borda seja tracejada | **REPRODUZIDO DE PROPÓSITO** e registrado no `deferred-items.md` — o protótipo é a autoridade do rótulo |
| **D8** | `countLabel` é `list.length` + " de " + `all.length` — o **M** é o total de TODAS as ofertas da conta | **reproduzido** (187-06), e é o que faz o "N de M" não mentir |
| **D9** | `ids` (que o checkbox do cabeçalho marca) **inclui** as `preparando`; só `_nav` as exclui | **REPRODUZIDO DE PROPÓSITO** e no `deferred-items.md`. ⚠️ A segurança não depende disto: a trava real é `aprovarEmLote` **no servidor**, e o modal mostra ✓/× **com o motivo** |
| **D10** | o traço do mínimo está em `left:30%` **porque `MIN` é 12 e 12/40 dá 30%** | **implementada a FÓRMULA** `min(40,max(0,minimo))/40` (FIN-09), nunca o `30%` literal — o mínimo vem do parâmetro e pode ser outro por empresa |
| **D-ANEL** (a 11ª) | o protótipo gira em `.8s`; o token `animate-pub-spin` é **`.7s`** | **divergência HERDADA de propósito** — ela já está escrita em `SeloDeAndamentoInterno.jsx` (Fase 186, somente leitura). Criar um `.8s` ao lado criaria **duas verdades**. **Nenhum token de animação foi criado** |

### As 5 divergências NOVAS do `187-BASELINES.md` (N1..N5) — reconfirmadas

| # | o quê | estado no fim da fase |
|---|---|---|
| **N1** | as duas cópias de `Publicacoes v2.dc.html` diferem em **4 hunks**, não só na seção A1 (dois são da **A2**) | confirmada; quem implementar a seção A precisa dos quatro |
| **N2** | os 5 tokens novos **não podiam** compilar no plano 01 (o `content` varre só `*.jsx`, e aquele plano não escreveu `.jsx` nenhum) | **RESOLVIDA**: o gate era `skip` NOMEADO por token e **acordou sozinho** nas waves que escreveram os `.jsx`; os `skipped` da suíte JS **zeraram na wave 09** |
| **N3** | o `cursor:pointer` de `:133` está num **`div`** — o único do E1 fora de `button`, e é a LINHA INTEIRA da tabela | confirmada e implementada (187-08) |
| **N4** | `border-left` **não existe** no E1 (varredura vazia é medida, não descuido) | confirmada |
| **N5** | `resources/css/app.css` **não é chave** do `manifest.json` | **reconfirmada nesta sessão**: o CSS entra por `manifest['resources/js/app.jsx'].css`, hoje `assets/app-DtSI74zM.css` |

### Divergências NOVAS medidas **neste plano de fechamento**

| # | o quê | medida |
|---|---|---|
| **F1** | ⚠️ A "Prova 1 por RANGE" que o próprio plano 187-11 prescreve **não pode passar**: o range tem 43 commits alheios e acusa **12** dos 60 alvos. **Registrada, não contornada** — e o gate perene foi escrito sobre o invariante certo | §3.1 deste documento |
| **F2** | ⚠️ Quatro **caminhos fantasmas**, os quatro com o arquivo REAL em outro lugar — e o `ResumoRapidoService` **existe** e **não é** o `FilaDeAprovacaoService` | §3.3 deste documento |
| **F3** | ⚠️ O Teste 4 do plano ("nenhum arquivo novo da fase é `.js`") é **literalmente falso**: há 9 `.js` novos, todos testes. O invariante certo é sobre `resources/` | §3.6 deste documento |
| **F4** | A SUMMARY do 187-08 registra `Aprovacao.dc.html` com **1433 linhas**; o `wc -l` desta sessão — e as SUMMARYs 01 e 09 — dizem **1432**. O **sha256 é idêntico nas três medições** (`68624883ce35547e`), então **o arquivo não mudou**: é erro de transcrição numa SUMMARY. É a família FID-03 ("erra por REFERÊNCIA") acontecendo num número de linhas | `187-08-SUMMARY.md:191` contra `wc -l` |
| **F5** | ⚠️ **Eu caí na armadilha do `lerSemComentarios` ao escrever o gate deste plano** — e isso é a melhor prova de que ela está viva: o helper remove blocos de comentário, e `'./resources/js/**/*.jsx'` **contém** um `/*` seguido de um `*/`, então o glob chega ao gate como `'./resources/js*.jsx'` e o gate mede NADA. ⚠️ **Não é achado novo**: está medida desde a Fase 179-06, e há **duas iscas invertidas** no repositório exigindo que o helper CONTINUE mastigando o glob. Segui o precedente (leitura **CRUA**) e acrescentei a **terceira** isca | `tests/js/_fonte.js` contra `publicador-visao-geral-acoes.test.js:344` e `publicador-aprovacao-vocabulario.test.js:746` |
| **F6** | O briefing desta sessão diz que o `--autoteste` tem **20** casos; a SUMMARY do 187-01 registra **16** (ela elevou de 14 para 16). Os **4** que entraram depois são as **iscas da forma `>=N`** (16/10/2026). **Re-medido hoje: 20 casos, 0 divergência** | `verificar-testes.sh`, casos 4 a 7 |
| **F7** | ⚠️ **O NONO caminho fantasma da milestone**, e ele veio do briefing do orquestrador: `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` **não existe**; o real é `tests/Feature/Publicador/CockpitDaContaTest.php`, **fora** de `Aprovacao/`. ➡️ `test -e` vale também para os caminhos que o **orquestrador** cita | `ls` nos dois caminhos; §2.1 |
| **F8** | O briefing diz que o `CockpitDaContaTest` usa o relógio **54** vezes; medido: **52** (`grep -c "now()"`) e **53** contando `setTestNow`/`today()`. O `deferred-items.md` da wave 08 registra **52**, e está certo | `grep -c` em `tests/Feature/Publicador/CockpitDaContaTest.php` |

---

## §6 — A CAMPANHA DE MUTAÇÃO CONSOLIDADA

### A disciplina, igual nos onze planos

1. **COMMITAR ANTES de mutar** — mutação sobre trabalho não commitado é risco sem necessidade;
2. `cp` do arquivo para o **scratchpad** (fora do repositório);
3. aplicar com a **ocorrência TRAVADA EM 1 ANTES** de substituir (aborta se diferente de 1);
4. **linha conferida DEPOIS** de aplicar;
5. restauração **DA CÓPIA**, em `trap EXIT` / `finally`;
6. `sha256` da árvore contra `git show HEAD:`, com `tr -d '\r'` **nos dois lados**;
7. ⚠️⚠️ **`git checkout` NUNCA** — ele comeu **90 linhas** de gate na Fase 186 (learnings §4c.1).

### Uma linha por plano

| plano | mutações | mortas | sobreviventes | observação |
|---|---|---|---|---|
| **187-01** | **0** | — | — | ⚠️ **declarado:** plano de baselines, harness e tokens — não há código de tela para mutar. A prova de que o harness não é vazio é o **`--autoteste`** (20 casos), que é mutação por outro nome |
| **187-02** | 8 (+2 reaplicações) | 8 | **0** | a **M6** é a que mais importa: alargar o escopo do gate de território para incluir a wave 1 faz ele **reprovar** — prova de que o gate **não é vazio por construção** |
| **187-03** | 10 | 10 | **0** | inclui a mutação do `href` da aba para `'#'` e a da aba ativa **ganhando** `href` (navegar para si mesma, D4) |
| **187-04** | 18 | 18 | **0** | **3 mutações ABORTARAM antes de rodar** — e isso é a trava funcionando: duas por **2 ocorrências** (uma no docblock, uma no JSX) e uma por **0 ocorrências** (`grep -F` não casa escape de regex). ⚠️ Uma delas, `ativa="aprovacao"` no docblock, é exatamente a família *"comentário que explica casa com o gate que conta"* |
| **187-05** | 26 (9 PHP + 17 JS) | 26 | **0 depois de 2 consertos** | ⚠️ a **M4** (tirar o recorte de `LIVE` de dentro de `escolher()`) **SOBREVIVEU** e era **lacuna de cobertura REAL** |
| **187-06** | 17, em **3 rodadas** | 17 | **0 na rodada 3** | ⚠️⚠️ **DUAS LACUNAS REAIS**, e a causa das duas era a **FIXTURE**, não a asserção: com **uma** oferta ao vivo só, "a conta inteira" e "as ao vivo" davam o **MESMO número**. Consertado com fixture de **TRÊS** ofertas + asserções pelas **PROPS** + isca do termo impossível |
| **187-07** | 7 | 7 | **0** | — |
| **187-08** | 11 | 11 | **0** | — |
| **187-09** | 17 | 17 | **0** | ⚠️⚠️ **a campanha foi REFEITA INTEIRA** (ver §6.1). ⚠️ A **M16** sobreviveu numa rodada e era **lacuna REAL** (ver abaixo). E **M1 contra o gate `lote-escrita` sobreviveu de propósito**: `veredicto()` é camada de TELA e a trava é `aprovarEmLote` **no servidor** — contra `lote-veredicto` ela morre. **Controle por construção, não furo** |
| **187-10** | 9 | 9 | **0** | — |
| **187-11** (este) | **9** | **9** | **0** | ver §6.2 |
| **TOTAL** | **132** | **132** | **0 ao fim** | **5 eventos de sobrevivência**, dos quais **4 eram lacuna REAL** e **1 era controle por construção** — a mesma proporção que a Fase 186 mediu (6 sobreviventes, 4 lacunas reais) |

### ⚠️ Cada sobrevivente INVESTIGADA, nunca presumida equivalente

| sobrevivente | investigação | conserto |
|---|---|---|
| **187-05 M4** — tirar o recorte de `LIVE` de dentro de `escolher()` | `escolher()` **não tinha teste** para a guarda de `LIVE`: as fixtures só traziam ofertas ao vivo, então remover a guarda não mudava nada | teste novo da guarda, com fixture que inclui oferta fora de `LIVE` |
| **187-06 M6 / M7** — popovers contados sobre o recorte, e o **M** virando o total ao vivo | ⚠️ **a causa não era a asserção, era a FIXTURE**: uma oferta ao vivo só não distingue "a conta" de "as ao vivo". E as asserções de escopo moravam no **serviço**, não nas **PROPS** — que é onde a fiação do controller aparece | fixture de 3 ofertas (1 ao vivo + 1 `preparando` + 1 `publicado` de hoje), `assertSame(1, contadorDaAba())` como **isca cega**, e asserções pelas PROPS |
| **187-06 R2-1** — estreitar `panoramaDaConta()`, **ainda sobreviveu** | passava em **todas** as asserções novas, porque as contagens de motivo vinham da oferta ao vivo e a soma continuava > 0. ⚠️ **Quem discrimina é a BUSCA**: ela é resolvida sobre o MESMO panorama, e uma `preparando` existe no recorte DA CONTA e não no das ao vivo | a `preparando` ganhou nome distintivo, e entraram duas asserções pelas PROPS (acha **1** com o panorama certo, **0** com o estreitado) + a **isca do termo impossível** |
| **187-09 M16** — aceitar id de **outra conta** no `?sel=` | ⚠️⚠️ **PROXY DE FONTE não prova comportamento.** O gate `lote-rota` exercita o **POST**, e a conferência do GET era um `assert.match` sobre o **TEXTO** do controller — e `match` casa a **primeira** ocorrência, que é a do POST. O gate ficava verde | nasceu `test_sel_da_URL_com_id_de_OUTRA_conta_e_descartado_187_09` (id `selecao-na-url`): a oferta de outra conta não entra em `selecionados`, não entra no lote e **o título dela não vaza para as props** (T-187-96); mais o lixo da querystring e a isca na outra direção. E o proxy de fonte virou **contagem exata de 2** |
| **187-09 M1 contra `lote-escrita`** | **controle por construção** — mutar a camada de tela não muda quem é aprovado, que é o desenho da Fase 186 | nenhum: a mutação morre contra o gate certo (`lote-veredicto`) |

### ⚠️ Os DOIS regimes de contagem, e qual manifesto os guarda

Oito ids de JS desta fase têm **duas** contagens (`tests=N,N-mutado`): a sonda com `skip` por `mtime`
reporta números **diferentes** com o arquivo pristino e com ele mutado. Sem os dois regimes a
campanha de mutação **abortaria na primeira execução** ou marcaria "SOBREVIVEU" por um motivo que
**não é o código**.

| manifesto | ids com dois regimes |
|---|---|
| `contagens-187-01.txt` | `tokens-da-fila` (16,9) |
| `contagens-187-03.txt` | `abas-da-pagina` (111,101) · `cabecalho-sem-subtitulo` (86,76) |
| `contagens-187-04.txt` | `cabecalho-da-fila` (99,85) · `faixa-de-andamento` (99,85) |
| `contagens-187-06.txt` | `filtros-render` (29,19) |
| `contagens-187-07.txt` | `tabela-niveis-1-e-2` (27,19) |
| `contagens-187-08.txt` | `linha-da-oferta-render` (23,14) |
| `contagens-187-09.txt` | `lote-render` (26,19) |

### ⚠️⚠️ §6.1 — ACHADO NOVO DE ORQUESTRAÇÃO: **o scratchpad é COMPARTILHADO entre subagentes paralelos**

**E o erro é do ORQUESTRADOR, não do executor.** As waves **09** e **10** foram lançadas **em
paralelo** pela mesma sessão, e **as duas recebem o MESMO diretório de scratchpad**. As primeiras
rodadas da wave 09 usaram nomes **genéricos** (`mutar.sh`, `de.txt`, `para.txt`, `out.txt`) — e o
`mutar.sh` da wave 10 **foi sobrescrito pelo da 09 no meio do trabalho**.

| quem | o que fez |
|---|---|
| **wave 10** | **detectou** por `sha256` que o próprio script havia mudado |
| **wave 09** | **refez a campanha INTEIRA** com nomes únicos (`…-187-09-waveNOVE` em todos) e rodou com a wave 10 **já fechada**, sem colisão possível |
| **as duas** | conferiram, por `sha256` contra `git show HEAD:`, que **nenhum arquivo de produção se perdeu** |

➡️ ⚠️ **A LIÇÃO EXATA, que vai para o learnings §4c:** *conclusão de mutação tirada com script
auxiliar de **nome genérico**, em execução **paralela**, **não vale** — o nome do script precisa
carregar o plano.* Este plano usa `…-187-11` em todos os nomes de script e de saída.

### §6.2 — A campanha DESTE plano

<!-- PREENCHIDA NA TAREFA 3; ver a tabela em §6.2.1 -->

---

## §7 — OS 11 MANIFESTOS E O `--autoteste`

| manifesto | ids | manifesto | ids |
|---|---|---|---|
| `contagens-187-01.txt` | 3 | `contagens-187-07.txt` | 9 |
| `contagens-187-02.txt` | 4 | `contagens-187-08.txt` | 10 |
| `contagens-187-03.txt` | 3 | `contagens-187-09.txt` | 7 |
| `contagens-187-04.txt` | 5 | `contagens-187-10.txt` | 12 |
| `contagens-187-05.txt` | 4 | **`contagens-187-11.txt`** | **5** |
| `contagens-187-06.txt` | 9 | **TOTAL** | **71** |

⚠️ **Nenhum id com `tests=0`** (gate provando, com a razão escrita: *"`tests=0` não é contagem, é
número de fora"*). ⚠️ **Nenhum id repetido dentro do mesmo manifesto** (o harness pega o **primeiro**
e o segundo nunca roda). ⚠️ **Todo método PHP deste plano tem id** — teste em arquivo sem id no
manifesto **NUNCA RODA**, e é a armadilha 1 da Fase 186.

⚠️ **Os 4 ids PHP deste plano têm NO MÁXIMO 3 métodos cada** (3 + 3 + 3 + 1 = 10). Id composto
grande soma contagens de coisas sem relação, e a correção barata passa a ser *"elevar o número"*.

⚠️⚠️ **`tests/js/publicador-vocabulario-novo.test.js` NÃO está embutido em nenhum id composto.** Ele
tem id **próprio** (`vocabulario-tamanhos`), com a forma **`>=443`**, em `contagens-187-01.txt` — e
há gate provando que ele não aparece em nenhum outro id de nenhum manifesto da fase. A razão é
medida: a contagem dele **cresce por construção** (um sub-teste por arquivo varrido nos diretórios
fechados), reprovou a wave 5 em **dois** ids de outras waves, e ia reprovar em **toda** wave de 6 a
11 — a **nona** ocorrência da família *"contagem que depende de quantos arquivos existem"*.

### O harness, re-medido nesta sessão

```
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

Os 20: 3 da forma do JS + **4 iscas da forma `>=N`** + 1 **isca G1** (reporter padrão cega o gate) +
5 da forma do PHP + 1 **isca ANSI** + 2 da resolução de manifesto (incluindo a **isca de
compatibilidade** com os 163 ids da Fase 186) + 4 de manifesto incompleto/ausente **abortando**.

### A conferência de HOMÔNIMO, por script, nas DUAS direções

⚠️ O `--filter` do PHPUnit é **regex NÃO ANCORADO**, e a Fase 186 está **em produção**. Medido nesta
sessão sobre `tests/Feature/Publicador/Aprovacao/*.php` + `tests/Unit/Publicador/Aprovacao/*.php`:

```
ARQUIVOS=26  METODOS=389  HOMONIMOS_EXATOS=0  PREFIXOS=0
```

⚠️ **A conferência de PREFIXO é a que importa**, e foi ela que a Fase 186 teve de acrescentar depois
de o caso acontecer **duas vezes**: nome que é **prefixo** de outro casa os **DOIS**, e o gate mede
duas suítes achando que mede uma. Os 10 métodos deste plano levam `_187_` no nome e **nenhum** é
prefixo de outro arquivo, nem tem outro arquivo como prefixo.

---

## §8 — AS PERGUNTAS, TODAS COM DONO

<!-- ⚠️ Forma fixa lida pelo gate `fechamento-perguntas`. "a definir" NÃO é dono. -->

PERGUNTA-P1: RESPONDIDA — dono: usuário (15/10/2026) — o "pior caso entre N variações" na célula Preço fica em BALÃO ao passar o mouse (`title`), e a frase visível vai para o painel da Fase 188; a coluna fica com UMA linha, fiel ao protótipo (92px, `:156`), e com um preço só `variante` é `null` e não há nota. Recusado: acrescentar segunda linha na coluna. APLICADA EM: 187-08 (o `title`) e Fase 188 (a frase)
PERGUNTA-P2: RESPONDIDA — dono: usuário (15/10/2026) — o custo da composição por oferta é resolvido PRÉ-AQUECENDO por tarefa em segundo plano. Recusadas explicitamente "aceitar e medir em produção" e "limitar N consultas por abertura" — a segunda porque "Incompleto" BLOQUEIA a aprovação, logo limitar protegeria o servidor travando o trabalho. APLICADA EM: 187-10 (plano novo, criado por causa desta resposta)
PERGUNTA-P3: RESPONDIDA — dono: usuário (15/10/2026) — a fila nasce vazia até a Fase 194: confirmado, é honesto e não é defeito. APLICADA EM: 187-09 (os dois vazios) e 187-10 (o relatório diz "0 trios" sem parecer erro)
PERGUNTA-P4: RESPONDIDA — dono: usuário (15/10/2026) — "Cadastro completo · categoria e ficha técnica organizadas" é medido no rascunho; não sendo verdade, a frase NÃO aparece, e sem nenhuma fonte servível o executor PARA e devolve. Não imprimir a frase do protótipo como decoração. APLICADA EM: 187-07
PERGUNTA-P5: RESPONDIDA — dono: usuário (17-18/10/2026) — o vazio honesto fica com o literal "Nenhuma oferta aguardando aprovação." mais "As ofertas entram nesta fila quando o sistema termina de preparar o anúncio. Enquanto isso, nada aqui é um erro.", SEM BOTÃO. APLICADA EM: 187-09
PERGUNTA-P6: RESPONDIDA — dono: usuário (17-18/10/2026) — o toast do lote mantém o VOCABULÁRIO TRAVADO (`TOASTS.aprovado`, "Aprovado — aguardando publicação", contagem no sub); o protótipo (`Aprovacao.dc.html:1167`, "N ofertas aprovadas") PERDE aqui, para não criar uma 7ª frase fora do vocabulário com gate. APLICADA EM: 187-09, e registrada em §5 como o segundo caso da fase em que o protótipo perde
PERGUNTA-P7: RESPONDIDA — dono: usuário (18/10/2026) — autorizou o DEPLOY da fase inteira, para que a conferência visual possa acontecer em produção. APLICADA EM: o deploy do commit `7791d7aa` (ver §0)
PERGUNTA-VISUAL: ABERTA — dono: usuário — a CONFERÊNCIA VISUAL da fila na conta 459, em produção. Roteiro de 8 itens em §11. Nada neste documento afirma que a tela foi conferida
PERGUNTA-13: ABERTA — dono: Fase 193 — `PUBLICADO => []` é TERMINAL: não há transição saindo de "Publicado". A decisão 12 cobre a janela entre aprovar e publicar (BLOQ-06); mexer na foto de um anúncio que JÁ ESTÁ NO AR é outro fluxo (editar publicado), e quem decide é a Fase 193. Não bloqueia nada na 187
PERGUNTA-17: ABERTA — dono: Fase 194 — BUG LATENTE: o recorte da fila usa as duas âncoras com `AND` (`where company_id = ? and mlb_empresa_id = ? and andamento in (...)`) e `mlb_empresa_id` é ANULÁVEL, então oferta com nulo numa conta que TEM `MlbEmpresa` fica INVISÍVEL e a pílula diria `0` com trabalho esperando. Hoje não é bug (nada cria oferta). Duas saídas, e a 194 escolhe UMA: (a) preencher sempre as duas colunas ao criar a oferta — mais simples e não toca código em produção; ou (b) o recorte da Fase 186 vira tolerante a nulo. ⚠️ O executor do 187-02 NÃO corrigiu, e estava certo: a semântica mora no `AndamentoDaOfertaService`, somente leitura, e reimplementar o recorte criaria a segunda verdade. É a RESSALVA do CRIT-02
PERGUNTA-D1: ABERTA — dono: outro dev (bloqueia a Fase 192) — os chips de problema estruturados viram texto na nossa borda, ou o problema é guardado estruturado do lado do Creative Engine, servindo ao `CreativeJuiz`? (IA-03)
PERGUNTA-D2: ABERTA — dono: outro dev (bloqueia a Fase 192) — ele pretende mexer em `validacao_status`, no `PublicadorCriativoAprovacaoService` ou nos endpoints `criativo*` nas próximas semanas? Se sim, combinar a ORDEM antes
PERGUNTA-D4: ABERTA — dono: outro dev (bloqueia a Fase 194) — o que falta do lado do Creative Engine para ligar `publicador.criativos_auto.ativo`? (AUTO-07)

⚠️ **7 RESPONDIDAS e 6 ABERTAS.** Nenhuma das 6 abertas bloqueia a Fase 187 — e **nenhuma delas é
desta fase para resolver**.

---

## §9 — OS DOIS GATES **ALHEIOS** QUE ESTA FASE TOCOU, DECLARADOS

### ⚠️⚠️ §9.1 — EXPANSÃO DE TERRITÓRIO DECLARADA: a wave 08 mexeu num gate da **Fase 186**, que está EM PRODUÇÃO

**O plano 187-08 não previa tocar gate da 186.** Tocou, e o registro é este — porque expansão de
território **não declarada** é o que faz a próxima fase descobrir por acidente.

| o quê | medida |
|---|---|
| arquivo | `tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php` |
| commit | **`37c3ebaf`** |
| o defeito | a **camada B** daquele gate acusava **EXPOR** o andamento como se fosse **ESCREVER** — e a linha da fila legitimamente **expõe** |
| o conserto | lista de **EXPOSITORES** com a exceção **PROVADA, não afirmada**: o expositor **tem** de casar a sonda larga (senão a exceção é linha morta) **e** não pode ter nenhuma forma de escrita |
| manifesto remedido | `contagens-186-09.txt`: `andamento-um-lugar-so` **13 → 19** asserções · `transversais` **251 → 257** asserções |

⚠️ **RECONFERIDO POR MIM NESTA SESSÃO**, os dois ids, rodando o harness:

```
gate 'andamento-um-lugar-so' (php): esperado 1 passed / 19 assertions
  Tests:    1 passed (19 assertions)          PASSOU

gate 'transversais' (php): esperado 12 passed / 257 assertions
  Tests:    12 passed (257 assertions)         PASSOU
```

⚠️ **E a razão de o conserto ser legítimo e não "fazer passar":** a exceção não é uma **lista de
nomes** — é uma lista **cujos membros são conferidos por COMPORTAMENTO** (casar a sonda larga **e**
não escrever). É exatamente a correção que o learnings §4c prescreve para *"o que impede alguém de
entrar na lista de exceções?"*.

⚠️ **E eu conferi o efeito colateral antes de criar o meu arquivo:** o
`FechamentoDaFase186Test::test_os_nove_manifestos_nao_tem_homonimo_que_some_dois_arquivos` faz
`glob()` em `tests/Feature/Publicador/Aprovacao/*.php` — **o diretório onde este plano cria
`FechamentoDaFase187Test.php`**. Medido: as asserções daquele método são **fora dos laços**, então a
contagem **não muda**; o que mudaria é o veredicto, se eu introduzisse homônimo ou prefixo. **Medi
antes: 0 e 0.**

### ⚠️ §9.2 — A wave 10 consertou, DO PRÓPRIO LADO, um gate da wave 02 que reprovou

| o quê | medida |
|---|---|
| o gate | `contador-e-territorio` (plano 187-02) |
| por que ficou vermelho | a wave 10 acrescentou o **despacho do job de aquecimento** em `meus()`, e o gate daquela wave contava o diff de `MlbAnuncioController` |
| ⚠️ **o risco REAL, e é de produção** | na conexão **`sync`**, `dispatch()` roda o job **DENTRO do request** — e a tela de Publicações, **que está no ar**, pagaria as chamadas HTTP ao Mercado Livre **sincronamente** |
| a guarda | **`Queue::connection() instanceof SyncQueue`** |
| ⚠️ **o que NÃO serve como guarda** | `config('queue.default')` — ele é `'sync'` **também sob `Queue::fake()`**, então a guarda desligaria o aquecimento nos próprios testes que o provam; e `runningUnitTests()`, que confunde ambiente com conexão |
| conserto | feito **do lado da wave 10**, sem tocar o arquivo de gate da wave 02 |

⚠️ **RECONFERIDO POR MIM NESTA SESSÃO** (gate alheio, rodado inteiro): `contador-e-territorio` →
**8 testes / 102 asserções, PASSOU**.

➡️ **A decisão registrada:** *conexão de fila que executa EM LINHA não recebe job de aquecimento* —
**melhor não aquecer do que aquecer dentro do request**. É desvio Rule 2 (funcionalidade crítica
ausente), e a razão é o incidente de **30/07/2026**, quando requisições lentas ocuparam os workers
do php-fpm e **até o login parou**.

### ⚠️ §9.3 — Um gate da **Fase 186** reprovou no commit de partida desta fase, e NÃO foi consertado

`VinculoDuravelDaOriginalTest::terr_11` (Fase 186) é um gate de território **por RANGE**, com
`PARTIDA = '1c16d9e9'` cravada. A **sessão paralela** commitou `feat(196)` **dentro** daquele range e
tocou `PublicadorCriativoReferenciaService.php`.

⚠️ **NÃO corrigido, por três razões:** o arquivo acusado está **proibido** a esta fase (Creative
Engine / retenção de 48 h); o teste é prova de uma fase **em produção**; e reapontar um gate da 186
para "fazer passar" é a definição de **teste que para de provar algo**.

➡️ ⚠️ **É o terceiro caso desta fase da mesma família:** *"gate de território por RANGE acusa o
ORQUESTRADOR e as SESSÕES PARALELAS"* (os outros dois: §3.1 e o próprio Teste 2 do plano 187-11). O
recorte certo é **por ESCOPO DO ASSUNTO**, nunca por range puro — e nunca pelo **autor**, que aqui
não discrimina.

---

## §10 — AS DÍVIDAS DESTA FASE

Registradas com dono em
`.planning/phases/187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela/deferred-items.md`:

| dívida | dono |
|---|---|
| o **risco de custo** da composição por linha (pergunta P2) — mitigado pelo pré-aquecimento, **não eliminado** | Fase 188 / operação |
| a **`CascaDeModal.jsx`** nasceu aqui e a **Fase 188 REUSA** — se ela recriar, haverá **duas cascas divergindo** | Fase 188 |
| a **legenda do rodapé** não lista o anel de **falha** nem o quadrado de **regenerando**, embora os dois existam na tabela | Fase futura (melhoria) |
| a divergência **D7** (o `title` do quadrado em `regenerating` diz "a conferir") | protótipo — reproduzida de propósito |
| a divergência **D9** (o checkbox do cabeçalho marca linhas em `preparando`) | protótipo — reproduzida de propósito |
| a dívida **DIV-01 da Fase 186** (3 das 4 estruturas declaram a fase dona **só fora do docblock**) **segue aberta** e **não é desta fase** | orquestrador ou Fase 191 |
| ancorar o relógio em `CockpitDaContaTest.php:966` e `:1318` (as duas falhas de fronteira de dia) | quem mantiver a Fase 179 |

### ⚠️ A legenda do rodapé — item para fase futura, **não lacuna**

A legenda **não lista** o anel de *falha* nem o quadrado de *regenerando*, e isso é **FIEL ao
protótipo** (`Aprovacao.dc.html:193`-`:198`). A wave 09 **reproduziu e PERGUNTOU**; a decisão foi
**MANTER**, porque acrescentar seria **adaptar o layout** — e o protótipo ganha nos rótulos e na
ordem. ➡️ **Registrado como item para fase futura, não como lacuna desta.**

---

## §11 — O CHECKPOINT HUMANO: A CONFERÊNCIA VISUAL É DO USUÁRIO

⚠️⚠️ **Antes do roteiro, a frase que o precede:** **nenhum executor desta fase viu tela
renderizada** (§1). ⚠️ **A conferência é EM PRODUÇÃO, na conta 459** — e o deploy que a torna
possível **já aconteceu**, em `7791d7aa`, **com autorização explícita do usuário em 18/10/2026**
(§0). ⚠️ **Este plano NÃO deployou, NÃO deu push e NÃO tocou no VPS.**

**Roteiro, 8 itens:**

1. Abrir `/mlb/anuncios/meus/459` e conferir que **o subtítulo saiu** e entraram **duas abas**
   alinhadas à base, com a linha de 1px embaixo do cabeçalho.
2. Conferir que **"Publicadas no Mercado Livre"** está **ativa** (amarelo embaixo) com o contador
   simples, e que **"Aguardando aprovação"** tem a **pílula amarela** com um número.
3. Conferir que **busca, filtros, agrupamento, painel, lote e atalhos da lista continuam
   funcionando igual** — e que uma URL antiga com `?status=em_revisao` **ainda funciona**.
4. Clicar em **"Aguardando aprovação"** e conferir que a fila abre: a linha
   "ⓘ Margem mínima: N% · Toda oferta precisa de aprovação humana antes de publicar", a faixa de 9
   cards, os filtros e a tabela.
5. ⚠️ **Se a fila estiver VAZIA, isso é o esperado hoje:** nada nesta fase cria oferta. O texto tem
   de ser o do vazio **honesto** — *"Nenhuma oferta aguardando aprovação."* mais *"As ofertas entram
   nesta fila quando o sistema termina de preparar o anúncio. Enquanto isso, nada aqui é um erro."*
   —, **sem** o botão "Limpar filtros". Ver uma oferta de verdade depende da **Fase 194**.
6. Clicar num card da faixa e conferir que (a) a lista filtra, (b) **as contagens dos outros cards
   NÃO mudam** e (c) a URL carrega o filtro; recarregar devolve o mesmo recorte.
7. Abrir um popover e conferir que ele fica **abaixo** do header global e **acima** da tabela.
8. Marcar duas linhas, conferir a barra flutuante no rodapé, abrir o modal e conferir que ele lista
   as duas com ✓/× **e o motivo**. ⚠️⚠️ **Só aprovar se quiser aprovar de verdade: não há
   desfazer.**

➡️ **Divergência que o usuário relatar vira item com `arquivo:linha`** — nesta SUMMARY ou numa quick
task, nunca "ajuste" silencioso de critério.

---

## §12 — O QUE O ORQUESTRADOR TEM DE MARCAR À MÃO, E POR QUÊ

⚠️ **Nenhum dos arquivos abaixo pertence a este plano** (GATE-07): quem edita é o **orquestrador**.
E os três têm de ser editados **à mão**, em **bloco ADITIVO**, com `git diff --numstat` provando
**0 remoções** — pelas razões **medidas neste projeto**:

| ferramenta | o que ela faz de verdade |
|---|---|
| `roadmap update-plan-progress` | **reporta sucesso e NÃO escreve nada** |
| `requirements.mark-complete` | devolve **`not_found`** para IDs que só existem em `REQUIREMENTS-vNN.md` (o `REQUIREMENTS.md` da raiz parou na v17.0) |
| `gsd-sdk query state.advance-plan` | **corrompeu o `STATE.md` deste projeto SEIS vezes** — ⚠️ **não rodar** |

1. **`.planning/ROADMAP.md`** — o **Coverage Map** da Fase 187 (famílias `ABA-01/02/05/06`,
   `FILA-01..16`, `MOD-11`, `MOD-12`) e a linha `**Plans**:` da fase. ⚠️ A forma é
   **"em produção (18/10/2026, commit `7791d7aa`)"** — **NÃO** a da Fase 186 (*"Entregue no CÓDIGO —
   NÃO DEPLOYADO"*), que era verdade em 15/10 e deixou de ser.
2. **`.planning/REQUIREMENTS-v27.md`** — marcar os checkboxes **à mão**.
3. **`.planning/STATE.md`** — **bloco aditivo**, sem `state.advance-plan`.

---

## §13 — AVISOS ÀS FASES SEGUINTES

### ⚠️⚠️ Para a **Fase 188** (Revisão simples — o painel de 1140px)

1. ⚠️⚠️ **`resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx` É DESTA FASE. IMPORTE-A,
   NUNCA A RECRIE.** O Coverage Map atribui a "casca comum" à 188 (MOD-01..05), mas o **modal de
   lote** é da 187 — então ela **nasceu aqui**. **Duas cascas divergiriam.** Está declarado dentro do
   arquivo e **tem gate** (`fechamento-arquivos-e-manifestos`).
2. ⚠️ **`MlbPublicadorAprovacaoController` tem `$mlbEmpresaId` DUAS vezes** — em **`:195`** e em
   **`:559`** —, resultado das edições paralelas das waves 09 e 10. ➡️ **Quem editar ali NÃO deve
   usar âncora por número de linha**: use âncora semântica (o nome do método, a linha inteira da
   atribuição com o contexto).
3. A frase *"pior caso entre N variações"* **visível** é da 188 (decisão P1); na fila ela é só
   `title`.
4. O **FOCO** da oferta (`?oferta=`) é o que a 188 substitui pelo `openPanel`. A **miniatura** da
   foto também é da 188 — o acervo é território proibido nesta fase.

### Para a **Fase 193** (do aprovado ao ar)

⚠️⚠️ **A FRONTEIRA DESTA FASE: a fila APROVA — e aprovar ENFILEIRA. A fila NÃO PUBLICA.** A escrita
no Mercado Livre é da **Fase 193**, e **nada nesta fase escreve no ML**
(`Http::fake` + `preventStrayRequests()` em toda a suíte; `MlPublicacaoService` com `numstat` ZERO).
⚠️ E a tensão declarada no `ROADMAP.md` continua declarada: o **D-11** prova que a **LISTA** não
escreve; a **FILA** escreve, com gate de escrita próprio. Quem ler só um dos dois lê contradição.
⚠️ A **PERGUNTA-13** (editar foto de anúncio no ar) é sua.

### Para a **Fase 194** (ligar `publicador.criativos_auto.ativo`)

⚠️⚠️ **A PERGUNTA-17 é sua, e é BUG LATENTE que tem de ser resolvido ANTES de criar a primeira
oferta.** Ver §8. **Duas saídas, escolha UMA**; a (a) é mais simples e **não toca código em
produção**.

### Para a **Fase 192**

As **PERGUNTA-D1** e **PERGUNTA-D2** são do **outro dev**. ⚠️ Antes de mexer em
`app/Services/Creative/`, nos endpoints `criativo*`, em `AnunciarML.jsx`, `PainelCriativosIa.jsx` ou
`KitCriativosGrade.jsx` — **mostre o aviso de coordenação ao usuário e pergunte se ele já combinou
com o outro dev. Não decida por ele.**
