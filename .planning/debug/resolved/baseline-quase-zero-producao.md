---
slug: baseline-quase-zero-producao
status: diagnosed
trigger: Empresa que faturou ~R$0 no mês anterior gera faturamento_var_pct de milhares por cento e infla a nota de desempenho. Confirmado no comparador (Fase 121); a suspeita é que o mesmo furo esteja no cálculo legado que está NO AR hoje, valendo bônus real. Não medido.
created: 2026-07-31
updated: 2026-07-31T20:40:00-03:00
criticality: alta
---

# Baseline quase-zero infla a nota de faturamento (suspeita em produção)

## Symptoms

**Expected behavior:**
A régua de faturamento deve refletir crescimento ou queda econômica real da carteira. Uma empresa que estava parada e voltou a operar não deve pesar como se a carteira inteira tivesse crescido centenas de por cento.

**Actual behavior:**
A média bruta de `faturamento_var_pct` não tem nenhuma proteção contra variação percentual calculada sobre baseline quase-zero. Medido na carteira do Douglas (competência 2026-06, run_id `03787204-51a7-49fb-8478-da56a5b07e2a`):

| Média de `faturamento_var_pct` — carteira do Douglas (27 empresas) | |
|---|---|
| Com todas | **+766,25%** |
| Sem a empresa 332 | **−1,9%** |
| Segunda maior variação individual da carteira | +68,64% |

A empresa 332 ("Lojão do Bras") tem `revenue = R$0,00` de 12 a 18/06 e volta a faturar em 19/06, com baseline de maio praticamente zero → `faturamento_var_pct = +20738,26%`. Ela sozinha vira o bucket da régua de faturamento de 3 pts para 5 pts. A carteira, que na verdade encolheu ~2%, pontua como se tivesse explodido.

**Error messages:**
Nenhum. Nada quebra — a conta roda e devolve um número plausível à primeira vista. É exatamente por isso que passou despercebido.

**Timeline:**
Descoberto em 2026-07-31, na sessão de debug `residuo-delta-douglas-danilo` (ver `.planning/debug/resolved/`), enquanto se investigava outra coisa. **Não se sabe desde quando afeta produção nem se já afetou bônus pago.** Nada indica que seja regressão recente — a ausência de guarda parece ser original do cálculo.

**Reproduction:**
Consulta ao banco no VPS pelo run_id acima. O que ainda NÃO foi reproduzido é o ponto central desta sessão: se o ranking / dashboard / bônus **em produção** usa o mesmo agregado sem guarda.

## Perguntas que esta sessão precisa responder

1. **O cálculo em produção tem o mesmo furo?** `computeVarFaturamento()` (e o equivalente de margem) alimentam a nota do ranking e do bônus reais, ou só o lado "antigo" do comparador? Esta é a pergunta que decide se o resto importa.
2. **Qual o tamanho do estrago?** Quantas empresas têm baseline quase-zero na competência anterior, em quantas carteiras, em quantas competências. A empresa 332 aparece nas carteiras de Douglas E Danilo — provavelmente não é caso único.
3. **Já virou dinheiro?** Competências fechadas leem snapshot congelado (`desempenho:consolidar-mes`). Se snapshots passados foram congelados com nota inflada, corrigir a fórmula hoje não desfaz o que já foi pago — e essa distinção precisa estar explícita no relatório.
4. **A margem tem o mesmo problema?** `margem_diff_pct` sofre da mesma divisão por quase-zero, ou a natureza do dado protege?

## Escopo

`goal: find_root_cause_only`. Alterar a régua de faturamento muda pagamento de pessoas — é decisão de negócio, não correção de bug. O entregável aqui é **diagnóstico com números**, mais opções de proteção avaliadas (ex.: piso de faturamento mínimo no baseline, winsorização, mediana em vez de média), sem aplicar nenhuma.

## Current Focus

