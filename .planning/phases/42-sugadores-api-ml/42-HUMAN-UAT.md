---
status: partial
phase: 42-sugadores-api-ml
source: [42-VERIFICATION.md]
started: 2026-06-26T14:30:00Z
updated: 2026-06-26T14:30:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Sidebar — item 'Onboarding ML' escondido + rota acessivel via URL direta
expected: Logado como admin, item 'Onboarding ML' NAO aparece em nenhum grupo da sidebar. Acessar `/dev/sugadores-ml-onboarding` via URL direta abre a pagina normalmente (rota preservada conforme D-02).
result: [pending]

### 2. Config UI — campo cpc_minimo_cliques em /sugadores/configs/{company}
expected: Acessar `/sugadores/configs/{id-da-bymobille}` mostra o campo "Cliques minimos para validar CPC" ao lado de cpc_maximo. Configurar `cpc_maximo=4` + `cpc_minimo_cliques=5` e salvar persiste corretamente em sugador_configs.
result: [pending]

### 3. Smoke real ByMobille no VPS
expected: Rodar `php artisan sugadores:analyze --company=298` no VPS apos deploy. Comando roda usando provider=ml automaticamente (cut-over D-05). Sugadores aparecem em `/sugadores` na tela normal — analista nao percebe a troca de motor.
result: [pending]

### 4. Sugador origem ML em /sugadores/{id} + botao Painel de Ads
expected: Abrir sugador de origem ML mostra cards de metricas (investimento/vendas/faturamento/ACOS/cliques/impressoes/CPC/ROAS) preenchidos com dados da API ML. Botao 'Painel de Ads' abre `mercadolivre.com.br/anuncios/product-ads/anuncios?campaignId={X}` (nao Adman).
result: [pending]

### 5. Quarentena SGI por nome de campanha em payload ML real
expected: Configurar campanha "SGI - Lentes" no Mercado Livre para ByMobille. Rodar analise. Adgroups da campanha SGI NAO aparecem em `/sugadores` mesmo batendo criterios (gasto>=20 sem venda etc).
result: [pending]

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 0

## Gaps
