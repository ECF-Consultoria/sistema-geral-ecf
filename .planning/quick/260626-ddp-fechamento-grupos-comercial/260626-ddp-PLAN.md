---
quick_id: 260626-ddp
slug: fechamento-grupos-comercial
status: in-progress
date: 2026-06-26
---

# Quick 260626-ddp — Fechamento usa Grupos do Comercial (CompanyGroup)

## Problema
O **Fechamento** (Administrativo) e os relatórios consolidam cobrança por **pai/filhas**
(`parent_company_id`), enquanto o **Comercial** define grupos via **`CompanyGroup`**
(`company_group_id`, aba "Grupos"). Os grupos do Comercial não aparecem no Fechamento.

## Decisões (confirmadas com o usuário)
1. **Linha única por grupo**: cada `CompanyGroup` vira UMA linha no Fechamento (nome+cor),
   somando faturamento/cobrança dos membros, com **um único toggle de "recebido"** para o
   grupo (marca/desmarca todos os membros no mês). Empresas sem grupo = linhas avulsas.
2. **Remover montar-grupos do Administrativo**: tirar a aba "Grupos" (GruposManager) de
   `Admin/Empresas.jsx`. Grupos só se definem no Comercial. **Limpar todos os
   `parent_company_id`** (autorizado: "desfazer todos").

## Tarefas
- [ ] **T1 — Backend Fechamento**: `AdminController::fechamento()` agrupa por `company_group_id`
  (cada grupo = 1 linha consolidada com `membros[]`, somas, recebido do grupo). Avulsas iguais
  a hoje. Mantém faixa/cobrança via `CobrancaCalculator` e cache Adman.
- [ ] **T2 — Toggle recebido do grupo**: `AdminController::toggleRecebidoGrupo(CompanyGroup)` —
  marca/desmarca `FechamentoRecebido` de todos os membros no mês. Rota
  `POST /financeiro/grupo/{group}/recebido` (`admin.financeiro.grupo.recebido`).
- [ ] **T3 — Frontend Fechamento** (`Admin/Financeiro.jsx`): renderizar linha por grupo
  (nome+cor, "Grupo · N", soma, toggle do grupo, expansão = composição dos membros + gestão de
  contrato por membro). Avulsas como hoje. `TotalConsolidado` conta 1x por grupo. Gráficos
  achatam membros.
- [ ] **T4 — Remover GruposManager do admin** (`Admin/Empresas.jsx` + `AdminController::empresas`):
  tirar a aba "Grupos" e props não usadas; manter badge de grupo read-only.
- [ ] **T5 — Migration**: limpar `parent_company_id` (NULL em todas as companies).
- [ ] **T6 — Build**: `npm run build`.

## Fora de escopo (follow-up)
- Regrupar os PDFs/email (`relatorio-geral`, `relatorio-fechamento`, `EnviarRelatorioFechamentoJob`)
  por `CompanyGroup`. Após limpar `parent_company_id` eles **degradam graciosamente** para
  "uma empresa por bloco" (caminho `vinculadas` não dispara) — continuam funcionando, mas sem
  agrupar. Reescrever os documentos de cobrança para cabeçalho de grupo é mudança separada.
