---
quick_id: 260930-njd
slug: fechamento-do-mes-em-curso-bate-com-a-adman
date: 2026-09-30
status: complete
commits:
  - 0330252b
  - d03c5b1e
  - 85753664
---

# O fechamento do mês em curso passa a bater com a Adman

Três commits, na ordem do plano. **Nada foi para produção** — quem deploya e roda
o aquecimento a primeira vez é o orquestrador. A chave
`fechamento_faturamento_da_api_ativo` **segue desligada**, e com ela desligada
nada muda (com teste).

| commit | o que entrou |
|---|---|
| `0330252b` | T1 — mês em curso lê o total do período da Adman (do cache) |
| `d03c5b1e` | T2 — `adman:warm-fechamento` |
| `85753664` | T3 — `adman:reler-dias` + correção do `updateOrCreate` |

---

## T1 — Como ficou o gate do mês corrente

O bloqueio antigo era `$usarApi = $faturamentoDaApi && $mes !== now()->format('Y-m')`.
Saiu. No lugar entraram DOIS conceitos separados, e a separação é o ponto:

**1. A janela pedida à Adman** — `FechamentoRollupService::janelaDaAdman($mes)`, novo
método público, **única** definição dessa janela:

| competência | `janela()` (soma diária) | `janelaDaAdman()` (o que se pede à Adman) |
|---|---|---|
| mês corrente, hoje 30/09 | 01/09 .. **30/09** (hoje) | 01/09 .. **29/09** (ontem) |
| mês fechado (2026-08) | 01/08 .. 31/08 | 01/08 .. 31/08 (igual) |
| dia 1º do mês | 01/10 .. 01/10 | **`null`** — nenhum dia fechado |

O clamp em ontem existe porque a Adman é D-1: incluir hoje devolve um dia pela
metade, que muda de valor a cada hora. E **é a mesma função que o comando de
aquecimento usa** — a chave de cache é `custId:dateFrom:dateTo:dia`, então um dia
de diferença entre quem aquece e quem lê deixaria o cache eternamente frio, com o
aquecimento rodando verde e a tela no fallback para sempre.

**2. O modo cache-only** — `porEmpresa()` ganhou `$apiSomenteDoCache` (default
`false`). Liga em dois casos:

- **sempre** no mês corrente, qualquer chamador;
- por **pedido explícito** do chamador — é o que a tela faz, para as DUAS
  competências que calcula.

O segundo caso foi um **desvio necessário do plano** (detalhado abaixo): o plano
mandava passar `faturamentoDaApi` nas três chamadas, e o rollup do **mês anterior**
é competência FECHADA — sem o cache-only ele sairia chamando a Adman ao vivo
dezenas de vezes no meio do carregamento da tela, exatamente o que o `cache:clear`
de 30/07 provou que derruba a produção.

A leitura do cache é UM `getCachedGrossBillingsMany()` (um round-trip) antes do
laço, nunca um `Cache::get` por empresa.

### O fallback

| situação | faturamento | `faturamento_fonte` |
|---|---|---|
| cache quente | total do período da Adman | `api` |
| cache frio / sentinela de erro | soma diária de `adman_metrics` | `soma_diaria_fallback` |
| dia 1º do mês (não há o que pedir) | soma diária | `soma_diaria` |
| empresa que `podeUsarApiDaAdman()` recusa | soma diária | `soma_diaria` |
| chave desligada | soma diária | `soma_diaria` |

O dia 1º devolver `soma_diaria` e não `_fallback` é deliberado: a Adman não deixou
de responder, não havia o que perguntar. Marcar fallback ali faria a tela avisar de
um problema inexistente todo dia 1º.

O aviso de fallback saiu **agregado**: UM `Log::warning` por carregamento, com a
lista de nomes, em vez de 84 linhas por visita. Uma ou duas empresas ali é a Adman
tendo dado erro naquela conta; a lista inteira é sinal de que o aquecimento não
rodou hoje, e é isso que precisa aparecer de uma vez só.

`podeUsarApiDaAdman()` **não foi tocado**. Para o comando de aquecimento poder usar
o mesmo critério sem duplicá-lo, entrou o invólucro público
`podeLerFaturamentoDaAdman()`, que só delega.

### A tela

