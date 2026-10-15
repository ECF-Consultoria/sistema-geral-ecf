---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 02
subsystem: publicador-aprovacao
tags: [fila, aprovacao, rota, contador, inertia, gates, territorio]
requires:
  - "Fase 186: AndamentoDaOfertaService (filaDaConta / ordenarFila / faixaDeAndamento)"
  - "Fase 186: ComposicaoFinanceiraService::margemMinimaComOrigem"
  - "Fase 186: PubAprovacaoOferta (LIVE / ORDEM / ANDAMENTOS)"
  - "Fase 187-01: o harness resolvendo `187-NN` e o 187-BASELINES.md"
provides:
  - "rota nomeada mlb.anuncios.meus.aprovacao (GET /mlb/anuncios/meus/{company}/aprovacao)"
  - "MlbPublicadorAprovacaoController::index — a action da fila, com a trava de conta"
  - "FilaDeAprovacaoService — o unico ponto por onde a tela le a fila (5 delegacoes)"
  - "prop `aguardando_aprovacao` em MlbAnuncioController::meus() — o contador real da aba"
  - "tests/gates/contagens-187-02.txt — 4 ids, 24 metodos, bijecao por script"
affects:
  - "app/Http/Controllers/MlbAnuncioController.php (1 hunk, 1 linha de codigo, 0 remocoes)"
  - "routes/mlb_anuncios.php (1 rota + 1 use)"
tech-stack:
  added: []
  patterns:
    - "servico-fachada de DELEGACAO PURA, com gate provando ausencia de logica propria"
    - "gate de territorio por ESCOPO DE ASSUNTO do commit, nunca por range nem por autor"
    - "commit por pathspec com reconstrucao do arquivo a partir do HEAD, para nao publicar WIP alheio"
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - tests/Unit/Publicador/Aprovacao/FilaDeAprovacaoServiceTest.php
    - tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php
    - tests/gates/contagens-187-02.txt
  modified:
    - routes/mlb_anuncios.php
    - app/Http/Controllers/MlbAnuncioController.php
    - tests/Feature/Publicador/PublicacoesDaTelaTest.php
decisions:
  - "A fila pendura na rota que EXISTE (/meus/{company}/aprovacao), nao no /publicacoes/aprovacao do README"
  - "A aba do cabecalho global continua 'publicacoes': CabecalhoPublicadorService::ABAS nao ganha 6o valor"
  - "O contador e resolvido por app() e nao por injecao, para o diff de meus() ser 1 hunk"
  - "O gate do trio de tarifa NAO nasce aqui: para() nao e chamado neste payload (seria gate sobre zero casos)"
metrics:
  duration: "~2h"
  completed: 2026-10-15
  tasks: 3
  casos_de_teste: 24
  asserções: 208
  mutacoes: "9 execucoes, 0 sobreviventes"
  baseline_php_antes: "2347 / 20264 / 0"
  baseline_php_depois: "2373 / 20473 / 2 (as 2 falhas NAO sao deste plano — ver §17.2)"
---

# Fase 187 Plano 02: O servidor da fila "Aguardando aprovação" — Summary

A fila existe **no servidor**: `mlb.anuncios.meus.aprovacao` responde com o escopo da conta, o
contador **real** da aba vem de `PubAprovacaoOferta::LIVE` lido do modelo, a faixa de 9 recortes e a
ordem literal de `ORD` são **delegadas** à Fase 186 (zero reimplementação), e a tela de Publicações
que já está no ar ganhou **uma** chave aditiva — 1 hunk, 1 linha de código, 0 remoções.

**Commit de partida deste plano: `a3169a03`.** Todos os gates de diff usam `a3169a03..HEAD`
**filtrado pelo escopo de assunto `(187-02)`**.

---

## 1. ⚠️ As baselines RE-MEDIDAS — registradas, não ajustadas

Medidas **antes de qualquer edição minha**, no commit `a3169a03`, pelo harness (`--suite php`):

| linha | `187-BASELINES.md` (em `75b9f82f`) | ⚠️ **RE-MEDIDO** (em `a3169a03`, antes de eu editar) | leitura |
|---|---|---|---|
| **PHP** | `2346 / 20243 / **1**` | **`2347 / 20264 / 0`** · exit **0** | **a falha herdada SAIU** |

E o **depois** deste plano (ver §17 para as 2 falhas, que **não são deste plano**):

| rodada | PHP | falhas |
|---|---|---|
| antes (`a3169a03`) | `2347 / 20264 / 0` | — |
| depois, 1ª rodada | `2373 / 20475 / 2` | `PublicacoesDaTelaTest::custo…` (**minha, consertada**) + `PortalFotosTest::foto…gigante` |
| depois, 2ª rodada (pós-conserto) | `2373 / 20473 / 2` | `SincronizaPortalCompletoTest` ×2 — **outras duas**, no mesmo pipeline de fotos |

**+26 testes** (2347 → 2373): **24 são meus**, 2 da sessão paralela.

### A falha herdada foi embora, e a causa está medida

A `1` falha da baseline era `VinculoDuravelDaOriginalTest::test_terr_11_…`, um gate de território da
Fase 186 **por range ABERTO** que acusava a **sessão paralela** de mexer no arquivo dela
(`PublicadorCriativoReferenciaService.php`, commit `67d6a005` / `feat(196)`). O conserto foi o commit
`a3169a03` — o gate passou a discriminar pelo **escopo do assunto**. Com ele, `--suite php` sai com
**0 falhas** e **exit 0**.

### A diferença de contagem, atribuída por medição (não presumida)

- **+1 teste** (2346 → 2347): `git log 75b9f82f..a3169a03 -- tests/Feature/Publicador/ImagensTest.php`
  devolve **`adb4609e feat(197)`** — a **sessão paralela**, que acrescentou 2 métodos naquele arquivo
  (o saldo líquido de 1 vem de rearranjo no mesmo commit). Nada meu.
