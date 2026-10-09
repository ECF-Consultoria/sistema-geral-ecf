---
phase: 176-ficha-do-portal-completa-ate-o-publicador
plan: 05
subsystem: portal-estrutura-produtos
tags: [descricao, ficha, sigilo, react]
requires: [176-01, 176-02]
provides:
  - PUT /portal/estrutura/produtos/{produto}/descricao (escopo por empresa, max 5000, vazio limpa, auditado)
  - prop `descricao` na ficha e bloco "Descrição do produto" gravado no mesmo Salvar
affects: [176-09, 176-11]
key-files:
  created:
    - app/Services/Portal/Estrutura/Produtos/DescricaoDoProduto.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/DescricaoDoProdutoTest.php
    - resources/js/Components/Portal/Estrutura/Produtos/useDescricaoProduto.js
    - resources/js/Components/Portal/Estrutura/Produtos/FichaDescricao.jsx
    - tests/js/estrutura-produtos-descricao.test.js
  modified:
    - app/Http/Controllers/PortalEstruturaProdutosController.php
    - routes/web.php
    - app/Http/Middleware/RestringeDominioDoPortal.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/FichaTecnicaDoProdutoTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php
    - resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js
    - resources/js/Pages/Portal/EstruturaProdutoFicha.jsx
requirements: [FP176-02, FP176-03]
completed: 2026-10-08
---

# Phase 176 Plan 05: descrição do produto na ficha

Descrição por produto ponta a ponta: serviço (trim, vazio vira null, só grava e audita se mudou), endpoint com 404 antes da validação, prop na ficha, linha exata na allowlist do domínio e bloco na tela, gravado logo depois da ficha técnica no mesmo Salvar.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 | 3c9e5338 | feat(176-05): endpoint e serviço da descrição do produto na ficha |
| 2 | 96634977 | test(176-05): sigilo cobre descricao e estoque; assertSemOrigem decodifica acento escapado |
| 3 | 60f96fdc | feat(176-05): bloco Descrição do produto na ficha, gravado no mesmo Salvar |
| fix | 09d0d880 | test(176-05): contagem de rotas e allowlist do AcessoAosProdutosTest |

## Testes

- PHP: DescricaoDoProdutoTest + DominioLiberaTodoModuloTest + FichaDoProdutoTest: 17 testes, OK. FichaTecnicaDoProdutoTest: 25 testes, OK.
- PHP `tests/Feature/PortalCliente` inteiro: 606 testes; fora de `Sugestoes` (14 falhas do baseline) as 2 únicas falhas eram minhas (AcessoAosProdutosTest, contagem de rotas e allowlist), corrigidas; AcessoAosProdutosTest 14 OK.
- JS: `tests/js/estrutura-produtos-*.test.js`: 157 testes, 0 falhas.
- `npm run build` exit 0; manifest contém `EstruturaProdutoFicha`.

## Mutação (Task 2)

Troquei a mensagem do estoque em `NormalizadorDeLinha` para "Informe o estoque do anúncio." e o `test_campos_novos_nao_revelam_origem` falhou ("anúncio vazou em estoque inválido"); mensagem restaurada (arquivo sem diff).

## Deviations from Plan

**1. [Rule 1 - Bug] `assertSemOrigem` não pegava acento**
- O JSON do Laravel escapa acento (`anúncio` vira `anúncio`), então a varredura por "anúncio" passava batido; a primeira mutação NÃO falhou por isso.
- Fix: `assertSemOrigem` decodifica `\uXXXX` antes de varrer. Os testes de sigilo anteriores continuam verdes.

**2. [Rule 3 - Bloqueio] AcessoAosProdutosTest**
- Travava rotas `portal.auth.estrutura.produtos.*` em 22 e a lista exata de `PERMITIDO_COM_ID`. Atualizados para 23 e com a linha `{id}/descricao`. Arquivo fora da lista do plano.

**3.** O estoque inválido no POST de linhas responde 200 com `erros` (não 422); o teste verifica `erros` não vazio e varre o corpo.

## Known Stubs

Nenhum.

## Self-Check: PASSED