`faturamento_fonte` passou a viajar nos **cinco literais** de linha do
`AdminController`. Nas linhas de GRUPO fica `null` de propósito: o total do grupo
soma membros que podem ter procedências diferentes entre si, e rotular a linha
inteira com uma delas diria do número do grupo uma coisa que só vale para parte
dele. A procedência aparece empresa por empresa, ao abrir a linha — mesma
disciplina de `faturamento_ml_bruto`.

Componente novo `ProcedenciaFaturamentoNota`, discreto, no fim do cartão
"1 · Faturou no mês":

- `api` → "Total conferido com a Adman, já com os ajustes que ela fez nos dias passados."
- `soma_diaria_fallback` → "O total da Adman não chegou hoje — este é o nosso, somado dia a dia, e pode estar alguns por cento abaixo do que a Adman mostra."
- `soma_diaria` e `null` → **nada**. É o estado de quem não tem conta Adman
  conferível (selo em toda linha vira ruído) e, com a chave desligada, TODA linha é
  `soma_diaria` — mostrar algo ali seria mudar a tela com a chave desligada.

Sem jargão, com teste: a copy não pode conter "cache", "rollup", "api", "fonte",
"endpoint", "fallback" nem "aquec".

---

## T2 — O comando de aquecimento

**Nome:** `adman:warm-fechamento`
**Opções:** `--mes=YYYY-MM` (default: mês corrente), `--company=ID` (conferência manual)
**Agendamento:** `11:50` (`warm-fechamento-pos-sync`) e `16:00` (`warm-fechamento-tarde`), ambos `withoutOverlapping()`

11:50 porque 11:40 já é do `adman:warm-diff` e empilhar na mesma janela de 10 rpm
sem folga joga o pool para 429. 16:00 é a segunda passada, como o `warm-diff` já
faz em dois horários.

- população: empresas **ativas** que passam em `podeLerFaturamentoDaAdman()` — o
  mesmo critério do rollup, nunca uma segunda régua;
- janela: `janelaDaAdman()`, nunca calculada no comando;
- marketplace no default `'meli'`, igual ao do rollup (o marketplace entra na chave);
- espaçamento de **7 s** (`PAUSA_ENTRE_CHAMADAS`), o mesmo do fan-out do
  `adman:sync` (`ADMAN_RATE_LIMIT_RPM = 10` → 6 s teóricos + 1 s de folga). Pulado
  nos testes, com a constante travada por teste;
- erro numa empresa **não derruba a rodada**, e o resumo sai **com nomes**
  ("3 ficaram de fora" não é acionável);
- sai `SUCCESS` mesmo com empresas de fora: conta Adman problemática é problema de
  cadastro, não falha de execução;
- **não enfileira nada** — roda no processo do agendador. Lote não vai para a fila
  `high` (os 179 jobs de acervo que seguraram o webhook do Clicksign por horas em
  16/09).

**`forceRefresh` + reposição do valor anterior.** A rodada da tarde relê de
verdade; sem `forceRefresh` ela seria um no-op no cache-hit da manhã e não serviria
para nada. Mas `fetchGrossBilling()` grava a sentinela de erro quando falha, então
um 429 passageiro às 16h trocaria o número bom da manhã por fallback na tela. O
comando lê o valor antes de forçar e, se a releitura falhar, **repõe** — via
`AdmanService::guardarGrossBillingNoCache()`, método novo cujo único uso é este.
Dado de algumas horas atrás é melhor que nenhum.

---

## T3 — Como a releitura dos 5 dias foi implementada

**Comando novo `adman:reler-dias`**, e NÃO o segundo agendamento de
`adman:sync --from/--to` que o plano sugeriu. Motivo, achado no código:

> `--from`/`--to` do `adman:sync` só valem no ramo `--company=`. No fan-out para a
> base inteira as duas opções são **ignoradas** e o comando enfileira
> `SyncAdmanCompanyJob` sem data — ou seja, D-1 de novo. O agendamento sugerido
> rodaria todo dia sem reler nada.

**Opções:** `--dias=5` (default; teto de 31), `--company=ID` (síncrono, para conferência)
**Agendamento:** `19:00` (`adman-reler-ultimos-dias`), `withoutOverlapping()`

