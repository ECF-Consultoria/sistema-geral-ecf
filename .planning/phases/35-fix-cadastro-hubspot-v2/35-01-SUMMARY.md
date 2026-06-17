---
phase: 35-fix-cadastro-hubspot-v2
plan: 01
subsystem: companies + comercial
tags: [ux, backfill, sort, marketplaces, brl, mlb-empresa]
requires:
  - Phase 34 Plan 34-01 (coluna empresa_nova + close fields)
  - Phase 34 Plan 34-03 (modal admin com close fields)
  - Phase 7 (MlbEmpresa.company_id FK)
provides:
  - Migration de backfill defensiva (companies pre-2026-06-13 zera empresa_nova)
  - Company::mlbEmpresa() hasOne — relacao inversa para filtro
  - CompanyController::index filtra mlbEmpresa + aceita ?sort=
  - Sort UI na aba Pendencias quando filtro=empresa_nova
  - Label marketplaces extras + helper text consistentes (3 sites)
  - BRL formatado em 2 casas decimais via formatCurrency (canonico)
affects:
  - /companies (lista deixa de mostrar empresas com MlbEmpresa associada)
  - Pendencias counters (mesma exclusao aplicada)
  - Comercial/NovaEmpresa, Comercial/Empresas, Companies/Index modal admin
tech-stack:
  added: []
  patterns:
    - "whereDoesntHave para filtro de relacao inversa"
    - "Migration idempotente (where empresa_nova=true)"
    - "Delegacao de fmtBRL local para formatCurrency canonico"
key-files:
  created:
    - database/migrations/2026_06_13_400001_backfill_empresa_nova_existentes.php
  modified:
    - app/Models/Company.php
    - app/Http/Controllers/CompanyController.php
    - resources/js/Pages/Companies/Index.jsx
    - resources/js/Pages/Comercial/NovaEmpresa.jsx
    - resources/js/Pages/Comercial/Empresas.jsx
decisions:
  - "D-01: Migration zera apenas created_at < 2026-06-13 AND empresa_nova=true (idempotente)"
  - "D-02: sort param do controller + select UI na Pendencias visivel SO quando filtro=empresa_nova"
  - "D-03: whereDoesntHave('mlbEmpresa') aplicado na query base — lista e contadores em sincronia"
  - "D-07: Padronizado BRL em 2 casas decimais via formatCurrency de @/lib/utils (canonico)"
  - "D-08: Label '\''Em quais outros marketplaces o cliente ja vende?'\'' + helper text aplicado em 3 sites"
metrics:
  duration_minutes: ~25
  tasks_completed: 7
  files_modified: 5
  files_created: 1
  commits: 5
completed_at: 2026-06-17T14:29Z
---

# Phase 35 Plan 35-01: Fixes UX/backfill + filtro /companies sem MlbEmpresa — Summary

Wave 1 da Phase 35. Bateria atomica de fixes do pos-uso real da Phase 34: backfill da
tag "empresa nova", filtro de dupla contagem `/companies` vs `/mlb/empresas`, sort
opcional por created_at na Pendencias, padronizacao de copy/BRL nos formularios
de cadastro de empresa.

## Escopo Entregue

### 1. Migration backfill `empresa_nova` (D-01)

Arquivo: `database/migrations/2026_06_13_400001_backfill_empresa_nova_existentes.php`

```php
DB::table('companies')
    ->where('created_at', '<', '2026-06-13 00:00:00')
    ->where('empresa_nova', true)  // idempotencia
    ->update([
        'empresa_nova'           => false,
        'empresa_nova_visto_em'  => now(),
        'empresa_nova_visto_por' => null,  // = backfill automatico (vs marcacao humana)
    ]);
```

- `down()` no-op intencional (apos backfill nao da pra distinguir backfill de marcacao humana).
- Validado via tinker (DB direto): old record (12/06) -> `empresa_nova=0`; new record (14/06) -> mantem `empresa_nova=1`; re-run afetou 0 linhas.

### 2. Relacao `Company::mlbEmpresa()` (D-03 dep)

Adicionada `hasOne(MlbEmpresa::class)` em `app/Models/Company.php`.

Verificado via tinker com synthetic data:
- Company sem MlbEmpresa -> `whereDoesntHave('mlbEmpresa')` inclui
- Company com MlbEmpresa -> filtra fora corretamente

### 3. `CompanyController::index` — filtro + sort (D-02 + D-03)

- `whereDoesntHave('mlbEmpresa')` aplicado na query base (lista E contadores em sincronia, ja que `pendCounts` usa o mesmo `companies` mapeado).
- Aceita `?sort=nova_recente|nova_antiga` validado contra whitelist; valores fora dela viram `null` (cai no default `orderBy('name')`).
- Expoe `filters.sort` no payload Inertia para o `<Select>` UI sincronizar estado.

### 4. `Companies/Index.jsx` — sort UI + label marketplaces (D-02 + D-08)

- `<Select>` "Ordenar" aparece na barra da Pendencias APENAS quando `pendenciaFilter === 'empresa_nova'`. 3 opcoes (Padrao/Mais recente/Mais antiga). Click dispara `router.get` preservando `tab=pendencias`.
- Label do bloco marketplaces no modal admin reformulado para `"Em quais outros marketplaces o cliente ja vende?"` + helper `"Marketplaces que o cliente ja opera por conta propria. Nao confundir com servicos que vamos prestar."`

