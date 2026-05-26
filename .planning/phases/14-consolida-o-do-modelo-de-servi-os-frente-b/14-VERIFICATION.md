---
phase: 14-consolida-o-do-modelo-de-servi-os-frente-b
verified: 2026-05-26T23:55:00Z
status: human_needed
score: 9/10 must-haves verified (1 documental — REQUIREMENTS.md desatualizado)
overrides_applied: 0
gaps:
  - truth: "REQUIREMENTS.md SVC-XX marcados como Complete e refletindo conclusão da Phase 14"
    status: partial
    reason: "Tabela 'Traceability v4.0' lista SVC-01..04 e SVC-06..07 como 'Planned'; checkboxes na seção 'Consolidação do Modelo de Serviços (SVC)' marcam SVC-02..04 e SVC-06..07 como '[ ]'. Apenas SVC-01 e SVC-05 estão como Complete/[x]. O codebase já entregou as 7 SVCs."
    artifacts:
      - path: ".planning/REQUIREMENTS.md"
        issue: "Tabela e checkboxes SVC desatualizados — fase entregue na prática mas REQUIREMENTS.md ainda mostra status pré-execução"
    missing:
      - "Atualizar checkboxes SVC-02, SVC-03, SVC-04, SVC-06, SVC-07 de [ ] para [x] (linhas 38-43)"
      - "Atualizar tabela 'Traceability v4.0' (linhas 60-66): SVC-01, SVC-02, SVC-03, SVC-04, SVC-06, SVC-07 de 'Planned' para 'Complete'"
human_verification:
  - test: "UAT visual em /administrativo/financeiro"
    expected: "Lista de empresas exibe badges com nomes vindos do catálogo; expandir empresa mostra seção 'Serviços contratados' com tabela, modal Add/Edit/Desativar funcional; total consolidado = faixa + SUM(contratos mensais ativos); console JS sem erros"
    why_human: "Plan 14-05 deferiu UAT visual; depende de browser/login real e percepção de UI (Dialog, badges, layout); coberto funcionalmente por Phase14FechamentoUiTest mas pendente smoke real"
  - test: "Smoke visual fim-a-fim das 5 telas refatoradas"
    expected: "Telas /administrativo/empresas, /comercial/empresas, /comercial/empresas/novo, /mlb/empresas, /companies renderizam sem erros JS; filtros por serviço funcionam; pendentes aparecem com badges corretos"
    why_human: "Plan 14-07 deferiu smoke visual; gates automatizados verdes mas validação de UX requer browser real"
  - test: "Executar phase14:verificar-cobranca --abort-on-divergence em host com dados reais"
    expected: "Exit code 0, 0 divergências para todas as empresas reais do banco de produção (ou dump local com dados completos)"
    why_human: "Comando rodou local com 0 empresas (banco zerado); validação de SVC-02 em dados reais requer ambiente populado — pending-host-run conforme deferred-items.md"
  - test: "Cleanup das suítes antigas de coexistência"
    expected: "Phase14MigrationTest, Phase14VerificarCobrancaTest::test_aborta_com_divergencia, partes de Phase14AdminControllerCobrancaTest, Phase14ComercialTest::test_update_ignora_campos_legacy convertidos para schema pós-drop ou removidos"
    why_human: "Débito conhecido — testes pré-drop esperam colunas que não existem mais; precisa decisão sobre converter para schema pós-drop ou deletar"
---

# Phase 14: Consolidação do Modelo de Serviços (Frente B) — Verification Report

**Phase Goal:** Modelo unificado em `contratos_servico` — os 5 campos legacy de `companies` (`service_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`) substituídos pelo modelo N:N introduzido na quick task 260526-jgj (Frente A); Fechamento, Comercial e demais consumidores migrados sem alterar resultados financeiros.

**Verified:** 2026-05-26T23:55:00Z
**Status:** human_needed — todos os truths técnicos verificados; pendências documentais (REQUIREMENTS.md) e UAT visual deferidos no `deferred-items.md`.
**Re-verification:** No — verificação inicial.