- **hypothesis:** CONFIRMADA — `computeVarFaturamento()` (via `AdmanMetricDiffService::resolveField()`) não tem nenhuma proteção quando a Adman entrega o `.diff` NATIVO dela mesma sobre um baseline quase-zero. A flag `metrics.performance_company_first_score` está desligada em produção, então 100% do bônus/ranking oficial passa por este caminho legado.
- **next_action:** nenhuma — diagnóstico concluído, sessão pronta para `return_diagnosis` (goal=find_root_cause_only, não aplicar fix)
- **test:** consulta ao snapshot congelado de junho/2026 (`desempenho_score_snapshots`) para Douglas/Danilo, e replay direto de `AdmanMetricDiffService::compute()` para a empresa 332
- **expecting:** confirmado — número do snapshot bate byte a byte com o número medido manualmente no comparador

## Evidence

- timestamp: 2026-07-31 (leitura de código)
  checked: `app/Services/DesempenhoScoreService.php::computeVarFaturamento()` (linhas 1319-1366)
  found: Faz `foreach ($companies as $company) { ... $diffPct = $this->diffDispatcher->compute(...)['metrics']['revenue']['diff_pct'] ?? null; if ($diffPct !== null) $vars->push($diffPct); }` e no final `round($vars->avg(), 2)`. NENHUM guard de magnitude — só filtra `null`. É EXATAMENTE o mesmo padrão de média simples sem proteção que o comparador expôs.
  implication: o "furo" não é exclusivo do comparador/CompanyScoreService (shadow) — está no método legado que hoje decide a nota oficial.

- timestamp: 2026-07-31 (leitura de código)
  checked: `app/Services/DesempenhoScoreService.php::compute()` (linhas 505-604) e docblock da Fase 120/121
  found: `if (config('metrics.performance_company_first_score') && $empresasScore !== null) { ... } else { $nota = $this->computeNotaFinal($nps, $varFat, $margemPontos); }`. Segundo `<ambiente>` da sessão, a flag está e deve permanecer `false` em produção (proibido ligar). `$varFat` vem de `computeVarFaturamento()`.
  implication: CONFIRMA Pergunta 1 — em produção HOJE, sem exceção, `nota_final`/`faixa_bonus` usam o ramo legado com o furo. Não é um problema só do comparador "antigo" nem só do caminho novo (shadow) — os DOIS compartilham a mesma fonte de baixo nível (`MetricDiffDispatcher` → `AdmanMetricDiffService`).

- timestamp: 2026-07-31 (leitura de código)
  checked: `app/Services/Metrics/AdmanMetricDiffService.php::resolveField()` (linhas 237-261) e `diffPctGuardado()` (linhas 524-531)
  found: Dois caminhos possíveis para `revenue.diff_pct`: (1) `isJanelaIgual && $adminDiff !== null` → usa o `.diff` NATIVO da própria Adman, sem NENHUMA validação de magnitude/baseline; (2) senão → `calculated_fallback` local, que TEM um guard (`anterior <= 0` → null), mas só bloqueia zero/negativo, nunca "quase-zero positivo". Para TODO mês fechado/bônus oficial, `comparison_mode = 'previous_equal_length_window'` (ver `MetricPeriodResolver::resolveLastClosedMonth()`/`resolveSpecificMonth()`), ou seja `isJanelaIgual=true` sempre que há competência fechada — o caminho (1) é o PREFERENCIAL e mais usado.
  implication: a causa raiz é mais grave que a hipótese original. Não é só "nosso cálculo local não tem piso" — é que quando a Adman informa o `.diff` dela mesma, nós REPASSAMOS sem questionar, e o `calculated_fallback` (que teria um guard, ainda que fraco) nem chega a ser executado nesse caso.

- timestamp: 2026-07-31 (consulta VPS, leitura livre)
  checked: `AdmanMetric::where('company_id', 332)->whereBetween('reference_date', ['2026-05-01','2026-06-30'])`
  found: ZERO linhas em maio/2026 na tabela local `adman_metrics` para a empresa 332 (Lojão do Bras) — primeira linha é 12/06 (revenue=0), fatura de fato a partir de 19/06.
  implication: se o cálculo dependesse do `calculated_fallback` local, o guard `margem_dias` (`rowsAnterior->isEmpty()`) teria barrado e devolvido `null` — ou seja, o furo NÃO viria do nosso fallback aqui. Isso empurra a suspeita para o caminho `adman_diff`.

