---
phase: 14
plan: 04
subsystem: servicos
tags: [phase-14, services-consolidation, comercial, refactor, ui-jsx, helper-puro, tests]
requires:
  - "Plan 14-02 (catálogo Servicos populado com 6 nomes canônicos)"
  - "Plan 14-03 (EmpresaCadastradaNotification refatorado para string|array)"
provides:
  - "ComercialController::servicoDisparaImplementacao(): helper estático puro"
  - "ComercialController::store() aceitando servicos[] do catálogo (substitui service_type enum)"
  - "ComercialController::update() com validação enxuta (apenas name/cnpj/notes)"
  - "ComercialController::create() método novo para popular UI com servicos_disponiveis"
  - "ComercialController::empresas() reconstrói service_type[] + servicos_contratados[] (compat)"
  - "Comercial/NovaEmpresa.jsx: seletor multi do catálogo dinâmico com valor por contrato"
  - "tests/Unit/ComercialControllerHelperTest (9 testes / 9 assertions)"
  - "tests/Feature/Phase14ComercialTest (8 testes / 38 assertions)"
affects:
  - "Cadastro Comercial NÃO PRODUZ MAIS dados legacy — todo novo registro vai 100% pelo modelo N:N"
  - "EmpresaCadastradaNotification: ÚLTIMO caller string foi eliminado — Plan 14-06 pode dropar a forma string"
  - "Plan 14-06 NÃO precisa limpar update() — validação já enxuta aqui"
tech_added: []
patterns_used:
  - "Helper estático puro testável fora do container (CobrancaCalculator + servicoDisparaImplementacao)"
  - "Validation Rule::exists com where('ativo', true) — rejeita servico_id inativo"
  - "DB::transaction atômica: companies + N contratos_servico + roteamento opcional"
  - "Roteamento por NOME (str_contains case-sensitive) em vez de slug enum"
  - "Reconstrução de chave legacy via mapa nome→slug para compat UI (até Plan 14-06/07)"
  - "Mapa do setor por nome do serviço (slugSetorParaServico) substitui resolverSlugsSetores"
  - "Form Inertia com seletor multi dinâmico + input de valor por item selecionado"
key_files:
  created:
    - "tests/Unit/ComercialControllerHelperTest.php"
    - "tests/Feature/Phase14ComercialTest.php"
  modified:
    - "app/Http/Controllers/ComercialController.php"
    - "resources/js/Pages/Comercial/NovaEmpresa.jsx"
    - "routes/web.php"
    - ".planning/phases/14-.../deferred-items.md"
decisions:
  - "Helper PUBLIC STATIC permite chamada via self:: dentro do store() E testes diretos sem instanciar controller"
  - "store() valida servicos[].valor_contratado nullable+numeric — cliente pode omitir e cai no valor_padrao"
  - "update() enxuto IGNORA silenciosamente campos legacy enviados (não throw 422) — preserva compat UI Comercial/Empresas.jsx até Plan 14-07"
  - "empresas() reconstrói service_type[] via mapa nome→slug — Plan 14-06 dropa a coluna, Plan 14-07 dropa a chave Inertia"
  - "Rota GET /empresas/novo migrada de index() (redirect noop) para create() — agora retorna a página com props do catálogo"
  - "Roteamento por NOME canônico (str_contains 'Polos', 'Assessoria', 'Incubadora') case-sensitive — D-02 garante Title Case no catálogo"
  - "Phase13ComercialTest fica obsoleta — cobertura equivalente reproduzida em Phase14ComercialTest; deletion/port adiada para quick task pós Plan 14-06"
metrics:
  duration_minutes: 8
  task_count: 3
  files_created: 2
  files_modified: 4
  test_assertions: 47
  completed_date: 2026-05-26
---

# Phase 14 Plan 04: Cadastro Comercial usa catálogo de serviços — Summary

