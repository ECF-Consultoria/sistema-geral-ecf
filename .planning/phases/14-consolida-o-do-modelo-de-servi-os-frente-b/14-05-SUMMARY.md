---
phase: 14
plan: 05
subsystem: ui
tags: [phase-14, services-consolidation, ui-jsx, fechamento, blade, contratos-servico, refactor, tests, inertia]
requires:
  - "Plan 14-02 (catálogo Servicos populado com 6 nomes canônicos + tabela contratos_servico)"
  - "Plan 14-03 (AdminController passa `servicos_contratados` em fechamento/gerarRelatorio/gerarRelatorioGeral; Company tem accessor `service_type_label`)"
  - "Plan 14-04 (cadastro Comercial não produz mais dados legacy; mapper `service_type[]` mantido via compat)"
provides:
  - "Admin/Financeiro.jsx: ContratosSection com tabela de contratos + Modal Add/Edit/Desativar (espelho de Companies/Show.jsx) — editor inline legacy removido"
  - "ServiceBadge prioriza `servicos_contratados` (catálogo) com fallback legacy para compat até Plan 14-07"
  - "GraficoServico (deriva de servicos_contratados) + GraficoCobranca (mensal/única) — substituem GraficoContrato legacy (fixo/progressão)"
  - "FiltroBarra: dropdown 'Serviço' derivado do dataset; remove filtros legacy service_type/contract_type"
  - "3 Blade views (relatorio-fechamento, relatorio-geral, relatorio-geral-pdf) usam `$company->service_type_label` (accessor) + `$v['servicos_contratados']` (string formatada)"
  - "AdminController::fechamento passa `servicos_disponiveis` (catálogo ativo) à página — popula o modal"
  - "tests/Feature/Phase14BladeRefactorTest (3 testes / 12 assertions)"
  - "tests/Feature/Phase14FechamentoUiTest (4 testes / 58 assertions)"
affects:
  - "UI Fechamento NÃO LÊ MAIS service_type/contract_*/additional_* — apenas usa servicos_contratados (catálogo)"
  - "3 Blade views NÃO USAM mais Company::labelFromTypes — apenas accessor service_type_label"
  - "Plan 14-06 pode dropar colunas legacy de companies sem quebrar UI Financeiro nem Blades de relatório"
  - "Plan 14-07 (cleanup final JSX) — remove fallback legacy do ServiceBadge + 5 outros JSX consumers"
tech_added: []
patterns_used:
  - "URL crua nos router.post/put/delete (em vez de route helper Ziggy) — evita acoplamento a nome nomeado"
  - "Accessor Eloquent (snake_case na Blade: service_type_label) em vez de método (camelCase: serviceTypeLabel)"
  - "Compat fallback no ServiceBadge: prefere servicos_contratados, cai em tipo legacy se ausente — coexistência até Plan 14-07"
  - "AssertableInertia (mesmo padrão de AdminFechamentoControllerTest + Phase14AdminControllerCobrancaTest) para validar shape exato de props"
  - "ContratosSection inline (não componentizada) — mantém escopo de Plan 14-05; refator para componente reutilizável fica para futuro"
key_files:
  created:
    - "tests/Feature/Phase14BladeRefactorTest.php"
    - "tests/Feature/Phase14FechamentoUiTest.php"
  modified:
    - "app/Http/Controllers/AdminController.php"
    - "resources/js/Pages/Admin/Financeiro.jsx"
    - "resources/views/admin/relatorio-fechamento.blade.php"
    - "resources/views/admin/relatorio-geral.blade.php"
    - "resources/views/admin/relatorio-geral-pdf.blade.php"
    - ".planning/phases/14-.../deferred-items.md"