- timestamp: 2026-07-31 (consulta VPS, replay direto)
  checked: `AdmanMetricDiffService::compute($company332, $periodo)` com `period_key='2026-06'` (mesma janela do bônus oficial de junho)
  found: `metrics.revenue = {value: 16666.44, prev_value: 79.98, diff_pct: 20738.26, diff_source: 'adman_diff'}`, `quality.status = 'complete'`.
  implication: CONFIRMADO — `diff_source='adman_diff'`. A própria API da Adman devolve `prev=R$79,98` (um valor residual/ruído, não um baseline real) para a janela 02/05–31/05, e computa ela mesma a variação de 20738,26%. Nosso código repassa esse número sem nenhum piso. Bate exatamente com o valor citado nos Symptoms.

- timestamp: 2026-07-31 (consulta VPS, dado já persistido — sem custo Adman)
  checked: `DesempenhoScoreSnapshot::mensal()->where('mes_referencia','2026-06-01')` para Douglas (user 19) e Danilo (user 15) — o snapshot CONGELADO que decide o bônus oficial de junho/2026 (pago em julho/2026)
  found: Douglas → `var_faturamento_pct=766.25`, `pontos_componentes.faturamento=5`, `nota_final=4`, `faixa_bonus=basico`. Danilo → `var_faturamento_pct=699.68`, `pontos_componentes.faturamento=5`, `nota_final=4.55`, `faixa_bonus=intermediario`.
  implication: RESPOSTA DEFINITIVA à Pergunta 1 — `var_faturamento_pct=766.25` bate BYTE A BYTE com o número medido manualmente na sessão anterior via comparador ("Com todas: +766,25%"). Isso prova que o furo não é uma hipótese: já está CONGELADO no snapshot oficial que decide pagamento. `pontos_componentes.faturamento=5` (nota máxima, "crescimento excelente") é artefato do baseline quase-zero, não faturamento real. `bonus_payment_month` = competência+1 = 2026-07 — o MÊS ATUAL (hoje é 2026-07-31), ou seja o ciclo de pagamento de julho para a competência de junho está no fim da janela.

- timestamp: 2026-07-31 (consulta VPS, dado já persistido — sem custo Adman)
  checked: `DesempenhoComparadorEmpresa::where('run_id','03787204-...')` — 862 linhas, 11 profissionais distintos, filtro `faturamento_var_pct > 300% OU < -90%`
  found: 48 linhas (24 empresas distintas, cada uma linkada a 2 profissionais) com variação implausível, TODAS com `fonte_financeira='adman'`. Extremos: DIM STORE (company 324) +346.533,43%, DINMAP (company 319) +220.828,05%, Golden Producions +109.260,01%, Utilarshop +32.782,72%, Jf Auto Câmbio +30.293,21%, Lojão do Bras (332) +20.738,26%, entre outras. 9 dos 11 profissionais do run têm pelo menos 1 empresa na lista.
  implication: RESPOSTA (parcial, com a base já coletada) à Pergunta 2 — não é caso isolado da empresa 332/carteira do Douglas. É um padrão sistêmico que atinge pelo menos 24 empresas em uma única competência (junho/2026), espalhado pela maioria dos profissionais medidos. NOTA: nem toda empresa extrema entra na média AGREGADA do profissional com o mesmo peso — profissionais como Rubens (user 20, dono de DIM STORE +346.533%) e Gustavo (user 16, dono de DINMAP +220.828%) tiveram snapshot final "normal" (12,84% e -1,75%), sugerindo que ou (a) essas empresas específicas não entraram no subconjunto financeiro elegível DESSE profissional no cálculo oficial, ou (b) há dessincronia entre o run do comparador e o universo `financial_metrics_eligible` vivo no momento do snapshot — não investigado a fundo (fora do escopo desta pergunta; achado lateral, não é o foco de find_root_cause_only).

