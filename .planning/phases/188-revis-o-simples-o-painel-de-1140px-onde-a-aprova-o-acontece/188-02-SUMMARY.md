---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 02
subsystem: Publicador / Aprovação / Painel de revisão simples (servidor)
tags: [publicador, aprovacao, painel, view-model, servidor, inertia, gates, mutacao]
requires:
  - "app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php (Fase 186, EM PRODUÇÃO)"
  - "app/Services/Publicador/Aprovacao/VocabularioDoPortao.php (Fase 186, EM PRODUÇÃO)"
  - "app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php (Fase 186, EM PRODUÇÃO)"
  - "app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php (Fase 187, EM PRODUÇÃO)"
  - "app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php (Fase 187, EM PRODUÇÃO)"
  - "app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php (Fase 187, EM PRODUÇÃO)"
provides:
  - "PainelDeRevisaoService::para(oferta, base) — o view-model do painel E2, montado no servidor"
  - "a prop `painel` no payload de `mlb.anuncios.meus.aprovacao`, recortada ao `?oferta=`"
  - "a lista de navegação ↑/↓ como DADO (ids sem `preparando`, anterior/próxima, posição, total)"
  - "os NOVE destinos de correção, resolvidos no servidor"
  - "as chaves `fotos` (plano 188-03) e `preco` (plano 188-04), nascidas null e declaradas"
affects:
  - "tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php (lista de EXPOSITORES da camada B)"
  - "tests/gates/contagens-186-09.txt (dois ids REMEDIDOS)"
tech-stack:
  added: []
  patterns:
    - "view-model montado no servidor; a tela LÊ o veredicto e não o calcula (D9)"
    - "navegação entregue como DADO (ids), nunca como string"
    - "destino de correção como DADO resolvido no servidor, nunca `switch` no .jsx"
    - "chave de contrato nasce null e DECLARADA, nunca ausente"
key-files:
  created:
    - "app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php"
    - "tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php"
    - "tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php"
    - "tests/gates/contagens-188-02.txt"
  modified:
    - "app/Http/Controllers/MlbPublicadorAprovacaoController.php"
    - "tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php"
    - "tests/gates/contagens-186-09.txt"
    - ".planning/phases/188-.../188-FID01.md"
decisions:
  - "O destino de `frete_ausente` tem DOIS ramos, derivados da correção que a 186 já resolve (desvio Regra 1)"
  - "O painel recebe o Builder `$base`, em vez de remontá-lo: o ↑/↓ não pula para fora do recorte"
  - "`PainelDeRevisaoService` entra na lista de EXPOSITORES da camada B COM PROVA, e `contagens-186-09.txt` foi REMEDIDO"
metrics:
  commits: 4
  gates: 9
  mutacoes: 10
  sobreviventes: 1
---

# Phase 188 Plan 02: O servidor do painel de revisão simples — Summary

`PainelDeRevisaoService` monta o view-model do painel E2 **no servidor** (identificação, caixa de
falha, pendências com os **nove destinos de correção**, rodapé com `aprovavel` lido e motivo
chamado, `versao` para COL-04 e a **navegação ↑/↓ como DADO**), e o controller passa a entregar a
prop `painel` recortada ao `?oferta=` — com uma leitura de bloqueios por oferta e **zero** chamada
extra quando não há oferta aberta.

---

## PARTIDA

**`0a5d4dc1`** — capturado na **PRIMEIRA ação** do turno (`git rev-parse --short HEAD`), exatamente
o valor que o briefing previa.

⚠️ **Usado só como REFERÊNCIA DE LEITURA, nunca como extremo de range confiável.** O território foi
provado por **ESCOPO DO ASSUNTO** (ver §Território).

**Os 4 commits deste plano:**

| hash | o que entrou |
|---|---|
| `e2aa4263` | `feat(188-02)` — o serviço: os 9 destinos, o rodapé que CHAMA o resumo, a navegação como DADO, 9 casos de teste, os 5 primeiros ids do manifesto |
| `bdecc48a` | `feat(188-02)` — a prop `painel` no controller, o `PainelDaOfertaRotaTest`, e o **SEGUNDO EXPOSITOR** da camada B com prova + `contagens-186-09.txt` remedido |
| `c15a4858` | `test(188-02)` — o **GAP que a sobrevivente da mutação achou**: a PERGUNTA-188-B não tinha trava própria |
| `c0e034b1` | `docs(188-02)` — a **FID-01 §J**: a região do view-model nunca foi re-medida, e tem 6 ponteiros deslocados |

---

## AS 4 BASELINES, RE-MEDIDAS — e a régua nova aplicada

| | ANTES (medido por mim, antes de editar) | DEPOIS | veredicto |
|---|---|---|---|
| **BUILD** | — | **exit 0 · 77 s** | OK (o 188-01 mediu 77 s; idêntico) |
| **MANIFEST** | 495 / 202 | **495 entradas / 202 páginas** · isca ausente ✓ · controle presente ✓ | **IGUAL** — este plano **não cria `.jsx`** |
| **JS** | 5498 / 5497 pass / 1 fail / 0 skipped | **5498 / 5497 pass / 1 fail / 0 skipped** | **IDÊNTICO** |
| **PHP** | **2495 / 21935 / 0** | **2510 / 22264 / 0** | **+15 testes · ZERO falhas** |

⚠️ **O `+15` é EXATAMENTE o número de métodos novos deste plano** (9 no
`PainelDeRevisaoServiceTest` + 3 no `PainelDaOfertaRotaTest` + os 3 transversais já contados nos
nove ids = **15**, conferido pela bijeção). ➡️ **Nada desapareceu da suíte**, e `--suite php` saiu
com **exit 0**.
⚠️ **As falhas foram de `0` para `0`**: os dois casos do `CockpitDaContaTest` ficaram **verdes** nas
duas medições de hoje. **Nenhum teste diferente falhou, e não passou de 2** — logo, **nada aqui é da
fase**.
⚠️ **A contagem de ASSERÇÕES subiu 21.935 → 22.264**, e eu **não a usei como régua** (ela não é
invariante — a wave 1 mediu 21.915 × 21.920 com testes idênticos).

