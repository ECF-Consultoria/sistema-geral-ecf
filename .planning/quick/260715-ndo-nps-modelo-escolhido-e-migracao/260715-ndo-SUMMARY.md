---
quick_id: 260715-ndo
subsystem: nps
tags: [nps, laravel, inertia, backend, console-command, snapshot]

# Dependency graph
requires:
  - phase: 79-nps-multi-modelo-disparo-por-servicos-cobertos-snapshot-de-atribuicoes
    provides: "NpsSnapshotService::registrar() + tabelas de snapshot (nps_response_scores/covered_services/score_assignments)"
  - phase: 81-nps-config-ux
    provides: "Modal 'Gerar link' modelo-first, oferecendo o seletor de template_id para QUALQUER usuário autorizado (não só admin)"
provides:
  - "NpsController::generate honra template_id de qualquer usuário autorizado + valida escopo server-side (espelha empresasElegiveis)"
  - "Comando nps:remigrar-modelo-resposta — corrige respostas NPS já geradas com o modelo errado, reatribuindo via NpsSnapshotService"
affects: [nps, bonus, desempenho]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Validação de escopo server-side espelhando os 2 ramos de NpsTemplateController::empresasElegiveis (com scope → exige contrato ativo; sem scope → fallback aceita qualquer empresa)"
    - "Comando de correção pontual com diff+confirmação DENTRO de uma transação aberta (DB::beginTransaction/commit/rollBack) — dry-run e recusa fazem rollBack, sem duplicar a lógica de simulação"
    - "Idempotência por construção via comparação de FK (template_id), nunca por tolerância decimal — evita o bug de NpsBackfillDivisorTextoLivre:212 (divisão não-exata nunca converge com tolerância 0.0001)"

key-files:
  created:
    - app/Console/Commands/NpsRemigrarModeloResposta.php
    - tests/Feature/V16/NpsModeloEscolhidoTest.php
    - tests/Feature/V16/NpsRemigrarModeloRespostaTest.php
  modified:
    - app/Http/Controllers/NpsController.php

key-decisions:
  - "DEC-NDO-1: validação de escopo aceita template SEM nenhum scope (pivot vazio) para qualquer empresa — espelha o fallback real de empresasElegiveis, não apenas 'sempre exigir interseção'"
  - "DEC-NDO-2: erro de validação via back()->with('error', ...), nunca abort(422) — Inertia não converte abort() em flash"
  - "DEC-NDO-3: resolveForCompany preservado como fallback quando template_id vem ausente"
  - "DEC-NDO-4: FKs template_question_id/template_option_id das answers NÃO são re-apontadas na remigração — snapshot colunas são a fonte de verdade; risco residual é cosmético (ordenação do detalhe) e só se o template antigo for excluído no futuro"
  - "DEC-NDO-5: comando de remigração exige --survey= explícitos, SEM seletor genérico por template_id — evita corromper NPS legítimos gerados após o fix"
  - "DEC-NDO-6: average_score recongelado PODE subir em produção por causa do fix do divisor de texto_livre (quick task 260715-kam) ainda não backfillado — não é regressão"
  - "DEC-NDO-7: comparações de decimal(5,2) sempre na escala da coluna (round($x,2)), nunca tolerância 0.0001"

requirements-completed: []

duration: ~35min
completed: 2026-07-15
---

# Quick Task 260715-ndo: Modelo NPS escolhido + migração das respostas erradas

**Fecha o furo "UI diz uma coisa, servidor faz outra" em `NpsController::generate` (gate `isAdmin()` que ignorava silenciosamente o `template_id` de não-admin) e entrega o comando `nps:remigrar-modelo-resposta` para corrigir as 2 respostas já afetadas em produção, reusando `NpsSnapshotService::registrar()`.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 3
- **Files modified:** 4 (1 controller, 1 comando novo, 2 suites novas)

## Accomplishments

- **Bug A corrigido:** `NpsController::generate` agora honra o `template_id` escolhido por qualquer usuário já autorizado pela empresa (não só admin) — alinhando servidor e UI (o modal modelo-first da Fase 81 já oferecia o seletor para todos).
- **Validação de escopo server-side** adicionada como defesa em profundidade, espelhando os 2 ramos reais de `NpsTemplateController::empresasElegiveis`: modelo com serviços cobertos exige contrato ATIVO da empresa; modelo sem nenhum scope (ex.: NPS Padrão) é aceito para qualquer empresa (fallback preservado — sem isso o NPS Padrão quebraria).
- **Decisão de produto atendida:** empresa com ML + Shopee agora consegue gerar os DOIS NPS separados, cada um atribuído ao responsável do seu setor — espelhando o disparo mensal multi-modelo da Fase 79.
- **Comando `nps:remigrar-modelo-resposta`** criado: troca `template_id` de surveys específicos (`--survey=* --para=`) e recongela TODO o snapshot (scores/covered_services/assignments) via o mesmo motor de produção, com diff nominal (quem→quem, nota antes→depois), `--dry-run`, confirmação interativa e idempotência por construção.

