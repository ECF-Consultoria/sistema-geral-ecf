---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 08
subsystem: Bling ERP — importador do catálogo e Job de leitura em fatias
tags: [bling, importador, job, release, fila, publicador-ia, produtos]

requires:
  - phase: 184-02
    provides: "TradutorBlingParaPortal::paraLinhas() — função pura Bling → linhas do NormalizadorDeLinha"
  - phase: 184-03
    provides: "AtorDoPortal::sistema() e ProdutoCadastroService::gravarLinhas(..., agendarPreparo: false)"
  - phase: 184-06
    provides: "BlingClient::produtos()/produto() — os dois endpoints de leitura que o Job consome"
provides:
  - "LerProdutosDoBlingJob — leitura do catálogo do Bling em fatias de ~45s, continuação por release(), fila publicador-ia"
  - "ImportadorDoBling::previa() — classificação novos/atualizados/erros sem gravar nada"
  - "ImportadorDoBling::aplicar() — gravação em lotes de 200 com agendarPreparo:false e criação/atualização de bling_vinculos"
affects:
  - "184-09 (varredura de estoque): mesmo padrão de Job em fatias e mesma fila"
  - "184-10 (travas da variação vinculada): lê os vínculos que este plano cria"
  - "184-13 (checkpoint humano): confere a importação real na conta de teste do Bling"

tech-stack:
  added: []
  patterns:
    - "Job em fatias continua por release(), nunca por um novo despacho estático de dentro do próprio handle() — evita recursão no driver sync dos testes"
    - "previa()/aplicar() compartilham um plano() privado sem estado, no molde do ImportadorProdutos (plano/aplicar)"
    - "Classificação por 2 consultas whereIn fora do laço, nunca uma consulta por linha"
    - "Espião de método sem Mockery::partialMock() em classe com propriedades promovidas: subclasse anônima que delega a parent:: depois de contar"

key-files:
  created:
    - app/Jobs/LerProdutosDoBlingJob.php
    - app/Services/Bling/ImportadorDoBling.php
    - tests/Feature/Bling/LerProdutosDoBlingJobTest.php
    - tests/Feature/Bling/ImportadorDoBlingTest.php
  modified: []

decisions:
  - "retryUntil(): \\DateTimeInterface em vez do \\DateTime literal do plano — now() devolve Carbon\\CarbonInterface, incompatível com a classe concreta; alinhado com 4 dos outros 6 Jobs do projeto"
  - "limitePaginaOverride e janelaSegundosOverride como propriedades públicas (não config nem subclasse) — caminho mais simples para o teste reduzir a janela/o limite de página sem esperar 45s nem fabricar 100 produtos de fixture"
  - "previa() nunca distingue sem_mudanca de atualizados (sempre [] na prévia) — a comparação campo a campo já existe em ProdutoCadastroService::gravarLinhas; replicá-la na prévia seria uma segunda fonte da mesma regra sem nenhum teste do plano exigindo a distinção"

requirements-completed: [BLING-23, BLING-24, BLING-32]

metrics:
  duration: "~70 min"
  completed: 2026-10-13
---

# Phase 184 Plan 08: Importador do Bling e Job de leitura em fatias Summary

`LerProdutosDoBlingJob` lê o catálogo do Bling em fatias de ~45s continuando por `release()` (nunca
por um novo despacho de dentro do próprio `handle()`), na fila `publicador-ia`; `ImportadorDoBling`
classifica a rodada lida sem gravar nada (`previa()`) e grava em lotes de 200 pelo caminho único de
escrita com `agendarPreparo: false` (`aplicar()`), criando os vínculos e marcando órfãos sem apagar
nada no Portal.

## Performance

- **Duration:** ~70 min
- **Completed:** 2026-10-13
- **Tasks:** 3/3
- **Files modified:** 4 (todos criados)

## Accomplishments

- `LerProdutosDoBlingJob` lê `GET /produtos` paginado + `GET /produtos/{id}` por item, guardando o
  progresso em cache (`bling_leitura_{companyId}_{rodada}`) a cada volta do laço — uma fatia que
  morre no meio não perde o que já leu.
- A continuação é **sempre** por `$this->release()`; o `grep -c "self::dispatch"` do arquivo é **0**.
  Fila `publicador-ia` (`PreparoIaAgenda::fila()`), nunca `high` nem `default` — nenhuma das duas
  strings aparece entre aspas no arquivo.
