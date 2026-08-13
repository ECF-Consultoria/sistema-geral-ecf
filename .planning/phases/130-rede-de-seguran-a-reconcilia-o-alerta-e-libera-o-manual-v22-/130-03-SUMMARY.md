---
phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-
plan: 03
subsystem: jobs
tags: [laravel, queue, clicksign, rate-limit, reconciliacao]

# Dependency graph
requires:
  - phase: 130-01
    provides: "ContratoLiberacao::VIA_RECONCILIACAO, EmpresaOperacionalRouter::liberarEmpresa(motivoSlug:)"
  - phase: 129
    provides: "ClicksignClient, GateLiberacaoOperacionalService, ContratoSignatariosSyncService, EmpresaOperacionalRouter, BaixarPdfContratoAssinadoJob, bucket clicksign-webhook (3/min global)"
provides:
  - "ReconciliarContratoClicksignJob — reconsulta o envelope de UM contrato, aplica o gate e libera via='reconciliacao'"
  - "Comando clicksign:reconciliar — dois escopos (D-07 aguardando_assinaturas, D-08 PDF pendente), SELECT+dispatch, zero HTTP direto"
  - "Configuracao::get('clicksign_reconciliacao_status') — carimbo D-09 (executado_em/vistos/corrigidos/pdfs_redisparados/erro)"
affects: [130-06-agendador, 130-07-gate-humano-sandbox]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Job de reconciliação copiado 1:1 da forma de ProcessarEventoClicksignJob, trocando o gatilho (evento webhook) por ContratoAssinatura direto — nunca lê payload/evento, decide só por reconsulta"
    - "Comando 'SELECT + fan-out de dispatch' sem HTTP direto (molde SyncAdmanData), sem delay artificial porque o throttle já vive dentro do job via RateLimited"
    - "Prova de rate limit por ReflectionProperty sobre o middleware instanciado, nunca por leitura de string do arquivo-fonte"

key-files:
  created:
    - app/Jobs/ReconciliarContratoClicksignJob.php
    - app/Console/Commands/ClicksignReconciliar.php
    - tests/Feature/Phase130/ReconciliacaoDivergenciaTest.php
    - tests/Feature/Phase130/ReconciliacaoEscopoTest.php
    - tests/Feature/Phase130/ReconciliacaoPdfPendenteTest.php
    - tests/Feature/Phase130/ReconciliacaoRateLimitTest.php
  modified: []

key-decisions:
  - "Nenhuma decisão nova — plano executado exatamente como especificado. Toda a arquitetura (guard de trabalho redundante, bucket único, escopo estreito, carimbo em Configuracao) já estava travada no PLAN.md e no 130-PATTERNS.md."

patterns-established:
  - "clicksign:reconciliar nunca importa ClicksignClient nem faz Http:: — a chamada HTTP fica isolada dentro do job, atrás do RateLimited('clicksign-webhook')"

requirements-completed: [REDE-04]

# Metrics
duration: 35min
completed: 2026-08-13
---

# Phase 130 Plano 03: Rede de segurança — reconciliação, alerta e liberação manual Summary

**`ReconciliarContratoClicksignJob` (reconsulta + gate + `via='reconciliacao'`) e comando `clicksign:reconciliar` (SELECT + dispatch + carimbo D-09), corrigindo sozinho o contrato cujo webhook nunca chegou sem nunca estourar o bucket de 3/min GLOBAL da Clicksign**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-13T19:05:00Z (aprox.)
- **Completed:** 2026-08-13T19:40:00Z (aprox.)
- **Tasks:** 3 completadas
- **Files modified:** 6 (todos criados)

## Accomplishments

- `ReconciliarContratoClicksignJob` — job irmão de `ProcessarEventoClicksignJob` (Fase 129), mas SEM evento por trás: recebe o `ContratoAssinatura` direto, guard de trabalho redundante (sai sem chamar a Clicksign se já liberado ou fora de `aguardando_assinaturas`), reconsulta o envelope (`$envelope['attributes']`, nunca `['data']['attributes']`), aplica o mesmo `GateLiberacaoOperacionalService::avaliar()` do fluxo automático, e libera via `EmpresaOperacionalRouter::liberarEmpresa()` com `via = ContratoLiberacao::VIA_RECONCILIACAO` — sem lock nem guard de idempotência próprios (herdados de `lockDaEmpresa()`).
- Comando `clicksign:reconciliar` — SOMENTE `SELECT` + `dispatch()` + carimbo, ZERO chamada HTTP direta. Dois escopos separados e propositalmente estreitos: (D-07) `aguardando_assinaturas` com envelope e sem `liberado_em`; (D-08) `assinado` sem `pdf_assinado_path`, redisparando `BaixarPdfContratoAssinadoJob` mesmo depois de uma falha anterior (`pdf_assinado_erro` preenchido) — o link fresco vem da reconsulta dentro do próprio job de PDF.
- Carimbo D-09 (`Configuracao::set('clicksign_reconciliacao_status', ...)`) gravado tanto no caminho feliz quanto no `catch` de exceção — nenhum "morre calado" para a futura checagem de ausência (plano 130-06).
- `ReconciliacaoRateLimitTest` prova por Reflection (nunca por leitura de string do arquivo-fonte) que o job usa exatamente o bucket `clicksign-webhook` e que esse bucket está registrado a 3 por minuto — se alguém afrouxar o número sem medir a janela de produção, o teste acusa.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: ReconciliarContratoClicksignJob — reconsulta, avalia e libera (D-07)** - `4ffa6d1f` (feat)
2. **Task 2: Comando clicksign:reconciliar — dois escopos, dispatch e carimbo (D-06, D-08, D-09)** - `c0a5a428` (feat)
3. **Task 3: Prova arquitetural do rate limit (Pitfall 3)** - `11311ae4` (test)

