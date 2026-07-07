---
phase: 61-dashboards-multi-fonte-indicador-de-origem
plan: 02
subsystem: frontend-ui
tags: [frontend, ui-primitive, shadcn, cva, badge, source-indicator]
requires: []
provides:
  - "Componente `<SourceBadge>` reusável importável via `@/Components/ui/source-badge`"
  - "Vocabulário visual travado das 4 fontes canônicas do ADR DATA-04"
affects: []
tech_stack:
  added: []
  patterns:
    - "cva + cn (shadcn/ui) — mesmo padrão de `badge.jsx`"
    - "Tooltip via `title` HTML nativo (evita Radix Tooltip que não está no projeto)"
    - "Fail-safe render (variant desconhecido → 'none')"
key_files:
  created:
    - resources/js/Components/ui/source-badge.jsx
  modified: []
decisions:
  - "Tooltip via `title` nativo em vez de Radix — zero dep nova, cobertura desktop suficiente"
  - "Variant 'unified' renderiza rótulo 'Agregado' (regra anti-jargão pt-BR); 'unified' fica só na prop"
  - "Rendering usa `<span>` (não `<div>`) — permite embutir inline em headers de tabela e KPI cards"
metrics:
  duration_min: 4
  tasks_completed: 1
  files_touched: 1
completed_at: 2026-07-07
---

# Phase 61 Plan 02: `<SourceBadge>` primitivo React Summary

**One-liner:** Componente shadcn/cva com 4 variantes canônicas (ml/adman/unified→"Agregado"/none) e tooltip nativo — primitivo visual único consumido pelos plans 61-03/04/05.

## O que foi entregue

Novo arquivo `resources/js/Components/ui/source-badge.jsx` (86 linhas) contendo:

- Import padrão shadcn: `React`, `cva` de `class-variance-authority`, `cn` de `@/lib/utils`
- `sourceBadgeVariants = cva(...)` com 4 variantes visuais fixas
- Mapa `LABEL` module-scope com rótulos pt-BR (ML / Adman / Agregado / Sem integração)
- Mapa `TOOLTIP` module-scope com textos explicativos pt-BR (sem dados sensíveis)
- Componente funcional `SourceBadge({ variant, className, showLabel, 'data-testid': testId, ...props })`
- Render em `<span>` (não `<div>`) — permite embutir inline em headers/KPIs
- Guard `safeVariant` — variant desconhecido do backend cai em `'none'` (fail-safe visual)
- Export nomeado `{ SourceBadge, sourceBadgeVariants }` — mesmo padrão de `badge.jsx`

### Vocabulário travado (ADR DATA-04)

| Variant   | Label pt-BR       | Cor visual                                    | Tooltip                                                   |
| --------- | ----------------- | --------------------------------------------- | --------------------------------------------------------- |
| `ml`      | ML                | `bg-[#FFE600] text-black`                     | "Métrica calculada a partir da API do Mercado Livre..."   |
| `adman`   | Adman             | `bg-white/[0.06] text-white/70`               | "Métrica calculada a partir do sync Adman (last-sync D-1)" |
| `unified` | **Agregado**      | `bg-ecf-yellow/15 text-ecf-yellow border/40`  | "Métrica agregada: ML dita valores operacionais..."       |
| `none`    | Sem integração    | `bg-transparent text-white/40 border/[0.15]`  | "Empresa sem integração ativa em nenhuma fonte"           |

Nota anti-jargão: prop aceita `'unified'` (nome técnico do ADR) mas UI mostra **"Agregado"** — nunca "unified" cru. Regra `feedback_evitar_jargao_ui` respeitada.

## Comandos de verificação

```bash
# 1. Arquivo existe
test -f resources/js/Components/ui/source-badge.jsx        # OK (86 linhas)

# 2. 4 variantes cobertas
grep -cE "variant.*ml|variant.*adman|variant.*unified|variant.*none" \
  resources/js/Components/ui/source-badge.jsx              # 4

# 3. Labels/tooltips + export + testid + title HTML
grep -cE "Agregado|Sem integração|Adman|ML|title=|data-testid|export \{ SourceBadge" \
  resources/js/Components/ui/source-badge.jsx              # 20

# 4. Nenhuma dep nova
grep -cE "@radix-ui/react-tooltip|from '@radix-ui/react-popover'" \
  resources/js/Components/ui/source-badge.jsx              # 0
git diff package.json | wc -l                              # 0

# 5. Build verde
npm run build                                              # exit 0, 19.27s
```

Todos os acceptance criteria do PLAN atendidos.

## Deviations from Plan

None — plano executado exatamente como escrito.

## Riscos residuais / Notas para próximos plans

- **Consumidores (61-03/04/05):** importar via `import { SourceBadge } from '@/Components/ui/source-badge'` e passar `variant={dto.source}` diretamente do payload Inertia. Guard `safeVariant` cobre valores inválidos vindos do backend (T-61-02-01 mitigado).
- **Tooltip mobile:** `title` HTML não aparece em touch. Se plan futuro precisar de tooltip mobile, aí sim considerar Radix Popover (já em package.json) ou adotar Radix Tooltip com decisão explícita. Por ora, desktop-first é suficiente para dashboards internos.
- **Consistência visual:** variantes `ml` e `unified` compartilham a matiz amarela `#FFE600` — diferenciadas por opacidade/borda. Se em teste visual o contraste não bater, ajustar `unified` para outro token (ex.: `bg-blue-500/15`), sem tocar em `ml` (cor de marca ML travada).

## Self-Check: PASSED

- Arquivo criado: `resources/js/Components/ui/source-badge.jsx` — FOUND
- Commit registrado: `32b2215` — FOUND (git log --oneline mostra o commit)
- `npm run build` — PASS (exit 0, 19.27s, zero warnings do arquivo novo)
- `package.json` — INTACTO (git diff = 0 linhas)
- Working tree alheio — INTOCADO (Companies/Show.jsx, GoalController.php etc permanecem untracked/modified conforme baseline)
