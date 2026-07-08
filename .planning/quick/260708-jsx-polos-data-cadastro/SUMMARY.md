---
quick_id: 260708-jsx
slug: polos-data-cadastro
date: 2026-07-08
status: complete
---

# Resumo — Painel Polos: data de cadastro/entrada no sistema

## O que foi feito
Adicionada a coluna **"Cadastro"** (read-only) no Painel Polos, mostrando a data em que a
empresa foi cadastrada/entrou no sistema (`MlbEmpresa.created_at`). Complementa a coluna
editável "Data solicitação" (`data_solicitacao`), que só existe quando há ficha de onboarding —
"Cadastro" existe para **toda** empresa.

## Arquivos alterados
- `app/Http/Controllers/PolosController.php` — `painel()`: cada linha agora envia
  `data_cadastro => $e->created_at?->format('Y-m-d')`.
- `resources/js/Pages/Polos/Painel.jsx`:
  - `COLUNAS`: nova coluna `data_cadastro` (label "Cadastro", type date, format `fmtDataBR`, filtrável/ordenável).
  - `COLS_POR_LENTE.geral`: `data_cadastro` como **PRIMEIRA** coluna (após feedback do usuário; removida de `acessos`).
  - `LinhaPainel`: fragmento dedicado `celCadastro` (1ª célula da Geral) com data (dd/mm/aaaa, ou "—")
    + selo **"novo"** (usa a flag `e.novo` do backend).
- Backend `novo`: ninguém editou ainda (empresa E ficha com `updated_at ≈ created_at`, tolerância 2s).
  Some na 1ª edição — os controllers de edição fazem `back()`, recarregando `empresas` e recalculando.
- Build: `npm run build` OK.

## Verificação
- [x] Cabeçalho ↔ corpo alinhados (Geral 1+6+4+5+5+5+1=27; Acessos 4=4).
- [x] Build sem erros.
- [x] Selo "novo" (≤30 dias) na coluna Cadastro da Geral.
- [ ] Conferência visual no navegador (lente Geral) — pendente do usuário.

## Notas / follow-ups
- `created_at` reflete quando o registro foi criado no sistema. Empresas importadas em lote
  (seeds/sync) podem compartilhar a data da importação, não a entrada real — é o melhor dado
  automático disponível.
- **DEPLOYADO 260708** (14a3b9e) junto do pacote maior (modo claro + desempenho TV) reconciliado
  com origin/main do outro dev. Ver memória `project_deploy_260708_reconcilia_tudo`.
- Fora de escopo: modal "Ver" (ImplModal) e semântica de Entrantes (MetasPanel segue `data_solicitacao`).
