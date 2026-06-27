---
phase: quick/260626-qgf
plan: 01
type: execute
status: complete
one_liner: "Exibir TODOS os MLBs de adgroup-sugador na secao 'MLBs neste adgroup' (auto-load + 1 botao 'Copiar todos'); ML e fonte canonica de IDs, Adman MCP enriquece com metricas quando disponivel."
dependency_graph:
  requires:
    - Phase 39 Plan 39-03 (AdgroupMlbMapRepository)
    - Phase 39 Plan 39-02 (MercadoLivreSugadoresProvider.fetchAdgroupMlbs)
    - Phase 39 Plan 39-04 (SugadorAnalysisService refatorado via factory)
  provides:
    - "Persistencia automatica adgroup->[MLB IDs] em adman_adgroup_mlbs durante analise ML"
    - "Payload `mlbs[]` adicional em SugadorController::show"
    - "MlbsDoAdgroup auto-carrega + 1 botao 'Copiar todos' (Adman MCP virou enriquecimento, nao fonte)"
    - "Parser ML usa ad_group_id (snake_case) — antes usava 'id' (chave inexistente no payload)"
  affects:
    - SugadorAnalysisService (constructor 2->3 params; bloco persistencia ao final do analyzeCompany ML)
    - SugadorController (constructor 3->4 params; show retorna mlbs[])
    - MercadoLivreSugadoresProvider (2 sites trocam 'id' por 'ad_group_id')
    - Sugadores/Show.jsx (header sem MlbHighlight; MlbsDoAdgroup auto-load + ML canonico + freshness so com Adman OK)
tech_stack:
  added: []
  patterns:
    - "Fail-open na persistencia adgroup->MLB (warning + swallow, nao derruba analise)"
    - "ML hint via Inertia prop = fonte canonica; Adman MCP enriquece via merge por mlb_id"
    - "Render compacto no MlbRow quando sem metricas Adman (evita 'R$ 0,00' poluindo UI)"
key_files:
  created: []
  modified:
    - app/Services/SugadorAnalysisService.php
    - app/Http/Controllers/SugadorController.php
    - app/Services/Sugadores/MercadoLivreSugadoresProvider.php
    - resources/js/Pages/Sugadores/Show.jsx
decisions:
  - "Path Adman NAO chama bulkSetFromProvider (Phase 30 ja tem SyncCompanyAdgroupMlbsJob — duplicar criaria double-write)"
  - "MLBs vivem APENAS na secao 'MLBs neste adgroup' (sem MlbHighlight no header — sem duplicacao visual)"
  - "Bloco de freshness Adman so' renderiza com state.data presente E syncedAt resolvido (empresas ML-only nao mostram nada)"
  - "Escopo expandido pos-PLAN original: Tasks 4-6 adicionadas em resposta a achados do UAT (parser bug + UX polish + ML canonico)"
metrics:
  completed_date: 2026-06-26
  tasks_completed: 7
  tasks_total: 7
  commits: 7
  deploys: 4
  uat_rounds: 3
---

# Quick Task 260626-qgf: Exibir Todos os MLBs do Adgroup Sugador — Summary

## Status: COMPLETE (validado pelo operador 2026-06-26)

## One-Liner

A secao "MLBs neste adgroup" do `Show.jsx` agora carrega automaticamente todos os MLBs do adgroup-sugador (via API ML como fonte canonica de IDs + Adman MCP enriquecendo com metricas quando disponivel), com apenas 1 botao "Copiar todos". Header da view ficou limpo, sem duplicacao de info.

## Escopo expandido durante a execucao

O PLAN.md original (Tasks 1-3) cobria apenas a persistencia + chips no header + UAT. **3 Tasks adicionais** foram introduzidas em resposta a achados do UAT em producao:

