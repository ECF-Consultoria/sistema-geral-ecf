---
phase: 110-fix-margem-adman-preferir-fallback-local-deterministico-blin
plan: 02
subsystem: desempenho
tags: [adman, margem, desempenho, bonus, congelamento, tdd, rate-limit]

# Dependency graph
requires:
  - phase: 110-01
    provides: "contribution_margin_pct resolvido com prioridade local determinística + gate de cobertura por dias-com-linha; cacheKey v11"
provides:
  - "compute() expõe margem_amostra{n_real,n_elegivel,cobertura} no topo do resultado"
  - "ConsolidarMesDesempenho recusa persistir snapshot mensal quando a amostra de margem do user está degradada (cobertura < 70%), preservando o snapshot anterior + Log::error de alerta"
  - "sub-caso sem snapshot anterior: recusa intencionalmente barulhenta (Log::error acionável nomeando impacto DESEMP-08 + instrução de re-rodar o comando)"
affects: [desempenho-bonus, congelamento-mensal, promocao-desemp-08]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gate de qualidade da amostra ANTES do updateOrCreate — recusa + preserva o valor já congelado em vez de sobrescrever com dado degradado"
    - "Log::error estruturado com contexto acionável (sem_snapshot_anterior, impacto_desemp08) para casos que exigem reconciliação operacional"

key-files:
  created:
    - tests/Feature/Phase110/ConsolidarMesMargemResilienteTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - app/Console/Commands/ConsolidarMesDesempenho.php

key-decisions:
  - "Gate só dispara quando n_elegivel>0 (existe empresa Adman elegível) — ausência de margem Adman (só-Shopee/sem carteira financeira) NUNCA é tratada como degradação (cobertura=1.0 nesse caso)"
  - "Limiar de cobertura para o CONGELAMENTO é 70% (MARGEM_COBERTURA_MINIMA_CONGELAMENTO), distinto do limiar de 80% do 110-01 (MARGEM_COBERTURA_MINIMA, por-empresa/dias-com-linha) — são gates em níveis diferentes: 110-01 decide local-vs-ao-vivo por EMPRESA, 110-02 decide persistir-vs-recusar por USER (fração de empresas com margem real)"
  - "Recusa SEM snapshot anterior mantém a política de não criar row placeholder/degradada (decisão travada do plano) — prioriza não envenenar o pagamento, mas torna o Log::error acionável e nomeia o impacto em DESEMP-08 explicitamente"
  - "Empresas de teste sem custId (adman_account_id/ml_store_id) usadas para simular amostra degradada — AdmanMetricDiffService::compute() curto-circuita pra emptyMetrics() quando custId vazio (linha ~110), simulando de forma limpa 'empresa elegível sem margem real' sem depender do gate de cobertura por-empresa do 110-01"

patterns-established:
  - "Gate de congelamento: ler compute()['margem_amostra'] ANTES de qualquer persistência que 'paga' — nunca confiar em uma passada única sem checar a qualidade da amostra"

requirements-completed: [FIXMARG-03]

# Metrics
duration: ~40min
completed: 2026-07-23
---

# Phase 110 Plan 02: Blindar congelamento mensal (ConsolidarMesDesempenho) contra margem degradada Summary

**`ConsolidarMesDesempenho` passa a recusar persistir o snapshot mensal (que PAGA o bônus) quando a cobertura de margem real do user está abaixo de 70%, preservando o snapshot anterior e emitindo `Log::error` de alerta — com sub-caso intencionalmente barulhento quando não há snapshot anterior para preservar, nomeando o impacto na cadeia de promoção DESEMP-08.**

## Performance

- **Duration:** ~40 min
- **Tasks:** 2/2
- **Files modified:** 3 (2 código + 1 teste novo)

## Accomplishments
- `DesempenhoScoreService::computeVarMargem()` passa a devolver `n_elegivel` (empresas com fonte financeira 'adman' — Shopee nunca fornece diff de margem real, não conta no denominador)
- `compute()` expõe `margem_amostra{n_real,n_elegivel,cobertura}` no topo do resultado — campo aditivo, sem bump de cacheKey (permanece v11 do Plan 01)
- `ConsolidarMesDesempenho::handle()` gateia a persistência: cobertura < 70% (com `n_elegivel>0`) → RECUSA `updateOrCreate`, preserva o snapshot já congelado, emite `Log::error`
- Sub-caso sem snapshot anterior: `Log::error` acionável nomeando `user_id`/`mes_referencia`, ausência de row para preservar, impacto em DESEMP-08 (`promoverPor2MesesConsecutivos` lê o mensal de M-1) e a instrução operacional de re-rodar `desempenho:consolidar-mes` manualmente quando o rate-limit passar
- Ausência de empresa Adman elegível (só-Shopee/sem carteira financeira) permanece fora do gate — `cobertura=1.0` nesse caso, comportamento normal preservado
- Contador `$degradado` incluído no log final do comando (`... · Degradados: N`)
- 5 testes de comportamento cobrindo os casos do plano: degradada+anterior (preserva), saudável (persiste), só-Shopee (não degradado), idempotência pós-gate, degradada+sem-anterior (nenhuma row + alerta acionável)

## Task Commits

Each task was committed atomically:

1. **Task 1: expor margem_amostra em compute()** - `fc95e456` (feat)
2. **Task 2a (RED): teste do gate de congelamento** - `eda3f2bc` (test)
3. **Task 2b (GREEN): gate de qualidade + recusa + alerta** - `dc994f65` (feat)

