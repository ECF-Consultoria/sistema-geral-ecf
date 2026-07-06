---
phase: 59-desacoplamento-de-reas-transversais
plan: 02
subsystem: refactor
tags: [refactor, multi-marketplace, cross-cutting, cust-id, accessor]

requires:
  - phase: 59-desacoplamento-de-reas-transversais
    plan: 01
    provides: 59-AUDIT.md com lista concreta "Itens a corrigir no Plan 02" (2 itens MEDIUM)
provides:
  - "CompanyController::index() e AdminController::fechamento()/gerarRelatorioGeral() usam o accessor Company::cust_id em vez de replicar a resolução manualmente"
  - "Divergência de ordem de prioridade (ml_store_id ?: adman_account_id vs. adman_account_id ?: ml_store_id) eliminada nos 2 pontos identificados"
  - "59-AUDIT.md atualizado com status APLICADO + seção de execução do Plan 02"
affects: [59-03-regressao]

tech-stack:
  added: []
  patterns:
    - "Preferir accessor $model->cust_id em vez de replicar fallback adman_account_id/ml_store_id manualmente em payloads Inertia"

key-files:
  created: []
  modified:
    - app/Http/Controllers/CompanyController.php
    - app/Http/Controllers/AdminController.php
    - .planning/phases/59-desacoplamento-de-reas-transversais/59-AUDIT.md

key-decisions:
  - "Os 2 itens 'fix Phase 59' do AUDIT foram aplicados exatamente como classificados — nenhuma expansão de escopo, ComercialController não foi tocado (0 itens lá)"
  - "Achado adicional durante a aplicação: a expressão manual usava ordem de prioridade INVERTIDA (ml_store_id ?: adman_account_id) em relação ao accessor canônico Company::cust_id (adman_account_id ?: ml_store_id, fixado em 2026-06-09 após bug real ADHARAPRINTSHOP/AVF_2K). Usar o accessor — já era o fix recomendado pelo próprio AUDIT — corrige naming E ordem simultaneamente, sem mudança de escopo"
  - "AdminController.php:709 (gerarRelatorioGeral) também foi ajustado no mesmo commit da linha 545, pois o próprio AUDIT pedia unificar as DUAS ocorrências (mesmo arquivo, 1 commit)"
  - "CompanyController.php tinha edições locais não commitadas de outra frente de trabalho no momento da execução; o fix desta plan foi isolado via patch (git apply --cached) para stagear e commitar SOMENTE o hunk relevante, preservando as demais mudanças intocadas no working tree"

patterns-established:
  - "Isolamento de commit via patch (git diff → git apply --cached) quando o arquivo alvo já tem mudanças locais não relacionadas pendentes"

requirements-completed: [CROSS-01]

duration: ~35min
completed: 2026-07-06
---

# Phase 59 Plan 02: Fixes cirúrgicos — resolução cust_id Summary

**Substituídas 2 expressões manuais de fallback cust_id (`ml_store_id ?: adman_account_id`) pelo accessor canônico `Company::cust_id`, corrigindo naming E ordem de prioridade invertida em CompanyController e AdminController, sem regressão nos smoke tests.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-07-06T13:55:00Z (aprox.)
- **Completed:** 2026-07-06T14:30:00Z (aprox.)
- **Tasks:** 3/3
- **Files modified:** 3 (`CompanyController.php`, `AdminController.php`, `59-AUDIT.md`)

## Accomplishments

- Validados os 2 itens `fix Phase 59` do `59-AUDIT.md` contra o código atual
  (Task 1) — ambos sem drift (o trecho citado ainda existia idêntico; a
  numeração de linha do `CompanyController.php` mudou de 129→109 por causa
  de edições locais não commitadas de outra frente, mas o conteúdo do
  trecho em si não mudou).
- `CompanyController.php` — payload `index()`: `'adman_account_id' => $c->ml_store_id ?: $c->adman_account_id`
  trocado para `$c->cust_id` (accessor canônico).
