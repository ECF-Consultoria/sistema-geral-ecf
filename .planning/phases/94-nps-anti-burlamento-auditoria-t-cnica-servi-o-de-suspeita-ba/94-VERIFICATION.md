---
phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
verified: 2026-07-16T00:00:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
---

# Phase 94: NPS Anti-Burlamento — auditoria técnica + serviço de suspeita (backend) Verification Report

**Phase Goal:** Toda abertura e resposta de link NPS deixa rastro técnico (IP, user-agent, horários, duração) e um serviço central avalia e persiste se a resposta é suspeita — sem nenhuma mudança visível para quem responde.
**Verified:** 2026-07-16
**Status:** passed
**Re-verificação:** Não — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Abrir link NPS registra horário/IP/UA e incrementa `open_count`; reabrir atualiza `last_opened_at` sem perder `first_opened_at` | ✓ VERIFIED | `NpsController::respond()` (linhas 548-568): `update(['first_opened_at' => $survey->first_opened_at ?? now(), 'last_opened_at' => now(), 'open_count' => $survey->open_count + 1, 'open_ip_address' => ..., 'open_user_agent' => ...])` roda em TODO GET (antes dos early-returns de completed/expired). Teste `NpsOpenTrailTest::test_reabertura_preserva_first_opened_at_e_atualiza_last_opened_at` PASSOU (rodado ao vivo). |
| 2 | Responder registra IP/UA/duração; resposta ganha veredito `is_suspicious` + motivos do `NpsSuspicionService` | ✓ VERIFIED | Helper `capturarRastroEAvaliarSuspeita()` (linha 749) chamado nos dois paths (`submitResponseV15` linha 868, `submitResponseLegacy` linha 1017) — grava `response_ip_address`/`response_user_agent`/`response_duration_seconds`/`is_suspicious`/`suspicion_reasons` na MESMA linha do `NpsResponse::create()`. `NpsSuspicionService::evaluate()` existe e implementa as 4 regras. Testes `NpsResponseTrailAndSuspicionTest` (8 testes) PASSARAM ao vivo. |
| 3 | IP interno ECF OU resposta rápida → suspeita com motivo pt-BR legível | ✓ VERIFIED | `NpsSuspicionService::evaluate()` (linhas 27-57): Regra 1 (IP interno via `IpUtils::checkIp`), Regra 2 (janela configurável, texto dinâmico em minutos/segundos), Regra 3 (combinação → severidade 'alta'), Regra 4 (sessão autenticada). Textos pt-BR exatos conforme CONTEXT. Testes `NpsSuspicionServiceTest` (9 cenários) PASSARAM ao vivo. |
| 4 | `nps_survey_events` acumula timeline completa (generated → sent_email/sent_digisac → opened → submitted/expired) | ✓ VERIFIED | 6 pontos de emissão confirmados no código: `generated` (manual em `NpsController::generate()` linha 507, mensal em `NpsDispararMensal.php` linha 267), `sent_email` (linha 341, só no branch de sucesso), `sent_digisac` (linha 390, só quando `status==='enviado'`), `opened` (linha 561), `expired` (linha 579), `submitted` (helper `registrarEventoSubmitted()` linha 782, dentro da transação). Testes E2E de timeline completa (`timeline e2e fluxo automatico` e `timeline e2e fluxo manual` em `NpsSurveyEventsTest`) PASSARAM ao vivo, comprovando a sequência ponta a ponta via HTTP real. |
| 5 | Nada muda para o cliente (payload público sem dados técnicos) e legado não quebra | ✓ VERIFIED | `Inertia::render('Nps/Respond', ...)` expõe só `survey.{token,company_name,estrategista_name,analista_name,tem_analista,textos}` + `perguntas_extras` + `template` (nenhum campo de rastro/suspeita). Teste explícito `test_payload_inertia_survey_nao_ganha_chaves_novas` asserta `has('survey', 6)` (contagem exata de chaves) — PASSOU ao vivo. `Nps/AlreadyCompleted`, `Nps/Expired`, `Nps/ThankYou` são renderizados sem nenhum prop. Retrocompat: `NpsAntiBurlamentoBackwardCompatTest` (5 testes, incluindo defaults de coluna e fluxo GET/POST legado completo) PASSOU ao vivo. |

