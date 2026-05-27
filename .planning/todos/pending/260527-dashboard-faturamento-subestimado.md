---
id: 260527-dashboard-faturamento-subestimado
created: 2026-05-27
priority: high
effort_estimate: 2-4h
category: bug-investigation
status: in-progress
handoff_to: outro dev
references:
  - .planning/debug/resolved/dashboard-sync-inconsistente.md (debug v1 — fix de oscilação por mistura cache+DB)
  - .planning/debug/resolved/dashboard-sync-inconsistente-v2.md (debug v2 desta sessão — fix preventivo cust_id)
  - app/Http/Controllers/AdmanController.php (3 fixes aplicados)
  - app/Http/Controllers/DashboardController.php
  - app/Services/AdmanService.php
---

# Handoff: Dashboard faturamento subestimado + sync rate-limited

## Contexto

Sessão 2026-05-27 investigando 3 sintomas reportados pelo admin:

1. **Oscilação no dashboard** (valores diferentes entre reloads) → ✅ **resolvida** no debug v1 (commit `0906ee4`, há semanas) + reforçada hoje em `f9d0547`
2. **Algumas empresas sem números** → ⚠️ **causa parcial identificada** (16 empresas com cache miss + algumas com Adman API 429)
3. **Faturamento total subestimado** → 🔴 **PENDENTE** — provavelmente lacunas em `adman_metrics` por syncs falhos repetidos

## O que foi feito nesta sessão (4 commits)

| Commit | Problema atacado | Status |
|--------|------------------|--------|
| `f9d0547` | `custId` inconsistente entre writer/readers (preventivo — em prod nenhuma empresa diverge ml_store_id vs adman_account_id, mas dia ter empresa só-MLB iria quebrar) | ✅ Deployado |
| `5e85425` | `/adman/sync` 500 Internal Server Error → OOM 128MB do PHP-FPM em syncAll inline | ✅ Deployado |
| `af23000` | `/adman/sync` 504 Gateway Timeout → volta para dispatch via queue (`SyncAdmanCompanyJob` × N) | ✅ Deployado |
| `f0fdb44` | 77% jobs falhando com 429 Adman → delay 1.5s entre dispatches | ✅ Deployado |

## Estado atual em produção

- HEAD: `f0fdb44` (verificado em 2026-05-27 ~10:25 BRT)
- Workers: `ecf-worker:00` + `_01` rodando
- Fila Redis: vazia
- **Adman API em rate limit (429 sustentado)** — cooldown estimado ~30min após o último ataque
- `adman_metrics`: parada de receber updates desde ~09:45 BRT (1h+ atrás quando rate limit começou)
- Dashboard: mostra SUM(adman_metrics) 30d com lacunas → subestima

## Causa raiz do faturamento subestimado

O ciclo vicioso:
1. Cron `adman:sync` (5min) tenta sincronizar 168 empresas
2. Adman API tem rate limit baixo (não documentado, mas confirmado via curl: 429 sustentado após ~20-30 chamadas em janela curta)
3. Algumas empresas pegam 429 → backoff 3 tentativas (2s, 4s, 8s) → throw RuntimeException → adman_metric daquele dia NÃO É GRAVADO
4. SUM(adman_metrics.revenue) 30d acumula lacunas → dashboard mostra menos do que a Adman real tem

Confirmação direta via curl com `integrator-api-key`:
```
custId 1234939581 → 429 Too many requests
custId 1010510141 → 429 Too many requests
custId 267873368  → 429 Too many requests
```

(Os "erros 500" no log Laravel são propagados do método `fetchPerformance`: linha 219 faz `if ($response->failed()) throw new RuntimeException("Adman API erro {$response->status()}");` — mas o status real era 429 após esgotar os 3 retries internos.)

## O que ainda precisa ser resolvido

### Alta prioridade

