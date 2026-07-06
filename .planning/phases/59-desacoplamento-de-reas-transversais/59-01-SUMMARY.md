---
phase: 59-desacoplamento-de-reas-transversais
plan: 01
subsystem: testing
tags: [audit, refactor, multi-marketplace, cross-cutting, grep-scout]

requires:
  - phase: 57-modelo-de-dados-multi-marketplace
    provides: modelo N:N company_marketplaces + coluna flat marketplace
  - phase: 58-dashboard-ecf-agregado-shells-por-marketplace
    provides: rotas /dashboard/{ecf,mercadolivre,shopee,amazon}
provides:
  - "59-AUDIT.md — mapa exaustivo (arquivo:linha) de acoplamento ML nos 3 controllers hotspot"
  - "Confirmação grep-based de que Publicação (pub.*) é transversal"
  - "Baseline de testes (955 coletados, 63 pré-existentes vermelhos) para gate de zero-regressão do Plan 03"
  - "Lista concreta 'Itens a corrigir no Plan 02' (2 itens MEDIUM)"
affects: [59-02-fixes, 59-03-regressao]

tech-stack:
  added: []
  patterns:
    - "Classificação Tipo/Severidade/Plano para auditoria de acoplamento cross-cutting"
    - "Contorno de infraestrutura: split de suite phpunit em 2 lotes para evitar reset de set_time_limit(300) entre processos"

key-files:
  created:
    - .planning/phases/59-desacoplamento-de-reas-transversais/59-AUDIT.md
  modified: []

key-decisions:
  - "Baseline de comparação para Plan 03 são os 63 vermelhos pré-existentes (15 errors + 48 failures) de 955 testes coletados — nenhum deles relacionado ao escopo Comercial/Company/Admin desta Phase"
  - "Suite completa não roda via 'php artisan test' nem 'vendor/bin/phpunit' direto sem contorno — set_time_limit(300) em SyncGrantsFromEcfDrive::handle() reseta o timer do processo PHPUnit inteiro; contornado rodando em 2 lotes (exclui SyncGrantsFromEcfDriveTest, roda separado) sem tocar código de produção"
  - "Zero itens HIGH encontrados nos 56 refs auditados — apenas 2 MEDIUM (naming/consistência interna de payload cust_id) e 1 LOW (prefixo mlb. em permission keys, deferred v14+)"

patterns-established:
  - "Split de suite phpunit por --filter negativo (regex lookahead) quando um teste isolado causa efeito colateral de processo compartilhado"

requirements-completed: [CROSS-01, CROSS-02]

duration: ~55min
completed: 2026-07-06
---

# Phase 59 Plan 01: Audit + baseline Summary

**Auditoria linha-a-linha dos 56 refs ML em ComercialController/CompanyController/AdminController encontrou ZERO acoplamento HIGH — apenas 2 inconsistências MEDIUM de naming/payload — e confirmou via grep que Publicação já é transversal.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-07-06T13:00:00Z (aprox.)
- **Completed:** 2026-07-06T13:52:28Z
- **Tasks:** 2/2
- **Files modified:** 1 (`59-AUDIT.md`, criado)

## Accomplishments

- Baseline de testes capturado com contagem exata: **955 testes coletados,
  4748 assertions, 15 errors + 48 failures (63 vermelhos pré-existentes),
  1 skipped**. Phase 57 confirmada 20/20 e Phase 58 confirmada 16/16 —
  ambas verdes conforme `58-VERIFICATION.md`.
- Descoberta e documentação de uma limitação real de infraestrutura de teste:
  `SyncGrantsFromEcfDrive::handle()` chama `set_time_limit(300)` (código de
  produção legítimo), e `SyncGrantsFromEcfDriveTest` invoca esse comando real
  12 vezes no mesmo processo PHPUnit — cada chamada reseta o timer do
  processo inteiro, e a suite subsequente (que usa `usleep()` real nos testes
  de backoff Phase 41/42) eventualmente excede os 300s, derrubando o processo
  com fatal error. Contornado rodando a suite em 2 lotes via
  `vendor/bin/phpunit` direto, sem alterar nenhum arquivo de produção.
- Scout completo dos 56 refs `marketplace|meli|mlb|Mlb|ml_store` nos 3
  controllers hotspot (29 Comercial + 17 Company + 10 Admin), cada linha lida
  no código real antes de classificar (não de cabeça).
- Confirmação via grep + leitura completa do `EnsurePermission.php` e
  `checkPubAccess()` (`MlbController.php`) de que Publicação (`pub.*` /
  `hasPubPermission()`) não tem amarração a marketplace — único achado é o
  prefixo naming histórico `mlb.` nas permission keys, classificado
  `deferred v14+` por exigir migração de dados gravados.
- Lista concreta "Itens a corrigir no Plan 02" com exatamente 2 itens
  (ambos MEDIUM, naming/consistência de payload `cust_id`, zero risco de
  quebrar rota/schema/contrato de API).

## Task Commits

Each task was committed atomically:

1. **Task 1: Capturar baseline de testes verdes antes de qualquer mudança** - `171620e` (docs)
2. **Task 2: Scout completo + classificação por linha + seção Publicação transversal** - `d742f21` (docs)

_Nenhum arquivo de código de produção foi tocado nesta plan — apenas o deliverable `59-AUDIT.md`._

## Files Created/Modified

