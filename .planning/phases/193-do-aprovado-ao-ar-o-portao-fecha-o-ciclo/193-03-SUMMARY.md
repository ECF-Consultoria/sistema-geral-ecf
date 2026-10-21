---
phase: 193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo
plan: 03
subsystem: publicador-aprovacao
tags: [reconciliador, publicado-com-mlb, erro-real, clausula-condicional, rn-93, decisao-24, mutacao, territorio]
requires:
  - "193-01 (a ausencia de migration provada por assercao sobre o schema vivo)"
  - "193-02 (enfileirar() com a guarda da decisao 23, e o gancho aoAprovar() LIGADO)"
  - "Fase 186 (a unica porta, as 3 colunas de ligacao, o historico append-only)"
  - "Fase 188 (a rota de tentar de novo e a caixa de falha do painel)"
provides:
  - "PublicacaoDoPortaoService::reconciliar() — o passo do minuto que espelha o que a fila FEZ"
  - "publicado SO com MLB confirmado, gravado ANTES, com a porta recusando sem ele"
  - "falha com o erro REAL + a clausula do prototipo SO quando nada do tipo da oferta foi criado"
  - "o item em voo (PENDING/SENT/UNKNOWN) JAMAIS tocado (RN-93), com isca invertida"
  - "DECISAO 24: os avisos do Mercado Livre aceitos gravados no historico, nomeados"
  - "o item 193-G resolvido: as duas irmas aprovadas ficam ligadas ao MESMO item de fila"
  - "registrarUmaVez(): o historico nao repete o motivo que ja e a ultima linha"
affects:
  - "193-04 (o comando do minuto chama reconciliar(); o retry enfileira ANTES de mover)"
  - "193-05 (o fechamento: numstat de MlPublicacaoService, D-11, checkpoint humano)"
tech-stack:
  added: []
  patterns:
    - "predicado PUBLICO quando a condicao e quase-sempre-verdadeira no caminho que o exercita"
    - "sonda ancorada no COMECO do caminho, nunca por substring (/seller-promotions/items/ contem /items/)"
    - "igualdade de CONJUNTO dos destinos de mover(), nunca contagem de call-sites"
    - "de-duplicacao do historico contra a ULTIMA linha, lendo antes de inserir (a tabela segue append-only)"
    - "dependencia ciclica resolvida por resolucao sob demanda, com o ciclo MEDIDO em container limpo"
key-files:
  created:
    - tests/Feature/Publicador/Aprovacao/ReconciliarPublicacaoDoPortaoTest.php
    - tests/gates/contagens-193-03.txt
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-03-SUMMARY.md
  modified:
    - app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php
decisions:
  - "Decisao 24 do usuario IMPLEMENTADA: os avisos do ML aceitos vao ao historico, lidos do PROPRIO item da fila (ciente + validacao_id), sem o portao passar a ler a ConferenciaService e sem mudar o painel"
  - "Item 193-G resolvido pelo caminho do ANEXO dentro do reconciliador — nenhuma escrita em linha alheia pelo enfileirar()"
  - "O item que saiu da fila sem publicar tem a ligacao DESFEITA: oferta que ainda espera publicacao nunca aponta para item fora de VIVOS"
  - "A chave literal de andamento foi TIRADA do contexto de log, em vez de o arquivo entrar na lista de expositores do gate 186-09 ou de a chave ser renomeada para fugir dele"
  - "motivoComClausula() e publica de proposito: sem isso a mutacao 'concatenar a clausula SEMPRE' sobreviveria"
metrics:
  duration: "~4 h"
  completed: "2026-10-21"
  tasks: 2
  commits: 2
---

# Fase 193 Plano 03: Do aprovado ao ar — o reconciliador, e a decisão 24 que tirou a ficção do `ciente` Summary

O `reconciliar()` está de pé: o passo do minuto espelha na oferta o que a fila **efetivamente fez** —
`publicando` enquanto sobe (e ele bloqueia edição), `publicado` **só com MLB confirmado**, `falha` com
o **erro REAL** e a revisão aprovada **intacta**. Nada da disciplina de escrita no Mercado Livre foi
reimplementado: ela já está em `PublicacaoService`, e o que esta wave acrescenta é a garantia de que o
reconciliador **jamais toca item em voo**.

⚠️ **A decisão 24 do usuário entrou como acréscimo e está implementada:** os avisos do Mercado Livre
aceitos pelo clique passam a ser **gravados no histórico, nomeados** — o aviso deixa de ser invisível
e passa a ser auditável, sem o portão ler a `ConferenciaService` e **sem mudar o painel**.

⚠️⚠️ **A campanha de mutação teve 13 mutações e ZERO sobreviventes** — mas três sondas MINHAS foram
consertadas **na raiz** antes disso, duas delas por serem **mais largas que o invariante** e uma por
ancorar em **número que muda legitimamente**. E uma das minhas sondas **pegou uma violação real de um
gate alheio antes do gate**.

---

## §1 — A PARTIDA

```
PARTIDA: b222df47   (capturada na PRIMEIRA ação, antes de qualquer leitura — confere com o esperado)
HEAD no fim do plano: 5f473075  (+ o commit desta SUMMARY)
```

| commit | de quem | o que |
|---|---|---|
| **`5f473075`** | **MEU** | `feat(193-03)`: o `reconciliar()` do portão — `publicando`, `publicado` só com MLB, `falha` com o erro real |
| (este) | **MEU** | `docs(193-03)`: a SUMMARY |

⚠️ **A PARTIDA foi usada SÓ como referência de leitura.** O território saiu do **escopo do assunto no
`%s`** (§11), nunca de range aberto a partir dela.

⚠️⚠️ **DESTA VEZ O HEAD NÃO SE MOVEU, e isso é medição, não sorte:** a `FACE 2` da prova de território
(§11) veio **vazia** — **nenhum** commit alheio entrou no range `b222df47..HEAD`. Nas duas waves
anteriores houve **um** e **três**, respectivamente.

### ⚠️ As duas tarefas num commit só, declarado

O plano pede **commit atômico por tarefa**, e eu entreguei **um** commit de código para as duas. A
razão é concreta: **o mesmo arquivo de teste carrega as duas tarefas** (os desfechos do reconciliador
e as travas de edição são provados com a MESMA fixture de conta/produto/oferta), e separar **depois de
medido** exigiria tirar do disco métodos já provados para recommitá-los. ➡️ O que a régua protege —
*"não perder trabalho sem commit"* — está cumprido: **nada ficou sem commit em nenhum momento**, e o
commit saiu **antes** da campanha de mutação, como a forma exige.

---

## §2 — ⚠️⚠️ A DECISÃO 24 IMPLEMENTADA: QUAIS AVISOS DO ML FORAM GRAVADOS

**O problema, conferido por mim e não aceito do briefing:**

| # | o que o briefing disse | o que eu medi |
|---|---|---|
| 1 | `PublicacaoService:95-96` recusa publicar com avisos do ML sem `ciente` | ✅ **`:95`-`:96`**: `if ($v->resultado === ConferenciaService::AVISOS && ! $cienteDosAvisos) throw new RegraViolada('RN-90', 'Há avisos do Mercado Livre: marque "Estou ciente" para publicar.')` |
| 2 | a caixa *"Estou ciente"* não existe no portão | ✅ `ciente` aparece em `Components/Mlb/Publicador/Lote/DialogoAgendar.jsx` (**lote**) e em **nenhum** componente de `aprovacao/` |
| 3 | nada em `Aprovacao/` lê a `ConferenciaService` | ✅ confirmado — e **continua assim depois desta wave** |

### ⚠️ A FONTE DOS AVISOS É O PRÓPRIO ITEM DA FILA — e é isso que mantém a decisão barata

Medido em `FilaPublicacaoService::agendar()` (`:108` e `:105`):

```php
'ciente' => $ciente && $l['avisos_ml'],
'validacao_id' => $v->id,
```

E em `ResumoRapidoService::prontidaoDoLote()` (`:509`): `'avisos_ml' => $v->resultado === ConferenciaService::AVISOS`.

➡️ **Logo `ciente === true` significa, MEDIDO, *"houve aviso do Mercado Livre e o clique o aceitou"***,
e `validacao_id` é **a mesma** conferência que o `PublicacaoService::iniciar()` vai reler. ⚠️ **Nada
reconsulta a `ConferenciaService`, nada aplica uma segunda régua de *"qual conferência vale"*, e o
painel não mudou** — mostrar os avisos na tela foi **oferecido e RECUSADO** pelo usuário, e barrar a
aprovação é o **9º bloqueio**, que ele **já recusou** na decisão 21.

### O que é gravado, com a saída do teste colada

```
test_avisos_do_ml_aceitos_vao_para_o_historico_193_03

  ISCA (−) conferência SEM aviso:
     o item nasce com `ciente` FALSO                                          ✅
     eventos com o prefixo de avisos aceitos = []   (nenhuma linha vazia)     ✅

  ISCA (+) conferência com 4 `issues` (2 avisos distintos + 1 repetido + 1 INFO):
     o item nasce com `ciente` VERDADEIRO e com a `validacao_id` da conferência ✅
     EXATAMENTE **1** evento no histórico                                      ✅
     o evento nomeia o aviso do SKU  ("já está em outro anúncio ativo…")       ✅
     o evento nomeia o aviso de frete ("acima do frete da Precificação")       ✅
     o INFO ("A descrição foi normalizada") **não** entrou                     ✅
     o aviso REPETIDO entrou **uma** vez (12 cores com o mesmo aviso não são 12) ✅
     `issues` adverso (string, não lista) devolve [] sem lançar                ✅
```

**O texto gravado**, na forma exata:

```
Avisos do Mercado Livre aceitos ao aprovar para publicação: O SKU «RCL-9» já está em outro
anúncio ativo desta conta (MLB123). Confira se não é o mesmo produto. · O frete do Mercado
Livre ficou acima do frete da Precificação.
```

### ⚠️ A PROVA DE QUE NENHUMA CHAVE TÉCNICA VAZA

O histórico é **texto de TELA** (DADO-10, a linha do tempo do painel), e por isso vai **só a
`mensagem` pt-BR** — nunca o `regra` (`V-FRT-01`…), nunca o `ml_causa` cru. Provado por **asserção
COLETORA** (não uma por evento — o número de eventos depende de quantas vezes o enfileiramento foi
tentado, e o 193-02 mediu isso subir 26 → 27 **sem defeito nenhum**):

```
jargão varrido em TODOS os eventos da oferta: V-REM-02 · V-FRT-01 · item.sku.duplicated ·
   ml_causa · severidade · WARNING · gold_special        →  []   ✅
```

⚠️ **E a mutação prova que não é enfeite:** desligar a gravação (`m13`) **MATA** o gate.

---

## §3 — ⚠️⚠️ O ITEM `193-G`, DEVOLVIDO PELO 193-02 E RESOLVIDO AQUI

Com a guarda da **decisão 23**, quem agenda é a **ÚLTIMA** aprovação do produto: a irmã aprovada antes
fica em `aprovado` **sem `fila_item_id`**, porque o `enfileirar()` do 193-02 escreve a ligação **só na
oferta que recebeu** (escrever na irmã seria aquele serviço mexendo em linha que não lhe foi passada).

➡️ **É o reconciliador que a liga, pelo caminho do ANEXO** — que o 193-02 já tinha implementado e
provado. A sequência, com a saída colada:

```
test_as_irmas_aprovadas_ficam_ligadas_ao_mesmo_item_apos_reconciliar_193_03

  1. Clássico aprovado (Premium ATIVO e não aprovado) → 0 itens de fila, sem ligação   ✅
  2. Premium aprovado  (os dois passaram pelo portão) → ELE agenda; o Clássico segue
     SEM ligação (prova de que o 193-02 não escreve em linha alheia)                   ✅
  3. reconciliar()  → enfileiradas=1, erros=0
        as DUAS ofertas apontam para o MESMO fila_item_id                              ✅
        itens de fila do produto = 1  (o unique `pubfilai_produto_ativo_uq`)            ✅
        as 4 colunas de conferência seguem sendo LIDAS pelo item (DADO-03)              ✅
  4. a 2ª passada: mesma ligação, ZERO evento novo (idempotência)                       ✅
```

⚠️ **Sem isto a oferta do Clássico iria ao ar sem nunca apontar para a fila que a publicou**, e o
painel leria as 4 colunas de conferência de lugar nenhum.

---

## §4 — `publicado` SÓ COM MLB CONFIRMADO, E A TRAVA ESTÁ NO CÓDIGO

**A ordem é `ml_item_id` → `mover(PUBLICADO)`, e quem a impõe não é o teste: é a porta.**

```php
// desfecho(), em PublicacaoDoPortaoService
$oferta->update(['ml_item_id' => trim($mlb)]);                      // ⚠️ PRIMEIRO
$this->porta()->mover($oferta->fresh(), PubAprovacaoOferta::PUBLICADO);
```

`AndamentoDaOfertaService::mover()` **recusa** o destino `publicado` quando `podeSerPublicado()` é
falso, com o código `AND-04-SEM-MLB-CONFIRMADO`. ➡️ **Quem invertesse a ordem não publicaria errado: a
própria porta reprovaria.** Provado nas duas direções:

```
a porta recusa `publicado` sem MLB  → RegraViolada AND-04-SEM-MLB-CONFIRMADO     ✅
a recusa é NO-OP COMPLETO: a linha inteira, byte a byte, inclusive `updated_at`  ✅
e MLB em branco (`''`, `'   '`) também não passa — `trim()` antes, não `!== null` ✅
e a mutação m3 (mover ANTES de gravar o MLB) MATA o gate                          ✅
```

### O MLB é o da modalidade DESTA oferta, por UMA regra só

A mesma de `PublicacaoService::cadastrarNaRegua()` (`:691`-`:716`): `sortBy('indice')` →
`groupBy('listing_type_id')` → `first()`. **Duas iscas** fecham o caso:

```
CREATED de OUTRO listing_type   → a oferta do Premium NÃO vai a publicado, e não grava
                                  o MLB do Clássico (T-193-22)                        ✅
DOIS CREATED do mesmo tipo com os índices TROCADOS (7 e 2) → o escolhido é o de
                                  índice 2 (MLB333333333), não o de id menor           ✅
mutação m4 (sem filtro de tipo) MORRE · mutação m5 (`last()`) MORRE                    ✅
```

⚠️ **A conferência de MLB legível vem DEPOIS da escolha e não a substitui:** trocar o `first()` por
*"o primeiro com MLB"* seria a **segunda régua** que esta fase existe para não criar.

---

## §5 — A FALHA: O ERRO REAL, A REVISÃO PRESERVADA E A CLÁUSULA CONDICIONAL

### O erro REAL, pela precedência do precedente

`AgendadorDaFila::fechar()` usa `conta_snapshot['motivo'] ?? <item FAILED>->avisos['mensagem'] ?? <frase>`.
O reconciliador usa a **mesma ordem**, com **cinco degraus** e um refinamento declarado:

| degrau | fonte |
|---|---|
| 1 | `publicacao.conta_snapshot['motivo']` |
| 2 | `avisos['mensagem']` do item `FAILED` **do `listing_type_id` DESTA oferta** |
| 3 | `avisos['mensagem']` de **qualquer** item `FAILED` |
| 4 | `item.motivo` — o que a **fila** já escreveu |
| 5 | `MOTIVO_SEM_CAUSA` (*"O Mercado Livre não informou a causa da recusa."*) |

⚠️ **O refinamento é o degrau 2, e ele é declarado:** `fechar()` fecha o **PRODUTO** (um motivo para os
dois anúncios); aqui a oferta **É** uma modalidade, e mostrar na tela do Premium o erro do Clássico
seria *"o erro certo da coisa errada"*. **A ordem dos degraus não muda.** Provado:

```
com conta_snapshot E aviso de item presentes → GANHA o conta_snapshot (isca da ordem)   ✅
sem conta_snapshot, com FAILED dos DOIS tipos → o Premium recebe o erro do PREMIUM      ✅
mutação m9 (motivo genérico sempre) MORRE                                                ✅
```

### A revisão aprovada, intacta — lida CRUA do banco

```
antes e depois de mover(FALHA):  versao · versao_aprovada · aprovado_por · aprovado_em
  lidos por `DB::table(...)->first([...])`, IDÊNTICOS                                    ✅
"Tentar de novo" (falha → publicando): LIMPA `erro_publicacao` e os quatro seguem
  idênticos — a `versao` NÃO incrementa                                                  ✅
a caixa de falha do painel (`PainelDeRevisaoService::para()`) traz a mensagem REAL
  gravada e o rótulo "Tentar publicar novamente"                                         ✅
```

### ⚠️⚠️ A CLÁUSULA — LITERAL CONGELADO, E UM ACHADO SOBRE A ISCA DO PLANO

O literal é congelado **no teste**, por igualdade de string, com **cinco iscas de quase-igual**:

```
'Nenhuma oferta foi criada; a revisão foi preservada.'   (autoridade: Aprovacao.dc.html:956)
  ✗ sinônimo:            'Nenhum anúncio foi criado; …'
  ✗ sem ponto final:     '… foi preservada'
  ✗ truncada:            'Nenhuma oferta foi criada;'
  ✗ sem cedilha:         '… a revisao foi preservada.'
  ✗ vírgula por ponto e vírgula
```

⚠️⚠️ **E AQUI ESTÁ UM CRITÉRIO DO PLANO QUE EU RODEI E ACHEI FRACO POR CONSTRUÇÃO.** O plano diz: *"o
caso do PARCIAL prova a cláusula em UMA oferta e ausente na outra"*. **Rodei: a segunda metade é
quase-vazia** — no PARCIAL a outra oferta vai a **`publicado`** e tem `erro_publicacao` **NULO**, então
*"a cláusula está ausente nela"* é verdade **por não haver texto nenhum** para conter a cláusula.
➡️ Pior: dentro do caminho da falha, *"nenhum `CREATED` do meu tipo"* é **estruturalmente quase sempre
verdade** (é justamente por isso que a oferta não foi a `publicado`), logo a mutação **"concatenar a
cláusula SEMPRE" sobreviveria** a um gate feito só com o PARCIAL.

**As duas correções de raiz:**

1. **O predicado virou PÚBLICO** (`motivoComClausula()`) e é medido nas **duas** direções, com o corte
   de `string(1000)` **reservando a cláusula antes** (erro gigante não a engole);
2. **Achei o caso REAL em que a falha convive com `CREATED` do meu tipo:** item `CREATED` **sem
   `ml_item_id` legível**. O anúncio **foi** criado e o código não foi lido — a oferta não pode ir a
   `publicado` (AND-04) e dizer *"nenhuma oferta foi criada"* ali seria **MENTIRA**. É a **isca
   invertida forte**, dentro do mesmo caminho.

```
PARCIAL (Clássico CREATED + Premium FAILED):
   publicado=1, falha=1 · o Clássico com MLB444444444 e `erro_publicacao` NULO
   o Premium com o erro REAL + a cláusula no FIM                                  ✅
CREATED do MEU tipo SEM MLB legível:
   vai a `falha`, com o motivo real ("não devolveu o código") e **SEM** a cláusula ✅
predicado direto:  []  → cláusula entra  ·  ['um item'] → cláusula NÃO entra       ✅
mutação m6 (cláusula SEMPRE) MORRE · m7 (cláusula NUNCA) MORRE                     ✅
```

