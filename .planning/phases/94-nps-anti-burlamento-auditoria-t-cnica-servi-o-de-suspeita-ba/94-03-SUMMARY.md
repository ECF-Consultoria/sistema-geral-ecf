---
phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
plan: 03
subsystem: nps
tags: [laravel, artisan-command, phpunit, tdd, audit-trail, e2e]

# Dependency graph
requires:
  - phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
    plan: 01
    provides: tabela nps_survey_events, model NpsSurveyEvent, colunas de rastro
  - phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
    plan: 02
    provides: NpsController instrumentado (opened/expired/submitted/generated manual)
provides:
  - "NpsDispararMensal::handle() emite 'generated' por survey criada (1 por empresa × modelo aplicável, metadata.origem=disparo_mensal)"
  - "NpsDispararMensal::handle() emite 'sent_email' SOMENTE no branch de sucesso do Mail::send"
  - "NpsDispararMensal::handle() emite 'sent_digisac' SOMENTE quando o envio Digisac confirma status='enviado'"
  - "Success Criteria 4 da fase comprovado por E2E: nps_survey_events acumula a linha do tempo completa gerado → enviado → aberto → respondido, tanto no fluxo automático (disparo mensal) quanto no manual (link admin)"
  - "Gate de regressão da fase: Phase94 43/43, Nps 250/250 (241 baseline + 9 novos), V16 145/145 — 0 falhas"
affects: [95-ui-confianca-admin, 96-endurecimento-bloqueio]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Console Command sem Request HTTP: eventos de auditoria emitidos com ip_address/user_agent/user_id sempre null — metadata.origem discrimina a origem (disparo_mensal vs manual, já estabelecido no Plano 94-02)"
    - "Emissão de evento condicionada ao branch de sucesso confirmado (não ao 'tentou fazer'): sent_email só após NpsEmailEnvio status=enviado gravado; sent_digisac só quando $envio->status === 'enviado' (não em falha/skipped)"

key-files:
  created: []
  modified:
    - app/Console/Commands/NpsDispararMensal.php
    - tests/Feature/Phase94/NpsSurveyEventsTest.php

key-decisions:
  - "Teste de '2 modelos aplicáveis' usa 2 serviços NOVOS (setor performance) isolados do serviço reaproveitado pelo trait ContrataServicoNpsCoberto — evita colisão com o scope do 'NPS Padrão' real (já semeado por migration de Phase 68/79), que teria inflado o resultado para 3 surveys em vez de 2"
  - "criarEmpresaElegivelDisparoMensal() aceita templateIds=null como sentinela explícito de 'nenhuma cobertura automática' (distinto de [] = comportamento padrão do trait), necessário para o teste isolado de 2 modelos"
  - "Verificação manual de topologia de proxy do VPS (RESEARCH Pitfall 1 / Open Question 1) permanece como pendência PÓS-DEPLOY — não executada nesta sessão (nenhum deploy foi feito; convenção do projeto exige autorização explícita do usuário para deploy)"

requirements-completed: [AB-94-3, AB-94-5]

# Metrics
duration: ~20min
completed: 2026-07-16
---

# Phase 94 Plan 03: NpsDispararMensal Instrumentado + Gate Final da Fase Summary

**Disparo mensal automático emite os 3 eventos que faltavam (`generated`/`sent_email`/`sent_digisac`) e a linha do tempo completa de um survey NPS — gerado → enviado → aberto → respondido — é comprovada por 2 testes E2E via HTTP real; fase fecha com 250/250 em Nps e 145/145 em V16.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-07-16T17:13:00-03:00
- **Completed:** 2026-07-16T17:20:34-03:00 (código); gate de regressão rodado logo em seguida
- **Tasks:** 2 completed
- **Files modified:** 2 (1 comando instrumentado, 1 suite de teste estendida)