## Conquista do Objetivo

### Verdades Observáveis (Success Criteria do ROADMAP.md)

| #   | Truth (SC do ROADMAP) | Status     | Evidence       |
| --- | --------------------- | ---------- | -------------- |
| 1   | Migration popula `servicos` com 6 tipos canônicos + cria `contratos_servico` por empresa preservando datas | ✓ VERIFIED | `database/migrations/2026_05_27_100001_seed_servicos_catalog.php` (linhas 35-55) cria 6 nomes via `firstOrCreate`; `2026_05_27_100002_migrate_legacy_service_data.php` (linhas 94-187) deriva contratos com `data_contratacao`/`data_vencimento` a partir de `contract_start`/`contract_end`. Cobertura: `Phase14MigrationTest::test_migration_cria_6_servicos_canonicos` + `test_migration_cria_contratos_para_empresa_com_service_type` |
| 2   | Empresas com `additional_service` recebem contrato adicional com find-or-create (Title Case) e `valor_contratado=additional_service_price` | ✓ VERIFIED | Migration 2 (linhas 140-186) faz `mb_convert_case(trim, MB_CASE_TITLE, 'UTF-8')` + `Servico::firstOrCreate`. Cobertura: `Phase14MigrationTest::test_migration_cria_contrato_adicional_com_normalizacao_title_case` |
| 3   | `AdminController::fechamento` usa `CobrancaCalculator::novo` → mesmo resultado financeiro | ✓ VERIFIED | `app/Http/Controllers/AdminController.php` tem 5 chamadas a `CobrancaCalculator::novo` (linhas 283, 520, 542, 682, 705); helper em `app/Support/CobrancaCalculator.php`. Cobertura: `Phase14AdminControllerCobrancaTest::test_cobranca_mensal_legacy_e_novo_modelo_batem_para_empresa_com_additional_service` + `CobrancaCalculatorTest` (13 assertions) |
| 4   | `Admin/Financeiro.jsx` substitui editor por modal/seção de contratos reusando UI da Frente A | ✓ VERIFIED | `resources/js/Pages/Admin/Financeiro.jsx` tem `ContratosSection` (linha 492), modal Add/Edit, ações editar/desativar, leitura de `servicos_disponiveis`. Cobertura: `Phase14FechamentoUiTest` (4 testes / 58 assertions) |
| 5   | Filtros/badges apontam para `contratos_servico` via JOIN; ServiceBadge mostra nomes dos contratos ativos | ✓ VERIFIED | `grep -rE "whereJsonContains.*service_type" app/` retorna 0 matches; `app/Http/Controllers/MlbController.php` e `CompanyController.php` migrados para `whereHas('contratosServico')`. Cobertura: `Phase14MlbControllerFiltroTest` (2 testes) |
| 6   | `Comercial/NovaEmpresa.jsx` substitui input `service_type` por seletor multi do catálogo + `DB::transaction` mantendo roteamento Phase 13 | ✓ VERIFIED | `resources/js/Pages/Comercial/NovaEmpresa.jsx` declara prop `servicos_disponiveis` (linha 29), state `servicos[]` (linha 36) com `servico_id`+`valor_contratado`. `ComercialController::store` (linha 158) valida `servicos[]`, faz `DB::transaction` e roteia via `servicoDisparaImplementacao` (linha 53). Cobertura: `Phase14ComercialTest` (8 testes incluindo POLOS/Assessoria/Publicidade/multi-serviços) |
| 7   | Migration drop descarta as 5 colunas legacy (na verdade 6 — `contract_type` incluído); `down()` recria estrutura vazia | ✓ VERIFIED | `database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php` (linhas 22-29) dropa 6 colunas: `service_type`, `contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`. `down()` recria com tipos originais (TEXT/string/date/decimal) conforme Pitfall 7 |
| 8   | `EmpresaCadastradaNotification` e `EnviarRelatorioFechamentoJob` adaptados para consumir `contratos_servico` | ✓ VERIFIED | `EmpresaCadastradaNotification.php` aceita `array $servicos` (linha 32), monta label via `implode(', ')` (linha 35); `EnviarRelatorioFechamentoJob.php` faz eager loading de `contratosServico.servico` (linhas 87-88) e monta chave `servicos_contratados` no payload (linhas 122-141) |
| 9   | `grep -rE 'service_type\|contract_start\|contract_end\|additional_service\|additional_service_price' app/ resources/js/` retorna 0 matches em código aplicativo | ✓ VERIFIED | `Grep app/` (PHP): 0 matches. `Grep resources/js/` (JSX): 0 matches. Migrations históricas (não-aplicativo) excluídas conforme critério |