| # | Task | Origem |
|---|------|--------|
| 1 | Persistir mapa adgroup->[MLB IDs] na analise ML | PLAN original |
| 2 | `mlbs[]` no payload + chips `MlbsList` no header | PLAN original |
| 3 | Checkpoint UAT humano | PLAN original |
| 4 | **Parser fix**: provider usa `'ad_group_id'` no payload ML (era `'id'` — chute do CANDIDATO) | Achado UAT 1 |
| 5 | **UX**: tirar chips do header, auto-load do `MlbsDoAdgroup`, 1 botao "Copiar todos" | Pedido operador pos UAT 1 |
| 6 | **ML canonico**: `mlbsHint` virou fonte de IDs, Adman MCP so enriquece metricas | Achado UAT 2 (empresa ML-only) |
| 7 | **Polish**: remover `MlbHighlight` do header, esconder freshness Adman em ML-only | Pedido operador pos UAT 3 |

## Arquivos modificados

### 1. `app/Services/SugadorAnalysisService.php` (commit `647ff36`)

- Constructor: 2 -> 3 params (`AdgroupMlbMapRepository`).
- `analyzeCompany`: novo bloco apos `Sugador::upsert(...)` chamando `$provider->fetchAdgroupMlbs(...)` + `bulkSetFromProvider($company->id, $map)`.
- So roda quando `!$dryRun && $provider->name() === 'ml'` (Adman continua com `SyncCompanyAdgroupMlbsJob` da Phase 30).
- Fail-open via try/catch + `Log::warning` — falha do fetch nao derruba a analise.

### 2. `app/Http/Controllers/SugadorController.php` (commit `f979975`)

- Constructor: 3 -> 4 params (`AdgroupMlbMapRepository`).
- `show`: novo lookup via `getMlbsForAdgroup($sugador->company_id, (string)$sugador->adgroup_id)` para sugadores `tipo=adgroup` com `adgroup_id` preenchido.
- Payload Inertia ganha chave `'mlbs' => $mlbs`. `mlb_id` singular preservado (compat).

### 3. `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` (commit `5ef38e2`)

- **Bug raiz descoberto via smoke direto na empresa 298 (advertiser 620095):** payload de `/product_ads/items` usa chave `'ad_group_id'` (snake_case), nao `'id'`.
- 2 sites corrigidos:
  - Linha 209 (mapping de adgroup-sugador): `(string)($r['ad_group_id'] ?? '')`.
  - Linha 260 (mapping em `fetchAdgroupMlbs`): idem.
- Comentarios `// CANDIDATO` removidos junto com o fix — agora confirmados pos-smoke.
- Antes do fix: 100% dos sugadores ML eram persistidos com `adgroup_id=''` e a tabela `adman_adgroup_mlbs` caia toda no mesmo bucket vazio.

### 4. `resources/js/Pages/Sugadores/Show.jsx` (commits `f979975` -> `db1b918` -> `49af345` -> `ec79124`)

Evolucao em 4 commits conforme UAT iterou:

- `f979975`: prop `mlbs` adicionada, componente `MlbsList` com chips no header.
- `db1b918`: chips do header removidos, `MlbsDoAdgroup` auto-carrega ao montar, mantem apenas botao "Copiar todos" (remove "Carregar MLBs", "Recarregar", "Copiar provaveis", "Forcar atualizacao").
- `49af345`: `mlbsHint` virou fonte canonica de IDs; Adman MCP enriquece via merge por mlb_id; `MlbRow` renderiza versao compacta (so ID + link ML) quando nao ha metricas Adman; 422 silencioso para empresa ML-only.
- `ec79124`: `MlbHighlight` removido do header (decisao operador — evita duplicacao com a secao abaixo); bloco de freshness so renderiza quando `state.data && syncedAt` (corrige "⚠ Dado defasado" aparecendo com data vazia em ML-only).

## UAT (3 rodadas em prod)

### UAT 1 — Operador relatou "todos sugadores com 1 MLB so"

Diagnostico via SSH no VPS revelou:
- 1550 rows em `adman_adgroup_mlbs` da empresa 298 TODAS com `adgroup_id IS NULL` (bug do parser).
- Sugadores `tipo=adgroup` recem-criados com `adgroup_id=''` pelo mesmo motivo.
- Comentario `// CANDIDATO` no provider ja avisava que a chave era chute.
- **Fix:** Task 4 (parser).
- **Cleanup:** `DELETE FROM adman_adgroup_mlbs WHERE cust_id='436501796' AND adgroup_id IS NULL` removeu 1550 rows lixo.

