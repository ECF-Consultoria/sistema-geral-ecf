---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 09
subsystem: Bling ERP — varredura de estoque e comando agendado
tags: [bling, estoque, varredura, scheduler, jobs, portal]

requires:
  - phase: 184-03
    provides: "AtorDoPortal::sistema() e ProdutoCadastroService::gravarLinhas(..., agendarPreparo: false) — os dois caminhos até PreparoIaAgenda já fechados por parâmetro"
  - phase: 184-05
    provides: "SaldoDoBling::normalizar()/depositosParaIgnorar() — normalização pura do saldo (NULL != 0)"
  - phase: 184-06
    provides: "BlingClient::saldos()/depositos() e IDS_POR_CHAMADA_PADRAO (50)"
  - phase: 184-08
    provides: "BlingVinculo com situacao/variacao_id — o padrão de Job em fatias e o precedente de command isolado"
provides:
  - "ProdutoCadastroService::gravarEstoqueDoBling() — caminho dedicado que grava SÓ a coluna estoque, filtrado por company_id, sem agendar preparo pela IA"
  - "EstoqueDoBlingService::varrer() — a varredura por blocos de ids, gravando bloco a bloco, parando sem insistir num 429"
  - "Comando bling:estoque, agendado a cada 30 min em processo próprio"
affects:
  - "184-10 (estoque só-leitura e Ref travada): vai usar o estoque gravado aqui como sinal de 'vem do Bling'"
  - "184-11 (tela de configurações): o botão 'Atualizar agora' chama bling:estoque --empresa={id}"

tech-stack:
  added: []
  patterns:
    - "Método dedicado de gravação (fora de gravarLinhas) para um dado que o Bling SEMPRE escreve na variação vinculada — mesmo idioma do agendarPreparo:false do plano 03, mas como caminho próprio em vez de parâmetro, porque o plano 10 vai fazer a LINHA ignorar esse campo justamente nessas variações"
    - "Gravação BLOCO A BLOCO (não ao final da varredura): um 429 no meio não perde o que os blocos anteriores já leram"
    - "chunk() do Eloquent como mecanismo de parada: o closure devolve false para interromper a varredura sem uma 2ª flag de controle"

key-files:
  created:
    - app/Services/Bling/EstoqueDoBlingService.php
    - app/Console/Commands/BlingEstoque.php
  modified:
    - app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
    - routes/console.php
    - tests/Feature/Bling/EstoqueDoBlingTest.php

decisions:
  - "gravarEstoqueDoBling() nunca toca em PreparoIaAgenda, EstruturaOfertaService nem Job — provado por grep de arquivo inteiro no Service e por grep com janela de 40 linhas no método do cadastro"
  - "Comparação de 'sem_mudanca' é por identidade direta ($variacao->estoque === $estoque), não por (int) cast — null (nunca gravado) e 0 (gravado explicitamente) têm de ficar DIFERENTES, senão um (int)null=0 esconderia a gravação do zero"
  - "O comando, com --empresa, ignora o filtro de status/estoque_ativo da query e deixa o LOOP decidir 'pulada' — é o único jeito de uma conexão divergente/revogada/erro aparecer no resumo (sem --empresa ela nunca entra na query, e não há como 'pular' o que nunca foi buscado)"
  - "routes/console.php ganhou onOneServer() além dos dois modificadores do polos:warm — a cota de estoque é DO CLIENTE, e duas máquinas rodando juntas a dobrariam (motivo que o polos:warm não tinha)"

requirements-completed: [BLING-25, BLING-26]

metrics:
  duration: "~80min"
  completed: 2026-10-13
---

# Phase 184 Plan 09: Estoque do Bling — gravação, varredura e comando agendado Summary

Um método dedicado no `ProdutoCadastroService` que grava SÓ a coluna `estoque` (nunca agenda preparo
pela IA), um serviço que lê os saldos do Bling em blocos de 50 ids e grava bloco a bloco, e um
comando agendado a cada 30 minutos em processo próprio — provado por 32 testes novos, sem regressão
nos 146 já existentes.