- timestamp: 2026-07-31 (consulta VPS, dado já persistido — sem custo Adman)
  checked: `DesempenhoComparadorEmpresa` do mesmo run — filtro em `margem_diff_pct` (>100%/<-100%) e `margem_var_pp` (>20pp/<-20pp)
  found: 4 linhas com `margem_diff_pct` extremo, 12 linhas com `margem_var_pp` extremo (bem menos que as 48 de faturamento).
  implication: RESPOSTA à Pergunta 4 — margem tem a MESMA exposição estrutural (também usa `.diff` nativo da Adman sem piso, ver `resolveMargemPct()`), mas a incidência prática é bem menor. Explicação provável: margem é um valor JÁ relativo (percentual da receita, tipicamente 5-40%), raramente "quase-zero" da forma que receita absoluta em R$ pode ser (uma empresa parada tem receita R$0 mas raramente reporta margem % positiva quase-zero nesse mesmo período — o `contribution_margin_value`/`pct` tende a vir `null` junto quando não há venda, por isso o `n_com_margem_real` já exclui boa parte desses casos antes de entrar na média). Ainda assim, NÃO é imune — 4-12 casos existem na mesma amostra. Alinhado ao aprendizado de projeto já registrado ("Margem % do .diff Adman é instável no bônus" — debug aberto anterior, não regressão desta sessão).

## Eliminated

- hypothesis: "O furo existe só no comparador/CompanyScoreService (caminho novo, shadow) — o cálculo legado que está no ar pode ter proteção própria"
  evidence: leitura completa de `computeVarFaturamento()` (legado) mostra o MESMO padrão de média simples sem guard de magnitude, e o snapshot oficial congelado de junho/2026 tem o número EXATO (766,25%) medido no comparador — prova que os dois caminhos convergem na mesma vulnerabilidade de baixo nível (`AdmanMetricDiffService`)
  timestamp: 2026-07-31

- hypothesis: "O furo é do nosso `calculated_fallback` local (guard `anterior <= 0` fraco demais)"
  evidence: replay direto de `AdmanMetricDiffService::compute()` para a empresa 332 mostra `diff_source='adman_diff'`, não `calculated_fallback`. A tabela local `adman_metrics` nem tem linhas em maio/2026 para essa empresa — se dependesse do fallback local, o guard `margem_dias` teria barrado (retornaria null). O furo real é mais grave: confiamos cegamente no `.diff` que a PRÓPRIA API da Adman calcula, sem nenhuma validação de magnitude do baseline
  timestamp: 2026-07-31

## Resolution

root_cause: |
  Em `AdmanMetricDiffService::resolveField()` (revenue) e `resolveMargemPct()` (margem), quando
  `comparison_mode === 'previous_equal_length_window'` (SEMPRE verdadeiro para mês fechado/bônus
  oficial — `MetricPeriodResolver::resolveLastClosedMonth()`/`resolveSpecificMonth()`) E a Adman
  retorna um `.diff` não-nulo, o código usa esse valor DIRETAMENTE, sem NENHUMA validação de
  magnitude do baseline (`prev`). Quando o baseline informado pela própria Adman é "quase-zero"
  (ex.: empresa 332/Lojão do Bras: prev=R$79,98 — resíduo/ruído, não faturamento real de um mês
  parado), a Adman mesma calcula uma variação percentual astronômica (20.738,26%) e nós a
  repassamos sem piso.

  Isso alimenta `computeVarFaturamento()` em `DesempenhoScoreService` — método que, com a feature
  flag `metrics.performance_company_first_score` desligada (estado atual e obrigatório de
  produção), é 100% do caminho que decide `nota_final`/`faixa_bonus` no ranking e no bônus oficial
  (`computeOficial()`). Não é uma vulnerabilidade teórica: o snapshot CONGELADO de junho/2026
  (`desempenho_score_snapshots`, competência que paga em julho/2026 — o mês corrente) já tem
  `var_faturamento_pct=766,25%` para Douglas e `699,68%` para Danilo, batendo byte a byte com os
  números manuais do comparador. `pontos_componentes.faturamento=5` (nota máxima) para ambos —
  artefato do baseline quase-zero, não crescimento real.

  Contrariando a hipótese inicial da sessão: o guard fraco do `calculated_fallback` local
  (`anterior <= 0`) NÃO é o vetor principal — o `calculated_fallback` sequer é alcançado nesse
  caso, porque o `.diff` nativo da Adman tem prioridade. Qualquer correção futura (fora do escopo
  desta sessão) precisa validar o baseline DEPOIS de receber o `.diff` da Adman, não só dentro do
  cálculo local.

  Escala medida (run_id 03787204-51a7-49fb-8478-da56a5b07e2a, junho/2026): 24 empresas distintas
  com variação de faturamento >300% ou <-90%, atingindo 9 dos 11 profissionais amostrados —
  não é caso isolado da empresa 332. Margem tem a mesma exposição estrutural mas incidência bem
  menor (4-12 casos vs 48 no faturamento), provavelmente porque margem já é um valor relativo
  (%), raramente "quase-zero" do jeito que receita absoluta em R$ pode ser.

