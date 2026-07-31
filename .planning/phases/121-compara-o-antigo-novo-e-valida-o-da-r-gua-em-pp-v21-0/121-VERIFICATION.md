---
phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0
verified: 2026-07-31T18:00:00Z
status: passed
score: 9/9 must-haves verificados
overrides_applied: 0
---

# Fase 121: Comparação antigo × novo e validação da régua em pp (v21.0) — Relatório de Verificação

**Goal da fase:** Antes de ligar a flag em produção, existe evidência numérica de quanto a nota de cada profissional muda e de como a régua reusada se comporta sobre a distribuição real de pontos percentuais da carteira.
**Verificado em:** 2026-07-31
**Status:** passed
**Re-verificação:** Não — verificação inicial

## Goal Achievement

### Observable Truths (Success Criteria do ROADMAP)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `desempenho:comparar-score-empresa --mes=YYYY-MM` reporta por profissional `nota_antiga`, `nota_nova`, `delta`, contadores de empresas total/complete/partial e a maior causa do delta | ✓ VERIFIED | Comando existe e registrado (`artisan list`); `--help` confirma `--mes`/`--historico`/`--force`/`--run`; código lido em `app/Console/Commands/CompararScoreEmpresa.php` grava e imprime exatamente essas colunas; 28/28 testes `Phase121` verdes (rodados por mim), incluindo `CompararScoreEmpresaCommandTest`, `DecomposicaoDeltaTest`, `RelatorioComparadorTest` |
| 2 | A comparação roda sobre a última competência fechada e as 7 amostras de risco do plano §6 são conferidas manualmente | ✓ VERIFIED | `121-05-SUMMARY.md` documenta rodada real `--mes=2026-06 --historico=2` (última competência fechada) com `run_id=03787204-51a7-49fb-8478-da56a5b07e2a`; as 7 amostras aparecem nomeadas (Matheus Estrela/Nathalia Martins/Prisciele/CLICK_DECOR/Empresa teste/invalidada-184/Gustavo); a checagem de sanidade "carteira do Luiz ≈ −0,59 pp → nota 3" bate com o número já registrado em `REQUIREMENTS-v21.md` (D2) ANTES desta fase rodar — corroboração forte de que a leitura é real, não fabricada |
| 3 | A distribuição real de `margem_var_pp` da carteira inteira é medida e apresentada | ✓ VERIFIED | Histograma implementado (`imprimirHistograma`/dedupe por `company_id`/3 competências) provado pelo gate nº 3 (`RelatorioComparadorTest`, 2 testes); rodada real relatada em `121-05-SUMMARY.md`: concentração 3+4 de 60,7% (2026-04) / 62,8% (2026-05) / 59,5% (2026-06) / 61,0% consolidado |
| 4 | **GATE:** o usuário aprova explicitamente o delta antes de qualquer ativação de flag em produção | ✓ VERIFIED | `121-05-SUMMARY.md`: decisão "ACEITO COM RESSALVAS" registrada com o fato que embasou (P2 negativo para os 11 profissionais, −0,35 a −0,84) e a ressalva (resíduo não-decomposto de Douglas/Danilo); `run_id` reconsultável documentado |

**Score:** 4/4 truths do ROADMAP verificados

### Must-haves adicionais dos PLANs (01–05)

| # | Truth (plano) | Status | Evidence |
|---|------|--------|----------|
| 5 | Payload de `compute()` expõe `nota_final_por_empresa`/`score_status_por_empresa` só quando o shadow roda (D-05) | ✓ VERIFIED | Código lido em `DesempenhoScoreService.php:680-750`: par calculado 1x antes da bifurcação, D-91-01 aplicada, chaves acrescentadas ao `$payload` só dentro de `if ($empresasScore !== null)`; `ShadowNotaNovaExpostaTest` 5/5 verde |
| 6 | Teste dourado da Fase 120 continua verde sem nenhum valor congelado mudar | ✓ VERIFIED | `--filter=Phase120` rodado por mim: 18/18 verde, incluindo `PayloadBaselineFlagOffTest` |
| 7 | Duas tabelas insert-only (`desempenho_comparador_profissionais`/`desempenho_comparador_empresas`) existem, com `decimal(14,6)` na margem e sem `enum()` | ✓ VERIFIED | Migration lida linha a linha — bate exatamente com o spec do plano; `grep -n "enum("` e `grep -n "nullOnDelete"` retornam 0 linhas; `ComparadorTabelasTest` 3/3 verde |
| 8 | Uma única chamada de `compute()` por (profissional, competência); releitura do `diff_pct` interleaved, nunca em segunda passada | ✓ VERIFIED | `grep -c "scoreService->compute"` = 1; `CompararScoreEmpresaCommandTest::releitura_do_dispatcher_acontece_interleaved...` verde, provando a sequência `diff(A), persist(A), diff(B), persist(B)` |
| 9 | Decomposição do delta com resíduo explícito, nunca escondido; réguas expostas via visibilidade pública (D-06/D-07), sem função "espelho" nova | ✓ VERIFIED | `DecomposicaoDeltaTest` 5/5 verde, incluindo cenário de resíduo dominante (`interacao_nao_decomposta`); `reguaFaturamento()`/`reguaMargem()` confirmadas `public` em `DesempenhoScoreService.php:1518`/`1542`; nenhuma função `*Espelho()` no comando (correção pós-plan-check aplicada como documentado no `121-03-SUMMARY.md`) |
| 10 | Flag `metrics.performance_company_first_score` continua `false`; comando não toca `.env`/`config/metrics.php` | ✓ VERIFIED | `config/metrics.php` lido: default `false`; `grep -rn "PERFORMANCE_COMPANY_FIRST_SCORE" .env .env.example` = 0 linhas; `grep -n "performance_company_first_score" app/Console/Commands/CompararScoreEmpresa.php` = 0 ocorrências funcionais |