19:00, fora da cascata D-1 das 11h, de propósito: são ~420 chamadas a 7 s, ~49 min
— empilhar isso entre 11h e 13h competiria pelo mesmo limite de 10 rpm do
`adman:sync`, `ml:sync`, `adman:sync-margem`, `warm-diff` e `warm-fechamento`.

- relê de **D-1 para trás**, nunca HOJE (a Adman é D-1; o dia em curso viria pela
  metade e gravá-lo trocaria dado bom por dado incompleto);
- fan-out de `SyncAdmanCompanyJob` espaçado em **7 s**, fila **default** (não `high`);
- **mesma população do `adman:sync`**, incluindo a exclusão de quem tem token ML
  ativo — para essas empresas o `adman_metrics` é escrito pelo `ml:sync`, e reler
  da Adman recriaria a linha mista (revenue ML + margem Adman) que o cutover de
  01/06/2026 acabou;
- **não** recalcula nem apaga snapshot de desempenho; nenhum comando `desempenho:*`.

### `syncCampaigns` encarece a releitura? SIM — e por isso ficou de fora

Medido no código, não presumido. `syncCompany()` chama `syncCampaigns()`, que gasta:

- `fetchCampaigns()` → **1 chamada**
- `fetchCampaignMetrics()` → **1 chamada por campanha da conta**

Ou seja, um dia relido custaria `2 + N` chamadas em vez de 1. Com 5 campanhas por
empresa: 7 chamadas por dia, 5 dias × ~84 empresas = **~2.940 chamadas** em vez de
420 — fora do limite de 10 rpm por uma ordem de grandeza. E é desperdício puro: a
releitura conserta o **faturamento** de dias passados, não o histórico de campanhas.

Solução: `syncCompany($company, $date, bool $incluirCampanhas = true)` — **default
`true`, comportamento de sempre intocado**, com teste dos dois lados (o `adman:sync`
e o job sem terceiro argumento continuam sincronizando campanhas). Precedente do
mesmo tipo já existia: `syncCompanyMarginOnly()`.

`SyncAdmanCompanyJob` ganhou `$incluirCampanhas`. ⚠️ Declarada como **propriedade
com default**, não parâmetro promovido: um job já enfileirado antes do deploy foi
serializado sem essa chave, e o `unserialize` inicializa as propriedades com o
default da classe antes de aplicar o payload — promovida (sem default possível) ela
ficaria "não inicializada" e o job antigo estouraria ao ser processado.

---

## QUANTAS CHAMADAS POR DIA cada parte passa a custar

Base: **84 das 187 empresas ativas** podem ler o faturamento da Adman
(`podeUsarApiDaAdman`).

| parte | chamadas/dia | duração | observação |
|---|---|---|---|
| **tela do fechamento** (T1) | **0** | — | só cache, em qualquer competência, dentro do request |
| `adman:warm-fechamento` 11:50 | ~84 | ~10 min | 1 por empresa |
| `adman:warm-fechamento` 16:00 | ~84 | ~10 min | `forceRefresh` — releitura de verdade |
| `adman:reler-dias` 19:00 | **~420** | ~49 min | 5 dias × 84, 1 chamada por dia (sem campanhas) |
| **total acrescentado** | **~588** | ~69 min | distribuídas em 3 janelas que não se sobrepõem |
| `fechamento:consolidar-mes` | inalterado | — | mês fechado segue ao vivo, com a pausa dele |

Contrafactuais, para registro:
- se a releitura mantivesse `syncCampaigns`: ~2.940 chamadas em vez de 420 (com 5
  campanhas/empresa) — **7x mais**;
- se a tela chamasse ao vivo: 84 chamadas HTTP **por carregamento**, por usuário.

---

## Os dois gates, antes e depois

| gate | antes de editar | ao final | |
|---|---|---|---|
| Phase137/139/140/141/142/143 + Quick260915/260916/260922/260930 | **680 passed** (3094 assertions), exit **0** | **730 passed** (3226 assertions), exit **0** | +50 testes novos, zero regressão |
| Phase74 + Phase110 | **39 passed** (170 assertions), exit **0** | **39 passed** (170 assertions), exit **0** | inalterado |

Exit code capturado ANTES de qualquer pipe nas quatro rodadas.

Testes novos: 50, em quatro arquivos sob `tests/Feature/Quick260930/`.

---

