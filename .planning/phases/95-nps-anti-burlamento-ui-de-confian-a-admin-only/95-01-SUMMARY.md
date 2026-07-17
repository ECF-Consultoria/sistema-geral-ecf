---
phase: 95-nps-anti-burlamento-ui-de-confian-a-admin-only
plan: 01
subsystem: api
tags: [laravel, inertia, nps, anti-burlamento, payload-gating, tdd]

# Dependency graph
requires:
  - phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
    provides: "nps_survey_events, colunas de rastro em nps_surveys/nps_responses, NpsSuspicionService (veredito is_suspicious/suspicion_reasons já persistido)"
provides:
  - "NpsController::index() entrega `confianca` (tri-estado) e `auditoria` (12 campos) por item, admin-only"
  - "Filtro server-side `?confianca=todos|confiavel|atencao|suspeita` com whitelist, afeta paginação"
  - "Helpers privados `confiancaDe()`/`auditoriaDe()` reutilizáveis pela Wave 2 (frontend)"
  - "Shape exato do payload para 95-02 consumir: `item.confianca.{status,motivos}`, `item.auditoria.{gerado_em,gerado_por,aberto_primeira,aberto_ultima,aberto_contagem,respondido_em,tempo_ate_resposta,ip_abertura,ip_resposta,user_agent,canal,motivos}`, `props.pode_ver_confianca`, `props.filtros.confianca`"
affects: [95-02-nps-anti-burlamento-ui-de-confianca-admin-only]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Blindagem de payload por role: chave só é CRIADA no array dentro de `if ($user->isAdmin())` — nunca populada e depois filtrada/escondida na renderização"
    - "Filtro server-side validado por whitelist com fallback silencioso (mesmo molde do `$mesFiltro` já existente) — parâmetro inválido/não autorizado nunca gera erro"
    - "Leitura pura de veredito já persistido (Fase 94) via `suspicion_reasons->severity` usando operador JSON nativo do Eloquent — nenhum recálculo de regra de negócio na camada de apresentação"

key-files:
  created:
    - tests/Feature/Phase95/NpsConfiancaPayloadTest.php
    - tests/Feature/Phase95/NpsConfiancaFiltroTest.php
  modified:
    - app/Http/Controllers/NpsController.php

key-decisions:
  - "Eager-load de `events` só acontece quando `$user->isAdmin()` — não-admin nunca paga a query extra da trilha de auditoria"
  - "`confiancaDe()` lê exclusivamente `suspicion_reasons['severity']` (nunca `is_suspicious`) para preservar os 3 estados (confiavel/atencao/suspeita) em vez de colapsar em 2"
  - "Filtro `confianca` usa `whereHas('response', ...)` com operador JSON nativo do Eloquent (`suspicion_reasons->severity`), comprovadamente funcional em SQLite (testes) e MySQL/MariaDB — nenhum `whereRaw(JSON_EXTRACT(...))`"
  - "`filtro=confiavel` usa `whereNull('suspicion_reasons')` porque resposta limpa persiste NULL (fato confirmado no código da Fase 94) — nunca existe `severity='nenhuma'` gravado no banco"
  - "`pode_ver_confianca` e `filtros.confianca` só existem no payload quando admin — ausência da CHAVE, não `false`/`null`"

patterns-established:
  - "Pattern de gate condicional dentro do `->through()` de paginação Inertia: construir o item base idêntico para todos os roles, e só depois anexar chaves extras dentro de `if ($user->isAdmin())`"

requirements-completed: [AB-95-1, AB-95-2, AB-95-3, AB-95-4]

# Metrics
duration: ~50min (trabalho ativo; suites completas de regressão consumiram a maior parte do tempo de execução)
completed: 2026-07-16
---

# Phase 95 Plan 01: Payload de confiança admin-only + filtro server-side Summary

**`NpsController::index()` estendido com badge tri-estado (`confianca`) e seção de auditoria (`auditoria`) admin-only, lidos direto dos dados já persistidos pela Fase 94, mais filtro server-side `?confianca=` com whitelist — payload de não-admin permanece byte-idêntico ao de hoje.**

## Performance

- **Duration:** ~50 min de trabalho ativo (múltiplas execuções da suite completa `--filter=Nps`, ~320s cada, dominaram o tempo de parede)
- **Started:** 2026-07-16T18:20:00-03:00 (aprox.)
- **Completed:** 2026-07-16T22:41:24-03:00
- **Tasks:** 2/2
- **Files modified:** 3 (1 controller, 2 arquivos de teste novos)