- `AdminController.php` — payload `fechamento()` (linha 545) e
  `gerarRelatorioGeral()` (linha 709): ambas unificadas para `$f->cust_id`,
  eliminando a divergência entre as duas rotas do mesmo controller para o
  mesmo conceito de dado.
- Achado extra (não expande escopo — é o mesmo fix recomendado pelo AUDIT):
  a expressão manual usava a ordem `ml_store_id ?: adman_account_id`,
  invertida em relação ao accessor `Company::cust_id`
  (`adman_account_id ?: ml_store_id`), cuja ordem foi corrigida
  deliberadamente em 2026-06-09 após bug real de produção (empresas
  ADHARAPRINTSHOP / AVF_2K retornavam HTTP 500 com `ml_store_id`). Usar o
  accessor resolve os dois problemas ao mesmo tempo.
- `59-AUDIT.md` atualizado: os 2 itens marcados `APLICADO em <sha>`, seções
  Company/Admin com nota explicando o achado da ordem invertida, e nova
  seção `## Plan 02 — Execução (status)` com contagem literal, lista de
  commits e amostra de smoke tests.
- Suite Phase 57 (20/20) + Phase 58 (16/16) = **36/36 confirmada verde**
  após todos os commits desta plan.

## Task Commits

Each task was committed atomically:

1. **Task 1: Validação dos 2 itens contra o código atual** — nenhum commit
   próprio (validação, sem mudança de arquivo).
2. **Task 2: Aplicar fixes item a item**:
   - `e816307` (refactor) — `CompanyController.php:109` — resolução cust_id
   - `90a2afe` (refactor) — `AdminController.php:545,709` — resolução cust_id
3. **Task 3: Documentar Plan 02 no AUDIT.md**:
   - `40e406c` (docs) — marca itens APLICADO + seção de execução

## Files Created/Modified

- `app/Http/Controllers/CompanyController.php` — linha 109: `'adman_account_id' => $c->cust_id` (era `$c->ml_store_id ?: $c->adman_account_id`)
- `app/Http/Controllers/AdminController.php` — linhas 545 e 709: `'adman_account_id' => $f->cust_id` em ambas (unificado)
- `.planning/phases/59-desacoplamento-de-reas-transversais/59-AUDIT.md` — itens marcados APLICADO + seção de execução do Plan 02

## Decisions Made

- Aplicar exatamente os 2 itens do AUDIT, nada além — `ComercialController.php`
  não foi tocado (0 itens `fix Phase 59` naquele controller).
- Usar o accessor `$model->cust_id` em vez de renomear a chave de saída
  (`cust_id_display`) — mantém o contrato de payload existente
  (`adman_account_id` continua a chave), só corrige a resolução do valor.
  Nenhum caller JSX/teste assume um nome de chave diferente.
- Unificar as duas ocorrências de `AdminController.php` (545 e 709) no MESMO
  commit, pois são o mesmo arquivo e o próprio AUDIT pedia unificação
  conjunta (não é expansão de escopo — é o item conforme descrito).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Ordem de prioridade invertida em relação ao accessor canônico**
- **Found during:** Task 2 (leitura de `app/Models/Company.php` antes do fix)
- **Issue:** As duas expressões manuais (`CompanyController.php:109` e
  `AdminController.php:545`) usavam `ml_store_id ?: adman_account_id`
  (prioridade ML), enquanto o accessor `Company::cust_id` usa a ordem
  inversa (`adman_account_id ?: ml_store_id`), deliberadamente fixada em
  2026-06-09 após bug real de produção (ADHARAPRINTSHOP/AVF_2K retornavam
  HTTP 500 ao usar `ml_store_id` como cust_id). Isso significa que, para
  empresas onde os dois IDs divergem, os payloads afetados podiam estar
  entregando o cust_id ERRADO (o mesmo bug já corrigido no accessor, mas
  ainda presente nestas 2 réplicas manuais).
- **Fix:** Troca das 3 ocorrências (`CompanyController.php:109`,
  `AdminController.php:545`, `AdminController.php:709`) para usar o
  accessor `$model->cust_id` diretamente — já era o fix recomendado pelo
  próprio `59-AUDIT.md` ("usar o accessor `$c->cust_id` diretamente"), então
  não é expansão de escopo, é a mesma ação com um benefício adicional
  identificado durante a leitura do código-fonte do accessor.
