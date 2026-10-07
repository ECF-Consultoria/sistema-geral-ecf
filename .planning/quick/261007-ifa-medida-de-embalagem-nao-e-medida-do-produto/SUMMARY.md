---
quick: 261007-ifa
subsystem: creative-engine
tags: [creative-engine, product-truth, dimensoes, mercado-livre, truth-02]
requires: [Fase 161 em produção, Fase 169 (medidasConfirmadas)]
provides: [dimensions nunca mais elegível só por medida de embalagem]
affects: [app/Services/Creative/CreativeSlotCatalog.php]
tech-stack:
  added: []
  patterns: ["lista fechada de prefixo de exclusão antes de casar um padrão positivo — mesma disciplina de PADRAO_ID_CONTAGEM"]
key-files:
  created:
    - tests/Unit/Quick261007Ifa/CreativeSlotCatalogEmbalagemTest.php
  modified:
    - app/Services/Creative/CreativeSlotCatalog.php
    - tests/Unit/Phase161/CreativePlannerTest.php
decisions:
  - "Lista fechada de exclusão: SELLER_PACKAGE_* (medida informada pelo vendedor) e PACKAGE_* (atributo de sistema do ML para a mesma medida de pacote) — os dois prefixos confirmados em uso real no projeto (ClassificadorAtributos, AnuncioSaudeService::ATRIBUTOS_DIMENSAO, fixtures de categoria). SHIPPING_ ficou fora: só existe como SHIPPING_ORIGIN (localização, não medida)."
  - "Corrigido o fixture de tests/Unit/Phase161/CreativePlannerTest.php que usava SELLER_PACKAGE_WIDTH como estande-in de 'tem atributo de dimensão'; o próprio teste codificava o bug; troquei para WIDTH."
metrics:
  duration: "~35min"
  completed: "2026-10-07"
---

# Quick 261007-ifa: Medida de embalagem não é medida do produto — Summary

**`CreativeSlotCatalog::dimensions` deixou de aceitar `SELLER_PACKAGE_*`/`PACKAGE_*` (medida da caixa) como prova de medida do produto — nova lista fechada `PADRAO_ID_EMBALAGEM` exclui os dois prefixos antes de casar o padrão de dimensão.**

## O defeito (confirmado em produção, antes do fix)

`PADRAO_ID_DIMENSAO` (sufixo `_WIDTH|_HEIGHT|_LENGTH|_DEPTH` ou nome exato) casa por sufixo, sem olhar
o prefixo — então `SELLER_PACKAGE_WIDTH`, `SELLER_PACKAGE_HEIGHT` e `SELLER_PACKAGE_LENGTH` (medida da
CAIXA que o vendedor declara para frete) satisfaziam `dimensions` exatamente como `WIDTH`/`HEIGHT` do
produto.

Medido no rascunho 8 (categoria MLB31578, "Mesa Centro Sala Mesinha Base Piramide") chamando
`elegiveis()` com os atributos reais: só havia `SELLER_PACKAGE_HEIGHT/LENGTH/WIDTH` = 12 cm e
`SELLER_PACKAGE_WEIGHT` = 4000 g, zero medida de produto. Isso colocava `dimensions` na posição 2 da
fila de elegíveis, pronto para entrar no kit de 7 com uma imagem anunciando "12 x 12 x 12 cm" para uma
mesa de centro. É a mesma classe de falha de TRUTH-02/03 (o caso dos "quatro pés" num produto de
cinco): número errado no prompt é pior que número nenhum, porque o modelo obedece com confiança e a
imagem sai coerente consigo mesma, passando pela revisão humana sem levantar suspeita.

## A correção

Nova constante `PADRAO_ID_EMBALAGEM` (lista fechada de prefixo, nunca substring livre, a convenção já
documentada no topo do arquivo) e um novo método `temAtributoDeDimensaoDoProduto()` que descarta
qualquer id de embalagem ANTES de casar `PADRAO_ID_DIMENSAO`. `satisfaz('dimensions', ...)` passou a
chamar esse método em vez de `temAtributoCasando($truth, PADRAO_ID_DIMENSAO)` puro.

**Conjunto de prefixos excluído e por quê:**
- `SELLER_PACKAGE_*` é o prefixo literalmente medido no rascunho 8; confirmado como "medida da caixa
  declarada pelo vendedor" em `ClassificadorAtributos::SECAO_EMBALAGEM` e em toda a camada de payload
  do Publicador (`EditorRascunhoService`, `MlbAnuncioController`, `IaParaRascunhoService`).