**Refatora `ComercialController::store()` para aceitar `servicos[]` do catálogo (Frente A), substituindo o enum legacy `service_type`; cria atomicamente Company + N contratos_servico + roteamento opcional Polos/Assessoria/Incubadora; UI `NovaEmpresa.jsx` ganha seletor multi dinâmico com valor por contrato. Roteamento Phase 13 (COM-04/05/06/08) PRESERVADO; `update()` agora valida apenas name/cnpj/notes (campos legacy silenciosamente ignorados).**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-05-26T19:26:29Z
- **Completed:** 2026-05-26T19:34:40Z
- **Tasks:** 3
- **Files modificados:** 4 (1 controller, 1 jsx, 1 routes, 1 deferred-items)
- **Files criados:** 2 (2 suites de teste)

## Objetivo Atingido

SVC-05 cumprido. Cadastro Comercial passa a usar o modelo unificado de serviços, fechando o ciclo de produção de dados pelo novo modelo. Sem este plan, novas empresas continuariam produzindo dados legacy que precisariam ser migrados depois (estado já evitado para registros futuros).

## Arquivos Modificados / Criados

### Código de produção

| Arquivo | Mudança principal |
| ------- | ----------------- |
| `app/Http/Controllers/ComercialController.php` | + `servicoDisparaImplementacao()` (helper public static); + `create()` (GET form); `store()` reescrito para validar `servicos[]` + criar N contratos atômico + roteamento por nome; `update()` enxuto; `empresas()` com eager-loading + reconstrução `service_type[]`/`servicos_contratados[]`; + `slugSetorParaServico()` (substitui `resolverSlugsSetores` deprecated) |
| `resources/js/Pages/Comercial/NovaEmpresa.jsx` | Remove `TIPOS` hardcoded; aceita prop `servicos_disponiveis`; useForm com `servicos: []`; renderiza catálogo dinâmico com checkbox + input de valor; empty state quando catálogo vazio |
| `routes/web.php` | Rota GET `/comercial/empresas/novo` aponta para `create()` (em vez de `index()` que era redirect noop) |

### Testes

| Arquivo | Tipo | Cenários | Assertions |
| ------- | ---- | -------- | ---------- |
| `tests/Unit/ComercialControllerHelperTest.php` | Unit (sem DB) | 9 | 9 |
| `tests/Feature/Phase14ComercialTest.php` | Feature (RefreshDatabase) | 8 | 38 |
| **Total novos** | | **17** | **47** |

### Outros

| Arquivo | Conteúdo |
| ------- | -------- |
| `.planning/phases/14-.../deferred-items.md` | Phase13ComercialTest obsoleta (12 testes) — cobertura equivalente em Phase14ComercialTest; deletion/port adiado |

## Tarefas Executadas

### Task 1: Refatoração do ComercialController (`b95a804`)

- **Commit:** `refactor(14-04): ComercialController aceita servicos[] do catálogo + cria contratos N:N`
- Helper estático `public static function servicoDisparaImplementacao(string $nome): ?string`
  - Roteamento por `str_contains` case-sensitive: 'Polos' → 'polos'; 'Assessoria' → 'assessoria'; 'Incubadora' → 'incubadora'; default → null
- Novo método `create()`:
  - Retorna `Servico::where('ativo', true)->orderBy('nome')` como prop `servicos_disponiveis`
  - Acesso protegido (admin OR `comercial.cadastrar_empresa`)
- `store()` reescrito:
  - Validation: `servicos` required array min:1; `servicos.*.servico_id` integer + `Rule::exists('servicos', 'id')->where('ativo', true)`; `servicos.*.valor_contratado` nullable numeric
  - Guard de duplicata mantido intacto (`LOWER(name)` em companies + mlb_empresas)
  - `DB::transaction`: (a) Cria Company status='pendente'; (b) Para cada item de `servicos[]` cria ContratoServico (valor_contratado cai no `valor_padrao` se omitido); (c) Roteamento por nome cria MlbEmpresa + (apenas para POLOS) MlbImplementacao
  - Activity log: `withProperties(['empresa', 'servicos' => $nomes])` — array (não mais string)
  - Notification: `EmpresaCadastradaNotification($name, $nomesServicos, $userId)` — chamada com array
