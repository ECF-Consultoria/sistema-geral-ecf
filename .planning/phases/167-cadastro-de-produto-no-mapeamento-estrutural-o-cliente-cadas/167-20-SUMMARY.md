---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 20
subsystem: portal-cliente / mapeamento-estrutural / produtos
tags: [react, inertia, lista-de-produtos, visual-grande, lista, seletor, gap-closure]
requires: ["167-19"]
provides:
  - "Página de Produtos no desenho da REF-1 (Visual grande) e da REF-3 (Lista), com seletor persistido"
  - "CabecalhoEstrutura com variante amplo (só Produtos usa)"
affects: ["167-21"]
tech-stack:
  added: []
  patterns: ["variante opcional em componente compartilhado", "modo de visualização em localStorage dentro de try/catch", "bolinha de cor só por mapa fechado (nunca texto do usuário em style)"]
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/SeletorVisualizacao.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoProdutoGrande.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoProdutoLinha.jsx
    - tests/js/estrutura-produtos-topo.test.js
  modified:
    - resources/js/Components/Portal/Estrutura/comum.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx
    - resources/js/lib/produtosEstrutura.js
    - resources/js/lib/produtosNavegacao.js
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
    - tests/js/estrutura-produtos.test.js
    - tests/js/estrutura-produtos-janelas.test.js
    - tests/js/estrutura-produtos-ml.test.js
    - tests/js/estrutura-produtos-lista.test.js
decisions:
  - "D-25 layout das REF-1/REF-3 com tokens ecf-*; D-26 seletor persistido em localStorage (ecf.produtos.modo); D-30 menu ⋮ só com ações reais"
metrics:
  tasks: 3
  completed: 2026-10-06
---

# Phase 167 Plan 20: Visual grande e Lista em Produtos Summary

A lista de Produtos passou a seguir a REF-1 (cartões em 3 colunas) e a REF-3 (cartões horizontais), com topo de trilha em círculos, linha de ações com busca, seletor Visual grande/Lista guardado no navegador e volta da ficha preservando busca, página, modo e rolagem.

## Commits

| Task | Commit | O que entrou |
|------|--------|--------------|
| 1 | `489cbf49` | `CabecalhoEstrutura amplo`, `BarraAcoesProdutos`, `SeletorVisualizacao`, `lerModo`/`gravarModo`, gate `estrutura-produtos-topo.test.js` |
| 2 | `e5831f85` | `CartaoProdutoGrande`, `CartaoProdutoLinha`, `ListaProdutos` com `modo`, peças (`BolinhaCor`, `LinhaVariacao`, `PilulaFalta`, `MenuDoProduto`, `aoClicarNoCartao`), helpers da lib, gate da lista reescrito |
| 3 | `018b98c8` | Página reescrita no desenho das referências, gates da página/janelas/ML atualizados |

## O que mudou de lugar

- Aviso dispensável da lista -> `title` do "Importar planilha".
- "Consultar fretes no Mercado Livre" -> linha do seletor, à direita (só com conta conectada e variação ME2).
- "Como funciona" -> canto direito do topo.
- Medidas, peso cubado e custo do cartão -> dica (`title`) da variação e ficha.
- "Falta: ..." deixou de ser linha por variação: virou pílula por produto (com popover do detalhe por variação).

## Verificação

- `npm run test:js`: 1046 passam, 2 falham — exatamente o baseline (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`).
- Gates de Produtos/grade/teclado (10 arquivos): 90 passam.
- `npm run build`: exit 0, mtime do `manifest.json` mudou (11:57 -> 11:59); `Pages/Portal/EstruturaProdutos.jsx` e `EstruturaProdutoFicha.jsx` no manifest.
- `tests/Feature/PortalCliente`: OK (372 testes, 2716 asserções), sem mudança de backend.
- Cabeçalho das outras 5 páginas: ramo padrão intacto (só entra o parâmetro `amplo = false` e um `return` antecipado do ramo novo); gate confere `rounded-2xl border border-white/[0.08] bg-ecf-card p-1.5`. Sem captura lado a lado (é do 167-21).

## Deviations from Plan

**1. [Rule 3 - Blocking] Gate de `JanelaListas` lia 'Famílias e ambientes' da página**
- **Found during:** Task 3. O texto agora mora na barra; o gate `JanelaListas: usa as rotas...` de `estrutura-produtos-janelas.test.js` (linha 88) falhava.
- **Fix:** passou a ler `barra`. Mesmo commit da Task 3.

**2. Observação (sem desvio de regra):** `produtosEstrutura.js` tem CRLF na cópia de trabalho; o git normaliza para LF (diff só com as linhas acrescentadas).

## Known Stubs

Nenhum. O quadro da foto com iniciais é decisão D-29 (sem upload), não stub.

## Threat Flags

Nenhum. T-167-78 (cor só pelo mapa `CORES_CONHECIDAS`) e T-167-79 (sem HTML cru) cobertos por gate.

## Self-Check: PASSED

Arquivos criados e commits `489cbf49`, `e5831f85`, `018b98c8` conferidos no git.
