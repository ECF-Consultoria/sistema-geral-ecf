---
phase: 76-respons-veis-por-servi-o-company-users-com-dimens-o-de-servi
plan: 02
subsystem: models-carteira
tags: [eloquent, belongsToMany, dedup, carteira, bonus, servico_id]
requires: ["76-01"]
provides:
  - "Company::consultor()/estrategista() blindados por dedup + variantes consultorDoServico()/estrategistaDoServico()"
  - "User::companies()/consultorCompanies()/estrategistaCompanies() com select('companies.*')->distinct('companies.id')"
  - "Testes de invariante consolidado e não-duplicação da carteira do bônus"
affects:
  - "Todos os ~15 leitores consolidados da carteira (bônus/pendências) — comportamento preservado"
tech-stack:
  added: []
  patterns: ["dedup na relação (não no consumidor)", "distinct(coluna) p/ COUNT(DISTINCT) correto"]
key-files:
  created:
    - tests/Feature/V16/ResponsaveisConsolidadoInvarianteTest.php
    - tests/Feature/V16/CarteiraBonusNaoDobraTest.php
  modified:
    - app/Models/Company.php
    - app/Models/User.php
decisions:
  - "distinct('users.id')/distinct('companies.id') em vez de distinct() boolean — só a forma com coluna gera COUNT(DISTINCT) e deduplica no ->count()"
  - "Removido withPivot('assigned_at')+withTimestamps() da base companies() — reinjetavam colunas divergentes no SELECT e furavam o distinct (Pitfall 4/W1)"
metrics:
  duration: "~25min"
  completed: "2026-07-14"
  tasks: 2
  files: 4
---

# Phase 76 Plan 02: Blindar leitura consolidada (DEC-A2) Summary

Carteira consolidada (`Company::consultor()/estrategista()` e `User::companies()/consultorCompanies()/estrategistaCompanies()`) blindada com dedup defensivo para não double-contar quando a Phase 78 adicionar a linha Shopee, mais variantes service-aware (`*DoServico`) e provas por teste do invariante e da não-duplicação da carteira do bônus.

## O que foi feito

- **Company.php**: `consultor()`/`estrategista()` ganharam `->distinct('users.id')`; adicionadas as variantes `consultorDoServico(int $servicoId)` e `estrategistaDoServico(int $servicoId)` (para a aba Shopee da Phase 78; leitores atuais não as usam).
- **User.php**: `companies()`/`consultorCompanies()`/`estrategistaCompanies()` passaram a `->select('companies.*')->distinct('companies.id')`. Removidos `withPivot('assigned_at')` e `withTimestamps()` da base `companies()` (reinjetavam colunas de pivot divergentes no SELECT, furando o distinct — Pitfall 4/W1).
- **Testes**: `ResponsaveisConsolidadoInvarianteTest` (consolidado imutável com linha Shopee; variantes distinguem por serviço) e `CarteiraBonusNaoDobraTest` (carteira conta 1x com ML+Shopee de `assigned_at`/timestamps divergentes, via `->count()` e `->get()->count()`).

## Assumption A2 — confirmada por grep

- `grep pivot->assigned_at` em `app/` → **nenhum** consumidor.
- `grep pivot->role` em `app/` → único leitor é `PortfolioController:849`, alimentado por `companies()` em `:670` que **re-declara** `->withPivot('role')` por conta própria. Mecânica do Laravel (`aliasedPivotColumns`) reanexa `pivot_role` ao SELECT mesmo com `select('companies.*')` na base → `:849` continua resolvendo (validado por `--filter=Portfolio`, 28 passed).
- Nenhuma escrita via `companies()->attach/sync/detach` → remover `withTimestamps()` é seguro.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `->distinct()` boolean não deduplica no `->count()`**
- **Found during:** Tarefa 2 (teste RED inicial deu count==2 após linha Shopee)
- **Issue:** No grammar do Laravel, `distinct` boolean + colunas `['*']` compila `COUNT(*)` (o DISTINCT é ignorado no agregado), então `consultor()->count()`/`companies()->count()` contavam ML+Shopee como 2.
- **Fix:** Trocar `->distinct()` por `->distinct('users.id')` (Company) e `->distinct('companies.id')` (User) — a forma com coluna vira `is_array($query->distinct)` → `COUNT(DISTINCT col)`. `->get()` continua `SELECT DISTINCT` como antes.
- **Files modified:** app/Models/Company.php, app/Models/User.php
- **Commit:** b1f05db

## Deferred Issues (fora de escopo)

- `Tests\Feature\PublicacaoDesempenhoRouteTest > user com mlb dashboard acessa rota e recebe 200` retorna 403. **Pré-existente** (falha idêntica em HEAD~1/76-01), não relacionado à dedup de carteira. Registrado em `deferred-items.md`. Não corrigido (scope boundary).

## Verificação

- `php artisan test tests/Feature/V16 --stop-on-failure` → 11 passed (24 assertions).
- `php artisan test --filter=Portfolio` → 28 passed (370 assertions).
- `php artisan test --filter=Nps` → 157 passed (1011 assertions).
- `php artisan test --filter=Desempenho` → 55 passed, 1 failed (pré-existente, fora de escopo).

## Threat model

- **T-76-04** (Tampering — carteira do bônus dobrada): mitigado por `select('companies.*')->distinct('companies.id')`; provado por `CarteiraBonusNaoDobraTest` com `assigned_at` divergente.
- **T-76-06** (Information Disclosure — responsável consolidado): mitigado por `distinct('users.id')`; invariante provado por `ResponsaveisConsolidadoInvarianteTest` com linha Shopee simulada.

## Self-Check: PASSED

Todos os arquivos criados/modificados existem; commits cd73753 e b1f05db presentes no histórico.

## Notas para deploy

- Backend-only: **sem** `npm run build`, sem deploy. Branch MySQL da FK não é exercitado em SQLite — nada de schema mudou neste plano (só relações Eloquent), então sem impacto de migration aqui.