- `retryUntil()` finito (45 min) e `tries = 1` (o `release()` é o mecanismo de continuação, não de
  retentativa de erro).
- 429/5xx registram o erro no estado da rodada e liberam (60s para `TOO_MANY_REQUESTS`, 15s nos
  demais) **sem** nova requisição na mesma execução — provado por contagem exata de chamadas HTTP.
- `ImportadorDoBling::previa()`/`plano()` nunca gravam nada (`assertDatabaseCount(..., 0)` para
  `estrutura_produtos`, `estrutura_produto_variacoes` e `bling_vinculos`) e deduplicam código
  repetido **entre produtos diferentes** do Bling no lote inteiro — o tradutor só vê um produto por
  vez e não pode fazer essa checagem.
- O vínculo casa pelo `bling_produto_id`, nunca pelo SKU: um produto renomeado no Bling continua
  classificado como `atualizados`, não `novos`.
- `ImportadorDoBling::aplicar()` refaz o plano do zero, grava em lotes de até 200 linhas pelo
  caminho único de escrita com `agendarPreparo: false` (fechando os dois caminhos até o agendamento
  do preparo pela IA), cria/atualiza `bling_vinculos` por `updateOrCreate` (idempotente — aplicar
  duas vezes na mesma rodada não duplica vínculo), junta `nao_entraram` com as recusas do plano e as
  falhas da gravação, e marca vínculos órfãos como `removido_no_bling` **sem apagar nada** no
  Portal — só quando a rodada concluiu.

## Task Commits

1. **Task 1: LerProdutosDoBlingJob — leitura em fatias por release()** — `0a3eaea7` (feat)
2. **Task 2: ImportadorDoBling::previa()** — `9ed913cb` (feat)
3. **Task 3: ImportadorDoBling::aplicar() e os vínculos** — `731b4f2c` (feat)

**Plan metadata:** (este commit — docs)

## Files Created/Modified

- `app/Jobs/LerProdutosDoBlingJob.php` (184 linhas) — Job em fatias
- `app/Services/Bling/ImportadorDoBling.php` (~370 linhas) — `previa()`, `aplicar()`, `plano()`
- `tests/Feature/Bling/LerProdutosDoBlingJobTest.php` (9 testes)
- `tests/Feature/Bling/ImportadorDoBlingTest.php` (20 testes: 9 de `previa()` + 11 de `aplicar()`)

## Decisions Made

Ver frontmatter `decisions`. Destaques:

- `retryUntil()` declarado com `\DateTimeInterface`, não o `\DateTime` literal do texto do plano —
  `now()` devolve `Carbon\CarbonInterface`, que não é compatível com a classe concreta `\DateTime`
  no PHP (o IDE sinalizou erro de tipo); a maioria dos outros Jobs do projeto
  (`BaixarPdfContratoAssinadoJob`, `ProcessarEventoClicksignJob`, `PublicarRascunhoJob`,
  `ExecutarLoteAlavancaJob`) já usa `\DateTimeInterface` — alinhado com a convenção majoritária, não
  inventado.
- `janelaSegundosOverride`/`limitePaginaOverride` como propriedades públicas simples, em vez de
  `config()` (mencionado como opção no plano) ou subclasse de teste — o teste só precisa de
  `$job->janelaSegundosOverride = 0;` antes de chamar `handle()` diretamente, sem mexer em
  `config()` global que poderia escapar para outro teste na mesma suíte.
- `previa()` nunca calcula `sem_mudanca` de verdade (sempre devolve `[]` nesse grupo) — nenhum dos 9
  comportamentos pedidos para a Task 2 exige diferenciar "já existe mas não mudou nada" de "já
  existe"; replicar a comparação campo a campo do `ProdutoCadastroService::gravarLinhas` na prévia
  criaria uma segunda fonte da mesma regra. Em `aplicar()`, os totais `novos`/`atualizados`/
  `sem_mudanca` vêm da contagem REAL que `gravarLinhas` fez na gravação — não de uma suposição da
  prévia (confirmado pelo Teste 4 do Task 3: aplicar duas vezes a mesma rodada classifica a 2ª
  passada como `sem_mudanca`, não `atualizados`, porque os valores gravados são idênticos).

## Deviations from Plan

### Auto-fixed Issues

Nenhuma por bug, funcionalidade crítica faltante, ou bloqueio (Regras 1-3) além do ajuste de tipo do
`retryUntil()` acima (Rule 1 — correção de tipagem).

### Esclarecimentos sobre a leitura literal de dois gates do plano (não são desvios de escopo)

