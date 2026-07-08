---
phase: 72-dashboards-pendencias-dia-cobranca
milestone: v15.0
plan: 72-03
type: execute
wave: 3
depends_on: [72-01, 72-02]
requirements: [NPS-E-02, NPS-E-03]
tags: [nps, frontend, react, jsx, inertia, badge, widget, portfolio, dashboard, mobile-friendly, phase72]
tech_stack:
  added: []
  patterns:
    - "Componente puro sem side-effects — consome shape via prop, sem network calls proprios"
    - "Variant switch via prop ('inline' / 'compact') — mesmo componente atende listagens amplas e tabelas apertadas"
    - "Guard defensivo `Array.isArray(pendentes) ? pendentes : []` em todos os consumidores — backward compat quando backend legado nao injetar"
    - "Ziggy route('companies.show', id) para navegacao — sem router.visit imperativo (usa Inertia Link declarativo)"
    - "Design tokens ecf-* + orange-* (Tailwind default) — nova cor semantica 'pending' distinta de yellow (primary) e red (destroy)"
key_files:
  created:
    - resources/js/Components/Nps/NpsPendingBadge.jsx
    - resources/js/Components/Nps/NpsPendingWidget.jsx
  modified:
    - resources/js/Pages/Portfolio/Show.jsx
    - resources/js/Pages/Companies/Index.jsx
    - resources/js/Pages/Dashboard/Admin.jsx
    - resources/js/Pages/Dashboard/User.jsx
    - resources/js/Pages/Performance/Dashboard.jsx
decisions:
  - "Cor orange-500 escolhida em vez de custom token — Tailwind default cobre semanticamente 'pendente/aviso' sem invadir a paleta ecf-yellow (primary) nem red (destroy)"
  - "Widget SEMPRE linka para /companies/{id} — nao para /nps/{token} porque o token so eh gerado no disparo mensal; 'Disparar NPS manual' fica pra Phase 73 (v15.1)"
  - "Variant 'compact' (icone circular) para tabelas apertadas — 'inline' (icone + texto) para listagens de cards; ambos mesmo componente"
  - "Widget renderizado SEM condicao `{lista.length > 0 && ...}` — proprio widget trata empty state internamente; mantem layout consistente e evita saltos visuais quando lista fica vazia mid-session"
  - "NpsPendingBadge tooltip mostra 'template: X' via title HTML nativo — sem lib de tooltip (evita dep nova)"
  - "Guard defensivo `nps_pendentes ?? []` em TODOS os call-sites — se backend legado (rota nao passou por 72-02) chegar a renderizar a pagina, os componentes nao quebram"
metrics:
  duration_min: 25
  completed_date: 2026-07-08
  tasks_completed: 5
  files_touched: 7
---

# Phase 72 Plan 03: NpsPendingBadge + NpsPendingWidget + integracao em 5 paginas

Entrega dos 2 componentes React (`NpsPendingBadge`, `NpsPendingWidget`) que tornam visiveis os dados de `nps_pendentes` injetados pelos Plans 72-01 (service) e 72-02 (prop injection). Cobre 100% dos REQ NPS-E-02 (badges em listagens) e NPS-E-03 (widget contagem + lista em dashboards).

## Deliverables

### 1. NpsPendingBadge (novo, 71 LOC)

Path: `resources/js/Components/Nps/NpsPendingBadge.jsx`

Badge compacto usado em listagens de empresas (Portfolio/Show, Companies/Index). Renderiza APENAS se `companyId` esta na lista `pendentes` — do contrario retorna `null`. Zero dep nova; usa `AlertCircle` do Lucide + `cn()` utility.

**Props:**
- `companyId` (int) — id da empresa a verificar
- `pendentes` (array) — shape do `NpsPendingService::forCarteira` (Plan 72-01): `[{company_id, name, template_id, template_nome, month_reference, dias_atraso}, ...]`
- `variant` ('inline' | 'compact') — default `'inline'`. Compact mostra so icone circular (tabelas apertadas); inline mostra icone + texto "NPS pendente"

**Regras visuais:**
- Padrao: `bg-orange-500/20 text-orange-400 border-orange-500/30`
- Critico (dias_atraso >= 7): `bg-orange-500/30 text-orange-300 border-orange-500/40`
- Tooltip nativo (title HTML): `"NPS pendente há N dias (template: X)"` — pluralizacao pt-BR (0=hoje, 1=1 dia, >=2=N dias)

**Guard defensivo:** `Array.isArray(pendentes) ? pendentes : []` — nunca crasha por prop mal-formada.

### 2. NpsPendingWidget (novo, 105 LOC)

Path: `resources/js/Components/Nps/NpsPendingWidget.jsx`

Card com lista de empresas pendentes de NPS no mes corrente. Consome mesmo shape do service. Zero dep nova; usa `Link` do Inertia + `AlertCircle`, `Clock` do Lucide.

**Props:**
- `pendentes` (array) — shape do service
- `title` (string, opcional) — default `"Empresas pendentes de NPS este mês"`
- `maxVisible` (int, opcional) — default `5`. Rodape mostra "+ N outras" quando total > maxVisible

