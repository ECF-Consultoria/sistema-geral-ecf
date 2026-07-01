---
phase: 51-reestruturacao-grants-nova-api-ecf-drive
plan: "51-02"
status: complete
completed_at: 2026-07-01
wave: 2
depends_on: [51-01]
requirements: [REQ-51-02]
duration_min: ~18
commits:
  - c055b47 test(51-02) RED — Phase 51 test suite (T1+T2)
  - 032d310 feat(51-02) EcfDriveService.grantsResumo/grantsDistribuicao
  - 859ece1 feat(51-02) GrantController.index remoto+fallback+universo corrigido
tests: "22/22 GREEN (10 Phase 51 novos + 12 Phase 20 pré-existentes — zero regressão)"
files_created:
  - tests/Feature/Phase51/GrantsResumoTest.php
files_modified:
  - app/Services/EcfDriveService.php
  - app/Http/Controllers/GrantController.php
---

# Phase 51 Plan 02 — Read-path /grants/resumo + fallback local + universo no_grant

**One-liner:** `EcfDriveService::grantsResumo/grantsDistribuicao` cacheados (300s/3600s) espelhando o pattern `carteiraResumo/breakdown`, `GrantController::index()` consome remoto em try/catch com fallback local + Log::warning('[Grants]'), universo `no_grant` corrigido para respeitar (adman_account_id OR ml_store_id OR ml_token ativo) AND NOT EXISTS grant ativo — tudo TDD RED→GREEN sem regressão.

## Commits (ordem)

| SHA | Tipo | Descrição |
|-----|------|-----------|
| `c055b47` | test | RED — arquivo `tests/Feature/Phase51/GrantsResumoTest.php` com 10 testes (5 T1 + 5 T2), todos falham |
| `032d310` | feat | T1 GREEN — `EcfDriveService::grantsResumo()` (TTL 300s) + `grantsDistribuicao(string $dimensao)` (TTL 3600s, valida `['programa']` only) |
| `859ece1` | feat | T2 GREEN — `GrantController::index(EcfDriveService)` remoto+fallback+SQL corrigido; 8 campos Phase 51 expostos na tabela |

## Arquivos

**Criado:**
- `tests/Feature/Phase51/GrantsResumoTest.php` — 10 testes, `RefreshDatabase` + `Http::fake` + `Cache::flush` no setUp. Helpers: `makeCompany`, `makeMlToken`, `makeGrant`, `actingAsAdmin`, `resumoPayload`.

**Modificado:**
- `app/Services/EcfDriveService.php` — nova seção `── Grants (Phase 51) ──` após `acoesPendentes()`:
  - `grantsResumo()`: `Cache::remember($cacheKey('/grants/resumo'), 300, fn () => $this->get('/grants/resumo'))`.
  - `grantsDistribuicao(string $dimensao)`: valida `in_array($dimensao, ['programa'], true)` — senão `InvalidArgumentException`. `Cache::remember($cacheKey('/grants/distribuicao', ['dimensao'=>$dimensao]), 3600, ...)`.
- `app/Http/Controllers/GrantController.php`:
  - Imports novos: `App\Services\EcfDriveService`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Log`.
  - `index()` → `index(EcfDriveService $service)` (DI).
  - Universo `no_grant` refatorado (era `whereNotIn` em cima de pluck; agora `whereExists`/`whereNotExists`).
  - Novo bloco `$bucketsLocal` com 5× `CompanyGrant::expiringSoon(N)->count()` (7/15/30/60/90d).
  - Try/catch em torno de `$service->grantsResumo()` seguindo pattern `PolosController:524-528`.
  - `stats` no `Inertia::render` ganha 4 chaves novas: `buckets`, `divergencia_ml`, `source`, `totais_remoto`.
  - Tabela `$grants` ganha os 8 campos Phase 51 (programa, iniciativa, nivel_solucion, nombre_solucion, parceiro, localidade, medalha_fecha_in, medalha_fecha_out) — a UI da Wave 3 renderiza opcionalmente.

## Shape final de `stats` (Inertia payload)

```json
{
  "total_companies":  <int>,
  "active_grants":    <int>,
  "expiring_soon":    <int>,   // legacy — count do collection expiringSoon(30)
  "expired_grants":   <int>,
  "no_grant":         <int>,   // universo corrigido
  "buckets": {                  // Phase 51 — do remoto (se OK) OU fallback local
    "d7":  <int>,
    "d15": <int>,
    "d30": <int>,
    "d60": <int>,
    "d90": <int>
  },
  "divergencia_ml":   <int|null>,   // null quando fallback
  "source":           "remote" | "local",
  "totais_remoto":    <array|null>  // ativos/vigentes/expirados do remoto — null quando fallback
}
```

## Payload esperado de `/grants/resumo` (do CONTEXT.md, adotado nos testes)

```json
{
  "totais":      { "ativos": 396, "vigentes": 345, "expirados": 51 },
  "buckets":     { "d7": 2, "d15": 51, "d30": 73, "d60": 90, "d90": 120 },
  "divergencia": { "sellers_em_base_sem_contatos_cpp": 726 }
}
```

**Observação de forward-compat:** o controller acessa `$resumo['buckets']` e `$resumo['divergencia']['sellers_em_base_sem_contatos_cpp']` com `??` fallback — se a API real vier com outra chave (ex: `expirando_em_7d` em vez de `d7`), a Wave 3 pode adaptar sem quebrar a Wave 2. Confirmação empírica do payload real fica para o smoke em prod da v9.0.

## Bug corrigido durante execução (Rule 1)

**Problema:** meu primeiro rascunho do controller usava `whereNotNull('cust_id')` mas `cust_id` é um **accessor** no `Company::getCustIdAttribute()` (retorna `adman_account_id ?: ml_store_id`) — não é uma coluna física do DB. O SQL `whereNotNull('cust_id')` em SQLite in-memory retornava linhas onde não deveria, e o teste `stats.no_grant=2` vs esperado 1 revelou o bug.

**Fix:** substituído por composto `whereNotNull('adman_account_id')->orWhereNotNull('ml_store_id')` dentro do OR com `EXISTS ml_token active`. Testes também ajustados para setar `adman_account_id` (coluna real) em vez de `cust_id` (accessor não fillable).

Auto-fix atômico dentro do commit GREEN da T2 — a validação real foi via teste.

## SQL final do universo `no_grant`

```sql
SELECT id, name, adman_account_id, ml_store_id
FROM companies
WHERE active = 1
  AND (
        (adman_account_id IS NOT NULL OR ml_store_id IS NOT NULL)
     OR EXISTS (
          SELECT 1 FROM ml_tokens
          WHERE ml_tokens.company_id = companies.id
            AND ml_tokens.status = 'active'
        )
      )
  AND NOT EXISTS (
        SELECT 1 FROM company_grants
        WHERE company_grants.company_id = companies.id
          AND company_grants.status = 'active'
      )