**1. Teste 6 do Job (429) não fecha em exatamente 2 chamadas HTTP, como o texto do plano descrevia.**
O `<behavior>` dizia *"a contagem exata prova a ausência de retentativa"* e o `acceptance_criteria`
citava `Http::assertSentCount(2)` ("a 1ª página mais a 2ª que deu 429"). Na implementação real, uma
página que **não** é a última (porque tem `count(itens) === limite`, não menos) sempre precisa
buscar a **ficha** de cada item antes de a rotina seguir para a próxima página — não há como pular
isso sem violar o próprio `<action>` do plano (*"para cada item, chamar `produto($id)`"*, sem
condição). Com `limitePaginaOverride = 1` e 1 item na página 1, o mínimo possível de chamadas é
**3**: página 1 + ficha do único item + página 2 (que devolve 429). O teste final afirma
`Http::assertSentCount(3)`, com o comentário explicando a conta — a contagem **exata** continua
provando a ausência de retentativa (não há uma 4ª chamada repetindo a página 2), só que com o
número real do comportamento, não o da prosa do plano. Mesma classe do "gate vazio por construção"
dos learnings, só que aqui invertida: quem não fechava era o número do próprio plano, não o grep.

**2. O espião da Task 3 (Teste 7, lotes de 200) não usou `Mockery::partialMock()`/`$this->partialMock()`.**
Tentativa inicial: `$this->partialMock(ProdutoCadastroService::class, fn ($m) =>
$m->shouldReceive('gravarLinhas')->twice()->passthru())` — falhou com `Typed property
...::$ofertas must not be accessed before initialization`. O Mockery, ao criar um mock parcial de
uma classe CONCRETA sem passar argumentos de construtor, **pula** o construtor real por padrão, e
`ProdutoCadastroService` tem 5 dependências promovidas (`private ListasDaEmpresaService $listas`,
etc.) que ficam sem valor. Resolvido com uma subclasse anônima que recebe as 5 dependências reais
resolvidas pelo container, soma um contador em `gravarLinhas()` e delega a `parent::gravarLinhas()`
— ligada ao container via `$this->app->instance(ProdutoCadastroService::class, $espiao)` antes de
chamar `aplicar()`. O resultado observável (`$espiao->chamadas === 2` para 250 linhas / 200 por
lote) é o mesmo que o plano pedia; só o mecanismo de espionagem mudou.

## Gate dos acceptance_criteria

- `grep -c "self::dispatch" app/Jobs/LerProdutosDoBlingJob.php` → **0**
- `grep -c "\$this->release(" app/Jobs/LerProdutosDoBlingJob.php` → **2** (≥ 2 exigido)
- `grep -c "retryUntil" app/Jobs/LerProdutosDoBlingJob.php` → **1**
- `grep -cE "onQueue\(|\$queue *=" app/Jobs/LerProdutosDoBlingJob.php` → **1**
- `grep -cE "'high'|\"high\"|'default'|\"default\"" app/Jobs/LerProdutosDoBlingJob.php` → **0**
- `grep -c "function failed" app/Jobs/LerProdutosDoBlingJob.php` → **1**
- `Cache::put` dentro do laço de `handle()` — confirmado por leitura direta do código.
- `assertDatabaseCount(..., 0)` depois de `previa()` para `estrutura_produtos`,
  `estrutura_produto_variacoes` e `bling_vinculos` — confirmado no Teste 1 da Task 2.
- `grep -cE "\->(create|update|save|insert)\(" app/Services/Bling/ImportadorDoBling.php` → as 2
  únicas ocorrências (`$vinculo->save()`, `$conexao->save()`) estão **dentro de `aplicar()`**
  (linhas 220 e 228; `aplicar()` vai de 113 a 242) — **zero** ocorrências em `plano()` (258+) ou em
  `previa()`.
- `grep -c "chaveCodigo" app/Services/Bling/ImportadorDoBling.php` → **6**
- `grep -c "whereIn" app/Services/Bling/ImportadorDoBling.php` → **2**
- `grep -c "agendarPreparo: false"` → **2**; `grep -cE "PreparoIaAgenda|GerarPreparoIaJob|PrepararProdutoNoPublicadorJob"` → **0** (nem por importação de classe — o `PrepararProdutoNoPublicadorJob` só aparece com FQCN completo dentro do TESTE, nunca no arquivo de produção).
- `grep -c "updateOrCreate"` → **1**; `grep -cE "progresso\['concluida'\]|concluida"` → **6**.
- Teste 2 da Task 3 afirma `Queue::assertNothingPushed()`, com o par positivo
  (`test_par_positivo_o_queue_fake_captura_quando_ha_algo_a_capturar`) na MESMA suíte, chamando
  `gravarLinhas(..., agendarPreparo: true)` diretamente e afirmando `Queue::assertPushed(...)`.
