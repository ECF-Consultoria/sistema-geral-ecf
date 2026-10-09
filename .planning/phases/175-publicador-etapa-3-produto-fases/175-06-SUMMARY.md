---
phase: 175-publicador-etapa-3-produto-fases
plan: 06
subsystem: api
tags: [publicador, kit, creative-engine, ia, gemini, nvidia, cache, queue, laravel, inertia]

# Dependency graph
requires:
  - phase: 175-05
    provides: "PreviaDaFaseService (título/descrição/estoque sugeridos), MlbPublicadorFaseController com baseDaConta()/recusa()/ator(), rotas publicador.fases.*"
  - phase: 175-02
    provides: "CriarFaseService::criar() e o clone que copia a foto 1 do base para o rascunho do kit com BYTES PRÓPRIOS — é dessa cópia que a capa parte"
  - phase: 165
    provides: "Creative Engine no Publicador: pub_rascunho_id, PublicadorCriativoReferenciaService, MlAnuncioCriativoKit::retomavelDoPublicador, CreativeContextBuilder::paraPublicador"
  - phase: 161
    provides: "PlanejarKitCriativosJob, CreativePlanner, CreativeSlotCatalog, ProductTruth/contagens"
provides:
  - "SugestaoKitIaService: título e descrição do kit por IA, serviço IRMÃO do PalavrasChaveService, com ALVOS, prompt e chave de cache próprios"
  - "GerarSugestaoKitIaJob na fila `high` (tries=1, timeout=300, prazo 240s)"
  - "AnaliseAnuncioService::textoDeKit() — método novo, nenhum existente alterado"
  - "Rotas publicador.fases.ia (POST, throttle 20/min) e publicador.fases.ia.status (GET, throttle 240/min)"
  - "CapaDoKitService: a capa do kit no Creative Engine com DOIS slots fixos (lifestyle + hero)"
  - "unidadesDoKit atravessando CreativeContext -> ProductTruth::contagens como FATO de cadastro"
  - "Terceiro parâmetro do PlanejarKitCriativosJob (tiposFixos) e quinto do CreativePlanner::planejar"
affects: [175-07, 175-08, 175-10, creative-engine, publicador]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Serviço irmão em vez de alvo novo num serviço em produção (chave de cache própria, mesma FORMA do contrato)"
    - "Número de cadastro vira FATO no prompt via ProductTruth::contagens, nunca frase solta"
    - "Tipos de slot FIXADOS pelo chamador (parâmetro opcional, default = comportamento de sempre)"
    - "Efeito pós-transação que não pode desfazer a transação: devolve ['ok','motivo'] e nunca lança"

key-files:
  created:
    - app/Services/Publicador/SugestaoKitIaService.php
    - app/Jobs/Publicador/GerarSugestaoKitIaJob.php
    - app/Services/Publicador/CapaDoKitService.php
    - tests/Unit/Publicador/SugestaoKitIaTest.php
    - tests/Feature/Publicador/CapaDoKitTest.php
  modified:
    - app/Services/Ia/AnaliseAnuncioService.php
    - app/Services/Creative/Dto/CreativeContext.php
    - app/Services/Creative/CreativeContextBuilder.php
    - app/Services/Creative/ProductTruthBuilder.php
    - app/Services/Creative/CreativePlanner.php
    - app/Jobs/PlanejarKitCriativosJob.php
    - app/Services/Publicador/CriarFaseService.php
    - app/Http/Controllers/MlbPublicadorFaseController.php
    - routes/mlb_anuncios.php

