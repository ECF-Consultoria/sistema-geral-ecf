---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
plan: 05
subsystem: comercial-listagem-unificada
tags: [comercial, listagem, filtros-empilhaveis, snake_case, pendencias, origem-hubspot, grupos, sidebar]
requires:
  - 37-01  # servicos.setor (filtro por categoria)
  - 37-02  # hubspot_line_item_mapping (feeder do paraNome usado pelo Plan 37-04)
  - 37-04  # HubspotWebhookController grava line_items_nao_mapeados em payload
provides:
  - "Endpoint GET /comercial/empresas/listagem (rota nomeada comercial.empresas.listagem)"
  - "ComercialController::listagem com filtros snake_case empilhaveis (servico, setor, ordem, pendencia, q)"
  - "5 pendencias comerciais calculadas apenas para empresas de origem HubSpot (REQ-37-10)"
  - "Pagina Comercial/EmpresasListagem.jsx com tabs Empresas/Grupos + cards/chips/tabela"
  - "Sub-item 'Empresas (todos os setores)' no grupo Comercial do AppLayout"
  - "Company.hubspotEventoOrigem (hasOne) + hubspotEventos (hasMany) para detectar origem HubSpot"
affects:
  - "Plan 37-06 (refoco /companies em Performance) usa o mesmo padrao de servicos.setor"
  - "Plan 37-07 (reorg final sidebar: migrar Servicos e Grupos para Comercial)"
tech-stack:
  added: []
  patterns:
    - "Filtros empilhaveis snake_case com whitelist em PHP (in_array em setor/ordem/pendencia)"
    - "withExists para flag is_origem_hubspot sem joins desnecessarios"
    - "Eager loading hubspotEventos limitado (latest 3) para detectar payload->line_items_nao_mapeados sem N+1"
    - "Paginacao manual via LengthAwarePaginator pos-filtro PHP (pendencia depende de calculo em codigo)"
    - "pendencia_counts calculados ANTES do filtro pendencia (contagens absolutas no header)"
    - "Tabs com sync ?tab= via window.history.replaceState (sem refetch ao alternar)"
key-files:
  created:
    - "resources/js/Pages/Comercial/EmpresasListagem.jsx"
    - "tests/Feature/Phase37ComercialListagemTest.php"
  modified:
    - "app/Http/Controllers/ComercialController.php"
    - "app/Models/Company.php"
    - "routes/web.php"
    - "resources/js/Layouts/AppLayout.jsx"
key-decisions:
  - "withExists + scope nome hubspot_evento_origem_exists em vez de subquery raw — usa o Eloquent idiomatico"
  - "Pendencia calculo em PHP (depois do get) em vez de query pura — payload->line_items_nao_mapeados (JSON) eh nao-trivial em SQLite/MySQL portavel"
  - "Filtro pendencia aplicado APOS calcular as pendencias para todas — preserva pendencia_counts absolutos"
  - "Paginator->setCollection apos map para JSX — preserva metadata (current_page, last_page, total) corretos"
  - "Sub-item adicionado como PRIMEIRO do grupo Comercial (porta de entrada operacional); 'Cadastrar empresa' continua segundo"
  - "Tabs ?tab= via history.replaceState (sem rota separada por tab) para preservar filtros ao alternar"
  - "GruposManager reaproveitado intacto — gestor comercial nao-admin enxerga (CRUD via rotas company-groups.* admin-only continua o gate de seguranca)"
  - "Debounce 400ms na busca via setTimeout interno (sem dep extra como use-debounce)"
metrics:
  duration: ~22min
  completed: 2026-06-18
  task_count: 2  # TDD RED + GREEN consolidados (controller + JSX juntos para o teste passar)
  test_count: 17 testes (62 assertions)
  files_created: 2
  files_modified: 4
---

# Phase 37 Plan 37-05: Listagem Comercial Unificada Summary

Nova listagem `/comercial/empresas/listagem` cobrindo TODAS as empresas (todos os setores) com filtros snake_case empilhaveis (servico, setor, ordem, pendencia, q), 5 cards de pendencia comercial isoladas (calculadas apenas para empresas de origem HubSpot via EXISTS em `hubspot_eventos.company_id_criada`) e aba de Grupos integrada via GruposManager — primeira porta de entrada do Comercial.

## What Got Built

### Backend (`ComercialController::listagem`)

Endpoint `GET /comercial/empresas/listagem` renderiza `Inertia::render('Comercial/EmpresasListagem', ...)` com payload:

- `companies` — paginador (50/page) com empresas mapeadas (id, name, cnpj, is_origem_hubspot, pendencias_comerciais, setor_dominante, contratos_servico, grupo, consultor, estrategista, nicho, telefone, email_cliente, created_at)
- `filters` — snake_case com 5 chaves (servico, setor, ordem, pendencia, q); valores sanitizados via whitelist em PHP
- `pendencia_counts` — map `{sem_servico, sem_valor, servico_nao_reconhecido, sem_setor, dados_close_incompletos}` calculado ANTES do filtro `pendencia` (contagens absolutas no header)
- `servico_counts` — chips de filtro com `{id, nome, setor, total}` para categorizacao por setor
- `grupos` — `CompanyGroup::withCount('companies')` para aba Grupos
- `servicos_disponiveis` — catalogo ativo para o GruposManager (atribuir servico ao grupo)

Permissao: `comercial.cadastrar_empresa` OR `isAdmin()` (mesmo padrao dos outros endpoints do Comercial).

### Helper privado `calcularPendenciasComerciais(Company)`

Implementa as 5 regras de pendencia comercial isolada (REQ-37-06). Retorna `[]` vazio para empresas legacy (sem `HubspotEvento::company_id_criada`) — REQ-37-10:

| Pendencia | Regra |
|-----------|-------|
| `sem_servico` | empresa origem HubSpot + 0 contratos ativos |
| `sem_valor` | tem contrato ativo + `valor_contratado=0` em algum deles |
| `servico_nao_reconhecido` | `HubspotEvento.payload` contem `line_items_nao_mapeados` (gravado pelo Plan 37-04) |
| `sem_setor` | TODOS os contratos ativos apontam para Servico com `setor=outros` (ou Servico null) |
| `dados_close_incompletos` | `nicho IS NULL` OR `dor IS NULL` OR `faturamento_mensal IS NULL` |

### Frontend (`Comercial/EmpresasListagem.jsx`, ~370 linhas)

- Tabs **Empresas** / **Grupos** com sincronizacao `?tab=` via `window.history.replaceState` (sem refetch ao alternar)
- Header de filtros: busca debounced (400ms), select setor (Todos/Performance/Publicação/Outros), select ordem (Mais recentes / Mais antigas), CTA `Cadastrar empresa`
- 5 cards de pendencia clicaveis (toggle `pendencia=X`) com contadores absolutos
- Chips de servico (clicaveis, filtram `servico=ID`) com totais via `Badge`
- Tabela com 6 colunas (Empresa, Origem, Servicos, Setor, Pendencias, Acoes) + tooltip de tooltip nos contratos extra
- Paginador Inertia simples (Anterior/Proxima) usando `prev_page_url`/`next_page_url`
- Aba Grupos reaproveita `GruposManager` (CRUD via rotas `company-groups.*` admin-only) — REQ-37-08
- Componentes locais: `OrigemBadge`, `SetorBadge`, `ServicoBadges`, `PendenciaBadges`, `Paginator`
- Dark theme com tokens `ecf-*` consistente com Companies/Index.jsx

### `applyFilter` empilhavel (Licao Phase 18)

```js
const applyFilter = (key, value) => {
  router.get(route('comercial.empresas.listagem'), {
    ...filters,
    [key]: value || undefined,
  }, { preserveState: true, preserveScroll: true });
};
```

Garantia operacional: alterar 1 filtro NUNCA perde os outros 4 — test `test_filtros_empilhaveis_query_string_preservada` valida payload completo.

### `Company` ganha relacoes para detectar origem HubSpot

```php
public function hubspotEventoOrigem() {
    return $this->hasOne(HubspotEvento::class, 'company_id_criada');
}

public function hubspotEventos() {
    return $this->hasMany(HubspotEvento::class, 'company_id_criada');
}
```

Usadas com `withExists(['hubspotEventoOrigem'])` para flag `is_origem_hubspot` (sem joins) + eager loading limitado de `hubspotEventos` (latest 3) para detectar `payload->line_items_nao_mapeados` sem N+1.

### Sub-item no `AppLayout` (grupo Comercial)

```js
{ label: 'Empresas (todos os setores)', routeName: 'comercial.empresas.listagem',
  page: 'Comercial/EmpresasListagem', icon: Building2,
  permission: 'comercial.cadastrar_empresa' },
```

Adicionado como PRIMEIRO sub-item do grupo Comercial (porta de entrada operacional). 'Cadastrar empresa' continua como segundo. Plan 37-07 cuida da reorg final (migrar Servicos e Grupos).

## Testing

**Suite: `Phase37ComercialListagemTest` — 17 testes / 62 assertions / verdes**

