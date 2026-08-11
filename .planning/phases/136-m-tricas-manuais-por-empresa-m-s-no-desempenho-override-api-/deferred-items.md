# Itens fora de escopo descobertos durante a execução — Fase 136

Registrado durante a execução do Plano 01, Task 2, ao rodar uma varredura mais ampla
(`tests/Feature/V16/`, `tests/Feature/V18/`, `tests/Feature/Phase117/`,
`PortfolioShopeeCarteiraTest`, `NpsFaltantesPorModeloTest`,
`CarteiraContextShopeeElegivelTest`) para garantir que a correção do desempate (D-10) não
regrediu nada fora das 5 suítes da baseline oficial (`136-BASELINE-TESTES.md`).

## Falhas confirmadas como PRÉ-EXISTENTES (não causadas pela Fase 136)

Todas verificadas com evidência direta — dump do mapa `$fontes` resolvido por
`FinancialSourceResolver` mostrando que o resultado do desempate é **byte-idêntico** ao que
a regra antiga produziria nestes cenários (nenhum dos dois tem carteira mista sem `cust_id`).
A causa raiz é a mesma que já explica os 9 failures da baseline oficial: o hotfix de
2026-07-24 revogou a prioridade do `calculated_fallback` local em `AdmanMetricDiffService::
resolveMargemPct()` — fixtures que criam `AdmanMetric` direto no banco (sem `.diff` nativo via
HTTP fake) deixam de produzir `contribution_margin_pct.diff_pct`, e qualquer teste que
dependia desse cálculo local passa a ver `null` onde esperava um número.

1. **`tests/Feature/V16/DesempenhoElegibilidadeTest::test_misto_e_official_com_financeiro_so_do_vinculo_elegivel`**
   — espera `var_margem_pct=2.80` (via `calculated_fallback`); a empresa A já tem
   `adman_account_id` setado desde 2026-07 (comentário no próprio teste cita "Fase 102
   (BON-03)"). Dump do desempate: `{"1":"adman","2":"shopee"}` — igual à regra antiga.
   Falha é `null` em vez de `2.80`, mesma assinatura da baseline
   (`V18\DesempenhoPeriodoOficialTest > var margem pct cai no calculated fallback quando diff ausente`).

2. **`tests/Feature/V16/DesempenhoElegibilidadeTest::test_partial_quando_vinculo_elegivel_sem_dados_financeiros_no_periodo`**
   — mesma causa raiz (calculated_fallback).

3. **`tests/Feature/V16/PerformanceIndexMetadadosTest::test_ranking_inclui_os_6_metadados_de_elegibilidade_por_linha`**
   — empresa ÚNICA vínculo performance (sem desempate algum envolvido), `adman_account_id`
   setado. Espera `score_status='official'`, recebe `'partial'` porque a margem calculada
   veio `null` (mesma causa: calculated_fallback revogado). Comentário no próprio teste já
   avisa: "custId vazio = emptyMetrics() [...] var_margem_pct sempre null → score_status vira
   'partial'" — aqui o custId **não** está vazio, então é o hotfix de 2026-07-24, não D-10.

4. **`tests/Feature/Phase61/PortfolioMultiFonteE2ETest::test_flag_on_portfolio_carteiras_admin_expoe_source_counts_por_user`**
   e **`test_flag_off_portfolio_carteiras_admin_nao_expoe_source_counts`** — causa DIFERENTE:
   a fixture (`attachCarteira()`) usa `$user->companies()->attach()` puro, sem `servico_id` e
   sem `ContratoServico` ativo. `CarteiraContextService::forUser()` só resolve o ramo legado
   (`servico_id NULL`) quando existe contrato de serviço `performance` ativo — como a fixture
   nunca cria contrato, `forUser()` já devolve **vazio** ANTES de qualquer código tocado por
   esta fase rodar (linha do card `if ($vinculos->isEmpty()) return null;` roda antes de
   `fontesFinanceirasPorEmpresa()`, que é o único método que a Fase 136 modificou neste
   arquivo). Provado por teste diagnóstico temporário chamando
   `CarteiraContextService::forUser()` isoladamente (arquivo não tocado pela Fase 136): 0
   vínculos devolvidos para o mesmo padrão de fixture.

5. **`tests/Feature/Phase61/PortfolioSourceEnrichmentTest::test_flag_on_portfolio_own_admin_enriquece_user_portfolios_com_source_counts`**
   — mesma causa do item 4 (mesma fixture `attachCarteira()`).

## Ação tomada

Nenhuma dessas 5 falhas foi corrigida — estão fora do escopo da Task 1 (fixture financeira
não relacionada a D-10) do plano `136-01-PLAN.md`. Registradas aqui para não confundir
dívida antiga com regressão desta fase, seguindo a mesma disciplina da baseline oficial
(`136-BASELINE-TESTES.md`).

## O que FOI corrigido nesta fase (não é dívida — é consequência direta de D-10)

Estes 2 arquivos de teste tinham fixtures de carteira mista (performance + shopee) que
dependiam do comportamento ANTIGO (adman vence incondicionalmente) sem nunca terem dado à
empresa um `cust_id` real. Corrigido adicionando `adman_account_id` ao fixture — preserva a
intenção original do teste ("quando a empresa REALMENTE tem conta Adman, adman vence") sob a
regra corrigida:

- `tests/Feature/PortfolioShopeeCarteiraTest.php` — 2 métodos
  (`test_carteira_individual_desempate_adman_vence_quando_dois_vinculos_com_dado_real`,
  `test_carteira_consolidada_desempate_adman_vence_nao_duplica`)
- `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php` — 1 método
  (`test_ml_e_shopee_mesma_empresa_nao_duplica_financeiro`)
- `tests/Feature/V16/CarteirasConsolidadasContextoTest.php` — 1 método
  (`test_card_ml_e_shopee_mesma_empresa_nao_duplica_financeiro`)