## Performance

- **Duration:** ~80 min
- **Completed:** 2026-10-13
- **Tasks:** 3/3
- **Files modified:** 5 (2 criados, 3 modificados)

## Accomplishments

- `ProdutoCadastroService::gravarEstoqueDoBling(Company $empresa, array $estoquePorVariacao, AtorDoPortal $ator)`
  carrega as variações com `whereIn('id', ...)` **e** `where('company_id', $empresa->id)` — id de
  outra empresa no mapa é ignorado em silêncio. `null` nunca toca a linha (vira `ignoradas`); valor
  igual ao gravado vira `sem_mudanca`; diferente grava **só** a coluna `estoque`
  (`$variacao->update(['estoque' => $estoque])`, nunca `fill()` largo). Uma transação por lote, com
  uma linha de `activity_log` (`origem = 'bling'`, sem causer, evento `estoque_do_bling`).
- `EstoqueDoBlingService::varrer(BlingConexao $conexao)` lê `/depositos` uma única vez, monta blocos
  de `BlingClient::IDS_POR_CHAMADA_PADRAO` (50) só com `BlingVinculo` em `situacao = 'vinculado'`, e
  por bloco: pede `/estoques/saldos`, normaliza cada saldo (`SaldoDoBling::normalizar`) e grava
  imediatamente pelo método dedicado — nunca ao final da varredura inteira. Um 429 (ou qualquer
  `RuntimeException` do cliente) interrompe a busca de blocos seguintes (via `return false` dentro
  do `chunk()`), mas o que os blocos anteriores já gravaram permanece. `ultimo_estoque_em` só avança
  quando a varredura passou por todos os blocos sem erro; conexão inativa ou com `estoque_ativo = false`
  faz zero requisições, nem o depósito é pedido.
- Comando `bling:estoque` varre todas as conexões `ativa` + `estoque_ativo` (ordenadas pela mais
  antiga sem varredura primeiro), ou só uma com `--empresa={id}` — o caminho que o botão "Atualizar
  agora" do plano 11 vai usar, e que também permite uma conexão divergente/revogada/erro aparecer
  "pulada" no resumo. Uma empresa que falha não interrompe as demais. `--dry-run` lista as conexões e
  o número de vínculos sem nenhuma requisição. Agendado em `routes/console.php` a cada 30 minutos em
  processo próprio, com `withoutOverlapping(25)`, `onOneServer()` e `runInBackground()`.

## Task Commits

1. **Task 1: ProdutoCadastroService::gravarEstoqueDoBling** — `7c18c762` (feat)
2. **Task 2: EstoqueDoBlingService — varredura por blocos de ids** — `549882e3` (feat)
3. **Task 3: Comando bling:estoque e o agendamento** — `4558d26a` (feat)

## Correção do orquestrador aplicada (Teste 2b)

O plano original tinha o Teste 2 (`Queue::assertNothingPushed()`) sem par positivo. Adicionado
`test_par_positivo_o_queue_fake_captura_quando_ha_algo_a_capturar` no **mesmo arquivo**
`tests/Feature/Bling/EstoqueDoBlingTest.php` (roda no filtro `--filter=EstoqueDoBling`): despacha
`LerProdutosDoBlingJob::dispatch(999999, 'par-positivo-184-09')` — um Job sem nenhuma relação com
`gravarEstoqueDoBling` — e afirma `Queue::assertPushed(LerProdutosDoBlingJob::class)`. **O par
positivo passou**, confirmando que o `Queue::fake()` desta suíte captura de verdade quando há algo a
capturar; o Teste 2 (asserção negativa) vale.

## Files Created/Modified

- `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php` — método novo
  `gravarEstoqueDoBling()` (só acréscimo; `git diff` confirma zero linhas removidas)
