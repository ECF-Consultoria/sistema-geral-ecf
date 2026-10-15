---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 08
subsystem: publicador-aprovacao
status: PARCIAL — tarefa 1 concluída; tarefas 2 e 3 DEVOLVIDAS COMO PERGUNTA
tags: [bloqueios, vocabulario, cadeia-exclusiva, fotos, excecao, gates, mutacao]
requires:
  - 186-02 (as 11 chaves de bloqueio em pt-BR, no módulo `.jsx`)
  - 186-03 (os conjuntos e as imagens da revisão)
  - 186-04 (a oferta do portão e a única porta de andamento)
  - 186-05 (histórico, ajustes e exceção autorizada)
  - 186-07 (a composição financeira: `incompleto`, `resultado`, `margem`, mínimo resolvido)
provides:
  - "app/Services/Publicador/Aprovacao/VocabularioDoPortao.php — o lado PHP das 9 abreviações, dos 8 rótulos de filtro e do mapa de conferência com o protótipo"
  - "app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php — os 8 bloqueios por oferta, com a cadeia EXCLUSIVA, o rodapé de BLOQ-12 e o dado `aprovavel`"
affects:
  - 186-09 (o gate (g) da sincronia PHP × JS agora tem os DOIS lados)
  - 188 (o botão "Aprovar para publicação" consome `aprovavel` + o motivo)
  - 193 (a publicação sai do gancho `aoAprovar()`)
tech-stack:
  added: []
  patterns:
    - "o PHP é a FONTE do vocabulário de bloqueio; o `.jsx` é o espelho, e a prova LÊ o arquivo"
    - "a exceção decide se o bloqueio é EMPILHADO, nunca a condição da cadeia"
key-files:
  created:
    - app/Services/Publicador/Aprovacao/VocabularioDoPortao.php
    - app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php
    - tests/Unit/Publicador/Aprovacao/BloqueiosDaOfertaServiceTest.php
    - tests/Feature/Publicador/Aprovacao/BloqueiosContraOPrototipoTest.php
    - tests/gates/contagens-186-08.txt
  modified: []
decisions:
  - "A cadeia `frete_ausente → resultado_negativo → margem_abaixo_do_minimo` é um `if/elseif/elseif` único, e a EXCEÇÃO entra no `push`, não na condição"
  - "O bloqueio `frete_ausente` nomeia a fonte que de FATO falta (5 linhas anulam o resultado, não só o frete) — forma do protótipo preservada, conteúdo verdadeiro"
  - "A ordem dos grupos de foto é `GENERAL` primeiro, depois por `id`: `pub_aprovacao_conjuntos` NÃO tem coluna `posicao`"
  - "Os 8 bloqueios da fase = os 9 que `info()` empilha menos `em_preparacao` (aviso de preparo, correção vazia)"
metrics:
  tasks_completed: 1
  tasks_returned: 2
  commits: 4
  tests_added: 39
  assertions_added: 746
  mutations: 22
  mutations_killed: 21
  mutations_survived: 1
completed: 2026-10-15
---

# Fase 186 Plano 08: os 8 bloqueios com a cadeia exclusiva — Summary

Os **8 bloqueios** da oferta nascem no servidor com a cadeia
`frete_ausente → resultado_negativo → margem_abaixo_do_minimo` **exclusiva**, o bloqueio de fotos
cobre **todos os grupos do rascunho**, e o lado **PHP** do vocabulário de bloqueio passou a existir
— mas **aprovar, reabrir e as regras de imagem ficaram de fora**, porque a transição que `BLOQ-06`
exige **não existe** na lista fechada da única porta.

---

## ⚠️⚠️ PARE E DEVOLVA — A PERGUNTA QUE BLOQUEOU AS TAREFAS 2 E 3

**`BLOQ-06` exige a transição `aprovado → aguardando_aprovacao`, e ela NÃO EXISTE.**

`REQUIREMENTS-v27.md:440-444` é literal: *"incrementa `version` e, **se a oferta estava
`approved`, volta para `awaiting`** com o registro 'Aprovação reaberta: …'"*. O protótipo faz o
mesmo (`design_handoff_publicador/Aprovacao.dc.html:1149`, `reopen()`:
`o.version++; if (o.status === 'approved') { o.status = 'awaiting'; … }`).

Mas a **lista fechada** de `PubAprovacaoOferta::TRANSICOES`
(`app/Models/PubAprovacaoOferta.php:148-157`) não tem esse par. **Medido agora, por `tinker`:**

```
pares declarados: 12
aprovado   -> aguardando_aprovacao   : RECUSADA
aprovado   -> em_revisao_ecf         : RECUSADA
aprovado   -> ajustes_solicitados    : RECUSADA
aprovado   -> publicando             : PERMITIDA
```

E o par **não pode ser acrescentado por mim**, por três motivos que caem exatamente nas regras de
parada:

1. **É a única porta.** `TRANSICOES` é a lista fechada de que `AndamentoDaOfertaService` depende, e
   o território do plano 186-08 diz "**CHAME**, não altere".
