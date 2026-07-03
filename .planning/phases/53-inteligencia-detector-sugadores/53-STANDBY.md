---
phase: 53-inteligencia-detector-sugadores
status: standby-observacao
paused_at: 2026-07-03
---

# Phase 53 — Stand-by (em observação)

Detector de sugadores requer múltiplos ciclos de análise diária (cron 12h BRT) para validar organicamente os fixes. Operador optou por deixar em stand-by ao invés de aprovar/reprovar antes de ter dados suficientes.

## O que foi entregue em prod

- **Wave 1** (commits `1ece0ba` → `823157c`): cache 10min em `listCampaigns` + `fetchItemStatus` (1h) + filtro MLB `paused/closed/under_review` (fix B1 CAMILLO)
- **Wave 2** (commits `f64e57b` → `2b20251`): `sold_global` no payload + filtro `sold_global >= 10` remove `gasto_sem_venda` (fix B2 BARAOSHOP + B3 DINMAP)
- **Wave 4 hotfix** (commit `7b82501`): `campaign_name` persistido corretamente + `mlbsHint` fallback para `sugador.mlb_id`
- 17/17 testes unit Phase 53 verdes, zero regressão

## O que fica em observação

**Verificar organicamente ao longo dos próximos dias:**

1. **Após cron `sugadores:analyze` de 2026-07-03 12:00 BRT** — 1º ciclo COMPLETO com todos os fixes:
   - `campaign_name` deve começar a popular no banco (não mais 100% NULL como estava)
   - Sugadores em campanhas SGI devem parar de aparecer (skip agora funciona antes E o dado persistido permite cleanup retroativo)
   - Total de sugadores criados deve REDUZIR vs baseline (~183 sugadores/dia de 2026-07-02) — CAMILLO/BARAOSHOP/DINMAP e similares filtrados

2. **Botão "Copiar MLBs" nas empresas ML-only** (WENUS já validado) — deve funcionar em todas onde `sugador.mlb_id` está preenchido pelo provider

3. **Bugs diferidos** (registrados em `.planning/todos/pending/270702-sugadores-bugs-3-4-e-caso2-uat.md`):
   - Bug 3: rate limit persistente derruba empresas grandes (log 12:36 mostrou dezenas caindo)
   - Bug 4: adgroup multi-MLB (venda parcial não filtra)
   - Caso 2: config stale + cliques errados (aguarda nome da empresa)

## Retomada

Quando operador tiver dados de 3+ dias organicamente:

- Se fixes funcionam bem → **UAT APROVADO** → fechar phase com SUMMARY completo → migrar TODOs para Phase 55 ou nova phase
- Se surgirem novos bugs → nova wave de hotfix ou reabrir escopo
- Se Bug 3 (rate limit) for chato → priorizar fix (backoff + retry)

## Contexto operacional (não perder)

- Análise diária: `0 12 * * *` (`sugadores:analyze`)
- Cleanup automático: `30 12 * * *` (`sugadores:cleanup-quarentena`) — fecha sugadores cujo adgroup ganhou nome SGI/Sugadores depois do fato
- Sync MLBs legado (Adman MCP): `0 3 * * *` (`sugadores:sync-adgroup-mlbs --all`)