key-decisions:
  - "A capa do kit tem DOIS slots, `lifestyle` e `hero` — decisão do usuário em 2026-10-08 ('gerar as duas e você escolhe'), ~R$ 1,10 por capa; o plano, escrito para 1 slot, está superado neste ponto"
  - "Os dois slots valem em TODA categoria, não só em móveis (premissa registrada no 175-DECISOES.md, não decidida explicitamente pelo usuário)"
  - "O terceiro parâmetro do PlanejarKitCriativosJob NÃO é uma quantidade: é a lista de TIPOS fixos, e a quantidade sai do tamanho dela — quantidade sozinha não garantiria lifestyle+hero"
  - "CreativePlanner::planejar ganhou um 5º parâmetro opcional (tiposFixos): era a única forma de fixar os tipos sem duplicar a reconciliação do planner"
  - "CapaDoKitService usa CreativePermissao::podePlanejar() em vez de exigir(): mesma regra (OPS-04), sem o abort(403) que transformaria uma fase criada em erro"
  - "SugestaoKitIaService é serviço irmão com chave `publicador:kit-ia:{rascunho do BASE}:{alvo}:{N}` — a quantidade entra na chave porque trocar o N muda o resultado esperado"
  - "AnaliseAnuncioService ganhou textoDeKit(): tituloPorTermos() seria o mais perto, mas é SEO por termos mais buscados do ML, prompt errado para kit"

patterns-established:
  - "Extensão de DTO readonly do Creative Engine: parâmetro novo no FIM com default, e a chave em paraAuditoria() só quando preenchida"
  - "Parâmetro opcional que fixa comportamento (tiposFixos): vazio/null = o default de todo mundo, intocado"
  - "Pós-processamento de texto de IA no servidor: o prefixo e a frase obrigatória são GARANTIDOS, nunca confiados ao modelo"

requirements-completed: [FASE-07, FASE-08]

# Metrics
duration: 48min
completed: 2026-10-09
---

# Fase 175 Plano 06: "Sugerir com IA" do kit e a capa no Creative Engine — Summary

**Os dois disparos do painel "Criar Fase N": título/descrição do kit por IA num serviço irmão com cache próprio, e a capa do kit planejada com DOIS slots fixos (`lifestyle` + `hero`) a partir da cópia da foto 1 do próprio kit, com as N unidades entrando no prompt como FATO de cadastro.**

## Performance

- **Duration:** ~48 min (08:19 → 09:07 BRT)
- **Started:** 2026-10-09T11:19:00Z
- **Completed:** 2026-10-09T12:07:00Z
- **Tasks:** 2 de código + 1 checkpoint (já respondido pelo `175-DECISOES.md`)
- **Files modified:** 19 (5 criados, 9 de produção alterados, 5 de teste)

## Accomplishments

- **"Sugerir com IA" existe e mexe SÓ em título e descrição.** `SugestaoKitIaService` copia a FORMA do `PalavrasChaveService` (pedir → Job → cache → `estado`) e **nada mais**: alvos (`titulo`/`descricao`), prompt e chave de cache são próprios. `git diff --stat app/Services/Publicador/PalavrasChaveService.php` sai **vazio**.
- **O N das unidades chega ao prompt como FATO.** `pub_produtos.quantidade_kit` → `CreativeContext::unidadesDoKit` → `ProductTruth::contagens` com `origem: 'cadastro'` → "CONTAGENS CONFIRMADAS NO CADASTRO (respeite exatamente)". O `CreativePromptBuilder` **não foi tocado** — ele já renderizava isso.
- **A capa sai com os dois slots exatos.** `CapaDoKitService::SLOTS_DA_CAPA = ['lifestyle', 'hero']`, fixados no plano e provados por teste contra um modelo que propõe outra coisa.
- **Nenhum kit existente mudou de comportamento.** Suítes do Creative Engine (Fases 16x): **379 verdes, 1 incompleto pré-existente** — o mesmo resultado de antes.
- **Nenhum teste chama API real.** `AnaliseAnuncioService` dublê, `ImageGenerationProvider` dublê, `Queue::fake()` e `Http::fake()`.

## Task Commits

1. **Task 1 (RED): prova do `SugestaoKitIaService`** — `d1363bc1` (test)
2. **Task 1 (GREEN): serviço + Job + `textoDeKit()`** — `68b7ca19` (feat)
3. **Task 1: rotas e endpoints `fases.ia*`** — `c01ba5e8` (feat)
4. **Task 2 (RED): prova das quatro costuras** — `f149ce2d` (test)
5. **Task 2 (GREEN): as N unidades como FATO + tipos fixos** — `5fb01d79` (feat)
6. **Task 2: `CapaDoKitService` + gancho no Confirmar** — `723dfce5` (feat)

**Plan metadata:** ver o último commit `docs(175-06)`.