**Score:** 9/9 success criteria do ROADMAP verificados no codebase.

### Verificações Adicionais (do prompt)

| #   | Verificação                                                              | Status     | Evidence       |
| --- | ------------------------------------------------------------------------ | ---------- | -------------- |
| V1  | Migration `2026_05_27_100003` está em `database/migrations/`              | ✓ VERIFIED | Arquivo encontrado via Glob |
| V2  | Migration `2026_05_27_100001` seed 6 tipos canônicos                     | ✓ VERIFIED | Lista verbatim 'Publicação','Polos','Assessoria','Incubadora','Publicidade','Gestão' |
| V3  | Migration `2026_05_27_100002` cria contratos idempotente                 | ✓ VERIFIED | Guards explícitos via `where(...)->exists()` antes de `create` |
| V4  | grep `service_type\|contract_type\|...` em `app/*.php`                   | ✓ VERIFIED | 0 matches |
| V4b | grep mesma regex em `resources/js/*.jsx`                                 | ✓ VERIFIED | 0 matches |
| V4c | grep `labelFromTypes` em `app/` e `resources/views/`                     | ✓ VERIFIED | 0 matches em `app/`; 3 matches em Blade VIEWS são **comentários históricos** pt-BR ("Phase 14: labelFromTypes(legacy) → serviceTypeLabel(derivado de contratos) — D-09") — aceitos por critério explícito do prompt |
| V5  | `CobrancaCalculator` com `legacy()` e `novo()` + testes unit             | ✓ VERIFIED | `app/Support/CobrancaCalculator.php` + `tests/Unit/CobrancaCalculatorTest.php` |
| V6  | Comando `phase14:verificar-cobranca` com `--abort-on-divergence`         | ✓ VERIFIED | `app/Console/Commands/Phase14VerificarCobranca.php` linha 29: signature contém `{--abort-on-divergence : Aborta com exit code 1 se houver divergência}` |
| V7  | `Phase14AdminControllerCobrancaTest` cobre golden                        | ✓ VERIFIED | 3 testes: `test_cobranca_mensal_legacy_e_novo_modelo_batem_*`, `test_phase14_verificar_cobranca_retorna_zero_divergencias_apos_migrations`, `test_fechamento_props_inclui_servicos_contratados` |
| V8  | `ComercialController::store` aceita `servicos[]`, transação atômica, roteamento Phase 13 preservado | ✓ VERIFIED | Linha 158-281; `DB::transaction` linha 193; helper `servicoDisparaImplementacao` PUBLIC STATIC linha 53 |
| V9  | `Admin/Financeiro.jsx` modal contratos + 3 Blades usam `$company->service_type_label` | ✓ VERIFIED | `Financeiro.jsx` linha 492 `ContratosSection`; Blades em `relatorio-fechamento.blade.php` (linha 276), `relatorio-geral.blade.php` (linha 376), `relatorio-geral-pdf.blade.php` (linha 320) — todas usam o accessor |
| V10 | REQUIREMENTS.md SVC-XX marcado complete                                  | ⚠️ PARTIAL | **FALHA DOCUMENTAL** — apenas SVC-01 (`[x]`) e SVC-05 (Complete) atualizados; SVC-02, SVC-03, SVC-04, SVC-06, SVC-07 ainda `[ ]` e "Planned" |

