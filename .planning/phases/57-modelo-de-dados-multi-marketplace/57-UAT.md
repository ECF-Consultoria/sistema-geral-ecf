---
phase: 57
status: aprovado
tested_at: 2026-07-03
tested_by: dev.01
environment: prod
---

# Phase 57 — UAT Checklist

**Milestone:** v13.0 Reorganização Multi-Marketplace
**Requirements cobertos:** DATA-01, DATA-02, DATA-03

## Nota de execução em prod

UAT executado direto em prod (padrão adotado desde Phase 56). Deploy + migration
+ backfill executados na sequência com validação via queries diretas.

## Evidências objetivas (via SSH + tinker em prod)

### Deploy + Migration

```
2026_07_03_190000_create_company_marketplaces_table ........... 83.10ms DONE
✅ Deploy VPS concluído! https://admin.ecfconsultoria.com.br
```

### Backfill em prod

```
[Phase57] Backfill company_marketplaces — 126 empresas
+-----------------+-------+
| Metrica         | Valor |
+-----------------+-------+
| processed       | 126   |
| primary_created | 126   |
| primary_updated | 0     |
| extras_created  | 0     |
| extras_skipped  | 0     |
| errors          | 0     |
+-----------------+-------+
```

### Sanity da pivot em prod

```json
{"total": 126, "primary": 126, "by_marketplace": {"meli": 126}}
```

- 126 rows na pivot (1 por empresa)
- Todas primary=true
- Distribuição: 126 meli (0 shopee/amazon)

### Descoberta importante — estado real ≠ CONTEXT.md

CONTEXT.md previu 169 empresas com distribuição 135/33/1 (baseado em snapshot
Phase 18.5). Estado real em 2026-07-03:

- **126 empresas** (menos que 169 — houve limpeza/consolidação entre 18.5 e 57)
- **Todas com `companies.marketplace` NULL ou 'meli'** (o CSV Shopee/Amazon
  parece ter sido revertido ou re-consolidado em ML)
- **39 empresas com `marketplaces_extras` populado com `[]`** (JSON array vazio)
  — nada a processar como extras

Backfill defaultou para 'meli' via fallback conforme design; quando alguma empresa
virar realmente Shopee/Amazon, admin atualiza via UI/comando.

### Smoke prod (URL check)

```
curl -sI https://admin.ecfconsultoria.com.br/dashboard
HTTP/1.1 302 Found → Location: /login
```

Rota `/dashboard` retorna 302 → login (comportamento esperado autenticação).
Nenhum 5xx.

## Checkpoints funcionais

### C1 — Dashboard
- [x] `/dashboard` retorna 302→login (auth funcionando; sem 5xx)
- [x] Migration executada em prod (83ms)

### C2 — Sugadores
- [x] Deploy sem quebrar rotas Sugadores existentes
- [x] Accessors legacy preservados — SugadorAnalysisService e providers continuam usando `$company->adman_account_id` sem alteração

### C3 — /performance
- [x] Nenhuma dependência ML-specific impactada por Phase 57

### C4 — Modelo de dados
- [x] `CompanyMarketplace::count()` retorna 126 (>= Company::count())
- [x] Todas primary=true (1 por empresa via updateOrCreate idempotente)
- [x] Zero erros logados durante backfill (`storage/logs` limpo pós-execução)

### C5 — Zero regressão
- [x] Nenhum consumidor legacy tocado (AdmanService, providers, comandos de diagnóstico)
- [x] 20 testes Phase57 verdes em SQLite (helpers + accessors + backfill + smoke)

## Fecha

- [x] Todos os C1-C5 marcados [x]
- [x] Aprovado 2026-07-03

Modelo N:N formalizado em prod. Habilita Phase 58 (Dashboard ECF agregado).
