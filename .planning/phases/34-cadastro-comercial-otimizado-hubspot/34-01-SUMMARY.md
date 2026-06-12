---
phase: 34-cadastro-comercial-otimizado-hubspot
plan: 01
subsystem: backend
tags: [migration, schema, model, controller, route, tests, hubspot, companies]
dependency_graph:
  requires: []
  provides:
    - companies.nicho
    - companies.dor
    - companies.vende_ml
    - companies.faturamento_mensal
    - companies.marketplaces_extras
    - companies.email_colaborador
    - companies.empresa_nova
    - companies.empresa_nova_visto_em
    - companies.empresa_nova_visto_por
    - hubspot_eventos (tabela)
    - Endpoint POST /companies/{company}/marcar-visto
    - Pendencia 'empresa_nova'
    - Fix pendencia 'sem_email_colaborador' (D-07)
  affects:
    - app/Http/Controllers/CompanyController.php (index payload + pendencias + marcarVisto)
    - app/Models/Company.php (fillable + casts)
    - routes/web.php (rota nova sob role:admin)
tech_stack:
  added:
    - Eloquent cast 'decimal:2' em faturamento_mensal
  patterns:
    - Migration defensiva com Schema::hasColumn (Phase 14 pattern)
    - Auditoria + replay via tabela dedicada (hubspot_eventos)
    - role:admin via middleware EnsureUserHasRole
key_files:
  created:
    - database/migrations/2026_06_12_300001_add_close_fields_to_companies_table.php
    - database/migrations/2026_06_12_300002_create_hubspot_eventos_table.php
    - app/Models/HubspotEvento.php
    - tests/Feature/Phase34CompaniesCloseFieldsTest.php
  modified:
    - app/Models/Company.php
    - app/Http/Controllers/CompanyController.php
    - routes/web.php
decisions:
  - D-01 schema 9 colunas em companies (defensivo Schema::hasColumn)
  - D-02 tabela hubspot_eventos com 2 indexes
  - D-06 endpoint marcar-visto dedicado role:admin
  - D-07 fix pendencia sem_email_colaborador olha email_colaborador
metrics:
  duration_min: 12
  completed_date: 2026-06-12
  tasks_completed: 4
  files_created: 4
  files_modified: 3
  commits: 4
  tests_added: 8
  tests_total_green: 36
requirements:
  - REQ-34-01
  - REQ-34-02
  - REQ-34-03
  - REQ-34-04
---

# Phase 34 Plan 01: Schema + pendencias + endpoint "marcar visto" Summary

Fundacao backend da Phase 34: 9 colunas novas em `companies` (close comercial + tag empresa nova), tabela `hubspot_eventos` (auditoria para o webhook do Plan 34-04), nova pendencia `empresa_nova`, fix do bug semantico D-07 (`sem_email_colaborador` agora olha `email_colaborador`), e endpoint admin-only `POST /companies/{company}/marcar-visto`. Wave 1 completa — desbloqueia W2 (3 plans paralelos: 34-02 wizard comercial, 34-03 admin UI, 34-04 HubSpot webhook).

## Tasks Executadas

| # | Task | Commit | Arquivos |
|---|------|--------|----------|
| 1 | Migrations (close fields + hubspot_eventos) | `5b04bc9` | 2 migrations |
| 2 | Company fillable+casts + model HubspotEvento | `8574173` | 2 models |
| 3 | CompanyController (pendencia + fix D-07 + payload + marcarVisto) + route | `3ff9688` | controller + routes/web.php |
| 4 | Suite Phase34CompaniesCloseFieldsTest (8 cases) | `8262ca1` | 1 test file |

## Decisoes Tomadas

### Defensividade da migration 300001

Cada `Schema::hasColumn` guarda a adicao da coluna. Se a migration for re-rodada manualmente ou se uma coluna for adicionada por outra via (hotfix em prod), o `up()` nao quebra. Padrao herdado da Phase 14 (Plan 14-02). Verificado localmente com rollback + migrate (ambos OK).