⚠️ **O MANIFEST foi lido PELO JSON**, com script dedicado (`manifest-188-02.cjs`) — **nunca por
grep**: o hash deste build sai **com hífen** (`assets/app--5yuaubs.js`) e o grep não casaria.

### ⚠️ A RÉGUA NOVA DO PHP, aplicada como o briefing manda

➡️ **`0` é o alvo.** Medi **`2495 / 21935 / 0`** como "antes", **com os próprios olhos**, antes de
qualquer edição — ou seja, os dois casos do `CockpitDaContaTest` (Fase 179) estavam **VERDES** hoje,
confirmando a medição da wave 1.

- aqueles **DOIS** casos voltando a vermelho é a **oscilação conhecida de fronteira de dia** — não
  conserto, não conto como regressão, **não criei `REF_PHP_FALHAS`**;
- **qualquer OUTRO teste, ou mais de 2, seria DA FASE** e teria de ser investigado.

⚠️ **E as ASSERÇÕES do PHP NÃO são invariante** (a wave 1 mediu 21.915 × 21.920 com testes idênticos
e zero commits em `tests/`). ➡️ **Comparei TESTES e FALHAS; não persegui a contagem de asserções.**

⚠️ **A única falha JS é a pré-existente**, e eu a identifiquei **por SEMÂNTICA**, não por número de
linha: `not ok 191 - Características secundárias nasce recolhido (é o grupo que mais infla)` —
`tests/js/estrutura-grade-glide.test.js`, Fase 87. **Não corrigida.**

### ⚠️ O achado de ambiente da wave 1, honrado

Rodei **`npm run build` DEPOIS da campanha de mutação e ANTES de medir a suíte JS**, como o briefing
manda. Resultado: **`0 skipped`** — a camada de CSS dos gates **não pulou**.
⚠️ **Nota medida:** no meu caso a campanha só tocou `.php` (nunca `resources/`), então o
`mtime`-vs-CSS não poderia ter mordido de todo jeito. O rebuild ficou como disciplina, não como
conserto — e vale registrar a distinção para a próxima wave não concluir que o rebuild "resolveu"
algo que não aconteceu.

---

## A LISTA DE NAVEGAÇÃO ENTREGUE (critério 2) — o defeito que as duas revisões consertaram

⚠️⚠️ **String não é navegável.** A primeira redação do plano entregava só `pos` (*"3 de 12 na
fila"*), e sem os ids o atalho ↑/↓ da wave 7 **não teria para onde ir** — a tela precisaria
**recalcular a fila no cliente**, que é a segunda verdade que este plano existe para impedir.

**`navegacao` entrega, com estes nomes exatos:**

| chave | tipo | medido |
|---|---|---|
| `ids` | `list<int>` | a fila **ORDENADA**, **SEM** as ofertas em `preparando` |
| `anterior_id` | `?int` | `null` na **primeira** |
| `proxima_id` | `?int` | `null` na **última** |
| `posicao` | `?int` | 1-based; `null` para quem está fora da navegação |
| `total` | `int` | `count(ids)` |
| `pos` | `?string` | `'{i} de {N} na fila'`; **`null`** fora da navegação |

**Provado com fixture de 4 ofertas, UMA em `preparando`** (com uma oferta só nada disso se
distingue — foi a falha de fixture das duas sobreviventes da 187-06):
`ids` tem **3** · a 1ª dá `anterior_id = null` · a 3ª dá `proxima_id = null` · a do meio tem as duas
pontas · a de `preparando` dá `posicao = null` **e** `pos = null`.

⚠️ **A ordem vem de `AndamentoDaOfertaService::ordenarFila()` — a ÚNICA PORTA** — e o filtro de
`preparando` é aplicado **sobre o resultado dela**, nunca com uma segunda consulta.
⚠️ **O recorte é o Builder `$base`** (a conta pelas duas âncoras **+** os filtros, **menos** a
etapa), recebido do controller: é isso que impede o ↑/↓ de pular para fora do recorte em que a
pessoa está. Há caso provando que oferta de **outra conta** não entra em `ids`.

### ⚠️ UMA DIVERGÊNCIA DO PROTÓTIPO, e ele PERDE (não é decisão nova)

| fonte | o que produz para a oferta em `preparando` |
|---|---|
| protótipo `:1318` | `indexOf` devolve **−1**, e a frase sai **"0 de 3 na fila"** |
| produto | `pos = null`, e a tela **não desenha** a frase |

➡️ **"0 de N" é a tela afirmando uma posição que não existe.** O próprio critério 2 já manda *"a
tela não inventa posição"*, então isto é **aplicação da regra escrita**, não escolha nova — e está
coberto por asserção explícita (`assertStringNotContainsString('0 de', …)`).

⚠️ **`preparando` sai da NAVEGAÇÃO mas CONTINUA nos `ids` do LOTE** (divergência **D9** da 187, já no
ar): **são listas diferentes, e não foram unificadas.** Há asserção medindo que o recorte da conta
tem as **quatro** — é a navegação que exclui, não a consulta.

---

## OS NOVE DESTINOS (critério 9)

| # | chave | `destino` | medido |
|---|---|---|---|
| 1 | `em_preparacao` | **`null`** | `:1089` dá `fix: ''` — não há nada que a pessoa possa fazer |
| 2 | `fotos_a_conferir` | `{tipo:'foto', imagem_id:<foco>}` | **a primeira COM ALERTA**, não a primeira pendente (`:1172`) |
| 3 | `sem_fotos` | `{tipo:'foto', imagem_id:null}` | não há imagem; a coluna abre e **diz que está vazia** (IMG-12) |
| 4 | `frete_ausente` | `{tipo:'modal', modal:'frete'}` **ou** `modal:'ajustes'` | ⚠️ **DOIS ramos** — ver abaixo |
| 5 | `resultado_negativo` | `{tipo:'modal', modal:'excecao', excecao:'resultado_negativo'}` | |
| 6 | `margem_abaixo_do_minimo` | `{tipo:'modal', modal:'excecao', excecao:'margem_abaixo_do_minimo'}` | |
| 7 | `alteracao_nao_conferida` | `{tipo:'acao', acao:'conferir_alteracao'}` | **confere SEM alterar o preço** (`:1176`) |
| 8 | `ajuste_em_aberto` | **`null`** | **PERGUNTA-188-B** — sem rota, **SEM BOTÃO** |
| 9 | `titulo_longo` | **`null`** | **PERGUNTA-188-B** — idem |

⚠️ **PERGUNTA-188-B aplicada como DECIDIDA, não inventada:** destino `null` significa **SEM BOTÃO**,
e o **TEXTO da pendência CONTINUA aparecendo** — há asserção sobre o texto de cada um
(*"1 ajuste solicitado em aberto"* e *"61 caracteres"*) e sobre o glifo. **Zero `onClick` vazio,
zero `disabled` decorativo.**

⚠️ **`correcao` é `null` em TODAS as pendências fora do `live`** (`hasFix = !!b.fix && inf.live`,
**`:1295`**), provado com **UMA** asserção coletando os infratores, e com a **ponta POSITIVA**
obrigatória (ao vivo HÁ correção nas que têm rota) — gate de ausência rodado só com dado vazio é
vazio por construção.

### ⚠️⚠️ DESVIO DECLARADO (Regra 1): `frete_ausente` tem DOIS destinos

A Fase 186 **já diverge do protótipo de propósito**: quando o que falta **não é o frete** (são
**CINCO** as linhas que anulam o resultado), a correção deixa de ser *"Informar frete"* e passa a
ser `VocabularioDoPortao::CORRECAO_SEM_FRETE` (*"Solicitar ajuste"*).

➡️ Cravar o modal de **frete** nos dois casos faria o botão **dizer "Solicitar ajuste" e abrir o
modal de frete** — o rótulo apontando para um lugar e o destino para outro, que é exatamente o que
VOC-04 existe para impedir. ➡️ **O destino é DERIVADO da correção que a 186 já resolveu**, num lugar
só. É menos código, não mais.

---

## O RODAPÉ — `resumoDoRodape()` LIDO, e a divergência contra o `join` declarada

⚠️ **`rodape.aprovavel` é LIDO** de `BloqueiosDaOfertaService::para()['aprovavel']`, nunca
recalculado — e há asserção de **identidade com o valor do próprio serviço da 186**, não só com um
booleano esperado.

⚠️⚠️ **O motivo CHAMA `resumoDoRodape()`. Eu LI o método; não remontei a frase.**

| fonte | o que produz |
|---|---|
| protótipo **`:1297`** | `'Bloqueado: ' + inf.B.map(b => SHORT[b.k]).join(' · ')` — **TODAS** as abreviações |
| produto (186, EM PRODUÇÃO) | `resumoDoRodape()` — o **PRIMEIRO** abreviado + `' +N'` dos **DEMAIS** |

**Medido com DOIS bloqueios:** o produto dá `Bloqueado: fotos a conferir +1`; o `join` do protótipo
daria `Bloqueado: fotos a conferir · resultado negativo`. ➡️ **O produto GANHA** — é o critério 10 do
ROADMAP (*"com o primeiro abreviado"*) e `resumoDoRodape()` cita BLOQ-12. Recopiar o `join` criaria
a **SEGUNDA VERDADE** da mesma frase na mesma tela (o rodapé da fila e o do painel divergiriam).
⚠️ A divergência está **asseverada nos dois sentidos**: a frase do produto é igual à do serviço, **e
diferente** da reconstrução do `join` — que o próprio teste monta, para o dia em que ela mudar.

**Os 5 textos fora do `live`** (`:1297`, na ordem do ternário) estão todos medidos, e há asserção de
que os **cinco são DIFERENTES entre si** — um ternário colapsado daria o mesmo texto em dois ramos e
ninguém veria. Sem nome de aprovador, o segmento `' por {nome}'` é **omitido**: não se inventa
responsável.

⚠️ **`botoes.aprovar` e `aprovavel` são campos DIFERENTES**, e isso está declarado: o primeiro é *"o
botão existe"* (só com `live`, `:713`/`:718`), o segundo é *"ele está habilitado"* (o `disabled=` de
E12, que o plano **188-10** desenha). Fundi-los faria oferta publicada mostrar um botão amarelo.
**"Próximo ↓" existe SEMPRE** (`:721`, fora dos `sc-if`) — medido nos cinco andamentos fora do live.

