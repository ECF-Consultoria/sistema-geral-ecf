---
phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
plan: 05
subsystem: api
tags: [laravel, contratos, clicksign, observers, gate, eloquent-events]

# Dependency graph
requires:
  - phase: 128-03
    provides: "GatilhoContratoAdministrativoService::dispararSeElegivel() — orquestrador único"
  - phase: 128-04
    provides: "Chamada explícita do gate nos dois controllers, fora da DB::transaction()"
provides:
  - "CompanyGatilhoContratoObserver — reavalia o gate quando email_cliente/cnpj/nome_contato mudam (updated())"
  - "ContratoServicoGatilhoObserver — reavalia o gate ao criar/editar ContratoServico (created()/updated()), via DB::afterCommit() dentro de transação"
  - "D-04 fechada: empresa aguardando_comercial sai sozinha do estado quando a pendência some, sem botão manual"
affects: ["128-06", "131"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Observer com wasChanged() restrito a uma lista fixa de campos (CAMPOS_GATILHO), documentando explicitamente o que fica de FORA e por quê"
    - "DB::afterCommit() dentro do created() de um model criado em massa dentro de DB::transaction() — só reavalia depois que TODOS os registros do lote existem"
    - "Spy que estende a classe real e delega 100% do comportamento (nunca Mockery) para contar invocações sem alterar resultado"
    - "withoutEvents() no setup de testes que testam um service de camada inferior isoladamente, quando um Observer novo passaria a interferir no fixture"

key-files:
  created:
    - app/Observers/CompanyGatilhoContratoObserver.php
    - app/Observers/ContratoServicoGatilhoObserver.php
    - tests/Feature/Phase128/ReavaliacaoAutomaticaTest.php
  modified:
    - app/Models/Company.php
    - app/Models/ContratoServico.php
    - tests/Feature/Phase128/GatilhoContratoPendenciaTest.php
    - tests/Feature/Phase127/ContratoClicksignServiceTest.php
    - tests/Feature/Phase127/IdempotenciaContratoTest.php

key-decisions:
  - "Company::updated() só reavalia se wasChanged(['email_cliente','cnpj','nome_contato']) — hubspot_notas/hubspot_snapshot ficam de fora de propósito para que replay de webhook não reavalie"
  - "Company::created() NÃO tem hook — no created() a empresa ainda não tem ContratoServico (nasce depois, na mesma transação); o caminho de criação já é coberto pela chamada explícita dos controllers (plano 04)"
  - "ContratoServicoGatilhoObserver::created() roda dentro de DB::afterCommit() — verificado empiricamente que o callback dispara quando a transação onde o create() aconteceu fecha (mesmo sendo uma transação aninhada dentro do wrapper de teste do RefreshDatabase), não apenas no commit real de nível 0; isso é o que permite ao ComercialController::store() criar N ContratoServico dentro de UMA DB::transaction() e o Observer reavaliar só UMA vez, com o lote completo já visível"
  - "Testes de Phase127/128-03 que criam ContratoServico::create() fora de uma DB::transaction() (helpers de fixture, não fluxo real de controller) passaram a usar withoutEvents() — o Observer novo faria disparo automático como efeito colateral do SETUP, quebrando a determinística/isolamento que aquelas suítes pré-existentes assumem ao testar GatilhoContratoAdministrativoService e ContratoClicksignService isoladamente"

patterns-established:
  - "Pattern: reavaliação automática = Observer com wasChanged() restrito + DB::afterCommit() quando o create() pode acontecer em lote dentro de transação + guard de reentrância do orquestrador (plano 03) + trava composta do banco (Fase 127) — 4 camadas redundantes de propósito"

requirements-completed: [REDE-06]

# Metrics
duration: ~35min
completed: 2026-08-12
---

# Phase 128 Plano 05: Reavaliação automática do gate administrativo (D-04) Summary

**Dois Observers (`CompanyGatilhoContratoObserver` + `ContratoServicoGatilhoObserver`) fecham a D-04 — empresa `aguardando_comercial` sai sozinha do estado quando o Comercial corrige o dado que faltava, sem nenhum botão manual, com laço descartado por contagem de invocações (spy real, não mock)**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-12 (sessão contínua após 128-04)
- **Completed:** 2026-08-12
- **Tasks:** 3
- **Files modified:** 8 (2 criados na produção, 1 criado em teste, 5 modificados — 2 model, 3 arquivos de teste pré-existentes de Phase127/128-03)

## Accomplishments

- `CompanyGatilhoContratoObserver::updated()` reavalia o gate quando `email_cliente`, `cnpj` ou `nome_contato` mudam — lista fixa (`CAMPOS_GATILHO`) documentada com o motivo de `hubspot_notas`/`hubspot_snapshot` ficarem de fora (replay de webhook não deve reavaliar).
- `ContratoServicoGatilhoObserver` cobre o gancho que `Company` sozinha não cobre: vincular um serviço novo ou corrigir `valor_contratado`/`data_contratacao`/`servico_id`. `created()` roda via `DB::afterCommit()` — verificado empiricamente (não só por leitura de documentação) que isso permite ao cadastro em lote do Comercial (N `ContratoServico` dentro de UMA `DB::transaction()`) reavaliar só depois que TODOS os registros existem, evitando processar o gate com o lote parcial.
- Registro via `#[ObservedBy]` nos dois models (`Company`, `ContratoServico`), mesmo padrão já usado por `MlbEmpresaObserver`.
- `ReavaliacaoAutomaticaTest` (6 cenários, TDD): completar `nome_contato` dispara sem chamada manual; editar `hubspot_notas`/`hubspot_snapshot` não chama o gate (spy real registrando zero invocações); contrato já em andamento não duplica ao editar `email_cliente` de novo; adicionar serviço novo a uma empresa já completa (que só tinha Polos) gera contrato só para o serviço novo; empresa só-Polos nunca gera contrato mesmo editando campos-gatilho; fluxo HTTP real de `ComercialController::store()` mede exatamente 2 invocações do gate (Observer via afterCommit + chamada explícita do controller), nunca crescente.
- Verificação estrutural por grep: nenhum arquivo do fluxo de contrato (`GatilhoContratoAdministrativoService`, `ContratoClicksignService`, `GerarContratoAssinaturaJob`, os dois Observers novos) grava em `Company` — a camada anti-laço estrutural que o plano exige.

## Task Commits

Each task was committed atomically:

1. **Task 1: CompanyGatilhoContratoObserver com lista fixa de campos** - `1ff79dd1` (feat)
2. **Task 2: ContratoServicoGatilhoObserver — o gancho que Company sozinha não cobre** - `5c2fa102` (feat, inclui fix de teste pré-existente 128-03)
3. **Task 3: Teste da reavaliação automática e da ausência de laço** - `e1198ea6` (test, inclui fix de 2 testes pré-existentes de Phase127)

**Plan metadata:** (este commit)

## Files Created/Modified

- `app/Observers/CompanyGatilhoContratoObserver.php` - `updated()` restrito a `email_cliente`/`cnpj`/`nome_contato` (`wasChanged`)
- `app/Observers/ContratoServicoGatilhoObserver.php` - `created()` via `DB::afterCommit()`; `updated()` restrito a `ativo`/`valor_contratado`/`data_contratacao`/`servico_id`
- `app/Models/Company.php` - `#[ObservedBy(CompanyGatilhoContratoObserver::class)]`
- `app/Models/ContratoServico.php` - `#[ObservedBy(ContratoServicoGatilhoObserver::class)]`
- `tests/Feature/Phase128/ReavaliacaoAutomaticaTest.php` - 6 cenários + `SpyGatilhoContratoAdministrativoService` (delega 100%, só conta invocações)
- `tests/Feature/Phase128/GatilhoContratoPendenciaTest.php` (128-03) - `contratoServicoAtivo()` passa a usar `withoutEvents()` — a suíte testa o service isoladamente
- `tests/Feature/Phase127/ContratoClicksignServiceTest.php` - mesmo fix (`withoutEvents()`)
- `tests/Feature/Phase127/IdempotenciaContratoTest.php` - mesmo fix (`withoutEvents()`)

## Decisions Made

- `Company::created()` sem hook: no momento em que a empresa nasce, ela ainda não tem `ContratoServico` (criados depois, na mesma transação) — o gate sempre devolveria `sem_servico`, ruído puro. O caminho de criação já é coberto pela chamada explícita dos controllers (plano 04).
- `DB::afterCommit()` em vez de chamada síncrona no `created()` de `ContratoServico`: sem isso, o cadastro do Comercial (que cria N `ContratoServico` dentro de UMA `DB::transaction()`) reavaliaria o gate a CADA `create()` individual, vendo o lote incompleto — exatamente o Pitfall 1 que o plano avisa. Comportamento verificado empiricamente com um teste-sonda antes da implementação (não assumido só pela documentação do Laravel): o callback dispara quando a transação-alvo (a mesma em que o `create()` rodou) fecha, mesmo estando aninhada dentro do wrapper de transação que o `RefreshDatabase` abre para cada teste.
- Guard de reentrância (`emAndamentoDaEmpresa()`, plano 03) é EMPRESA-level, não por serviço — por isso o cenário de teste "serviço novo numa empresa já completa" foi desenhado com o primeiro serviço sendo Polos (isento, nunca cria `ContratoAssinatura`), evitando a ambiguidade de testar dois serviços que exigem contrato ao mesmo tempo enquanto o primeiro contrato ainda está "em andamento".

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `GatilhoContratoPendenciaTest` (128-03) quebrou com o novo `ContratoServicoGatilhoObserver`**
- **Found during:** Task 2, ao rodar a suíte completa `--filter=Phase128`
- **Issue:** O teste `empresa_com_gestao_e_polos_dispara_para_gestao_e_pula_polos` cria dois `ContratoServico` em sequência (fora de `DB::transaction()`, diferente do fluxo real dos controllers) e depois chama `dispararSeElegivel()` explicitamente esperando ser o PRIMEIRO disparo. Com o Observer novo, o primeiro `create()` já reavalia e cria o `ContratoAssinatura` sozinho — a chamada explícita do teste passou a ver `ja_em_andamento` em vez de `disparado`.
- **Fix:** `contratoServicoAtivo()` passou a envolver o `create()` em `ContratoServico::withoutEvents()` — a suíte testa `GatilhoContratoAdministrativoService` isoladamente e de forma determinística; o disparo automático via Observer já tem prova própria (`ReavaliacaoAutomaticaTest`).
- **Files modified:** `tests/Feature/Phase128/GatilhoContratoPendenciaTest.php`
- **Commit:** `5c2fa102`

**2. [Rule 1 - Bug] Dois testes de `Phase127` (`ContratoClicksignServiceTest`, `IdempotenciaContratoTest`) quebraram pelo mesmo motivo**
- **Found during:** Task 3, ao rodar `--filter=Phase127` como parte da verificação do plano
- **Issue:** Ambas as suítes testam `ContratoClicksignService` DIRETO (instanciado à mão, fora do container), com `signatarios_ecf` configurado no `setUp()`. O `contratoServicoAtivo()` de cada uma cria `ContratoServico::create()` como fixture — com o Observer novo, esse `create()` sozinho já dispara o gate real (via `app(GatilhoContratoAdministrativoService::class)`), criando `ContratoAssinatura` como efeito colateral do setup ANTES da chamada explícita que cada teste está medindo. Um dos testes chegou a estourar a constraint composta `(company_id_em_andamento, servico_id_em_andamento)` (violação de unicidade real).
- **Fix:** Mesmo padrão — `contratoServicoAtivo()` das duas suítes passou a usar `ContratoServico::withoutEvents()`.
- **Files modified:** `tests/Feature/Phase127/ContratoClicksignServiceTest.php`, `tests/Feature/Phase127/IdempotenciaContratoTest.php`
- **Commit:** `e1198ea6`

---

**Total deviations:** 2 auto-fixed (ambas Rule 1 — testes pré-existentes quebrados por um efeito colateral novo e correto, não bug de produção)
**Impact on plan:** Nenhum código de produção foi ajustado por causa destas quebras — os dois Observers funcionam exatamente como o plano descreveu. O ajuste foi 100% nos fixtures de teste que criavam `ContratoServico` fora do fluxo real (sem `DB::transaction()`) e passaram a sofrer o novo efeito colateral automático. Escopo contido dentro da própria Fase 128 (127 e 128-03 são planos anteriores da MESMA fase/milestone).

## Issues Encountered

Investigação de comportamento do `DB::afterCommit()` sob `RefreshDatabase` consumiu a maior parte do tempo desta task: a suposição inicial (callback só dispara no commit REAL de nível 0, nunca durante um teste que usa `RefreshDatabase`) mostrou-se incorreta na prática — foi necessário um teste-sonda isolado e leitura do código-fonte do `DatabaseTransactionsManager` para confirmar o comportamento real antes de decidir o design do Observer e dos testes.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- D-04 fechada: a partir de agora, `aguardando_comercial` nunca mais é um estado permanente — a correção do dado (pelo Comercial ou pelo replay do HubSpot, se o dado corrigido for um dos 3 campos-gatilho) reavalia sozinha.
- As 4 camadas de proteção contra laço estão todas provadas por teste de contagem (não por argumento): `wasChanged()` restrito, `DB::afterCommit()`, guard estático do orquestrador (plano 03), trava composta do banco (Fase 127).
- Baseline `Phase124` (16 testes) + `Phase127` (66 testes) + `Phase128` (33 testes) = 115 testes verdes, zero regressão líquida (os 2 arquivos de teste ajustados continuam cobrindo exatamente o mesmo comportamento de antes, só isolados do novo efeito colateral).
- Pronto para o plano 06 (gate humano contra o sandbox Clicksign real) — nenhuma chamada real foi feita nesta plano, tudo via `Http::fake()`/`Queue::fake()`.

---
*Phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0*
*Completed: 2026-08-12*