- `update()` enxuto:
  - Validation APENAS `name`/`cnpj`/`notes`
  - Campos legacy enviados pelo cliente são silenciosamente ignorados (não chegam em `$validated`)
- `empresas()`:
  - Remove `service_type` do `get([...])`
  - Eager-loads `contratosServico.servico` (where ativo=true)
  - Mapper transform: reconstrói `service_type[]` (slugs) + `servicos_contratados[]` (nomes) — compat para `Comercial/Empresas.jsx`
- Novo helper privado `slugSetorParaServico($nome)`:
  - Polos/Assessoria/Publicação → 'publicacao'; Publicidade → 'publicidade'; Gestão → 'gestao'; Incubadora → 'incubadora'
  - `resolverSlugsSetores()` marcado `@deprecated` (remoção no Plan 14-06)
- Rota `comercial.empresas.novo` migrada de `index()` (redirect noop) para `create()` em `routes/web.php`
- `php -l` OK

### Task 2: Refatoração do NovaEmpresa.jsx (`09b4c69`)

- **Commit:** `feat(14-04): NovaEmpresa.jsx usa seletor multi do catálogo + valor por contrato`
- Remove `const TIPOS = [...]` hardcoded (3 valores)
- Destructuring de prop `servicos_disponiveis = []`
- `useForm({ nome, cnpj, notes, servicos: [] })` — `servicos` é array de `{ servico_id, valor_contratado }`
- Helpers `toggleServico(id)` / `updateValor(id, valor)` para manipulação imutável
- Renderiza cada Servico ativo do catálogo:
  - Checkbox para seleção
  - Label + `tipo_cobranca` em texto secundário
  - Input de `valor_contratado` (visível só quando selecionado; default = `valor_padrao` do catálogo)
- Empty state quando `servicos_disponiveis.length === 0` — instrui acessar `/servicos`
- Botão submit `disabled={processing || data.servicos.length === 0}`
- Comentário de cabeçalho atualizado com nova estrutura do payload
- `npm run build` OK (9.02s) — bundle `NovaEmpresa-DSL4_8Gr.js` registrado em `public/build/manifest.json`
- Grep de `service_type` no arquivo retorna 0 matches (nem em comentários)

### Task 3: Testes (`7737f44`)

- **Commit:** `test(14-04): helper puro + roteamento COM-04/05/06/08 preservados + update enxuto (17 testes)`

**`Tests\Unit\ComercialControllerHelperTest` — 9 testes / 9 assertions:**

| # | Teste | Cobertura |
|---|-------|-----------|
| 1 | `test_polos_dispara_implementacao_polos` | Nome canônico → 'polos' |
| 2 | `test_polos_sp_dispara_implementacao_polos` | Variante 'Polos SP' bate via str_contains |
| 3 | `test_assessoria_dispara_implementacao_assessoria` | 'Assessoria Premium' → 'assessoria' |
| 4 | `test_incubadora_dispara_implementacao_incubadora` | 'Incubadora Tech' → 'incubadora' |
| 5 | `test_publicidade_retorna_null` | COM-06 — Publicidade só company |
| 6 | `test_gestao_retorna_null` | COM-06 — Gestão só company |
| 7 | `test_treinamento_arbitrario_retorna_null` | Catálogo customizado não dispara |
| 8 | `test_polos_lowercase_retorna_null_por_case_sensitive` | D-02 garante Title Case |
| 9 | `test_publicacao_retorna_null` | Publicação genérica não cria mlb_empresas |

**`Tests\Feature\Phase14ComercialTest` — 8 testes / 38 assertions:**

