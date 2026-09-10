---
phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 08
subsystem: backend
tags: [checklist-administrativo, fluxo-entrada, permissoes, inertia, etapa-transicao, http]

# Dependency graph
requires:
  - phase: 152-05
    provides: "ChecklistAdministrativoService::paraEmpresa()/concluirManualmente()/reabrirItem()/gerarConexaoEcf() — as mutações que os endpoints expõem"
  - phase: 152-06
    provides: "FinalizarEntradaAdministrativaService::podeFinalizar()/finalizar() — a régua do payload e o efeito do botão"
  - phase: 152-07
    provides: "ChecklistEtapaSincronizadorService::sincronizar() — a régua da D-15, disparada exclusivamente por esta camada"
  - phase: 151
    provides: "Permissions::COMERCIAL_ENTRADA e a listagem Comercial › Entrada que abre esta ficha"
  - phase: 131
    provides: "ContratoAdminController::show() e a tela Admin/ContratoDetalhe que passa a servir as duas listagens"
provides:
  - "Rota admin.contratos.show com permission:admin.contratos,comercial.entrada — a ficha única da D-08"
  - "4 rotas de ação: admin.contratos.checklist.concluir/reabrir/conexao-ecf e admin.contratos.finalizar-entrada"
  - "ContratoAdminController::sincronizarEtapaChecklist() — o único ponto do sistema que chama sincronizar()"
  - "Props novas em Admin/ContratoDetalhe: checklist, pode_ver_contrato, pode_finalizar, adman_register_url"
