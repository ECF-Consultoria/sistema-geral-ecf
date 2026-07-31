---
quick_id: 260731-drn
slug: operacoes-minimizado
date: 2026-07-31
status: in-progress
---

# Quick 260731-drn — Painel "Operações" do /polos nasce minimizado (e para de travar)

## Problema relatado

O "dash de Operações" do Painel Polos **já vem aberto** e **trava ao carregar os dados**.

## Diagnóstico

`OperacoesPanel.jsx` abria com `useState(true)`. Aberto na lente Geral ele monta **6 cards
`DistribuicaoResumo`**, e cada card instancia um **canvas ECharts** próprio — 6 inicializações
de gráfico no caminho crítico da entrada na página.

Pior: o cálculo dos contadores rodava **fora** do guard `{aberto && ...}`, ou seja, **mesmo com o
painel fechado**:

- `af.optionsFor(col)` (`useAutoFilter.js:119`) varre todas as linhas contra **todas** as colunas
  — ~390 empresas × ~28 colunas ≈ 11k chamadas de accessor + alocação de array por chamada.
- O painel fazia isso 6× (donuts) + 1× (`responsavel`) = **~76k acessos por render**.
- `activeKey` chamava `af.isOnly()` por categoria até achar a ativa — **mais uma varredura completa
  por categoria** quando havia filtro ativo na coluna.
- Como `optionsFor` é `useCallback` sem cache por chave e o pai re-renderiza a cada tecla na busca,
  isso se repetia **a cada tecla digitada**.

Os filtros de cabeçalho de coluna **não** têm esse problema — usam `getOptions={() => af.optionsFor(...)}`,
avaliado só quando o popover abre. `OperacoesPanel` era o único consumidor ansioso.

## Escopo

1. `useState(false)` — painel nasce minimizado.
2. Mover o cálculo para um `useMemo` que retorna cedo quando fechado, com dependências nos
   callbacks estáveis (`af.optionsFor`, `af.isColumnActive`, `af.isOnly`) e não no objeto `af`
   (que é recriado a cada render).
3. `activeKey` calculado dentro do mesmo memo.
4. Cabeçalho ganha "mostrar N indicadores" / "ocultar" — fechado por padrão, sem isso parece que
   os gráficos sumiram.

**Fora de escopo:** cache por chave dentro do `useAutoFilter` (beneficiaria a página toda, mas o
hook é compartilhado com outras telas e pede verificação própria).

## Verificação

- `npm run build` verde.
- Entrar no /polos: painel Operações fechado, zero canvas ECharts montado, grade carrega direto.
- Abrir o painel: os 6 indicadores aparecem com os mesmos números de antes.
- Cross-filter preservado: clicar numa categoria destaca a fatia e filtra a grade; clicar de novo limpa.
- Digitar na busca com o painel fechado não dispara recálculo dos contadores.
