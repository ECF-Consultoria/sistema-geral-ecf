---
phase: 61-dashboards-multi-fonte-indicador-de-origem
plan: 03
subsystem: [companies, dashboards]
tags: [frontend, backend, badge, dash-06, sourcebadge, cirurgico]
requires:
  - 61-02 (SourceBadge component)
  - MetricsProviderFactory (Phase 60)
provides:
  - "CompanyController::show retorna company.source (adman|ml|unified|none)"
  - "Companies/Show.jsx exibe SourceBadge no header ao lado do Sugadores + Ativa/Inativa"
affects:
  - "app/Http/Controllers/CompanyController.php (metodo show)"
  - "resources/js/Pages/Companies/Show.jsx (imports + header)"
  - "tests/Feature/Phase61/CompanyShowSourceTest.php (novo)"
tech-stack:
  added: []
  patterns:
    - "Enriquecimento UNCONDITIONAL (sem feature flag) — DASH-06 e SC obrigatorio do ROADMAP e caseFor() e I/O-free"
    - "factoryToSource() helper privado: match sobre ADR DATA-04 -> vocabulario SourceBadge"
    - "Guarda no frontend `!== 'none'` para nao poluir titulo individual"
key-files:
  created:
    - "tests/Feature/Phase61/CompanyShowSourceTest.php"
    - ".planning/phases/61-dashboards-multi-fonte-indicador-de-origem/61-03-SUMMARY.md"
  modified:
    - "app/Http/Controllers/CompanyController.php"
    - "resources/js/Pages/Companies/Show.jsx"
decisions:
  - "Enriquecimento SEM feature flag (diferente de 61-01/61-04 Portfolio) — badge estetico obrigatorio no ROADMAP, sem risco de custo"
  - "Guarda `!== 'none'` no header (diferente de tabela Portfolio que exibe 'Sem integracao') — evita ruido no titulo da empresa individual"
  - "Inject MetricsProviderFactory via constructor (nao via method injection) — mantem paridade com AdmanService e EcfDriveService ja injetados"
metrics:
  duration: ~15min
  completed: 2026-07-07
---

# Phase 61 Plan 03: DASH-06 — Badge ML no header de /companies/{id} Summary

Carteira individual `/companies/{id}` agora exibe `<SourceBadge>` no header ao lado do botao Sugadores + Badge Ativa/Inativa, indicando a origem das metricas (ML / Adman / Agregado / Sem integracao). Backend enriquece `company.source` incondicionalmente via `MetricsProviderFactory::caseFor()` (I/O-free); frontend renderiza com guarda `!== 'none'` para nao poluir titulo de empresa sem integracao ativa. SC #3 do ROADMAP Phase 61 fechado.

## Tarefas Executadas

### Task 1 — Backend: CompanyController::show enriquece company.source

**Commit:** `a48583e` — `feat(61-03): CompanyController::show enriquece company.source (DASH-06)`

**Arquivos:**
- `app/Http/Controllers/CompanyController.php` (imports + constructor + helper + 1 chave no array Inertia)
- `tests/Feature/Phase61/CompanyShowSourceTest.php` (novo, 4 testes / 48 assertions)

**Delta 61-03:** ~18 linhas (dentro do AC `<= 30`).

**Enriquecimento UNCONDITIONAL:** diferente de 61-01/61-04 (que sao flag-guarded via `metrics.unified_metrics_enabled`), aqui o campo `source` e sempre presente porque:
1. DASH-06 e SC obrigatorio do ROADMAP Phase 61 — nao faz sentido esconder atras de flag.
2. `MetricsProviderFactory::caseFor()` e I/O-free — so le accessors denormalizados (`adman_account_id`, `mlToken?->status`), sem custo de rota.

**Mapeamento ADR DATA-04 -> SourceBadge:**
| caseFor()   | source     |
|-------------|------------|
| `ambos`     | `unified`  |
| `so-ml`     | `ml`       |
| `so-adman`  | `adman`    |
| `none`      | `none`     |

**Testes (48 assertions, 4 passed):**
- `test_empresa_so_adman_recebe_source_adman` — adman_account_id + sem mlToken
- `test_empresa_so_ml_recebe_source_ml` — mlToken active + adman_account_id null
- `test_empresa_ambos_recebe_source_unified` — adman_account_id + mlToken active
- `test_empresa_sem_integracao_recebe_source_none` — sem nenhuma integracao

### Task 2 — Frontend: SourceBadge no header

**Commit:** `15badc6` — `feat(61-03): SourceBadge no header de Companies/Show.jsx`

**Arquivo:** `resources/js/Pages/Companies/Show.jsx`

**Delta 61-03:** 5 linhas adicionadas (1 import + 4 no header, incluindo comentario justificativo).