2. **Reprovaria o gate de outro plano.**
   `tests/Unit/Publicador/Aprovacao/AndamentoDaOfertaServiceTest.php:262` afirma
   `assertSame(12, $pares, 'o número de transições aceitas mudou — transição a mais aqui publica
   sem ninguém ter conferido')`. Acrescentar o par faz 13 e reprova o gate `transicoes` do
   manifesto `contagens-186-04.txt`. **Não enfraqueci o gate para "fazer passar".**
3. **Não é detalhe de implementação, é decisão de desenho.** `aprovado` é o estado em que
   **aprovar já publicou** (Fase 193 enfileira no `aoAprovar()`). "Voltar de `aprovado`" é uma
   afirmação sobre o que acontece quando alguém mexe na foto de uma oferta **que já foi ao ar** — e
   isso é pergunta para o usuário, não dedução minha.

### Há precedente do lado estreito, e ele reforça a pergunta

O plano **186-05** bateu na MESMA parede em `AND-07` (*"pedir ajuste move as ofertas afetadas para
`changes`"*, inclusive de `approved`, como faz `submitAdjust` em `:1198`). Ele resolveu
**estreitando**: `AjustesDaOfertaService.php:291` só move quem está `LIVE`
(`in_array($afetada->andamento, PubAprovacaoOferta::LIVE, true)`), deixando a oferta `aprovado`
parada. Estreitar ali custa pouco — um ajuste pedido numa oferta já aprovada fica registrado e não
muda o estado.

**Estreitar aqui custa a milestone inteira:** a reabertura por mudança de foto é **a** trava de
`BLOQ-06`, e é ela que impede que uma foto trocada depois da aprovação vá ao ar. Com a oferta
presa em `aprovado`, ela também não é `live` ⇒ **não é editável** (`PubAprovacaoOferta::podeEditar()`)
⇒ ninguém conseguiria consertar a foto que acabou de mudar.

### As três saídas, para o usuário escolher

| # | Saída | O que custa |
|---|-------|-------------|
| **A** | Acrescentar `aprovado → aguardando_aprovacao` à lista fechada (13 pares) e **remedir** o gate `transicoes` do 186-04 | mexe num modelo da wave 1 e no manifesto de outro plano; é o que o REQUIREMENTS pede ao pé da letra |
| **B** | Não mudar o estado: a reabertura fica **só** no `versao > versao_aprovada` (`aprovacaoReaberta()`, que **já existe** no modelo) | a fila continua mostrando "Aprovado", a oferta não é editável, e a Fase 188 teria de tratar "aprovado-mas-reaberto" como um nono estado de tela |
| **C** | Reabrir via `aprovado → ajustes_solicitados` (também ausente hoje) | mesma mudança estrutural de (A), com rótulo que mente: ninguém pediu ajuste |

➡️ **Minha leitura (não executada):** (A) é a única que entrega `BLOQ-06` como escrito, e o gate do
186-04 é feito para **reprovar quando a lista muda** — ele cumpriu o papel dele. Mas a decisão é do
usuário.

**Enquanto isso não se decide, ficaram de fora:**
`AprovacaoDaOfertaService` (tarefa 2 inteira: aprovar · reabrir · conferir alteração · lote ·
concorrência por versão · `salvarRevisao`) e `RevisaoDeImagemService` (tarefa 3: as 11 regras de
imagem e `IMG-06`).

---

## O que foi entregue (tarefa 1)

| Tarefa | Nome | Commit | Arquivos |
|--------|------|--------|----------|
| 1 | `VocabularioDoPortao` + `BloqueiosDaOfertaService` | `5ae4dde1` | os dois serviços |
| 1 | o teste dos bloqueios + o manifesto | `40b5d134` | `BloqueiosDaOfertaServiceTest`, `contagens-186-08.txt` |
| 1 | o sobrevivente M1 investigado | `6dd2d07e` | o invariante do 1º elo |
| 3 (parte) | os 10 estados do protótipo | `af659577` | `BloqueiosContraOPrototipoTest` |

⚠️ O `BloqueiosContraOPrototipoTest` é da tarefa 3 e **não depende da transição ausente** (ele só
calcula bloqueio), então foi entregue. O `RevisaoDeImagemService`, que é o resto daquela tarefa,
depende e **não** foi.

### Os 8 bloqueios, com texto e ação de correção

| # | chave (pt-BR) | texto (do protótipo, `:1089-:1097`) | correção | cor |
|---|---------------|--------------------------------------|----------|-----|
| — | `em_preparacao` | `Sistema ainda {frase REAL do que falta}` | **vazia** (AUTO-01: não há o que fazer) | âmbar |
| 1 | `fotos_a_conferir` | `N foto(s) aguardando conferência[ · N com alerta automático]` | `Conferir fotos` | âmbar / **vermelho com alerta** |
| 2 | `sem_fotos` | `Nenhuma foto na publicação` | `Adicionar fotos` | vermelho |
| 3 | `frete_ausente` | `Frete do vendedor não confirmado · resultado indisponível` **ou** `{Rótulo} não confirmado · resultado indisponível` | `Informar frete` **ou** `Solicitar ajuste` | vermelho |
| 4 | `resultado_negativo` | `Resultado negativo: − R$ 103,57 por venda` | `Solicitar exceção` | vermelho |
| 5 | `margem_abaixo_do_minimo` | `Margem de 4,1% abaixo do mínimo de 12%` | `Solicitar exceção` | âmbar |
| 6 | `alteracao_nao_conferida` | `Preço alterado após a última conferência: R$ 799,90 → R$ 749,90` | `Conferir alteração` | âmbar |
| 7 | `ajuste_em_aberto` | `1 ajuste solicitado em aberto` / `2 ajustes solicitados em aberto` | `Ver ajustes` | âmbar |
| 8 | `titulo_longo` | `Título com 61 caracteres (máx. 60)` | `Ver conteúdo` | âmbar |

**A conta dos "8", explicitada:** `info()` empilha **9** chaves em `B`; **oito** são coisa a
consertar, e `em_preparacao` é **aviso de preparo** com correção vazia. Daí "os 8 bloqueios" da
fase. Isso está em `VocabularioDoPortao::OS_OITO` e tem asserção própria — deixar implícito **já me
custou uma contagem errada neste plano** (escrevi `assertCount(8, BLOQUEIOS_EM_ORDEM)` sobre uma
lista de 9).

⚠️ **VOC-05:** toda linha sai com `glifo` `!` **e** `severidade` — cor nunca é o único sinal, e há
asserção disso em cada um dos 10 estados.

### A prova da cadeia EXCLUSIVA nos 3 casos

| caso | cenário (preço 100, comissão 11% real, imposto 19%) | obtido |
|------|------------------------------------------------------|--------|
| **frete ausente** | custo 80, **frete `null`** — se o frete existisse, daria **prejuízo** | **`[frete_ausente]`** · nem `resultado_negativo`, nem `margem_abaixo_do_minimo` |
| **prejuízo** | custo 80, frete 10 ⇒ resultado **−11,00**, margem **−11%** | **`[resultado_negativo]`** · `margem` **não nasce**, apesar de −11% < 12% |
| **margem baixa** | custo 65, frete 10 ⇒ resultado **+4,00**, margem **4,0%** | **`[margem_abaixo_do_minimo]`** · `loss` não nasce (resultado positivo) |

**"Faltando dado não existe prejuízo nem margem, porque não há resultado":** no caso 1 a asserção é
`assertNull($composicao->resultado)` e `assertNull($composicao->margem)` — não é zero, é ausência.
**Com ISCA:** o MESMO custo com o frete informado produz `resultado_negativo`, senão eu estaria
provando apenas que `loss` nunca nasce naquele cenário.

### ⚠️ O defeito REAL que a cadeia escondeu, e onde a exceção entra

Primeira redação do serviço: o `! in_array(...)` da exceção estava **dentro da condição** do
`elseif`. O teste `test_bloq08_excecao_de_prejuizo_remove_so_o_loss...` **reprovou na hora**:
autorizar o **prejuízo** fazia nascer `margem_abaixo_do_minimo` **no lugar dele**, porque resultado
negativo é **sempre** margem abaixo do mínimo. ➡️ A pessoa precisaria de **DUAS** exceções para o
**MESMO** fato, e o caso pior (prejuízo) pediria o dobro do trabalho do caso mais leve.

**Conserto:** o **FATO** escolhe o elo da cadeia; a exceção só apaga o bloqueio **daquele** elo —
que é como o protótipo faz (`!o.exc` está junto do `push`, `:1093-1094`). Está escrito no serviço
com a data e tem teste nos dois sentidos.

### `loss`/`margin` disparam sobre o valor ESTIMADO POR BAIXO

Provado em `test_decisao7_...`, em quatro passos observáveis:

1. a linha `embalagem` **está** `Pendente` (asserção `ehPendente()`);
2. e ainda assim **não** há `frete_ausente` — `embalagem` não está em
   `PubAprovacaoComposicao::ANULAM_O_RESULTADO` (as **cinco**: custo, comissão, tarifa fixa, frete,
   imposto), e `incompleto` é `false`;
3. `estimadoPorBaixo === true` e a nota diz *"sem o custo de embalagem"*;
4. **o bloqueio dispara sobre ele** — `[margem_abaixo_do_minimo]` no cenário de margem e
   `[resultado_negativo]` no de prejuízo.

A razão escrita no código: o valor é **otimista** (falta um custo real para descontar), então a
margem **de verdade é menor** — com aprovar-já-publica e sem desfazer, **não bloquear seria o erro
grave**.

### O bloqueio de fotos cobre TODOS os grupos — com isca

Dois testes, cada um com **4 conjuntos** (3 valores de eixo que definem foto + `GENERAL`), todas as
imagens aprovadas **menos uma**:

| teste | onde está a única pendente | resultado |
|-------|----------------------------|-----------|
| `test_achado_s2_pendente_fora_do_primeiro_grupo_…` | **3º grupo de variação** | `fotos_a_conferir` presente e `aprovavel === false` **nas DUAS ofertas**; `foco_imagem_id` aponta para ela |
| `test_achado_s2_pendente_no_grupo_general_…` | **`GENERAL`** (o grupo que uma FK única por variação deixaria de fora) | idem, nas duas ofertas |

**ISCA (em ambos):** aprovando aquela última, `fotos_a_conferir` **desaparece** nas duas ofertas e o
rodapé vira `Fotos, preço e conteúdo conferidos`. Sem isso o teste passaria por um bloqueio que
nunca sai.

**E o desempenho é gate, não promessa:**
`test_o_bloqueio_de_fotos_nao_faz_uma_consulta_por_grupo` conta as consultas com **1** e com **12**
grupos e exige o **mesmo número** (três consultas fixas: conjuntos · imagens por `whereIn` · alertas
por eager loading), com isca provando que o log capturou algo. ⚠️ `conjunto_id` **não** foi
reintroduzido em `pub_aprovacao_ofertas`.

### BLOQ-08 — a exceção libera só prejuízo e margem, com fixture PREENCHIDA

O teste monta os **outros cinco** bloqueios de verdade (`fotos_a_conferir` numa variação,
frete presente, `alteracao_nao_conferida`, `ajuste_em_aberto` e `titulo_longo` com 61 caracteres) —
porque **negativa com fixture vazia é vazia por construção**. Depois da exceção de prejuízo os
cinco continuam de pé, e `aprovavel` segue `false`. No espelho (margem baixa com resultado
positivo), a exceção de **prejuízo não serve** e o bloqueio fica; a de **margem** libera.

### `VocabularioDoPortao` — a bijeção, LIDA do arquivo

`test_bloq12_as_nove_abreviacoes_e_os_oito_rotulos_sao_espelho_lido_do_modulo_jsx` **abre**
`resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx`, parseia
`ABREVIACAO_DO_BLOQUEIO` (9), `ROTULO_DO_BLOQUEIO` (8), `BLOQUEIO_DO_PROTOTIPO` (11),
`SELOS_DE_ORIGEM` (3) e os literais `TUDO_CONFERIDO` / `BLOQUEADO_PREFIXO` / `INCOMPLETO`, e compara
**byte a byte** com o PHP. A sonda tem **isca** (acha um rótulo conhecido) — sem ela, um parser
quebrado devolveria array vazio e `assertSame([], [])` passaria sem medir nada.

A bijeção das **11** chaves vale nos **dois sentidos** (`ABREVIACOES ∪ ROTULOS` = valores de
`BLOQUEIO_DO_PROTOTIPO`), com os **3 só em `SHORT`** e os **2 só em `BLK`** nomeados. E as chaves
**inglesas** são recusadas uma a uma (`chaveValida('loss') === false`): ⚠️ **nenhum `||` aceita as
duas formas**. Os `PubAprovacaoExcecao::LIBERA` são provados subconjunto deste namespace.

⚠️ Os 3 selos de origem **não foram recopiados**: `selosDeOrigem()` devolve
`PubAprovacaoComposicao::ORIGENS_DA_LINHA`, e há asserção de que são o mesmo objeto de verdade.

⚠️ O módulo `.jsx` **não foi tocado** (`git diff` dele vazio no range).

### Gate de ausência de segundo clique / desfazer — com ISCA e ISCA INVERTIDA

`test_nenhum_arquivo_deste_plano_cria_segundo_clique_desfazer_ou_cancelar_aprovacao`, sobre os
**dois** arquivos de produção, lidos **sem comentários** pelo tokenizador do PHP:

| sonda | ancorada em |
|-------|-------------|
| `confirmar publicação` | `/confirmar[_ ]?(a )?publica/iu` |
| `segundo passo/ato/clique` | `/(segundo\|dois)[_ ](passo\|ato\|clique)s?/iu` |
| `desfazer a aprovação/publicação` | `/desfazer[_ ]?(a )?(aprova\|publica)/iu` |
| `undo da aprovação` | `/\bundo[_ ]?(approv\|publi)/iu` |
| `cancelar a aprovação` | `/cancelar[_ ]?(a )?aprova/iu` |
| `janela de desfazer` | `/janela[_ ]de[_ ]desfazer/iu` |
| `exceção aguardando autorização` | `/(excecao\|exceção)[_ ]?(aguardando\|pendente\|a[_ ]autorizar)/iu` |

- **ISCA de cada uma:** uma amostra que ela TEM de pegar (`confirmarPublicacao()`,
  `JANELA_DE_DESFAZER`, `undoApproval()`…). Sem isso eu não saberia se o silêncio vem do código
  limpo ou da regex não casar nada.
- ⚠️ **ISCA INVERTIDA:** o que é **legítimo e parecido** não pode casar — o rótulo
  `Aprovar para publicação` (que **não muda**), o gancho `aoAprovar()`, a exceção que nasce com
  `ativa => true`, e `cancelarPedidoDoCliente()` de outro assunto. **Sonda ancorada no SUJEITO**,
  não em palavra solta: `cancelar` sozinho casaria o legítimo, e o executor seguinte a
  enfraqueceria.
- **Contagem FIXA (zero infratores) com a lista nomeada**, e isca de caminho fantasma
  (`assertFileExists` em cada alvo): contagem que depende de quantos arquivos existem é frágil por
  construção.
- E o leitor sem comentários tem gate próprio
  (`test_o_leitor_tira_os_comentarios_do_plano_08_e_preserva_o_codigo`): tira comentário de linha,
  de bloco e docblock, **preserva o código**, e prova que o docblock que explica a decisão **não**
  sobrevive — senão o gate reprovaria pelo próprio conserto (foi o que aconteceu com `try` nesta
  fase).

⚠️ `aoAprovar()` **não foi mexido** e segue vazio em `AndamentoDaOfertaService` — é o único ponto de
extensão reservado.

### A fronteira do D-11, declarada

`test_terr09_a_fronteira_do_d11_esta_declarada_e_este_plano_nao_escreve_no_ml`:

> O gate **D-11** vale para a **LISTA de Publicações**, que **nunca** escreve no Mercado Livre. A
> **FILA** do portão escreve — mas **não neste plano**: aqui só se **calcula bloqueio**. A escrita
> no ML é da **Fase 193**, pelo gancho `aoAprovar()`.

O teste confere `tests/js/estrutura-meus-anuncios.test.js` por **SEMÂNTICA** (os marcadores `D-11` e
`D-22` continuam lá, sem depender de número de linha — é arquivo de outra área) e prova que nenhum
arquivo deste plano menciona `MlPublicacaoService`, `Http::`, `items` ou `MercadoLivreService`, com
isca provando que a sonda acha o nome quando ele está presente. **O D-11 não foi estendido nem
afrouxado.**

### A tabela dos 10 estados do protótipo — estrutura, não números

⚠️ **"O protótipo ganha" vale para a FÓRMULA, os RÓTULOS e a ORDEM — NUNCA para os NÚMEROS.** As
taxas de `fin()` são ficção declarada por ele mesmo (`:1064` *"taxa de exemplo"*, `:1066`
*"exemplo"*, `src: 'est'`). Os valores abaixo vêm das **fontes reais**: `percentage_fee` **11**
(Clássico) e **14** (Premium) das fixtures de `/sites/MLB/listing_prices` capturadas em 2026-10-01,
e imposto **19,0** de `EstruturaPrecificacaoParametros::PADROES` (⚠️ **não** os 6% do README do
desenho). **Um `test()` por estado, nunca um laço.**

| estado | andamento | pendentes | resultado · margem | bloqueios **esperados = obtidos** (na ordem) |
|--------|-----------|-----------|--------------------|----------------------------------------------|
| `o1` | aguardando | 0 | **+20,03 · 4,09%** | `[margem_abaixo_do_minimo]` |
| `o2` | aguardando | 0 | **+32,13 · 6,06%** | `[margem_abaixo_do_minimo]` |
| `o3` | aguardando | 3 (2 c/ alerta) | **−103,57 · −13,81%** | `[fotos_a_conferir, resultado_negativo, alteracao_nao_conferida]` |
| `o4` | aguardando | 3 (2 c/ alerta) | **`null` · `null`** (incompleto) | `[fotos_a_conferir, frete_ausente, ajuste_em_aberto]` |
| `o5` | em revisão ECF | 2 (1 c/ alerta) | +57,03 · 21,94% | `[fotos_a_conferir]` |
| `o6` | ajustes solicitados | 2 (1 c/ alerta) | +62,63 · 22,38% | `[fotos_a_conferir, ajuste_em_aberto]` |
| `o7` | falha | 0 | +108,03 · 22,05% | `[]` + `kind` `falha_na_publicacao` |
| `o8` | aprovado | 0 | +113,43 · 21,82% | `[]` (não `live` ⇒ não aprovável) |
| `o9` | publicando | 0 | +78,03 · 23,65% | `[]` (edição bloqueada) |
| `o10` | preparando | 0 | +88,23 · 24,52% | `[em_preparacao]` |

**As duas divergências medidas, com `arquivo:linha`:**

1. ⚠️ **`o1` INVERTE de aprovável para bloqueado.** `Aprovacao.dc.html:1064-1066` dá +R$ 70,82 /
   14,46% com as taxas de ficção; com as fontes reais são **+R$ 20,03 / 4,09%** ⇒
   `margem_abaixo_do_minimo`. **Ganham as fontes reais.** (A diferença contra os **+R$ 12,03** do
   plano 186-07 é a **embalagem**: lá entrava com o `pack` do protótipo; aqui ela nasce `Pendente`
   sem fonte no repositório e não entra na soma — e é justamente por isso que o resultado é
   *estimado por baixo*.)
2. ⚠️⚠️ **A seção F do `README-v2.md` é PROSA, e discorda do `info()`.** Ela chama `o3` de *"margem
   baixa"*; pela cadeia exclusiva (`Aprovacao.dc.html:1092-1094`) ele dá **PREJUÍZO**
   (−R$ 103,57 / −13,81%), e `margem_abaixo_do_minimo` **não nasce** porque o elo anterior já
   consumiu o fato. **Só `info()` decide bloqueio** — e o teste tem asserção explícita de que a
   margem **está** abaixo do mínimo e ainda assim o bloqueio dela não aparece.

**ISCA obrigatória da tabela:** com as fontes reais **nenhum dos dez** é aprovável, e gate cujo
veredicto é sempre o mesmo não prova nada. `test_isca_da_tabela_…` conserta o único bloqueio do `o5`
(aprova as duas fotos pendentes) e exige `aprovavel === true` + o rodapé verde — e prova que a
**irmã (`o6`) também** perde o bloqueio de fotos, porque o conjunto é compartilhado **por
estrutura**.

**Degradação explícita (GATE-10):** sem `design_handoff_publicador/Aprovacao.dc.html` no disco cada
estado dá **`skip` EXPLÍCITO com a razão** — e há **isca do predicado**: hoje o arquivo existe (logo
os dez foram **conferidos**, não "skipados em silêncio"), e o mesmo `is_file()` dá `false` para um
caminho inexistente.

### Gate de cobertura da tabela

`test_a_tabela_cobre_os_dez_estados_e_os_oito_andamentos_do_prototipo`: 10 ofertas, 5 conjuntos, os
**7** andamentos que o `OFF` do protótipo usa (⚠️ `publicado` **não** aparece — o `OFF` não tem
`status: 'published'`, e inventá-lo mediria o que o protótipo não disse), todos chaves reais do
modelo, as **3** fotos com alerta medidas por script no `IMG`, e **todos os 10 títulos ≤ 60
caracteres** por `mb_strlen` — é por isso que `titulo_longo` não aparece em estado nenhum da tabela
(ele é exercitado no outro arquivo, com **60** e com **61**).

---

## Os gates (13 ids, todos MEDIDOS)

`sh tests/gates/verificar-testes.sh 08 <id>` — manifesto `tests/gates/contagens-186-08.txt`.
O `--autoteste` do helper: **14 casos, 0 divergência**.

| id | testes / asserções |
|----|--------------------|
| `vocabulario-php-e-jsx` | 3 / 140 |
| `cadeia-exclusiva` | 4 / 65 |
| `estimado-por-baixo` | 1 / 14 |
| `excecao-libera-so-dois` | 2 / 30 |
| `achado-s2-todos-os-grupos` | 2 / 32 |
| `galeria-e-foco` | 3 / 35 |
| `titulo-change-adjust-prep` | 3 / 36 |
| `kinds-aprovavel-rodape` | 4 / 53 |
| `estados-o1-a-o5` | 5 / 104 |
| `estados-o6-a-o10` | 5 / 64 |
| `tabela-isca-e-cobertura` | 2 / 44 |
| `ausencia-e-territorio` | 4 / 83 |
| `bijecao-manifesto` | 1 / 24 |

⚠️ **Nenhum gate chama `artisan test` ou `node --test` direto** (learnings §4, casos 12 e 13).
⚠️ **Alternância grande = filtro de classe:** os 39 métodos estão em **13 ids**, nenhum com mais de
5 métodos.

**A bijeção ids ↔ métodos** (`test_todo_metodo_do_plano_08_…`, sufixo `_do_plano_08` de propósito —
os planos 186-06 e 186-07 têm homônimos e o `--filter` SOMA contagens de vários arquivos) falha nos
**dois sentidos**: método sem id (**nunca roda**) e id órfão (**gate sobre zero casos**). Com isca do
casador (entende alternância; **não** casa por prefixo) e isca da sonda (acha ≥ 20 métodos e acha a
si mesma). **39 métodos, 13 ids, zero órfãos, zero sem id.**

⚠️ A contagem de `bijecao-manifesto` **depende do número de linhas do manifesto** — contagem frágil
por construção, e está **declarada como tal** dentro do arquivo: acrescentar um id sobe o número e o
helper reprova até ser remedido. É desconforto de propósito.

---

## Campanha de mutação — 22 mutações, 21 mortas, 1 sobrevivente INVESTIGADA

**Protocolo:** commit **antes** de mutar · cópia guardada **fora do repositório**
(`$TMPDIR/mut/*.php`) · ocorrência **travada em 1 ANTES** (zero = no-op que eu chamaria de
sobrevivente; mais de uma = mudei o que não quis) · **linha conferida depois** · restauração **da
cópia**, em `trap … EXIT`, com **`sha256sum` conferido contra `git show HEAD:<arquivo>`**.
⚠️ **`git checkout` NÃO foi usado em nenhum momento** — está na lista de proibidos deste projeto, e
nesta fase já apagou uma camada de teste não commitada.

| # | mutação | veredicto |
|---|---------|-----------|
| M1 | o `elseif` do elo do prejuízo vira `if` independente | ⚠️ **SOBREVIVEU** — ver abaixo |
| M2 | o `elseif` do elo da margem vira `if` independente | morta (`cadeia-exclusiva`, `excecao-libera-so-dois`) |
| M3 | título `>` vira `>=` | morta |
| M4 | as fotos leem **só o primeiro** conjunto (o achado S2) | morta (`achado-s2-todos-os-grupos`) |
| M5 | a exceção de margem passa a liberar prejuízo (o achado S7) | morta |
| M6 | `aprovavel` esquece o `live` | morta |
| M7 | o `+N` do rodapé conta o total | morta |
| M8 | foto reprovada volta a contar como pendente | morta |
| M9 | a severidade perde o contexto do alerta | morta |
| M10 | embalagem pendente passa a anular o resultado | morta |
| M11 | a galeria ignora `fora`/`posicao` | morta |
| M12 | o foco abre a primeira pendente mesmo havendo alerta | morta |
| M13 | o texto do frete mente quando falta o custo | morta |
| M14 | alerta em foto **aprovada** volta a contar | morta |
| M15 | uma abreviação divergindo do `.jsx` (paráfrase) | morta |
| M16 | a chave inglesa do protótipo volta a ser chave de dado | morta |
| M17 | os vermelhos perdem o `sem_fotos` | morta |
| M18 | `TUDO_CONFERIDO` parafraseado | morta |
| M19 | o `kind` de falha olha o estado errado | morta |
| M20 | ajuste em aberto deixa de bloquear | morta |
| M21 | a frase de preparação parafraseada | morta |
| M22 | o aviso de preço alterado deixa de bloquear | morta |

### ⚠️ M1 — o sobrevivente, INVESTIGADO e não presumido equivalente

Trocar `} elseif ($composicao->resultado !== null && $composicao->resultado < 0) {` por
`} if (…) {` **sobreviveu a todos os gates**. Investigado: é **mutante equivalente de verdade**, não
lacuna. `ComposicaoDaOferta::montar()` devolve `resultado`/`margem` **`null`** sempre que
`incompleto` (o `return` antecipado), e `null < 0` é `false` em PHP ⇒ o `if` independente tem o
MESMO comportamento do `elseif` **para o primeiro elo**.

➡️ **O que dá para medir é o INVARIANTE, e ele passou a estar preso** (commit `6dd2d07e`):
`assertSame($composicao->incompleto, $composicao->resultado === null)` + a existência do código de
regra `FIN-05-INCOMPLETO-COM-RESULTADO` no modelo. Se a composição um dia conviver com `incompleto`
E resultado numérico, o `elseif` deixa de ser redundante e o gate reprova **antes** de a tela dizer
"Resultado negativo" sobre um número inventado.
⚠️ O `elseif` **FICA** no serviço: ele é a intenção, e a redundância é a rede. ⚠️ E o elo
**prejuízo → margem**, esse **é** observável — a mutação M2 morreu nele.

---

## Divergências do plano, declaradas

| # | o plano dizia | o que está no código, e por quê |
|---|---------------|---------------------------------|
| 1 | *"a ordem é `GENERAL` primeiro, depois os grupos na ordem de `posicao`"* | **`pub_aprovacao_conjuntos` NÃO tem coluna `posicao`** — medido em `database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php:185-191`. A ordem é `GENERAL` primeiro, depois por **`id`** (criação, estável). O protótipo não tinha como ter essa ordem (um conjunto só), então não há literal a obedecer; o que importa é ser determinística, senão a foto que a tela abre muda entre duas chamadas. |
| 2 | o texto de `freight` é sempre *"Frete do vendedor não confirmado"* | No protótipo só o frete podia faltar (`fin()`, `:1067`); aqui **cinco** linhas anulam o resultado. Dizer "frete" quando falta o **custo** seria a tela afirmando o que não é, e mandar `Informar frete` levaria a pessoa ao modal errado. ➡️ **A FORMA do protótipo é preservada** (`"… não confirmado · resultado indisponível"`); muda **qual fonte é nomeada**, e a correção passa a `Solicitar ajuste`. O rótulo do filtro já previa: é *"Frete **ou custo** ausente"*. Tem teste com isca nos dois sentidos. |
| 3 | *"as 7 mudanças relevantes"* / *"as 8 chamadas"* | Não implementado — **tarefa 2 devolvida** (ver o bloco de PARE acima). |
| 4 | `VocabularioDoPortao` traz *"os 3 selos de origem"* | Traz como **método** `selosDeOrigem()`, que **LÊ** `PubAprovacaoComposicao::ORIGENS_DA_LINHA` em vez de recopiar os três rótulos. Recopiar criaria duas verdades livres para divergir. Há asserção de que são a mesma verdade. |
| 5 | o retorno exporia `andamento` (⇒ camada A) | **Não expõe.** As chaves de saída são `ao_vivo`, `aprovavel`, `chaves`, `kinds`, `bloqueios`, `resumo`… — nenhum `'andamento' =>`. ➡️ **A camada A de `AndamentoDaOfertaServiceTest` NÃO foi tocada**, e o manifesto `contagens-186-04.txt` **não** precisou mudar. Conferido por `grep` sobre `app/Services/Publicador/Aprovacao/*.php`: os dois arquivos novos não aparecem. |

---

## Baselines

| suíte | antes (186-07) | agora | delta |
|-------|----------------|-------|-------|
| **PHP** (`--suite php`) | 2269 / 18.717 / **0** | **2308 / 19.463 / 0** | +39 testes, +746 asserções, **0 falhas** |
| **JS** (`--suite js`) | 4777 / 4776 pass / 1 fail | **4777 / 4776 pass / 1 fail** | inalterado — nenhum arquivo JS tocado |

A única falha de JS é a **pré-existente** `tests/js/estrutura-grade-glide.test.js` (*"Características
secundárias nasce recolhido"*, Fase 87) — ⚠️ **não corrigida de propósito**, não é regressão deste
plano.

---

## Território

- **Nada das waves 1-4 foi alterado:** `git diff` do range `0ba69bf3..HEAD` mostra **só** os 5
  arquivos deste plano (mais um `docs(learnings)` do orquestrador, que não é meu).
- `AndamentoDaOfertaService`, `HistoricoDaOfertaService`, `AjustesDaOfertaService`,
  `ComposicaoFinanceiraService`, `DadosEfetivosService`, `SimuladorVoceRecebe` e os models do portão
  foram **CHAMADOS**, nunca alterados.
- `app/Support/Publicador/`, `app/Services/Creative/`, `MlPublicacaoService`, o editor,
  `AppLayout.jsx`, `Bling*`, `Portal/`, `estrutura-meus-anuncios.test.js` e o módulo
  `vocabularioDoPortao.jsx`: **diff vazio**.
- **Nenhuma migration** criada ou rodada. **Nenhum pacote** instalado. **Nenhuma chamada à API real
  do ML** (`Http::preventStrayRequests()` + fixtures de `tests/fixtures-ml/`). **Nenhuma leitura de
  produção. Nenhum deploy, push ou comando no VPS.**
- `.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v27.md` e `.planning/STATE.md` **não** foram
  tocados, e `state.advance-plan` **não** foi rodado — o plano está **parcial**.
- `vendor/bin/pint` não rodado. Nenhum worktree criado. `git add -A`, `git stash`, `git reset`,
  `git checkout`, `git clean` e `--amend` **não** usados; todo commit por **pathspec**.

---

## PUB-09 — o ponto de extensão

> **O ponto de extensão `aoAprovar()` é preenchido pela Fase 193; nenhum segundo clique, nenhuma
> janela de desfazer — oferecidos e recusados em 14/10/2026.**

O gancho continua **vazio e documentado** em `AndamentoDaOfertaService::aoAprovar()`, dentro da
mesma `DB::transaction` que grava `aprovado_por` / `aprovado_em` / `versao_aprovada`. O rótulo do
botão segue sendo o literal do protótipo, **"Aprovar para publicação"**, e o gate de ausência (com
isca e isca invertida) impede que um plano futuro "complete o fluxo" achando que faltou.

⚠️ **A transação única da aprovação** (andamento + `aprovado_*` + evento + **congelamento da
composição**) é da **tarefa 2** e **não foi entregue** — ela depende da decisão sobre a transição de
reabertura, porque é o mesmo serviço.

---

## TDD Gate Compliance

O plano marca as tarefas como `tdd="true"`. **O que de fato aconteceu, declarado sem maquiagem:**
o teste e o serviço foram escritos no mesmo ciclo e commitados em dois commits que deixam a árvore
**verde em cada ponto** (`feat` `5ae4dde1` depois `test` `40b5d134`) — ou seja, **a ordem RED antes
de GREEN não aparece no `git log`**.

⚠️ **Mas o momento RED existiu e está registrado:** na primeira execução, `27 testes / 2 falhas`, e
uma delas era **defeito REAL de produção** (a exceção dentro da condição do `elseif`, que promovia
`margem_abaixo_do_minimo` ao autorizar o prejuízo). O conserto é o commit `5ae4dde1` já com a forma
certa, e a lição está escrita no serviço **e** no teste, com a data. A segunda falha era o manifesto
ainda inexistente.

---

## Known Stubs

Nenhum. Os dois serviços entregues são completos para o que calculam.
⚠️ O que falta não é stub: são **dois serviços que não foram criados** (`AprovacaoDaOfertaService` e
`RevisaoDeImagemService`), e o motivo está no bloco de PARE no topo.

## Threat Flags

Nenhuma superfície nova além do `<threat_model>` do plano. Os mitigadores entregues:

| Threat | Como ficou |
|--------|-----------|
| **T-186-32** (aprovar com bloqueio) | `aprovavel = live && zero bloqueios`, conferido **no servidor**; recusa é da tarefa 2 (devolvida) |
| **T-186-35** (exceção liberando demais) | BLOQ-08 com teste negativo nos cinco, **fixture preenchida** |
| **T-186-38** (composição por linha no lote) | uma leitura por oferta, fora de laço; três consultas fixas de foto, com gate de contagem |
| **T-186-SC** (composer) | **nenhum** pacote instalado |
| T-186-33 / T-186-34 / T-186-36 | **pendentes** — são da tarefa 2 |

## Self-Check: PASSADO

Arquivos criados conferidos no disco e no `git`; os 4 commits conferidos por `git log`; os 13 gates
reexecutados pelo helper depois da última restauração; os dois arquivos de produção com `sha256`
idêntico ao de `git show HEAD:` (nenhum resíduo de mutação).
