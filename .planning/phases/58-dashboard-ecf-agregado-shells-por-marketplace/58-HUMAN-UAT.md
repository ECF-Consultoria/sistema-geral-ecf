---
status: partial
phase: 58-dashboard-ecf-agregado-shells-por-marketplace
source: [58-VERIFICATION.md]
started: 2026-07-06
updated: 2026-07-06
---

## Current Test

[awaiting human testing]

## Tests

### 1. Renderização visual do sidebar (grupo Mercado Livre atualizado)
**expected:** Logar como admin em ambiente local/staging, abrir o sidebar. Primeiro item do grupo Mercado Livre é "ECF Consolidado" (ícone PieChart) seguido de "Mercado Livre" (ícone LayoutDashboard). Nenhum item chamado apenas "Dashboard" aparece mais no menu.
**result:** [pending]

### 2. Renderização visual dos shells Shopee/Amazon
**expected:** Navegar autenticado para `/dashboard/shopee` e `/dashboard/amazon`. Cada tela mostra: header com logo da marca + h1 + pill "Em desenvolvimento"; grid com 4 KPI cards ("GMV", "Vendas", "ROAS", "Sellers") todos com valor "—" e label "Aguardando integração"; card explicativo com ícone Construction em ecf-yellow e botão amarelo "Ver Dashboard ECF Consolidado" que navega para `/dashboard/ecf`.
**result:** [pending]

### 3. UAT em produção das 5 URLs Dashboard
**expected:** Após deploy em `admin.ecfconsultoria.com.br`, validar as 5 URLs autenticado como admin: `/dashboard/ecf` (retorna 200, mostra Dashboard/Admin com dados reais das 126 empresas meli), `/dashboard/mercadolivre` (retorna 200, mesmo output pois todas as empresas hoje são meli), `/dashboard/shopee` (retorna 200 renderizando shell), `/dashboard/amazon` (retorna 200 renderizando shell), `/dashboard` legacy (retorna 200 pra deep links antigos). Nenhum erro 500, sidebar correto em prod.
**result:** [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