- `app/Services/Bling/EstoqueDoBlingService.php` (novo, ~150 linhas) — a varredura por blocos
- `app/Console/Commands/BlingEstoque.php` (novo) — o comando `bling:estoque`
- `routes/console.php` — entrada `Schedule::command('bling:estoque')` (só acréscimo)
- `tests/Feature/Bling/EstoqueDoBlingTest.php` (novo) — os 32 testes das 3 tasks (8 + 10 + 14,
  contando o par positivo/negativo da Task 2)

## Decisions Made

Ver frontmatter `decisions`. Destaque: a comparação de "sem mudança" em `gravarEstoqueDoBling` usa
`$variacao->estoque === $estoque` (identidade direta do PHP, sem `(int)` cast) — `null` (nunca
gravado) e `0` (gravado explicitamente) são tipos diferentes em PHP e precisam permanecer distintos;
um cast para `int` faria `(int) null === (int) 0` ser verdadeiro, escondendo a gravação do zero
atrás de um "sem mudança" falso.

## Deviations from Plan

### Auto-fixed Issues

Nenhuma por bug, funcionalidade crítica faltante ou bloqueio (Regras 1-3) no código de produção.

### Armadilha de teste encontrada e corrigida ANTES do commit (não é desvio de produção)

**`Http::fake($closure)` acumula closures via `merge()`, nunca substitui.** Ao escrever os testes da
Task 2/3 que precisavam de duas respostas diferentes do Bling na mesma varredura (ex.: "a 1ª
varredura grava 8, a 2ª o Bling não devolve o produto"), a primeira tentativa chamou o helper de fake
duas vezes no mesmo método de teste. Isso NÃO reseta: `Illuminate\Http\Client\Factory::fake()`
sempre `merge()` o novo closure na pilha existente — e, pior, `Http::fake()` **sem argumento** também
não é um reset: ele empilha um closure genérico que responde 200 vazio a QUALQUER requisição, que
"ganha" de todos os outros (resolução por ordem de registro, primeiro-registrado-primeiro-tentado).
Confirmado lendo `vendor/laravel/framework/.../Http/Client/Factory.php::fake()` — o ramo
`is_null($callback)` só troca o valor de `$callback`, cai no mesmo `merge()` final dos dois ramos.

Dois testes foram reescritos para nunca chamar `Http::fake()` mais de uma vez no mesmo método:
- `test_produto_ausente_da_resposta_nao_zera_o_estoque_anterior` passou a usar um ÚNICO
  `Http::fake()` cujo closure decide a resposta pelo NÚMERO da chamada (contador capturado por
  referência) — a 1ª chamada devolve o saldo, a 2ª devolve ausência.
- O teste antigo `ultimo_estoque_em_so_atualiza_sem_erro_em_nenhum_bloco` (duas conexões, dois fakes)
  foi DIVIDIDO em dois métodos independentes
  (`test_ultimo_estoque_em_atualiza_quando_nao_ha_erro_em_nenhum_bloco` e
  `test_ultimo_estoque_em_nao_atualiza_quando_um_bloco_deu_erro`), cada um com seu próprio
  `Http::fake()` único.

Nenhuma mudança no código de produção por causa disto — documentado aqui, e replicado no bloco
aditivo do STATE.md, porque é uma armadilha nova (não estava nos learnings do projeto) que qualquer
outra sessão escrevendo teste com `Http::fake()` duas vezes no mesmo método vai reproduzir.

## Known Stubs

Nenhum. Este plano é só backend (método de serviço + Service + Command) — não há UI nem fluxo com
dado vazio renderizado.

## Threat Flags

Nenhum novo. As superfícies deste plano (gravação automática de estoque, varredura agendada
consumindo a cota do cliente, comando em processo próprio) já estavam cobertas pelo `<threat_model>`
do próprio `184-09-PLAN.md` (T-184-57 a T-184-65, T-184-SC) — nenhum arquivo tocado introduz
superfície fora dele.

## Gate dos acceptance_criteria

- `artisan test --filter=EstoqueDoBling`: **32 passed**, exit 0 (8 Task 1 + 10 Task 2 + 14 Task 3 —
  os números de teste das Tasks 2 e 3 vieram maiores que os `<behavior>` literais porque alguns
  comportamentos únicos do plano foram cobertos por um par positivo/negativo em vez de um teste só).
- `artisan test --filter=Bling` (suíte inteira da fase): **170 passed** (146 anteriores + 24 novos
  nas Tasks 2+3; a Task 1 não aparece nesse delta porque ela soma dentro do mesmo arquivo de teste
  que a Task 2 reaproveita — a contagem exata por task está nos commits), exit 0, **zero regressão**.
- `tests/Feature/PortalCliente`: **757 passed**, mesmo baseline conferido pelo orquestrador antes
  desta execução (medido depois do commit da Task 1, que foi o único a tocar
  `ProdutoCadastroService.php`).
- `grep -n -A 40 "function gravarEstoqueDoBling" app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php | grep -cE "PreparoIaAgenda|dispatch\(|ofertas->"` → **0**.
- `grep -n -A 40 "function gravarEstoqueDoBling" ... | grep -c "company_id"` → **1** (≥ 1 exigido).
- `grep -cE "gravarLinhas|EstruturaOfertaService|PreparoIaAgenda|dispatch\(" app/Services/Bling/EstoqueDoBlingService.php` → **0** (arquivo inteiro, não só o método).
- `grep -c "AtorDoPortal::sistema" app/Services/Bling/EstoqueDoBlingService.php` → **1**; `grep -c "situacao"` → **1** (ambos ≥ 1 exigido).
- `grep -n -A 8 "Schedule::command('bling:estoque')" routes/console.php | grep -cE "withoutOverlapping|onOneServer|runInBackground"` → **3**; `grep -c "withoutOverlapping(25)"` → **1**.
- `artisan schedule:list | grep bling` → as duas entradas (`bling-renovar-tokens` do plano 04,
  `bling-estoque` deste plano), ambas com "Next Due".
- `git diff 2caf3b4b..HEAD -- routes/console.php | grep -c '^-[^-]'` → **0** (só acréscimo; `2caf3b4b`
  é o HEAD antes de qualquer commit deste plano).
- `git diff 2caf3b4b..HEAD -- app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php | grep -c '^-[^-]'` → **0**.

## Issues Encountered

Nenhum bloqueio no código de produção. A única dificuldade foi de teste (`Http::fake()` acumulando
closures), documentada acima em "Deviations from Plan" e resolvida antes do commit de cada task
afetada. A árvore é compartilhada com outra sessão (Fase 180): um commit dela
(`a68be042 feat(180-10)`) aterrissou entre os commits das Tasks 1 e 2 deste plano — confirmado
inofensivo por `git log --oneline` e por `git status` mostrando só os arquivos deste plano staged em
cada commit meu.

## User Setup Required

None — nenhuma configuração externa. As credenciais reais do Bling continuam para o checkpoint
humano do plano 13.

## Next Phase Readiness

- `ProdutoCadastroService::gravarEstoqueDoBling()` e `EstoqueDoBlingService::varrer()` estão prontos
  para o plano 10 (estoque só-leitura e Ref travada na variação vinculada) consumir — nenhum ajuste
  de contrato esperado.
- O comando `bling:estoque --empresa={id}` está pronto para o plano 11 (tela de configurações)
  disparar como o botão "Atualizar agora".
- Nenhum bloqueio.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-13*

## Self-Check: PASSED

Arquivos:
- FOUND: app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
- FOUND: app/Services/Bling/EstoqueDoBlingService.php
- FOUND: app/Console/Commands/BlingEstoque.php
- FOUND: routes/console.php
- FOUND: tests/Feature/Bling/EstoqueDoBlingTest.php

Commits:
- FOUND: 7c18c762 (Task 1)
- FOUND: 549882e3 (Task 2)
- FOUND: 4558d26a (Task 3)

`artisan test --filter=Bling`: 170 passed (146 anteriores + 24 novos), exit 0.
`tests/Feature/PortalCliente`: 757 passed, mesmo baseline.
