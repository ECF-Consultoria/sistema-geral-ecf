---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 06
subsystem: publicador-aprovacao-painel-escritas-de-preco-frete-ajuste-excecao
tags: [publicador, aprovacao, painel, escrita, preco, simulacao, frete, ajuste-por-escopo, excecao-autorizada, transporte, payload-dos-modais]
partida: 091340b6
commits:
  - 1f2dbe44  # feat(188-06) preco salvo, conferir alteracao, frete + o TRANSPORTE da simulacao
  - 4c3dde27  # feat(188-06) ajuste por ESCOPO, resolver e a excecao
  - (este)    # docs(188-06) a SUMMARY + a §P do FID01
requires:
  - AndamentoDaOfertaService     # a UNICA PORTA dos 8 estados / 13 transicoes (Fase 186)
  - AprovacaoDaOfertaService     # conferirAlteracao / confirmarFrete / registrarPrecoSalvo / registrarMudanca (Fase 186)
  - AjustesDaOfertaService       # pedir / resolver / abertosQueAfetam / aplicarExcecao / ofertasAfetadas (Fase 186)
  - HistoricoDaOfertaService     # registrar — CHAMADO, nunca alterado (Fase 186)
  - ComposicaoFinanceiraService  # para / precoDoAlvo (Fase 186)
  - ComposicaoDaOferta + LinhaFinanceira  # os DTOs (Fase 186)
  - PubAprovacaoFrete::ORIGENS / ::informar / ::nota / ::vigenteDe  # as 3 origens (Fase 186)
  - PubAprovacaoExcecao::LIBERA / ::justificativaValida / ::libera / ::fraseDeExibicao  # (Fase 186)
  - PubAprovacaoAjuste::ESCOPOS / ::ANCORA_DO_ESCOPO / ::destinoLegivel / ::MODALIDADES  # (Fase 186)
  - PubAprovacaoDefeito::DEFEITOS  # os 6 chips canonicos (Fase 186)
  - FilaDeAprovacaoService::paraAConta       # a trava de conta (Fase 187)
  - FiltrosDaFilaService::responsaveisDaConta  # o resolvedor dos autorizadores (Fase 187)
  - FotosDoPainelService::para               # a galeria dos itens de ajuste (wave 188-03)
  - PrecoDoPainelService::simular / ::sanear # a simulacao PURA (wave 188-04)
provides:
  - mlb.anuncios.meus.aprovacao.painel.preco                 # salvar o preco simulado
  - mlb.anuncios.meus.aprovacao.painel.preco.simular         # O TRANSPORTE (leitura pura) — era BLOCKER
  - mlb.anuncios.meus.aprovacao.painel.conferir-alteracao    # BLOQ-07, sem mexer no preco
  - mlb.anuncios.meus.aprovacao.painel.frete                 # confirmar frete (valor + origem + nota + versao++)
  - mlb.anuncios.meus.aprovacao.painel.frete.previa          # a previa do criterio 14 (leitura pura)
  - mlb.anuncios.meus.aprovacao.painel.ajuste                # pedir ajuste COM ESCOPO
  - mlb.anuncios.meus.aprovacao.painel.ajuste.resolver       # AND-07, nos dois sentidos
  - mlb.anuncios.meus.aprovacao.painel.excecao               # a excecao autorizada
  - PrecoDoPainelService::previaDoFrete / ::registroDePrecoSalvo / ::registroDeFreteConfirmado
  - a chave `modais` do payload do painel (B1.4 · B1.5 · B1.6)
affects:
  - 188-09  # ela consome o transporte; o NOME da rota bate com o que ela esperou (confirmado na SUMMARY dela)
  - 188-10  # o rodape e as pendencias leem o mesmo payload
  - 188-11  # o modal de ajustes consome `modais.ajuste.itens` (rotulo/escopo/destino/nota/sugestoes)
  - 188-12  # os modais de excecao, frete e reprovar consomem `modais.excecao.autorizadores` e `modais.reprovar.defeitos`
  - 193     # a escrita no ML — NAO antecipada
tech-stack:
  added: []        # ⚠️ ZERO dependencia nova — provado por FORMA (T-188-SC): diff VAZIO nos 4 arquivos
  patterns:
    - "action FINA: trava de conta -> guarda de revisao+versao -> UMA chamada ao servico da 186 -> retorno"
    - "quem narra o ATO e quem o executa (HistoricoDaOfertaService:160-162); a CONSEQUENCIA e de registrarMudanca()"
    - "leitura com entrada no corpo via POST (nao-bookmarkavel), devolvendo a MESMA array em JSON ou em flash"
    - "premissa inobservavel hoje -> TRAVADA por assercao (o indice unico; e o PONTO FIXO da previa)"
key-files:
  created:
    - tests/Feature/Publicador/Aprovacao/EscritaDePrecoEFreteDoPainelTest.php
    - tests/Feature/Publicador/Aprovacao/EscritaDeAjusteEExcecaoDoPainelTest.php
    - tests/gates/contagens-188-06.txt
  modified:
    - routes/mlb_anuncios.php
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
    - app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php
    - tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php    # cirurgia, contagem INALTERADA
    - tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php     # cirurgia, contagem INALTERADA
    - tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php         # assinatura do espiao
    - .planning/phases/188-.../188-FID01.md                                  # §P, aditiva
requirements: [MOD-01, MOD-02, MOD-03, FIN-11, FIN-12, BLOQ-08, BLOQ-09, DADO-09, DADO-11, DADO-12, AND-07, COL-04, COL-05]
metrics:
  tasks: 2
  rotas_novas: 8
  tests_novos: 18
  assertions_novas: 249
  ids_no_manifesto: 7
  mutacoes: 8          # todas MORRERAM
  duration: ~4h
completed: 2026-10-19
---

# Fase 188 Plano 06: as escritas de preço, frete, ajuste-por-escopo e exceção — Summary

As **oito rotas** que fecham a metade servidor dos critérios **7** (a simulação salva só no
`live`, versão++), **11** (a exceção libera só `loss`/`margin`, com ≥ 10 caracteres e quem
liberou), **12** (ajuste por escopo; resolver o último devolve a `awaiting`) e **14** (frete com
prévia e versão++) — **incluindo o TRANSPORTE da simulação, que era BLOCKER do verificador**.

**PARTIDA:** `091340b6`.

---

## ⚠️⚠️ O QUE ESTE PLANO RESOLVEU, E QUE É O CENTRO DELE

### 1. O TRANSPORTE da simulação — e o laço FECHOU com a wave 9

`PrecoDoPainelService::simular()` existia desde a wave **188-04** e **nenhuma rota nem parâmetro
recebia o preço digitado**. Como o plano **188-09** proíbe aritmética de taxa no cliente, os
critérios **6** ("simular preço") e **7** ("recalcula e mostra o selo") ficavam **sem caminho
nenhum**. ➡️ A rota, com o nome EXATO:

```
POST mlb/anuncios/meus/{company}/aprovacao/{oferta}/preco/simular
     name: mlb.anuncios.meus.aprovacao.painel.preco.simular
     middleware: role:admin (do grupo) + throttle:120,1,publicador.aprovacao.painel.preco-simular
```

⚠️⚠️ **O LAÇO FECHA, E ISSO NÃO É AFIRMAÇÃO: ESTÁ NA SUMMARY DA WAVE 9.** Ela terminou **durante**
esta execução (commits `aecda262` e `f0211f32`) e escreveu, no apêndice,
`188-09-SUMMARY.md:947`:

```
routes/mlb_anuncios.php:630  ->name('meus.aprovacao.painel.preco')
routes/mlb_anuncios.php:638  ->name('meus.aprovacao.painel.preco.simular')
```

➡️ **O nome que ela esperou é o nome que existe.** Nada a devolver. ⚠️ E ela declarou que **não
religou os handlers de propósito** (as rotas não estavam commitadas quando as tarefas dela foram
escritas), deixando a peça *"PRONTA PARA RECEBER"* — o religamento é uma linha por handler, e o
gate de *"nenhum `router.post` direto"* mora no **188-12**.

⚠️ **POST e não GET, de propósito:** o valor é entrada da pessoa e **não é bookmarkável** — preço
simulado na URL faria um link compartilhado abrir a tela com a simulação de outra pessoa, e a
decisão de *"gesto não vai para a URL"* já está tomada neste módulo (`Aprovacao.jsx:316`-`:317`).
⚠️ **E ela NÃO GRAVA NADA** — é leitura pura com entrada no corpo.

**As 9 chaves que o plano declarou ler da wave 188-04 existem no estado SIMULADO *e* no NEUTRO**
(forma diferente quebraria o cliente em metade das respostas), com **UMA** asserção coletando as
ausentes — nunca uma por chave:

`resultado` · `resultado_cor` · `margem` · `margem_cor` · `delta` · `delta_cor` · `simulando` ·
`pode_salvar` · `versao`.

### 2. A PRÉVIA DO FRETE — a assinatura que o FID01 prescreve **NÃO EXISTE**

A `188-FID01.md` §F.3 diz *"No produto: `ComposicaoFinanceiraService::para($oferta, …)` com o
frete simulado"*. **Medido:** a assinatura é `para(PubAprovacaoOferta, ?float $precoForcado)` — ela
força o **PREÇO**, nunca o frete, e a linha de frete é montada **dentro** dela por
`PubAprovacaoComposicao::linhaDeFrete($vivo, PubAprovacaoFrete::vigenteDe($oferta))`. Acrescentar
um `?float $freteForcado` seria **alterar um serviço da Fase 186 em produção** — o que esta fase
não faz.

**As duas saídas MEDIDAS e DESCARTADAS** (estão escritas no docblock de
`PrecoDoPainelService::previaDoFrete()`, para ninguém as tentar de novo):

| saída | por que NÃO funciona |
|---|---|
| (a) dublar `DadosEfetivosService` e pedir `para()` ao serviço real | em `linhaDeFrete()` o frete **informado à mão VENCE** o da Precificação → numa oferta que já tem frete confirmado (o caso de *corrigir* o valor) a prévia mostraria o frete **ANTIGO** |
| (b) subtrair o delta do `resultado` atual | **falha no caso PRINCIPAL**: sem frete a composição é `incompleta` e `resultado` é **`null`** — e o modal de frete existe justamente porque o frete falta |

➡️ **A saída: trocar a LINHA e somar como o DTO soma** — e a equivalência fica **TRAVADA POR
ASSERÇÃO num PONTO FIXO**:

> com o valor **IGUAL** ao frete que já vale, `previaDoFrete()` devolve **exatamente** o
> `resultado` e a `margem` de `ComposicaoFinanceiraService::para()`.

**Se a fórmula do DTO da 186 mudar, aquela asserção QUEBRA** — que é o comportamento certo. E o
caso principal é medido contra a composição **real de uma oferta que JÁ TEM aquele frete**, nunca
contra um literal recalculado no teste.

⚠️ **`equilibrio` e `precoAlvo` NÃO são calculados ali** (precisariam dos dois percentuais, que
são privados de `ComposicaoFinanceiraService` e **não recuperáveis sem perda** — o da comissão só
existe formatado, com 0 ou 1 casa, em `percentual()`): **número plausível e errado é pior que
número ausente**, e a prévia do protótipo não os mostra.

### 3. Os TRÊS efeitos do preço salvo, medidos SEPARADAMENTE — e o histórico que ficaria VAZIO

| efeito | onde mora | asserção própria |
|---|---|---|
| a `versao` sobe | `registrarPrecoSalvo()` → `registrarMudanca()` (Fase 186) | `versaoAntes + 1` |
| o aviso de alteração é **limpo** | `registrarPrecoSalvo()` (`preco_alterado => null`) | `assertNull($salva->preco_alterado)` |
| o **ato** vai ao histórico | ⚠️ **a ACTION** | `assertContains('Alterou o preço de R$ 100,00 para R$ 129,90 na revisão · versão N')` |

⚠️⚠️ **E É POR ISSO QUE OS TRÊS SÃO SEPARADOS:** um gate que só olhasse a versão **passaria com o
aviso de alteração esquecido de pé** — e a oferta ficaria **bloqueada por uma alteração que a
pessoa acabou de fazer**. A fixture nasce **COM o aviso de pé**, com asserção provando-o: sem
isso, *"o aviso foi limpo"* seria verdade por ele **nunca ter existido**.

⚠️ **A NARRAÇÃO DO ATO É DA ACTION, E ISSO FOI MEDIDO:** `registrarPrecoSalvo()` registra a
**CONSEQUÊNCIA** (*"Aprovação reaberta: preço alterado na revisão · de X para Y (versão N)"*) e
**só quando a oferta estava `aprovado`** — numa oferta `aguardando_aprovacao` o histórico ficaria
**sem nenhuma linha**. É a régua que `HistoricoDaOfertaService:160`-`:162` escreve com todas as
letras (*"quem narra o ATO é quem o executa"*), e o mesmo padrão de `RevisaoDeImagemService`
(evento do ato + evento da reabertura). As frases moram em `PrecoDoPainelService`
(`registroDePrecoSalvo()`/`registroDeFreteConfirmado()`) porque levam **dinheiro formatado** e
`brl()` é privado dali — uma casa só.

⚠️ **O PREÇO EM SI NÃO É GRAVADO, e isso é território — DIVERGÊNCIA DECLARADA:** o `applyPrice` do
protótipo (`:1169`) faz `x.price = r2(p)` porque tem o banco em memória; aqui o preço efetivo é
**LIDO** (`DadosEfetivosService` / `pub_variante_precos`) e quem o escreve é o **editor**, que esta
fase não toca. O que pertence ao portão é a **consequência**.

### 4. `aprovado` é ACEITO nas três escritas — e é a direção SEGURA

`registrarMudanca()` devolve a oferta `aprovado` para `aguardando_aprovacao` pela única porta.
➡️ Salvar preço (ou confirmar frete) numa oferta aprovada **REABRE** a aprovação, e é isso que
**impede a Fase 193 de publicar um número velho**. Recusar seria *menos* seguro. É o que o
protótipo faz (`:1169`: `if (x.status === 'approved') x.status = 'awaiting'`).

⚠️ **ASSIMETRIA DECLARADA:** a chave `pode_salvar` do payload é `live` **somente** (wave 188-04) —
ela é o **conforto** da tela. A trava é a `recusaDeRevisao()` da action, e ela é **um estado mais
permissiva, na direção segura**. Fora de `LIVE ∪ {aprovado}` (`preparando`, `publicando`,
`publicado`, `falha`) a recusa é 422 com o id que **já existe**
(`AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO`), nunca um id novo.