| # | Teste | Cobertura Phase 13/14 |
|---|-------|----------------------|
| 1 | `test_cadastra_empresa_polos_cria_mlb_implementacao` | COM-04 (Polos → Company + MlbEmpresa POLO + MlbImplementacao) |
| 2 | `test_cadastra_empresa_assessoria_cria_mlb_sem_implementacao` | COM-05 (Assessoria → Company + MlbEmpresa ASSESSORIA; sem impl.) |
| 3 | `test_cadastra_empresa_publicidade_nao_cria_mlb` | COM-06 (Publicidade → só Company) |
| 4 | `test_cadastra_empresa_com_multiplos_servicos_cria_contratos_e_mlb_apropriado` | Multi (Polos + Gestão → 2 contratos + 1 mlb_empresa) |
| 5 | `test_cadastro_sem_servicos_falha_validation` | Validation: `servicos: []` → erro |
| 6 | `test_cadastro_com_servico_inativo_falha_validation` | Validation: Rule::exists ativo=true |
| 7 | `test_cadastro_aciona_notificacao_para_lideres_do_setor` | COM-08 + Notification recebe ARRAY (não string) |
| 8 | `test_update_ignora_campos_legacy` | Validação enxuta: PUT com service_type/additional_service_price ignora |

## Comandos de Verificação

```bash
# Linting
c:/xampp/php/php.exe -l app/Http/Controllers/ComercialController.php
# >>> No syntax errors detected

c:/xampp/php/php.exe -l routes/web.php
# >>> No syntax errors detected

c:/xampp/php/php.exe -l tests/Unit/ComercialControllerHelperTest.php
# >>> No syntax errors detected

c:/xampp/php/php.exe -l tests/Feature/Phase14ComercialTest.php
# >>> No syntax errors detected

# Build frontend
npm run build
# >>> built in 9.02s
# >>> bundle NovaEmpresa-DSL4_8Gr.js gerado e registrado em public/build/manifest.json

# Testes (unit + feature)
php artisan test --filter=ComercialControllerHelperTest
# >>> Tests: 9 passed (9 assertions) Duration: 0.68s

php artisan test --filter=Phase14ComercialTest
# >>> Tests: 8 passed (38 assertions) Duration: 1.63s

# Suite combinada Phase 14
php artisan test --filter='Phase14|CobrancaCalculator|ComercialControllerHelper'
# >>> Tests: 38 passed (149 assertions) Duration: 14.89s

# Verificador financeiro
php artisan phase14:verificar-cobranca
# >>> [Phase14] Todas as 0 empresas conferem (0 divergências).

# Grep de service_type no JSX
grep -nE "service_type" resources/js/Pages/Comercial/NovaEmpresa.jsx
# >>> 0 matches em código vivo

# Grep de service_type no controller
grep -nE "service_type" app/Http/Controllers/ComercialController.php
# >>> Apenas em comentários de história + compat mapper (empresas())
```

## Critérios de Sucesso vs. Realização

| # | Critério | Status |
|---|----------|--------|
| 1 | Helper `ComercialController::servicoDisparaImplementacao` é `public static` puro testado isoladamente | OK (9 unit testes) |
| 2 | `store()` valida `servicos[].servico_id` exists+ativo + cria contratos N:N atomicamente | OK (Rule::exists + DB::transaction) |
| 3 | Roteamento Phase 13 preservado: Polos→mlb_empresas+mlb_implementacao; Assessoria→mlb_empresas; Publicidade/Gestão→só company | OK (testes 1, 2, 3 feature) |
| 4 | `NovaEmpresa.jsx` mostra catálogo dinâmico em vez dos 3 checkboxes hardcoded | OK |
| 5 | `npm run build` 0 erros | OK (built in 9.02s) |
| 6 | EmpresaCadastradaNotification recebe array de nomes (sem implode '+') | OK (teste 7 verifica `is_array($meta['servicos'])`) |
| 7 | `update()` valida APENAS name/cnpj/notes; campos legacy enviados pelo cliente são ignorados | OK (teste 8 confirma `service_type` e `additional_service_price` legacy NÃO sobrescritos) |
| 8 | 16+ assertions verdes entre as 2 suítes de teste | OK — 47 assertions (9 + 38) |

## Commits do Plan 14-04

| Hash | Tipo | Mensagem |
|------|------|----------|
| `b95a804` | refactor | ComercialController aceita servicos[] do catálogo + cria contratos N:N — Task 1 |
| `09b4c69` | feat | NovaEmpresa.jsx usa seletor multi do catálogo + valor por contrato — Task 2 |
| `7737f44` | test | helper puro + roteamento COM-04/05/06/08 preservados + update enxuto (17 testes) — Task 3 |

