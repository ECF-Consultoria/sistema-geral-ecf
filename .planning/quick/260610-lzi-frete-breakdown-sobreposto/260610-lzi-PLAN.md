---
quick_id: 260610-lzi
slug: frete-breakdown-sobreposto
date: 2026-06-10
status: complete
---

# Quick 260610-lzi: Corrigir aba "Tipo de Envio" (frete) da Decomposição da carteira

## Problema

A API `/carteira/breakdown?dimensao=frete` retorna categorias **sobrepostas**
(ME2 / COLETAS / FULL / OUTROS / FLEX / PLACES). A soma dos GMV das fatias
(~R$ 31,7M) excede o GMV total da carteira (~R$ 16,9M) — não é partição, pois
um mesmo pedido conta em mais de uma modalidade. A API já entrega o `pct`
correto (fatia sobre o faturamento total).

O `BreakdownTabs` renderizava frete como **pizza** (força soma 100%) e
**recalculava** o percentual como `gmv / soma_das_fatias`, ignorando o `pct` da
API → ME2 aparecia ~49% em vez de 91,8%. Canais COLETAS/OUTROS/PLACES também
vinham sem tradução pt-BR.

As demais abas (programa/cluster/localidade) são partições reais e estavam
corretas — não foram alteradas.

## Mudanças

- `resources/js/lib/ecfDriveLabels.js`
  - `FRETE_LABELS`: adiciona COLETAS, PLACES, OUTROS; nota explicando que frete
    não é mutuamente exclusivo.

- `resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx`
  - Flag `sobreposto: true` na config da aba frete + helpText honesto.
  - `sobreposto` derivado de `activeConfig`.
  - `pctFaturamento`: usa `item.pct` da API quando sobreposto; mantém recálculo
    como share do somatório para as partições.
  - Render esquerdo: **barras horizontais** (largura = pct, cap visual 100%)
    quando sobreposto; **pizza** mantida para partições.
  - Aviso âmbar de sobreposição (percentuais somam >100%, não é erro).
  - Tabela: oculta coluna "Lojistas" no modo sobreposto (frete não traz
    contagem de sellers).

## Verificação

- `npm run build` → ✓ built in ~11s, sem erros.
- Dados ao vivo da API (pós-correção do parceiro) conferidos via curl no VPS:
  programa (CPP+POLOS), cluster, segmentação e resumo saudáveis.
- Abas programa/cluster/localidade: lógica intacta (pizza/tabela inalteradas).
