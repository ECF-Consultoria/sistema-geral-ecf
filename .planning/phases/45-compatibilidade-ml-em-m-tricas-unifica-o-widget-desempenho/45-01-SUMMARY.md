# 45-01 SUMMARY

**Phase:** 45-compatibilidade-ml-em-m-tricas-unifica-o-widget-desempenho
**Plan:** 45-01
**Status:** complete
**Executado em:** 2026-06-29

---

## Tarefas executadas

### Tarefa 1 — Fix filtro users no PerformanceController::index()

**Status:** COMPLETA

**Arquivo modificado:** `app/Http/Controllers/PerformanceController.php`

**O que foi feito:**
Substituído o filtro legado (`whereIn('role', ['consultor','mentor']) + whereNull('publication_role')`) pelo filtro canônico via `user_setores → cargos` com `slug IN ['analista', 'estrategista']`, idêntico ao widget "Desempenho da equipe" do `DashboardController` (linhas 588-594).

**Antes:**
```php
$users = User::where('active', true)
    ->whereIn('role', ['consultor', 'mentor'])
    ->whereNull('publication_role')
    ->get();
```

**Depois:**
```php
// Fonte canônica: cargo via user_setores → cargos (desde quick 260610-f69).
// Alinhado ao widget "Desempenho da equipe" do DashboardController (Phase 45 fix).
$users = User::where('active', true)
    ->whereExists(function ($q) {
        $q->from('user_setores as us')
          ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
          ->whereColumn('us.user_id', 'users.id')
          ->whereIn('c.slug', ['analista', 'estrategista']);
    })
    ->get(['id', 'name', 'role']);
```

**Commit:** `e49879d` — `fix(45-01): alinha filtro de users /performance ao widget Dashboard via user_setores->cargos`

**Zero regressão:** 18 testes passaram (Phase37CompaniesPerformanceFilterTest + Phase37ServicoSetorTest).

---

### Tarefa 2 — Smoke Bymobille #298 em adman_metrics

**Status:** PENDENTE — operador deve rodar `php artisan dev:bymobille-smoke` em produção

**Motivo do pendente:** MariaDB local corrompido desde 2026-06-25 (memory `project_mariadb_local_corrompido`). Consultas não podem ser executadas em dev.

**Artefatos entregues:**
- `app/Console/Commands/BymobilleSmoke.php` — comando Artisan `dev:bymobille-smoke` com 4 consultas diagnósticas e lógica de decisão automática
- `.planning/phases/45-compatibilidade-ml-em-m-tricas-unifica-o-widget-desempenho/45-01-BYMOBILLE-SMOKE.md` — template de resultado + lógica de decisão documentada

**Commit:** `aa29c5b` — `docs(45-01): smoke Bymobille #298 em adman_metrics — PENDENTE`

**Hipótese mais provável:** Bymobille é empresa ML-only (`adman_account_id` = NULL), portanto `total = 0` em `adman_metrics` → Plans 45-02/03 **NECESSÁRIAS**.

---

## Verificação de critérios de sucesso

| Critério | Status |
|----------|--------|
| Filtro users canônico em `PerformanceController::index()` | OK |
| `grep "user_setores as us" PerformanceController.php` >= 1 | OK (2 ocorrências) |
| Filtro legado `whereIn(role, consultor)` removido de `index()` | OK |
| Comando Artisan `dev:bymobille-smoke` criado e registrado | OK |
| `45-01-BYMOBILLE-SMOKE.md` com template + decisão documentada | OK |
| 2 commits atômicos em pt-BR | OK (e49879d + aa29c5b) |
| Suite de testes — 18 passed, 0 failures | OK |

---

## Commits gerados

| SHA | Descrição |
|-----|-----------|
| `e49879d` | fix(45-01): alinha filtro de users /performance ao widget Dashboard via user_setores->cargos |
| `aa29c5b` | docs(45-01): smoke Bymobille #298 em adman_metrics — PENDENTE (DB local corrompido, rodar em prod) |

---

## Próximos passos

1. **Operador:** rodar `php artisan dev:bymobille-smoke` no VPS de produção
2. **Se output = total=0 (NECESSÁRIAS):** executar Plans 45-02 e 45-03 (MlMetricsProvider + PerformanceScoreService)
3. **Se output = total>0, recentes (NÃO NECESSÁRIAS):** investigar PortfolioScoreService e join/cust_id; Phase 45 pode fechar
4. Preencher template em `45-01-BYMOBILLE-SMOKE.md` com resultado real