## Accomplishments
- `NpsDispararMensal::handle()` ganhou os 3 emissores que faltavam da trilha `nps_survey_events` (AB-94-3): `generated` logo após cada `NpsSurvey::create()` dentro do loop de modelos aplicáveis (1 evento por survey — empresa com 2 modelos gera 2 eventos), `sent_email` apenas no branch de sucesso do `Mail::send` (o branch catch que grava `NpsEmailEnvio status=falha` permanece intocado, sem evento órfão) e `sent_digisac` apenas quando o dispatcher confirma `status === 'enviado'` (não em `falha`/`skipped`)
- Contexto de console (sem `Request` HTTP): `ip_address`/`user_agent`/`user_id` sempre `null` nos 3 eventos; `metadata.origem = 'disparo_mensal'` discrimina do `'manual'` já emitido pelo `NpsController::generate()` (Plano 94-02)
- Zero mudança em dry-run, idempotência, contadores (`$criados`/`$enviados`/`$digisacEnviados` etc.) ou logs `[NPS Mensal]` existentes — diff é estritamente aditivo (3 blocos `NpsSurveyEvent::create` inseridos, nenhuma linha pré-existente alterada)
- **Success Criteria 4 da fase comprovado por E2E** (2 testes novos, via HTTP real sem mock de controller):
  - Fluxo automático: `nps:disparar-mensal` → GET no link → POST v15 completo → sequência exata `[generated, sent_email, opened, submitted]`
  - Fluxo manual: `generate()` (admin) → GET → GET → POST → sequência `[generated, opened, opened, submitted]`, com `generated.user_id` = id do admin e `metadata.origem = 'manual'`
- **Gate de regressão da fase**: `Phase94` 43/43, `Nps` 250/250 (241 baseline pós-94-02 + 9 novos deste plano), `V16` 145/145 — 0 falhas em qualquer suite

## Task Commits

Each task was committed atomically (TDD RED → GREEN):

1. **Task 1: NpsDispararMensal emite generated + sent_email + sent_digisac**
   - `31b9617` (test) — RED: 7 cenários do disparo mensal (empresa elegível, 2 modelos, falha de email, digisac enviado/skipped, dry-run, idempotência) — 4 falhando como esperado (produção ainda não emitia os eventos)
   - `59701c6` (feat) — GREEN: 3 inserções `NpsSurveyEvent::create` no comando — 15/15 verde
2. **Task 2: Linha do tempo completa + gate de regressão da fase**
   - `6db329c` (test) — 2 testes E2E de timeline (automático + manual) — zero mudança de produção, apenas integração/comprovação do comportamento já implementado — 17/17 verde

_TDD: Task 1 teve commit RED e GREEN separados. Task 2 não alterou código de produção (só monta testes de integração sobre comportamento já correto) — commit único, conforme esperado para esse tipo de tarefa._

## Files Created/Modified
- `app/Console/Commands/NpsDispararMensal.php` - 3 blocos `NpsSurveyEvent::create` (generated/sent_email/sent_digisac) + `use App\Models\NpsSurveyEvent;` — nenhuma outra linha alterada
- `tests/Feature/Phase94/NpsSurveyEventsTest.php` - 9 novos testes: 7 cobrindo os emissores do disparo mensal (Task 1) + 2 de timeline E2E completa (Task 2)

## Decisions Made
- O teste de "2 modelos aplicáveis" precisou de 2 serviços `setor=performance` **isolados** (criados via `DB::table` direto), em vez de reaproveitar o serviço padrão do trait `ContrataServicoNpsCoberto` — esse trait reaproveita QUALQUER serviço performance ativo existente, que já está scoped ao "NPS Padrão" real (semeado pelas migrations de Phase 68/79). Usar o trait para os 2 templates de teste teria feito o "NPS Padrão" real também entrar no `modelosAplicaveis`, gerando 3 surveys em vez dos 2 esperados. Resolvido isolando o cenário com serviços/contratos/scopes novos, sem tocar no trait compartilhado (usado por outras suites que dependem do comportamento atual)
- `criarEmpresaElegivelDisparoMensal()` ganhou o sentinela `null` (vs. `[]`) para "nenhuma cobertura automática" — permite ao teste de 2 modelos montar a cobertura manualmente sem o helper criar um contrato indesejado antes

