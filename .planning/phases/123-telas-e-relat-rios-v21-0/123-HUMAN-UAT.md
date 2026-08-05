---
status: partial
phase: 123-telas-e-relat-rios-v21-0
source: [123-VERIFICATION.md, 123-CHECKPOINT-VISUAL.md]
started: 2026-08-04
updated: 2026-08-04
---

## Current Test

[aguardando conferência pós-deploy em produção]

## Tests

### 1. Auditoria de Bônus — conferência visual com dado real

Adiada por decisão explícita do usuário em 2026-08-04. A tela depende de
`company_users` (vínculo profissional↔empresa) para resolver a carteira, e o
usuário optou por **não** copiar mais essa tabela de produção para o ambiente
local — o pull foi bloqueado pelo classificador e a decisão foi não liberá-lo.

Sustentação para adiar: `AuditoriaBonusNotaEmpresaTest` (9 testes verdes, subiu de
6 após o plano 123-08) mais os gates estruturais em
`tests/js/estrutura-auditoria-desempenho.test.js` cobrem a mecânica de leitura e
o selo de safra. O que não foi conferido é a aparência da tela com carteira real.

expected: Em `/desempenho/auditoria-bonus?mes=<competência fechada>`, cada
profissional lista suas empresas com `nota_empresa` e os três componentes; a nota
do profissional exibe o selo "recalculada agora" **apenas** quando a competência
não foi consolidada; quando consolidada, nenhum selo aparece e o número vem do
`breakdown_json` congelado.

result: [pending]

### 2. WR-02 — seção "Empresas da carteira" some sem aviso quando `sem_carteira` é true

Achado residual do code review, fora do escopo dos planos de fechamento 123-07/08
(o arquivo `Performance/Show.jsx` não é tocado desde o plano 123-04).

A seção nova está dentro do ramo `!semCarteira`. Se um profissional tiver
`sem_carteira === true` no payload E existir detalhe congelado por empresa, a
seção inteira — inclusive o aviso da D-03 — desaparece sem nenhum sinal, em vez
de exibir o aviso explícito que a D-03 exige.

Não bloqueia nenhum Success Criteria literal do ROADMAP nem os dois gaps fechados.
Requer confirmar em produção se esse estado (sem carteira + detalhe gravado) chega
a ocorrer na prática antes de decidir se vale corrigir.

expected: Confirmar se existe profissional com `sem_carteira=true` e linhas em
`desempenho_company_score_snapshots` na mesma competência. Se existir, a tela deve
mostrar o aviso da D-03 em vez de omitir a seção silenciosamente.

result: [pending]

## Summary

total: 2
passed: 0
issues: 0
pending: 2
skipped: 0
blocked: 0

## Gaps
