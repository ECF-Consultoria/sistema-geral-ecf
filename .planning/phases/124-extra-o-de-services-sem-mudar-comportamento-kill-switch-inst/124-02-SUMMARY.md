---
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
plan: 02
subsystem: testing
tags: [phpunit, feature-test, hubspot, webhook, mlb-empresas, mlb-implementacao, refactor-safety-net]

# Dependency graph
requires: []
provides:
  - "Baseline congelado do comportamento HOJE de HubspotWebhookController::criarEmpresa()/rotearImplementacao() (4 testes verdes)"
  - "Prova de Incubadora ponta a ponta pelo caminho webhook (MlbEmpresa sem MlbImplementacao)"
  - "Prova em teste da assimetria de gmail_colaborador entre Comercial e webhook (D-02) — webhook nunca preenche, cai no default de dadosPadrao()"
  - "Prova de FLUXO-05: empresa que já tem MlbEmpresa não ganha uma segunda quando um deal novo chega (guard da linha ~938 reaproveitado)"
  - "Prova de FLUXO-06: hubspot:reprocess-event contra empresa já roteada não duplica MlbEmpresa nem recria MlbImplementacao (token do link público preservado)"
affects: [124-03, 124-04, 124-05]

# Tech tracking
tech-stack:
  added: []
  patterns: ["Teste de caracterização (approval-style) que NÃO pode mudar após a refatoração — se mudar, é regressão"]

key-files:
  created: [tests/Feature/Phase124RegressaoHubspotTest.php]
  modified: []

key-decisions:
  - "Estagio default confirmado null (mesma tabela mlb_empresas do 124-01) — a migration 2026_05_08_000001_make_estagio_nullable_mlb_empresas superou o default 'Não Listado' da migration original; asserção usa assertNull, não presume literal"
  - "gmail_colaborador marcado explicitamente como assimetria TRANSITÓRIA — não é bug, é o comportamento vigente; a extração precisa preservar os dois lados (D-02 do CONTEXT)"
  - "FLUXO-05 testado via match FORTE por CNPJ (mesmo padrão de Phase113HubspotDedupTest) — garante que o cenário exercita o guard da linha ~938 sem depender de criação de empresa nova"
  - "FLUXO-06 testado disparando o webhook normal e depois rodando hubspot:reprocess-event contra o MESMO evento — reprocessarEvento() reusa criarEmpresa(), então o mesmo guard cobre o replay"

patterns-established:
  - "Docblock obrigatório em teste de caracterização explicando POR QUE o comportamento é preservado mesmo quando parece assimetria não intencional (gmail_colaborador) — espelha o padrão de D-08 do plano irmão 124-01"

requirements-completed: [FLUXO-04, FLUXO-05, FLUXO-06]

duration: ~25min
completed: 2026-08-07
---

# Fase 124 Plano 02: Caracterização do webhook HubSpot Summary

**4 testes de caracterização travando o comportamento atual de `HubspotWebhookController::criarEmpresa()` → `rotearImplementacao()` antes da extração de `EmpresaOperacionalRouter` — cobrindo Incubadora ponta a ponta, a assimetria do `gmail_colaborador`, FLUXO-05 (proteção contra ficha duplicada) e FLUXO-06 (replay não prende/duplica empresa já roteada).**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-08-07T15:49:27-03:00 (timestamp do commit)
- **Tasks:** 2 (ambas fundidas num único arquivo, conforme o plano previa — mesmo padrão do 124-01)
- **Files modified:** 1

## Accomplishments

- Arquivo `tests/Feature/Phase124RegressaoHubspotTest.php` criado com 4 testes, todos verdes de primeira contra o código de produção intocado
- Gap real de cobertura fechado: Incubadora ponta a ponta pelo webhook (antes só coberto pelo caminho Comercial no 124-01), FLUXO-05 e FLUXO-06 nunca tinham teste dedicado
- Assimetria do `gmail_colaborador` entre os dois caminhos de entrada documentada em teste com docblock explicando por que é comportamento vigente, não bug — espelha o `dadosPadrao()` em vez de hardcodar o literal
- FLUXO-05 provado exercitando o guard real (`MlbEmpresa::where('company_id', ...)->exists()`) via cenário de match FORTE por CNPJ, sem depender de criação de empresa nova
- FLUXO-06 provado rodando `hubspot:reprocess-event` contra um evento cuja empresa já tem ficha operacional — nem `MlbEmpresa`, nem `MlbImplementacao` (token preservado), nem `Company` duplicam
- Zero arquivos de produção tocados (`git status --porcelain app/` vazio)

## Task Commits

Ambas as tasks do plano foram implementadas no mesmo arquivo e commitadas juntas (o plano já previa que Task 2 estendesse o arquivo da Task 1 sem depender de commit isolado intermediário — mesmo racional do 124-01):

1. **Task 1 + Task 2: Caracterizar Incubadora, assimetria do gmail, FLUXO-05 e FLUXO-06** - `475c9de8` (test)

## Files Created/Modified

- `tests/Feature/Phase124RegressaoHubspotTest.php` - 4 testes de caracterização (381 linhas): Incubadora ponta a ponta, assimetria de gmail_colaborador, FLUXO-05 (guard anti-segunda-ficha), FLUXO-06 (replay não duplica)

## Decisions Made

- **Estagio default:** confirmado `null` por leitura das duas migrations de `mlb_empresas` — mesma conclusão já registrada no `124-01-SUMMARY.md` para o caminho Comercial (tabela compartilhada, mesmo default vigente). Não presumido pela migration original que declara `'Não Listado'`.
- **Cenário do FLUXO-05:** optou-se por reusar o padrão de match forte por CNPJ de `Phase113HubspotDedupTest` (empresa pré-existente + `MlbEmpresa` ASSESSORIA pré-criada) em vez de tentar simular duas chamadas de webhook consecutivas — é o caminho mais direto para exercitar o guard sem introduzir dependência entre disparos.
- **Cenário do FLUXO-06:** disparo normal do webhook (cria tudo do zero) seguido de `$this->artisan('hubspot:reprocess-event', ...)` contra o mesmo evento — confirma que `reprocessarEvento()` reusa o mesmo `criarEmpresa()` e portanto o mesmo guard, sem precisar mockar um cenário de replay isolado.
- Demais decisões seguiram o plano e o `124-CONTEXT.md` sem desvio.

## Deviations from Plan

None - plano executado exatamente como escrito. Todos os 4 testes passaram na primeira execução, sem necessidade de ajuste de asserção (diferente do 124-01, que teve uma correção de acurácia no `estagio`; aqui o valor já era conhecido pela leitura prévia do plano irmão).

## Issues Encountered

Nenhum. A leitura do `124-01-SUMMARY.md` antes de escrever este plano já havia esclarecido o literal correto de `estagio` (null, não `'Não Listado'`), evitando repetir a investigação.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `tests/Feature/Phase124RegressaoHubspotTest.php` está pronto para servir de baseline no gate da wave (comparação nominal, plano `124-03`), junto com `tests/Feature/Phase124RegressaoComercialTest.php` (124-01)
- Ambos os arquivos de caracterização (Comercial + HubSpot) da Wave 0 estão concluídos
- Nenhum bloqueio identificado para os planos seguintes da fase

---
*Phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst*
*Completed: 2026-08-07*

## Self-Check: PASSED

- FOUND: tests/Feature/Phase124RegressaoHubspotTest.php
- FOUND: commit 475c9de8 (test)