- **+21 asserções** (20243 → 20264): a soma de (a) as asserções que voltaram a contar quando o
  método que abortava passou a passar — a baseline registra que a queda de 18 era *"consequência
  aritmética da falha"* — e (b) as asserções que o meu conserto `a3169a03` acrescentou ao gate da
  186 (as três iscas de escopo).

⚠️ **Nenhum número foi "ajustado" para bater com o arquivo.** `REF_PHP_TESTS=2346` do harness
continua valendo como PISO (contagem acima passa, abaixo reprova).

### JS — não re-medido, e isso é DECLARADO

`git diff` deste plano **não tem um único arquivo sob `resources/` nem sob `tests/js/`**. Rodar a
suíte JS mediria exclusivamente o trabalho das outras duas sessões, e um gate de `npm run build`
sobre diff sem JS é **vazio por construção** (é o que a própria `<verification>` do plano diz, item
3). A falha JS pré-existente da Fase 87 (`estrutura-grade-glide.test.js`) segue intocada.

### O harness

`sh tests/gates/verificar-testes.sh --autoteste` → **`16 casos, 0 divergencia`**. (Eram 14 na §3 do
`187-BASELINES.md`, antes de a wave 1 acrescentar os dois casos de resolução de manifesto.)

---

## 2. A rota respondendo 200, e a não-colisão nas duas direções

```
GET /mlb/anuncios/meus/{company}/aprovacao   ->  mlb.anuncios.meus.aprovacao
                                             ->  MlbPublicadorAprovacaoController@index
```

| o quê | como foi provado | resultado |
|---|---|---|
| **200** com conta conectada | `X-Inertia` + asserção nas props | ✅ |
| **404** sem `ml_token` | `assertNotFound()` + mensagem conferida **contra a da lista** | ✅ idênticas |
| **403** para quem não é admin | `actingAs(consultor)` | ✅ |
| **nenhum middleware novo** (T-187-12) | `gatherMiddleware()` da fila **== IGUAL** ao da lista | ✅ |
| `aprovacao` → a FILA | `RouteCollection::match()` | ✅ `@index` |
| `MLB123456789/detalhe` → `meus.detalhe` | `RouteCollection::match()` | ✅ `@detalheAnuncio` |

### ⚠️ MEDIÇÃO QUE CORRIGE A PREMISSA DO PLANO — a colisão era impossível por estrutura

O plano e o briefing tratam "não roubar `meus.detalhe`" como risco de colisão. **Medido:**

- `meus.detalhe` = `/meus/{company}/{mlItemId}/detalhe` → **TRÊS** segmentos depois de `meus`
  (`routes/mlb_anuncios.php:450`-`:452`);
- a fila = `/meus/{company}/aprovacao` → **DOIS**.

As duas **não podem** casar a mesma URI. ➡️ Um gate que apenas comparasse as duas rotas de hoje
seria **vazio por construção** (armadilha 4 do briefing). Por isso a asserção é feita **pelo
ROUTER**, respondendo *"qual rota ganha esta URI"* — a pergunta que **continua podendo ter a
resposta errada amanhã**: uma curinga de dois segmentos (`/meus/{company}/{aba}`) registrada antes
da linha da fila a roubaria em silêncio. Três iscas fecham a sonda:

1. URI inexistente → `null` (a sonda sabe não casar);
2. `/meus/{id}/NAOEMLB/detalhe` → `null` (o `->where('mlItemId','MLB[0-9]+')` continua mordendo);
3. as duas URIs são diferentes entre si.

### ⚠️ DIVERGÊNCIA DE ROTA — confirmada e declarada no código

`ABA-06` e o `README-v2.md` dizem `…/publicacoes/aprovacao`. A rota real da lista é
`/mlb/anuncios/meus/{company}` (`routes/mlb_anuncios.php:445`, `name('meus')`) e o README chama as
dele de *"Rotas sugeridas"*. Renomear quebraria o critério 4, TERR-08 e **22 testes** de
`MeusAnunciosTest`. ➡️ A divergência está escrita no comentário da própria rota e no docblock do
controller.

---

## 3. ⚠️ A FRONTEIRA DE WAVE QUE EU MEDI — e que o 187-03 precisa saber

**Um GET SEM o header `X-Inertia` nesta rota devolve ERRO, não 200.** Medido literalmente:

```
Unable to locate file in Vite manifest: resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
  (View: resources/views/app.blade.php)
```

Causa: sem `X-Inertia`, o Laravel renderiza a view raiz, cujo `@vite` procura o componente da página
no `manifest.json`. **`Pages/Mlb/Publicador/Aprovacao.jsx` só existe a partir do 187-04.**

➡️ **Isto é a fronteira declarada no plano** (*"ESTE PLANO NÃO DESENHA TELA"*), não defeito desta
rota: o teste deste plano usa o caminho Inertia, que devolve JSON e **não** carrega o `.jsx`. A
asserção de **componente resolvido** e o caminho **sem** `X-Inertia` são **pendência NOMEADA do
187-04**, declarada no docblock do arquivo de teste e no do controller.

⚠️⚠️ **CONSEQUÊNCIA OPERACIONAL PARA O 187-03, que vale perguntar antes:** entre o 187-03 (a aba) e
o 187-04 (a página), a aba levará a uma rota que **responde** mas cuja página **não existe** — seja
pelo blade (erro de manifest, num carregamento completo) seja pelo resolvedor do cliente (um
`<Link>` Inertia recebe o JSON e não acha o componente). A ordem das waves é deliberada e eu a
respeitei; **a janela é real e não é fechável dentro deste plano** (criar a página é território do
187-04). Está aqui para ser decidido, não silenciado.

---

## 4. O contador vindo do servidor, com `LIVE` lido do modelo