## Files Created/Modified

- `app/Jobs/ReconciliarContratoClicksignJob.php` - construtor recebe `ContratoAssinatura` direto (nunca `ContratoAssinaturaEvento`), `middleware()` com `WithoutOverlapping('clicksign-reconciliar-{id}')` + `RateLimited('clicksign-webhook')`, `handle()` reconsulta+sync+gate+libera+redispara PDF, `failed()` no canal `ecf-webhooks` com `podarPii()`
- `app/Console/Commands/ClicksignReconciliar.php` - `$signature = 'clicksign:reconciliar'`, dois `SELECT` separados por escopo, `try/catch (\Throwable)` envolvendo o `handle()` inteiro, carimbo em `Configuracao::set('clicksign_reconciliacao_status', ...)` gravado nos dois caminhos
- `tests/Feature/Phase130/ReconciliacaoDivergenciaTest.php` - 4 testes: correção do webhook perdido com `via=reconciliacao`, execução dupla sem duplicar liberação, envelope ainda em andamento não libera, contrato já liberado não chama a Clicksign
- `tests/Feature/Phase130/ReconciliacaoEscopoTest.php` - 5 testes: os 5 estados fora do escopo (`rascunho`/`recusado`/`expirado`/`cancelado`/`erro`) nunca despacham o job, `aguardando_assinaturas` com/sem envelope, já liberado, e carimbo com contagens corretas
- `tests/Feature/Phase130/ReconciliacaoPdfPendenteTest.php` - 4 testes: PDF pendente redisparado, PDF já baixado não redisparado, erro anterior não impede novo disparo, carimbo `pdfs_redisparados` reconsultado do banco
- `tests/Feature/Phase130/ReconciliacaoRateLimitTest.php` - 5 testes: comando não fala HTTP com 8 contratos elegíveis, 8 jobs despachados, `middleware()` contém `RateLimited`+`WithoutOverlapping`, bucket é exatamente `clicksign-webhook` (via Reflection), limite registrado é 3/min

## Decisions Made

Nenhuma decisão nova tomada durante a execução — plano seguido exatamente como especificado. Toda a arquitetura (guard de trabalho redundante no job, escopo estreito e separado do comando, carimbo em `Configuracao`, prova de rate limit via Reflection) já estava travada no `130-03-PLAN.md` e no `130-PATTERNS.md`.

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered

Nenhum. Toda a suíte rodou via SQLite (`RefreshDatabase`), sem tocar o MariaDB local (instabilidade conhecida do ambiente, documentada em `.planning/learnings/`). O item 2 da `<verification>` do plano ("`php artisan clicksign:reconciliar` roda num banco de desenvolvimento e sai 0") não foi exercitado contra o MariaDB local de propósito — não é requisito do `<success_criteria>` deste executor e o ambiente instrui a não depender do MariaDB local para nada além dos testes SQLite.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Nenhuma chamada HTTP real à Clicksign foi feita (`Http::fake()` em todos os testes) — as chamadas reais em sandbox são o gate humano do plano 130-07.

## Next Phase Readiness

- `ReconciliarContratoClicksignJob` e `clicksign:reconciliar` prontos para o registro no agendador do plano 130-06 (fora de escopo deste plano, de propósito, para não haver conflito de arquivo entre planos paralelos em `routes/console.php`)
- `Configuracao::get('clicksign_reconciliacao_status')` pronto para a checagem de ausência (`ClicksignVerificarVarredura`, também plano 130-06)
- Suíte `Phase130` completa: 41/41 testes verdes (23 herdados dos planos 01/02 + 4 + 9 + 5 novos deste plano)
- Suíte `Phase129` (80 testes) verde após a introdução do job novo — nenhum call-site existente quebrou
- Nenhum bloqueio conhecido para os planos seguintes desta fase

## Self-Check: PASSED

Todos os 6 arquivos criados foram confirmados no disco e os 3 commits de task (`4ffa6d1f`, `c0a5a428`, `11311ae4`) foram confirmados em `git log`. Suíte `Phase130` completa roda verde via `C:\xampp\php\php.exe artisan test --filter=Phase130` (41 testes, 92 assertions). Suíte `Phase129` roda verde via `C:\xampp\php\php.exe artisan test --filter=Phase129` (80 testes, 235 assertions).

---
*Phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-*
*Completed: 2026-08-13*
