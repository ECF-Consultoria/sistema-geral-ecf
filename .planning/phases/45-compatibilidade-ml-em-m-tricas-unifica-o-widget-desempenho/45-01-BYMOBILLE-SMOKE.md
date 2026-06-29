# Smoke Bymobille #298 — adman_metrics (Phase 45-01)

**Data:** 2026-06-29
**Objetivo:** Confirmar se company_id=298 (Bymobille) tem dados em adman_metrics para determinar se Plans 45-02/03 (MlMetricsProvider) são necessárias.

## Status

**PENDENTE — operador deve rodar em produção**

O banco de dados local (MariaDB) está corrompido desde 2026-06-25 (ver memory `project_mariadb_local_corrompido`). As consultas não puderam ser executadas no ambiente dev. O comando Artisan `dev:bymobille-smoke` foi criado como parte desta tarefa e deve ser rodado diretamente no VPS de produção.

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

## Template de resultado (preencher após rodar em prod)

### A — adman_metrics WHERE company_id=298
| total | desde (MIN) | até (MAX) |
|-------|-------------|-----------|
| ???   | YYYY-MM-DD  | YYYY-MM-DD |

### B — adman_metrics últimos 30d
| registros | revenue_total | ad_spend_total | desde | até |
|-----------|--------------|---------------|-------|-----|
| ???       | R$ ???       | R$ ???        | ???   | ??? |

### C — companies WHERE id=298
| id | name | adman_account_id | ml_store_id | active |
|----|------|-----------------|-------------|--------|
| 298 | Bymobille | NULL / VALOR | NULL / VALOR | sim/não |

### D — ml_tokens WHERE company_id=298
| status | expires_at |
|--------|-----------|
| ???    | ???       |

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

**Plans 45-02/03:** PENDENTE — operador roda `php artisan dev:bymobille-smoke` em produção e preenche o template acima.

**Hipótese mais provável** (baseada em `project_ml_only_companies_adman_endpoints.md`): Bymobille é empresa ML-only — `adman_account_id` é NULL, portanto `total = 0` em adman_metrics. Isso implica **Plans 45-02/03 NECESSÁRIAS**.

Se confirmado (total = 0): Plans 45-02 (AdmanMetricsProvider + MlMetricsProvider) e 45-03 (PerformanceScoreService unificado) devem ser executadas.

Se refutado (total > 0, dados recentes): Plans 45-02/03 **NÃO NECESSÁRIAS** — investigar PortfolioScoreService e lógica de join/cust_id como causa do score zerado.

---

## Próximo passo

1. SSH no VPS: `ssh ecf-prod`
2. `cd /var/www/html/ecf_admin && php artisan dev:bymobille-smoke`
3. Copiar output e preencher template acima
4. Atualizar esta decisão para "NECESSÁRIAS" ou "NÃO NECESSÁRIAS"
5. SE NECESSÁRIAS: executar `gsd-execute-phase 45` para Plans 45-02 e 45-03
6. SE NÃO NECESSÁRIAS: Phase 45 pode fechar aqui; atualizar `project_adman_data_sources.md`