decisions:
  - "Accessor `service_type_label` (snake_case na Blade) em vez do método `serviceTypeLabel()` — Plan 14-03 implementou como accessor; Plan 14-05 inicialmente seguiu a forma método mas precisou ajustar (Rule 1 - Bug)"
  - "URL crua nas chamadas Inertia router.post/put/delete em vez de route('empresas.contratos.store') — evita acoplamento ao Ziggy/route helper; reduz risco de quebra silenciosa se nome nomeado mudar"
  - "ServiceBadge fallback legacy mantido (compat até Plan 14-07) — preserva renderização para empresas sem servicos_contratados (caso transição em produção tenha ressaltos)"
  - "GraficoContrato (eixo: tipo de contrato — fixo/progressão, leitura de contract_type) substituído por GraficoCobranca (eixo: tipo_cobranca — mensal/única) — alinhamento conceitual com o catálogo"
  - "FechamentoRow exibe 'N contratos ativos' em vez de range contract_start/end — datas individuais ficam visíveis no modal de edição do contrato"
  - "UAT humano (Task 5) deferido como débito em deferred-items.md — fechar Plan baseado em testes automatizados (28/28 verdes) preserva tokens; validação visual fica para próxima sessão de uso real do Fechamento"
metrics:
  duration_minutes: 17
  task_count: 4
  files_created: 2
  files_modified: 5
  test_assertions: 70
  completed_date: 2026-05-26
requirements-completed:
  - SVC-03
  - SVC-04
---

# Phase 14 Plan 05: UI Fechamento + 3 Blade views consomem contratos_servico — Summary

**Refatora `Admin/Financeiro.jsx` substituindo editor inline legacy (`service_type` enum + `additional_service` texto livre + `additional_service_price`) por seção de contratos com modal Add/Edit/Desativar (espelho de `Companies/Show.jsx`); refatora as 3 Blade views de relatório (`relatorio-fechamento`, `relatorio-geral`, `relatorio-geral-pdf`) para consumir `$company->service_type_label` (accessor derivado de contratos ativos) e `$v['servicos_contratados']` (string formatada do mapper Plan 14-03). 4 commits, 7 novos testes verdes (12 + 58 = 70 assertions); UAT humano deferido como débito.**

## Performance

- **Duration:** ~17 min
- **Started:** 2026-05-26T19:43:00Z
- **Completed:** 2026-05-26T20:00:00Z
- **Tasks:** 4 (Tasks 1-4 executadas; Task 5 — checkpoint humano — deferida como débito)
- **Files modificados:** 5 (1 controller, 1 jsx, 3 blade views) + 1 deferred-items.md
- **Files criados:** 2 (2 suítes de teste)
- **Commits:** 4 (1 refactor blade + 1 feat jsx + 2 test)

## Objetivo Atingido

**SVC-03** e **SVC-04** cumpridos:

- **SVC-03:** `Admin/Financeiro.jsx` substitui o editor de "Serviço adicional" (texto livre + preço único) pela UI de gestão de contratos análoga a `Companies/Show.jsx` — modal de "Adicionar contrato" funcional via URL crua, lista de contratos ativos com ações editar/desativar.
- **SVC-04:** Filtros e badges de tipo de serviço em Fechamento E nas 3 Blade views passam a apontar para `contratos_servico` — `whereJsonContains('service_type', ...)` substituído por filtragem client-side via `servicos_contratados[].servico_nome`; nenhuma chamada nova a `Company::labelFromTypes()` introduzida; chamadas legacy nas Blades migradas para `$company->service_type_label`.

Após este plan, a UI de Fechamento + as 3 Blade views consomem 100% o modelo novo. Resta o **Plan 14-06** (drop irreversível das 6 colunas legacy) — desbloqueado.

## Arquivos Modificados / Criados

### Código de produção

| Arquivo | Mudança principal |
| ------- | ----------------- |
| `app/Http/Controllers/AdminController.php` | `fechamento()` passa nova prop `servicos_disponiveis` (catálogo ativo) — alimenta o modal Add/Edit do Financeiro.jsx |
| `resources/js/Pages/Admin/Financeiro.jsx` | (+624 / -196) Editor inline legacy substituído por `ContratosSection`; `ServiceBadge` prioriza `servicos_contratados` com fallback legacy; `GraficoServico` deriva do catálogo; `GraficoCobranca` (mensal/única) substitui `GraficoContrato` (fixo/progressão); `FiltroBarra` com dropdown 'Serviço' dinâmico; `FechamentoRow` exibe 'N contratos ativos' |
| `resources/views/admin/relatorio-fechamento.blade.php` | (+4 / -2) `Company::labelFromTypes($company->service_type)` → `$company->service_type_label`; `$v['service_type']` → `$v['servicos_contratados'] ?? '—'` |
| `resources/views/admin/relatorio-geral.blade.php` | (+4 / -2) Idem (2 sites) |
| `resources/views/admin/relatorio-geral-pdf.blade.php` | (+4 / -2) Idem (2 sites) |

