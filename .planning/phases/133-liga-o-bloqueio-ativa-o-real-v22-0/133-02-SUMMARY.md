---
phase: 133-liga-o-bloqueio-ativa-o-real-v22-0
plan: 02
subsystem: operacional
tags: [laravel, kill-switch, fluxo-09, contrato, fail-safe, divida-tecnica]

# Dependency graph
requires:
  - phase: 133-01
    provides: "A exceção por serviço já provada em EmpresaOperacionalRouter::rotear() — a MESMA lógica (Servico::exigeContrato() + servicoDisparaImplementacao()) é reaproveitada aqui, na segunda cópia da regra"
  - phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
    provides: "servicos.exige_contrato + Servico::exigeContrato() — o dado consultado pela nova checagem"
provides:
  - "Checagem do interruptor administrativo_bloqueio_ativo dentro de MlbController::ativarEmpresaPendente() — a porta dos fundos do FLUXO-09 fechada"
  - "Helper privado servicoContratadoIsentoParaTipo() — decide pelo serviço realmente contratado (D-07), nunca pelo rótulo do formulário"
  - "Primeira cobertura de teste de ativarEmpresaPendente() (0 → 8 cenários)"
  - "Dívida D-06 registrada e versionada: as duas rotas que criam MlbEmpresa fora do router"
