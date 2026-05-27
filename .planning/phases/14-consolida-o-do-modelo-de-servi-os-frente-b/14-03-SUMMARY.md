---
phase: 14
plan: 03
subsystem: servicos
tags: [phase-14, services-consolidation, refactor, consumers-php, coexistence]
requires:
  - "Plan 14-01 (CobrancaCalculator helper)"
  - "Plan 14-02 (catálogo + contratos populados)"
provides:
  - "AdminController::fechamento/gerarRelatorio/gerarRelatorioGeral → CobrancaCalculator::novo"
  - "Company::labelFromServicos() (D-09)"
  - "MlbController + CompanyController filtros via whereHas"
  - "EmpresaCadastradaNotification (string|array backward compat)"
  - "EnviarRelatorioFechamentoJob payload com servicos_contratados"
  - "tests/Feature/Phase14AdminControllerCobrancaTest (3 testes)"
  - "tests/Feature/Phase14MlbControllerFiltroTest (2 testes)"
affects:
  - "Runtime PHP NÃO LÊ MAIS campos legacy em CÁLCULOS"
  - "Mappers Inertia mantêm chaves legacy + ADICIONAM servicos_contratados (coexistência Wave 2)"
tech_added: []
patterns_used:
  - "Helper estático puro (CobrancaCalculator::novo) consumido pelos 5 call-sites de cálculo"
  - "Eager loading with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')]) — evita N+1 (Pitfall 2)"
  - "whereHas('contratosServico') → JOIN servicos.nome em vez de whereJsonContains"
  - "Backward compat string|array na assinatura da Notification (até Plan 14-04)"
  - "Comentários TODO Plan 14-06 marcam chaves/regras legacy a remover no drop"
  - "Deprecated proxy: Company::labelFromTypes mantido como compat para Blade views (refatoradas no Plan 14-05)"
key_files:
  created:
    - "tests/Feature/Phase14AdminControllerCobrancaTest.php"
    - "tests/Feature/Phase14MlbControllerFiltroTest.php"
    - ".planning/phases/14-consolida-o-do-modelo-de-servi-os-frente-b/deferred-items.md"
  modified:
    - "app/Models/Company.php"
    - "app/Http/Controllers/AdminController.php"
    - "app/Http/Controllers/MlbController.php"
    - "app/Http/Controllers/CompanyController.php"
    - "app/Notifications/EmpresaCadastradaNotification.php"
    - "app/Jobs/EnviarRelatorioFechamentoJob.php"
decisions:
  - "Helper retorna float; `?: null` no caller preserva semântica legacy 'null quando vazio'"
  - "Chaves legacy mantidas nos mappers Inertia ao lado de servicos_contratados — removidas só no Plan 14-06"
  - "labelFromTypes mantido como deprecated proxy enquanto as 3 Blade views ainda consomem (refator no Plan 14-05)"
  - "Notification.meta passa de 'service_type' (string) para 'servicos' (array) — string ainda aceita até Plan 14-04"
  - "EnviarRelatorioFechamentoJob.servicos_contratados formatado como 'Nome (R$ X,XX)' — refator da Blade do email no Plan 14-05"
  - "Filtro filtra por nome legível (Publicação, Polos, etc.) em vez de slug (publicacao, polos) — D-01"
metrics:
  duration_minutes: 10
  task_count: 3
  files_created: 3
  files_modified: 6
  test_assertions: 43
  completed_date: 2026-05-26
---

# Phase 14 Plan 03: Refator de consumers PHP — Summary

Refatora os 6 consumers PHP que liam os campos legacy em CÁLCULOS para usar o novo modelo `contratos_servico`. Após este plan, o runtime PHP NÃO LÊ MAIS os 6 campos legacy em cálculos — apenas no mapper Inertia (coexistência Wave 2) e validation rules dormentes (marcadas com TODO Plan 14-06).

## Objetivo

Garantir SVC-02 (fatura idêntica até R\$ 0,01), SVC-04 (filtros via JOIN) e SVC-07 (notification/job consomem contratos) com:
- **5 call-sites** de cálculo de `cobranca_mensal` em `AdminController` refatorados para `CobrancaCalculator::novo`
- **2 filtros** `whereJsonContains('service_type', ...)` substituídos por `whereHas('contratosServico')` JOIN `servicos.nome`
- **1 filtro** `whereJsonContains` em `AdminController::gerarRelatorioGeral` substituído por `whereHas` (D-01 com lookup slug→nome)
- **1 helper estático** `Company::labelFromServicos` adicionado; `labelFromTypes` marcado @deprecated
- **1 notification** com backward compat `string|array` na assinatura
- **1 job** com chave nova `servicos_contratados` no payload de email
- **5 testes feature** novos garantindo o invariante financeiro

