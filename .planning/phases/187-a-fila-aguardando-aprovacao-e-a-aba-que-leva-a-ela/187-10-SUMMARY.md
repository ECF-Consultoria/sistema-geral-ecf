---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 10
subsystem: publicador-aprovacao
tags: [publicador, aprovacao, fila, cache, tarifa-ml, job, fila-high, comando, custo]
requires:
  - "187-02 — `FilaDeAprovacaoService::paraAConta()` (o recorte por conta, com a `RegraViolada`)"
  - "186 — `ComposicaoFinanceiraService::para()` (quem monta e usa a chave de cache da tarifa)"
  - "187-06 / 187-08 — os dois gates de custo, que este plano RECONFEREIU e NÃO afrouxou"
provides:
  - "`AquecimentoDeTarifasService` — a varredura dos trios distintos e o aquecimento por CHAMADA ao serviço da 186"
  - "`AquecerTarifasDaFilaJob` — o acionador interativo, na fila `high`, com trava de 300 s por conta"
  - "`WarmTarifasDaFila` (`publicador:warm-tarifas`) — o acionador manual, com `--dry-run` e relatório de forma fixa"
  - "Os dois disparos: `MlbAnuncioController::meus()` e `MlbPublicadorAprovacaoController::index()`"
  - "A trava `SyncQueue`: conexão que executa em linha NÃO aquece (desvio Rule 2)"
affects:
  - "app/Http/Controllers/MlbAnuncioController.php (2 hunks, 25 inserções, ZERO remoções)"
  - "app/Http/Controllers/MlbPublicadorAprovacaoController.php (1 hunk, 22 inserções, ZERO remoções)"
tech-stack:
  added: []
  patterns:
    - "Aquecimento por CHAMADA ao serviço dono da chave (forma do `WarmAdmanDiffCache`) — nunca por chave recopiada"
    - "Job de aquecimento interativo na fila `high` com trava curta (forma do `AquecerPedidosMlEstruturaJob`)"
    - "Despachante embrulhado em `try/catch (\\Throwable)` — a tela no ar nunca cai por causa do aquecimento"
    - "Trava `instanceof SyncQueue`: conexão que executa em linha não recebe job de aquecimento"
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php
    - app/Jobs/AquecerTarifasDaFilaJob.php
    - app/Console/Commands/WarmTarifasDaFila.php
    - tests/Unit/Publicador/Aprovacao/AquecimentoDeTarifasServiceTest.php
    - tests/Feature/Publicador/Aprovacao/AquecerTarifasDaFilaTest.php
    - tests/gates/contagens-187-10.txt
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
decisions:
  - "A chave de cache é a MESMA por CONSTRUÇÃO: o aquecimento chama `para()` e não monta chave nenhuma"
  - "A tela NUNCA degrada: zero cap por request, zero 'sem tarifa', zero 'incompleto' — cache frio dá o número CERTO, só mais devagar"
  - "Fila `high` com trava de 300 s (MENOR que o TTL de 1 h da tarifa, senão a trava impediria o reaquecimento)"
  - "O comando NÃO entra no cron: o cache é de 1 h, então `dailyAt()` seria decorativo e `hourly()` gastaria 24 varreduras/dia"
  - "Conexão de fila que executa EM LINHA não aquece — melhor não aquecer do que aquecer dentro do request (Rule 2)"
metrics:
  duration: "~5 h"
  completed: "2026-10-17"
  commits: 4
  files: 8
  insertions: 2755
  deletions: 6
  gates: "12 ids / 26 métodos / 219 asserções"
  mutations: "9 rodadas, 9 mortas, 0 sobreviventes"
---

# Fase 187 Plano 10: O pré-aquecimento das tarifas do Mercado Livre — Summary

A tarifa do Mercado Livre passou a ser consultada **fora do request**, por job na fila `high`
disparado ao abrir Publicações e ao abrir a fila, **sem que nenhum número da tela mude** — porque o
aquecimento **não constrói chave de cache nenhuma: ele chama `ComposicaoFinanceiraService::para()`**,
o mesmo método que a tela chama.

---

## 1. As baselines — na partida e no fim, separando o que é do 187-09 em paralelo

**Commit de partida, capturado na PRIMEIRA ação:** `f75864d0`
(`git rev-parse --short HEAD`, antes de qualquer edição).

| suíte | referência da wave 8 em `f75864d0` | **MEDIDA por mim na partida** | **MEDIDA por mim no fim** |
|---|---|---|---|
| PHP | 2445 / 21.463 / **2** falhas | **2445 / 21463 / 2** ✅ bate | **2482 / 21754 / 4** |
| JS | 5409 / 5406 pass / **1** fail / 2 skipped | **5365 / 5344 pass / 15 fail / 6 skipped** ⚠️ **NÃO bate** | **5476 / 5460 pass / 15 fail / 1 skipped** |

### 1.1 PHP — a partida bateu exatamente, e as 2 falhas eram as ALHEIAS previstas

Medida em background **antes** de qualquer edição de produção: `PHP: 2445 / 21463 / 2`. As duas
falhas, identificadas por semântica:

```
FAILED Tests\Feature\Publicador\CockpitDaContaTest > publicacoes criadas mede as duas janelas e a serie de trinta dias
FAILED Tests\Feature\Publicador\CockpitDaContaTest > evento sem data vai para o ultimo grupo nunca para o topo
```

Exatamente as duas que o briefing nomeou (Fase 179, `:966` e `:1318`, fragilidade de **fronteira de
dia**). ⚠️ **Não corrigidas, não contadas como regressão minha, e NÃO criei `REF_PHP_FALHAS`.**
⚠️ **Achado:** no fim do trabalho elas estavam **VERDES** — a fronteira de dia passou. É a prova
empírica de que a causa é o relógio, não o código.

### 1.2 PHP no fim — 4 falhas, e **NENHUMA é minha**. A conferência, uma a uma

```
FAILED FilaDeAprovacaoRotaTest > rota da fila resolve o componente da pagina 187_04
       → "gate sobre caminho fantasma: rode `npm run build`"
         Failed asserting that file ".../public/build/manifest.json" exists.
FAILED FilaDeAprovacaoRotaTest > a pagina da fila nao desenha o que nao existe
       → diff de `Aprovacao.jsx`, nos imports de BarraDeSelecao / ModalDeLote / ToastDaFila
FAILED SincronizaPortalCompletoTest > nada no ml conta liberada ... UnableToCreateDirectory
FAILED SincronizaPortalCompletoTest > sincronizar duas vezes e idempotente
```