---

## §6 — ⚠️⚠️ RN-93: O ITEM EM VOO NÃO SE TOCA — COM A FIXTURE TENDO UM `UNKNOWN`

`UNKNOWN` é timeout **depois** do envio: o anúncio **pode existir**. Mover a oferta para `falha` diria
que nada foi criado quando talvez tenha sido; reenviar **duplicaria o anúncio** (T-193-23).

⚠️⚠️ **E A ISCA INVERTIDA É O QUE FAZ O CASO VALER:** a fixture tem o `UNKNOWN` **AO LADO DE UM
`CREATED` DO MESMO TIPO**. Sem o `CREATED` ao lado, *"a oferta não se moveu"* seria verdade **por não
haver nada para mover** — gate vazio por construção.

```
test_item_em_voo_no_mercado_livre_nunca_e_tocado_com_isca_invertida_193_03

  fixture: item de fila `parcial` + publicação PARTIALLY_PUBLISHED com
           [CREATED do Clássico MLB555555555, UNKNOWN do Clássico]

  a oferta segue `publicando` · ml_item_id NULO · publicado+falha = 0 · esperando=1   ✅
  ZERO evento novo no histórico                                                        ✅
  os itens da publicação, byte a byte (status, ml_item_id, tentativas, enviado_em):
     IDÊNTICOS antes e depois — NADA foi reenviado                                     ✅
  Http::recorded() VAZIO — o reconciliador não falou com o Mercado Livre               ✅
  os TRÊS estados barram igual: PENDING e SENT também, não só UNKNOWN                  ✅

  ⚠️ ISCA INVERTIDA: trocado o item em voo por FAILED, a oferta VAI a publicado
     com MLB555555555 — o gate não estava verde por outro motivo                       ✅
  mutação m8 (UNKNOWN fora da lista de em-voo) MORRE                                   ✅
```

### O item que saiu da fila SEM publicar

Os **4** estados de `SEM_PUBLICAR` (`falhou`, `precisa_revisar`, `cancelado`, `pulado`), um por um:

```
o motivo REAL do item vai ao histórico, com o prefixo próprio                 ✅
a oferta NÃO sai de `aprovado` (ninguém publicou nada)                        ✅
a ligação com o item morto é DESFEITA — invariante do 193-02: oferta que
   espera publicação nunca aponta para item fora de VIVOS                     ✅
a 2ª passada NÃO duplica a linha do histórico                                 ✅
```

⚠️ **Passar por `publicando` aqui seria um estado que MENTE** — a alternativa (c), recusada no
planejamento, numa máquina em que `publicando` bloqueia edição.

---

## §7 — `publicando` E `publicado` BLOQUEIAM EDIÇÃO — PELOS DOIS LADOS

```
podeEditar() e abreRevisao() FALSOS em `publicando` e em `publicado`                  ✅
ISCA: os TRÊS `live` (aguardando_aprovacao, em_revisao_ecf, ajustes_solicitados)
   são VERDADEIROS — a regra discrimina, não recusa tudo                              ✅

AS 5 ROTAS DE ESCRITA DO PAINEL, CHAMADAS DIRETO (sem a tela), por IGUALDADE DE CONJUNTO:
   painel.preco                 → 422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO
   painel.conferir-alteracao    → 422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO
   painel.frete                 → 422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO
   painel.ajuste                → 422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO
   painel.salvar-revisao        → 422 BLOQ-04-APROVAR-FORA-DO-AO-VIVO                 ✅
ISCA: a MESMA rota com a oferta ao vivo ACEITA (302)                                  ✅
```

⚠️ **DOIS ACHADOS NA MINHA PRÓPRIA SONDA, os dois medidos ao rodar:**

1. **Payload inválido mede o VALIDADOR, não a trava.** As rotas de frete e de ajuste devolveram
   `422 (sem regra)` na primeira rodada, porque `$request->validate()` roda **antes** de
   `recusaDeRevisao()`. ➡️ Os payloads passaram a ser **válidos de propósito** (`origem`+`valor`,
   `escopo`+`item`+`texto`), e aí o `422` que sai é o da **trava**, com o id estável da regra.