### `empresa_nova_visto_por` com `nullOnDelete` (nao `cascadeOnDelete`)

Preserva auditoria mesmo se o admin que marcou for excluido — sabemos QUANDO (`visto_em`) ainda que `visto_por` vire NULL.

### `signature_valid` em `hubspot_eventos` default 0 (false)

Mesmo eventos com HMAC invalido sao gravados — investigacao de ataques/misconfig. O Plan 34-04 vai gravar a row ANTES de validar (pattern Phase 26 `EcfWebhookController`).

### `marcar-visto` NAO dispara activity log

Mudanca operacional rotineira (admin abrindo tela e marcando 5-10 empresas no dia). Para nao poluir o feed. Se for necessario auditar quem-marcou-quando, basta consultar `empresa_nova_visto_em` + `empresa_nova_visto_por` direto na coluna.

### `faturamento_mensal` cast `decimal:2` + conversao para `float` no payload

Cast retorna string em SQLite (testes); para garantir que o JSON Inertia tenha um numero, o controller faz `(float)` explicito no payload. Padrao Phase 14 (Plan 14-02 — Pitfall 4 RESEARCH).

### Refresh explicito do model nos testes apos `Company::create()`

SQLite (banco de testes) NAO retorna defaults de coluna no insert — o atributo `empresa_nova` no objeto retornado pelo create() fica `null` ate o `refresh()`. Documentado inline no teste T2 para futuros plans nao caírem na mesma armadilha.

## Verificacao

- [x] `php artisan migrate` rodou local sem erro
- [x] `php artisan migrate:rollback --step=2 && php artisan migrate` rodou sem erro (defensividade ok)
- [x] Tinker: `Company` fillable contem `nicho`, `empresa_nova`
- [x] Tinker: classe `App\Models\HubspotEvento` existe
- [x] `php artisan route:list --name=companies.marcar-visto` retorna a rota
- [x] Route lista middleware `Authenticate`, `EnsureEmailIsVerified`, `EnsureUserHasRole:admin`
- [x] Suite Phase 34 — 8/8 verdes (30 assertions)
- [x] Suite Phase 31+33+34 — 36/36 verdes (184 assertions), zero regressao

## Desvios do Plano

### Auto-fixed (Rule 1/2/3)

**1. [Rule 2 - Robustez] Conversao explicita de `faturamento_mensal` para `float` no payload**

- **Encontrado durante:** Task 3
- **Issue:** Cast `decimal:2` retorna string em SQLite. Payload Inertia entregaria `"50000.75"` (string) ao JSX em vez de numero.
- **Fix:** `$c->faturamento_mensal !== null ? (float) $c->faturamento_mensal : null` no payload.
- **Justificativa:** Plans 34-02/34-03 vao consumir esse payload em forms; receber string quebra `parseFloat` defensivo e Inputs `type=number`.
- **Commit:** `3ff9688`

**2. [Rule 2 - Robustez] Cast `empresa_nova_visto_por => integer` no Company**

- **Encontrado durante:** Task 2
- **Issue:** FK BIGINT vinha como string em alguns drivers; nao havia cast explicito.
- **Fix:** Adicionado `'empresa_nova_visto_por' => 'integer'` em $casts.
- **Justificativa:** Plans futuros vao usar para join/comparacao — string vs int gera bugs sutis.

**3. [Rule 2 - UX] Refresh defensivo do model em T2**

- **Encontrado durante:** Suite execution (Task 4)
- **Issue:** Teste falhou em `assertTrue($empresa->empresa_nova)` apos `Company::create([])` — SQLite nao popula defaults no objeto retornado.
- **Fix:** Adicionado `$empresa->refresh()` antes da asserao + comentario explicativo inline.

### Architectural (Rule 4)

Nenhum.

## Gotchas para Wave 2

**CRITICOS — leia ANTES de comecar os 3 plans paralelos:**