## Files Created/Modified

### Criados

- `app/Services/Publicador/SugestaoKitIaService.php` — pedir/estado/executar/falhou + pós-processamento puro (`ajustarTituloDoKit`, `ajustarDescricaoDoKit`, `fraseDoKit`). 302 linhas.
- `app/Jobs/Publicador/GerarSugestaoKitIaJob.php` — fila `high` no construtor, `tries=1`, `timeout=300`, `PRAZO_S=240`, `failed()` com mensagem para a tela.
- `app/Services/Publicador/CapaDoKitService.php` — gates, lock, portador, kit do Creative Engine e o despacho com os dois slots. 227 linhas.
- `tests/Unit/Publicador/SugestaoKitIaTest.php` — 18 casos.
- `tests/Feature/Publicador/CapaDoKitTest.php` — 22 casos (11 das costuras + 11 do serviço/gancho/endpoint).

### Modificados (produção)

- `app/Services/Ia/AnaliseAnuncioService.php` — `textoDeKit()` + `promptTextoDeKit()`. Nenhum método existente alterado.
- `app/Services/Creative/Dto/CreativeContext.php` — `?int $unidadesDoKit = null` no FIM; chave em `paraAuditoria()` só quando preenchida.
- `app/Services/Creative/CreativeContextBuilder.php` — `paraPublicador()` lê `quantidade_kit >= 2`. Sem query nova.
- `app/Services/Creative/ProductTruthBuilder.php` — a entrada `unidades idênticas do mesmo produto` em `contagens()`, com o comentário do porquê ser TRUTH-02-compatível.
- `app/Services/Creative/CreativePlanner.php` — 5º parâmetro `array $tiposFixos = []`.
- `app/Jobs/PlanejarKitCriativosJob.php` — 3º parâmetro `?array $tiposFixos = null`; `uniqueId()`, `uniqueFor()`, fila, tries e timeout intocados.
- `app/Services/Publicador/CriarFaseService.php` — `capa`/`user` em `$dados`, chamada FORA da transação, resultado em `$resultadoDaCapa`.
- `app/Http/Controllers/MlbPublicadorFaseController.php` — `pedirIa()`, `iaStatus()`, e `capa` na resposta do `criar()`.
- `routes/mlb_anuncios.php` — as duas rotas de IA no grupo `role:admin`, com throttle nomeado.

### Modificados (teste, por efeito do construtor novo)

- `tests/Unit/Publicador/CriarFaseServiceTest.php`, `tests/Feature/Publicador/CriarFaseCloneTest.php` — `new CriarFaseService(repo, capa)`.
- `tests/Feature/Publicador/CriarFaseEndpointTest.php` — 10 casos novos (8 de IA + 2 de capa) e o caso antigo da flag `capa` reescrito.

## Decisões Made

### Os dois gates da Task 3 vieram do `175-DECISOES.md` — não houve nova pergunta

A Task 3 era `checkpoint:decision gate="blocking"` com duas coisas dentro. As duas estavam
**respondidas antes desta wave**, em `.planning/phases/175-.../175-DECISOES.md` (registrado em
2026-10-08), e a autorização para prosseguir sem parar veio no briefing desta execução:

1. **Coordenação do Creative Engine — LIBERADA.** O aviso do `CLAUDE.md` (v24.0, Fases 160–163)
   sobre mexer em `app/Services/Creative/` foi mostrado ao usuário pela sessão que escreveu o
   `175-DECISOES.md`; resposta dele: **"Já combinei, pode seguir"**. As edições em
   `CreativeContext`, `CreativeContextBuilder`, `ProductTruthBuilder`, `PlanejarKitCriativosJob`
   (e, ver desvio 1, `CreativePlanner`) estão autorizadas nessa base.
2. **A capa tem DOIS slots** (`lifestyle` + `hero`), não um: *"gerar as duas e você escolhe"*,
   2026-10-08. Custo aceito: ~R$ 1,10 por capa. Equivale à opção `c-dois-slots` do checkpoint,
   generalizada para toda categoria (premissa registrada no `175-DECISOES.md` como da sessão de
   planejamento, não do usuário).