```php
// FilaDeAprovacaoService::contadorDaAba()
return $this->paraAConta($companyId, $mlbEmpresaId)
    ->whereIn('andamento', PubAprovacaoOferta::LIVE)
    ->count();
```

| prova | forma | resultado |
|---|---|---|
| conta exatamente `LIVE` | **um caso por estado, nos 8** (3 de dentro = 1, 5 de fora = 0) | ✅ |
| `LIVE` é lido, não copiado | zero literal dos 8 estados no **código sem comentário** do serviço | ✅ |
| a prop iguala o serviço | `assertSame($servico->contadorDaAba(...), $props['aguardando_aprovacao'])` | ✅ 3 |
| `0` é **int** em conta vazia | `assertSame(0, …)` + `get_debug_type` | ✅ |
| a fila nunca é lida sem recorte | `RegraViolada(DADO-14-FILA-SEM-CONTA)` **propagada** nos 2 métodos | ✅ |
| não vaza entre contas | nas **duas** direções (a oferta da outra conta é alcançável pelo recorte dela) | ✅ |

⚠️ **A fila nasce VAZIA e isso é honesto.** Confirmei a medição do planejador: nenhum
`create|firstOrCreate|updateOrCreate` de `PubAprovacaoOferta` em `app/` — quem cria é a **Fase 194**.
O contador será **0** em produção até lá. **Nenhum dado de exemplo, nenhuma fixture como fonte,
nenhum esconder a aba quando for 0**; há caso de teste cravando que o `0` é um número e que a mesma
chamada devolve `1` com uma oferta `live` (senão "sempre 0" passaria).

### `LIVE` no CÓDIGO × `LIVE` no COMENTÁRIO — a armadilha invertida, e como ela foi fechada

O gate proíbe literal de estado no serviço. O docblock do serviço **precisa** dizer quais são os três
estados. Se o leitor de código parasse de tirar comentários, **o gate reprovaria pelo próprio
parágrafo que o explica** — e o conserto barato seria apagar a explicação. ➡️ O arquivo tem uma isca
em duas direções: o conteúdo **com** comentário **tem de conter** `aguardando_aprovacao`, e o
conteúdo **sem** comentário **não pode**. As duas asserções juntas provam que `token_get_all`
discrimina.

---

## 5. A faixa de 9 e a ordem de `ORD` — delegadas, e provadas pela desigualdade

A faixa sai com as 9 entradas na ordem exata de `ST`, com os rótulos literais e `"Publicado hoje"`
no fim. **O que PROVA o recorte DO DIA não é o rótulo:** é `Todas` **>** soma dos oito quando existe
publicada de **outro dia** (medido: 9 > 8). E a **isca inverte**: sem publicada de outro dia a
desigualdade vira **igualdade**. Um gate só no rótulo seria satisfeito por uma faixa que contasse o
estado inteiro com o nome certo.

A ordem é `[0, 1, 1, 2, 3, 4, 5, 6]` — o empate de `aguardando_aprovacao`/`em_revisao_ecf` em 1
**preservado**. Asserção sobre a sequência de `ordem()` e **não** sobre a sequência de chaves: exigir
uma das duas antes da outra cravaria no teste uma ordem que o desenho **não prescreve**. Os dois
desempates:

- **maior espera primeiro** dentro do degrau (com isca: a ordem de criação é OUTRA, então uma
  ordenação por `id` não passaria por acidente);
- **`entrou_na_fila_em` nulo vai para o FIM** do degrau (e dois nulos desempatam por `id`, estável).

E a espera **não atravessa degraus**: `preparando` desde setembro continua por último.

---

## 6. +1 consulta exata, e as 19 props inalteradas

### O custo (T-187-14)

"Ganhou uma consulta a mais" não é mensurável sem o "antes", que deixa de existir depois da edição.
Então a prova é em **dois níveis**:

| nível | medida |
|---|---|
| (a) `contadorDaAba()` isolado | **exatamente 1** consulta, e o SQL casa `^select count\(\*\) as aggregate from .pub_aprovacao_ofertas. where .company_id. = \? and .andamento. in \(\?, \?, \?\)$` |
| (b) o request inteiro de `meus()` | consultas a `pub_aprovacao_ofertas`: **exatamente 1**, e ela contém `count(*)` (a tela não recebe LINHAS) |
| o índice | `PRAGMA index_info('pubapof_conta_andamento_ix')` → **`['company_id', 'andamento']`**, lido do **schema vivo**, não do arquivo da migration |

Isca: `assertGreaterThan(1, count($doRequest))` — um `DB::listen` morto deixaria as duas contagens em
0 e o gate verde por construção.

### ⚠️ MEDIÇÃO QUE CORRIGE A PREMISSA — são **19** props, não 20

O plano e o briefing falam de **20** props em `meus()`. Contadas **por script** no
`Inertia::render('Mlb/MeusAnuncios', …)`:

```
empresa · conta · cabecalho · sub · subTotais · anuncios · rascunhos · triagem · filtros ·
abas · contagens · grupos · rodape · sincronizacao · faixaSucesso · skuNaoColetado ·
defasagem · saudeMlDisponivel · rotacaoN
```

= **19**. A 20ª é a deste plano. O gate declara as 19 com **nome E tipo** (`get_debug_type` do JSON
do Inertia: 14 `array`, 1 `string`, 2 `bool`, 1 `int`, 1 `null`) e usa **UMA asserção de contagem
fixa coletando os divergentes** — nunca uma por prop, que foi a armadilha caída 4 vezes na Fase 186.
Iscas: a sonda de tipo sabe discordar, e o payload não responde a chave inventada.

### O diff do controller da lista (T-187-13)

| medida | resultado |
|---|---|
| hunks | **1** |
| linhas de **código** acrescentadas | **1** (contém `aguardando_aprovacao`) |
| linhas removidas | **0** — a mudança é ADITIVA |
| endpoints `criativo*` no diff | **0**, por SEMÂNTICA (universo lido do arquivo) |

