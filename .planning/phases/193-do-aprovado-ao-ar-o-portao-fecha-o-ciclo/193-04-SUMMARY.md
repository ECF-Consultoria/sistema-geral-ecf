---
phase: 193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo
plan: 04
subsystem: publicador-aprovacao
tags: [comando-do-minuto, scheduler, fila-high, retry-republica, nenhuma-rota-nova, mutacao, territorio, devolucao]
requires:
  - "193-01 (a ausencia de migration provada por assercao sobre o schema vivo)"
  - "193-02 (enfileirar() com a guarda da decisao 23, e o gancho aoAprovar() LIGADO)"
  - "193-03 (reconciliar() publico e provado — a fronteira que esta wave fecha)"
  - "Fase 188 (a rota do retry, o throttle, a guarda de ml_token e a caixa de falha do painel)"
provides:
  - "publicador:portao-publicacao — o passo do minuto, com ZERO logica propria"
  - "o agendamento everyMinute + withoutOverlapping(10) + onOneServer, DEPOIS do fila-publicacao"
  - "a resposta 'em que fila isso roda' escrita no codigo, com a medicao de 16/09/2026"
  - "o 'Tentar de novo' que REPUBLICA: enfileirar() ANTES de tentarDeNovo(), em transacao"
  - "REGRA_FILA_RECUSOU_O_RETRY (PUB-02-FILA-RECUSOU): a recusa com o motivo REAL da fila"
affects:
  - "193-05 (o fechamento: numstat de MlPublicacaoService, D-11, checkpoint humano)"
  - "ATENCAO: duas DEVOLUCOES abertas nesta wave (188-05 tentar-de-novo e o item 193-I)"
tech-stack:
  added: []
  patterns:
    - "Command que imprime as CHAVES do resumo sem escreve-las — mantem o arquivo sem nome de estado"
    - "sonda de 'sem logica propria' que NAO proibe os nomes dos estados (seria mais larga que o invariante)"
    - "agendamento ancorado por NOME no Schedule do container, com a prova de que 10/true nao sao default"
    - "ordem relativa por POSICAO DE OCORRENCIA no codigo sem comentario, nunca por numero de linha"
    - "nome de metodo alheio entrando por VARIAVEL para nao virar forma de DECLARACAO no texto"
key-files:
  created:
    - app/Console/Commands/PublicadorPortaoPublicacao.php
    - tests/Feature/Publicador/Aprovacao/ComandoDoPortaoDaPublicacaoTest.php
    - tests/gates/contagens-193-04.txt
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-04-SUMMARY.md
  modified:
    - routes/console.php
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
decisions:
  - "O comando imprime as chaves do resumo lendo-as do retorno, em vez de escreve-las: nome de estado dentro de um Command que nao decide nada seria contradicao, e uma sonda que proibisse os nomes reprovaria o trabalho correto"
  - "A ordem enfileirar() -> tentarDeNovo() em UMA transacao: recusa nao move, e falha do mover() desfaz a ligacao nova"
  - "REGRA_FILA_RECUSOU_O_RETRY e id NOVO porque a recusa e nova: reusar BLOQ-04/AND-02 faria a tela marcar o erro no lugar errado"
  - "Na recusa, a linha do historico que o enfileirar() escreve volta atras com o rollback — o canal de um clique e a RESPOSTA, e gravar fora da transacao deixaria registro de tentativa que nao houve"
  - "NAO consertei o 188-05 tentar-de-novo nem o item 193-I: as duas saidas mexem em decisao de produto de fase EM PRODUCAO. DEVOLVIDOS."
metrics:
  duration: "~5 h"
  completed: "2026-10-21"
  tasks: 2
  commits: 3
---

# Fase 193 Plano 04: o ciclo anda sozinho e o "Tentar de novo" republica Summary

O `reconciliar()` do 193-03 estava público e provado, mas **ninguém o chamava a cada minuto** — era a
fronteira que aquela wave declarou na §21. Agora há `publicador:portao-publicacao`, com **zero lógica
própria**, agendado **todo minuto logo depois** do `publicador:fila-publicacao`, com
`withoutOverlapping(10)` e `onOneServer()`; e o **"Tentar de novo" republica de verdade**, enfileirando
**antes** de mover, com a razão escrita no docblock.

⚠️⚠️ **E ESTA WAVE DEVOLVE DUAS COISAS, NENHUMA DELAS CONSERTADA POR MIM** (§17): o gate alheio
**`188-05 tentar-de-novo` ficou VERMELHO** porque a fixture daquela fase nunca foi publicável, e eu
**medi um BECO** no retry de produto com duas modalidades. As duas saídas mexem em decisão de produto
de fase **em produção** — são do usuário/orquestrador, não minhas.

---

## §1 — A PARTIDA

```
PARTIDA: 8c54a5d8   (capturada na PRIMEIRA ação, antes de qualquer leitura — confere com o esperado)
HEAD no fim do código: dbbbbaf2  (+ o commit desta SUMMARY)
```

| commit | de quem | o que |
|---|---|---|
| **`c47aad5d`** | **MEU** | `feat(193-04)`: o passo do minuto do portão — comando sem lógica própria, agendado depois da fila |
| **`dbbbbaf2`** | **MEU** | `feat(193-04)`: o *"Tentar de novo"* republica — enfileirar ANTES de mover, com a razão no docblock |
| (este) | **MEU** | `docs(193-04)`: a SUMMARY |

⚠️ **A PARTIDA foi usada SÓ como referência de leitura.** O território saiu do **escopo do assunto
`(193-04)` no `%s`** (§13), nunca de range aberto a partir dela.

⚠️ **Commit atômico POR TAREFA, como o plano pede** — ao contrário do 193-03, que juntou as duas.

---

## §2 — O COMANDO DO MINUTO, PROVADO **SEM** SCHEDULE

**`app/Console/Commands/PublicadorPortaoPublicacao.php`** (89 l.), no molde exato do
`PublicadorFilaPublicacao.php` (32 l.): injeta `PublicacaoDoPortaoService`, chama `reconciliar()`
**uma vez**, imprime o resumo só quando há o que contar.

```
test_o_comando_do_minuto_roda_sem_fila_viva_e_nao_faz_nada_193_04

  banco VAZIO:  artisan publicador:portao-publicacao  -> exit 0, saida ''            ✅
                Queue::assertNothingPushed()  ·  Http::recorded() VAZIO               ✅
  ⚠️ ISCA: com UMA oferta esperando, a saida traz "Portão de publicação:" e a oferta
     vai a `publicando`  — sem isto, "não imprimiu" seria verdade por o comando
     NÃO EXISTIR, ou por ele imprimir só quando quer                                  ✅
```

⚠️⚠️ **O `artisan` é chamado de verdade** (`Artisan::call()` + `Artisan::output()`), e o `exit` é lido
do retorno — não de um `tail`.

### ⚠️ Zero lógica própria (T-193-31), lido **SEM COMENTÁRIO**

**Por que aqui importa mais que em qualquer lugar:** o gate `186-04 unica-porta` é
`glob('app/Services/Publicador/Aprovacao/*.php')`, **não recursivo**. Um Command vive em
`app/Console/Commands/` e **não cai no glob** — lógica de andamento ali ficaria **fora do alcance** do
gate que existe para pegar segundo escritor.

```
no Command, lido sem comentario (token_get_all) e contado por substr_count:
  ->mover(  PubAprovacaoOferta  PubFilaPublicacaoItem  PubPublicacaoItem
  'andamento' =>  ->andamento =  DB::  ->where(  Http::  dispatch(  ClienteMlPublicador
                                                           ->  TODOS 0   ✅
  ->reconciliar(  ->  EXATAMENTE 1   ·   PublicacaoDoPortaoService $  ->  1       ✅
  ISCA (+): um texto sintetico com as 11 formas e pego pela sonda                 ✅
  linhas lidas no limpo: 89  (gate sobre quase nada: reprovado)                   ✅
```

### ⚠️⚠️ UM ACHADO DE DESENHO QUE EU TOMEI DE PROPÓSITO, E QUE DIVERGE DO CRITÉRIO DO PLANO

O `<acceptance_criteria>` diz: *"o comando lido sem comentário não contém `mover(`, nem **literal de
andamento**, nem `PubAprovacaoOferta::`"*. ⚠️ **Rodei e a cláusula do meio é uma sonda MAIS LARGA QUE O
INVARIANTE:** o retorno de `reconciliar()` é
`['publicando' => …, 'publicado' => …, 'falha' => …, 'enfileiradas' => …, 'esperando' => …, 'erros' => …]`
— e o modelo (`PublicadorFilaPublicacao`) **escreve as chaves à mão** (`{$r['iniciados']}`…). Escrevê-las
poria **nome de estado** num arquivo que não deve decidir nada sobre estado, e proibi-las numa sonda
**reprovaria o comando por imprimir corretamente o que leu** — a família que já **barrou um deploy**
nesta milestone.

➡️ **Conserto na raiz, nos dois lados:** o comando imprime as chaves **lendo-as do próprio retorno**
(`foreach ($r as $contado => $quantas)`), então não há nome de estado no arquivo; e a sonda **não**
proíbe os nomes dos estados — ela proíbe **transição, modelo de domínio, consulta, rede e fila**, que é
o invariante de verdade. ⚠️ A chave literal `'andamento' =>` **continua proibida** (é a sonda larga do
gate alheio `186-09`, que varre `app/` inteiro) e dá **0**.

### A idempotência, por **RECONSULTA AO BANCO** — nunca por stdout

O comando roda **60 vezes por hora** (T-193-32).

```
test_duas_passadas_do_comando_dao_o_mesmo_estado_sem_evento_novo_193_04

  1ª passada: `aprovado` -> `publicando`    (⚠️ ISCA: ela MOVE de verdade, senão
              "nada mudou na 2ª" seria verdade por nada ter mudado nunca)          ✅
  2ª passada: a linha INTEIRA da oferta, byte a byte, inclusive `updated_at`,
              IDÊNTICA   ·   histórico IDÊNTICO (sem 1.440 linhas iguais por dia)  ✅
              o ITEM DA FILA também idêntico byte a byte (o comando só LÊ a fila)  ✅
  3ª passada (item terminado + CREATED): fecha em `publicado` com MLB912345678     ✅
  4ª passada: a oferta publicada não se move (terminal por estrutura)              ✅
  exit 0 nas QUATRO   ·   Queue nada   ·   Http::recorded() VAZIO                  ✅
```