| # | Test | Cobre |
|---|------|-------|
| 1 | `test_acesso_403_sem_permissao` | Autorizacao falha sem `comercial.cadastrar_empresa` |
| 2 | `test_admin_acessa_sem_setor_permissao` | Admin via short-circuit `isAdmin()` |
| 3 | `test_lista_todas_empresas_active_sem_filtros` | Filtra `active=true` |
| 4 | `test_filtro_servico_aplicado` | `?servico=ID` filtra via `whereHas('contratosServico')` |
| 5 | `test_filtro_setor_performance` | `?setor=performance` filtra via `whereHas('contratosServico.servico')` |
| 6 | `test_ordem_recentes_vs_antigas` | `?ordem=antigas` inverte `orderBy created_at` |
| 7 | `test_busca_q_match_name_ou_cnpj` | `?q=ACME` casa name OR cnpj |
| 8 | `test_filtros_empilhaveis_query_string_preservada` | 4 filtros simultaneos preservam-se no payload |
| 9 | `test_empresa_origem_hubspot_gera_pendencia_sem_servico` | HubSpot + 0 contratos → `sem_servico` |
| 10 | `test_empresa_legacy_NAO_gera_pendencias_REQ_37_10` | Sem HubspotEvento → `pendencias_comerciais === []` |
| 11 | `test_pendencia_servico_nao_reconhecido` | `payload->line_items_nao_mapeados` → flag |
| 12 | `test_pendencia_sem_setor` | Contratos ativos com setor=outros → flag |
| 13 | `test_pendencia_dados_close_incompletos` | nicho=null → flag |
| 14 | `test_pendencia_sem_valor` | Contrato com `valor_contratado=0` → flag |
| 15 | `test_pendencia_counts_corresponde_a_lista_completa` | Counts independem do filtro `pendencia` |
| 16 | `test_filtro_pendencia_aplicado` | `?pendencia=sem_servico` filtra coleção pós-calculo |
| 17 | `test_servico_counts_exporta_setor` | Chips contem `{id, nome, setor, total}` |

**Zero regressao:** Phase 34/35/36/37 (99/99 testes, 545 assertions) seguem verdes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test usava `assertArrayHasKey` em stdClass**
- **Found during:** Task 1 (verificacao GREEN)
- **Issue:** `DB::table()->selectRaw()` retorna `stdClass`, nao array. `assertArrayHasKey` falhava com `TypeError: Argument #2 must be ArrayAccess|array`.
- **Fix:** Convertido `(array) $props['servico_counts'][0]` antes do assert.
- **Files modified:** `tests/Feature/Phase37ComercialListagemTest.php`
- **Commit:** `4ecd6b3`

### Variacoes do Plan

- **Tasks 1 e 2 consolidadas em 2 commits funcionais** (RED test + GREEN backend+frontend juntos). Motivo: o teste `test_admin_acessa_sem_setor_permissao` chama `$response->assertInertia(fn => $page->component('Comercial/EmpresasListagem'))` que dispara Vite manifest lookup do JSX — sem o JSX criado + `npm run build`, ate o caminho GREEN do controller falha. Atomicidade preservada: testes RED isolados (commit 1) → controller+JSX GREEN (commit 2) → sidebar + page UI (commit 3).
- **Limite de eager loading `hubspotEventos`:** Plan sugeria `->latest()->limit(1)` mas isso so traria o ultimo evento; um deal pode receber multiplos webhooks (idempotencia Plan 37-04). Implementado como `->orderByDesc('id')->limit(3)` para cobrir cenarios reais sem N+1.
- **Helper `temLineItemsNaoMapeados`** do plan foi inlined no `calcularPendenciasComerciais` via `$c->hubspotEventos->contains(...)` — eager loading ja resolve o N+1; metodo separado seria duplicacao.

## Sub-Repos / Multi-Project

Single-repo — sem mapeamento sub_repos. Todos os commits no main worktree.

## Self-Check: PASSED

Validações executadas:

- [x] `tests/Feature/Phase37ComercialListagemTest.php` existe — `git log --oneline` mostra commit 28b1e42 (RED) + 4ecd6b3 (GREEN fix)
- [x] `app/Http/Controllers/ComercialController.php` modificado — metodo `listagem` + `calcularPendenciasComerciais` adicionados
- [x] `app/Models/Company.php` modificado — relacoes `hubspotEventoOrigem` + `hubspotEventos` adicionadas
- [x] `routes/web.php` modificado — rota `comercial.empresas.listagem` registrada
- [x] `resources/js/Pages/Comercial/EmpresasListagem.jsx` existe — criado em commit `eae9036`
- [x] `resources/js/Layouts/AppLayout.jsx` modificado — sub-item adicionado em commit `eae9036`
- [x] Commit 28b1e42 existe no `git log` (test RED)
- [x] Commit 4ecd6b3 existe no `git log` (GREEN controller + route + model + test fix)
- [x] Commit eae9036 existe no `git log` (GREEN page + sidebar)
- [x] Rota `comercial.empresas.listagem` registrada em `php artisan route:list`
- [x] `php artisan test --filter Phase37ComercialListagemTest` → 17/17 verdes, 62 assertions
- [x] Suite combinada Phase 34/35/36/37 → 99/99 verdes (zero regressao)
- [x] `npm run build` verde (manifest atualizado para Vite resolver EmpresasListagem.jsx)