**D5 continua valendo e não foi tocado:** o bloco AMBIENTE (ambiente brasileiro subentendido)
é emitido pelo `CreativePromptBuilder` só para `lifestyle`/`lifestyle_uso`/`composicao` —
nunca em `hero` nem em `white_background`, que são regidos pela moderação de capa do ML.

### O terceiro parâmetro do Job: necessário, mas NÃO como quantidade

O `175-DECISOES.md` pedia para conferir se o parâmetro era necessário, já que `SLOTS_PADRAO`
já é 2. **Conferido no código, e a resposta é: a quantidade sozinha não serve.**

Medido em `CreativePlanner::planejar()`: com `quantidade = 2`, o slot 1 é forçado
(`garantirPrimeiroSlot`) para `hero` — ou `lifestyle`, em categoria de móvel — e o **slot 2 é
o que o LLM propôs ou, no fallback, o primeiro `COM_FATO` elegível**. Com medida do produto no
cadastro (o caso comum em móveis), esse segundo slot é `dimensions`: imagem de medidas, não
capa de kit. Confiar na config daria "hero + medidas" fora de móveis e "ambientada + medidas"
em móveis — nunca os dois pedidos.

Então o parâmetro novo carrega **os TIPOS**, não um número:
`PlanejarKitCriativosJob::dispatch($portador, $kit, ['lifestyle', 'hero'])`. A quantidade sai
de `count($tiposFixos)`, o que é melhor que um número à parte: os dois nunca podem divergir.

**Como os dois slots são garantidos (três camadas, nenhuma confiando no modelo):**

1. `$elegiveis = $tiposFixos` — a lista FECHADA passa a ser só os dois, então a reconciliação
   descarta qualquer tipo proposto fora dela (o `in_array($tipo, $elegiveis)` que já existia).
2. `$primeiro = $tiposFixos[0]` = `lifestyle` — `garantirPrimeiroSlot()` força a posição 1.
3. `$quantidade = count($tiposFixos)` = 2 — o preenchimento do catálogo para no segundo, e só
   `hero` sobrou na lista elegível.

Provado por dois testes: um com o provedor fora do ar (plano determinístico) em categoria de
móvel e em categoria comum, outro com o modelo propondo `white_background` + `angles` + `hero`.
Nos três casos a saída é exatamente `['lifestyle', 'hero']`, com índices 1 e 2.

**`uniqueId()` reconferido no código:** é `'kit-plano:'.$this->kitId` — só o kit. O parâmetro
novo não muda a chave de unicidade, e o teste
`test_o_terceiro_parametro_nao_muda_a_chave_de_unicidade` grava isso.

### Como se provou que os kits antigos de 7 slots continuam lendo

Quatro provas, nesta ordem de força:

1. **`test_sem_tipos_fixos_o_plano_antigo_de_7_slots_continua_igual`** — `planejar(ctx, truth, 7,
   false)` sem o parâmetro novo devolve **7 slots** com `hero` na posição 1. É o caminho literal
   dos kits gravados em produção.
2. **`test_o_job_sem_o_terceiro_parametro_continua_lendo_a_config`** — `new PlanejarKitCriativosJob(11, 22)`
   tem `tiposFixos === null`, fila `creative` e `uniqueId()` `kit-plano:22`.
3. **`test_unidades_nulas_nao_mudam_a_forma_serializada_do_ramo_antigo`** — `paraAuditoria()` de
   um contexto sem kit tem **exatamente** as mesmas chaves, na mesma ordem, e `contagens` vazio.
   É a prova de que nenhum `ml_anuncio_criativos.contexto` já gravado muda de forma.
4. **As suítes das Fases 160–169 rodadas inteiras**: `379 passed, 1 incomplete`. O incompleto
   (`Phase165\RotasAntigasComKitDoPublicadorTest::guarda das rotas antigas se liga sozinha
   quando chegar`) é pré-existente e se declara incompleto de propósito.

### `podePlanejar()` em vez de `exigir()`

O plano dizia "`CreativePermissao::exigir($user,'planejar')`" e, na frase seguinte, "sem
permissão, devolve `ok=false` com motivo (nunca lança 500 no meio da criação da fase)". As duas
coisas são incompatíveis: `exigir()` faz `abort_unless(..., 403)`.