## Accomplishments
- Admin passa a receber `confianca` (status confiavel/atencao/suspeita + motivos pt-BR) e `auditoria` (12 campos: gerado em/por, abertura first/last/contagem, respondido em, tempo até resposta, IPs, user-agent, canal, motivos) em cada item da lista paginada de `/nps` — zero recálculo, leitura pura do que a Fase 94 já persiste
- Payload de não-admin permanece byte-idêntico ao de antes da Fase 95 — nenhuma chave nova, nem `pode_ver_confianca`, comprovado por teste que inspeciona o array de props bruto (não o DOM)
- Filtro server-side `?confianca=todos|confiavel|atencao|suspeita` afeta paginação para admin, com whitelist estrita e fallback silencioso para valor inválido — mesmo padrão do `$mesFiltro` já existente
- Não-admin que manda `?confianca=suspeita` recebe HTTP 200 com lista/contagem idênticas à ausência do parâmetro — filtro nunca "denuncia" sua própria existência via 403/422

## Task Commits

Cada task seguiu o ciclo RED → GREEN (TDD):

1. **Task 1: Payload admin-only `confianca` + `auditoria`**
   - `6e3d0c3` — `test(95-01)`: 7 cenários (verde/amarelo/vermelho/auditoria completa/canal manual/blindagem/pendente) — RED
   - `9f1f6ba` — `feat(95-01)`: helpers `confiancaDe()`/`auditoriaDe()` + gate condicional no `->through()` — GREEN
2. **Task 2: Filtro server-side `?confianca=`**
   - `49dcf86` — `test(95-01)`: 7 cenários (filtro por status, todos/ausência, fallback inválido, props.filtros, blindagem não-admin) — RED
   - `5328118` — `feat(95-01)`: whitelist + `whereHas('response', ...)` com operador JSON nativo do Eloquent — GREEN

**Plan metadata:** (este commit, a seguir)

## Files Created/Modified
- `app/Http/Controllers/NpsController.php` — `index()` estendido: eager-load condicional de `events`, `->through()` convertido para closure completa com gate `if ($user->isAdmin())`, 2 helpers privados novos (`confiancaDe`/`auditoriaDe`), filtro `confianca` validado por whitelist aplicado após o escopo de carteira, `pode_ver_confianca` + `filtros.confianca` condicionais nos props
- `tests/Feature/Phase95/NpsConfiancaPayloadTest.php` — 7 testes cobrindo AB-95-1/AB-95-2/AB-95-4 (payload bruto, admin vs. não-admin)
- `tests/Feature/Phase95/NpsConfiancaFiltroTest.php` — 7 testes cobrindo AB-95-3 (filtro server-side + comportamento inócuo para não-admin)

## Decisions Made
- Ler exclusivamente `suspicion_reasons['severity']` (nunca `is_suspicious`) no helper `confiancaDe()` — evita colapsar os 3 estados exigidos pelo CONTEXT em apenas 2
- Usar o operador JSON nativo do Eloquent (`coluna->chave`) em vez de `whereRaw("JSON_EXTRACT(...)")` para o filtro — validado empiricamente funcionando em SQLite (suite de testes) sem qualquer configuração extra
- `filtro=confiavel` mapeado para `whereNull('suspicion_reasons')` (não `severity='nenhuma'`, que nunca é gravado no banco) — mesma regra que `confiancaDe()` usa para o mapeamento de leitura
- `pode_ver_confianca`/`filtros.confianca` ausentes (não `false`) para não-admin — blindagem mais estrita que "flag booleana visível", exigida literalmente pelo CONTEXT

## Deviations from Plan

None - plan executado exatamente como escrito. O único ajuste foi cosmético: reescrita de um comentário no controller que continha literalmente a string `whereRaw(...)` (dentro de uma frase explicando o que NÃO fazer) para não inflar o grep de sanidade do plano (`grep -c whereRaw`) com um falso positivo — sem alteração de comportamento.

## Issues Encountered
None.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- Backend 100% pronto para a Wave 2 (95-02) consumir: shape de `confianca`/`auditoria`/`pode_ver_confianca`/`filtros.confianca` documentado no frontmatter acima
- Suite `php artisan test --filter=Nps`: 264/264 verde (baseline 250 + 14 testes novos desta fase) — nenhuma regressão
- Nenhum arquivo de página pública (`Respond`/`ThankYou`/`AlreadyCompleted`/`Expired`) tocado — confirmado via `git diff --stat` dos commits desta fase
- `resources/js/Pages/Nps/Index.jsx` permanece intocado — pendência 100% da Wave 2 (`npm run build` não é necessário nesta plan)

---
*Phase: 95-nps-anti-burlamento-ui-de-confian-a-admin-only*
*Completed: 2026-07-16*

## Self-Check: PASSED

Todos os arquivos declarados (`NpsController.php`, `NpsConfiancaPayloadTest.php`, `NpsConfiancaFiltroTest.php`, este SUMMARY) e todos os 4 commits de task (`6e3d0c3`, `9f1f6ba`, `49dcf86`, `5328118`) confirmados presentes no repositório e no histórico do git.