### 5. "Conferir alteração" CONFERE SEM ALTERAR O PREÇO

É contraintuitivo — o botão se chama *"Conferir alteração"* e `:1170` faz
`D.offers[id].priceChanged = null` **e só**. ➡️ Há asserção de que o preço ficou **IGUAL** (lido de
`ComposicaoFinanceiraService::para()->preco` antes e depois) **e** de que a versão **não** subiu.
**É essa asserção que impede alguém de "completar" a função** — e a mutação **(vi)** (trocar a
chamada por `registrarPrecoSalvo()`) **MORREU** nela.

### 6. O ESCOPO do ajuste — e o `<behavior>` do plano era **IMPOSSÍVEL** neste schema

⚠️⚠️ **ACHADO QUE MUDOU A FIXTURE PRESCRITA, MEDIDO E NÃO DEDUZIDO.** O `<behavior>` (e as
mutações (i)/(ii) do `<verification>`) pedia **"4 ofertas em 2 conjuntos do MESMO produto"**.
A primeira tentativa reprovou com:

```
SQLSTATE[23000]: Integrity constraint violation: 19
UNIQUE constraint failed: pub_rascunhos.produto_id
```

A unicidade é **`pubr_produto_uq`**, declarada em
`database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php:95` (decisão **D15/D27**
da Fase 164: *"`pub_rascunhos` passa a ser de um PRODUTO"*), e **vale em MariaDB também** — não é
artefato do SQLite dos testes.

➡️ **Consequência:** um produto tem **um** rascunho, logo `ofertasAfetadas()` devolve o mesmo
conjunto para `set` (`where('rascunho_id', …)`) e para `product` (`where('produto_id', …)`).
**A diferença entre os dois é inobservável por CONTAGEM hoje.**

➡️ **A fixture honesta, e o que ela prova:**

| escopo | alcance medido | o que provaria se errado |
|---|---|---|
| `offer` | **1** (só aquela) | mover a irmã poria a foto reprovada no ar pelo Premium |
| `set` | **2** (as duas do rascunho, atravessando os **DOIS** grupos de foto) | com um grupo só, *"alcança todos os grupos"* (achado **S2** da Fase 186) seria inobservável |
| `product` | **2** — e **ZERO do SEGUNDO produto da mesma conta** | **T-188-51**: sem o segundo produto na fixture, *"não vazou"* seria verdade por **não haver para onde vazar** |

➡️ **E a PREMISSA FICA TRAVADA POR ASSERÇÃO** sobre o índice único: se a unicidade cair, os dois
escopos passam a divergir e o caso **REPROVA** — o comportamento certo. A diferença entre `set` e
`product` fica provada **onde ela É observável**: a **coluna-âncora**
(`PubAprovacaoAjuste::ANCORA_DO_ESCOPO`: `oferta_id` × `conjunto_id` × `produto_id`, com
`ancoraValida()` conferida) e o **destino legível**.

⚠️ **As mutações (i) e (ii) continuam MORRENDO** nessa fixture: (i) `offer` alcançando o rascunho
dá 2 onde se espera 1; (ii) `set` alcançando só a oferta dá 0 onde se esperam 2.

⚠️ **`publicando`/`publicado` NÃO vão para `changes`** (`:1198` só move `awaiting`, `ecf_review` e
`approved`) — **mas RECEBEM o evento**, porque o fato aconteceu. As ofertas são levadas aos
estados **pela ÚNICA PORTA antes de medir**: medir *"não reabriu"* numa oferta que nunca esteve lá
é o **cenário IMPOSSÍVEL** que passa sem provar nada (achado da wave 188-05).

### 7. Resolver, nos DOIS sentidos, na MESMA fixture

| caso | medido |
|---|---|
| resolver **UM de DOIS** | a oferta **CONTINUA** em `ajustes_solicitados`, `restantes = 1` |
| resolver **O ÚLTIMO** | a oferta **VOLTA** para `aguardando_aprovacao` pela única porta, `restantes = 0` |

⚠️ Um gate que só testasse o "último" **passaria com um serviço que devolve SEMPRE** — a mutação
**(iii)** prova isso, e **MORREU**. ⚠️ E `{ajuste}` é conferido contra `abertosQueAfetam()` (o
**FILTRO É A CONSULTA**): ajuste de outra conta → **404, nunca 403**; ajuste **já resolvido** →
404 também, com asserção de que o fato **não** foi registrado duas vezes.

### 8. A exceção — ISCA POR BLOQUEIO nos SEIS outros, e quem LIBEROU

⚠️⚠️ **Os SEIS outros saem de `VocabularioDoPortao::OS_OITO` MENOS `PubAprovacaoExcecao::LIBERA`**,
nunca de uma lista escrita à mão que poderia ficar velha — com asserção de que o resultado tem
exatamente **6**. Cada um recusado com `BLOQ-08-EXCECAO-NAO-LIBERA-ESSE-BLOQUEIO`, **sem gravar**,
numa asserção única que **nomeia qual passou**. ⚠️ `loss`/`margin` do protótipo **também caem**:
`TIPO_DO_PROTOTIPO` é mapa de **conferência**, não um `||` que aceita as duas formas.

⚠️ **ISCA NAS DUAS DIREÇÕES:** os **dois** legítimos TÊM de ser aceitos — senão o laço acima
passaria com uma rota que recusa **toda** exceção, e o gate seria vazio por construção.
A mutação **(v)**, que muda **só o comportamento** de `libera()` sem tocar a constante `LIBERA`
(tocá-la a mataria numa asserção de **lista de nomes**, que não é prova de comportamento),
**MORREU na isca por bloqueio**.

⚠️ **A fronteira: 9 → 422 (`BLOQ-09-EXCECAO-SEM-MOTIVO`) · 10 → 302 · 11 → 302**, com o mínimo
**LIDO** de `PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA` e nunca o literal `10`. A mutação **(iv)**
(`>` em vez de `>=`) **MORREU**.

⚠️⚠️ **QUEM LIBEROU É O RESPONSÁVEL ESCOLHIDO, NÃO QUEM CLICOU** — é o centro do critério 11
(`:1203` grava o evento com `F.by`, não com `this.ME`). Medido: `liberada_por` **e** o
`autor_id` do evento são o gestor, com asserção explícita de que **não** são quem clicou. E o
`responsavel_id` é conferido contra a **lista FECHADA da conta** (o MESMO resolvedor do filtro da
fila e dos autorizadores do payload): id de fora → **404, nunca 403**, sem gravar.

### 9. Os TRÊS payloads dos modais (B1.4 · B1.5 · B1.6) — **teste de LEITURA**

Os planos **188-11** e **188-12** mandavam **PARAR** se o servidor não entregasse estas listas, e
**nenhum plano as entregava**. Elas nascem na chave `modais` do payload do painel — e são
**testadas pela ROTA**, não por afirmação (a primeira redação dizia que o serviço *"devolve"* os
itens sem nada medir isso).

