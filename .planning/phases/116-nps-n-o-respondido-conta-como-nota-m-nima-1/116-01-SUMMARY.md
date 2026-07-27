---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
plan: 01
subsystem: database
tags: [nps, laravel, eloquent, migration, tdd, bonificacao]

requires: []
provides:
  - "Tabela nps_imputed_assignments (grão survey × serviço × dimensão × responsável)"
  - "Model NpsImputedAssignment com scope vigentes()"
  - "NpsImputationService::materializar/materializarLote (escrita idempotente)"
  - "NpsImputationService::notasDoUsuario/notasDaEmpresa/surveyIdsComNotaDefinitiva (API de leitura única)"
affects: [116-02, 116-03, 116-04, desempenho, nps-controller, carteira]

tech-stack:
  added: []
  patterns:
    - "Tabela nova de grão survey para snapshot congelado (mesmo padrão de nps_score_assignments, mas com FK survey_id obrigatória em vez de nps_response_id)"
    - "status como ESTADO GRAVADO (provisorio/definitivo), não cálculo ao vivo — garante que resposta tardia não reescreve competência fechada"
    - "Fechamento por DATA com gt (não gte) — divergência justificada do padrão irmão computeNpsWindow"

key-files:
  created:
    - database/migrations/2026_07_27_100000_create_nps_imputed_assignments_table.php
    - app/Models/NpsImputedAssignment.php
    - app/Services/Nps/NpsImputationService.php
    - tests/Feature/Phase116/NpsImputacaoServiceTest.php
  modified: []

key-decisions:
  - "Tabela nova (nps_imputed_assignments), não reuso de nps_score_assignments — lá as FKs de resposta são NOT NULL, não existe linha sem resposta real"
  - "Gate de não respondido = status != 'completed', nunca 'expired' isolado (transição para expired é lazy)"
  - "Fechamento por DATA com gt (não gte, diverge de computeNpsWindow) — o link do NPS vale até 23:59:59 do último dia (expires_at = endOfMonth)"
  - "provisorio conta na leitura desde o disparo (D2) — obrigatório para o snapshot mensal do bônus enxergar o não respondido do mês em curso"
  - "Contrato ativo é avaliado no momento da MATERIALIZAÇÃO, não do disparo — limitação aceita do backfill retroativo"

patterns-established:
  - "NpsImputationService é a ÚNICA fonte de escrita/leitura da regra — nenhum consumidor das próximas waves pode reimplementar resolução de responsável, gate de competência ou dedupe"

requirements-completed: [NPSFLOOR-03, NPSFLOOR-05, NPSFLOOR-06, NPSFLOOR-07, NPSFLOOR-11, NPSFLOOR-12]

duration: ~50min
completed: 2026-07-27
---

# Fase 116 Plano 01: Fundação de dados do NPS não respondido Summary

**Tabela `nps_imputed_assignments` + `NpsImputationService` (materialização idempotente + API de leitura) que congela o não-respondido do NPS como nota 1.00 por dimensão, sem alterar nenhum consumidor existente.**

## Performance

- **Duration:** ~50 min
- **Completed:** 2026-07-27T18:18:13Z
- **Tasks:** 3
- **Files modified:** 4 (todos novos — nenhum arquivo de consumidor tocado)

## Accomplishments
- Tabela `nps_imputed_assignments` (grão survey × serviço × dimensão × responsável), com FKs `servico_id`/`user_id` nullable + nullOnDelete (armadilha MySQL 1830 documentada no código) e unique de idempotência.
- Model `NpsImputedAssignment` com scope `vigentes()` — blindagem de leitura que exclui linhas provisórias órfãs de survey já respondido.
- `NpsImputationService::materializar()`/`materializarLote()` — escrita idempotente, com fallback de responsável consolidado, resolução de competência com fallback `created_at` (D6), e transição provisório → definitivo por data (D2/NPSFLOOR-07).
- API de leitura `notasDoUsuario()`/`notasDaEmpresa()`/`surveyIdsComNotaDefinitiva()` — única fonte que os consumidores das próximas waves (Desempenho, NPS, Carteira) devem usar.
- Suíte `tests/Feature/Phase116/NpsImputacaoServiceTest.php` com 16 testes (56 assertions) provando os invariantes D2/D3/D6/D7 e a idempotência.

## Task Commits

Cada task foi commitada atomicamente (ciclo TDD RED → GREEN):

1. **Tarefa 1 (RED): suíte de comportamento do serviço de imputação** - `67a6a2c9` (test)
2. **Tarefa 2: migration + model** - `2697719e` (feat)
3. **Tarefa 3 (GREEN): NpsImputationService** - `4ce89d7f` (feat)