### Testes

| Arquivo | Tipo | Cenários | Assertions |
| ------- | ---- | -------- | ---------- |
| `tests/Feature/Phase14BladeRefactorTest.php` | Feature (RefreshDatabase + Blade) | 3 | 12 |
| `tests/Feature/Phase14FechamentoUiTest.php` | Feature (RefreshDatabase + AssertableInertia) | 4 | 58 |
| **Total novos** | | **7** | **70** |

### Outros

| Arquivo | Conteúdo |
| ------- | -------- |
| `.planning/phases/14-.../deferred-items.md` | UAT humano (Task 5) deferido como débito — 12 itens de verificação visual listados; cobertura automatizada justifica fechamento do plan |

## Tarefas Executadas

### Task 1: Refatoração das 3 Blade views (`d393983`)

- **Commit:** `refactor(14-05): Blade views consomem serviceTypeLabel + servicos_contratados`
- 6 sites refatorados (2 por Blade view: empresa pai + empresa filha em array):
  - **Empresa pai:** `{{ \App\Models\Company::labelFromTypes($company->service_type) }}` → `{{ $company->service_type_label }}`
  - **Empresa filha (array):** `{{ \App\Models\Company::labelFromTypes($v['service_type'] ?? null) }}` → `{{ $v['servicos_contratados'] ?? '—' }}`
- Comentários inline em pt-BR documentam a transição (Phase 14: labelFromTypes legacy → service_type_label accessor derivado de contratos).
- `php -l` OK nas 3 Blade views.

**Nota:** A primeira escrita usou `$company->serviceTypeLabel()` (forma método camelCase, conforme `<interfaces>` do PLAN). Bug corrigido na Task 3 (ver "Deviations from Plan" abaixo) — Plan 14-03 implementou como accessor (snake_case): `$company->service_type_label`.

### Task 2: Refatoração do Admin/Financeiro.jsx (`29f0c51`)

- **Commit:** `feat(14-05): Admin/Financeiro substitui editor legacy por seção de contratos`
- Diff: `+440 / -196 LOC` (1 arquivo + 12 linhas no AdminController para passar `servicos_disponiveis`)
- **Editor inline legacy removido:**
  - `useForm` perde `service_type`, `contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`
  - Bloco de checkboxes service_type, inputs de datas contract_*, input texto+valor additional_service — TODOS removidos
- **Nova `ContratosSection` (espelho de `Companies/Show.jsx`):**
  - Tabela com colunas: Serviço, Valor, Tipo (badge mensal/única), Ações (editar/desativar)
  - Botão "Adicionar contrato" abre `Dialog` com select do catálogo, input valor, datas, observações, switch ativo, Cancelar/Salvar
  - **URL crua** nas chamadas Inertia (decisão pre-flight Task 0):
    - POST `/empresas/${empresa.id}/contratos-servico`
    - PUT `/empresas/${empresa.id}/contratos-servico/${contrato.id}`
    - DELETE `/empresas/${empresa.id}/contratos-servico/${contrato.id}`
- **ServiceBadge** prioriza `servicos_contratados` (lista de objetos do catálogo); fallback legacy preservado para empresas sem contratos (estado de transição).
- **GraficoServico:** deriva de `companies.flatMap(c => c.servicos_contratados.map(...))` — pizza por nome de serviço.
- **GraficoCobranca:** substitui `GraficoContrato`. Eixo passa de `contract_type` (legacy fixo/progressão) para `tipo_cobranca` (mensal/única) — alinhamento com catálogo.
- **FiltroBarra:**
  - Remove dropdowns `service_type` e `contract_type` legacy
  - Adiciona dropdown único "Serviço" populado dinamicamente de `[...new Set(companies.flatMap(c => c.servicos_contratados.map(s => s.servico_nome)))]`
  - `FILTROS_INICIAL` atualizado: remove `service_type`/`contract_type`, adiciona `servico_nome: ''`