---

## §3 — O AGENDAMENTO: POR NOME, E O QUE ELE **NÃO** PROVA

`routes/console.php`, **37 inserções / 0 remoções**, **só o bloco novo**, inserido **entre** o bloco do
`publicador:fila-publicacao` e o `publicador:agente-encaminhar` (de outra sessão).

```
test_o_agendamento_esta_no_scheduler_por_nome_com_overlapping_e_um_servidor_193_04

  achado no Schedule do container PELO NOME (nunca por linha), por IGUALDADE DE CONJUNTO:
     expressao            '* * * * *'
     nome                 'publicador-portao-publicacao'
     sem_sobreposicao     true
     minutos_do_mutex     10
     um_servidor_so       true                                                     ✅
  ⚠️ ISCA (+): o controle positivo `publicador:fila-publicacao` (que JÁ EXISTE) é
     achado pela MESMA sonda — senão ela estaria cega                              ✅
  ⚠️ ISCA (−): `publicador:nao-existe-9z7` NÃO é achado                            ✅
  ⚠️ ISCA: o vizinho tem a MESMA forma (é dele que a minha foi copiada)             ✅
  ⚠️⚠️ E A PROVA DE QUE `10` E `true` NÃO SÃO DEFAULT, por reflexão em `Event`:
     expiresAt default 1440 · onOneServer default false · withoutOverlapping false ✅
     (sem isto, as duas asserções seriam VAZIAS: um `everyMinute()` pelado passaria)
```

⚠️⚠️ **O QUE ESTE ID NÃO PROVA, E ESTÁ DECLARADO: o cron do sistema operacional NÃO É EXERCIDO NESTA
MÁQUINA.** O que se mede é (a) o **comando**, por `artisan` com `Http::fake`, e (b) o **registro** no
`Schedule`, lido do container. Rodar o minuto de verdade é prova de **produção**.

### A ordem relativa, por **posição de ocorrência** no código sem comentário

```
test_a_ordem_relativa_no_arquivo_poe_o_portao_depois_da_fila_193_04

  UMA ocorrência de cada `Schedule::command(...)` no código limpo (duas seriam
     agendamento duplicado; zero, bloco ausente)                                   ✅
  posição do portão  >  posição da fila                                            ✅
  ⚠️ ISCA DO LEITOR: no texto CRU o comando vizinho aparece MAIS vezes que no limpo
     (a prosa do meu bloco o cita de propósito) — ler sem comentário tem sujeito    ✅
  ⚠️ os blocos de OUTRA SESSÃO (`publicador:agente-encaminhar`, `publicador:agente-
     varrer`) continuam com 1 ocorrência cada: não reordenei nem editei nada alheio ✅
```

### ⚠️ A conferência de `routes/console.php` **ANTES** de editar, e o que eu achei lá

```
git diff HEAD -- routes/console.php                 ->  (VAZIO)
git diff HEAD --numstat -- routes/console.php       ->  (VAZIO)
git log --oneline -1 -- routes/console.php          ->  e20a7653 feat(quick-261020-ozm): AG-06 do agente …
sha256 antes de editar                              ->  0d5a53d5de1426ad…
```

➡️ **NÃO havia WIP alheio** (a outra sessão já tinha commitado o AG-06). Reconferi **imediatamente
antes** da edição, e o diff depois dela é **37 / 0** — só inserção, nada removido.

---

## §4 — EM QUE FILA ISSO RODA, E POR QUÊ (declarado no código, **não mudado**)

| peça | onde roda | por quê |
|---|---|---|
| `publicador:portao-publicacao` | **scheduler**, `everyMinute()->withoutOverlapping(10)->onOneServer()` | ⚠️ **NÃO É FILA**: passo de minuto, **idempotente**, que só **LÊ** o banco e move andamento pela única porta. **Zero `dispatch()`, zero requisição** — medido (`Queue::assertNothingPushed()` e `Http::recorded()` vazio em todos os casos) |
| a publicação em si | **`PublicarRascunhoJob`, fila `high`** — **já era assim, e NÃO muda** | ⚠️ a razão está no projeto, não é preferência: a fila de produção é **Redis** (a tabela `jobs` dá **0** e **engana**), e em **16/09/2026** **179 jobs de acervo do ML na `default`** seguraram um webhook da Clicksign **por HORAS**. Publicação disparada por quem acabou de aprovar **não espera atrás do sync do acervo** (T-193-36). ⚠️ **Não é `creative`** (fila do outro dev, onde roda geração paga) e **não é `default`** |

**Medido no código do job, SEM COMENTÁRIO:** `onQueue('high')` = **1** ·
`onQueue('default')` + `onQueue('creative')` = **0**.

⚠️ **Esta fase NÃO deploya.** Fica registrado **no comentário do agendamento e no docblock do comando**:
depois do `deploy.sh` (que só reinicia `ecf-worker:*`) é obrigatório
`sudo -u www-data php artisan queue:restart` para pegar `ecf-worker-high` **sem matar job em andamento**
— `supervisorctl restart` mataria uma publicação (ou uma geração paga) no meio.

⚠️ **Nenhum feature-flag novo:** a trava de *"ligar publicar"* é
`config('publicador.contas_liberadas.*')` (**fail-closed**, lista vazia = NINGUÉM), a mesma que o PUB-06
exige. Um segundo interruptor faria **duas verdades**.

---

## §5 — O "TENTAR DE NOVO" QUE REPUBLICA

`app/Http/Controllers/MlbPublicadorAprovacaoController.php`: **60 inserções / 1 remoção**, e a única
remoção é a linha antiga da action. O diff tem **apenas**: o `use` do serviço do portão, a `const` da
regra nova (declarada **imediatamente acima** da action, para o diff ficar local), o acréscimo ao
docblock, o parâmetro novo e o corpo.

```
git diff HEAD~1..HEAD -- …Controller.php | grep -E "^@@|^-[^-]"
  @@ -23,6 +23,7 @@                      <- o `use`
  @@ -1025,6 +1026,17 @@ aprovar(      <- a const + docblock dela
  @@ -1042,6 +1054,35 @@ aprovar(      <- o acrescimo ao docblock da action
  @@ -1050,6 +1091,7 @@ tentarDeNovo( <- o parametro novo
  @@ -1062,7 +1104,24 @@ tentarDeNovo(
  -            $retentada = $aprovacao->tentarDeNovo($daConta, $request->user());   <- a UNICA remocao
```

### A ordem, e a **isca invertida** que a torna mensurável

```
test_o_retry_enfileira_antes_de_mover_e_a_oferta_aponta_para_item_vivo_193_04

  a oferta chega a `falha` PELO CAMINHO DE VERDADE (aprovada -> enfileirada ->
     item da fila `parcial` + publicação FAILED -> o PASSO DO MINUTO fecha)        ✅
  ⚠️ O ESTADO DE PARTIDA, que é o sujeito da isca: `fila_item_id` aponta para um
     item FORA de `VIVOS`                                                          ✅
  depois do clique:  andamento `publicando`
                     `fila_item_id` MUDOU   e   o item novo está DENTRO de `VIVOS`
                     `produto_ativo` do item novo = o produto (a coluna-sombra do
                        unique `pubfilai_produto_ativo_uq`)                        ✅
  `erro_publicacao` NULO  ·  `versao`/`versao_aprovada`/`aprovado_por`/`aprovado_em`
     IDÊNTICOS, lidos CRUS do banco (`DB::table(...)->first([...])`)               ✅
  ⚠️ e o PASSO DO MINUTO SEGUINTE NÃO a derruba — que é o defeito que a ordem evita ✅
  zero escrita no ML  ·  zero job despachado                                       ✅
```

⚠️ **A razão está escrita no docblock da action**, com todas as letras: movendo primeiro, a oferta
ficaria `publicando` com a ligação no item **terminado**, e o reconciliador **não teria como
distinguir** *"acabou de falhar, feche"* de *"acabou de ser retentada, espere"*. ➡️ **Enfileirar antes
elimina o estado ambíguo por construção**, e é por isso que o 193-03 pôde tratar *"`publicando` com
`fila_item_id` nulo"* como **impossível** (`Log::warning` e nada mais). ⚠️ **Nenhum ramo de produto foi
inventado para o estado impossível.**

### A guarda da action, **preservada**, com a isca que mostra por quê

```
test_a_guarda_de_so_a_partir_de_falha_continua_valendo_com_isca_193_04

  ⚠️ ISCA DA LISTA FECHADA: `TRANSICOES[aprovado]` INCLUI `publicando` — o serviço
     NÃO recusaria. A trava é da ACTION, não dele                                  ✅
  por IGUALDADE DE CONJUNTO (piso `>= N` não prova presença):
     aguardando_aprovacao -> 422 AND-02-TRANSICAO-ILEGAL
     aprovado             -> 422 AND-02-TRANSICAO-ILEGAL
     publicando           -> 422 AND-02-TRANSICAO-ILEGAL                           ✅
  cada recusa é NO-OP COMPLETO: a linha inteira, byte a byte                       ✅
  ⚠️ ISCA (+): a MESMA rota, a partir de `falha`, ACEITA (302)                      ✅
  conta alheia -> 404, NUNCA 403 (403 confirmaria que o id existe)                 ✅
```

⚠️ **O `302` como isca é lição herdada:** `->json('regra')` numa resposta de **redirecionamento** falha
como asserção (*"a resposta não é JSON válido"*) — o 193-03 pagou esse furo. A aceitação é **status**.

### O botão **não finge que tentou** (T-193-35)