### Artefatos Obrigatórios

| Artifact | Expected    | Status | Details |
| -------- | ----------- | ------ | ------- |
| `database/migrations/2026_05_27_100001_seed_servicos_catalog.php` | Seed do catálogo idempotente | ✓ VERIFIED | 65 linhas, `firstOrCreate` para 6 nomes |
| `database/migrations/2026_05_27_100002_migrate_legacy_service_data.php` | Backfill idempotente | ✓ VERIFIED | 188 linhas, `DB::transaction` + `chunk(100)` + guards |
| `database/migrations/2026_05_27_100003_drop_legacy_service_columns_from_companies.php` | Drop das 6 colunas | ✓ VERIFIED | 45 linhas, dropa 6 colunas, `down()` recria estrutura |
| `app/Support/CobrancaCalculator.php` | Helper puro com `legacy()` e `novo()` | ✓ VERIFIED | 78 linhas, ambos métodos estáticos |
| `app/Console/Commands/Phase14VerificarCobranca.php` | Comando gate com flag --abort-on-divergence | ✓ VERIFIED | 147 linhas, signature correta, exit code 1 quando flag presente |
| `app/Models/Company.php` | sem fillable/casts/logOnly legacy; `labelFromTypes` removido | ✓ VERIFIED | 196 linhas, $fillable sem campos legacy; `labelFromServicos` + accessor `getServiceTypeLabelAttribute` |
| `app/Http/Controllers/AdminController.php` | 5 chamadas a `CobrancaCalculator::novo` | ✓ VERIFIED | Linhas 283, 520, 542, 682, 705 |
| `app/Http/Controllers/ComercialController.php` | `store()` aceita `servicos[]` + `servicoDisparaImplementacao` PUBLIC STATIC | ✓ VERIFIED | Linhas 53-65 (helper), 158-281 (store) |
| `app/Notifications/EmpresaCadastradaNotification.php` | Aceita `array $servicos` | ✓ VERIFIED | Linha 32 |
| `app/Jobs/EnviarRelatorioFechamentoJob.php` | Eager loading + chave `servicos_contratados` | ✓ VERIFIED | Linhas 87-88, 122-141 |
| `resources/js/Pages/Admin/Financeiro.jsx` | `ContratosSection` + modal | ✓ VERIFIED | Linha 492 |
| `resources/js/Pages/Comercial/NovaEmpresa.jsx` | Seletor multi `servicos[]` | ✓ VERIFIED | Linha 36 |
| `resources/views/admin/relatorio-fechamento.blade.php` | Usa `$company->service_type_label` | ✓ VERIFIED | Linha 276 |
| `resources/views/admin/relatorio-geral.blade.php` | Idem | ✓ VERIFIED | Linha 376 |
| `resources/views/admin/relatorio-geral-pdf.blade.php` | Idem | ✓ VERIFIED | Linha 320 |
| `tests/Unit/CobrancaCalculatorTest.php` | Cobertura unit do helper | ✓ VERIFIED | Existe; usa objetos anônimos (sem container) |
| `tests/Feature/Phase14*Test.php` (7 suites) | Cobertura feature da fase | ✓ VERIFIED | Migration, AdminControllerCobranca, Comercial, BladeRefactor, FechamentoUi, VerificarCobranca, MlbControllerFiltro — todos presentes |

### Verificação de Key Links

| From | To  | Via | Status | Details |
| ---- | --- | --- | ------ | ------- |
| `Admin/Financeiro.jsx` | `/api/empresas/{id}/contratos-servico` | router.post/put/delete (URLs cruas — decisão Plan 14-05) | ✓ WIRED | Plan 14-05 documenta decisão de não usar Ziggy; rotas existem da Frente A |
| `AdminController::fechamento` | `CobrancaCalculator::novo` | use + chamada estática | ✓ WIRED | 5 sites confirmados |
| `Phase14VerificarCobranca` | `CobrancaCalculator::legacy/novo` | use + chamada estática | ✓ WIRED | Linhas 86-89 |
| `ComercialController::store` | `Servico::where(...exists)` | Rule::exists in validation | ✓ WIRED | Linha 174 |
| `ComercialController::store` | `ContratoServico::create` | DB::transaction | ✓ WIRED | Linha 193 |
| Migration 2 | `Servico::firstOrCreate` + `ContratoServico::create` | direto via Eloquent | ✓ WIRED | Linhas 47-186 |
| Blade `relatorio-*.blade.php` | `Company::getServiceTypeLabelAttribute` | accessor `$company->service_type_label` | ✓ WIRED | 3 views + accessor em Company.php linha 62 |