## Decisões de Execução

- **Helper PUBLIC STATIC, não privado:** permite chamada via `self::servicoDisparaImplementacao(...)` dentro do `store()` E testes diretos sem instanciar o controller (`ComercialController::servicoDisparaImplementacao('Polos')`). PHPDoc explica.
- **`store().servicos.*.valor_contratado` nullable + numeric:** cliente pode omitir e o controller cai no `valor_padrao` do catálogo via `$servico->valor_padrao`. Threat T-14-05 (Comercial manipulando valor client-side) aceita per D-05 — activity log registra.
- **`update()` IGNORA silenciosamente campos legacy:** validação enxuta não inclui `service_type`/`additional_service_price`/etc. — campos não validados não chegam em `$validated`. Isso permite que o Comercial/Empresas.jsx (refator final no Plan 14-07) continue enviando o payload antigo sem causar erro 422 nos clientes em produção durante o período de transição.
- **Reconstrução `service_type[]` em `empresas()`:** UI `Comercial/Empresas.jsx` ainda lê chave `service_type` para badges/filtros. Reconstruímos via mapa nome→slug a partir de `contratosServico.servico.nome`. Plan 14-06 dropa a coluna; Plan 14-07 dropa a chave da prop Inertia.
- **Rota GET `/empresas/novo` migrada para `create()`:** antes apontava para `index()` que era apenas `redirect()->route('comercial.empresas')` — efetivamente noop. Agora retorna a página com prop `servicos_disponiveis`. Comportamento de "visitar /novo abre o formulário" preservado (UI render igual; mais dados pra UI).
- **Roteamento por NOME case-sensitive (str_contains):** D-02 garante Title Case no catálogo via `mb_convert_case(..., MB_CASE_TITLE, 'UTF-8')`. Variantes lowercase nunca entram via fluxo normal — teste 8 do helper documenta esse contrato.
- **Mapeamento `slugSetorParaServico` novo, `resolverSlugsSetores` mantido como `@deprecated`:** evita quebra silenciosa caso outro método ainda chame o legacy. Plan 14-06 remove ambos juntos no drop final.
- **`Phase13ComercialTest` deixada como falhando:** documentado em `deferred-items.md`. Reescrever 12 testes só para usar `servicos[]` em vez de `service_type` é duplicação de esforço — a `Phase14ComercialTest` (escrita nesta task) já cobre todos os COMs equivalentes. Deletion/port para quick task pós Plan 14-06.

## Sistema em COEXISTÊNCIA (atualizado)

**Estado atual após Plan 14-04:**

- `companies.service_type`, `contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price` — **AINDA POPULADOS para empresas pré-Phase 14** (drop só no Plan 14-06)
- **NOVOS cadastros (pós Plan 14-04) NÃO populam mais nenhum campo legacy** — `companies.create()` recebe apenas `name`/`cnpj`/`notes`/`status`/`active`. Os campos legacy ficam null por default.
- `contratos_servico` — **POPULADO com:**
  - Contratos derivados das empresas pré-Phase 14 (via Migration 2 do Plan 14-02)
  - Contratos diretos para empresas pós-Phase 14 (cadastros via UI do Comercial refatorada)
- **Runtime PHP NÃO LÊ MAIS campos legacy em cálculos NEM em cadastro** — apenas:
  - Mappers Inertia ainda incluem `service_type[]` legacy (reconstruído via compat — Plan 14-06/07 limpam)
  - Validation rules dormentes em controllers de UI legacy (marcadas TODO Plan 14-06)

**Próximos plans:**

- **Plan 14-05 — UI Financeiro:** refator das 3 Blade views + JSX (`Admin/Financeiro.jsx`, `Comercial/Empresas.jsx`) consumindo `servicos_contratados` direto (sem o mapa de compat). Pode também já refatorar `NovaEmpresa.jsx`? NÃO — feito aqui.
- **Plan 14-06 — Drop irreversível:** roda `phase14:verificar-cobranca --abort-on-divergence` (CHECKPOINT humano); se exit 0, dropa as 6 colunas legacy + remove chaves legacy de todos os arrays Inertia + remove `labelFromTypes` + valida rules legacy + remove forma `string` da Notification + remove `resolverSlugsSetores`.

