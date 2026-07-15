---
phase: 80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s
plan: 03
subsystem: performance-widgets
tags: [nps, desempenho, widgets, atribuicoes, dual-path, inertia, react, tdd]

# Dependency graph
requires:
  - phase: 80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s
    plan: 01
    provides: "computeNpsMedio dual-path + semântica do skip por papel (DEC-80-B1) espelhada aqui"
  - phase: 80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s
    plan: 02
    provides: "Cache v3 — o headline nps.media servido pelo service já sai correto"
  - phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
    provides: "nps_score_assignments (atribuição congelada) + service_setor para o rótulo de área"
provides:
  - "PerformanceController::notasNpsDoUsuarioPorResposta — dual-path de APRESENTAÇÃO, 1 linha por resposta"
  - "Coluna NPS por empresa, últimas respostas e heatmap derivam de UMA passagem de dados"
  - "Rótulo de área em linguagem clara (Mercado Livre / Shopee) — slug cru nunca vai pra tela"
  - "Lista de últimas respostas volta a ser renderizada (estava órfã desde o redesign de 2026-07-09)"
  - "Suite tests/Feature/V16/WidgetNpsAtribuicoesTest — Shopee visível + isolamento inverso"
affects: [checkpoint visual do 80-03, deploy do pacote NPS v16.0, follow-up PortfolioController:1374]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Helper de apresentação declarado NÃO-autoritativo no docblock (service ganha em divergência)"
    - "Uma passagem de dados na janela mais larga + recortes em memória (evita 3 varreduras divergentes)"
    - "Flags por-item computadas DENTRO do callback do .map() (feedback_rollup_map_scope_bug)"

key-files:
  created:
    - tests/Feature/V16/WidgetNpsAtribuicoesTest.php
  modified:
    - app/Http/Controllers/PerformanceController.php
    - resources/js/Pages/Performance/Dashboard.jsx

key-decisions:
  - "Helper vive no PerformanceController (apresentação), NÃO no DesempenhoScoreService — git diff do service vazio é critério de aceite atendido"
  - "Ramo (A) do helper filtra por $companyIds (assimetria deliberada vs o service) — os widgets só renderizam empresas da carteira exibida; documentado como prova de que o helper não pode virar régua"
  - "->principal() PERMANECE no ramo legado do helper (DEC-80-D) — é o isolamento por serviço"
  - "MAX(service_setor) no dedup: desempate determinístico do rótulo quando a mesma pessoa responde por 2 setores na MESMA resposta (a nota é idêntica, não muda)"
  - "Lista de últimas respostas re-renderizada (Rule 2) — sem isso o must_have #1 era inatingível"

patterns-established:
  - "Widget de apresentação espelha a semântica do service sem virar segunda fonte de verdade"
  - "Verificação do bundle minificado como prova do map-scope (não só 'build passou')"

requirements-completed: [DEC-80-E]

# Metrics
duration: ~25min (continuação — Tarefas 1-2 já commitadas)
completed: 2026-07-15
---

# Phase 80 Plan 03: Widgets do NPS leem as atribuições — Summary

**Os 3 leitores de apresentação do `dashboardCarteira` (coluna NPS por empresa, últimas respostas e heatmap) deixaram de filtrar por `->principal()` e passaram a derivar do mesmo dual-path do bônus — a resposta do NPS Shopee finalmente APARECE para quem responde pelo Shopee, com o rótulo "Shopee" em vez do slug cru, e continua invisível para o analista de ML da mesma empresa.**

## Performance

- **Duração:** ~25 min (continuação: as Tarefas 1 e 2 já estavam commitadas ao início desta sessão)
- **Tarefas:** 2/3 auto concluídas + Tarefa 3 (checkpoint visual) pendente de validação humana
- **Arquivos:** 1 criado (teste, 354 linhas), 2 modificados
- **Commits:** 3 (RED, GREEN, rótulo) + docs

## Accomplishments

### Tarefa 1 — Helper dual-path de apresentação (`ef31196` RED → `92973d0` GREEN)