| falha | de quem | prova |
|---|---|---|
| 1 e 2 | **187-09** | `public/build/manifest.json` **não existia** no instante da medição — o Vite apaga e reescreve `public/build` durante o `npm run build`, e a sessão paralela estava construindo os `.jsx` dela. A falha 2 é literalmente o diff de `Aprovacao.jsx` nos **componentes dela**. ⚠️ **Meu diff tem ZERO arquivo sob `resources/` e ZERO sob `tests/js/`** — medido: `git diff --name-only f75864d0..HEAD \| grep -E "^(resources\|tests/js)/"` sai **vazio**. O plano declara que `npm run build` não é exigido aqui, justamente por isso. |
| 3 e 4 | **flaky de concorrência** | **PASSAM em isolamento** (`Tests: 2 passed (12 assertions)`). `UnableToCreateDirectory` é a pasta `storage/framework/testing` sendo escrita por duas suítes ao mesmo tempo (eu e a sessão paralela). Território `app/Services/Portal/`, que está nos meus proibidos e **não aparece no meu escopo** — medido. |

⚠️ **A diferença de contagem FECHA com o meu diff:** 2445 → 2482 = **+37** testes. Meus são **26**;
os outros 11 são do 187-09 (`LoteDaFilaTest`). Asserções 21.463 → 21.754 = **+291**, das quais
**219 são minhas** (medido: `artisan test` nos meus dois arquivos → `26 passed (219 assertions)`).

### 1.3 JS — ⚠️ a partida JÁ NÃO BATIA com a referência, e a causa é o 187-09 em voo

A referência da wave 8 é `5409 / 5406 pass / 1 fail / 2 skipped`. **Na minha partida eu medi
`5365 / 5344 pass / 15 fail / 6 skipped`** — ou seja, a árvore já estava com WIP alheio **antes de
eu tocar em nada**. Prova colhida no mesmo instante:

```
 M resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
?? resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx
?? .../CascaDeModal.jsx  ?? .../ModalDeLote.jsx  ?? .../RodapeDaFila.jsx
?? .../ToastDaFila.jsx   ?? .../VazioDaFila.jsx
 M tests/js/publicador-fila-zindex-187.test.js
 M tests/js/publicador-vocabulario-novo.test.js
```

E as falhas nomeadas por semântica incluem literalmente `a prescrição do 187-09 (barra de lote e
toast)` e `GATE-03 — nenhum arquivo de ... fica FORA de ARQUIVOS_NOVOS` (os seis componentes novos
dela ainda não estavam em `ARQUIVOS_NOVOS`).

