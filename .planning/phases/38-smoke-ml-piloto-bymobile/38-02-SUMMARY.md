---
phase: 38-smoke-ml-piloto-bymobile
plan: 02
status: partially_complete
subsystem: sugadores
tags: [ml, mercado-ads, product-ads, artisan-command, smoke, tdd, http-fake]
dependency_graph:
  requires:
    - phase: 38-smoke-ml-piloto-bymobile/01
      provides: "MercadoLivreAdsService (4 metodos publicos: discoverAdvertiser, listCampaigns, listAds, tryFetchAdsMetrics)"
    - phase: 20
      provides: "MercadoLivreService::get() — auth/refresh do mlToken (consumido indiretamente via MercadoLivreAdsService)"
  provides:
    - "app/Console/Commands/SugadoresMlSmoke.php (comando Artisan sugadores:ml-smoke --company={id} --days=30)"
    - "tests/Feature/Phase38/MlSmokeCommandTest.php (4 tests Feature Http::fake — 31 assertions)"
    - "Schema da fixture JSON: storage/app/sugadores/ml-smoke/{company_id}-{YYYY-MM-DD}.json"
  affects:
    - "Phase 39 (provider pattern) — BLOQUEADA ate smoke real validar shape do payload ML real"
tech_stack:
  added: []
  patterns:
    - "Comando Artisan diagnostico (exit code 0 mesmo com endpoints falhados; exit 1 so em falha de resolucao de empresa/token)"
    - "Inspecao automatica de campos contrato §2.3 vs payload real (gera contract_fields_present/missing no relatorio)"
    - "Fallback de resolucao de empresa: --company explicito > lookup Bymobille por nome"
key_files:
  created:
    - app/Console/Commands/SugadoresMlSmoke.php
    - tests/Feature/Phase38/MlSmokeCommandTest.php
    - .planning/phases/38-smoke-ml-piloto-bymobile/deferred-items.md
  modified: []
decisions:
  - "Smoke real (Tarefa 3) deferido — MariaDB local corrompido apos tentativa de fix do aria_log_control bloqueou execucao contra API ML"
  - "Plan fecha como partially_complete; codigo + testes 100% verdes; falta APENAS validacao manual contra producao ML"
  - "Phase 39 fica formalmente bloqueada na execucao (planning preliminar pode comecar); destravamento depende de reparar MariaDB local + rodar smoke contra Bymobille"
  - "Codigo nao toca routes/console.php (smoke e manual on-demand); sem agendamento via Scheduler"
  - "Acceptance criteria de seguranca atendido: grep confirma 0 ocorrencias de access_token/refresh_token no comando (T-38-06 mitigado)"
requirements_completed: []  # Nenhum — todos os 4 requirements deste plan dependem do smoke real
requirements_pending:
  - REQ-38-01
  - REQ-38-05
  - REQ-38-06
  - REQ-38-07
metrics:
  duration_min: 12
  completed_date: "2026-06-25"
  task_count: 2  # de 3 totais (Tarefa 3 deferida)
  file_count: 3
  commits: 2
---

# Phase 38 Plan 38-02: Smoke ML — partially_complete (bloqueio MariaDB local)

**Comando Artisan `sugadores:ml-smoke` + suite Http::fake 4/4 verde entregues; smoke real contra API ML deferido por corrupcao do MariaDB local de dev pos-fix do aria_log_control.**

## Status: PARTIALLY COMPLETE

**Codigo:** 100% pronto, testado e commitado.
**Pendente:** validacao manual da Tarefa 3 (smoke real contra API ML usando token da empresa Bymobille - Teste no XAMPP local).
**Razao do partial:** infraestrutura local fora do escopo deste plan (recovery do MariaDB) bloqueia a unica acao manual restante.

## Performance

- **Duracao (Tarefas 1+2 codigo):** ~12 min
- **Iniciado:** 2026-06-25T16:30:00-03:00 (estimativa)
- **Codigo concluido:** 2026-06-25T17:00:00-03:00 (commit `45e986c`)
- **Smoke real:** DEFERIDO (Tarefa 3)
- **Tarefas:** 2/3 completas (3a aguardando recovery do MariaDB)
- **Arquivos modificados:** 3 criados, 0 modificados

## O que foi entregue (Tarefas 1 + 2)

### Comando Artisan `sugadores:ml-smoke`

Localizacao: `app/Console/Commands/SugadoresMlSmoke.php` (311 linhas).