Como a capa roda **depois** da transação que já criou a fase, um `abort(403)` ali transformaria
uma fase criada com sucesso numa resposta 403 — e a fase não volta atrás. Então o serviço chama
`podePlanejar()`, que é **o mesmo método** que o `exigir()` chama por dentro (`pode`.ucfirst) e
a mesma regra de duas camadas do OPS-04. `CreativePermissao` continua **usado, não editado**.
Provado por `test_sem_permissao_de_gastar_cota_a_capa_recusa_sem_derrubar_nada`, com a lista
`creative_engine_usuarios` preenchida sem o id do admin.

### O motivo da capa chega ao endpoint por propriedade, não pelo retorno

`CriarFaseService::criar()` devolve o `PubProduto` do kit, e esse contrato é lido por quem já
chama. A capa é um efeito de **fora** da transação que não pode mudar o retorno nem desfazer a
fase. Então ela grava em `public ?array $resultadoDaCapa`, zerada no começo de cada `criar()` e
no `catch`, e o controller a devolve em `capa` no JSON — com **201**, porque recusa da capa
nunca é recusa da fase.

## Deviations from Plan

### 1. [Rule 3 - Blocking] `CreativePlanner` entrou nos arquivos tocados

- **Found during:** Task 2
- **Issue:** O plano lista 4 costuras no Creative Engine e não inclui `CreativePlanner.php`. Mas
  a decisão do usuário (dois slots **exatamente** `lifestyle` e `hero`) é impossível de cumprir
  sem fixar os tipos, e quem escolhe os tipos é o planner: `elegiveis()` e
  `garantirPrimeiroSlot()` decidem lá dentro. Nem o Job nem o `CapaDoKitService` têm como
  influenciar o slot 2.
- **Fix:** 5º parâmetro `array $tiposFixos = []` em `planejar()` — três linhas, com default
  vazio = comportamento idêntico ao de hoje para **todo** chamador existente. A alternativa era
  duplicar a reconciliação do planner dentro do `CapaDoKitService` (≈80 linhas de lógica
  espelhada, que sairia de sincronia na primeira mudança) ou gravar o plano à mão sem passar
  pelo planner (perdendo estratégia, `podeTerTexto` e `faltam`).
- **Files modified:** `app/Services/Creative/CreativePlanner.php`
- **Verification:** `test_sem_tipos_fixos_o_plano_antigo_de_7_slots_continua_igual` +
  `CreativePlannerTest` da Fase 161 inteiro verde + as 379 das suítes 16x.
- **Committed in:** `5fb01d79`
- ⚠️ **Esta é uma edição a mais em `app/Services/Creative/` do que o plano previu.** A
  autorização de coordenação do `175-DECISOES.md` cobre a pasta, mas o arquivo específico não
  estava na lista mostrada ao usuário — vale o ECF Dev saber.

### 2. [Rule 3 - Blocking] Dois testes antigos instanciavam `CriarFaseService` com 1 argumento

- **Found during:** Task 2
- **Issue:** `new CriarFaseService(new RascunhoRepository())` em
  `tests/Unit/Publicador/CriarFaseServiceTest.php` (2 lugares, um deles classe anônima) e
  `tests/Feature/Publicador/CriarFaseCloneTest.php` (2 lugares) — 18 falhas de "Too few
  arguments" depois do construtor ganhar `CapaDoKitService`.
- **Fix:** `app(CapaDoKitService::class)` como 2º argumento nos quatro lugares, com comentário
  de que nenhum teste daqueles arquivos pede capa (e, sem `capa` em `$dados`, ela nem é
  consultada).
- **Files modified:** os dois arquivos de teste
- **Verification:** os dois arquivos verdes (50 casos), suíte Publicador inteira verde.
- **Committed in:** `723dfce5`

### 3. [Rule 2 - Missing] Os endpoints de IA ganharam teste de rota/escopo que o plano não listava