_TDD: RED (test) → GREEN (feat) para a Task 2, conforme `tdd="true"` implícito no fluxo do plano (RED explicitamente pedido na `<action>`)._

## Files Created/Modified
- `app/Services/DesempenhoScoreService.php` — `computeVarMargem()` devolve `n_elegivel`; `compute()` expõe `margem_amostra{n_real,n_elegivel,cobertura}`
- `app/Console/Commands/ConsolidarMesDesempenho.php` — `MARGEM_COBERTURA_MINIMA_CONGELAMENTO=0.7`; gate antes do `updateOrCreate`; contador `$degradado`; docblock referenciando FIXMARG-03 e o `<design_decision>` do plano
- `tests/Feature/Phase110/ConsolidarMesMargemResilienteTest.php` (novo) — 5 testes cobrindo os casos do plano

## Decisions Made
- Escopo estrito: gate só considera `n_elegivel` (empresas 'adman') — nunca penaliza user sem carteira financeira Adman (cobertura=1.0 por definição do 110-01)
- Fixture de teste usa empresas SEM `adman_account_id` para simular amostra degradada (o `AdmanMetricDiffService::compute()` curto-circuita pra métricas vazias quando falta custId) — evita depender do gate de cobertura por-empresa (80%, do Plan 01) para montar o cenário, tornando o teste mais direto e menos acoplado a detalhes internos daquele plano
- Não foi necessário bump de cacheKey — `margem_amostra` é campo aditivo ao shape de `compute()`, e `ConsolidarMesDesempenho` chama `compute()` PURO (não cached), sempre lendo fresco

## Deviations from Plan

None - plan executado exatamente como especificado (interfaces, comportamento, mensagens de log e os 5 casos de teste seguiram o `110-02-PLAN.md` à risca, incluindo o `<design_decision>` travado sobre o sub-caso sem-snapshot-anterior).

## Issues Encountered

A regressão ampla via `--filter="Desempenho|V18|Nps"` (451 testes) ultrapassou o timeout padrão de 120s
do shell e rodou em background (~646s no total); resultado final: **448 passed, 3 failed**. As 3 falhas
são PRÉ-EXISTENTES e fora do escopo deste plano (nenhum arquivo relacionado a elas foi tocado pelos
commits `fc95e456`/`eda3f2bc`/`dc994f65`):

1. `PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200` (403 em vez de 200) —
   já documentada em `deferred-items.md` desde o Plan 01 (rota `/publicacao/desempenho`, nenhuma relação
   com margem/congelamento).
2. `Phase31NpsSubmitTest::generate_cria_survey_com_auto_generated_false` — asserção de `expires_at`
   contra `now()` REAL (sem `Carbon::setTestNow`), sensível ao instante exato da execução.
3. `Phase69\NpsPhase69IntegrationTest::fluxo_2_generate_manual_por_admin_estrategista` — mesmo padrão
   (asserção de `expires_at`+7d contra `now()` real, sem `Carbon::setTestNow` no método).

Ambas as novas falhas (#2, #3) são testes de geração manual de survey NPS sensíveis ao horário de
execução (cruzamento de fronteira de dia entre criação e asserção) — nada a ver com
`DesempenhoScoreService`/`ConsolidarMesDesempenho`. Documentadas em `deferred-items.md` para investigação
futura fora da Fase 110 (Scope Boundary — não corrigidas). Antes de chegar ao resultado completo, também
rodei uma regressão direcionada mais rápida (32s) cobrindo especificamente os arquivos afetados/relacionados
pelas mudanças deste plano e do Plan 01 — 48/48 verdes, somados aos 27/27 da verificação exigida pelo plano,
todos confirmados verdes pela regressão ampla também.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Fase 110 (Plan 01 + Plan 02) está completa: margem determinística local (Plan 01) + rede de segurança no congelamento mensal (Plan 02), ambos fechados ANTES do congelamento oficial de junho em 31/07 14h BRT.
- `desempenho:consolidar-mes` agora resiste a rate-limit 429 transitório coincidindo com o instante do cron — recusa gravar dado envenenado, preservando o snapshot bom (ou alertando de forma acionável quando não há snapshot pra preservar).
- Recomendação operacional (não bloqueante): monitorar os logs `[Desempenho Mensal] Amostra de margem degradada` no dia 31/07 — se aparecerem, rodar `php artisan desempenho:consolidar-mes --mes=2026-07` manualmente depois que o rate-limit da Adman passar (reconciliação operacional, conforme `<design_decision>` do plano).
- Regressão ampla confirmada: `--filter="Desempenho|V18|Nps"` (451 testes) — 448 passed, 3 failed (todas pré-existentes/fora de escopo, ver "Issues Encountered" e `deferred-items.md`). Nenhuma falha relacionada às mudanças deste plano.

---
*Phase: 110-fix-margem-adman-preferir-fallback-local-deterministico-blin*
*Completed: 2026-07-23*

## Self-Check: PASSED

- FOUND: app/Services/DesempenhoScoreService.php
- FOUND: app/Console/Commands/ConsolidarMesDesempenho.php
- FOUND: tests/Feature/Phase110/ConsolidarMesMargemResilienteTest.php
- FOUND commit: fc95e456 (feat — margem_amostra em compute())
- FOUND commit: eda3f2bc (test RED — gate de congelamento)
- FOUND commit: dc994f65 (feat GREEN — gate de qualidade + recusa + alerta)