Signature: `sugadores:ml-smoke {--company= : ID numerico da empresa (default: Bymobille - Teste)} {--days=30 : Janela em dias para metricas (clamp 1-90)}`

Orquestra o `MercadoLivreAdsService` (Plan 01) em 4 etapas:
1. `discoverAdvertiser` — descobre advertiser_id Mercado Ads (advertiser vazio vira blocker; erro vira endpoint_failed)
2. `listCampaigns` — pagina campanhas no periodo (so se Etapa 1 retornou advertiser_id)
3. `listAds` (via `tryFetchAdsMetrics`) — encapsula listAds + diferencia "vazio" de "404/5xx"
4. `inspectContractFields` — compara payload real vs contrato §2.3 do plano de migracao (preferencia ads, fallback campanhas)

Saidas:
- **Console:** relatorio CLI estruturado em Markdown (secoes `endpoints_ok`, `endpoints_failed`, `contract_fields_present`, `contract_fields_missing`, `blockers`)
- **Disco:** fixture JSON em `storage/app/sugadores/ml-smoke/{company_id}-{YYYY-MM-DD}.json` com schema completo da CONTEXT.md

Comportamento de exit code (DIAGNOSTICO):
- `0` — smoke executado, com ou sem endpoints falhados (estes viram blockers no relatorio)
- `1` — APENAS em falha de resolucao: empresa nao encontrada, ambigua, ou sem mlToken ativo

Seguranca aplicada:
- T-38-06 (information disclosure): grep confirma **0 ocorrencias** de `access_token` / `refresh_token` no comando — token delegado integralmente ao `MercadoLivreService::get()`
- T-38-07 (SQL injection): `(int) $companyId` cast antes do Eloquent `find()`
- T-38-08 (DoS por paginacao): `--days` clamped em `[1, 90]`; tetos de offset (500 campanhas / 2000 ads) ja vem do Plan 01
- T-38-05 (PII na fixture): warn explicito no console "Fixture pode conter dados reais de cliente — revisar antes de compartilhar fora da equipe"

### Suite de testes Feature

Localizacao: `tests/Feature/Phase38/MlSmokeCommandTest.php` (297 linhas, 4 tests, 31 assertions).

| # | Test | Cobre |
|---|------|-------|
| 1 | `command_fails_when_company_id_does_not_exist` | Exit 1 + mensagem "Empresa nao encontrada" |
| 2 | `command_fails_when_company_has_no_ml_token` | Exit 1 + mensagem "sem token ML" |
| 3 | `command_writes_fixture_with_expected_shape_when_all_endpoints_succeed` | Path feliz: 3 endpoints Http::fake -> fixture JSON com 9 chaves obrigatorias + 4 campos do contrato presentes + anti-leak `access_token` |
| 4 | `command_report_prints_required_sections_when_endpoint_fails` | Falha parcial: ads/items 404 -> seçoes `endpoints_failed` e `blockers` no output + exit 0 |

Setup: `Http::preventStrayRequests()` + `Storage::fake('local')` isolam rede e filesystem real.

Execucao: `php artisan test --filter=MlSmokeCommandTest` -> **4 passed (31 assertions) em 1.51s**.

## Task Commits

1. **Tarefa 1 (RED):** `984f3bc` — `test(38-02): adiciona suite Http::fake do comando sugadores:ml-smoke (RED)`
2. **Tarefa 2 (GREEN):** `45e986c` — `feat(38-02): implementa SugadoresMlSmoke command (GREEN)`
3. **Tarefa 3 (CHECKPOINT):** DEFERIDA — smoke real contra API ML, bloqueado por recovery do MariaDB local

**Plan metadata commit:** sera criado ao gravar este SUMMARY.md (`docs(38-02): SUMMARY partially_complete + bloqueio MariaDB local`).

## TDD Gate Compliance

- RED commit (`test(38-02): ... (RED)`) presente: SIM (`984f3bc`)
- GREEN commit (`feat(38-02): ... (GREEN)`) presente apos RED: SIM (`45e986c`)
- REFACTOR commit: nao necessario (codigo limpo no GREEN)

## Verificacao de regressao