2. **`->json('regra')` numa resposta de REDIRECIONAMENTO falha como asserção** (*"a resposta não é
   JSON válido"*) — a minha isca reprovou por isso, não pelo invariante. ➡️ A isca passou a ser o
   **STATUS**: recusa é `422`, aceitação é `302`.

### `publicado` é terminal por ESTRUTURA — e é a medição da `PERGUNTA-193-B`

```
TRANSICOES[PUBLICADO] === []                                                          ✅
os 8 destinos de ANDAMENTOS, enumerados e TODOS recusados com
   AND-02-TRANSICAO-ILEGAL, por IGUALDADE DE CONJUNTO (piso >= N não prova presença)  ✅
a oferta publicada não se moveu nem um byte depois das 8 tentativas                    ✅
```

⚠️⚠️ **E A MEDIÇÃO QUE SUSTENTA A RECOMENDAÇÃO DA `PERGUNTA-193-B`** (editar a FOTO de um anúncio **já
no ar**, a decisão 13 do ROADMAP): **não existe `PUT /items/{id}` em todo o Publicador**. ➡️ Trocar
foto de anúncio no ar é **fluxo novo** — transição saindo de um estado terminal **mais** uma capacidade
de escrita que o sistema **não tem**. **Este plano não o implementa; a decisão é do usuário, e a
recomendação do planejamento é DIFERIR para fase própria.**

⚠️⚠️ **MAS A MINHA PRIMEIRA SONDA DE `PUT` ERA MAIS LARGA QUE O INVARIANTE — e isso é a família que já
barrou um deploy nesta milestone.** Varrer `'PUT'` acusou **QUATRO arquivos legítimos**:

```
app/Services/Publicador/Alavancas/Acoes/AlterarCampanha.php    PUT /seller-promotions/promotions/
app/Services/Publicador/Alavancas/Acoes/AlterarCupom.php       PUT /seller-promotions/promotions/
app/Services/Publicador/Alavancas/Acoes/AlterarNoConvite.php   PUT /seller-promotions/items/
app/Services/Publicador/Alavancas/RequisicaoMl.php             const METODOS = [… 'PUT' …]
```

➡️ **O invariante de verdade é *"nenhum PUT cujo CAMINHO COMEÇA em `/items`"***, com **igualdade de
conjunto** dos dois caminhos que existem. ⚠️ **E a âncora é o COMEÇO do caminho, não o substring:
`/seller-promotions/items/` CONTÉM `/items/`** — uma sonda por substring acusaria a promoção de
convite. As duas iscas estão no caso: a sonda **casa** `'PUT', '/items/MLB123'` escrito na forma do
projeto, e **não** lê a lista de métodos como endpoint.

### O toast de publicada é asserção de AUSÊNCIA

```
o payload do painel da oferta EM VOO não contém "Publicado e confirmado" nem "MLB"     ✅
ISCA: com a oferta publicada, o MLB777777777 APARECE no payload — a sonda de
   ausência não era vazia                                                              ✅
```

⚠️ E o toast **nunca dispara sozinho** nesta fase: a confirmação chega minutos depois, pelo scheduler,
e **não há polling** — é a `PERGUNTA-193-C`, de fase futura.

---

## §8 — PUB-06 POR NÃO-REGRESSÃO, POR CONTAGEM DE OCORRÊNCIAS SEM COMENTÁRIO

⚠️ **Nada da disciplina de escrita no ML foi reimplementado.** Lista **CONSTANTE** de 3 arquivos, com
`assertFileExists` em **cada** um antes de medir, lidos por `token_get_all` e contados por
`substr_count` — **nunca** `preg_match(...) === 1` (passa com a linha de código **apagada** quando o
texto também vive em comentário) e **nunca `grep -c`**, que conta **LINHAS**. **UMA** asserção
coletora:

| invariante | medido no código **sem comentário** |
|---|---|
| trava de conta antes de qualquer gravação | `ContasLiberadas::exigir(` ≥ **2** em `PublicacaoService` |
| `ContasLiberadas` é fail-closed | `exigir(` ≥ 1 no Support |
| item gravado `SENT` antes do POST | `PubPublicacaoItem::SENT` ≥ **3** |
| `4xx → FAILED` | `PubPublicacaoItem::FAILED` ≥ **3** |
| `5xx`/rede `→ UNKNOWN` | `PubPublicacaoItem::UNKNOWN` ≥ **4** |
| RN-93 (janela antes de reconciliar) | `reconciliar_apos_segundos` ≥ 1 |
| sem reenvio por conta própria | trava `publicador:publicacao:` ≥ 1 **e** `function retryUntil(` ≥ 1 |
| ⚠️ a fila é **`high`** | `onQueue('high')` ≥ 1 em `PublicarRascunhoJob` |

⚠️ **Piso e não igualdade, de propósito:** o número de chamadas de uma mesma guarda **cresce
legitimamente** quando alguém a aplica num caminho novo, e ancorar no número exato é a família
*"âncora em número que muda legitimamente"*.

⚠️ **A prova de que o leitor LÊ:** no texto **CRU** de `PublicarRascunhoJob` a palavra `SENT` aparece
**mais** vezes que no limpo (os docblocks a citam). **Sem essa ponta, as contagens poderiam estar
lendo comentário.**

### O serviço do portão, medido no próprio código sem comentário

```
chave literal de `andamento`                       : 0
Http:: / ClienteMlPublicador / dispatch(           : []   (com isca sintética que acha as três)
destinos de mover(), por IGUALDADE DE CONJUNTO     : [FALHA, PUBLICADO, PUBLICANDO]
```

⚠️⚠️ **UMA SONDA MINHA PEGOU UMA VIOLAÇÃO REAL DE UM GATE ALHEIO ANTES DO GATE.** A primeira redação
do `reconciliar()` tinha `'andamento' => $oferta->andamento` no contexto do `Log::error`. A sonda larga
do gate **186-09 `andamento-um-lugar-so`** casa aquela chave em **qualquer** array — inclusive de log,
que **não escreve nada** —, e aquele gate varre `app/` **inteiro**. ➡️ **As duas saídas erradas**
seriam (a) entrar na lista de **expositores** daquele gate (fase **em produção**, cuja decisão foi
*"arquivo novo no portão NÃO entra aqui sozinho"*) ou (b) **renomear a chave** para fugir do gate (o
projeto tem **um vocabulário só**). ➡️ **A saída certa, e a que foi tomada: não expor andamento.** O
`oferta_id` basta para ler a linha.

### ⚠️ O ciclo de construtor, MEDIDO em container limpo

`AndamentoDaOfertaService::__construct()` **já recebe** `PublicacaoDoPortaoService` (é a ligação do
gancho, 193-02). ➡️ Declarar a porta no construtor **do portão** fecharia o ciclo `A → B → A`. **Eu
não afirmei que o container quebra: eu medi**, num `Illuminate\Container` limpo com duas classes de
dependência mútua:

```
C:/xampp/php/php.exe -d memory_limit=64M ciclo-193-03.php
→ PHP Fatal error: Allowed memory size of 67108864 bytes exhausted
  in vendor/laravel/framework/src/Illuminate/Container/Container.php on line 864
→ EXIT_REAL=255
```

➡️ **O container do Laravel NÃO detecta o ciclo: ele recursa até estourar.** Por isso a porta é
resolvida **sob demanda**, e há asserção com **controle positivo** (a porta **declara** o portão) e
negativo (o portão **não** declara a porta).

### Conta não liberada não publica

```
`publicador.contas_liberadas.*` VAZIO → a oferta aprovada NÃO entra na fila, 0 itens,
   e ela fica em `aprovado`                                                            ✅
o motivo chega ao histórico — EXATAMENTE 1 evento, mesmo com 3 reconciliações          ✅
ISCA: liberada a conta, a passada seguinte a enfileira                                 ✅
a fila do job de publicar continua `high`                                              ✅
```

⚠️ **Nenhum feature-flag novo:** a trava de *"ligar publicar"* é essa lista, que é a **mesma** que o
PUB-06 exige. Um segundo interruptor faria **duas verdades** sobre quem pode publicar.

---

## §9 — EM QUE FILA ISSO RODA, E POR QUÊ (declarado, não mudado)

| peça | onde roda | por quê |
|---|---|---|
| `reconciliar()` | **scheduler**, no passo do minuto (`publicador:portao-publicacao`, plano **193-04**) | **não é fila**: é passo idempotente que **só LÊ** o banco e move andamento. Zero `dispatch()`, zero requisição |
| a publicação em si | **`PublicarRascunhoJob`, fila `high`** — **já é assim, e NÃO muda** | ⚠️ a razão está no projeto: a fila de produção é **Redis** (a tabela `jobs` dá **0** e engana), e em **16/09/2026** **179 jobs de acervo do ML na `default`** seguraram um webhook da Clicksign **por HORAS**. Publicação disparada por gente que aprovou **não espera atrás do sync do acervo**. ⚠️ Não é `creative` (fila do outro dev) e não é `default` |

⚠️ **Esta fase NÃO deploya.** Fica registrado para quem for: depois do `deploy.sh` (que só reinicia
`ecf-worker:*`) é obrigatório `sudo -u www-data php artisan queue:restart` para pegar
`ecf-worker-high` **sem matar job em andamento**.

---

## §10 — A FRONTEIRA D-11 / D-22, DECLARADA

O **D-11** diz que a tela de **Publicações** (`Meus Anúncios`) **nunca escreve**. ⚠️ **Esta fase leva
oferta ao ar** — logo a fronteira tem de ficar explícita:

| lado | quem | o que escreve |
|---|---|---|
| **o portão / a fila** (meu) | `PublicacaoDoPortaoService` → `FilaPublicacaoService` → `AgendadorDaFila` → `PublicacaoService` → `PublicarRascunhoJob` | ⚠️ **nesta wave, SÓ BANCO**: o reconciliador não faz **requisição nenhuma** (`Http::recorded()` vazio em todos os casos). O único `POST /items` do sistema é `PublicacaoService::executarFatia()` (`app/Services/Publicador/PublicacaoService.php:442`), **reusado INTOCADO** |
| **a lista de Publicadas** (D-11/D-22) | `tests/js/estrutura-meus-anuncios.test.js` | **nada** — `numstat` **VAZIO** por range, e o gate **não foi estendido** à área nova |

➡️ **O gate do D-11 continua intacto e NÃO foi estendido.** A área nova tem **gate de escrita próprio**
(`ausencia-de-segundo-passo-193`, do 193-02, sobre a lista constante dos arquivos do portão — e este
plano **não acrescenta arquivo de produção**, então a lista não mudou). A igualdade de conjunto das
asserções daquele arquivo JS é do **193-05**.

### O gate de ausência dos 4 literais proibidos, pela UNIÃO das duas formas

`ausencia-de-segundo-passo-193` → `EXIT_REAL=0`, `Tests: 1 passed (23 assertions)` — **contagem
IDÊNTICA**, com o meu código novo dentro do arquivo que ele varre.

⚠️ **A sonda dele é a UNIÃO medida pelo 193-02:** a `sondaPub09` do **186-09 não** casa a forma pt-BR
acentuada com espaço (*"Confirmar publicação"*), e a do **188-05 não** casa `undo` nem
`publicar_aprovado`. **Nenhuma das duas, isolada, cobre as nove formas.** ⚠️ O achado sobre o gate
alheio **segue valendo e não foi consertado** (Fase 186 está em produção) — **dono: usuário /
orquestrador**.

⚠️ E a **isca do leitor** continua com sujeito: a prosa dos meus comentários novos cita as nove formas
**de propósito**, e no código **limpo** a sonda acha **zero**.

---

## §11 — O TERRITÓRIO: `PI(193-03)` POR ESCOPO DO ASSUNTO

`sha256` do script: `12c0be5a7acbb8a0`.

```
PARTIDA=b222df47   HEAD=5f473075
PASSADA A (git log --grep, le a MENSAGEM INTEIRA): 5f473075
PASSADA B (pelo ASSUNTO %s apenas):                5f473075
TOTAL_A=1   TOTAL_B=1
FACE 1 — SOBRE-CAPTURA (A menos B): NENHUMA (os dois conjuntos sao IGUAIS)
FACE 2 — SUB-CAPTURA: NENHUM vizinho alheio no range (medido A MAO, por diferenca)
FACE 3 — O AUTOR: MB.ECF-100376 x3715 · ECF Dev x2008 · Dev ECF x57 · root x5 · ecfmaycon x4
FANTASMAS=0   VIOLACOES=0
ISCA (+) contagens-193-03.txt: 198  0        <- o range NAO esta cego
ISCA (-) app/NaoExiste9z7.php: (vazio)       <- e por isso a isca (+) e obrigatoria
Arquivos RASTREADOS modificados na arvore: (vazio)   (por git diff HEAD, nunca git status)
27 stashes: APENAS CONTADOS
```

**Os únicos 3 arquivos que os meus commits tocam** — e os três estão no `files_modified` do plano:

```
app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php
tests/Feature/Publicador/Aprovacao/ReconciliarPublicacaoDoPortaoTest.php
tests/gates/contagens-193-03.txt
```

### AS TRÊS FACES

**FACE 1 — sobre-captura.** `git log --grep` lê a **mensagem inteira**: um commit de outra wave que
**cite** `(193-03)` no corpo entraria sem ter tocado nada meu. **Não se concretizou** (A = B = 1), mas
foi **medido**, não suposto.

**FACE 2 — sub-captura. ⚠️ NÃO se concretizou nesta wave, e isso é notícia:** nas duas waves
anteriores ela se concretizou (o `193-01` perdeu 19 min de medição por causa do `56ade5f0`, e o
`193-02` mediu **três** vizinhos invisíveis ao assunto, um deles tocando `ROADMAP.md` e a própria
`193-REGUA.md`). Aqui a varredura por diferença (`b222df47..HEAD` menos os meus) devolveu **vazio**:
**nenhuma outra sessão commitou durante esta wave**. ⚠️ **Medido, não presumido** — e é por isso que a
baseline longa pôde ser medida com o HEAD parado nas duas pontas.

**FACE 3 — o autor.** **CINCO** identidades; o `%an` dos meus commits é o **mesmo** das outras
sessões. ➡️ O autor **não discrimina**, e é por isso que o território saiu do **assunto**.

### Os vizinhos intocáveis, medidos por RANGE (nunca pela árvore)

| alvo | prova |
|---|---|
| `app/Services/Mlb/Publicacao/MlPublicacaoService.php` (**PUB-03**) | `test -e` **OK** e `numstat` **VAZIO** |
| `app/Services/Publicador/PublicacaoService.php` (⚠️ o `193-05` exige vazio) | `numstat` **VAZIO**; o `POST /items` está em `:442`, intocado |
| `app/Services/Publicador/Fila/` · `PublicarRascunhoJob` · `app/Support/Publicador/` | `numstat` **VAZIO** |
| `app/Services/Publicador/Agente/` (⚠️ outra sessão) | `numstat` **VAZIO** |
| os **6** serviços da 186 fora do gancho · `Aprovacao/{Fila,Painel}/` | `numstat` **VAZIO** |
| ⚠️ **`AndamentoDaOfertaService.php`** | `numstat` **VAZIO** — ⚠️ **esta wave não o alterou**; a mutação `m1` o tocou e a restauração foi conferida por `sha256` contra `git show HEAD:` |
| `app/Services/Creative/` · `app/Mcp/` | `numstat` **VAZIO** |
| **todo `resources/js/`** · `routes/` · `database/migrations/` | `numstat` **VAZIO** (⚠️ **nenhuma migration, e `artisan migrate` NÃO foi rodado** — há migration alheia rastreada na árvore) |
| `tests/js/estrutura-meus-anuncios.test.js` (**D-11/D-22**) · `publicador-header-zindex-ordem.test.js` | `numstat` **VAZIO** |
| `contagens-186-04.txt` · `contagens-186-09.txt` · `contagens-188-05.txt` | `numstat` **VAZIO** |
| `.planning/{ROADMAP,REQUIREMENTS-v27,STATE}.md` (**GATE-07**) · `193-REGUA.md` · `193-COBERTURA.md` · `193-BASELINES.md` · os `193-NN-PLAN.md` | `numstat` **VAZIO** |
| `package.json` · `package-lock.json` · `composer.json` · `composer.lock` | `numstat` **VAZIO** (nenhum install) |

⚠️ `state.advance-plan` **não** foi rodado. `pint` **não** foi rodado. Nenhum worktree. Nenhum
`git stash`/`reset`/`checkout`/`clean`/`--amend`. ⚠️ **Os untracked alheios** (`CompanyPortfolioAccessTest.php`,
`tests/js/.pub-lote187-inertia-stub-*.mjs`) **não são meus e não foram tocados**.

---

## §12 — A CAMPANHA DE MUTAÇÃO: 13 MUTAÇÕES, **ZERO SOBREVIVENTES**

**Forma cumprida ponto por ponto:** commit **ANTES** (`5f473075`); `cp -p` para **fora** do repo;
script com nome que carrega o plano (**`mutar-193-03.sh`**, `sha256 ca286d6533940118`, e o trocador
`trocar-193-03.php`, `sha256 33ab085654accaa6`, **os dois impressos antes de qualquer veredicto** — o
scratchpad é COMPARTILHADO e um `mutar.sh` genérico já foi sobrescrito no meio de uma campanha; o hash
do script foi reconferido **depois** e está idêntico); ocorrência **travada em 1 ANTES** por string
**exata** (o trocador **aborta** com 0 ou 2+); **qual linha mudou** impressa depois; aplicação provada
por **divergência de `sha256`**; restauração **DA CÓPIA** em `trap EXIT`; `sha256` final contra
`git show HEAD:` com `tr -d '\r'` nos dois lados. ⚠️⚠️ **`git checkout` NUNCA.**

### ⚠️ O `# tests` do mutado CONFERIDO contra o do pristino

```
PRISTINO:  reconciliar-publicando-e-publicado   3 passed (38)
           reconciliar-falha-e-clausula         3 passed (50)
           reconciliar-nao-toca-em-voo          3 passed (39)
           pub06-sem-regressao                  2 passed (22)
```

| # | mutação | arquivo · linha | veredicto |
|---|---|---|---|
| **m1** | (i) `aoAprovar()` deixado **vazio** | porta `:368` | **MORREU** `2 failed, 1 passed` (**3** = pristino) |
| **m2** | (ii) `enfileirar()` **sempre agenda**, nunca anexa | portão `:374` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m3** | (iii) `mover(PUBLICADO)` **antes** de gravar o MLB | portão `:860` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m4** | (iv) o MLB lido **sem filtrar** `listing_type_id` | portão `:998` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m5** | (v) `last()` em vez de `first()` por `indice` | portão `:856` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m6** | (vi) a cláusula concatenada **SEMPRE** | portão `:915` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m7** | (vii) a cláusula **NUNCA** | portão `:921` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m8** | (viii) `UNKNOWN` **fora** da lista de em-voo (tratado como terminado) | portão `:325` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m9** | (ix) `mover(FALHA)` com motivo **genérico** | portão `:940` | **MORREU** `2 failed, 1 passed` (**3**) |
| **m10** | (x) a **guarda de tipo** da modalidade removida | portão `:1013` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m11** | (xi) a guarda `is_array($issues)` removida (decisão 24) | portão `:1060` | **MORREU** `1 failed, 2 passed` (**3**) |
| **m12** | (xii) a **de-duplicação** do histórico desligada | portão `:1111` | **MORREU** `1 failed, 1 passed` (**2** = pristino) |
| **m13** | (xiii) a **decisão 24** desligada (avisos não gravados) | portão `:1030` | **MORREU** `1 failed, 2 passed` (**3**) |

➡️ **Em TODAS as 13 o total de testes bate com o pristino**, logo o arquivo **CARREGOU** em todas e
cada morte é **da asserção**, não de falha de boot. ⚠️ **É exatamente a lição que a `m5` do 193-02
deixou:** lá a mutação morreu por o container **não resolver a dependência** — *"mutação que morre pelo
motivo errado não prova nada"*.

```
─── RESTAURACAO (DA COPIA, nunca por git checkout) ───
  OK   app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php  (2c5371b6f28e7fdc == git show HEAD:)
  OK   app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php  (bb313dc45f84d13d == git show HEAD:)
```

⚠️ **ZERO sobreviventes**, logo **nenhuma investigação de sobrevivente** nesta wave — e **isso não é
mérito do acaso**: as três sondas que teriam deixado sobrevivente (a cláusula sem o caso do `CREATED`
sem MLB, a sonda de rede larga, o piso de `mover()`) foram **consertadas antes**, por eu ter rodado
cada asserção perguntando *"o que precisaria estar errado no mundo para ela falhar?"*.

---

## §13 — AS BASELINES, RE-MEDIDAS, COM O HEAD CONFERIDO NAS DUAS PONTAS

```
PARTIDA:   b222df47
COMMIT:    5f473075   (o de docs vem depois)
BUILD:     exit 0 · 94 s · "built in 1m 27s"      HEAD 5f473075 nas DUAS pontas
MANIFEST:  500 entradas / 204 paginas   (controle Aprovacao.jsx PRESENTE, isca NaoExiste9z7.jsx AUSENTE)
JS:        6031 / 6030 pass / 1 fail / 0 skipped  (exit REAL 0)   HEAD 5f473075 nas DUAS pontas
PHP:       2675 / 24684 / 0                       (exit REAL 0, 929 s)   HEAD 5f473075 nas DUAS pontas
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

### ⚠️ O isolamento, PROVADO POR LINHA DE COMANDO (não por nome de processo)

Contar `node.exe` **por nome** daria **16** e pareceria contaminado. ➡️ Inspecionei a **linha de
comando** de cada um (`Get-CimInstance Win32_Process`): são o Adobe Creative Cloud, dois runtimes do
Codex, servidores MCP (`digisac-ecf.mjs`, `mcp-remote` subindo por `npx-cli.js`) e workers do Codex.
**ZERO** era `node --test`, `vite` ou `npm run`. E `php.exe` estava em **0** imediatamente antes de
cada rodada de PHP. ➡️ **DECLARADO: não houve build nem outra suíte concorrente em nenhuma das
medições.** ⚠️ `0 skipped` no JS porque o `build` rodou **antes** e **sozinho**.

### ⚠️⚠️ A SUÍTE PHP **COMPLETA** NÃO É MEDÍVEL NESTA MÁQUINA

`artisan test` sem filtro **aborta com Fatal de memória** em
`vendor/.../Illuminate/Http/Testing/FileFactory.php:87`, a ~6% de 10.653 testes, e
`-d memory_limit=-1` **não resolve** (o estouro é em processo **FILHO**). ➡️ O número `PHP:` acima é o
**RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`, o `--suite php`), e **eu digo isso**
em vez de deixar o leitor supor que a suíte inteira passou.