⚠️ **E o gate conta linha de CÓDIGO, não linha.** Exigir "exatamente 1 linha acrescentada"
**proibiria documentar a linha** — gate que reprova pelo próprio conserto é como o executor seguinte
aprende a ajustar o gate em vez do código. Isca: o diff **tem** comentário, então o filtro está
sendo exercitado.

### ⚠️ MEDIÇÃO: são **11** endpoints `criativo*`, não 12

`grep -n "public function criativo"` em `MlbAnuncioController.php` devolve **11**
(`:2335`-`:3255`). O gate lê o universo do arquivo (não de uma lista à mão) e exige `> 5` — contagem
fixa sobre "quantos existem" é frágil por construção (armadilha 5).

---

## 7. A página não soma nada — e por que a sonda ficou NESTA forma

O invariante é ABA-05: **o número vem do servidor**. A forma mais forte de garanti-lo é a tela **não
ter o que somar**:

- o JSON de **todas** as props de `meus()` **não contém** `"andamento":` nem nenhum dos 8 estados em
  **posição de valor** (`:"preparando"`, …);
- **isca**: o JSON da **FILA** contém **as 8** marcas (a `faixa` manda `"chave":"preparando"`), então
  a sonda não está cega;
- e nenhum `.jsx` tem `reduce(` junto com chave de andamento. Universo medido: **2** arquivos citam
  as chaves hoje (`andamentoDoPortao.jsx` e `SeloDeAndamentoInterno.jsx`, os dois da Fase 186 e
  somente leitura) e **nenhum** tem `reduce(`.

### ⚠️ DUAS armadilhas que esta sonda pegou nesta rodada (a 1ª versão REPROVAVA a tela)

A sonda crua (`str_contains($json, $estado)`) acusou **dois falsos positivos**, os dois da família
*"substring casa com prefixo"*:

| acusado | por quê | é legítimo? |
|---|---|---|
| `publicado` | casa dentro de **`"publicados"`** (chave de `subTotais`) e de **`"publicada_em"`** | sim — palavras do idioma na tela |
| `aguardando_aprovacao` | casa com **a própria prop deste plano** (`"aguardando_aprovacao":0`), em posição de CHAVE | sim — é o que TEM de existir |

➡️ A sonda passou a medir **posição de VALOR** (`:"estado"`) e a chave `"andamento":`. Linha de
oferta vazada traria o estado como valor, e é só isso que ela procura. Há **quatro controles** —
dois positivos e os **dois casos reais** que a derrubaram, como negativos.

### ⚠️ O QUE A SONDA DELIBERADAMENTE NÃO PROCURA: `.length`

A página da fila (187-04) vai **legitimamente** contar a lista que mostra (`countLabel`,
`Aprovacao.dc.html:1276`). Uma sonda que proibisse `.length` perto de uma chave de andamento
**reprovaria trabalho certo de outra wave** — é a família *"o gate é mais largo que o invariante"*,
que já **barrou um deploy** nesta milestone. A sonda do cliente ficou em `reduce(`, que é somar, e o
resto é coberto pela ausência de dado.

---

## 8. ⚠️ O gate de custo — o que ele é, e o que ele NÃO é (declarado)

**O que ele é:** `Http::fake()` + `Http::preventStrayRequests()` e **zero** requisição a
`mercadolibre.com` na abertura da fila, com **isca** provando que o predicado acusa (uma chamada
explícita a `/sites/MLB/listing_prices` faz o `assertNotSent` falhar).

**O que ele NÃO é, e por quê:** ele **não** é o gate de *"no máximo 1 requisição por trio
`(categoria, tipo, preço)` distinto"* nem de *"`para()` 1× por oferta"*. Aqueles medem
`BloqueiosDaOfertaService::para()` por oferta, e **o payload deste plano não chama `para()`**
(`empresa`, `conta`, `cabecalho`, `contador_da_aba`, `faixa`, `margem_minima` — nenhum bloqueio).
Escrevê-los agora seria **gate sobre ZERO casos**, que é exatamente a armadilha 4 do briefing. ➡️
Eles nascem no plano que trouxer o payload por oferta.

⚠️ **A régua do usuário está preservada e escrita no docblock do controller:** a tela **nunca
degrada** — zero cap por request, zero "sem tarifa", zero "incompleto por timeout". Cache frio
produz o número **certo**, só mais devagar; "limitar N consultas por abertura" foi **oferecido e
RECUSADO** porque incompleto **bloqueia a aprovação**. E o aquecimento do **187-10** muda **QUANDO**
a requisição acontece, não **QUANTAS** — este gate continua valendo depois dele.

---

## 9. A bijeção ids ↔ métodos, e os 4 gates

`tests/gates/contagens-187-02.txt`, todos **medidos** nesta máquina, uma invocação por gate:

| id | filtro | medido | tempo |
|---|---|---|---|
| `fila-servico` | os 10 métodos de `FilaDeAprovacaoServiceTest` | **10 passed / 46 assertions** | 7 s |
| `fila-rota` | os 7 de rota/payload | **7 passed / 53 assertions** | 7 s |
| `contador-e-territorio` | os 8 de contador/custo/território | **8 passed / 102 assertions** | 8 s |
| `bijecao-manifesto` | 1 método | **1 passed / 7 assertions** | 2 s |

**Total: 24 métodos / 208 asserções, todos pelo harness (`sh tests/gates/verificar-testes.sh 187-02
<id>`), nenhum por `artisan test` direto no veredicto.**

A bijeção falha nos **dois** sentidos (método sem id = **nunca roda**; id órfão = gate sobre zero
casos), em **UMA** asserção de contagem fixa **coletando os infratores**, com iscas provando que os
dois lados têm conteúdo. E há gate exigindo o **sufixo `_187_02`** em todo método — nome genérico
colide e o `--filter` **soma** as contagens de homônimos.