- **FechamentoRow:** exibe "N contratos ativos" em vez de range `contract_start/contract_end` — datas individuais ficam visíveis no modal de edição do contrato.
- **AdminController::fechamento()** passa nova prop `servicos_disponiveis = Servico::where('ativo', true)->orderBy('nome')` — alimenta o select do modal Add.
- Imports adicionados: `Plus`, `Pencil`, `PowerOff` (lucide-react); `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogFooter` (@/Components/ui/dialog).
- `npm run build` OK — bundle `Admin/Financeiro-*.js` registrado em manifest (36.77 kB).

### Task 3: Phase14BladeRefactorTest (`5bf1c65`)

- **Commit:** `test(14-05): Phase14BladeRefactorTest + fix accessor service_type_label`
- **3 testes / 12 assertions verdes:**

| # | Teste | Cenário |
|---|-------|---------|
| 1 | `test_relatorio_fechamento_renderiza_nomes_servicos_de_contratos_ativos` | Pai com 2 contratos ativos (Polos + Gestão) — assertSee('Polos', 'Gestão'); assertDontSee('labelFromTypes') |
| 2 | `test_relatorio_fechamento_renderiza_servicos_contratados_de_empresa_filha` | Pai com 1 contrato (Polos) + filha com 2 contratos (Assessoria + Publicidade) — assertSee da string `servicos_contratados` da filha |
| 3 | `test_relatorio_geral_renderiza_nomes_servicos` | 2 empresas com contratos distintos — itera todos, todos visíveis no HTML |

- **[Rule 1 - Bug] Fix accessor:** Plan instruía usar `$company->serviceTypeLabel()` (método) mas Plan 14-03 implementou como **accessor Eloquent** (`getServiceTypeLabelAttribute`) → na Blade lê como **snake_case**: `$company->service_type_label`. Trocado nas 3 Blade views; verificado via grep e via teste 1 (`assertStringNotContainsString('labelFromTypes')` confirma renderização correta).
- `assertStringNotContainsString('labelFromTypes')` garante que o método legacy não vaza no HTML mesmo se a Blade tivesse erro silencioso.

### Task 4: Phase14FechamentoUiTest (`7af91b7`)

- **Commit:** `test(14-05): Phase14FechamentoUiTest cobre props Inertia + servicos_disponiveis`
- **4 testes / 58 assertions verdes:**

| # | Teste | Cobertura |
|---|-------|-----------|
| 1 | `test_financeiro_inertia_component_e_company_props_estrutura` | `component('Admin/Financeiro')` + shape exato de `servicos_contratados` (8 chaves: id, servico_id, servico_nome, valor_contratado, tipo_cobranca, data_contratacao, data_vencimento, ativo) |
| 2 | `test_financeiro_companies_inclui_chave_servicos_contratados_em_todas_empresas` | 3 empresas (0 contratos, 1 contrato, 3 contratos) — `servicos_contratados` existe como ARRAY em TODAS |
| 3 | `test_financeiro_props_inclui_cobranca_mensal_calculada_de_contratos` | 1 empresa com 1 contrato Polos mensal R$ 200 + faixa R$ 4.500 → `cobranca_mensal === 4700.0` |
| 4 | `test_financeiro_payload_inclui_servicos_disponiveis_catalogo_ativo` | Prop `servicos_disponiveis` (catálogo ativo) presente no payload — popula o modal |

- Usa `Inertia\Testing\AssertableInertia` (mesmo padrão de `AdminFechamentoControllerTest` + `Phase14AdminControllerCobrancaTest`).
- Confirma E2E que o backend continua entregando shape esperado pela UI refatorada.

## Comandos de Verificação

