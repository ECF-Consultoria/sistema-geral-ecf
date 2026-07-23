# Phase 110: Fix margem Adman (fallback local determinístico + blindar congelamento) — Context

**Gathered:** 2026-07-23
**Status:** Ready for planning
**Source:** /gsd:debug margem-adman-diff-instavel (root cause) + probe de cobertura local

<domain>
## Phase Boundary

Corrigir a instabilidade da nota de MARGEM do bônus de desempenho causada por rate-limit 429 na leitura AO VIVO da Adman. Fonte da verdade: a sessão de debug `.planning/debug/margem-adman-diff-instavel.md` (root cause confirmado).

**Entra no escopo:**
- Inverter a prioridade em `AdmanMetricDiffService::resolveField()` para `contribution_margin_pct` (e afins): preferir o `calculated_fallback` LOCAL determinístico quando a cobertura local for suficiente; usar o `.diff` nativo ao vivo só como último recurso.
- Gate de cobertura mínima antes de aceitar/necessitar a leitura ao vivo.
- Blindar o congelamento mensal (`ConsolidarMesDesempenho`) com retry/reconciliação de qualidade antes de persistir o snapshot — é o snapshot que PAGA o bônus.

**Fora do escopo:**
- Não mudar a régua de margem nem a fórmula da nota (Fase 109/anteriores).
- Não mexer no `MetricPeriodResolver`.
- Não resolver o rate-limit 429 na origem (concorrência entre MLB syncs e Adman) — é frente maior; aqui tornamos o cálculo RESILIENTE a ele.
- Não tocar no caminho Shopee (ShopeeMetricDiffService não tem esse problema — não faz HTTP ao vivo).
</domain>

<decisions>
## Root cause (confirmado no debug — NÃO reabrir)

- **NÃO é lag de assentamento da Adman.** Testado: 3 chamadas por empresa + 2 passadas do agregado (~3min) deram valores IDÊNTICOS. O dado não flutua.
- **É falha transitória por rate-limit 429.** `computeVarMargem()` faz loop SEQUENCIAL de ~26 chamadas HTTP síncronas à Adman; processos concorrentes (`[MLB SyncTodasVendas]`, `[MLB SyncPub]`) batem na MESMA API-key Adman (30.388 429s nos logs). Quando coincidem, 1+ empresas falham fail-open → `null` naquela passada → saem de `n_com_margem_real` → a média muda dezenas de pontos (+6,83 → −3,25 → +8,63).
- **Agravante:** `resolveField()` (AdmanMetricDiffService linhas ~200-218) SEMPRE prioriza o `.diff` nativo (ao vivo) sobre o `calculated_fallback` local quando `comparison_mode='previous_equal_length_window'`, mesmo com dado local já existente.

## Evidência que DE-RISCA o fix (probe 2026-07-23)

Cobertura de `contribution_margin` LOCAL em `adman_metrics` para junho, empresas adman das carteiras afetadas:
- **Luiz (user 3): 643/644 dias-com-margem (99,8%)** — só 1 dia faltando (KAMATZUSC 27/28). As "0/0" são empresas sem NENHUMA linha (sem venda/teste), não buraco de margem.
- **Danilo (user 15): 504/504 (100%)**.
- O `adman:sync-margem` (dedicado, desde 10/07 — ver [[project_adman_margem_oauth_ml_sync]]) restaurou a margem local de junho praticamente completa. **Logo, o fallback local é confiável para junho** → inverter a prioridade é seguro.

## Gravidade / prazo

- HOJE o bônus de junho lê um snapshot CONGELADO (snapshot-first em PerformanceController/RelatorioBonificacaoController) → volatilidade ao vivo não afeta o número atual.
- MAS o congelamento oficial roda em **31/07 14h BRT** (`desempenho:consolidar-mes`, `lastDayOfMonth`) e SOBRESCREVE o snapshot com UMA única passada `compute()` ao vivo, sem retry. **Fechar o fix ANTES de 31/07 14h.**

## Decisão de escopo (usuário 2026-07-23)
Fix COMPLETO: (a) preferir fallback local + (c) gate de cobertura + (b) retry/reconciliação no congelamento. Recomendação do debugger.
</decisions>

<canonical_refs>
## Canonical References

- `.planning/debug/margem-adman-diff-instavel.md` — root cause completo, evidência, 3 direções de fix.
- `.planning/debug/resolved/audit-margem-luiz-ana.md` — histórico do cutover ML (contexto).
- `app/Services/Metrics/AdmanMetricDiffService.php` — `resolveField()` (gate .diff nativo vs calculated_fallback, ~200-218); `fallbackMargemPct()`/`somasComGuards()` (guards de dias-com-margem já existentes); cache 24h.
- `app/Services/DesempenhoScoreService.php` — `computeVarMargem()` (~1079, loop sequencial + média não-ponderada).
- `app/Console/Commands/ConsolidarMesDesempenho.php` — congela snapshot mensal (uma passada compute(), sem retry).
- `app/Console/Commands/SyncAdmanMargem.php` — fonte do dado local restaurado.
- `app/Models/DesempenhoScoreSnapshot.php` — `updateOrCreate([user_id, mes_referencia])`.
- `routes/console.php` (~235-240) — schedule `lastDayOfMonth('14:00')`.
</canonical_refs>

<specifics>
## Direções de fix (implementar)

- **(a) Inverter prioridade da margem**: em `resolveField()` (ou wrapper específico para `contribution_margin_pct`), quando o `calculated_fallback` local tiver cobertura suficiente, USAR o fallback (determinístico) em vez do `.diff` nativo ao vivo. Reaproveitar os guards de dias-com-margem já cicatrizados (`somasComGuards`/`fallbackMargemPct`).
- **(c) Gate de cobertura**: definir "cobertura suficiente" (ex.: X% dos dias da janela current E baseline com `contribution_margin` não-nulo). Se local insuficiente E ao-vivo falhou → tratar como null explícito (não fail-open silencioso que polui a média).
- **(b) Retry/reconciliação no congelamento**: `ConsolidarMesDesempenho` não deve persistir um snapshot cujo componente de margem veio de passada com falhas de rede — retry por empresa que falhou, ou recusar congelar/alertar se a qualidade da amostra for baixa. É o ponto que PAGA.

## Armadilhas
- Bump da cacheKey do desempenho se o comportamento do compute() mudar (hoje v10 — ver [[project_cache_version_hardcoded_nos_testes]], atualizar strings de teste juntas).
- Não confundir "empresa sem linha adman_metrics" (0/0, sem venda) com "margem faltando" — a primeira é legitimamente sem dado.
- Regressão: não mudar números de quem já está estável; a mudança deve APROXIMAR o valor do determinístico local (que já bate com a dashboard Adman), não introduzir novo viés.
</specifics>

<deferred>
## Deferred
- Resolver o rate-limit 429 na origem (serializar/rate-limit os MLB syncs vs Adman, ou key/pool separada) — frente maior, separada.
- Cobertura do 1 dia faltante de KAMATZUSC / empresas sem baseline de maio — caso menor, o gate de cobertura cobre.
</deferred>

---

*Phase: 110-fix-margem-adman-preferir-fallback-local-deterministico-blin*
*Context: 2026-07-23*
