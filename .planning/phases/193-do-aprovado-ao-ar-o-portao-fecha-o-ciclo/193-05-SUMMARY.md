---
phase: 193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo
plan: 05
subsystem: publicador-aprovacao
tags: [fechamento, gates, territorio, d11, pub03, baselines, mutacao, checkpoint]
requires:
  - "193-01 (a ausencia de migration como assercao, e as baselines)"
  - "193-02 (o gancho aoAprovar ligado e a guarda da decisao 23)"
  - "193-03 (o reconciliar, a decisao 24, publicado so com MLB)"
  - "193-04 (o passo do minuto e o retry que republica)"
  - "quick 261021-fix (as decisoes 25 e 26, e o gate 188-05 que estava vermelho)"
provides:
  - "o 193-FECHAMENTO.md: os 10 criterios com veredicto individual e a medicao ao lado"
  - "PLACAR-193: OK=9 RESSALVA=1 REPROVA=0"
  - "o deferred-items.md com DONO em cada um dos 14 itens"
  - "o manifesto tests/gates/contagens-193-05.txt, com os 4 ids MEDIDOS"
  - "o roteiro numerado da conferencia visual da conta 459 (checkpoint humano)"
affects: []
tech-stack:
  added: []
  patterns:
    - "gate de fechamento SEM git: o numstat vai para o documento como MEDICAO DATADA"
    - "declaracao de metodo por TOKEN (T_FUNCTION + T_STRING), nunca por regex sobre texto cru"
    - "tamanho EXATO + subconjunto CRITICO em vez de piso, para lista que nao pode encolher"
key-files:
  created:
    - tests/Feature/Publicador/Aprovacao/FechamentoDaFase193Test.php
    - tests/gates/contagens-193-05.txt
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-FECHAMENTO.md
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/deferred-items.md
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-05-SUMMARY.md
  modified: []
decisions:
  - "O gate do fechamento NAO chama git: um range PARTIDA..HEAD sobre arquivo e auto-invalidante"
  - "O documento foi commitado ANTES do gate, porque ele e o SUJEITO do gate"
  - "A lista ALVO-INTOCADO e medida por tamanho EXATO (32) + subconjunto CRITICO (9), nunca por piso"
metrics:
  duration: "~5 h"
  completed: "2026-10-21"
  tasks: 3
  commits: 9
---

# Fase 193 Plano 05: o fechamento medido — e a suíte completa achou uma regressão que nenhum gate meu pegaria Summary

A Fase 193 fecha com **`PLACAR-193: OK=9 RESSALVA=1 REPROVA=0`**. O critério 10 nasce **RESSALVA
declarada** (dono: usuário) porque a validação de ponta a ponta **é impossível nesta máquina** — e a
dependência **mudou e ficou mais perto**: era *"depois da Fase 194"*, e agora basta o **piloto do
agente**, porque `PreparadorDaAprovacao:148` **já cria oferta**.

⚠️⚠️ **O achado mais importante desta wave não é um critério: é que a SUÍTE COMPLETA achou uma
regressão MINHA que nenhum dos meus 4 ids pegaria** — porque o invariante violado era de **outra
wave**. *"Rode a suíte completa"* não é formalidade.

---

## §1 — A PARTIDA, E O HEAD NAS DUAS PONTAS

```
PARTIDA: 0b9c51e0   (capturada na PRIMEIRA ação, antes de qualquer leitura — confere com o esperado)
HEAD no fim:  993f01b9   (+ o commit desta SUMMARY)
```

⚠️ **A PARTIDA foi usada SÓ como referência de leitura.** Em nenhum momento ela serviu de extremo de
range para medir território — ele saiu do **ESCOPO DO ASSUNTO `(193-05)` no `%s`**, em duas passadas
independentes (§10).

| commit | tarefa | o quê |
|---|---|---|
| `2f888014` | 2 (parte) | `docs(193-05)`: o fechamento medido — território, PUB-03, a fronteira D-11 e os 10 critérios |
| `73bcac1c` | 1 | `test(193-05)`: os gates transversais — **e nenhum deles chama git** |
| `602b4d62` | 1 (conserto) | `fix(193-05)`: a suíte COMPLETA achou **1 regressão MINHA** |
| `87f29f2e` | 2 | `docs(193-05)`: as dívidas, cada uma com DONO |
| `eb1dc77f` | 2 | `docs(193-05)`: as baselines RE-MEDIDAS e os sete furos |
| `761ab6a4` | 2 (mutação) | `fix(193-05)`: a sobrevivente **m3** — a sonda do dono casava no meio da palavra |
| `bd0574ba` | 2 (mutação) | `fix(193-05)`: a sobrevivente **m9** — *"piso não prova PRESENÇA"* |
| `993f01b9` | 2 (fecho) | `docs(193-05)`: a mutação consolidada e o self-check |
| (este) | — | `docs(193-05)`: a SUMMARY |

### ⚠️ Divergência de ORDEM, declarada

O plano põe o gate na tarefa 1 e o documento na tarefa 2, **mas o `<behavior>` da tarefa 1 exige
asseverar sobre o `193-FECHAMENTO.md`**: um gate cujo **sujeito não existe** não é mensurável, e
commitar o gate primeiro deixaria um commit na história **com o gate vermelho por ausência do alvo**
(provado: a primeira rodada do gate deu `1 failed` por *"manifesto ausente"*). ➡️ Documento primeiro,
gate depois. ⚠️ **Nada ficou sem commit em nenhum momento** — três agentes caíram na Fase 187, um
deixando 2/3 de um plano sem commit.

---

## §2 — OS 10 CRITÉRIOS, CONTADOS POR MIM, COM ISCA

```
awk '/^### Phase 193:/,/^### Phase 194:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "   ->  10
ISCA (as vizinhas):  186 -> 17   187 -> 15   188 -> 20   189 -> 8   192 -> 12   194 -> 9
ISCA de SUB-captura: varredura por `^ *[0-9]+\. ` (QUALQUER indentação) -> as MESMAS 10
```