⚠️ **A bijeção tem id SEPARADO de propósito:** é o único gate que **lê** o manifesto. Metê-la num id
junto com os outros faria a contagem daquele id depender do próprio manifesto, e um conserto do
manifesto mudaria o número que o manifesto declara.

⚠️ **O único número medido DEPOIS de o arquivo existir** foi o da própria bijeção (escrevi 8,
**medi 7**, corrigi — está anotado na linha do manifesto, como diff visível).

---

## 10. A campanha de mutação — 8 execuções, 8 mortes, **0 sobreviventes**

Protocolo, na ordem: **commitar ANTES** → `cp` para o scratchpad → aplicar com a ocorrência
**travada em 1 ANTES** (aborta se ≠ 1) e a **linha conferida depois** → gate → **restaurar DA
CÓPIA**, em `trap EXIT` → `sha256` contra `git show HEAD:`. ⚠️ **`git checkout` não foi usado nenhuma
vez.**

| # | mutação | gate | veredicto |
|---|---|---|---|
| M1 | `PubAprovacaoOferta::LIVE` → `[AGUARDANDO_APROVACAO, EM_REVISAO_ECF]` (2 dos 3) | `fila-servico` | MORREU (1 failed / 9 passed) |
| M2 | `filaDaConta(...)` → `PubAprovacaoOferta::query()` (**sem recorte**) | `fila-servico` | MORREU (**5** failed) |
| M3 | `ordenarFila($ofertas)` → `$ofertas->values()` (sem ordem) | `fila-servico` | MORREU (3 failed) |
| M4 | `margemMinimaComOrigem(...)` → `['valor' => 12.0, 'origem' => 'config']` | `fila-servico` | MORREU (1 failed) |
| M4b | **a mesma** mutação, contra o gate de **ausência do 12%** | `fila-rota` | MORREU (1 failed) |
| M5 | a prop do contador cravada em `0` | `contador-e-territorio` | MORREU (2 failed) |
| M5b | idem, com token distintivo — **linha 1207 conferida** | `contador-e-territorio` | MORREU (2 failed) |
| M6 | `ESCOPO` do gate de território alargado para `(187-0[12])` — passa a medir a **wave 1** | `contador-e-territorio` | MORREU (1 failed) |
| M7 | um id do manifesto com typo (gera método SEM id **e** id órfão) | `bijecao-manifesto` | MORREU (1 failed) |

Todos os 6 arquivos deste plano conferiram `sha256` **idêntico** ao `git show HEAD:` depois da
campanha.

### ⚠️ Um defeito do MEU mutador, achado pela própria campanha

Na M5 o mutador reportou *"MUTADA na linha 64"*, que é **mentira**: o detector de linha procura a
string de **substituição**, e a substituição era `0` — então casou a primeira linha do arquivo que
contém um `0` (um comentário). A mutação em si estava correta (a trava de ocorrência = 1 garante o
lugar), mas **o relatório de linha não é confiável para substituição curta**. Refiz como **M5b** com
um token distintivo e a linha saiu **1207** — a linha da prop. ➡️ Lição: em campanha de mutação, a
**trava de ocorrência** é a garantia; o relatório de linha só vale com substituição distintiva.

### ⚠️ A M6 é a mutação que mais importa

Ela prova que o gate de território **não é vazio por construção**: alargar o escopo para incluir a
**wave 1** (commits REAIS, que tocaram `resources/js`, `tailwind.config.js` e `tests/js/`) faz o
gate **reprovar**. Sem ela, "nada fora da lista permitida" poderia estar verde porque o conjunto
medido era vazio.

---

## 11. Território — por ESCOPO DE ASSUNTO, com a sessão paralela commitando no meio

O range `a3169a03..HEAD` tem **9** commits, e **um deles não é meu**: `1b83f2a8 feat(198)`, da sessão
paralela, **intercalado** entre o meu `b8b3f538` e o meu `f0c219fd`. O gate filtra por `(187-02)` e
passou — é a prova viva de que a forma está certa.

⚠️ **Nenhum gate aqui confere autor** (`user.name` é o mesmo para as três sessões → gate vazio por
construção) e **nenhum lê `git status`** (o `??` e o ` M` de outra sessão abortariam).

Arquivos tocados pelos meus commits — **exatamente** a lista permitida, nada fora:

```
app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php
app/Http/Controllers/MlbPublicadorAprovacaoController.php
app/Http/Controllers/MlbAnuncioController.php
routes/mlb_anuncios.php
tests/Unit/Publicador/Aprovacao/FilaDeAprovacaoServiceTest.php
tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php
tests/gates/contagens-187-02.txt
```

E os **29** alvos proibidos, cada um com **`test -e` ANTES** do diff (caminho fantasma mede nada e
sai verde — já aconteceu **6 vezes** nesta milestone): os **10** arquivos da Fase 186 em
`app/Services/Publicador/Aprovacao/`, `app/Services/Creative`,
`app/Services/Publicador/Criativos`, `resources/js` (inteiro), `MlPublicacaoService.php`,
`app/Support/Publicador`, `app/Mcp`, `app/Services/Bling`, `app/Services/Portal`,
`resources/js/Components/Publicador/Mesa` (**sem** o `Mlb`), `Pages/Mlb/Publicador/Editor.jsx`,
`Layouts/AppLayout.jsx`, `tests/js/estrutura-meus-anuncios.test.js` (D-11/D-22),
`package.json`/`package-lock.json`/`composer.json`/`composer.lock` (T-187-SC),
`database/migrations`, `.planning/ROADMAP.md`, `.planning/STATE.md` → **todos com 0 toques**.

