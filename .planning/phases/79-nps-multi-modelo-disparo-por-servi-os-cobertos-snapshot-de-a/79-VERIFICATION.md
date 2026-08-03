---
phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
verified: 2026-07-14T00:00:00Z
status: human_needed
score: 5/5 must-haves verificados
overrides_applied: 0
human_verification:
  - test: "FK MySQL das 3 tabelas de snapshot no VPS"
    expected: "migrate --force cria nps_response_scores/nps_response_covered_services/nps_score_assignments; SHOW CREATE TABLE mostra as FKs (nps_response_id, company_id, nps_response_score_id, user_id cascade; servico_id nullOnDelete) e os índices"
    why_human: "Testes rodam em SQLite :memory:; comportamento cross-driver de FK/índice só é comprovável no MariaDB/MySQL de produção (gotcha 1553 / memória project_mysql_drop_index_fk)"
  - test: "Rollout do disparo estrito no VPS"
    expected: "nps:disparar-mensal --dry-run lista os modelos aplicáveis por empresa e o Log::warning '[NPS Mensal] ... sem modelo aplicável' identifica as empresas que ficariam SEM NPS antes do primeiro envio real"
    why_human: "Blindagem de rollout (DEC-79-A) exige inspeção do log real contra a carteira de produção; não verificável em ambiente de teste"
---

# Phase 79: NPS multi-modelo — disparo por serviços cobertos + snapshot de atribuições por serviço — Relatório de Verificação

**Phase Goal:** NPS opera multi-modelo por "Serviços cobertos": empresa com serviços em áreas diferentes recebe 1 NPS por modelo; cada resposta congela (snapshot) as médias por dimensão + serviços cobertos + atribui as médias SÓ aos responsáveis (`company_users.servico_id`) dos serviços cobertos ∩ ativos. Bônus INTOCADO (Fase 80). Zero regressão no NPS atual.
**Verified:** 2026-07-14
**Status:** human_needed
**Re-verification:** Não — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth (DEC) | Status | Evidência |
|---|-------------|--------|-----------|
| 1 | 3 tabelas de snapshot criadas (schema + FKs) cross-driver (DEC-79-C) | ✓ VERIFIED | `migration 2026_07_14_200001` cria `nps_response_scores`/`nps_response_covered_services`/`nps_score_assignments` com `foreignId()->constrained()` (cascade + `nullOnDelete` em `servico_id`), uniques e índices `(user_id,role)`/`(service_setor)`. `SnapshotSchemaTest` (4 casos, incl. cascade delete + relations Eloquent) verde |
| 2 | Seed NPS Shopee idempotente + NPS Padrão linkado a performance (DEC-79-B/A) | ✓ VERIFIED | `migration 2026_07_14_200002` cria "NPS Shopee" (is_default=false, active=true, envio_automatico_mensal=true, priority=10) com 3 perguntas/15 opções + scope shopee; Passo D linka NPS Padrão a todos os serviços ativos setor=performance via `updateOrInsert`. `SeedNpsShopeeTest` (5 casos, incl. idempotência 0 rows na 2ª execução) verde |
| 3 | Disparo estrito: performance→Padrão, shopee→Shopee, ambos→2, sem cobertura→0+log; dedup por template_id (DEC-79-A) | ✓ VERIFIED | `NpsDispararMensal:199-259` resolve `$modelosAplicaveis` via `whereHas('serviceScopes', whereIn servico_id)`; `isEmpty()`→`Log::warning`+`$puladosSemModelo`+continue (sem fallback); dedup por `(company_id, month_reference, template_id)`. `DisparoEstritoTest` (5 casos da matriz) verde |
| 4 | Submit congela scores por dimensão + covered_services + assignments dentro da transação após as answers (DEC-79-D) | ✓ VERIFIED | `NpsController:718` chama `app(NpsSnapshotService::class)->registrar($response)` DENTRO da `DB::transaction`, após o foreach das answers e antes do `$survey->update(completed)`. `NpsSnapshotService::registrar` grava scores (sum/count/avg via `NpsScoreCalculator`), covered_services e assignments. `SubmitSnapshotTest` (2 casos) verde |
| 5 | Atribuição por serviço: NPS Shopee → analista Shopee, NÃO ML; responsável faltante → sem assignment + pendência (DEC-79-D) | ✓ VERIFIED | `NpsSnapshotService:151-192` interseção cobertos ∩ `contratosServico()->active()`; resolve `consultorDoServico/estrategistaDoServico($servico->id)`; responsável faltante → `Log::warning('[NPS Snapshot] responsável faltante')` sem assignment vazio; empresa (não está em `DIMENSAO_ROLE`) nunca vira assignment. `AtribuicaoPorServicoNpsTest` (3 casos) verde |

**Score:** 5/5 truths verificados

### Verificação DEC-79-E (Bônus intocado) + Zero regressão

| Check | Status | Evidência |
|-------|--------|-----------|
| `DesempenhoScoreService` NÃO alterado nesta fase | ✓ VERIFIED | Último commit do arquivo: `481a0ec` (2026-07-13, Phase 74) — anterior à Phase 79 (2026-07-14) |
| `->principal()` funcionando | ✓ VERIFIED | `DesempenhoScoreService:302` mantém `->principal()`; suite `--filter=Nps` (168 verdes) exercita o dual-path |
| Nenhum consumo de assignments no bônus (escopo Fase 80 não vazou) | ✓ VERIFIED | grep por `nps_score_assignments`/`NpsScoreAssignment` em `app/Services/` e `app/Http/Controllers/` retorna SÓ `NpsSnapshotService` (escritor). Nada lê as atribuições ainda — deferido corretamente à Fase 80 (`deferred-items.md` + pasta `phases/80-*`) |