⚠️ **As três primeiras batem com o que aquelas fases declararam** (`CRITERIOS = 20` no
`FechamentoDaFase188Test`, 17 na 186, 15 na 187) — logo o recorte está certo, e não é *"o `awk`
devolveu um número e eu acreditei"*.

**PLACAR-193: OK=9 RESSALVA=1 REPROVA=0.** Os veredictos um por um estão no `193-FECHAMENTO.md` §6,
cada um em forma fixa `CRIT-NN: VEREDICTO — … prova: …`, com gate ou medição nomeados.

⚠️⚠️ **E o CRIT-04 é o único que precisou de julgamento:** o critério, escrito em 20/10, diz
*"'Tentar de novo' muda direto para `publishing`"*, e as decisões **21/23/25/26** chegaram **depois** e
refinaram o caminho — quando a fila **recusa**, o retry **não move**. ➡️ **Veredicto OK**, com o
refinamento escrito em §6.1, **não** `OK com ressalva` (que a forma fixa proíbe) e **não** RESSALVA:
não há entrega faltando nem dono pendente, porque a autoridade são as decisões do usuário e elas estão
registradas no `ROADMAP.md`. ⚠️ **A alternativa *"mover mesmo com a fila recusando"* foi recusada no
planejamento e pelas waves:** produziria `publicando` ligado a item terminado, e o reconciliador o
fecharia como `falha` no minuto seguinte — **flapping com a edição bloqueada**.

---

## §3 — ⚠️⚠️ PUB-03: `numstat` VAZIO NO `MlPublicacaoService` **E** NO `PublicacaoService`

```
test -e app/Services/Mlb/Publicacao/MlPublicacaoService.php   ->  OK, 175 linhas
test -e app/Services/Publicador/PublicacaoService.php         ->  OK, 777 linhas
test -e app/Services/Mlb/MlPublicacaoService.php              ->  FANTASMA (a isca)

git diff --numstat c4529cca..HEAD -- app/Services/Mlb/Publicacao/MlPublicacaoService.php  ->  VAZIO
git diff --numstat c4529cca..HEAD -- app/Services/Publicador/PublicacaoService.php        ->  VAZIO
ISCA do numstat (arquivo que eu SEI que mudou):
git diff --numstat c4529cca..HEAD -- …/Aprovacao/AndamentoDaOfertaService.php             ->  47  3
```

⚠️ **A janela é a da FASE INTEIRA** (`c4529cca..HEAD`, **26** commits), não a desta wave: ela é
**estritamente mais forte**, porque cobre também os **13 commits alheios** — ninguém tocou os dois
arquivos, **nem a fase nem os vizinhos**.

⚠️ **E o gate não é sobre zero casos:** o alvo existe (175 linhas) e o **controle positivo** está
asseverado — `'POST', '/items'` continua no `PublicacaoService` (`:442`), que é **o único POST /items
do sistema**. ⚠️⚠️ **O briefing dizia para "reusar o `MlPublicacaoService`"; MEDIDO, ele recebe
`MlAnuncioRascunho`** — o rascunho do **assistente antigo** — e **não está no caminho do Publicador**.

**A versão COMPORTAMENTAL do diff vazio**, que é o que fica no gate perene: nenhum dos 5 arquivos
desta fase **menciona** `MlPublicacaoService` em **código** (lido sem comentário: **0**; no texto
**CRU**: **1**, na prosa que explica justamente que ele está fora — a **isca do leitor**).

---

## §4 — ⚠️⚠️ A FRONTEIRA D-11 / D-22: INTACTA, NÃO ESTENDIDA, E COM GATE PRÓPRIO DO OUTRO LADO

```
numstat c4529cca..HEAD -- tests/js/estrutura-meus-anuncios.test.js        ->  VAZIO
numstat c4529cca..HEAD -- tests/js/publicador-header-zindex-ordem.test.js ->  VAZIO
ISOLADOS (exit de $?, SEM pipe):  30 / 30 / 0  e  7 / 7 / 0,  EXIT_REAL=0 nos dois
sha256:  914cb2ded90e59bc (709 l.)   ·   3e4536c0df9966a8 (189 l.)
conjunto de NOMES congelado:  24 (D-11)  e  7 (z-index)   ·   assert.:  97  e  15
```

⚠️ **IGUALDADE DE CONJUNTO, nunca piso:** com 24 nomes, perder **um** deixaria 23 e qualquer piso
razoável passaria — foi a sobrevivente **M5** do fechamento da 187.
⚠️ **24 declarações rodam 30 testes**, e a aritmética está escrita (`6 × 2 + 18 = 30`): as seis
primeiras estão num laço sobre **dois** caminhos. Congelar a **declaração** mede o mesmo invariante
sem precisar rodar `node` de dentro do PHPUnit.

**O D-11 e o D-22 por SEMÂNTICA, e o HOMÔNIMO que uma sonda ingênua engoliria:** a família D-11 tem
**3** nomes e o D-22 **1**; ⚠️ o mesmo arquivo tem *"abas: … `mlb.anuncios.wizard` **(D22)** …"*, que
**não** é o D-22 da nota. ➡️ O D-22 é achado pelo **prefixo do nome** (`D-22:`), com asserção provando
que o teste de **abas não entra** no conjunto **e** que ele **ainda existe** (senão a isca perde o
sujeito). ⚠️ **O plano citava `≈ :250, :256, :285`** — ancorar por **linha** é o que um deslocamento de
outro dev quebra.

**NÃO ESTENDIDO:** as 6 marcas da fase (`PublicacaoDoPortaoService`, `PublicadorPortaoPublicacao`,
`portao-publicacao`, `tentarDeNovo`, `aoAprovar`, `193`) dão **zero** naquele arquivo, e a **isca (+)**
prova que a sonda **acusa** um sintético que mencione a área nova.