⚠️ **Decisão 9 honrada por LEITURA, não por remontagem:** a frase `· com a tarifa real do Mercado
Livre` já é montada por **`ComposicaoDaOferta:271`** (conferido por `grep`), e a **outra** margem —
a do parâmetro da Precificação — vive em **`app/Services/Publicador/Fila/ResumoRapidoService`**
(⚠️ **conferido com `test -e`: o caminho NÃO é `.../Aprovacao/Fila/`**). Este plano não tocou nenhuma
das duas.

---

## A EXCEÇÃO DOS EXPOSITORES (camada B) — COM PROVA NAS DUAS PONTAS, e o manifesto REMEDIDO

**O gate acusou o arquivo novo, exatamente como o plano previu.** Medido ANTES de qualquer
alteração do gate:

```
sh tests/gates/verificar-testes.sh 186-09 andamento-um-lugar-so
  → REPROVOU: a linha 'Tests:' contem 'failed' ou 'error'.
  → Tests: 1 failed (2 assertions)
  → "AND-09: a escrita de `andamento` passa SEMPRE pela única porta"
  → Array &0 [ 0 => 'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php' ]
```

**Por que é falso positivo:** o serviço **EXPÕE** o andamento em **DOIS** lugares de SAÍDA — o chip
de identificação (`'andamento' => ['chave' => …, 'rotulo' => $oferta->rotulo()]`) e a escolha do
motivo do rodapé fora do `live` — e **não escreve NADA**. A sonda larga casa `'andamento' =>` em
qualquer array, inclusive no de saída, e o segundo filtro casa porque o serviço recebe um
`PubAprovacaoOferta` por parâmetro, **como tem de receber**.

### ⚠️ A PROVA TEM DUAS PONTAS, e as duas estão medidas

| ponta | o que exige | onde está provada |
|---|---|---|
| (a) **CASA a sonda larga** | senão a exceção é **linha morta** abrindo buraco | `FechamentoDaFase186Test` (`$semSujeito`) **e** `test_territorio_e_ausencias_do_plano_188_02` (`preg_match($sondaLarga, …) === 1`) |
| (b) **NÃO tem nenhuma forma de escrita** | é isto que distingue EXPOR de ESCREVER | `FechamentoDaFase186Test` (`$queEscrevem`) **e** o meu território, com sonda **MAIS LARGA** (a minha inclui `save(` e `delete(`) |

