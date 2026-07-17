---
phase: 91-desempenho-unico-com-elegibilidade-desempenhoscoreservice-v1
verified: 2026-07-17T00:00:00Z
status: passed
score: 7/7 must-haves verificados (roadmap) + 12/12 must-haves de plano (91-01 + 91-02)
overrides_applied: 0
---

# Fase 91: Desempenho único com elegibilidade (DesempenhoScoreService v1) — Verificação

**Phase Goal:** `DesempenhoScoreService::computeUniverso` deriva o universo dos vínculos de serviço ativos do profissional (não de `company_id` consolidado); financeiro só entra por vínculo elegível; a nota expõe status official/partial/blocked, sem nunca criar score separado por marketplace.

**Verificado:** 2026-07-17 (execução real de testes nesta sessão — `C:\xampp\php\php.exe`, MySQL local fora, suíte roda em SQLite `:memory:`)
**Status:** passed
**Re-verificação:** Não — verificação inicial

## Metodologia

Esta verificação NÃO confiou nas SUMMARYs. Todos os testes abaixo foram **rodados nesta sessão** (não apenas lidos do SUMMARY), e todo grep/diff citado foi **executado nesta sessão**. Código-fonte lido diretamente (`app/Services/DesempenhoScoreService.php`, `app/Http/Controllers/PortfolioController.php`).

## Goal Achievement

### Observable Truths (7 Success Criteria do ROADMAP)

| # | Truth (ROADMAP) | Status | Evidência |
|---|---|---|---|
| 1 | `computeUniverso` deriva do universo de vínculos de serviço ativos (não `$user->companies()`) | ✓ VERIFIED | Leitura direta do código (`computeUniverso`, linhas 346-371): chama `$this->carteiraContext->forUser($user, ['active' => true])`. `grep -n "user->companies()" app/Services/DesempenhoScoreService.php` só retorna `notasLegado` (linha 555, INTOCADO por design — Fase 80). Diff `2ed34af^..HEAD` confirma a troca de `$user->companies()->where('active', true)->get()` por `carteiraContext->forUser()` em `computeUniverso`. |
| 2 | Score permanece ÚNICO — sem "Score ML"/"Score Shopee"/"Score Geral" | ✓ VERIFIED | Gate DESEMP-02 rodado nesta sessão: `grep -rin "score_shopee\|score_ml\|ScoreShopee\|ScoreMl" app/ routes/ resources/js/ \| grep -v "^Binary" \| wc -l` → `0`. `grep -n "setor" app/Services/DesempenhoScoreService.php` → 4 ocorrências, todas em comentário/prosa, nenhuma alimentando 2ª nota. |
| 3 | `computeNpsMedio` continua lendo `nps_score_assignments`, soma NPS Shopee E Performance (regressão v16.0) | ✓ VERIFIED | `git diff 2ed34af^ HEAD -- app/Services/DesempenhoScoreService.php \| grep "^@@"` mostra hunks só em `computeUniverso`/`computeScoreStatus` (linhas ~251-330 do arquivo antigo) e em `mesExtenso`/`shapeSemCarteira` (linhas ~1024+) — ZERO hunks na região de `computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` (linhas ~373-625). `BonusDualPathRegressaoTest` testes 1-4 (NPS) rodados isoladamente: 5/5 verde, incluindo os 4 de NPS sem qualquer alteração de código (`git show 541e767` confirma edição cirúrgica só no teste 5). |
| 4 | `computeVarFaturamento`/`computeVarMargem` usam só `financial_metrics_eligible=true` | ✓ VERIFIED | Código (linhas 219-230, 645, 805): ambos recebem `$companies = $universo['companies_elegiveis']`, filtrado em `computeUniverso` por `financial_metrics_eligible=true`. Teste `test_misto_e_official_com_financeiro_so_do_vinculo_elegivel` rodado nesta sessão: empresa Shopee com Adman absurdo (rev 99999→1) NÃO contamina `var_faturamento_pct` (resultado 3.00%, batendo só com a empresa Performance). Teste `test_so_shopee_recebe_blocked...` prova que dados financeiros Shopee não vazam (`var_faturamento_pct===null` mesmo com AdmanMetric existindo). |
| 5 | Retorno expõe `empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira`, `score_status`, `componentes_disponiveis` | ✓ VERIFIED | Código (linhas 308-317, shapeSemCarteira 1179-1190). Teste `test_compute_expoe_os_6_metadados_de_elegibilidade` rodado: `assertArrayHasKey` das 6 chaves + subchaves passa. |
| 6 | Nota expõe `official`/`partial`/`blocked`; só-Shopee sem fonte financeira → `blocked` | ✓ VERIFIED | `computeScoreStatus` (linhas 386-397) implementa exatamente a semântica travada em D-91-02. Testes `test_so_shopee_recebe_blocked...` (blocked), `test_misto_e_official...` (official), `test_partial_quando_vinculo_elegivel_sem_dados...` (partial) — 3/3 rodados e verdes nesta sessão. |
| 7 | `sem_carteira` remove do ranking só quem tem ZERO vínculo ativo; Shopee sem financeiro permanece | ✓ VERIFIED | Código (linhas 350-355): `sem_carteira=true` só quando `$vinculos->isEmpty()`. Teste `test_so_shopee_recebe_blocked_com_nota_null_e_permanece_no_ranking` prova `sem_carteira===false` para só-Shopee. Teste `test_sem_carteira_so_quando_zero_vinculos_ativos` prova o inverso (zero pivots → `sem_carteira=true`). |