## Arquivos Modificados

### Código de produção (6)

| Arquivo | Mudança principal |
| ------- | ----------------- |
| `app/Models/Company.php` | + `labelFromServicos()` D-09; `labelFromTypes` @deprecated; `getServiceTypeLabelAttribute` deriva de contratos ativos |
| `app/Http/Controllers/AdminController.php` | 5 sites de cálculo → `CobrancaCalculator::novo`; 4 queries com eager loading; 4 mappers Inertia com `servicos_contratados` adicionado; filtro `service_type` via `whereHas` |
| `app/Http/Controllers/MlbController.php` | Filtro empresas_pendentes via `whereHas` (Publicação/Polos/Assessoria) |
| `app/Http/Controllers/CompanyController.php` | Filtro empresas_pendentes via `whereHas` (Publicidade/Gestão) |
| `app/Notifications/EmpresaCadastradaNotification.php` | Construtor aceita `string|array $servicos`; meta passa de `service_type` p/ `servicos` |
| `app/Jobs/EnviarRelatorioFechamentoJob.php` | Eager loading + chave `servicos_contratados` no payload das vinculadas |

### Testes (2 arquivos / 5 testes)

| Arquivo | Testes | Assertions |
| ------- | ------ | ---------- |
| `tests/Feature/Phase14AdminControllerCobrancaTest.php` | 3 | 12 |
| `tests/Feature/Phase14MlbControllerFiltroTest.php` | 2 | 31 |
| **Total novos** | **5** | **43** |

### Outros

| Arquivo | Conteúdo |
| ------- | -------- |
| `.planning/phases/14-.../deferred-items.md` | Falhas pré-existentes em `AdminFechamentoControllerTest` (5 testes) — fora de escopo |

## Tarefas Executadas

### Task 1: Company.php + AdminController.php (`0f9234a`)

- **Commit:** `refactor(14-03): Company.labelFromServicos + AdminController via CobrancaCalculator::novo`
- `import App\Support\CobrancaCalculator` no controller
- Company.php: nova função `labelFromServicos(iterable)` com joiner `, `; `labelFromTypes` mantido como proxy `@deprecated`; `getServiceTypeLabelAttribute` agora chama `loadMissing('contratosServico.servico')` + `labelFromServicos`
- AdminController.php: 5 chamadas a `CobrancaCalculator::novo` (verificado via grep)
  - `fechamento()` linha ~293
  - `gerarRelatorio()` filhas linha ~547; pai linha ~575
  - `gerarRelatorioGeral()` filhas linha ~727; pai linha ~756
- Eager loading `contratosServico` adicionado em `empresas()`, `fechamento()`, `gerarRelatorio()`, `gerarRelatorioGeral()`
- Chave `servicos_contratados` adicionada aos arrays Inertia (mapa para `empresas()`/`fechamento()`; string formatada `, ` para `gerarRelatorio()`/`gerarRelatorioGeral()` filhas; chave `servicos_contratados_pai` no array passado para Blade)
- `updateEmpresa()` e `updateFechamento()`: validation rules legacy preservadas + `TODO Plan 14-06: remover ...`
- Filtro `service_type` em `gerarRelatorioGeral` migrado para `whereHas` com lookup slug→nome (D-01)

### Task 2: MlbController + CompanyController + Notification + Job (`c47592d`)

- **Commit:** `refactor(14-03): consumers PHP (MLB+CompanyController+Notification+Job) usam contratos`
- MlbController::empresas() — filtro `empresas_pendentes` via `whereHas('contratosServico')` JOIN `servicos.nome IN ('Publicação','Polos','Assessoria')`; `transform()` adiciona array `servicos_contratados` ao lado de `service_type`
- CompanyController::index() — análogo para Publicidade/Gestão
- EmpresaCadastradaNotification — construtor `string|array $servicos`; explode('+') quando string legacy; meta `'servicos' => $servicosNomes`
- EnviarRelatorioFechamentoJob — eager loading pai + filhas; chave `servicos_contratados` no formato `"Nome (R$ X,XX), Outro (R$ Y,YY)"` derivada de `contratosServico.servico`
- `grep -nE "whereJsonContains.*service_type" app/Http/Controllers/` retorna 0 matches em código (apenas em comentários explicativos)

### Task 3: Tests (`2f923e1`)

- **Commit:** `test(14-03): golden cobrança AdminController + filtros whereHas (5 testes)`
- `Phase14AdminControllerCobrancaTest` (3/3 verdes):
  - Golden test: empresa com `additional_service_price=200` + contrato `Treinamento R$ 200` + faturamento R\$ 600k → `cobranca_mensal === 4700` (faixa_2 + Treinamento)
  - Comando `phase14:verificar-cobranca --abort-on-divergence` retorna exit 0 em estado consistente
  - `servicos_contratados` aparece nas props Inertia ao lado de `service_type` (coexistência)