### UAT 2 — Operador relatou "deixou de mostrar todos MLBs"

Diagnostico:
- 9 de 10 sugadores novos da empresa 298 tinham >1 MLB no ML (8, 4, 5, 2 etc).
- Mas a secao "MLBs neste adgroup" mostrava 0 MLBs + caixa vermelha de erro.
- Causa: `SugadorController::mlbs` (endpoint Adman) retorna 422 "Empresa sem adman_account_id" para empresas ML-only — empresa 298 cai nesse caso.
- **Fix:** Task 6 (ML canonico).

### UAT 3 — Operador pediu polimento

- "MLB no header nao precisa, tem na secao abaixo" → Task 7 (remove `MlbHighlight`).
- "Mensagem '⚠ Dado defasado · ultima sincronizacao em' aparece em todos" → Task 7 (esconde bloco de freshness quando `state.data` null).

### UAT final — VALIDADO

Operador respondeu "validado" apos o deploy do `ec79124`.

## Build / deploys

- 4 deploys em prod via `bash deploy.sh` (push + git pull no VPS + `npx vite build` + `composer install` + `php artisan migrate --force` + caches + `supervisorctl restart ecf-worker:*`).
- Nenhum erro de sintaxe PHP/JSX em nenhum deploy.
- Nenhuma migration nova rodou (decisao do PLAN original: usar `adman_adgroup_mlbs` ja existente).

## Cleanup operacional executado

```sql
DELETE FROM adman_adgroup_mlbs
WHERE cust_id = '436501796'
  AND adgroup_id IS NULL;
-- 1550 rows removidas (lixo pre-parser-fix da empresa 298 ByMobille Teste)
```

Outras companies podem ter rows similares com `adgroup_id IS NULL` da era pre-fix — nao foram limpas neste quick (operador escolheu cleanup apenas da empresa 298). Backlog futuro: query global de auditoria + cleanup geral.

## Side-effects / fantasmas conhecidos

- Sugadores `tipo=adgroup` da empresa 298 criados PRE-parser-fix (ids 19355..19364) tem `adgroup_id=''` e continuam na tabela `sugadores`. A unique key do `Sugador::upsert` inclui `adgroup_id`, entao a re-analise pos-fix criou NOVOS sugadores ao lado (ids 20247..20256) com `adgroup_id` real preenchido. Os fantasmas serao reciclados pelo `auto_resolvido` na proxima rodada quando deixarem de bater criterio (memory `feedback_perguntar_antes_deploy_v9.md`).
- Cleanup desses fantasmas pode ser feito em quick separado se incomodar — nao bloqueia operacao.

## Commits

| SHA | Mensagem |
|-----|----------|
| `bb7d4a0` | docs(260626-qgf): pre-dispatch plan |
| `647ff36` | feat(qgf): persiste mapa adgroup->MLB durante analise ML (Task 1) |
| `f979975` | feat(qgf): exibe todos os MLBs do adgroup-sugador no Show.jsx (Task 2) |
| `5ef38e2` | fix(qgf): parser ML usa ad_group_id, nao id (Task 4) |
| `db1b918` | ui(qgf): unifica exibicao de MLBs na secao 'MLBs neste adgroup' (Task 5) |
| `49af345` | fix(qgf): ML como fonte canonica de IDs no drilldown de MLBs (Task 6) |
| `ec79124` | ui(qgf): remove MLB do header e bloco de freshness Adman em ML-only (Task 7) |

## Self-Check: PASSED

- [x] Persistencia `adman_adgroup_mlbs` durante analise ML — Task 1
- [x] `mlbs[]` no payload + UI inicial — Task 2
- [x] Parser `ad_group_id` corrigido — Task 4
- [x] UX simplificada (auto-load + 1 botao copiar) — Task 5
- [x] ML como fonte canonica de IDs — Task 6
- [x] Header limpo + freshness suprimido em ML-only — Task 7
- [x] UAT humano em prod (3 rodadas) — Task 3 + UATs 2-3
- [x] Cleanup lixo `adman_adgroup_mlbs` (1550 rows da empresa 298)
- [x] `npm run build` verde em todas as iteracoes
- [x] 4 deploys em prod sem erro