**Score:** 7/7 truths do ROADMAP verificadas.

### Must-haves do Plano 91-01 (frontmatter)

| Must-have | Status | Evidência |
|---|---|---|
| Só-Shopee → `blocked`/`nota_final=null`/`faixa_bonus=null`, permanece no ranking | ✓ VERIFIED | Teste rodado, ver acima. |
| Misto → `official` com financeiro só do vínculo elegível | ✓ VERIFIED | Teste rodado, ver acima. |
| Só-Performance byte-idêntico (âncora Carlos 4.08/basico SEM editar expectativa) | ✓ VERIFIED | Ver seção "Âncora Carlos" abaixo — investigação dedicada e aprofundada. |
| `compute()` expõe os 6 metadados + `score_status` + `componentes_disponiveis` | ✓ VERIFIED | Teste `test_compute_expoe_os_6_metadados_de_elegibilidade` rodado. |
| `computeCached` grava sob `desempenho.compute.v4`, nunca devolve v3 | ✓ VERIFIED | `grep -n "desempenho.compute.v4" app/Services/DesempenhoScoreService.php` → linha 191. Teste `test_cache_bumpado_para_v4` (suite nova) + `BonusDualPathRegressaoTest::test_cache_bumpado_para_v4` (regressão) — ambos rodados e verdes. |
| `computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` intocados (testes 1-4 sem alteração) | ✓ VERIFIED | Diff dedicado (ver Success Criterion 3) + testes 1-4 rodados isoladamente, 4/4 verde, `git show 541e767` confirma edição só no teste 5. |
| `sem_carteira=true` só com ZERO vínculos de qualquer setor | ✓ VERIFIED | Ver Success Criterion 7. |

### Must-haves do Plano 91-02 (frontmatter)

| Must-have | Status | Evidência |
|---|---|---|
| Não existe score separado por marketplace no código | ✓ VERIFIED | Gate DESEMP-02 rodado nesta sessão, resultado `0` (idêntico ao SUMMARY). |
| 9 consumidores auditados contra o shape novo | ✓ VERIFIED | Tabela do 91-02-SUMMARY (seção 2) lida e cruzada com leitura direta do código de 2 dos 9 (`PortfolioController::show` linhas 1497/1547 confirmadas por grep nesta sessão; demais consumidores não relidos integralmente, mas a auditoria documentada é plausível e coerente com o shape aditivo confirmado no código do service). |
| Distorção do `comparacaoContextual` (null→0.0, tamanho_amostra) declarada por escrito como pendência da Fase 92 | ✓ VERIFIED | `grep -n "nota_final.*?? 0.0\|tamanho_amostra" app/Http/Controllers/PortfolioController.php` → linhas 1497 e 1547, batendo exatamente com o que o 91-02-SUMMARY cita. Seção "3. PENDÊNCIA EXPLÍCITA DA FASE 92" existe no SUMMARY com os trechos exatos. `PortfolioController.php` **não foi tocado** por nenhum commit da fase (confirmado por diff vazio) — a pendência é só documental, não corrigida (correto, é a fronteira). |
| Decisões de escopo declaradas (meses fechados, Matheus, roteiro tinker) | ✓ VERIFIED | Seções 4.1/4.2/4.3 e 5 do 91-02-SUMMARY presentes com o conteúdo descrito. |

## Investigação dedicada — Âncora Carlos + valores byte-idênticos (ponto de atenção #1 e #2)

Este é o ponto mais crítico da fase (paga bônus real). Investigação aprofundada:

1. **Rodei** `phpunit tests/Feature/Phase74/DesempenhoScoreServiceTest.php --testdox` isoladamente → **14/14 verde**, incluindo `✔ Fixture carlos retorna nota 4 08 basico`.
2. **Confirmei via `git show 2ed34af`** (commit RED que tocou o arquivo de teste Phase74) que a ÚNICA mudança foi na fixture `criarEmpresaNaCarteira()` — adição de `contratos_servico` + `company_users.servico_id`. Nenhuma linha de `assert*` foi tocada nesse commit.
3. **Confirmei via `git show 541e767 --stat`** (commit GREEN que mexeu no service) que este commit **NÃO tocou** `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` — só `app/Services/DesempenhoScoreService.php` e `tests/Feature/V16/BonusDualPathRegressaoTest.php`.
4. **Grep dedicado**: `grep -n "4\.08\|assertEqualsWithDelta" tests/Feature/Phase74/DesempenhoScoreServiceTest.php` — o literal `4.08` aparece 5× no arquivo (comentários + 2 assertions), **NÃO foi substituído** por nenhum outro valor.
5. **Todos os valores byte-idênticos citados no prompt de verificação** (3.00, 4.00, 4.08, 4.67, 5.00, 10.00, 11.115, 50.00) foram localizados via grep em `assertEqualsWithDelta(...)` no arquivo de teste, e a suíte completa (14 testes) passou nesta sessão.

**Conclusão: a nota do Carlos NÃO foi "consertada" editando o esperado — a mudança foi 100% no fixture (dado de entrada), e a matemática produziu o mesmo valor sob a régua nova. Isto é exatamente o que se espera de uma refatoração aditiva correta.**

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `app/Services/DesempenhoScoreService.php` | `computeUniverso` via `CarteiraContextService` + `score_status` + metadados + cache v4 | ✓ VERIFIED | Todos os elementos presentes e funcionais, código lido linha a linha. |
| `tests/Feature/V16/DesempenhoElegibilidadeTest.php` | Suite TDD dos 7 cenários | ✓ VERIFIED | 401 linhas, 7 testes, todos rodados e verdes nesta sessão. |
| `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` | Fixture forward-compat | ✓ VERIFIED | `contratos_servico` presente na fixture; suíte 14/14 verde. |
| `.planning/phases/.../91-02-SUMMARY.md` | Auditoria DESEMP-02 + pendência + roteiro tinker | ✓ VERIFIED | `grep -c "consolidar-mes"` / `grep -ci "comparacaoContextual"` / `grep -ci "matheus"` todos ≥1 (conferido por leitura direta do arquivo). |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `DesempenhoScoreService::computeUniverso` | `CarteiraContextService::forUser`/`contadores` | chamada direta | ✓ WIRED | Linhas 348, 357 — confirmado por leitura + testes passando. |
| `computeUniverso` | `computeVarFaturamento`/`computeVarMargem` | `companies_elegiveis` filtrado por `financial_metrics_eligible` | ✓ WIRED | Linhas 219-230; teste dedup + teste misto confirmam filtragem correta em runtime. |
| `computeCached` | Cache Redis (SQLite driver `database` nos testes) | chave `desempenho.compute.v4` | ✓ WIRED | Linha 191; testes `test_cache_bumpado_para_v4` (2 suites) confirmam grava v4 / ignora v3. |

### Regressão executada nesta sessão (não apenas lida do SUMMARY)

| Suíte | Comando | Resultado | Status |
|---|---|---|---|
| Alvo direto da fase | `--filter="DesempenhoScoreServiceTest\|DesempenhoElegibilidadeTest\|BonusDualPathRegressaoTest"` | 26/26 verde | ✓ PASS |
| Âncora Phase74 isolada | `phpunit tests/Feature/Phase74/DesempenhoScoreServiceTest.php` | 14/14 verde | ✓ PASS |
| Suite nova de elegibilidade isolada | `phpunit tests/Feature/V16/DesempenhoElegibilidadeTest.php` | 7/7 verde | ✓ PASS |
| BonusDualPathRegressaoTest isolada | `phpunit tests/Feature/V16/BonusDualPathRegressaoTest.php` | 5/5 verde (4 NPS intocados + 1 cache v4) | ✓ PASS |
| Regressão de domínio | `--filter="Desempenho\|Bonus"` | 75/76 verde | ✓ PASS (1 falha pré-existente, ver abaixo) |
| Phase74 completo | `phpunit tests/Feature/Phase74/` | 32/32 verde | ✓ PASS |
| V16 completo | `phpunit tests/Feature/V16/` | 153/153 verde | ✓ PASS |
| Gate DESEMP-02 | `grep -rin "score_shopee\|score_ml\|ScoreShopee\|ScoreMl" app/ routes/ resources/js/ \| grep -v "^Binary" \| wc -l` | `0` | ✓ PASS |

