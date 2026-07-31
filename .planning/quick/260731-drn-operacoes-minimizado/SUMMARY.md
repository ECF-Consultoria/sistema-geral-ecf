---
quick_id: 260731-drn
slug: operacoes-minimizado
date: 2026-07-31
status: complete
commits:
  - f5d93575
---

# Quick 260731-drn — Painel "Operações" do /polos nasce minimizado (e para de travar)

## O que foi feito

`resources/js/Pages/Polos/components/OperacoesPanel.jsx`:

1. `useState(true)` → `useState(false)`: o painel nasce minimizado.
2. O cálculo dos indicadores saiu do corpo do render e foi para um `useMemo` que **retorna cedo
   quando o painel está fechado**.
3. Dependências do memo são os callbacks estáveis do hook (`af.optionsFor`, `af.isColumnActive`,
   `af.isOnly`) — e **não** o objeto `af`, que é um literal recriado a cada render e anularia o memo.
4. `activeKey` passou a ser calculado dentro do mesmo memo (antes era uma função chamada no JSX).
5. Cabeçalho ganhou `mostrar N indicadores` / `ocultar`.

## Por que travava

Dois custos somados no caminho crítico da entrada no /polos:

- **6 canvas ECharts**: cada `DistribuicaoResumo` instancia o seu, e a lente Geral tem 6 cards.
- **~76k acessos por render**: `af.optionsFor(col)` (`useAutoFilter.js:119`) varre todas as linhas
  contra todas as colunas (~390 empresas × ~28 colunas ≈ 11k acessos com alocação de array por
  chamada). O painel chamava 6× (donuts) + 1× (`responsavel`). E `activeKey` chamava `af.isOnly()`
  por categoria até achar a ativa — cada uma outra varredura completa.

O agravante era o cálculo estar **fora** do guard `{aberto && ...}`: rodava mesmo fechado, e como
`optionsFor` é `useCallback` sem cache por chave e o pai re-renderiza a cada tecla da busca, repetia
**a cada tecla digitada**. Ou seja: só trocar o `useState` para `false` deixaria a travada quase
inteira de pé — daí o memo ser parte necessária da correção, não um extra.

Os filtros de cabeçalho de coluna **não** contribuíam: usam `getOptions={() => af.optionsFor(...)}`,
avaliado só quando o popover abre. `OperacoesPanel` era o único consumidor ansioso da página.

## Verificação

- `npm run build` verde local (23.2s) e `npx vite build` verde na VPS (18.3s).
- Bundle servido em produção contém o código novo (`mostrar ${n} indicador…`), asset HTTP 200.
- `/polos` responde 302 (redirect de auth, sem 500).

Pendente de conferência visual do usuário: painel fechado ao entrar, e os mesmos números ao abrir.

## Fora de escopo (pendência anotada)

Cache por chave dentro do `useAutoFilter.optionsFor` — beneficiaria a página toda (inclusive os
popovers de filtro quando abertos em sequência), mas o hook é compartilhado com outras telas e
merece verificação própria.

## Deploy

**DEPLOYADO 260731** — deploy isolado: push FF `1e8e2b9b..f5d93575`, na VPS `git reset --hard
origin/main` + `npx vite build` + `chown www-data`. **1 arquivo de código** (o `.jsx`) + 3 docs.
Sem composer, sem migrate, sem `config/route/view:cache`, sem restart de workers — nada disso se
aplica a uma mudança só de frontend (o helper `@vite` lê o manifest em runtime).