### Plan 34-02 (Wizard Comercial — `Comercial/NovaEmpresa.jsx`)

- **Validacao backend ainda nao foi atualizada:** `ComercialController::store` precisa validar os 9 campos novos (nicho/dor/vende_ml/faturamento_mensal/marketplaces_extras/email_colaborador). O Plan 34-01 NAO tocou em `ComercialController` — entrega so o schema. **34-02 precisa estender as `rules` da validation.**
- **`marketplaces_extras` eh JSON/array no DB:** o form deve enviar como array PHP nativo (axios serializa `multipart/form-data` com `[]`) — Laravel `validate('marketplaces_extras' => 'nullable|array')` + `'marketplaces_extras.*' => 'string|in:shopee,amazon,magalu,temu,tiktok'`. O cast `=> 'array'` no model cuida da gravacao.
- **`vende_ml` eh tinyint nullable** (3-estados): null/0/1. Cuide para o radio button do form aceitar null ("Nao sei") explicitamente.

### Plan 34-03 (Admin UI — `Companies/Index.jsx` + modal admin)

- **Payload ja inclui os 9 campos novos** + `empresa_nova` (bool puro) por empresa. JSX pode ler `company.empresa_nova` direto para badge.
- **Pendencias array ja inclui `empresa_nova`**: adicione no `PENDENCIAS` const (label "Empresa nova") + cor (recomendo `bg-ecf-yellow text-black` para destacar).
- **Botao "Marcar como visto"**: chame `router.post(`/companies/${company.id}/marcar-visto`)` — ja registrado sob `role:admin`. Mostre so se `auth.user.role === 'admin' && company.pendencias.includes('empresa_nova')`.
- **Pendencia `sem_email_colaborador` vai RESSURGIR em massa** apos deploy do schema: o autopop do ECF Drive populou so `email_cliente`, nao `email_colaborador`. Esperado — admin vai editar manualmente. Considere copy do banner antes de subir.

### Plan 34-04 (HubSpot Webhook)

- **Tabela `hubspot_eventos` ja existe** com 2 indexes (`status+created_at`, `object_id`). NAO precisa criar.
- **Model `HubspotEvento` ja existe** com `$fillable`, `$casts` e relacionamento `companyCriada()`.
- **Padrao recomendado:** grave a row com `signature_valid=false` ANTES de validar HMAC. Se valido, update a row para `signature_valid=true` e continue. Padrao Phase 26 (`EcfWebhookController`).
- **Idempotencia D-04:** antes de criar Company, faca
  `HubspotEvento::where('object_id', $dealId)->whereNotNull('company_id_criada')->exists()` → pula.
- **Companies vai criar com `empresa_nova=true` por default** (D-04 + D-06). Admin vai ver via badge — fluxo desenhado.

## Self-Check: PASSED

- [x] FOUND: `database/migrations/2026_06_12_300001_add_close_fields_to_companies_table.php`
- [x] FOUND: `database/migrations/2026_06_12_300002_create_hubspot_eventos_table.php`
- [x] FOUND: `app/Models/HubspotEvento.php`
- [x] FOUND: `tests/Feature/Phase34CompaniesCloseFieldsTest.php`
- [x] FOUND commit: `5b04bc9` (migrations)
- [x] FOUND commit: `8574173` (models)
- [x] FOUND commit: `3ff9688` (controller + routes)
- [x] FOUND commit: `8262ca1` (tests)

## CRITICO: NAO Deployar Sozinho

O Plan 34-01 entrega so a **fundacao**. Os 3 plans paralelos da Wave 2 (34-02 wizard, 34-03 admin UI, 34-04 webhook) consomem essas colunas/tabelas. Subir o schema sem a UI cria empresas em branco e expoe o badge "Empresa nova" sem o botao "Marcar visto". **Agrupar deploy dos 4 plans da Phase 34** (igual feito em Phase 31, 32, 33).
