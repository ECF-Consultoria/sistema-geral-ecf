---
phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 04
subsystem: backend
tags: [checklist-administrativo, resolver, contrato-assinatura, ml-oauth, onboarding-link]

# Dependency graph
requires:
  - phase: 139-02
    provides: "Tabela checklist_administrativo_itens (company_id) + model App\\Models\\ChecklistAdministrativoItem"
  - phase: 139-03
    provides: "App\\Contracts\\ChecklistResolver + App\\Services\\ChecklistAdministrativo\\ChecklistResolverResultado + App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoDefinicao"
provides:
  - "App\\Services\\ChecklistAdministrativo\\Resolvers\\ContratoEnviadoResolver — item 2, leitura agregada de enviado_em por serviço"
  - "App\\Services\\ChecklistAdministrativo\\Resolvers\\ContratoAssinadoResolver — item 3, OR entre ContratoAssinatura e ContratoLiberacao (D-16), agregado por serviço (D-18)"
  - "App\\Services\\ChecklistAdministrativo\\Resolvers\\MlOAuthConectadoResolver — item 7, leitura de ml_tokens.status"
  - "App\\Services\\ChecklistAdministrativo\\Resolvers\\ConexaoEcfResolver — item 8, leitura de existência de onboarding_links"
  - "Prova automatizada (D5 da milestone): nenhum dos 4 resolvers escreve em contrato_assinaturas/contrato_liberacoes/onboarding_links, nenhum dispara HTTP"
