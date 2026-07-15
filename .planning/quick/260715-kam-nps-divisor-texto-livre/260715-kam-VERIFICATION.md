---
phase: quick-260715-kam
verified: 2026-07-15T18:00:00Z
status: passed
score: 9/9 must-haves verified
overrides_applied: 0
---

# Quick Task 260715-kam: Divisor de nota NPS exclui texto_livre — Verification Report

**Task Goal:** Corrigir o divisor do cálculo de nota NPS por dimensão — perguntas
`texto_livre` inflavam o denominador (entravam como ZERO na média), tornando a
nota 5 matematicamente inalcançável. Inclui backfill retroativo das tabelas
congeladas (`nps_response_scores`, `nps_score_assignments`).

**Verified:** 2026-07-15
**Status:** passed
**Re-verification:** No — initial verification

## Critério de verdade aritmético (teste decisivo)

Reproduzido em teste real (`tests/Feature/V16/NpsDivisorTextoLivreTest.php`), rodado
por este verificador (não a partir da SUMMARY):

| dimensão | perguntas total | com peso | texto_livre | ANTES (bug) | DEPOIS (medido agora) |
|---|---|---|---|---|---|
| empresa | 6 | 5 | 1 | 4.17 (25/6) | **5.00** (25/5) ✓ |
| analista | 9 | 7 | 2 | 3.89 (35/9) | **5.00** (35/7) ✓ |
| estrategista | 8 | 6 | 2 | 3.75 (30/8) | **5.00** (30/6) ✓ |

Os 3 testes (`test_dimensao_empresa_todos_pesos_maximos_retorna_5_luccmax`,
`test_dimensao_analista_todos_pesos_maximos_retorna_5`,
`test_dimensao_estrategista_todos_pesos_maximos_retorna_5`) rodaram e
**passaram** contra `NpsScoreCalculator::compute()` real, sem mocks.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | Cliente que responde peso 5 em TODAS as perguntas com peso de uma dimensão recebe nota 5.00, mesmo com perguntas texto_livre | ✓ VERIFIED | 3 testes rodados por este verificador, resultado 5.00 nas 3 dimensões (ver tabela acima) |
| 2 | Pergunta escala/opções NÃO respondida CONTINUA puxando a média pra baixo (regra 08/07 preservada) | ✓ VERIFIED | `test_pergunta_escala_pulada_continua_puxando_media_pra_baixo` passa: 14/4=3.5, com a 4ª pergunta de escala não respondida ainda contando no divisor. Filtro em `contarPerguntasComPeso()` é `->where('tipo', '!=', TIPO_TEXTO_LIVRE)` — sobre o TIPO da pergunta no template, nunca sobre "tem answer ou não" |
| 3 | Pergunta texto_livre NUNCA entra no divisor, respondida ou não | ✓ VERIFIED | `test_texto_livre_respondida_nao_altera_sum_nem_divisor` cobre o caso respondida (comentário preenchido, peso NULL) — resultado idêntico ao caso não respondida (5.00) |
| 4 | Invariante `score_sum / question_count == average_score` vale em `nps_response_scores` | ✓ VERIFIED | `test_invariante_snapshot_question_count_nao_conta_texto_livre` monta cenário real via `NpsSnapshotService::registrar()` e assere a invariante com delta 0.001; `question_count` gravado é 3 (não conta a pergunta texto_livre) |
| 5 | Backfill corrige notas congeladas (scores + assignments) e roda 2x com mesmo resultado | ✓ VERIFIED | `test_force_corrige_question_count_e_average_score` (6→5, 4.17→5.00), `test_propaga_average_score_corrigido_para_assignments` (assignment 4.17→5.00), `test_idempotencia_duas_execucoes_seguidas_mesmo_resultado` (2ª execução classifica `ja_corrigido`, mesmo resultado) |
| 6 | Operador vê o diff antes/depois antes de qualquer gravação | ✓ VERIFIED | `exibirDiff()` + `exibirStats()` chamados ANTES do gate de `--dry-run`/`confirm()`; `test_dry_run_nao_grava` confirma que nada é persistido em dry-run |