**O GATE DE ESCRITA PRÓPRIO DA ÁREA NOVA:** zero `Http::`/`curl_`/`Guzzle`/`'POST', '/items'`//`items`
nos 5 arquivos lidos **sem comentário** (2.993 linhas), com **isca do leitor** (4 ocorrências no texto
cru), controle positivo no serviço reusado, `onQueue('high')` lido do código e `ContasLiberadas::exigir`
no `iniciar()`.

---

## §5 — ⚠️⚠️ A SONDA ESTREITA DO `PUT /items`, E O 11º ERRO DE BRIEFING RE-MEDIDO POR MIM

```
grep -rnE "'PUT',\s*'/items" app/Services/Publicador/    ->  ZERO   (invariante SUSTENTADO)
grep -rnE "'PUT'.*'/[^']*items" app/Services/Publicador/ ->  AlterarNoConvite.php:155
                                                              ^^ a LARGA acusaria TRABALHO CERTO
```

O briefing afirmou que *"não existe NENHUM `PUT` no Publicador"*. ⚠️ **Existem TRÊS**
(`AlterarCampanha:211`, `AlterarCupom:146`, `AlterarNoConvite:155`), e o terceiro usa
**`PUT /seller-promotions/items/`**, que **CONTÉM** `/items/`. ➡️ O invariante verdadeiro — e o que
sustenta a **decisão 22** — é ***"nenhum `PUT` cujo caminho COMEÇA em `/items`"***, e ele é
**VERDADEIRO**. ⚠️ **As duas pontas estão asseveradas no gate**, e a prova de que preferir a estreita
não é gosto: a larga **acusa** o arquivo legítimo.

---

## §6 — NADA SEM DESTINO: **16** ROTAS PELO ROUTER (e não 11)

```
ROUTER (artisan route:list --json), prefixo mlb.anuncios.meus.aprovacao  ->  16
```

⚠️⚠️ **A `193-COBERTURA.md` §1 (critério 9) escreve *"as 6+5 da 188 mais nenhuma"*, o que soma 11.**
O número do artefato está **ERRADO**; o do ROUTER é o que vale, e é por isso que o conjunto é
congelado **por NOME**, nunca por contagem citada de artefato. ⚠️ **Não editei a `193-COBERTURA.md`** —
artefato do planejador. **Devolvido** (item 9 do `deferred-items.md`).

**Zero coluna nova, zero `.jsx`:** provado como propriedade do **território declarado** (nenhum
caminho sob `database/migrations/` nem sob `resources/`), com **controle positivo das 5 naturezas** —
senão os dois `assertSame([], …)` seriam **vazios por construção** — e com os **três do GATE-07**
asseverados **fora** da lista e **existentes no disco**.

**As 9 formas de segundo passo**, na **UNIÃO dos 5 arquivos** — e este é o único lugar que as varre
juntas (cada wave rodou só a **sua** lista). A sonda é a **UNIÃO** das duas que já existem: a do
`186-09` **não** casa a prosa acentuada (*"Confirmar publicação"*) e a do `188-05` faz o **inverso**.

---

## §7 — AS BASELINES, RE-MEDIDAS, COM HEAD NAS DUAS PONTAS E ISOLAMENTO PROVADO

```
PARTIDA   0b9c51e0
COMMIT    87f29f2e
BUILD     exit REAL 0 · 98 s · "built in 1m 32s"    HEAD 73bcac1c nas DUAS pontas
MANIFEST  500 / 204   (controle Aprovacao.jsx PRESENTE, isca NaoExiste9z7.jsx AUSENTE)
JS        6031 / 6030 pass / 1 fail / 0 skipped     exit REAL 0   HEAD 73bcac1c nas DUAS pontas
PHP       2702 / 25119 / 0                          exit REAL 0   HEAD 87f29f2e nas DUAS pontas
PROTOTIPO design_handoff_publicador/Aprovacao.dc.html · 1432 l. · sha256 68624883ce35547e
```

**Isolamento PROVADO POR LINHA DE COMANDO:** `php.exe` = **0** antes das rodadas de PHP; **16**
`node.exe` por NOME e **ZERO** sendo `node --test`/`vite`/`npm run` (Adobe CC, 9 runtimes do Codex,
MCP `digisac-ecf.mjs` ×2 e `mcp-remote` por `npx-cli.js` ×4). ⚠️ **Filtro por nome acusa falso** — a
quick de ontem mediu **SETE `php.exe` de outra sessão** rodando **o mesmo recorte** nesta árvore.

⚠️ **Uma contaminação DECLARADA em vez de omitida:** na rodada 1 o script gravou
`PHP_EXE_VIVOS_ANTES=1` (origem **não identificada**, provável resíduo meu); na rodada 2 — **a que
vale** — gravou **`0`**.

⚠️⚠️ **A SUÍTE PHP COMPLETA NÃO É MEDÍVEL NESTA MÁQUINA** (Fatal de memória em
`FileFactory.php:87`, a ~6% de 10.653, e `-d memory_limit=-1` **não resolve**, porque o estouro é em
processo **FILHO**). ➡️ O número é o **RECORTE**, e **eu digo isso**.

| linha | referência | agora | Δ | atribuição |
|---|---|---|---|---|
| PHP testes | **2692** (quick) | **2702** | **+10** | **MEUS**, e fecha na vírgula: os 10 métodos de `FechamentoDaFase193Test.php`, por `--diff-filter=A` (**nunca por range**) |
| PHP falhas | 0 | **0** | 0 | ✅ (a rodada 1 deu **1**, **minha**, consertada — §9) |
| JS testes/falhas | 6031 / 1 | **6031 / 1** | 0 | a falha é a **pré-existente da Fase 87**, atribuída por **execução ISOLADA**: `19 / 18 pass / 1 fail / 0 skipped`. **NÃO corrigida** |
| MANIFEST | 500 / 204 | **500 / 204** | 0 | e o invariante **real** provado: `numstat` **VAZIO** em `resources/js/` |
| asserções | 24.988 | **25.119** | +131 | ⚠️ **INFORMATIVO** — o harness tem `REF_PHP_TESTS` e **não** tem referência de asserções |