affects: [139-05, 139-06, 139-07, 139-08, 139-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Universo vazio de serviços que exigem contrato nunca resolve para concluido — sempre indeterminado com motivo, mesmo modo de falha 'vazio lido como zero' que o value object de 3 estados existe para evitar"
    - "Envelope vigente por serviço = orderByDesc('id')->first() sobre ContratoAssinatura, nunca 'todos os envelopes já criados' — evita travar o item para sempre sobre linha morta de erro/cancelado"
    - "Agregação 'manda o mais atrasado' (D-18) aplicada ENTRE serviços, nunca entre envelopes do mesmo serviço — dentro de um serviço só o vigente conta"
    - "Resolver de leitura pura que evita literalmente citar identificador de outro subsistema no docblock quando a citação criaria falso positivo no grep de auditoria do próprio plano (lição herdada do 139-02/139-03)"

key-files:
  created:
    - app/Services/ChecklistAdministrativo/Resolvers/ContratoEnviadoResolver.php
    - app/Services/ChecklistAdministrativo/Resolvers/ContratoAssinadoResolver.php
    - app/Services/ChecklistAdministrativo/Resolvers/MlOAuthConectadoResolver.php
    - app/Services/ChecklistAdministrativo/Resolvers/ConexaoEcfResolver.php
    - tests/Feature/Phase139/ChecklistGrupoContratoAutoTest.php
    - tests/Feature/Phase139/ContratoAssinadoPorLiberacaoTest.php
    - tests/Feature/Phase139/MultiplosEnvelopesTest.php
    - tests/Feature/Phase139/ChecklistGrupoEntradaAutoTest.php
  modified: []

key-decisions:
  - "D-16 implementado: ContratoAssinadoResolver fecha por OR entre (assinado_em/status=assinado do envelope vigente) e ContratoLiberacao::existeParaServico() — testado isoladamente em ContratoAssinadoPorLiberacaoTest com a via VIA_MANUAL chamada direto em EmpresaOperacionalRouter::liberarEmpresa()"
  - "D-18 implementado: os itens 2 e 3 só fecham quando TODOS os serviços que exigem contrato (D-07) chegam ao estado — as 5 combinações de MultiplosEnvelopesTest incluem o cruzamento D-16×D-18 (um serviço assinado por envelope + um fechado por liberação) e a defesa da linha morta (envelope antigo em erro não impede o vigente mais recente de fechar)"
  - "D-05 implementado: MlOAuthConectadoResolver fecha só com ml_tokens.status=active — cópia quase literal de MlTokenAtivoResolver, sem nenhuma menção literal a buildAuthUrl/MercadoLivreService no código (docblock reescrito em prosa para não colidir com o grep de auditoria do plano, mesma disciplina do 139-02/139-03)"
  - "D-14 implementado: ConexaoEcfResolver faz só OnboardingLink::where('company_id', ...)->exists() — deliberadamente NÃO chama o método de fábrica idempotente do serviço de link de onboarding (evitaria o item fechar sozinho na 1ª renderização da ficha, violando ADMIN-03); teste dedicado reconsulta o banco e afirma zero linha criada como efeito do resolver"
  - "D5 da milestone implementado: nenhum dos 4 resolvers contém save()/create(/update(/firstOrCreate/Http:: fora de comentário — verificado por grep de auditoria nos 4 arquivos e por Http::assertNothingSent() + reconsulta ao banco em ChecklistGrupoContratoAutoTest"

patterns-established:
  - "Segunda fonte de fechamento (D-16) registrada no valor do resultado ('assinatura'|'liberacao') — dá rastro na tela de POR QUE o item fechou sem envelope assinado, sem precisar reconsultar o banco para entender"

requirements-completed: [ADMIN-02, ADMIN-03]

# Metrics
duration: ~25min
completed: 2026-09-09
---

# Phase 139 Plan 04: Os 4 resolvers automáticos do checklist administrativo (itens 2, 3, 7, 8) Summary

**Os 4 resolvers automáticos do checklist — contrato enviado/assinado (com a segunda fonte da D-16 e o "manda o mais atrasado" da D-18), OAuth Mercado Livre conectado (D-05) e conexão ECF gerada (D-14) — todos leitura pura, provados por reconsulta ao banco e `Http::assertNothingSent()` a não escreverem nada em `contrato_assinaturas`, `contrato_liberacoes` ou `onboarding_links`.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-09-09T19:15:00Z (aprox., logo após o fechamento do 139-03)
- **Completed:** 2026-09-09T19:29:02Z
- **Tasks:** 3/3
- **Files modified:** 8 (todos criados novos)

## Accomplishments

- `ContratoEnviadoResolver` (item 2) — lê `contrato_assinaturas.enviado_em` do envelope vigente
  (`orderByDesc('id')->first()`) por serviço que exige contrato; `concluido` só quando TODOS os
  serviços têm envelope enviado (D-18); universo vazio nunca resolve para `concluido` — sempre
  `indeterminado`.
- `ContratoAssinadoResolver` (item 3) — OR entre envelope assinado
  (`assinado_em !== null` ou `status === STATUS_ASSINADO`) e
  `ContratoLiberacao::existeParaServico()` (D-16), agregado por serviço (D-18); o `valor` registra
  por serviço qual das duas fontes fechou (`'assinatura'`/`'liberacao'`).
- `MlOAuthConectadoResolver` (item 7) — cópia quase literal de `MlTokenAtivoResolver`, adaptada
  para `Company` direto; fecha só com `ml_tokens.status = 'active'`, nunca na geração do link
  (D-05); zero menção literal a `buildAuthUrl`/`MercadoLivreService` no arquivo (docblock reescrito
  em prosa após a 1ª tentativa acusar falso positivo no grep de auditoria).
- `ConexaoEcfResolver` (item 8) — leitura pura de existência
  (`OnboardingLink::where('company_id', ...)->exists()`), deliberadamente sem chamar o método de
  fábrica idempotente que criaria a linha sozinho a cada renderização (D-14); teste dedicado prova,
  por reconsulta ao banco, que o resolver não cria nada.
- 4 arquivos de teste Feature (`tests/Feature/Phase139/`) — 16 testes / 32 assertions, cobrindo os
  3 estados do value object para cada resolver, os 5 cruzamentos D-16×D-18 de múltiplos envelopes,
  a via manual de liberação isolada, e a defesa do efeito colateral do item 8.
- Suíte de regressão `tests/Unit/Phase139 + tests/Feature/Phase139 + tests/Unit/Phase137 +
  tests/Feature/Phase137 + tests/Feature/Phase138` rodou **151 testes / 547 assertions, 100%
  verde** — sem nenhuma regressão contra a baseline de 135/515 do 139-03 (+16 testes / +32
  assertions deste plano, exatamente os 4 arquivos novos).

## Task Commits

Each task was committed atomically:

1. **Task 1: ContratoEnviadoResolver e ContratoAssinadoResolver (itens 2 e 3)** - `efbedccf` (feat)
2. **Task 2: Testes dedicados da liberação manual (D-16) e dos múltiplos envelopes (D-18)** - `242d91e7` (test)
3. **Task 3: MlOAuthConectadoResolver e ConexaoEcfResolver (itens 7 e 8)** - `4ec544c4` (feat)

## Files Created/Modified

- `app/Services/ChecklistAdministrativo/Resolvers/ContratoEnviadoResolver.php` - item 2, leitura agregada por serviço
- `app/Services/ChecklistAdministrativo/Resolvers/ContratoAssinadoResolver.php` - item 3, OR entre assinatura e liberação (D-16)
- `app/Services/ChecklistAdministrativo/Resolvers/MlOAuthConectadoResolver.php` - item 7, leitura de `ml_tokens.status`
- `app/Services/ChecklistAdministrativo/Resolvers/ConexaoEcfResolver.php` - item 8, leitura de existência de `onboarding_links`
- `tests/Feature/Phase139/ChecklistGrupoContratoAutoTest.php` - 4 casos + asserção D5 (nada escrito em `contrato_assinaturas`)
- `tests/Feature/Phase139/ContratoAssinadoPorLiberacaoTest.php` - prova isolada da via manual (D-16)
- `tests/Feature/Phase139/MultiplosEnvelopesTest.php` - as 5 combinações de 2+ serviços com contrato (D-18)
- `tests/Feature/Phase139/ChecklistGrupoEntradaAutoTest.php` - 6 casos dos itens 7 e 8, incluindo defesa do efeito colateral

## Decisions Made

- **D-16 (segunda fonte do item 3):** implementado exatamente como travado no CONTEXT — OR entre
  envelope assinado e `ContratoLiberacao::existeParaServico()`, com teste isolado provando que a
  via `manual` não deixa rastro em `contrato_assinaturas`.
- **D-18 (múltiplos envelopes, "manda o mais atrasado"):** aplicado ENTRE serviços (não entre
  envelopes do mesmo serviço) — dentro de um serviço, só o envelope vigente (mais recente por id)
  conta; a linha morta em `erro`/`cancelado` nunca trava o item.
- **D-05 (item 7 só fecha com conexão real):** cópia quase literal do resolver análogo do
  Onboarding, sem gerar link nem citar literalmente identificadores de outro subsistema no
  docblock — ajuste feito durante a Task 3 ao rodar o grep de auditoria (ver Deviations).
- **D-14 (item 8 fecha por existência, não por clique):** o resolver só lê; quem cria a linha é a
  ação explícita do usuário no plano 139-08, não este resolver.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] Docblock de `MlOAuthConectadoResolver` citava literalmente `buildAuthUrl`/`MercadoLivreService`, violando a acceptance criteria da própria Task 3**
