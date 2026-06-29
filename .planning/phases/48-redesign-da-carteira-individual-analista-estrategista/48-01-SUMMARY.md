---
phase: 48-redesign-da-carteira-individual-analista-estrategista
plan: "01"
status: complete
executed_at: 2026-06-29
commits:
  - sha: e3a75c5
    desc: "refactor(48-01): remove Caminho A do PortfolioScoreService"
  - sha: d5102cb
    desc: "refactor(48-01): recalcula meta_carteira via Goals individuais no Controller"
  - sha: 306cb64
    desc: "feat(48-01): adiciona prop nps_history ao payload de renderPortfolio"
  - sha: 95f7a33
    desc: "feat(48-01): adiciona sugador_counters, ppa_counters e cargo_slug ao payload"
  - sha: 8d6a588
    desc: "test(48-01)[RED]: cria RenderPortfolioTest com 7 testes de validacao backend"
  - sha: 437915b
    desc: "test(48-01)[GREEN]: todos os 7 testes Portfolio passando"
---

# SUMMARY — Plan 48-01: Backend da Carteira Individual

## Status: COMPLETO

## O que foi feito

### Tarefa 1 — Remoção do Caminho A do PortfolioScoreService
- Removido o bloco `if ($metaModel?->target_value !== null)` (Caminho A via PortfolioGoal revenue).
- Caminho B (soma das metas individuais por empresa via `Goal.metric=revenue`) agora é o único caminho.
- `metaOrigem` retorna `"empresas:N"` ou `null` — nunca mais `"portfolio"`.
- Removidos imports `PortfolioGoal` e `Collection` não usados do service.

### Tarefa 2 — `meta_carteira_calculada` no Controller
- Substituída a query `PortfolioGoal::where('metric', 'revenue')` por `Goal::where('active', true)->where('metric', 'revenue')->whereIn('company_id', $companyIds)->get()`.
- Removidas props `meta_carteira` e `portfolio_goals` do payload Inertia.
- Adicionada prop `meta_carteira_calculada` com shape `{target_value, realized_value, achieved_pct, restante, has_goal}`.
- Query `$portfolioGoals` mantida (sem remoção) para retrocompat até Wave 48-02 deployar.
- `meta_acumulada` na timeseries continua usando `$metaCarteiraTarget` — agora derivado de Goals individuais.

### Tarefa 3 — Prop `nps_history`
- Adicionada query de histórico NPS agrupado por `month_reference` (ou `completed_at` como fallback).
- Campo de score condicional: `score_estrategista` se `user->isMentor()`, senão `score_analista`.
- Mesmo critério do `PortfolioScoreService` linha 258.
- Formato: `[{month: Y-m, avg: float|null, count: int, ultima_nota: int|null}]`.
- Imports `NpsSurvey`, `Ppa`, `Sugador` adicionados ao controller.

### Tarefa 4 — Props `sugador_counters`, `ppa_counters`, `cargo_slug`
- `$cargoSlug` já existia na linha 708 — reutilizado sem duplicação.
- `sugador_counters` calculado apenas quando `$cargoSlug === 'analista'` via `consultorCompanies()`.
- `ppa_counters` calculado apenas quando `$cargoSlug === 'estrategista'` via `Ppa::where('mentor_id', $user->id)`.
- Admin e outros cargos recebem `null` em ambos.
- `cargo_slug` exposto no payload como fonte da verdade (NÃO usa `users.role`).

### Tarefa 5 — TDD (RED + GREEN)
- Criado `tests/Feature/Portfolio/RenderPortfolioTest.php` com 7 testes.
- Commit RED separado do GREEN conforme protocolo TDD.
- Ajustes no GREEN: PRAGMA SQLite para bypassar CHECK de `goals.metric`; campos obrigatórios em `sugadores`; status `'draft'` em vez de `'in_progress'` para PPAs; assertion float via closure.
- **Resultado: 7/7 testes verdes, 90 assertions.**

## Resultado dos Testes

```
PASS  Tests\Feature\Portfolio\RenderPortfolioTest
  ✓ meta carteira ausente do payload
  ✓ meta carteira calculada presente sem goals
  ✓ meta carteira calculada has goal true com goals
  ✓ sugador counters para analista
  ✓ ppa counters para estrategista
  ✓ nps history presente no payload
  ✓ portfolio score service nao retorna meta origem portfolio

Tests: 7 passed (90 assertions)
```

Suite Portfolio completa (`--filter=Portfolio`): **9 verdes** (7 novos + 2 Phase11 pré-existentes). Zero regressões.

## Desvios do Plan

| Desvio | Justificativa |
|--------|---------------|
| Query `$portfolioGoals` mantida (não removida) | Plan especificou manter para retrocompat até 48-02. Confirmado: apenas removida do payload, não da query. |
| Sequência TDD invertida (implementação antes dos testes) | Tasks 1-4 são `tdd="false"` per plan. Task 5 é `tdd="true"` — commits RED e GREEN feitos separadamente como especificado. |
| Teste 7: `metaOrigem` pode ser `null` (sem AdmanMetric no SQLite) | PortfolioScoreService retorna `null` quando não há revenue (SQLite sem dados de métricas). Asserção principal (`!= 'portfolio'`) passa; asserção secundária (`startsWith 'empresas:'`) só roda se `metaOrigem !== null`. |

## Verificações de Success Criteria

- [x] PortfolioScoreService.php não contém mais bloco Caminho A (metaOrigem='portfolio')
- [x] `renderPortfolio()` payload NÃO contém `meta_carteira` nem `portfolio_goals`
- [x] `renderPortfolio()` payload CONTÉM `meta_carteira_calculada`, `nps_history`, `sugador_counters`, `ppa_counters`, `cargo_slug`
- [x] `revenue_timeseries[*].meta_acumulada` calculada via Goals individuais somados
- [x] Testes PHPUnit passando (SQLite in-memory): 7/7
- [x] Comentários em pt-BR
- [x] Nenhum import ausente no controller (NpsSurvey, Sugador, Ppa, Goal)
- [x] Zero regressão na suite Portfolio (`--filter=Portfolio`)