⚠️⚠️ **NENHUM `REF_PHP_FALHAS` FOI CRIADO.** ⚠️ **O `+2 / +1` do manifest é ALHEIO** (`Agente.jsx`,
criado por `56ade5f0`); o **+1 não-página** (295 → 296) **não é isolável sem `git checkout`**, que é
**proibido** — e está **provado que não é da 193**.

---

## §8 — OS GATES, COM O `EXIT_REAL` POR `$?` NA LINHA SEGUINTE

| id | medido | manifesto |
|---|---|---|
| `193-05 territorio-e-d11` | **3 / 43**, `EXIT_REAL=0` | 43 ✅ |
| `193-05 nada-sem-destino` | **3 / 15**, `EXIT_REAL=0` | 15 ✅ |
| `193-05 bijecao-dos-cinco-manifestos` | **1 / 24**, `EXIT_REAL=0` | 24 ✅ (**REMEDIDO** 21 → 24) |
| `193-05 fechamento-de-forma-fixa` | **3 / 51**, `EXIT_REAL=0` | 51 ✅ (**REMEDIDO** 49 → 51) |

**Os ALHEIOS, re-rodados COM o meu arquivo no disco e de novo no HEAD final — todos IDÊNTICOS:**
`186-04 unica-porta` **43** · `186-04 oferta-do-portao` **67** · `186-09 transversais` **259** ·
`186-09 sem-segundo-passo` **53** · `186-09 andamento-um-lugar-so` **21** ·
`186-09 bijecao-e-homonimo` **31** · `188-05 aprovar-e-enfileirar` **85** ·
`188-05 tentar-de-novo` **32** · `188-05 ausencia-de-segundo-passo` **17** ·
`188-07 casca-do-painel` **45/45/0** · `193-01..04 bijecao-manifesto-*` **20 / 32 / 33 / 31**.

⚠️ **O `188-05 tentar-de-novo` estava VERMELHO no fim da wave 04** (`2 failed (2 assertions)`) e a
quick `261021-fix` o consertou **por método**; **confirmado independentemente por mim**: `2 / 32`.
⚠️ **O `186-04` não poderia subir** (o glob é não recursivo e esta wave não cria arquivo em
`app/Services/Publicador/Aprovacao/`) — **se tivesse subido, era PARADA**.

**A isca do 1º argumento, medida AO VIVO:**

```
'05 territorio-e-d11'     ->  EXIT_REAL=2  ABORTOU: id nao esta em …/contagens-186-05.txt
'193-5 territorio-e-d11'  ->  EXIT_REAL=2  ABORTOU: manifesto nao existe …/contagens-193-5.txt
```

⚠️⚠️ **E repare:** o número **NU** resolveu `contagens-186-05.txt`, que **EXISTE** — só abortou porque
o **id** não está lá. ➡️ **Se o nome coincidisse, o harness rodaria o gate da fase ERRADA, em
SILÊNCIO, e ficaria VERDE.**

**`--autoteste`: 20 casos, 0 divergência, `EXIT_REAL=0`** (rodado duas vezes: no meio e no fim; a
saída integral está colada no `193-FECHAMENTO.md` §12).

**A bijeção dos CINCO manifestos:** `ids = 22` · `metodos _193_0N = 50` · **`SEM_ID=0`** ·
**`IDS_REPETIDOS=0`** · **`HOMONIMOS=0`** · `ORFAOS=4` ⚠️ **CONGELADOS e DECLARADOS, com dono** (ver
§11).

---

## §9 — ⚠️⚠️ OS NOVE FUROS QUE EU ACHEI NAS MINHAS PRÓPRIAS SONDAS

**1 — o `EXIT_REAL` lido DEPOIS de um pipe.** A primeira leitura do arquivo do D-11 foi
`node --test … | grep` com `$?` depois: deu **0**, que era o exit do **`grep`**. ⚠️ O real **também**
era 0 — mas isso é **sorte, não método**.

**2 — a isca da interseção de território saiu com `comm: file 1 is not in sorted order`**, porque eu
injetei o caminho **depois** do `sort`. Refeita, ela **ACUSOU**. ⚠️ Sem refazer, eu registraria *"a
isca não acusou"* como propriedade da sonda, quando era erro do meu pipeline.

**3 — a sonda de `PUT /items` que eu quase escrevi era a LARGA** (§5).

**4 — a sonda do D-22 por `/D-?22/` engoliria o teste de ABAS** (§4).

**5 — eu ia congelar a contagem de EXECUÇÃO (30) como se fosse a de DECLARAÇÃO (24)** (§4).

**6 — ⚠️⚠️ EU CAUSEI UMA REGRESSÃO, E QUEM A ACHOU FOI A SUÍTE COMPLETA.** O
`193-03 bijecao-manifesto-193-03` ficou **VERMELHO**:

```
⨯ 193-03 > o manifesto tem bijecao com os metodos e nenhum id orfao 193 03
  + 0 => 'FechamentoDaFase193Test.php::test_avisos_do_ml_aceitos_vao_para_o_historico_193_03_extra'
```

O varredor daquela wave lê o **TEXTO CRU** (comentário incluído), e o meu docblock **citava o literal
inteiro** da isca dela, com a palavra-chave de declaração **colada ao nome**. ⚠️⚠️ **É o FURO 1 DA
WAVE 04 AO CONTRÁRIO:** lá o nome alheio estava numa **asserção**; aqui, na **PROSA**. ➡️ **A regra
*"o nome alheio entra por VARIÁVEL"* vale para comentário também.**
**Conserto na raiz** (`602b4d62`): a prosa parou de escrever a forma de declaração **e** nasceu um
**gate próprio** que a proíbe neste arquivo, com isca nas duas pontas.
⚠️ **O conserto errado, que eu NÃO fiz:** pôr o meu arquivo numa exceção do gate alheio, ou alterar
aquele gate. **Custo medido: 1122 s de suíte descartados.**