```
test_retry_com_enfileiramento_recusado_nao_move_e_traz_o_motivo_real_193_04

  conta FORA de `contas_liberadas` (fail-closed: lista vazia = NINGUÉM):
     422  ·  regra = PUB-02-FILA-RECUSOU
     message contém "fila de publicação" E "liberada" (o MOTIVO REAL, não um
        "algo deu errado" — que é o que PUB-05 proíbe)                             ✅
     a linha INTEIRA da oferta, byte a byte, INTACTA                               ✅
     ZERO item de fila VIVO nasceu                                                 ✅
     ⚠️ o HISTÓRICO também volta atrás (o rollback leva a linha do `enfileirar()`) ✅
  ⚠️ ISCA (+): liberada a conta, o MESMO clique passa (302 + `publicando`) — sem
     ela, "recusou" era indistinguível de "recusa sempre"                          ✅
```

⚠️⚠️ **A CONSEQUÊNCIA DO ROLLBACK É MEDIDA E DECLARADA, não suposta:** como enfileirar e mover estão na
**mesma transação** (o plano manda), a linha de histórico da recusa **volta atrás**. O canal que importa
num clique é a **resposta**, e ela leva o motivo real; gravar fora da transação deixaria registro de
uma tentativa que **não aconteceu**. ⚠️ Se o usuário quiser a recusa do retry **no histórico**, é
decisão de produto — e sai do escopo desta wave.

### O `regra` novo, e por que não reusei um existente

`MlbPublicadorAprovacaoController::REGRA_FILA_RECUSOU_O_RETRY = 'PUB-02-FILA-RECUSOU'`.

⚠️ **Nenhum dos 21 ids do módulo diz *"a fila não aceitou"***: `BLOQ-04-APROVAR-FORA-DO-AO-VIVO` é
*"não está aberta para revisão"*, `AND-02-TRANSICAO-ILEGAL` é *"este par de estados não existe"* e
`DADO-14-FILA-SEM-CONTA` é *"consulta sem recorte de conta"*. Reusar qualquer um faria a tela **marcar o
erro no lugar errado**. ➡️ O id novo segue o padrão `<REQ>-<SLUG>` dos outros e sai pelo
**`recusaDoPainel()` de sempre** (`response()->json(['message','regra'], 422)`, **18 chamadas medidas**).
⚠️ **NÃO refatorei para `back()->withErrors()`** — é dívida com dono em `deferred-items.md` da 188.
⚠️ **Não é rótulo, não é estado, não é toast:** os 6 toasts de VOC-08 seguem fechados e o rótulo
*"Aprovar para publicação"* não foi tocado.

---

## §6 — NENHUMA ROTA NOVA, E A FRONTEIRA D-11 / D-22

```
test_o_conjunto_de_rotas_de_aprovacao_e_identico_ao_da_fase_188_193_04

  IGUALDADE DE CONJUNTO das 16 rotas `mlb.anuncios.meus.aprovacao*`, resolvidas
     PELO ROUTER (nunca por grep no arquivo de rotas)                              ✅
  nenhuma rota batizada com o número desta fase                                    ✅
  `routes/mlb_anuncios.php` (sem comentário): `'tentarDeNovo'` = 1 ocorrência e o
     throttle `publicador.aprovacao.painel.retry` = 1 — a rota é da 188, intacta   ✅
  `numstat` de `routes/mlb_anuncios.php` no meu range: VAZIO                       ✅
```

### A fronteira, declarada

| lado | quem | o que escreve |
|---|---|---|
| **o portão / a fila** (meu) | `PublicacaoDoPortaoService` → `FilaPublicacaoService` → `AgendadorDaFila` → `PublicacaoService` → `PublicarRascunhoJob` (fila **`high`**) | ⚠️ **nesta wave, SÓ BANCO**: ⚠️⚠️ **MEDIDO — a action do retry faz ZERO requisição**, nem a leitura autorizada da tarifa. O único `POST /items` do sistema é `PublicacaoService::executarFatia()` (`:442`), **reusado INTOCADO** (`numstat` vazio) |
| **a lista de Publicadas** (D-11/D-22) | `tests/js/estrutura-meus-anuncios.test.js` | **nada** — `numstat` **VAZIO** por range, **30 / 30 / 0** em isolamento, e o gate **NÃO foi estendido** à área nova (asseverado: o arquivo não cita `publicador:portao-publicacao`, `portao-publicacao` nem `tentarDeNovo`) |

⚠️⚠️ **A SONDA FICOU EM "ZERO ESCRITA COM LISTA CONSTANTE DE LEITURAS AUTORIZADAS", DE PROPÓSITO,
MESMO TENDO MEDIDO ZERO REQUISIÇÃO.** `agendar()` passa pela prontidão, que em outro cenário **pode**
ler a tarifa real (decisão 9 do usuário); prometer *"zero requisição"* seria gate que o próprio desenho
pode contradizer, e o executor seguinte o "consertaria" pré-aquecendo cache. **A medição está
registrada no comentário; congelá-la numa asserção seria a armadilha que o comentário descreve.**

⚠️ `tests/js/publicador-header-zindex-ordem.test.js`: **7 / 7 / 0** em isolamento, **INTOCÁVEL**.

### O gate de ausência dos 4 literais, pela **UNIÃO** das duas formas

Varre a **lista CONSTANTE** dos 2 arquivos desta wave, no código **sem comentário**, com
`assertFileExists` em cada um antes de medir:

```
9 formas iscadas (+), todas casadas  ·  3 legítimas iscadas (−), nenhuma acusada   ✅
infratores no código limpo: []                                                     ✅
⚠️ ISCA DO LEITOR: no texto CRU a sonda ACHA (a prosa do Command cita as formas de
   propósito) — sem esta ponta, "zero infratores" seria indistinguível de
   "a sonda não lê nada"                                                           ✅
```

⚠️ **A união é obrigatória, e isso foi MEDIDO pelas waves 02 e 03:** a `sondaPub09` do **186-09** não
casa a forma pt-BR acentuada com espaço (*"Confirmar publicação"*) e a do **188-05** faz o **inverso**
(não casa `undo` nem `publicar_aprovado`). **Nenhuma das duas, isolada, cobre as nove formas.**
⚠️ **Borda à ESQUERDA só** (`undo` é substring de *segundo/fundo/profundo*; borda nos dois lados cegaria
para `desfazerAprovacao`). ⚠️ **O gate da 186 NÃO foi alterado** — ele está em produção, e o achado
segue **devolvido** (dono: usuário/orquestrador).

---

## §7 — PUB-06 POR NÃO-REGRESSÃO, POR CONTAGEM DE OCORRÊNCIAS SEM COMENTÁRIO

⚠️ **Nada da disciplina de escrita no ML foi reimplementado.** Lista **CONSTANTE** de 5 arquivos, com
`is_file()` em cada um antes de medir, lidos por `token_get_all` e contados por `substr_count` —
**nunca `grep -c`** (conta **LINHAS**) e **nunca `preg_match(...) === 1`** (passa com a linha de código
**apagada** quando o texto também vive em comentário).

```
  ContasLiberadas::exigir( em PublicacaoService (trava antes de gravar)    2
  function exigir( no Support (fail-closed)                               1
  PubPublicacaoItem::SENT  (registro ANTES do POST)                       7
  PubPublicacaoItem::FAILED  (4xx)                                        4
  PubPublicacaoItem::UNKNOWN (5xx/rede)                                  10
  reconciliar_apos_segundos  (RN-93)                                      1
  trava `publicador:publicacao:`  (sem reenvio por conta propria)         1
  function retryUntil( no job                                             1
  onQueue('high') no job                                                  1
  onQueue('default') + onQueue('creative') no job                         0
  '/items' em PublicacaoService                                           3
```

**A prova de que o leitor LÊ:** no texto **CRU** de `PublicarRascunhoJob` a palavra `SENT` aparece
**1** vez e no **limpo 0** — ou seja, ela só existe em **docblock** naquele arquivo. ⚠️ Sem essa ponta,
contagens poderiam estar lendo comentário.

### ⚠️ UM FURO NA MINHA SONDA DE MEDIÇÃO, ACHADO POR RODAR

A minha primeira medição procurou a trava `publicador:publicacao:` **em `PublicacaoService`** e deu
**0**. ⚠️ **MEDIDO: ela vive no JOB** (`app/Jobs/Publicador/PublicarRascunhoJob.php:57`,
`Cache::lock("publicador:publicacao:{$p->id}", …)`). ➡️ **Um NOME não é um CAMINHO** — a lição da
milestone, agora na minha própria sonda. Corrigido e remedido (**1**).

---

## §8 — OS IDS DA `186-04`: **IDÊNTICOS (43 / 67)**, E SUBIR SERIA **PARADA**

```
186-04 unica-porta        EXIT_REAL=0   Tests: 1 passed (43 assertions)   [IDENTICO]
186-04 oferta-do-portao   EXIT_REAL=0   Tests: 3 passed (67 assertions)   [IDENTICO]
```

⚠️⚠️ **A TRAVA DESTA WAVE É A INVERSA DA DO `193-02`, e a razão é medida:** a camada B daqueles ids é
`glob('app/Services/Publicador/Aprovacao/*.php')`, **não recursivo**, com **2 asserções POR ARQUIVO**.
Esta wave **não cria arquivo** ali — o Command vive em `app/Console/Commands/` e o controller em
`app/Http/Controllers/`, **nenhum dos dois cai no glob**. ➡️ **Se eles tivessem SUBIDO, era PARADA**:
significaria que nasceu arquivo na raiz do portão sem eu declarar.

---

## §9 — OS GATES DESTE PLANO, COM O `exit` REAL POR `$?` **NA LINHA SEGUINTE**

⚠️ **Nunca depois de um pipe** — e esta wave pagou a lição **duas vezes**: a primeira rodada do RED saiu
`EXIT_REAL=0` porque o último comando do composto era um `tail`; e **a notificação do processo em
background reportou "exit code 0" quando o `$?` da suíte PHP, capturado na linha seguinte, era `1`.**
**Cinco waves já caíram nisso.**