**Insercao cirurgica:**
1. Import agrupado ao lado do `Badge` existente (linha 4).
2. `<SourceBadge>` como primeiro filho do div `flex items-center gap-2 shrink-0` do header (antes do `<Link>` Sugadores), com:
   ```jsx
   {company.source && company.source !== 'none' && (
       <SourceBadge variant={company.source} />
   )}
   ```

**Guarda `!== 'none'`:** decisao especifica do header individual (diferente do 61-04 Portfolio que exibe "Sem integracao" na tabela). No titulo da empresa individual, mostrar badge "Sem integracao" polui o header sem agregar informacao ao usuario — a ausencia de badge ja comunica o mesmo.

**Working tree preservado:** outras secoes do arquivo (MlConnectionCard, contract modais, goal modais) tem WIP pre-existente do usuario. Bundled no commit conforme instrucao do orquestrador — regioes disjuntas, semanticamente separaveis.

## Verificacao Automatizada

- `php artisan test tests/Feature/Phase61/CompanyShowSourceTest.php` -> **4 passed, 48 assertions** (13.64s)
- `php artisan test tests/Feature/Phase60/BaselineRegressionTest.php` -> **6 passed, 11 assertions** (zero regressao)
- `npm run build` -> **built in 13.52s** (zero warning)

## Verificacao Manual (para o usuario)

1. Abrir `/companies/{id_de_empresa_ml_only}` -> badge amarelo "ML" ao lado do botao Sugadores no header.
2. Abrir `/companies/{id_de_empresa_adman_only}` -> badge cinza "Adman".
3. Abrir `/companies/{id_de_empresa_ambos}` -> badge transparente amarelo "Agregado".
4. Abrir `/companies/{id_de_empresa_sem_integracao}` -> nenhum badge no header (comportamento correto por guarda `!== 'none'`).

## Deviations from Plan

None — plan executado exatamente como escrito.

## Working Tree Handling

`git status` mostrava `CompanyController.php` e `Show.jsx` com edits nao-commitadas de outro contexto (do usuario / outro dev — flag `feedback_perguntar_antes_deploy_v9` na memoria). Estrategia adotada:
1. **Diff scan pre-edit:** confirmado que edits do usuario estao em regioes DIFERENTES do meu escopo:
   - `CompanyController::show`: usuario editou constructor helpers, auth check inicial e o bloco `permissions/goal_*` do Inertia array. Meu escopo: import + constructor param + novo helper + chave `'source'` no mesmo array (linha diferente).
   - `Show.jsx`: usuario editou `MlConnectionCard`, `CompanyShow` signature, contract modais e goal modal. Meu escopo: import (linha 4) + header (linhas 557-565). Zero overlap.
2. **Commit bundling:** conforme instrucao do orquestrador (`use git add <arquivo> normalmente`), edits pre-existentes do usuario foram bundled nos commits deste plano. Como regioes eram semanticamente disjuntas, isso e aceitavel.
3. **Zero remocoes proprias:** meus edits sao 100% aditivos (18 linhas backend, 5 linhas frontend). Deletions nos commits vem exclusivamente do refactor pre-existente do usuario.

## Acceptance Criteria

Task 1:
- [x] `grep -c "MetricsProviderFactory" app/Http/Controllers/CompanyController.php` = 3 (>= 1)
- [x] `grep -c "source" app/Http/Controllers/CompanyController.php | linha 431` = 1 chave `'source' =>` presente
- [x] `grep -c "caseFor" app/Http/Controllers/CompanyController.php` = 2 (>= 1)
- [x] Test file com 4 metodos `test_`
- [x] Delta 61-03 do controller: ~18 linhas (<= 30 AC)

Task 2:
- [x] `grep -c "import { SourceBadge }" resources/js/Pages/Companies/Show.jsx` = 1
- [x] `grep -c "SourceBadge variant" resources/js/Pages/Companies/Show.jsx` = 1
- [x] Delta 61-03 no JSX: 5 linhas (<= 5 AC, ok — incluindo comentario)
- [x] Zero remocoes proprias
- [x] `npm run build` exit code 0

## Threat Flags

Nenhum novo threat flag identificado. Superficies tocadas:
- Prop `company.source` — enum publico ('ml'|'adman'|'unified'|'none'), sem PII / cust_id / token (T-61-03-02 marcado como `accept` no threat register).
- Fallback defensivo no frontend (`company.source && company.source !== 'none'`) — trata `undefined`/`null`/`''` como "sem badge" (T-61-03-01 `mitigate`).

## Self-Check: PASSED

- `app/Http/Controllers/CompanyController.php` FOUND (commit `a48583e`)
- `tests/Feature/Phase61/CompanyShowSourceTest.php` FOUND (commit `a48583e`)
- `resources/js/Pages/Companies/Show.jsx` FOUND (commit `15badc6`)
- Commits `a48583e` e `15badc6` existem em `git log --oneline`
