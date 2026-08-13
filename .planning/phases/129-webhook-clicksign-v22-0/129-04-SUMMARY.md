---
phase: 129-webhook-clicksign-v22-0
plan: 04
subsystem: api
tags: [laravel, clicksign, contrato, liberacao, gate, operacional]

# Dependency graph
requires:
  - phase: 129-03
    provides: "ContratoSignatariosSyncService populando contrato_assinatura_signatarios.situacao antes deste gate rodar"
  - phase: 129-02
    provides: "Gate A1 fechado — não bloqueia tecnicamente este plano, só a ordem de risco da wave"
provides:
  - "Tabela contrato_liberacoes — fato gravado de liberação por (empresa, serviço), com via/motivo/quem/quando"
  - "GateLiberacaoOperacionalService::avaliar() — a regra CLICK-05: closed sozinho NÃO libera"
  - "EmpresaOperacionalRouter::liberarEmpresa() — ponto único compartilhado webhook (129) + manual (130)"
affects: ["129-05", "130", "131", "132"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guard de leitura + catch QueryException(23000) para idempotência de EFEITO, não só de ingestão"
    - "Método público fino delegando a método privado (aplicarRoteamento), mesmo padrão de rotearServico/rotearCadastro"
    - "Contorno deliberado e documentado de um interruptor de emergência, quando o próprio interruptor pressupõe o caminho contornado"

key-files:
  created:
    - database/migrations/2026_08_14_100001_create_contrato_liberacoes_table.php
    - app/Models/ContratoLiberacao.php
    - app/Services/Contratos/GateLiberacaoOperacionalService.php
    - tests/Feature/Phase129/ContratoLiberacaoSchemaTest.php
    - tests/Feature/Phase129/GateLiberacaoOperacionalTest.php
    - tests/Feature/Phase129/LiberarEmpresaIdempotenteTest.php
  modified:
    - app/Services/Operacional/EmpresaOperacionalRouter.php

key-decisions:
  - "status == closed NÃO é suficiente para liberar — exige também que todo signatário PAPEL_CONTRATANTE tenha SITUACAO_ASSINOU (Pitfall A: deadline pode fechar com assinatura parcial)"
  - "Só o contratante é obrigatório — pendência de contratada (ECF) ou testemunha não prende o cliente"
  - "liberarEmpresa() contorna deliberadamente o interruptor administrativo_bloqueio_ativo (T-129-25) — o interruptor trava o roteamento PRÉ-contrato, e a liberação por contrato assinado é a porta que ele pressupõe"
  - "D-02 (uma ficha só por empresa) já estava coberta pelo guard MlbEmpresa::exists() por reconsulta — nenhuma mudança necessária, só documentação da garantia"
  - "liberarEmpresa() nasce na Fase 129, não na 130 — SC3/SC4 da Fase 130 exigem ponto único desde o primeiro chamador"

requirements-completed: [CLICK-05, CLICK-04]

# Metrics
duration: ~1h30min
completed: 2026-08-13
---

# Phase 129 Plan 04: Gate de liberação operacional + ponto único de liberação Summary

**`GateLiberacaoOperacionalService` recusa liberar um envelope `closed` cujo contratante ainda não assinou (o fechamento forçado por prazo do achado medido), e `EmpresaOperacionalRouter::liberarEmpresa()` é o ponto único, idempotente e à prova de corrida que webhook (129) e liberação manual (130) vão compartilhar.**

## Performance

- **Duration:** ~1h30min
- **Started:** 2026-08-13
- **Completed:** 2026-08-13
- **Tasks:** 3/3
- **Files modified:** 6 criados, 1 modificado

## Accomplishments

- **Tabela `contrato_liberacoes` + model `ContratoLiberacao`** (Task 1) — o fato gravado de liberação por (empresa, serviço), com FK/índices nomeados à mão (`cl_empresa_servico_uniq`, `cl_company_fk`, `cl_servico_fk`, `cl_contrato_fk`, `cl_user_fk`), `via` STRING (não enum), `gerou_ficha` como prova material da D-03. Migration aplicada com sucesso (`Ran`, não `Pending`) — sem estouro de 64 caracteres.
- **`GateLiberacaoOperacionalService`** (Task 2) — a regra da CLICK-05, testada ramo a ramo: `closed` com contratante pendente NÃO libera (o cenário central do Pitfall A), recusa de qualquer signatário para o processo, fail-closed sem contratante registrado, e pendência da ECF/testemunha não prende o cliente. Service puro, sem HTTP, recebe o envelope já reconsultado.
- **`EmpresaOperacionalRouter::liberarEmpresa()`** (Task 3) — refatoração mínima (`rotear()` → `aplicarRoteamento()`, comportamento observável idêntico) seguida do método novo: guard de leitura + `catch QueryException` por SQLSTATE 23000 para a corrida real, contorno deliberado do interruptor de emergência, grava a liberação mesmo sem gerar ficha, e usa `save()` (nunca `update()`) em `ContratoAssinatura` para não desligar a trava `ca_empresa_servico_andamento_uniq`.

## Task Commits

1. **Task 1: tabela `contrato_liberacoes` + model** — `8803879c` (feat) — 3 testes, 15 assertions
2. **Task 2: `GateLiberacaoOperacionalService`** — `74d2c6bc` (feat) — 6 testes, 17 assertions
3. **Task 3: `EmpresaOperacionalRouter::liberarEmpresa()`** — `461adb8b` (feat) — 7 testes novos + 4 suítes de regressão (Phase124KillSwitchTest, Phase124RegressaoComercialTest, Phase124RegressaoHubspotTest, InvarianteRoteamentoTest) verdes sem edição

_Nenhuma task teve TDD — plano `type="execute"` padrão._

## Files Created/Modified

- `database/migrations/2026_08_14_100001_create_contrato_liberacoes_table.php` (novo) — `company_id`, `servico_id`, `contrato_assinatura_id` (nullable), `via`, `liberado_por_user_id` (nullable), `motivo`, `gerou_ficha`, `liberado_em`; índice único `cl_empresa_servico_uniq`
- `app/Models/ContratoLiberacao.php` (novo) — `VIA_WEBHOOK`/`VIA_MANUAL`, `existeParaServico()` (conveniência de UX, docblock avisa que a garantia real é o índice), sem `LogsActivity`
- `app/Services/Contratos/GateLiberacaoOperacionalService.php` (novo) — `avaliar(ContratoAssinatura, array $envelopeReconsultado): array`, 5 passos avaliados em ordem
- `app/Services/Operacional/EmpresaOperacionalRouter.php` (modificado) — `aplicarRoteamento()` extraído de `rotear()`; `liberarEmpresa()` novo, público
- 3 arquivos de teste novos em `tests/Feature/Phase129/` (16 testes, 53 assertions no total desta plano)

## Decisions Made

- Ver `key-decisions` no frontmatter para o registro completo.
- O teste de corrida (`liberacao_ja_existente_e_devolvida_sem_excecao_vazar`) segue a prática já aceita no projeto (precedente Fase 127, `IdempotenciaContratoTest`): insere a linha manualmente antes de chamar o método, em vez de tentar forçar paralelismo real em PHPUnit. Exercita o guard de leitura (passo 1 de `liberarEmpresa()`); a garantia do `catch QueryException` em si é provada por inspeção estática de código (mesmo padrão do precedente).

## Deviations from Plan

None — plano executado exatamente como escrito.

## Issues Encountered

None.

## User Setup Required

None.

⚠️ Lembrete herdado do ambiente (não desta task): o túnel cloudflared e o `php artisan serve` seguem rodando desta sessão — não foram tocados por esta execução.

## Next Phase Readiness

- `GateLiberacaoOperacionalService::avaliar()` está pronto para ser chamado pelo `ProcessarEventoClicksignJob` no plano 129-05 (ponto de extensão já marcado no job desde a 129-03).
- `EmpresaOperacionalRouter::liberarEmpresa()` está pronto para a Fase 130 chamar com `via = 'manual'` sem reimplementar nada — SC3/SC4 do ROADMAP cobertos desde o nascimento do método.
- Nenhum bloqueio para o plano 129-05 iniciar.

## Self-Check: PASSED

- `database/migrations/2026_08_14_100001_create_contrato_liberacoes_table.php` → FOUND
- `app/Models/ContratoLiberacao.php` → FOUND
- `app/Services/Contratos/GateLiberacaoOperacionalService.php` → FOUND
- `app/Services/Operacional/EmpresaOperacionalRouter.php` (contém `liberarEmpresa`) → FOUND
- Commit `8803879c` → FOUND em `git log`
- Commit `74d2c6bc` → FOUND em `git log`
- Commit `461adb8b` → FOUND em `git log`
- `php artisan migrate:status` → `contrato_liberacoes` mostra `Ran`
- Suíte `Phase129` (isolada) + regressão 124/128 → 27 passed / 98 assertions, exit 0 (via filtro combinado da Task 3)
- Suíte cumulativa `Phase124|Phase125|Phase126|Phase127|Phase128|Phase129` → 319 passed / 1050 assertions, exit 0 (baseline 303/997 + 16 testes/53 assertions desta plano — sem regressão)
- `git diff --name-only tests/` não inclui nenhum arquivo `Phase124*` nem `Phase128/*`

---
*Phase: 129-webhook-clicksign-v22-0*
*Completed: 2026-08-13*