```
comando-sem-logica        EXIT_REAL=0   Tests: 3 passed (53 assertions)
agendamento-do-minuto     EXIT_REAL=0   Tests: 2 passed (19 assertions)
retry-republica           EXIT_REAL=0   Tests: 3 passed (48 assertions)
nenhuma-rota-nova         EXIT_REAL=0   Tests: 2 passed (26 assertions)
bijecao-manifesto-193-04  EXIT_REAL=0   Tests: 1 passed (31 assertions)
```

### A isca do 1º argumento, medida **AO VIVO**

```
--- com '193-04' (CERTO) ---
exit=0
--- com '04' nu (ERRADO) ---
EXIT_REAL=2
ABORTOU: id 'retry-republica' nao esta em …/tests/gates/contagens-186-04.txt (manifesto incompleto)
SIM: tests/gates/contagens-186-04.txt existe (o risco e real, nao teorico)
```

⚠️ **No meu caso ele abortou ALTO** porque nenhum id meu existe lá. ⚠️⚠️ **Mas `04` nu é o pior caso
possível desta fase:** `contagens-186-04.txt` é justamente o manifesto do `unica-porta`/
`oferta-do-portao` — um id meu homônimo de um deles rodaria o gate da fase **ERRADA, em silêncio**.

### A bijeção, nos TRÊS sentidos, como teste **durável**

`SEM_ID=0` · `ORFAOS=0` · `IDS_REPETIDOS=0` · `HOMONIMOS=0` (varrendo toda a suíte), sufixo `_193_04`
em **todos os 11** métodos por asserção **coletora**, **nenhum id com mais de TRÊS métodos**, `tests=`
conferido contra o número de métodos de cada filtro, e a **terceira bijeção** id ↔ `<verify>`.
⚠️ O **expansor de alternância** é iscado nas **duas** direções **antes de valer**, com a prova medida de
que a regex ingênua casa **ZERO** em `test_(a|b|c)` e **1** em `test_abc`.

### `--autoteste`: 20 CASOS, 0 DIVERGÊNCIA (colado integralmente)

`EXIT_REAL_DO_HARNESS=0`

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

## §10 — OS GATES ALHEIOS: SETE IDÊNTICOS, **UM VERMELHO** (devolvido)

```
186-04 unica-porta               EXIT_REAL=0   Tests:  1 passed (43 assertions)   [IDENTICO]
186-04 oferta-do-portao          EXIT_REAL=0   Tests:  3 passed (67 assertions)   [IDENTICO]
186-09 transversais              EXIT_REAL=0   Tests: 12 passed (259 assertions)  [IDENTICO]
186-09 andamento-um-lugar-so     EXIT_REAL=0   Tests:  1 passed (21 assertions)   [IDENTICO]
186-09 sem-segundo-passo         EXIT_REAL=0   Tests:  1 passed (53 assertions)   [IDENTICO]
186-09 bijecao-e-homonimo        EXIT_REAL=0   Tests:  1 passed (31 assertions)   [IDENTICO]
188-05 aprovar-e-enfileirar      EXIT_REAL=0   Tests:  3 passed (85 assertions)   [IDENTICO]
188-05 ausencia-de-segundo-passo EXIT_REAL=0   Tests:  1 passed (17 assertions)   [IDENTICO]
188-05 tentar-de-novo            EXIT_REAL=1   Tests:  2 failed (2 assertions)    ⚠️⚠️ VERMELHO — §17
```

⚠️ **Os `186-09` ficaram em `259 / 21 / 53` — os mesmos números do `193-03`**, e o invariante é
*"a contagem não mudou **por mim**"*, não *"a contagem é 258"* (a régua §5 foi corrigida pelo
orquestrador exatamente por isso: o `+1` de `20 → 21` é do commit **alheio** `56ade5f0`, com a razão
escrita em linha no manifesto). **Nada remedido por mim ali.**

---

## §11 — ⚠️⚠️ OS FUROS QUE EU ACHEI NAS MINHAS PRÓPRIAS SONDAS

**Todos achados por RODAR, não por ler. Nenhum consertado por elevação de régua.**

### FURO 1 — ⚠️⚠️ a minha isca escrevia um nome alheio na **forma de DECLARAÇÃO** (violação REAL de gate alheio)

A ISCA (−) do homônimo trazia o literal `'public function test_tentar_de_novo_nao_publica_no_mercado_livre_com_isca_188_05() {}'`.
⚠️ A sonda do gate alheio **`186-09 bijecao-e-homonimo`** é
`/public function (test_[A-Za-z0-9_]+)\s*\(/` sobre o **código sem comentário** — ela leu aquele literal
como *"este arquivo DECLARA um método que já existe em `SalvarEAprovarDoPainelTest`"* e **REPROVOU**
(`1 failed, 11 passed`, 234 asserções).

➡️ Era **falso positivo do invariante dele** (eu não declaro o método; é **dado de teste**), e o
conserto é **na raiz**: o nome entra por **VARIÁVEL**, a isca continua usando o nome **REAL** de outra
fase, e **o gate da 186 não foi tocado**. ⚠️ **As duas saídas erradas** seriam entrar em alguma lista de
exceção daquele gate (fase em produção) ou **trocar o nome por um inexistente** (o que o 193-03 fez sem
notar — a isca dele usa `test_aprovar_e_enfileirar_188_05`, que **não existe** como método; a isca dele
passa, mas é mais fraca). Remedido: `transversais` volta a **259**.

### FURO 2 — ⚠️ o critério do plano pedia uma sonda **mais larga que o invariante**

*"nem literal de andamento"* no Command reprovaria o comando por **imprimir corretamente** as chaves do
resumo. Ver §2: conserto nos dois lados (o comando não escreve as chaves; a sonda não as proíbe).

### FURO 3 — o `publicacao_id` ausente na fixture media o **ramo errado**

A 3ª passada do caso de idempotência ia a **`falha`** em vez de `publicado`: sem
`$item->update(['publicacao_id' => $p->id])`, o `desfecho()` lê publicação **nula** e conclui
*"nada foi criado"*. ⚠️ **Eu teria "consertado" trocando a expectativa** — a saída certa é a fixture.

### FURO 4 — asserção **VAZIA POR CONSTRUÇÃO** que eu escrevi e apaguei

Eu havia escrito, no caso de idempotência:
`assertSame(DB::table('…')->first(), DB::table('…')->first())` — **duas leituras do mesmo instante**.
⚠️ **Verdade por construção, para sempre.** ➡️ Trocada pela comparação **antes × depois** com a linha
capturada depois da 1ª passada.

### FURO 5 — e **quase** repeti o furo da "sonda mais larga" no fim da wave

Depois de medir que o retry faz **zero requisição**, eu acrescentei
`assertSame(0, Http::recorded()->count())`. ⚠️ **Isso congelaria uma medição que o próprio desenho pode
contradizer** (`prontidaoDoLote` pode ler a tarifa real), e o executor seguinte a "consertaria"
pré-aquecendo cache — no mesmo caso em que o meu próprio comentário diz isso. ➡️ **Removida**: a medição
fica no comentário, a asserção fica em *"zero ESCRITA com lista constante de leituras autorizadas"*.

### FURO 6 — a minha sonda de PUB-06 procurou a trava no arquivo errado

Ver §7: *"um NOME não é um CAMINHO"*.

### FURO 7 — o literal do erro na fixture do retry era **igualdade** onde devia ser **começo**

`assertSame('O Mercado Livre recusou…')` reprovou: o 193-03 **concatena a cláusula**
*"Nenhuma oferta foi criada; a revisão foi preservada."* ➡️ `assertStringStartsWith` — exigir igualdade
ali mediria a cláusula **daquele** plano, não o retry **deste**.

---

## §12 — A CAMPANHA DE MUTAÇÃO: **9 MUTAÇÕES, ZERO SOBREVIVENTES**

**Forma cumprida ponto por ponto:** commit **ANTES** (`c47aad5d` + `dbbbbaf2`); `cp -p` dos 3 arquivos
para **fora** do repo; scripts com o plano no nome (**`mutar-193-04.sh`**, `sha256 9a210b5f5cf5dc5b`, e
o trocador **`mutar-193-04.php`**, `sha256 90d6e79ea76ed0a1`, **os dois impressos antes de qualquer
veredicto e reconferidos depois**); ocorrência **travada em 1 ANTES** por string **exata** (o trocador
**ABORTA** com 0 ou 2+); **qual linha mudou** impressa depois; aplicação provada por **divergência de
`sha256`**; restauração **DA CÓPIA** em `trap EXIT`; `sha256` final contra `git show HEAD:` com
`tr -d '\r'` nos dois lados. ⚠️⚠️ **`git checkout` NUNCA.**

| # | mutação | arquivo · linha | veredicto |
|---|---|---|---|
| **m1** | (i) o retry **MOVE sem enfileirar** (o enfileiramento vira no-op que se diz bem-sucedido) | controller `:1109` | **MORREU** `2 failed, 1 passed` (**3** = pristino) |
| **m2** | (ii) a guarda *"só a partir de `falha`"* **removida** | controller `:1098` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m3** | (iii) `everyMinute()` → `hourly()` no bloco do portão | `routes/console.php:640` | **MORREU** `1 failed, 1 passed` (**2**) |
| **m4** | (iv) `withoutOverlapping(10)` **removido** | `routes/console.php:641` | **MORREU** `1 failed, 1 passed` (**2**) |
| **m5** | (v) `onOneServer()` **removido** | `routes/console.php:641` | **MORREU** `1 failed, 1 passed` (**2**) |
| **m6** | (vi) o **Command chamando `mover()` direto** em vez de `reconciliar()` | Command `:76` | **MORREU** `2 failed, 1 passed` (**3**) |
| **m7** | (vii) o agendamento do portão movido para **ANTES** do `fila-publicacao` (3 trocas, com sentinela) | `routes/console.php:601`/`:638` | **MORREU** `2 failed` (**2**) |
| **m8** | (viii) o comando imprimindo **SEMPRE** (guarda `array_sum($r) > 0` removida) | Command `:78` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m9** | (ix) a recusa do enfileiramento **NÃO lança** — o botão finge que tentou | controller `:1111` | **MORREU** `1 failed, 2 passed` (**3**) |

