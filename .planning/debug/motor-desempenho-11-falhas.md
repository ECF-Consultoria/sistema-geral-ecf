---
status: diagnosed
trigger: "Investigue 11 falhas no motor de nota de desempenho e bonificação. Investigação primeiro — não saia consertando. php artisan test --filter=\"Phase74|Phase110\" → 11 failed, 28 passed"
created: 2026-09-14
updated: 2026-09-14
---

## Current Focus

hypothesis: CONFIRMADA — as 11 falhas são **testes desatualizados**, em 3 grupos, todos
  encadeados na revogação do `calculated_fallback` local da margem % (hotfix a413e823,
  2026-07-24). Nenhuma é defeito de cálculo de bônus.
test: sondas temporárias (já removidas) sobre AdmanMetricDiffService, DesempenhoScoreService
  e ConsolidarMesDesempenho, com e sem `.diff` nativo da Adman
expecting: com `.diff` presente, `var_margem_pct` volta a 4,5 e o snapshot persiste — confirmado
next_action: nenhuma nesta rodada (investigação-only por instrução explícita do usuário).
  O conserto está recomendado por grupo na seção Resolution, sem aplicar.

## Symptoms

expected: 39 testes passando em Phase74 + Phase110
actual: 11 failed, 28 passed (exit 1)
errors: |
  DesempenhoScoreServiceTest.php:585 — var_margem_pct vindo null onde o golden espera 4.5
  DesempenhoScoreServiceTest.php:902 — null vs 15.0
  DesempenhoScoreServiceTest.php:967 — null vs 50.0
  DesempenhoScoreServiceTest.php:1036 — 3.0 vs 4.67
  DesempenhoScoreServiceTest.php:1145 — 3.3333333333333335 vs 5.0
  ConsolidarMesDesempenhoCommandTest.php:286/310 — "null is not null" (snapshot ausente)
  ConsolidarMesDesempenhoCommandTest.php:326 — 0 vs 1
  ConsolidarMesDesempenhoCommandTest.php:380 — size 0 vs 3
  ConsolidarMesMargemResilienteTest.php:274 — "null is not null"
  ConsolidarMesMargemResilienteTest.php:321 — 0 vs 1
reproduction: C:\xampp\php\php.exe artisan test --filter="Phase74|Phase110"
started: comportamento mudou em 2026-07-24 (a413e823); agravado em 2026-08-10 (3b320dce).
  Ficou INVISÍVEL até 2026-09-14 porque a migration b3db53b0 (2026-09-10) passou a semear o
  setor "Performance" e o setUp morria na colisão de `setores.nome` UNIQUE. O quick 260914-gmp
  desbloqueou o setUp. Antes de 10/09 as falhas EXISTIAM e eram baseline conhecida — o commit
  50d85f21 (2026-08-05) declara "A baseline de falhas do modulo continua exatamente a mesma de
  antes (31)", e o learnings §0.02 lista a mesma classe de falha medida em 2026-08-10.

## Eliminated

- hypothesis: "regressão introduzida pelo quick 260914-gmp (colisão setores.nome)"
  evidence: usuário mediu com setor isolado e único — mesmas falhas, mesma quantidade
  timestamp: pré-sessão

- hypothesis: "bump de chave de cache `desempenho.compute.vNN` quebrou strings hardcoded"
  evidence: nenhuma das 11 mensagens de falha menciona chave de cache; todas são valores
    numéricos/ausência de row. A suspeita do learnings §5 não se aplica a este lote.
  timestamp: 2026-09-14

- hypothesis: "instabilidade do .diff de margem da Adman (margem-adman-diff-instavel.md)"
  evidence: os testes rodam com `Http::preventStrayRequests()` + fake 404 — zero HTTP real.
    Não há como instabilidade de rede produzir falha determinística e reprodutível.
  timestamp: 2026-09-14

- hypothesis: "1062 Duplicate entry do unique legado (learnings §10.1) barra o consolidar-mes"
  evidence: é falha de MariaDB (índice de apoio a FK, erro 1553 na migration). Os testes rodam
    em SQLite, onde o drop da migration 140001 sempre funcionou e o legado nunca existiu.
    Sonda confirmou que a não-persistência vem do gate FIXMARG-03, não de colisão de unique.
  timestamp: 2026-09-14