Iscas do gate: o conjunto de commits é **não vazio**, o arquivo-âncora **aparece**, a sonda de escopo
**acusa** `feat(187-02)` e **NÃO acusa** `feat(197)` nem `feat(187-01)`, e a comparação de prefixo de
diretório casa `resources/js/Pages/X.jsx` e **não** casa o irmão `resources/jsonzinho.txt`.

### ⚠️ Nenhuma migration — confirmado

`database/migrations` está na lista de proibidos com 0 toques. Não faltou coluna em nenhum momento: o
índice `pubapof_conta_andamento_ix` (`company_id`, `andamento`) **já existia**
(`2026_10_15_100200_create_pub_aprovacao_ofertas_table.php:302`) e foi lido do schema vivo.
**`artisan migrate` não foi executado** — nem o meu, nem a migration pendente da sessão paralela.

---

## 12. ⚠️ A ÁRVORE COMPARTILHADA — como o `routes/mlb_anuncios.php` foi commitado

**No momento em que fui editar a rota, a sessão paralela tinha trabalho NÃO COMMITADO no mesmo
arquivo** (um bloco `Route::prefix('ativos')` da "Fase 198", em `:263`). Um `git add -- routes/…`
teria publicado o WIP dela dentro do meu commit.

Procedimento usado nos **dois** commits que tocaram aquele arquivo:

1. `cp` do arquivo da árvore (meu + dela) para o scratchpad;
2. `git show HEAD:routes/mlb_anuncios.php` → base;
3. reconstrução de **base + só o meu bloco**, por **âncora de texto** (`->name('meus.detalhe');`),
   conferida por `diff`: **0 linhas removidas**;
4. `cp` do reconstruído → árvore, `git add`/`git commit` **por pathspec**;
5. `cp` da cópia de volta → árvore, e `sha256` conferido **idêntico** ao estado de antes.

Resultado medido: meu commit tem **só** o meu hunk; o trabalho dela ficou intacto na árvore (e ela
já commitou desde então — `feat(198)` — e segue com a "Fase 199" em WIP).

⚠️ **`git add -A`, `git stash`, `git reset`, `git checkout` e `git clean` não foram usados nenhuma
vez.** Mensagens acentuadas por `-F <arquivo>`.

---

## 13. Desvios do plano

### Auto-corrigidos

**1. [Regra 3 — bloqueio] O `use` do controller da fila faltava e a rota resolvia para o vazio**
- **Encontrado em:** tarefa 2, no primeiro GREEN
- **Problema:** `routes/mlb_anuncios.php` referenciava `MlbPublicadorAprovacaoController::class` sem
  `use`. Em PHP isso resolve para a string do namespace **global**, e o router devolveu
  *"Target class [MlbPublicadorAprovacaoController] does not exist"*. A rota **existia e não levava a
  lugar nenhum** — exatamente o que esta fase existe para evitar.
- **Correção:** import em ordem alfabética, entre `MlbPublicadorAcervoController` e
  `MlbPublicadorBlingController`.
- **Commit:** `5b5dcb6d`

**2. [Regra 1 — gate verde por construção] A isca do admin usava GET sem `X-Inertia` e dava 500**
- Ver §3. Passou ao caminho Inertia, com a causa medida escrita no docblock.
- **Commit:** `b8b3f538`

**3. [Regra 1 — gate verde por construção] O caso do usuário ANÔNIMO vinha 200**
- **Problema:** `actingAs()` do Laravel **persiste** pelo resto do método, então um `get()` sem ator
  depois de um `actingAs()` continua **autenticado**. Escrito ingenuamente, aquele caso seria **verde
  por construção** (ou reprovaria por motivo errado, como reprovou).
- **Correção:** T-187-12 passou a ser provado por **igualdade** da pilha de middleware com a rota da
  lista — lista **fechada**, não `assertContains('role:admin')`, que passaria com um middleware extra
  ao lado.
- **Commit:** `b8b3f538`

**4. [Regra 1 — sonda mais larga que o invariante] A sonda de vazamento acusava a tela**
- Ver §7: `publicado` ⊂ `"publicados"`/`"publicada_em"`, e `aguardando_aprovacao` casava com a
  própria prop deste plano. Passou a medir **posição de valor**, com 4 controles.
- **Commit:** `f0c219fd`

**5b. [Regra 3 — bloqueio] O gate de custo da Fase 180 reprovou a suíte, e CORRETAMENTE**
- Ver §17.1. `CONSULTAS_DA_ACTION` 33 → 34, re-medido, com a consulta nova enumerada no docblock,
  pelo protocolo que o próprio gate declara. **Commit:** `6e011011`
- Consequência: `tests/Feature/Publicador/PublicacoesDaTelaTest.php` entrou na lista permitida do meu
  gate de território, com a razão escrita ao lado da linha.

**5. [Regra 1 — gate que reprova pelo próprio conserto] O docblock do serviço derrubava a isca**
- A isca do leitor sem comentário exigia que o arquivo **com** comentário citasse
  `aguardando_aprovacao`, e o docblock não citava. Em vez de enfraquecer a isca, acrescentei ao
  docblock a frase que **tem** de estar lá (quais são os três `LIVE`) — e ela é, agora, a própria
  isca. **Commit:** `f0c219fd`

### Decisões tomadas dentro do território do plano

**6. A aba do cabeçalho global continua `'publicacoes'`.** `CabecalhoPublicadorService::ABAS` é lista
FECHADA de cinco (`visao-geral`, `produtos`, `publicacoes`, `alavancas`, `configuracoes`) e **não
ganhou um sexto valor**: a fila é aba **INTERNA** da tela de Publicações (as duas abas do protótipo
vivem dentro do mesmo header), não entrada nova do menu global. Mandar `'aprovacao'` faria o header
perder o destaque de Publicações **e** exigiria alterar um serviço que este plano não possui.