- `Phase14MlbControllerFiltroTest` (2/2 verdes):
  - `/mlb/empresas` filtra pendentes Polos (não retorna Publicidade nem ativas)
  - `/companies` filtra pendentes Publicidade/Gestão (não retorna Polos)
- Falhas pré-existentes em `AdminFechamentoControllerTest` (5 testes) confirmadas via `git stash` e documentadas em `deferred-items.md` (fora de escopo).

## Comandos de Verificação

```bash
# Linting
c:/xampp/php/php.exe -l app/Models/Company.php                                       # OK
c:/xampp/php/php.exe -l app/Http/Controllers/AdminController.php                     # OK
c:/xampp/php/php.exe -l app/Http/Controllers/MlbController.php                       # OK
c:/xampp/php/php.exe -l app/Http/Controllers/CompanyController.php                   # OK
c:/xampp/php/php.exe -l app/Notifications/EmpresaCadastradaNotification.php          # OK
c:/xampp/php/php.exe -l app/Jobs/EnviarRelatorioFechamentoJob.php                    # OK

# Suíte Phase 14 (combinada)
php artisan test --filter='Phase14|CobrancaCalculator'
# >>> Tests: 21 passed (102 assertions) Duration: 9.11s

# Comando verificador permanece verde
php artisan test --filter=Phase14VerificarCobrancaTest
# >>> 3 passed

# Grep de cleanup do refator
grep -nE "whereJsonContains.*service_type" app/Http/Controllers/
# >>> Apenas em comentários explicativos (não em código)

grep -nE "CobrancaCalculator::novo" app/Http/Controllers/AdminController.php
# >>> 5 chamadas reais (linhas 293, 547, 575, 727, 756)
```

## Critérios de Sucesso vs. Realização

| # | Critério | Status |
|---|----------|--------|
| 1 | AdminController usa `CobrancaCalculator::novo` nos 3 sites (5 chamadas reais — pai + filha) | OK |
| 2 | Eager loading evita N+1 nas queries de fechamento/relatório | OK |
| 3 | MlbController e CompanyController usam `whereHas` | OK |
| 4 | EmpresaCadastradaNotification aceita backward compat `string\|array` | OK |
| 5 | Job payload com chave `servicos_contratados` formatada | OK |
| 6 | `Company::labelFromServicos` existe; `labelFromTypes` mantido deprecated | OK |
| 7 | `phase14:verificar-cobranca` retorna 0 divergências em teste | OK |
| 8 | Golden test confirma idempotência financeira (4.700 ≡ 4.700 até R\$ 0,01) | OK |
| 9 | Coexistência respeitada (chaves legacy nos mappers Inertia + `servicos_contratados`) | OK |

## Commits do Plan 14-03

| Hash | Tipo | Mensagem |
|------|------|----------|
| `0f9234a` | refactor | Company.labelFromServicos + AdminController via CobrancaCalculator::novo (Task 1) |
| `c47592d` | refactor | consumers PHP (MLB+CompanyController+Notification+Job) usam contratos (Task 2) |
| `2f923e1` | test | golden cobrança AdminController + filtros whereHas (5 testes — Task 3) |

## Decisões de Execução

- **Coexistência Wave 2 rigorosamente respeitada:** todas as chaves legacy (`service_type`, `contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`) permanecem nos arrays Inertia/payload do Job/notification ao lado de `servicos_contratados`. Comentários explicativos `// ─── Chaves legacy — TODO Plan 14-06: remover após drop ───` em todos os mappers para facilitar o cleanup futuro.
- **`labelFromTypes()` mantido como `@deprecated` proxy:** evita quebrar as 6 chamadas em 3 Blades (`admin/relatorio-fechamento.blade.php`, `admin/relatorio-geral.blade.php`, `admin/relatorio-geral-pdf.blade.php`) — refator das Blades fica para o Plan 14-05.
- **`getServiceTypeLabelAttribute()` usa `loadMissing`:** Phase 14 (Frente B) — quando chamado em loop Blade sem eager loading explícito, o accessor força carregamento via `loadMissing('contratosServico.servico')` — defesa em profundidade contra N+1.
- **`EmpresaCadastradaNotification` aceita `string|array`:** assinatura union mantém todos os callers (1 em `ComercialController` ainda passa string) funcionando — string legacy convertida via `explode('+')` + `trim()`. Forma string marcada `@deprecated` — Plan 14-04 refator final do ComercialController.
- **Filtro slug→nome no `gerarRelatorioGeral`:** o request continua aceitando o slug legacy (`publicacao`, `polos`, etc.) para compat com a UI atual; lookup interno mapeia para o `nome` do catálogo antes do `whereHas`. UI nova (Plan 14-05) poderá passar diretamente o nome.
- **Job payload formatado como string `"Nome (R$ X,XX)"`:** mais fácil de consumir no email-template Blade do que array de objetos. View Blade do email (`resources/views/emails/relatorio-fechamento.blade.php`, se existir) será refatorada no Plan 14-05.
- **Testes usam `viewData('page')['props']`:** comparação numérica direta via `assertEqualsWithDelta` (Inertia serializa `4700.0` como `4700` em JSON e `->where(..., 4700.0)` falha por `===`). Padrão consagrado.
- **Falhas pré-existentes em `AdminFechamentoControllerTest` (5 testes):** detectadas via `git stash` — não introduzidas por este plan. Movidas para `deferred-items.md` por estarem fora de escopo (SCOPE BOUNDARY do executor).