### ⚠️ O manifest: o invariante é *"a 193 não mexe no manifest"*, e não um número

```
git diff --numstat b222df47..HEAD -- resources/js/   →  (VAZIO)
git diff --numstat 5f473075~1..5f473075 -- resources/js/ package.json package-lock.json vite.config.js  →  (VAZIO)
```

➡️ **500 / 204 é o mesmo que o `193-02` mediu, e o `+2/+1` sobre o 498/203 do `193-01` é ALHEIO**
(`resources/js/Pages/Mlb/Publicador/Agente.jsx`, **criado por `56ade5f0`**, de outra sessão). ⚠️ **Não
subi número em lugar nenhum**, e **não editei o `193-BASELINES.md`** (artefato do `193-01`; dono:
usuário/orquestrador). ⚠️ **E o controle que importa rodou:** `Aprovacao.jsx` **PRESENTE** e a isca
`NaoExiste9z7.jsx` **AUSENTE** — *"página fora do manifest SOME"* é o invariante real.

### A aritmética do Δ — cada parcela atribuída pelo commit que CRIOU/ALTEROU o arquivo

⚠️ **Por `git show --name-only` do commit, NUNCA por range** (o range engloba os meus próprios
commits, e a 189-07 caiu exatamente nisso).

| linha | baseline do `193-02` | medido agora | Δ | atribuição |
|---|---|---|---|---|
| **BUILD** | exit 0 · 47 s | **exit 0 · 94 s** | +47 s | ⚠️ **INFORMATIVO** — tempo de build nesta máquina não é invariante (cache do Vite, carga da máquina). O que importa é o `exit 0` |
| **MANIFEST entradas / páginas** | 500 / 204 | **500 / 204** | 0 | ✅ **IDÊNTICO** — e provado por `numstat` vazio, não só pelo número |
| **JS testes** | 6031 | **6031** | 0 | ✅ **IDÊNTICO.** Os meus commits tocam **ZERO** teste JS |
| **JS falhas** | 1 | **1** | 0 | **pré-existente, Fase 87**: *"Características secundárias nasce recolhido (é o grupo que mais infla)"* — mesma **semântica**, nunca por número de linha. ⚠️ **NÃO corrigida** |
| **PHP testes** (o RECORTE) | 2660 | **2675** | **+15** | **MEU, e a aritmética fecha na vírgula:** os **15** métodos de `ReconciliarPublicacaoDoPortaoTest.php`, criado pelo meu `5f473075`. ⚠️ **Nenhum teste PHP alheio entrou no meu range** — a `FACE 2` do território veio **vazia** |
| **PHP falhas** | 0 | **0** | 0 | ⚠️ **ZERO falhas: não há NADA a atribuir por execução isolada, e por isso nenhum `REF_PHP_FALHAS` foi criado** — um limiar de falhas aceito seria a porta dos fundos que o harness existe para fechar. ⚠️ As falhas que a `193-BASELINES.md` §4 prevê (`CalcularFaixaTest`, `CompanyServiceTypeTest`, `Phase39`) ficam **FORA** do recorte, e as de `CockpitDaContaTest`/`SincronizaPortalCompletoTest` **passaram** |
| **PHP asserções** | 24.471 | **24.684** | +213 | ⚠️ **INFORMATIVO**, não comparável: o harness tem `REF_PHP_TESTS` e **não** tem referência de asserções, porque casos de fronteira de dia percorrem ramos diferentes conforme a hora |
| **PROTÓTIPO** | 1432 l. / `68624883ce35547e` | **idem** | 0 | ✅ |

