---
quick: 261007-rmv
subsystem: creative-engine
tags: [creative-engine, publicador, reversao, product-truth]
requires: [Fase 169 (Planos 01/02/03), quick 261007-ifa]
provides: [kit.pode_ter_texto, kit.faltam]
affects:
  - app/Services/Creative/CreativeSlotCatalog.php
  - app/Services/Creative/CreativePlanner.php
  - app/Services/Creative/Dto/CreativePlan.php
  - app/Services/Publicador/Criativos/PublicadorCriativoKitPresenter.php
tech-stack:
  added: []
  patterns:
    - "Dado derivado do Truth gravado uma vez no plano do kit (CreativePlan::paraAuditoria(), coluna ml_anuncio_criativo_kits.plano) em vez de recalculado a cada leitura/polling - mesmo padrao ja usado para estrategia"
key-files:
  created:
    - database/migrations/2026_10_07_190000_drop_pub_produto_fatos_criativo_table.php
    - tests/Unit/Quick261007Rmv/CreativeSlotCatalogAvisoTextoTest.php
    - tests/Unit/Quick261007Rmv/CreativePlannerAvisoTextoTest.php
    - tests/Feature/Quick261007Rmv/PublicadorCriativoKitPresenterAvisoTextoTest.php
  modified:
    - resources/js/Components/Publicador/Mesa/PainelCriativos.jsx
    - resources/js/Components/Publicador/useCriativosDoPublicador.js
    - tests/js/publicador-painel-criativos-render.test.js
    - app/Http/Controllers/MlbPublicadorCriativoController.php
    - app/Services/Creative/CreativeContextBuilder.php
    - app/Services/Creative/CreativePlanner.php
    - app/Services/Creative/CreativeSlotCatalog.php
    - app/Services/Creative/Dto/CreativeContext.php
    - app/Services/Creative/Dto/CreativePlan.php
    - app/Services/Creative/Dto/ProductTruth.php
    - app/Services/Creative/ProductTruthBuilder.php
    - app/Services/Publicador/Criativos/ContextoCriativoDoPublicador.php
    - app/Services/Publicador/Criativos/PublicadorCriativoKitPresenter.php
    - routes/mlb_anuncios.php
    - tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php
    - tests/Unit/Phase160/ProductTruthBuilderTest.php
    - tests/Unit/Quick261007Ifa/CreativeSlotCatalogEmbalagemTest.php
  deleted:
    - app/Models/PubProdutoFatoCriativo.php
    - tests/Feature/Phase169/FatosCriativoEndpointsTest.php
    - tests/Feature/Phase169/PubProdutoFatoCriativoTest.php
    - tests/Unit/Phase169/ContextoCriativoDoPublicadorFatosHumanosTest.php
    - tests/Unit/Phase169/CreativePlannerFatosHumanosTest.php
    - tests/Unit/Phase169/CreativeSlotCatalogFatosHumanosTest.php
    - tests/Unit/Phase169/PubProdutoFatoCriativoMigrationGuardaTest.php
key-decisions:
  - "A mensagem de aviso deixou de depender de um endpoint dedicado: CreativePlanner::planejar() grava pode_ter_texto/faltam no CreativePlan (mesmo Truth que decidiu os slots), e o presenter do kit le isso de kit.plano - elimina reconstrucao de Truth a cada polling, sem precisar manter nenhum dos 4 nomes (fatos/carregarFatos/salvarFato/removerFato) que o prompt pedia para remover"
  - "beneficiosVerificados voltou a [] fixo (campo preservado - nasceu na Fase 160, nao na 169); medidasConfirmadas foi removido por inteiro (nasceu na 169)"
  - "O teste da ifa (CreativeSlotCatalogEmbalagemTest) perdeu o parametro medidasConfirmadas do helper truth() e o caso que testava o caminho humano - a correcao de produto-vs-embalagem em si nao mudou uma linha"
duration: ~50min
completed: 2026-10-07
---

# Quick 261007-rmv: Remover o bloco de pontos fortes/medidas digitados a mao - Summary