- **Files modified:** `app/Http/Controllers/CompanyController.php`,
  `app/Http/Controllers/AdminController.php`
- **Verification:** `php artisan test tests/Feature/Phase57/ tests/Feature/Phase58/`
  → 36/36 passed. Testes específicos de `AdminFechamentoControllerTest`,
  `Phase14AdminControllerCobrancaTest`, `Phase14VerificarCobrancaTest`,
  `Phase18\CompaniesCustIdFilterTest` confirmados com as MESMAS falhas
  pré-existentes (verificado via reversão temporária do fix + re-execução
  isolada de cada teste falho, confirmando que a falha ocorre igualmente
  sem o fix desta plan — causa raiz é coluna legacy `service_type`/datas de
  contrato, não `adman_account_id`).
- **Committed in:** `e816307` e `90a2afe`

**2. [Rule 3 - Blocking] Isolamento de commit em arquivo com mudanças locais não relacionadas pendentes**
- **Found during:** Task 2, ao preparar o commit de `CompanyController.php`
- **Issue:** `CompanyController.php` já tinha ~33 linhas de mudanças locais
  não commitadas de outra frente de trabalho em andamento (métodos
  `userCanViewCompany`/`userIsCompanyEstrategista`, props `goal_metrics`/
  `permissions` em `show()`) presentes no working tree ANTES desta plan
  começar. `git add` do arquivo inteiro incluiria essas mudanças não
  relacionadas no commit `refactor(59-02)`, violando o escopo cirúrgico.
- **Fix:** Extraído o diff completo do arquivo, construído um patch
  contendo SOMENTE o hunk da linha 109 (referenciado contra `HEAD`), validado
  com `git apply --check --cached`, aplicado com `git apply --cached` para
  stagear apenas essa mudança, e commitado. As demais ~33 linhas
  permaneceram intocadas e não commitadas no working tree, como estavam
  antes desta plan.
- **Files modified:** Nenhum arquivo adicional — apenas isolamento de
  staging em `app/Http/Controllers/CompanyController.php`.
- **Verification:** `git show --stat e816307` confirma "1 file changed, 4
  insertions(+), 1 deletion(-)" (apenas o hunk do fix); `git diff --stat`
  pós-commit confirma que as 33 linhas não relacionadas continuam
  presentes e não commitadas no working tree.
- **Committed in:** `e816307`

---

**Total deviations:** 2 auto-fixed (1 bug/Rule 1, 1 blocking/Rule 3)
**Impact on plan:** Ambos os desvios foram necessários para completar a
Task 2 corretamente e sem contaminar o commit com mudanças de outra frente.
Nenhum scope creep — o achado da ordem invertida é coberto pelo mesmo fix
já recomendado pelo AUDIT.

## Issues Encountered

- `CompanyController.php` com mudanças locais não commitadas pré-existentes
  (ver Deviation 2 acima) — resolvido via patch isolado, sem impacto no
  resultado final.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- **Plan 03 (regressão) tem os 2 fixes aplicados e commitados**, prontos
  para o gate de suite completa comparando contra o baseline de 63
  vermelhos pré-existentes documentado em `59-AUDIT.md` / `59-01-SUMMARY.md`.
- Smoke tests já confirmam: nenhuma regressão nova introduzida pelos fixes
  desta plan (36/36 Phase57+58 verde; falhas em arquivos relacionados
  confirmadas como pré-existentes via reversão temporária).
- Nenhum bloqueio conhecido para o Plan 03.

---
*Phase: 59-desacoplamento-de-reas-transversais*
*Completed: 2026-07-06*

## Self-Check: PASSED

- FOUND: `.planning/phases/59-desacoplamento-de-reas-transversais/59-02-SUMMARY.md`
- FOUND commit: `e816307` (Task 2, CompanyController)
- FOUND commit: `90a2afe` (Task 2, AdminController)
- FOUND commit: `40e406c` (Task 3, AUDIT.md)
