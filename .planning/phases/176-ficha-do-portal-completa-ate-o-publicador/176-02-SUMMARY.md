---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 02
subsystem: portal-estrutura-produtos
tags: [estoque, ficha, normalizador, react]
requires: [176-01]
provides:
  - estoque por variação validado no servidor (int >= 0, teto 99.999.999), devolvido nas linhas e editável na ficha
affects: [176-08]
key-files:
  modified:
    - app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php
    - app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
    - app/Services/Portal/Estrutura/Produtos/ProdutoLinhas.php
    - resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js
    - resources/js/lib/produtosEstrutura.js
    - tests/js/estrutura-produtos-ficha.test.js
  created:
    - tests/js/estrutura-produtos-estoque.test.js
requirements: [FP176-01, FP176-03]
completed: 2026-10-08
---

# Phase 176 Plan 02: estoque por variação na ficha

Estoque ponta a ponta: normalizador (0 distinto de vazio; `null` explícito limpa; ausente ou `''` não mexe), gravação sem herança da 1ª variação, linha devolvida, campo "Estoque (un.)" no cartão e contrato JS.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 | 9b10bd76 | feat(176-02): estoque por variação no servidor |
| 2 | ver `git log` | feat(176-02): campo Estoque por variação na ficha do portal |

## Testes

- PHP: NormalizadorDeLinhaTest, GravarLinhasTest, ModeloEImportacaoTest, ProdutoLinhasTest: 67 testes, 498 asserções, OK (importação intacta).
- JS: `tests/js/estrutura-produtos-*.test.js`: 153 testes, 0 falhas.
- `npm run build` exit 0; manifest contém `EstruturaProdutoFicha`.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] Gate antigo `hook.includes('toque')`**
- Em `tests/js/estrutura-produtos-ficha.test.js`, a asserção de que o hook não contém "toque" (texto de UI antigo) passou a falhar porque `estoque` contém "toque".
- Fix: trocado por `/\btoque\b/.test(hook)`. Arquivo fora da lista do plano.

**2. Gate de sigilo do cartão restrito ao bloco do estoque**
- O cartão já tem "anúncio(s)" na linha da oferta (preexistente, interno à Lista SKUs). O gate do plano (arquivo todo) falharia; o gate novo vale só para o bloco do campo Estoque.

**3. Grade do cartão**: além da 5ª coluna, `lg:max-w-[775px]` virou `[905px]` para o campo novo caber.

Erro do estoque aparece na faixa de erro da variação (`erros[k]`), como os demais; não há erro por campo.

## Known Stubs

Nenhum.

## Self-Check: PASSED