**7. O contador é resolvido por `app()` e não por injeção na assinatura.** A trava de território é
"UM hunk, só na lista de props"; mexer na assinatura de `meus()` abriria um **segundo** hunk no
arquivo onde vivem os 11 endpoints `criativo*` do outro dev. Está documentado na própria linha, com o
caminho nomeado para quando a action for aberta por outro motivo.

---

## 14. Divergências medidas, com `arquivo:linha`

| # | divergência | referência do plano/briefing | ⚠️ MEDIDO |
|---|---|---|---|
| **V1** | a baseline PHP mudou pelo meu conserto | `187-BASELINES.md` §1: `2346 / 20243 / 1` | **`2347 / 20264 / 0`**, exit 0 — a falha herdada saiu em `a3169a03` |
| **V2** | número de props de `meus()` | "as **20** props de hoje" | **19** — contadas por script em `MlbAnuncioController.php`, no `Inertia::render('Mlb/MeusAnuncios'` |
| **V3** | número de endpoints `criativo*` | "os **12** endpoints `criativo*`" | **11** — `grep "public function criativo"`, `MlbAnuncioController.php:2335`-`:3255` |
| **V4** | a colisão de rota | "não rouba `meus.detalhe`" tratado como risco | **impossível por estrutura**: `:450` tem TRÊS segmentos, a fila tem DOIS. O gate foi reescrito para medir *"qual rota ganha a URI"*, que não é vazio |
| **V5** | GET sem `X-Inertia` | o plano diz "usa o caminho JSON"; o briefing diz "sem resolver o componente" | **confirmado e medido**: sem `X-Inertia` o `@vite` de `app.blade.php` erra por manifest (`Pages/Mlb/Publicador/Aprovacao.jsx` é do 187-04) |
| **V6** | casos do `--autoteste` | `187-BASELINES.md` §3: `14 casos` | **16 casos**, 0 divergência — a wave 1 acrescentou os dois de resolução de manifesto |
| **V7** | o gate do trio de tarifa | o briefing pede "1 requisição por trio" e "`para()` 1× por oferta" | **não é escrevível aqui**: `para()` não é chamado neste payload → seria gate sobre ZERO casos. Entregue no lugar: **zero** chamada ao ML, com isca |
| **V8** | o relatório de linha do meu mutador | — | **não confiável para substituição curta** (M5 reportou linha 64 com a mutação em 1207); refeito como M5b com token distintivo |

---

## 15. O que este plano NÃO fez, de propósito

- **Nenhuma tela.** Nada em `resources/`. A aba é do **187-03**, a página do **187-04**.
- **Nenhuma migration**, e `artisan migrate` não foi executado.
- **Nenhum arquivo da Fase 186 alterado** — os 10 de `app/Services/Publicador/Aprovacao/` foram
  **CHAMADOS**, nunca tocados (`numstat` 0, provado por commit de escopo).
- **Nenhuma reimplementação** de recorte, ordem, faixa ou margem mínima: 5 métodos, 5 delegações, com
  gate provando ausência de lógica própria (API pública travada em contagem fixa de 5).
- **Nenhum `para()`** de bloqueios no payload → nenhum gate de trio de tarifa (seria vazio).
- **Nenhum gate `js`**, nenhum `npm run build`: diff sem JS.
- **Nenhum `state.advance-plan`**, nenhum toque em `ROADMAP.md`/`REQUIREMENTS-v27.md`/`STATE.md`.
- **Nenhum deploy, push ou comando no VPS.** Nenhuma chamada à API real do Mercado Livre.
- **Nenhuma afirmação de tela vista renderizada** — a conferência é do usuário, em produção, na
  conta 459.

---

## 16. Pendências NOMEADAS para as waves seguintes

1. **187-03 (a aba):** consumir a prop `aguardando_aprovacao` e linkar para `mlb.anuncios.meus.aprovacao`.
   ⚠️ **Ver §3**: até o 187-04 a página não existe, e a aba levará a erro. **Decisão de sequenciamento
   em aberto.**
2. **187-04 (a página):** criar `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` e **acrescentar a
   asserção de componente RESOLVIDO** (e o caminho sem `X-Inertia`) ao
   `FilaDeAprovacaoRotaTest` — está declarado no docblock daquele arquivo e do controller.
3. **O plano que trouxer o payload por oferta:** escrever o gate de *"≤ 1 requisição por trio
   distinto"* e *"`para()` 1× por oferta"*. Ver §8.
4. **Fase 194:** passar a criar `PubAprovacaoOferta`. Até lá o contador é **0** em produção — e isso é
   honesto, não defeito.
5. **Fase 190/191:** a revisão detalhada (`…/aprovacao/{oferta}/{aba}`), que **não** nasceu aqui.

---

## 17. ⚠️ A suíte PHP sai com 2 falhas — UMA era minha (consertada) e as outras NÃO são

### 17.1 A minha, e o gate estava CERTO

`PublicacoesDaTelaTest::test_custo_da_action_esta_travado` (Fase 180) trava o número de consultas de
`meus()` em `CONSULTAS_DA_ACTION`. Este plano acrescenta **uma** consulta àquela action **de
propósito** — então o gate reprovou **corretamente**. A mensagem do **próprio gate** diz o que fazer:

> *"o número de consultas da action mudou. Se foi de PROPÓSITO, atualize `CONSULTAS_DA_ACTION` e
> escreva no docblock qual consulta entrou/saiu. Se não foi, é N+1 …"*

Foi exatamente isso, e **nada mais**:

- `CONSULTAS_DA_ACTION`: **33 → 34**, **re-medido** nesta máquina depois da mudança;
- a **34ª enumerada** no docblock, com o SQL, o índice e a razão de a tela receber o **número** e
  nunca as linhas da fila;
- o caso irmão `test_custo_nao_cresce_de_um_para_cinquenta_itens` segue verde **sem mudança** — um
  `count(*)` por conta não cresce com a linha;
- **nenhuma asserção removida nem enfraquecida**: o docblock **ganhou** conteúdo.