- **Found during:** Task 1
- **Issue:** O plano lista só `tests/Unit/Publicador/SugestaoKitIaTest.php` para a Task 1, mas
  as rotas novas precisam da mesma prova de escopo que as do 175-05: grupo `role:admin`,
  throttle nomeado, `{conta}` morrendo na rota e **produto de outra conta = 404, nunca 403**
  (T-175-25). Sem isso, o T-175-25 do próprio `threat_model` deste plano ficava sem prova.
- **Fix:** 8 casos novos em `tests/Feature/Publicador/CriarFaseEndpointTest.php`, que é a casa
  dos endpoints de fase (do 175-05) — em vez de um arquivo novo que duplicaria as fixtures.
- **Files modified:** `tests/Feature/Publicador/CriarFaseEndpointTest.php`
- **Verification:** 36 casos verdes no arquivo depois dos novos.
- **Committed in:** `c01ba5e8`

### 4. [Rule 1 - Bug] O vazio da IA era recusado tarde demais

- **Found during:** Task 1 (o teste pegou)
- **Issue:** `executar()` conferia o vazio **depois** do pós-processamento. Para o alvo `titulo`,
  `ajustarTituloDoKit('')` prefixa `Kit {N} ` e devolve `"Kit 2"` — não vazio. Resultado: IA
  muda viraria `status: pronto` com um "título" que é só a marca do kit, e a tela ofereceria
  isso para a pessoa aprovar.
- **Fix:** a recusa passou para `chamarIa()`, no texto **CRU**, antes de qualquer
  pós-processamento. A conferência de `executar()` ficou como segunda rede.
- **Files modified:** `app/Services/Publicador/SugestaoKitIaService.php`
- **Verification:** `test_titulo_vazio_da_ia_vira_erro_com_mensagem_nunca_pronto_vazio`
- **Committed in:** `68b7ca19`

### 5. [Plano superado] "Kit de 1 slot" → dois slots, e o teste do plano mudou com isso

- **Found during:** antes da Task 1 (briefing + `175-DECISOES.md`)
- **Issue:** O `<behavior>` da Task 2 pede
  `PlanejarKitCriativosJob::dispatch($portador, $kit, 1)` e "planeja UM slot". A decisão do
  usuário de 2026-10-08 superou isso.
- **Fix:** dois slots, `['lifestyle','hero']`. O `must_haves.truths` que diz "A capa do kit é
  planejada com UM slot" e o `key_links` que fala de "terceiro parâmetro de quantidade" estão
  **superados pelo `175-DECISOES.md`** — o resto do `must_haves` (o N como FATO, `contagens`
  com origem `cadastro`, nada gravado no rascunho por trás da pessoa, nenhuma API real nos
  testes, config de slots valendo para todos os outros kits) foi cumprido como escrito.
- **Verification:** `test_a_capa_sao_dois_slots_ambientada_e_fundo_limpo` e
  `test_a_capa_planeja_exatamente_lifestyle_e_hero_em_qualquer_categoria`.

---

**Total deviations:** 5 — 2 bloqueios auto-corrigidos (Rule 3), 1 funcionalidade crítica
ausente (Rule 2), 1 bug (Rule 1), 1 "plano superado por decisão do usuário".
**Impact on plan:** nenhum escopo novo. A única que merece leitura de outra pessoa é a nº 1
(`CreativePlanner` tocado além dos 4 arquivos previstos) — ver a ressalva lá.

## Issues Encountered

- **Testes PHP passando `&$recebido` sem inicializar.** `$this->servico('x', $recebido)` com
  `$recebido` nunca declarado passa `null` por referência e estoura o `array` do parâmetro.
  Resolvido declarando `$recebido = []` nos quatro casos que o usam.
- **`$this->fail()` dentro de `catch (\RuntimeException)`.** A `AssertionFailedError` do PHPUnit
  11 **é** um `RuntimeException`, então o `fail()` era engolido pelo próprio `catch` e o teste
  passava por acidente. Reescrito com um `$mensagem = null` + `assertNotNull` — padrão que vale
  para qualquer teste deste projeto que queira provar "lançou".

## Known Stubs