⚠️ **O expositor é conferido pelos DOIS lados**, não só pelo `continue` do gate alheio.

### `contagens-186-09.txt` REMEDIDO — antes / depois

| id | antes | depois |
|---|---|---|
| `andamento-um-lugar-so` | `tests=1 assertions=19` | **`tests=1 assertions=20`** |
| `transversais` | `tests=12 assertions=257` | **`tests=12 assertions=258`** |

Os dois conferidos **verdes pelo harness** depois da re-medição. ⚠️ **O número não foi "ajustado
para passar": foi MEDIDO de novo**, uma invocação por id.

⚠️ **NÃO renomeei chave para fugir do gate** (o `.jsx` lê `andamento.chave` na fila **e** no painel;
dobrar a tela para o gate criaria dois nomes para o mesmo dado na mesma tela) e **NÃO enfraqueci
camada**.

### ⚠️ DE ONDE VEM O `+1`, exatamente — e uma observação para o fechamento

O laço de prova chama `$this->ler($rel)`, que tem um **`assertFileExists` POR EXPOSITOR**. ➡️ Essa
**única** asserção do gate **cresce com o tamanho da lista**, e isso é a família
`learnings §4b.2` (*"contagem que depende de quantos itens existem"*).

⚠️ **NÃO consertei isso, de propósito:** mudar a **FORMA** de um gate da 186 que está **EM
PRODUÇÃO** está **fora** da única exceção de território aberta a esta fase (que é *entrar na lista
com prova e remedir*). ➡️ **Fica REGISTRADO para o plano 188-13 decidir.** As **duas** asserções de
veredicto do laço já são únicas e coletam infratores; só o `assertFileExists` do leitor é por item.
Está escrito também no cabeçalho de `contagens-186-09.txt`, para quem chegar pelo grep.

---

## OS NOVE IDS — zero órfão, bijeção nos dois sentidos, homônimo por script

| id | filtro | medido |
|---|---|---|
| `identificacao-e-chips` | 2 métodos | `tests=2 assertions=41` |
| `pendencias-e-destinos` | 2 métodos | `tests=2 assertions=45` |
| `rodape-e-aprovavel` | 2 métodos | `tests=2 assertions=43` |
| `cadeia-e-incompleto` | 1 método | `tests=1 assertions=20` |
| `navegacao-e-payload` | 2 métodos | `tests=2 assertions=50` |
| `custo-do-painel` | 2 métodos (um em CADA arquivo) | `tests=2 assertions=24` |
| `painel-na-rota` | 2 métodos | `tests=2 assertions=41` |
| `territorio-painel` | 1 método | `tests=1 assertions=42` |
| `bijecao-manifesto-02` | 1 método | `tests=1 assertions=22` |

**Todos os NOVE verdes pelo harness, com `188-02` como 1º argumento.**
⚠️ **Nenhum id com mais de DOIS métodos** (o teto é três) — e há asserção coletando os que passarem
disso. **15 métodos, 15 testes somados nos nove ids** — fecha.

### ⚠️ A TERCEIRA BIJEÇÃO (id ↔ `<verify>`), provada por script

```
ids no MANIFESTO ∖ ids no <verify>  →  (vazio)   ← zero ÓRFÃO
ids no <verify> ∖ ids no MANIFESTO  →  (vazio)   ← nenhum abortaria o harness
ISCA: a sonda do <verify> casou 2 linhas no plano  ← não está cega
```

Isso era o defeito que a revisão desta fase achou: **cinco ids desta fase eram órfãos na primeira
redação** — no manifesto, com contagem medida, e **rodados por verify nenhum**. **Zero agora.**

### ⚠️ HOMÔNIMO — por script, nas DUAS direções, incluindo SUBSTRING

```
ARQUIVOS=902  METODOS=7718  HOMONIMOS=0
```

⚠️ **E a sonda tem ISCA nas duas direções, também medida** — sem isso "0" é indistinguível de sonda
morta:

| direção | caso | resultado |
|---|---|---|
| 1 — meu nome é SUBSTRING de um alheio | `test_territorio_e_ausencias_do_plano` | **ACUSOU 5**: `..._187_06`, `_187_07`, `_187_08`, `_187_09`, `_187_10` |
| 2 — um alheio é SUBSTRING do meu | `..._em_app_inteiro_com_isca_E_MAIS` | **ACUSOU** o método real da Fase 186 |
| controle | `test_metodo_que_ninguem_escreveu_188_02` | `HOMONIMOS=0` |

⚠️⚠️ **A direção 1 é a prova de que o sufixo `_188_02` NÃO é zelo:** os cinco homônimos
`test_territorio_e_ausencias_do_plano_187_0N` **existem no disco**, e sem o sufixo o id de uma
daquelas waves **somaria** com o meu. Há asserção no `bijecao-manifesto-02` provando que o meu
filtro **não casa** `..._187_08` **e** que aquele método continua existindo (senão a isca perde o
sujeito).

⚠️ **Minha contagem (`ARQUIVOS=902`) difere da da wave 1 (`1208`) porque a sonda é outra**: eu conto
arquivos **que contêm `function test_`**, ela contou de outra forma. **Sondas diferentes, mesma
conclusão: `HOMONIMOS=0`.** Registro a diferença para ninguém tratar um dos números como errado.

---

## `--autoteste`, colado

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

## A CAMPANHA DE MUTAÇÃO — `mutar-188-02.sh`, 10 mutações, 9 MORRERAM, 1 SOBREVIVEU (investigada)

⚠️ **Todo auxiliar leva o plano no nome** (`mutar-188-02.sh`, `copia-188-02-*`,
`out-188-02-<rotulo>.txt`, `homonimo-188-02.sh`, `manifest-188-02.cjs`), porque o scratchpad é
**COMPARTILHADO** entre subagentes paralelos e na 187 um script foi **sobrescrito no meio**. ⚠️ E
conferi o **`sha256` do próprio script** antes de confiar no veredicto (`0fc34a4d9362…` e, após o
conserto da guarda, `1d1cb2f92728…`).

