# Smoke Bymobille #298 — adman_metrics (Phase 45-01)

**Data:** 2026-06-29
**Objetivo:** Confirmar se company_id=298 (Bymobille) tem dados em adman_metrics para determinar se Plans 45-02/03 (MlMetricsProvider) são necessárias.

## Status

**EXECUTADO em 2026-06-29 às 14:30 BRT via SSH no VPS de produção.**

Resultado: **Plans 45-02/03 — NÃO NECESSÁRIAS** (Bymobille TEM dados em adman_metrics).

---

## Comando para executar em produção

```bash
php artisan dev:bymobille-smoke
# Ou com janela diferente:
php artisan dev:bymobille-smoke --company=298 --dias=30
```

Localização do comando: `app/Console/Commands/BymobilleSmoke.php`

---

## Consultas executadas pelo comando

### A — Presença/intervalo em adman_metrics
```sql
SELECT COUNT(*) as total, MIN(reference_date) as desde, MAX(reference_date) as ate
FROM adman_metrics
WHERE company_id = 298;
```

### B — Métricas recentes (últimos 30d)
```sql
SELECT
    COUNT(*) as registros,
    COALESCE(SUM(revenue), 0) as revenue_total,
    COALESCE(SUM(ad_spend), 0) as ad_spend_total,
    MIN(reference_date) as desde,
    MAX(reference_date) as ate
FROM adman_metrics
WHERE company_id = 298
  AND reference_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY);
```

### C — Identifiers da empresa
```sql
SELECT id, name, adman_account_id, ml_store_id, active
FROM companies
WHERE id = 298;
```

### D — Status do ml_token
```sql
SELECT status, expires_at
FROM ml_tokens
WHERE company_id = 298
ORDER BY id DESC
LIMIT 1;
```

---

## Resultado real (2026-06-29)

### A — adman_metrics WHERE company_id=298
| total | desde (MIN) | até (MAX) |
|-------|-------------|-----------|
| **60** | 2026-04-28 | 2026-06-28 |

### B — adman_metrics últimos 30d
| registros | revenue_total | ad_spend_total | desde | até |
|-----------|--------------|---------------|-------|-----|
| **28**    | **R$ 2.401.119,56** | **R$ 62.223,45** | 2026-05-30 | 2026-06-28 |

### C — companies WHERE id=298
| id | name | adman_account_id | ml_store_id | active |
|----|------|-----------------|-------------|--------|
| 298 | ByMobille - Teste | **NULL** | **436501796** | sim |

### D — ml_tokens WHERE company_id=298
| status | expires_at |
|--------|-----------|
| **active** | 2026-06-29 13:59:02 |

---

## Lógica de decisão

O comando `dev:bymobille-smoke` aplica automaticamente esta lógica:

| Cenário | total (A) | registros_30d (B) | Decisão |
|---------|-----------|-------------------|---------|
| Empresa cobertura Adman normal | > 0 | > 0 | **NÃO NECESSÁRIAS** — bug pode ser join/cust_id |
| Sync Adman parou (dados muito antigos) | > 0 | 0 | **NECESSÁRIAS** — dados caducaram |
| Empresa ML-only (sem adman_account_id) | 0 | 0 | **NECESSÁRIAS** — nunca teve Adman |

---

## Decisão

**Plans 45-02/03: NÃO NECESSÁRIAS** — `total = 60` registros em adman_metrics, 28 nos últimos 30d com R$ 2.4M revenue + R$ 62K ad_spend. Bymobille **TEM dados em adman_metrics** apesar de ter `adman_account_id = NULL`.

### Implicação técnica

A hipótese inicial (Bymobille ML-only = sem adman_metrics) foi REFUTADA. Os dados estão chegando em adman_metrics por algum sync — possivelmente:
- Sync ML→adman_metrics introduzido em alguma phase v11.0 (Phase 41 shadow comparison? Phase 42 cut-over?)
- Sync direto via Adman MCP usando ml_store_id como cust_id quando adman_account_id é NULL
- Pipeline interno do AdmanService que cobre fallback

A consequência é que o pattern `CompanyMetricsProvider` (factory Adman vs ML) NÃO RESOLVE o bug do usuário — adman_metrics já tem dados de empresas ML.

### Próxima investigação (NÃO faz parte da Phase 45)

Se o usuário reporta "Bymobille zerada no score do analista responsável", o root cause está em outro lugar:

1. **Hipótese alta:** `whereDoesntHave('mlbEmpresa')` em queries do PortfolioScoreService/DashboardController EXCLUI Bymobille porque ela TEM mlbEmpresa (é uma empresa MLB). Esse filtro vem do Phase 13 (split de empresas Performance vs MLB) e pode estar mal aplicado aqui.
2. **Hipótese média:** `company_users` pivot não tem row pra Bymobille → ninguém é responsável formalmente → não aparece em nenhum score.
3. **Hipótese baixa:** Score lê de cache `adman_metrics` que foi populado em janela antiga (antes de Bymobille ter dados).

Essa investigação deve virar uma **quick task de debug** (`/gsd-quick` ou `/gsd-debug`) APÓS o UAT da Phase 45 (45-04) confirmar/refutar visualmente. Se UAT mostrar Bymobille aparecendo OK no score, não há bug e a quick task não precisa rodar.

---

## Caminho atualizado da Phase 45 (Caminho B confirmado)

- ✅ Plan 45-01 — fix filtro users + smoke (commit `e49879d`, smoke executado e documentado)
- ⊘ Plan 45-02 — **DEFERRED** (factory pattern não resolve o bug observado)
- ⊘ Plan 45-03 — **DEFERRED** (refactor não necessário sem provider novo)
- ⏭ Plan 45-04 — UAT humano em prod (próximo passo)

Phase 45 fecha após UAT (45-04) confirmar que widget == página `/performance`. Se UAT revelar Bymobille zerada → abrir quick task de debug separada (não voltar pra cá).