ORDER BY name
```

Compatível com SQLite in-memory (usado nos testes) e MySQL/MariaDB (prod).

## Resultado dos testes

```
Phase 51 (novos):   10/10 GREEN (74 assertions)
Phase 20 (legado):  12/12 GREEN (41 assertions) — zero regressão
Total:              22/22 GREEN (115 assertions)
Duration:           ~11s
```

## Desvios do plano

1. **Commit RED consolidado (T1+T2)** — o plano previa 2 commits RED separados (`test(51-02): RED — testes EcfDriveService...` + `test(51-02): RED — testes GrantController...`). Como o arquivo `GrantsResumoTest.php` é único e criei-o de uma vez com os 10 testes, o RED T2 caiu no mesmo commit `c055b47`. Ao rodar após o GREEN T1 (`032d310`), os 5 do controller continuavam vermelhos (comportamento RED puro). Mantém a semântica TDD (RED antes de GREEN) e economiza 1 commit sem valor semântico. Análogo ao desvio 1 da Wave 1.
2. **Bug do `cust_id` accessor descoberto durante GREEN da T2** — o teste falhou pela primeira vez com `stats.no_grant=2` vs 1. Foi Rule 1 (bug de correctness): traduzi o accessor Company::cust_id para as 2 colunas físicas no SQL do controller e ajustei os testes para usar `adman_account_id`. Fix incluído dentro do commit GREEN `859ece1`.
3. **`php artisan test` full não rodado** — rodados apenas os arquivos Phase 51 + Phase 20. Como a única mudança de assinatura pública é `GrantController::index()` → `index(EcfDriveService)` (resolvida pelo container) e nenhum outro teste depende de `GrantController`, cobertura suficiente. Full-suite fica para o deploy da v9.0.
4. **Smoke `/grants` no browser não rodado** — MariaDB local corrompido (per memory `project_mariadb_local_corrompido`); validação real do payload `/grants/resumo` da API remota fica para o deploy autorizado da v9.0 (o CONTEXT alertou que a estrutura exata do payload seria confirmada no momento da execução — chaves `buckets.d7/.../d90` e `divergencia.sellers_em_base_sem_contatos_cpp` adotadas por convenção, com `??` fallback defensivo).

## Success criteria (do PLAN.md)

- [x] `EcfDriveService::grantsResumo()` implementado com `Cache::remember` TTL=300s
- [x] `EcfDriveService::grantsDistribuicao(string $dimensao)` implementado; valida `['programa']` only via `InvalidArgumentException`
- [x] `GrantController::index(EcfDriveService $service)` chama `grantsResumo()` em try/catch e propaga `buckets` + `divergencia` + `source`
- [x] Universo `no_grant` corrigido: `active=true AND (adman_account_id OR ml_store_id OR ml_token active) AND NOT EXISTS grant ativo` — cust_id accessor traduzido para colunas físicas
- [x] `stats.buckets.d7/d15/d30/d60/d90` sempre presente (remoto OU fallback)
- [x] `stats.source === 'local'` quando falha; `'remote'` quando OK
- [x] Log warning `[Grants] /grants/resumo offline — usando contagem local` no fallback (não erro 500)
- [x] Suite Phase 51 ≥10 testes GREEN (10/10), suite Phase 20 sem regressão (12/12)
- [x] Commits TDD separados RED → GREEN em cada tarefa

## Próximo

Wave 3 → `51-03-PLAN.md`: UI (`resources/js/Pages/Grants/Index.jsx`) — StatCards dos 5 buckets com cores progressivas, card "Divergência ML" (tooltip), badge "API offline" quando `stats.source === 'local'`, colunas opcionais na tabela (`programa`, `nivel_solucion`, `medalha_fecha_in/out`) — ocultar quando NULL em todas as linhas.

## Self-Check: PASSED

- `tests/Feature/Phase51/GrantsResumoTest.php` — FOUND
- `app/Services/EcfDriveService.php` — MODIFIED (+55 linhas, grantsResumo+grantsDistribuicao)
- `app/Http/Controllers/GrantController.php` — MODIFIED (imports+DI+bloco no_grant+bucketsLocal+try/catch+stats expandido)
- Commits `c055b47`, `032d310`, `859ece1` — FOUND in `git log`
- Testes: 22/22 GREEN (Phase 51 novos + Phase 20 legados)
