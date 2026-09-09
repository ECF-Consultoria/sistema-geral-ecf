---
phase: 138-rea-comercial-conectada-etapa-v23-0
plan: 09
subsystem: comercial
tags: [hubspot, inertia, react, checkpoint-humano, testes, laravel]

# Dependency graph
requires:
  - phase: 138-07
    provides: conta de sistema "Sistema HubSpot" (D-17) e nascimento na etapa 1 nas duas portas
  - phase: 138-08
    provides: navegação reorganizada — Entrada.jsx, Contrato movido para o Comercial, admin.empresas fora do menu
provides:
  - Suíte da fase 138 comparada item a item com a baseline pré-migration (138-01), sem regressão
  - Conta de sistema "Sistema HubSpot" verificada como não-logável no ambiente local e registrada por escrito
  - Render real das telas Comercial/Entrada e Admin/Contratos verificado no navegador por um humano
  - Roteiro completo e explícito para os 3 itens pendentes da VPS, carregado no SUMMARY para quem fizer o deploy
affects: [139-checklist-administrativo]

# Tech tracking
tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified:
    - .planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-CONTA-SISTEMA-HUBSPOT.md

key-decisions:
  - "Task 2, passo 3: usuário dispensou a tentativa manual de login pela tela e aceitou o teste automatizado ComercAtorSistemaTest (4 senhas óbvias via Auth::attempt) como prova de não-logabilidade — prova mais forte e repetível, mas literalmente diferente da pedida no how-to-verify original"
  - "Task 2, passo 7: escopo OAuth crm.objects.owners.read fica NÃO CONFIRMADO por decisão do usuário ('deixar anotada para o deploy') — pendência formal da VPS, não falha de segurança (degrada para campo vazio, sem erro)"
  - "Task 3: nenhuma correção de código foi necessária — as duas 'esquisitices' encontradas na verificação (fixtures de teste ausentes da tela Contrato, e maioria das empresas legadas com etapa NULL) são comportamento correto por desenho (universo por contrato ativo, e D-03), não bugs"

patterns-established: []

requirements-completed: [COMERC-01, COMERC-02, COMERC-03]

# Metrics
duration: ~90min (inclui espera de aprovação humana nos dois checkpoints; ver nota abaixo)
completed: 2026-09-09
---

# Phase 138 Plan 09: Checkpoints Finais da Fase Summary

**Fase 138 fechada (9/9 planos): suíte comparada contra a baseline sem regressão nova, conta de sistema "Sistema HubSpot" comprovadamente não-logável e registrada, e as telas Comercial/Entrada e Admin/Contratos verificadas no navegador por um humano — nenhum deploy executado.**

## Performance

- **Duração:** Task 1 e Task 2 executadas e commitadas em sessão anterior; esta sessão (continuação) fechou a Task 3, reexecutou a suíte de verificação e escreveu este SUMMARY.
- **Task 1:** commit `8df94867`, 2026-09-09 09:28
- **Task 2 (checkpoint aprovado):** commit `883479d5`, 2026-09-09 09:36
- **Task 3 (checkpoint aprovado, fechado nesta sessão):** commit `30f39ab2`, 2026-09-09 10:55
- **Tasks:** 3/3 completas
- **Files modified:** 1 (`138-CONTA-SISTEMA-HUBSPOT.md`, editado nas três tasks)

## Accomplishments

- Suíte da fase (`tests/Unit/Phase138 tests/Feature/Phase138 tests/Feature/Phase131 tests/Unit/Phase137 tests/Feature/Phase137`) reexecutada nesta sessão: **252 tests, 921 assertions, OK, exit code 0** — sem regressão contra o que a Task 1 já havia medido item a item na baseline (`138-BASELINE-TESTES.md`)
- Conta de sistema `sistema.hubspot@ecfconsultoria.com.br` (id local **49**) confirmada não-logável por reconsulta ao banco + `ComercAtorSistemaTest` (5 tests, 26 assertions, `Auth::attempt` falha em 4 senhas óbvias, `Hash::check` falso) — checkpoint aprovado pelo usuário
- Render real de `/comercial/entrada` e `/administrativo/contratos` verificado no navegador via screenshots do usuário: os 8 campos mínimos do §2 presentes, pendência do fluxo e pendências do cadastro em colunas visualmente separadas nas duas telas, fronteira D-07 confirmada nos dois sentidos (empresas de etapa 5, ids 412/417, existem no banco e não aparecem na Entrada), e a listagem Contrato comprovadamente **sem** corte por etapa (empresa `asdadassdsad`, id 55, `em_operacao`, segue visível)
- Nenhum item de checklist, botão FINALIZAR ou placeholder da Fase 139 apareceu em nenhuma das duas telas — a casca entregue é mesmo casca (D-01/D-06)
- Fase 138 fechada por completo: 9/9 planos, COMERC-01/02/03 marcados em `REQUIREMENTS-v23.md`

