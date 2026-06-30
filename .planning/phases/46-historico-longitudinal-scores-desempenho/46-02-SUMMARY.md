---
phase: 46-historico-longitudinal-scores-desempenho
plan: 46-02
status: complete
completed_at: 2026-06-30
wave: 2
type: execute
depends_on: ["46-01"]
requirements:
  - REQ-46-02
files_modified:
  - app/Http/Controllers/PerformanceController.php
  - routes/web.php
files_created:
  - tests/Feature/DesempenhoEvolucaoTest.php
commits:
  - 8e9c243 test(46-02) RED — testes de delta no ranking de PerformanceController
  - 9680034 feat(46-02) enriquece ranking de /performance com delta_vs_ontem e delta_vs_semana_passada
  - 644dd37 test(46-02) RED — endpoint GET /api/performance/{user}/evolucao
  - 7cc198b feat(46-02) endpoint GET /api/performance/{user}/evolucao com payload ordenado
tests:
  passed: 11
  failed: 0
  assertions: 51
provides:
  - "Ranking de /performance enriquecido com delta_vs_ontem e delta_vs_semana_passada (nullable float) por item"
  - "Endpoint JSON GET /api/performance/{user}/evolucao?period={7..365} com curva de score"
  - "Rota nomeada `performance.evolucao` gateada por permission:core.performance"
next:
  - "Wave 3 (46-03): UI — indicadores delta na coluna do score + drawer com gráfico Recharts consumindo o endpoint"
---

# Phase 46 Plan 46-02 — Leitura dos snapshots: SUMMARY

## Entregue

Camada de leitura do histórico longitudinal: `PerformanceController::index()` passa
a enriquecer cada item do ranking com `delta_vs_ontem` e `delta_vs_semana_passada`,
e o novo endpoint `GET /api/performance/{user}/evolucao` serve a curva de score
do user em JSON pronto para Recharts (Wave 3).

## Commits (4)

| SHA       | Tipo  | Mensagem                                                                                  |
| --------- | ----- | ----------------------------------------------------------------------------------------- |
| `8e9c243` | test  | RED — 5 testes da Tarefa 1 falham (campos delta ainda não existem)                        |
| `9680034` | feat  | Enriquece ranking com deltas (2 queries adicionais, sem N+1)                              |
| `644dd37` | test  | RED — 6 testes da Tarefa 2 falham (rota performance.evolucao ainda não existe)            |
| `7cc198b` | feat  | Método `evolucao()` + rota nomeada `performance.evolucao` com gate `core.performance`     |

## Arquivos modificados

- `app/Http/Controllers/PerformanceController.php` — +44 linhas (enrichment de deltas)
  +52 linhas (método `evolucao`) + imports `DesempenhoScoreSnapshot` e `JsonResponse`
- `routes/web.php` — +5 linhas (rota nova logo após `publicacao.desempenho.index`)

## Arquivos novos

- `tests/Feature/DesempenhoEvolucaoTest.php` — 11 testes, 51 assertions

## Resultado dos testes

```
PASS  Tests\Feature\DesempenhoEvolucaoTest
  ✓ delta null quando sem snapshot anterior
  ✓ delta vs ontem calculado corretamente
  ✓ delta vs semana passada usa mais recente dentro da janela
  ✓ delta vs ontem pode ser negativo
  ✓ delta vs ontem pega anterior disponivel se cron falhou
  ✓ evolucao retorna 200 com permission core performance
  ✓ evolucao retorna 403 sem permission
  ✓ evolucao serie ordenada asc por date
  ✓ evolucao respeita period query
  ✓ evolucao clamp period
  ✓ evolucao serie vazia quando sem snapshots

Tests:    11 passed (51 assertions)
Duration: 13.83s
```

### Regressão Wave 1 + Phase 49

```
PASS  Tests\Feature\DesempenhoScoreSnapshotTest  → 8/8 (Wave 1)
PASS  Tests\Feature\PerformanceCargoFilterTest   → 6/6 (Phase 49)
PASS  Tests\Feature\PublicacaoDesempenhoRouteTest → 4/4 (Phase 49)

Total agregado: Tests: 29 passed (110 assertions) — Duration: 27.82s
```

## Verificações de smoke

```
$ php artisan route:list --name=performance.evolucao
GET|HEAD  api/performance/{user}/evolucao
   ... performance.evolucao › PerformanceController@evolucao

  Showing [1] routes
```

