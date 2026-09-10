---
id: 260909-e8n
slug: fechamento-escopo-e-legibilidade
date: 2026-09-09
status: in-progress
---

# Fechamento — escopo da lista, período visível e legibilidade

Feedback do usuário sobre `/administrativo/financeiro` (tela de fechamento), com
diagnóstico feito contra a produção antes de planejar.

## Contexto medido em produção (2026-09-09, competência 2026-08)

- 201 empresas ativas; snapshot de agosto congelado em **03/09/2026 14:59** (origem `consolidar_mes`).
- Estados: `ok` 127, `sem_integracao` 69, `sem_tabela` 4, `sem_faturamento` 1.
- As 69 "sem dados" **não têm `adman_account_id` nem `ml_store_id`**; 61 delas têm `status = pendente`.
- 11 empresas só têm serviço do setor `polos`; 15 não têm nenhum contrato de serviço ativo.
- Ale Peças, Tuki Pet e RAVENA RESKALLA HOME faturam pela Shopee (31 dias, faixa 2 = R$ 2.000/mês
  cada) mas estão com `status = pendente` — cadastro desatualizado.
- Interior Magazine (grupo Utilar) faturou R$ 204.427 com todos os contratos inativos; excluí-la
  mudaria a soma do grupo (R$ 3.299.903 → R$ 3.095.476) e poderia derrubar a faixa cobrada.

## Decisões do usuário

1. Sair da lista: só-Polos, sem serviço atribuído e `status = pendente` — **fora da tela de vez**,
   não escondidas por filtro.
2. **Trava de segurança**: o filtro nunca esconde empresa com faturamento apurado no mês, com
   cobrança calculada, ou que seja membro de grupo / tenha hierarquia de empresa.
3. Furos de sync: investigar a causa antes de qualquer UI (feito — ver SUMMARY).

## Tarefas

### T1 — Escopo da lista do fechamento (`AdminController`)
Novo helper privado `fechamentoRemoverForaDeEscopo()` aplicado em `fechamento()` **e**
`gerarRelatorioGeral()` (PDF tem que bater com a tela), rodando **antes** da agregação por grupo
para as somas de grupo nunca mudarem.

Esconde a empresa quando (`status = pendente` OU não tem contrato ativo de serviço com
`setor != 'polos'`) E nenhuma trava se aplica:
- `faturamento` apurado > 0
- `cobranca_mensal` > 0 ou `valor_mensal` > 0
- `company_group_id` não nulo
- `parent_company_id` não nulo

### T2 — Período apurado no banner de competência
Backend passa `periodo` (`inicio`/`fim` em `d/m/Y`); `StatusCompetenciaBadge` mostra
`Período 01/08 a 31/08 · fechado em 03/09/2026`, deixando explícito que a data de execução não é
o período apurado.

### T3 — Badge "Sem integração" (bug)
`Financeiro.jsx` condiciona a badge a `!empresa.has_adman` (só olha ML) enquanto o estado real
também considera Shopee. Trocar por `empresa.estado === 'sem_integracao'`.

### T4 — Escala tipográfica
Subir um degrau as fontes da tela (11→12, 13→14, 15→16, nome da empresa 16→17), mantendo os
tokens `ecf-*` e a hierarquia visual.

### T5 — Testes
Rodar a suíte de fechamento (Phase137/138/139 + AdminFechamentoControllerTest) e ajustar as
fixtures que criam empresa sem contrato de serviço e agora saem do escopo. Cobrir o novo filtro
e as travas com teste próprio.
