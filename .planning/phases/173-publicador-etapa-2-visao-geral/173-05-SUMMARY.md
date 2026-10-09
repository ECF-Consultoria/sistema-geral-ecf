---
phase: 173-publicador-etapa-2-visao-geral
plan: 05
subsystem: publicador-historico
tags: [publicador, historico, pub_publicacoes, pub_publicacao_itens, inertia, react]

requires:
  - phase: 173-publicador-etapa-2-visao-geral plan 02
    provides: "MlbAnuncioController estável após o refactor de meus() (não tocado por este plano)"
provides:
  - "historico() lendo pub_publicacoes/pub_publicacao_itens/pub_rascunhos/pub_produtos em vez de ml_anuncio_rascunhos"
  - "AnunciosHistorico.jsx com fail-safe pode_duplicar que desabilita (nunca esconde) Anunciar semelhante/duplicar lote"
affects: [publicador-descontinuacao-assistente-antigo, visao-geral-publicador]

tech-stack:
  added: []
  patterns:
    - "Troca de fonte de dados preservando o shape consumido pelo front: query nova + batch lookup de SKU/foto, zero mudança de contrato"
    - "Campo novo pode_duplicar com fail-safe (=== true, nunca !== false) para desabilitar ação sem esconder"

key-files:
  created:
    - tests/js/estrutura-anuncios-historico.test.js
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - resources/js/Pages/Mlb/AnunciosHistorico.jsx
    - tests/Feature/Phase86/HistoricoAnunciosTest.php

key-decisions:
  - "listing_tier guarda o valor RAW do ML (gold_special/gold_pro), não o rótulo interno classico/premium que o array_flip(LISTING_TYPES) do plano sugeria — ver Deviations."
  - "foto resolvida via lookup em pub_imagens.ml_url pelo ml_picture_id do payload (payload só guarda o id, nunca uma URL) — ver Deviations."
  - "pode_duplicar sempre false: não existe hoje rotina de clonar PubRascunho; construir isso seria recurso novo, não troca de fonte (decisão já tomada no PLAN.md)."

patterns-established:
  - "Quando um plano descreve um mapeamento de campo via helper existente (array_flip, etc.), confirmar contra o consumidor real (aqui: LABEL_TIER_HISTORICO no front) antes de aplicar — o helper certo para UM contexto pode quebrar outro."

requirements-completed: [HIST-01]

duration: "~50min"
completed: "2026-10-08"
---

# Fase 173 Plano 05: Histórico passa a ler o editor novo (pub_publicacoes) Summary

**A aba Histórico trocou de fonte — de `ml_anuncio_rascunhos` (assistente antigo, ZERO publicações em produção) para `pub_publicacoes`/`pub_publicacao_itens` (editor novo) — sem mudar o contrato que `AnunciosHistorico.jsx` já consumia, e com "Anunciar semelhante"/"duplicar lote" desabilitados (nunca escondidos) para a fonte nova.**

## Performance

- **Duration:** ~50min
- **Tasks:** 2
- **Files modified:** 3 (+ 1 arquivo de teste novo)

## Accomplishments
- `historico()` lê a cadeia `PubPublicacaoItem` (status=CREATED) → `pub_publicacoes` → `pub_rascunhos` → `pub_produtos`, escopada pela mesma dupla-âncora do módulo (`mlb_empresa_id` OR `company_id`)
- Shape devolvido ao front **não mudou de nome em nenhuma chave** — só a origem dos valores e o campo novo `pode_duplicar`
- `AnunciosHistorico.jsx` desabilita (com `title` explicativo) "Anunciar semelhante" e "Anunciar semelhante em massa" para todo item da fonte nova, com fail-safe que nunca habilita por omissão
- 14 testes de Feature (reescritos com fixtures do editor novo) + 8 testes estruturais JS novos

## Task Commits

1. **Task 1: historico() lê pub_publicacoes** - `a959a9a2` (feat)
2. **Task 2: AnunciosHistorico.jsx desabilita ações para a fonte nova** - `be4d9d0f` (feat)

**Plan metadata:** pendente (SUMMARY/STATE/ROADMAP não commitados, conforme instrução do ambiente)

## Files Created/Modified
- `app/Http/Controllers/MlbAnuncioController.php` - `historico()` reescrito: query join em `pub_publicacao_itens`/`pub_publicacoes`/`pub_rascunhos`/`pub_produtos`, lookup em lote de SKU (`PubProduto::skuExibido()`) e foto (`pub_imagens.ml_url` via `ml_picture_id`), `pode_duplicar=false` em todo item
- `resources/js/Pages/Mlb/AnunciosHistorico.jsx` - `CardAnuncio`/`BlocoLote` ganham fail-safe `pode_duplicar === true` / `every(... !== true)`, `disabled` + `title` explicativo no terceiro estado visual (distinto de "clonando")
- `tests/Feature/Phase86/HistoricoAnunciosTest.php` - fixtures reescritas para a cadeia `PubProduto`→`PubRascunho`→`PubPublicacao`→`PubPublicacaoItem`; 14 testes (10 preservando a intenção original + 4 novos do behavior do plano)
- `tests/js/estrutura-anuncios-historico.test.js` (novo) - 8 gates estruturais (mesmo padrão de `estrutura-anunciar-massa.test.js`) cobrindo o fail-safe dos dois componentes

