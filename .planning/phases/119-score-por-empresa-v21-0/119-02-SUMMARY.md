# Plano 119-02 — SUMMARY

**Fase:** 119 — Score por empresa (v21.0) · **Wave 1 de 3**
**Requirements:** EMPS-01, EMPS-02, EMPS-04
**Concluído:** 2026-07-29
**Status:** completo — 3/3 tasks

## Uma frase

`CompanyScoreService::computeEmpresasScore()` — linha de fato por `(user_id, company_id)` com NPS/faturamento/margem já pontuados pela régua POR EMPRESA (antes de qualquer média), aditiva e sem consumidor de produção.

## Commits

| Commit | Task | Conteúdo |
|---|---|---|
| `87a7df46` | 1 | `CompanyScoreService` com `reguaFaturamento()`/`reguaMargem()` duplicadas byte a byte + `CompanyScoreServiceReguasTest` (equivalência via Reflection) |
| `faceb77e` | 2 | `computeEmpresasScore()` completo (universo, fonte vencedora, guard C-04, chamada única ao NPS e ao dispatcher) + `CompanyScoreServiceContratoTest` |
| `de25d5a3` | 3 | `CompanyScoreServiceFormulaTest` — caso âncora 4,53 (EMPS-04) e divergência régua-por-empresa × régua-da-média (EMPS-02) |

## O que foi entregue

**`app/Services/Desempenho/CompanyScoreService.php`** (novo, aditivo) — `computeEmpresasScore(User $user, Carbon $mes, array $periodo, ?Collection $invalidadas = null): Collection`, chaveada por `company_id`, com as 17 chaves do contrato §3.1:

```
company_id, company_name, fonte_financeira, nps_pontos,
faturamento_atual, faturamento_anterior, faturamento_var_pct, faturamento_pontos,
margem_pct_atual, margem_pct_anterior, margem_var_pp, margem_pontos,
componentes_presentes, nota_empresa, nota_empresa_parcial, status,
quality{revenue_diff_source, margin_diff_source, margin_source, motivos[]}
```

Decisões honradas:
- **D-01** — dois números: `nota_empresa` (estrita, `null` se faltar 1 de 3) e `nota_empresa_parcial` (média dos presentes). Nunca um só.
- **D-02** — Shopee sempre `margem_pontos=1.0` fixo (placeholder), `quality.margin_source='placeholder_shopee'`, `status` pode fechar `complete`.
- **D-03** — empresa sem fonte financeira elegível permanece na Collection com `status='sem_fonte'`, `quality.motivos=['sem_fonte_financeira', ...]`.
- **D-05** — `$periodo` sempre chega já resolvido por quem chama; `$mesFechado` deriva de `$periodo['is_closed']`, nunca é parâmetro próprio; `$invalidadas` nulo resolve via `BonusInvalidacao::companyIdsInvalidadas()` e o MESMO objeto é repassado ao `NpsPorEmpresaService`.
- **C-03** — `reguaFaturamento()`/`reguaMargem()` copiadas byte a byte de `DesempenhoScoreService:1290-1298`/`:1311-1319`, com teste de equivalência via Reflection cobrindo `null` + 19 boundaries, e valores literais não-tautológicos.
- **C-04** — guard `if ($fonteFinanceira !== null)` ANTES de qualquer chamada ao `MetricDiffDispatcher::compute()` — nunca num `catch`. Empresa `sem_fonte` nunca chega ao dispatcher.
- **EMPS-03** — `margem_var_pp` vem SEMPRE de `contribution_margin_pct.diff_pp`, nunca de `diff_pct`.
- **EMPS-05** — UMA única chamada ao dispatcher por empresa, alimentando faturamento e margem juntos (fusão das antigas `computeVarFaturamento`/`computeVarMargem` do original, que chamavam 2x).
- **EMPS-06** — fonte vencedora resolvida por vínculo elegível (`financial_metrics_eligible=true`); 'adman' vence sobre 'shopee' quando ambos presentes na mesma empresa; universo permanece COMPLETO (nunca pré-filtrado por elegibilidade, diferente de `computeUniverso()` original).

`quality.motivos` montado em ordem determinística: `sem_fonte_financeira` → `faturamento_sem_baseline` → `margem_pp_indisponivel` → `nps_janela_aberta`/`nps_indisponivel`.

## Verificação

| Gate | Resultado |
|---|---|
| `--filter=Phase119` | **12/12 verdes** (168 asserções) |
| Aditividade: `sha256sum app/Services/DesempenhoScoreService.php` | `cfc16da2a8404fba…9edd` — byte-a-byte intocado, verificado em toda task |
| `git diff --name-only` | não inclui `DesempenhoScoreService.php` |
| Consumidor de produção | nenhum — `grep -rn "CompanyScoreService" app/ routes/` só encontra uma referência documental pré-existente (docblock da Fase 117 em `ProbeMargemPrevStability.php:279`, escrita ANTES desta fase existir) |
| `--filter=Desempenho` | **14 falhas** — exatamente a baseline pré-existente (debug de margem já aberto). Sem regressão. |

## Deviations from Plan

### Auto-fixed Issues (Rule 1 — bugs nos meus próprios testes, não no serviço)