**A receita cumprida em todas:** trabalho **commitado antes** · `cp` para o scratchpad · ocorrência
**travada em 1** · linha **conferida depois** · restauração **DA CÓPIA** em `trap EXIT` · `sha256`
contra `git show HEAD:` **com `tr -d '\r'` nos dois lados**. ⚠️ **`git checkout` NUNCA.**

| # | mutação | alvo | gate | veredicto |
|---|---|---|---|---|
| i | ordem das pendências **invertida** (`array_reverse`) | o serviço | `pendencias-e-destinos` | ✓ **MORREU** |
| ii | `correcao` **não-nula fora do `live`** (dropa o `&& $aoVivo`) | o serviço | `pendencias-e-destinos` | ✓ **MORREU** |
| iii | `foco_imagem_id` **ignorando o alerta** | `BloqueiosDaOfertaService` | `pendencias-e-destinos` | ✓ **MORREU** |
| iv | a **embalagem ANULANDO** o resultado | `PubAprovacaoComposicao` | `cadeia-e-incompleto` | ✓ **MORREU** |
| v | `para()` chamado **DUAS vezes** | o serviço | `custo-do-painel` | ✓ **MORREU** |
| vi | `preparando` **ENTRANDO** na navegação | o serviço | `navegacao-e-payload` | ✓ **MORREU** |
| vii | **o CALL SITE** de `SEM_ROTA_ATE_A_FASE_190` esvaziado | o serviço | `pendencias-e-destinos` | ⚠️ **SOBREVIVEU** |
| viii | **a CONSTANTE** `SEM_ROTA_ATE_A_FASE_190` mudada | o serviço | `pendencias-e-destinos` | ✓ **MORREU** |
| ix | `aprovavel` **RECALCULADO** em vez de lido | o serviço | `rodape-e-aprovavel` | ✓ **MORREU** |
| x | o motivo **REMONTADO com o `join`** do protótipo | o serviço | `rodape-e-aprovavel` | ✓ **MORREU** |

**Restauração conferida por `sha256` nos três arquivos mutados, todos idênticos ao HEAD**, e
`git diff --numstat` **vazio** neles.

### ⚠️⚠️ A SOBREVIVENTE (vii) — INVESTIGADA, e a investigação achou GAP REAL

**Por que sobreviveu:** sem o `return null` antecipado, `destino()` cai no `match` — **que não tem
arm** para `ajuste_em_aberto` nem `titulo_longo`. O `default => null` devolvia **exatamente o mesmo
`null`**. ➡️ A mutação era **semanticamente equivalente**, e a decisão do usuário na
**PERGUNTA-188-B estava protegida SÓ pela ausência acidental de dois arms do `match`**.

⚠️ **É a régua 6 em estado puro:** *"asserção vazia quando escrita vira FALSO POSITIVO depois"*. No
dia em que a Fase 190/191 acrescentar o arm da revisão detalhada e esquecer de esvaziar a lista — ou
o contrário —, **nada acusaria**.

**O conserto (`c15a4858`) fecha o laço na OUTRA direção**, dentro do mesmo caso:

> **ter destino ⟺ NÃO estar em `SEM_ROTA_ATE_A_FASE_190` e NÃO ser `em_preparacao`**

Com a lista vazia, as duas passam a **DEVER** ter destino, não têm, e a mutação **MORRE** (é a
mutação **viii**, medida). ⚠️ **UMA** asserção coletando os divergentes, os nove destinos
**COLETADOS num mapa** (com asserção de que as **nove** foram medidas, não quatro), e **isca nas
duas direções**: quem está FORA da lista TEM destino, quem está DENTRO não tem.

⚠️ **O que FICA declarado, e não foi "consertado" inventando código:** a mutação **vii** (o call
site) **continua sobrevivendo**, e isso é **correto e esperado** — o `return null` antecipado é
**redundante por construção hoje**, porque o `match` não tem arm para as duas chaves. Ele volta a
ser load-bearing **automaticamente** no dia em que a Fase 190/191 escrever o arm. ➡️ **Não inventei
um destino especulativo** (`{tipo:'revisao_detalhada', …}`) só para a mutação morrer: isso
antecipararia a Fase 190/191, que a régua §9 proíbe. **O artefato que carrega a decisão é a
CONSTANTE, e ela está provada.**

### ⚠️ DUAS RECUSAS LEGÍTIMAS DA TRAVA, e um defeito do MEU script

| o que | por que | desfecho |
|---|---|---|
| âncora **multi-linha** (a constante inteira) | `grep -F -c` conta **LINHAS**, não ocorrências: a constante de 4 linhas deu `12`, e uma âncora com `\n` no fim deu **`724`** | **RECUSADA** pela trava — comportamento **certo**; usei âncora de **uma linha** com a mesma semântica |
| réplica **contendo** a âncora (a mutação **v**) | a guarda era *"a âncora antiga sumiu"*, e `$this->bloqueios->para($oferta); $leitura = …para($oferta);` **CONTÉM** a antiga | **ABORTOU indevidamente** — era defeito da **guarda**, não da mutação |

➡️ **Consertei a guarda na RAIZ:** a conferência do passo (4) passou a ser **"o `sha256` divergiu da
CÓPIA"**, que é o invariante de verdade, em vez de *"a âncora antiga sumiu"*. ⚠️ A trava do passo
(3) — ocorrência **== 1** — ficou **intacta**; ela funcionou nas duas recusas.

---

## A PROVA DE QUE NADA VAI AO MERCADO LIVRE (critério 18)

⚠️ **O painel APROVA — e aprovar ENFILEIRA —, NÃO PUBLICA. A escrita no ML é da Fase 193.**

**Provado por AUSÊNCIA DE REQUISIÇÃO, com isca, em dois níveis:**

| nível | o que mede | isca |
|---|---|---|
| serviço (`custo-do-painel`) | **nenhuma** requisição fora de `/sites/MLB/listing_prices` | `Http::get('…/items/MLB1')` **tem** de ser registrada pelo gravador |
| rota (`custo-do-painel`) | **nenhuma** requisição **não-GET/HEAD** sai da action | `Http::post('…/items', …)` **tem** de aparecer como `POST …/items` |