**Score:** 5/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/Nps/NpsSuspicionService.php` | Serviço stateless com 4 regras + textos pt-BR | ✓ VERIFIED | Existe, substantivo (103 linhas), usado em `NpsController` via `app()` — wired |
| `app/Models/NpsSurveyEvent.php` | Model com 6 `TYPE_*` constants + relações | ✓ VERIFIED | Existe, `TYPES` array com 6 tipos, `survey()`/`user()` relations, cast `metadata`→array — wired e usado em 3 arquivos de produção |
| `config/nps.php` | Config `.env`-driven (IPs/CIDRs/janela) | ✓ VERIFIED | Existe, 100% via `env()`, zero hardcode — consumido por `NpsSuspicionService` |
| `database/migrations/2026_07_16_100001_*` (nps_surveys) | Colunas de rastro de abertura, nullable | ✓ VERIFIED | 5 colunas nullable (exceto `open_count` default 0), guard `Schema::hasColumn` idempotente |
| `database/migrations/2026_07_16_100002_*` (nps_responses) | Colunas de rastro de resposta + suspeita, nullable | ✓ VERIFIED | 5 colunas nullable (exceto `is_suspicious` default false), guard idempotente |
| `database/migrations/2026_07_16_100003_*` (nps_survey_events) | Tabela nova, `user_id` nullable antes de `nullOnDelete` | ✓ VERIFIED | `nullable()` chamado ANTES de `nullOnDelete()` (evita erro 1830 MariaDB, conforme memória do projeto); `Schema::hasTable` guard |
| `app/Http/Controllers/NpsController.php` | `respond()`/`submitResponse*()`/`generate()` instrumentados | ✓ VERIFIED | Todos os 4 pontos de emissão + captura de rastro presentes e wired |
| `app/Console/Commands/NpsDispararMensal.php` | Emite `generated`/`sent_email`/`sent_digisac` | ✓ VERIFIED | 3 blocos `NpsSurveyEvent::create` presentes, condicionados corretamente aos branches de sucesso |
| `tests/Feature/Phase94/*.php` (5 arquivos) | Suite de testes da fase | ✓ VERIFIED | 43 testes, todos presentes e passando (ver Behavioral Spot-Checks) |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `NpsController::respond()` | `NpsSurveyEvent` | `NpsSurveyEvent::create(['event_type' => TYPE_OPENED, ...])` | WIRED | Emitido em todo GET, antes dos early-returns |
| `NpsController::submitResponseV15/Legacy()` | `NpsSuspicionService::evaluate()` | Helper `capturarRastroEAvaliarSuspeita()` → `app(NpsSuspicionService::class)->evaluate(...)` | WIRED | Resultado spreadado direto no `NpsResponse::create()` |
| `NpsController::submitResponseV15/Legacy()` | `NpsSurveyEvent` | Helper `registrarEventoSubmitted()` dentro de `DB::transaction()` | WIRED | Reverte junto com o guard 23000 (testado) |
| `NpsDispararMensal::handle()` | `NpsSurveyEvent` | 3 `NpsSurveyEvent::create()` condicionados a sucesso confirmado | WIRED | `sent_email` só após `Mail::send` sem exceção; `sent_digisac` só quando `status==='enviado'` |
| `NpsSuspicionService` | `config/nps.php` | `config('nps.anti_burlamento.*')` | WIRED | Testado com config vazia (não lança exceção) e com valores custom |

### Behavioral Spot-Checks (execução real da suite)

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Suite Phase94 completa | `php artisan test --filter=Phase94` | **43 passed (246 assertions)**, 0 falhas | ✓ PASS |
| Suite Nps completa (regressão) | `php artisan test --filter=Nps` | **250 passed (1490 assertions)**, 0 falhas | ✓ PASS |

Ambos os comandos foram executados neste processo de verificação (não copiados do SUMMARY) — resultados batem com o que os SUMMARYs 94-01/02/03 reportaram (43/43 Phase94, 250/250 Nps).

### Escopo — Verificação de Não-Vazamento

- Nenhuma alteração em `resources/js/` atribuível à Fase 94 — os 2 arquivos `.jsx` modificados no range de commits (`AdminCarteira.jsx`, `Carteiras.jsx`) pertencem a commits `8400931`/`b40ac55` (`feat(90-02)`), confirmados via `git log` como Fase 90 (trabalho paralelo, conforme aviso do usuário).
- Nenhuma lógica de bloqueio de sessão interna encontrada (`abort`/403 não associado a suspeita) — Regra 4 apenas MARCA (`is_suspicious=true`), não impede o submit. Correto para o escopo (bloqueio é Fase 96).
- Nenhuma UI de confiança/badge/filtro introduzida (Fase 95, fora de escopo) — busca por `is_suspicious`/`suspicion_reasons` fora de `app/` e `tests/` não retornou nada em `resources/js/`.
- Nenhuma configuração de IPs pela UI (Fase 96, fora de escopo) — `config/nps.php` é 100% `.env`-driven, sem controller/rota de configuração.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|--------------|--------|----------|
| AB-94-1 | 94-02 | Rastro de abertura | ✓ SATISFIED | `NpsController::respond()` linhas 548-568 |
| AB-94-2 | 94-02 | Rastro de resposta | ✓ SATISFIED | Helper `capturarRastroEAvaliarSuspeita()` linhas 749-774 |
| AB-94-3 | 94-01/02/03 | Trilha de eventos (6 tipos, todos os emissores) | ✓ SATISFIED | `NpsSurveyEvent` model + 6 pontos de emissão em `NpsController` e `NpsDispararMensal` |
| AB-94-4 | 94-01/02 | `NpsSuspicionService` (4 regras) | ✓ SATISFIED | `NpsSuspicionService::evaluate()` linhas 27-57 |
| AB-94-5 | 94-01 | Retrocompatibilidade | ✓ SATISFIED | Colunas nullable, migrations idempotentes, `NpsAntiBurlamentoBackwardCompatTest` (5/5 passando) |

Nenhum requisito órfão encontrado — todos os AB-94-1..5 mapeados no ROADMAP aparecem cobertos pelos 3 planos.

### Anti-Patterns Found

Nenhum marcador de dívida (`TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`) encontrado nos arquivos de produção da fase. As únicas ocorrências de "TODO" no texto são falsos-positivos em português ("roda em TODO GET", "TODOS os serviços") — não são marcadores de dívida técnica.

### Human Verification Required

Nenhum item requer verificação humana para o **código** desta fase (backend-only, sem UI). Um único item fica registrado como pendência **pós-deploy** (não bloqueante para o fechamento da Fase 94, documentado nos próprios SUMMARYs 94-03):

1. **Topologia de proxy do VPS (Regra 1 / IP interno)**
   - **Teste:** Após deploy autorizado, abrir um link NPS de fora da rede ECF e conferir no banco se `nps_surveys.open_ip_address` reflete o IP público real do dispositivo (não `127.0.0.1` nem IP do proxy).
   - **Esperado:** IP real do cliente, não do proxy/servidor.
   - **Por que humano:** Topologia de rede de produção não é reproduzível em teste automatizado (ausência de `trustProxies` configurado em `bootstrap/app.php`, conforme RESEARCH Pitfall 1).
   - **Nota:** Não bloqueia a Fase 94 (que é backend-only e não expõe nada em UI), mas é pré-requisito de confiabilidade da Regra 1 antes que a Fase 95 exiba os dados a usuários reais.

### Gaps Summary

Nenhum gap encontrado. Os 5 success criteria do ROADMAP e os 5 requisitos (AB-94-1..5) do CONTEXT estão implementados, testados e comprovados por execução real (não apenas por leitura de SUMMARY):

- Schema de rastro (abertura + resposta + suspeita) nullable e idempotente.
- `NpsSurveyEvent` cobrindo os 6 `event_type` com todos os emissores wired.
- `NpsSuspicionService` com as 4 regras, textos pt-BR configuráveis via `.env`.
- Payload público inalterado (testado explicitamente com contagem de chaves).
- Suites `Phase94` (43/43) e `Nps` (250/250) executadas ao vivo neste processo de verificação, 0 falhas.
- Escopo respeitado: nenhuma UI de confiança (Fase 95), nenhum bloqueio (Fase 96), nenhuma configuração de IP pela UI.

---

*Verified: 2026-07-16*
*Verifier: Claude (gsd-verifier)*