➡️ ⚠️⚠️ **Em TODAS as 9 o `# tests` do mutado BATE com o do pristino**, logo o arquivo **CARREGOU** em
todas e cada morte é **da asserção**, não de falha de **boot** — é exatamente a lição que a `m5` do
`193-02` deixou (*"mutação que morre pelo motivo errado não prova nada"*).

⚠️ **A m7 era a candidata declarada a sobreviver** (*"se sobreviver, INVESTIGUE: pode ser equivalência
real pela idempotência"*). **Ela MORREU**, e a razão é honesta: ela mata **duas** asserções ao mesmo
tempo — a da **ordem** (posição de ocorrência) e a do **nome** do evento, porque a troca dos literais de
assinatura deixa o bloco do portão carregando `name('publicador-fila-publicacao')`. ➡️ **O gate de
ordem, isolado, mede ordem**; mas é justo registrar que a morte da m7 **não isola só a ordem**.

```
─── RESTAURACAO (DA COPIA, nunca por git checkout) ───
  OK   app/Http/Controllers/MlbPublicadorAprovacaoController.php  (a8fecbdf245f560e == git show HEAD:)
  OK   app/Console/Commands/PublicadorPortaoPublicacao.php        (19894200da30820c == git show HEAD:)
  OK   routes/console.php                                         (0a0e6ed77454e5cb == git show HEAD:)
árvore depois: `git diff HEAD --name-only` VAZIO
```

---

## §13 — O TERRITÓRIO: `PI(193-04)` POR ESCOPO DO ASSUNTO

`sha256` do script: `4cbfebe9aa79fe31`.

```
PARTIDA=8c54a5d8   HEAD=dbbbbaf2   (a PARTIDA e SO referencia de leitura)

PASSADA A (git log --grep, le a MENSAGEM INTEIRA): c47aad5d dbbbbaf2
PASSADA B (pelo ASSUNTO %s apenas):                c47aad5d dbbbbaf2
TOTAL_A=2   TOTAL_B=2

FACE 1 — SOBRE-CAPTURA (A menos B): NENHUMA (os dois conjuntos sao IGUAIS)
FACE 2 — SUB-CAPTURA (TODOS os commits do range, medidos A MAO por diferenca):
   MEU     dbbbbaf2  MB.ECF-100376  feat(193-04): o "Tentar de novo" republica …
   MEU     c47aad5d  MB.ECF-100376  feat(193-04): o passo do minuto do portão …
   (NENHUM vizinho alheio)
FACE 3 — O AUTOR: MB.ECF-100376 x3718 · ECF Dev x2008 · Dev ECF x57 · root x5 · ecfmaycon x4
FANTASMAS=0   VIOLACOES=0   (test -e + numstat em 35 alvos e 10 diretorios)
ISCA (+) contagens-193-04.txt: 178  0        <- o range NAO esta cego
ISCA (-) app/NaoExiste9z7.php: (vazio)       <- e por isso a isca (+) e obrigatoria
rastreados modificados na arvore (git diff HEAD, NUNCA git status): (vazio)
27 stashes: APENAS CONTADOS
```

**Os únicos 5 arquivos que os meus commits tocam** — e os cinco são **exatamente** o `files_modified`
do plano:

```
app/Console/Commands/PublicadorPortaoPublicacao.php
app/Http/Controllers/MlbPublicadorAprovacaoController.php
routes/console.php
tests/Feature/Publicador/Aprovacao/ComandoDoPortaoDaPublicacaoTest.php
tests/gates/contagens-193-04.txt
```

### AS TRÊS FACES

**FACE 1 — sobre-captura.** `git log --grep` lê a **mensagem inteira**: um commit de outra wave que
**cite** `(193-04)` no corpo entraria sem ter tocado nada meu. **Não se concretizou** (A = B = 2), mas
foi **medido**, não suposto.

**FACE 2 — sub-captura. ⚠️ NÃO se concretizou, e isso é MEDIÇÃO, não sorte:** a varredura por
**diferença** (todos os commits de `8c54a5d8..HEAD` menos os meus) devolveu **vazio** — **nenhuma outra
sessão commitou durante esta wave**. Nas waves 01 e 02 ela se concretizou (1 e 3 vizinhos); na 03, não.
➡️ **É por isso que a baseline longa pôde ser medida com o HEAD parado nas duas pontas.**

**FACE 3 — o autor.** **CINCO** identidades, e o `%an` dos meus commits é o **mesmo** das outras
sessões. ➡️ O autor **não discrimina**, e é por isso que o território saiu do **assunto**.

### Os vizinhos intocáveis, medidos por RANGE (nunca pela árvore)

| alvo | prova |
|---|---|
| `app/Services/Mlb/Publicacao/MlPublicacaoService.php` (**PUB-03**) | `test -e` **OK**, `numstat` **VAZIO** |
| `app/Services/Publicador/PublicacaoService.php` (⚠️ o `193-05` exige vazio) | `numstat` **VAZIO**; o `POST /items` está em `:442`, intocado |
| `PublicarRascunhoJob` · `app/Services/Publicador/Fila/` · `app/Support/Publicador/` | `numstat` **VAZIO** |
| `AndamentoDaOfertaService` · `AprovacaoDaOfertaService` · **`PublicacaoDoPortaoService`** · `BloqueiosDaOfertaService` · `VocabularioDoPortao` · `HistoricoDaOfertaService` | `numstat` **VAZIO** — ⚠️ **esta wave CHAMA o portão e a porta, e não alterou nenhum dos dois** |
| `app/Services/Publicador/Agente/` (⚠️ outra sessão) · `app/Services/Creative/` · `app/Mcp/` · `app/Services/Portal/` · `Alavancas/` | `numstat` **VAZIO** |
| **todo `resources/js/`** · `routes/mlb_anuncios.php` · `database/migrations/` | `numstat` **VAZIO** (⚠️ **nenhuma migration, e `artisan migrate` NÃO foi rodado**) |
| `tests/js/estrutura-meus-anuncios.test.js` (**D-11/D-22**) · `publicador-header-zindex-ordem.test.js` | `numstat` **VAZIO** |
| `contagens-186-04.txt` · `contagens-186-09.txt` · `contagens-188-05.txt` | `numstat` **VAZIO** |
| `SalvarEAprovarDoPainelTest.php` (⚠️ o do gate vermelho) · `FechamentoDaFase186Test.php` | `numstat` **VAZIO** — ⚠️ **eu NÃO os toquei** |
| `.planning/{ROADMAP,REQUIREMENTS-v27,STATE}.md` (**GATE-07**) · `193-REGUA.md` · `193-COBERTURA.md` · `193-BASELINES.md` · os `193-NN-PLAN.md` | `numstat` **VAZIO** |
| `package.json` · `package-lock.json` · `composer.json` · `composer.lock` | `numstat` **VAZIO** (**T-193-SC**: nenhum install) |

⚠️ `state.advance-plan` **não** foi rodado. `pint` **não** foi rodado. Nenhum worktree. Nenhum
`git stash`/`reset`/`checkout`/`clean`/`--amend`. ⚠️ **Os untracked alheios**
(`tests/Feature/CompanyPortfolioAccessTest.php`, `tests/js/.pub-lote187-inertia-stub-9672.mjs`,
`PLANO_AGENTE_ECF_MVP_CLAUDE_CODE.md`) **não são meus e não foram tocados**.

⚠️ **Dois arquivos TEMPORÁRIOS de medição** (`tests/Feature/Tmp19304SondaDoRetryTest.php` e
`tests/Feature/Tmp19304RedeDoRetryTest.php`) foram criados para medir §17 e **APAGADOS** antes de
qualquer commit — `test -e` confirma a remoção, e nenhum dos dois aparece em commit nenhum.

---

## §14 — AS BASELINES, RE-MEDIDAS, COM O HEAD CONFERIDO NAS DUAS PONTAS

```
PARTIDA:   8c54a5d8
COMMIT:    dbbbbaf2   (o de docs vem depois)
BUILD:     exit REAL 0 · 86 s · "built in 1m 20s"      HEAD dbbbbaf2 nas DUAS pontas
MANIFEST:  500 entradas / 204 paginas   (controle Aprovacao.jsx PRESENTE, isca NaoExiste9z7.jsx AUSENTE)
JS:        6031 / 6030 pass / 1 fail / 0 skipped       (exit REAL 0)   HEAD dbbbbaf2 nas DUAS pontas
PHP:       2686 / 24831 / 2                            (exit REAL 1, 814 s)  HEAD dbbbbaf2 nas DUAS pontas
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

### ⚠️ O isolamento, **PROVADO POR LINHA DE COMANDO** (não por nome de processo)

Contar `node.exe` **por nome** daria **16** e pareceria contaminado. ➡️ Inspecionei a **linha de
comando** de cada um (`Get-CimInstance Win32_Process`): Adobe Creative Cloud, dois runtimes do Codex,
servidores MCP (`digisac-ecf.mjs` ×2, `mcp-remote` subindo por `npx-cli.js` ×4) e workers do Codex.
**ZERO** era `node --test`, `vite` ou `npm run`. E `php.exe` estava em **0** imediatamente antes da
rodada de PHP. ➡️ **DECLARADO: não houve build nem outra suíte concorrente em nenhuma das medições.**
⚠️ `0 skipped` no JS porque o `build` rodou **antes** e **sozinho**.

### ⚠️⚠️ A SUÍTE PHP **COMPLETA** NÃO É MEDÍVEL NESTA MÁQUINA

`artisan test` sem filtro **aborta com Fatal de memória** em
`vendor/.../Illuminate/Http/Testing/FileFactory.php:87`, a ~6% de 10.653 testes, e
`-d memory_limit=-1` **não resolve** (o estouro é em processo **FILHO**). ➡️ O número `PHP:` acima é o
**RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`, o `--suite php`), e **eu digo isso**
em vez de deixar o leitor supor que a suíte inteira passou.

### ⚠️ O manifest: o invariante é *"a 193 não mexe no manifest"*