⚠️ `Http::preventStrayRequests()` em todos os arquivos — **nenhuma chamada à API real do ML** saiu.
⚠️ **As iscas são o que torna a prova por ausência não-vazia:** sem elas, `[]` seria indistinguível
de gravador cego, e esta é a prova por ausência mais importante do plano.

### O custo, medido

| cenário | `para()` do painel | `painel` |
|---|---|---|
| **sem** `?oferta=` (o caso comum) | **0** | `null` |
| **com** `?oferta=` válido | **1** | montado |

⚠️ **Com TRÊS ofertas ao vivo na fila** — com uma só, "zero chamada" não se distingue de "uma por
oferta". E há asserção de que a **fila inteira continua montada** (`contador_da_aba === 3`): *"zero
chamada do painel"* não pode ser *"página vazia"*.
⚠️ **E a prova de que o espião está DE FATO no caminho da action**, não num ramo morto: se o
container estivesse resolvendo o serviço real, `chamadas` seria 0 **com** o painel montado — o falso
verde mais fácil deste gate, e há asserção fechando-o.

---

## TERRITÓRIO — por ESCOPO DO ASSUNTO, nunca por range

⚠️⚠️ **Range não passa nesta árvore** (a 187-11 mediu 111 commits, **43 da sessão paralela**, e o
diff cru acusava **12 dos 60 alvos vetados**). ⚠️ **E NÃO conferi o AUTOR:** as três máquinas
commitam com o MESMO `user.name` (`MB.ECF-100376`).

**Os commits do escopo `(188-02)` tocaram EXATAMENTE estes 8 arquivos:**

```
.planning/phases/188-…/188-FID01.md
app/Http/Controllers/MlbPublicadorAprovacaoController.php
app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php
tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php
tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php
tests/gates/contagens-186-09.txt
tests/gates/contagens-188-02.txt
```

**`git diff HEAD --numstat` VAZIO** em: os 10 serviços da 186 em `Aprovacao/`, os 8 de `Fila/` da
187, os 21 `.jsx` de `aprovacao/`, `resources/js/` inteiro, `AppLayout.jsx`,
`publicador-header-zindex-ordem.test.js`, `estrutura-meus-anuncios.test.js`,
`publicador-fila-proximo-187.test.js`, `app/Support/Publicador/`, `app/Mcp/`, `app/Services/Creative/`,
`.planning/ROADMAP.md`, `.planning/STATE.md`, e os **quatro** manifestos de dependência
(**T-188-SC** — este plano **não instala nada**, provado por **FORMA**).

⚠️ **Os diffs são praticamente todos ADITIVOS:** controller **67 / 0**, gate da 186 **27 / 0**,
`contagens-186-09.txt` **39 / 2** (as 2 remoções são **exatamente** as duas linhas de contagem
remedidas, declaradas acima). **Nenhum commit deletou arquivo** — conferido por
`git diff --diff-filter=D` depois de cada um.

⚠️ **ANTI-FANTASMA (o gate perene):** rodei `test -e` em **cada** caminho que escrevi em gate, e o
próprio `territorio-painel` tem asserção coletando caminho vetado que não exista no disco. **Zero
fantasma.**

⚠️ **O gate da 187 que lê o TEXTO do controller continua verde:**
`publicador-fila-proximo-187.test.js` → **76/76**, e `publicador-fila-linha-187` +
`publicador-fila-lote-187` → **49/49**. ➡️ As linhas `'faixa' => $fila->faixa($base)` e
`'proximo_a_revisar' => $proximo->para($base)` **ficaram INLINE**, como têm de ficar.

⚠️ **Esta fase NÃO tem migration**, e isso está **medido**: `git diff --name-only 0a5d4dc1..HEAD --
database/migrations/` sai **vazio**. **Não rodei `artisan migrate`.** ⚠️ **Não deployei, não dei
push, não toquei no VPS, não chamei a API real do ML.**
⚠️ `mysqld` **não está em execução** nesta máquina (conferido por `tasklist`) — a suíte roda em
SQLite `:memory:`, e nada deste plano depende do banco local.

### ⚠️ Resíduos e observações de território

- **O resíduo do `188-01-PLAN.md:105`** (*"início de escopo"*) **NÃO existe no `188-02-PLAN.md`** —
  conferido por `grep`. Nada a declarar, e **não editei plano nenhum** (território do planejador).
- **`.planning/` do meu plano:** só estendi **`188-FID01.md`** (permitido), e **conferi
  `git diff HEAD --` nele antes** (saiu vazio — sem WIP alheio).
- ⚠️ **Existe um `stash@{0}` ("On main: wip") de OUTRA sessão nesta árvore.** Eu apenas o **listei**;
  **não rodei `git stash` em nenhuma forma** — o stash é compartilhado entre worktrees e um `pop`
  cego aplicaria WIP alheio.
- **Não rodei** `pint`, `git add -A`, `git reset`, `git checkout`, `git commit --amend`,
  `git clean`, nem `state.advance-plan`. Todos os commits com `-F <arquivo>` e **pathspec**, com o
  `Co-Authored-By` conferido presente em cada um.

---

## DIVERGÊNCIAS MEDIDAS, com `arquivo:linha`

### 1. ⚠️⚠️ A região do VIEW-MODEL do protótipo **nunca foi re-medida** — 6 ponteiros deslocados

**Está escrito em `188-FID01.md` §J** (commit `c0e034b1`), com a medição completa. O resumo:

| a prosa herdada dizia | real (medido por `sed`) | o que está DE FATO lá |
|---|---|---|
| `sku`/`formato`/`origem` em `:1309` | **`:1301`** | `:1309` é `onApprove`/`onSave`/`onAdjust`/`onRetry` |
| `hasFix` **e as cores** em `:1304` | **`:1295`** | `:1304` é `shared`/`sharedNote`/`safeCount` |
| o `reason` (5 textos) em `:1307` | **`:1297`** | `:1307` é `canApprove`/`approveBg`/`approveCursor` — **o E12 do critério 8** |
| `_nav` em `:1264` | **`:1263`** | `:1264` está **VAZIA** (conferido por `cat -A`) |
| `P.pos` em `:1317` | **`:1318`** | `:1317` é o `indexOf` |
| `foco_imagem_id` em `:1171` | **`:1172`** | `:1171` é a **abertura** `fix(o, k) {` |