**7 — e o gate que eu criei no furo 6 nasceu MAIS LARGO QUE O INVARIANTE.** Ele varria **todas** as
declarações da suíte, inclusive helpers de nome comum em pt-BR (`linha`, `gate`, `rota`, `conta`,
`ler`, `passo`, …) que são **substring dos MEUS PRÓPRIOS** métodos: acusou **14**, e **todos eram
meus**. Estreitado para nomes `test_`. ⚠️ **Dois furos da mesma família em cinco minutos.**

**8 — a sonda de *"a suíte PHP não é medível"* exigia MINÚSCULA e vizinhança imediata**, e o documento
escreve **maiúscula** com `**COMPLETA**` (13 chars de markdown) no meio: ela **reprovou o documento
correto**. ➡️ `/iu` + `[^\n]{0,40}`, com **isca (−)** provando que ela **não** casa a afirmação
contrária. ⚠️ É o lado **ESTREITO** da família *"a frase quebra no markdown e a sonda não"*.

**9 — o meu conjunto de ÓRFÃOS tinha CINCO e são QUATRO.** O gate **reprovou nomeando a divergência**:
o `193-01-PLAN.md` **DECLARA** o id de bijeção dele, logo **não é "auto-referência por natureza"** — as
waves 02/03/04 **simplesmente não o declararam**. ⚠️ **O harness funcionando é parte da prova.**

---

## §10 — O TERRITÓRIO: `PI(193-05)` POR ESCOPO DO ASSUNTO

```
PASSADA A:  git log --grep='(193-05)' | filtro por %s                       ->  8 commits
PASSADA B:  git log --all --format='%h|%s' | awk sobre %s (SEM --grep)      ->  8 commits
diff A B                                                                    ->  (IDÊNTICOS)
arquivos distintos dos 8                                                    ->  4
interseção com os 24 proibidos                                              ->  (VAZIA)
ISCA (sort DEPOIS da injeção)                                               ->  ACUSOU
```

Os 4 arquivos: `193-FECHAMENTO.md`, `deferred-items.md`, `FechamentoDaFase193Test.php`,
`contagens-193-05.txt`. ⚠️ **Nada em `app/`, `resources/`, `routes/`, `database/migrations/`, e nada
nos três do GATE-07.**

### AS TRÊS FACES

| face | medição |
|---|---|
| **sobre-captura** (`--grep` lê a mensagem inteira) | **neutralizada** pelo filtro por `%s`: as duas passadas devolveram o **mesmo** conjunto |
| ⚠️ **sub-captura** | medida **À MÃO, por diferença**: o range `0b9c51e0..HEAD` tem **8** commits e **todos os 8 são do escopo** — a `FACE 2` veio **VAZIA**, **nenhum** vizinho commitou durante esta wave |
| **o autor** | ⚠️ **vazio por construção**: **CINCO** identidades no repositório (`MB.ECF-100376` 3727, `ECF Dev` 2008, `Dev ECF` 57, `root` 5, `ecfmaycon` 4) e os **8** commits são **todos da primeira**, a mesma das waves alheias. **NUNCA usei `%an`** |

⚠️⚠️ **A RESSALVA DA FACE 2, DECLARADA:** *"não commitou" ≠ "não está trabalhando"*. Na quick de
ontem a FACE 2 veio **vazia** e o vizinho **estava rodando campanha de PHPUnit nesta árvore** — e é
por isso que o isolamento é provado por **linha de comando** (§7), **não** pelo silêncio do `git log`.

⚠️ **`test -e` em CADA caminho citado: 32/32 OK, ZERO fantasma**, com **TRÊS iscas** confirmadas
ausentes (`app/Services/Mlb/MlPublicacaoService.php`,
`app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php`,
`new_layout_publicador/Aprovacao.dc.html`).

---

## §11 — A CAMPANHA DE MUTAÇÃO: **45** NA FASE, **9** NESTA WAVE, **3 SOBREVIVENTES**

O alvo desta wave é o **`193-FECHAMENTO.md`** — ⚠️ mutar o próprio arquivo de teste **não provaria
nada** (o teste **é** o gate).

| wave | mutações | sobreviventes |
|---|---|---|
| `193-01` | **0** | ⚠️ **declarado:** nada de produção novo para mutar |
| `193-02` | 5 | ⚠️ **2** (as duas, furo REAL, consertadas na raiz) |
| `193-03` | 13 | 0 |
| `193-04` | 9 | 0 |
| `261021-fix` | 9 | 0 |
| **`193-05`** | **9** | ⚠️ **3** |
| **TOTAL** | **45** | **5** |

| # | mutação | veredicto |
|---|---|---|
| m1 | um critério inteiro desaparece | **MORREU** |
| m2 | veredicto inventado | **MORREU** |
| m3 | a RESSALVA perde o dono (`· dono:` → `· prova-no-dono:`) | ⚠️ **SOBREVIVEU** → furo REAL |
| m4 | uma pergunta ganha `dono: a definir` | **MORREU** |
| m5 | um `ALVO-INTOCADO:` vira caminho FANTASMA | ⚠️ "sobreviveu" por **erro meu de medição** |
| m6 | a numeração do roteiro quebra | **MORREU** |
| m7 | o documento passa a afirmar ter visto a tela | **MORREU** |
| m8 | veredicto válido que faz o placar não fechar | **MORREU** |
| m9 | um `ALVO-INTOCADO:` é **REMOVIDO** | ⚠️ **SOBREVIVEU** → furo REAL do PISO |

