---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 08
subsystem: portal-estrutura
tags: [retrato, sugestoes, combo, kit, combit, isolamento-por-empresa]
requires: ["168-02", "168-06", "168-07"]
provides:
  - "RetratoDoCatalogo::daEmpresa(Company): array (retrato do gerador + detalhes)"
  - "SugestoesService::gerar(Company): array{sugestoes, retrato}"
  - "Trait Tests\\Concerns\\CatalogoSinteticoDeSugestoes (paresDoTeste, catalogoSintetico)"
affects: ["168-10", "168-11", "168-13"]
key-files:
  created:
    - app/Services/Portal/Estrutura/Geracao/RetratoDoCatalogo.php
    - app/Services/Portal/Estrutura/Geracao/SugestoesService.php
    - tests/Concerns/CatalogoSinteticoDeSugestoes.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/CatalogoSinteticoTest.php
    - tests/Feature/PortalCliente/Estrutura/Sugestoes/RetratoDoCatalogoTest.php
key-decisions:
  - "Retrato com 11 consultas fixas (independe do número de produtos); empresa filtrada nas duas pontas do join de composições"
  - "O retrato carrega TODAS as variações (oferta_id nulo quando sem oferta); quem as descarta é o gerador (D-09)"
  - "SKU da variação no retrato = SKU da oferta simples ligada, com o código da variação como reserva"
requirements-completed: [PR168-01, PR168-05, PR168-06]
duration: ~25min
completed: 2026-10-07
---

# Phase 168 Plan 08: Retrato do catálogo e SugestoesService::gerar — Summary

`RetratoDoCatalogo::daEmpresa` monta o retrato da empresa em 11 consultas fixas (tipo efetivo = escolha guardada, senão inferência por categoria e depois nome; composições existentes por variação, inclusive as feitas à mão) e `SugestoesService::gerar` roda o `GeradorDeSugestoes` sobre ele; o catálogo sintético no banco dá 15 Combo, 6 Kit e 8 Combit (29).

## Commits

- `7607a9c9` feat(168): retrato do catálogo da empresa e geração das sugestões (os 5 arquivos do plano; commit único, como o plano pede)

## Verificação

- Vermelho registrado: `RetratoDoCatalogoTest` antes das classes = 10 erros (classe inexistente). `CatalogoSinteticoTest` já verde antes do retrato (6 testes, 58 asserções).
- Final: `RetratoDoCatalogoTest` (10 testes) + `CatalogoSinteticoTest` (6) + `tests/Unit/PortalEstrutura/Geracao` = 141 testes, 1123 asserções, exit 0.
- Casos cobertos: 29 sugestões; variação sem oferta fora de toda chave; tipo inferido (categoria a_confirmar), escolhido e tipo excluído voltando à inferência; Kit feito à mão em `existentes` (Kits 6 para 5); combo sobre oferta antiga sem produto ignorado; descarte com data; duas empresas sem vazamento; contagem de consultas igual com +10 produtos.
- `RetratoDoCatalogo.php`: toda consulta de dado de empresa tem `where('company_id'`; sem consulta dentro de laço; só lê (D-04).

## Deviations from Plan

None - plano executado como escrito. Um ajuste só no próprio teste: a expectativa do caso de isolamento tirava a v203 da lista do retrato, mas o retrato a carrega (com `oferta_id` nulo) e o gerador é quem a descarta; a expectativa foi corrigida, o código não mudou.

## Observações para os próximos planos

- Saída de `gerar`: `sugestoes` (contrato do 168-07) e `retrato` com `detalhes.{variacoes, produtos, tipos, tem_produtos}`. `detalhes.produtos[id].tipo_escolhido` é nulo quando o tipo guardado foi excluído.
- O trait traz `V101..V1001` como códigos/SKUs (mapas `produtos` por nome, `variacoes` por código, `ofertas` por SKU); `V203` não tem oferta. `paresDoTeste()` é idempotente e mexe em tabelas globais (tipos e pares).
- Descarte chega como `descartadas[chave] = 'Y-m-d'` do `created_at`.
- Dados 100% sintéticos; a planilha real não foi lida.

## Known Stubs

None.

## Threat Flags

None. T-168-20, T-168-21 e T-168-22 mitigados e testados.

## Self-Check: PASSED

- Arquivos dos 5 caminhos existem; commit `7607a9c9` presente; STATE.md e ROADMAP.md não tocados.