## Task Commits

Cada task foi commitada individualmente (Tasks 1 e 2 em sessão anterior; Task 3 nesta sessão):

1. **Task 1: Suíte da fase comparada com a baseline pré-migration** — `8df94867` (docs)
2. **Task 2: Conta "Sistema HubSpot" não-logável em local e na VPS** — `883479d5` (docs, checkpoint aprovado)
3. **Task 3: Render das listagens Contrato e Entrada e da navegação reorganizada** — `30f39ab2` (docs, checkpoint aprovado)

**Plan metadata:** commit deste SUMMARY + STATE/ROADMAP (a seguir, nesta mesma sessão)

_Nota: as três tasks deste plano são `docs` porque nenhuma delas produz código novo — Task 1 mede e registra, Tasks 2 e 3 são checkpoints humanos cujo artefato é o próprio registro da verificação._

## Files Created/Modified

- `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-CONTA-SISTEMA-HUBSPOT.md` — recebeu, ao longo das três tasks: a tabela suíte × baseline (Task 1), a resolução do checkpoint da conta de sistema com os passos 3 e 7 registrados como o usuário de fato respondeu (Task 2), e o registro completo da verificação visual das duas telas com a distinção entre o que foi visto e o que foi inferido (Task 3, esta sessão)

## Decisions Made

- **Task 2, passo 3:** o usuário dispensou a tentativa manual de login pela tela ("Aprovar pelo teste automatizado") — a não-logabilidade fica provada por `ComercAtorSistemaTest` (4 senhas testadas via `Auth::attempt`, mais forte e repetível que as 3 pedidas originalmente no `how-to-verify`, mas literalmente diferente do que foi pedido). Registrado sem suavizar: nenhuma tentativa de login foi feita na tela do navegador.
- **Task 2, passo 7:** o usuário respondeu "Deixar anotada para o deploy" para o escopo `crm.objects.owners.read` — `escopo_owners_read:` permanece `NÃO CONFIRMADO`, carregado como pendência obrigatória da VPS (ver seção abaixo).
- **Task 3:** nenhuma correção de código foi necessária. Duas coisas que pareciam defeito durante a verificação foram explicadas e confirmadas como comportamento correto por desenho: (1) as empresas `[TESTE 138-09]` não aparecem na listagem Contrato porque têm zero serviços contratados — o universo da Contrato é estado de contrato, não etapa; (2) 179 das 190 empresas locais têm `etapa` NULL por decisão D-03 (`nullable()` sem `default()`, documentado no cabeçalho da migration), mostradas como "Sem etapa (legado)".
- A conferência de barra lateral e do acesso por `admin.contratos` isolado (passos 1, 2 e 10 do `how-to-verify` da Task 3) não tiveram relato item-a-item do usuário — ele aprovou o checkpoint inteiro depois de ver as duas telas. A cobertura dessas duas regras específicas é automatizada (`ComercNavegacaoReorganizadaTest`, `ComercEntradaPermissaoRotaTest`, ambos verdes) e está registrada como tal, não como verificação visual feita.

## Deviations from Plan

None (Rule 1-4) — plano executado exatamente como escrito. As duas adaptações acima (Task 2 passo 3, Task 3 passos 1/2/10) são decisões do usuário sobre **como** verificar, não desvios de execução autônoma, e estão documentadas na letra em `138-CONTA-SISTEMA-HUBSPOT.md`.

## Issues Encountered

None que exigissem correção de código. As duas aparentes inconsistências da Task 3 (fixtures ausentes da tela Contrato; maioria das empresas com etapa NULL) foram investigadas e confirmadas como comportamento correto — ver "Decisions Made" acima.

## Pendências obrigatórias para a VPS (ler antes de autorizar o deploy desta fase)