`PerformanceController::notasNpsDoUsuarioPorResposta(User, Collection $companyIds, Carbon $desde): Collection` — 1 linha por resposta com `company_id` / `completed_at` (Carbon) / `nota` (float) / `area` (`performance` | `shopee` | `null`).

Espelha a união disjunta do service:
- **(A) atribuições** (`nps_score_assignments`) do user, JOIN até `nps_surveys.completed_at` (DEC-80-B0 — nunca `assigned_at`), dedup `groupBy(nps_response_id, role)` + `MAX()`; qualquer modelo conta; `area` sai de `service_setor`.
- **(B) legado** com `->principal()` **preservado** (DEC-80-D — é o isolamento por serviço), pulando as respostas já cobertas por atribuição **no papel correspondente à dimensão do cargo** (DEC-80-B1, snapshot autoritativo por papel); `area = null`.

O set de skip é derivado dos surveys **já carregados** (`->flip()` para lookup O(1)), nunca de uma 2ª query com filtro de data próprio — dois filtros divergem e a resposta escaparia contada pelos DOIS ramos.

**Cenário âncora do teste (Decoral real):** NPS Padrão (principal, ML, nota 5) + NPS Shopee (não-principal, nota 2) na MESMA empresa, responsáveis distintos. Atribuições geradas pelo **fluxo real** (`POST /nps/{token}` → `NpsSnapshotService`), não por inserção manual. `Http::preventStrayRequests()` — widget não pode depender de rede.

### Tarefa 2 — Os 3 widgets + rótulo de área (`92973d0`, `3fe102d`)

**Uma passagem de dados** na janela mais larga (`now()->subMonths(5)->startOfMonth()`, a do heatmap) e recortes em memória: coluna NPS = `filter(60d)` + `groupBy(company_id)->first()` (a coleção já vem `sortByDesc(completed_at)`); últimas respostas = `take(4)`; heatmap = `groupBy(company_id|Y-m)->avg`. Os 3 contam a MESMA história — antes eram 3 queries `->principal()` independentes.

`$npsMedio` (`:454`) **intocado**: segue vindo de `$data['componentes']['nps_medio']` (o service). Shape do payload preservado (`media`/`respostas`/`heatmap`); única adição é `area` em `respostas[]`.

**Frontend:** `ROTULO_AREA` (module-scope const) mapeia `performance → "Mercado Livre"`, `shopee → "Shopee"`; `area: null` → sem badge. Slug cru nunca vai pra tela (regra do projeto: evitar jargão sem explicação).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Funcionalidade crítica ausente] A lista de últimas respostas estava ÓRFÃ — o plano assumia que era renderizada**

- **Encontrado em:** Tarefa 2
- **Issue:** o plano manda "exibir o rótulo de área ao lado de cada item de `nps.respostas`" — assumindo que a lista existia na tela. **Não existia.** O payload `nps.respostas` é enviado desde a Phase 73, mas o redesign de 2026-07-09 (`a2d28ef`) trocou a lista pelo heatmap e **ninguém mais consumia o prop**. Verificado em `694096f`: as 3 ocorrências de "respostas" no `Dashboard.jsx` eram **2 comentários + 1 string de empty state não relacionada** — zero consumo real; e `corPorNota()` tinha **só a definição** (código morto).
- **Por que é Rule 2 e não escopo novo:** sem re-renderizar a lista, o backend mandaria `area` para o vazio e o **must_have #1 do plano** ("a resposta do NPS Shopee aparece na lista de últimas respostas do widget") seria **inatingível** — o sintoma que o usuário reportou continuaria de pé com todos os testes verdes.
- **Fix:** lista re-renderizada dentro do `NpsHeatmapWidget` (empresa · badge de área · quando · nota), reusando o `corPorNota()` morto.
- **Arquivo:** `resources/js/Pages/Performance/Dashboard.jsx`
- **Commit:** `3fe102d`

**2. [Discrição do plano] Assimetria deliberada do ramo (A) vs o service — documentada, não "corrigida"**

