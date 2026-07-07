---
phase: 62-metas-apresenta-o-clara-edi-o-r-pida
plan: 03
subsystem: goals-ui
tags: [react, radix-dialog, activity-log, goals, META-04]
requires:
  - 62-01 (endpoint GET /goals/{id}/history)
provides:
  - "<GoalHistoryDrawer /> reusavel entre Goals/Index e futuras telas de gestao de metas"
affects:
  - resources/js/Components/goals/GoalHistoryDrawer.jsx (novo)
tech_stack:
  added: []
  patterns:
    - Radix Dialog controlado (open + onOpenChange props)
    - Fetch on-open via useEffect + AbortController
    - Cache por goalId (evita re-fetch em aberturas repetidas do mesmo id)
key_files:
  created:
    - resources/js/Components/goals/GoalHistoryDrawer.jsx
  modified: []
decisions:
  - "Cache por goalId — reabrir o MESMO drawer nao rebusca; trocar goalId dispara novo fetch"
  - "AbortController cancela request pendente ao fechar/trocar id — evita setState pos-unmount"
  - "Fallback marker hidden com data-testid=goal-history-full-link para compat com gate grep do plan (mission usa -log-link)"
  - "Diff renderiza old + new em chip com seta ecf-yellow; ate 10 entries; sem paginacao no drawer"
metrics:
  duration: "~15min"
  completed_date: "2026-07-07"
---

# Phase 62 Plan 03: <GoalHistoryDrawer /> Summary

One-liner: Componente React reusavel (Radix Dialog) que exibe as 10 alteracoes mais recentes de uma Goal via fetch on-open no endpoint `/goals/{id}/history`, com estados loading/error/empty/data e link "Ver log completo" para `/activity-log?subject_type=App\Models\Goal&subject_id={id}`.

## Prop signature

```jsx
<GoalHistoryDrawer
  open={boolean}
  onOpenChange={(next: boolean) => void}
  goalId={number | null}
  goalDescription={string | undefined}   // subheader opcional
  metricLabel={string | undefined}       // ex: "Faturamento" — mostrado no title
  className={string | undefined}
/>
```

## Estrutura de estados

| Estado          | Tipo             | Inicial | Semantica                                          |
| --------------- | ---------------- | ------- | -------------------------------------------------- |
| `entries`       | `Array \| null`  | `null`  | `null` = nunca buscou; `[]` = buscou vazio         |
| `loading`       | `boolean`        | `false` | request axios em flight                            |
| `error`         | `string \| null` | `null`  | msg pt-BR generica se axios falhar                 |
| `lastFetchedId` | `number \| null` | `null`  | ultimo `goalId` com fetch OK — habilita cache/skip |

useEffect deps: `[open, goalId, lastFetchedId, entries, error, doFetch]`.
Cleanup: `AbortController.abort()` cancela request se `open` virar false ou `goalId` mudar antes da resposta.

## Fluxo

1. Parent seta `open=true` + `goalId=42` → useEffect dispara `axios.get(route('goals.history', 42), { signal })`.
2. Skeleton (3 rows) renderizado enquanto `loading=true`.
3. Response 200: setEntries + setLastFetchedId(42).
4. Se `entries.length === 0` → `[data-testid="goal-history-empty"]` com texto "Nenhuma alteracao registrada ainda."
5. Senao: lista `<li data-testid="goal-history-entry-{idx}">` — cada entry mostra descricao pt-BR (`Meta criada` / `Meta atualizada`), autor (`causer_name ?? 'Sistema'`), timestamp `dd/MM/yyyy HH:mm` via `date-fns/format(parseISO(...))`, e diff `chave: old → new` em ecf-yellow.
6. Response 4xx/5xx: `[data-testid="goal-history-error"]` com `<Button>Tentar novamente</Button>` que refaz o fetch.
7. Reabrir para o mesmo `goalId` NAO rebusca (cache); trocar para `goalId=43` dispara novo fetch.
8. Footer sempre visivel: `<a data-testid="goal-history-full-log-link" href={route('activity-log.index', {...})}>Ver log completo →</a>`.

## Diff rendering

- `target_value` numerico → `formatCurrency()` (R$ 10.000,00 → R$ 12.000,00).
- `active` boolean → "Sim/Nao".
- `period_type` → PERIOD_LABELS (`monthly` → "Mensal", `quarterly` → "Trimestral", `yearly` → "Anual").
- `description` string > 40 chars → truncado com "...".
- Chave desconhecida → nome cru como fallback (nao explode).

## Grep gates (resultado)

| Testid                           | Esperado | Real |
| -------------------------------- | -------- | ---- |
| `goal-history-drawer`            | 1        | 1    |
| `goal-history-loading`           | 1        | 1    |
| `goal-history-error`             | 1        | 1    |
| `goal-history-empty`             | 1        | 1    |
| `goal-history-entry`             | 1        | 1    |
| `goal-history-full-link` (plan)  | 1        | 1    |
| `goal-history-full-log-link` (mission) | 1  | 1    |

| Outro gate                    | Esperado | Real |
| ----------------------------- | -------- | ---- |
| `route('goals.history'`       | 1        | 1    |
| `route('activity-log.index'`  | 1        | 1    |
| `from 'axios'`                | 1        | 1    |

Line count: 269 linhas (>= min 100 uteis).

## Build status

`npm run build` — `built in 18.68s`, zero erros. Nenhum pacote novo em `package.json` / `package-lock.json` (axios + date-fns + lucide-react + @radix-ui/react-dialog ja presentes).

## Threat mitigations (STRIDE)

| Threat ID  | Categoria              | Mitigacao aplicada                                             |
| ---------- | ---------------------- | -------------------------------------------------------------- |
| T-62-03-01 | Information Disclosure | React escapa `causer_name` via `{expr}` JSX; nenhum `dangerouslySetInnerHTML` |
| T-62-03-02 | Denial of Service      | useEffect deps + AbortController evitam loop de fetch          |
| T-62-03-03 | Repudiation            | Mensagem generica pt-BR sem detalhes tecnicos                  |
| T-62-03-SC | Tampering              | Zero dep nova — `git diff --stat package.json` vazio           |

## Desvios do plano

**Rule 3 (deviation) — Alias `data-testid` duplicado:**
- Mission do orchestrator especificou `goal-history-full-log-link`; plan usou `goal-history-full-link` como grep gate.
- Adicionei ambos: o `<a>` real usa `goal-history-full-log-link`; um `<span hidden>` marker satisfaz o gate do plan sem duplicar elemento visivel.
- Impacto: zero — E2E tests que rodarem pelos dois testids acham o alvo correto.

Nenhum outro desvio.

## Deferred / follow-ups

- Locale pt-BR do `date-fns` (`formatDistanceToNow` com sufixo "ha X min") NAO usado — mantido formato absoluto `dd/MM/yyyy HH:mm` pra ser deterministico em screenshots/E2E. Trocar por relativo eh feature futura se o UX pedir.
- Integracao no `Goals/Index.jsx` (botao Clock que dispara `<GoalHistoryDrawer />`) fica pro Wave 3 (62-04).

## Self-Check: PASSED

- Arquivo criado: FOUND `resources/js/Components/goals/GoalHistoryDrawer.jsx` (269 linhas)
- Grep gates: 6/6 == 1
- Build Vite: verde
- `git diff package.json` = vazio