- Teste 8 da Task 3 (órfão) afirma as **três** permanências: `assertDatabaseHas` em
  `estrutura_produto_variacoes`, `estrutura_produtos` e `estrutura_ofertas`.
- Teste 7 da Task 3 afirma **2** chamadas reais a `gravarLinhas` para 250 linhas (espião).

## Known Stubs

Nenhum. Este plano é só backend (Job + Service) — não há UI nem fluxo com dado vazio renderizado. As
rotas que vão expor isso na tela são do plano 10 (fora deste plano, conforme a seção `<output>`).

## Threat Flags

Nenhum novo. As superfícies deste plano (gravação em massa com `agendarPreparo: false`, Job em
fatias, vínculos órfãos) já estavam cobertas pelo `<threat_model>` do próprio `184-08-PLAN.md`
(T-184-47 a T-184-56, T-184-SC) — nenhum arquivo tocado introduz superfície fora dele.

## Issues Encountered

Nenhum bloqueio. As duas dificuldades (número do Teste 6 e o espião do Teste 7) estão documentadas
em "Deviations from Plan" acima, resolvidas antes do commit de cada task.

## User Setup Required

None — nenhuma configuração externa. As credenciais reais do Bling continuam para o checkpoint
humano do plano 13 (184-13-T1/T2).

## Next Phase Readiness

- `LerProdutosDoBlingJob::chaveEstado()` e o contrato do estado da rodada
  (`pagina/produtos/avisos/erro/concluida/lidos`) estão prontos para o plano 09 (varredura de
  estoque) reutilizar o mesmo padrão de Job em fatias, se precisar.
- `ImportadorDoBling::previa()`/`aplicar()` estão prontos para o plano 10 ganhar as rotas que os
  expõem na tela — nenhum ajuste de contrato esperado.
- Pendência explícita para o checkpoint humano (184-13): confirmar, na conta de teste, que a
  paginação e a leitura de fichas se comportam como medido aqui só com `Http::fake` (nenhum teste
  desta fase toca a API real do Bling).
- Nenhum bloqueio.

## Verificação dos gates do plano

- `C:\xampp\php\php.exe artisan test --filter=LerProdutosDoBlingJob`: **9 passed** (26 assertions),
  exit 0.
- `C:\xampp\php\php.exe artisan test --filter=ImportadorDoBling`: **20 passed** (60 assertions),
  exit 0.
- `C:\xampp\php\php.exe artisan test --filter=Bling` (suíte inteira da fase): **146 passed**, exit 0
  — 117 já existentes (planos 01-07) + 29 novos desta plano (9+20), **zero regressão**.
- `git diff --stat b2bf9a04..HEAD -- app/ tests/` (SHA de HEAD antes de qualquer commit deste
  plano): só `app/Jobs/LerProdutosDoBlingJob.php`, `app/Services/Bling/ImportadorDoBling.php` e os
  dois arquivos de teste deste plano aparecem como MEUS; `tests/js/publicador-publicacoes-grupos.test.js`
  (novo) e `tests/js/publicador-vocabulario-novo.test.js` (modificado) no mesmo range são **da outra
  sessão** (Fase 180, árvore compartilhada) — não tocados, não commitados por mim.
- `routes/mlb_anuncios.php` e `routes/web.php`: intocados por este plano (confirmado — nenhuma rota
  nova aqui; a `<output>` do plano reserva isso para o plano 10).

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-13*

## Self-Check: PASSED

Arquivos:
- FOUND: app/Jobs/LerProdutosDoBlingJob.php
- FOUND: app/Services/Bling/ImportadorDoBling.php
- FOUND: tests/Feature/Bling/LerProdutosDoBlingJobTest.php
- FOUND: tests/Feature/Bling/ImportadorDoBlingTest.php

Commits:
- FOUND: 0a3eaea7 (Task 1)
- FOUND: 9ed913cb (Task 2)
- FOUND: 731b4f2c (Task 3)

`artisan test --filter=Bling`: 146 passed (444 assertions), exit 0 — sem regressão nos 117 testes
dos planos 01-07.