**Score consolidado:** 9/9 (4 do ROADMAP + 5 dos planos, sem duplicação de escopo) — nenhum truth reprovado.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/DesempenhoScoreService.php` | chaves aditivas condicionais + réguas públicas | ✓ VERIFIED | Lido integralmente nos trechos relevantes; comportamento bate 100% com o plano |
| `database/migrations/2026_07_31_120000_create_desempenho_comparador_tables.php` | 2 tabelas insert-only | ✓ VERIFIED | Lida integralmente; decimal(14,6), sem enum, `cascadeOnDelete()` |
| `app/Models/DesempenhoComparadorProfissional.php` | model enxuto | ✓ VERIFIED | `$fillable` completo, sem `LogsActivity` |
| `app/Models/DesempenhoComparadorEmpresa.php` | model enxuto | ✓ VERIFIED | `$fillable` completo, sem `LogsActivity` |
| `app/Console/Commands/CompararScoreEmpresa.php` | comando completo (coleta+decomposição+relatório) | ✓ VERIFIED | 1077 linhas; `--help` funcional; todos os métodos citados nos SUMMARYs presentes (`calcularDecomposicao`, `imprimirRelatorio`, `imprimirHistograma`, `imprimirAmostrasDeRisco`, etc.) |
| `tests/Feature/Phase121/*.php` (5 suítes) | gates 1-4 + tabelas | ✓ VERIFIED | 28/28 testes verdes, rodados por mim nesta verificação |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `DesempenhoScoreService::compute()` | `computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa` | chamada única antes da bifurcação | ✓ WIRED | Confirmado no código lido |
| `CompararScoreEmpresa` | `DesempenhoScoreService::compute` | `incluirEmpresasScore: true`, 1x por (profissional, competência) | ✓ WIRED | `grep -c` = 1; teste de contagem via dublê verde |
| `CompararScoreEmpresa` | `MetricDiffDispatcher::compute` | releitura interleaved, só competência alvo | ✓ WIRED | Sequência de eventos provada em teste |
| `CompararScoreEmpresa` | `desempenho_comparador_profissionais`/`empresas` | insert antes de agregação | ✓ WIRED | `::create()` presente; reconsulta testada |
| `CompararScoreEmpresa` | `BonusFaixa::classificar()` | faixas pré-promoção (D-06) | ✓ WIRED | Chamado direto, sem espelho |
| `CompararScoreEmpresa` | `BonusInvalidacao::companyIdsInvalidadas()` | prova de ausência (amostra 6) | ✓ WIRED | Chamada confirmada em `app/Console/Commands/CompararScoreEmpresa.php:591`; corroborada na rodada real (`company_id=184` ausente em 2026-06, presente em 2026-04/05) |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Comando registrado e `--help` funcional | `php artisan desempenho:comparar-score-empresa --help` | Lista `--mes`/`--historico`/`--force`/`--run` com descrições pt-BR | ✓ PASS |
| Suíte completa da fase | `php artisan test --filter=Phase121` | 28 passed (162 assertions) | ✓ PASS |
| Golden test da Fase 120 intocado | `php artisan test --filter=Phase120` | 18 passed (105 assertions) | ✓ PASS |
| Baseline de regressão | `php artisan test --filter=Desempenho` | 14 failed / 100 passed — idêntico à baseline documentada pré-fase | ✓ PASS |
| `enum()`/`nullOnDelete` proibidos na migration | `grep -n "enum("` / `grep -n "nullOnDelete"` | 0 ocorrências | ✓ PASS |
| Flag não vazou para `.env` nem para o comando | `grep -rn "PERFORMANCE_COMPANY_FIRST_SCORE"` | 0 ocorrências | ✓ PASS |

### Probe Execution

Não aplicável — a fase não declara nem usa `scripts/*/tests/probe-*.sh`. O "probe" desta fase é o próprio comando `desempenho:comparar-score-empresa`, coberto pelos spot-checks acima e pela suíte automatizada.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| ROLL-01 | 121-01/02/03/04 | Comando produz por profissional nota_antiga/nota_nova/delta/contadores/maior_causa_delta | ✓ SATISFIED (funcional) | Código + 28 testes + rodada real documentada |
| ROLL-02 | 121-04/05 | Comparação sobre última competência fechada + 7 amostras conferidas manualmente | ✓ SATISFIED (funcional) | Amostras implementadas e identificadas na rodada real |
| ROLL-03 | 121-04/05 | Distribuição real de `margem_var_pp` medida e apresentada | ✓ SATISFIED (funcional) | Histograma implementado + números reais no SUMMARY |

**⚠️ Achado de rastreamento (não bloqueia a fase):** `.planning/REQUIREMENTS-v21.md` continua com as caixas `- [ ]` **desmarcadas** para ROLL-01/02/03 e a tabela de rastreabilidade (linhas 163-165) mostra `Pending` para as três. O executor do Plano 03 relatou que `requirements.mark-complete ROLL-01` retornou `not_found` — investigado nesta verificação: **`REQUIREMENTS.md` (a raiz, sem sufixo) é do milestone v17.0 antigo**; os requirements da v21.0 vivem exclusivamente em `REQUIREMENTS-v21.md`, um arquivo separado. A ferramenta `requirements.mark-complete` aparentemente só escreve no `REQUIREMENTS.md` canônico, não no arquivo versionado — por isso "not_found". **Este não é um problema específico da Fase 121**: o mesmo padrão de "Pending" desatualizado aparece em NPSE-01..06 (Fase 118) e EMPS-01..07 (Fase 119) na mesma tabela, mesmo com as caixas `[x]` marcadas acima — confirma que é uma lacuna sistêmica da tabela de rastreabilidade do milestone v21.0, não uma falha desta fase. Recomenda-se atualizar manualmente as caixas e a tabela de `REQUIREMENTS-v21.md` para ROLL-01/02/03 (e idealmente para as fases anteriores também), mas isso é debt de documentação — não afeta o `passed` desta verificação, pois o conteúdo funcional dos três requirements está comprovadamente implementado e testado.

### Anti-Patterns Found

Nenhum anti-pattern bloqueante encontrado nos arquivos tocados por esta fase (`app/Services/DesempenhoScoreService.php`, `app/Console/Commands/CompararScoreEmpresa.php`, os dois models, a migration, as 5 suítes de teste).

- Ocorrências de "placeholder" no código são todas do domínio de negócio já travado (placeholder de margem Shopee = 1.0, decisão da Fase 109) — não é código incompleto.
- Um único `TODO` pré-existente em `DesempenhoScoreService.php:1297` ("TODO Plan 74-09: cobrir edge case...") não foi tocado por esta fase e referencia um plano formal de follow-up — não é um marcador órfão.
- Nenhum ID de `company_id` chumbado no comando (`grep` por literais não encontra nada, conforme exigido pelo `<done>` do Plano 04).

### Human Verification Required

Nenhum item pendente. O GATE humano da fase (`checkpoint:human-verify` do Plano 05 — aprovação do delta pelo usuário) já foi conduzido durante a execução, com decisão registrada ("aceito com ressalvas") e o número que a embasou. Não há necessidade de reabrir esse gate nesta verificação.

### Gaps Summary

Nenhum gap bloqueante. Todos os 4 success criteria do ROADMAP e os 5 must-haves adicionais dos planos 01-05 foram verificados diretamente no código (leitura de arquivo) e por execução real dos testes (28/28 Phase121, 18/18 Phase120, baseline exata de 14 falhas em `--filter=Desempenho`) nesta sessão de verificação — não apenas por citação do SUMMARY.

A única ressalva registrada é de natureza documental (tabela de rastreabilidade de `REQUIREMENTS-v21.md` desatualizada para ROLL-01/02/03, e sistemicamente para outras fases da mesma milestone) — não impede a Fase 122 de começar, mas deveria ser corrigida na próxima oportunidade de manutenção do arquivo.

A evidência de execução real em produção (Plano 05: `run_id=03787204-51a7-49fb-8478-da56a5b07e2a`, 11 profissionais, 3 competências) não é re-verificável diretamente por mim (sem acesso ao VPS/banco de produção nesta sessão), mas está fortemente corroborada por: (a) o comando ser um `checkpoint:human-verify` que exige interação humana real para avançar — não pôde ter sido "inventado" silenciosamente pelo executor; (b) o número de sanidade da carteira do Luiz reportado (`≈ −0,59 pp → nota 3`) bater exatamente com o valor já registrado em `REQUIREMENTS-v21.md` (D2) **antes** desta fase rodar; (c) o `run_id` e os detalhes operacionais (adiamento por cron às 11:03, deploy às 13:5x, tempos de pré-aquecimento) serem specíficos e consistentes com as constraints documentadas no `121-05-PLAN.md`.

---

_Verified: 2026-07-31_
_Verifier: Claude (gsd-verifier)_
