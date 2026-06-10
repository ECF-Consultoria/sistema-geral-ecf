---
quick_id: 260610-lzi
slug: frete-breakdown-sobreposto
date: 2026-06-10
status: complete
---

# Summary 260610-lzi

Corrigida a aba "Tipo de Envio" (frete) da Decomposição da carteira no Painel
Executivo. O frete é uma dimensão **sobreposta** (não partição): a API entrega
o `pct` de cada modalidade sobre o faturamento total e as fatias somam >100%.

O componente passou a, **somente para frete**:
- usar o `pct` da API em vez de recalcular como partição;
- renderizar **barras horizontais** (participação no GMV total) no lugar da pizza
  enganosa;
- exibir aviso de sobreposição (não soma 100% — esperado);
- ocultar a coluna "Lojistas" (frete não traz contagem de sellers);
- traduzir COLETAS/PLACES/OUTROS.

Abas programa/cluster/localidade inalteradas. Build Vite OK.

## Arquivos
- `resources/js/lib/ecfDriveLabels.js`
- `resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx`

## Verificação adicional (pedido do usuário)
Conferência ao vivo da API ECF Drive (pós-fix do parceiro) via VPS:
- `/carteira/resumo`, `/carteira/breakdown` (programa/cluster), `/carteira/segmentacao`
  voltaram com CPP+POLOS e distribuições saudáveis.
- Página **Concentração** (MatrizHeatmap, ForecastChart, VacasLeiteirasTabela)
  e HistoricoChart do Painel: revisados, sem defeito — consomem dados de partição
  corretos.
- Achado pré-existente (não corrigido aqui): `/carteira/distribuicao/medalhas`
  retorna PLATINUM 100% em todos os meses — degenerado na origem (API), não no front.