## Decisões tomadas durante a execução

**1. `listing_tier` guarda o valor RAW do ML, não `array_flip(LISTING_TYPES)`.**
O PLAN.md (seção Interfaces) instruía mapear `pub_publicacao_itens.listing_type_id`
para o literal interno `classico`/`premium` via `array_flip(EstruturaPublicacao::LISTING_TYPES)`
— o mesmo helper usado em `PublicacaoService::cadastrarNaRegua()`. Conferindo o
consumidor real no front (`resources/js/Pages/Mlb/anuncioHistoricoUtils.js`), a
constante `LABEL_TIER_HISTORICO` é indexada por **`gold_special`/`gold_pro`** (o
valor raw do ML — exatamente o que `MlAnuncioRascunho.listing_tier` já guardava
no modelo antigo), não por `classico`/`premium`. Aplicar o `array_flip` sugerido
faria `rotuloTier('classico')` cair no fallback `t || '—'` e mostrar o literal
cru `"classico"` em vez de `"Clássico"` — uma regressão visual direta causada
pela minha própria troca de fonte. Mantive `listing_tier = $i->listing_type_id`
sem transformação, que é exatamente o que a seção Interfaces pedia como meta
("PRESERVE EXATAMENTE este shape... `AnunciosHistorico.jsx` não deveria precisar
de nenhuma mudança de leitura de campo"). Isto é um ajuste dentro do objetivo
declarado do plano, não uma divergência de intenção.

**2. `foto` resolvida via `pub_imagens.ml_url`, não `payload['pictures'][0]['source']`.**
O PLAN.md já sinalizava incerteza aqui ("confirme a chave exata lendo um payload
real... pode ser `source` ou outra"). Conferi `PayloadBuilderUserProducts::montar()`
(a fonte real do payload de `pub_publicacao_itens`): a chave é **`id`** — o
`ml_picture_id` já enviado ao ML — nunca `source`/URL. Uma foto só é exibível
via `pub_imagens.ml_url` (gravado por `ImagemAssetService::enviarAoMl()` no
momento do upload). Implementei um lookup em lote (uma query `PubImagem::whereIn`,
não N+1) por `rascunho_id`, casando pelo `ml_picture_id` do payload, com fallback
para a primeira foto enviada do rascunho quando o id não casar (dado legado).

**3. SKU busca inclusão de `pub_produtos.sku` direto na query SQL (não via
`skuExibido()`), mas o campo exibido no item final usa `skuExibido()`.**
A busca (`LIKE`) precisa rodar em SQL contra a coluna; o `skuExibido()` (que
prioriza `oferta.sku` quando existe) só entra no lookup em lote pós-query, para
o campo `sku_origem` do item devolvido ao front — exatamente como pedido
("via `PubProduto::skuExibido()`, não a coluna cru"). Produto de origem
`portal` com oferta ligada pode, em teoria, ter `sku` de coluna diferente do
`sku` da oferta; a busca por texto aceita casar pela coluna cru (ainda vale a
pena achar o item), e o campo exibido mostra o SKU "oficial". Não é uma
inconsistência nova: o antigo modelo tinha coluna única, então este é um caso
que só existe no editor novo — documentado aqui, sem ação adicional pedida
pelo plano.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `listing_tier` sem `array_flip` — ver decisão 1 acima**
- **Found during:** Task 1
- **Issue:** seguir a literal do PLAN.md (`array_flip(LISTING_TYPES)` → `classico`/`premium`) quebraria a tradução do rótulo no front, que indexa por `gold_special`/`gold_pro`
- **Fix:** manter `listing_tier = $i->listing_type_id` (raw), sem transformação
- **Files modified:** `app/Http/Controllers/MlbAnuncioController.php`
- **Commit:** `a959a9a2`

**2. [Rule 3 - Blocking] Chave real do payload de foto é `id`, não `source`**
- **Found during:** Task 1
- **Issue:** o PLAN.md já previa a incerteza; sem resolver, `foto` seria sempre `null`
- **Fix:** lookup em lote contra `pub_imagens.ml_url` pelo `ml_picture_id`, com fallback para a primeira foto enviada
- **Files modified:** `app/Http/Controllers/MlbAnuncioController.php`
- **Commit:** `a959a9a2`

---

**Total deviations:** 2 auto-fixed (1 bug evitado, 1 bloqueio resolvido)
**Impact on plan:** Nenhum scope creep — os dois ajustes são necessários para cumprir literalmente a meta que o próprio PLAN.md declarou ("PRESERVE EXATAMENTE este shape... zero mudança de leitura de campo no front") e para o campo `foto` funcionar de verdade. Nenhuma linha fora de `historico()`/`AnunciosHistorico.jsx`/teste foi tocada.

## Conferência item a item do que não podia sumir (ver `<o_cuidado_que_esta_tarefa_exige>`)

- **"Anunciar semelhante" (individual):** continua VISÍVEL em todo item — `linkAnuncioMl`, texto, ícone intactos. Para item da fonte nova (100% dos itens hoje), fica `disabled` com `title="Ainda não é possível duplicar anúncios publicados pelo editor novo."` em vez de desaparecer. Conferido lendo o JSX final (`resources/js/Pages/Mlb/AnunciosHistorico.jsx` linhas 66-84) e pelo teste estrutural `estrutura-anuncios-historico.test.js`.
- **duplicar lote / "Anunciar semelhante em massa":** mesma regra, aplicada ao botão de cabeçalho de `BlocoLote` (linhas 170-194). A rota `mlb.anuncios.empresa.duplicar-lote` e o endpoint `duplicarLoteComoTemplate()` no controller **não foram tocados** — continuam funcionando exatamente como antes para `MlAnuncioRascunho` (confirmado pelos 2 testes preservados `anunciar_semelhante_em_massa_*`, que passam sem alteração de asserção).
- **busca:** continua filtrando por título (`family_name`) OU SKU, agrupada em `where(function...)` para não furar o escopo — testado em `busca_por_titulo_filtra_sem_furar_o_escopo` e `busca_por_sku_filtra_sem_furar_o_escopo` (novo, cobrindo especificamente SKU, que o teste antigo não exercitava isoladamente).
- **agrupamento por lote:** mesma chave (`categoria . '|' . dia`), testado em `anuncios_da_mesma_categoria_e_dia_colapsam_num_unico_lote` e `mesma_categoria_em_dias_diferentes_sao_lotes_separados`.
- **paginação:** `LengthAwarePaginator` com 12 por página, idêntico; teste novo `paginacao_traz_12_lotes_por_pagina` prova 13 lotes → 12 na página 1, 1 na página 2 (cenário do behavior do plano que o teste antigo não cobria).
- **os 6 rascunhos antigos (status `rascunho` em `ml_anuncio_rascunhos`):** `historico()` nunca os listava (sempre filtrou por `status=publicado`) — continuam fora daqui e **inalterados** em Meus Anúncios › sub-aba Rascunhos, Em massa e na lista do assistente antigo, porque nenhuma dessas três telas foi tocada por este plano (confirmado por grep: nenhuma alteração em `meus()`, `massa()`, `wizard()`).

## Verificação executada (resultado real)

```
HistoricoAnunciosTest ................................ 14 passed (44 assertions)
tests/Feature/Publicador + tests/Unit/Publicador ..... 849 passed (4226 assertions), 0 failed
npm run test:js ....................................... 1359 passed, 2 failed (pré-existentes:
                                                          estrutura-grade-glide.test.js,
                                                          polosEntrantes.test.js — nenhuma nova)
npm run build .......................................... OK, AnunciosHistorico presente no manifest
```

A contagem de `tests/Feature/Publicador`+`tests/Unit/Publicador` subiu de 844
(baseline do 173-02) para 849 — 5 a mais, mas nenhum dos 4 testes novos deste
plano está nesse caminho (`HistoricoAnunciosTest` mora em
`tests/Feature/Phase86/`, fora do filtro); o aumento vem dos outros executores
paralelos (173-01/173-03/173-04) que também rodam na mesma árvore. 0 falhas
confirma que nada vizinho quebrou.

## Issues Encountered
Nenhum bloqueio além dos dois pontos já documentados em Deviations (ambos
previstos como incerteza pelo próprio PLAN.md e resolvidos lendo o código real
em vez de supor).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- A Visão geral (plan 04) e o Histórico agora contam a mesma história — os
  números de publicação batem com a aba de destino.
- A descontinuação completa do assistente antigo (Em massa, wizard, Meus
  Anúncios, e uma rotina real de clonar `PubRascunho` para substituir
  "Anunciar semelhante"/"duplicar lote") continua como etapa futura, fora
  desta fase — nenhum trabalho deste plano bloqueia ou força essa decisão.

---
*Phase: 173-publicador-etapa-2-visao-geral*
*Completed: 2026-10-08*