**Confirmados sem mudança:** `:1089` (`fix: ''`), `:1099` (`approvable`), `:1092`-`:1094` (a cadeia).

⚠️ **O ACHADO É SOBRE A SONDA, NÃO SOBRE O DOCUMENTO.** A re-medição do 188-01 declarou as faixas em
escopo — `547-722`, `775-820`, `888-890` —, que são as de **HTML/CSS**. A região do view-model
(**1076-1320**) **ficou fora das três passadas**, e é dela que vem quase todo ponteiro citado em
prosa de plano.

⚠️⚠️ **A PROVA INDEPENDENTE de que a medição está certa:** o código da Fase 186, **EM PRODUÇÃO**, já
usa os ponteiros certos — `VocabularioDoPortao:58`, `:210`, `:243`, `:255` citam **`:1295`** e
**`:1297`**. ➡️ **Quem mediu na 186 acertou; foi a prosa dos planos da 188 que herdou números
deslocados.** O conserto é do **ponteiro**, nunca do valor.

⚠️ **E o padrão que torna isso perigoso:** o `:1307` deslocado cai em cima do
`canApprove`/`approveCursor`, que é trecho **legítimo e load-bearing**. **O ponteiro errado aponta
para OUTRO assunto real**, e quem o ler sem medir acha que está no lugar certo.

### 2. O rodapé: `resumoDoRodape()` × o `join(' · ')` de `:1297`

Declarada acima, em §Rodapé. **O produto ganha** (critério 10 do ROADMAP), e a divergência está
asseverada **nos dois sentidos**.

### 3. `pos` da oferta em `preparando`: `null` × `"0 de N na fila"` (`:1318`)

Declarada acima, em §Navegação. **O produto ganha** — é aplicação do critério 2 escrito.

### 4. `frete_ausente` com DOIS destinos (desvio Regra 1)

Declarada acima, em §Destinos. Deriva da divergência que a **186 já declarou** em
`BloqueiosDaOfertaService:377`-`:384` e `VocabularioDoPortao:227`-`:234`.

### 5. `app/Services/Publicador/Fila/ResumoRapidoService.php` — o caminho **confirmado**

⚠️ `test -e` **OK**. O caminho **NÃO** é `.../Aprovacao/Fila/`. Nenhuma linha deste plano o
confundiu com `FilaDeAprovacaoService`, e a decisão 9 (duas margens, de propósito) segue intacta.

---

## OS DEFEITOS QUE AS PRÓPRIAS SONDAS TINHAM — medidos, e consertados na raiz

Nenhum destes era dedutível; todos foram **medidos vermelhos** antes de serem consertados.

| # | o defeito | por que importa |
|---|---|---|
| 1 | **o PHP avalia o RECEPTOR antes do ARGUMENTO** — `$this->servico()->para($montar(…))` construía o serviço **antes** de `$montar()` instalar o dublê, e ele nascia com o `DadosEfetivosService` **REAL** | É o **falso negativo silencioso medido no plano 186-07** (está no docblock daquele teste). O sintoma foi exato: o cenário ao vivo vinha com `frete_ausente` e o fora-do-live com `resultado_negativo`, com a fixture pedindo os MESMOS bloqueios. **Conserto: montar a oferta numa variável primeiro** |
| 2 | **`$props['painel'] ?? 'AUSENTE'` MENTE** — o `??` dispara no `null` **igual** à chave ausente | É **exatamente** a distinção que o bloco existia para medir (chave ausente ≠ chave nula, que derrubou a página em 07/10/2026). Os sete casos saíram `=> "AUSENTE"` com a chave **presente e nula**. **Conserto: `array_key_exists` para a presença, acesso direto para o valor** |
| 3 | **a árvore da fila é `grupos[] → conjuntos[] → ofertas[]`** — `grupos.produtos` e `conjuntos.linhas` **não existem** | O passeio saía **VAZIO** e, com um `assertNotEmpty`, teria passado como gate verde **sobre zero casos**. **Conserto: os nomes certos + isca contando as 3 linhas** |
| 4 | ⚠️⚠️ **o gate que exigia `TUDO_CONFERIDO` no arquivo REPROVAVA O CONSERTO CERTO** | O literal verde sai **de dentro** de `resumoDoRodape()`, que é o que este plano **deve** chamar. Cobrar a constante empurraria o próximo a **remontar a frase** só para o gate passar — família `feedback_gate_que_reprova_pelo_proprio_conserto`. **Conserto: o invariante é o PAR** (`resumoDoRodape` é chamado **e** o literal não é recopiado) |
| 5 | **a trava da mutação com âncora multi-linha** — `grep -F -c` conta **linhas** (deu `12`, e com `\n` no fim deu `724`) | A trava **recusou corretamente**; o que faltava era eu usar âncora de uma linha |
| 6 | **a guarda do passo (4) da mutação** — *"a âncora antiga sumiu"* abortou a mutação **v**, cuja réplica **contém** a âncora | **Conserto na raiz: a conferência é `sha256` divergir da CÓPIA**, o invariante de verdade |
| 7 | **`pub_produtos.sku` é NOT NULL** (`23000` na primeira rodada) | "sem SKU" se testa com SKU **em branco**, que é o que `texto()` apara |
| 8 | **`mlb_empresa_id` tem de ser gravado na fixture da rota** | O recorte usa as **duas** âncoras com `AND`; sem a segunda, a oferta sai do recorte e o caso passaria **medindo o caso errado** (é a **PERGUNTA-17**, aberta, dono Fase 194) |
| 9 | **as cifras precisavam da calibração MEDIDA da 186** | Com `preco 1200 · custo 1000` o cenário caía em **prejuízo** quando eu pedia **margem** (mínimo = 12%). ➡️ Adotei a régua de `BloqueiosDaOfertaServiceTest::cenario()`: `preco 100 · imposto 10`, e `custo 20/65/80` = em ordem / margem baixa / prejuízo |

---

## O QUE NENHUM EXECUTOR PODE AFIRMAR

⚠️⚠️ **EU NÃO VI ESTA TELA RENDERIZADA, e não há como ver nesta máquina.** Medido e é o ambiente:
o banco local **não tem empresa com `ml_token`** (a rota dá **404**), `pub_aprovacao_ofertas` está
**vazia**, **a fila nasce vazia até a Fase 194** (decisão **P3** do usuário — é honesto, **não** é
defeito), e **não há navegador**.