- `.planning/phases/59-desacoplamento-de-reas-transversais/59-AUDIT.md` — mapa completo de acoplamento ML (baseline + metodologia + 3 tabelas de controller + seção Publicação + sumário + lista de itens pro Plan 02)

## Decisions Made

- **Baseline de comparação para o Plan 03 = 63 vermelhos pré-existentes** (não
  zero). Inspeção linha a linha de cada um dos 15 errors + 48 failures
  confirmou que NENHUM está relacionado ao acoplamento ML de
  Comercial/Company/Admin — são falhas legadas já conhecidas (Phase 13/14
  migrations com coluna `service_type` já dropada, `CalcularFaixaTest` com DI
  desatualizada, bug de timezone Carbon no Windows, `Phase38\PolosControllerTest`
  e `Phase42\*` já documentados em `STATE.md`/`deferred-items.md` como
  pré-existentes de outras phases). Plan 03 deve confirmar que os fixes do
  Plan 02 não introduzem NENHUM vermelho novo além destes 63.
- **Contorno de infraestrutura sem tocar produção**: suite rodada em 2 lotes
  (`--filter` regex negativo excluindo `SyncGrantsFromEcfDriveTest`, depois
  esse arquivo isolado) para obter contagem completa sem o crash do
  `set_time_limit(300)` compartilhado entre testes no mesmo processo PHPUnit.
  Documentado no `59-AUDIT.md` para que o Plan 03 reaplique o mesmo contorno.
- **Zero itens HIGH** — os 3 controllers hotspot já usam o accessor
  `Company::cust_id` (`adman_account_id ?: ml_store_id`) na maioria dos
  pontos; as 2 exceções MEDIUM (`CompanyController.php:129` e
  `AdminController.php:545`) são inconsistências de naming/resolução, não
  filtros que excluem funcionalidade de empresas não-ML.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Contornada limitação de infraestrutura de teste que impedia captura do baseline**
- **Found during:** Task 1 (captura de baseline)
- **Issue:** `php artisan test` e `vendor/bin/phpunit` direto crasham
  deterministicamente com "Maximum execution time of 300 seconds exceeded"
  (mesmo com `-d max_execution_time=0` explícito), impedindo qualquer
  contagem de baseline. Causa raiz: `set_time_limit(300)` em
  `SyncGrantsFromEcfDrive::handle()` (código de produção legítimo, Phase 20)
  é chamado 12x por `SyncGrantsFromEcfDriveTest` no mesmo processo PHPUnit,
  resetando o timer compartilhado; a suite subsequente (testes de backoff
  Phase 41/42 com `usleep()` real) acumula tempo suficiente para estourar
  o novo teto de 300s antes do fim.
- **Fix:** Suite rodada em 2 lotes via `vendor/bin/phpunit` direto — lote 1
  exclui `SyncGrantsFromEcfDriveTest` via `--filter` regex negativo (943
  testes), lote 2 roda esse arquivo isolado (12 testes, todos verdes).
  Totais somados para o baseline. **Nenhum arquivo de produção foi alterado**
  — a causa raiz (`set_time_limit(300)`) é comportamento correto em produção
  (protege requests HTTP longos), só colide com a arquitetura de processo
  único do PHPUnit quando combinada com testes de backoff que usam sleep real.
- **Files modified:** Nenhum arquivo de código — apenas documentação da
  descoberta em `59-AUDIT.md` (seção "Baseline pré-fix").
- **Verification:** Lote 1 (943 testes) + Lote 2 (12 testes) = 955 testes,
  batendo com a contagem original de 955 vista na tentativa de run completo
  (que crashava antes de imprimir o resumo). Phase 57 e Phase 58 confirmadas
  verdes em runs `--filter` isolados adicionais.
- **Committed in:** `171620e` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking, infraestrutura de teste)
**Impact on plan:** Necessário para completar a Task 1 (baseline é pré-requisito
obrigatório do plan). Nenhuma mudança em código de produção; nenhum scope creep.

## Issues Encountered

- Tentativas iniciais de rodar `php artisan test` (com e sem `-d max_execution_time=0`)
  falharam com fatal error antes de produzir qualquer contagem — ver Deviations acima
  para causa raiz e contorno aplicado.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- **Plan 02 (fixes) tem lista de trabalho concreta e pequena**: apenas 2
  itens MEDIUM (`CompanyController.php:129` e `AdminController.php:545`),
  ambos de naming/consistência de payload `cust_id`, sem risco de quebrar
  rota/schema/contrato externo. É plausível que o Plan 02 seja um plan curto
  ou até vire "no-op documentado + fix pontual" conforme CONTEXT §Sumário
  já previa como resultado válido.
- **Plan 03 (regressão) tem baseline exato para comparar**: 955 testes
  coletados, 63 vermelhos pré-existentes (todos identificados e não
  relacionados ao escopo desta Phase), Phase 57 20/20 e Phase 58 16/16.
  Plan 03 deve reaplicar o mesmo contorno de 2 lotes documentado neste
  summary/AUDIT para obter uma contagem pós-fix comparável.
- Nenhum bloqueio conhecido para o Plan 02.

---
*Phase: 59-desacoplamento-de-reas-transversais*
*Completed: 2026-07-06*

## Self-Check: PASSED

- FOUND: `.planning/phases/59-desacoplamento-de-reas-transversais/59-AUDIT.md`
- FOUND: `.planning/phases/59-desacoplamento-de-reas-transversais/59-01-SUMMARY.md`
- FOUND commit: `171620e` (Task 1)
- FOUND commit: `d742f21` (Task 2)