```
git diff --numstat 8c54a5d8..HEAD -- resources/js/                                     ->  (VAZIO)
git diff --numstat 8c54a5d8..HEAD -- package.json package-lock.json vite.config.js …   ->  (VAZIO)
```

➡️ **500 / 204 é o mesmo que o `193-02` e o `193-03` mediram**, e **não subi número em lugar nenhum**;
**não editei a `193-BASELINES.md`** (artefato do `193-01`; dono: usuário/orquestrador). ⚠️ **E o
controle que IMPORTA rodou:** `Aprovacao.jsx` **PRESENTE** e a isca `NaoExiste9z7.jsx` **AUSENTE** —
*"página fora do manifest SOME"* é o invariante real, e ele **não depende** da contagem total.

### A aritmética do Δ — cada parcela atribuída pelo commit que **CRIOU** o arquivo

⚠️ **Por `git log --diff-filter=A` do arquivo, NUNCA por range** (o range engloba os meus próprios
commits, e a 189-07 caiu exatamente nisso).

| linha | baseline do `193-03` | medido agora | Δ | atribuição |
|---|---|---|---|---|
| **BUILD** | exit 0 · 94 s | **exit 0 · 86 s** | −8 s | ⚠️ **INFORMATIVO** — tempo de build nesta máquina não é invariante (cache do Vite, carga). O que importa é o `exit 0` |
| **MANIFEST** | 500 / 204 | **500 / 204** | 0 | ✅ **IDÊNTICO**, e provado por `numstat` vazio |
| **JS testes** | 6031 | **6031** | 0 | ✅ **IDÊNTICO.** Os meus commits tocam **ZERO** teste JS |
| **JS falhas** | 1 | **1** | 0 | **pré-existente, Fase 87**: *"Características secundárias nasce recolhido (é o grupo que mais infla)"* — identificada por **semântica**, nunca por linha. **Isolada: 19 / 18 pass / 1 fail / 0 skipped.** ⚠️ **NÃO corrigida** |
| **PHP testes** (o RECORTE) | 2675 | **2686** | **+11** | **MEU, e a aritmética fecha na vírgula:** os **11** métodos de `ComandoDoPortaoDaPublicacaoTest.php`, **criado por `c47aad5d`** (`git log --diff-filter=A` → só o meu commit). ⚠️ **Nenhum teste alheio entrou** — a `FACE 2` do território veio **vazia** |
| **PHP falhas** | 0 | **2** | **+2** | ⚠️⚠️ **MINHAS, por consequência de comportamento — e atribuídas por EXECUÇÃO ISOLADA:** rodando `SalvarEAprovarDoPainelTest.php` **SOZINHO** dá `2 failed, 7 passed (156 assertions)`, e as duas são exatamente `tentar de novo so a partir de falha…` e `tentar de novo nao publica…`. ⚠️ **Reprodutíveis em isolamento, logo NÃO são corrida de `storage/framework/testing`.** Ver §17 |
| **PHP asserções** | 24.684 | **24.831** | +147 | ⚠️ **INFORMATIVO**, não comparável: o harness tem `REF_PHP_TESTS` e **não** tem referência de asserções |
| **PROTÓTIPO** | 1432 l. / `68624883ce35547e` | **idem** | 0 | ✅ |

⚠️⚠️ **NENHUM `REF_PHP_FALHAS` FOI CRIADO.** Um limiar de falhas aceito seria a porta dos fundos que o
harness existe para fechar — e as 2 falhas são **devolvidas**, não toleradas.

⚠️ As falhas que a `193-BASELINES.md` §4 prevê (`CalcularFaixaTest`, `CompanyServiceTypeTest`,
`Phase39`) ficam **FORA** do recorte; as de `CockpitDaContaTest` (que **oscilam com a hora do dia**) e as
de `SincronizaPortalCompletoTest` **passaram**.

---

## §15 — ⚠️ CRITÉRIOS DE ACEITAÇÃO DO PLANO QUE EU RODEI E ACHEI VAZIOS, FRACOS OU FALSOS

| item do plano | veredicto |
|---|---|
| *"o comando lido sem comentário não contém `mover(`, nem **literal de andamento**, nem `PubAprovacaoOferta::`"* | ⚠️⚠️ **A CLÁUSULA DO MEIO É SONDA MAIS LARGA QUE O INVARIANTE** e reprovaria o trabalho correto (as chaves do resumo **são** nomes de estado). ➡️ Conserto nos dois lados, §2 |
| *"duas passadas seguidas → mesmo estado por reconsulta ao banco"* | ✅ cumprido — **mas sozinho seria VAZIO**: sem a isca de que a **1ª** passada move, *"nada mudou na 2ª"* é verdade por nada ter mudado nunca |
| *"o agendamento achado pelo nome, com `withoutOverlapping` e `onOneServer`"* | ✅ cumprido — **mas seria VAZIO por construção sem a prova de que `10` e `true` NÃO são default** (`expiresAt` default é **1440**). Um `everyMinute()` pelado passaria. **Acrescentado por reflexão na classe `Event`** |
| *"a ordem relativa provada por posição de ocorrência"* | ✅ cumprido — **mas tem de ser no código SEM COMENTÁRIO**: a prosa do meu bloco cita o vizinho, e uma sonda sobre o texto cru mediria a prosa. ⚠️ E registrei que a mutação m7 **não isola só a ordem** (§12) |
| *"`Http::fake` + `preventStrayRequests()` + `Queue::fake()`: zero requisição, zero despacho"* | ✅ cumprido no comando (`Http::recorded()` **vazio**). ⚠️ **No retry a régua FICOU em "zero ESCRITA", de propósito, mesmo tendo medido zero requisição** — ver §6 e o FURO 5 |
| *"`git diff <partida>..HEAD -- routes/console.php` mostra apenas o bloco novo"* | ✅ **37 inserções / 0 remoções** |
| *"≥ 6 mutações, todas MORTAS"* | ✅ **9 mutações, 9 mortas**, com o `# tests` de cada uma conferido contra o pristino |
| *"`188-05 tentar-de-novo` tem de continuar VERDE, e isso é medição, não torcida"* | ⚠️⚠️ **MEDIÇÃO: ELE FICOU VERMELHO, e NÃO pela causa que o plano previu.** O plano previu *"porque `agendar()` faz alguma REQUISIÇÃO"*; **medido, ele não faz nenhuma**. A causa é outra e é de **produto** — §17 |
| *"nenhuma rota nova, por igualdade de conjunto pelo router"* | ✅ cumprido, **16** rotas idênticas |
| *"a oferta conferida contra `paraAConta()` ANTES de qualquer gravação"* | ✅ **não é trabalho novo**: é `ofertaDoPainel()`, da Fase 188, e eu **não a toquei** — só a chamo na primeira linha, como as outras 18 actions |
| **gate de ordem `dropForeign → dropIndex`** | ⚠️ **NÃO ENTROU, declarado:** esta fase não dropa índice nenhum — seria gate sobre **ZERO casos** |

---

## §16 — ⚠️⚠️ ONDE O BRIEFING DIVERGIU DA MINHA MEDIÇÃO (a medição ganha, e eu declaro)

| o briefing disse | eu medi | consequência |
|---|---|---|
| *"`188-05 tentar-de-novo` tem de continuar VERDE … se reprovar porque `agendar()` faz alguma REQUISIÇÃO, acrescente a LEITURA à lista constante"* | ⚠️⚠️ **ele reprovou, e a causa NÃO é requisição: a fixture daquela fase nunca foi publicável.** Medido: a action do retry faz **ZERO** requisição | **a remediação prescrita não se aplica.** §17, **DEVOLVIDO** |
| *"os blocos `publicador:agente-*` JÁ EXISTEM … o seu bloco pode entrar ENTRE eles … confirme `git diff HEAD -- routes/console.php`: outra sessão commita nesse arquivo"* | ✅ confirmado: os dois blocos existem (`:610`, `:619`) e **não havia WIP alheio** (`git diff HEAD` vazio; último commit do arquivo: `e20a7653`, do AG-06) | o bloco novo entrou **entre** eles, 37/0, e os dois alheios são asseverados intactos |
| *"a trava `publicador:publicacao:` está em `PublicacaoService`"* (régua §1-M3, por implicação) | ⚠️ **ela está no JOB** (`PublicarRascunhoJob:57`) | a minha sonda deu **0** no serviço antes de eu achar o caminho real — §7 |
| *"`mysqld` local está DE PÉ (PID 30496) — e NÃO afeta nada"* | ✅ **confirmado nas duas metades**: `tasklist` mostra `mysqld.exe 30496`, e `phpunit.xml:27-28` fixa `DB_CONNECTION=sqlite` / `:memory:` | ⚠️ **`artisan migrate` NÃO foi rodado** (esta fase não tem migration, e há migration alheia rastreada na árvore) |
| *"o HEAD se moveu 1 e 3 vezes nas waves anteriores — meça, não presuma"* | ⚠️ **desta vez NÃO se moveu** (`FACE 2` vazia, HEAD `dbbbbaf2` nas duas pontas de BUILD, JS e PHP) | a baseline longa é válida |
| *"MANIFEST 500/204"* | ✅ **500 / 204** | provado por `numstat` **vazio**, não pelo número |
| *"a isca (−) do homônimo do 193-03 usa `test_aprovar_e_enfileirar_188_05`, um nome REAL de outra fase"* | ⚠️ **aquele nome NÃO EXISTE como método** (os métodos daquele id são `test_aprovar_recusa_nos_tres_eixos…`, `…zero_bloqueios…`, `…enfileira_e_nao_escreve…`) | é por isso que a isca dele **não** tropeçou no `186-09`; a minha, com o nome **real**, tropeçou — e o conserto foi na raiz (§11, FURO 1) |

---

## §17 — ⚠️⚠️ AS DUAS DEVOLUÇÕES DESTA WAVE (nenhuma consertada por mim)

### DEVOLUÇÃO 1 — o gate alheio **`188-05 tentar-de-novo` ficou VERMELHO**

