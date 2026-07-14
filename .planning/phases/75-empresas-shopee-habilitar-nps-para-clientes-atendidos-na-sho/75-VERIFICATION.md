---
phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho
verified: 2026-07-14T14:44:23Z
status: passed
score: 7/7 must-haves verificados
overrides_applied: 0
human_verification:
  - test: "Sidebar como admin — grupo 'Shopee' com filhos 'Empresas' e 'Dashboard (Em breve)'"
    expected: "Grupo Shopee renderiza; item Empresas navega para /shopee/empresas; some para quem não tem a key"
    why_human: "Render visual do NAV_TREE + gating por permissão em runtime — wiring verificado estaticamente, confirmação visual pendente"
  - test: "Atribuir Analista + Estrategista via seleção → bulk-assign na aba"
    expected: "Persistência do pivot ao recarregar; barra de atribuição visível nas duas abas"
    why_human: "Interação de UI (seleção em massa + submit) não coberta por teste de browser"
  - test: "Botão 'Gerar NPS' por linha (empresa com estrategista + contato)"
    expected: "Modal exibe link NPS gerado e copia corretamente"
    why_human: "Fluxo visual do modal + captura de flash.nps_link em runtime (backend já provado por teste)"
---

# Phase 75: Empresas Shopee — Verification Report

**Phase Goal:** Cadastrar empresas atendidas SÓ na Shopee (sem ML, sem métricas/API) via Comercial e gerar NPS em nome delas, através de uma aba "Empresas" da Shopee enxuta (pendências mínimas pro NPS + atribuição Analista/Estrategista), gated por permission `shopee.empresas`. Motor NPS NÃO muda.
**Verified:** 2026-07-14T14:44:23Z
**Status:** passed (com checkpoint visual humano pendente — não bloqueante)
**Re-verification:** Não — verificação inicial

## Goal Achievement

### Observable Truths (mapeadas às decisões LOCKED DEC-1..DEC-5)

| # | Truth | Decisão | Status | Evidência |
|---|-------|---------|--------|-----------|
| 1 | Enum `servicos.setor` aceita `'shopee'` (persiste em SQLite) + serviço "Shopee" semeado idempotentemente | DEC-1 | ✓ VERIFICADO | Migration `2026_07_14_100001` (branch SQLite `string()->change()` sem CHECK; MySQL ALTER MODIFY inclui `'shopee'`) + `100002` (`firstOrCreate` por nome). Teste `Phase75MigracaoSeedTest`: 3 casos verdes (persiste sem CHECK, seed idempotente, constante/labels) |
| 2 | Cadastro Comercial de empresa Shopee sem dado ML NÃO cria MlbEmpresa | DEC-1 | ✓ VERIFICADO | `Phase75CadastroShopeeTest`: 5 casos verdes — company com `adman_account_id`/`ml_store_id` nulos, 1 ContratoServico ativo setor shopee, zero MlbEmpresa, helpers de roteamento ML retornam null para nome exato "Shopee" |
| 3 | Aba filtra só contrato ativo setor shopee; payload sem métrica/cust_id; pendências = sem_responsavel/sem_contato/empresa_nova | DEC-2, DEC-4 | ✓ VERIFICADO | `ShopeeEmpresasController@index` — `empresasShopeeBaseQuery()` filtra `contratosServico.ativo + servico.setor=SETOR_SHOPEE`, sem `withCount(grants)`/`mlToken`/`whereDoesntHave(mlbEmpresa)`; payload sem cust_id/adman/ml/token. `Phase75ShopeeEmpresasTest`: filtro, publicacao-não-aparece, inativo-não-aparece, multi-marketplace-aparece, payload-enxuto-sem-chaves-ml, pendências DEC-2 completas, sem_contato só quando ambos vazios |
| 4 | Guard anti-IDOR no bulkAssign: empresa fora do escopo shopee → 422, fail-closed | DEC-4 | ✓ VERIFICADO | `bulkAssign` valida `ids.*` com closure reusando `empresasShopeeBaseQuery()`; ID fora do escopo derruba request inteiro. Teste `bulk assign rejeita empresa fora do escopo shopee` verde + grava pivot consultor/estrategista verde |
| 5 | Gate `shopee.empresas` — 403 sem key, 200 admin, 200 user de setor com a key | DEC-3 | ✓ VERIFICADO | Rotas `shopee.empresas.index/bulk-assign` sob `middleware('permission:shopee.empresas')` (nunca core.empresas); key no catálogo `Permissions::SHOPEE_EMPRESAS` grupo "Shopee". Testes `gate 403 sem a key`, `gate 200 para admin`, `gate 200 para user de setor com a key` + `Phase75PermissaoCatalogoTest` (3 casos) verdes |
| 6 | NPS gerável para empresa Shopee sem métrica (motor intocado) | DEC-5 | ✓ VERIFICADO | `Phase75NpsShopeeTest`: 2 casos verdes — empresa sem `adman_account_id`/`ml_store_id` + estrategista no pivot gera NpsSurvey com template `is_default` (fallback); admin também gera. Nenhuma alteração em NpsController |
| 7 | Menu: grupo "Shopee" real com filho "Empresas" gated + Dashboard stub | DEC-3 | ✓ VERIFICADO (estático) | `AppLayout.jsx:114-119` — stub "Em breve" convertido em `group:'Shopee'` com filho `Empresas` (`routeName:'shopee.empresas.index'`, `permission:'shopee.empresas'`) + Dashboard stub `badgeText:'Em breve'`. `itemVisivel()` gate o filho. Render visual → checkpoint humano |

**Score:** 7/7 truths verificados

### Required Artifacts