**m3 — FURO REAL.** A sonda era `/dono:\s*\S/u` e casava `dono:` **no MEIO de outra palavra**: uma
**RESSALVA SEM DONO** passaria com qualquer palavra terminada em `dono:`. ➡️ **Conserto na raiz:**
`SONDA_DO_DONO` com **borda à ESQUERDA** e o **HÍFEN na classe de exclusão** (foi um hífen que a
mutação usou), nos **dois** pontos que leem dono, + as duas iscas. Manifesto **REMEDIDO** 49 → 51,
razão **em linha**.

**m5 — NÃO era furo: era erro de MEDIÇÃO meu.** O anti-fantasma mora no id `territorio-e-d11` e eu
rodei a mutação contra o `fechamento-de-forma-fixa`. ⚠️ *"Sobrevivente é investigada"* valeu aqui para
**absolver** a sonda: contra o id certo, **MORREU**.

**m9 — FURO REAL do PISO.** O gate media a lista por `>= 20`, e com **32** alvos apagar um deixa
**31**. ⚠️ Família ***"piso `>= N` NÃO prova PRESENÇA"***, a mesma da sobrevivente **M5** da 187 — e o
risco é concreto: *"tirar alvo da lista"* é **o conserto barato** que o learnings manda evitar.
➡️ **Conserto na raiz em duas camadas:** `ALVOS_INTOCADOS = 32` por **tamanho EXATO** (frágil por
construção, **declarado**) **+** `ALVOS_CRITICOS` (**9**) por **presença explícita**, para que nem uma
**troca** que preserve o total tire um crítico. ⚠️ **Subconjunto, não a lista inteira**, para não criar
duas verdades livres para divergir.

**A forma, cumprida:** commit **ANTES**; `cp -p` para **fora** do repo; `mutar-193-05.sh`
(`sha256 72c27f96c9407572…`) e `mutar-193-05-rodada2.sh` (`sha256 3ed4dfd124ecf39f…`), **os dois com
o `sha256` impresso ANTES do veredicto e RECONFERIDO igual no fim**; **validação seca das 8 âncoras**
(todas **VIVAS**); ocorrência **travada em 1**; aplicação por **divergência de `sha256`**; **linha
mudada** impressa depois; restauração **DA CÓPIA** em `trap EXIT`; `sha256` final contra
`git show HEAD:` com `tr -d '\r'` nos dois lados — **OK nas duas rodadas**. ⚠️⚠️ **`git checkout`
NUNCA.** ⚠️ **Em todas as 9 o `# tests` do mutado bate com o do pristino** (**3** = pristino), logo
cada morte é **da asserção**, não de **boot**.

### ⚠️⚠️ UM ACHADO DE QUARTA ORDEM: O GATE REPROVOU O PRÓPRIO FECHAMENTO, DUAS VEZES

Ao **descrever** a mutação m7, eu escrevi no documento a **forma exata** que ela injeta — e o gate
reprovou (`1 failed, 2 passed`). Reescrevi; ele reprovou **de novo**, agora pegando a minha **citação
da mensagem de erro do próprio gate**.

⚠️ É a família *"gate que reprova pelo próprio conserto"* — **mas aqui o GATE ESTÁ CERTO e a PROSA é
que se adapta.** Uma sonda que abrisse exceção para *"a tabela de mutação"* seria uma **porta dos
fundos de uma linha**. ➡️ A forma proibida vive no **script de mutação**, fora do repositório.
⚠️⚠️ **E a distinção é a lição:** quando a prosa cita o literal proibido **para explicar o acerto**
(como fazem os 5 arquivos desta fase com as nove formas de segundo passo), o conserto é **ler sem
comentário**; quando a prosa **AFIRMA** a coisa proibida, o conserto é **não afirmar** — e *"ler sem
comentário" não serve em markdown*, que não tem comentário.

---

## §12 — ⚠️ CRITÉRIOS DE ACEITAÇÃO DO **MEU** PLANO QUE EU RODEI E ACHEI VAZIOS, FRACOS OU FALSOS

| critério | veredicto medido |
|---|---|
| `key_link`: *"`git diff --numstat` DENTRO do teste"* | ⚠️⚠️ **RECUSADO, com medição:** seria **auto-invalidante** (base fixa, range que cresce **para sempre**) e ficaria vermelho por **trabalho alheio correto**. Família consertada **17** vezes nesta milestone na raiz e **zero** por elevação — e o 17º caso foi **ontem**. Os fechamentos das Fases **188 e 189** já tomaram esta decisão (*"NÃO usa range de git — nem git nenhum"*). ➡️ O `numstat` foi medido em linha de comando e está **COLADO** no documento |
| *"range cru `<partida>..HEAD` como INFORMATIVO"* | ⚠️ **VAZIO POR CONSTRUÇÃO no início**: `rev-list --count` deu **0** (PARTIDA == HEAD). Registrado como medição e **substituído** pela janela da **FASE INTEIRA** (26 commits), que é **estritamente mais forte** |
| *"as 6+5 da 188"* | ⚠️ **o número do artefato está ERRADO**: o ROUTER mede **16** |
| *"`numstat` vazio"* como prova de *"D-11 intacto"* | ⚠️ **FRACO sozinho** (prova que **esta janela** não o tocou, não que ele está íntegro). ➡️ + igualdade de conjunto dos nomes, + contagem de `assert.`, + execução **isolada** |
| *"os 9 literais ausentes nos arquivos desta fase"* | ✅ mas cada wave rodou só a **SUA** lista. ➡️ Este é o único lugar que roda a **UNIÃO** |
| *"nenhuma coluna nova (nenhuma migration)"* | ⚠️ **duplicaria** o `193-01 schema-sem-migration` (3/50). ➡️ Reancorado no que **este** plano pode provar sem repetir, com **controle positivo das 5 naturezas** |
| *"gate `js`"* no manifesto desta wave | ⚠️ **NÃO ENTROU, declarado:** os dois `.js` são **INTOCÁVEIS** e já têm medição isolada; um id `js` aqui rodaria arquivo alheio sob o número desta wave |
| *"igualdade de conjunto das asserções do D-11"* por contagem de `assert.` | ⚠️ **fraca sozinha** (contagem igual com conteúdo trocado passaria) → a **igualdade dos 24 nomes** é a asserção principal |
| *"o critério 10 é RESSALVA porque só é executável depois da Fase 194"* | ⚠️ **a razão MUDOU e ficou mais perto:** `PreparadorDaAprovacao:148` **já cria oferta** ⇒ basta o **piloto do agente** |

