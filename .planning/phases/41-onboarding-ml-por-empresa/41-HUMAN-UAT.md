---
status: partial
phase: 41-onboarding-ml-por-empresa
source: [41-VERIFICATION.md]
started: 2026-06-25T22:10:00Z
updated: 2026-06-25T22:10:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Acessar /dev/sugadores-ml-onboarding como admin no navegador
expected: Pagina renderiza com tabela de empresas, KPIs no header, filtros e botoes de acao. Layout pt-BR, tokens ecf-* aplicados, sem erros JS no console.
result: [pending]

### 2. Logar como usuario com role=consultor/mentor e tentar acessar /dev/sugadores-ml-onboarding
expected: 403 Forbidden ou redirect. Sidebar NAO mostra item 'Sugadores ML Onboarding'.
result: [pending]

### 3. Clicar em 'Rodar smoke' para uma empresa com token ativo + advertiser ok
expected: Flash success 'Smoke ML disparado para {nome}.' aparece. Comando sugadores:ml-smoke realmente entra na queue (verificar tabela jobs ou queue:work em paralelo).
result: [pending]

### 4. Clicar em 'Toggle Shadow' para uma empresa e validar que sugador_ml_company_config foi gravado/atualizado
expected: Row criada/atualizada em sugador_ml_company_config com shadow_enabled=true e shadow_started_at=hoje; subsequente toggle reverte. Activity log do operador opcional (IN-05 nao bloqueia).
result: [pending]

### 5. Rodar `php artisan sugadores:shadow-ml --company=all` no VPS apos UI ter habilitado shadow para >=1 empresa
expected: Comando le da tabela sugador_ml_company_config (NAO do env CSV) e processa apenas as empresas com shadow_enabled=true; output cita empresas corretas; sumario `admanOk + mlOk + falhas == 2 * days * len(companies)` (invariante CR-02).
result: [pending]

### 6. Validar metricas ml_metrics em sugador_provider_runs.summary apos um shadow run real (provider=ml)
expected: Coluna summary contem chaves total_calls, pages_read, rate_limit_429, refresh_token_count, backoff_sleep_ms, total_duration_ms com valores >0 quando o run fez chamadas HTTP reais ao ML.
result: [pending]

### 7. Verificar comportamento de backoff em 429/5xx contra a API ML real
expected: Quando ML responde 429, observar log/metricas indicando retry com Retry-After respeitado; nao deve cascatear erro nem queimar tokens.
result: [pending]

## Summary

total: 7
passed: 0
issues: 0
pending: 7
skipped: 0
blocked: 0

## Gaps