## Sistema em COEXISTÊNCIA

**Estado atual após Plan 14-03:**

- `companies.service_type`, `contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price` — **AINDA POPULADOS** (drop só no Plan 14-06)
- `contratos_servico` — **POPULADO** com contratos derivados (1 por slug + 1 por additional_service)
- **Runtime PHP NÃO LÊ MAIS campos legacy em CÁLCULOS** — apenas nos mappers Inertia (coexistência) e nas validation rules dormentes (marcadas TODO Plan 14-06)
- **Filtros já usam o novo modelo** (`whereHas('contratosServico')` em vez de `whereJsonContains`)
- **Notification + Job já consomem o novo modelo** (com backward compat onde aplicável)
- **UI (Blade + JSX) ainda lê chaves legacy** — refator dos JSX (Plan 14-05) e drop final (Plan 14-06) pendentes

**Próximos plans:**
- **Plan 14-04 — ComercialController:** refator do form de cadastro + helper `servicoDisparaImplementacao()`. Removerá o último caller que ainda passa `string` para `EmpresaCadastradaNotification`.
- **Plan 14-05 — UI Financeiro:** refator das 3 Blade views (`relatorio-fechamento`, `relatorio-geral`, `relatorio-geral-pdf`) + JSX (`Admin/Financeiro.jsx`, `Comercial/Empresas.jsx`, `Comercial/NovaEmpresa.jsx`) consumindo `servicos_contratados`.
- **Plan 14-06 — Drop irreversível:** roda `phase14:verificar-cobranca --abort-on-divergence` (CHECKPOINT humano); se exit 0, dropa as 6 colunas legacy + remove chaves legacy de todos os arrays Inertia + remove `labelFromTypes` + validation rules legacy + remove forma `string` da Notification.

## Deviations from Plan

Nenhuma — plan executado conforme escrito. Apenas dois detalhes operacionais documentados como decisões de execução:
- Testes usam `viewData('page')['props']` para comparações numéricas (Inertia serializa `4700.0` como `4700`)
- Comentário sobre `5 chamadas reais` de `CobrancaCalculator::novo` (3 sites de cálculo × 2 níveis pai/filha — exceto `fechamento()` que tem só 1 nível agregado)

## Threat Flags

Nenhuma. O plano:

- **Não introduz endpoints novos** — refator interno de queries/serialização
- **Não toca auth/RBAC** — middleware/policies inalterados
- **Não muta schema** — apenas refator de leitura
- **Operação financeira INVARIANTE** validada por golden test + comando automatizado

## Self-Check: PASSED

- Arquivo `app/Models/Company.php` modificado (verificado via `php -l` + grep `labelFromServicos`)
- Arquivo `app/Http/Controllers/AdminController.php` modificado (verificado via `php -l` + 5 grep matches em `CobrancaCalculator::novo`)
- Arquivo `app/Http/Controllers/MlbController.php` modificado (verificado via `php -l`)
- Arquivo `app/Http/Controllers/CompanyController.php` modificado (verificado via `php -l`)
- Arquivo `app/Notifications/EmpresaCadastradaNotification.php` modificado (verificado via `php -l`)
- Arquivo `app/Jobs/EnviarRelatorioFechamentoJob.php` modificado (verificado via `php -l`)
- Arquivo `tests/Feature/Phase14AdminControllerCobrancaTest.php` criado (3/3 verdes, 12 assertions)
- Arquivo `tests/Feature/Phase14MlbControllerFiltroTest.php` criado (2/2 verdes, 31 assertions)
- Suíte combinada Phase 14: 21/21 verdes (102 assertions)
- Commits `0f9234a`, `c47592d`, `2f923e1` presentes em `git log --oneline`
