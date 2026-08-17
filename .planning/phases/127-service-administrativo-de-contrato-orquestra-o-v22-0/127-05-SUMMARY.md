---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
plan: 05
subsystem: clicksign / fila
tags: [laravel, queue, rate-limit, clicksign, tdd, d-01, d-02, d-03, d-06]

# Dependency graph
requires:
  - phase: 127-01
    provides: "schema D-06 (servico_id, prazo_dias, lembrete_dias, trava composta empresa+servico)"
  - phase: 127-02
    provides: "ClicksignClient::montarEnvelopePorModelo(..., ativar: false) — caminho que para no rascunho"
  - phase: 127-04
    provides: "Servico::clicksignTemplateId() e ContratoAssinatura::prazoDiasEfetivo()/lembreteDiasEfetivo()"
provides:
  - "App\\Jobs\\GerarContratoAssinaturaJob — worker de fila que monta UM envelope por contrato e para no rascunho"
  - "RateLimiter::for('clicksign-envelope', ...) — bucket global 1/min em AppServiceProvider::boot()"
affects: [127-06, 127-07, 129, 130]

tech-stack:
  added: []
  patterns:
    - "middleware() do job combina WithoutOverlapping (Cache::lock, fecha a corrida do RateLimited) + RateLimited (throttle propriamente dito) — mesmo padrão para qualquer job futuro contra a mesma conta Clicksign"
    - "failed() grava pelo save() do model, nunca update() de query builder — o hook saving é quem alimenta a trava de unicidade (D-06)"

key-files:
  created:
    - app/Jobs/GerarContratoAssinaturaJob.php
    - tests/Feature/Phase127/GerarContratoAssinaturaJobTest.php
  modified:
    - app/Providers/AppServiceProvider.php

key-decisions:
  - "D-01 implementada: RateLimited('clicksign-envelope') + WithoutOverlapping('clicksign-envelope-global') juntos — RateLimited sozinho tem janela de corrida entre tooManyAttempts()/hit() que WithoutOverlapping fecha sem custo de infra"
  - "D-02 implementada: ativar: false é passado explicitamente ao ClicksignClient — enviado_em NUNCA é tocado pelo job, só o webhook da Fase 129 vai saber que foi enviado"
  - "D-03 implementada: deadline_at/remind_interval vão em $dadosEnvelope na CRIAÇÃO, mesma forma literal de ClicksignClient::ativarEnvelope() — reusada, não reinventada"
  - "Guard de reentrega (T-127-16): contrato com clicksign_envelope_id já preenchido retorna sem montar segundo envelope"
  - "failed() poda e-mail da mensagem de erro antes de gravar (WR-11) — regra de terceiro não confiável para gravar sem tratamento"

patterns-established:
  - "Job contra API externa com orçamento de chamadas apertado: middleware() = [WithoutOverlapping, RateLimited], tries/timeout/backoff copiados de SyncAdmanCompanyJob, failed() grava estado de erro pelo model (nunca query builder)"

requirements-completed: [CLICK-02, CLICK-08, DADOS-06]

# Metrics
duration: ~30min
completed: 2026-08-12
---

# Phase 127 Plan 05: GerarContratoAssinaturaJob Summary

**Job de fila que monta um envelope Clicksign completo por contrato (documento por modelo, 4 signatários, 8 requisitos) e para no rascunho — orçamento provado em 14 chamadas por execução, dentro do teto de 15/20 medido.**

## Performance

- **Duration:** ~30 min
- **Tasks:** 3
- **Files modified:** 3 (2 criados, 1 modificado)

## Accomplishments
- `GerarContratoAssinaturaJob` monta o envelope inteiro contra a API sandbox da Clicksign e nunca ativa — quem envia ao cliente continua sendo o Comercial, pela interface (D-02).
- Bucket `RateLimiter::for('clicksign-envelope', ...)` (1/min, global) registrado ao lado do `'adman-api'` já existente, combinado com `WithoutOverlapping` para fechar a janela de corrida entre `tooManyAttempts()`/`hit()` do `RateLimited` isolado.
- Prazo e lembrete efetivos do contrato (`prazoDiasEfetivo()`/`lembreteDiasEfetivo()`, do plano 127-04) vão na criação do envelope, na MESMA forma literal já usada por `ativarEnvelope()` — D-03.
- Falha definitiva libera a empresa: `failed()` grava `status = erro` pelo `save()` do model (o hook `saving` do `ContratoAssinatura` zera as duas colunas de "em andamento" e libera o slot para nova tentativa).
- `erro_mensagem` nunca carrega e-mail de terceiro (poda de PII antes de gravar).
- Caminho feliz medido em exatamente 14 chamadas HTTP (15 da Fase 126 menos a ativação removida pela D-02) — dentro do orçamento de 15/20 da Fase 127.