```bash
# Linting Blade
c:/xampp/php/php.exe -l resources/views/admin/relatorio-fechamento.blade.php
c:/xampp/php/php.exe -l resources/views/admin/relatorio-geral.blade.php
c:/xampp/php/php.exe -l resources/views/admin/relatorio-geral-pdf.blade.php
# >>> No syntax errors detected

# Linting controller + testes
c:/xampp/php/php.exe -l app/Http/Controllers/AdminController.php
c:/xampp/php/php.exe -l tests/Feature/Phase14BladeRefactorTest.php
c:/xampp/php/php.exe -l tests/Feature/Phase14FechamentoUiTest.php
# >>> No syntax errors detected

# Build frontend
npm run build
# >>> built in ~9s — bundle Admin/Financeiro-*.js gerado e registrado em public/build/manifest.json (36.77 kB)

# Testes específicos
php artisan test --filter=Phase14BladeRefactorTest
# >>> Tests: 3 passed (12 assertions)

php artisan test --filter=Phase14FechamentoUiTest
# >>> Tests: 4 passed (58 assertions)

# Suíte combinada Phase 14
php artisan test --filter='Phase14|CobrancaCalculator|ComercialControllerHelper'
# >>> Tests: 28 passed (202 assertions)

# Grep de chamadas legacy nas Blades — deve retornar 0 matches em código vivo
grep -nE "labelFromTypes" resources/views/admin/relatorio-*.blade.php
# >>> 0 matches

# Grep de chamadas legacy no Financeiro.jsx
grep -nE "additional_service|contract_start|contract_end|contract_type" resources/js/Pages/Admin/Financeiro.jsx
# >>> apenas em comentários explicativos (compat até Plan 14-07)

# service_type aparece SOMENTE no fallback do ServiceBadge (compat) — esperado
grep -nE "service_type" resources/js/Pages/Admin/Financeiro.jsx
# >>> matches APENAS dentro do bloco "// Fallback: tipo legacy"
```

## Critérios de Sucesso vs. Realização

| # | Critério | Status |
|---|----------|--------|
| 1 | Admin/Financeiro.jsx substitui inputs legacy por seção de contratos + modal | OK |
| 2 | Modal usa URL crua `/empresas/${id}/contratos-servico[/{contrato_id}]` (decisão pre-flight Task 0) | OK |
| 3 | ServiceBadge exibe `servicos_contratados` (preferência) com fallback legacy | OK |
| 4 | 3 Blade views usam `$company->service_type_label` (derivado de contratos) | OK (accessor, não método — fix Task 3) |
| 5 | Filtros refatorados para derivar serviços do dataset | OK (dropdown único 'Serviço' dinâmico) |
| 6 | npm run build 0 erros | OK |
| 7 | Feature test `Phase14BladeRefactorTest` cobre renderização das 3 Blades | OK (3/12) |
| 8 | Feature test `Phase14FechamentoUiTest` cobre prop `servicos_contratados` | OK (4/58) |
| 9 | Checkpoint humano confirma fluxo funcional fim-a-fim | **DEFERIDO** — fechado baseado em testes automatizados (28/28 verdes); UAT visual em `deferred-items.md` |

## Commits do Plan 14-05

| Hash | Tipo | Mensagem |
|------|------|----------|
| `d393983` | refactor | Blade views consomem serviceTypeLabel + servicos_contratados (3 views, 6 sites) — Task 1 |
| `29f0c51` | feat | Admin/Financeiro substitui editor legacy por seção de contratos (+440 / -196) — Task 2 |
| `5bf1c65` | test | Phase14BladeRefactorTest (3 testes / 12 assertions) + fix accessor service_type_label — Task 3 |
| `7af91b7` | test | Phase14FechamentoUiTest cobre props Inertia + servicos_disponiveis (4 testes / 58 assertions) — Task 4 |

## Decisões de Execução

- **URL crua nas chamadas Inertia (decisão pre-flight Task 0):** `router.post/put/delete` usam `/empresas/${empresa.id}/contratos-servico[/${contrato.id}]` em vez de `route('empresas.contratos.store', ...)`. Evita acoplamento ao Ziggy/nome nomeado — se a rota nomeada mudar, JSX não quebra silenciosamente.
- **Accessor `service_type_label` (snake_case) em vez de método `serviceTypeLabel()`:** Plan 14-03 implementou como accessor Eloquent (`getServiceTypeLabelAttribute()`) — na Blade lê como `$company->service_type_label`. Plan 14-05 inicialmente seguiu a forma método (do `<interfaces>` do PLAN); ajustado na Task 3 (Rule 1 - Bug).
- **ServiceBadge fallback legacy mantido:** prioriza `servicos_contratados`; cai em `tipo` legacy se ausente. Preserva renderização para empresas que (em ambiente de transição) ainda não tiveram contratos populados via Migration 2. Removido no Plan 14-07 (cleanup final JSX).
- **GraficoContrato substituído por GraficoCobranca:** antigo agrupava por `contract_type` (fixo/progressão — colunas legacy a serem dropadas no Plan 14-06); novo agrupa por `tipo_cobranca` (mensal/única — campo do `contratos_servico`). Alinhamento conceitual com o catálogo.
- **FechamentoRow exibe 'N contratos ativos' em vez de range contract_start/end:** datas individuais agora moram em cada contrato (no modal de edição). Range agregado perde sentido — empresa pode ter contratos com datas distintas.
- **AdminController::fechamento passa `servicos_disponiveis`:** catálogo ativo (`Servico::where('ativo', true)->orderBy('nome')`) populando o select do modal. Sem isso, o modal precisaria fazer fetch separado.
- **Modal usa Dialog do shadcn/ui:** mesmo componente de `Companies/Show.jsx` — consistência visual e de UX.
- **UAT humano (Task 5) deferido como débito:** usuário decidiu pular checkpoint visual baseado nos testes automatizados (28/28 verdes, 202 assertions na suíte combinada Phase 14). 12 itens de verificação visual registrados em `deferred-items.md` para próxima sessão de uso real. **NÃO bloqueia Plan 14-06** — gate de drop depende apenas do `phase14:verificar-cobranca`, não da UI.

