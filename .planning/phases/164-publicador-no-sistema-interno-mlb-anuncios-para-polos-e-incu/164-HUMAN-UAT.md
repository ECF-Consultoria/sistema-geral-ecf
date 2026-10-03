---
status: partial
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
source: [164-VERIFICATION.md]
started: 2026-10-02T23:59:00Z
updated: 2026-10-02T23:59:00Z
---

## Current Test

[aguardando teste humano — quase tudo depende do deploy, que ainda não foi feito nem pedido]

## Tests

### 1. Os rascunhos de teste da #459 abrem pelo produto, em produção
expected: Depois do deploy, a migration roda sem erro no MariaDB de produção e os 2 rascunhos existentes abrem no Publicador interno pelo seu produto, sem perda (SC5).
result: [pending]
evidencia_deploy: 03/10 — reconsulta ao banco depois do deploy: eram 4 rascunhos (não 2), todos ligados a produtos 1–4 (origem portal, company 459, ofertas 522–525), 0 sem produto, coluna dormente oferta_id vazia. Falta abrir na tela.

### 2. Trava única do rascunho no MariaDB real (WR-B02)
expected: Salvar no editor enquanto a IA gera espera milissegundos pela trava `lockForUpdate`, sem deadlock e sem 500.
result: [pending]

### 3. Novo modelo de salvamento do editor, no navegador (CR-F01, CR-F02, WR-F02)
expected: Digitar e, em menos de 1 s, mexer numa foto, na categoria ou nas variações não descarta o que foi digitado (conferir com F5). Durante "Anunciar por IA" a mesa fica só leitura e, no fim, mostra o que a IA gravou. Falha de rede no salvamento tenta de novo e avisa. Sair da página com algo por salvar pede confirmação.
result: [pending]

### 4. Passo D20 em produção (ação do usuário)
expected: `php artisan publicador:empresa-teste` (simulação) e depois `--confirmar`: a Dev 02 (#459) passa a existir como empresa da Incubadora, com a conta liberada.
result: [pending]

### 5. E2E real só na #459, com confirmação antes de cada POST /items
expected: Item "Item de teste - Não ofertar" criado no ML; o MLB aparece na aba Anúncios da oferta (SC3); empresa sem Company publica com o token por `mlb_empresa_id` (SC4); anúncios fechados ao final.
result: [pending]

### 6. Portal do cliente sem o Anunciar, em produção
expected: Menu sem Anunciar e URL antiga dando 404; Lista SKUs, Precificação, Anúncios, Planejamento e Mapeamento intactos (SC7 — já coberto por `PortalSemAnunciarTest`).
result: [pending]
evidencia_deploy: 03/10 — `cliente.ecfconsultoria.com.br/estrutura/anunciar` responde 404 e o Portal EstruturaAnunciar saiu do manifest. Falta conferir o menu logado como cliente.

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0
blocked: 0

## Gaps