⚠️ **Isto não é "ajustar gate para passar"** — é o protocolo que o próprio gate declara para mudança
intencional, com o número re-medido e a consulta nomeada. O arquivo entrou na lista permitida do meu
gate de território **com a razão escrita ao lado da linha**. Commit `6e011011`.

### 17.2 As outras NÃO são deste plano — e a prova é a NÃO-DETERMINAÇÃO

| rodada | falhas |
|---|---|
| 1ª | `PortalFotosTest > foto com resolucao gigante nao e decodificada e entra no resumo` (esperava `['dimensao_grande' => 1]`, veio `['arquivo_sumido' => 1]`) |
| 2ª | `SincronizaPortalCompletoTest > sincronizar duas vezes e idempotente` e `> empresa fora do piloto e preenchida igual d10` (`assertSame(3, $antes['pub_imagens'])` → veio **0**) |

**Cinco provas, todas medidas:**

1. **As três PASSAM em isolamento** (`artisan test --filter=<método>` → `PASS`).
2. **As falhas MUDARAM entre as duas rodadas**, e a única mudança de código entre elas foi o meu
   docblock + `CONSULTAS_DA_ACTION` — que **não pode** afetar leitura de foto. ➡️ **Não-determinação
   sob pipeline de foto inalterado é prova de estado EXTERNO**, não de regressão de código.
3. **As três vivem no mesmo pipeline**: `arquivo_sumido` vem de
   `PortalParaRascunhoService::copiarFoto` → `$this->leitor->lerImagem()` devolvendo `null`
   (`PortalParaRascunhoService.php:853`), e `pub_imagens` = 0 é o mesmo caminho não criando imagem.
   **É leitura de arquivo no disco.**
4. **Meus 8 commits tocaram 7 arquivos** (provado pelo gate de território por escopo de assunto),
   **nenhum** no pipeline de imagem.
5. **A sessão paralela está editando esse pipeline AGORA.** Commitado no range:
   `1b83f2a8 feat(198)` mexeu em `ImagemAssetService.php` (+37) e `AcervoDeImagensService.php`
   (+139). Não commitado no momento desta medição: `app/Support/Publicador/Imagem/ArquivosDeImagem.php`,
   `app/Models/AcervoImagem.php`, `docs/imagens-r2.md`, `.planning/REQUIREMENTS-v28.md` — e, mais
   cedo, `config/publicador.php` e `ImagemAssetService.php`.

⚠️ **NÃO corrigidas, de propósito e por três razões:**

1. O pipeline de imagem/acervo é território da **sessão paralela** (Fases 196–199) e do Creative
   Engine — **proibido** a este plano (TERR).
2. Consertar teste alheio cujo código está **em WIP de outra sessão** é consertar um alvo em
   movimento: a falha já mudou de teste uma vez em 1 h.
3. O harness **não foi afrouxado**: não criei `REF_PHP_FALHAS` e não mexi na régua (`_f > 0`
   continua reprovando). A diferença está **registrada aqui, não silenciada** — a mesma disciplina
   que o `187-BASELINES.md` §2.2 adotou para a falha herdada da wave 1.

➡️ **Para quem comparar:** o *antes* deste plano é `2347 / 20264 / 0`; o *depois* é
`2373 / 20473 / 2`, com as 2 falhas **nomeadas acima** e **fora da área deste plano**. A área deste
plano está **100% verde** (os 4 gates, 24 métodos, 208 asserções).

---

## 18. ⚠️⚠️ UM RISCO LATENTE QUE EU ACHEI E **NÃO** CORRIGI — precisa de decisão

**O recorte da fila usa as DUAS âncoras com `AND`, e isso pode esconder oferta.**

O plano manda (e eu segui): *"tirar daí o `mlb_empresa_id` — a fila é recortada pelas **duas**
âncoras quando as duas existem"*. `AndamentoDaOfertaService::filaDaConta()` aplica **os dois
`where`** quando os dois chegam. Medido no SQL real de `PublicacoesDaTelaTest`:

```sql
select count(*) from "pub_aprovacao_ofertas"
 where "company_id" = ? and "mlb_empresa_id" = ? and "andamento" in (?, ?, ?)
```

⚠️ **`pub_aprovacao_ofertas.mlb_empresa_id` é ANULÁVEL por construção.** Então, numa conta que **tem**
`MlbEmpresa` ligada, qualquer oferta gravada com `mlb_empresa_id = NULL` fica **invisível** no
contador e na fila — e o número diria **0** com trabalho esperando.

**Por que eu NÃO corrigi:**

- a semântica do `AND` é do `AndamentoDaOfertaService` (Fase 186), **somente leitura** para mim;
- fazer o recorte "`company_id` OU `mlb_empresa_id`" **no meu serviço** criaria a **segunda verdade**
  que este plano existe para impedir (e o gate de "sem lógica própria" reprovaria, certo);
- **hoje não é bug**: nada cria `PubAprovacaoOferta` até a **Fase 194**, então o contador é 0 de
  qualquer jeito.

➡️ **A decisão pertence à Fase 194 (ou a quem puder tocar a 186):** ou a 194 **preenche as duas
colunas sempre** (e aí o `AND` está certo e é mais seguro), ou o recorte precisa virar
`company_id OR mlb_empresa_id` dentro do serviço da 186. **Está aqui para ser decidido, não
silenciado.**

---

## Self-Check: PASSED

Conferido por comando, não por leitura:

- os **6** arquivos criados → `FOUND` (os 5 de código/teste + a SUMMARY);
- os **9** commits deste plano → `FOUND` em `git log --all`;
- os **4** gates pelo harness → `PASSOU` (re-rodados **depois** da última mudança);
- os **6** arquivos de código conferidos por `sha256` **idênticos** ao `git show HEAD:` após a
  campanha de mutação.
