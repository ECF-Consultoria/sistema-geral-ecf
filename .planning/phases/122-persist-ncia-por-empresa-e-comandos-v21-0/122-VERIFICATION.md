---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
verified: 2026-08-03T18:08:44Z
status: passed
score: 5/5 must-haves verified (SNAP-01..06)
overrides_applied: 0
---

# Phase 122: Persistência por empresa e comandos (v21.0) — Verification Report

**Phase Goal:** O detalhe por empresa vira fato auditável e persistido, e o fechamento mensal passa a gravá-lo — com o caminho de reconsolidação de competências fechadas incluído no rollout.
**Verified:** 2026-08-03T18:08:44Z
**Status:** passed
**Re-verification:** No — verificação inicial

## Goal Achievement

### Observable Truths (5 critérios do ROADMAP)

| # | Truth (critério do ROADMAP) | Status | Evidência |
|---|---|---|---|
| 1 | `empresas_score` é persistido em `desempenho_score_snapshots.breakdown_json` | ✓ VERIFIED | `DesempenhoScoreService::compute()` grava `'empresas_score' => $empresasScore?->values()->all() ?? []` no payload (linha 796). `ConsolidarMesDesempenho` (linha 286) e `SnapshotDesempenhoScores` (linha 132) gravam `'breakdown_json' => $result` inteiro. Teste `snapshot scores grava linhas origem snapshot diario e preenche empresas score no breakdown` — verde. |
| 2 | Tabela `desempenho_company_score_snapshots` com `unique(user_id, company_id, mes_referencia)` explica o resumo empresa por empresa | ✓ VERIFIED | Migration `2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` cria a tabela com `unique(['user_id','company_id','mes_referencia'], 'dcss_user_company_mes_unique')` — nome de índice explícito (fix do defeito MariaDB 1059 achado no rollout). `CompanyScoreSnapshotSchemaTest` (6 testes) prova colunas, chave única, precisão decimal e round-trip de `quality` — verde. |
| 3 | `ConsolidarMesDesempenho`, `SnapshotDesempenhoScores` e `WarmDesempenhoCache` gravam as linhas por empresa; invalidar empresa remove as linhas daquela competência | ✓ VERIFIED | Os 3 comandos injetam `CompanyScoreSnapshotWriter` e chamam `sync()` com a origem correta (`consolidar_mes`/`snapshot_diario`/`warm_cache`), guardados por `array_key_exists('score_status_por_empresa', $result)`, dentro do `try/catch` por profissional. `BonusAuditoriaController::bustarCacheDaEmpresa()` deleta `DesempenhoCompanyScoreSnapshot` por `(mes_referencia) AND (company_id OU user_id afetado)` nos dois sentidos do toggle. `ComandosGravamEmpresasTest` (7 testes) e `InvalidacaoRemoveLinhasTest` (7 testes) — todos verdes. |
| 4 | `margem_amostra` conta cobertura de `margem_var_pp` | ✓ VERIFIED | `DesempenhoScoreService::compute()` monta `$margemAmostra` com `base='margem_var_pp'` e sub-chave `legado` preservando os 3 números antigos quando o shadow roda; shape legado intocado quando desligado. `MargemAmostraPpTest` (5 testes) e `ShadowRoteamentoTest` reconciliado — verdes. `computeVarMargem()`/`reguaMargem()`/`reguaFaturamento()`/`margemPontos()`/`computeNotaFinal*()`/`computeScoreStatus*()` — diff confirmado vazio nesses métodos entre o início e o fim da fase (`git diff 2ee3eceb..6de377be`); `computeVarMargem()` continua com `$vars->avg()` (linha 1518), `computeVarFaturamento` continua com `median()` (linha 1442) — nenhuma "uniformização" aconteceu, conforme decisão do usuário. |
| 5 | O rollout inclui `desempenho:consolidar-mes --mes=` para competências fechadas, e o gate `FIXMARG-03` é conferido por reconsulta ao snapshot, nunca por stdout | ✓ VERIFIED (com nota de escopo, ver abaixo) | `desempenho:verificar-consolidacao` é read-only (nenhum `save/delete/insert/forget/create/updateOrCreate` no arquivo) e reporta por exit code + `--json`. Rollout real em produção: `desempenho:consolidar-mes --mes=2026-06` + `desempenho:verificar-consolidacao --mes=2026-06` → exit 0, 11 profissionais, 286 linhas todas `origem=consolidar_mes` (evidência em `122-ROLLOUT.md`). Nenhum teste do Phase122 assere sobre stdout (`grep -rn "expectsOutput\|assertStringContains.*OK:" tests/Feature/Phase122/` = 0 linhas). |