- hypothesis: "defeito real no cálculo de bônus"
  evidence: com `.diff` nativo da Adman fornecido ao stub, `var_margem_pct` volta a 4,5 e
    `nota_final_legado` volta a 4,42 — exatamente os goldens. O motor calcula certo; o que
    mudou foi de ONDE a margem vem (nativo Adman, não SUM local).
  timestamp: 2026-09-14

## Evidence

- timestamp: 2026-09-14
  checked: saída completa dos 11 testes, agrupada por mensagem
  found: 3 grupos — (A) var_margem_pct null ×3; (B) nota_final rebaixada ×2; (C) snapshot não
    persistido ×6 (4 Phase74 + 2 Phase110)
  implication: não é uma causa só; mas os três compartilham um elo

- timestamp: 2026-09-14
  checked: comentário do setUp em DesempenhoScoreServiceTest.php:113-124 (commit c270a714,
    2026-07-20, Fase 102-01)
  found: "o fake 'sem .diff' força calculated_fallback DETERMINÍSTICO (o golden vem do fixture
    local, nunca de prod)" — o teste foi DESENHADO em cima do cálculo local
  implication: prova documental de que a premissa do teste é o fallback local

- timestamp: 2026-09-14
  checked: git show a413e823 (2026-07-24) em AdmanMetricDiffService.php
  found: `fn () => $this->fallbackSomaSimples($company, $periodo, 'contribution_margin')` virou
    `fn () => null`; `resolveMargemPct()` perdeu os parâmetros `$company, $periodo`; cache v3→v4
  implication: a premissa do teste foi revogada 4 dias depois de o teste ser escrito

- timestamp: 2026-09-14
  checked: sonda sobre AdmanMetricDiffService::compute() com adman_metrics locais + Adman 404
  found: revenue diff_pct=3 via `calculated_fallback`; contribution_margin_pct diff_pct=**null**,
    diff_pp=null, diff_source=`adman_indisponivel`
  implication: a ASSIMETRIA é a chave — faturamento manteve fallback local, margem % não.
    Por isso `var_faturamento_pct` (3.00) passa e `var_margem_pct` falha no mesmo teste

- timestamp: 2026-09-14
  checked: mesma sonda, com o stub devolvendo percentageMargin {value:20.90, diff:4.50, prev:20.00}
  found: diff_pct=**4.5**, diff_pp=0.9, diff_source=`adman_diff`
  implication: FALSIFICAÇÃO fechada — o 4,5 do golden é produzível; só precisa vir da Adman

- timestamp: 2026-09-14
  checked: sonda sobre compute() no cenário "saudável" do Phase110
  found: margem_amostra = {n_real:0, n_elegivel:1, cobertura:0, legado:{cobertura:0}}
  implication: gate FIXMARG-03 (< 0,7) recusa congelar → zero snapshot. O gate está FUNCIONANDO
    como projetado; o cenário é que deixou de produzir margem. Grupo C = mesmo elo do A

- timestamp: 2026-09-14
  checked: sonda com 3 users semeados com NPS 5 / 4 / 3 no cenário de ConsolidarMes
  found: nps_medio=**null** nos três; nota_final IDÊNTICA (1.3333) nos três
  implication: DEFEITO DE TESTE LATENTE (Grupo D) — `notasLegado()` usa `->principal()`, que
    filtra `template_id = principalId`, e `NpsSurveyFactory` cria `template_id => null`.
    `test_comando_popular_ranking_pos_por_mes_referencia` voltará a falhar (ranks empatados)
    mesmo depois de resolver o Grupo C

