---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 05
subsystem: publicador-aprovacao-painel-escritas
tags: [publicador, aprovacao, painel, escrita, imagem, revisao-parcial, aprovar, retry]
partida: 8c4a7c7f
commits:
  - e2b074ac  # feat(188-05) as tres escritas de IMAGEM
  - 86795e06  # test(188-05) salvar/aprovar/retry
  - (este)    # docs(188-05) a SUMMARY + a §M do FID01
requires:
  - AndamentoDaOfertaService   # a UNICA PORTA dos 8 estados / 13 transicoes (Fase 186)
  - AprovacaoDaOfertaService   # aprovar / salvarRevisao / tentarDeNovo / registrarMudanca (Fase 186)
  - RevisaoDeImagemService     # as 11 regras de imagem (Fase 186)
  - BloqueiosDaOfertaService   # contagemDeFotos / para (Fase 186)
  - FilaDeAprovacaoService     # paraAConta — a trava de conta (Fase 187)
  - FotosDoPainelService       # sem_alerta.quantidade — o numero que o botao promete (wave 188-03)
provides:
  - mlb.anuncios.meus.aprovacao.painel.imagem.aprovar
  - mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta
  - mlb.anuncios.meus.aprovacao.painel.imagem.reprovar
  - mlb.anuncios.meus.aprovacao.painel.salvar-revisao
  - mlb.anuncios.meus.aprovacao.painel.aprovar
  - mlb.anuncios.meus.aprovacao.painel.tentar-de-novo   # REVS-05 / W12 — nao existia
affects:
  - routes/mlb_anuncios.php
  - app/Http/Controllers/MlbPublicadorAprovacaoController.php
tech-stack:
  added: []        # ⚠️ ZERO dependencia nova — provado por FORMA (T-188-SC)
  patterns:
    - "action FINA: trava de conta -> validacao -> UMA chamada ao servico da 186 -> retorno"
    - "sucesso por back()->with(...) (o painel renasce da prop); recusa por json(message+regra, 422)"
    - "404 e nunca 403 em id de outra conta; o FILTRO e a CONSULTA, nunca in_array no PHP"
key-files:
  created:
    - tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php
    - tests/Feature/Publicador/Aprovacao/SalvarEAprovarDoPainelTest.php
    - tests/gates/contagens-188-05.txt
  modified:
    - routes/mlb_anuncios.php
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - .planning/phases/188-.../188-FID01.md   # §M, aditiva
requirements: [IMG-01, IMG-02, IMG-03, IMG-04, IMG-05, IMG-06, MOD-04, COL-01, COL-05, BLOQ-04, BLOQ-06, AND-06, REVS-14]
metrics:
  tasks: 2
  tests_novos: 16
  assertions_novas: 308
  ids_no_manifesto: 7
  mutacoes: 7          # todas MORRERAM
  duration: ~3h
completed: 2026-10-19
---

# Fase 188 Plano 05: as escritas de imagem, revisão e aprovação do painel — Summary

As **seis rotas de escrita** do painel de 1140px (aprovar foto · aprovar N sem alerta · reprovar
imagem · salvar revisão · aprovar para publicação · tentar de novo), com as actions **finas** sobre
os serviços da Fase 186 que estão em produção, e os gates que provam que **aprovar enfileira e não
publica**.

**PARTIDA:** `8c4a7c7f`.

---

## ⚠️⚠️ O que este plano resolveu, e que é o centro dele

### "Aprovar N sem alerta" **percorre `PubAprovacaoConjunto::doRascunho()`**

O rótulo *"Aprovar N sem alerta"* é montado por `FotosDoPainelService::para()` →
`sem_alerta.quantidade`, e esse N sai de `BloqueiosDaOfertaService::contagemDeFotos()`, que cobre
**TODOS os grupos do rascunho**. Já `RevisaoDeImagemService::aprovarSemAlerta()` recebe **UM conjunto
por chamada**.

➡️ A action **percorre os conjuntos e soma**. Chamar o serviço para um conjunto só aprovaria **menos
do que o botão promete**: com `GENERAL → 2` e `COR:Azul → 1`, o admin clicaria em **"Aprovar 3 sem
alerta"** e **uma ficaria de pé**, sem nada na tela explicando por quê.

**Medido, pela rota:**

```
o que o servico do painel promete : sem_alerta.quantidade = 3
o que a rota aprovou              : painel_fotos_sem_alerta.aprovadas = 3
grupos percorridos                : 2  (GENERAL + COR:Azul)
com_alerta_restantes              : 1  (continua para conferencia individual)
```

⚠️ **A asserção ancora no número que o serviço promete, nunca num literal `3`** — um `3` cravado
passaria mesmo se as duas pontas divergissem juntas. E a **isca do recorte** prova que o caso pode
falhar: `soDoGeral = 2 < prometido = 3`. Com um grupo só na fixture, "percorrer" e "não percorrer"
dariam o **mesmo** número e o caso seria inobservável por construção — a falha de fixture que matou
duas mutações da wave 187-06.