| Suite | Resultado | Observacao |
|-------|-----------|------------|
| `tests/Feature/Phase38/MercadoLivreAdsServiceTest` (Plan 01) | **4 passed** | Sem quebra do service do Plan 01 |
| `tests/Feature/Phase38/MlSmokeCommandTest` (novo) | **4 passed** (31 assertions, 1.51s) | GREEN limpo |
| `tests/Feature/Phase38/PolosControllerTest` (outro escopo) | 6 failed pre-existentes | Documentado em `deferred-items.md` — escopo Polos, fora da migracao ML |

`php artisan list | grep sugadores:ml-smoke` -> comando registrado com a description correta.

## Confirmacao "Nao-mexer"

`git diff --name-only HEAD~2 HEAD` para arquivos de prod do Sugadores e do Plan 01:

```bash
$ git diff --name-only HEAD app/Services/SugadorAnalysisService.php \
                            app/Http/Controllers/SugadorController.php \
                            app/Jobs/AnalyzeCompanySugadoresJob.php \
                            app/Jobs/FetchAdmanMlbsByCampaignJob.php \
                            app/Services/Sugadores/MercadoLivreAdsService.php \
                            tests/Feature/Phase38/MercadoLivreAdsServiceTest.php \
                            routes/console.php
(vazio — zero alteracoes em qualquer um dos arquivos)
```

Tambem zero migrations, zero rotas novas, zero alteracoes no `AdmanService` ou `MercadoLivreService` (Phase 20).

## Decisoes registradas durante a execucao

- **Path da fixture na producao real:** `storage/app/sugadores/ml-smoke/{id}-{date}.json` (alinhado com CONTEXT.md §Fixture JSON; diretorio criado no Plan 01 via `.gitkeep`)
- **Inspecao de campos do contrato:** `inspectContractFields` checa primeiro o primeiro item de ads (item-level tem mais campos do contrato §2.3), fallback para campanhas se ads vazio/falhou
- **Resolucao de empresa:** se `--company` vazio, tenta lookup Bymobille via `name LIKE '%Bymobille%' OR LIKE '%ByMobille%'` + `mlToken status=active`; se 0 ou multiplos resultados, falha com exit 1 e lista candidatos
- **Smoke como DIAGNOSTICO:** endpoints individuais falhados NUNCA viram exit code != 0 — apenas blockers no relatorio. Exit 1 reservado para erros que impedem qualquer chamada API (empresa inexistente, token ausente)

## Pendencia: Tarefa 3 (smoke real)

### O que ficou faltando

Rodar manualmente no host XAMPP de dev:

```bash
# 1. Confirmar company_id do Bymobille
php artisan tinker --execute='dump(\App\Models\Company::where("name","like","%Bymobille%")->whereHas("mlToken", fn($q) => $q->where("status","active"))->get(["id","name"])->toArray());'

# 2. Executar smoke real contra API ML
php artisan sugadores:ml-smoke --company=<ID> --days=30

# 3. Anonimizar/revisar a fixture gravada
ls -la storage/app/sugadores/ml-smoke/
```

Output esperado no console (referencia: CONTEXT.md §"Relatorio CLI"):
- `OK Advertiser: id=..., site=MLB, seller=...` (OU mensagem clara de "advertiser vazio" / 401 / 403)
- `### endpoints_ok (N)` com pelo menos 1 URL
- `### endpoints_failed (N)` — vazia OU com URLs+erros explicados
- `### contract_fields_present (N)` listando campos achados (esperado: `clicks, impressions, investment, revenue, acos, roas, cpc`, etc)
- `### contract_fields_missing (N)` listando ausentes (`organic_amount`, `organic_units` aceitavel — sao opcionais segundo §2.3)
- `### blockers (N)` — vazia (Phase 39 pode comecar) OU com lista clara de issues

### Razao do deferimento

Tentativa de rodar o smoke real (2026-06-25) bloqueada por corrupcao do MariaDB local do XAMPP:

1. **Sintoma inicial:** handle orfao no `aria_log_control` (errno 9 "Bad file descriptor") — Windows Defender provavelmente segurando handle entre open e write do MariaDB
2. **Tentativa de fix:** renomeacao `aria_log_control` -> `.bak` para forcar regeneracao
3. **Resultado adverso:** corrompeu catalogo de sistema do MariaDB; `mysql.db` ficou com "Incorrect file format" porque os logs Aria (`aria_log.00000001`) ficaram inconsistentes com o novo control file
4. **Restauracao do `.bak`:** nao reverteu o dano — corrupcao persiste em disco
5. **Reboot Windows:** nao resolve (corrupcao em disco persiste)

