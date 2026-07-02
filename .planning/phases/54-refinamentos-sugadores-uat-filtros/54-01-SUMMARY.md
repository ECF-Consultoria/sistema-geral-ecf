---
phase: 54-refinamentos-sugadores-uat-filtros
plan: "54-01"
status: complete
completed_at: 2026-07-02
subsystem: sugadores/backend-filters
tags: [backend, filtros, tdd, sugadores, index, porEmpresa]
requires: []
provides:
  - "props is_admin + analistas + analista_id_selecionado no SugadorController::index"
  - "prop periodo + periodo_presets + filtro reference_date no SugadorController::porEmpresa"
  - "cobertura PHPUnit Phase54 dos 2 filtros"
affects:
  - "resources/js/Pages/Sugadores/Index.jsx (Plan 54-03 vai consumir)"
  - "resources/js/Pages/Sugadores/EmpresaListagem.jsx (Plan 54-02/54-04 vao consumir)"
tech-stack:
  patterns:
    - "match($periodo) whitelist estrita + fallback default"
    - "whereIn subquery em company_users role='analista' — reuso do padrao ja usado no filtro user_id (linha 94-101 do index)"
key-files:
  modified:
    - app/Http/Controllers/SugadorController.php
    - tests/Feature/Phase52/SugadorPorEmpresaListagemTest.php
    - tests/Feature/Sugadores/SugadoresIndexTest.php
  created:
    - tests/Feature/Phase54/SugadorIndexAnalistaFilterTest.php
    - tests/Feature/Phase54/SugadorPorEmpresaPeriodoFilterTest.php
decisions:
  - "?analista_id aplicado em \$visibleIds (nao em \$query) — pattern research §3"
  - "periodo invalido cai no default hoje — Common Pitfall #3 do research"
  - "Guard \$user->isAdmin() explicito no analista_id — decisao A3 locked"
metrics:
  duration_min: 55
  commits: 3
  tests_added: 11
  tests_updated: 2 (regressao encadeada)
---

# Phase 54 Plan 54-01: Backend Filtros /sugadores UAT — Summary

Entrega dos 2 filtros de backend que sustentam A3 (dropdown de analista no Index)
e B1 (preset de periodo no drilldown porEmpresa). Zero mudanca de UI aqui —
apenas props Inertia + PHPUnit. TDD estrito (RED em 2 commits separados,
depois GREEN em 1 commit).

## Objetivo alcancado

Backend do Plan 54-01 pronto para consumo pelas waves seguintes (54-02 layout
+ 54-03 header Index + 54-04 filtro periodo UI). Nenhuma quebra de contrato
existente — filtro `?user_id` legacy do index continua funcionando ao lado
do novo `?analista_id`.

## Commits

| SHA       | Tipo    | Mensagem curta                                                              |
| --------- | ------- | --------------------------------------------------------------------------- |
| `ef5093a` | test    | RED: failing tests para filtro de analista no /sugadores                    |
| `81f139c` | test    | RED: failing tests para filtro de periodo em porEmpresa                     |
| `6f2ba55` | feat    | GREEN: filtros analista_id (index) e periodo (porEmpresa) + regressao fixes |

## Arquivos

### Criados

- `tests/Feature/Phase54/SugadorIndexAnalistaFilterTest.php` (4 cenarios)
  - admin recebe `is_admin=true` + `analistas` distintos ordenados
  - nao-admin recebe `is_admin=false` + `analistas=[]`
  - admin com `?analista_id=X` reduz `companies_summary` para so a carteira de X
  - nao-admin com `?analista_id=X` ignora o param (bypass protection)
- `tests/Feature/Phase54/SugadorPorEmpresaPeriodoFilterTest.php` (7 cenarios)
  - default `hoje` filtra reference_date=today()
  - `?periodo=7d` traz janela de 7 dias
  - `?periodo=30d` traz janela de 30 dias
  - `?periodo=todos` NAO aplica filtro de data
  - props `periodo` (eco) + `periodo_presets` (opcoes) presentes
  - `?periodo=xpto` invalido cai em `hoje` (seguranca)
  - regressao: analista fora da carteira mantem 403 mesmo com `periodo=todos`

### Modificados

- `app/Http/Controllers/SugadorController.php`
  - `index()`: props `is_admin`, `analistas`, `analista_id_selecionado`;
    filtro `?analista_id` reduz `$visibleIds` + `$companies` antes do
    bloco de aggregates
  - `porEmpresa()`: whitelist de periodo + match() aplicando filtro
    `reference_date`; props `periodo` + `periodo_presets`
- `tests/Feature/Phase52/SugadorPorEmpresaListagemTest.php`
  - `test_ordena_por_reference_date_desc` agora passa `?periodo=todos`
    (default mudou de "tudo" para "hoje" na Phase 54 — decisao B1 locked)
- `tests/Feature/Sugadores/SugadoresIndexTest.php`
  - Limite de queries do teste anti-N+1: 16 -> 17 (nova query fixa da
    lista de analistas; mesmo pattern whereIn+subquery do `$users`
    ja existente, sem N+1)

## Resultado dos testes