## RED confirmado (prova do bug antes do fix)

Antes da Tarefa 2, um não-admin com a empresa na carteira escolhendo explicitamente o template "Perf" (`template_id` no payload) recebia um survey com `template_id` = "Shopee" (o de maior `priority`, resolvido silenciosamente por `resolveForCompany`) — reprodução exata do dano medido em produção. Confirmado via teste automatizado (`git stash`-free: temporariamente revertido o fix para reconfirmar o RED, depois restaurado) e via o próprio commit RED (`e00b9da`), que falhou nos 4 casos dependentes do gate (bug + 3 validações de escopo) e passou nos 3 casos independentes dele (admin, fallback sem `template_id`, 403 fora da carteira).

## Task Commits

1. **Tarefa 1: Teste RED do Bug A + validação de escopo** - `e00b9da` (test)
2. **Tarefa 2: GREEN — generate() honra o modelo escolhido + valida escopo** - `5f5eb17` (fix)
3. **Tarefa 3a: Teste RED da remigração** - `8102bca` (test)
3. **Tarefa 3b: Comando nps:remigrar-modelo-resposta** - `9abe670` (feat)

## Files Created/Modified

- `app/Http/Controllers/NpsController.php` — remove o gate `isAdmin()` do override de template em `generate()`; adiciona validação de escopo (2 ramos: com/sem serviços cobertos) espelhando `empresasElegiveis`; mensagens de erro em pt-BR via `back()->with('error', ...)`.
- `app/Console/Commands/NpsRemigrarModeloResposta.php` — comando novo, auto-discovery Laravel 12. Troca `template_id`, apaga o snapshot antigo (assignments→covered_services→scores, ordem por causa de FK) e recongela via `NpsSnapshotService::registrar()`. Diff+confirmação dentro de uma transação aberta; `--dry-run`/recusa fazem `rollBack()`.
- `tests/Feature/V16/NpsModeloEscolhidoTest.php` — 7 casos: bug reproduzido, 3 validações de escopo (contrato inativo, template inativo, fallback pivot vazio), 3 não-regressões (admin, fallback sem `template_id`, 403).
- `tests/Feature/V16/NpsRemigrarModeloRespostaTest.php` — 8 casos: reatribuição correta, valor preservado, idempotência (2ª execução no-op), `--dry-run` sem escrita, survey sem resposta (skip), `--para` inexistente/inativo (falha sem gravar), comando registrado via `Artisan::all()`.

## Decisões Tomadas

Ver `key-decisions` no frontmatter (DEC-NDO-1 a DEC-NDO-7) — todas já estavam travadas no PLAN.md; nenhuma decisão nova precisou ser tomada durante a execução.

## Deviations from Plan

**Nenhuma deviation de código.** Um único desvio metodológico (dentro do próprio espírito do plano, Rule de rigor já praticada nesta milestone):

**1. Contaminação de fixture com dados seedados — corrigida durante a Tarefa 1**
- **Encontrado durante:** Tarefa 1 (primeira rodada de RED)
- **Problema:** O helper `inserirLinhaShopee()` do trait `CriaCenarioResponsaveis` reaproveita o serviço Shopee GLOBAL já semeado pela migration `2026_07_14_100002_seed_servico_shopee` (comportamento correto para os testes de disparo/atribuição que o herdaram). Usar esse helper no cenário desta suite fazia o template de teste "Shopee" competir com o template REAL "NPS Shopee" (semeado pela migration `2026_07_14_200002`, `priority=10`), que também tinha scope no mesmo serviço reaproveitado — o tiebreak por menor id elegia o template semeado, não o do teste, mascarando os asserts com ids incorretos.
- **Fix:** Isolamento explícito — cada teste cria seu PRÓPRIO serviço Shopee via `criarServico()` (linha nova), em vez de reaproveitar o catálogo global. Padrão replicado igualmente em `NpsRemigrarModeloRespostaTest`.
- **Arquivos:** `tests/Feature/V16/NpsModeloEscolhidoTest.php`, `tests/Feature/V16/NpsRemigrarModeloRespostaTest.php`
- **Verificação:** RED reproduziu o bug corretamente após o isolamento (assertivas batendo com os ids esperados); nenhum teste de terceiros afetado.
- **Committed in:** `e00b9da` (parte do commit RED da Tarefa 1 — o isolamento foi ajustado antes do primeiro commit, não é um commit separado).