**Score:** 9/9 truths/artifacts/key-links verificados (detalhe abaixo)

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `app/Services/Nps/NpsScoreCalculator.php` | Divisor exclui texto_livre + `contarPerguntasComPeso()` público | ✓ VERIFIED | Método existe (linha 158-165), `compute()` consome (linha 119), docblock distingue as 2 regras (linhas 57-65, 92-107) |
| `app/Services/Nps/NpsSnapshotService.php` | `question_count` usa o MESMO divisor do calculator | ✓ VERIFIED | Linha 118: `$this->calculator->contarPerguntasComPeso(...)`; nenhuma query de count inline restante |
| `app/Console/Commands/NpsBackfillDivisorTextoLivre.php` | Backfill idempotente com dry-run, confirmação e log antes/depois | ✓ VERIFIED | `nps:backfill-divisor-texto-livre` registrado (`php artisan list` confirma); máquina de estados (ja_corrigido/corrigível/divergente/sem_base/sem_template) implementada linhas 165-262; `DB::transaction()` linha 271; `Log::` com prefixo `[NPS Backfill]` em todos os ramos |
| `tests/Feature/V16/NpsDivisorTextoLivreTest.php` | RED do bug + regressão 08/07 + invariante | ✓ VERIFIED | 7 testes, todos passando (rodado por este verificador) |
| `tests/Feature/V16/NpsBackfillDivisorTest.php` | Idempotência, dry-run, propagação assignments | ✓ VERIFIED | 6 testes, todos passando (rodado por este verificador) |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `NpsSnapshotService.php` | `NpsScoreCalculator::contarPerguntasComPeso` | chamada ao método público injetado | ✓ WIRED | `grep -n "where('dimensao'" app/Services/Nps/` mostra a definição única do filtro dentro de `contarPerguntasComPeso()` no calculator; snapshot service não tem query de count própria |
| `NpsBackfillDivisorTextoLivre.php` | `NpsScoreCalculator::contarPerguntasComPeso` | resolução do divisor-alvo pela mesma fonte | ✓ WIRED | Linha 184: `$this->calculator->contarPerguntasComPeso($templateId, $dimensao)`; comando injeta `NpsScoreCalculator` via constructor |
| `NpsBackfillDivisorTextoLivre.php` | `nps_score_assignments.average_score` | propagação via `nps_response_score_id` | ✓ WIRED | Linhas 286-293: `NpsScoreAssignment::where('nps_response_score_id', $score->id)...->update(['average_score' => $score->average_score])`; testado por `test_propaga_average_score_corrigido_para_assignments` |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Suite V16 (divisor + backfill) roda e passa | `php artisan test tests/Feature/V16/NpsDivisorTextoLivreTest.php tests/Feature/V16/NpsBackfillDivisorTest.php tests/Feature/Phase69/NpsScoreCalculatorTest.php` | 19 passed (39 assertions) | ✓ PASS |
| Sem regressão em Phase69 (legado, cria só perguntas `escala`) | incluído no comando acima | 6/6 Phase69 verde, sem edição | ✓ PASS |
| Sem regressão em NPS v16 / Phase79 / Phase80 (consumidores do snapshot e do bônus) | `php artisan test tests/Feature/V16/ tests/Feature/Phase79/ tests/Feature/Phase80/` | 118 passed (488 assertions) | ✓ PASS |
| Comando de backfill registrado | `php artisan list \| grep nps:backfill` | `nps:backfill-divisor-texto-livre` listado | ✓ PASS |
| Nenhuma query de divisor duplicada fora de `contarPerguntasComPeso()` | `grep -rn "where('dimensao'" app/Services/Nps/` | Só 1 ocorrência, dentro do próprio `contarPerguntasComPeso()` | ✓ PASS |

### Migration Deviation Check (ponto de atenção 4)

`database/migrations/2026_07_13_101151_alter_nps_template_questions_tipo_add_texto_livre.php`
alterada pelo executor (Rule 3 auto-fix). Diff completo revisado via
`git show a534893`:

- **Ramo MySQL/MariaDB (produção): IDÊNTICO ao original.** O `DB::statement("ALTER TABLE ... MODIFY COLUMN tipo ENUM(...)")` não mudou uma linha — mesma string SQL, mesmo comentário de coluna. Zero risco de mudança de semântica em produção, onde a migration já rodou.
- **Ramo SQLite (só testes):** trocou de "skip total" (que na verdade não escapava do CHECK, causando falha) para `$table->string('tipo')->change()` — remove o CHECK, aplicando o mesmo padrão já usado em `2026_07_14_100001_add_shopee_to_servicos_setor_enum.php` (memória do projeto `project_enum_setor_sqlite_check`).
- **Rollback (`down()`):** ganhou o mesmo branch por driver; guard de `$orfaos > 0` preservado antes de qualquer downgrade.
- **Veredito:** desvio legítimo, documentado, sem impacto em produção. Confirmado pela suíte completa passando em SQLite.

### Anti-Patterns Found

Nenhum. `grep` por `TBD|FIXME|XXX|TODO|HACK|PLACEHOLDER` nos 6 arquivos
modificados/criados retornou zero ocorrências fora de comentários explicativos
não relacionados (nenhum marcador de débito técnico).

### Requirements Coverage

Quick task sem entrada formal em `.planning/REQUIREMENTS.md` (esperado — tarefas
`quick/` não passam pelo fluxo de requirements numerados; `KAM-01` é uma tag
interna do plano, não um REQ-ID rastreado). Sem requisitos órfãos a reportar.

### Consumidores herdando a correção (verificado, não confiar na SUMMARY)

`git log` confirma que `app/Jobs/CalculateGoalResults.php` e
`app/Http/Controllers/NpsController.php` NÃO foram tocados por nenhum dos 3
commits desta task (`a534893`, `e3b2103`, `856c716`) — ambos consomem
`NpsScoreCalculator::compute()` e herdam a correção automaticamente, conforme
o plano previa (nenhuma duplicação de lógica introduzida).

### Human Verification Required

Nenhum item requer verificação humana — todos os truths são verificáveis
programaticamente (aritmética exata, testes automatizados, grep estrutural).

O único passo pendente é operacional, já documentado corretamente na SUMMARY e
fora do escopo desta verificação de código: **rodar
`nps:backfill-divisor-texto-livre --dry-run` no VPS após o deploy**, pois o
MariaDB local está indisponível (`project_mariadb_local_corrompido`) e a
suíte SQLite é a verificação vinculante conforme o próprio plano previa. Isso
não é um gap do código — é o próximo passo operacional já sinalizado pelo
executor.

### Gaps Summary

Nenhum gap encontrado. Os 3 pontos de maior risco identificados no briefing de
verificação foram checados linha a linha e confirmados corretos:

1. Regra de 08/07 preservada — filtro é `tipo != texto_livre`, nunca sobre
   presença de answer.
2. Invariante do snapshot — `NpsSnapshotService` consome
   `contarPerguntasComPeso()` do calculator injetado, zero duplicação de query.
3. Migration MySQL/produção byte-idêntica ao original; só o ramo SQLite (testes)
   mudou.

---

_Verified: 2026-07-15_
_Verifier: Claude (gsd-verifier)_