Nenhum destes itens foi executado — são a lista completa e única do que precisa acontecer na
sessão de deploy, com autorização explícita do usuário. Detalhamento completo em
`138-CONTA-SISTEMA-HUBSPOT.md` § "Roteiro completo para a VPS" e em `138-HUBSPOT-MEDICOES.md`.

1. **Criar a conta de sistema na VPS.** `php artisan hubspot:criar-usuario-sistema --apply` na
   VPS, anotar o `id` daquele ambiente (será diferente do `id_local=49`) e apontar
   `HUBSPOT_WEBHOOK_USER_ID` do `.env` da VPS para ele. **Sem isso, o webhook em produção cria a
   empresa normalmente, mas ela nunca ganha etapa — degrada em silêncio, só com log, sem erro
   visível em tela nenhuma.**
2. **Medir o nome interno real da property de owner.** `php artisan hubspot:inspect-properties --objects=deals`
   na VPS (impossível medir localmente — este worktree não tem `HUBSPOT_ACCESS_TOKEN`). Se o
   `name` da property de owner do deal vier diferente de `hubspot_owner_id`, setar
   `HUBSPOT_PROP_DEAL_OWNER_ID=<nome_real>` no `.env` da VPS — sem mudança de código, só
   `config:clear` (ou reiniciar o worker/supervisor se o config estiver cacheado).
3. **Confirmar o escopo `crm.objects.owners.read` no Private App de produção.** Painel HubSpot →
   Settings → Integrations → Private Apps → app do ECF Admin → aba Scopes. Se marcar e o HubSpot
   rotacionar o token, atualizar `HUBSPOT_ACCESS_TOKEN` no `.env` da VPS antes de considerar
   resolvido. **Sintoma se ficar pendente:** coluna "Responsável comercial" das listagens Contrato
   e Entrada permanentemente vazia em produção, sem erro visível em lugar nenhum.

Além disso: **item 4 do roteiro (não é pendência de configuração, é disciplina operacional)** —
a conta `sistema.hubspot@ecfconsultoria.com.br` precisa entrar em toda auditoria/revisão de
usuários daqui pra frente. Precedente direto: o usuário de review da Shopee (`users.id=30`) segue
ativo em produção desde 2026-07-16 porque ninguém escreveu que ele precisava sair.

**Fixtures locais não removidas (fora do escopo deste plano):** ids **408-417**, 10 empresas
`[TESTE 138-09]` (duas por etapa, 1 a 5), criadas via `EtapaTransicaoService` para a verificação
da Task 3. Seguem no banco local.

## User Setup Required

None — nenhuma configuração de serviço externo é necessária no ambiente local. As 3 pendências
acima são exclusivamente da VPS, na sessão de deploy.

## Next Phase Readiness

- Fase 138 **completa (9/9 planos)** — COMERC-01, COMERC-02 e COMERC-03 fechados.
- Fase 139 (checklist administrativo + trava de finalização) pode iniciar: a casca entregue por
  esta fase (navegação, duas listagens, nascimento na etapa 1) está confirmada como casca de
  verdade — nenhuma das duas telas antecipou item de checklist ou botão FINALIZAR.
- Nenhum bloqueio técnico para a Fase 139. O único bloqueio real é de produto/operacional: o
  deploy desta fase (138) ainda não foi autorizado, e as 3 pendências da VPS acima precisam ser
  resolvidas nessa sessão, antes ou junto do deploy.

---
*Phase: 138-rea-comercial-conectada-etapa-v23-0*
*Completed: 2026-09-09*

## Self-Check: PASSED

- FOUND: commit `8df94867` (Task 1)
- FOUND: commit `883479d5` (Task 2)
- FOUND: commit `30f39ab2` (Task 3)
- FOUND: `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-CONTA-SISTEMA-HUBSPOT.md`
- FOUND: `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-09-SUMMARY.md`
- FOUND: COMERC-01/02/03 marcados `[x]` em `.planning/REQUIREMENTS-v23.md`
- CONFIRMED: `tests/Feature/Phase138 tests/Feature/Phase131` → 187 tests, 746 assertions, OK, exit 0 (reexecutado nesta sessão)
- CONFIRMED: `tests/Unit/Phase138 tests/Feature/Phase138 tests/Feature/Phase131 tests/Unit/Phase137 tests/Feature/Phase137` → 252 tests, 921 assertions, OK, exit 0 (reexecutado nesta sessão)