| chave | o que é | fonte |
|---|---|---|
| `modais.ajuste.itens` | uma entrada **por imagem da galeria** + os **SETE** fixos, na ordem, com o sétimo alternando por `units > 1`; cada item com `rotulo`, `escopo`, `destino`, `nota_do_escopo`, `sugestoes[]` | `PubAprovacaoAjuste` (escopos, `destinoLegivel()`, `MODALIDADES`) + a galeria JÁ LIDA da wave 188-03 |
| `modais.excecao.autorizadores` | `id`, `nome`, `descricao` — lista **FECHADA da conta** | `FiltrosDaFilaService::responsaveisDaConta()`, **CHAMADO** (a MESMA array que o filtro da fila já calcula) |
| `modais.reprovar.defeitos` | os 6 chips: `chave`, `rotulo` | `PubAprovacaoDefeito::DEFEITOS`, a lista canônica |

⚠️ **As três chaves existem SEMPRE, mesmo vazias** — chave ausente e lista vazia são casos
**diferentes** no cliente, e foi **chave ausente** que derrubou esta página em 07/10/2026. As
**sete** chaves do caminho `modais.*` são conferidas numa asserção **única** coletando as ausentes.
⚠️ **Lista vazia é caso REAL:** sem autorizador o modal **diz e não envia** (D4) — e há **ISCA do
recorte**: uma conta **sem responsável** devolve `[]`, o que impede o *"não vazia"* de passar com
uma lista global.

⚠️ **O `destino`/`nota_do_escopo` saem de UM LUGAR SÓ.** Escrevê-los no `.jsx` criaria a segunda
verdade sobre *"o que cada item afeta"* — e a divergente **moveria ofertas erradas para
`changes`**. O gate confere **item por item** que `destino === PubAprovacaoAjuste::destinoLegivel()`
e que `nota_do_escopo` sai da constante, e que **VOC-06** é respeitado (as chaves `offer`/`set`/
`product` **nunca** aparecem no texto que a pessoa lê, com isca provando que a sonda vê a chave
quando ela está presente).

⚠️⚠️ **A ORDEM É A LISTA INTEIRA, NUNCA UM PISO.** Com `count >= 9` um item perdido ainda passaria.
A alternância do sétimo é provada em fixture de **UNIDADE** (`Categoria`) e de **COMBO**
(`Composição do kit`), com asserção de que os outros itens **não** mudaram, e o item de preço
nomeando a modalidade da oferta (`Preço Clássico` × `Preço Premium`, `modName` de `:1059`).

---

## ⚠️⚠️ AS BASELINES — NA PARTIDA E NO FIM, COM O QUE É DA WAVE 9 SEPARADO

### Na partida (HEAD `091340b6`, antes de qualquer edição)

```
PARTIDA:   091340b6
--autoteste: 20 casos, 0 divergencia        (colado na §autoteste abaixo)
PHP (suite inteira):            2550 / 23342 / 0 falhas
PHP (Publicador/Aprovacao):      301 /  5367 / 0 falhas
JS:          5610 / 5604 pass / 5 fail / 1 skipped
PROTOTIPO:  design_handoff_publicador/Aprovacao.dc.html · 1432 linhas
            sha256 68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2
```

⚠️⚠️ **AS 5 FALHAS JS DA PARTIDA SÃO **DA WAVE 9 EM VOO**, NOMEADAS UMA POR UMA** — ela estava
rodando quando medi, e o harness as lista por semântica:

| falha | de quem |
|---|---|
| `Características secundárias nasce recolhido` | **Fase 87**, pré-existente, aceita por `REF_JS_FALHAS=1` — **não corrigir** |
| `⚠️ ANTI-FANTASMA: todo caminho citado por este gate existe no disco 188_09` | **wave 188-09** |
| `⚠️⚠️ D7: os TRÊS estados do resultado têm TRÊS cores DISTINTAS… 188_09` | **wave 188-09** |
| `⚠️ D4: a coluna de preço NÃO desenha casca sem dado… 188_09` | **wave 188-09** |
| `⚠️ MANIFESTO: este arquivo está declarado em contagens-188-09.txt… 188_09` | **wave 188-09** |

