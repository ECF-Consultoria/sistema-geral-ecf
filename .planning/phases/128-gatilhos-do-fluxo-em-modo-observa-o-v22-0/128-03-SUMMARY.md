---
phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
plan: 03
subsystem: api
tags: [laravel, contratos, clicksign, gate, orquestrador]

# Dependency graph
requires:
  - phase: 128-01
    provides: "Servico::exigeContrato() / scopeExigeContrato(), Polos isento na migration"
  - phase: 128-02
    provides: "PendenciasComerciaisService::calcularUniversais() — 4 pendências universais"
provides:
  - "GatilhoContratoAdministrativoService — orquestrador único do gate administrativo (avaliar/aguardandoComercial/dispararSeElegivel)"
  - "Skip de serviço isento dentro de ContratoClicksignService::iniciarParaEmpresa() (motivo 'servico_isento' em pulados)"
  - "Prova de fluxo do SC3 (pendência bloqueia, zero I/O) e SC0 (Polos nunca entra) em GatilhoContratoPendenciaTest"
affects: ["128-04", "128-05", "128-06"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Orquestrador único (como EmpresaOperacionalRouter) consultado por todos os pontos de entrada, nunca duplicado por controller"
    - "Estado derivado, não persistido (aguardando_comercial não grava em companies.status)"
    - "Guard estático de reentrância por request indexado por company_id, limpo em finally"
    - "try/catch(\\Throwable) envolvendo todo o corpo de um gate, nunca relança"

key-files:
  created:
    - app/Services/Contratos/GatilhoContratoAdministrativoService.php
    - tests/Feature/Phase128/GatilhoContratoPendenciaTest.php
  modified:
    - app/Services/Clicksign/ContratoClicksignService.php

key-decisions:
  - "Isenção de serviço checada em DOIS lugares (orquestrador E dentro do ContratoClicksignService), porque a Fase 131 vai expor um botão que chama o service da Clicksign direto, sem passar pelo orquestrador"
  - "aguardando_comercial é estado DERIVADO (não persistido em companies.status, que já tem semântica própria pendente/ativo)"
  - "Reentrância checada ANTES dos portões de propósito (D-04) — não confiar só na trava composta do banco da Fase 127"

patterns-established:
  - "Pattern: gate administrativo = isenção → reentrância → 1º portão de pendências → disparo (2º portão roda dentro do service chamado)"

requirements-completed: [REDE-06, FLUXO-08]

# Metrics
duration: 4min
completed: 2026-08-12
---

# Phase 128 Plano 03: Orquestrador único do gate administrativo Summary

**`GatilhoContratoAdministrativoService` decide isenção → reentrância → pendência comercial → disparo numa única sequência, com skip de serviço isento também dentro do `ContratoClicksignService` (Fase 131 chama este direto)**

## Performance

- **Duration:** 4 min (14:27–14:30 BRT)
- **Started:** 2026-08-12T14:27:00-03:00
- **Completed:** 2026-08-12T14:30:17-03:00
- **Tasks:** 3
- **Files modified:** 3 (1 modificado, 2 criados)

## Accomplishments
- `ContratoClicksignService::iniciarParaEmpresa()` agora pula, dentro do laço de `ContratoServico`, qualquer serviço com `exige_contrato = false` — registrado em `pulados` com `motivo = 'servico_isento'`, sem gerar `ContratoAssinatura` para ele.
- `GatilhoContratoAdministrativoService` criado como orquestrador único: `avaliar()` (puro, sem efeito colateral), `aguardandoComercial()` (deriva o SC3) e `dispararSeElegivel()` (o método que os dois controllers do plano 04 e o Observer do plano 05 vão chamar).
- Prova de fluxo: empresa 100% Polos nunca é avaliada nem chega a pendente (SC0); empresa com pendência comercial fica `aguardando_comercial` com zero chamada HTTP e zero job (SC3); contrato já em andamento não duplica; falha da Clicksign nunca escapa do gate.

## Task Commits

Each task was committed atomically:

1. **Task 1: Skip de serviço isento dentro do ContratoClicksignService** - `579a8cef` (feat)
2. **Task 2: GatilhoContratoAdministrativoService — o orquestrador único** - `6fe4345e` (feat)
3. **Task 3: Teste do gate — SC3 e SC0** - `4e6d6b28` (test)

**Plan metadata:** (este commit)

## Files Created/Modified
- `app/Services/Clicksign/ContratoClicksignService.php` - laço de `iniciarParaEmpresa()` pula serviço isento antes do guard de `emAndamentoDoServico`
- `app/Services/Contratos/GatilhoContratoAdministrativoService.php` - orquestrador único: `avaliar()`, `aguardandoComercial()`, `dispararSeElegivel()`
- `tests/Feature/Phase128/GatilhoContratoPendenciaTest.php` - 6 testes cobrindo SC0, SC3, reentrância e falha não-propagante

## Decisions Made
- Isenção checada nos DOIS níveis (orquestrador + laço do `ContratoClicksignService`), conforme exigido pelo plano — a Fase 131 vai chamar o service da Clicksign direto por um botão, sem passar pelo orquestrador.
- Estado `aguardando_comercial` é derivado em tempo de leitura (`avaliar()`), nunca gravado em `companies.status`.
- `dispararSeElegivel()` usa guard estático por `company_id` (não é lock distribuído) — suficiente para cortar o laço Observer → disparo → gravação → Observer dentro da MESMA execução PHP; não protege contra dois workers concorrentes (isso é papel da trava composta do banco, Fase 127).

## Deviations from Plan

None - plan executado exatamente como escrito. Task 3 exigiu adicionar `Queue::fake()` num dos testes (a suíte já usa `QUEUE_CONNECTION=sync` no `phpunit.xml`, então sem fake o `GerarContratoAssinaturaJob` rodava de verdade e falhava por falta de `clicksign_template_id`/config) — ajuste de fixture de teste, não deviation de comportamento do código de produção.

## Issues Encountered
Nenhum além do ajuste de fixture acima (mesmo padrão já usado em `tests/Feature/Phase127/ContratoClicksignServiceTest.php::setUp()`).

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- `GatilhoContratoAdministrativoService::dispararSeElegivel()` está pronto para ser injetado nos dois pontos de entrada (plano 04) e no Observer (plano 05) — nenhuma lógica de gate precisa ser duplicada ali.
- Baseline `tests/Feature/Phase127/` intacto (66 testes verdes).
- Grep confirma zero `$company->save()`/`->update()` dentro do orquestrador.

---
*Phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0*
*Completed: 2026-08-12*