Nenhum. O painel React que consome os endpoints `fases.ia*` e a flag `capa` é do **175-07** —
o que existe aqui é servidor completo e testado, não stub.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. As duas rotas novas são as previstas
(T-175-25, com `baseDaConta()` nas duas e 404 provado), o despacho da capa reaproveita lock,
`retomavelDoPublicador` e throttles existentes (T-175-23), e nenhum token sai para o navegador
(T-175-24: a resposta tem `capa.kit_id` numérico e nada mais).

## Verificação medida

| O quê | Antes | Depois |
|---|---|---|
| `tests/{Feature,Unit}/Publicador` | **1047 verdes** | **1095 verdes** (5291 asserções) |
| Suítes do Creative Engine (16x) | 379 verdes + 1 incompleto | **379 verdes + 1 incompleto** (o mesmo) |
| `npm run test:js` | **pass 1442 / fail 2** | **pass 1442 / fail 2** (as 2 pré-existentes) |
| `git diff --stat` de `PalavrasChaveService.php` | — | **vazio** |
| `git diff --stat` de `usePublicador.js`, `EditorRascunhoService`, `App\Support\Publicador`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` | — | **vazio** |

As duas falhas JS (`estrutura-grade-glide.test.js`, `polosEntrantes.test.js`) são pré-existentes
e não têm relação com esta plan — nenhum arquivo `.js`/`.jsx` foi tocado aqui.

## Como o usuário confere isto em produção (conta #459 "Dev 02 Testes API")

⚠️ **Ainda não há botão.** O painel "Criar Fase 2" com "Sugerir com IA" e o checkbox da capa é o
**175-07**. O que segue é o roteiro para depois do deploy dessa plan — e o banco local não
serve, porque não tem empresa com `ml_token`.

**Antes de qualquer coisa, no VPS (sem isto nada roda):**

1. A chave do Creative Engine precisa estar ligada — é registro em `configuracoes`, sem deploy:
   `Configuracao::set('creative_engine_ativo', '1')`.
2. Se existir lista `creative_engine_usuarios`, o id do usuário que vai clicar precisa estar nela.
3. ⚠️ **Depois do deploy:** `sudo -u www-data php artisan queue:restart`. O `deploy.sh` só
   reinicia `ecf-worker:*`; a IA de texto roda em **`high`** e o planejamento da capa em
   **`creative`**. Sem o `queue:restart`, os dois ficam com código velho.

**Roteiro clicável:**

1. Abrir `https://admin.ecfconsultoria.com.br/mlb/anuncios/publicador` e escolher o programa da
   conta **#459 (Dev 02 Testes API)**.
2. Abrir um produto **com a Fase 1 publicada** e **com foto na galeria principal** (as duas
   condições importam: sem publicação o botão nem aparece, sem foto a capa recusa com
   "envie uma foto antes de gerar a capa").
3. Clicar em **"Criar Fase 2"**, deixar a quantidade em 2.
4. Clicar em **"Sugerir com IA"** no título. O que tem de acontecer:
   - o campo mostra "gerando…" e volta sozinho em alguns segundos a minutos (é polling);
   - o título sugerido **começa com "Kit 2"** e cabe no limite da categoria;
   - **nada foi salvo**: fechar o painel sem confirmar não muda o produto.
5. Clicar em **"Sugerir com IA"** na descrição. A **primeira linha** tem de ser exatamente
   `Este kit contém 2 unidades de {nome do produto}.`
6. Mudar a quantidade para 3 e pedir de novo: a sugestão tem de vir com **"Kit 3"** — se vier
   "Kit 2", o N está entrando na chave errada (é a prova da chave por quantidade).
7. Marcar **"gerar a capa do kit"** e Confirmar. A resposta tem de ser 201 e abrir o editor do
   kit em "Condições de venda".
8. Abrir o card de **Fotos** do kit e esperar o kit de criativos. O que tem de aparecer:
   - **DUAS** imagens, não uma: uma **ambientada** (`lifestyle`) e uma de **fundo limpo**
     (`hero`);
   - **as duas mostrando as N unidades do produto** — é esta a conferência que importa, e é a
     que não dá para provar por teste (ver "O que pode sair errado", abaixo);
   - aprovar UMA das duas; a aprovada vira a **foto 1** do kit e as 2+ seguem as herdadas do base.
9. Conferir no produto BASE que **nada mudou**: fotos, título e descrição dele continuam iguais.