⚠️ Casa com a **decisão 5** do usuário (o bloqueio de fotos cobre todos os grupos; **aprovação cor
por cor foi RECUSADA**) e com a **decisão 8** (*"tudo de uma vez, e só libera com todas
conferidas"*) — a resposta leva `com_alerta_restantes` para a tela poder **dizer quem ficou de fora**,
da **mesma** `contagemDeFotos()`, nunca de duas contagens que poderiam somar diferente.

➡️ **E a mutação (vii) prova que o gate pega:** trocar o laço por `aprovarSemAlerta($conjuntos->first())`
**MORREU**.

---

### Os **cinco** efeitos de reprovar imagem (critério 13), cada um com isca

Reprovando a **principal** — o caso que mais importa, porque é o que faria o anúncio subir com a foto
errada:

| # | efeito | como está provado | a isca |
|---|---|---|---|
| 1 | sai do conjunto final **na hora** | `estado = rejected` **e** `noConjuntoFinal() === false` | partida: a foto estava `pending` e **na** galeria |
| 2 | vai para **"Fora da publicação"** | as **três** marcas juntas: `posicao = null`, `fora = true`, `motivo` gravado | partida: `posicao = 0` e `fora = false` (conferir só o estado deixaria foto `fora` ocupando lugar) |
| 3 | **a próxima vira principal** | `principal_imagem_id` = a `imagem_id` da #1, e a ordem **recompactada** (0, 1) | partida: a capa apontava para a foto reprovada |
| 4 | o **motivo** registrado | exatamente **1** evento contendo o motivo, com prefixo `Reprovou a foto ` | — |
| 5 | **reabre TODAS as ofertas do conjunto** | `versao` 1→2 **nas duas** e **1** evento `Aprovação reaberta: ` em **cada** | as duas versões têm de ser **iguais**: se a reabertura alcançasse só a oferta da rota, o Premium ficaria em 1 |

⚠️⚠️ **E o efeito 5 exigiu um conserto de fixture que é informação:** `registrarMudanca()` só emite
*"Aprovação reaberta: …"* para oferta que estava **`aprovado`**. Numa oferta em
`aguardando_aprovacao` há incremento de `versao` mas **nada a reabrir** — a primeira redação do caso
media o efeito 5 sobre um cenário em que ele era **inobservável por construção**, e o teste reprovou
(certo). ➡️ As duas ofertas são levadas a `aprovado` **pela única porta** antes (`mover()` valida a
**transição**, não os bloqueios — é por isso que dá para chegar lá sem montar a conta inteira).

➡️ **As mutações (ii) "não promover a próxima a principal" e (iii) "reabrir só a oferta, não o
conjunto" MORRERAM.**

---

### A revisão parcial fica **no SERVIDOR** — zero `localStorage`

`aguardando_aprovacao → em_revisao_ecf` **pela única porta**, com o registro **literal**:

```
Salvou revisão · 3 de 3 fotos aprovadas
```

⚠️ **A prova de COL-02 é COMPORTAMENTAL, não por ausência de `localStorage` no código:** outra
pessoa, em outra sessão, lê o **mesmo estado do banco** e, ao salvar de novo, o **segundo autor** fica
registrado:

```
autores dos eventos `save` (REVISAO_PARCIAL) = [admin, outraPessoa]
```

Um gate que só procurasse a string `localStorage` nos meus arquivos seria **vazio por construção** —
eles nunca a teriam. O que o critério 16 pede é *"outra pessoa continua de onde a primeira parou"*, e
é isso que está medido.

⚠️ **E o `x de y` vem de `contagemDeFotos()`, com a OUTRA PONTA conferida:** com `x < y` o bloqueio
`fotos_a_conferir` **tem de estar de pé**. Duas contagens da mesma verdade apareceriam como a tela
dizendo *"5 de 5 fotos aprovadas"* **com um bloqueio de fotos aceso** — o pior tipo de bug de dado,
porque **parece completo**. A fixture tem `x = 2, y = 3` de propósito: com `x == y` a asserção não
distinguiria "aprovadas" de "total".

---

### O servidor **recusa aprovar** com bloqueio ou fora de `live` — e o teste chama a rota **direto**

⚠️ **O `disabled=` do botão é do plano 188-10 e é conforto de tela.** Os casos chamam as rotas
**sem passar pela tela** — é o único jeito de provar que a trava não é cosmética (T-188-41).

| eixo | regra | o que foi medido |
|---|---|---|
| bloqueio de pé | `REGRA_COM_BLOQUEIO` | **três famílias** testadas (fotos pendentes · resultado negativo · frete ausente), cada uma com **isca** provando que o bloqueio existe no cenário |
| fora dos três `live` | `REGRA_FORA_DO_AO_VIVO` | os **quatro** estados (`aprovado`, `publicando`, `publicado`, `falha`) |
| versão divergente | `REGRA_VERSAO_DIVERGENTE` | `versao_esperada = 7` com `versao = 1` → 422, **sem gravar** (T-188-42) |

Em **todos**: andamento intacto, `aprovado_por`/`aprovado_em`/`versao_aprovada` **nulos**, zero
evento novo, zero composição congelada.

⚠️ **Isca do eixo 3:** a versão **CERTA** passa, e a **ausente** também (a aprovação pela linha da
fila não afirma versão) — sem ela, uma guarda que recusasse **sempre** passaria o caso.

➡️ **As mutações (iv) "aprovar ignorando bloqueio" e (v) "ignorar `versao_esperada`" MORRERAM.**

---

### ⚠️⚠️ **NADA vai ao Mercado Livre** — e a régua honesta não é "zero requisição"

**A fronteira da Fase 193 está declarada** no docblock do controller, no comentário das rotas e nos
dois arquivos de teste: `aprovar()` move o andamento, grava os três campos e congela a composição —
**tudo no nosso banco**. O gancho `aoAprovar` da Fase 186 está **VAZIO** e é daquela fase.

**MEDIDO, e isto corrige o `<behavior>` do plano:**

| rota | régua | porque |
|---|---|---|
| as **3 de imagem** | **zero requisição, literal** (`Http::recorded()` vazio) | nenhuma delas lê composição financeira |
| `aprovar`, `salvar-revisao`, `tentar-de-novo` | **zero ESCRITA** (nenhum método ≠ GET, nenhuma URL de `/items`) | `aprovar()` → `BloqueiosDaOfertaService::para()` → `ComposicaoFinanceiraService::para()` **LÊ a tarifa real** no GET público `/sites/MLB/listing_prices` (decisão 9 do usuário, cache de 1 h pré-aquecido por job) |

⚠️ **Prometer "zero requisição" na rota de aprovar seria um gate que o próprio desenho contradiz** —
e o executor seguinte o "consertaria" pré-aquecendo o cache, transformando-o em gate sobre zero
casos. ➡️ A autorização é por **lista CONSTANTE** (`LEITURAS_AUTORIZADAS`), não por filtro largo, e a
**outra face** também é conferida: a tarifa **FOI** consultada (senão o `assertSame([])` seria
verdadeiro por o fake nem ter sido tocado).

**As iscas, provando que o espião não está cego:**

```
ISCA 1  POST https://api.mercadolibre.com/items        -> PEGO pela sonda
ISCA 2  GET  https://api.mercadolibre.com/users/me     -> PEGO (GET fora da lista CONSTANTE)
ISCA    PUT  https://api.mercadolibre.com/items/MLB1   -> PEGO (no caso do retry)
ISCA    Http::recorded() com 1 chamada deliberada      -> count = 1 (nas rotas de imagem)
ISCA    preventStrayRequests() numa Factory NOVA       -> LANCA; e a URL combinada passa (200)
```

⚠️ **E nenhum `MLB…` gravado** (`ml_item_id === null` depois de aprovar e depois do retry): AND-04
exige MLB **confirmado** pelo Mercado Livre (T-188-46).

---

### A ausência do segundo passo (decisão 1 do usuário)

Gate de ausência de **`confirmar publicação`** / **`desfazer aprovação`** / **`segundo passo`** /
**`cancelar aprovação`**, nos arquivos deste plano **e** em todo o router.

⚠️⚠️ **Ele lê o código SEM COMENTÁRIO** (`token_get_all`, nunca regex — um regex de comentário come
o `//` dentro de `'https://…'` e apagaria código de verdade). **Isso não é zelo:** a prosa do plano,
o docblock do controller e os comentários das rotas citam os quatro **de propósito**, para explicar
que não existem. Um gate que lesse comentários **reprovaria pelo próprio conserto** — foi o que
abortou **três** mutações da wave 187-04.

As iscas, nas duas direções e **antes** da varredura (sonda furada torna o `assertSame([], …)` vazio
por construção):

- **(+)** cada uma das quatro sondas casa a forma que ela existe para pegar (`desfazerAprovacao()`,
  `Route::post('/cancelar-aprovacao')`, …);
- **(−)** nenhuma delas casa o rótulo **legítimo** `Aprovar para publicação`, que fica inalterado;
- **do `semComentarios()`**: o limpo tem de ser **menor** que o cru **e** ainda conter
  `public function aprovar(` — sem isso, um `semComentarios()` que devolvesse string vazia faria o
  gate verde para sempre;
- **do sujeito da isca**: as palavras **de fato** estão nos comentários do arquivo cru — se não
  estivessem, "ler sem comentário" não seria o que protege este gate.

⚠️ O recorte é **lista CONSTANTE** dos meus arquivos, com `test -e` anti-fantasma em cada caminho:
*desfazer* e *cancelar* são palavras comuns do português, e varrer `app/` é a família de gate que já
**barrou um deploy** nesta milestone.

---

### `img-02-img-03-sem-regressao` — o id órfão, agora num `<verify>`

⚠️⚠️ **RESÍDUO DE VERIFICAÇÃO CONSERTADO.** O id existia no manifesto, com contagem medida,
apontando para métodos que existiriam — **e nenhum `<verify>` o rodava**. Um id órfão **não falha:
ele simplesmente nunca roda**, e é indistinguível de um gate verde. É o gêmeo exato da armadilha 1 da
Fase 186 (*teste sem id nunca roda*). Os dois agora estão em `<verify>`: `img-02-img-03-sem-regressao`
e `tentar-de-novo`.

**A fronteira 189/192 está DECLARADA:** `IMG-02` e `IMG-03` são REQ-IDs desta fase, mas o **caminho
de TELA** deles (MOD-06 "substituir pela original", MOD-07 "regenerar com IA") é das Fases **189/192**.
Este plano **não** desenha aquela UI e **não** abre rota para ela — botão sem destino é **D4**
violado. O que está provado é **não-regressão**:

- `substituirPorOriginal()`: a entrante assume a **posição** da que saiu (2, não 0 — com isca da
  partida), e entra **`pending`**;
- **IMG-02**: original que **já estava aprovada** mantém a aprovação;
- `recolocarOriginal()`: volta `pending`, fora de "Fora da publicação";
- `concluirRegeneracao()`: a nova entra **`pending`** — foto regenerada pela IA que nascesse aprovada
  é foto que ninguém conferiu;
- **ausência de rota da 189/192** por **igualdade de conjunto** das seis rotas do painel. ⚠️ Nunca
  `>= 6`: **piso não prova PRESENÇA**, e perder uma rota deixaria cinco passando. Com isca de que a
  sonda achou algo.

⚠️ **E um conserto de fixture que é informação:** o unique de `pub_aprovacao_imagens` é
(`conjunto_id`, `imagem_id`), então substituir uma foto **pela própria `PubImagem` dela** faz
entrante e substituída serem **a MESMA linha** — o serviço grava `approved` e logo depois `replaced`
em cima. A primeira redação fazia isso e reprovou: o cenário era **degenerado**, não o invariante. O
cenário real do IMG-02 é uma original **já aprovada e fora** da publicação voltando como substituta
de **outra** foto.

---

## Divergências declaradas

### 1. ⚠️ `com exceção autorizada` no registro da aprovação — **NÃO** acrescentado

O `<behavior>` pedia que *"com exceção vigente, o registro acrescenta `com exceção autorizada`"*.

**Medido:** a frase é montada por `HistoricoDaOfertaService:169`
(`'Aprovou para publicação (versão %d)'`), que é **somente leitura** para este plano (Fase 186, **EM
PRODUÇÃO**), e `grep` por `com exceção autorizada` em `app/` sai **VAZIO**. Emendá-la exigiria
**alterar um serviço da 186** (vetado pela §9) **ou** gravar um **SEGUNDO evento para o mesmo fato** —
que é exatamente o defeito que o docblock daquele arquivo registra como **consertado em 15/10/2026**
(dois `adjust` por pedido de ajuste, com o filtro por tipo dobrando a contagem).

➡️ **A informação não se perde, e é MAIOR:** o evento `exc` fica **imediatamente antes** do `ok`, com
**quem liberou**, quando e a justificativa — que é o conteúdo da **decisão 6** do usuário (*"a exceção
só REGISTRA quem liberou"*). O sufixo diria apenas "houve exceção". **Medido e provado** no caso
`aprovar_com_zero_bloqueios…`:

```
indice do evento "Autorizou exceção (Resultado negativo): Cliente aceitou a perda nesta campanha."
  <  indice do evento "Aprovou para publicação (versão 1)"
excecao.liberada_por = o admin;  fraseDeExibicao() contem "Rafael Gusmão"
```

⚠️ **E não é divergência nova:** a Fase 186 já a declarou e resolveu do mesmo jeito, em produção, em
`AprovarEReabrirTest::test_aprovar_com_excecao_ativa_deixa_a_autorizacao_na_linha_do_tempo`. **Nada
foi alterado em serviço nenhum.**

### 2. ⚠️ Motivo de reprovação: o piso do servidor é **não-vazio**, não `> 2`

O protótipo exige `> 2` caracteres (**`:1402`** — ponteiro reconferido; ver §M) e
`RevisaoDeImagemService::reprovar()` aceita qualquer motivo **não-vazio** depois do `trim()`.

➡️ O `> 2` é o **`disabled=` do modal MOD-04** (planos 188-11/12), conforto de tela como o do botão de
aprovar. Cravar `min:3` na action criaria **duas** regras sobre o mesmo campo, a da action mais
estrita que a do serviço que **outros chamadores já usam** — e a divergente só apareceria quando
alguém reprovasse por um motivo curto **pela API e não pela tela**. Está medido nos dois sentidos: um
caractere **é aceito** pelo servidor, com a mensagem de falha dizendo que mudar isso é mudança **no
serviço da 186**, não aqui.

### 3. ⚠️ A rota de "aprovar sem alerta" **não leva `{conjunto}`** na URI

O plano escrevia `…/conjunto/{conjunto}/aprovar-sem-alerta`. ➡️ Um parâmetro de conjunto **mentiria**:
o botão promete o N de **todos** os grupos, e quem chamasse teria de escolher um. A URI é
`…/{oferta}/fotos/aprovar-sem-alerta`. Declarado no comentário da rota com a medição.

### 4. ⚠️ Duas guardas nascem na **action**, e as duas estão medidas

| guarda | por que não está no serviço | id de regra usado |
|---|---|---|
| salvar revisão **fora do `live`** | `salvarRevisao()` **não** confere `live` — ele só promove a partir de `aguardando_aprovacao` e **registra sempre**; em `publicado` registraria um fato que não aconteceu | **reusa** `AprovacaoDaOfertaService::REGRA_FORA_DO_AO_VIVO` — a tela já sabe ler esse, e cunhar um segundo para a mesma recusa espalharia o vocabulário |
| "tentar de novo" **fora de `falha`** | `TRANSICOES[aprovado]` **INCLUI** `publicando`, então `mover()` **não** recusaria e o botão empurraria para `publicando` uma oferta recém-aprovada — que a Fase 193 publicaria por cima | `AndamentoDaOfertaService::REGRA_TRANSICAO_ILEGAL` |

⚠️ A segunda tem **isca que a justifica**: o caso afirma que `aprovado → publicando` **é** transição
legal. Se um dia deixar de ser, a asserção fica vermelha e a guarda pode sair — em vez de virar código
morto que ninguém ousa tocar.

⚠️ E a primeira tem **isca do recorte**: os três `live` **não** podem ser recusados pela mesma guarda.
Sem ela, uma guarda invertida (ou sempre-verdadeira) passaria o laço dos quatro estados e **barraria o
trabalho legítimo** — a família "gate mais largo que o invariante", do lado da action.

---

## ⚠️⚠️ PERGUNTA NOVA — devolvida, não decidida

```
PERGUNTA-188-D: ABERTA — dono: USUÁRIO — o par de toast de "Aprovar N sem alerta" NÃO EXISTE no
  vocabulário travado. `VOC-08` fecha a lista em SEIS toasts
  (`vocabularioDoPortao.jsx:213`-`:220`: aprovado · publicado · revisao_salva · ajuste_solicitado ·
  foto_reprovada · gerando_com_ia) e o par do protótipo (`:1151`, CONFIRMADO por sed) —
  'Fotos sem alerta aprovadas' + 'Fotos com alerta automático continuam para conferência individual'
  — **não está entre eles**.
  ⚠️ É o QUARTO conflito protótipo × regra do projeto desta milestone, e as três derrotas anteriores
  do protótipo foram TODAS decisão do usuário. ➡️ ESTE EXECUTOR NÃO DECIDIU e NÃO cunhou uma 7ª frase.
  ⚠️ NÃO BLOQUEIA ESTE PLANO: a decisão 8 do usuário pede que "a tela diga quem ficou de fora e por
  quê", e o SERVIDOR já entrega o dado que permite a frase (`aprovadas`, `com_alerta_restantes`,
  `pendentes`, `grupos_percorridos`). O TEXTO é da tela — waves 188-08/09.
  As opções, para o usuário: (a) acrescentar o par como 7º toast de VOC-08 (o protótipo ganha, e o
  gate de VOC-08 é remedido); (b) reaproveitar um dos seis existentes; (c) dizer o mesmo FORA de
  toast (linha na própria coluna de fotos, que casa melhor com "a tela diz quem ficou de fora" e não
  desaparece em 3 s). APLICAR EM: 188-08/09 (o texto) e 188-13 (a ressalva, se ficar aberta).
```

---

## Gates e verificação

### Os 7 ids, medidos (`contagens-188-05.txt`)

```
imagem-aprovar-e-sem-alerta    Tests: 3 passed (49 assertions)   PASSOU
imagem-reprovar                Tests: 3 passed (58 assertions)   PASSOU
img-02-img-03-sem-regressao    Tests: 1 passed (15 assertions)   PASSOU
salvar-revisao                 Tests: 3 passed (52 assertions)   PASSOU
aprovar-e-enfileirar           Tests: 3 passed (85 assertions)   PASSOU
ausencia-de-segundo-passo      Tests: 1 passed (17 assertions)   PASSOU
tentar-de-novo                 Tests: 2 passed (32 assertions)   PASSOU
                               ──────────────────────────────
                               16 testes / 308 asserções, em SETE ids
                               (nenhum id com mais de TRÊS métodos)
```

⚠️ **Este plano NÃO declara `vocabulario-tamanhos` (o GATE-03), e está certo:** ele é **só de
servidor** e não roda aquele gate — nenhum `.jsx` é dele. A regra é **quem roda, declara**, não
*todos declaram* (é o mesmo caso de `contagens-187-02.txt`).

### Bijeção e homônimo — por script, nos dois sentidos

```
$ php homonimo-188-05.php
ARQUIVOS=1215 METODOS=7810 MEUS=16 HOMONIMOS=0
ISCA_POSITIVA=2/2 (tem de ser 2/2)  ISCA_NEGATIVA=nao-acusa (tem de ser nao-acusa)
MANIFESTO=existe  NO_MANIFESTO=16  SEM_ID=0  ORFAOS=0  IDS_REPETIDOS=0
```

- **método sem id NUNCA RODA** (foi o sobrevivente da mutação do 186-03) → `SEM_ID=0`;
- **id órfão é gate sobre ZERO casos** → `ORFAOS=0`;
- **id repetido**: o harness pega o **primeiro** → `IDS_REPETIDOS=0`;
- **homônimo**: o `--filter` do PHPUnit é regex **não ancorado** e as Fases 186 **e** 187 estão em
  produção; a conferência cobre **superstring e prefixo**, nos dois sentidos, contra todo `test_*` de
  `tests/` → `HOMONIMOS=0`. Todo método leva `_188_05`.

⚠️⚠️ **E A QUARTA OCORRÊNCIA DE "FURO NA PRÓPRIA SONDA" FOI NESTE SCRIPT.** A primeira redação lia os
métodos do manifesto com `preg_match_all('/test_[A-Za-z0-9_]+/')`, que **não casa a forma de
alternância `test_(a|b|c)`** — a forma que **todos** os manifestos desta fase usam. Resultado medido:
**`SEM_ID=14` com o manifesto CORRETO**, isto é, a sonda **acusando trabalho certo**. Consertado
expandindo a alternância; registrado na §M do FID01 para as waves 06-13.

### `--autoteste` — colado

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

### Os ids das waves 1-4 — **re-rodados, contagens INALTERADAS**

⚠️ **São 21, não 19** (o briefing dizia 2+9+4+4; o manifesto da wave 1 tem **4** ids, não 2):

```
188-01 baselines-medidas       1 passed (7 assertions)     PASSOU
188-01 tokens-e-tamanhos                                   PASSOU
188-01 vocabulario-tamanhos                                PASSOU
188-01 fid01-remedida          1 passed (13 assertions)    PASSOU
188-02 identificacao-e-chips   2 passed (41 assertions)    PASSOU
188-02 pendencias-e-destinos   2 passed (45 assertions)    PASSOU
188-02 rodape-e-aprovavel      2 passed (43 assertions)    PASSOU
188-02 cadeia-e-incompleto     1 passed (20 assertions)    PASSOU
188-02 navegacao-e-payload     2 passed (50 assertions)    PASSOU
188-02 custo-do-painel         2 passed (24 assertions)    PASSOU
188-02 painel-na-rota          2 passed (41 assertions)    PASSOU
188-02 territorio-painel       1 passed (42 assertions)    PASSOU
188-02 bijecao-manifesto-02    1 passed (22 assertions)    PASSOU
188-03 galeria-e-marcadores    3 passed (84 assertions)    PASSOU
188-03 borda-e-estados         3 passed (59 assertions)    PASSOU
188-03 sem-alerta-e-contagem   3 passed (48 assertions)    PASSOU
188-03 verificacoes-e-ordinal  3 passed (113 assertions)   PASSOU
188-04 kpis-e-incompleto       3 passed (101 assertions)   PASSOU
188-04 composicao-e-origem     3 passed (89 assertions)    PASSOU
188-04 simulacao-sem-salvar    3 passed (77 assertions)    PASSOU
188-04 atalhos-excecao-irma    3 passed (185 assertions)   PASSOU
```

⚠️ E `fid01-remedida` foi re-rodado **depois** de eu acrescentar a §M ao `188-FID01.md`: segue
**1 passed (13 assertions)**, inalterado.

### A camada B do gate de andamento — **NÃO precisei entrar na lista de EXPOSITORES**

**Medido** no meu controller, lido **sem comentário**:

```
SONDA_LARGA=0   SONDA_ESCRITA=0   REF_PORTAO=1
```

➡️ Ele **não casa** a sonda larga (`/'andamento'\s*=>|->andamento\s*=(?!=)/`), então entrar na lista
de EXPOSITORES seria **linha morta abrindo um buraco para o futuro** — exatamente o que o gate exige
que não se faça (`expositor que não casa a sonda larga: a exceção dele é linha morta — tire da
lista`). **Nenhuma escrita de `andamento` passa por este arquivo:** quem move é
`AndamentoDaOfertaService::mover()`, sempre, por dentro dos serviços da 186.

⚠️ **`contagens-186-09.txt` NÃO foi remedido, e os dois ids foram conferidos INALTERADOS:**

```
andamento-um-lugar-so   Tests: 1 passed (20 assertions)    PASSOU   (manifesto: 20)
transversais            Tests: 12 passed (258 assertions)  PASSOU   (manifesto: 258)
```

---

## Mutação — `mutar-188-05.sh`, **7 mutações, TODAS MORRERAM**

```
sha256 do PROPRIO script (execucao paralela): 9b1bd00129aa852a7049eb5d750b50a2
== sha256 ANTES da campanha ==
   OK: os 3 arquivos batem com o HEAD

MUT i   [aceitar motivo vazio]                               gate=imagem-reprovar              -> MORREU (certo)
MUT ii  [nao promover a proxima a principal]                 gate=imagem-reprovar              -> MORREU (certo)
MUT iii [reabrir so a oferta, nao o conjunto]                gate=imagem-reprovar              -> MORREU (certo)
MUT iv  [aprovar ignorando bloqueio]                         gate=aprovar-e-enfileirar         -> MORREU (certo)
MUT v   [ignorar versao_esperada]                            gate=aprovar-e-enfileirar         -> MORREU (certo)
MUT vi  [contar as pending COM alerta no sem-alerta]         gate=imagem-aprovar-e-sem-alerta  -> MORREU (certo)
MUT vii [aprovar sem alerta em UM conjunto so (a MINHA action)] gate=imagem-aprovar-e-sem-alerta -> MORREU (certo)

== sha256 DEPOIS da campanha (restauracao conferida) ==
   OK: os 3 arquivos voltaram identicos ao HEAD
```

⚠️ **A (vii) é a minha, e é a mais importante:** ela ataca o laço pelos conjuntos. Se sobrevivesse, o
botão poderia mentir sem nenhum gate acusar.

**A receita seguida** (§7): trabalho **commitado antes** (`e2b074ac`, `86795e06`) · `cp` para cópia
com **nome que carrega o plano** · ocorrência **travada em 1 ANTES** · linha **conferida depois** ·
restauração **DA CÓPIA** em `trap EXIT` · `sha256` contra `git show HEAD:` com `tr -d '\r'` nos dois
lados · a guarda de NO-OP por **divergência de sha256**, nunca por `grep` da âncora antiga ·
⚠️ **`git checkout` NUNCA**.

⚠️⚠️ **A MUT iv ABORTOU na primeira tentativa, e o aborto é o comportamento CERTO.** A âncora
`if (! $info['aprovavel']) {` aparece **DUAS** vezes naquele arquivo — em `aprovar()` (`:205`) e em
`aprovarEmLote()` (`:278`) — e a guarda de ocorrência recusou. Âncora ambígua mutaria o **método
errado** e o veredicto não valeria nada. ➡️ Ela foi refeita em `mutar-188-05-iv.php`, com âncora
**multi-linha** desambiguada pela linha anterior, e a trava de ocorrência por **`substr_count()`** —
porque **`grep -c` conta LINHAS**, não ocorrências, e âncora multi-linha não é travável por ele.

```
sha256 do script: ee0e2a7eac311f0de4d5366cb0fc996b
sha256 ANTES: OK (disco == HEAD)
OCORRENCIAS=1 (ancora multi-linha, conferida por substr_count)
MUTADO (sha diverge do HEAD, como tem de ser)
MUT iv [aprovar ignorando bloqueio] gate=aprovar-e-enfileirar -> MORREU (certo)
sha256 DEPOIS: OK (restaurado, identico ao HEAD)
```

---

## Território — por **ESCOPO DO ASSUNTO**, duas passadas, isca nas duas direções

⚠️ **NÃO por range** (§6, regra 9): a 187-11 mediu 111 commits no range com **43** da sessão
paralela, e a wave 188-04 mediu que **3 dos 6** commits do range dela eram da **Fase 189**. ⚠️ **E não
se confere o AUTOR:** as três máquinas desta árvore commitam com o mesmo `user.name`
(`MB.ECF-100376`), então `%an` é vazio por construção aqui.

```
== (0) ANTI-FANTASMA: test -e em CADA alvo ANTES de medir ==
   FANTASMAS=0 (tem de ser 0)
   .jsx de aprovacao/ no disco: 24

== (1) OS COMMITS DO ASSUNTO (188-05) ==
   86795e06 test(188-05): ...
   e2b074ac feat(188-05): ...
   QTD=2
   ISCA (+)=ok (achou commits)  ISCA (-)=ok (nao pegou a 188-07)

== (2) PASSADA 1 — numstat de CADA commit meu contra os 24 alvos vetados + o dir dos .jsx ==
   PASSADA_1_VIOLACOES=0

== (3) PASSADA 2 (INDEPENDENTE, do lado OPOSTO) — tudo que meus commits tocaram, contra a LISTA BRANCA ==
     app/Http/Controllers/MlbPublicadorAprovacaoController.php
     routes/mlb_anuncios.php
     tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php
     tests/Feature/Publicador/Aprovacao/SalvarEAprovarDoPainelTest.php
     tests/gates/contagens-188-05.txt
   PASSADA_2_VIOLACOES=0

== (4) Dependencias (T-188-SC) ==
   DEPENDENCIAS_TOCADAS=0

VEREDICTO: fantasmas=0 passada1=0 passada2=0 dependencias=0
```

⚠️ **A passada 2 parte do lado oposto da 1:** em vez de perguntar *"meu commit tocou o alvo?"*, ela
lista **tudo** que meus commits tocaram e confere contra a **lista branca** — assim um alvo que eu
tivesse **esquecido de vetar** também aparece.

⚠️ **ZERO instalação de pacote** — nenhum dos quatro arquivos de dependência foi tocado.
⚠️ **Nenhum `.jsx` é meu**, e `aprovacao/` tem **24** arquivos no disco (não 21): os três novos são da
wave **188-07**, que roda em paralelo.

---

## Baselines

| | PARTIDA (`8c4a7c7f`) | FIM | fecha com o meu diff? |
|---|---|---|---|
| **PHP testes** | **2534** | **2550** | ✅ **FECHA EXATO**: `2534 + 16 = 2550`, e os 16 são os meus, medidos um a um |
| **PHP asserções** | **22986** | **23294** | ✅ **FECHA EXATO**: `+308`, e `49+58+15+52+85+17+32 = 308` é a soma dos sete ids. ⚠️ Mesmo assim, asserção **não é invariante** (a wave 188-04 mediu `23015` × `23020` com os **mesmos** 2534 testes) — a régua é **TESTES e FALHAS** |
| **PHP falhas** | **1** | **1** | ⚠️ **A MESMA, e ALHEIA** — `FilaDeAprovacaoRotaTest`, da wave **188-07**, provada abaixo |
| **JS** | *não medido na partida* | **5568 / 5567 pass / 1 fail / 0 skipped** | ❌ **NÃO fecha, e eu NÃO ajustei** — os `+70` são da wave 188-07; ver a ressalva 2 |
| **BUILD** | *não rodado* | *não rodado* | ⚠️ deliberado — ver a ressalva 3 |

⚠️⚠️ **O FECHAMENTO EXATO NO PHP É O QUE IMPORTA AQUI:** nos dois eixos (testes **e**
asserções) a diferença entre partida e fim é **exatamente** o que os sete ids do meu manifesto
medem, um a um. Nenhuma asserção alheia se moveu, e nenhuma falha nova apareceu.

### ⚠️⚠️ A falha PHP da partida **NÃO é nenhuma das quatro conhecidas — e é da wave 188-07, PROVADO**

```
⨯ FilaDeAprovacaoRotaTest > a pagina da fila nao desenha o que nao existe 187 04
  "os quatro slots têm de passar por `isValidElement`: nó inválido como filho faz o React LANÇAR."
  Failed asserting that 5 is identical to 4.
  at tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php:1674
```

**A prova de que não é minha, e ela é decisiva:**

```
$ git log -S "noPainel" --oneline -- resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
8c4a7c7f feat(188-07): o teclado como FUNCAO PURA, a identificacao com os 5 chips e as DUAS COLUNAS...

$ git show HEAD:resources/js/Pages/Mlb/Publicador/Aprovacao.jsx | grep -c "isValidElement("
5                       # ja eram 5 no COMMITADO

$ git diff HEAD --stat -- resources/js
                        # VAZIO — eu nao toquei um .jsx
```

➡️ O **5º** `isValidElement(` (o `noPainel`, `:722`) entrou no commit **`8c4a7c7f`**, que é
**`feat(188-07)`** — **a wave que roda em paralelo**, e que é **a minha PARTIDA**. ⚠️ **A falha
precede a minha primeira linha**, e o conserto é **do lado dela**: quem acrescenta o 5º slot remede o
`4` daquele gate. **Eu não consertei, por desenho** — mexer no gate de outra wave é o caso que a §6
descreve como *"empurrava para o conserto errado"*.

### As quatro falhas conhecidas, nomeadas — **todas ALHEIAS, nenhuma apareceu nesta execução**

| teste | fase | por que não é regressão |
|---|---|---|
| `tests/Feature/Publicador/CockpitDaContaTest.php:966` | **179**, em produção | fronteira de dia; o arquivo usa `now()` **52** vezes. ⚠️ O caminho `.../Aprovacao/CockpitDaContaTest.php` **NÃO EXISTE** — é o 9º fantasma da milestone; o real é **fora** de `Aprovacao/` |
| `tests/Feature/Publicador/CockpitDaContaTest.php:1318` | **179**, em produção | idem |
| `tests/Feature/PortalCliente/.../SincronizaPortalCompletoTest:257` | alheia | **sensibilidade de ORDEM** medida pela wave 188-04: `PubImagem::count()` deu 0/1 em vez de 3 e, **na mesma árvore, sem uma linha de mudança**, a re-execução deu 0 falhas. Hipótese declarada (não confirmada): três arquivos registram listener de modelo e só um chama `flushEventListeners()` |
| `tests/Feature/PortalCliente/.../SincronizaPortalCompletoTest:293` | alheia | idem |

⚠️ **Nenhuma das quatro apareceu na minha medição de partida** — consistente com a oscilação por hora
do dia e com a sensibilidade de ordem, as duas já documentadas. **Não consertei nenhuma, não contei
como regressão, e NÃO criei `REF_PHP_FALHAS`.**

### ⚠️ Ressalvas declaradas das baselines — o que eu **não** medi, e por quê

1. **JS e BUILD não foram medidos na PARTIDA.** É uma lacuna minha e fica registrada. ➡️ **Por que é
   seguro:** meu diff tem **ZERO** arquivo de frontend — provado pela **passada 2** do script de
   território (os 5 arquivos tocados, nenhum deles `.jsx`/`.css`) e por `git diff HEAD --stat --
   resources/js` **vazio**. Nenhuma mudança minha **pode** mover a contagem JS.
2. **O JS no fim é 5568 / 1 fail**, contra os **5498 / 1 fail** que a wave 188-04 mediu: **+70**,
   todos da wave **188-07** (ela acrescentou 3 `.jsx` a `aprovacao/`, e o `deepEqual` de
   `DIRETORIOS_FECHADOS` cria sub-testes **por arquivo varrido**, mais o arquivo de teste dela).
   ⚠️ **Contagem ACIMA da baseline é ESPERADA e passa; a diferença NÃO fecha com o meu diff — e eu
   não ajustei nada, estou dizendo que não fecha e por quê.** A única falha é a pré-existente
   `tests/js/estrutura-grade-glide.test.js` (*"Características secundárias nasce recolhido"*, Fase
   87), aceita por `REF_JS_FALHAS=1`.
3. **`npm run build` NÃO foi rodado, deliberadamente.** A regra do `cp`/`mtime` (§5) manda rodá-lo
   **depois da mutação, antes de medir o JS** — mas a minha mutação tocou **só `.php`**, e nenhum
   `mtime` de `.jsx`/`.css` mudou. ➡️ Rodar o build **concorrentemente com a wave 188-07, que é a dona
   dos `.jsx` e está editando-os agora**, geraria `public/build` a partir do estado **meio-feito** dela
   e poderia **correr com os artefatos que ela precisa para os próprios gates**. Com diff de frontend
   **zero**, o build adiciona risco sem adicionar informação.

---

## O que **não** foi feito, e é desenho

- ⚠️ **Nenhum serviço da 186/187 foi alterado.** Os seis actions **chamam**; `numstat` zero nos 24
  alvos vetados.
- ⚠️ **Nenhum `.jsx`.** A coluna de fotos é da wave 08, a de preço da 09, pendências/rodapé da 10, os
  modais da 11/12. **Nada de provisório de nenhuma delas.**
- ⚠️ **O transporte da simulação de preço (`POST …/preco/simular`) é da wave 188-06** — não escrito
  aqui.
- ⚠️ **Nenhuma rota da 189/192** (substituir pela original, regenerar com IA): a fronteira está
  declarada **e provada por igualdade de conjunto** das seis rotas.
- ⚠️ **Nenhuma 7ª frase de toast** — PERGUNTA-188-D, devolvida ao usuário.
- ⚠️ **Nenhum deploy, nenhum push, nenhum VPS, nenhuma chamada à API real do ML.** Esta fase não tem
  migration e `artisan migrate` **não** foi rodado.
- ⚠️ **`.planning/ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` não foram tocados** (GATE-07), nem
  os `188-NN-PLAN.md` / `188-REGUA.md` / `188-COBERTURA.md`. **`188-FID01.md` ganhou a §M, aditiva**, e
  `fid01-remedida` segue verde.

## ⚠️ O que nenhum executor desta fase pode afirmar

**Nenhuma tela renderizada foi vista.** O banco local **não tem empresa com `ml_token`** (a rota dá
404) e `pub_aprovacao_ofertas` está **vazia** — tudo aqui é exercitado **por fixture**, pelas rotas.
⚠️ **A conferência visual da Fase 187 pelo usuário continua ABERTA**, e nada deste plano a substitui
nem a presume feita.

## Roteiro de conferência (conta 459) — o trecho deste plano, para o 188-13

> ⚠️ **Só executável quando a fila tiver oferta** (Fase 194, decisão P3 do usuário: fila vazia é
> honesto, não é defeito). As seis rotas são **POST**: o usuário **não roda comando** — ele clica.

1. Na fila, abrir uma oferta em *Aguardando aprovação* (o painel chega na wave 188-07).
2. Clicar em **uma** foto para aprovar → o progresso sobe e, **na oferta irmã** do mesmo produto, a
   mesma foto aparece aprovada (DADO-04).
3. Clicar em **"Aprovar N sem alerta"** → o número aprovado é **o N do botão**, inclusive em produto
   com variação de cor; as fotos com alerta **continuam** pedindo conferência.
4. Reprovar a foto **de capa** com um motivo → ela vai para *Fora da publicação*, **a próxima vira a
   capa**, e **as duas** ofertas voltam para *Aguardando aprovação* com *"Aprovação reaberta"* no
   histórico.
5. Clicar em **Salvar revisão** → o andamento vira *Em revisão ECF* e o histórico diz
   *"Salvou revisão · x de y fotos aprovadas"*. **Abrir a mesma oferta em outro navegador/conta:** o
   estado é o mesmo (a revisão está no servidor).
6. Com pendência em aberto, **Aprovar para publicação** é recusado com a pendência nomeada.
7. Sem pendência, **Aprovar para publicação** aprova **num clique** — **não há** segunda tela, não há
   desfazer — e o anúncio **não sobe ainda** (a publicação é da Fase 193).

## Self-Check: PASSED

Por **reconsulta ao disco e ao `git log`**, nunca por memória:

```
=== (1) ARQUIVOS AFIRMADOS ===
FOUND: routes/mlb_anuncios.php
FOUND: app/Http/Controllers/MlbPublicadorAprovacaoController.php
FOUND: tests/Feature/Publicador/Aprovacao/EscritaDeImagemDoPainelTest.php
FOUND: tests/Feature/Publicador/Aprovacao/SalvarEAprovarDoPainelTest.php
FOUND: tests/gates/contagens-188-05.txt
FOUND: .planning/phases/188-.../188-05-SUMMARY.md
FOUND: .planning/phases/188-.../188-FID01.md

=== (2) COMMITS ===
FOUND: e2b074ac    FOUND: 86795e06

=== (3) must_haves.artifacts ===
min_lines >= 220 em EscritaDeImagemDoPainelTest.php  -> 909 linhas      OK
"meus.aprovacao.painel" em routes/mlb_anuncios.php   -> 6 ocorrencias   OK

=== (4) must_haves.key_links (os patterns) ===
RevisaoDeImagemService  no controller -> 7 ocorrencias   OK
AprovacaoDaOfertaService no controller -> 9 ocorrencias  OK

=== (5) §M no FID01 -> 1 (aditiva; fid01-remedida segue 13 assertions) OK

=== (6) O ROUTER, e a prova de que `lote` NAO foi roubado ===
mlb/anuncios/meus/9/aprovacao/lote                       -> mlb.anuncios.meus.aprovacao.lote
mlb/anuncios/meus/9/aprovacao/7/aprovar                  -> ...painel.aprovar
mlb/anuncios/meus/9/aprovacao/7/salvar-revisao           -> ...painel.salvar-revisao
mlb/anuncios/meus/9/aprovacao/7/tentar-de-novo           -> ...painel.tentar-de-novo
mlb/anuncios/meus/9/aprovacao/7/fotos/aprovar-sem-alerta -> ...painel.fotos.sem-alerta
mlb/anuncios/meus/9/aprovacao/7/imagem/4/aprovar         -> ...painel.imagem.aprovar
mlb/anuncios/meus/9/aprovacao/7/imagem/4/reprovar        -> ...painel.imagem.reprovar
ISCA ok: oferta nao-numerica NAO casa (whereNumber de pe)
```

⚠️ **O que este executor NÃO fez, por determinação expressa:** `state.advance-plan` **não** foi
rodado (corrompeu o `STATE.md` deste projeto **seis** vezes) e `STATE.md` / `ROADMAP.md` /
`REQUIREMENTS-v27.md` **não** foram tocados (GATE-07 — nenhum dos três pertence a plano nenhum; quem
edita é o orquestrador, à mão, em bloco aditivo). ⚠️ **Os REQ-IDs deste plano seguem sem marcação em
`REQUIREMENTS-v27.md`, de propósito.**