1. **Validar fix do rate limit em runtime** — após Adman cooldown (~30min), clicar Sincronizar no dashboard, esperar ~5min, conferir:
   - `adman_metrics` recebe updates (`SELECT COUNT(*) FROM adman_metrics WHERE synced_at >= NOW() - INTERVAL 10 MINUTE` deve ser ~150+)
   - Worker log mostra muito mais DONE que FAIL
   - Dashboard reflete números maiores após próximo ciclo de cache (~30min)

2. **Backfill retroativo das lacunas** — empresas com dias faltando em `adman_metrics` precisam re-sincronizar dias antigos. Hoje o `syncCompany` aceita `?string $date` opcional (default = yesterday). Sugestão:
   - Comando artisan `adman:backfill --days=30` que itera empresas e dias faltantes
   - Detectar gap: `SELECT company_id, DATE(reference_date) FROM adman_metrics WHERE reference_date >= NOW() - INTERVAL 30 DAY` → diff vs calendar
   - Dispatch `SyncAdmanCompanyJob` com data específica, respeitando o delay 1.5s

### Média prioridade

3. **Documentar rate limit da Adman** — descobrir limite formal com a equipe Adman (req/min ou req/empresa/dia). Ajustar `usleep` e tries de acordo.

4. **Adicionar telemetria no dashboard** — `Log::info('[Dashboard] modo=cache|db, total_companies, cached_companies, total_revenue')` em cada request. Permite diagnosticar discrepâncias futuras.

5. **Cleanup do `RefreshGrossBillingCacheJob`** — job tem MaxAttemptsExceeded recorrente nos logs (`[2026-05-27 09:38:32] production.ERROR: App\Jobs\RefreshGrossBillingCacheJob has been attempted too many times`). Provavelmente herdou do mesmo rate limit.

### Baixa prioridade

6. **`AdminController`/`CompanyController` ainda usam padrão `$missingCache` por empresa** — debug v1 explicitamente deixou esses arquivos fora de escopo. Pode ter outro card com mistura por empresa (oscilação localizada). Auditar todos os call sites de `fetchGrossBilling`/`fetchAccountMetrics`.

## Como validar o estado atual

```bash
# SSH no VPS
plink.exe -ssh root@177.7.53.164

# 1) Quantas empresas têm metric do dia recente?
mysql -u ecf_admin -p$DB_PASS ecf_admin -e "
  SELECT 
    COUNT(*) total_companies,
    SUM(CASE WHEN cm.last_sync >= NOW() - INTERVAL 1 DAY THEN 1 ELSE 0 END) synced_today,
    SUM(CASE WHEN cm.last_sync < NOW() - INTERVAL 7 DAY OR cm.last_sync IS NULL THEN 1 ELSE 0 END) stale
  FROM companies c
  LEFT JOIN (SELECT company_id, MAX(synced_at) last_sync FROM adman_metrics GROUP BY company_id) cm ON cm.company_id = c.id
  WHERE c.active = 1 AND c.adman_account_id IS NOT NULL;
"

# 2) Cache Redis adman:
redis-cli -n 2 keys '*' | grep -c gross_billing  # esperado: ~168
redis-cli -n 2 keys '*' | grep -c account_metrics # esperado: ~168

# 3) Worker log
tail -20 /var/www/ecf_admin/storage/logs/worker.log

# 4) Testar Adman API direto (header correto)
curl -H "integrator-api-key: $ADMAN_API_KEY" \
     "https://api.ad-man.io/v1/mercadolivre/performance/267873368?dateFrom=2026-04-27&dateTo=2026-05-27" \
     -w '\nHTTP_CODE: %{http_code}\n' | tail -3
```

## Débitos pré-existentes registrados em deferred-items.md

Da Phase 14 (não relacionados ao dashboard mas vale o handoff conhecer):
- `260527-verificar-cobranca-em-prod` — gate de cobrança Phase 14 (já rodou ✅ 168/168 OK em prod hoje, pode marcar resolvido)
- `260527-cleanup-suites-coexistencia` — Phase14MigrationTest e outras esperam schema pré-drop, quebram com migrate:fresh