➡️ Tudo o que este plano prova é o **payload**: *"a tela mostra X"* aqui significa **"o payload
contém X"**, e as fixtures são de teste (factory/seed), nunca "abrir e ver".

⚠️ **A conferência visual da Fase 187 pelo usuário continua ABERTA** (`PERGUNTA-VISUAL-187`). **Nada
deste plano a substitui nem a presume feita.**

⚠️ **O painel ainda não aparece em tela nenhuma:** a prop existe, e quem a desenha é o plano
**188-07** em diante. Isto **não** é meio-feito que pareça defeito — é a fronteira do plano, e o
payload é consumido por um componente que ainda não nasceu.

### Para o roteiro de conferência da conta 459 (o plano 188-13 consolida)

Quando a tela do painel existir (188-07+), o trecho deste plano é:
1. abrir `/mlb/anuncios/meus/{company}/aprovacao` **sem** `?oferta=` → o painel **não** aparece, e a
   fila carrega como hoje;
2. acrescentar `?oferta=<id de uma oferta da conta>` → o painel abre **naquela** oferta, com
   *"N de M na fila"* coerente com a fila visível;
3. trocar por `?oferta=<id de outra empresa>` → o painel **não** abre, **sem** erro na tela;
4. abrir uma oferta em **preparando** → **não** há *"0 de N na fila"*, e os botões "Salvar revisão",
   "Solicitar ajustes" e "Aprovar para publicação" **não** aparecem; "Próximo ↓" **aparece**.

---

## PERGUNTAS — nenhuma nova, e nenhum quarto conflito protótipo × regra do projeto

⚠️ **NÃO achei um quarto conflito** daqueles que só o usuário decide. As quatro divergências deste
plano são todas **aplicação de regra JÁ ESCRITA** (critério 10, critério 2, PERGUNTA-188-B) ou
**desvio de correção** (Regra 1, o `frete_ausente`) — nenhuma é escolha de desenho nova.

| pergunta | estado aqui |
|---|---|
| **PERGUNTA-188-B** | **APLICADA** — destino `null`, SEM BOTÃO, texto continua. ⚠️ E agora **com trava própria** (ver a sobrevivente) |
| **PERGUNTA-188-A** | **não incide** neste plano (é 188-03 e 188-08) |
| **PERGUNTA-188-C** | **não incide** — a URL da miniatura é do plano **188-03**; não montei payload de foto, e `fotos` nasce `null` |
| **PERGUNTA-17** | **continua ABERTA, dono Fase 194.** Encostei nela na fixture da rota (o `AND` das duas âncoras) e **declarei no docblock** em vez de "consertar" |
| **PERGUNTA-VISUAL-187** | **continua ABERTA**, dono usuário |

### ⚠️ UMA observação que o plano 188-13 precisa decidir (não é pergunta ao usuário)

O `+1` de assertion no gate `andamento-um-lugar-so` vem do **`assertFileExists` por expositor**
dentro do `ler()`. É a família *"contagem que cresce com a lista"*, e está **em produção na 186**.
**Não a consertei** porque mudar a FORMA daquele gate está fora da exceção aberta a esta fase. ➡️
**Registrado em `contagens-186-09.txt` e aqui**, para o fechamento decidir com os treze pares na mão.

---

## ONDE ESTE PLANO PAROU

**Plano COMPLETO: as duas tarefas executadas e commitadas, mais três commits de conserto medido.**
Nada ficou pela metade, e nada ficou sem commit.

| tarefa | estado | commit |
|---|---|---|
| **1** — `PainelDeRevisaoService` | **COMPLETA**, 5 gates verdes | `e2aa4263` |
| **2** — a prop `painel` no controller | **COMPLETA**, 4 gates verdes | `bdecc48a` |
| conserto do gap da sobrevivente | **COMPLETO** | `c15a4858` |
| a FID-01 §J medida | **COMPLETO** | `c0e034b1` |
| a prop INLINE (`key_links` do plano) | **COMPLETO** | `74189a6f` |

---

## Self-Check

### Os arquivos que esta SUMMARY afirma existem

```
FOUND:   app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php (724 linhas · min_lines 180)
FOUND:   tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php  (1650 linhas · min_lines 200)
FOUND:   tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php      (460 linhas)
FOUND:   tests/gates/contagens-188-02.txt                                   (100 linhas)
FOUND:   app/Http/Controllers/MlbPublicadorAprovacaoController.php          (671 linhas)
FOUND:   tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php     (1472 linhas)
FOUND:   tests/gates/contagens-186-09.txt                                   (149 linhas)
FOUND:   .planning/phases/188-…/188-FID01.md                                (768 linhas)
```

### Os commits que esta SUMMARY afirma existem

```
FOUND:   e2aa4263   FOUND:   bdecc48a   FOUND:   c15a4858
FOUND:   c0e034b1   FOUND:   74189a6f
```

### Os `key_links` declarados no plano

| link | pattern | medido |
|---|---|---|
| serviço → `BloqueiosDaOfertaService::para` | `BloqueiosDaOfertaService` | **8 ocorrências** ✓ |
| controller → `PainelDeRevisaoService::para` | `'painel' =>` | **presente, INLINE** ✓ (era **0** antes de `74189a6f` — achado por este self-check) |

### Os `must_haves.truths` do plano

| verdade | onde está provada |
|---|---|
| `?oferta=<id>` devolve a prop `painel` daquela oferta, e só dela | `painel-na-rota` |
| identificação com data formatada no servidor | `identificacao-e-chips` (`assertIsString` na espera) |
| cada pendência com o seu DESTINO | `pendencias-e-destinos` (os 9, um por um) |
| rodapé com `aprovavel`, motivo e lista de botões | `rodape-e-aprovavel` |
| **nenhum status do ML no payload** | `navegacao-e-payload` (sonda por palavra inteira + 4 iscas) |
| a `versao` viaja no payload | `navegacao-e-payload` + `painel-na-rota` |

### Self-Check: **PASSED**

Nenhum item ausente. ⚠️ **Um defeito REAL foi achado por este próprio self-check** (o `key_links`
do `'painel' =>`, que dava **zero**) e consertado em `74189a6f` — registrado acima em vez de
silenciado.