**O que pode sair errado, e o que significa:**

- **A capa sai com UMA unidade só.** É o risco registrado no `deferred-items.md` item 2: o
  prompt INFORMA a contagem ("unidades idênticas do mesmo produto: 4") mas nenhuma linha dele
  **pede** a composição "N lado a lado", e o bloco MASTER diz "nunca mude a quantidade". Se
  acontecer, a correção é um bloco KIT no `CreativePromptBuilder` ou uma `cena` própria dos dois
  slots da capa — está escrito lá, com as duas opções e por que não foi feito agora.
- **"O gerador de imagens por IA está desligado"** → passo 1 do pré-requisito.
- **"Você não tem permissão para gerar imagens por IA"** → passo 2.
- **A fase é criada mas a capa recusa.** É o comportamento certo: a fase nunca é desfeita por
  falha da capa, e o motivo aparece na tela.

## Next Phase Readiness

**Pronto para o 175-07 (o painel React):**

- `POST publicador/empresas/{conta}/produtos/{produto}/fases/ia` com `{alvo, quantidade}` →
  **202** `{pedido, status:'rodando'}`.
- `GET …/fases/ia/{alvo}?quantidade=N` → `{pedido, status, valor, erro}` ou `{status:'nenhum'}`.
  Nunca 404 por "ainda não pedi".
- `POST …/fases` aceita `capa: true` e devolve, em **201**, `capa_pedida` e
  `capa: {ok, motivo, kit_id}` (ou `capa: null` sem a flag). O painel só precisa mostrar
  `capa.motivo` quando `capa.ok` é falso — e **não** tratar isso como erro da criação.
- O `kit_id` devolvido é o do Creative Engine: serve direto nas rotas
  `mlb.anuncios.publicador.criativos.*` do 165-04 para o polling e a aprovação.

**Ressalvas para quem continua:**

1. **O risco da composição da capa** (`deferred-items.md` item 2) só se resolve com uma geração
   real. Vale gerar uma antes de prometer a funcionalidade ao usuário.
2. **A premissa dos dois slots em TODA categoria** é da sessão de planejamento, não do usuário
   (está dito no `175-DECISOES.md`). Se ele quiser 1 slot fora de móveis, é uma linha em
   `CapaDoKitService::SLOTS_DA_CAPA` + o argumento passado.
3. **`CreativePlanner` foi tocado** além dos 4 arquivos previstos — mostrar ao ECF Dev junto do
   resto da coordenação.

## Self-Check: PASSED

- Os 5 arquivos criados existem em disco (`SugestaoKitIaService` 302 linhas,
  `CapaDoKitService` 227 — acima dos mínimos do `must_haves`).
- Os 6 commits de task existem no repositório (`d1363bc1`, `68b7ca19`, `c01ba5e8`,
  `f149ce2d`, `5fb01d79`, `723dfce5`).
- `tests/{Feature,Unit}/Publicador`: **1095 verdes**, 0 falhas.
- Suítes do Creative Engine (16x): **379 verdes**, 1 incompleto pré-existente.
- `npm run test:js`: **pass 1442 / fail 2**, idêntico ao antes.

⚠️ **O `artisan test` da suíte INTEIRA não serve de gate nesta máquina** e não foi
usado como tal: ele morre com `Fatal error: Allowed memory size of 536870912 bytes
exhausted` no meio da Fase 121 e, antes disso, acusa falhas **pré-existentes** em
suítes que esta plan não encosta. Duas conferidas em isolamento para não confundir
ruído com regressão:

- `Tests\Unit\CalcularFaixaTest` — `new AdminController()` com 0 de 7 argumentos.
  `AdminController` não está no diff desta plan.
- `Tests\Feature\ExampleTest` — `GET /` devolve 302 e o teste espera 200 (o teste
  de exemplo do Laravel).
- `Tests\Unit\CompanyServiceTypeTest` — `service_type => 'polo'` recusado: é a
  armadilha já registrada na memória do projeto (migration de enum que pulou o
  branch SQLite).

---
*Phase: 175-publicador-etapa-3-produto-fases*
*Completed: 2026-10-09*