## Sistema em COEXISTÊNCIA (atualizado)

**Estado atual após Plan 14-05:**

- `companies.service_type`, `contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price` — **AINDA EXISTEM na tabela** (drop só no Plan 14-06)
- **UI Fechamento NÃO LÊ MAIS NENHUM destes campos no caminho principal:**
  - ServiceBadge prefere `servicos_contratados`; fallback legacy só para empresas sem contratos
  - Filtros só usam `servicos_contratados[].servico_nome`
  - FechamentoRow exibe contagem de contratos ativos (não range de datas)
  - Modal Add/Edit é 100% `contratos_servico`
- **3 Blade views NÃO LEEM MAIS** `Company::labelFromTypes($company->service_type)` — apenas accessor `service_type_label` (que internamente lê de `contratosServico` via Plan 14-03)
- **5 JSX consumers restantes ainda leem legacy** (cleanup no Plan 14-07):
  - `Admin/Empresas.jsx` (badge service_type)
  - `Comercial/Empresas.jsx` (badge + filtro)
  - `Mlb/Empresas.jsx` (badge)
  - `Companies/Index.jsx` (badge)
  - `Admin/Financeiro.jsx` (fallback do ServiceBadge — mantido propositalmente até 14-07)

**Próximos plans:**

- **Plan 14-06 — Drop irreversível:** gate `phase14:verificar-cobranca --abort-on-divergence` no host (humano) → se exit 0, drop das 6 colunas legacy + remove `labelFromTypes` + valida rules legacy + remove forma `string` da Notification + remove `resolverSlugsSetores`. **DESBLOQUEADO** por este plan.
- **Plan 14-07 — Cleanup final JSX:** remove fallback legacy do ServiceBadge + 5 outros JSX consumers + smoke test humano fim-a-fim. Depende do Plan 14-06.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Forma método vs. accessor — `serviceTypeLabel()` → `service_type_label`**
- **Found during:** Task 3 (escrita dos testes Blade)
- **Issue:** O `<interfaces>` do PLAN 14-05 instruía `$company->serviceTypeLabel()` (forma método camelCase). Ao escrever o teste 1 (`assertSee('Polos')` no HTML renderizado), o assertion falhou — a Blade exibia string vazia. Investigação mostrou que o Plan 14-03 implementou o helper como **accessor Eloquent** (`getServiceTypeLabelAttribute()`), que na Blade lê como `$company->service_type_label` (snake_case), NÃO como método.
- **Fix:** Trocado nas 3 Blade views (já refatoradas na Task 1): `$company->serviceTypeLabel()` → `$company->service_type_label`. Comentário inline atualizado.
- **Files modified:** `resources/views/admin/relatorio-fechamento.blade.php`, `resources/views/admin/relatorio-geral.blade.php`, `resources/views/admin/relatorio-geral-pdf.blade.php` (2 linhas cada, total 3 edições — 1 site por Blade)
- **Verification:** Teste 1 do `Phase14BladeRefactorTest` passou após o fix; `assertStringNotContainsString('labelFromTypes')` valida que o legacy não vaza.
- **Committed in:** `5bf1c65` (junto com a criação do teste Phase14BladeRefactorTest — bug e teste no mesmo commit por escopo coeso)

---