### Cobertura de Requirements

| Requirement | Source Plan | Description | Status (codebase) | Status (REQUIREMENTS.md) | Evidence |
| ----------- | ---------- | ----------- | ------ | ------ | -------- |
| SVC-01 | 14-02 | Migration popula `servicos` + cria `contratos_servico` por empresa preservando datas | ✓ SATISFIED | `[x]` / **Planned** (tabela) | Migrations 1+2; `Phase14MigrationTest` (5 testes) |
| SVC-02 | 14-01, 14-03 | `cobranca_mensal` = faixa + SUM contratos ativos mensais; resultado idêntico | ✓ SATISFIED | `[ ]` / **Planned** | `CobrancaCalculator` + 5 chamadas em AdminController + `Phase14AdminControllerCobrancaTest` |
| SVC-03 | 14-05 | `Admin/Financeiro.jsx` substitui editor por UI de gestão de contratos | ✓ SATISFIED | `[ ]` / **Planned** | `ContratosSection` (linha 492) + modal + `Phase14FechamentoUiTest` |
| SVC-04 | 14-03, 14-05, 14-06, 14-07 | Filtros via JOIN `servicos.nome`; nenhuma referência a `service_type` em código aplicativo | ✓ SATISFIED | `[ ]` / **Planned** | `MlbController` + `CompanyController` refatorados; `Phase14MlbControllerFiltroTest`; grep 0 matches |
| SVC-05 | 14-04 | `Comercial/NovaEmpresa.jsx` seletor multi + `DB::transaction` mantendo roteamento Phase 13 | ✓ SATISFIED | `[x]` / **Complete** | `NovaEmpresa.jsx` + `ComercialController::store` + `Phase14ComercialTest` (8 testes) |
| SVC-06 | 14-06 | Migration descarta 5 colunas legacy (na prática 6 — `contract_type` incluído) | ✓ SATISFIED | `[ ]` / **Planned** | Migration 3 dropa 6 colunas; `down()` recria estrutura |
| SVC-07 | 14-03 | `EmpresaCadastradaNotification` + `EnviarRelatorioFechamentoJob` consomem `contratos_servico` | ✓ SATISFIED | `[ ]` / **Planned** | Notification array; Job eager + chave `servicos_contratados` |

**Gap documental:** A entrega técnica de todas as 7 SVC está completa, mas REQUIREMENTS.md não foi atualizado (linhas 38-43 e 60-66 da seção SVC + Traceability v4.0).

### Anti-Patterns Encontrados

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| nenhum em código aplicativo (`app/`, `resources/js/`) | — | — | — | Nenhum TBD/FIXME/XXX em arquivos Phase 14 |
| 3 Blade views (`relatorio-*.blade.php`) | 275, 319, 375 | Comentário histórico `Phase 14: labelFromTypes(legacy) → serviceTypeLabel(derivado de contratos) — D-09` | ℹ️ Info | Documentação intencional pt-BR conforme CLAUDE.md mandate; o comentário referencia `labelFromTypes` mas o código usa `$company->service_type_label` |
| 4 suites de teste em `tests/Feature/` | várias | Resíduos de coexistência (Phase14MigrationTest, Phase14VerificarCobrancaTest::test_aborta_com_divergencia, partes de Phase14AdminControllerCobrancaTest, Phase14ComercialTest::test_update_ignora_campos_legacy) | ⚠️ Warning (débito conhecido) | Suítes ficam vermelhas pós-drop; documentadas em `deferred-items.md` como `pending-regression-cleanup` |

