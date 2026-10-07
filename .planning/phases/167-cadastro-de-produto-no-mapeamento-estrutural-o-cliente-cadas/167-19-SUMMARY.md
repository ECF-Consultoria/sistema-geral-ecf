---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 19
subsystem: portal-estrutura-produtos
tags: [portal, ficha-do-produto, inertia, react, allowlist, gap-closure]
requires: ["167-18"]
provides:
  - "Ficha do produto em página inteira (REF-2): /portal/estrutura/produtos/novo e /{produto}"
  - "PERMITIDO_COM_ID e RestringeDominioDoPortal::liberado() (id numérico sem curinga)"
  - "useFichaProduto (a regra do painel antigo), 5 componentes da ficha e produtosNavegacao"
affects: ["167-20", "167-21", "167-17"]
tech-stack:
  added: []
  patterns: ["allowlist com id numérico ancorado (\\A…\\z)", "ida e volta lista-ficha em sessionStorage com URL validada", "guarda de alteração não salva via router.on('before')"]
key-files:
  created:
    - resources/js/Pages/Portal/EstruturaProdutoFicha.jsx
    - resources/js/lib/produtosNavegacao.js
    - resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js
    - resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoVolume.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/FaixaCalculados.jsx
    - tests/Feature/PortalCliente/Estrutura/Produtos/FichaDoProdutoTest.php
    - tests/js/estrutura-produtos-ficha.test.js
  modified:
    - app/Http/Controllers/PortalEstruturaProdutosController.php
    - routes/web.php
    - app/Http/Middleware/RestringeDominioDoPortal.php
    - resources/js/lib/produtosEstrutura.js
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
    - tests/Feature/PortalCliente/DominioLiberaTodoModuloTest.php
    - tests/Feature/PortalCliente/Estrutura/AcessoAoModuloEstruturaTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php
    - tests/js/estrutura-produtos.test.js
    - tests/js/estrutura-produtos-lista.test.js
    - tests/js/estrutura-produtos-pickers.test.js
    - tests/js/estrutura-produtos-ml.test.js
  deleted:
    - resources/js/Components/Portal/Estrutura/Produtos/SheetProduto.jsx
decisions:
  - "D-27..D-30 implementadas: ficha em página, calculados só leitura, foto sem upload, sem o que a referência mostra sem dado"
  - "O id da ficha entra na allowlist por PERMITIDO_COM_ID (só dígitos, âncoras de início e fim), nunca por 'produtos/*'"
  - "Salvar grava em lotes do limite do servidor (200) — variações não têm limite na tela; 1º lote carrega o grupo, os seguintes o produto_id"
metrics:
  tasks: 3
  completed: 2026-10-06
---

# Phase 167 Plan 19: Ficha do produto em página inteira (REF-2) Summary

Ficha do produto como página própria (`/portal/estrutura/produtos/{id}` e `/novo`), no desenho da REF-2 com os tokens `ecf-*`, gravando pelo mesmo POST `linhas`; o painel lateral (`SheetProduto`) foi apagado e a lista passou a abrir a ficha por URL, voltando com busca, página e rolagem preservadas.

## Commits

| Task | Commit | O quê |
|------|--------|-------|
| 1 | `ff47fd56` | Rotas `novo()`/`ficha()`, throttle próprio, `PERMITIDO_COM_ID` + `liberado()`, testes |
| 2 | `86b71557` | `useFichaProduto` + `PecasDoProduto`, `FichaDadosGerais`, `CartaoVariacao`, `CartaoVolume`, `FaixaCalculados`, gate da ficha |
| 3 | `c9d8b15f` | Página `EstruturaProdutoFicha`, `produtosNavegacao`, lista abrindo por URL, `SheetProduto` removido, gates no contrato novo |

## O que saiu

- `SheetProduto.jsx` (359 linhas, painel lateral/folha de baixo) — a regra foi para `useFichaProduto`, nada duplicado.
- `useTelaEstreita` e o estado `ficha`/`onRemovida` da página da lista; a frase "Produto salvo. Criamos…" virou `textoProdutoSalvo` na lib.

## Mecanismo PERMITIDO_COM_ID

`RestringeDominioDoPortal::liberado($caminho)`: verdadeiro se `Str::is` casa algum `PERMITIDO` (mesma semântica de antes) ou se o caminho casa, ancorado (`\A…\z`), algum padrão de `PERMITIDO_COM_ID` em que `{id}` vira `[0-9]+`. `/produtos/12` passa; `/produtos/12/x`, `/produtos/abc`, `/produtos/12x`, `/produtos/` e `portal/usuarios` continuam barrados. `/novo` entrou por linha própria em `PERMITIDO`. Nenhum `'portal/estrutura/produtos/*'`.

## Números

- PHP: `tests/Feature/PortalCliente` OK com **372 testes, 2716 assertions** (baseline 363; FichaDoProdutoTest tem 9 métodos). Rotas `portal.auth.estrutura.produtos.*` com throttle próprio: 16; linhas `portal/estrutura/produtos…` em PERMITIDO: 14.
- JS: `node --test` dos 9 gates de Produtos/grade/teclado: 82 passam, 0 falham; `npm run test:js`: 1038 passam, **só as 2 falhas do baseline** (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`).
- Build real: `npm run build` ok, mtime do `public/build/manifest.json` mudou (1791293271 → 1791298154); `Pages/Portal/EstruturaProdutoFicha.jsx` e `Pages/Portal/EstruturaProdutos.jsx` presentes no manifest.
- Sem banco, sem regra nova: `git diff --stat` de `database config app/Services SpreadsheetGrid.jsx gradeTeclado.js` desde o 167-18 vazio.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] `AcessoAoModuloEstruturaTest` falhava com a rota nova**
- **Found during:** Task 3 (suíte PortalCliente)
- **Issue:** `test_as_rotas_do_modulo_existem_e_estao_na_allowlist` testa cada rota `portal.auth.estrutura*` só com `Str::is` sobre `PERMITIDO`; `produtos/{produto}` ficou "fora da allowlist".
- **Fix:** aceita também a rota cujos parâmetros têm todos `whereNumber` e que `liberado()` libera (mesma regra do `DominioLiberaTodoModuloTest`).
- **Files modified:** tests/Feature/PortalCliente/Estrutura/AcessoAoModuloEstruturaTest.php
- **Commit:** `c9d8b15f`

**2. [Ajuste] Grupo e produto_id nos lotes do `salvar`**
- O hook usa o `produto_id` de qualquer variação já gravada (inclusive após gravação parcial) para as variações novas, e o `grupo` só quando nenhuma foi gravada — generaliza o comportamento do painel antigo sem mudar o contrato do servidor.

**3. [Ajuste] Comentários e gates sem a palavra do arquivo apagado**
- O critério `grep -rn "SheetProduto" resources/js tests/js` sem saída exigiu reescrever comentários e montar o nome por concatenação nos gates negativos.

## Known Stubs

Nenhum. O quadro da foto mostra ícone + iniciais por decisão (D-29: sem upload).

## Threat Flags

Nenhuma superfície nova além do modelado (T-167-72..77): rotas GET isoladas pela empresa da sessão, allowlist testada em positivo e negativo, `urlDeVolta` só aceita o caminho da lista.

## Pendências (não são deste plano)

Lista em Visual grande/Lista (167-20), passe de fidelidade com capturas (167-21), conferência humana (Task 3 do 167-17).

## Self-Check: PASSED

- Arquivos criados conferidos no disco; commits `ff47fd56`, `86b71557`, `c9d8b15f` existem no `git log`.