| Artifact | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `database/migrations/2026_07_14_100001_add_shopee_to_servicos_setor_enum.php` | Enum widening cross-driver | ✓ VERIFICADO | Branch mysql/sqlite; down() reverte |
| `database/migrations/2026_07_14_100002_seed_servico_shopee.php` | Seed idempotente serviço Shopee | ✓ VERIFICADO | `firstOrCreate` por nome; timestamp > enum |
| `app/Models/Servico.php` | SETOR_SHOPEE + SETORES + label + isShopee() | ✓ VERIFICADO | linhas 55-91, 155-157 |
| `app/Support/Permissions.php` | key shopee.empresas + grupo Shopee | ✓ VERIFICADO | linhas 64, 159-160 |
| `app/Http/Controllers/ShopeeEmpresasController.php` | index enxuto + bulkAssign com guard | ✓ VERIFICADO | construtor vazio, payload ML-free, guard IDOR |
| `routes/web.php` | rotas shopee.empresas.* gated | ✓ VERIFICADO | linhas 507-513 sob permission:shopee.empresas |
| `resources/js/Pages/Shopee/Empresas.jsx` | página enxuta + Gerar NPS | ✓ VERIFICADO | posta em shopee.empresas.bulk-assign + nps.generate |
| `resources/js/Layouts/AppLayout.jsx` | grupo Shopee no NAV_TREE | ✓ VERIFICADO | linhas 114-119 |
| `tests/Feature/Phase75/` | suíte da phase | ✓ VERIFICADO | 5 arquivos Phase75* + 42 testes verdes |

### Key Link Verification

| From | To | Via | Status | Detalhes |
|------|----|----|--------|----------|
| Empresas.jsx | shopee.empresas.bulk-assign | `router.post(route(...))` | ✓ WIRED | linha 127 |
| Empresas.jsx | nps.generate | `npsForm.post(route('nps.generate'))` | ✓ WIRED | linha 136 (transform injeta company_id) |
| routes/web.php | ShopeeEmpresasController | import + Route::get/post | ✓ WIRED | linhas 44, 512-513 |
| AppLayout NAV_TREE | shopee.empresas.index | `routeName` + `permission` gate | ✓ WIRED | linha 118 |
| index() | Servico::SETOR_SHOPEE | whereHas contratosServico ativo | ✓ WIRED | controller 44-50 |

### Behavioral Spot-Checks / Testes Automatizados

| Behavior | Comando | Resultado | Status |
|----------|---------|-----------|--------|
| Suíte Phase 75 completa | `php artisan test --filter=Phase75` | 42 passed (150 assertions), 42.89s | ✓ PASS |

Distribuição: MigracaoSeed (3), Cadastro (5), NpsShopee (2), PermissaoCatalogo (3), ShopeeEmpresas (16) + suítes vizinhas no diretório (Cadastro/Anuncios legadas) todas verdes.

### Anti-Patterns Found

| File | Line | Pattern | Severidade | Impacto |
|------|------|---------|-----------|---------|
| Empresas.jsx | 226 | `placeholder="Buscar empresa..."` | ℹ️ Info | Atributo HTML legítimo de input — NÃO é stub |
| AppLayout.jsx | 119 | `badgeText:'Em breve'` (Dashboard Shopee) | ℹ️ Info | Stub intencional preservado por DEC-3 (Dashboard deferido) |

Nenhum TODO/FIXME/XXX/PLACEHOLDER nos arquivos entregues. Grep de `companies.bulk-assign|companies.update|companies.destroy` na página → vazio (T-75-15 respeitado: nenhum vazamento de rota core).

### Cobertura Automatizada vs. Checkpoint Humano

**Cobertos por teste automatizado (verdes):**
- DEC-1: enum persiste SQLite + seed idempotente + cadastro sem ML sem MlbEmpresa
- DEC-2/DEC-4: filtro por contrato shopee, payload enxuto, dicionário de pendências, guard anti-IDOR (422)
- DEC-3: gate 403/200 (rota + catálogo de permissão)
- DEC-5: geração de NPS para empresa Shopee sem métrica

**Dependem de checkpoint visual humano (não bloqueantes — wiring já verificado estaticamente):**
- Render do menu (grupo Shopee + filho Empresas gated) na sidebar
- Atribuição Analista/Estrategista pela UI (seleção → bulk-assign → persistência ao recarregar)
- Botão "Gerar NPS" por linha (modal exibe/copia o link)

### Gaps Summary

Nenhum gap bloqueante. Todas as 5 decisões LOCKED (DEC-1..DEC-5) estão implementadas no código e provadas por 42 testes verdes (150 asserções). O goal da phase — cadastrar empresa Shopee sem ML e gerar NPS em nome dela via aba enxuta gated por `shopee.empresas`, sem tocar no motor NPS — está atingido no codebase.

**Fora de escopo (registrado):** falhas pré-existentes em `Phase13ComercialTest`/`Phase14ComercialTest` (payload legacy `service_type`) e nas suítes de Anúncios (Phase 18) são independentes da Phase 75 e estão documentadas em `deferred-items.md`. A suíte filtrada `--filter=Phase75` é 100% verde. Ambiente rodou em SQLite `:memory:` (MySQL local indisponível — MariaDB corrompido), comportamento esperado por `phpunit.xml`.

**Checkpoint visual pendente:** Tarefa 3 do Plan 75-05 (validação humana da sidebar, atribuição na UI e modal Gerar NPS) permanece aberta. É confirmação de UAT visual de wiring já verificado estaticamente — não bloqueia o goal.

---

_Verified: 2026-07-14T14:44:23Z_
_Verifier: Claude (gsd-verifier)_