**Estado atual:** MariaDB local nao sobe (`Fatal error: Can't open and lock privilege tables: Incorrect file format 'db'`); dados em `ecf_admin` (InnoDB) estao intactos, apenas system tables corrompidas (Aria engine).

**Tratamento:** recovery do MariaDB e tarefa separada (escopo fora deste plan). Operador vai abrir quick task `dev:reparar-mariadb-local` em paralelo com diagnostico + opcoes de recovery (`aria_chk --safe-recover` ou restore do diretorio `data/mysql/`).

### Criterio para destravar este plan (Tarefa 3 -> completo)

1. MariaDB local de pe (quick task `dev:reparar-mariadb-local` concluida)
2. `php artisan sugadores:ml-smoke --company=<id_bymobille> --days=30` roda com:
   - Output do console contendo as 5 secoes do relatorio
   - Arquivo `storage/app/sugadores/ml-smoke/<id>-<YYYY-MM-DD>.json` valido
   - `grep -ic "access_token" storage/app/sugadores/ml-smoke/*.json` == 0
3. Operador anota o resultado (`aprovado` / `ajustar` / `permissao`) e me chama de volta

Quando esses 3 criterios baterem, este SUMMARY.md sera atualizado para `complete`, o plan vai ser marcado completo no STATE/ROADMAP, e os 4 requirements (REQ-38-01/05/06/07) viram check.

## Impacto na Phase 39 (proxima)

**Planning:** pode comecar (definir provider contract, repository, refactor SugadorAnalysisService).
**Execucao:** **BLOQUEADA** ate este plan virar `complete` — Phase 39 precisa do smoke real para:
- Confirmar nome exato dos endpoints ML Ads (especialmente o endpoint CANDIDATO `/advertising/advertisers/{id}/product_ads/items`)
- Validar quais campos do contrato §2.3 estao realmente presentes no payload (`contract_fields_present`/`missing`)
- Confirmar shape de metrics no nivel item vs campanha
- Capturar fixture real para reuso em testes da Phase 39

## Deviations from Plan

None — Tarefas 1 e 2 executadas exatamente como escrito, sem auto-fix Rule 1/2/3 necessario. Tarefa 3 nao executada por causa externa (infraestrutura local do dev), nao por deviation do plan.

## Issues Encountered

- **6 falhas pre-existentes em `tests/Feature/Phase38/PolosControllerTest`:** detectadas ao rodar `--filter=Phase38`. Vem de commit anterior `ba6fc24 feat(polos): ... (Phase 38)` (outro dev em paralelo na mesma milestone, namespace `tests/Feature/Phase38/` coincidente). Fora do escopo SCOPE BOUNDARY do executor. Documentadas em `.planning/phases/38-smoke-ml-piloto-bymobile/deferred-items.md`.
- **MariaDB local corrompido:** bloqueou Tarefa 3 (smoke real). Detalhado na secao "Razao do deferimento" acima.

## Threat surface scan

Nenhuma nova surface de seguranca. Comando Artisan rodavel apenas localmente (sem rota HTTP), nao persiste em DB de producao do Sugadores, nao expoe credenciais (T-38-06 mitigado — grep verifica zero ocorrencias `access_token`/`refresh_token`).

## Self-Check: PASSED

- [x] `app/Console/Commands/SugadoresMlSmoke.php` existe (311 linhas)
- [x] `tests/Feature/Phase38/MlSmokeCommandTest.php` existe (4 tests, 297 linhas)
- [x] `.planning/phases/38-smoke-ml-piloto-bymobile/deferred-items.md` existe
- [x] Commit `984f3bc` (test RED) presente no log
- [x] Commit `45e986c` (feat GREEN) presente no log
- [x] 4/4 MlSmokeCommandTest verdes
- [x] 4/4 MercadoLivreAdsServiceTest verdes (sem regressao Plan 01)
- [x] Zero modificacoes em arquivos de prod do Sugadores
- [x] Zero modificacoes em `routes/console.php`
- [x] `php artisan list | grep sugadores:ml-smoke` retorna a linha do comando
- [x] Tarefa 3 NAO executada — documentada como pendente com criterio claro de destravamento

---

*Phase: 38-smoke-ml-piloto-bymobile*
*Plan: 02*
*Status: partially_complete — codigo 100% pronto; smoke real deferido por bloqueio do MariaDB local*
*Documentado em: 2026-06-25*
