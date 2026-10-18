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

Arquivo: **`tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php`** — **Fase 179, EM
PRODUÇÃO**.

| linha | o que espera | o que obtém |
|---|---|---|
| `:966` | `serie[29] == 12` | `0` |
| `:1318` | `'hoje'` | `'ontem'` |

**Diagnóstico medido, não presumido:** é **fragilidade de FRONTEIRA DE DIA**. O arquivo usa o relógio
**54 vezes**, e o último commit dele é literalmente *"ancora o relogio no caso de agrupamento da
Atividade"* — alguém já consertou **um** caso desta família e deixou **estes dois**. Histórico desta
fase: **VERMELHAS** na wave 08, **VERDES** nas waves 09 e 10, e **vermelhas outra vez hoje**, porque
o dia virou.

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
CRIT-15: OK — a tabela FID-01 da fila foi medida contra `design_handoff_publicador/Aprovacao.dc.html` (sha256 `68624883ce35547e`, não se moveu) e **declara como mediu**, plano por plano, em §5 — **10 de 10 por SCRIPT**, com `cursor` em coluna própria e as referências RE-MEDIDAS por `grep -n` (os planos erraram 9/16, 7/12, 8/9, 13/20, 11/17 e 19/25 ponteiros) — gate vocabulario-tamanhos, 443 testes + gate territorio-e-fid, 3/41

### Placar

| veredicto | quantos | quais |
|---|---|---|
| **OK** | **12** | 01, 04, 05, 06, 07, 08, 09, 10, 11, 13, 14, 15 |
| **RESSALVA** | **3** | **02** (decisão 17, dono Fase 194) · **03** (fila nasce vazia, dono do dado Fase 194) · **12** (runtime do timer não provado, sem navegador) |
| **REPROVA** | **0** | — |

⚠️ **Nenhum `REPROVA`.** As três ressalvas são **limites declarados do ambiente e do escopo**, com
dono nomeado — nunca trabalho faltando dentro desta fase.