- **Contexto:** aqui o ramo (A) TAMBÉM filtra por `$companyIds`; no service **não** filtra (o congelamento manda). Os 3 widgets só sabem renderizar empresas da carteira exibida — uma nota órfã viraria linha fantasma no heatmap.
- **Impacto real:** nulo na prática — `User::companies()` não filtra por `servico_id`, então toda empresa com atribuição do user está na carteira; o recorte só morde empresa **inativa**.
- **Escolha:** manter a assimetria e **documentá-la no docblock** como exatamente o tipo de divergência que proíbe promover o helper a régua de bônus (T-80-11), em vez de alinhar os dois e criar uma segunda fonte de verdade.

## Verification Results

| Verificação | Resultado |
|---|---|
| `--filter=WidgetNpsAtribuicoesTest` | **2/2 verdes** (35 assertions) |
| `--filter=Performance` | **37/37 verdes** |
| `--filter=Desempenho` | 56 testes, 1 falha — **idêntica ao baseline dos planos 80-01/80-02** (pré-existente, ver abaixo) |
| `npm run build` | **✓ built in 16.33s**, sem erro |
| `grep -n "principal()"` em `dashboardCarteira` (:265-547) | **zero queries** — as 2 ocorrências no range são **comentários**; a query real (`:638`) vive no helper, ramo legado, por design (DEC-80-D) |
| `git diff app/Services/DesempenhoScoreService.php` | **vazio (0 linhas)** ✔ — a régua de bônus não foi tocada |
| `git diff --stat 694096f..HEAD` | **3 arquivos** — controller, Dashboard.jsx e o teste novo |
| Payload mantém `media`/`respostas`/`heatmap` | ✔ (+ `area` em `respostas[]`) |

**Prova do bundle (não só "o build passou"):** o minificado confirma o padrão do map-scope —
`h.map((a,o)=>{const n=ee[a.area]??null,c=a.nota!=null;` — lookup e flags computados **dentro** do callback, com `ee` (o `ROTULO_AREA`) como const de **módulo**, que o Rollup não elimina. `performance:"Mercado Livre",shopee:"Shopee"` presente em `Dashboard-ClEtkAq9.js`. A armadilha da memória `feedback_rollup_map_scope_bug` está evitada por construção, não por sorte.

## Deferred Issues

**Falha pré-existente fora do escopo** (já registrada em `deferred-items.md` pelos planos 80-01/80-02):
`Tests\Feature\PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` (403 ≠ 200).

**Por que não pode ser do 80-03 (argumento estrutural, não só "já estava lá"):** o teste exercita `/publicacao/desempenho` — outro módulo — e tem **zero** referências a `PerformanceController`, `dashboardCarteira` ou `Performance/Dashboard`. O blast radius do plano é de 3 arquivos, nenhum deles no caminho dessa rota. Capturada pelo `--filter=Desempenho` só por coincidência de nome de classe. Não corrigida (SCOPE BOUNDARY).

**Follow-up registrado (item 6 do `deferred-items.md`) — resposta explícita ao `<output>` do plano:**
**`PortfolioController:1374` DEVE virar follow-up, mas NÃO nesta fase.** Mesmo padrão `->principal()` + dimensão por cargo → a série histórica de NPS da carteira também não enxerga NPS Shopee (o sintoma sobrevive em outra tela). **Agravante encontrado agora e não mapeado no CONTEXT/RESEARCH:** o `groupBy` usa `month_reference ?? completed_at`, dando **precedência ao `month_reference`** — conflito frontal com o **DEC-80-B0**. Uma resposta disparada num mês e respondida no seguinte cai em meses diferentes nas duas telas. **Corrigir os dois no mesmo follow-up.** Já coberto pelo bump v3 (consome `computeCached` em `:1251`/`:1277`) — não precisa de novo bump.

## Known Stubs

Nenhum. O ramo legado do helper **não é stub nem ponte temporária** — é fallback **permanente** (empresas com `company_users.servico_id = NULL` no backfill nunca geram atribuição), pelo mesmo motivo documentado no 80-01.