**Reversao deliberada do bloco de confirmacao manual de "ponto forte"/"medida" (Fase 169) - decisao do usuario depois de ver, na pratica, que o texto so sobrevive quando e identico a um VALOR ja cadastrado no Mercado Livre; fica so a quarta etapa de imagens (169-04) como caminho real, e o aviso de "ainda nao e possivel colocar texto" passou a vir do proprio kit planejado, sem endpoint dedicado.**

## O que foi removido

- **Front:** o bloco "Pontos fortes e medidas do produto" (lista de confirmados + formulario) em `Mesa/PainelCriativos.jsx`; os metodos `fatos`/`carregarFatos`/`salvarFato`/`removerFato` em `useCriativosDoPublicador.js`.
- **Servidor:** os 3 endpoints `criativos.fatos*` e suas rotas; o ramo de fato humano em `CreativeSlotCatalog::satisfaz()`; `ContextoCriativoDoPublicador::fatosHumanos()`; os campos de fato humano em `CreativeContext`/`ProductTruth`; `CreativePlanner::comTextoHumanoReforcado()`; o model `PubProdutoFatoCriativo` e a tabela `pub_produto_fatos_criativo` (migration nova de remocao - a tabela ja existe em producao, batch 167, vazia).

## O que foi preservado

1. **A mensagem de aviso.** `CreativeSlotCatalog::algumAceitaTexto()`/`faltamParaTexto()` continuam no codigo, com as duas mensagens reescritas para nao oferecer mais "aqui mesmo, como fato confirmado" - agora so apontam o cadastro do Mercado Livre. Como os quatro nomes do hook (`fatos`/`carregarFatos`/`salvarFato`/`removerFato`) tinham que sair, o dado passou a viajar por um caminho que ja existe: `CreativePlanner::planejar()` calcula `podeTerTexto`/`faltam` com o MESMO `ProductTruth` que decidiu os slots do kit, grava os dois no `CreativePlan` (persistido em `ml_anuncio_criativo_kits.plano`), e `PublicadorCriativoKitPresenter::paraTela()` devolve `pode_ter_texto`/`faltam` junto do resto do kit - sem reconstruir Truth a cada polling, sem rota nova. O front so le `c.kit.pode_ter_texto`/`c.kit.faltam`.
2. **A correcao da quick 261007-ifa, integralmente.** `PADRAO_ID_EMBALAGEM`/`temAtributoDeDimensaoDoProduto()` em `CreativeSlotCatalog` nao foram tocados - medida de embalagem continua nao satisfazendo `dimensions`. O teste dedicado (`tests/Unit/Quick261007Ifa/CreativeSlotCatalogEmbalagemTest.php`) so perdeu o parametro `medidasConfirmadas` do helper e o caso que testava o caminho humano (comportamento que nao existe mais); as 5 assercoes da correcao em si ficaram intactas.
3. **Os call-sites de `CreativePromptBuilder::paraSlot()`.** Confirmado por leitura: `GerarCriativoIaJob.php:195` continua passando `$regeneracao`/`$ajusteOperador` - nenhuma linha tocada.

## Texto final na tela

Dentro do kit planejado, quando `pode_ter_texto` e `false`:

> **Ainda nao e possivel colocar texto em nenhuma imagem:**
> Confirme mais N ponto(s) forte(s) do produto no cadastro do Mercado Livre para habilitar texto no slot de beneficios.
> Confirme uma medida do produto no cadastro do Mercado Livre para habilitar texto no slot de dimensoes.

Nenhum termo tecnico ("fato", "slot", "Product Truth") aparece - so em nomes de variavel/comentario.

## Tasks e commits

| Task | Nome | Commit |
|---|---|---|
| 1 | Front - sai o bloco, aviso lido de `kit.pode_ter_texto`/`kit.faltam` | `9c4c21bb` |
| 2 | Servidor - remove o fato humano, preserva o aviso via `CreativePlan` | `012364d6` |

## Verificacao executada (resultado real)

