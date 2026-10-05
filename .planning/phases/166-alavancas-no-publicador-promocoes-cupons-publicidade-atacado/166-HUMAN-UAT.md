---
status: partial
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
source: [166-VERIFICATION.md]
started: 2026-10-05T12:00:00Z
updated: 2026-10-05T12:00:00Z
---

## Current Test

[aguardando deploy — prova real na #459; roteiro completo em `.planning/todos/pending/261004-e2e-alavancas-459.md`]

## Tests

### 1. Permissão "Promoções" do app ECF no DevCenter (Questão 5)
expected: Alavancas da #459 (company-459) abre e a aba Promoções lê os convites sem erro de permissão
result: [pending]

### 2. A1 — faixas de PxQ e `version` no mesmo GET de prices
expected: `GET /items/{id}/prices?display_version=true` com `show-all-prices: true` traz `version` e `price_per_quantity`; gravar 1 faixa e relê-la (sem isso a tela recusa com ALAV-B2B-09/10 e nada é gravado)
result: [pending]

### 3. A3 — "quanto a loja recebe" em oferta cofinanciada
expected: o número da tela bate com o Seller Center (hoje marcado como estimativa)
result: [pending]

### 4. A9 e Questão 1 — critério de "convite aberto" e forma real de `benefits`
expected: o panorama conta os convites que o Seller Center mostra abertos; a linha "o ML banca" aparece nos candidatos cofinanciados
result: [pending]

### 5. Roteiro E2E na #459 (desconto individual criar/remover, atacado gravar/reler, conta de cliente travada)
expected: cada escrita confirmada na janela, histórico OK, nada escrito em conta de cliente
result: [pending]

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 5

## Gaps

Nenhum gap de código (verificação 20/20 requisitos, 13/13 decisões). Os 5 itens dependem do código em produção:
deploy só com autorização do usuário; antes dele, `.env` de produção com `PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES=459`
e nada mais; depois, `queue:restart`.