➡️ **Eu NÃO as consertei** (a régua é explícita: *"se um id da wave 9 falhar, NÃO é seu: ela roda
em paralelo. Diga, não conserte."*). Ela as consertou e fechou.

### No fim

```
BUILD:     exit 0 · 51 s
MANIFEST:  495 entradas / 202 paginas / isca ausente / dois controles presentes
           (CSS de app.jsx: ["assets/app-BatUsbz0.css"])
JS:        5654 / 5653 pass / 1 fail / 0 skipped
PHP (suite inteira):           2568 / 23591 / 0 falhas
PHP (Publicador/Aprovacao):     319 /  5616 / 0 falhas
```

### A conta, e o que ela fecha

| número | partida | fim | diferença | de quem |
|---|---|---|---|---|
| MANIFEST entradas / páginas | 495 / 202 | **495 / 202** | **0** | **minha**: este plano **não cria nenhum `.jsx`** |
| JS testes | 5610 | **5654** | **+44** | ⚠️ **TODA da wave 9** — ela ainda escrevia quando medi |
| JS falhas | 5 | **1** | **−4** | ⚠️ **TODA da wave 9** (os quatro `188_09` fecharam) |
| JS skipped | 1 | **0** | **−1** | ⚠️ da wave 9 (um gate dela estava em `skip` por `mtime` no meio da execução) |
| PHP `Aprovacao` testes | 301 | **319** | **+18** | **minha**: exatamente os **18** métodos novos |
| PHP `Aprovacao` asserções | 5367 | **5616** | **+249** | **minha**: 38+44+25+26+46+30+40 = **249** |
| PHP **suíte inteira** testes | 2550 | **2568** | **+18** | **minha**: exatamente os 18 — ⚠️ nada mais mexeu no PHP (os **seis** commits da wave 9 tocaram **ZERO** arquivos `.php`, conferido por ela um por um) |
| PHP **suíte inteira** asserções | 23 342 | **23 591** | **+249** | **minha**: a MESMA soma, e ela fecha **exatamente** com a dos sete ids |
| PHP **suíte inteira** falhas | 0 | **0** | **0** | ⚠️ inclusive as **duas** conhecidas da Fase 179 (`CockpitDaContaTest:966`/`:1318`, **FORA** de `Aprovacao/`), que estavam **verdes** nas duas medições |

⚠️⚠️ **A DIFERENÇA JS FECHA COM O MEU DIFF: ele toca ZERO arquivos `.js`/`.jsx`** — provado pelo
gate de território (os 10 arquivos tocados pelos commits do assunto, listados abaixo). E a medição
final bate **byte a byte** com a que a wave 9 registrou no seu apêndice
(`5654 / 5653 pass / 1 fail / 0 skipped`), medida independentemente por ela.

### ⚠️ A CORRIDA DE `storage/framework/testing` — medida, e provada por execução ISOLADA

A wave 9 registrou que, na rodada dela de `tests/Feature/Publicador/Aprovacao`, **dois** arquivos
falharam no diretório e **passaram sozinhos** — um deles o meu
`EscritaDeAjusteEExcecaoDoPainelTest` (`8 passed (116 assertions)` **isolado**). É a corrida entre
**duas suítes PHPUnit simultâneas na MESMA árvore**, a mesma que a wave 188-07 mediu.
➡️ **Depois que ela terminou, a minha rodada do diretório deu `319 passed / 0 failed`** — zero
falha, sem mudança de código. **Não é regressão, não se conserta, e não se cria `REF_PHP_FALHAS`.**

⚠️ **E as duas falhas conhecidas da Fase 179** (`tests/Feature/Publicador/CockpitDaContaTest.php:966`
e `:1318`, **FORA** de `Aprovacao/`, que vão e voltam com a hora do dia) estavam **VERDES** nas
duas medições — como já estavam na baseline da wave 188-01.

---

## ⚠️ A RÉGUA HONESTA DE REDE — e por que ela tem DUAS formas aqui

| rota | régua | por quê |
|---|---|---|
| `preco` · `conferir-alteracao` · `ajuste` · `ajuste/{a}/resolver` · `excecao` | **ZERO requisição de QUALQUER tipo** | nenhuma lê a composição: `precoDoAlvo()` lê a Precificação e `registrarPrecoSalvo()` não toca a tarifa |
| `preco/simular` · `frete/previa` · `frete` | **ZERO requisição de ESCRITA** (nenhum método ≠ GET, nenhuma URL de `/items`) | elas **LEEM** a tarifa real no GET `/sites/MLB/listing_prices` — a **decisão 9 do usuário** |

⚠️⚠️ **PROMETER ZERO ABSOLUTO NAS TRÊS DE BAIXO SERIA GATE QUE O PRÓPRIO DESENHO CONTRADIZ**, e o
executor seguinte o "consertaria" **pré-aquecendo o cache** — virando gate sobre zero casos. A
leitura autorizada está em **lista CONSTANTE nomeada** (`LEITURAS_AUTORIZADAS`), nunca num filtro
largo, e há asserção de que ela é exatamente `['/sites/MLB/listing_prices']`.

### ⚠️ ACHADO: a ISCA do espião MUDA DE FORMA quando o zero é literal

O primeiro caso desta wave **REPROVOU** com *"o espião de rede está CEGO: nem a tarifa foi vista —
Failed asserting that 0 is greater than 0"*. ➡️ **O conserto foi do GATE, não do código:**
`salvarPreco()` faz zero requisição, então `assertGreaterThan(0, requisicoesDeTarifa())` é uma
isca **errada** ali. A isca certa é uma requisição **DELIBERADA** que TEM de aparecer no gravador
(`provarQueOEspiaoVe()`), chamada no **FIM** do caso porque ela polui o gravador.

E as três iscas do espião, nas duas direções:

| isca | resultado |
|---|---|
| uma requisição deliberada aparece no gravador | ✓ (o gravador está vivo) |
| `escritasEDesvios()` **ACUSA** um `POST /items` | ✓ `['POST https://api.mercadolibre.com/items']` |
| `Http::recorded()` é **Collection**, não array | ✓ tratado com `foreach` (medido na wave 188-05: `array_map` sobre ela **lança**) |

---

## ⚠️ AS SETE ENTRADAS DO MANIFESTO, E AS TRÊS BIJEÇÕES

```
preco-salvo-e-versao   php  3 testes / 38 assercoes   PASSOU
frete-confirmado       php  3 testes / 44 assercoes   PASSOU
simular-sem-gravar     php  2 testes / 25 assercoes   PASSOU
payload-dos-modais     php  2 testes / 26 assercoes   PASSOU
ajuste-por-escopo      php  3 testes / 46 assercoes   PASSOU
resolver-ajuste        php  2 testes / 30 assercoes   PASSOU
excecao-travas         php  3 testes / 40 assercoes   PASSOU
```

⚠️ **Nenhum id com mais de TRÊS métodos.** ⚠️ **Todas as contagens MEDIDAS**, uma invocação por id,
com o ANSI tirado antes de ler a linha `Tests:`. ⚠️ **`vocabulario-tamanhos` NÃO é declarado, e
está CERTO:** este plano é **só de servidor** e não roda o GATE-03 — a regra é *quem roda, declara*
(mesmo caso de `contagens-187-02.txt` e `contagens-188-05.txt`).

### Bijeção e homônimo, **por script** (`bijecao-188-06.php`)

```
ISCA_ALTERNANCIA=expandiu-3
ARQUIVOS=1217 METODOS=7828 MEUS=18
SEM_ID=0
ORFAOS=0
IDS_REPETIDOS=0
HOMONIMOS=0
ISCA_POSITIVA=2/2
ISCA_NEGATIVA=nao-acusa
IDS=7 NO_VERIFY=7
ID_SEM_VERIFY=0
VERIFY_SEM_ID=0
```

⚠️⚠️ **A SONDA EXPANDE A ALTERNÂNCIA `test_(a|b|c)` ANTES DE COMPARAR** — foi o furo medido pela
wave 188-05, em que `/test_[A-Za-z0-9_]+/` **não casava** a forma que **todos** os manifestos desta
fase usam e acusava `SEM_ID=14` **com o manifesto CORRETO**. A isca
`ISCA_ALTERNANCIA=expandiu-3` guarda isso.
⚠️ **As três bijeções:** método ↔ id (método sem id **nunca roda**), id ↔ método (id órfão é gate
sobre **zero casos**, indistinguível de verde) e **id ↔ `<verify>`** (os sete aparecem em
`<verify>` do plano, e nenhum `<verify>` usa id não declarado).
⚠️ **Homônimo nas DUAS direções, incluindo PREFIXO** (o `--filter` do PHPUnit é regex
**não-ancorado** e as Fases 186/187 estão **em produção**): zero, com `ISCA_POSITIVA=2/2`
(superstring **e** prefixo acusam) e `ISCA_NEGATIVA=nao-acusa` (um nome real da 188-05 **não** é
acusado).

### ⚠️ OS 32 IDS DAS WAVES ANTERIORES, RE-RODADOS — contagem INALTERADA

```
188-01: baselines-medidas 1/7 · tokens-e-tamanhos (js) · vocabulario-tamanhos (js) · fid01-remedida 1/13
188-02: identificacao-e-chips 2/41 · pendencias-e-destinos 2/45 · rodape-e-aprovavel 2/43 ·
        cadeia-e-incompleto 1/20 · navegacao-e-payload 2/50 · custo-do-painel 2/24 ·
        painel-na-rota 2/41 · territorio-painel 1/42 · bijecao-manifesto-02 1/22
188-03: galeria-e-marcadores 3/84 · borda-e-estados 3/59 · sem-alerta-e-contagem 3/48 ·
        verificacoes-e-ordinal 3/113
188-04: kpis-e-incompleto 3/101 · composicao-e-origem 3/89 · simulacao-sem-salvar 3/77 ·
        atalhos-excecao-irma 3/185
188-05: imagem-aprovar-e-sem-alerta 3/49 · imagem-reprovar 3/58 · img-02-img-03-sem-regressao 1/15 ·
        salvar-revisao 3/52 · aprovar-e-enfileirar 3/85 · ausencia-de-segundo-passo 1/17 ·
        tentar-de-novo 2/32
188-07: casca-do-painel (js) · vocabulario-tamanhos (js)
188-08: coluna-de-fotos (js) · vocabulario-tamanhos (js)
186-09: andamento-um-lugar-so 1/20 · transversais 12/258 · transversais-render (js)
➡️ TODOS PASSOU. ⚠️ Inclusive os DOIS em que houve cirurgia: `navegacao-e-payload` segue **2/50**
   e `img-02-img-03-sem-regressao` segue **1/15**.
```

---

## ⚠️ CIRURGIA EM TRÊS GATES ALHEIOS — todos reprovavam pelo PRÓPRIO conserto

| gate | por que reprovava | o conserto | contagem |
|---|---|---|---|
| `EscritaDeImagemDoPainelTest` (188-05, `img-02-img-03-sem-regressao`) | ele crava a **igualdade de conjunto** das rotas do painel, para provar que a Fase 189/192 não foi antecipada — e as minhas **oito** rotas novas crescem o conjunto legitimamente | os oito nomes entraram, **marcados `// 188-06`** — e é literalmente o que a mensagem do gate pede (*"se é a 189/192 entrando, atualize a fronteira declarada aqui"*). O invariante que importa segue de pé: **nenhuma rota de `imagem.substituir`/`recolocar`/`regenerar`** | **1/15, INALTERADA** |
| `PainelDeRevisaoServiceTest` (188-02, `navegacao-e-payload`) | ele crava a **igualdade de conjunto** das chaves do payload, e `modais` é chave nova | `modais` entrou na lista `$esperadas`; a asserção segue **UMA** e segue igualdade de conjunto | **2/50, INALTERADA** |
| `PainelDaOfertaRotaTest` (188-02, `custo-do-painel`) | o espião é uma **subclasse anônima** de `PainelDeRevisaoService` e a assinatura de `para()` ganhou um 3º parâmetro → **`Fatal error: Declaration … must be compatible`** | a assinatura do espião repassa o 3º argumento **INTACTO** (comê-lo faria o espião devolver `autorizadores` **vazio** — falso negativo ali e verde mentiroso no meu gate) | **2/24, INALTERADA** |

⚠️ **Nenhuma contagem foi elevada, nada foi afrouxado, e nenhuma escrituração nova foi criada.**

### ⚠️ Por que `modais` NÃO virou um 6º argumento do construtor

Há gate de **aridade** em `PrecoDoPainelServiceTest:1481`-`:1482` exigindo **cinco** parâmetros
obrigatórios, e **ele está CERTO**: guarda a armadilha do `Container::resolveClass()`
(`Container.php:1327`-`:1331`), que devolve o **DEFAULT** quando ele existe. Um `app()` dentro do
serviço seria **service locator disfarçado de injeção**, exatamente o que o docblock do construtor
proíbe. ➡️ Os autorizadores chegam como **3º parâmetro de `para()`**, e a action **repassa a MESMA
array** que já calcula para o filtro da fila
(`MlbPublicadorAprovacaoController:244`): **zero consulta nova, zero segunda verdade**.
⚠️ O default `[]` **não é a ilusão de injeção** (ela vale para parâmetro de **CLASSE** que o
container resolve): aqui é o caso honesto *"nenhum autorizador resolvido"* → o modal **diz e não
envia** (D4). E há caso de **ROTA** exigindo a lista **não vazia** — é ele que impede o default de
virar silêncio.

### ⚠️ E UMA leitura da coluna de fotos por oferta

`modais.ajuste.itens` precisa da galeria, e `para()` já a lê para a chave `fotos`. ➡️ A leitura é
feita **UMA vez** e passada adiante: duas chamadas a `FotosDoPainelService::para()` dobrariam as
consultas **e** abririam a chance de as duas listas discordarem — e aí o *"Imagem #3"* do modal
apontaria para **outra foto**. O gate de custo do 188-02 segue verde (`custo-do-painel` 2/24).

---

## ⚠️⚠️ A MUTAÇÃO — **OITO**, com `mutar-188-06.sh`, e **TODAS MORRERAM**

Receita seguida: **commitar ANTES** · `cp -p` para cópia **com o plano no nome** · ocorrência
**travada em 1 ANTES** por **string exata** (`substr_count`, nunca `grep -c`, que conta **linhas**)
· **linha conferida DEPOIS** · **`php -l` depois de aplicar** · aplicação provada por
**divergência de `sha256`** (NO-OP aborta) · restauração **DA CÓPIA** em `trap EXIT` · `sha256`
contra `git show HEAD:` com `tr -d '\r'` nos dois lados. ⚠️ **`git checkout` NUNCA.**

| # | o que foi mutado | arquivo | veredicto |
|---|---|---|---|
| (i) | escopo `offer` passando a mover o **conjunto** | `AjustesDaOfertaService` | **MORREU** (1 failed) |
| (ii) | escopo `set` passando a mover **só a oferta** | `AjustesDaOfertaService` | **MORREU** (1 failed) |
| (iii) | resolver devolvendo a `awaiting` **SEMPRE** | `AjustesDaOfertaService` | **MORREU** (1 failed) |
| (iv) | justificativa com `> 10` em vez de `>= 10` | `PubAprovacaoExcecao` | **MORREU** (1 failed) |
| (v) | a exceção aceitando `fotos_a_conferir` — ⚠️ **só o COMPORTAMENTO de `libera()`**, sem tocar a constante `LIBERA` | `PubAprovacaoExcecao` | **MORREU** (1 failed) |
| (vi) | `conferir-alteracao` **REAPLICANDO** o preço | `MlbPublicadorAprovacaoController` | **MORREU** (1 failed) |
| (vii) | preço salvo **sem limpar** o aviso de alteração | `AprovacaoDaOfertaService` | **MORREU** (1 failed) |
| (viii) | a prévia do frete somando o frete **DOBRADO** (o PONTO FIXO) | `PrecoDoPainelService` | **MORREU** (1 failed) |

⚠️ **A (v) foi desenhada para matar o COMPORTAMENTO, não a lista de nomes:** mutar a constante
`LIBERA` a mataria na asserção de **lista**, que *não é prova de comportamento* — então a mutação
mexe só em `libera()`, e quem a pegou foi a **isca por bloqueio**.
⚠️ **`sha256` de cada arquivo voltou IGUAL a HEAD** em todas as oito, e `git diff HEAD` nos meus
arquivos saiu **vazio** depois da campanha — nada de mutação ficou no disco.
⚠️ **`sha256` do próprio script** (nome único, scratchpad COMPARTILHADO): `95d367092a60f990`.

---

## ⚠️ O TERRITÓRIO — por **ESCOPO DO ASSUNTO `(188-06)`**, duas passadas, isca nas três direções

```
COMMITS_DO_ASSUNTO=2        (1f2dbe44, 4c3dde27 — pelo ASSUNTO, nunca por RANGE)
ARQUIVOS_TOCADOS=10
  app/Http/Controllers/MlbPublicadorAprovacaoController.php
  app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
  app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php
  routes/mlb_anuncios.php
  tests/Feature/Publicador/Aprovacao/EscritaDeAjusteEExcecaoDoPainelTest.php
  tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php
  tests/Feature/Publicador/Aprovacao/EscritaDePrecoEFreteDoPainelTest.php
  tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php
  tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php
  tests/gates/contagens-188-06.txt

FANTASMAS=0                 (37 alvos vetados, `test -e` em CADA um)
VETADOS_TOCADOS=nenhum      (diff `numstat` vazio E ausentes dos commits do assunto)
ISCA_POSITIVA=acusa-o-que-eu-toquei
ISCA_NEGATIVA=nao-acusa-o-intocado
ISCA_FANTASMA=acusa-o-inventado
DEPS_DIFF_VAZIO=sim         DEPS_NOS_COMMITS=0     (T-188-SC)
MIGRATIONS_NOS_COMMITS=0    (esta fase NAO tem migration; `artisan migrate` NAO foi rodado)
```

⚠️ **"Prova por RANGE" não passa nesta árvore** (a 187-11 mediu 111 commits no range, 43 da sessão
paralela, e o diff cru acusou 12 dos 60 alvos vetados). ⚠️ **E o AUTOR não é conferido:** as três
máquinas desta árvore commitam com o MESMO `user.name`.

### ⚠️ UM FANTASMA QUE A PRÓPRIA SONDA PEGOU — o **DÉCIMO** da milestone

A primeira execução acusou
`app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` como **FANTASMA**. **O real é
`app/Services/Publicador/Fila/ResumoRapidoService.php`**, **sem** o `Aprovacao/` — e é exatamente a
confusão que a `188-REGUA.md` §9 e a decisão 9 avisam que **APAGARIA** a decisão das duas margens.
➡️ **Reproduzida por quem tinha acabado de ler o aviso**, e pega pelo `test -e` na primeira
execução. **O ANTI-FANTASMA se paga sozinho.**

### ⚠️ A camada B do gate de andamento: **NÃO entrei na lista de EXPOSITORES — e medi por quê**

```
MlbPublicadorAprovacaoController.php   SONDA_LARGA('andamento' =>)=0   SONDA_ESCRITA(->andamento =)=0
PainelDeRevisaoService.php             SONDA_LARGA('andamento' =>)=1   SONDA_ESCRITA(->andamento =)=0
PrecoDoPainelService.php               SONDA_LARGA('andamento' =>)=0   SONDA_ESCRITA(->andamento =)=0
ISCA: a sonda casa quando a forma EXISTE => 1
```

➡️ A **única** ocorrência da sonda larga em `PainelDeRevisaoService` é a chave `'andamento' => [`
da linha `:644`, **introduzida pela wave 188-02** (`git log -S`: commit `e2aa4263`) — **não por
mim**. E os gates da camada B estão **verdes** (`andamento-um-lugar-so` **1/20** e `transversais`
**12/258**). ➡️ **Nada a acrescentar e nada a remedir:** `contagens-186-09.txt` segue intacto
(`andamento-um-lugar-so` **tests=1 assertions=20** e `transversais`
**tests=12 assertions=258** — ⚠️ o `19` da `188-REGUA.md` §1 é o tamanho da LISTA de quem pode
mencionar o andamento, **não** a contagem de asserções; os números do manifesto são estes, medidos). **Entrar na lista seria linha morta**, exatamente
como a wave 188-05 mediu para o seu caso.
⚠️ **Nenhuma das oito actions escreve `andamento`** — quem move é `AndamentoDaOfertaService::mover()`,
por dentro dos serviços da 186 — **e nenhuma o devolve numa chave de saída**: as respostas de
ajuste levam **IDS** (quais ofertas o escopo alcançou, quantos restam), não estados.

---

## ⚠️ OS PONTEIROS: CINCO DESLOCADOS, DEZESSEIS CONFIRMADOS — e o plano DIVERGIA do FID01

A `188-FID01.md` ganhou a **§P** com a tabela completa (a **sétima** confirmação consecutiva da
lição da §J). Em resumo:

| o que a prosa do plano / a §F dizia | real (medido por `sed`) | o que está DE FATO lá |
|---|---|---|
| as três origens de frete em `:1403` | **`:1401`** | `:1403` é o `return` do ramo **`reject`** (MOD-04) |
| a prévia `fin(…, {freight: v})` em `:1402` | **`:1400`** | `:1402` é o `length > 2` do MOD-04 |
| `previewColor` em `:1402` (§F.3) | **`:1401`** | idem |
| `destColor`/`destNote` em `:1396` (§F.1) | **`:1397`** | `:1396` é a abertura `if (k === 'adjust')` |
| os **8** fixos de `adjustItems` em `:1181`-`:1191` | **`:1183`-`:1189`**, e são **SETE** | `:1181` é o `.map()` das imagens; `:1191` é um `}` |

⚠️⚠️ **E A SEXTA VEZ EM QUE O PLANO DIVERGIU DO FID01, COM O FID01 TENDO RAZÃO:** a prosa do
`188-06-PLAN.md` diz *"os **8** fixos na ordem, com o **8º** alternando"*; o protótipo tem **4 de
escopo `offer` + 3 de escopo `product` = SETE**, e a **§F.1 do FID01 também lista SETE**.
➡️ **A premissa fica TRAVADA** por `assertCount(7, PainelDeRevisaoService::AJUSTE_ITENS_FIXOS)` +
a igualdade da lista de escopos na ordem.

**Confirmados por `sed`** (não presumidos): `:1048` · `:1059` · `:1169` · `:1170` · `:1179` ·
`:1181` · `:1193` · `:1197` · `:1198` · `:1202` · `:1203` · `:1204` · `:1395` · `:1402` · `:813` ·
`:795`-`:804`.
E **fora** do protótipo: `routes/mlb_anuncios.php:527`-`:528` (o padrão das rotas) ·
`HistoricoDaOfertaService:160`-`:162` (*"quem narra o ATO é quem o executa"*) ·
`AjustesDaOfertaService:319` (o evento da exceção) ·
`PrecoDoPainelServiceTest:1481`-`:1482` (o gate de aridade) ·
`PainelDeRevisaoServiceTest:1274` e `PainelDaOfertaRotaTest:203`/`:218` (as **duas** construções
manuais — ⚠️ o briefing cita `:1269` e `:203`, e os dois estavam certos **antes** das minhas
linhas: o `new PainelDeRevisaoService(` andou para `:1274` porque a cirurgia do contrato acrescentou
5 linhas de comentário acima dele. **Reconfirmados por `grep -n` DEPOIS da edição.**)
· `MlbPublicadorAprovacaoController:244` (o `$responsaveis` reaproveitado) ·
`database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php:95` (`pubr_produto_uq`)
· `routes/mlb_anuncios.php:158` (o prefixo `publicador/produtos/{produto}` — **:157 é o middleware da
rota ANTERIOR**, confirmando a correção da §K contra a `188-REGUA.md` §14).

---

## Divergências e decisões — DECLARADAS, com `arquivo:linha`

**1. [DIVERGÊNCIA] A fixture de "4 ofertas em 2 conjuntos" é IMPOSSÍVEL** —
`pub_rascunhos.produto_id` é UNIQUE (`pubr_produto_uq`,
`database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php:95`, **D15/D27**).
`set` e `product` alcançam o mesmo conjunto hoje; a premissa ficou **travada por asserção** e a
diferença provada pela **coluna-âncora** e pelo **destino**. Ver §6 acima e a §P do FID01.

**2. [DIVERGÊNCIA] `ComposicaoFinanceiraService::para()` com o frete substituído não existe** — a
prescrição da §F.3 é inimplementável sem alterar a Fase 186. As duas alternativas foram **medidas e
descartadas**, e a equivalência ficou travada por **PONTO FIXO**. Ver §2 acima.

**3. [DIVERGÊNCIA] O preço em si não é gravado** — `applyPrice` (`:1169`) faz `x.price = r2(p)`
porque é protótipo com banco em memória. Aqui o preço é LIDO e quem o escreve é o **editor**, fora
do território desta fase. O portão grava a **consequência**.

**4. [DIVERGÊNCIA — já declarada pela Fase 186 e pela wave 188-05, NÃO REABERTA] A frase
*"§ Exceção autorizada por X · data · justificativa"* é a de EXIBIÇÃO**
(`PubAprovacaoExcecao::fraseDeExibicao()`, que o painel entrega em `preco.excecao`, wave 188-04), e
há asserção do texto **literal**. O **evento** do histórico é *"Autorizou exceção (…): …"*
(`AjustesDaOfertaService:319`), **com quem liberou**. Emendar o texto exigiria alterar um serviço da
186 em produção **ou** gravar um **2º evento para o mesmo fato** — o defeito que a 186 consertou em
15/10/2026.

**5. [DIVERGÊNCIA] A prosa do protótipo de `:804`** (*"No protótipo, a exceção é registrada como
autorizada pelo responsável escolhido."*) **NÃO foi para a tela** — é fala **sobre** o protótipo.
Nenhuma string dela aparece nos meus arquivos, e o gate de ausência (ancorado no **sujeito**) prova
que nem o estado de espera entrou.

**6. [DIVERGÊNCIA] As CHAVES viajam, os RÓTULOS ficam no servidor (VOC-06).** O corpo manda
`cotacao_transportadora`/`tabela_cliente`/`simulador` e `resultado_negativo`/
`margem_abaixo_do_minimo` e `offer`/`set`/`product`; os **rótulos** literais do protótipo
(`Cotação da transportadora` · `Tabela do cliente` · `Simulador de frete`, `:1401`) vêm de
`PubAprovacaoFrete::ORIGENS`. ⚠️ **`loss`/`margin` e `prod` do protótipo são RECUSADOS de
propósito** — os mapas `TIPO_DO_PROTOTIPO`/`ESCOPO_DO_PROTOTIPO` são de **conferência**, não um
`||` que aceita duas formas. Há caso provando a recusa dos dois.

**7. [DIVERGÊNCIA] `descricao` do autorizador.** `:1399` traz dois nomes **inventados** com um
**cargo inventado** (`'Ricardo Gomes · gestor da conta'`). **Não existe dado de "cargo nesta conta
ML"** neste sistema (a pivot `company_users` tem papel por **serviço**), e escrever um cargo seria a
tela **afirmando o que não é**. ➡️ A descrição é o número **REAL** que a conta tem — o **mesmo** que
o filtro de Responsável da fila já mostra (`responsaveisDaConta()` → `total`), com singular e plural
em uma casa só. É a §3 do FID01: o protótipo ganha na FÓRMULA, nos RÓTULOS e na ORDEM, **nunca nos
DADOS**.

**8. [DIVERGÊNCIA] A contagem de rotas.** O plano fala de *"cinco rotas"* e o `success_criteria`
repete; o `<behavior>` das duas tarefas descreve **oito** actions (as seis de escrita mais as
**duas de leitura** que os critérios 6 e 14 exigem). ➡️ **São OITO**, e o gate alheio da lista de
rotas do painel foi reapontado de **6 → 14** nomes.

**9. [OBSERVAÇÃO, não conserto — para o 188-12] A recusa é `{message, regra}` em 422**, o formato
que o módulo já usa e que a wave 188-05 fixou nas seis rotas dela. ⚠️ Já
`escreverNoPainel` (`PainelDeRevisao.jsx:266`, **reconfirmado por `grep -n`**) procura o **id da regra DENTRO da mensagem**
(`texto.includes(REGRA_VERSAO_DIVERGENTE)`). ➡️ **Eu NÃO mudei nenhum dos dois lados** — pôr o id
na mensagem seria **jargão na tela**, e mudar o formato da recusa quebraria as seis rotas da
188-05. O aviso de versão divergente é do **188-12**: **a decisão de como ligar os dois é dele**, e
fica registrada aqui para ele não a descobrir por bisseção.

**10. [RESSALVA] `preparando` não é exercitado pela rota de preço.** A fixture nasce
`aguardando_aprovacao` e não há transição de volta para `preparando` pela única porta. A recusa
dele está coberta pelo payload (`rodape.botoes`, wave 188-02), e há asserção de que
`PainelDeRevisaoService::FORA_DA_NAVEGACAO` continua sendo `[preparando]`.

---

## ⚠️ O `--autoteste` DO HARNESS, COLADO

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA >=: 2 verdes com >=2 (igual ao minimo) -> PASSA (como esperado)
  OK   caso 5: ISCA >=: 2 verdes com >=1 (acima do minimo, teste novo so soma) -> PASSA (como esperado)
  OK   caso 6: ISCA >=: 2 verdes com >=5 (encolheu ou nao carregou) -> REPROVA (como esperado)
  OK   caso 7: ISCA >=: falha nao e perdoada pelo >= (o '# fail 0' vem ANTES) -> REPROVA (como esperado)
  OK   caso 8: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 9: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 10: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 11: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 12: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 13: No tests found. -> REPROVA (como esperado)
  OK   caso 14: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 16: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 18: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 19: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 20: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

---

## ⚠️ O QUE NENHUM EXECUTOR DESTA FASE PODE AFIRMAR

⚠️⚠️ **NÃO VI ESTA TELA RENDERIZADA.** O banco local **não tem empresa com `ml_token`** (a rota dá
**404**), `pub_aprovacao_ofertas` está **VAZIA** e a fila nasce vazia até a **Fase 194** (decisão
**P3** do usuário: é honesto, **não** é defeito). Tudo aqui é **fixture** (factory/seed de teste) +
o caminho **Inertia sem resolver o componente** (header `X-Inertia` + asserção sobre as props).
⚠️ **A conferência visual da Fase 187 pelo usuário continua ABERTA** — nada deste plano a
substitui nem a presume feita.

### O trecho do roteiro da conta 459 que é deste plano

1. abrir uma oferta no painel e, na coluna de preço, **digitar** um preço diferente do salvo → o
   selo **`SIMULAÇÃO · NÃO SALVA`** aparece e o resultado/margem/delta **mudam**, com o delta
   trazendo o sufixo ` vs. salvo` (⚠️ o religamento dos handlers é do **188-12**);
2. clicar **"Salvar no anúncio"** → o aviso de alteração **desaparece**, a `Versão N` do topo
   **sobe uma**, e a linha do tempo ganha *"Alterou o preço de … para … na revisão · versão N"*;
3. numa oferta com o frete **Pendente**, abrir **"Informar frete confirmado"**, digitar um valor →
   a **prévia** diz `Resultado … · margem …%` **antes** de confirmar; confirmar → a linha de frete
   vira **Confirmado**, a nota diz **a fonte e a data**, e a versão **sobe uma**;
4. pedir ajuste no item **`Imagem #1 · Capa`** (escopo conjunto) → **as duas** ofertas do rascunho
   saem da fila de conferência; resolver → **só com o último** elas voltam;
5. numa oferta com prejuízo, registrar **exceção autorizada** escolhendo o responsável e escrevendo
   **10+ caracteres** → a linha `§ Exceção autorizada por … · … · …` aparece, e **não existe** nada
   a "aprovar depois".

---

## Self-Check

```
FOUND: routes/mlb_anuncios.php
FOUND: app/Http/Controllers/MlbPublicadorAprovacaoController.php
FOUND: app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
FOUND: app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php
FOUND: tests/Feature/Publicador/Aprovacao/EscritaDePrecoEFreteDoPainelTest.php
FOUND: tests/Feature/Publicador/Aprovacao/EscritaDeAjusteEExcecaoDoPainelTest.php
FOUND: tests/gates/contagens-188-06.txt
FOUND: 1f2dbe44   feat(188-06) tarefa 1
FOUND: 4c3dde27   feat(188-06) tarefa 2
```

## Self-Check: PASSED
