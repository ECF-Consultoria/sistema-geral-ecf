---
quick_id: 260626-ddp
slug: fechamento-grupos-comercial
status: complete
date: 2026-06-26
---

# Quick 260626-ddp — Fechamento usa Grupos do Comercial (CompanyGroup)

## O que mudou
O **Fechamento** (Administrativo) deixou de agrupar empresas pela hierarquia
**pai/filhas** (`parent_company_id`) e passou a consolidar pelos **grupos nomeados
do Comercial** (`CompanyGroup` / `company_group_id`). O admin não monta mais grupos.

## Entregue
- **Backend `AdminController::fechamento()`**: agrupa por `company_group_id`. Cada grupo
  vira UMA linha consolidada (`is_grupo`, `name`, `color`, `membros[]`, soma de
  faturamento/cobrança, `recebido` = todos os membros recebidos). Empresas sem grupo
  ficam avulsas (linha individual, como antes). Faixa/cobrança continuam via
  `CobrancaCalculator::novo` + cache Adman.
- **Toggle recebido por grupo**: `AdminController::toggleRecebidoGrupo(CompanyGroup)` —
  marca/desmarca `FechamentoRecebido` de todos os membros ativos no mês. Rota nova
  `POST administrativo/financeiro/grupo/{group}/recebido` → `admin.financeiro.grupo.recebido`.
- **Frontend `Admin/Financeiro.jsx`**: `GrupoRow`/`GrupoAccordion`/`MembroRow` renderizam a
  linha do grupo (cor + "Grupo · N" + soma + toggle do grupo); expansão mostra a
  composição com gestão de contrato por membro (reaproveita o modal global). Avulsas
  seguem com `FechamentoRow`/`FechamentoAccordion`. `TotalConsolidado` conta 1x por linha;
  gráficos achatam os membros (`empresasFlat`). Filtros adaptados a linhas de grupo.
- **`Admin/Empresas.jsx`**: removida a aba "Grupos" (GruposManager) — só listagem read-only
  com badge do grupo. `AdminController::empresas()` parou de enviar `grupos`/`servicos_disponiveis`.
- **Migration** `2026_06_26_123900_limpar_parent_company_id_companies`: zera `parent_company_id`
  de todas as companies (coluna preservada). `down()` no-op (irreversível por design).

## Decisões
- Consolidação = **linha única por grupo** (confirmado com o usuário).
- Remover montar-grupos de **todo o administrativo** (confirmado).
- `parent_company_id` órfão de UI (Admin/Empresas já não vinculava; Comercial só usa
  CompanyGroup) → limpar é seguro.

## Verificação
- `php -l` OK em AdminController, routes e migration.
- `npm run build` OK (Financeiro + Empresas compilam).
- `route:list` confirma `admin.financeiro.grupo.recebido`.
- Migration **não rodada localmente** (4 migrations de outros trabalhos estão pendentes;
  rodar `migrate` aplicaria todas). O recurso funciona sem ela (o novo código ignora
  `parent_company_id`). Roda no deploy via `migrate --force`.

## Fora de escopo (follow-up sugerido)
- Regrupar os **PDFs/email** (`relatorio-geral`, `relatorio-fechamento`,
  `EnviarRelatorioFechamentoJob`) por `CompanyGroup`. Após limpar `parent_company_id` eles
  degradam graciosamente para "uma empresa por bloco" (caminho `vinculadas` não dispara) —
  continuam funcionando, mas sem cabeçalho de grupo. Reescrever os documentos de cobrança
  para um header de grupo é uma mudança separada (o blade espera um `Company` como cabeçalho).

## Commits
- `feat(fechamento): consolida por grupo do Comercial (CompanyGroup)` — controller + rota
- `feat(fechamento-ui): linha unica por grupo + toggle de recebido do grupo` — Financeiro.jsx
- `feat(admin-empresas): remove montar-grupos do admin (so Comercial define)` — Empresas.jsx + controller
- `chore(db): limpa parent_company_id (desfaz grupos pai/filhas legados)` — migration