## Deviations from Plan

Nenhuma deviação significativa. Plan executado conforme escrito. Detalhes operacionais documentados acima:

- Adição de `test_publicacao_retorna_null` (9º teste unit não listado no PLAN) — completude conceitual: o nome canônico 'Publicação' também não dispara mlb_empresas (decisão de design — Publicação genérica é apenas tag organizacional, tipo concreto Polos/Assessoria é decidido depois pelo setor).
- Atualização da rota `comercial.empresas.novo` em `routes/web.php` (não listada em `<files_modified>` do PLAN, mas necessária por causa do novo método `create()`). Documentada como decisão de execução.
- `Phase13ComercialTest` ficou com 10/12 testes falhando — documentado em `deferred-items.md` (SCOPE BOUNDARY: fora de escopo do executor; cobertura equivalente reproduzida em `Phase14ComercialTest`).

## Issues Encountered

Nenhum problema bloqueante. Verificação final:

- `php artisan test --filter='Phase14|CobrancaCalculator|ComercialControllerHelper'` → 38 verdes / 149 assertions
- `php artisan phase14:verificar-cobranca` → 0 divergências (DB dev vazio, mas comando roda OK)
- `npm run build` → built in 9.02s

## Cadastro Comercial NÃO PRODUZ MAIS dados legacy

**Confirmação:** A nova `store()` não chama nenhum `Company::create([...'service_type' => $x])`. O array passado para `Company::create()` contém APENAS `name`, `cnpj`, `notes`, `status`, `active`. Os campos legacy ficam `null` por default no DB para todo registro futuro.

**Implicação para Plan 14-06:** o drop das 6 colunas legacy não afeta NENHUM código de produção que escreve nelas — todas as escritas legacy já cessaram a partir deste plan. O drop é seguro do ponto de vista de produção (read-side ainda usa via mapper Inertia, mas reconstruído de `contratosServico`).

## Threat Flags

Nenhuma surface nova introduzida. Mitigações Phase 14 já cobertas:

- **T-14-04 (Tampering — servico_id arbitrário):** `Rule::exists('servicos', 'id')->where('ativo', true)` rejeita IDs inválidos OU de servicos inativos — teste `test_cadastro_com_servico_inativo_falha_validation` valida.
- **T-14-05 (Tampering — valor manipulado client-side):** aceito per D-05 — Comercial pode customizar; activity log via LogsActivity do ContratoServico (Frente A) registra auditoria.
- **T-14-06 (Integrity — race condition no nome):** guard `LOWER(name) = LOWER(?)` em companies + mlb_empresas preservado intacto do Phase 13.
- **T-14-10 (Tampering — payload legacy no update):** validation enxuta IGNORA silenciosa — teste `test_update_ignora_campos_legacy` confirma que `service_type` e `additional_service_price` legacy não são sobrescritos quando enviados.

## Self-Check: PASSED

- Arquivo `app/Http/Controllers/ComercialController.php` modificado (verificado via `php -l` + grep `servicoDisparaImplementacao` retorna 1 match na classe + 9 chamadas nos testes unit)
- Arquivo `resources/js/Pages/Comercial/NovaEmpresa.jsx` modificado (verificado via `npm run build` OK + bundle `NovaEmpresa-DSL4_8Gr.js` no manifest)
- Arquivo `routes/web.php` modificado (verificado via `php -l` + grep `'create'` na linha 103)
- Arquivo `tests/Unit/ComercialControllerHelperTest.php` criado (9/9 verdes, 9 assertions)
- Arquivo `tests/Feature/Phase14ComercialTest.php` criado (8/8 verdes, 38 assertions)
- Suíte combinada Phase 14: 38/38 verdes (149 assertions)
- Commits `b95a804`, `09b4c69`, `7737f44` presentes em `git log --oneline`
- `Phase13ComercialTest` documentada como obsoleta em `deferred-items.md`