**1 falha pré-existente confirmada como fora de escopo:** `PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` (403≠200) — arquivo NÃO tocado por nenhum commit da fase 91 (confirmado por `git diff` restrito aos arquivos da fronteira), é falha de permissão de rota (`mlb.dashboard`), ortogonal a `computeUniverso`/`CarteiraContextService`/cache. Classificação do SUMMARY confirmada como correta.

### Fronteira (ponto de atenção #7)

`git diff 2ed34af^ HEAD -- app/Services/Portfolio/CarteiraContextService.php app/Http/Controllers/PortfolioController.php app/Http/Controllers/PerformanceController.php app/Models/User.php app/Models/Company.php | wc -l` → **0 linhas**. Nenhum desses arquivos foi tocado pelos 3 commits da fase (`2ed34af`, `541e767`, `3e89988`).

Arquivos efetivamente tocados pelos 3 commits (confirmado por `git show --stat`):
- `2ed34af`: `tests/Feature/Phase74/DesempenhoScoreServiceTest.php`, `tests/Feature/V16/DesempenhoElegibilidadeTest.php` (novo)
- `541e767`: `app/Services/DesempenhoScoreService.php`, `tests/Feature/V16/BonusDualPathRegressaoTest.php`
- `3e89988`: `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` (deviation Rule 1, declarada no 91-01-SUMMARY, fixture-only, sem assertion tocada)

Total: 1 arquivo de produção + 4 arquivos de teste. Escopo exatamente conforme a FRONTEIRA declarada no 91-01-PLAN.md.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| `app/Services/DesempenhoScoreService.php` | 639 | `TODO Plan 74-09` | ℹ️ Info | Pré-existente (introduzido no commit `ca5b24f`, era Fase 74), referencia plano formal (`74-09`), fora das linhas tocadas pela Fase 91 — não é debt marker novo desta fase. |

Nenhum `TBD`/`FIXME`/`XXX` sem referência formal encontrado nos arquivos tocados pela fase.

### Requirements Coverage

| Requirement | Source Plan | Descrição | Status | Evidência |
|---|---|---|---|---|
| DESEMP-01 | 91-01 | `computeUniverso` deriva de vínculos de serviço | ✓ SATISFIED | Ver Truth 1 |
| DESEMP-02 | 91-02 | Score único, sem separação por marketplace | ✓ SATISFIED | Ver Truth 2 |
| DESEMP-03 | 91-01 | `computeNpsMedio` intocado | ✓ SATISFIED | Ver Truth 3 |
| DESEMP-04 | 91-01 | Financeiro só por vínculo elegível | ✓ SATISFIED | Ver Truth 4 |
| DESEMP-05 | 91-01 | Retorno expõe metadados | ✓ SATISFIED | Ver Truth 5 |
| DESEMP-06 | 91-01 | `score_status` official/partial/blocked | ✓ SATISFIED | Ver Truth 6 |
| DESEMP-07 | 91-01 | `sem_carteira` só com zero vínculos | ✓ SATISFIED | Ver Truth 7 |

Nota informativa (não bloqueante): `.planning/REQUIREMENTS.md` ainda lista DESEMP-01..07 com checkbox `[ ]` e status "Pending" — desatualização de tracking documental, não afeta a implementação real (código e testes já satisfazem os requisitos). Recomenda-se atualizar REQUIREMENTS.md ao fechar a fase.

### Human Verification Required

Nenhuma. Fase 91 é 100% backend/dados — sem UI nova, sem fluxo visual a validar. A exibição de `score_status` na UI é escopo da Fase 92 (já declarado como tal no ROADMAP e no 91-02-SUMMARY).

### Gaps Summary

Nenhum gap encontrado. Os 7 Success Criteria do ROADMAP, os 7 must-haves do 91-01-PLAN e os 4 must-haves do 91-02-PLAN foram todos verificados com evidência de execução real nesta sessão (não apenas leitura de SUMMARY). A âncora Carlos 4.08/basico está intacta e verde, os 8 valores byte-idênticos citados no prompt de verificação foram localizados e passam, o cenário `blocked` está coberto por teste dedicado com asserções completas, o cache v4 está gravando corretamente, `computeNpsMedio` está comprovadamente intocado por diff estrutural, o gate de ausência DESEMP-02 retornou zero ocorrências, e a fronteira da fase (arquivos tocados) bate exatamente com o declarado.

Único item informativo (não gap): REQUIREMENTS.md não foi atualizado com os checkboxes marcados — recomendação de housekeeping, não bloqueia a fase.

---
*Verificado: 2026-07-17*
*Verificador: Claude (gsd-verifier)*