---

**Total deviations:** 0 de código; 1 ajuste metodológico de fixture (isolamento de teste) resolvido antes do primeiro commit.
**Impact on plan:** Nenhum. A correção de fixture era necessária para o RED reproduzir o bug real, não uma mudança de comportamento do sistema.

## Issues Encountered

Nenhum bloqueio. `php` não estava no `PATH` do shell da sessão (XAMPP) — resolvido adicionando `/c/xampp/php` ao `PATH` a cada invocação de `php artisan`.

## Advertência para o operador (deploy/produção)

Ao rodar `nps:remigrar-modelo-resposta --survey=102 --survey=106 --para=2` em produção (fora do escopo desta execução — orquestrador cuida do deploy):

- **DEC-NDO-6:** o `average_score` recongelado PODE subir em relação ao valor atual, porque `NpsSnapshotService::registrar()` recalcula via `NpsScoreCalculator` VIVO — que já contém o fix do divisor de `texto_livre` (quick task 260715-kam), cujo backfill das linhas antigas AINDA NÃO rodou em produção. Isso NÃO é bug do comando; é o mesmo efeito que `nps:backfill-divisor-texto-livre` produziria nessas 2 linhas. Rodar primeiro com `--dry-run` para ver o diff (nota antes → depois) antes de confirmar.
- **DEC-NDO-4:** as FKs `template_question_id`/`template_option_id` das respostas #102/#106 continuam apontando para o template 3 (Shopee) mesmo após a remigração — decisão deliberada (ver docblock do comando). Risco cosmético e só se materializa se o template Shopee for excluído no futuro (não é o plano — a decisão de produto é mantê-lo vivo e gerável).

## Regression Gates

- `tests/Feature/V16/NpsModeloEscolhidoTest.php` — 7/7 ✓
- `tests/Feature/V16/NpsRemigrarModeloRespostaTest.php` — 8/8 ✓
- `tests/Feature/V16/` (suite completa) — 102/102 ✓
- `--filter=Nps` — 204/204 ✓ (baseline citado no plano era 174; o aumento reflete os testes das Fases 79-81 e desta quick task, todos verdes)
- `--filter=Desempenho` — 55/56 ✓ (1 falha PRÉ-EXISTENTE e NÃO relacionada: `PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200`, 403≠200 — última alteração no arquivo é do commit `8748d47`, Phase 49-02, muito antes desta quick task; confirmado via `git log` do arquivo)
- Âncora Carlos 4.08/`basico` — verde, sem edição do arquivo.
- `git diff --stat` (escopo real desta sessão, `e00b9da^..HEAD`) — exatamente os 4 arquivos do plano, nenhum outro tocado.
- Backend-only: nenhum `.jsx` tocado, `npm run build` não executado (não necessário).
- Nada em `tests/Feature/Phase77..82/` tocado.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Bug A fechado — não-admin escolhendo modelo no modal recebe exatamente o modelo escolhido, com validação de escopo server-side.
- Comando `nps:remigrar-modelo-resposta` pronto para uso em produção (aparece em `php artisan list nps`), mas NÃO foi executado contra produção nesta sessão — cabe ao orquestrador rodar `--survey=102 --survey=106 --para=2 --dry-run` primeiro, conferir o diff, depois `--force`.
- Nenhum blocker técnico. Zero deploy realizado nesta sessão.

---
*Quick task: 260715-ndo*
*Completed: 2026-07-15*

## Self-Check: PASSED

- FOUND: app/Http/Controllers/NpsController.php
- FOUND: app/Console/Commands/NpsRemigrarModeloResposta.php
- FOUND: tests/Feature/V16/NpsModeloEscolhidoTest.php
- FOUND: tests/Feature/V16/NpsRemigrarModeloRespostaTest.php
- FOUND: .planning/quick/260715-ndo-nps-modelo-escolhido-e-migracao/260715-ndo-SUMMARY.md
- FOUND commit: e00b9da (test RED Tarefa 1)
- FOUND commit: 5f5eb17 (fix GREEN Tarefa 2)
- FOUND commit: 8102bca (test RED Tarefa 3)
- FOUND commit: 9abe670 (feat GREEN Tarefa 3)
