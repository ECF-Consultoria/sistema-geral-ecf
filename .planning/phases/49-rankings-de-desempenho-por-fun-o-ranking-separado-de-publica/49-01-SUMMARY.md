---
phase: 49-rankings-de-desempenho-por-fun-o-ranking-separado-de-publica
plan: "01"
status: done
executed_at: 2026-06-30
---

# SUMMARY — Plan 49-01

## Objetivo

Adicionar tabs de filtro por cargo (Geral / Analistas / Estrategistas) na página `/performance`,
exclusivamente no modo consultoria. Backend filtra `$ranking` pós-cálculo via `cargo_slug`.
Frontend exibe grupo de 3 botões toggle no header.

## Tarefas executadas

### Tarefa 1 — Testes RED (commit `a34d8f7`)

- Criado `tests/Feature/PerformanceCargoFilterTest.php` com 6 testes cobrindo todos os cenários
- Confirmado RED: 3 testes falhavam (filtro analista, filtro estrategista, prop cargo ausente)
- Estratégia: admin com `role='admin'` (short-circuit `isAdmin()`) + users vinculados via `user_setores → cargos`

### Tarefa 2 — Backend GREEN (commit `35b4150`)

Arquivo: `app/Http/Controllers/PerformanceController.php`

- Adicionado `$cargo = $request->get('cargo')` após leitura do `$period`
- Sanitização: valores fora de `['analista', 'estrategista']` (incluindo `'geral'` e inválidos) → `null`
- Filtro pós-cálculo via `$ranking->filter(fn ($r) => $r['cargo_slug'] === $cargo)->values()` (3 linhas)
- Prop `'cargo' => $cargo` adicionada ao `Inertia::render()`
- **Zero mudança no `PortfolioScoreService`**
- **Zero mudança em `indexPolos()`**
- Testes: 6/6 GREEN

### Tarefa 3 — Frontend (commit `65a67d5`)

Arquivo: `resources/js/Pages/Performance/Index.jsx`

- Prop `cargo = null` adicionada à assinatura do componente `PerformanceIndex`
- Grupo de 3 botões `Geral | Analistas | Estrategistas` inserido no header, após o seletor
  Consultoria|Publicações e antes do SelectBox de período
- Guard `{!isPolos && (...)}` — botões não aparecem no setor Publicações
- Botão ativo: `bg-ecf-yellow/[0.12] text-ecf-yellow` (mesmo padrão dos botões existentes)
- Click: `applyFilter()` via `router.get()` com `preserveState: true`
- Botão "Geral" remove `cargo` da query string (passa apenas `{ setor, period }`)

### Tarefa 4 — Build + smoke (sem commit de artefatos)

- `npm run build` — verde, 5079 módulos transformados, zero erros
- `php artisan test tests/Feature/PerformanceCargoFilterTest.php` — 6/6 GREEN após build

## Commits

| SHA       | Mensagem                                                                 |
|-----------|--------------------------------------------------------------------------|
| `a34d8f7` | test(49-01): testes RED para filtro ?cargo em /performance               |
| `35b4150` | feat(49-01): adiciona filtro ?cargo ao PerformanceController::index()    |
| `65a67d5` | feat(49-01): adiciona tabs Geral/Analistas/Estrategistas em /performance  |

## Resultado dos testes

```
PASS  Tests\Feature\PerformanceCargoFilterTest
✓ sem cargo retorna ranking completo           (2.20s)
✓ cargo analista retorna apenas analistas      (1.51s)
✓ cargo estrategista retorna apenas estrateg.  (1.71s)
✓ cargo invalido ignorado retorna ranking comp (1.69s)
✓ cargo geral retorna ranking completo         (1.63s)
✓ prop cargo propagada no inertia              (4.66s)

Tests: 6 passed (20 assertions)
```

## Desvios

- **Nenhum desvio estrutural.** O fluxo seguiu exatamente o prescrito no PLAN.md.
- Durante os testes RED, 3 de 6 testes falhavam (esperado). O 4º (cargo inválido) e 5º (cargo=geral)
  passaram imediatamente pois o controller já ignorava parâmetros não reconhecidos — comportamento correto.
- O helper `cargoDaResposta()` precisou de ajuste: `?? 'CHAVE_AUSENTE'` coalescia `null` para a sentinela.
  Corrigido para `array_key_exists()` — sem impacto na lógica de negócio.

## Confirmações

- `PerformanceController::indexPolos()` — **não tocado**
- `PortfolioScoreService` — **não tocado**
- Prop `setor` no Inertia — **não alterada** (continua `'consultoria'`)
- Design tokens usados: `bg-ecf-yellow/[0.12]`, `text-ecf-yellow`, `border-white/[0.08]` — padrão ECF

## Próximo

Wave 2 → `49-02-PLAN.md` (rota /publicacao/desempenho + sidebar entry)
