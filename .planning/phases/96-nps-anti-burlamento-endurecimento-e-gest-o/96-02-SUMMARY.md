---
phase: 96-nps-anti-burlamento-endurecimento-e-gest-o
plan: 02
subsystem: nps
tags: [laravel, inertia, react, phpunit, tdd, ip-validation, admin-config]

# Dependency graph
requires:
  - phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
    provides: "NpsSuspicionService::evaluate()/isInternalIp() lendo IPs/CIDRs de config/nps.php (.env)"
  - phase: 96-nps-anti-burlamento-endurecimento-e-gest-o (plan 01)
    provides: "Nada consumido diretamente — planos 96-01/96-02 são independentes (só compartilham o phase dir)"
provides:
  - "PATCH /nps/configuracao/ips-internos admin-only — persiste IPs exatos + CIDRs internos via Configuracao (JSON), com validação FILTER_VALIDATE_IP + regex CIDR"
  - "NpsSuspicionService::isInternalIp() lê a UNIÃO (.env ∪ Configuracao/UI) — .env nunca é substituído, só somado"
  - "IpsInternosWidget em Nps/Configuracao.jsx — chips editáveis (adicionar/remover) para IPs e CIDRs, molde DiaCobrancaWidget"
affects: [96-03-invalidacao-manual]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Configuracao (key/valor) reutilizada para mais uma chave JSON-array — mesmo padrão de nps_dia_cobranca/nps_textos, agora com 2 chaves (nps_internal_ips/nps_internal_cidrs)"
    - "União (∪) de fonte .env + UI dentro de um private method do service, sem alterar a assinatura pública evaluate() — molde reaproveitável se outra config precisar do mesmo tratamento fallback+UI"
    - "Flags de erro por item de lista (chip) computadas DENTRO do callback do .map() — não fora, para evitar ReferenceError no bundle Rollup (pitfall documentado no CONTEXT)"

key-files:
  created:
    - tests/Feature/Phase96/NpsConfiguracaoIpsInternosTest.php
  modified:
    - app/Services/Nps/NpsSuspicionService.php
    - app/Http/Controllers/NpsController.php
    - app/Http/Controllers/NpsTemplateController.php
    - routes/web.php
    - resources/js/Pages/Nps/Configuracao.jsx
    - tests/Feature/Phase94/NpsSuspicionServiceTest.php

key-decisions:
  - "Validação de IP via filter_var(FILTER_VALIDATE_IP) (aceita IPv4 e IPv6) e CIDR via regex estrita IPv4/prefixo — nunca persistir string livre não validada em Configuracao (T-96-05 do threat_model)"
  - "abort_unless(isAdmin(), 403) no controller MESMO a rota já vivendo no grupo role:admin — defesa em profundidade (T-96-04), mesmo padrão dos outros endpoints admin-only do módulo NPS"
  - "IpsInternosWidget renderizado lado a lado com DiaCobrancaWidget num grid 2 colunas (só no modo 'list') — evita alongar ainda mais a tela de configuração"
  - "json_decode(...) ?: [] como fallback defensivo tanto no service quanto no controller/props — nunca deixa um valor corrompido/ausente em Configuracao quebrar a leitura"

requirements-completed: [AB-96-2]

# Metrics
duration: ~35min (+ ~20min de suítes de regressão em background)
completed: 2026-07-17
---

# Phase 96 Plan 02: IPs/CIDRs Internos Configuráveis pela UI (AB-96-2) Summary

**Endpoint PATCH admin-only + widget de chips em Nps/Configuracao.jsx tornam os IPs/CIDRs internos da ECF editáveis sem deploy — `NpsSuspicionService::isInternalIp()` passa a ler a UNIÃO (.env ∪ Configuracao) sem alterar a assinatura pública `evaluate()`.**

## Performance

- **Duration:** ~35 min de implementação (+ ~20 min de suítes `--filter=Nps` completas rodadas em background para confirmar baseline e regressão)
- **Started:** 2026-07-17 (commit RED `837b9fe`)
- **Completed:** 2026-07-17 (commit GREEN Task 2 `4b7e24e`)
- **Tasks:** 2 completed
- **Files modified:** 7 (1 criado, 6 modificados)