**Score:** 5/5 truths verificados

### Nota sobre o escopo reduzido do rollout (critério 5)

O runbook (`122-ROLLOUT.md`) previa reconsolidar três competências fechadas: 2026-06, 2026-05 e 2026-04 (as mesmas da rodada do gate aprovado na Fase 121). Na execução real (Plano 06), o usuário decidiu conscientemente **fazer só 2026-06**, porque a conferência mostrou que 2026-05 e 2026-04 **nunca tiveram snapshot mensal** (0 linhas cada) — reconsolidá-las não seria "reconsolidar" nada, seria **fabricar** 22 registros de bônus que nunca existiram, calculados com os dados e regras de hoje.

Avaliação: isto **não compromete** o critério 5. O critério exige que "o rollout inclui `desempenho:consolidar-mes --mes=` para competências fechadas" — o comando foi de fato incluído e executado com sucesso sobre uma competência fechada real (2026-06), provando o caminho de reconsolidação ponta a ponta (deploy → backfill → verificação por reconsulta → exit code). Maio e abril nunca existiram como competências consolidadas, então não há detalhe por empresa "perdido" para elas — não há ranking, dashboard ou Relatório de Bonificação hoje mostrando dado dessas competências que ficaria incoerente. A decisão é bem documentada, tem motivo técnico sólido (evitar fabricar histórico de bônus) e foi tomada explicitamente pelo usuário, não inferida pelo executor. Portanto trato como decisão de escopo válida, não como gap.

### Verificações críticas solicitadas

**1. `computeVarMargem()` continua com `avg()` e as réguas continuam intocadas?**
CONFIRMADO. `git diff 2ee3eceb..6de377be -- app/Services/DesempenhoScoreService.php` mostra que a ÚNICA mudança no arquivo ao longo de toda a fase foi (a) o bump da cache key `v15→v16` e (b) o bloco de `margem_amostra` (cálculo de cobertura, não de nota). Nenhuma linha dentro de `computeVarMargem()`, `reguaMargem()`, `reguaFaturamento()`, `margemPontos()`, `computeNotaFinal()`, `computeNotaFinalPorEmpresa()`, `computeScoreStatus()` ou `computeScoreStatusPorEmpresa()` foi tocada. `computeVarMargem()` (linha 1518) usa `$vars->avg()`; `computeVarFaturamento` usa `->median()` (decisão anterior, fora do escopo desta fase) — as duas agregações permanecem propositalmente diferentes.

**2. A trava de congelamento cobre todos os caminhos de escrita?**
CONFIRMADO. Busca exaustiva por todos os pontos de escrita em `desempenho_company_score_snapshots`/`DesempenhoCompanyScoreSnapshot` no `app/` encontrou exatamente 4 sites: os 3 comandos (todos passam por `CompanyScoreSnapshotWriter::sync()`, que aplica a trava com `lockForUpdate()` dentro da transação) e `BonusAuditoriaController::bustarCacheDaEmpresa()` (que só DELETA, nunca sobrescreve — o delete roda intencionalmente nos dois sentidos do toggle, por decisão de design D-122-08/09, não é um bypass da trava porque não é uma escrita de dado novo). Nenhum outro Job/Controller/Observer grava na tabela.