affects: [133-03, 133-04, 133-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Checagem cirúrgica dentro do controller (D-03) em vez de refatorar para usar o router — regra duplicada deliberadamente, mitigada por teste nos dois caminhos"
    - "Decisão por dado contratado (contratosServico), nunca por rótulo de formulário (D-07) — mesmo princípio de Servico::exigeContrato() ('quem decide é o dado, não o nome')"
    - "back()->with('error', ...) para recusa, seguindo o precedente de ContratoAdminController::gerarContrato() (Fase 132) — não abort(422), para caber na faixa de flash.error já existente na tela"

key-files:
  created:
    - tests/Feature/Phase133/AtivarEmpresaPendenteBloqueioTest.php
    - .planning/todos/pending/260818-portas-extras-criam-mlbempresa-fora-do-router.md
  modified:
    - app/Http/Controllers/MlbController.php

key-decisions:
  - "D-03/D-07 (herdadas do CONTEXT): checagem dentro do próprio ativarEmpresaPendente(), decidindo pelos serviços contratados (Company::contratosServico), não pelo $validated['tipo']."
  - "Formato de recusa: back()->with('error', ...), não abort(422) — decisão explícita do plano (Claude's Discretion do CONTEXT), motivada pela faixa de flash.error que Empresas.jsx já renderiza."
  - "D-06: as duas rotas extras (MlbImplementacaoController::criar(), MlbController::storeEmpresa()) ficam FORA de escopo — registradas como dívida versionada, não fechadas nesta fase."

requirements-completed: [FLUXO-09]

# Metrics
duration: ~40min
completed: 2026-08-18
---

# Phase 133 Plano 02: Fecha a porta dos fundos do FLUXO-09 (D-03/D-07) Summary

**`ativarEmpresaPendente()` (botão "Ativar" de `/mlb/empresas`) ganhou uma segunda cópia da checagem do interruptor administrativo, decidindo pelo serviço REALMENTE contratado pela empresa — não pelo rótulo escolhido a mão no formulário — e recusando com mensagem sem jargão quando o contrato ainda não está assinado.**

## Performance

- **Duration:** ~40 min
- **Tasks:** 3/3 completed
- **Files modified:** 3 (2 criados, 1 modificado)

## Accomplishments
- `MlbController::ativarEmpresaPendente()` — método que hoje tinha **zero** cobertura de teste e ignorava completamente o interruptor — agora recusa a ativação manual quando o serviço contratado exige contrato e a chave está ligada, com `Log::warning('[Administrativo] Ativação manual retida pelo gate administrativo.', ...)` incluindo `company_id`, `user_id` e `tipo_pedido`.
- A decisão nunca confia no `$validated['tipo']` cru: o helper `servicoContratadoIsentoParaTipo()` carrega os `ContratoServico` ativos da empresa, resolve cada um via `ComercialController::servicoDisparaImplementacao()` e só libera se algum contrato ativo resolver para o tipo pedido **e** for isento (`!$servico->exigeContrato()`). Prova por teste: marcar "polos" numa empresa que contratou Assessoria não abre a porta.
- Fail-safe simétrico ao da 133-01: empresa sem nenhum `ContratoServico` ativo é recusada — ausência de dado nunca é isenção.
- Com o interruptor desligado, os dois tipos (Polos e Assessoria) continuam ativando exatamente como antes — regressão provada.
- A dívida D-06 (as duas rotas — `MlbImplementacaoController::criar()` e `MlbController::storeEmpresa()` — que criam `MlbEmpresa` fora do router, sempre com `tipo='POLO'` hardcoded/default) está registrada em `.planning/todos/pending/`, com os três gatilhos concretos que a tornam urgente.

## Task Commits

Each task was committed atomically:

1. **Task 1: teste RED do FLUXO-09 (8 cenários)** - `ceac8ca5` (test)
2. **Task 2: a checagem dentro de `ativarEmpresaPendente()` (D-03 + D-07)** - `987841ac` (feat)
3. **Task 3: registro da dívida D-06** - `6bb9c5ed` (docs)

_TDD: Task 1 é o RED (4 dos 8 testes falham por ausência da checagem — sentinela + os dois de regressão já passavam, exatamente como o plano previu); Task 2 é o GREEN (os 8 passam)._

## Files Created/Modified
- `tests/Feature/Phase133/AtivarEmpresaPendenteBloqueioTest.php` - 8 testes: sentinela de fixture, Polos passa com a chave ligada, Assessoria retida, rótulo divergente não abre porta (D-07), sem contrato é recusado (fail-safe), regressão com a chave desligada nos dois tipos, mensagem de recusa sem jargão (UI-06)
- `app/Http/Controllers/MlbController.php` - `ativarEmpresaPendente()` ganha injeção de `EmpresaOperacionalRouter`, checagem antes da `DB::transaction`, e o novo helper privado `servicoContratadoIsentoParaTipo()`
- `.planning/todos/pending/260818-portas-extras-criam-mlbempresa-fora-do-router.md` - dívida D-06 versionada, com as duas rotas nomeadas, motivo de não serem urgentes hoje e os três gatilhos que mudariam isso

## Decisions Made
Nenhuma decisão nova além das já travadas no CONTEXT.md (D-03, D-06, D-07). O formato de recusa (`back()->with('error', ...)` em vez de `abort(422)`) estava documentado como "Claude's Discretion" no CONTEXT, mas o próprio `<interfaces>` do plano já resolveu essa escolha com justificativa verificada no código (`Empresas.jsx` já lê `flash.error`) — seguida sem desvio.

## Deviations from Plan
Nenhuma. O plano foi executado exatamente como escrito, incluindo a contagem exata do RED (4 dos 8 testes falham na Task 1) e a ordem de linha da checagem (antes da `DB::transaction`, conferido por grep).

## Known Stubs
Nenhum. Nenhum dado hardcoded, placeholder ou componente sem fonte de dado foi introduzido.

## Threat Flags
Nenhuma superfície nova. A mudança consulta dados já existentes (`contratosServico`, `Servico::exigeContrato()`, `EmpresaOperacionalRouter::bloqueioAtivo()`) dentro de uma rota já protegida por `checkPubAccess('empresas')`; nenhuma rota, endpoint ou caminho de auth novo foi criado. Os 6 threats do `<threat_model>` do plano que se aplicam a este método (T-133-06 a T-133-11, exceto T-133-SC que é N/A por não haver instalação de pacote) foram todos mitigados dentro do escopo desta task — ver testes correspondentes: bypass pelo rótulo do formulário (T-133-06, `test_interruptor_ligado_recusa_quando_o_tipo_do_formulario_diverge_do_servico_contratado`), porta dos fundos fechada com checagem antes da escrita (T-133-07, ordem de linha confirmada por grep), fail-safe sem contrato (T-133-08, `test_interruptor_ligado_recusa_empresa_sem_servico_contratado_ativo`), regra duplicada em dois lugares — risco aceito, mitigado por teste nominal nos dois arquivos (T-133-09), DoS de retenção legítima de Polos sem contrato — risco aceito, mitigado por mensagem sem jargão + log (T-133-10), e as duas rotas extras — risco aceito e registrado como dívida D-06 (T-133-11).

## Self-Check: PASSED

- `tests/Feature/Phase133/AtivarEmpresaPendenteBloqueioTest.php` — FOUND
- `app/Http/Controllers/MlbController.php` — FOUND (modificado)
- `.planning/todos/pending/260818-portas-extras-criam-mlbempresa-fora-do-router.md` — FOUND
- Commit `ceac8ca5` — FOUND
- Commit `987841ac` — FOUND
- Commit `6bb9c5ed` — FOUND
- `tests/Feature/Phase133 tests/Feature/Phase124KillSwitchTest.php` — `Tests: 24 passed (65 assertions)`, confirmado por execução real