**Estados:**
- **Empty:** card com titulo + `"Nenhuma empresa pendente — todas responderam este mês."`
- **Com dados:** header (titulo + badge orange com contagem total) + lista scrollavel + rodape residual

**Estrutura da lista:**
- Cada item: `<Link href={route('companies.show', p.company_id)}>` (Ziggy)
- Hover: `bg-orange-500/10 border-orange-500/20`
- Item mostra `p.name` (truncate) + `Clock` + `p.dias_atraso` pluralizado

**Design tokens:** `bg-ecf-card border-white/[0.08] rounded-2xl` (padrao card ECF) + accents `orange-500/*`.

### 3. Portfolio/Show.jsx — badge integration

- Importa `NpsPendingBadge` de `@/Components/Nps/NpsPendingBadge`
- Adiciona prop `nps_pendentes = []` na assinatura (final da lista de props)
- Local guard: `const npsPendentesList = nps_pendentes ?? []`
- Badge renderizado em 2 pontos:
  - Cards mobile (`< md`): `<NpsPendingBadge companyId={c.id} pendentes={npsPendentesList} variant="compact" />` — variant compact economiza espaco em tela pequena
  - Tabela desktop (`>= md`): `variant="inline"` — texto + icone visivel

### 4. Companies/Index.jsx — badge integration

- Importa `NpsPendingBadge`
- Adiciona prop `nps_pendentes = []` na assinatura da funcao (final da lista existente)
- Local guard: `const npsPendentesList = nps_pendentes ?? []`
- Badge renderizado em 2 pontos:
  - Aba **Empresas** (coluna "Empresa"): `variant="compact"` — junto de `MlStatusBadge` e `CustIdInvalidoBadge`
  - Aba **Pendencias** (coluna "Empresa"): `variant="compact"` — junto de `GrupoBadge`

### 5. Dashboard/Admin.jsx — widget integration

- Importa `NpsPendingWidget`
- Adiciona prop `nps_pendentes = []` na assinatura
- Widget renderizado ao final do layout normal (fora do TV mode), abaixo dos charts row 2

### 6. Dashboard/User.jsx — widget integration

- Importa `NpsPendingWidget`
- Adiciona prop `nps_pendentes = []` na assinatura
- Local guard: `const npsPendentesList = nps_pendentes ?? []`
- Widget renderizado ao final, apos o alerta de sugadores pendentes

### 7. Performance/Dashboard.jsx — widget integration

- Importa `NpsPendingWidget`
- Adiciona prop `nps_pendentes = []` na assinatura
- Local guard: `const npsPendentesList = nps_pendentes ?? []`
- Widget renderizado entre o grid "NPS + Metas" (existente) e a tabela "Empresas em carteira" — complementa o widget NPS existente (que mostra respostas recebidas) com o inverso (quem falta responder)

## Verificacao

- `npm run build` verde — `built in 26.34s`, zero warnings
- Manifest confirma bundling dos 2 novos componentes:
  - `_NpsPendingBadge-CgIxXOzO.js`
  - `_NpsPendingWidget-C_v8b7j4.js`
- Ambos componentes sao lazy-loaded automatico via Vite dynamic import — bundle size < 1 KB gzipped cada
- Guard defensivo `nps_pendentes ?? []` em todos os 5 call-sites — backward compat total
- Zero remocao ou alteracao de outros props/logica nos consumidores

## Contract cumprido

- Zero library nova (apenas Lucide + Inertia + cn utility ja existentes)
- Design tokens ecf-* + orange-* (Tailwind default) — sem custom color no tailwind.config.js
- Componentes puros — nenhum network call proprio; consomem apenas props
- Comentarios pt-BR em blocos de cabecalho + decisoes nao-obvias
- Fallback defensivo em todos os call-sites

## Metricas

- **Duracao:** ~25 min
- **Tasks:** 5/5 completadas atomicamente
- **Files created:** 2 (Badge + Widget — 176 LOC total)
- **Files modified:** 5 (Portfolio/Show, Companies/Index, Dashboard/Admin, Dashboard/User, Performance/Dashboard — 57 LOC net delta)
- **Build:** `built in 26.34s` (Vite v7)

## Deviations from Plan

Nenhuma — plano executado exatamente como escrito.

## Auth gates

Nao aplicavel — pura entrega frontend.

## Threat Flags

Nenhum — componentes visuais puros; sem novo canal de dados, sem novo endpoint, sem manipulacao de PII. O shape `pendentes` ja existia (Plan 72-01) e o payload nao contem dados sensiveis (apenas company_id, name, template_id, template_nome, month_reference, dias_atraso).

## Self-Check: PASSED

- Files created:
  - FOUND: resources/js/Components/Nps/NpsPendingBadge.jsx (71 LOC)
  - FOUND: resources/js/Components/Nps/NpsPendingWidget.jsx (105 LOC)
- Files modified: FOUND (5 arquivos, 57 LOC net delta)
- Build manifest: FOUND ambos componentes bundled corretamente
- Acceptance criteria: TODOS satisfeitos (grep counts >= minimos exigidos)