```
188-05 tentar-de-novo   EXIT_REAL=1   Tests: 2 failed (2 assertions)
  Expected response status code [201, 301, 302, 303, 307, 308] but received 422.
  at tests/Feature/Publicador/Aprovacao/SalvarEAprovarDoPainelTest.php:976
```

**A CAUSA, MEDIDA (e não deduzida), com os três motivos empilhados:**

```
fixture da 188 (produto com as DUAS modalidades ATIVAS, só o Clássico em `falha`):
 [1] contas_liberadas VAZIO + irmã aguardando:
     'falta aprovar a outra modalidade deste produto, que está ativa e iria ao ar
      junto — Premium (Aguardando aprovação).'        <- a guarda da DECISÃO 23 domina
 [2] conta LIBERADA, irmã aguardando:                  o MESMO motivo
 [3] conta LIBERADA, irmã INATIVA:
     'Confira no Mercado Livre antes de agendar.'      <- falta a conferência L3
```

➡️ **A fixture daquela fase NUNCA FOI PUBLICÁVEL.** Enquanto o retry era só uma transição de estado, ela
redirecionava; agora que o retry **enfileira de verdade**, ela **recusa (422)** — e **recusar é o
comportamento certo**: mover sem enfileirar deixaria a oferta `publicando` com a ligação no item
terminado e o reconciliador a fecharia como `falha` no minuto seguinte (`publicando → falha` a cada
minuto, com a edição bloqueada). ⚠️ **As asserções de INVARIANTE daquele caso seguem corretas** (*"zero
escrita no ML"*, *"sem MLB"*): o que ficou obsoleto é o `assertRedirect()`.

**O QUE EU NÃO FIZ, E POR QUÊ:**

| saída | custo | por que eu não a tomei |
|---|---|---|
| **(a)** tornar a fixture da 188 publicável | ⚠️ exige, **por método**, liberar a conta **+** desativar/aprovar a irmã **+** criar a `PubValidacao` L3 **+** títulos distintos (V-TIT-04) — num `cenario()` **compartilhado por 9 métodos** de um teste de fase **EM PRODUÇÃO**, e **remedir `contagens-188-05.txt`** | é **cirurgia** num artefato de fase em produção, e a remediação que o plano autoriza é outra (*"acrescentar a LEITURA à lista constante"*, para a causa *requisição*, que **não é** esta) |
| **(b)** mover mesmo com a fila recusando | zero território | ⚠️ **o plano PROÍBE** (*"a oferta NÃO vai para `publicando`"*) e é pior: produz o estado que mente |
| **(c)** mover e anular `fila_item_id` | zero território | ⚠️ produz o estado que o `193-03` declarou **IMPOSSÍVEL** — o handoff manda **não inventar ramo de produto** para ele |

➡️ ⚠️ **DONO: USUÁRIO / ORQUESTRADOR.** A recomendação do executor é **(a)**, feita **por método** (não
no `cenario()` compartilhado), **sem tocar nenhuma sonda** daqueles dois casos e com **as duas pontas de
`contagens-188-05.txt` medidas e coladas**.

### DEVOLUÇÃO 2 — ⚠️⚠️ **ITEM `193-I` (NOVO, meu): o BECO do retry no produto com DUAS modalidades**

```
produto com os DOIS alvos ATIVOS e as DUAS ofertas em `falha`:
  retry no Clássico -> 'falta aprovar a outra modalidade … — Premium (Falha na publicação).'
  retry no Premium  -> 'falta aprovar a outra modalidade … — Clássico (Falha na publicação).'
  TRANSICOES[falha] = publicando        <- a UNICA saida de `falha`
```

**O mecanismo:** `JA_PASSARAM_PELO_PORTAO` (do `193-02`) é `[aprovado, publicando, publicado]` e deixa
**`falha` FORA de propósito**, com a razão escrita no docblock (*"quem está em `falha` não está indo ao
ar agora — e 'Tentar de novo' é o caminho próprio dela"*). ➡️ Quando **as duas** modalidades falham
juntas — que é o desfecho normal de uma publicação que falha, porque a fila publica o **produto
inteiro** —, **cada uma recusa nomeando a outra**, e `falha` só sai para `publicando`. **Nenhuma das
duas pode ser retentada.**

⚠️ **ISTO NÃO É DEFEITO DESTA WAVE:** é a guarda da **decisão 23 do usuário** alcançando o caminho do
retry. ⚠️ **E NÃO É ALCANÇÁVEL EM PRODUÇÃO HOJE:** `pub_aprovacao_ofertas` está **vazia**, e o único
código que cria oferta (`PreparadorDaAprovacao`, do agente, de outra sessão) encaminha produto com **UM
alvo ativo** — com um alvo só, a lista de pendentes vem **vazia por construção** e o retry enfileira
normalmente (provado: é o caminho de todos os casos verdes desta wave).

| saída | custo |
|---|---|
| **(a)** acrescentar `FALHA` a `JA_PASSARAM_PELO_PORTAO` | ⚠️ toca `PublicacaoDoPortaoService`, **revertendo uma decisão escrita** do `193-02`/`193-03`. ⚠️ **Semântica honesta a favor:** irmã em `falha` **JÁ foi aprovada** (`mover(FALHA)` preserva `versao_aprovada`/`aprovado_por`/`aprovado_em`) — ela não é "modalidade que ninguém revisou" |
| **(b)** a guarda não valer **no caminho do retry** | ⚠️ exigiria um parâmetro novo em `enfileirar()` — **duas semânticas** no mesmo método |
| **(c)** deixar como está | ⚠️ zero território, e **inalcançável até o piloto do agente com dois alvos ativos**. Mas é um beco **sem mensagem que a pessoa possa resolver** |

➡️ ⚠️ **DONO: USUÁRIO / ORQUESTRADOR.** ⚠️ **Eu NÃO alterei a constante** — ela é decisão escrita de
outra wave, e mudá-la por conta própria é exactly o que a régua proíbe. **Deliberadamente NÃO congelei o
beco numa asserção**: congelar um defeito faria o executor seguinte "consertar" a expectativa.

---

## §18 — AS PERGUNTAS DESTA WAVE

| # | pergunta | estado | dono |
|---|---|---|---|
| **193-04/A** | o `188-05 tentar-de-novo` vermelho | ⚠️ **MEDIDO e DEVOLVIDO** (§17-1). Recomendação: saída **(a)**, por método, sem tocar sonda, com as duas pontas medidas | **USUÁRIO / orquestrador** |
| ⚠️ **193-I** | o **beco** do retry no produto com duas modalidades em `falha` | ⚠️ **MEDIDO e DEVOLVIDO** (§17-2), com o custo de cada saída escrito. **Inalcançável em produção hoje** | **USUÁRIO / orquestrador** |
| **193-04/B** | a recusa do retry **não** fica no histórico (o rollback a desfaz) | ⚠️ **MEDIDO e DECLARADO** (§5). É consequência da transação que o plano manda; mudar é decisão de produto | **USUÁRIO** |
| ⚠️ | o `186-09 sem-segundo-passo` **não pega** a forma acentuada com espaço | ⚠️ **ACHADO do 193-02, re-reconfirmado e NÃO consertado** (Fase 186 em produção). Esta wave usa a **UNIÃO** | **USUÁRIO / orquestrador** |
| **193-C** | o toast *"Publicado e confirmado"* nunca dispara sozinho | ⚠️ segue valendo: a confirmação chega minutos depois, **pelo comando que esta wave criou**, e **não há polling** | Fase 194 / fase de tela |
| **193-H** | a de-duplicação do histórico é contra a **ÚLTIMA** linha | ✅ **FECHADA pelo orquestrador**: fica como o `193-03` implementou. **Não mexi** | — |

---

## §19 — O QUE ESTE PLANO NÃO FEZ (é a régua, não omissão)

- ❌ **nenhuma migration**, ❌ **`artisan migrate` não foi rodado**, ❌ **nenhuma coluna nova**
- ❌ **nenhum `.jsx`** (`numstat` vazio em **todo** `resources/js/`), ❌ **nenhuma rota nova**,
  ❌ **nenhum estado novo**, ❌ **nenhum rótulo novo**, ❌ **nenhum 7º toast**
- ❌ **nenhum feature-flag** de *"ligar publicar"* (a trava é `ContasLiberadas`, fail-closed)
- ❌ **nenhuma fila nova e nenhuma fila mudada**: a publicação continua no `PublicarRascunhoJob`/`high`
- ❌ **não alterei** `PublicacaoService`, `FilaPublicacaoService`, `MlPublicacaoService`,
  `AgendadorDaFila`, `ResumoRapidoService`, `PreparadorDaAprovacao`, `AndamentoDaOfertaService`,
  `AprovacaoDaOfertaService`, **`PublicacaoDoPortaoService`** nem qualquer outro serviço/`.jsx` das
  Fases 186-189
- ❌ **não toquei** `contagens-186-04.txt`, `contagens-186-09.txt`, `contagens-188-05.txt`,
  `SalvarEAprovarDoPainelTest.php` nem `FechamentoDaFase186Test.php`
- ❌ **não toquei** `ROADMAP.md`, `REQUIREMENTS-v27.md`, `STATE.md`, `193-REGUA.md`,
  `193-COBERTURA.md`, `193-BASELINES.md` nem os `193-NN-PLAN.md`; ❌ `state.advance-plan` não rodou
- ❌ **nenhum deploy, push, VPS ou chamada à API real do ML** · ❌ nenhum `npm install`/
  `composer require` · ❌ nenhum `pint`, `stash`, `reset`, `checkout`, `clean`, `--amend`, worktree
- ⚠️ **NÃO AFIRMO ter visto tela renderizada**, nem publicação real, nem **o cron de verdade rodando**:
  não há navegador, nenhuma empresa tem `ml_token` de verdade e **o scheduler do sistema operacional não
  é exercido nesta máquina**. A prova desta wave é **`artisan` + `Http::fake` + `preventStrayRequests()`**
  para o comando, e **leitura do `Schedule` do container** para o agendamento
- ⚠️ **O critério 10 nasce RESSALVA** (medir o caminho completo numa oferta real, em produção, conta
  459): é `checkpoint:human-verify` do `193-05`, e **fica mais perto** porque o `PreparadorDaAprovacao`
  **cria** oferta — mas **nenhuma loja tem o agente ligado**, logo `pub_aprovacao_ofertas` **segue vazia
  em produção**

---

## §20 — Deviações do plano

### Auto-corrigidas (Regra 1 — defeito no meu próprio artefato, achado por MEDIR)

1. **[Regra 1] A isca do homônimo escrevia um nome alheio na forma de DECLARAÇÃO** — violava o gate
   alheio `186-09 bijecao-e-homonimo` (`1 failed, 11 passed`). Conserto de raiz: o nome entra por
   variável, a isca mantém o nome REAL, o gate da 186 intocado. Commit `dbbbbaf2`.
2. **[Regra 1] O critério *"nem literal de andamento"* no Command era sonda mais larga que o
   invariante** — reprovaria o comando por imprimir corretamente as chaves do resumo. Conserto nos dois
   lados. Commit `c47aad5d`.
3. **[Regra 1] `publicacao_id` ausente na fixture media o ramo `falha` em vez de `publicado`.** Commit
   `c47aad5d`.
4. **[Regra 1] Asserção vazia por construção** (duas leituras do mesmo instante) trocada por
   antes × depois. Commit `c47aad5d`.
5. **[Regra 1] Quase congelei "zero requisição" numa asserção** depois de medi-la — removida; a medição
   fica no comentário. Commit de docs.
6. **[Regra 1] A minha sonda de PUB-06 procurou a trava no arquivo errado** (serviço, não job).
   Corrigida no script de medição.
7. **[Regra 1] Igualdade de literal onde devia ser `assertStringStartsWith`** (a cláusula do 193-03 é
   concatenada ao erro real). Commit `dbbbbaf2`.

### Deviação declarada de forma (mínima, no controller)

8. **A `const REGRA_FILA_RECUSOU_O_RETRY` foi declarada no controller**, imediatamente **acima** da
   action, e não só o corpo dela foi tocado. ⚠️ A razão é o próprio critério do plano (*"o diff mostra
   apenas a action"*): declarar a const **ao lado** mantém o diff local, em vez de espalhá-lo para o topo
   da classe. Ela existe **só** para esta action.

### ⚠️ Regra 4 acionada DUAS vezes — e por isso há DEVOLUÇÃO, não conserto

As duas estão em **§17**, com a medição e o custo de cada saída. **Nenhuma decisão arquitetural foi
tomada por mim**: nem reescrevi fixture de fase em produção, nem mexi em constante decidida por outra
wave.

---

## §21 — Known Stubs

**Nenhum.** Não há valor vazio hardcoded, texto de espera reservado nem componente sem fonte de dados.
⚠️ O que existe e **está declarado como fronteira, não como stub**: o **cron do sistema operacional** não
é exercido nesta máquina (prova-se o comando e o registro), e o **critério 10** é `checkpoint:human-verify`
do `193-05`.

## §22 — Self-Check: PASSED

Arquivos afirmados, conferidos por `test -e` (⚠️ **em CADA caminho citado** — são **dezoito** fantasmas
nesta milestone, e o fechamento da 189 pegou **6 de 32**, todos de NOMES): **24 / 24 FOUND, ZERO
fantasma.**

```
FOUND:   app/Console/Commands/PublicadorPortaoPublicacao.php            (89 l. · min_lines 40)
FOUND:   app/Console/Commands/PublicadorFilaPublicacao.php              <- o MODELO (32 l.)
FOUND:   app/Console/Commands/WarmTarifasDaFila.php                     <- o precedente "ZERO LOGICA PROPRIA"
FOUND:   routes/console.php
FOUND:   routes/mlb_anuncios.php                                        <- a rota do retry (da 188, INTOCADA)
FOUND:   app/Http/Controllers/MlbPublicadorAprovacaoController.php
FOUND:   tests/Feature/Publicador/Aprovacao/ComandoDoPortaoDaPublicacaoTest.php  (1292 l. · min_lines 200)
FOUND:   tests/gates/contagens-193-04.txt
FOUND:   app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php
FOUND:   app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
FOUND:   app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php
FOUND:   app/Services/Publicador/PublicacaoService.php                  <- o POST /items esta em :442
FOUND:   app/Jobs/Publicador/PublicarRascunhoJob.php                    <- onQueue('high') em :41, trava em :57
FOUND:   app/Support/Publicador/ContasLiberadas.php
FOUND:   app/Services/Publicador/Fila/FilaPublicacaoService.php
FOUND:   app/Services/Publicador/Fila/ResumoRapidoService.php           <- em Fila/, NAO em Aprovacao/Fila/
FOUND:   app/Services/Mlb/Publicacao/MlPublicacaoService.php            <- EXISTE
FOUND:   app/Services/Publicador/Agente/PreparadorDaAprovacao.php       <- em Agente/, de outra sessao
FOUND:   tests/Feature/Publicador/Aprovacao/SalvarEAprovarDoPainelTest.php   <- o do gate vermelho (INTOCADO)
FOUND:   tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php      <- o gate do homonimo (INTOCADO)
FOUND:   tests/js/estrutura-meus-anuncios.test.js                       <- D-11/D-22, 30/30/0
FOUND:   tests/js/publicador-header-zindex-ordem.test.js                <- 7/7/0
FOUND:   tests/js/estrutura-grade-glide.test.js                         <- a falha pre-existente da Fase 87
FOUND:   design_handoff_publicador/Aprovacao.dc.html                    <- 1432 l., sha256 68624883ce35547e
MISSING: (nenhum)
REMOVIDOS (temporarios de medicao, nunca commitados):
   tests/Feature/Tmp19304SondaDoRetryTest.php   -> test -e: AUSENTE
   tests/Feature/Tmp19304RedeDoRetryTest.php    -> test -e: AUSENTE
```

Commits afirmados, conferidos por `git log --oneline --all | grep`:

```
FOUND:   8c54a5d8  docs(193-03): a SUMMARY …                     <- a PARTIDA
FOUND:   c47aad5d  feat(193-04): o passo do minuto do portão …   <- a tarefa 1
FOUND:   dbbbbaf2  feat(193-04): o "Tentar de novo" republica …  <- a tarefa 2
MISSING: (nenhum)
```

`key_links` do plano, conferidos no código **sem comentário**:

```
routes/console.php -> PublicadorPortaoPublicacao   via Schedule::command('publicador:portao-publicacao')
   ·  1 ocorrencia no codigo limpo, DEPOIS do `fila-publicacao`, achada tambem pelo NOME no Schedule
Controller::tentarDeNovo -> PublicacaoDoPortaoService   via `$portao->enfileirar(` ANTES de `tentarDeNovo(`
   ·  1 chamada a enfileirar(  ·  1 chamada a tentarDeNovo(  ·  as duas na MESMA DB::transaction
Command -> PublicacaoDoPortaoService   via `->reconciliar(`
   ·  EXATAMENTE 1  ·  `->mover(` = 0  ·  `PubAprovacaoOferta` = 0
```

## §23 — Threat Flags

**Nenhuma superfície nova fora do `<threat_model>`.** ⚠️ **Nenhuma rota criada** (`routes/mlb_anuncios.php`
com `numstat` vazio e igualdade de conjunto das 16 pelo router), nenhum caminho de autenticação novo
(a action reusa `ofertaDoPainel()`, com `ml_token` → **404** e `paraAConta()` **antes** de qualquer
gravação), nenhum acesso a arquivo novo e **nenhuma mudança de schema** (`database/migrations/` com
`numstat` vazio por range).

⚠️ **Superfície nova de EXECUÇÃO, declarada e dentro do registro:** um **comando Artisan agendado**
(`publicador:portao-publicacao`). Ele **só LÊ o banco** e move andamento **pela única porta**; **não
aceita argumento nenhum**, **não faz requisição** e **não despacha job** — tudo medido.

`T-193-SC` (npm/composer installs) conferido por `numstat` **VAZIO** no range `8c54a5d8..HEAD` em
`package.json`, `package-lock.json`, `composer.json` e `composer.lock`.

| ameaça | mitigada por |
|---|---|
| `T-193-31` lógica de domínio no comando, longe de todo gate | §2 — 11 formas proibidas com **0** ocorrências no código sem comentário, isca (+) sintética, `->reconciliar(` = 1; mutação **m6** mata |
| `T-193-32` passada do minuto se sobrepondo e movendo duas vezes | §2/§3 — `withoutOverlapping(10)` + `onOneServer()` por igualdade de conjunto **com a prova de que não são default**, e idempotência por **reconsulta ao banco** (linha inteira, byte a byte); mutações **m4** e **m5** matam |
| `T-193-33` retry que move sem enfileirar | §5 — `enfileirar()` antes, em transação, com a **isca invertida** medindo o `fila_item_id` antes (fora de `VIVOS`) e depois (dentro); a razão no docblock; mutação **m1** mata |
| `T-193-34` guarda *"só a partir de `falha`"* enfraquecida | §5 — igualdade de conjunto dos 3 estados recusados + a **isca da lista fechada** (`TRANSICOES[aprovado]` inclui `publicando`); mutação **m2** mata |
| `T-193-35` botão que finge ter tentado | §5 — 422 com o `regra` estável e o motivo **REAL**, linha intacta byte a byte, zero item de fila novo; mutação **m9** mata |
| `T-193-36` publicação atrás de 179 jobs de acervo na `default` | §4 — **accept + document**: a publicação **já** roda na `high` (medido no job sem comentário), e o comentário do agendamento registra a medição de **16/09/2026** e o `queue:restart` pós-deploy |
| `T-193-37` mutação com script sobrescrito por agente paralelo | §12 — `mutar-193-04.sh`/`mutar-193-04.php` com o plano no nome, `sha256` dos **dois** impresso antes e reconferido depois |
| `T-193-SC` npm/composer installs | §13 — `numstat` **VAZIO** nos quatro arquivos |