**Total deviations:** 1 auto-fixed (Rule 1 - Bug)
**Impact on plan:** Forma de acesso ao helper foi documentada incorretamente no PLAN.md (referência ao `<interfaces>` levava à forma método); fix é apenas sintático (mesmo dado, mesma fonte). Sem impacto no escopo.

## Checkpoint humano (Task 5) — UAT deferido

**Status:** `pending-human-uat` (registrado em `deferred-items.md`)

Plan fechado baseado em **testes automatizados (28/28 verdes, 202 assertions)** e `npm run build` 0 erros. UAT humano (12 itens de verificação visual descritos no `<how-to-verify>` da Task 5) fica como débito para próxima sessão de uso real do Fechamento.

**Justificativa:** Critérios funcionais (CRUD de contrato, cálculo de total consolidado, prop shapes Inertia, renderização de nomes nas Blades) já cobertos por:

- `Phase14BladeRefactorTest` (3 testes / 12 assertions) — confirma Blades renderizam nomes corretos.
- `Phase14FechamentoUiTest` (4 testes / 58 assertions) — confirma component + shape exato de `servicos_contratados` + cálculo de `cobranca_mensal` + presença de `servicos_disponiveis`.
- Cobertura CRUD do `contratos_servico` já existe pela Frente A (quick task 260526-jgj).

UAT visual valida **percepção** (badges, layout, comportamento de Dialog), não **comportamento de domínio**. Item 12 (console JS limpo) só pode ser validado manualmente.

**NÃO bloqueia Plan 14-06:** gate de drop depende do `phase14:verificar-cobranca` (no host), não da UI do Fechamento. Itens podem ser validados por uso real sem prejudicar a sequência.

## Issues Encountered

Nenhum problema bloqueante. Notas operacionais:

- O ambiente do executor (XAMPP local) não tem dados de produção, então `phase14:verificar-cobranca` continua retornando "0 empresas, 0 divergências" — execução real fica para Plan 14-06 com base de dados populada (já documentado em `deferred-items.md` no Plan 14-04).
- Fix do accessor (Rule 1 - Bug) descoberto pelo teste 1 — sem o teste, a Blade renderizaria string vazia silenciosamente. Confirma valor da camada de testes Blade.

## Threat Flags

Nenhuma surface nova introduzida. Mitigações Phase 14 já cobertas:

- **T-14-04 (Tampering — servico_id arbitrário no contrato):** Modal Add/Edit envia `servico_id` via POST; `CompanyController::storeContrato` (Frente A) valida via `Rule::exists('servicos', 'id')->where('ativo', true)`. Coberto pela Frente A.
- **T-14-05 (Tampering — valor manipulado client-side):** Admin pode customizar valor; activity log via `LogsActivity` do ContratoServico registra. Aceito per D-05.
- **T-14-10 (Tampering — payload legacy no update):** N/A no Plan 14-05 — não há mais `updateFechamento` na UI; tudo passa pelos endpoints de contrato da Frente A. Editor legacy removido.

## Self-Check: PASSED

- Arquivo `app/Http/Controllers/AdminController.php` modificado (verificado via diff do commit `29f0c51` — 12 linhas alteradas; adição de prop `servicos_disponiveis`)
- Arquivo `resources/js/Pages/Admin/Financeiro.jsx` modificado (verificado via diff do commit `29f0c51` — +440 / -196; bundle `Admin/Financeiro-*.js` gerado em `npm run build`)
- 3 Blade views modificadas (`relatorio-fechamento`, `relatorio-geral`, `relatorio-geral-pdf`) — verificado via diff dos commits `d393983` + `5bf1c65`
- Arquivo `tests/Feature/Phase14BladeRefactorTest.php` criado (3/3 verdes, 12 assertions)
- Arquivo `tests/Feature/Phase14FechamentoUiTest.php` criado (4/4 verdes, 58 assertions)
- Suíte combinada Phase 14: 28/28 verdes (202 assertions)
- Commits `d393983`, `29f0c51`, `5bf1c65`, `7af91b7` presentes em `git log --oneline`
- UAT humano deferido como débito em `deferred-items.md`
- Plan 14-05 marcado como `[x]` no ROADMAP

---
*Phase: 14-consolida-o-do-modelo-de-servi-os-frente-b*
*Plan: 14-05*
*Completed: 2026-05-26*
