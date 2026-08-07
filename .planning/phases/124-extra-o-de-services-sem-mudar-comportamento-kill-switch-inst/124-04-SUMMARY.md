---
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
plan: 04
subsystem: operacional
tags: [refactor, service-extraction, kill-switch, feature-flag, comercial, hubspot, mlb-empresas]

# Dependency graph
requires: ["124-01", "124-02"]
provides:
  - "App\\Services\\Operacional\\EmpresaOperacionalRouter — lugar único que transforma serviço contratado em ficha de operação"
  - "Interruptor administrativo_bloqueio_ativo funcional, lido num ponto só, default desligado, provado nos dois lados"
  - "Ponto de extensão marcado (comentário) para a isenção Polos/D-09 que a Fase 128 vai preencher"
affects: [124-05]

# Tech tracking
tech-stack:
  added: []
  patterns: ["Service com duas APIs públicas delegando para um único método privado, ponto único de leitura de feature flag"]

key-files:
  created:
    - app/Services/Operacional/EmpresaOperacionalRouter.php
    - tests/Feature/Phase124KillSwitchTest.php
  modified: []

key-decisions:
  - "String literal da chave administrativo_bloqueio_ativo aparece só UMA vez no arquivo do router (na constante CHAVE_BLOQUEIO) — a mensagem de Log::warning referencia self::CHAVE_BLOQUEIO em vez de repetir o literal, para satisfazer o acceptance criteria grep -c=1 sem perder a rastreabilidade no log"
  - "Guard por empresa e dedup por tipo implementados como um único metodo privado rotear() parametrizado por bool guardPorEmpresa, evitando duplicar a leitura do interruptor em dois lugares"

patterns-established:
  - "Comentário marcador '// PONTO DE EXTENSÃO da Fase 128' sem nenhuma lógica de isenção implementada — grep de guarda confirmado vazio (polos.*isent|exige_contrato|servicos_isentos)"

requirements-completed: [REDE-01, FLUXO-04]

duration: ~20min
completed: 2026-08-07
---

# Fase 124 Plano 04: EmpresaOperacionalRouter + interruptor de emergência provado Summary

**`App\Services\Operacional\EmpresaOperacionalRouter` criado com as duas mecânicas de roteamento (guard-por-empresa do webhook vs. dedup-por-tipo sem guard do Comercial) preservadas lado a lado, e o interruptor `administrativo_bloqueio_ativo` lido num único ponto — desligado por padrão, comprovadamente capaz de interromper o roteamento nos dois pontos de entrada, sem nenhum chamador ainda.**

## Performance

- **Duration:** ~20 min
- **Completed:** 2026-08-07
- **Tasks:** 2/2
- **Files modified:** 2 (ambos novos)

## Accomplishments

- `app/Services/Operacional/EmpresaOperacionalRouter.php` criado (168 linhas): `rotearServico()` (mecânica do webhook, com guard `MlbEmpresa::exists()`) e `rotearCadastro()` (mecânica do Comercial, dedup por tipo, sem guard — D-08 preservado) delegam para um único método privado `rotear()`, que é o único ponto de leitura de `bloqueioAtivo()`
- Interruptor `administrativo_bloqueio_ativo` provado nos DOIS lados em `tests/Feature/Phase124KillSwitchTest.php` (5 testes, 9 assertions, todos verdes): nome da chave, default desligado sem gravação prévia, bloqueio efetivo via `rotearCadastro()` E via `rotearServico()` (zero `MlbEmpresa` e zero `MlbImplementacao` nos dois), e roteamento normal com a chave desligada (criação positiva de `MlbEmpresa` POLO + `MlbImplementacao`)
- Wrapper `criarImplementacaoPolo()` do controller NÃO reaproveitado — `MlbImplementacaoFactory::criarParaPolo()` chamado direto do router (D-03), confirmado por grep (`criarImplementacaoPolo` = 0 ocorrências no arquivo novo)
- Ponto de extensão da Fase 128 (isenção Polos/D-09) marcado só como comentário — nenhuma regra de isenção escrita (grep de guarda `polos.*isent|exige_contrato|servicos_isentos` = 0)
- Zero controllers tocados (`git status --porcelain app/Http/Controllers/` vazio nos dois commits) — o router nasce isolado, sem chamador; religar os caminhos é o plano 124-05
- Gate de regressão de 6 arquivos rodado como verificação extra (não exigido pelo `<output>` do plano, mas pela VALIDATION.md da fase): diff nominal contra `baseline-antes.txt` (criado no 124-03) retornou vazio — zero regressão por nome de teste

