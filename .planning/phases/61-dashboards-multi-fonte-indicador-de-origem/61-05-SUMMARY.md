---
phase: 61-dashboards-multi-fonte-indicador-de-origem
plan: 05
subsystem: dashboard-admin
tags: [frontend, dashboard, badge, source, multifonte, DASH-04, DATA-05]
requires:
  - 61-01 (payload backend enriquecido com stats.source_counts + companies_performance[].source)
  - 61-02 (componente SourceBadge)
provides:
  - Legenda visual multi-fonte no header do Dashboard/Admin.jsx
  - Badge de origem por linha na tabela de empresas
affects:
  - Dashboard admin (rota `/dashboard` renderizando `Dashboard/Admin.jsx`)
tech-stack:
  added: []
  patterns:
    - Guarda defensiva `sourceCounts &&` para backward compat quando flag UNIFIED_METRICS_ENABLED=false
    - Guarda `c.source &&` por linha da tabela (mesmo motivo)
    - Ordem canônica ML → Agregado → Adman → Sem integração (evita reshuffle por key order do JS)
key-files:
  created: []
  modified:
    - resources/js/Pages/Dashboard/Admin.jsx (+18/-0)
decisions:
  - Legenda posicionada dentro do container `ml-auto` do header, ANTES do badge Sync D-1, mantendo agrupamento visual dos indicadores no canto direito
  - `data-testid="dashboard-source-legend"` presente no wrapper para asserts do Plan 61-06
  - `stats?.source_counts ?? null` (não `undefined`) porque `null` é explicitamente falsy no guard e evita ambiguidade de "não veio" vs "veio vazio"
metrics:
  duration: ~10 min
  completed: 2026-07-07T17:11:25Z
---

# Phase 61 Plan 05: Dashboard/Admin.jsx multi-fonte (DASH-04 + DATA-05) Summary

Adição do indicador visual de origem multi-fonte no dashboard admin (`Dashboard/Admin.jsx`) via componente `SourceBadge` (criado em 61-02), consumindo o payload enriquecido em 61-01 — legenda agregada no header (a partir de `stats.source_counts`) + badge por linha na tabela de empresas (a partir de `companies_performance[].source`). Zero alteração no cálculo de KPIs, filtros ou colunas — apenas comunicação visual da procedência.

## Objetivo

Cumprir DASH-04 do ROADMAP no aspecto UX: os KPIs de topo do dashboard admin já somam empresas de todas as fontes (ML + Adman) num único número por natureza (soma sobre companies do escopo), mas antes desta plan o operador NÃO tinha como saber se aquilo era "só-Adman" (que exclui ML-only) ou realmente agregado. A Phase 61 costura essa comunicação visual sem tocar no cálculo. Complementarmente, DATA-05 avançou com badge por linha na tabela.

## Contexto

- Wave 2 de 3 na Phase 61 (executando em paralelo com 61-03 Companies/Show e 61-04 outro dashboard — arquivos disjuntos, sem risco de merge).
- Depende de: 61-01 (payload backend enriquecido) + 61-02 (componente SourceBadge).
- Consumido por: 61-06 (testes E2E que assertam `data-testid="dashboard-source-legend"`).

## O que foi feito

### Task 1 — Dashboard/Admin.jsx: legenda `source_counts` + SourceBadge por linha

**Arquivo alterado:** `resources/js/Pages/Dashboard/Admin.jsx` (+18 linhas, 0 removidas)

**Mudanças aplicadas:**

1. **Import** (linha 13):
   ```jsx
   import { SourceBadge } from '@/Components/ui/source-badge';
   ```

2. **Const `sourceCounts`** (linha 115, dentro do body do component):
   ```jsx
   const sourceCounts = stats?.source_counts ?? null;
   ```
   Guard `?? null` mantém backward compat quando o payload não enviou `source_counts` (flag OFF ou empty scope).

3. **Legenda no header** (dentro do `ml-auto`, antes do badge Sync D-1):
   ```jsx
   {sourceCounts && (
       <div className="flex items-center gap-2 text-[11px] text-white/50 flex-wrap" data-testid="dashboard-source-legend">
           <span className="uppercase tracking-wider">Fontes:</span>
           {sourceCounts.ml > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="ml" />{sourceCounts.ml}</span>}
           {sourceCounts.unified > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="unified" />{sourceCounts.unified}</span>}
           {sourceCounts.adman > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="adman" />{sourceCounts.adman}</span>}
           {sourceCounts.none > 0 && <span className="inline-flex items-center gap-1"><SourceBadge variant="none" />{sourceCounts.none}</span>}
       </div>
   )}
   ```
   Ordem canônica: ML → Agregado → Adman → Sem integração (prioriza fontes ricas primeiro).

4. **Badge por linha na tabela** (dentro do wrapper de `c.name`, imediatamente após `CustIdInvalidoBadge`):
   ```jsx
   {c.source && <SourceBadge variant={c.source} />}
   ```

## Aceite

| Critério                                              | Esperado | Obtido | Status |
|-------------------------------------------------------|----------|--------|--------|
| `grep -c "import { SourceBadge }"`                    | == 1     | 1      | OK     |
| `grep -c "SourceBadge variant"`                       | >= 5     | 5      | OK     |
| `grep -c "sourceCounts"`                              | >= 4     | 7      | OK     |
| `grep -c "dashboard-source-legend"`                   | == 1     | 1      | OK     |
| `git diff | grep -c "^-[^-]"` (linhas removidas)      | == 0     | 0      | OK     |
| `git diff | grep -c "^+[^+]"` (linhas adicionadas)    | <= 25    | 17     | OK     |
| `npm run build` exit code                             | 0        | 0      | OK     |

**Build:** `built in 13.76s` sem erros.

## Verificação manual (backward compat)

- Flag OFF (`UNIFIED_METRICS_ENABLED=false`): payload não envia `stats.source_counts` nem `companies_performance[].source` → `sourceCounts` fica `null`, `c.source` fica `undefined` → dashboard renderiza IDÊNTICO ao pré-Phase-61 (nenhuma legenda, nenhum badge por linha). Confirmado por leitura de código; asserts E2E cobertos por 61-06.
- Flag ON: legenda aparece no canto direito do header, cada linha da tabela ganha badge de fonte ao lado do nome (e do `CustIdInvalidoBadge` quando aplicável).

## Deviations from Plan

None — plan executado exatamente como escrito. Nenhum bug encontrado, nenhuma funcionalidade crítica ausente, nenhum bloqueador. Diff dentro do orçamento (17 linhas + de um cap de 25).

## Threat Flags

Nenhum novo threat flag introduzido. Payload já enriquecido em 61-01; esta plan apenas consome UI-side. Todas as mitigações do threat register (T-61-05-01/02/03) foram aplicadas:
- T-61-05-01 (Tampering — chaves ausentes): guards `sourceCounts?.ml > 0` etc.
- T-61-05-02 (Availability — ordem visual): ordem canônica declarada no JSX.
- T-61-05-03 (Information Disclosure): apenas contagens agregadas por variante, nenhum cust_id / PII.

## Known Stubs

Nenhum.

## Self-Check: PASSED

- FOUND: `resources/js/Pages/Dashboard/Admin.jsx` (arquivo modificado, +18/-0)
- FOUND: `.planning/phases/61-dashboards-multi-fonte-indicador-de-origem/61-05-SUMMARY.md` (este arquivo)
- Build verde confirmado (`built in 13.76s`)
- Todos os critérios de aceite marcados OK