---

## §13 — ⚠️⚠️ AS TRÊS AFIRMAÇÕES DO MEU PRÓPRIO PLANO QUE FICARAM FALSAS

1. **`193-05-PLAN.md:201-202`** — *"nada em `app/` cria oferta"*: ⚠️ **FALSO**.
   `app/Services/Publicador/Agente/PreparadorDaAprovacao.php:148` chama **`PubAprovacaoOferta::create`**
   (quick `261020-oeg`, **outra sessão**). ⚠️ Nenhuma loja tem o agente ligado, então a tabela **segue
   vazia em produção** — mas o **critério 10 antecipou**: é executável **depois do piloto do agente**.
2. **o invariante do manifest "498/203"**: ⚠️ **REANCORADO** — é *"a 193 não mexe no manifest"*,
   provado por `numstat` vazio. Hoje **500/204**, e o `+2/+1` é **alheio**. **Não subi número.**
3. **o invariante do `contagens-186-09.txt` "contagem idêntica"**: ⚠️ **REANCORADO** — é *"contagem
   não mudada POR VOCÊ"*. Está em **259 / 53 / 21 / 31**, remedido por commit **alheio** com a razão
   escrita **em linha**. **Não é regressão.**

⚠️ **E uma quarta, menor:** *"as 6+5 da 188"* da `193-COBERTURA.md` (§6). **Devolvida.**

---

## §14 — OS QUATRO ITENS COM DONO **USUÁRIO / ORQUESTRADOR** — registrados, **NÃO** consertados

1. **A família *"gate mede WIP, não território"*:** **24 pontos em 7 arquivos** chamam `numstatDe(rel)`
   **sem o 2º argumento**, comparando a **ÁRVORE**; mais dois `git status --porcelain`. ⚠️ O conserto
   de raiz **JÁ EXISTE no código** (`casca-188:549` usa range `HEAD~1 HEAD`).
2. **O `186-09 sem-segundo-passo` não pega a forma pt-BR acentuada**, e a do `188-05` faz o inverso;
   ⚠️ **nenhuma das duas, isolada, cobre as nove formas**, e alargar o do `186-09` é delicado porque
   ele varre **`app/` inteiro** — foi um gate dessa natureza que **BARROU UM DEPLOY**.
3. **Os ponteiros do `189-FID01.md` §G apodreceram TRÊS vezes**, e um atribui MOD-06 à **Fase 190,
   CANCELADA**. ⚠️ **Não é artefato desta fase.**
4. **O id `guarda-modalidade-irma` está ÓRFÃO de `<verify>`.** ⚠️ Ele **não falha: NUNCA RODA** por
   iniciativa do plano. A causa é medida (nasceu no meio da wave 02 com a decisão 23, e o PLAN.md é
   artefato do **planejador**). ⚠️ **O id FUNCIONA** (2/25). O conserto é uma linha no `<verify>` do
   `193-02-PLAN.md`.

⚠️ **E o 17º gate de âncora frágil consertado na raiz foi do orquestrador, ontem** (`d1cd7713`): o
`casca-188` era **auto-invalidante por janela deslizante**, e o vermelho **escondia** que as duas
passadas de território asseveravam `[] === []`. **Re-medido por mim: 45 / 45 / 0.** ➡️ **17 consertos
na raiz, ZERO por elevação de número.**

---

## §15 — O QUE ESTA WAVE **NÃO** FEZ (é a régua, não omissão)

Nenhuma migration · ⚠️ **`artisan migrate` NÃO rodado** (e o ritual `migrate → rollback → migrate`
**não se aplica**, porque não há DDL — **declarado**, com a receita guardada) · nenhum `.jsx` · nenhuma
rota, estado, rótulo, toast, coluna, job ou fila novos · nenhum serviço das Fases 186-189 alterado ·
`PublicacaoService` e `MlPublicacaoService` com `numstat` **VAZIO** · `app/Services/Publicador/Agente/`
intocado · `ROADMAP`/`STATE`/`REQUIREMENTS-v27` **não editados** (GATE-07) ·
⚠️ **`state.advance-plan` NÃO rodado** (corrompeu o STATE **seis** vezes) · `pint` não rodado ·
worktree não criado · `Write` não usado em arquivo existente · ⚠️ **nenhum `git add -A`, `git stash`,
`git reset`, `git checkout`, `git clean` ou `--amend`** · os **27 `stash`** apenas **contados** ·
⚠️ **nenhum deploy, push, comando no VPS ou chamada à API real do Mercado Livre**.

⚠️ **O MariaDB local está DE PÉ** (PID **30496**, outra sessão, 20/10 21:45) **e não afeta nada**:
`phpunit.xml:27-28` fixa `sqlite` `:memory:`. ⚠️ **A `193-REGUA.md` §2 diz "PARADO" e ficou obsoleta**
— devolvido.

⚠️⚠️ **E eu NÃO rodei uma terceira vez o `--suite php` inteiro** depois dos dois consertos de mutação.
➡️ **O que está provado é o que está escrito:** a contagem de **testes não pode** ter mudado (nenhum
método novo), e **os DEZ gates que leem o arquivo alterado** foram re-rodados no HEAD final
`bd0574ba`, **verdes e idênticos**. ⚠️ *Quem quiser o número do HEAD final, mede o HEAD final.*

---

## §16 — O CHECKPOINT HUMANO (critério 10) — **RESSALVA DECLARADA**

