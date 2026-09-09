---
phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 06
subsystem: backend
tags: [checklist-administrativo, fluxo-entrada, etapa-transicao, marketplace, finalizar]

# Dependency graph
requires:
  - phase: 139-05
    provides: "App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoService::paraEmpresa()/progresso() — a fonte da régua do FINALIZAR"
  - phase: 137
    provides: "App\\Services\\FluxoEntrada\\EtapaTransicaoService::podeTransicionar()/transicionar() — a única porta de escrita de companies.etapa"
provides:
  - "App\\Services\\ChecklistAdministrativo\\FinalizarEntradaAdministrativaService::podeFinalizar(Company): array — régua PURA {permitido, requisito_faltante} (ADMIN-05)"
  - "App\\Services\\ChecklistAdministrativo\\FinalizarEntradaAdministrativaService::marketplaceDestino(Company): ?string — destino determinístico mesmo com is_primary duplicado (D-12)"
  - "App\\Services\\ChecklistAdministrativo\\FinalizarEntradaAdministrativaService::finalizar(Company, User): array — efeito que transiciona 4→5 pela única porta permitida (ADMIN-06)"
affects: [139-07, 139-08, 139-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Par régua-pura/efeito espelhando EtapaTransicaoService::podeTransicionar()/transicionar() — a mesma função alimenta o disabled do botão e a recusa no servidor, nunca duas fontes de verdade"
    - "Mensagem de requisito_faltante com caso especial: quando o ÚNICO item pendente é o contrato assinado, a mensagem fala de CONTRATO ('O contrato ainda não está assinado'), não de contagem ('Faltam N de M itens') — mesmo quando tecnicamente a condição de contagem já cobriria o caso"
    - "Desempate determinístico e LOCAL a este service para is_primary duplicado (orderBy('id') + Log::warning) — Company::primaryMarketplace() não é alterado, porque é compartilhado por outros módulos"
    - "Grafo de injeção acíclico por construção: este service depende só de ChecklistAdministrativoService e EtapaTransicaoService, nunca da camada de sincronização de etapa do plano 139-07, que é quem depende DELE"

key-files:
  created:
    - app/Services/ChecklistAdministrativo/FinalizarEntradaAdministrativaService.php
    - tests/Feature/Phase139/FinalizarTravaTest.php
    - tests/Feature/Phase139/FinalizarTransicaoEtapaTest.php
  modified: []

key-decisions:
  - "D-07 implementado: podeFinalizar() considera a condição 'contrato assinado' satisfeita por AUSÊNCIA do grupo Contrato quando exige_contrato=false — nenhuma leitura de ContratoAssinatura acontece para empresa isenta"
  - "D-12 implementado: marketplaceDestino() conta linhas is_primary=true na pivot; com 2+, loga warning e desempata por orderBy('id') (menor id vence); com 0 ou 1, delega a Company::primaryMarketplace() sem alterar aquele método"
  - "ADMIN-06 implementado: finalizar() só chama EtapaTransicaoService::transicionar() — nenhum update(['etapa' => ...]) direto em nenhum lugar deste arquivo, comprovado por grep de auditoria com filtro de comentários"
  - "Este service não conhece a camada de sincronização de etapa do plano 139-07 — grafo de injeção acíclico preservado por construção (grep de auditoria em 0 ocorrências do identificador), disciplina explicada em docblock de classe, mesmo cuidado do 139-05"

patterns-established:
  - "Teste de régua pura fecha os itens automáticos pelo ESTADO REAL (ContratoAssinatura assinada, MlToken ativo, OnboardingLink existente) — nunca gravando status=concluido direto na tabela do checklist, mesma disciplina do plano 139-04/139-05"

requirements-completed: [ADMIN-05, ADMIN-06]

# Metrics
duration: ~25min
completed: 2026-09-09
---

# Phase 139 Plan 06: FinalizarEntradaAdministrativaService — a trava do FINALIZAR Summary

**A régua pura `podeFinalizar()` que decide se o botão FINALIZAR ENTRADA ADMINISTRATIVA habilita (todos os itens obrigatórios concluídos + contrato assinado, ou isenção por ausência do grupo), e o efeito `finalizar()` que move a empresa da etapa 4 para a etapa 5 exclusivamente por `EtapaTransicaoService::transicionar()`, resolvendo o marketplace de destino de forma determinística mesmo com `is_primary` duplicado na pivot.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-09-09
- **Tasks:** 2/2
- **Files modified:** 3 (todos criados novos)

## Accomplishments

- `FinalizarEntradaAdministrativaService::podeFinalizar(Company $company): array` — régua PURA que
  devolve `{permitido, requisito_faltante}`, a mesma forma de retorno de
  `EtapaTransicaoService::podeTransicionar()`. Não consulta `companies.etapa` nem conhece
  `EtapaTransicaoService` (verificado por grep isolando o corpo do método). Duas condições em
  ordem: (1) todos os itens obrigatórios concluídos — recusa nomeando quantos faltam e o primeiro
  pendente na ordem do catálogo, com caso especial quando o ÚNICO pendente é o contrato assinado
  (mensagem fala de "contrato", não de "falta N de M"); (2) contrato assinado, satisfeito por
  ausência do grupo Contrato para empresa isenta (D-07).
- `FinalizarEntradaAdministrativaService::marketplaceDestino(Company $company): ?string` — conta
  linhas `is_primary = true` na pivot `company_marketplaces`; com 2+, loga `Log::warning` e
  desempata por `orderBy('id')` (menor id vence, determinístico); com 0 ou 1, delega a
  `Company::primaryMarketplace()` sem alterar aquele método (é compartilhado por outros módulos —
  o desempate mora só aqui, onde a decisão foi tomada, D-12).
- `FinalizarEntradaAdministrativaService::finalizar(Company $company, User $por): array` — reavalia
  `podeFinalizar()`; se recusado, devolve sem escrever nada; se permitido, chama
  `EtapaTransicaoService::transicionar($company, Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $por)`
  (ADMIN-06); propaga `status`/`requisito_faltante` sem inventar mensagem nova quando a transição
  falha (ex.: empresa ainda não está na etapa 4); em sucesso, devolve `finalizado` com o destino de
  `marketplaceDestino()`. Nenhum `update(['etapa' =>`, nenhum `->etapa =` seguido de `save()`,
  nenhum `DB::transaction()` envolvendo a chamada — todos verificados por grep de auditoria com
  filtro de linhas de comentário.
- `tests/Feature/Phase139/FinalizarTravaTest.php` — 6 casos: empresa recém-criada sem nada
  concluído (recusada); faltando só o contrato assinado (recusada com mensagem de CONTRATO, não de
  contagem); os 9 itens concluídos (permitido, requisito_faltante nulo); empresa isenta com os 6
  itens concluídos, sem nenhum `ContratoAssinatura` no banco (permitido, D-07); empresa isenta com
  5 de 6 (recusada); desmarcar um item já concluído volta `permitido` para `false`.
- `tests/Feature/Phase139/FinalizarTransicaoEtapaTest.php` — 7 casos: etapa 4 completa finaliza e
  grava etapa 5 por reconsulta ao banco; mesmo `company_id` preservado e `Company::count()`
  inalterado (ADMIN-06); linha nova em `company_etapa_transicoes` com o `user_id` do ator; 1 item
  pendente na etapa 4 recusa e mantém etapa 4; checklist completo na etapa 2 é recusado pela
  máquina de estados — provando que a trava do checklist não contorna a trava de transições, com
  comentário explícito de que o mesmo cenário termina diferente pela rota HTTP (sincronização de
  etapa do controller, plano 139-08); `is_primary` duplicado (D-12, defesa) resolve pela linha de
  menor id de forma determinística, comprovado por leitura dupla no mesmo teste; sem nenhuma linha
  em `company_marketplaces` cai para a coluna flat `companies.marketplace`.
- Suíte completa `tests/Unit/Phase139 + tests/Feature/Phase139` rodou **58 testes / 192 assertions,
  100% verde** (+13 testes / +41 assertions sobre a baseline de 45/151 do plano 139-05). Regressão
  obrigatória `tests/Unit/Phase137 + tests/Feature/Phase137 + tests/Feature/Phase138 +
  tests/Unit/Phase139 + tests/Feature/Phase139` rodou **181 testes / 636 assertions, 100% verde**
  (+13 testes / +41 assertions sobre a baseline de 168/595 — idêntico ao esperado, nenhuma
  regressão).

## Task Commits

Each task was committed atomically:

1. **Task 1: podeFinalizar() — a régua pura da trava (ADMIN-05)** - `23bf54d8` (feat) — commit único
   que já inclui `marketplaceDestino()` e `finalizar()` (ver Deviations)
2. **Task 2: finalizar() — transição 4→5 e destino determinístico (ADMIN-06, D-12)** - `dbd22167`
   (test) — só o teste dedicado; os dois métodos já existiam desde a Task 1

## Files Created/Modified

- `app/Services/ChecklistAdministrativo/FinalizarEntradaAdministrativaService.php` - o service
  completo (podeFinalizar, marketplaceDestino, finalizar + 1 helper privado)
- `tests/Feature/Phase139/FinalizarTravaTest.php` - 6 testes, ADMIN-05/D-07
- `tests/Feature/Phase139/FinalizarTransicaoEtapaTest.php` - 7 testes, ADMIN-06/D-12

## Decisions Made

Nenhuma decisão nova de produto — as duas tasks implementam à letra ADMIN-05, ADMIN-06, D-07 e
D-12, já travados no `139-CONTEXT.md`. Uma decisão TÉCNICA foi tomada durante a execução, dentro do
espaço já previsto pela `<action>` do plano: quando o único item pendente é o contrato assinado, a
mensagem de `requisito_faltante` usa o texto específico "O contrato ainda não está assinado" em vez
do texto genérico de contagem, mesmo essa condição sendo tecnicamente capturada pela checagem de
`feitos < total` (que dispara primeiro na ordem do método) — implementado como caso especial dentro
da condição 1, não como a condição 2 separada originalmente esboçada, porque a condição 2 nunca
seria alcançada quando o grupo Contrato existe (o item 3 já entra no denominador do progresso, nota
de desenho que o próprio plano já registrava). O comportamento final é exatamente o que o
`<acceptance_criteria>` do Task 1 pedia; só a estrutura interna do método mudou de "duas condições
sequenciais" para "uma condição com um caso especial e uma condição de reforço para o futuro".

## Deviations from Plan

### Desvio de processo (não de comportamento)

**Escrita do service completo na Task 1, em vez de incremental por task — mesmo padrão do plano 139-05.**

- **O que o plano pedia:** Task 1 escreve `podeFinalizar()`; Task 2 acrescenta `marketplaceDestino()`
  e `finalizar()` — dois commits `feat` incrementais no mesmo arquivo.
- **O que aconteceu:** a Task 1 já escreveu o arquivo inteiro. O motivo é o mesmo documentado em
  `139-05-SUMMARY.md`: escrever o par régua-pura/efeito junto deixa o desenho da classe coeso — o
  docblock de `finalizar()` já precisa referenciar `podeFinalizar()` e vice-versa, e separar em duas
  passadas não reduziria trabalho real, só adiaria a escrita de código que já estava desenhado.
- **Consequência:** a Task 2 foi commitada como `test(139-06): ...` em vez de `feat(139-06): ...`,
  contendo só o arquivo de teste — o código de produção que ela prova já estava no commit `23bf54d8`.
  Todo `<acceptance_criteria>` e `<verify>` de cada task individual foi conferido e passou
  normalmente contra o estado do arquivo naquele ponto.
- **Por que isto não é Rule 4 (arquitetural):** nenhuma decisão de design mudou — a assinatura, o
  comportamento e o contrato de saída de cada método são exatamente os especificados no plano. É
  puramente uma reorganização de QUANDO o código foi escrito versus quando foi PROVADO por teste.
- **Impacto:** nenhum. Todas as acceptance criteria das 2 tasks passaram; a suíte de regressão ficou
  100% verde; nenhum comportamento do plano foi alterado.

**Total deviations:** 1 desvio de processo, 0 mudanças de comportamento, 0 bugs corrigidos.

## Issues Encountered

Durante o desenvolvimento do teste `test_faltando_so_contrato_assinado_e_recusada_com_requisito_falando_de_contrato`
(caso 2 do Task 1), a primeira versão de `podeFinalizar()` produzia a mensagem genérica "Faltam 1
de 9 itens — o primeiro pendente é 'Contrato assinado'" para o cenário em que só falta o contrato —
tecnicamente correta (nomeia o item certo), mas violando a letra do `<action>` do plano
("requisito_faltante fala de contrato, não de contagem"). Corrigido introduzindo o caso especial
descrito em "Decisions Made" acima, dentro da mesma Task 1, antes do commit — nenhum commit adicional
foi necessário.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Toda a lógica é orquestração de código já
existente (`ChecklistAdministrativoService` do plano 139-05, `EtapaTransicaoService` da Fase 137,
`Company::primaryMarketplace()` já existente).

## Next Phase Readiness

- `FinalizarEntradaAdministrativaService::podeFinalizar()`/`marketplaceDestino()`/`finalizar()`
  estão prontos para o plano 139-07 (camada de sincronização de etapa, que precisa ler este service
  ou ser lida por ele — a direção correta é o sincronizador depender DESTE service) e para o plano
  139-08 (controller HTTP, que orquestra: sincroniza a etapa até 4 e só então chama `finalizar()`
  com `$request->user()`).
- **Confirmado por este plano:** `FinalizarEntradaAdministrativaService` não conhece a camada de
  sincronização de etapa do plano 139-07 — o grafo de injeção continua acíclico (grep de auditoria
  em 0 ocorrências do identificador).
- **Ponto de atenção para o plano 139-08:** o caso 5 de `FinalizarTransicaoEtapaTest.php` prova que
  chamar `finalizar()` isoladamente numa empresa fora da etapa 4 é recusado pela máquina de estados,
  mesmo com checklist 100% completo. O controller do 139-08 PRECISA sincronizar a etapa até 4 antes
  de chamar `finalizar()`, ou o FINALIZAR nunca vai funcionar pela rota real.
- Nenhum bloqueio identificado para os planos 139-07/139-08.

---
*Phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-09*