## BUG encontrado e corrigido: `updateOrCreate` de `adman_metrics` não era idempotente

O plano pedia: *"`AdmanMetric::updateOrCreate` já é idempotente por
`(company_id, reference_date)` — releitura **atualiza**, não duplica. Confirmar com
teste."* Ao confirmar, **não era**:

```
2026-09-28: SQLSTATE[23000]: UNIQUE constraint failed:
  adman_metrics.company_id, adman_metrics.reference_date
```

`reference_date` é persistido como datetime (`'2026-09-28 00:00:00'`, cast `date` do
model), mas `updateOrCreate` monta o WHERE a partir do array de atributos **sem
aplicar cast** — com a string crua o SQL fica
`where reference_date = '2026-09-28'`, que não casa com o valor gravado. Em vez de
atualizar, tenta INSERIR e bate no unique.

Nunca apareceu porque o **MariaDB de produção é leniente** e coage a string curta
para datetime; o **SQLite dos testes compara como texto** e estoura — o inverso da
armadilha de sempre deste projeto. E ficou visível justamente ao exercitar a
releitura de um dia que já tem linha, que é **todo dia que ela precisa consertar**.

Correção (Rule 1): `Carbon::parse($date)->startOfDay()` no lugar da string, em
`AdmanService::syncCompany()`. `prepareBindings()` formata como `'Y-m-d H:i:s'` e o
WHERE casa nos dois bancos; para linha nova o valor gravado é idêntico ao de antes.

**Zero regressão, medida nos dois estados** (não presumida): com os arquivos
restaurados para HEAD e com eles aplicados, o filtro largo
`MercadoLivreSugadoresProviderTest|DevControllerTest|CompanyScoreServiceFonteTest|AnalyzeCompanyMlWindowQuarantineTest|Phase75NpsShopeeTest`
dá **`14 failed, 13 passed (155 assertions)` nas duas rodadas**.

`syncCompanyMarginOnly()` tem o **mesmo defeito** e NÃO foi corrigido — nada deste
quick passa por ele e a correção merece teste próprio do caminho de margem. Está em
`deferred-items.md`.

---

## Desvios do plano

1. **`apiSomenteDoCache` foi acrescentado ao `porEmpresa()`** (o plano só previa o
   gate por mês corrente). Sem isso, passar `faturamentoDaApi` na chamada do **mês
   anterior** — que o plano manda fazer, e com razão — faria a tela chamar a Adman
   ao vivo dezenas de vezes por carregamento, porque o mês anterior é competência
   fechada. O plano protegia o mês corrente e deixava o buraco no mês anterior.