- timestamp: 2026-09-14
  checked: sonda do fixture Carlos com a Adman respondendo os números exatos da fixture
  found: var_margem_pct=4.5 ✓, **nota_final_legado=4.42** ✓ (o golden do teste), mas
    **nota_final OFICIAL=3.72**, faixa `sem_bonus`; var_margem_pp=0.9; pontos={nps:4.17, fat:4, margem:3}
  implication: ACHADO DE PRIMEIRA ORDEM — o golden 4,42 do fixture Carlos hoje corresponde a
    `nota_final_legado` (metadado de auditoria), NÃO à nota oficial. Desde 2026-08-05 a nota
    oficial vem de `computeNotaFinalPorIndicador()` sobre `empresasScore`, e a margem lá é
    `margem_var_pp` (pontos percentuais, `diff_pp`) — não `diff_pct`. +4,5% relativo = +0,9 p.p.,
    e a mesma régua dá 5 pts na primeira grandeza e 3 pts na segunda

- timestamp: 2026-09-14
  checked: DesempenhoScoreService.php:1879 e git log -S DIVISOR_NOTA_FINAL
  found: `DIVISOR_NOTA_FINAL = 3` fixo desde 3b320dce (2026-08-10); indicador ausente entra como 0
  implication: explica por que `nota 5 exata` dá 3.3333 = (5+5+0)/3 e `2 meses consecutivos`
    dá 3.0 = (5+4+0)/3. Antes de 10/08 o divisor era o nº de presentes

- timestamp: 2026-09-14
  checked: consumidores de `componentes.var_margem_pct` em app/ e resources/js/
  found: PerformanceController (payload), PortfolioController (comparação com pares) e
    RelatorioBonificacaoController → única exibição em RelatorioBonificacao.jsx, que lê
    competência FECHADA de snapshot congelado (onde `diff_pct` existe)
  implication: o campo null do mês corrente não chega a nenhuma tela de bônus — confirma que
    não há impacto em pessoa real

- timestamp: 2026-09-14
  checked: todos os NpsSurvey::create() de produção
  found: NpsDispararMensal, NpsController e NpsGrupoReplicacaoService preenchem `template_id`
  implication: o Grupo D é defeito de FIXTURE (factory com template_id null), não de produção.
    Surveys sem template só existem no histórico pré-v15 (pré-Fase 69)

## Resolution

root_cause: |
  Elo comum: o hotfix `a413e823` (2026-07-24) revogou o `calculated_fallback` local da
  margem %. O faturamento MANTEVE o fallback local; a margem % passou a ser "nativo-ou-null".
  Toda a Phase74/Phase110 semeia `adman_metrics` locais e fakeia a Adman com 404 — cenário que
  antes produzia margem e desde 24/07 produz null.

  GRUPO A (3) — var_margem_pct null. Testes desatualizados desde 2026-07-24 (a413e823).
    Sub-caso `nao_inverte_sinal`: causa DUPLA — roda em mês EM CURSO, onde `diff_pct` é null
    por design mesmo com a Adman respondendo (ramo `adman_janela_baseline`, 3e6d0eab 2026-08-10).

  GRUPO B (2) — nota_final rebaixada. Consequência do A + divisor fixo 3 (3b320dce, 2026-08-10).
    `2 meses consecutivos` quebrou em 24/07 (dava 4,5 vs 4,67) e agravou em 10/08 (3,0).
    `nota 5 exata` quebrou só em 10/08 — até lá o divisor pelos presentes dava (5+5)/2 = 5,00
    e o teste passava por coincidência, com a margem já ausente.

  GRUPO C (6) — consolidar-mes não persiste. Gate FIXMARG-03 com cobertura 0,0, pelo mesmo elo.
    O gate funciona como projetado (ade529d9, 2026-07-23); o cenário é que parou de ter margem.

  GRUPO D (latente, 0 das 11 hoje) — NPS legacy invisível. `notasLegado()` usa `->principal()`
    desde 299cf63e (2026-07-13); `NpsSurveyFactory` cria `template_id => null`, que nunca casa.

  JANELA CEGA: b3db53b0 (2026-09-10) passou a semear o setor "Performance" e o setUp morria na
  colisão UNIQUE. O quick 260914-gmp (2026-09-14) desbloqueou. As falhas são de julho/agosto.

fix: NÃO APLICADO (investigação-only). Recomendações por grupo no relatório de retorno.
verification: não aplicável nesta rodada
files_changed: []