## Task Commits

1. **Task 1: Criar EmpresaOperacionalRouter com as duas mecânicas e o ponto de leitura do interruptor** - `8f720fd1` (feat)
2. **Task 2: Provar os dois lados do interruptor em teste isolado** - `ae172931` (test)

## Files Created/Modified

- `app/Services/Operacional/EmpresaOperacionalRouter.php` (novo) - classe `EmpresaOperacionalRouter`, namespace `App\Services\Operacional`; API pública `bloqueioAtivo()`, `rotearServico()`, `rotearCadastro()`; privados `rotear()` (ponto único do interruptor) e `criarFicha()` (os três ramos polos/assessoria/incubadora)
- `tests/Feature/Phase124KillSwitchTest.php` (novo) - 5 testes de `Tests\Feature\Phase124KillSwitchTest`, `RefreshDatabase`, exercitando o router direto via `app(EmpresaOperacionalRouter::class)`, sem HTTP

## Decisions Made

- **Deduplicação do literal da chave (achado durante a Task 1):** a acceptance criteria exige `grep -c "administrativo_bloqueio_ativo" arquivo` = 1 (leitura num ponto só). A primeira versão do docblock de classe citava o literal como exemplo, gerando uma segunda ocorrência de string (embora fora de código executável). Corrigido substituindo a menção no docblock por `` `self::CHAVE_BLOQUEIO` `` e trocando a mensagem do `Log::warning()` para interpolar `self::CHAVE_BLOQUEIO` em vez de repetir o literal — mantém a chave rastreável no log de produção sem duplicar a string-fonte no arquivo.
- Demais decisões seguiram o plano e o CONTEXT.md sem desvio: `iterable $nomesServicos` nas duas APIs públicas (D-01), pacote `$handoff` opcional espelhando a assinatura já existente de `criarParaPolo()` (D-02), interruptor lido só dentro do router (D-05), guard reaproveitado como está (D-07).

## Deviations from Plan

None - plano executado exatamente como escrito, com a correção de acurácia acima (redução do literal da chave de 2 para 1 ocorrência no arquivo), já coberta pela própria acceptance criteria do plano — não é desvio de comportamento, é ajuste para satisfazer a verificação automatizada explícita.

## Issues Encountered

Nenhum. O acceptance criteria automatizado (`grep -c`) pegou o problema do literal duplicado antes do commit, exatamente como desenhado.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `EmpresaOperacionalRouter` está pronto para ser consumido pelo plano `124-05`, que religa `ComercialController::store()` e `HubspotWebhookController::rotearImplementacao()` para chamá-lo no lugar do código inline
- O plano `124-05` deve remover o wrapper `ComercialController::criarImplementacaoPolo()` (D-03) ao religar o caminho Comercial
- O interruptor está provado e pronto para a Fase 128 (ponto de extensão marcado) e a Fase 133 (ativação em produção) — nenhum trabalho adicional de mecanismo pendente nesta fase
- Nenhum bloqueio identificado para o plano seguinte da fase

---
*Phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst*
*Completed: 2026-08-07*

## Self-Check: PASSED

- FOUND: app/Services/Operacional/EmpresaOperacionalRouter.php
- FOUND: tests/Feature/Phase124KillSwitchTest.php
- FOUND: commit 8f720fd1 (feat — Task 1)
- FOUND: commit ae172931 (test — Task 2)
