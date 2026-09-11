---
phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 01
subsystem: config
tags: [phpunit, laravel-config, requirements-doc, adman]

# Dependency graph
requires:
  - phase: 150-maquina-de-estados-etapa-v23-0
    provides: "EtapaTransicaoService + baseline de testes 137"
  - phase: 151-rea-comercial-conectada-etapa-v23-0
    provides: "Contrato/Entrada na Área Comercial + baseline de testes 138"
provides:
  - "152-BASELINE-TESTES.md — baseline verde (123 testes, 444 assertions) capturada antes de qualquer código da fase 152"
  - "config('services.adman.register_url') — link fixo de cadastro do Adman, configurável sem deploy"
  - "Bloco ADMAN_* documentado em .env.example pela primeira vez (BASE_URL, API_KEY, REGISTER_URL)"
  - "REQUIREMENTS-v23.md — exceções D-06/D-16 ao ADMIN-02 e correção D-04 ao ADMIN-03, registradas por escrito"
affects: [152-02, 152-03, 152-04, 152-05, 152-06, 152-07, 152-08, 152-09, 152-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Chave de config configurável com default seguro (env('X', 'valor-fixo')) — mesmo padrão do bloco hubspot da Fase 151, aplicado ao Adman"
    - "Nota recuada (blockquote `>` sob o item `- [ ] **REQ-ID**`) para registrar exceção/correção sem reescrever o texto original do requisito"

key-files:
  created:
    - .planning/phases/152-checklist-administrativo-trava-de-finaliza-o-v23-0/152-BASELINE-TESTES.md
    - tests/Unit/Phase152/AdmanRegisterUrlConfigTest.php
  modified:
    - config/services.php
    - .env.example
    - .planning/REQUIREMENTS-v23.md

key-decisions:
  - "D-04 implementado: services.adman.register_url é o link fixo https://app.ad-man.io/register?ref=588D0DD78C4F, trocável só via .env na VPS, sem deploy"
  - "D-19 implementado: D-06 e D-16 registrados como nota recuada abaixo do ADMIN-02 no REQUIREMENTS-v23.md, sem reescrever o texto original"
  - "Correção D-04/D-05/D-14 registrada abaixo do ADMIN-03: grafia Adman (não ADMA), link fixo/manual, só itens 7 e 8 auto-marcam"
  - "ADMAN_BASE_URL e ADMAN_API_KEY documentadas em .env.example junto com REGISTER_URL, mesmo já sendo lidas antes desta fase — lacuna pré-existente fechada de propósito, API_KEY deixada vazia"

patterns-established:
  - "Baseline de testes por fase segue o mesmo formato/rigor do 151-BASELINE-TESTES.md: comando exato (duas grafias), resultado literal, suítes fora do gate com razão, advertência de ambiente"

requirements-completed: [ADMIN-02, ADMIN-03]

# Metrics
duration: 9min
completed: 2026-09-09
---

# Phase 152 Plan 01: Baseline + config do Adman + registro de exceções nos requirements Summary

**Baseline verde de 137/138 capturada, `config('services.adman.register_url')` publicado com link fixo testado, e as duas exceções ao ADMIN-02 (D-06/D-16) mais a correção do ADMIN-03 (D-04) escritas no REQUIREMENTS-v23.md sem tocar no texto original dos requisitos.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-09-09T18:29:11Z
- **Completed:** 2026-09-09T18:38:23Z
- **Tasks:** 3/3
- **Files modified:** 5 (2 criados novos, 3 modificados)

## Accomplishments

- `152-BASELINE-TESTES.md` registra baseline **100% verde** (123 testes, 444 assertions) das suítes `tests/Unit/Phase150` + `tests/Feature/Phase150` + `tests/Feature/Phase151`, capturada antes de qualquer código novo da fase — a referência que os planos 152-02 em diante usam para distinguir falha nova de falha herdada.
- `config/services.php` ganhou a terceira chave do array `adman` já existente (`register_url`), com `env('ADMAN_REGISTER_URL', 'https://app.ad-man.io/register?ref=588D0DD78C4F')` — o mesmo padrão de configurável-sem-deploy que a Fase 151 usou para o HubSpot.
- `.env.example` ganhou o primeiro bloco `ADMAN_*` documentado do projeto — as três chaves, com `ADMAN_API_KEY` deixada vazia por segurança (nunca a chave real versionada).
- `tests/Unit/Phase152/AdmanRegisterUrlConfigTest.php` prova por teste automatizado que o default é exatamente o link fixo e que a URL não contém nenhum placeholder por empresa (`{company}`, `company_id`, `:id`).
- `.planning/REQUIREMENTS-v23.md` recebeu duas notas recuadas — abaixo do ADMIN-02 (as exceções D-06 e D-16) e abaixo do ADMIN-03 (a correção de nomenclatura Adman/ADMA e de premissa sobre geração do link) — sem alterar uma letra do texto original dos dois requisitos.

## Task Commits

Each task was committed atomically:

1. **Task 1: Capturar a baseline de testes das Fases 150/138** - `35f9d193` (docs)
2. **Task 2: Publicar o link fixo do Adman como configuração (D-04)** - `c0b68597` (feat)
3. **Task 3: Registrar as exceções ao ADMIN-02 (D-19) e a correção do ADMIN-03 (D-04)** - `a83c7706` (docs)

_Nenhuma task teve TDD — Task 2 escreveu teste e implementação no mesmo commit por decisão de escopo (config + prova, sem comportamento a testar em RED antes)._

## Files Created/Modified

- `.planning/phases/152-checklist-administrativo-trava-de-finaliza-o-v23-0/152-BASELINE-TESTES.md` - baseline de testes 137/138, 100% verde, com as suítes de Polos explicitamente fora do gate
- `config/services.php` - nova chave `register_url` no array `adman` existente
- `.env.example` - bloco `ADMAN_*` (3 chaves) documentado pela primeira vez
- `tests/Unit/Phase152/AdmanRegisterUrlConfigTest.php` - prova do link fixo e da ausência de placeholder por empresa
- `.planning/REQUIREMENTS-v23.md` - notas D-06/D-16 sob o ADMIN-02 e nota D-04/D-05/D-14 sob o ADMIN-03

## Decisions Made

- **D-04 (Adman):** o link de cadastro é fixo e mora em config, não em banco — trocar o `ref` em produção vira mudança de `.env` na VPS, sem deploy, seguindo o precedente do bloco `hubspot` da Fase 151.
- **Documentar ADMAN_BASE_URL e ADMAN_API_KEY junto com REGISTER_URL em `.env.example`:** as duas primeiras já eram lidas pelo código desde antes desta fase e nunca estiveram documentadas (lacuna pré-existente); documentá-las junto custa uma linha cada e evita o bloco novo mentir por omissão sobre o que o Adman precisa. `ADMAN_API_KEY` ficou vazia — nunca a credencial real versionada (mitigação T-152-01-02 do threat model do plano).
- **Formato da nota de exceção/correção no REQUIREMENTS-v23.md:** blockquote (`>`) recuado imediatamente abaixo da linha `- [ ] **ADMIN-NN**`, no mesmo estilo para as duas notas — preserva o texto original do requisito intacto e deixa a exceção/correção visualmente subordinada a ele, sem inventar uma seção nova no documento.

## Deviations from Plan

None - plan executado exatamente como escrito. As três tasks seguiram a `<action>` do plano na letra; nenhuma decisão arquitetural nova foi necessária (Rule 4 não se aplicou), e não houve bug, funcionalidade crítica ausente nem bloqueio a corrigir (Rules 1-3 não se aplicaram).

## Issues Encountered

Um erro de sintaxe do `git commit -- <path> -m "..."` na Task 1 (a flag `-m` colocada depois de `--` foi interpretada como pathspec, e o arquivo ainda não estava no índice do git por ser novo/untracked). Corrigido imediatamente reordenando para `git add -- <path>` seguido de `git commit -m "..." -- <path>`, sem impacto no conteúdo do commit final. Não é deviation de código — é operação de commit, documentada aqui por transparência.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. A chave `ADMAN_REGISTER_URL` tem default seguro em código; só precisa de valor real no `.env` da VPS se o código de referência (`ref=588D0DD78C4F`) precisar trocar no futuro — e isso é edição de `.env`, não deploy.

## Next Phase Readiness

- A baseline de 137/138 (123/123 verde) está registrada e disponível para os planos 152-02 em diante compararem regressão.
- `config('services.adman.register_url')` está pronto para o item 6 do checklist (D-04) — os planos seguintes só precisam ler essa chave, nunca gerar a URL.
- `REQUIREMENTS-v23.md` está correto e completo para o escopo do ADMIN-02/ADMIN-03; nenhum plano futuro precisa reabrir essas duas notas.
- Nenhum bloqueio identificado para o plano 152-02.

---
*Phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-09*

## Self-Check: PASSED

- FOUND: `.planning/phases/152-checklist-administrativo-trava-de-finaliza-o-v23-0/152-BASELINE-TESTES.md`
- FOUND: `tests/Unit/Phase152/AdmanRegisterUrlConfigTest.php`
- FOUND commit: `35f9d193`
- FOUND commit: `c0b68597`
- FOUND commit: `a83c7706`