### 5. `Comercial/NovaEmpresa.jsx` + `Comercial/Empresas.jsx` — label consistente (D-08)

Mesmo label + helper text aplicado nos dois sites (consistencia com Companies/Index).

### 6. Audit BRL padronizado em 2 casas (D-07)

- `Comercial/Empresas.jsx` tinha `fmtBRL` com `minimumFractionDigits: 0` (truncava decimais). Substituido por delegacao para `formatCurrency` de `@/lib/utils`.
- `Comercial/NovaEmpresa.jsx` ja tinha `fmtBRL` em 2 casas, mas tambem migrado para delegar ao helper canonico — fonte unica de verdade.
- `Companies/Show.jsx` ja usava `formatCurrency` em todos os pontos (Faturamento mensal, contratos, KPIs). Nao precisou de mudancas.
- `Companies/Index.jsx` modal admin tem apenas `<input type="number">` para faturamento, sem display formatado — sem inconsistencia.

## Verificacao

- `php artisan migrate` -> migration nova roda local sem erro.
- Tinker (DB direto) -> idempotencia + backfill seletivo OK.
- Tinker -> `whereDoesntHave('mlbEmpresa')` filtra corretamente companies com/sem mlb associada.
- `npm run build` -> verde, sem warnings novos.
- `php artisan test --filter="Phase31|Phase33|Phase34"` -> **53 passed (338 assertions)** — zero regressao no baseline.
- Suite expandida (Phases 8/18/25/29/31/33/34/35) -> **126 passed (957 assertions)** confirmando ausencia de regressao colateral.

## Deviations from Plan

None — plano executado conforme escopo. Pequena escolha discricionaria sobre layout do `<Select>` de sort (ml-auto na barra de filtros da Pendencias, opcao 3 "Padrao (nome)" oferecida pra limpar sem precisar de outro botao).

## Authentication Gates

Nenhum — execucao autonoma, sem interacao com APIs autenticadas.

## Known Stubs

Nenhum stub introduzido. Plano nao adiciona componentes com data placeholder.

## Pre-existing Issues (Out of Scope)

A suite completa (`php artisan test` sem filtro) tem 45 falhas pre-existentes em testes
das Phases 13/14 (`Phase13ComercialTest`, `Phase14*`, `CalcularFaixaTest`, `DevControllerTest`,
`ExampleTest`, `FechamentoMigrationTest`, `AdminFechamentoControllerTest`) — todas relacionadas
a colunas legacy do User renomeadas na Phase 7 (referencia: project memory entry `project_legacy_columns_rename.md`). **Nao** sao causadas por este plano e estao registradas como deferred.

## Gotchas para Wave 2

**Plan 35-02 (HubSpot v2)**: ao implementar D-04/D-05 (criar MlbEmpresa no webhook), reaproveitar a relacao `Company::mlbEmpresa()` agora disponivel — sem precisar query manual em `mlb_empresas`. Note que como /companies agora exclui empresas com MlbEmpresa, criar MlbEmpresa via webhook fara a Company desaparecer de /companies automaticamente (entrara em /mlb/empresas) — comportamento desejado pela D-03, mas verificar UX na notificacao Comercial (link para `/companies/{id}` ainda funciona pq e show direct, nao lista).

**Plan 35-03 (Notification)**: nada conflitante. `CompanyController::index` modificado nao afeta `show`, e a query da audiencia (`AudienciaComercial`) e helper novo a parte.

**Merge**: nenhum conflito esperado com 35-02/35-03 — eles tocam `HubspotApiClient`, `HubspotWebhookController`, `app/Notifications/`, `app/Support/`. Este plano tocou `Company.php`, `CompanyController.php` (apenas `index`), migration nova, e JSX nos paths `Companies/`, `Comercial/`.

## Commits

| Hash      | Mensagem                                                       |
| --------- | -------------------------------------------------------------- |
| `36a3b9a` | feat(35-01): migration backfill empresa_nova em empresas pre-existentes |
| `ec5bf56` | feat(35-01): adiciona relacao Company::mlbEmpresa() hasOne     |
| `64add01` | feat(35-01): CompanyController::index filtra mlbEmpresa + sort opcional |
| `a9047d0` | feat(35-01): Companies/Index UX — sort empresa_nova + label marketplaces |
| `f6510f6` | feat(35-01): Comercial UX — label marketplaces + BRL padronizado 2 casas |

## Self-Check

- [x] `database/migrations/2026_06_13_400001_backfill_empresa_nova_existentes.php` FOUND
- [x] `app/Models/Company.php` modified (mlbEmpresa added)
- [x] `app/Http/Controllers/CompanyController.php` modified (whereDoesntHave + sort)
- [x] `resources/js/Pages/Companies/Index.jsx` modified (sort UI + label)
- [x] `resources/js/Pages/Comercial/NovaEmpresa.jsx` modified (label + fmtBRL)
- [x] `resources/js/Pages/Comercial/Empresas.jsx` modified (label + fmtBRL)
- [x] Commits `36a3b9a`, `ec5bf56`, `64add01`, `a9047d0`, `f6510f6` present in `git log`
- [x] `npm run build` verde
- [x] Phase 31+33+34 suite 53/53 verde (baseline)

**Self-Check: PASSED**