### Verificação Humana Necessária

Itens documentados em `deferred-items.md` e levantados pelo prompt:

#### 1. UAT visual de `/administrativo/financeiro` (Plan 14-05)

**Test:** Acessar `https://admin.ecfconsultoria.com.br/administrativo/financeiro` com login admin, expandir empresa, abrir modal Add contrato, criar/editar/desativar contrato; conferir total consolidado; F12 sem erros JS.

**Expected:** Badges com nomes do catálogo (não enums legacy); UI Dialog funcional; CRUD via URLs cruas (`/empresas/{id}/contratos-servico`); soma `faixa + SUM(contratos mensais ativos)` correta.

**Why human:** UI Dialog (Radix), badges, percepção visual — coberto funcionalmente por `Phase14FechamentoUiTest` mas validação visual requer browser real.

#### 2. Smoke visual fim-a-fim das 5 telas (Plan 14-07)

**Test:** Navegar `/administrativo/empresas`, `/comercial/empresas`, `/comercial/empresas/novo`, `/mlb/empresas`, `/companies`.

**Expected:** Sem erros JS no console; filtros por serviço operam; pendentes exibem badges via `servicos_contratados`.

**Why human:** Gates automatizados (build, grep, regressão focada) verdes — falta validar UX real.

#### 3. Execução de `phase14:verificar-cobranca --abort-on-divergence` com dados reais

**Test:** Rodar comando em host com banco populado (XAMPP com dump prod ou produção em janela de manutenção) ANTES de aplicar Migration 3 em produção.

**Expected:** Exit code 0, 0 divergências.

**Why human:** Local rodou com 0 empresas. SVC-02 (invariante financeiro) só fica garantido em produção após o comando exit 0 em dados reais.

#### 4. Cleanup das suítes antigas pós-drop

**Test:** Decidir e implementar conversão/remoção de `Phase14MigrationTest`, `Phase14VerificarCobrancaTest::test_aborta_com_divergencia`, partes de `Phase14AdminControllerCobrancaTest`, `Phase14ComercialTest::test_update_ignora_campos_legacy`.

**Expected:** Suíte `php artisan test --filter=Phase14|CobrancaCalculator|ComercialControllerHelper` verde.

**Why human:** Débito de regression conhecido — requer decisão sobre converter para schema pós-drop ou deletar; quick task dedicada.

### Sumário de Gaps

**Gap único (documental):** REQUIREMENTS.md não foi atualizado para refletir conclusão das 7 SVCs. Tudo o que precisa ser feito no codebase já foi feito:

- 6 colunas legacy (5 do prompt + `contract_type`) dropadas via migration 3
- Catálogo populado e backfill idempotente
- Helper puro testado + comando gate funcional com flag --abort-on-divergence
- AdminController usa `CobrancaCalculator::novo` em 5 sites
- ComercialController.store aceita `servicos[]` em DB::transaction com helper de roteamento
- UI Fechamento + 3 Blades + 5 JSX consumers refatorados
- Notification + Job consomem `contratos_servico`
- `grep app/ resources/js/` retorna 0 matches em colunas legacy

**Os débitos conhecidos** (UAT visual, host-run, cleanup de suítes) já estão documentados em `deferred-items.md` e estão expressamente fora do critério de falha por solicitação do prompt — foram movidos para `human_verification`.

Para atingir status `passed`, basta:
1. Atualizar `.planning/REQUIREMENTS.md` linhas 38-43 (checkboxes SVC-02..04, SVC-06, SVC-07 de `[ ]` para `[x]`)
2. Atualizar tabela "Traceability v4.0" linhas 60-66 (status "Planned" → "Complete" para SVC-01..04, SVC-06, SVC-07)

E completar os 4 itens de `human_verification` quando o usuário/host permitir.

---

_Verified: 2026-05-26T23:55:00Z_
_Verifier: Claude (gsd-verifier)_
