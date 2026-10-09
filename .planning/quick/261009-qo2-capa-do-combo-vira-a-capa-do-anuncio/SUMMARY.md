---
tipo: quick
slug: qo2-capa-do-combo-vira-a-capa-do-anuncio
data: 2026-10-09
status: complete
commits:
  - 553e276c feat(fase2) — combo usa a MESMA capa no Clássico e no Premium
  - 821dda07 fix(fase2) — a capa gerada do combo passa a ser a foto 1
---

# O que estava errado

A Fase 2 gerava a capa do combo, pagava (~R$ 1,10 por 2 slots) e **publicava com a foto do
produto base**. A capa gerada ia para o fim da galeria nos dois tipos de anúncio.

Ninguém sabia: não estava no `deferred-items.md` da Fase 175, e o docblock do
`CapaDoKitService` afirmava o oposto ("a imagem só vira **foto 1** do kit depois de
aprovada"). O furo de cobertura que escondeu: o fixture do `CapaDoKitTest` tem **um alvo
só**, então a rotação Clássico/Premium de um kit nunca rodou em teste.

# O que foi feito

1. **Combo não rotaciona a capa** (decisão do usuário em 09/10: *"use a mesma foto tanto
   para clássico quanto para o premium, nesse caso pode quebrar aquela regra"*).
   `RascunhoSnapshot` ganhou `unidadesPorOferta` e o payload usa `indiceParaCapa = 0` para
   todo alvo quando é combo. `OrdemCapaPorAlvo` ficou **puro e intocado** — "não rotacionar"
   se expressa passando sempre 0, sem ramo dentro da função.

2. **A capa aprovada do combo assume a posição 0**, empurrando as herdadas do base.
   `colocarFotoNoGrupo(..., naFrente: true)` é **opt-in**: o default `false` é o que upload
   manual e toda aprovação da Fase 1 usam.

3. **Gatilho estruturado, não texto.** `PlanejarKitCriativosJob` grava
   `slot_plano['unidades_da_composicao']` junto da `cena` que já montava, e a aprovação lê
   esse inteiro. Ler a frase da `cena` seria regra de negócio extraída de texto de prompt —
   exatamente o que a disciplina TRUTH-02/03 do módulo proíbe. Conferido antes: o
   `CreativePromptBuilder::paraSlot()` lê chaves **nomeadas** e nunca itera o array, então a
   chave nova não vaza para o modelo.

# Três armadilhas que custaram tempo e ficaram documentadas no código

1. **`comEfetivos()` reconstrói o snapshot com argumentos POSICIONAIS** — e é por ele que a
   PUBLICAÇÃO passa. Campo novo esquecido ali volta ao default e o combo publicaria como
   Fase 1, sem nenhum erro aparecer. Comentado no arquivo.

2. **`snapshot()` roda em quase toda leitura do módulo.** O produto entra por `loadMissing`,
   nunca `load`, senão seria uma consulta a mais em todas elas.

3. **`VinculoDeKitTest` quebrou, e a falha estava certa.** Ele compara o snapshot "byte a
   byte" para provar que vincular não altera o rascunho — e `unidadesPorOferta` muda de 1
   para 2, porque vincular **grava** `quantidade_kit` (é o que a §6 manda). Nenhuma escrita
   nova no rascunho: mudou uma VISÃO DERIVADA. Então `unidadesPorOferta` saiu da assinatura
   **e a mudança de 1 → 2 passou a ser afirmada à parte**, para não virar omissão silenciosa.
   Qualquer deriva em categoria, atributos, eixos, variantes, estoque, alvos, fotos,
   descrição, envio ou garantia ainda reprova lá.

   ⚠️ Consequência assumida: vincular um combo já no ar faz a capa dele parar de rotacionar
   **se ele for republicado um dia**. O anúncio que está no ar NÃO é atualizado (§7 da
   ETAPA-3: atualizar o ML não é desta etapa).

# Verificação

| Suíte | Resultado |
|---|---|
| `tests/Unit/Publicador` + `tests/Feature/Publicador` | **1393 verdes**, 8468 asserções |
| `Phase160\|161\|162\|165\|168\|169\|170\|171\|Phase86` | **435 verdes** + 1 incomplete |

Testes RED escritos antes dos dois fixes, com a fronteira de 1 unidade coberta
(`test_composicao_de_uma_unidade_nao_vira_capa`). O CAPA-01 da Fase 1 segue provado pelo
teste que já existia — não foi afrouxado, foi **delimitado**.

# Limitações e pendências

- **Não deployado, não enviado.** Está em `main` local. O `CLAUDE.md` exige autorização
  explícita, e há dev em paralelo na mesma árvore.
- ⚠️ **Falta a prova real**: que o modelo de imagem obedece à composição de N unidades. Teste
  prova o prompt e a posição; só uma geração na conta #459 (~R$ 1,10) prova a imagem. É o
  Cenário F do briefing e segue sendo a última pendência de mérito da Fase 2.
- ⚠️ **Avisar o ECF Dev**: `app/Jobs/PlanejarKitCriativosJob.php` foi tocado (uma chave nova
  no `slot_plano`). Não está na lista do aviso de coordenação do `CLAUDE.md`, mas é vizinho
  do Creative Engine.
- **Catálogo (§25 do briefing) não foi implementado, de propósito:** publicar em catálogo não
  existe para NENHUMA fase. O payload nunca envia `catalog_listing`/`catalog_product_id`,
  `EstruturaAnuncio::TIPOS` só tem `classico` e `premium`, e `estrutura_anuncios.catalogo` é
  booleano **descritivo** da régua do Mapeamento (vindo da planilha), que
  `cadastrarNaRegua()` nem grava. Não é lacuna da Fase 2 — é capacidade ausente do
  Publicador inteiro, e construí-la é feature nova.
- **`kit_virtual` continua `false`** na régua para o combo publicado: o Mapeamento não sabe
  que aquele MLB é um combo. A verdade está em `pub_produtos.quantidade_kit`; a régua é
  espelho. Decisão de produto, não corrigida aqui.