## Deviations from Plan

None - plan executado exatamente como especificado. O único ajuste foi de construção de teste (não de código de produção): o cenário "2 modelos aplicáveis" precisou de serviços isolados em vez do padrão compartilhado do trait de teste, para não colidir com dados já semeados por migrations de fases anteriores — decisão documentada acima, sem impacto no comportamento de produção.

## Issues Encountered

Nenhum problema de ambiente nesta sessão (PATH do PHP já ajustado desde os planos 94-01/94-02). O único obstáculo foi de modelagem de teste (resolvido, ver Decisions Made acima) — não chegou a ser um bloqueio real, apenas uma iteração de RED antes do commit final.

## User Setup Required

None - nenhuma configuração de serviço externo necessária nesta sessão.

**Pendência registrada (não bloqueante para esta fase, não executada nesta sessão):** verificação manual pós-deploy da topologia de rede do VPS (`https://admin.ecfconsultoria.com.br`), conforme RESEARCH Pitfall 1 / Open Question 1 e o `<verification>` do plano 94-03. Passos, quando o deploy desta fase for autorizado pelo usuário:
1. Fazer o deploy (requer autorização explícita — convenção do projeto).
2. Abrir um link NPS de FORA da rede interna da ECF (ex.: celular via dados móveis).
3. Conferir no banco (`nps_surveys.open_ip_address` do survey aberto) se o IP registrado é o IP público real do dispositivo usado — **não** `127.0.0.1` nem um IP interno de proxy.
4. Se **não bater**: a Regra 1 do `NpsSuspicionService` (IP interno ECF) está recebendo o IP errado em produção — abrir follow-up para configurar `$middleware->trustProxies(...)` em `bootstrap/app.php` (Laravel 12 fluent API) ANTES de confiar na Regra 1 para qualquer decisão nas Fases 95/96.
5. Se bater: nenhuma ação adicional necessária, a Regra 1 já funciona corretamente em produção.

Esta verificação não bloqueia a Fase 94 (backend-only, nada exibido em UI) mas é pré-requisito de confiabilidade antes que a Fase 95 (UI de confiança admin) exiba `is_suspicious`/`suspicion_reasons` derivados da Regra 1 para usuários reais.

## Next Phase Readiness

Fase 94 (NPS Anti-Burlamento) está **completa no código**: schema de rastro + tabela de eventos + `NpsSuspicionService` (94-01), `NpsController` totalmente instrumentado (94-02) e `NpsDispararMensal` totalmente instrumentado + linha do tempo E2E comprovada (94-03, este plano). Toda a trilha `nps_survey_events` nasce em produção a partir do primeiro deploy pós-merge, para os 4 emissores mapeados no RESEARCH (link manual, disparo mensal, abertura, submit).

Pronto para:
- **Fase 95** (UI admin-only de confiança/auditoria) — consumir `is_suspicious`/`suspicion_reasons` (shape `{reasons, severity}`) e a timeline completa de `nps_survey_events`, agora com os 6 `event_type` cobertos ponta a ponta.
- **Fase 96** (endurecimento/bloqueio) — usar a Regra 4 (sessão autenticada) e a confiabilidade da Regra 1 (IP interno), condicionada à verificação manual de proxy pós-deploy documentada acima.

Nenhum bloqueio de código identificado. Único item pendente é a verificação manual pós-deploy (não-bloqueante, documentada acima e no STATE.md).

## Self-Check: PASSED

Arquivo modificado `app/Console/Commands/NpsDispararMensal.php` confirmado em disco (grep `NpsSurveyEvent::create` = 3 ocorrências). Arquivo `tests/Feature/Phase94/NpsSurveyEventsTest.php` confirmado em disco (17 testes na suite, todos verdes). Todos os 3 commits de task (`31b9617`, `59701c6`, `6db329c`) confirmados via `git log --oneline --all`.

---
*Phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba*
*Completed: 2026-07-16*