---

## §14 — `--autoteste`: 20 CASOS, 0 DIVERGÊNCIA (colado integralmente)

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

### ⚠️ A isca do 1º argumento, medida AO VIVO (não citada de briefing)

```
--- com '193-03' (CERTO) ---
exit=0
--- com '03' nu (ERRADO) ---
EXIT_REAL=2
ABORTOU: id 'pub06-sem-regressao' nao esta em .../tests/gates/contagens-186-03.txt (manifesto incompleto)
SIM: tests/gates/contagens-186-03.txt existe
```

⚠️ **No meu caso ele abortou ALTO porque nenhum id meu existe lá.** Se eu tivesse batizado um id com
nome que a 186 usa, ele rodaria o gate da fase **ERRADA em silêncio** — e `contagens-186-03.txt`
**existe**, o que torna o risco real e não teórico.

### O `exit` REAL do harness, em cada id — capturado por `$?` **na linha seguinte**

⚠️ **Nunca depois de um pipe:** o `193-01` pegou o harness saindo **1** enquanto a notificação do
background dizia *"exit code 0"*, porque o último comando do composto era um `tail`. **Quatro waves já
caíram nisso.**

```
reconciliar-publicando-e-publicado   EXIT_REAL=0   Tests: 3 passed (38 assertions)
reconciliar-falha-e-clausula         EXIT_REAL=0   Tests: 3 passed (50 assertions)
reconciliar-nao-toca-em-voo          EXIT_REAL=0   Tests: 3 passed (39 assertions)
bloqueios-e-terminal                 EXIT_REAL=0   Tests: 3 passed (31 assertions)
pub06-sem-regressao                  EXIT_REAL=0   Tests: 2 passed (22 assertions)
bijecao-manifesto-193-03             EXIT_REAL=0   Tests: 1 passed (33 assertions)
```

### Os gates ALHEIOS — contagem **IDÊNTICA**, e nenhum deles mudou por causa do meu trabalho

```
186-04 unica-porta               EXIT_REAL=0   Tests:  1 passed (43 assertions)   [IDENTICO]
186-04 oferta-do-portao          EXIT_REAL=0   Tests:  3 passed (67 assertions)   [IDENTICO]
186-09 transversais              EXIT_REAL=0   Tests: 12 passed (259 assertions)  [IDENTICO]
186-09 andamento-um-lugar-so     EXIT_REAL=0   Tests:  1 passed (21 assertions)   [IDENTICO]
186-09 sem-segundo-passo         EXIT_REAL=0   Tests:  1 passed (53 assertions)   [IDENTICO]
188-05 aprovar-e-enfileirar      EXIT_REAL=0   Tests:  3 passed (85 assertions)   [IDENTICO]
188-05 tentar-de-novo            EXIT_REAL=0   Tests:  2 passed (32 assertions)   [IDENTICO]
188-05 ausencia-de-segundo-passo EXIT_REAL=0   Tests:  1 passed (17 assertions)   [IDENTICO]
193-01 schema-sem-migration      EXIT_REAL=0   Tests:  3 passed (50 assertions)   [IDENTICO]
193-02 enfileirar-anexa-ou-agenda      EXIT_REAL=0   Tests: 3 passed (71 assertions)  [IDENTICO]
193-02 guarda-modalidade-irma          EXIT_REAL=0   Tests: 2 passed (25 assertions)  [IDENTICO]
193-02 aprovar-enfileira-atomico       EXIT_REAL=0   Tests: 3 passed (36 assertions)  [IDENTICO]
193-02 ausencia-de-segundo-passo-193   EXIT_REAL=0   Tests: 1 passed (23 assertions)  [IDENTICO]
193-02 bijecao-manifesto-193-02        EXIT_REAL=0   Tests: 1 passed (32 assertions)  [IDENTICO]
```

⚠️⚠️ **OS DOIS IDS DA `186-04` FICARAM INTACTOS (43 e 67), E ISSO ERA O ESPERADO — com a razão
medida:** a camada B deles é **por glob** com **2 asserções por arquivo**, e **este plano não cria
arquivo** em `app/Services/Publicador/Aprovacao/` (ele acrescenta métodos ao serviço que o 193-02
criou). ➡️ **Se eles tivessem SUBIDO, era PARADA** — significaria que nasceu arquivo que eu não
declarei. ⚠️ **O inverso da trava do 193-02**, e por isso ela foi conferida nas duas leituras.

⚠️⚠️ **E os `193-02` ficarem idênticos NÃO era óbvio:** eu **alterei** o `recusar()` daquele plano
(a de-duplicação do histórico). Medido: as asserções deles são de **presença** (`assertContains`) e
**coletoras**, não de **contagem de eventos**, então a de-duplicação não as move — e a razão é
exatamente o **FURO 1** que aquele executor consertou na raiz. ➡️ **O conserto dele pagou a minha
wave.**

### A bijeção, nos TRÊS sentidos, como teste **durável**

`SEM_ID=0` · `ORFAOS=0` · `IDS_REPETIDOS=0` · `HOMONIMOS=0` (varrendo toda a suíte), **sufixo
`_193_03` em TODOS os 15 métodos** por asserção coletora, **nenhum id com mais de TRÊS métodos**, e
`tests=` conferido contra o número de métodos de cada filtro. ⚠️ O **expansor de alternância** é
iscado nas **duas** direções **antes de valer**, e a cegueira da regex ingênua foi **remedida aqui**
(ela casa **ZERO** em `test_(a|b|c)` e **1** em `test_abc`). ⚠️ O **homônimo** também tem as duas
iscas: superstring de um nome meu **é** pega, e `test_aprovar_e_enfileirar_188_05` (real, de outra
fase) **não** é acusado.

⚠️ **A isca do 1º argumento** está escrita no cabeçalho do manifesto, com a medição do código
(`verificar-testes.sh:120-129`): **`193-03`**, nunca `03` — que resolveria `contagens-186-03.txt`, a
fase **errada**, **em silêncio**.

---

## §15 — ⚠️⚠️ OS FUROS QUE EU ACHEI NAS MINHAS PRÓPRIAS SONDAS

**Todos achados por RODAR, não por ler. Nenhum consertado por elevação de régua.**

### FURO 1 — ⚠️ a chave de `andamento` no contexto do log (violação REAL de gate alheio)

A minha sonda pegou **antes do gate**: `'andamento' => $oferta->andamento` no `Log::error` do
`reconciliar()` casaria a sonda larga do **186-09 `andamento-um-lugar-so`**, que varre `app/` inteiro.
➡️ **Conserto de raiz: não expor andamento** (nem entrar na lista de expositores, nem renomear a
chave). Ver §8.

### FURO 2 — ⚠️ a sonda de `PUT` era MAIS LARGA que o invariante

Acusou **quatro** arquivos legítimos de `Alavancas/` (`PUT /seller-promotions/*`). ➡️ Reancorada em
*"nenhum PUT cujo caminho **COMEÇA** em `/items`"*, com igualdade de conjunto e as duas iscas. ⚠️ E o
segundo furo dentro do primeiro: a sonda `'PUT'\s*,\s*'([^']*)'` **lia a lista de métodos**
(`['GET','POST','PUT','DELETE']`) e devolvia `DELETE` como se fosse endpoint — exigir a **barra** no
começo do caminho conserta. Ver §7.

### FURO 3 — ⚠️ a sonda de rede do portão era larga demais (deu **7**)

`/Http::|->get\(|->post\(|dispatch\(/` casava `->get(['id', ...])`, `$porAlvo->get(...)` e
`groupBy(...)->get($tipo)` — **Eloquent e Collection, não HTTP**. ➡️ Um gate assim **reprovaria pelo
próprio trabalho correto**. Reancorada nas três formas que importam (`Http::`,
`ClienteMlPublicador`, `dispatch(`), **com isca sintética** que acha as três.

### FURO 4 — ⚠️ piso de `->mover(` em número que muda legitimamente

Eu havia escrito `assertGreaterThanOrEqual(4, substr_count($portao, '->mover('))` e **são 3**.
➡️ Trocado por **igualdade de CONJUNTO dos destinos** (`[FALHA, PUBLICADO, PUBLICANDO]`), que prova o
invariante de verdade (*"as três transições passam pela porta"*) e **não** depende de quantos
call-sites existem.

