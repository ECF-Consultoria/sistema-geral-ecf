# Quick Task 260619-fq7: Distribuição do faturamento como silhueta de cidade - Context

**Gathered:** 2026-06-19
**Status:** Ready for planning

<domain>
## Task Boundary

Adaptar a seção "Distribuição do faturamento" da página /polos
([resources/js/Pages/Polos/Index.jsx]) para representar a participação de cada polo
no faturamento usando as SILHUETAS DAS CIDADES (contornos IBGE já em
`cityShapes.js`), no estilo de um cartograma. Reusar a infra existente
(`POLO_PALETTE`, `formatCurrency`, tema dark `ecf-*`, `cityShapes.js`).

Fora de escopo: alterar a seção de Status (continua a pizza rose) e os cards de
% da meta por polo (já no formato de cidade). Sem deploy.
</domain>

<decisions>
## Implementation Decisions

### Rose vs. cartograma
- **Manter AS DUAS visões juntas** na seção de faturamento: a pizza rose (RoseChart,
  ECharts) E o novo cartograma de cidades, empilhados. A rose não sai.

### Representação do faturamento na cidade
- **Tamanho ∝ faturamento (cartograma):** cada silhueta é ESCALADA pelo faturamento
  do polo (maior faturamento = cidade maior), preenchida SÓLIDA na cor do polo
  (POLO_PALETTE). Não é preenchimento por share — é escala de tamanho.
- Área proporcional ao valor → fator de escala linear ∝ sqrt(faturamento) (para a
  ÁREA crescer proporcional ao faturamento, não o lado).

### Layout / labels (Claude's Discretion — confirmado o conjunto)
- Cidades **lado a lado**, **ordenadas por faturamento desc** (maior primeiro),
  alinhadas pela base (baseline comum) para a comparação de tamanho ficar legível.
- Sob cada cidade: **valor (BRL)** + **% do total (share)**.
- **Total** da seção: reutilizar o "Total: R$ ..." que já existe no card.
- **Hover/tooltip:** realce sutil + título com polo · valor · %.

### Reuso de código
- Reusar `cityShapes.js` (paths + `shapeForPolo`). NÃO duplicar os contornos.
- `CityGauge` é medidor (enche por %); o cartograma é outra mecânica (escala sólida)
  → criar um primitivo enxuto (ex.: `CityShape`/`CityCartogram`) que desenha o path
  escalado e preenchido, sem reescrever os contornos.
- Polo SEM contorno mapeado (fallback): representar por um marcador simples
  escalado pelo faturamento (ex.: círculo/quadrado na cor do polo) para não sumir.
</decisions>

<specifics>
## Specific Ideas

- O cartograma deve "casar" visualmente com os cards de % da meta (mesmas silhuetas,
  mesmo tema). A diferença: lá a cidade ENCHE por %, aqui a cidade ESCALA por R$.
- Estado vazio (mês sem faturamento — todos R$0): manter um aviso/placeholder
  coerente (a rose já mostra "Sem faturamento"); o cartograma pode ocultar ou
  mostrar as silhuetas em tamanho mínimo neutro.
- Junho/2026 já teve o cache da Adman aquecido (polos:warm concluído) → dá para
  validar com valores reais no mês corrente, além dos meses fechados (CSV).
</specifics>

<canonical_refs>
## Canonical References

- `resources/js/Pages/Polos/components/cityShapes.js` — contornos + `shapeForPolo`.
- `resources/js/Pages/Polos/components/CityGauge.jsx` — referência de estilo (glow,
  contorno, cor de status) — mas a mecânica do cartograma é por escala, não por fill.
- `resources/js/Pages/Polos/components/RoseChart.jsx` — fica na seção (rose mantida).
- `resources/js/Pages/Polos/Index.jsx` — bloco `distrib.itens` (já ordenado por
  faturamento desc, com { polo, cor, faturamento, share }).
</canonical_refs>
