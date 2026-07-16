---
phase: 87-planilha-cores-por-grupo-de-coluna-e-grupos-colapsaveis-padr
date: 2026-07-15
status: pending-checkpoint
requirements: [VIS-87-1, VIS-87-2, VIS-87-3]
one-liner: "Cor por grupo de coluna (padrão Amazon) e grupos colapsáveis (padrão Excel)"
commits:
  - 5aca967 feat(87) cores por grupo de coluna e grupos colapsaveis
deployed: 2026-07-15 (deploy parcial)
---

# Fase 87 — Cores por grupo + collapse — Summary

## O que foi feito

Cada grupo de colunas tem sua cor e os colapsáveis recolhem/expandem: **Dados básicos** (blue),
**Preço e estoque** (emerald), **Identificação** (violet), **Dimensões** (sky), **Fotos** (amber),
**Ficha técnica** (pink), **Características secundárias** (slate).

Deixou de ser cosmético: com as características secundárias entrando na Fase 85, uma categoria
grande produz dezenas de colunas — recolher virou usabilidade.

## Decisões

- **Nativo, não desenho manual:** `GridColumn.themeOverride` aceita cor **por coluna** (verificado
  no `.d.ts`), e `onGroupHeaderClicked` dá o clique no cabeçalho. VIS-87-1 é declarativo.
- **O padrão da Amazon foi copiado; os tons não.** A referência é a aba "Modelo" do
  `2026-01-04 19-31-31.xlsm` do usuário (template `fptcustom`, 477 colunas, **10 grupos por cor**:
  pêssego `FCD5B4` 135 col, verde `92D050` 140, azul `8DB4E2` 52, rosa `CC9999` 48, vermelho
  `FF0000` 27, bege `BBA680` 32, azul claro `B7DEE8` 24, amarelo `FFFF00` 9 = imagens, coral
  `FF8080` 4 = variações, laranja `F8A45E` 4). Aqueles tons são para **planilha branca** e ficariam
  ilegíveis no dark theme — aqui cada grupo é um tom bem dessaturado (`0.05`) sobre o `ecf-card`:
  separa as faixas sem competir com o conteúdo nem com o amarelo da seleção.
- **"Características secundárias" nasce recolhido** — é o grupo que mais infla.
- **Dados básicos e Preço não recolhem** — são o mínimo para trabalhar.
- **Chips acima da grade reabrem:** recolhido, o cabeçalho do grupo some junto com as colunas e não
  há onde clicar no canvas. Os chips também mostram de relance o que está escondido.

## VIS-87-3 — recolher não apaga

O filtro é **só de exibição**: `montarPayloadLinha` lê o estado da linha, não as colunas visíveis.
Gate proibindo qualquer escrita no dado ao recolher.

## Verificação

68/68 JS (5 gates novos), Phase82 10/10, build verde. Deployado.

**Checkpoint visual pendente** (só em prod): as faixas de cor por grupo, o clique no cabeçalho
recolhendo, os chips reabrindo, e "Características secundárias" começando fechado.