affects: [152-09, 152-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Permissão de ROTA em OR abre a ficha; permissão de MÓDULO recorta o payload dentro dela — as duas camadas são necessárias, e a segunda é o que impede a primeira de virar vazamento"
    - "Funil privado de mutação (executarMutacaoChecklist) com efeito colateral obrigatório no ramo de sucesso: quem acrescentar endpoint novo ou passa pelo funil ou fica visivelmente fora do padrão na revisão"
    - "Ponto ÚNICO de chamada verificável por grep como gate de plano (`grep -c -- '->sincronizar('` = 1) — o invariante vira asserção mecânica, não convenção"
    - "Sincronizar ANTES de finalizar na camada HTTP, e não afrouxar a máquina de estados: o mesmo cenário é 'recusado' no service e 'finalizado' pela rota, de propósito, e os dois testes convivem com a distinção escrita"
    - "Neutralizar prop existente (contratos => [], pode_gerar_contrato => false) em vez de removê-la do payload — o JSX continua recebendo a chave e não quebra com undefined"

key-files:
  created:
    - tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php
    - tests/Feature/Phase152/ChecklistEndpointsTest.php
  modified:
    - routes/web.php
    - app/Http/Controllers/ContratoAdminController.php

key-decisions:
  - "D-17 implementada: admin.contratos.show saiu do grupo permission:admin.contratos e virou rota solta com permission:admin.contratos,comercial.entrada (OR nativo do EnsurePermission). Nenhuma chave de permissão nova (D-09) — Permissions.php não foi tocado"
  - "T-152-08-01 mitigada: sem admin.contratos o payload perde o grupo `contrato` do checklist e recebe contratos => [], pode_gerar_contrato => false, motivo_bloqueio => null. company/contratos_servico/faltantes continuam para os dois perfis — a listagem Entrada já os exibe, não há exposição nova"
  - "O progresso do checklist continua sendo o da empresa INTEIRA para o perfil de Entrada, de propósito: é a régua do FINALIZAR, não uma métrica da seção visível"
  - "D-15 na camada HTTP: sincronizarEtapaChecklist() é o único chamador de sincronizar() no sistema (gate por grep = 1). show() sincroniza porque o degrau 2→3 depende do webhook do Clicksign gravando enviado_em — evento externo que nenhuma ação de checklist observa"
  - "finalizarEntradaAdministrativa() sincroniza ANTES de chamar finalizar(): a empresa pode estar na etapa 2 ou 3 no clique, e TRANSICOES_PERMITIDAS só permite chegar na 5 vindo da 4. A alternativa (afrouxar a tabela) escreveria etapa sem histórico"
  - "IDOR removido por construção: {company} por route-model-binding e {chave} contra catálogo fechado — nenhum id de linha de checklist atravessa a fronteira HTTP"
  - "$forcar de concluirManualmente() NÃO é exposto por HTTP nesta fase — item automático não se marca à mão pela tela"

patterns-established:
  - "Em teste de checklist, contar por CHAVE e nunca o total da empresa: paraEmpresa() — chamada por dentro do sincronizador — persiste linha própria para cada um dos 4 itens automáticos, então o total nunca é o número de itens marcados à mão"
  - "companies.cnpj é UNIQUE: fixture que cria mais de uma empresa na mesma suíte precisa de sequência de CNPJ, senão o segundo create() morre com Integrity constraint violation"

requirements-completed: [ADMIN-01, ADMIN-03, ADMIN-04, ADMIN-05, ADMIN-06]

# Metrics
duration: ~50min
completed: 2026-09-10
---

# Phase 152 Plan 08: a camada HTTP que liga tudo Summary

**A ficha única passa a abrir para as duas listagens (D-17), serve o checklist e a régua do FINALIZAR no payload com a seção Contrato recortada pela permissão de módulo, expõe os quatro endpoints de ação — e é a camada que de fato dispara a D-15, já que os três serviços do checklist não se conhecem em ciclo.**

## Performance

- **Duration:** ~50 min
- **Completed:** 2026-09-10
- **Tasks:** 3/3
- **Files modified:** 4 (2 criados, 2 alterados)

## Accomplishments

### Task 1 — a ficha em OR e as 4 rotas de ação

- `admin.contratos.show` **saiu do grupo** `permission:admin.contratos` e virou rota solta com
  `permission:admin.contratos,comercial.entrada`. As demais rotas do grupo (`index`, `cadastro`,
  `gerar`, `reenviar`, `cancelamento`, `refazer`, `liberacao-manual`) **continuam** restritas —
  provado por caso de teste dedicado, o OR não vazou.
- Grupo irmão com as 4 rotas de ação (`checklist.concluir`, `checklist.reabrir`,
  `checklist.conexao-ecf`, `finalizar-entrada`) na mesma permissão em OR.
- `app/Support/Permissions.php` **não foi tocado** (`git diff --name-only` vazio) — D-09 cumprida:
  nenhuma chave nova, as duas já são liberáveis por setor sem deploy.
- `ChecklistAcessoPorEntradaTest` — asserção por `gatherMiddleware()` em vez de texto do arquivo de
  rotas, que pega também o caso de a rota ser envolvida por um grupo externo depois.

### Task 2 — `show()` gated por módulo e o funil de sincronização

- `show()` ganhou `Request $request` como primeiro parâmetro e mais **dois** serviços injetados como
  irmãos dos já existentes. O terceiro (o sincronizador) é resolvido dentro do funil, para a forma
  ser a mesma em todos os chamadores.
- `sincronizarEtapaChecklist()` — o **único** ponto do sistema que invoca `sincronizar()`
  (`grep -c` = 1, gate do plano). `show()` sincroniza porque o degrau 2→3 depende do webhook do
  Clicksign gravando `enviado_em`, evento externo que nenhuma ação de checklist observa; o
  carregamento da ficha é o único momento em que o sistema o vê.
- Ordem obrigatória em `show()`: `paraEmpresa()` (persiste o resultado dos 4 resolvers) →
  `sincronizarEtapaChecklist()` → `podeFinalizar()`, que precisa enxergar a empresa já na etapa certa.
- Gating por módulo (T-152-08-01): sem `admin.contratos`, o grupo `contrato` sai de
  `checklist.grupos` e `contratos`/`pode_gerar_contrato`/`motivo_bloqueio` chegam neutralizados.
  `company`, `contratos_servico` e `faltantes` continuam para os dois perfis.
- Props novas: `checklist`, `pode_ver_contrato`, `pode_finalizar` (o array inteiro — um desabilita o
  botão, o outro explica por quê) e `adman_register_url` de `config()` (D-04).

### Task 3 — os 4 endpoints por um funil único

- `guardaChecklist()` — `abort_unless(in_array($chave, ...chaves(true)), 404)` antes de qualquer
  chamada ao service (a `{chave}` é segmento de URL; `$request->validate()` não a alcança), e
  recorte por grupo: item de `contrato` exige `admin.contratos`, senão 403.
- `executarMutacaoChecklist()` — funil que roda a mutação em `try/catch (\DomainException)`,
  devolve `error` **sem sincronizar** no ramo de exceção (nada mudou), e sincroniza + devolve
  `success` no ramo de sucesso.
- Os quatro métodos públicos: `concluirItemChecklist`, `reabrirItemChecklist`,
  `gerarConexaoEcfChecklist` (os três pelo funil) e `finalizarEntradaAdministrativa`, que
  **sincroniza antes** e traduz os três desfechos de `finalizar()` para `success`/`error`/`error` +
  `Log::error`.
- Ator sempre `$request->user()`; `input('user_id')` não aparece no arquivo (gate por grep = 0).
  Só as chaves de flash já compartilhadas por `HandleInertiaRequests`.
- `ChecklistEndpointsTest` — 12 casos pela porta HTTP, todos com asserção de etapa por reconsulta ao
  banco, incluindo o 404 da chave inventada, o 403 do grupo Contrato, a idempotência da conexão ECF,
  o isolamento entre empresas e o legado com `etapa` NULL.

### Números

- Suíte da fase `tests/Unit/Phase152 + tests/Feature/Phase152` — **92 testes / 364 assertions,
  100% verde** (+24 testes / +139 assertions sobre 68/225 do fechamento do 152-07).
- Regressão `tests/Feature/Phase131 + tests/Feature/Phase151 + tests/Unit/Phase150 +
  tests/Feature/Phase150` — **245 testes / 910 assertions, 100% verde**. A parte 137/138 fecha em
  **123 testes / 444 assertions**, número por número **idêntica** ao `152-BASELINE-TESTES.md`; a
  Phase131 fecha em 122/466.

## Task Commits

Each task was committed atomically:

1. **Task 1: Rota da ficha em OR e as 4 rotas de ação (D-17)** - `4a3d2fea` (feat)
2. **Task 2: show() serve o checklist gated por módulo e sincroniza a etapa** - `7402db7d` (feat)
3. **Task 3: Os 4 endpoints por um funil único** - `80908116` (feat)

## Files Created/Modified

- `routes/web.php` - rota `show` solta com o OR + grupo novo com as 4 rotas de ação
- `app/Http/Controllers/ContratoAdminController.php` - `show()` alterado, 1 funil de sincronização,
  1 guarda, 1 funil de mutação e 4 métodos públicos novos
- `tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php` - 12 testes, 86 assertions
- `tests/Feature/Phase152/ChecklistEndpointsTest.php` - 12 testes, 53 assertions

## Decisions Made

Nenhuma decisão nova de produto — as três tasks implementam à letra D-17, D-08, D-09, D-04 e D-15,
já travadas no `152-CONTEXT.md`. Uma escolha **técnica** foi feita dentro do espaço que a `<action>`
da Task 2 explicitamente deixou aberto ("resolver o sincronizador dentro do helper por `app(...)`
**ou** injetá-lo no construtor do controller — escolher uma das duas e usar a mesma forma em todos
os métodos"): optou-se por `app(ChecklistEtapaSincronizadorService::class)` **dentro do funil**. O
motivo é que os quatro endpoints são actions separadas, e injetar por construtor obrigaria o
controller inteiro — inclusive `index()`, que nada tem a ver com checklist — a resolver os serviços
do checklist em toda requisição. Pelo mesmo raciocínio, os serviços de mutação
(`ChecklistAdministrativoService`, `FinalizarEntradaAdministrativaService`) são resolvidos por
`app()` dentro de cada endpoint, e por injeção de método em `show()`, onde já existem outros sete
serviços injetados dessa forma.

## Deviations from Plan

**Nenhum desvio de comportamento.** Dois pontos de processo valem registro:

### 1. Estado intermediário entre a Task 1 e a Task 3 (esperado, não acidental)

O commit da Task 1 (`4a3d2fea`) declara 4 rotas apontando para métodos de controller que só passam
a existir no commit da Task 3 (`80908116`). Isso é o que a ordem de tasks do plano produz. É inócuo
em runtime — Laravel resolve o método do controller só no **dispatch**, não no registro da rota, e
nenhum teste da Task 1 faz POST nessas rotas. O ponto para quem for auditar o histórico: `4a3d2fea`
isolado não é um estado deployável, `80908116` é.

### 2. Duas correções de fixture no teste da Task 3 (nenhuma mudança de produção)

Ambas apareceram na primeira execução e foram corrigidas antes do commit:

- **`companies.cnpj` é UNIQUE.** O caso 11 cria duas empresas na mesma suíte, e a fixture usava CNPJ
  fixo — o segundo `create()` morria com `Integrity constraint violation`. Resolvido com sequência
  de CNPJ na fixture.
- **Contar por chave, nunca o total.** O caso 5 esperava 1 linha em
  `checklist_administrativo_itens` depois de uma marcação e encontrou 5: `paraEmpresa()`, chamada
  por dentro do sincronizador, **persiste linha própria para cada um dos 4 itens automáticos**. A
  asserção passou a ser `assertDatabaseHas` da chave marcada. O comportamento do sistema está
  correto — era a expectativa do teste que estava errada.

**Total deviations:** 0 mudanças de comportamento, 2 correções de fixture de teste.

## Issues Encountered

Além das duas correções de fixture acima, nenhum. Todos os gates estáticos do plano passaram na
primeira medição:

- `grep -c -- '->sincronizar('` no controller = **1** (o invariante do funil).
- `grep -c 'executarMutacaoChecklist('` = **4** (1 definição + 3 chamadas).
- `grep -c "input('user_id')"` = **0**.
- `git diff --name-only` de `Permissions.php` e de `ChecklistAdministrativoService.php` = **vazio**.
- `paraEmpresa($company)` com **um** argumento.

## User Setup Required

None - nenhuma configuração de serviço externo. A chave `services.adman.register_url` já foi
publicada no plano 152-01.

## Next Phase Readiness

- O payload de `Admin/ContratoDetalhe` já entrega tudo o que o plano **152-09** (a UI) precisa:
  `checklist` (com `grupos`, `progresso` e `exige_contrato`), `pode_ver_contrato` para esconder o
  card de Contrato, `pode_finalizar` com `permitido` + `requisito_faltante` para o botão e o
  tooltip, e `adman_register_url` para o item 6.
- Os 4 endpoints estão prontos para os `router.post` do JSX. **Atenção para o 152-09:** o
  `requisito_faltante` já vem em texto pronto do servidor — não reconstruir a frase no front, senão
  passam a existir duas fontes de verdade para a mesma recusa.
- **Proibições que o 152-09 herda:** não reconstruir a régua do FINALIZAR no cliente (o botão lê
  `pode_finalizar`, nunca recalcula); e não hard-codar o link do Adman no JSX (D-04).
- Restam os planos **152-09** (UI) e **152-10** (fechamento da fase) para fechar a 139.
- Nenhum bloqueio identificado.

---
*Phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-10*

## Self-Check: PASSED

- FOUND: `routes/web.php` com `permission:admin.contratos,comercial.entrada`
- FOUND: `app/Http/Controllers/ContratoAdminController.php` com `sincronizarEtapaChecklist`
- FOUND: `tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php`
- FOUND: `tests/Feature/Phase152/ChecklistEndpointsTest.php`