- `PACKAGE_*` (sem `SELLER_`) é o atributo de SISTEMA do próprio Mercado Livre para a mesma medida de
  pacote. Confirmado em `AnuncioSaudeService::ATRIBUTOS_DIMENSAO` (os 4 atributos de dimensão do
  pacote: `PACKAGE_WEIGHT`, `PACKAGE_LENGTH`, `PACKAGE_WIDTH`, `PACKAGE_HEIGHT`) e em
  `ClassificadorAtributosTest` (`PACKAGE_HEIGHT` classificado como papel SYSTEM).
- `SHIPPING_*` foi avaliado e descartado: a única ocorrência no projeto inteiro é `SHIPPING_ORIGIN` em
  fixtures de `sale_terms.json`, é localização de origem do frete, não medida física. Incluir esse
  prefixo não teria efeito nenhum sobre `dimensions` e inventaria uma exclusão sem caso real.

**Caminho legítimo confirmado intacto:**
- Produto com medida própria (`WIDTH`, `SCREEN_WIDTH`, `HEIGHT`, etc., qualquer id que NÃO comece com
  `SELLER_PACKAGE_`/`PACKAGE_`) continua elegível a `dimensions` (teste dedicado).
- `medidasConfirmadas` (fato humano da Fase 169) continua valendo pelo operador OU lógico; mesmo quando
  o cadastro só tem embalagem, uma medida confirmada pelo operador basta.
- `fatosVerificados`/`ProductTruthBuilder` não foram tocados: `SELLER_PACKAGE_*` continua aparecendo
  normalmente como fato legível ("Seller package height: 12 cm"); é um fato real do cadastro, só não
  pode mais ser usado como PROVA de medida do produto no slot `dimensions`. Não ampliei esse escopo.

## Tasks e commits

| Task | Nome | Commit |
|---|---|---|
| 1 | Separar medida de produto e medida de embalagem no CreativeSlotCatalog | e9cc0ec3 |
| 2 | Teste que falharia com o código de antes (caso literal de produção + complemento) | e9cc0ec3 (mesmo commit, teste e fix nasceram juntos, feitos e verificados como unidade) |

## Decisões tomadas

- Lista fechada com só os dois prefixos confirmados em uso real (SELLER_PACKAGE_, PACKAGE_); não
  inventei SHIPPING_ nem nenhum outro prefixo não encontrado no código/fixtures.
- Corrigi tests/Unit/Phase161/CreativePlannerTest.php
  (test_dimensions_aparece_quando_ha_atributo_de_dimensao_e_o_llm_propoe): o fixture original usava
  SELLER_PACKAGE_WIDTH como "tem atributo de dimensão", o que significa que o próprio teste de
  baseline validava (sem querer) o comportamento que acabou de ser corrigido. Troquei para WIDTH,
  mantendo a mesma asserção (true), agora por um motivo correto.

## Deviations from Plan

Nenhuma. Plano executado como escrito. O ajuste no teste da Fase 161 não é uma Rule 1-4 no sentido
estrito (não é bug em código de produção nem funcionalidade faltante); é a consequência direta e
esperada da Tarefa 1: um fixture de teste que usava o atributo de embalagem como stand-in deixou de
fazer sentido depois que embalagem passou a não satisfazer mais `dimensions`. Documentado aqui por
transparência, não como desvio de escopo.

## Resultados de verificação (literais)

```
tests/Unit/Quick261007Ifa/CreativeSlotCatalogEmbalagemTest:                    6 tests, incluidas na rodada abaixo
Suite alvo (Quick261007Ifa + Phase169 + Phase161 + Phase162 + Phase168 + Quick261003L8o):
                                                                                 OK (97 tests, 304 assertions)

Baseline completo (tests/Feature/Publicador + tests/Unit/Publicador):          OK (816 tests, 4093 assertions)
```

Nenhuma falha, nenhum skip. O numero do baseline (816) bate exatamente com o informado nas restricoes
do ambiente.

## Issues Encountered

Nenhum. A unica "surpresa" foi descobrir que o proprio teste de regressao da Fase 161 usava o atributo
de embalagem como exemplo de "tem dimensao"; nao chegou a ser um bloqueio, so exigiu o ajuste
documentado acima.

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superficie nova. Mudanca e puramente de elegibilidade de slot dentro de CreativeSlotCatalog;
nao introduz endpoint, caminho de auth, acesso a arquivo nem schema novo.

## Self-Check

- app/Services/Creative/CreativeSlotCatalog.php contem PADRAO_ID_EMBALAGEM - FOUND
- tests/Unit/Quick261007Ifa/CreativeSlotCatalogEmbalagemTest.php - FOUND
- commit e9cc0ec3 - FOUND

## Self-Check: PASSED

## Next Phase Readiness

Correcao isolada e verificada. Nenhum bloqueio para o restante da Fase 169 (169-04, nao autorizada
aqui) nem para o Publicador novo (Fase 164/165). Nao deployado; deploy e passo separado, com o
usuario.

---
*Quick: 261007-ifa*
*Completed: 2026-10-07*
