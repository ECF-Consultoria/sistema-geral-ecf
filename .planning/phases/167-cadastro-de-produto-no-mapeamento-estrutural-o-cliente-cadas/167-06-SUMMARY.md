---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 06
subsystem: portal-cliente / mapeamento-estrutural
tags: [produtos, cadastro, normalizacao, categoria-ml, listas]
requires: [167-02, 167-03, 167-05]
provides:
  - NormalizadorDeLinha::normalizar (leitura única de linha, função pura)
  - ProdutoLinhas (pagina, paraProdutos, POR_PAGINA=100) — a forma única da linha para a tela
  - ProdutoCadastroService::gravarLinhas (MODO_GRADE, MODO_IMPORTACAO, chaveCodigo)
affects: [167-07, 167-08, 167-09, 167-10]
tech-stack:
  added: []
  patterns: [savepoint por linha, comparação de código no PHP, estado do lote em memória, memo de categoria por lote]
key-files:
  created:
    - app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php
    - app/Services/Portal/Estrutura/Produtos/ProdutoLinhas.php
    - app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
    - tests/Unit/PortalEstrutura/NormalizadorDeLinhaTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/ProdutoLinhasTest.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/CadastroDeProdutoTest.php
  modified: []
key-decisions:
  - "Célula em branco não apaga: texto vazio em custo/volumes_texto/variação não entra em `presentes`; null explícito em custo e lista vazia em volumes/ambientes limpam de propósito"
  - "Avisos do normalizador são mensagens em pt-BR (o código 'medidas_ilegiveis' está só em comentário); o serviço os prefixa com o código da variação"
  - "Estado do lote (variações/produtos da empresa, memo de categoria, o que a 1ª linha de cada produto pediu) é carregado uma vez e só é atualizado depois que a linha grava — linha recusada não deixa rastro em memória"
  - "Categoria já confirmada e igual à do produto não é revalidada no ML (a grade devolve a categoria em toda linha)"
requirements-completed: [PR167-02, PR167-03, PR167-04, PR167-11]
duration: ~45min
completed: 2026-10-05
---

# Phase 167 Plan 06: Cadastro por linha (normalizador, linha de saída, gravação) Summary

Um serviço de escrita para grade, celular e importação: `NormalizadorDeLinha` lê a linha, `ProdutoCadastroService::gravarLinhas` grava produto → variações → volumes com erro por linha, e `ProdutoLinhas` devolve cada variação já com logística, frete estimado e pendências calculados no PHP.

## Commits

- `808dd99d` — NormalizadorDeLinha + teste unitário (9 testes)
- `cd753200` — ProdutoLinhas + ProdutoLinhasTest (7 testes)
- `08895fe4` — ProdutoCadastroService + CadastroDeProdutoTest (15 testes)

## O que foi feito

- **Normalizador (função pura):** código e nome obrigatórios; coluna Variação aceita ordinal ("2", "única"), "Eixo: valor" (eixo fora da lista vira "outro" com aviso) e valor solto; eixo por chave ou rótulo; ambientes por `/`, `,`, `;`, `|` ou array; categoria `MLB…` vira id, texto vira "a confirmar"; custo e medidas por `NumeroBr`; volumes ilegíveis avisam e não entram em `presentes`.
- **ProdutoLinhas:** 100 produtos por página, busca por nome/código do produto e código de variação, uma única chamada `FreteMe2Service::estimar` por montagem, oferta lida em uma consulta (`withCount('anuncios')`, `usadaEm.oferta`).
- **gravarLinhas:** savepoint por linha; código único por empresa comparado no PHP sem caixa/acento (`chaveCodigo`) com SQLSTATE 23000 como rede; modo importação atualiza pelo código e classifica `sem_mudanca` sem tocar em `updated_at`; variação nova de produto existente copia eixo/custo/volumes da 1ª; família/ambientes por `resolverNomes` com `criadas_nas_listas`; categoria só folha, ML fora do ar = "não validada", memoizada por lote; ids de outra empresa dão "Produto não encontrado."; limite de 200 (grade) / 1000 (importação); UM `produtos_gravados` por lote com origem cliente/interno.

## Deviations from Plan

Nenhuma de regra 1-4. Dois detalhes de interpretação:

- O plano diz "aviso 'medidas_ilegiveis'"; o aviso é mensagem legível e o código fica em comentário (a assinatura é `list<string>`).
- Um bug de meu próprio código (arrow function capturando `$avisos` por valor, aviso de divergência perdido) foi pego pelo teste e corrigido antes do commit da Task 3.

## Testes

- `tests/Unit/PortalEstrutura` + `tests/Feature/PortalCliente/Estrutura/Produtos`: 114 testes OK (477 asserts).
- Depois do último commit: `tests/Feature/PortalCliente/Estrutura` + `tests/Unit/PortalEstrutura` inteiros: **197 testes OK, 1201 asserts, exit 0**.
- Nenhuma chamada HTTP real: `Http::fake` (ProdutoLinhasTest ainda afirma `assertNothingSent`).

## Known Stubs

`oferta` sai nula quando a variação não tem oferta; criar/sincronizar a oferta simples e excluir variação são do 167-07 (previsto no plano).

## Threat Flags

Nenhum além do modelo do plano (T-167-20..25 mitigados: `company_id` sempre do parâmetro, mensagem igual para outra empresa e inexistente, limites no normalizador, log por lote, ≤200 linhas e categoria memoizada).

## Self-Check: PASSED

Arquivos criados conferidos e commits `808dd99d`, `cd753200`, `08895fe4` presentes.