**1. Texto do plano com valor literal incorreto para `reguaMargem(-1.99)`**
- **Found during:** Task 1
- **Issue:** o `<behavior>` da Task 1 afirmava `-2.0 e -1.99 → 2.0`, mas o corte real (`<= -2`, copiado byte a byte do original) dá `2.0` só para `-2.01`/`-2.0`; `-1.99` cai no próximo corte (`<= 1`) e devolve `3.0`. O teste de equivalência via Reflection contra `DesempenhoScoreService::reguaMargem()` (a fonte canônica) confirmou que `3.0` é o valor correto.
- **Fix:** ajustei meu teste de valores literais para os boundaries corretos (`-2.01`/`-2.0 → 2.0`; `-1.99`/`-1.01 → 3.0`), documentando o motivo no comentário. Nenhuma mudança no serviço.
- **Files modified:** `tests/Feature/Phase119/CompanyScoreServiceReguasTest.php`
- **Commit:** `87a7df46`

**2. `Http::fake()` acumula stubs — meu `setUp()` registrava um 404 genérico que "ganhava" do fixture real**
- **Found during:** Task 2
- **Issue:** o `setUp()` do `CompanyScoreServiceContratoTest` registrava `Http::fake(['*/performance/*' => 404, ...])` como proteção contra rede real (GATE MPP-04). Ao chamar `fakeAdmanEndpoints()` dentro de cada teste (2º `Http::fake()` para os MESMOS padrões de URL), o Laravel mantém os stubs em ordem de registro e o PRIMEIRO ainda casa primeiro — o 404 do `setUp()` vencia, e `faturamento_atual`/`margem_pct_atual` ficavam `null` mesmo com o fixture real registrado depois (mesmo comportamento já documentado em `AdmanMetricDiffServiceTest`).
- **Fix:** removi o `Http::fake()` genérico do `setUp()`, mantendo só `Http::preventStrayRequests()`; cada teste que toca o dispatcher chama `fakeAdmanEndpoints()`/`fakeAdmanPorEmpresa()` explicitamente (inclusive o teste de "mês em curso", que também exercita o dispatcher — o guard C-04 depende só de `fonte_financeira !== null`, não de `mesFechado`).
- **Files modified:** `tests/Feature/Phase119/CompanyScoreServiceContratoTest.php`
- **Commit:** `faceb77e`

**3. Janela NPS ainda aberta na fixture do teste "empresa polos"**
- **Found during:** Task 2
- **Issue:** meu teste esperava `nota_empresa_parcial` igual à nota de NPS e `quality.motivos === ['sem_fonte_financeira']` para a empresa sem fonte, competência 2026-06 lida em 2026-07-15. Mas a janela de coleta do NPS (M+1 = julho) só fecha em 31/07 14h (`NpsJanelaResolver::fechada()`, régua `gte`) — sem survey real na fixture, `NpsPorEmpresaService` devolve `nota=null`/`origem=janela_aberta` para toda a carteira nessa janela. Comportamento CORRETO do serviço consumido, não bug do `CompanyScoreService`.
- **Fix:** ajustei a asserção para `nps_pontos=null`, `componentes_presentes=0` e `quality.motivos=['sem_fonte_financeira', 'nps_janela_aberta']` — documentando o motivo no comentário do teste.
- **Files modified:** `tests/Feature/Phase119/CompanyScoreServiceContratoTest.php`
- **Commit:** `faceb77e`

Nenhuma outra alteração de escopo. `DesempenhoScoreService.php` permanece byte a byte intocado (hash verificado em toda task).

## Débito registrado (herdado, para a Fase 120)

- **Réguas duplicadas** (`reguaFaturamento()`/`reguaMargem()`) — a proteção contra divergência é um teste de equivalência via Reflection, não a extração completa para uma classe compartilhada. Mesmo padrão da Fase 118 (`NpsJanelaResolver`/`computeNpsWindow`). Unificação real fica para a Fase 120, quando o gate de aditividade sair.
- **Risco registrado em `119-CONTEXT.md`<risks>** — a aritmética muda de fato: `margemPontos()` (blend ponderado por contagem, Fase 109) aplica a régua UMA VEZ sobre a média agregada; o caminho novo aplica por empresa antes da média. O invariante testado em `DesempenhoShopeeScoreTest` ("só-performance → idêntico ao pré-Fase-109") NÃO vale no caminho novo — é esperado (D3 da milestone), mas a Fase 120 precisa decidir se `DesempenhoShopeeScoreTest` ganha cenários novos para o modo flag-ligada ou se os invariantes são reescritos.

## Próximo

Wave 2 — `119-03-PLAN.md`: prova dura de `diff_pp` × `diff_pct` (EMPS-03 aprofundado), chamada única do dispatcher sob mais cenários, Shopee e taxonomia de status. Wave 3 — `119-04-PLAN.md` (conforme roadmap da fase).

## Self-Check: PASSED

Todos os arquivos criados (`CompanyScoreService.php` + 3 suítes + este SUMMARY) e todos os 3 commits (`87a7df46`, `faceb77e`, `de25d5a3`) foram verificados como existentes no disco/git log.