| Suite                                        | Antes    | Depois         |
| -------------------------------------------- | -------- | -------------- |
| `Phase54`                                    | inexiste | **11/11**      |
| `Phase52`                                    | 24/25    | **25/25** (regressao curada) |
| `Sugadores/` (feature completo)              | 15/16    | **16/16**      |
| `Phase42/RegressaoSugadoresExistentesTest`   | 12/13    | **13/13** (encadeado com Sugadores/) |
| `Phase39/MercadoLivreSugadoresProviderTest`  | 8/10 (2 red pre-existente) | 8/10 (idem — fora do escopo) |

Comando de referencia: `php artisan test --filter=Phase54` → 11/11 verdes em ~17s.

## Decisoes de execucao

### 1. Filtro `?analista_id` aplicado em `$visibleIds`, nao em `$query`

Research §3 confirma que o pattern `user_id` legacy (linha 94-101) filtra
`$query` (pagination de sugadores), mas o alvo do 54-01 e reduzir o UNIVERSO
do `companies_summary`. Aplicar em `$visibleIds` DEPOIS de `$companies->pluck`
e ANTES do bloco `if (!empty($visibleIds))` que roda o `SUM/CASE` — reduz
o custo do aggregate e mantem o `$companies->map()` (linha ~193) consistente
com o filtro. `$companies` tambem e reduzido via `->whereIn('id', $visibleIds)`
porque o `.map` final itera sobre ele.

### 2. `periodo` invalido cai no default `hoje` (nao 500)

CONTEXT §B1 e Common Pitfall #3 do research. Whitelist estrita
`in_array($periodo, ['hoje', '7d', '30d', 'todos'], true)` — qualquer
outro valor (incluindo `null`) cai no default `hoje`. Impede que um
operador manipule a query string para causar erro de match().

### 3. `todos` distinto de `null`

Cuidado explicito do prompt: `null` (param ausente) aciona o `default` do
match (hoje). `todos` e um valor da whitelist e resolve para `null` no
arm — sem filtro de data. Comportamentos diferentes preservados.

### 4. Guard `$user->isAdmin()` explicito no filtro `analista_id`

Analista tambem tem `can_manage=true` (Phase 52 A1), portanto o guard
tradicional (`can_manage`) permitiria bypass — analista Y poderia filtrar
pela carteira do analista X. Guard estrito `isAdmin()` bloqueia
silenciosamente (nao lanca erro nem 422 — apenas ignora o param).

## Debito tecnico registrado

- **`?analista_id` na URL do `EmpresaListagem` NAO faz sentido** (research
  Pitfall #5). Como `porEmpresa` opera sobre 1 empresa via route model
  binding, propagar `?analista_id` do Index -> EmpresaListagem seria
  overhead sem efeito. Decisao registrada: **nao propagar**. Se
  aparecer no futuro requisito de "manter contexto do filtro entre
  telas", virar seed novo — nao impacta o Plan 54-01.

- **2 testes RED pre-existentes de Phase 39**
  (`MercadoLivreSugadoresProviderTest::test_fetch_adgroups_metrics_normalizes_ml_payload_to_contract_keys`
  e `::test_fetch_adgroup_mlbs_extracts_mlb_ids_from_ads_payload`)
  continuam RED. Confirmei em subprocesso com HEAD original — NAO sao
  regressao 54-01. Marcados como fora de escopo (SCOPE BOUNDARY dos
  deviation rules).

## Desvios (Rule 1)

- **Regressao 1 — `SugadoresIndexTest::test_agregacao_nao_dispara_N_mais_um`**:
  minha impl adiciona 1 query fixa (`SELECT id, name FROM users WHERE id IN
  (SELECT DISTINCT user_id FROM company_users WHERE role='analista')`) para
  popular `$analistas`. O teste anti-N+1 assumia teto 16; agora 17. Rule 1
  (auto-fix): atualizado limite para 17 + comentario explicando o motivo.
  A query e single-shot (whereIn subquery), zero N+1.

- **Regressao 2 — `Phase52/SugadorPorEmpresaListagemTest::test_ordena_por_reference_date_desc`**:
  o teste seed 3 sugadores em datas distintas e esperava todos na listagem.
  Como o default do `porEmpresa` mudou para `periodo=hoje` (decisao B1
  locked, breaking change consciente registrado no research Pitfall #3), o
  teste precisava passar `?periodo=todos`. Rule 1 (auto-fix): atualizado.

## Self-Check: PASSED

- Arquivos criados:
  - `tests/Feature/Phase54/SugadorIndexAnalistaFilterTest.php` → FOUND
  - `tests/Feature/Phase54/SugadorPorEmpresaPeriodoFilterTest.php` → FOUND
- Commits:
  - `ef5093a` → FOUND
  - `81f139c` → FOUND
  - `6f2ba55` → FOUND

## Success criteria

- [x] 4 testes de filtro analista escritos, verdes
- [x] 7 testes de filtro periodo escritos, verdes
- [x] `SugadorController::index` envia props `is_admin` + `analistas`
- [x] Filtro `?analista_id` reduz `companies_summary` apenas para admin
- [x] `SugadorController::porEmpresa` aplica match(\$periodo) com default `hoje`
- [x] Props `periodo` + `periodo_presets` propagadas ao frontend
- [x] Regressao Phase 52 verde (25/25) — o teste de ordenacao atualizado
      para passar `?periodo=todos` reflete o breaking change locked no CONTEXT §B1
- [x] Commits RED e GREEN separados por tarefa (2 RED + 1 GREEN = 3 commits)