➡️ **Não ajustei nada e não corrigi nada.** No fim: `5476 / 5460 pass / 15 fail / 1 skipped` — os
testes **cresceram** (5365 → 5476, +111, todos dela) e as falhas **não aumentaram** (15 → 15).
⚠️ A falha pré-existente da Fase 87 (`estrutura-grade-glide.test.js`, *"Características secundárias
nasce recolhido"*) continua lá e **não foi corrigida**.
⚠️ Os `skipped` foram de 6 para 1 — é ela acordando os tokens `pub-float`/`pub-float-line`, como o
briefing previu. **Nada disso é meu:** este plano não escreve `.jsx` nenhum.

---

## 2. A prova de que o aquecimento NÃO constrói chave de cache

São **três provas independentes**, e nenhuma é por inspeção.

### 2.1 ESTRUTURAL — não existe caminho para montar aquela chave de fora

Medido nesta máquina em 17/10/2026:

```
$ grep -n "tarifaDoMl" app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php
238:        $tarifa = $this->tarifaDoMl($oferta, $listingType, $preco);
462:    private function tarifaDoMl(PubAprovacaoOferta $oferta, string $listingType, float $preco): array
```

**Duas** ocorrências: a declaração (`:462`, **`private`**) e o **único** chamador, `para():238`.
A chave nasce em `:474`. ➡️ Divergir exigiria editar um arquivo da Fase 186, que é **intocável**.

### 2.2 COMPORTAMENTO — depois do aquecimento, a tela faz ZERO requisição

`test_o_aquecimento_e_idempotente_e_a_composicao_depois_nao_consulta_187_10`:
aquece 3 ofertas de 1 trio (1 requisição) → roda de novo (**0** requisições, idempotente) →
`ComposicaoFinanceiraService::para()` nas MESMAS ofertas (**0** requisições).
⚠️ Com **isca**: um trio que ninguém aqueceu **consulta** (1 → 2), senão o "ZERO" poderia ser um
`para()` que nunca consulta nada.

E a **ISCA DA CHAVE** (`test_ISCA_DA_CHAVE_aquecer_com_outro_preco_nao_serve_187_10`): aquecer com
`preço + 1` **NÃO serve** — a tela volta a consultar (1 → 2). É ela que separa *"o aquecimento
CHAMA `para()`"* de *"o aquecimento é parecido com `para()`"*.

### 2.3 ESTRUTURAL POR GATE — o literal em exatamente UM arquivo de `app/`, com isca invertida

`test_o_literal_da_chave_mora_em_UM_arquivo_so_de_app_187_10` varre **todos** os `.php` de `app/`
(>200 arquivos), lê o código **sem comentário** (`token_get_all`) e exige:

- o literal `'aprovacao:tarifa:'` em **exatamente um** arquivo — e ele é
  `app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php`;
- **ZERO** nos três arquivos deste plano, nomeados um a um (com `test -e` coletando fantasmas);
- ⚠️ **ISCA DIREÇÃO 1:** cópia sintética do literal **no CÓDIGO** de um `.php` temporário **é
  pega**;
- ⚠️⚠️ **ISCA DIREÇÃO 2 (A INVERTIDA):** o mesmo literal **num COMENTÁRIO** **não conta**. Sem ela,
  um docblock que explicasse *"eu não monto esta chave"* reprovaria o trabalho certo, e o conserto
  barato do executor seguinte seria **apagar a explicação** — a armadilha nº 2 da régua.

➡️ **A mutação 3** (montar a chave à mão no serviço novo) **REPROVOU** este gate.

---

## 3. O gate de custo — 1 requisição por trio distinto, com isca nas DUAS direções

`test_uma_requisicao_por_trio_distinto_com_isca_nas_duas_direcoes_187_10`:

| direção | cenário | medido |
|---|---|---|
| **1** | 4 ofertas, **2** trios (mesmo preço, dois tipos) | `trios=2`, **2** requisições, `para()` chamado **2×** |
| **2 (isca)** | 4 ofertas, **4** trios (quatro preços distintos) | `trios=4`, **+4** requisições |

Mais, no mesmo caso: o gravador contou **6** requisições, **6** trios distintos (zero repetição na
rede), e **ISCA DO ESPIÃO** — ele conta de verdade (`$antes + 1` depois de uma chamada extra).

**`para()` 1× por oferta / por trio, por ESPIÃO-SUBCLASSE.** O espião é uma subclasse anônima de
`ComposicaoFinanceiraService` que herda a assinatura real, então renomear `para()` na Fase 186
quebra o teste em vez de deixá-lo passar calado. ⚠️ **E ele é o discriminador da mutação 2**:
aquecer *todas* as ofertas em vez de uma por trio **não muda** a contagem de requisições (o cache
dedupa) e **muda** a contagem de `para()` — a mutação 2 **REPROVOU** por aí.

### 3.1 ⚠️⚠️ A fixture tem `categoria_id` PREENCHIDO, e a razão está medida

O briefing avisou e eu **confirmei no código**:
`tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php:161` é
`PubRascunho::create(['produto_id' => $produto->id])` — **sem `categoria_id`**. Com categoria vazia,
`ComposicaoFinanceiraService::tarifaDoMl()` devolve `semFonte(...)` em `:470` e **não sai para a
rede**. ➡️ `test_a_fila_nao_chama_o_mercado_livre_187_02` está verde **porque a fixture dele não tem
categoria**, não porque a fila nunca chama o ML.

➡️ **Toda fixture dos meus dois arquivos nasce com `categoria_id = 'MLB189007'`** (a categoria que
tem fixture de tarifa no repositório), e isso está escrito no docblock do helper. Os casos de
"pulada" tiram a categoria **de propósito, com a contagem exigida** — e aquele caso tem **isca**: a
mesma fixture **completa** aquece de verdade (`trios=1`, 1 requisição), senão ele seria satisfeito
por um serviço que nunca aquece nada.

### 3.2 ⚠️ A reconferência dos gates de custo das waves 6 e 8 — INTOCADOS e VERDES

Rodados **depois** de todo o meu trabalho, com as contagens dos manifestos **alheios inalteradas**:

```
gate 'custo-do-ml'    (187-06): esperado 2 passed / 13 assertions  →  Tests: 2 passed (13 assertions)  PASSOU
gate 'custo-da-linha' (187-08): esperado 1 passed / 10 assertions  →  Tests: 1 passed (10 assertions)  PASSOU
```

⚠️ **Nenhuma contagem alheia foi mexida. Nenhum gate alheio foi afrouxado.** Este plano muda
**QUANDO** a requisição acontece, **não QUANTAS**.

⚠️ Conferi também o **homônimo contra o filtro da wave 6**, que é o mais perigoso: o id
`custo-do-ml` filtra `test_CUSTO_(uma_requisicao_por_trio_distinto_com_isca_nas_duas_direcoes_187_06|…)`
e o meu método se chama `test_uma_requisicao_por_trio_distinto_com_isca_nas_duas_direcoes_187_10`.
O `--filter` é regex **não ancorado**, mas o prefixo `test_CUSTO_` e o sufixo `_187_06` separam os
dois — e a medição confirma: o id da wave 6 continua reportando **2** testes e o meu, **3**.

---

## 4. A prova de que a tela NUNCA degrada

| exigência do usuário (15/10/2026) | prova |
|---|---|
| **zero cap por request** | `territorio-aquecimento` lê o código dos 3 arquivos **sem comentário** e exige ZERO `array_slice(`, `take(`, `limit(`. ⚠️ As sondas são ancoradas na **FORMA do defeito**, nunca em `limite`/`max`, que são palavras comuns do português e reprovariam trabalho correto (armadilha nº 8 da régua). |
| **zero "sem tarifa" / "incompleto"** | o mesmo gate exige ZERO `Incompleto` no código. ⚠️ Com **ISCA DO LEITOR**: a prosa dos três arquivos **cita "incompleto" de propósito** (é o que o usuário recusou), e o gate prova que o literal **existe no arquivo cru** e **não existe no código sem comentário** — se o leitor parasse de tirar comentário, o gate reprovaria pela própria explicação. |
| **cache frio produz o número CERTO** | o serviço **não tem ramo de fallback**: ele só chama `para()`, que é a mesma função da tela. Cache frio = cache-miss = consulta = número certo, só mais devagar. Não existe caminho de degradação para testar porque não existe caminho de degradação. |
| **nada é gravado** | `test_o_aquecimento_nao_grava_nada_e_o_simular_nao_consulta_187_10`: `pub_aprovacao_ofertas`, `pub_aprovacao_composicoes` e `pub_aprovacao_eventos` com a **MESMA contagem** antes e depois, e a asserção-controle de que o cenário TEM as 2 ofertas (senão seria tabela vazia por construção). |

### 4.1 "0 trios" com fila vazia **NÃO é erro**

- `test_fila_vazia_devolve_zero_trios_e_isso_nao_e_erro_187_10`: `['trios'=>0,'aquecidos'=>0,
  'pulados'=>0,'falhas'=>0]`, `segundos` presente, **0** requisições, e a **forma do relatório é
  FECHADA** (`array_keys()` comparado por igualdade), então chave nova entra por decisão.
- `test_o_comando_com_fila_vazia_da_SUCCESS_e_nao_parece_erro_187_10`: exit **0**, linha
  `trios=0 OK=1 FAIL=0 pulados=0`, e a saída **não contém** `falhou` / `falha` / `erro` /
  `exception`.
- Medido: **nada cria `PubAprovacaoOferta` hoje** — quem passa a criar é a **Fase 194**. `0` é o
  número honesto, e gritar por um estado correto treina quem lê o log a ignorá-lo.

---

## 5. A fila `high`, a trava e o horário de agendamento justificado

### 5.1 Fila `high` — e a razão não é minha: está escrita no projeto

O docblock de `app/Jobs/AquecerPedidosMlEstruturaJob.php`, um job de aquecimento com a **mesma
forma** deste, diz:

> *"Fila `high`: é disparado ao abrir a página, com alguém olhando — na `default` de produção
> ficaria atrás do sync do acervo. Uma tentativa: se falhar, a próxima abertura da página dispara de
> novo (a trava expira em 5 min)."*

E a medição de produção confirma o risco da outra escolha: em **16/09/2026**, **179 jobs de acervo
do ML na `default`** seguraram um webhook da Clicksign **por horas** (⚠️ a tabela `jobs` dá 0 e
engana — a fila de produção é **Redis**). ⚠️ **NÃO** é `creative` (Creative Engine, outro dev) nem
`default`. A asserção é sobre a **FILA** (`Queue::assertPushedOn('high', …)`), e a **mutação 1**
(`'high'` → `'default'`) **REPROVOU**.

### 5.2 A trava — 300 s, e o número é cobrado por gate nas duas pontas

`AquecerTarifasDaFilaJob::TRAVA_SEGUNDOS = 300`, chave `aprovacao:aquecer:<companyId>`, **expira
sozinha** (nunca é liberada à mão: soltá-la devolveria o F5 repetido à `high`).

⚠️ **A janela é ESTRITAMENTE MENOR que o TTL de 1 h da tarifa**, e o gate lê os dois lados:
`assertSame(3600, (int) config('publicador.alavancas.cache.tarifa'))` +
`assertLessThan(3600, TRAVA_SEGUNDOS)`. **Por quê:** trava de 1 h ou mais impediria o
**reaquecimento** depois de o cache esfriar — a pessoa abriria a tela, a trava diria "já aqueci", e
a tela pagaria a consulta no request, que é exatamente o que este job existe para evitar. Com 300 s,
no pior caso 12 aberturas dentro da hora disparam o job, e **onze delas custam nada** (cache-hit
idempotente). A trava é **por conta** (gate provando que as chaves de duas contas diferem).

### 5.3 ⚠️ O HORÁRIO DE AGENDAMENTO: **não há agendamento, e isso é a decisão**

**`publicador:warm-tarifas` NÃO entra no `routes/console.php`.** A razão é medida, não preferência:

- o `adman:warm-diff` é agendado 2×/dia (`routes/console.php:25` e `:29`) porque o cache dele é
  **DIÁRIO** — *"auto-invalida à meia-noite"*;
- ⚠️ **o cache da tarifa é de 1 HORA** (`config/publicador.php:97`, `'tarifa' => 3600`). Logo um
  `dailyAt('06:00')` aqueceria às 6h e estaria **frio às 7h**: agendamento **sem efeito**, que é a
  definição de decorativo;
- e um `hourly()` faria **24 varreduras/dia** de uma fila hoje **vazia**, gastando chamadas contra o
  limite que `adman:sync`, `ml:sync`, `adman:sync-margem`, `adman:warm-diff` e o warm do fechamento
  **já disputam** (a disputa está nomeada em `routes/console.php:42`). ⚠️ E a janela **11h–12h45** já
  é das importações — exatamente onde um `hourly()` colidiria.

➡️ **Quem resolve o caso real é o JOB**, disparado por quem está abrindo a tela: é o único acionador
cuja janela **casa com o TTL de 1 h**. O comando existe para os dois usos em que ele é a ferramenta
certa: **(1)** depois de deploy ou de `cache:clear` no VPS (o remédio registrado no incidente de
**30/07/2026**) e **(2)** **medir** com `--dry-run` quantos trios distintos uma conta tem, sem
gastar chamada.

**Gate de ausência**, `test_NAO_existe_agendamento_do_warm_tarifas_no_cron_187_10`: lê
`routes/console.php` **sem comentário**, exige ZERO `publicador:warm-tarifas`, com
**controle positivo** (`Schedule::command('adman:warm-diff')` **ESTÁ** lá, logo a sonda lê o arquivo
certo e não um arquivo vazio), **isca** (ela pega um agendamento sintético) e a **razão exigida no
docblock do comando** (`1 HORA`, `dailyAt`, `hourly`, `adman:warm-diff`) — ausência sem razão escrita
é esquecimento disfarçado de decisão. ➡️ A **mutação 6** (acrescentar o `Schedule::command`)
**REPROVOU**.

### 5.4 Os dois disparos, e eles são diferentes de propósito

1. **`MlbAnuncioController::meus()`** — o que de FATO aquece antes do uso: é o passo imediatamente
   anterior a clicar na aba, e entre o carregamento da lista e o clique passam segundos.
2. **`MlbPublicadorAprovacaoController::index()`** — ⚠️ ele **não ajuda AQUELE request** (a página já
   está calculando `para()` por oferta no mesmo instante): aquece a **PRÓXIMA** abertura dentro da
   hora, para quem chega por URL direta ou recarrega. **Está escrito no controller.**

⚠️ **O disparo nunca derruba a tela.** `despacharParaAConta()` é `try/catch (\Throwable)` inteiro.
`test_fila_de_jobs_indisponivel_nao_derruba_nenhuma_das_duas_telas_187_10` aponta `queue.default`
para uma conexão inexistente e exige **200** nas duas telas — com **ISCA** provando que, na MESMA
configuração, o `dispatch()` **cru LANÇA** (sem a isca o 200 poderia ser um cenário em que nada deu
errado). A **mutação 5** (`despacharParaAConta(` → `dispatch(`) **REPROVOU**.

---

## 6. Desvios do plano

### 6.1 ⚠️⚠️ [Rule 2 — funcionalidade crítica ausente] A trava da conexão que executa EM LINHA

**Encontrado durante:** a medição final da suíte PHP, por um **gate ALHEIO reprovando**.

**O problema:** `FilaDeAprovacaoRotaTest::test_meus_ganha_exatamente_uma_consulta_187_02` (T-187-14,
wave 2) reprovou com o meu disparo em `meus()`:

```
T-187-14: `meus()` ganhou mais de UMA consulta à fila:
  select * from "pub_aprovacao_ofertas" where "company_id" = ?
| select count(*) as aggregate from "pub_aprovacao_ofertas" where "company_id" = ? and "andamento" in (?, ?, ?)
```

Na conexão `sync` (o default do `phpunit.xml`), `dispatch()` roda o job **DENTRO do request**.

**⚠️ Medido, não suposto:** `->afterResponse()` **NÃO resolve** — o `terminate()` roda dentro da
janela do `DB::listen` do teste; a única diferença é a **ORDEM** das duas consultas (experimento
feito com commit antes, cópia no scratchpad e restauração).

**⚠️⚠️ A leitura certa é que o gate alheio estava CERTO, e o risco é de PRODUÇÃO:** numa conexão que
executa em linha, `meus()` — a tela que está **NO AR** — pagaria as **N chamadas HTTP ao Mercado
Livre SINCRONAMENTE**. É a classe literal do incidente de **30/07/2026** (requisições lentas
ocuparam os workers do php-fpm e **até o login parou**), e não é risco de teste: basta alguém pôr
`QUEUE_CONNECTION=sync` no VPS.

**Correção, do MEU lado:**
```php
if (Queue::connection() instanceof SyncQueue) {
    return false;
}
```
**Melhor NÃO aquecer do que aquecer dentro do request:** sem aquecimento a tela dá o número
**CERTO**, só mais devagar — exatamente o comportamento que o usuário aceitou ao recusar "limitar N
consultas por abertura". Aquecer em linha seria a degradação que ele recusou, entrando pela porta
dos fundos.

⚠️ **A sonda é sobre o COMPORTAMENTO da conexão**, nunca `config('queue.default') === 'sync'` (que é
`'sync'` **também sob `Queue::fake()`**, e aí o aquecimento nunca aconteceria em teste nenhum) nem
`app()->runningUnitTests()` (que desligaria a trava em produção). **Isca nas duas direções**, e as
**mutações 7 e 8** existem para cada uma.

⚠️⚠️ **O gate alheio ficou INTOCADO e voltou ao VERDE.** Nenhuma contagem de manifesto alheio foi
mexida. E esta wave ganhou **prova própria** do mesmo invariante
(`test_conexao_de_fila_em_linha_NAO_aquece_dentro_do_request_187_10` reproduz o `DB::listen` do
T-187-14), para não depender de um gate que ela não pode tocar.
**Commit:** `61bb74db`.

### 6.2 Os 3 ids que o plano prescreve **não existem** com aquele recorte

O plano escreve `aquecimento-servico`, `aquecimento-job` e `aquecimento-comando`. Nenhum existe:
cada um cobriria 7–9 métodos numa alternância só, e **alternância grande equivale a filtro de
CLASSE** — que passa dos 120 s do shell deste ambiente e o helper **RECUSA** (medição do plano
186-07, e a mesma razão que quebrou o 187-06 em quatro ids, o 187-07 em sete e o 187-08 em nove).
➡️ Os **26** métodos viraram **DOZE** ids por assunto, **nenhum com mais de TRÊS**. Declarado no
cabeçalho do manifesto.

### 6.3 `MlbAnuncioController` recebeu **2 hunks**, não 1

O plano diz "1 hunk". O `CLAUDE.md` do projeto manda *"all imports are explicit FQCN via `use`"*, e
o import é um segundo hunk (no bloco de `use`). ➡️ 2 hunks: o `use` e o dispatch.
⚠️ **No `MlbPublicadorAprovacaoController` eu fiz o contrário de propósito — FQCN inline, 1 hunk** —
porque aquele arquivo é o **contendido com o 187-09**, e um hunk no meio do corpo colide menos que
um hunk no bloco de `use`. Declarado.

### 6.4 `ResumoRapidoService.php` **não existe** — o OITAVO caminho fantasma desta milestone

O briefing o nomeia entre os serviços de `Fila/`. `test -e` nesta máquina em 17/10/2026:
**não existe**. Saiu da minha lista de proibidos (caminho fantasma faz a varredura dizer "território
intocado" sobre um arquivo que nunca existiu). A v26.0 teve seis, a Fase 186 achou o sétimo.
⚠️ `tests/gates/contagens-187-09.txt` também ficou fora, pelo mesmo motivo: ele **ainda não existe
no disco** (a wave 9 commitou duas vezes sem ele).

### 6.5 `ARQUIVOS_NOVOS` do `publicador-vocabulario-novo.test.js` — **conferido, e NÃO precisava**

Confirmo que **confirmei**: este plano escreve **zero `.jsx`** e **zero arquivo sob `resources/`**
(medido por `git diff --name-only f75864d0..HEAD`). Logo nada entra em `ARQUIVOS_NOVOS`, e o id
próprio daquele gate (`vocabulario-tamanhos`, forma `>=443`) continua **só** em
`contagens-187-01.txt` — **não** foi embutido num id composto meu, que é exatamente o que fez a
wave 5 reprovar dois ids de outras waves.
⚠️ **E `>=` não é usado em nenhum id deste plano** (ele é opt-in e raro; aqui nenhuma contagem
cresce por construção).

### 6.6 ⚠️⚠️ O scratchpad é COMPARTILHADO com o 187-09 — meu script de mutação foi SOBRESCRITO

Medido em 17/10/2026, no meio do trabalho: o
`…/9d6e18bd-3274-4e1f-b419-e60cad5d7470/scratchpad/mutar.sh` que eu escrevi para a wave 10 voltou
com o cabeçalho **`Campanha de mutação do plano 187-09`**. Os dois subagentes foram lançados pela
**MESMA sessão** do orquestrador, então dividem o diretório de scratchpad — e nome genérico colide.
➡️ As mutações 1–6 já tinham rodado e estão registradas abaixo; a partir daí todo arquivo meu levou
nome único (`mutar-187-10-waveDEZ.sh`, `mutacao-187-10-waveDEZ-N.bak`). ⚠️ **Nenhum arquivo de
produção foi perdido:** conferi, por `sha256` com `tr -d '\r'` nos dois lados, que os **nove**
arquivos meus batem byte a byte com `git show HEAD:`.

---

## 7. A colisão no controller com o 187-09 — como foi tratada

Ordem de trabalho obedecida: **o `MlbPublicadorAprovacaoController` foi a ÚLTIMA edição**, depois de
todo o resto estar commitado (commits `9e897b22`, `cf8e63e0`).

**Imediatamente antes de editar:**

```
$ git diff HEAD -- app/Http/Controllers/MlbPublicadorAprovacaoController.php
[vazio]
$ git show HEAD:…MlbPublicadorAprovacaoController.php | tr -d '\r' | sha256sum
2210197aebab173d841a90ea0c730fba7205ef220589f63cf7dec35f3511fb76
$ tr -d '\r' < …MlbPublicadorAprovacaoController.php | sha256sum
2210197aebab173d841a90ea0c730fba7205ef220589f63cf7dec35f3511fb76
```

⚠️ **Zero WIP alheio** — porque o 187-09 **já havia commitado o dele** em `ef6518bf`, levando o
arquivo de **393 para 574 linhas** no meio do meu trabalho. É por isso que eu **re-li o ponto de
inserção** em vez de confiar no número de linha que eu tinha anotado: `$mlbEmpresaId = …` tinha
andado de `:143` para `:195`, e o mesmo texto passou a existir **duas vezes** no arquivo (`:195` no
`index()` e `:537` na action de lote dela) — âncora por número de linha teria escrito no lugar
errado.

**A edição, por ÂNCORA de texto** (`$mlbEmpresaId = …;` + o comentário dos três recortes logo
abaixo, que é único), com FQCN inline para ficar em **1 hunk**. Prova de **ZERO remoções**:

```
$ git diff --numstat -- …MlbPublicadorAprovacaoController.php
22      0       app/Http/Controllers/MlbPublicadorAprovacaoController.php
$ git diff -U0 -- … | grep -c "^@@"
1
$ git diff -- … | grep "^-" | grep -v "^---"
(nenhuma)
```

Commit **imediatamente depois** (`faf45f74`), por **pathspec** (`git add -- <caminho>` +
`git commit -F <arquivo> -- <caminho>`), para fechar a janela de colisão.

⚠️ **Nunca** usei `git checkout`, `git stash`, `git reset`, `git add -A`, `git clean` nem
`git commit --amend`. ⚠️ **`git status` geral nunca foi usado como "árvore limpa"** (ele tem 70
entradas `M`/`??` de outras sessões) — a conferência foi sempre `git diff HEAD -- <os meus
arquivos>`.

⚠️ **E eu não toquei em nada do 187-09:** `routes/mlb_anuncios.php`, `LoteDaFilaService`,
`BarraDeSelecao.jsx`, `CascaDeModal.jsx`, `ModalDeLote.jsx`, `ToastDaFila.jsx`, `RodapeDaFila.jsx`,
`VazioDaFila.jsx`, `TabelaDaFila.jsx`, `Pages/Mlb/Publicador/Aprovacao.jsx`,
`publicador-fila-lote-187.test.js`, `LoteDaFilaTest.php`, `contagens-187-09.txt` — **zero**, provado
pelo gate de território por **escopo do assunto**, e os três primeiros estão na lista de proibidos
dele.

---

## 8. A bijeção, o homônimo e os 12 gates

### 8.1 Bijeção ids ↔ métodos, nos DOIS sentidos

`bijecao-manifesto-10`: **12 ids** `php` ↔ **26 métodos** `*_187_10`, com
`assertSame([], $semId)` (método sem id **NUNCA RODA**) e `assertSame([], $orfaos)` (id órfão é gate
sobre ZERO casos), mais a isca do nome que ninguém escreveu.

⚠️ **Desvio declarado em relação ao molde do 187-08:** ele usa `get_class_methods($this)`, que só
veria **uma** das duas classes. Este plano tem **dois** arquivos de teste (Unit + Feature), então a
bijeção lê os nomes do **FONTE dos dois** — senão o `AquecimentoDeTarifasServiceTest` ficaria **sem
bijeção em silêncio**, que é justamente o furo que a bijeção existe para fechar.

⚠️ A contagem deste id é de **forma FIXA** (limiares + dois `assertSame([])` + isca): acrescentar id
ao manifesto **não mexe** no número — **conferido**, não suposto (ela ficou em **11 asserções**
antes e depois de o 12º id entrar).

### 8.2 Homônimo — conferido por SCRIPT, nos dois sentidos, por DUAS implementações

Varri os **26** nomes contra **todos os 7.668** `test_*` de `tests/`, nas duas direções (meu contém
o alheio / alheio contém o meu) **e entre os meus**, por duas implementações independentes (`awk` e
`case` de shell). **ZERO colisão, exata ou de prefixo.** Nenhum sufixo precisou ser trocado — e, se
precisasse, o conserto seria sufixo no método **NOVO** (`…_187_10`), nunca elevar a contagem do
manifesto alheio.

⚠️ E o **gate ALHEIO** que varre os meus dois arquivos novos —
`FechamentoDaFase186Test::test_os_nove_manifestos_nao_tem_homonimo_que_some_dois_arquivos`
(`tests/{Unit,Feature}/Publicador/Aprovacao/*.php`) — foi rodado e **PASSA com a contagem intacta**:
`esperado 1 passed / 31 assertions → Tests: 1 passed (31 assertions) PASSOU`.

### 8.3 Os 12 gates — todos MEDIDOS nesta máquina, uma invocação por id

| id | filtro | medido |
|---|---|---|
| `aquecimento-custo-e-chave` | 3 métodos | 3 passed / 33 assertions |
| `aquecimento-chave-estrutural` | 3 métodos | 3 passed / 23 assertions |
| `aquecimento-bordas` | 3 métodos | 3 passed / 16 assertions |
| `aquecimento-job-e-trava` | 3 métodos | 3 passed / 21 assertions |
| `aquecimento-disparos` | 3 métodos | 3 passed / 10 assertions |
| `aquecimento-sem-fila-em-linha` | 1 método | 1 passed / 11 assertions |
| `aquecimento-props-intactas` | 1 método | 1 passed / 22 assertions |
| `aquecimento-comando-relatorio` | 3 métodos | 3 passed / 15 assertions |
| `aquecimento-comando-vazio-e-falha` | 3 métodos | 3 passed / 17 assertions |
| `aquecimento-sem-duplicacao` | 1 método | 1 passed / 13 assertions |
| `territorio-aquecimento` | 1 método | 1 passed / 27 assertions |
| `bijecao-manifesto-10` | 1 método | 1 passed / 11 assertions |

Os dois arquivos rodados juntos: **`26 passed (219 assertions)`**.

⚠️ **O 1º argumento é `187-10`**, e a isca do número nu foi exercida:
`sh tests/gates/verificar-testes.sh 10 aquecimento-bordas` →
`ABORTOU: manifesto nao existe: …/contagens-186-10.txt`.

### 8.4 `--autoteste`

```
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

---

## 9. A campanha de mutação — 9 rodadas, 9 MORTAS, **0 sobreviventes**

Protocolo em todas: **commit ANTES**, `cp` para o scratchpad, ocorrência **travada em 1** (aborta se
≠ 1), **linha conferida no disco** depois da troca, restauração **DA CÓPIA** em `trap EXIT`,
`sha256` contra `git show HEAD:` com `tr -d '\r'` **nos dois lados**. ⚠️ **`git checkout` NUNCA.**
⚠️ **Âncoras de UMA LINHA**, porque os arquivos são **CRLF** (medido: CR = nº de linhas) e âncora
multi-linha abortaria sempre, dando a impressão falsa de "mutação impossível".

| # | mutação | gate | veredicto |
|---|---|---|---|
| 1 | `onQueue('high')` → `'default'` | `aquecimento-job-e-trava` | **MORREU** (1 failed, 2 passed) |
| 2 | aquecer **todas** as ofertas em vez de uma por trio | `aquecimento-custo-e-chave` | **MORREU** (2 failed, 1 passed) |
| 3 | montar a chave à mão no serviço novo | `aquecimento-chave-estrutural` | **MORREU** (1 failed, 2 passed) |
| 4 | aquecer com preço errado (`para($oferta, 1.0)`) | `aquecimento-custo-e-chave` | **MORREU** (3 failed) |
| 5 | tirar o despachante protegido de `meus()` (`despacharParaAConta(` → `dispatch(`) | `aquecimento-disparos` | **MORREU** (1 failed, 2 passed) |
| 6 | acrescentar `Schedule::command('publicador:warm-tarifas')->hourly()` | `aquecimento-comando-vazio-e-falha` | **MORREU** (1 failed, 2 passed) |
| 7 | tirar a trava da conexão EM LINHA (`if (false)`) | `aquecimento-sem-fila-em-linha` | **MORREU** (1 failed) |
| 8 | trocar a sonda de COMPORTAMENTO por sonda de CONFIG (`config('queue.default') === 'sync'`) | `aquecimento-sem-fila-em-linha` | **MORREU** (1 failed) |
| 5r | mutação 5 **re-rodada** depois da trava do item 6.1 | `aquecimento-disparos` | **MORREU** (1 failed, 2 passed) |

⚠️ **Duas abortaram na primeira tentativa, e as duas aborções são informação:**

- **mutação 2** abortou com *"a mutação não entrou"*: o substituto
  `foreach ($ofertas as $oferta) {` **já existe** no arquivo (é a varredura dos trios), então a
  troca crua daria **duas** ocorrências. Conserto: `foreach ($ofertas->all() as $oferta) {`, que
  deixa o alvo único — **o guarda funcionou**;
- **mutação 6** abortou com *"o âncora sobrou"*: ela é **aditiva**, e o substituto **continha** o
  âncora. Conserto: um espaço antes do `)` (`…'adman:reler-dias' )`), que faz o âncora desaparecer,
  mantém o PHP válido e **preserva o guarda intacto** — em vez de afrouxar o guarda.

---

## 10. Território — por ESCOPO DO ASSUNTO, com isca nas quatro direções

⚠️ **O autor não discrimina NADA nesta árvore:** as três sessões commitam com o mesmo `user.name`.
O discriminador é o escopo `/\(187-10\)/`, com isca contra `(187-09)` (**em paralelo**), `(187-08)`
e `(261016-mwe)`.

Range: `f75864d0..HEAD`, **filtrado pelo escopo**. Os 4 commits do escopo tocaram **exatamente 8
arquivos**, **2755 inserções, 6 remoções** — e as **6 remoções são todas minhas**, no cabeçalho do
próprio manifesto (os contadores `NOVE`→`ONZE`→`DOZE` e `25`→`26`), conferidas uma a uma. **Zero
remoções** em todos os arquivos de produção.

| arquivo | + | − |
|---|---|---|
| `app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php` | 210 | 0 |
| `app/Jobs/AquecerTarifasDaFilaJob.php` | 175 | 0 |
| `app/Console/Commands/WarmTarifasDaFila.php` | 129 | 0 |
| `app/Http/Controllers/MlbAnuncioController.php` | 25 | 0 |
| `app/Http/Controllers/MlbPublicadorAprovacaoController.php` | 22 | 0 |
| `tests/Unit/Publicador/Aprovacao/AquecimentoDeTarifasServiceTest.php` | 756 | 0 |
| `tests/Feature/Publicador/Aprovacao/AquecerTarifasDaFilaTest.php` | 1143 | 0 |
| `tests/gates/contagens-187-10.txt` | 295 | 6 |

**Territórios VAZIOS no escopo** (gate `territorio-aquecimento`, lista conferida por `test -e` numa
asserção única coletando fantasmas): os 9 arquivos da Fase 186 em `Aprovacao/` fora de `Fila/` ·
os 6 de `Fila/` das waves 2/5/6/7/8 · **`resources/` INTEIRO** · `app/Services/Creative` ·
`app/Services/Publicador/Criativos` · `ImagemAssetService.php` · `app/Support/Publicador` ·
`app/Mcp` · `app/Services/Portal` · `app/Services/Bling` · `MlPublicacaoService.php` ·
`MlbPublicadorCriativoController.php` · `estrutura-meus-anuncios.test.js` ·
`publicador-header-zindex-ordem.test.js` · `LinhaDaOfertaServiceTest.php` ·
`FilaDeAprovacaoRotaTest.php` · `contagens-187-07/08.txt` · os 4 arquivos de dependência
(T-187-SC) · `database/migrations` · `tailwind.config.js` · `.planning/ROADMAP.md` ·
`.planning/STATE.md`. Mais `routes/mlb_anuncios.php` e `LoteDaFilaService.php` (território do
187-09).

⚠️ **Zero migration, e `artisan migrate` NÃO foi rodado.** ⚠️ **Nada em `resources/`**, então
`npm run build` não foi exigido (gate de build sobre diff sem JS é gate vazio por construção — e é o
que o próprio plano declara).

---

## 11. Declarações obrigatórias

- ⚠️ **A fila APROVA (e aprovar enfileira), NÃO PUBLICA.** Este plano só **LÊ** tarifa; a escrita no
  Mercado Livre é da **Fase 193**. Nenhum arquivo deste plano toca `MlPublicacaoService`.
- ⚠️ **Nenhuma chamada à API real do Mercado Livre.** `Http::fake` + `Http::preventStrayRequests()`
  em **todo** caso dos dois arquivos, com as fixtures de
  `tests/fixtures-ml/sondagem/publico/categorias/MLB189007/`.
- ⚠️ **Eu NÃO vi esta tela renderizada nem este job rodando em produção.** O banco local não tem
  empresa com `ml_token` fora de fixture, e nenhum comando foi rodado contra o VPS. **A conferência
  é do usuário, em produção, na conta 459.**
- ⚠️ **NÃO deployei, NÃO dei push, NÃO toquei no VPS.** Não rodei `pint`, não criei worktree, não
  rodei `state.advance-plan`, não toquei em `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md` nem
  `STATE.md`. `tests/Feature/CompanyPortfolioAccessTest.php` segue `??` e **intocado**.
- ⚠️ **A Fase 187 está no ar parcial:** nada aqui é meio-feito. Os quatro commits deixam a árvore
  coerente, e o único estado RED intencional (`cf8e63e0`, dois métodos vermelhos) foi declarado no
  próprio corpo do commit e fechado no commit seguinte.

### 11.1 ⚠️ A verificação que falta, e que é do usuário

Em produção, na **conta 459**:

1. abrir `/mlb/anuncios/meus/459` (Publicações), esperar alguns segundos e então clicar na aba
   **"Aguardando aprovação"** — a fila deve abrir **sem o tempo de espera** da primeira carga;
2. conferir no log que saiu uma linha
   `[AquecimentoTarifas] empresa 459 aquecida em Ns — trios=… OK=… pulados=… falhas=…`;
3. ⚠️ **a fila nasce vazia** (nada cria `PubAprovacaoOferta` até a Fase 194), então o esperado hoje é
   `trios=0` — e **isso não é erro**. A prova de valor só aparece quando a Fase 194 começar a criar
   ofertas.
4. Se quiser medir antes disso: `php artisan publicador:warm-tarifas --empresa=459 --dry-run`
   imprime quantos trios distintos a conta tem **sem gastar uma única chamada ao ML**.

---

## 12. Divergências medidas, com `arquivo:linha`

| # | o quê | `arquivo:linha` |
|---|---|---|
| **V1** | ⚠️⚠️ `test_a_fila_nao_chama_o_mercado_livre_187_02` **não prova** que a fila nunca chama o ML: a fixture cria o rascunho **sem `categoria_id`**, e aí `tarifaDoMl()` devolve "sem fonte" **sem sair para a rede**. Confirmado no código. | `tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php:161` × `app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php:470` |
| **V2** | ⚠️⚠️ `->afterResponse()` **não tira** o job dispatchado da janela do `DB::listen` de um teste HTTP: o `terminate()` roda dentro dela. Medido por experimento; a única diferença é a **ORDEM** das duas consultas. | `tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php:728` |
| **V3** | `ResumoRapidoService.php` — nomeado pelo briefing entre os serviços de `Fila/`, **não existe no disco**. OITAVO caminho fantasma da milestone. | `app/Services/Publicador/Aprovacao/Fila/` (varredura `test -e`, 17/10/2026) |
| **V4** | `tests/gates/contagens-187-09.txt` **não existe** depois de dois commits da wave 9 (`ef6518bf`, `3db1474d`). Ficou fora dos meus proibidos: proibir caminho inexistente é gate sobre zero casos. | `tests/gates/` |
| **V5** | ⚠️ A baseline **JS** do briefing (`5409 / 5406 pass / 1 fail / 2 skipped`) **já não valia na minha partida** (`5365 / 5344 pass / 15 fail / 6 skipped`): a wave 9 estava em voo com 6 componentes novos e 2 gates JS editados. | `resources/js/Components/Mlb/Publicador/aprovacao/` + `tests/js/publicador-vocabulario-novo.test.js` |
| **V6** | ⚠️ `MlbPublicadorAprovacaoController` foi de **393 para 574 linhas** no meio do meu trabalho (commit `ef6518bf`), e `$mlbEmpresaId = $alvo['mlb_empresa']?->id;` passou a existir **duas vezes** (`:195` no `index()`, `:537` na action de lote). Âncora por número de linha teria escrito no lugar errado. | `app/Http/Controllers/MlbPublicadorAprovacaoController.php:195` × `:537` |
| **V7** | ⚠️⚠️ O **scratchpad é compartilhado** entre os dois subagentes paralelos: meu `mutar.sh` foi sobrescrito pelo da wave 9 no meio do trabalho. | `…/9d6e18bd-…/scratchpad/mutar.sh` |
| **V8** | ⚠️ As **2 falhas PHP alheias** de `CockpitDaContaTest` (Fase 179, fronteira de dia) estavam **VERDES** no fim do trabalho — prova empírica de que a causa é o relógio. | `tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318` |
| **V9** | ⚠️ A isca do vocabulário de erro foi escrita errada na primeira redação (`falha` **não** é substring de `falhou`) e **REPROVOU**. Consertada exercendo os **quatro** termos, não baixando a expectativa. | `tests/Feature/Publicador/Aprovacao/AquecerTarifasDaFilaTest.php` (caso `…fila_vazia_da_SUCCESS…`) |
| **V10** | ⚠️ `SincronizaPortalCompletoTest` falha na suíte completa com `UnableToCreateDirectory` e **passa em isolamento** — colisão de `storage/framework/testing` entre as duas sessões rodando suíte ao mesmo tempo. | `tests/Feature/Publicador/SincronizaPortalCompletoTest.php` |

---

## Threat Flags

Nenhuma. Este plano **não cria rota, endpoint, caminho de autenticação, acesso a arquivo nem coluna
de schema** — medido: zero `Route::` nos três arquivos de produção, zero migration, e os dois hunks
de controller são um `dispatch` cada, dentro de actions que já têm o `abort_unless` do `mlToken` e o
`role:admin` do grupo de rotas. `T-187-SC`: `numstat` **ZERO** nos quatro arquivos de dependência —
este plano não instala nada.

## Known Stubs

Nenhum. Não há valor vazio hardcoded, placeholder nem "coming soon". ⚠️ **O `trios=0` de hoje não é
stub: é o número real de uma fila que ainda não tem quem a preencha** (a Fase 194), e isso está
escrito nos três arquivos de produção e provado por gate.

## TDD Gate Compliance

Sequência no `git log` do escopo, na ordem:

1. `9e897b22` **`feat(187-10)`** — serviço + 9 testes (RED→GREEN no mesmo passo: os testes foram
   escritos com o serviço e medidos antes do commit);
2. `cf8e63e0` **`test(187-10)`** — job, comando, `MlbAnuncioController` e os 16 testes do Feature,
   com **dois métodos VERMELHOS declarados no corpo do commit** (o RED explícito);
3. `faf45f74` **`feat(187-10)`** — o disparo na fila: **GREEN** dos dois;
4. `61bb74db` **`fix(187-10)`** — a trava da conexão em linha (Rule 2), com o gate novo.

⚠️ O par RED (`cf8e63e0`) → GREEN (`faf45f74`) está explícito e justificado: o controller contendido
com o 187-09 só podia ser editado **por último**, então a prova dele nasceu vermelha de propósito.
Nenhum `refactor(...)` foi necessário.

## Self-Check: PASSADO
