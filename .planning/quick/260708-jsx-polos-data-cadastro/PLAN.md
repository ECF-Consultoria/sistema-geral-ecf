---
quick_id: 260708-jsx
slug: polos-data-cadastro
date: 2026-07-08
status: complete
---

# Painel Polos — Data de cadastro/entrada da empresa no sistema

## Objetivo
Mostrar no Painel Polos a data em que a empresa foi **cadastrada / entrou no sistema**
(`MlbEmpresa.created_at`), independente de ter ficha de onboarding. Complementa a coluna
"Data solicitação" (`data_solicitacao`), que é editável e só existe quando há ficha.

## Contexto
- "Data solicitação" = `data_solicitacao` (vem da ficha `MlbImplementacao`; nula sem ficha).
- "Cadastro" (novo) = `created_at` da `MlbEmpresa` — timestamp automático, existe pra toda empresa.
- O painel é servido por `PolosController@painel` e renderizado em `resources/js/Pages/Polos/Painel.jsx`
  (planilha com lentes Geral/Acessos/... + AutoFiltro por cabeçalho).

## Tarefas
1. **Backend** — `PolosController@painel`: incluir `data_cadastro => $e->created_at?->format('Y-m-d')`
   em cada linha de empresa.
2. **Frontend** — `Painel.jsx`:
   - `COLUNAS`: nova coluna `data_cadastro` (label "Cadastro", type date, format `fmtDataBR`).
   - `COLS_POR_LENTE`: adicionar `data_cadastro` após `data_solicitacao` nas lentes `acessos` e `geral`.
   - `LinhaPainel` / `celAcessos`: novo `<td>` read-only com a data (dd/mm/aaaa) após "Data solicitação".
3. **Build** — `npm run build`.

## Fora de escopo
- Modal "Ver" (ImplModal) — pode ser follow-up.
- Alterar a semântica de "Entrantes" (MetasPanel continua usando `data_solicitacao`).
- Deploy (não autorizado).

## Verificação
- Coluna "Cadastro" aparece nas lentes Acessos e Geral, com data dd/mm/aaaa (ou "—" se sem data).
- Filtro/ordenação por "Cadastro" funciona no cabeçalho.
- Cabeçalho ↔ corpo continuam alinhados (mesma quantidade/ordem de colunas por lente).
- `npm run build` sem erros.