Middleware aplicado: `web > auth > verified > permission:core.performance`
(idêntico ao gate de `/performance`).

## Shape do payload

`GET /api/performance/42/evolucao?period=30`

```json
{
  "user_id": 42,
  "period": 30,
  "serie": [
    {"date": "2026-06-01", "score": 65, "ranking_pos": 3},
    {"date": "2026-06-02", "score": 68, "ranking_pos": 2},
    {"date": "2026-06-30", "score": 75, "ranking_pos": 1}
  ]
}
```

Quando o user não tem snapshots: `"serie": []`.

## Enrichment no `/performance` (Inertia props)

Cada item do `ranking` passa a carregar:

```jsonc
{
  "id": 42,
  "name": "Fulano",
  "score": 75.0,
  // ... campos existentes ...
  "delta_vs_ontem": 5.0,            // novo — score_hoje − snapshot D-1 (ou mais recente strict <)
  "delta_vs_semana_passada": 12.0    // novo — score_hoje − snapshot mais recente ≤ today-7d
}
```

Ambos `null` quando o user ainda não tem snapshot anterior (caso comum pré-deploy
do schedule e nos primeiros 7 dias após o schedule entrar em produção).

## Decisões técnicas

1. **`whereDate` em vez de comparação string** — herdado da Wave 1: SQLite armazena
   o `date` casted como `'YYYY-MM-DD 00:00:00'`, portanto `where('ref_date', $str)`
   falha em ambos os drivers. `whereDate` funciona idêntico em MariaDB prod.

2. **Enrichment sem N+1** — 2 queries totais para deltas: uma para "ontem" e outra
   para "semana passada". Cada query usa `whereIn('user_id', $userIds)` + `orderBy
   ref_date DESC`, e `groupBy('user_id')->map(fn ($g) => $g->first())` mantém só o
   mais recente por user em memória. Compatível com qualquer driver SQL.

3. **Clamp do `period` no endpoint** — aceita 7..365 (default 30); valores não-numéricos
   (`?period=abc`) viram 30 via `is_numeric() ? (int) : 30`. Plan original cita 7/30/90/180
   na seção do CONTEXT, mas o limite implementado é o range 7..365 (compatível com
   research §5 pseudocódigo e mais permissivo — UI da Wave 3 só usará 30/90 mesmo).

4. **`round(..., 1)` nos deltas** — score é tinyint (0..100), então delta cabe num
   float com 1 casa decimal sem perder precisão. UI vai exibir com a mesma escala.

5. **Filtro de cargo aplicado APÓS o enrichment** — mantém compatibilidade com
   Phase 49 (`?cargo=analista`); deltas ficam anexados a todos os itens antes do
   `filter()`, então a página filtrada continua mostrando os deltas corretos.

## Desvios do plano

Nenhum desvio funcional. Pequeno ajuste de range do `period`:

- **Plan original:** clamp `7..365` (na seção `<behavior>` da Tarefa 2). Implementado
  exatamente assim.
- O CONTEXT menciona "default 30" e a UI da Wave 3 só vai expor 7/30/90/180 como
  presets, mas o endpoint aceita qualquer valor no range 7..365 — flexível para
  experimentos futuros sem perder a proteção contra valores absurdos.

## success_criteria — status

- [x] `PerformanceController::index()` enriquece cada item do ranking com `delta_vs_ontem` e `delta_vs_semana_passada`
- [x] Enrichment usa no máximo 2 queries adicionais (sem N+1)
- [x] Quando não há snapshot anterior, deltas são `null` (não 0, não exception)
- [x] Novo método público `evolucao()` retorna `JsonResponse` com payload `{user_id, period, serie}`
- [x] Série ordenada ASC por date; `ranking_pos` incluído por ponto
- [x] Rota `GET /api/performance/{user}/evolucao` registrada com `permission:core.performance` e name `performance.evolucao`
- [x] Period clamp: min 7, max 365, default 30
- [x] Testes PHPUnit GREEN: 5 deltas + 6 endpoint = 11 testes (51 assertions)
- [x] Sem regressão em testes de Phase 49 nem em Wave 1 (29/29 agregados)
- [x] Commits separados RED → GREEN para cada bloco TDD

## Próximo

Wave 3 — Plan 46-03: UI dos deltas (micro-indicadores `↑ +2.3 / ↓ -1.1`) na
coluna do score + drawer/modal "Evolução individual" com `LineChart` do Recharts
consumindo `route('performance.evolucao', user.id) + '?period=30'`.
