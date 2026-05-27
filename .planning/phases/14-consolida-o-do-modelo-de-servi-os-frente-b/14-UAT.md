---
status: testing
phase: 14-consolida-o-do-modelo-de-servi-os-frente-b
source:
  - 14-VERIFICATION.md (human_verification items 1, 2, 3)
  - 14-01-SUMMARY.md
  - 14-02-SUMMARY.md
  - 14-03-SUMMARY.md
  - 14-04-SUMMARY.md
  - 14-05-SUMMARY.md
  - 14-06-SUMMARY.md
  - 14-07-SUMMARY.md
started: 2026-05-27T00:00:00Z
updated: 2026-05-27T00:00:00Z
---

## Current Test

number: 1
name: Fechamento Financeiro — badges, modal de contratos e total consolidado
expected: |
  Em /administrativo/financeiro: empresas exibem badges amarelas (ecf-yellow) com nomes do catálogo (ex: "Polos", "Gestão"); expandir empresa mostra tabela "Serviços contratados" com botão "Adicionar contrato"; modal Add/Edit/Desativar funciona; total consolidado = faixa + SUM(contratos mensais ativos); console JS (F12) sem erros.
awaiting: user response

## Tests

### 1. Fechamento Financeiro — badges, modal de contratos e total consolidado
expected: Em /administrativo/financeiro: empresas exibem badges amarelas (ecf-yellow) com nomes do catálogo (ex: "Polos", "Gestão"); expandir empresa mostra tabela "Serviços contratados" com botão "Adicionar contrato"; modal Add/Edit/Desativar funciona; total consolidado = faixa + SUM(contratos mensais ativos); console JS (F12) sem erros.
result: [pending]

### 2. Smoke das 5 telas refatoradas (Plan 14-07)
expected: Rotas /administrativo/empresas, /comercial/empresas, /comercial/empresas/novo, /mlb/empresas e /companies renderizam sem erros JS no console; badges + filtros consomem nomes do catálogo (não enums legacy); seletor multi do catálogo aparece em /comercial/empresas/novo (não 3 checkboxes hardcoded); pendentes em /mlb/empresas exibem badges corretos.
result: [pending]

### 3. Relatórios PDF — sem referências legacy
expected: Gerar 1 relatório individual (botão "Gerar relatório PDF" no accordion) e 1 relatório geral (botão "Gerar relatórios" → "Todas as empresas"). PDF mostra "Tipo de serviço" com nomes do catálogo (ex: "Polos, Gestão"), sem blocos "Tipo de contrato"/"Vigência"/"Serviço adicional" (todos removidos no fix CR-01). Totais batem com o exibido no /administrativo/financeiro web.
result: [pending]

### 4. phase14:verificar-cobranca em dados reais
expected: Em ambiente com dump de produção restaurado (ou janela de manutenção em prod), rodar `php artisan phase14:verificar-cobranca --abort-on-divergence`. Exit code 0, 0 divergências entre cálculo legacy reconstruído e cálculo novo (via contratos).
result: [pending]

## Summary

total: 4
passed: 0
issues: 0
pending: 4
skipped: 0
blocked: 0

## Gaps

[none yet]