### Required Artifacts

| Artifact | Status | Detalhes |
|----------|--------|----------|
| `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php` | ✓ VERIFIED | 3 tabelas, FKs cross-driver, uniques, índices; `down()` na ordem inversa |
| `database/migrations/2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php` | ✓ VERIFIED | Seed idempotente + link performance; `down()` no-op intencional (dados semânticos) |
| `app/Models/NpsResponseScore.php` | ✓ VERIFIED | fillable + relations `response()/company()/assignments(hasMany nps_response_score_id)` |
| `app/Models/NpsResponseCoveredService.php` | ✓ VERIFIED | fillable + `response()/servico()` |
| `app/Models/NpsScoreAssignment.php` | ✓ VERIFIED | fillable + `response()/score(belongsTo NpsResponseScore)/company()/user()/servico()` |
| `app/Services/Nps/NpsSnapshotService.php` | ✓ VERIFIED | `registrar()` stateless com `NpsScoreCalculator` injetado; sem transação própria |
| `app/Console/Commands/NpsDispararMensal.php` | ✓ VERIFIED | Loop estrito por modelo; guards de canal/estrategista preservados |
| `app/Http/Controllers/NpsController.php` (submitResponseV15) | ✓ VERIFIED | Wiring do snapshot na posição correta da transação |

### Key Link Verification

| From | To | Via | Status |
|------|----|----|--------|
| `NpsController::submitResponseV15` | `NpsSnapshotService::registrar` | `app(...)->registrar($response)` dentro da `DB::transaction` após answers (`:718`) | ✓ WIRED |
| `NpsSnapshotService` | `Company::consultorDoServico/estrategistaDoServico` | resolução por `$servico->id` na interseção (`:162-164`) | ✓ WIRED |
| `NpsDispararMensal` | `nps_template_service_scopes ∩ contratos ativos` | `whereHas('serviceScopes', whereIn servico_id)` (`:201-205`) | ✓ WIRED |
| `NpsScoreAssignment` | `NpsResponseScore` | `belongsTo(nps_response_score_id)` (`:71-74`) | ✓ WIRED |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Suite V16 completa | `artisan test tests/Feature/V16` | 54 passed (231 assertions) | ✓ PASS |
| Regressão NPS (submit legacy/v15 + `->principal()`) | `artisan test --filter=Nps` | 168 passed (1062 assertions) | ✓ PASS |
| Regressão Desempenho (bônus) | `artisan test --filter=Desempenho` | 55 passed, 1 failed (pré-existente) | ✓ PASS (ver nota) |

### Requirements Coverage

| Requisito | Plano | Status | Evidência |
|-----------|-------|--------|-----------|
| DEC-79-A (disparo estrito) | 79-02, 79-03 | ✓ SATISFIED | Truth #2, #3 |
| DEC-79-B (seed NPS Shopee) | 79-02 | ✓ SATISFIED | Truth #2 |
| DEC-79-C (3 tabelas snapshot) | 79-01 | ✓ SATISFIED | Truth #1 |
| DEC-79-D (cálculo/atribuição no submit) | 79-04 | ✓ SATISFIED | Truth #4, #5 |
| DEC-79-E (bônus intocado) | 79-04 | ✓ SATISFIED | Seção DEC-79-E |

### Anti-Patterns Found

Nenhum. Scan por `TBD/FIXME/XXX/HACK/não implementado` nos 8 arquivos modificados retornou vazio (exit 1). Sem stubs, sem placeholders, sem `return null`/dados vazios hardcoded no fluxo de renderização.

### Falha pré-existente (fora de escopo — NÃO bloqueante)

`PublicacaoDesempenhoRouteTest::user com mlb dashboard acessa rota e recebe 200` — GET `/publicacao/desempenho` retorna 403 em vez de 200. Confirmado pré-existente e não causado pela Phase 79:
- Arquivo de teste tocado por último em `8748d47` (2026-06-30, Phase 49-02) — anterior a este trabalho.
- É RBAC do módulo de publicação; a Phase 79 só toca NPS (`submitResponseV15`, snapshot, disparo). `DesempenhoScoreService` não foi alterado.
- Documentado em `deferred-items.md` para o dono do módulo de publicação/desempenho.

Corresponde exatamente à exceção prevista no objetivo de verificação ("só a pré-existente PublicacaoDesempenhoRouteTest").

### Human Verification Required

1. **FK MySQL das 3 tabelas** — Pós-deploy no VPS: `migrate --force` + `SHOW CREATE TABLE` das 3 tabelas; conferir FKs (cascade em nps_response_id/company_id/nps_response_score_id/user_id, nullOnDelete em servico_id) e índices. Motivo: testes rodam em SQLite :memory:, o comportamento cross-driver de FK só é comprovável no MariaDB/MySQL.

2. **Rollout do disparo estrito** — Pós-deploy no VPS: `nps:disparar-mensal --dry-run` e conferir o `Log::warning "[NPS Mensal] ... sem modelo aplicável"` das empresas que ficariam SEM NPS no novo modo, antes do primeiro envio real (blindagem DEC-79-A).

### Gaps Summary

Nenhum gap. Todos os 5 must-haves verificados no código e comprovados por testes automatizados (V16 = 54 verdes; regressão NPS = 168 verdes; bônus intocado confirmado por git + grep). O status é `human_needed` unicamente porque a Phase declara explicitamente 2 validações Manual-Only no VPS (FK MySQL cross-driver + rollout do disparo estrito) que não são verificáveis no ambiente SQLite de teste — não por ausência de implementação.

---

_Verificado: 2026-07-14_
_Verificador: Claude (gsd-verifier)_