**3. Algum critério de verificação dos planos depende de stdout de comando?**
CONFIRMADO QUE NÃO. `desempenho:verificar-consolidacao` retorna exit code binário e todo o conteúdo verificável sai por `--json`; a saída de tabela humana é explicitamente rotulada como "conveniência humana, nunca critério". Nenhum teste em `tests/Feature/Phase122/` usa `expectsOutput`/`assertStringContains` sobre a linha final de `consolidar-mes`. O runbook e o SUMMARY do Plano 06 registram os exit codes e o resultado reconsultado ao banco, nunca a linha impressa "OK: N · Falhas: N".

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` | Tabela com `unique(user_id,company_id,mes_referencia)` | ✓ VERIFIED | Existe, índices nomeados explicitamente (fix do 1059), sem `nullOnDelete`, colunas STRING sem CHECK |
| `app/Models/DesempenhoCompanyScoreSnapshot.php` | Model com casts/scopes, sem `LogsActivity` | ✓ VERIFIED | `scopeDaCompetencia`/`scopeDoUsuario` presentes, casts float corretos |
| `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` | `sync()` idempotente com prune e trava | ✓ VERIFIED | Upsert+prune numa `DB::transaction`, `lockForUpdate()` na checagem de congelamento |
| `app/Console/Commands/ConsolidarMesDesempenho.php` | Grava linhas + escolhe base do gate pela flag | ✓ VERIFIED | `sync()` chamado só após `updateOrCreate` bem-sucedido; `$base = $flagLigada ? $amostra : ($amostra['legado'] ?? $amostra)` |
| `app/Console/Commands/SnapshotDesempenhoScores.php` | Roda com shadow ligado, grava `origem=snapshot_diario` | ✓ VERIFIED | `compute(..., incluirEmpresasScore: true)`, `sync()` presente |
| `app/Console/Commands/WarmDesempenhoCache.php` | Grava `origem=warm_cache` respeitando congelamento | ✓ VERIFIED | `sync()` dentro do `try/catch` por profissional |
| `app/Http/Controllers/BonusAuditoriaController.php` | Delete das linhas por empresa no toggle | ✓ VERIFIED | Escopo `(mes_referencia) AND (company_id OU user_id)` |
| `app/Console/Commands/VerificarConsolidacaoDesempenho.php` | Comando read-only, exit code binário | ✓ VERIFIED | Zero escrita (grep confirmado), 5 tipos de inconsistência, `--json` |
| `.planning/phases/122-.../122-ROLLOUT.md` | Runbook + evidência real de produção | ✓ VERIFIED | Seção "Evidência da execução" preenchida com exit codes, notas inalteradas, cobertura pp, 2 defeitos corrigidos |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `ConsolidarMesDesempenho` | `CompanyScoreSnapshotWriter::sync()` | `ORIGEM_CONSOLIDAR_MES`, dentro do try/catch, após updateOrCreate | ✓ WIRED | Confirmado no código e nos testes |
| `SnapshotDesempenhoScores` | `CompanyScoreSnapshotWriter::sync()` | `ORIGEM_SNAPSHOT_DIARIO` | ✓ WIRED | Confirmado |
| `WarmDesempenhoCache` | `CompanyScoreSnapshotWriter::sync()` | `ORIGEM_WARM_CACHE` | ✓ WIRED | Confirmado |
| `BonusAuditoriaController::bustarCacheDaEmpresa` | `DesempenhoCompanyScoreSnapshot::query()->delete()` | Escopo competência+empresa/usuário | ✓ WIRED | Confirmado |
| `ConsolidarMesDesempenho` (gate FIXMARG-03) | `margem_amostra['legado']` | `config('metrics.performance_company_first_score')` | ✓ WIRED | Flag `false` em produção → lê legado, veredito idêntico ao de hoje |
| `VerificarConsolidacaoDesempenho` | `desempenho_company_score_snapshots` + `desempenho_score_snapshots` | Reconsulta cruzada, sem escrita | ✓ WIRED | Read-only confirmado por grep e por teste de não-escrita |

### Behavioral Spot-Checks / Testes rodados pelo verificador

| Suite | Comando | Resultado | Status |
|---|---|---|---|
| Phase122 (completa) | `php artisan test --filter=Phase122` | 49 passed (184 assertions) | ✓ PASS |
| Phase120 | `php artisan test --filter=Phase120` | 18 passed (109 assertions), `PayloadBaselineFlagOffTest` sem edição (`git log` confirma) | ✓ PASS |
| Phase110 | `php artisan test --filter=Phase110` | 2 failed / 3 passed — idêntico à baseline pré-existente documentada | ✓ PASS (baseline preservada) |
| Desempenho (completa) | `php artisan test --filter=Desempenho` | 14 failed / 101 passed — idêntico à baseline pré-existente documentada | ✓ PASS (baseline preservada, zero regressão nova) |
| Flag em config | `grep performance_company_first_score config/metrics.php` | default `false` via `env(..., false)`, sem override em `.env`/`.env.example` | ✓ PASS |
| Diff da régua | `git diff 2ee3eceb..6de377be -- app/Services/DesempenhoScoreService.php` | Só cache-key bump + bloco `margem_amostra`; zero linha em `computeVarMargem`/réguas | ✓ PASS |
| Stdout dependency | `grep expectsOutput\|assertStringContains.*OK: tests/Feature/Phase122/` | 0 ocorrências | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Descrição | Status | Evidência |
|---|---|---|---|---|
| SNAP-01 | 122-01/03 | `empresas_score` persistido em `breakdown_json` | ✓ SATISFIED | Payload + 2 comandos gravando `breakdown_json = $result` |
| SNAP-02 | 122-01 | Tabela `desempenho_company_score_snapshots` | ✓ SATISFIED | Migration + model + testes de schema |
| SNAP-03 | 122-03 | Os três comandos gravam linhas por empresa | ✓ SATISFIED | `ComandosGravamEmpresasTest` (7 testes) |
| SNAP-04 | 122-04 | Invalidação remove linhas da competência | ✓ SATISFIED | `InvalidacaoRemoveLinhasTest` (7 testes) |
| SNAP-05 | 122-02 | `margem_amostra` conta cobertura de `margem_var_pp` | ✓ SATISFIED | `MargemAmostraPpTest` (5 testes) + réguas intocadas |
| SNAP-06 | 122-05/06 | Rollout com reconsolidação + verificação por reconsulta | ✓ SATISFIED (nota de escopo acima) | `VerificarConsolidacaoTest` (10 testes) + evidência real de produção |

Nenhum requisito órfão — `REQUIREMENTS-v21.md` marca SNAP-01..06 como "Complete (2026-08-03)" e todos batem com plano/evidência de código.

### Anti-Patterns Found

Nenhum marcador de débito (`TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`) introduzido pelos arquivos desta fase. As únicas ocorrências de `TODO`/`PLACEHOLDER` em `DesempenhoScoreService.php` são pré-existentes (Fase 74/109), fora do diff desta fase.

### Human Verification Required

Nenhuma. O checkpoint humano da fase (Plano 06 — rollout em produção) já foi executado e a evidência reconsultada está registrada em `122-ROLLOUT.md`. Não há UI nova nesta fase (fica para a Fase 123) que exigisse checkpoint visual.

### Gaps Summary

Nenhum gap bloqueante encontrado. Um item de observação não-bloqueante:

- **`STATE.md` desatualizado**: o cabeçalho do arquivo ainda registra `stopped_at: Completou 122-05-PLAN.md` e `Status: Executing — Fase 122 (5/6 planos), rollout do 122-06 em andamento`, enquanto o ROADMAP.md e o `122-06-SUMMARY.md` confirmam que o Plano 06 foi executado e a fase está com 6/6 planos completos. Isto é um artefato de rastreamento desatualizado, não um problema de código — não afeta a verificação do goal da fase, mas deveria ser corrigido antes de abrir a Fase 123 para evitar confusão de estado.

---

*Verified: 2026-08-03T18:08:44Z*
*Verifier: Claude (gsd-verifier)*