- **Found during:** Task 3, ao rodar a checagem `grep -c "buildAuthUrl"` / `grep -c "MercadoLivreService"` sobre o arquivo recém-criado
- **Issue:** O primeiro rascunho do docblock explicava, corretamente, que o resolver NÃO gera link nem chama `MercadoLivreService::buildAuthUrl()` — mas citar o identificador proibido literalmente, mesmo para dizer "não usa", faz o grep de auditoria (que busca 0 ocorrências) encontrar um falso positivo. Mesma armadilha já registrada nos planos 139-02 e 139-03 e citada explicitamente nas `environment_notes` deste plano.
- **Fix:** Reescrita a passagem para descrever o comportamento sem repetir os identificadores (“nem monta a URL de OAuth” / “o endpoint de iniciar conexão ML por empresa, que já existe”), preservando o sentido explicativo.
- **Files modified:** `app/Services/ChecklistAdministrativo/Resolvers/MlOAuthConectadoResolver.php`
- **Verification:** `grep -c "buildAuthUrl"` e `grep -c "MercadoLivreService"` retornam 0; suíte da Task 3 permaneceu verde.
- **Committed in:** `4ec544c4` (Task 3) — corrigido antes do commit, nenhum commit adicional gerado.

---

**Total deviations:** 1 auto-fixed (1 bloqueio de verificação, sem mudança de comportamento)
**Impact on plan:** Ajuste redacional puro — o comportamento do resolver não mudou, só o texto do docblock. Nenhum scope creep.

## Issues Encountered

Nenhum outro. As duas primeiras tasks seguiram a `<action>` do plano na letra; a única correção
necessária foi a do docblock descrita acima, encontrada e corrigida dentro da própria Task 3 antes
do commit.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Os 4 resolvers são leitura pura de
tabelas/colunas já existentes no banco local.

## Next Phase Readiness

- Os 4 resolvers automáticos (`ContratoEnviadoResolver`, `ContratoAssinadoResolver`,
  `MlOAuthConectadoResolver`, `ConexaoEcfResolver`) estão prontos para o plano 139-05
  (`ChecklistAdministrativoService`) consumir via `ChecklistAdministrativoDefinicao::item($chave)-
  >auto_fonte` — nenhum precisa de injeção de dependência (construtor vazio nos 4).
- Nenhum bloqueio identificado para o plano 139-05. A régua "manda o mais atrasado" (D-18) e a
  segunda fonte de liberação (D-16) já estão provadas isoladamente — o service de orquestração não
  precisa reimplementar nenhuma delas, só chamar `resolver($company)` e aplicar o resultado.

---
*Phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-09*

## Self-Check: PASSED

- FOUND: `app/Services/ChecklistAdministrativo/Resolvers/ContratoEnviadoResolver.php`
- FOUND: `app/Services/ChecklistAdministrativo/Resolvers/ContratoAssinadoResolver.php`
- FOUND: `app/Services/ChecklistAdministrativo/Resolvers/MlOAuthConectadoResolver.php`
- FOUND: `app/Services/ChecklistAdministrativo/Resolvers/ConexaoEcfResolver.php`
- FOUND: `tests/Feature/Phase139/ChecklistGrupoContratoAutoTest.php`
- FOUND: `tests/Feature/Phase139/ContratoAssinadoPorLiberacaoTest.php`
- FOUND: `tests/Feature/Phase139/MultiplosEnvelopesTest.php`
- FOUND: `tests/Feature/Phase139/ChecklistGrupoEntradaAutoTest.php`
- FOUND commit: `efbedccf`
- FOUND commit: `242d91e7`
- FOUND commit: `4ec544c4`