```
Creative Engine completo (Phase160/161/162/165/168/169, Quick261003L8o, Quick261007Ifa, Quick261007Rmv):
-> Tests: 1 incomplete, 406 passed (1974 assertions)
  (o 1 incomplete e RotasAntigasComKitDoPublicadorTest - instabilidade conhecida
  de Windows, documentada em 168-*/deferred-items.md, nao e regressao)

Baseline completo (tests/Feature/Publicador + tests/Unit/Publicador):
-> Tests: 816 passed (4093 assertions) - bate exatamente com o numero documentado

Suite JS completa (npm run test:js):
-> tests 1132, pass 1130, fail 2
  As 2 falhas sao as PRE-EXISTENTES ja documentadas (estrutura-grade-glide.test.js,
  polosEntrantes.test.js) - nenhuma menciona PainelCriativos/useCriativosDoPublicador.
  Nao sao regressao desta quick.

npm run build:
-> built in 49.54s, sem erro; grep confirma "Ainda nao e possivel colocar texto"
  em public/build/assets/Editor-CUEMBYPh.js (bundle de producao) e ausencia
  total de "Pontos fortes e medidas" em qualquer arquivo do bundle.
```

Nenhuma falha nova em nenhuma suite PHP ou JS.

## Confirmacoes pedidas no prompt de execucao

**(a) A correcao da 261007-ifa esta intacta?** Sim - `PADRAO_ID_EMBALAGEM`/`temAtributoDeDimensaoDoProduto()` nao sofreram nenhuma edicao nesta quick (confirmado por diff); so o teste perdeu o parametro/caso ligado ao fato humano removido.

**(b) Nenhum teste orfao sobrou?** Sim - os 6 arquivos de teste que existiam exclusivamente para o fato humano (Phase169 completo) foram apagados; as 2 partes adicionadas em `ProductTruthBuilderTest.php` (Phase160) e `CreativeContextBuilderPublicadorTest.php` (Phase165) foram revertidas linha a linha ao estado anterior a Fase 169 (confirmado por `git show` do diff original que as introduziu).

**(c) Os call-sites de `paraSlot()` continuam passando `$regeneracao`/`$ajusteOperador`?** Sim - unico call-site (`GerarCriativoIaJob.php:195`), confirmado por `grep`, zero linha tocada por esta quick.

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superficie nova. Esta quick so REMOVE superficie (3 endpoints, 1 tabela, 1 model) e realoca um dado derivado (pode_ter_texto/faltam) para dentro de uma resposta que ja existia (`kit.plano`/presenter), sem criar rota, sem mudar auth, sem schema novo (a migration nova so dropa uma tabela vazia que nunca foi usada).

## Self-Check

- `app/Models/PubProdutoFatoCriativo.php` - MISSING (removido, esperado)
- `database/migrations/2026_10_07_190000_drop_pub_produto_fatos_criativo_table.php` - FOUND
- `resources/js/Components/Publicador/Mesa/PainelCriativos.jsx` contem `AvisoTextoIndisponivel` - FOUND
- commit `9c4c21bb` - FOUND em `git log --oneline`
- commit `012364d6` - FOUND em `git log --oneline`

## Self-Check: PASSED

## User Setup Required

None - nenhuma configuracao de servico externo. **Migration ainda nao rodou em producao** (fora de escopo desta quick - nao houve deploy nem comando de VPS). A tabela `pub_produto_fatos_criativo` continua existindo em producao (vazia) ate o deploy rodar a migration de remocao.

## Next Phase Readiness

Pronto para a Fase 169-04 (quarta etapa de imagens no editor, trabalho separado e posterior, nao tocado aqui). `Editor.jsx`, `EtapaDetalhes.jsx`, `apoio.js`, `AnunciarML.jsx`, `PainelCriativosIa.jsx` e `KitCriativosGrade.jsx` nao foram tocados (confirmado por `git diff --stat` restrito a esses caminhos: zero linha alterada).

---
*Quick: 261007-rmv*
*Completed: 2026-10-07*
