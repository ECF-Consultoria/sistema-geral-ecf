---
phase: 76-respons-veis-por-servi-o-company-users-com-dimens-o-de-servi
plan: 03
subsystem: atribuicao-responsaveis
tags: [company-users, servico_id, escrita-escopada, isolamento-ml-shopee, idor]
requires:
  - "76-01 (migration servico_id + data-migration performance/consolidado)"
provides:
  - "3 escritas de atribuição escopadas por servico_id (bulkAssign Shopee, bulkAssign ML, update sync)"
  - "helper CompanyController::servicoPerformanceAtivoId()"
  - "teste de isolamento ML×Shopee (AtribuicaoPorServicoIsolamentoTest)"
affects:
  - "app/Http/Controllers/ShopeeEmpresasController.php"
  - "app/Http/Controllers/CompanyController.php"
tech-stack:
  added: []
  patterns: ["escrita escopada por (company_id, role, servico_id) com whereNull no slot consolidado", "detach escopado no lugar de detach() total"]
key-files:
  created:
    - "tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php"
  modified:
    - "app/Http/Controllers/ShopeeEmpresasController.php"
    - "app/Http/Controllers/CompanyController.php"
decisions:
  - "ML puro sem contrato performance ativo grava no slot consolidado (servico_id NULL), consistente com a data-migration do 76-01 (DEC-A3 / Open Question 1)"
metrics:
  duration: "~10 min"
  completed: "2026-07-14"
  tasks: 3
  files: 3
---

# Phase 76 Plan 03: Escritas de atribuição escopadas por `servico_id` Summary

Reescreveu as 3 escritas de atribuição de responsáveis (DEC-A3) para operarem
escopadas por `servico_id`, corrigindo o risco introduzido na Phase 75 em que
`ShopeeEmpresasController::bulkAssign` e `CompanyController::bulkAssign` apagavam
por `(company_id, role)` e o `CompanyController::update` fazia `detach()` de TUDO
— o que sobrescreveria o responsável do outro canal. Atribuir Shopee nunca mais
apaga o responsável ML e vice-versa; o guard anti-IDOR da Phase 75 foi preservado.

## O que foi feito

### Tarefa 1 — `ShopeeEmpresasController::bulkAssign` escopado (commit `eb9c2b0`)
- Resolve, por empresa, o `servico_id` do contrato Shopee ATIVO (join
  `contratos_servico`×`servicos` where `s.setor = Servico::SETOR_SHOPEE`).
- Apaga apenas o slot `(company_id, role, servico_id shopee)` e faz `attach` já
  com `servico_id` no array de pivot — não toca a linha ML/consolidada.
- Empresa sem contrato Shopee ativo resolvido é pulada (`continue`) — não grava
  linha órfã.
- Validação e closure guard anti-IDOR (`:168-172`) preservados intactos.

### Tarefa 2 — `CompanyController` bulkAssign + update sync escopados (commit `7977c62`)
- Novo helper privado `servicoPerformanceAtivoId(Company): ?int` — resolve o
  `servico_id` do contrato performance ativo (via `MIN()` determinístico), ou
  `null` para empresa ML pura → slot consolidado.
- `bulkAssign`: `delete` filtrado por `(company_id, role, servico_id)` usando
  `whereNull` quando o slot é consolidado (Pitfall 1); `attach` com `servico_id`.
- `update` sync: **eliminado o `$company->users()->detach()` total** (Pitfall 3),
  substituído por detach escopado às roles `consultor`/`estrategista` do slot
  performance/consolidado (filtro `whereNull`/`where` por `servico_id`). Linhas
  Shopee nunca são apagadas. Comportamento de "não mexer quando `$sync` vazio"
  preservado.

### Tarefa 3 — Teste de isolamento (commit `6ea9cdf`)
- `tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php` (5 casos, HTTP real
  como admin, usa a trait `CriaCenarioResponsaveis`):
  1. bulkAssign Shopee não altera a linha ML;
  2. bulkAssign ML não altera a linha Shopee;
  3. update sync não apaga a linha Shopee (prova do detach escopado);
  4. re-atribuição consolidada (`servico_id NULL`) repetida não duplica (prova
     `whereNull`);
  5. guard anti-IDOR: empresa fora do escopo Shopee → 422 sem gravar nada.

## Verificação

- `php artisan test tests/Feature/V16` → **16 passed (46 assertions)**, verde.
- `php artisan test tests/Feature/Phase75/Phase75ShopeeEmpresasTest.php` →
  **27 passed** (o bulkAssign Shopee da Phase 75 continua funcionando, guard IDOR
  incluído).

## Deviations from Plan

Nenhum desvio de implementação. Comando de verificação: usado `/c/xampp/php/php.exe`
em vez de `php` (PHP não está no PATH — nota do plano).

## Deferred Issues (fora de escopo)

Ao rodar `--filter=Companies`, 5 testes falham — confirmados **PRÉ-EXISTENTES**
(reproduzem com os controllers ANTES das edições do 76-03, via `git stash`).
Todos exercitam `CompanyController::index`/comercial/migration (prop `companies`
vem vazia por dependência de dados/serviços externos ausentes no ambiente de
teste) — nenhum toca as 3 escritas deste plano. Registrados em
`deferred-items.md`. NÃO corrigidos (scope boundary).

- `Phase18\CompaniesCustIdFilterTest` (2 testes) — `index` retorna `companies` vazio.
- `Phase13ComercialTest > guard duplicata companies`.
- `Phase13MigrationTest > companies retroativas tem status ativo`.
- `Phase14MlbControllerFiltroTest > companies index filtra pendentes publicidade gestao`.

## Known Stubs

Nenhum stub introduzido.

## Threat Flags

Nenhuma nova superfície de segurança fora do `<threat_model>` do plano. As
mitigações T-76-01 (guard IDOR), T-76-02 (escrita cross-serviço) e T-76-07 (slot
NULL com `whereNull`) foram implementadas e provadas por teste.

## Self-Check: PASSED

Todos os arquivos criados/modificados e os 3 commits (eb9c2b0, 7977c62, 6ea9cdf) verificados no working tree e no git log.