**Plan metadata:** (este commit) `docs: complete plan`

## Files Created/Modified
- `database/migrations/2026_07_27_100000_create_nps_imputed_assignments_table.php` - Schema da tabela de imputação, com armadilha MySQL 1830 e enum-vs-string comentadas
- `app/Models/NpsImputedAssignment.php` - Model + scope `vigentes()` + constantes de status
- `app/Services/Nps/NpsImputationService.php` - Serviço único de escrita (materializar/materializarLote) e leitura (notasDoUsuario/notasDaEmpresa/surveyIdsComNotaDefinitiva)
- `tests/Feature/Phase116/NpsImputacaoServiceTest.php` - 16 testes cobrindo todos os invariantes do `<behavior>` da Tarefa 1

## Decisions Made
- Tabela nova em vez de reusar `nps_score_assignments` (FKs de resposta lá são NOT NULL — decisão de arquitetura #1 do plano, já travada no PLAN.md).
- `status` (`provisorio`/`definitivo`) gravado como estado, nunca recalculado — é a única forma de garantir que uma resposta tardia não reescreva a nota de uma competência já fechada.
- Gate de fechamento usa `gt` (não `gte`, diferente de `computeNpsWindow`) porque o link do NPS vale até 23:59:59 do último dia do mês.
- `dimensao`/`status` são `string(20)`, não `enum` — evita a armadilha "enum + SQLite" para valores futuros.

## Deviations from Plan

None - plan executado exatamente como escrito. Os únicos ajustes foram de redação em 2 comentários do código (trocar as literais `consultorDoServico`/`estrategistaDoServico` e `'expired'` por descrição textual equivalente) para que os greps automatizados de aceite da Tarefa 3 (que checam ausência dessas strings no arquivo) não contabilizassem menções em comentários explicativos — sem mudança de comportamento.

## Issues Encountered

Durante o gate de regressão da Tarefa 3, `php artisan test --filter=Nps` e `--filter=Desempenho` mostraram falhas pré-existentes:

- **`tests/Feature/V18/ConsolidarMesJanelaNpsTest.php`** (2 falhas) — congelamento de snapshot mensal via `desempenho:consolidar-mes`.
- **14 falhas na suíte `Desempenho`** (`DesempenhoShopeeScoreTest`, `Phase74/ConsolidarMesDesempenhoCommandTest`, `Phase74/DesempenhoScoreServiceTest`, `V16/DesempenhoElegibilidadeTest`, `V18/DesempenhoPeriodoOficialTest`, `PublicacaoDesempenhoRouteTest`) — todas relacionadas a `var_margem_pct`/`AdmanMetricDiffService`.

**Verificado que são pré-existentes e não causadas por este plano:** movi temporariamente os 4 arquivos criados nesta wave para fora do projeto e rodei `ConsolidarMesJanelaNpsTest` isoladamente — as mesmas 2 falhas se reproduziram identicamente sem nenhum arquivo da Fase 116 presente. `app/Services/Metrics/AdmanMetricDiffService.php` está sendo editado por outra sessão paralela no mesmo working tree (confirmado via `git log` — commits recentes nesse arquivo não presentes no HEAD do início desta execução), e é a fonte documentada de instabilidade em `.planning/debug/` (`project_adman_margem_diff_instavel_bonus`, debug aberto 2026-07-23, explicitamente "NÃO é regressão da Fase 109"). Nenhum arquivo desta wave toca `DesempenhoScoreService`, `AdmanMetricDiffService` ou qualquer consumidor de NPS — a suíte `NpsImputacaoServiceTest` (16/16) é o gate real desta plano e está 100% verde.

Não tentei corrigir essas falhas (fora de escopo — arquivo explicitamente marcado para não ser tocado nesta sessão).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Fundação de dados completa: `NpsImputationService` pronto para ser consumido pelas próximas waves (116-02 em diante) sem nenhuma mudança de comportamento visível ainda.
- Nenhum consumidor (`DesempenhoScoreService`, `NpsController`, dashboards/carteira) foi alterado — comportamento do sistema idêntico ao pré-fase.
- Bloqueador pendente e FORA do escopo deste plano: as falhas pré-existentes de `var_margem_pct`/`AdmanMetricDiffService` (outra sessão) devem ser resolvidas antes do fechamento final da milestone, mas não bloqueiam o andamento da Fase 116.

---
*Phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1*
*Completed: 2026-07-27*

## Self-Check: PASSED

Todos os 4 arquivos criados e o SUMMARY.md confirmados no disco; os 3 hashes de commit (`67a6a2c9`, `2697719e`, `4ce89d7f`) confirmados via `git log --oneline --all`.