fix: (não aplicado — goal=find_root_cause_only; decisão de negócio do usuário)
verification: (não aplicável nesta sessão)
files_changed: []


## Verificacao independente do orquestrador (2026-07-31) — CORRIGE o alcance

O corpo desta sessao afirma que o problema atinge "9 dos 11 profissionais". **Isso superestima.** Aquele numero conta profissionais que tem ao menos UMA empresa extrema na carteira — nao profissionais cuja NOTA FINAL ficou inflada. Na maioria dos casos a empresa extrema nao move o agregado o suficiente para mudar pontos.

Medicao direta no snapshot congelado (`desempenho_score_snapshots`, `componentes.var_faturamento_pct`), competencia 2026-06:

| Profissional | var_fat congelado | pts fat | nota | faixa |
|---|---|---|---|---|
| Douglas | **766,25%** | 5 | 4,00 | basico |
| Danilo | **699,68%** | 5 | 4,55 | intermediario |
| Matheus Estrela | 63,25% | 5 | 3,31 | sem_bonus |
| Felipe | 43,95% | 5 | 3,21 | sem_bonus |
| Nathalia Martins | 15,55% | 5 | 4,03 | basico |
| Ana Julia | 13,34% | 5 | 4,37 | basico |
| Stefani | 12,85% | 5 | 4,56 | intermediario |
| Rubens | 12,84% | 5 | 4,91 | intermediario |
| Luiz Henrique | 12,30% | 5 | 4,36 | basico |
| Gabriela Aguiar | 6,44% | 5 | 4,49 | basico |
| Gustavo | -1,75% | 2 | 2,76 | sem_bonus |

**Apenas 2 dos 11** tem valor implausivel no snapshot: Douglas e Danilo. Os outros 9 estao entre -1,75% e 63,25%.

**E so 1 dos 2 tem pagamento errado.** Removendo as empresas com variacao extrema (>300%):

- **Douglas: 766,25% -> -1,9%.** Gustavo, com -1,75%, recebeu 2 pontos — logo Douglas cairia de 5 para ~2 pontos, e a nota de 4,00 para ~3,00. Como Matheus (3,31) e `sem_bonus` e Nathalia (4,03) e `basico`, **Douglas provavelmente deveria estar em `sem_bonus`, nao em `basico`.** Isso e dinheiro, na competencia de junho que paga em julho/2026 — o mes corrente.
- **Danilo: 699,68% -> 8,7%.** Gabriela, com 6,44%, ja recebe 5 pontos — entao Danilo continua com 5 pontos, nota 4,55 e faixa `intermediario` inalteradas. **O numero exibido esta errado, o pagamento nao.**

Escopo temporal: existe snapshot congelado APENAS para 2026-06. As competencias 2026-05 e 2026-04 nao tem snapshot, entao nao ha historico de pagamento afetado alem de junho.

**Alcance final: 1 pessoa, 1 competencia, 1 faixa de bonus.** A vulnerabilidade estrutural e real e vale corrigir, mas o estrago financeiro medido e pontual — nao sistemico.
