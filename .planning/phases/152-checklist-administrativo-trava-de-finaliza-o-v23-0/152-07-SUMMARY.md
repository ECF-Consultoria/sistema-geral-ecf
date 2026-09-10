---
phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 07
subsystem: backend
tags: [checklist-administrativo, fluxo-entrada, etapa-transicao, maquina-de-estados, injecao-de-dependencia]

# Dependency graph
requires:
  - phase: 152-05
    provides: "App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoService::paraEmpresa()/progresso() — a fonte do estado dos itens que dirige os degraus"
  - phase: 152-06
    provides: "App\\Services\\ChecklistAdministrativo\\FinalizarEntradaAdministrativaService::podeFinalizar() — a condição do degrau para a etapa 4"
  - phase: 150
    provides: "App\\Services\\FluxoEntrada\\EtapaTransicaoService::transicionar() — a única porta de escrita de companies.etapa"
provides:
  - "App\\Services\\ChecklistAdministrativo\\ChecklistEtapaSincronizadorService::sincronizar(Company, User): array{transicoes, etapa_final} — a régua da D-15, terceiro chamador de produção de EtapaTransicaoService e o único a escrever as etapas 2, 3 e 4"
affects: [152-08, 152-09, 152-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Avanço em cadeia com laço limitado (máx. 3 degraus) + refresh() após cada degrau — uma empresa que fecha tudo de uma vez sobe 1→2→4 numa chamada só, cada degrau com linha PRÓPRIA de histórico"
    - "Degrau único por iteração, decidido em proximoDestino(): o salto para a etapa 4 é checado ANTES do avanço 2→3, e é essa ORDEM (não um ramo especial) que produz o salto 2→4 da empresa isenta"
    - "Grafo de injeção one-way defendido em RUNTIME, não só por grep: um teste resolve os três serviços pelo container e falha nomeando CircularDependencyException — regressão que não aparece em php -l e derruba com 500 toda rota que type-hinte qualquer um dos três"
    - "Interrupção da cadeia em qualquer recusa de transicionar() com Log::warning — nunca tentar o degrau seguinte por cima de uma recusa da máquina de estados"

key-files:
  created:
    - app/Services/ChecklistAdministrativo/ChecklistEtapaSincronizadorService.php
    - tests/Feature/Phase152/ChecklistDirigeEtapaTest.php
  modified: []

key-decisions:
  - "D-15 implementado: 1→2 quando progresso()['feitos'] >= 1; 2→3 quando exige_contrato e o item contrato_enviado está concluído; 2/3→4 quando podeFinalizar()['permitido'] — toda transição por EtapaTransicaoService::transicionar(), nenhuma escrita direta em companies.etapa"
  - "D-07 implementado: empresa isenta nunca tem exige_contrato=true, então o degrau 2→3 nunca dispara para ela — o salto 2→4 sai da ORDEM de checagem em proximoDestino(), sem ramo especial, e a tabela TRANSICOES_PERMITIDAS da Fase 150 já o previa"
  - "Guarda de legado (D-14 da Fase 151 / D-05 da Fase 150): etapa NULL devolve sem tocar em nada — zero linha em company_etapa_transicoes, provado por assertDatabaseMissing"
  - "Guarda de escopo: etapa fora de {1,2,3} devolve sem transicionar — a 4→5 é do FinalizarEntradaAdministrativaService e as posteriores são das Fases 154/142"
  - "Só AVANÇA, nunca retrocede: desmarcar item na etapa 4 não devolve a empresa à 3. Reversibilidade é Deferred no CONTEXT.md; inventar aqui um motivo automático escreveria no histórico uma decisão que ninguém tomou"
  - "ChecklistAdministrativoService NÃO foi alterado por este plano (git diff vazio) — quem dispara sincronizar() é a camada HTTP do plano 152-08, que injeta os três serviços como irmãos"

patterns-established:
  - "Teste de máquina de estados afirma SEMPRE por reconsulta ao banco (Company::findOrFail()->etapa) e conta linhas de company_etapa_transicoes antes/depois — nunca pelo objeto em memória nem pelo retorno do service isoladamente"

requirements-completed: [ADMIN-01, ADMIN-05, ADMIN-06]

# Metrics
duration: ~35min (execução em duas sessões — Task 1 e Task 2)
completed: 2026-09-10
---

# Phase 152 Plan 07: ChecklistEtapaSincronizadorService — a régua da D-15 Summary

**O service que traduz o progresso do checklist administrativo nas transições de etapa 1→2, 2→3 e 2/3→4, sempre por `EtapaTransicaoService::transicionar()` — sem ele o FINALIZAR do plano 152-06 nasceria morto, porque a etapa 5 só é alcançável a partir da 4 e nenhum chamador de produção escrevia as etapas intermediárias.**

## Performance

- **Duration:** ~35 min (a Task 1 foi executada e commitada numa sessão anterior; esta sessão retomou na Task 2)
- **Completed:** 2026-09-10
- **Tasks:** 2/2
- **Files modified:** 2 (ambos criados novos)

## Accomplishments

- `ChecklistEtapaSincronizadorService::sincronizar(Company $company, User $por): array` — devolve
  `{transicoes, etapa_final}`, onde `transicoes` lista **em ordem** as etapas efetivamente
  aplicadas nesta chamada (pode ser vazia). Duas guardas antes de qualquer escrita: legado
  (`etapa === null` devolve sem tocar em nada) e escopo (etapa fora de `{1,2,3}` devolve sem
  transicionar). Laço de no máximo 3 iterações com `refresh()` a cada degrau aplicado;
  qualquer retorno com `status !== 'transicionado'` emite `Log::warning('[Checklist] transição de
  etapa recusada', ...)` e **interrompe** a cadeia.
- `proximoDestino()` — helper privado que decide **um** degrau por iteração. A ordem de checagem é
  o desenho: o salto para a etapa 4 (via `podeFinalizar()`) é avaliado **antes** do avanço 2→3, e é
  isso que produz o salto 2→4 da empresa isenta sem nenhum ramo especial — a tabela
  `TRANSICOES_PERMITIDAS` da Fase 150 já previa essa aresta.
- Grafo de injeção **one-way por construção**: o sincronizador depende de
  `ChecklistAdministrativoService` e `FinalizarEntradaAdministrativaService`; nenhum dos dois
  depende dele. `git diff --name-only` do `ChecklistAdministrativoService.php` retornou **vazio**,
  e `grep -c 'ChecklistEtapaSincronizadorService'` naquele arquivo retornou **0**. A razão concreta
  está em docblock de classe em pt-BR: um construtor recíproco fecha ciclo no autowiring e derruba
  com 500 toda rota que type-hinte qualquer um dos três, incluindo `ContratoAdminController::show()`.
- `tests/Feature/Phase152/ChecklistDirigeEtapaTest.php` — **10 casos, 33 assertions**, todos com
  asserção de etapa por reconsulta ao banco: (1) 1º item manual leva à etapa 2, com linha de
  histórico carregando o `user_id` do ator; (2) envelope com `enviado_em` leva à etapa 3;
  (3) tudo concluído + contrato assinado leva à etapa 4; (4) **salto 2→4** da empresa isenta,
  provado por `assertDatabaseMissing` em `aguardando_assinatura`; (5) **cadeia numa chamada só**
  1→2→4, com `assertSame(2, ...)` no total de linhas de histórico — cada degrau é linha própria;
  (6) desmarcar item na etapa 4 **não** retrocede e não cria linha nova; (7) legado com `etapa`
  NULL não gera **nenhuma** linha; (8) idempotência entre duas chamadas seguidas; (9) empresa na
  etapa 5 não é tocada; (10) **grafo acíclico** — resolve os três serviços pelo container e falha
  nomeando `CircularDependencyException`.
- Suíte da fase `tests/Unit/Phase152 + tests/Feature/Phase152` — **68 testes / 225 assertions,
  100% verde** (+10 testes / +33 assertions sobre 58/192 do fechamento do 152-06).
- Regressão obrigatória `tests/Unit/Phase150 + tests/Feature/Phase150 + tests/Feature/Phase151` —
  **123 testes / 444 assertions, 100% verde**, número por número **idêntico** ao
  `152-BASELINE-TESTES.md`. Este plano acrescenta o **terceiro** chamador de produção de
  `EtapaTransicaoService` e não moveu a agulha da máquina de estados existente.

## Task Commits

Each task was committed atomically:

1. **Task 1: ChecklistEtapaSincronizadorService — a régua da D-15** - `2f44342d` (feat), 210 linhas
2. **Task 2: Provar a régua da D-15 e a ausência de ciclo de injeção** - `3dd5718` (test), 393 linhas

## Files Created/Modified

- `app/Services/ChecklistAdministrativo/ChecklistEtapaSincronizadorService.php` - o service
  completo (`sincronizar` + 2 helpers privados), 210 linhas
- `tests/Feature/Phase152/ChecklistDirigeEtapaTest.php` - 10 testes, D-15/D-07/D-14, 393 linhas

## Decisions Made

Nenhuma decisão nova de produto — as duas tasks implementam à letra a D-15 e a D-07, já travadas no
`152-CONTEXT.md`. Uma escolha **estrutural** foi feita dentro do espaço já previsto pela `<action>`:
o degrau para a etapa 4 é checado **antes** do degrau 2→3 dentro de `proximoDestino()`, em vez de
seguir a ordem literal em que os três degraus aparecem enunciados no plano (1→2, depois 2→3, depois
2/3→4). O plano já dizia que o salto 2→4 "não precisa de tratamento especial: a tabela já o
permite" — inverter a ordem de checagem é exatamente o que torna isso verdade sem nenhum ramo `if`
dedicado à isenção. Comportamento final idêntico ao especificado, incluindo para a empresa que
exige contrato (que não alcança `podeFinalizar()` sem assinar, então cai naturalmente no 2→3).

## Deviations from Plan

**Nenhum desvio de comportamento.** Todo `<acceptance_criteria>` das duas tasks foi conferido e
passou:

- `php -l` limpo no service.
- Gate de escrita direta: o grep oficial do plano (`update(['etapa'` e `DB::transaction(`, com
  filtro de linhas de comentário) retorna **0**. Nota para quem auditar depois: um grep mais largo
  incluindo `->etapa =` acusa 3 ocorrências, mas as três são `->etapa ===` — comparação, não
  atribuição; o prefixo casa por coincidência. O gate oficial está correto.
- Gate de ciclo: `grep -c 'ChecklistEtapaSincronizadorService'` no `ChecklistAdministrativoService.php` = **0**;
  `git diff --name-only` daquele arquivo = vazio.
- `Log::warning(` presente no tratamento de recusa; as 4 constantes de etapa presentes;
  `public function sincronizar(Company $company, User $por): array` presente.
- `CircularDependencyException` literal presente no teste (3 ocorrências: import, docblock, catch).
- `assertDatabaseMissing('company_etapa_transicoes'` presente no caso do salto 2→4 e no caso do legado.

**Total deviations:** 0.

## Issues Encountered

Nenhum. A execução foi retomada com a Task 1 já commitada e o arquivo de teste da Task 2 escrito
mas **não commitado** (untracked na árvore). O primeiro passo desta sessão foi rodar o teste em vez
de reescrevê-lo — 10/10 verde de primeira, seguido dos gates estáticos e da regressão. Nenhuma
correção foi necessária.

## User Setup Required

None - nenhuma configuração de serviço externo. Toda a lógica é orquestração de código já existente
(`ChecklistAdministrativoService` do 152-05, `FinalizarEntradaAdministrativaService` do 152-06,
`EtapaTransicaoService` da Fase 150).

## Next Phase Readiness

- `sincronizar()` está pronto para o plano **152-08** (camada HTTP), que precisa injetar os três
  serviços como **irmãos** e orquestrar na ordem: marcar/desmarcar item → `sincronizar()` →
  (no botão FINALIZAR) `finalizar()`. Essa ordem é obrigatória: o caso 5 de
  `FinalizarTransicaoEtapaTest.php` (plano 152-06) prova que `finalizar()` isolado numa empresa
  fora da etapa 4 é recusado pela máquina de estados mesmo com o checklist 100% completo.
- **Proibição que o 152-08 herda:** não injetar o sincronizador dentro de
  `ChecklistAdministrativoService` nem de `FinalizarEntradaAdministrativaService` — o caso 10 deste
  teste falha se alguém fizer isso, e a falha é intencional.
- Restam os planos **152-08** (endpoints HTTP), **152-09** e **152-10** para fechar a fase.
- Nenhum bloqueio identificado.

---
*Phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-10*

## Self-Check: PASSED

- FOUND: `app/Services/ChecklistAdministrativo/ChecklistEtapaSincronizadorService.php`
- FOUND: `tests/Feature/Phase152/ChecklistDirigeEtapaTest.php`