### FURO 5 — a asserção da cláusula seria **quase-vazia** com só o caso PARCIAL

Ver §5: a outra oferta do PARCIAL tem `erro_publicacao` **NULO**. ➡️ Predicado público medido nas duas
direções **mais** o caso real do `CREATED` sem MLB legível.

### FURO 6 — payload inválido mede o VALIDADOR, não a trava de edição

Duas rotas devolviam `422 (sem regra)`. ➡️ Payloads válidos, e o `422` passou a ser o da trava com o id
estável da regra.

### FURO 7 — `->json(...)` em resposta de REDIRECIONAMENTO falha como asserção

A minha isca reprovou por *"a resposta não é JSON válido"*, não pelo invariante. ➡️ A isca passou a ser
o **STATUS** (`422` × `302`).

### FURO 8 — a fixture do item vivo batia no **unique** do banco

Criar um segundo item `publicando` para um produto que a aprovação **já** enfileirou estoura
`UniqueConstraintViolationException` (`pubfilai_produto_ativo_uq`). ➡️ Para status VIVO o caminho
honesto é **mover o item que existe**, que é o que a fila faz na vida real (`itemDaAprovacao()`).

### FURO 9 — `alvo_id` é `NOT NULL` + UNIQUE

Anular `alvo_id` para simular *"modalidade ilegível"* deu `QueryException`. ➡️ A modalidade fica
ilegível **pelo próprio alvo** (`listing_type_id` vazio), que é o que o mundo real produz — a oferta é
a extensão **1:1** do alvo.

---

## §16 — ⚠️ CRITÉRIOS DE ACEITAÇÃO DO PLANO QUE EU RODEI E ACHEI VAZIOS OU FRACOS

| item do `<acceptance_criteria>` | veredicto |
|---|---|
| *"`grep -c "'andamento' =>"` lido sem comentário é 0"* | ⚠️ **FRACO POR CONSTRUÇÃO:** `grep -c` conta **LINHAS**, não ocorrências. ➡️ `substr_count` sobre o código limpo por `token_get_all`. **E o valor medido só é 0 porque eu TIREI a chave do log** (§15, furo 1) |
| *"o caso do PARCIAL prova a cláusula em UMA oferta e ausente na outra"* | ⚠️⚠️ **A SEGUNDA METADE É QUASE-VAZIA:** a outra oferta vai a `publicado` e tem `erro_publicacao` **NULO**. ➡️ Substituída pelo predicado público nas duas direções **mais** o caso do `CREATED` sem MLB legível (§5) |
| *"o caso do `UNKNOWN` prova andamento inalterado e zero requisição"* | ✅ roda — **mas sozinho seria vazio**: sem o `CREATED` do mesmo tipo ao lado, *"não se moveu"* é verdade por não haver nada para mover. A fixture tem os dois |
| *"a asserção da cláusula é por igualdade de string congelada, com ≥ 4 iscas"* | ✅ cumprido com **5** iscas |
| *"os 8 destinos saindo de `publicado` recusados, por igualdade de CONJUNTO"* | ✅ cumprido — e a **contagem de 8** é asseverada antes, senão a enumeração poderia encolher em silêncio |
| *"`mover(PUBLICADO)` sem MLB … nada gravado (nem `updated_at`)"* | ✅ cumprido comparando **a linha inteira**, byte a byte |
| *"PUB-06 por contagem de ocorrências … e uma asserção coletora"* | ✅ cumprido, com **piso** por invariante (igualdade exata seria âncora em número que cresce legitimamente) e com a ponta CRU × limpo provando que o leitor lê |
| *"`Http::fake` + `preventStrayRequests()` em toda a suíte"* | ✅ cumprido; e os casos que **só** reconciliam asseveram `Http::recorded()` **VAZIO** — mais forte que a lista de leituras autorizadas |
| *"≥ 10 mutações, todas MORTAS"* | ✅ **13 mutações, 13 mortas**, com o `# tests` de cada uma conferido contra o pristino |
| *"`186-04 unica-porta` … com contagem IDÊNTICA (mudou → PARE)"* | ✅ **43 e 67, idênticos** — e a razão está medida: este plano **não cria arquivo** no glob |
| **gate de ordem `dropForeign → dropIndex`** (armadilha 1553) | ⚠️ **NÃO ENTROU, e é declarado:** esta fase não dropa índice nenhum — seria gate sobre **ZERO casos**. O `193-01` já havia medido isso e eu **não** o recriei |

---

## §17 — ⚠️⚠️ ONDE O BRIEFING DIVERGIU DA MINHA MEDIÇÃO (a medição ganha, e eu declaro)

| o briefing disse | eu medi | consequência |
|---|---|---|
| *"não existe NENHUM `PUT /items/{id}` em todo o Publicador"* (régua §6, `PERGUNTA-193-B`) | ⚠️ **existem TRÊS `PUT`** no Publicador — todos `/seller-promotions/*`, em `Alavancas/`. A **afirmação sobre `/items` está certa**; a sonda ingênua que a "provaria" **não** | o gate ancora no **começo** do caminho, com igualdade de conjunto dos dois caminhos reais. ⚠️ Se alguém escrever a sonda por substring, ela acusa `/seller-promotions/items/` |
| *"o reconciliador … `Log::warning` e NADA mais"* para `publicando` sem `fila_item_id` | ✅ confirmado e implementado | nada a fazer |
| *"o MLB sai do `listing_type_id` do alvo com a MESMA regra de `cadastrarNaRegua()`"* | ✅ confirmado em `PublicacaoService:691`-`:716` | nada a fazer |
| *"`mover(FALHA)` não toca `versao`/`versao_aprovada`/`aprovado_por`/`aprovado_em`"* | ✅ confirmado no `$mudancas` de `mover()` **e** medido por reconsulta crua ao banco | nada a fazer |
| *"a cláusula … com o caso PARCIAL como isca invertida"* | ⚠️ **a isca do PARCIAL é fraca por construção** (§16) | entrou o caso do `CREATED` sem MLB legível, que é a isca invertida **de verdade** |
| *"`contagens-186-04.txt` já remedido pelo 193-02; a contagem tem de ficar IGUAL"* | ✅ **43 / 67, igual** | nada a fazer. ⚠️ E a trava é **inversa** à do 193-02: lá "se não subir, PARE"; aqui "se subir, PARE" |
| *"os dois ids do `188-05` … se o SEU reconciliador fizer alguma requisição, a asserção quebra"* | ⚠️ **ele não faz NENHUMA** — `Http::recorded()` vazio em todos os meus casos de reconciliação | **nada foi acrescentado** à lista constante daqueles gates, e **nada foi afrouxado** |
| *"`mysqld` local está DE PÉ (PID 30496)"* | ✅ irrelevante para a prova: `phpunit.xml:27-28` fixa `DB_CONNECTION=sqlite` e `:memory:` | ⚠️ **`artisan migrate` NÃO foi rodado** (esta fase não tem migration, e há migration alheia rastreada na árvore) |
| *"o HEAD se moveu TRÊS vezes durante a wave anterior — REMEÇA"* | ⚠️ **desta vez NÃO se moveu** (`FACE 2` vazia) | a baseline longa foi medida com o HEAD **parado** nas duas pontas |
| *"MANIFEST 500/204"* | ✅ **500 / 204** | e o invariante foi provado por `numstat` **vazio**, não pelo número |

---

## §18 — AS PERGUNTAS DESTA WAVE

| # | pergunta | estado | dono |
|---|---|---|---|
| **193-E** | `ciente = true` no agendamento | ✅ **RESPONDIDA pela decisão 24** e **implementada com o acréscimo**: o clique vale como ciência **e** os avisos vão ao histórico | — |
| **193-G** | a irmã aprovada sem `fila_item_id` | ✅ **RESOLVIDA e provada** nesta wave, pelo caminho do ANEXO no reconciliador | — |
| **193-B** | editar a FOTO de anúncio **já no ar** (decisão 13) | ⚠️ **MEDIDA e NÃO implementada**: `PUBLICADO => []` terminal por estrutura (8 destinos recusados) e **nenhum `PUT /items/{id}`**. Recomendação: **diferir** para fase própria | **USUÁRIO** |
| **193-C** | o toast *"Publicado e confirmado"* nunca dispara sozinho | ⚠️ **CONFIRMADA:** sem polling, ele só apareceria num recarregamento. Esta fase assevera a **AUSÊNCIA** dele sem MLB | Fase 194 / fase de tela |
| ⚠️ | o `186-09 sem-segundo-passo` **não pega** a forma acentuada com espaço | ⚠️ **ACHADO DO 193-02, reconfirmado e NÃO consertado** (Fase 186 em produção) | **USUÁRIO / orquestrador** |
| ⚠️ **193-H** | **NOVA, minha:** a de-duplicação do histórico é contra a **ÚLTIMA** linha, não contra todo o histórico | ⚠️ **DECLARADA, com o custo escrito.** O mesmo motivo voltando **depois** de outro fato é informação nova e **entra**; o mesmo motivo repetido em sequência **não**. ➡️ O efeito é que uma oferta que alterna entre dois motivos de recusa escreve **duas linhas por ciclo**. A alternativa (deduplicar contra todo o histórico) **apagaria** a diferença entre *"foi recusada"* e *"foi recusada de novo depois de alguém mexer"*. ⚠️ Se o usuário preferir a linha do tempo mais enxuta, isso é **decisão de produto** | **USUÁRIO** |

---

## §19 — O QUE ESTE PLANO NÃO FEZ (é a régua, não omissão)

- ❌ **nenhuma migration**, ❌ **`artisan migrate` não foi rodado**, ❌ **nenhuma coluna nova**
- ❌ **nenhum `.jsx`** (`numstat` vazio em **todo** `resources/js/`), ❌ **nenhuma rota nova**,
  ❌ **nenhum estado novo**, ❌ **nenhum rótulo novo**, ❌ **nenhum 7º toast**