`PERGUNTA-VISUAL-193: ABERTA — dono: usuário`, em **linha própria e forma fixa**, com o **roteiro de 8
passos numerados** no `193-FECHAMENTO.md` §CHECKPOINT.

⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU TELA RENDERIZADA, E NENHUM AFIRMA QUE VIU.** Sem navegador, sem
empresa com `ml_token` (a rota dá **404**), com `pub_aprovacao_ofertas` **vazia em produção**, **sem
deploy** e sem o cron visto rodando. A prova é **`Http::fake()` + `preventStrayRequests()`** (e
`Queue::fake()`). ⚠️ **As conferências visuais das Fases 187, 188 e 189 seguem ABERTAS e SEPARADAS.**

⚠️ **O roteiro só é executável depois de:** *(a)* **deploy autorizado pelo usuário**, e *(b)* existir a
primeira oferta — ⚠️ **e agora basta o PILOTO DO AGENTE**, não a Fase 194. O passo 6 diz com todas as
letras que **"Publicado" sem o MLB é REPROVA**.

---

## §17 — Deviações do plano

### Auto-corrigidas (Regra 1 — defeito no meu próprio artefato, achado por MEDIR)

1. **A regressão do docblock** (§9 furo 6) — `602b4d62`.
2. **O gate novo mais largo que o invariante** (§9 furo 7) — `602b4d62`.
3. **A sobrevivente m3**, a borda do dono — `761ab6a4`.
4. **A sobrevivente m9**, o piso da lista — `bd0574ba`.
5. **O conjunto de órfãos** de 5 para 4, corrigido **pelo gate**.
6. **A sonda de *"não é medível"*** reancorada com `/iu` e `[^\n]{0,40}`.
7. **A prosa que reprovava o próprio fechamento**, duas vezes.

### Deviações de desenho, declaradas

1. ⚠️ **O gate NÃO chama git**, contra o `key_link` do plano — com a medição que sustenta (§12).
2. ⚠️ **Ordem:** o documento foi commitado **antes** do gate, porque é o **sujeito** dele (§1).

### Nenhuma Regra 2, 3 ou 4 foi acionada

Nenhum pacote instalado, nenhuma funcionalidade nova, nenhuma decisão arquitetural.

---

## §18 — ⚠️ O QUE O ORQUESTRADOR TEM DE MARCAR À MÃO

| o quê | por que à mão |
|---|---|
| **Coverage Map** e a linha `**Plans**:` da Fase 193 no `ROADMAP.md` (hoje **`TBD`**) | `roadmap update-plan-progress` **reporta sucesso e não escreve nada** |
| **PUB-01..10** e os `TERR-*`/`GATE-*` no `REQUIREMENTS-v27.md` | `requirements.mark-complete` devolve **`not_found`** para IDs de `REQUIREMENTS-vNN.md` |
| o bloco de fechamento no `STATE.md`, **ADITIVO** | ⚠️⚠️ **`state.advance-plan` já corrompeu o STATE SEIS vezes.** Conferir `--numstat` com **0 linhas removidas** |
| a linha **`PLACAR-193: OK=9 RESSALVA=1 REPROVA=0`** | para a fase seguinte ler o portão de entrada |
| a correção de *"as 6+5 da 188"* → **16 rotas** na `193-COBERTURA.md` | artefato do planejador — **devolvido** |
| a correção de *"o MariaDB local está PARADO"* na `193-REGUA.md` §2 | idem (está **DE PÉ**, PID 30496, e não afeta nada) |
| o `<verify>` ausente do **`guarda-modalidade-irma`** em `193-02-PLAN.md` | idem — o id **funciona**, mas nenhum plano o invoca |
| `STATE.md:4513` (*"1407 linhas"* vs **1432** reais) e as **duas** tabelas de requisitos do `ROADMAP.md` que **discordam** | inconsistências herdadas, medidas pelas waves |
| ⚠️ **liberar a outra sessão para o AG-05b** | ela **esperou** este fechamento porque o gate de `numstat` do PUB-03 reprovaria. **Com este fechamento, está liberada** |

---

## §19 — Known Stubs

**Nenhum.** Esta wave não cria componente, rota, coluna nem fluxo: ela **mede**. O único "vazio" é o
**critério 10**, e ele está declarado como **RESSALVA com dono**, não como stub.

---

## §20 — Self-Check: PASSED

**Arquivos, conferidos no disco:**

```
OK   .planning/phases/193-…/193-FECHAMENTO.md        (1055 linhas, medidas AGORA)
OK   .planning/phases/193-…/deferred-items.md        (227 linhas)
OK   .planning/phases/193-…/193-05-SUMMARY.md        (este, 617 linhas)
OK   tests/Feature/Publicador/Aprovacao/FechamentoDaFase193Test.php
OK   tests/gates/contagens-193-05.txt
```

**Commits, conferidos por `git log --grep='(193-05)'`:** `2f888014` · `73bcac1c` · `602b4d62` ·
`87f29f2e` · `eb1dc77f` · `761ab6a4` · `bd0574ba` · `993f01b9` — **8**, todos do escopo, mais o desta
SUMMARY.

**Gates, no HEAD final:** `193-05` **4/4 verdes** (43 · 15 · 24 · 51) · os **12 alheios idênticos** ·
`--autoteste` **20/0** · `--suite js` **6031/6030/1/0 exit 0** · `--suite php` (recorte)
**2702/25119/0 exit 0**.

---

## §21 — Threat Flags

**Nenhuma.** Esta wave não cria endpoint, caminho de autenticação, acesso a arquivo nem mudança de
schema em fronteira de confiança. ⚠️ A única superfície que a **fase** ampliou (a linha de histórico da
recusa do retry sobrevivendo ao rollback, decisão 26) foi declarada pela quick `261021-fix`: é **texto
de tela**, com **ator Sistema**, e a tabela continua **APPEND-ONLY**.
