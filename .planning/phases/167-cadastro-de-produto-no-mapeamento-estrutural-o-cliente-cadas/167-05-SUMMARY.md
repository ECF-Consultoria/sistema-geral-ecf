---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 05
subsystem: portal-cliente / mapeamento-estrutural
tags: [frete, me2, mercado-livre, cache, precificacao]
requires: [167-02]
provides:
  - FreteMe2Service (estimar, cotar, dimensions)
affects: [167-10]
tech-stack:
  added: []
  patterns: [ponto fixo do preço de cotação, cotação em lote via MercadoLivreService::getMany, cache por chave de dimensões+preço]
key-files:
  created:
    - app/Services/Portal/Estrutura/Produtos/FreteMe2Service.php
    - tests/Feature/PortalCliente/Estrutura/Produtos/FreteDoProdutoTest.php
  modified: []
key-decisions:
  - "D-16/D-19: frete só exibido; nada grava em estrutura_precificacoes; só GET na conta do cliente"
  - "Convergência sem chamada extra: se o preço com o frete cai na mesma coluna da tabela (ou mesma chave de cotação), o frete confirma e não se re-cota"
  - "Orçamento de 12 conta cotações HTTP novas por chamada; item que não coube sai com a estimativa e entra em pendentes"
requirements-completed: [PR167-10]
duration: ~25min
completed: 2026-10-05
---

# Phase 167 Plan 05: Frete ME2 da variação Summary

Frete ME2 estimado na hora pela tabela da ECF (sem HTTP) e, com a conta do cliente conectada, cotado de verdade por `shipping_options/free` em lote de até 12, com cache de 6 h, só leitura.

## Commits

- `70ef6063` — FreteMe2Service e FreteDoProdutoTest (as duas tarefas num commit só: estimar e cotar vivem no mesmo arquivo e no mesmo teste, então não havia como separá-las sem commit intermediário artificial).

## O que foi feito

- `estimar()`: preço de cotação derivado do custo com os parâmetros da Precificação da empresa (preço de venda, sem acréscimo); sem custo usa `preco_referencia` e rotula como 'referencia'. Ponto fixo de no máximo `max_recotacoes`; não convergiu = `instavel`/`alerta_faixa`. ME1 e pendente voltam vazios. Se houver cotação real em cache, devolve origem 'api'.
- `cotar()`: só ME2/Full, `getMany` com o token da Company, dimensões `AxLxC,gramas`. Aviso de frete grátis obrigatório lido da resposta (mandatory sem free_shipping_by_meli). 500 em uma variação deixa só ela com `falhou` e tabela. Sem conta conectada: estimativa, zero HTTP.
- Nenhum literal 79/6000 no código (gate do 167-02 verde); `ClienteMlPublicador` não é usado.

## Verificação

- `FreteDoProdutoTest`: 12 testes, 57 asserções, OK; `tests/Unit/PortalEstrutura` verde junto (57 testes no total).
- `tests/Feature/PortalCliente/Estrutura` (pasta inteira, depois do commit): 121 testes, 904 asserções, OK.

## Deviations from Plan

Nenhuma de regra. Um ajuste de desenho: re-cotar só quando a coluna de preço muda (em vez de sempre confirmar com uma segunda chamada), o que mantém o caso comum em 1 chamada por variação e respeita o limite de 12 por requisição.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum. T-167-16..19 cobertos por teste (sem token no resultado, só GET, limite/cache, nada gravado).

## Self-Check: PASSED

Arquivos e commit `70ef6063` conferidos no git.