## Threat Flags

Nenhuma superfície de segurança nova. Mitigações do `<threat_model>` aplicadas e cobertas por teste:

| Threat ID | Mitigação aplicada | Prova |
|---|---|---|
| T-80-10 | Query sempre filtrada por `user_id = $user->id` **e** `whereIn(company_id, $companyIds)` da carteira dele | `test_analista_ml_nao_ve_resposta_shopee_da_mesma_empresa` — o analista de ML recebe 5.0 (a nota de ML) e a resposta Shopee não aparece na lista dele |
| T-80-11 | Helper é só apresentação; `nps.media` continua do service; docblock declara o service como fonte oficial e explica a assimetria do `$companyIds` | `git diff` do service vazio; `$npsMedio` (`:454`) intocado |
| T-80-12 | `with(['response.answers','response.survey'])` preservado + **uma** passagem de dados para os 3 widgets (era 3 queries) | `--filter=Performance` 37/37 sem regressão |
| T-80-13 | `npm run build` + flags dentro do `.map()` | bundle minificado inspecionado (snippet acima) |
| T-80-SC | Nenhuma dependência instalada | `composer.json`/`package.json` intocados |

## Tarefa 3 — Checkpoint visual (PENDENTE — passos para o usuário)

Código e bundle prontos; **não executei o deploy** e não travei no checkpoint (o orquestrador conduz). Passos de validação:

1. Login com usuário responsável por **Shopee** (equivalente ao Gustavo: analista de ML **e** de Shopee) e acessar o dashboard de carteira (`/performance/carteira`).
2. Conferir:
   - a média de NPS do topo do widget bate com a do ranking em `/performance`;
   - a resposta do **NPS Shopee** aparece nas últimas respostas, com o badge **"Shopee"**;
   - as respostas de ML aparecem com **"Mercado Livre"** — **nunca** o slug cru `performance`;
   - a coluna NPS da tabela de empresas mostra nota para a empresa que respondeu Shopee.
3. Login como analista de **ML** de uma empresa que também tem Shopee: a resposta do NPS Shopee **não** pode aparecer nem influenciar a nota dele. *(coberto por teste automatizado, mas é a regra de negócio mais sensível da fase — vale o olho humano)*
4. Heatmap continua renderizando as 6 colunas de mês, agora sem buraco no mês da resposta Shopee.
5. Console do navegador: **sem `ReferenceError`** (bundle de produção).

**Sinal de retomada:** "aprovado" ou a descrição do que estiver errado (print ajuda).

## Avisos operacionais

- **Frontend tocado → `npm run build` é obrigatório no deploy** (o `public/build` é gitignored: o bundle **não** viaja no commit, precisa ser buildado no destino ou enviado pelo script de deploy).
- Os avisos do 80-02 seguem valendo: o **degrau no `delta_vs_ontem`** no dia do deploy é a correção (não bug), e o **bump v3** é o que faz a correção aparecer.
- **Nenhum deploy executado.** Dev em paralelo (anunciar-ml) — reconciliar antes (memória `feedback_perguntar_antes_deploy_v9`).
- Ao fim da fase, atualizar a memória `project_nps_modelo_principal`: "só o principal conta" segue valendo no **ramo legado**, superada no **ramo das atribuições**.

## Self-Check: PASSED

- `tests/Feature/V16/WidgetNpsAtribuicoesTest.php` — FOUND (354 linhas ≥ min_lines 60)
- `app/Http/Controllers/PerformanceController.php` contém `nps_score_assignments` + `notasNpsDoUsuarioPorResposta` — FOUND
- `resources/js/Pages/Performance/Dashboard.jsx` renderiza `nps.respostas[].area` — FOUND
- Commit `ef31196` (test RED) — FOUND
- Commit `92973d0` (feat GREEN) — FOUND
- Commit `3fe102d` (feat rótulo) — FOUND
- Gate TDD: `test(...)` → `feat(...)` na ordem correta — CONFIRMADO
- `git diff app/Services/DesempenhoScoreService.php` vazio — CONFIRMADO
