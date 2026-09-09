---
phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 05
subsystem: backend
tags: [checklist-administrativo, service, autoria, progresso, montagem-condicional]

# Dependency graph
requires:
  - phase: 139-02
    provides: "Tabela checklist_administrativo_itens (company_id) + model App\\Models\\ChecklistAdministrativoItem"
  - phase: 139-03
    provides: "App\\Contracts\\ChecklistResolver + App\\Services\\ChecklistAdministrativo\\ChecklistResolverResultado + App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoDefinicao"
  - phase: 139-04
    provides: "Os 4 resolvers automáticos (ContratoEnviadoResolver, ContratoAssinadoResolver, MlOAuthConectadoResolver, ConexaoEcfResolver)"
provides:
  - "App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoService::paraEmpresa(Company): array — montagem condicional 9/6 itens (D-07), progresso embutido"
  - "App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoService::progresso(Company): array — {feitos,total,percentual} com denominador do catálogo (D-10)"
  - "App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoService::concluirManualmente()/reabrirItem() — autoria em par, recusa de item automático (D-11/D-13)"
  - "App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoService::gerarConexaoEcf() — geração idempotente sem marcar o item por segunda fonte (D-14)"
affects: [139-06, 139-07, 139-08, 139-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Montagem condicional de grupo lida direto de ChecklistAdministrativoDefinicao::itens(bool) — nenhuma marcação 'não aplicável', o grupo Contrato simplesmente não aparece em grupos para empresa isenta (D-02/D-07)"
    - "Persistência assimétrica por natureza: item automático sempre grava via updateOrCreate a cada chamada (leitura de coluna local, barata); item manual é lazy — a linha só existe depois que alguém marca"
    - "Denominador do progresso vem da contagem do catálogo em código, nunca de COUNT(*) na tabela — uma chave órfã fica fora dos dois lados da fração (numerador e denominador)"
    - "Ator sempre tipado (User $usuario), nunca int cru — mesma disciplina T-137-02 de EtapaTransicaoService::transicionar(), aplicada aqui sem que este service conheça aquele"

key-files:
  created:
    - app/Services/ChecklistAdministrativo/ChecklistAdministrativoService.php
    - tests/Feature/Phase139/ChecklistContagemItensTest.php
    - tests/Unit/Phase139/ChecklistProgressoTest.php
    - tests/Feature/Phase139/ChecklistMarcacaoManualAutoriaTest.php
  modified: []

key-decisions:
  - "D-07 implementado no service: paraEmpresa() nunca inclui a chave 'contrato' em grupos para empresa isenta — a lista de itens vem de ChecklistAdministrativoDefinicao::itens(false), que já não contém os 3 itens do grupo"
  - "D-10 implementado: progresso() usa count() da lista de itens do catálogo (6 ou 9 conforme exigeContrato), nunca ChecklistAdministrativoItem::where(...)->count() — testado com linha órfã gravada diretamente na tabela"
  - "D-11 implementado: concluirManualmente()/reabrirItem() gravam/limpam feito_por e feito_em SEMPRE juntos, no mesmo updateOrCreate/save(); activity log é trilha secundária, a leitura da tela vem das colunas"
  - "D-13 implementado: concluirManualmente() recusa (\\DomainException) item com auto_fonte declarado no catálogo, salvo $forcar — parâmetro que existe por paridade com o molde do Onboarding mas NÃO é exposto por HTTP nesta fase"
  - "D-14 implementado: gerarConexaoEcf() delega a idempotência ao OnboardingLinkService::paraEmpresa() (firstOrCreate) e NÃO marca o item 8 diretamente — quem fecha o item é o ConexaoEcfResolver na montagem seguinte, evitando segunda fonte de verdade"
  - "Este service não conhece EtapaTransicaoService nem o sincronizador de etapa da Fase 139-07 — grafo de injeção acíclico preservado por construção (grep de auditoria em 0 ocorrências), disciplina explicada em docblock de classe"

patterns-established:
  - "As três tasks do plano compartilham helpers privados internos ao service (mapaDeResolvers, exigeContrato, persistirResultadoAutomatico, achatarItem, calcularProgresso) que seriam reescritos a cada task se o arquivo fosse dividido em 3 commits incrementais — por isso a Task 1 escreveu o service inteiro (paraEmpresa/progresso/concluirManualmente/reabrirItem/gerarConexaoEcf) num único commit coeso, e as Tasks 2 e 3 adicionaram só os testes dedicados provando o que já estava implementado. Ver seção Deviations."

requirements-completed: [ADMIN-01, ADMIN-03, ADMIN-04]

# Metrics
duration: ~35min
completed: 2026-09-09
---

# Phase 139 Plan 05: ChecklistAdministrativoService — montagem, progresso e marcação manual Summary

**Service central do checklist administrativo: monta 9 itens (com contrato) ou 6 (isento) por montagem condicional lida do catálogo (D-07), roda e persiste os 4 resolvers automáticos a cada chamada, calcula progresso com denominador imune a chave órfã (D-10), e marca/desmarca manualmente com autoria gravada e limpa sempre em par (D-11), recusando marcação manual em item automático (D-13) e gerando a conexão ECF de forma idempotente sem segunda fonte de verdade (D-14).**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-09-09
- **Tasks:** 3/3
- **Files modified:** 4 (todos criados novos)

## Accomplishments

- `ChecklistAdministrativoService::paraEmpresa(Company $company): array` — resolve `exigeContrato()`
  (leitura de `contratosServico.servico`, `loadMissing` para evitar N+1), pega
  `ChecklistAdministrativoDefinicao::itens($exige)`, roda os 4 resolvers automáticos e persiste o
  resultado via `updateOrCreate` a cada chamada, lê as linhas manuais existentes por persistência
  lazy, agrupa por `grupo` na ordem do catálogo e omite a chave `'contrato'` inteira quando a
  empresa é isenta.
- `progresso(Company $company): array` — reaproveita `paraEmpresa()` internamente (sem montar o
  checklist duas vezes) e devolve `{feitos, total, percentual}` com `total` vindo da CONTAGEM DO
  CATÁLOGO — nunca de `COUNT(*)` na tabela — comprovado com uma linha órfã gravada diretamente no
  banco que não entra em nenhum dos dois números.
- `concluirManualmente(Company, string, User, bool)` — recusa (`\DomainException`) chave fora do
  catálogo, item do grupo Contrato em empresa isenta (D-07) e item com `auto_fonte` sem `$forcar`
  (D-13); no caso permitido, grava `status`/`feito_por`/`feito_em` juntos via `updateOrCreate` e
  registra trilha secundária no activity log.
- `reabrirItem(Company, string, User)` — recusa item que não está concluído; limpa `status`,
  `feito_por` e `feito_em` no mesmo `save()` (D-11, ponto 2).
- `gerarConexaoEcf(Company, User): OnboardingLink` — delega a `OnboardingLinkService::paraEmpresa()`
  (idempotente); não marca o item 8 diretamente, deixando o `ConexaoEcfResolver` fechar o item na
  montagem seguinte (D-14).
- 3 arquivos de teste — 17 testes / 48 assertions novas, cobrindo contagem 9/6 com montagem
  condicional, ordem do catálogo, persistência lazy dos itens manuais, denominador do progresso
  imune a chave órfã, guarda de divisão por zero, autoria em par (marcar/desmarcar), recusa de
  marcação manual em item automático e em item de grupo isento, recusa de chave desconhecida,
  sobrevivência da autoria ao soft delete do autor, e idempotência da geração da conexão ECF.
- Suíte completa `tests/Unit/Phase139 + tests/Feature/Phase139` rodou **45 testes / 151 assertions,
  100% verde**. Regressão `tests/Unit/Phase137 + tests/Feature/Phase137 + tests/Feature/Phase138`
  rodou **123 testes / 444 assertions, 100% verde** — idêntica à baseline. Suíte combinada completa
  (as 5 pastas do bloco `<verification>`) rodou **168 testes / 595 assertions, 100% verde**.

## Task Commits

Each task was committed atomically:

1. **Task 1: paraEmpresa() — montagem condicional e execução dos resolvers** - `5e44fe10` (feat) —
   commit único e coeso que já inclui `progresso()`, `concluirManualmente()`, `reabrirItem()` e
   `gerarConexaoEcf()` (ver Deviations)
2. **Task 2: progresso() com denominador vindo do catálogo (D-10)** - `637143a3` (test) — só o
   teste dedicado; o método já existia desde a Task 1
3. **Task 3: Marcação manual com autoria, desmarcação e geração da conexão ECF** - `5861f0bb`
   (test) — só o teste dedicado; os três métodos já existiam desde a Task 1

## Files Created/Modified

- `app/Services/ChecklistAdministrativo/ChecklistAdministrativoService.php` - o service completo
  (paraEmpresa, progresso, concluirManualmente, reabrirItem, gerarConexaoEcf + 5 helpers privados)
- `tests/Feature/Phase139/ChecklistContagemItensTest.php` - 5 testes, D-07/ADMIN-01
- `tests/Unit/Phase139/ChecklistProgressoTest.php` - 5 testes, D-10
- `tests/Feature/Phase139/ChecklistMarcacaoManualAutoriaTest.php` - 7 testes, D-11/D-13/D-14/ADMIN-04

## Decisions Made

Nenhuma decisão nova de produto — as três tasks implementam à letra D-01, D-02, D-07, D-10, D-11,
D-13 e D-14, já travadas no `139-CONTEXT.md`. A única decisão tomada durante a execução foi de
PROCESSO (ver Deviations abaixo): escrever o service inteiro num commit coeso na Task 1, porque os
cinco métodos públicos compartilham cinco helpers privados (`mapaDeResolvers`, `exigeContrato`,
`persistirResultadoAutomatico`, `achatarItem`, `calcularProgresso`) que teriam que ser reescritos ou
refatorados a cada task se o arquivo fosse dividido em três pedaços incrementais.

## Deviations from Plan

### Auto-fixed Issues

Nenhuma correção de bug ou funcionalidade faltante — o service foi escrito seguindo a `<action>`
das três tasks na letra.

### Desvio de processo (não de comportamento)

**Escrita do service completo na Task 1, em vez de incremental por task.**

- **O que o plano pedia:** Task 1 escreve `paraEmpresa()`; Task 2 acrescenta `progresso()`; Task 3
  acrescenta `concluirManualmente()`/`reabrirItem()`/`gerarConexaoEcf()` — três commits `feat`
  incrementais no mesmo arquivo.
- **O que aconteceu:** a Task 1 já escreveu o arquivo inteiro, porque os cinco métodos públicos
  compartilham os mesmos cinco helpers privados internos (o mapa de resolvers, a checagem de
  isenção, a persistência do resultado automático, o achatamento do item e o cálculo do progresso)
  — dividir a escrita em três passadas exigiria escrever esses helpers pela metade na Task 1 e
  completá-los depois, sem ganho real de atomicidade (o comportamento de `paraEmpresa()` sozinho já
  dependia de `persistirResultadoAutomatico`/`achatarItem`/`exigeContrato`/`mapaDeResolvers`, que
  são os mesmos helpers usados por `progresso()`).
- **Consequência:** as Tasks 2 e 3 foram commitadas como `test(139-05): ...` em vez de
  `feat(139-05): ...`, contendo só os arquivos de teste — o código de produção que elas provam já
  estava no commit `5e44fe10`. Todo `<acceptance_criteria>` e `<verify>` de cada task individual foi
  conferido e passou normalmente contra o estado do arquivo naquele ponto (os métodos de Task 2/3 já
  existiam desde o commit anterior, então os greps/testes de cada task encontraram exatamente o que
  esperavam).
- **Por que isto não é Rule 4 (arquitetural):** nenhuma decisão de design mudou — a assinatura, o
  comportamento e o contrato de saída de cada método são exatamente os especificados no plano. É
  puramente uma reorganização de QUANDO o código foi escrito versus quando foi PROVADO por teste, e
  cada task continua rastreável por commit + teste dedicado.
- **Impacto:** nenhum. Todas as acceptance criteria das 3 tasks passaram; a suíte de regressão
  ficou 100% verde; nenhum comportamento do plano foi alterado.

**Total deviations:** 1 desvio de processo, 0 mudanças de comportamento, 0 bugs corrigidos.

## Issues Encountered

Nenhum. As três tasks seguiram a `<action>` do plano na íntegra; nenhum bloqueio, nenhuma
ambiguidade de dado precisou de decisão nova.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Toda a lógica é orquestração de código já
existente (os 4 resolvers do plano 139-04, o catálogo do plano 139-03, o model do plano 139-02, e o
`OnboardingLinkService` já existente no projeto).

## Next Phase Readiness

- `ChecklistAdministrativoService::paraEmpresa()`/`progresso()`/`concluirManualmente()`/
  `reabrirItem()`/`gerarConexaoEcf()` estão prontos para o plano 139-06 (`FinalizarEntradaAdministrativaService`,
  que precisa ler `paraEmpresa()`/`progresso()` para decidir se o FINALIZAR pode habilitar) e para
  o plano 139-08 (controller HTTP, que chama os métodos de escrita com `$request->user()`).
- **Confirmado por este plano:** `ChecklistAdministrativoService` não conhece `EtapaTransicaoService`
  nem o sincronizador de etapa do plano 139-07 — o grafo de injeção continua acíclico. O plano
  139-07/139-08 é quem vai chamar `paraEmpresa()`/`progresso()` de fora e decidir a transição de
  etapa, nunca o contrário.
- Nenhum bloqueio identificado para os planos 139-06/139-07/139-08.

---
*Phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-09*

## Self-Check: PASSED

- FOUND: `app/Services/ChecklistAdministrativo/ChecklistAdministrativoService.php`
- FOUND: `tests/Feature/Phase139/ChecklistContagemItensTest.php`
- FOUND: `tests/Unit/Phase139/ChecklistProgressoTest.php`
- FOUND: `tests/Feature/Phase139/ChecklistMarcacaoManualAutoriaTest.php`
- FOUND commit: `5e44fe10`
- FOUND commit: `637143a3`
- FOUND commit: `5861f0bb`