## Accomplishments
- `NpsSuspicionService::isInternalIp()` soma (∪) `json_decode(Configuracao::get('nps_internal_ips'/'nps_internal_cidrs', '[]'), true) ?: []` ao `array_merge` existente das chaves `.env` (`config('nps.anti_burlamento.internal_ips'/'internal_cidrs')`) — `evaluate()` permanece com a mesma assinatura pública, sem quebrar nenhum call-site
- `NpsController::atualizarIpsInternos()` — admin-only (`abort_unless` + middleware `role:admin`), valida `ips.*` com `filter_var(FILTER_VALIDATE_IP)` e `cidrs.*` com regex CIDR estrita, persiste os 2 arrays como JSON em `Configuracao`
- Rota `PATCH /nps/configuracao/ips-internos` (`nps.configuracao.ips-internos.update`) registrada no mesmo grupo `role:admin` de `nps.configuracao.dia-cobranca.update`, antes da rota pública `/nps/{token}`
- `NpsTemplateController::index()` passa `ips_internos`/`cidrs_internos` (decodificados de `Configuracao`) como props para `Inertia::render('Nps/Configuracao', ...)`
- `IpsInternosWidget` em `resources/js/Pages/Nps/Configuracao.jsx` — molde exato do `DiaCobrancaWidget`: `useForm`, chips com adicionar/remover (Enter ou botão), erros de validação por item, renderizado lado a lado com `DiaCobrancaWidget` num grid 2 colunas no modo `'list'`
- `npm run build` (`npx vite build`) executado com sucesso (exit 0, ~3min21s) — `Configuracao-DZFonAUJ.js` presente no manifest
- Suíte `Phase96/NpsConfiguracaoIpsInternosTest` 4/4 verde: persistência de IPs+CIDRs válidos, rejeição de IP inválido (`ips.0`), rejeição de CIDR mal-formado (`cidrs.0`), 403 não-admin
- `Phase94/NpsSuspicionServiceTest` 10/10 verde (9 pré-existentes + 1 novo cenário provando a UNIÃO: IP e CIDR cadastrados SÓ na `Configuracao`, ausentes do `.env`, disparam a Regra 1)
- Baseline completo `--filter=Nps`: **274/274 passando** (269 anterior + 5 novos deste plano), 0 falhas

## Task Commits

Each task was committed atomically (TDD RED → GREEN):

1. **Task 1: Endpoint PATCH admin-only + rota + leitura efetiva no NpsSuspicionService** - `837b9fe` (test, RED) → `6b67cb5` (feat, GREEN)
2. **Task 2: Widget de IPs internos no Configuracao.jsx + props do controller** - `4b7e24e` (feat)

## Files Created/Modified
- `tests/Feature/Phase96/NpsConfiguracaoIpsInternosTest.php` - suíte completa AB-96-2 (persistência, validação IP/CIDR, 403) (novo)
- `app/Services/Nps/NpsSuspicionService.php` - `isInternalIp()` soma as chaves `nps_internal_ips`/`nps_internal_cidrs` da `Configuracao` ao `array_merge` do `.env`
- `app/Http/Controllers/NpsController.php` - `atualizarIpsInternos()` (PATCH admin-only, validação + persistência JSON)
- `app/Http/Controllers/NpsTemplateController.php` - `index()` passa `ips_internos`/`cidrs_internos` como props
- `routes/web.php` - rota `nps.configuracao.ips-internos.update` no grupo `role:admin`
- `resources/js/Pages/Nps/Configuracao.jsx` - `IpsInternosWidget` (chips editáveis) + grid 2 colunas com `DiaCobrancaWidget`
- `tests/Feature/Phase94/NpsSuspicionServiceTest.php` - novo cenário "IP cadastrado só pela UI" provando a união

## Decisions Made
- Validação de IP via `filter_var(FILTER_VALIDATE_IP)` (aceita IPv4/IPv6) e CIDR via regex estrita `^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$` — nunca persiste string livre não validada (T-96-05)
- `abort_unless($request->user()?->isAdmin(), 403)` como defesa em profundidade mesmo com o middleware `role:admin` já protegendo a rota (T-96-04), consistente com o resto do módulo NPS
- Widget renderizado lado a lado com `DiaCobrancaWidget` num `grid grid-cols-1 lg:grid-cols-2` — evita alongar verticalmente a tela de configuração, mantém os dois widgets globais visíveis juntos
- Flags de erro por chip (`temErro`) calculadas DENTRO do callback do `.map()` — segue à risca o pitfall documentado no CONTEXT (`feedback_rollup_map_scope_bug`) para não repetir o bug de escopo do Rollup

## Deviations from Plan

None - plan executado exatamente como escrito.

## Issues Encountered
Nenhum bloqueio. `npx vite build` e as duas rodadas completas de `php artisan test --filter=Nps` (baseline 269 + regressão final 274) foram executadas em background pelo tempo de duração (build ~3min21s, suíte completa ~13min) — sem impacto no resultado, só no tempo de wall-clock da sessão.

## User Setup Required
None — nenhuma configuração de serviço externo necessária. A migration de `Configuracao` já existe desde fases anteriores (tabela `configuracoes` genérica key/valor); nenhuma migration nova neste plano.

## Next Phase Readiness
AB-96-2 completo e testado. `NpsSuspicionService` já expõe a leitura em união (.env ∪ UI) que outras extensões futuras de config anti-burlamento podem seguir como molde. Pronto para:
- **Plano 96-03 (AB-96-3)**: invalidação manual de resposta — independente deste plano, não consome nada aqui além do módulo NPS já existente
- Admin agora pode ajustar IPs/CIDRs internos direto pela tela `/nps/configuracao` sem deploy — a pendência deixada na Fase 94 (`.env`-only) está resolvida

Nenhum bloqueio identificado.

## Self-Check: PASSED

Arquivo criado confirmado em disco:
- `tests/Feature/Phase96/NpsConfiguracaoIpsInternosTest.php` — FOUND

Commits confirmados via `git log --oneline`:
- `837b9fe` (test, RED) — FOUND
- `6b67cb5` (feat, GREEN Task 1) — FOUND
- `4b7e24e` (feat, Task 2) — FOUND

---
*Phase: 96-nps-anti-burlamento-endurecimento-e-gest-o*
*Completed: 2026-07-17*