## Task Commits

1. **Task 1: Testes do job de montagem (RED)** - `b18257a5` (test) — 12 métodos de teste (11 cenários do plano, teste 5 dividido em duas asserções de `deadline_at`), todos falhando por `Class "App\Jobs\GerarContratoAssinaturaJob" not found`.
2. **Task 2: Bucket de rate limit 'clicksign-envelope'** - `f5eada35` (feat)
3. **Task 3: GerarContratoAssinaturaJob (GREEN)** - `ca256fd0` (feat) — 12/12 verdes na primeira execução, sem necessidade de correção.

## Files Created/Modified
- `app/Jobs/GerarContratoAssinaturaJob.php` - o worker: `middleware()` (WithoutOverlapping + RateLimited), `handle()` (guard de reentrega, guard de modelo ausente, montagem via `ClicksignClient::montarEnvelopePorModelo(..., ativar: false)`, gravação por `save()`), `failed()` (status=erro + poda de PII).
- `app/Providers/AppServiceProvider.php` - `RateLimiter::for('clicksign-envelope', fn () => Limit::perMinute(1)->by('global'))`, comentado com o orçamento medido (15 de 20/min) e o aviso de que a janela de produção nunca foi medida.
- `tests/Feature/Phase127/GerarContratoAssinaturaJobTest.php` - 12 testes cobrindo CLICK-02, D-02, CLICK-08, DADOS-06, orçamento de chamadas, D-01 (middleware), Pitfall 3 (liberação da trava), PII, D-04 (rollback não duplicado) e D-21 (serviço sem modelo).

## Decisions Made
Ver `key-decisions` no frontmatter. Resumo: D-01 com dois mecanismos (não um), D-02 sem tocar `enviado_em`, D-03 reusando a forma literal de `ativarEnvelope()`, guard de reentrega contra `T-127-16`, `failed()` sempre pelo `save()` do model.

## Deviations from Plan

Nenhuma. O plano foi executado exatamente como escrito — os 12 testes (11 cenários do plano, com o teste 5 dividido em duas asserções separadas para `deadline_at`: coluna preenchida e padrão de config) passaram na primeira execução contra a implementação, sem ciclo de correção.

## Issues Encountered

Nenhum. Para o ciclo RED→GREEN genuíno da Task 1/3 (TDD), o arquivo do job foi escrito primeiro para leitura de referência cruzada, temporariamente removido do diretório antes de rodar a suíte (confirmando RED por classe inexistente) e restaurado antes da Task 3 — nenhum commit intermediário expôs o job antes do commit de teste RED.

## User Setup Required

None - nenhuma configuração de serviço externo. O bucket `clicksign-envelope` usa o mesmo cache `database` já configurado em produção (mesmo mecanismo comprovado para `'adman-api'`, `127-RESEARCH.md` Q1).

## Next Phase Readiness

`GerarContratoAssinaturaJob` está pronto para ser despachado pelo orquestrador do plano 127-06, que cria o `ContratoAssinatura` (com `servicos_snapshot` congelado) e dispara o job — este plano não cria contrato nem dispara nada, só monta o envelope de um contrato que já existe. `ContratoDadosMinimosService` (127-03) continua sendo checado ANTES do dispatch, no orquestrador — este job não repete essa validação. Gate NÃO MEDIDO herdado do `127-CONTEXT.md`: "prazo definido na criação sobrevive à ativação pela interface web" continua pendente do gate do plano 127-07, com envelope completo (não vazio).

Suíte combinada `Phase125 + Phase126 + Phase127` = **199 testes verdes** (baseline 187 + 12 novos deste plano). Zero regressão. Nenhuma chamada real à Clicksign; nenhum deploy.

---
*Phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: app/Jobs/GerarContratoAssinaturaJob.php
- FOUND: tests/Feature/Phase127/GerarContratoAssinaturaJobTest.php
- FOUND: app/Providers/AppServiceProvider.php (com RateLimiter::for('clicksign-envelope', ...))
- FOUND commit b18257a5 (test RED)
- FOUND commit f5eada35 (feat — bucket de rate limit)
- FOUND commit ca256fd0 (feat — GerarContratoAssinaturaJob GREEN)