- ❌ **nenhum feature-flag** de "ligar publicar" (a trava é `ContasLiberadas`, fail-closed)
- ❌ **não alterei** `PublicacaoService`, `FilaPublicacaoService`, `MlPublicacaoService`,
  `AgendadorDaFila`, `ResumoRapidoService`, `PreparadorDaAprovacao`, `AndamentoDaOfertaService`, nem
  qualquer outro serviço/`.jsx` das Fases 186-189
- ❌ **não toquei** `contagens-186-04.txt`, `contagens-186-09.txt` nem `contagens-188-05.txt`
- ❌ **não toquei** `ROADMAP.md`, `REQUIREMENTS-v27.md`, `STATE.md`, `193-REGUA.md`,
  `193-COBERTURA.md`, `193-BASELINES.md` nem os `193-NN-PLAN.md`; ❌ `state.advance-plan` não rodou
- ❌ **nenhum deploy, push, VPS ou chamada à API real do ML** · ❌ nenhum `npm install`/
  `composer require` · ❌ nenhum `pint`, `stash`, `reset`, `checkout`, `clean`, `--amend`, worktree
- ⚠️ **NÃO AFIRMO ter visto tela renderizada** nem publicação real: não há navegador e nenhuma empresa
  tem `ml_token` de verdade. **A prova desta wave é teste com `Http::fake` + `preventStrayRequests()`.**
- ⚠️ **O critério 10 nasce RESSALVA** (medir o caminho completo numa oferta real, em produção, conta
  459): é `checkpoint:human-verify` do `193-05`. ⚠️ **E ele ficou mais perto:** o
  `PreparadorDaAprovacao` (de outra sessão) **cria** oferta, então o critério passa a ser executável
  **depois do PILOTO DO AGENTE**, não só depois da Fase 194 — mas **nenhuma loja tem o agente
  ligado**, logo `pub_aprovacao_ofertas` **segue vazia em produção**

---

## §20 — Deviações do plano

### Auto-corrigidas (Regra 1 — defeito no meu próprio artefato, achado por MEDIR)

1. **[Regra 1] A chave de `andamento` no contexto do log** — violaria o gate alheio `186-09`.
   Conserto de raiz: não expor andamento. Commit `5f473075`.
2. **[Regra 1] Sonda de `PUT` mais larga que o invariante** (4 arquivos legítimos acusados) e a sonda
   lendo a lista de métodos como endpoint. Reancorada no começo do caminho. Commit `5f473075`.
3. **[Regra 1] Sonda de rede larga demais** (`->get(` é Eloquent/Collection) — deu 7. Reancorada nas
   três formas que importam, com isca. Commit `5f473075`.
4. **[Regra 1] Piso de `->mover(` em número que muda** — trocado por igualdade de conjunto dos
   destinos. Commit `5f473075`.
5. **[Regra 1] A isca da cláusula seria quase-vazia** com só o caso PARCIAL — entrou o predicado
   público e o caso do `CREATED` sem MLB legível. Commit `5f473075`.
6. **[Regra 1] Payload inválido medindo o validador** e **`->json()` em redirecionamento falhando como
   asserção** nas rotas. Commit `5f473075`.
7. **[Regra 1] A fixture batia no unique da fila** e **`alvo_id` é NOT NULL + UNIQUE**. Commit
   `5f473075`.

### Deviação de escopo (declarada, por decisão do usuário)

8. **A DECISÃO 24 não estava no plano** — ela chegou como decisão do usuário (ROADMAP, commit
   `b222df47`), atribuída a este plano. Implementada, provada nas duas direções e documentada.
   ⚠️ **Nenhum serviço das Fases 186-189 foi alterado para isso**, o portão **continua sem ler a
   `ConferenciaService`** e o **painel não mudou** — por isso **não houve parada**.
9. **As duas tarefas num commit só**, com a razão declarada (§1).

### Nenhuma Regra 2, 3 ou 4 foi acionada

Nada foi instalado. Nenhuma decisão arquitetural tomada por conta própria — a única que apareceu
(`193-H`, a semântica da de-duplicação) está **devolvida**, com o custo de cada saída escrito.

---

## §21 — Known Stubs

**Nenhum.** Não há valor vazio hardcoded, texto de espera reservado nem componente sem fonte de dados.
⚠️ O que existe e **está declarado como fronteira, não como stub**, é que o `reconciliar()` ainda não
tem **quem o chame a cada minuto**: o comando `publicador:portao-publicacao` e o bloco do
`routes/console.php` são do **193-04**, por território do plano. O método é público e provado; o
agendamento é da wave seguinte.

## §22 — Self-Check: PASSED

Arquivos afirmados, conferidos por `test -f` (⚠️ **`test -e` em CADA caminho citado** — são **dezoito**
fantasmas nesta milestone, e o fechamento da 189 pegou **6 de 32**, todos de NOMES):

```
FOUND:   app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php          (1138 l. · min_lines 380)
FOUND:   tests/Feature/Publicador/Aprovacao/ReconciliarPublicacaoDoPortaoTest.php (1583 l. · min_lines 300)
FOUND:   tests/gates/contagens-193-03.txt
FOUND:   .planning/phases/193-…/193-03-SUMMARY.md
FOUND:   design_handoff_publicador/Aprovacao.dc.html          <- a autoridade do literal (:956)
FOUND:   app/Services/Publicador/PublicacaoService.php        <- o POST /items esta em :442
FOUND:   app/Jobs/Publicador/PublicarRascunhoJob.php
FOUND:   app/Support/Publicador/ContasLiberadas.php
FOUND:   app/Services/Mlb/Publicacao/MlPublicacaoService.php  <- EXISTE (o briefing estava certo)
FOUND:   app/Services/Publicador/Agente/PreparadorDaAprovacao.php   <- em Agente/, NAO em Aprovacao/
FOUND:   app/Services/Publicador/Fila/ResumoRapidoService.php <- em Fila/, NAO em Aprovacao/Fila/
FOUND:   app/Services/Publicador/Alavancas/RequisicaoMl.php
FOUND:   app/Services/Publicador/Alavancas/Acoes/AlterarNoConvite.php
FOUND:   resources/js/Components/Mlb/Publicador/Lote/DialogoAgendar.jsx  <- a caixa "Estou ciente" (LOTE)
MISSING: (nenhum)
```

Commits afirmados, conferidos por `git log --oneline --all | grep`:

```
FOUND:   b222df47  feat(193): decisao 24 — o clique vale como ciencia…   <- a PARTIDA
FOUND:   5f473075  feat(193-03): o reconciliar() do portao…              <- o commit do codigo
MISSING: (nenhum)
```

`key_links` do plano, conferidos no código **sem comentário**:

```
PublicacaoDoPortaoService -> AndamentoDaOfertaService  via `mover(`          : 3 chamadas
   (⚠️ e os DESTINOS por igualdade de conjunto: [FALHA, PUBLICADO, PUBLICANDO])
PublicacaoDoPortaoService -> PubPublicacaoItem         via `listing_type_id` : 6 ocorrencias
   (⚠️ `PubPublicacaoItem::` = 5: os 3 de em-voo + CREATED + FAILED)
```

## §23 — Threat Flags

**Nenhuma superfície nova fora do `<threat_model>`.** Nenhum endpoint de rede novo (**nenhuma rota
criada**: `routes/` com `numstat` vazio), nenhum caminho de autenticação novo, nenhum acesso a arquivo
novo e **nenhuma mudança de schema** (`database/migrations/` com `numstat` vazio por range).

`T-193-SC` (npm/composer installs) conferido por `numstat` **VAZIO** no range `b222df47..HEAD` em
`package.json`, `package-lock.json`, `composer.json` e `composer.lock`.

As mitigações do registro desta wave, cada uma com a prova:

| ameaça | mitigada por |
|---|---|
| `T-193-21` `publicado` sem MLB confirmado | §4 — `ml_item_id` ANTES, a porta recusando (`REGRA_SEM_MLB`), recusa no-op completo; mutação **m3** mata |
| `T-193-22` MLB de OUTRA modalidade | §4 — filtro por `listing_type_id` + `first()` por `indice`, com as duas iscas; mutações **m4** e **m5** matam |
| `T-193-23` anúncio DUPLICADO por item em voo | §6 — RN-93 com a fixture TENDO `UNKNOWN` ao lado de `CREATED`, itens comparados byte a byte; mutação **m8** mata |
| `T-193-24` erro genérico / cláusula mentindo | §5 — cinco degraus do erro real, literal CONGELADO com 5 iscas, o caso do `CREATED` sem MLB; mutações **m6**, **m7**, **m9** matam |
| `T-193-25` revisão aprovada jogada fora | §5 — os quatro campos lidos CRUS, idênticos depois de `FALHA` e do retry |
| `T-193-26` edição de oferta em voo | §7 — `podeEditar()` falso **e** as 5 rotas chamadas DIRETO, por igualdade de conjunto, com isca |
| `T-193-27` gate lendo comentário | §8 — `token_get_all` + `substr_count`, com a ponta CRU × limpo provando que o leitor lê |
| `T-193-28` mutação com script sobrescrito | §12 — `mutar-193-03.sh` com o plano no nome, `sha256` do próprio script impresso **antes** e reconferido **depois** |
| `T-193-11` escrita de `andamento` fora da única porta | §8 — chave literal **0** no serviço (depois do conserto do furo 1), destinos por igualdade de conjunto, e `186-09 andamento-um-lugar-so` **idêntico** |
| `T-193-15` recusa engolida em silêncio | §6 e §8 — motivo REAL no histórico, ator Sistema, e **uma vez por motivo corrido**; mutação **m12** mata |