2. **`adman:reler-dias` é comando novo, não um segundo agendamento de
   `adman:sync --from/--to`.** O plano preferia a segunda opção ("mais simples e
   menos invasiva"), mas `--from`/`--to` são ignorados no fan-out — o agendamento
   sugerido não releria nada.

3. **`AdminController::fechamentoDadosPorEmpresaAoVivo()` não mudou de assinatura.**
   A chave é lida dentro do método, via `FechamentoFonteFaturamento` injetado no
   construtor (mesmo padrão do `FechamentoRegraTabela` já ali). As três chamadas de
   `porEmpresa()` recebem o mesmo valor; os três call-sites do método ficaram
   intocados.

4. **`AdmanService::guardarGrossBillingNoCache()` é método novo**, não previsto: sem
   ele, a rodada da tarde do aquecimento degradaria a tela em vez de melhorá-la
   quando a Adman devolvesse 429.

5. **Correção do `updateOrCreate`** (acima), Rule 1.

6. **Um teste existente foi reescrito**, não apagado:
   `Quick260911\FaturamentoDaApiNoRollupTest::mes_corrente_ignora_a_api_mesmo_com_a_chave_ligada`
   virou `mes_corrente_nunca_chama_a_api_ao_vivo_e_cai_no_fallback_com_cache_frio`.
   Metade dele continua sendo a trava mais importante do quick (zero chamada HTTP);
   só o `faturamento_fonte` esperado mudou de `soma_diaria` para
   `soma_diaria_fallback`.

---

## Armadilhas encontradas que valem para o próximo (não dedutíveis do código)

**`Route` memoiza a instância do controller entre `$this->get()` do MESMO teste.**
Virar a chave `fechamento_faturamento_da_api_ativo` no meio de um teste **não tem
efeito nenhum**: a segunda visita reaproveita o mesmo `AdminController` e, com ele,
o mesmo `FechamentoFonteFaturamento`, que memoiza a leitura por instância.
`app(...)->esquecer()` não alcança a instância que a rota guardou (a classe não é
singleton). Em produção cada requisição é um processo próprio e o problema não
existe — é artefato de teste, e cair nele dá um **falso negativo convincente** ("a
chave não faz nada"). Solução: dois testes, um por estado da chave. Documentado no
teste.

**`Http::fake()` chamado duas vezes ACRESCENTA stubs em vez de substituir.** O
primeiro padrão continua casando, então "arrange a resposta A, roda, arrange a
resposta B, roda" mede a resposta A **duas vezes** e passa. Dois testes do T2
nasceram como falso positivo por isso. Solução: `Http::sequence()`.

---

## Arquivos

**Código**
- `app/Services/Fechamento/FechamentoRollupService.php` — `janelaDaAdman()`,
  `podeLerFaturamentoDaAdman()`, `$apiSomenteDoCache`, leitura em lote do cache,
  aviso agregado
- `app/Http/Controllers/AdminController.php` — `FechamentoFonteFaturamento` no
  construtor; `faturamentoDaApi` + `apiSomenteDoCache` nas três chamadas;
  `faturamento_fonte` nos cinco literais
- `app/Services/AdmanService.php` — `guardarGrossBillingNoCache()`;
  `$incluirCampanhas` em `syncCompany()`; correção do `updateOrCreate`
- `app/Jobs/SyncAdmanCompanyJob.php` — `$incluirCampanhas`
- `app/Console/Commands/WarmFechamentoFaturamento.php` — **novo**
- `app/Console/Commands/RelerDiasAdman.php` — **novo**
- `routes/console.php` — 3 agendamentos novos
- `resources/js/Pages/Admin/Financeiro.jsx` — `ProcedenciaFaturamentoNota`
  (`npm run build` rodado, exit 0)

**Testes** (todos novos, exceto os dois de Quick260911)
- `tests/Feature/Quick260930/FechamentoMesCorrenteComTotalDaAdmanTest.php` (15)
- `tests/Feature/Quick260930/ProcedenciaFaturamentoUiTest.php` (5)
- `tests/Feature/Quick260930/WarmFechamentoFaturamentoTest.php` (15)
- `tests/Feature/Quick260930/RelerUltimosDiasAdmanTest.php` (15)
- `tests/Feature/Quick260911/FaturamentoDaApiNoRollupTest.php` — 1 teste reescrito
- `tests/Feature/Quick260911/OutrosChamadoresNaoChamamApiTest.php` — só docblock

**Planejamento**
- `deferred-items.md` nesta pasta — 15 falhas pré-existentes + o defeito gêmeo em
  `syncCompanyMarginOnly()`

---

## Para o orquestrador ligar isso em produção

Ordem obrigatória — inverter deixa a tela no fallback e parece que a correção não
funcionou:

1. **Deploy.**
2. **Rodar `adman:warm-fechamento` à mão**, uma vez, e conferir o resumo (quantas
   aqueceram, quais ficaram de fora e por quê). A chave ainda desligada: aquecer
   cache não muda número nenhum na tela.
3. **Conferir empresa a empresa**, com a chave ainda desligada, comparando o total
   do cache contra o painel da Adman. O delta esperado é da ordem de +3,5% na média,
   com casos de -3%.
4. **Só então** `Configuracao::set('fechamento_faturamento_da_api_ativo', '1')`.
   Reverter é desligar a mesma chave — sem deploy.
5. `adman:reler-dias` pode rodar independente da chave: ele melhora
   `adman_metrics`, que alimenta carteira, desempenho e bônus. ⚠️ A primeira
   rodada vai mover números de carteira/desempenho do mês em curso. Competência
   de desempenho **já consolidada não muda** sozinha — só por
   `desempenho:consolidar-mes --mes=`, que este quick não roda.

⚠️ **Nunca `cache:clear`** para "forçar" o aquecimento: ele apaga o cache aquecido
da Adman inteiro, o dashboard passa a esperar a API e a produção cai (30/07/2026).
O aquecimento já sobrescreve o que precisa.
