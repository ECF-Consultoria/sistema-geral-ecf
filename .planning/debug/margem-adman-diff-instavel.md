---
slug: margem-adman-diff-instavel
status: diagnosed
trigger: Margem % (contribution_margin_pct.diff_pct) do .diff NATIVO da Adman no modo bônus/fechado é instável — mesmo mês fechado (junho) retorna diffs diferentes a cada recompute, com swings implausíveis por empresa (±40–77%). Afeta a nota de margem do bônus de desempenho.
created: 2026-07-23
updated: 2026-07-23T15:35:00-03:00
criticality: alta
---

# Instabilidade da margem % do .diff nativo da Adman (modo bônus)

## Symptoms

**Expected behavior:**
A variação de margem % de um mês FECHADO (junho) deveria ser estável e determinística — recomputes sucessivos do mesmo período retornam o mesmo valor. Variação de margem % de uma empresa real não muda ±70% mês a mês.

**Actual behavior:**
- Média de var_margem_pct do Luiz Henrique (user 3, só-performance) mudou **+6,83 → −3,25 → +8,63** em três medições com minutos de diferença, todas no mesmo mês fechado (junho), mesmo código (v10).
- Swings por empresa implausíveis (via .diff nativo, diff_source='adman_diff'): LUCCAUTO +69,3%, LYAMDECOR +68,5%, GARCIA +77,9%, Hunter −44,4%, OESTE −33,7%.
- Várias empresas do Danilo (user 15) caem em calculated_fallback com margem NULL (DIM STORE, SHOP PRIME, SINVAL, SMART SHOPBR, Decoral, JF Auto) — sem histórico local de margem.

**Error messages:** Nenhum. Valores numéricos plausíveis-mas-voláteis (não crash).

**Timeline:** Observado 2026-07-23 durante o checkpoint da Fase 109 (após `cache:clear` + recompute fresco). Pré-existente (não é regressão da Fase 109 — Shopee não toca o caminho Adman; regressão-zero provada em teste).

**Reproduction:**
1. VPS: `php artisan desempenho:warm-cache` (ou computeCached de user 3/15, competência 2026-06)
2. Ler var_margem_pct / pontos_componentes.margem
3. `cache:clear` + recompute → valor muda materialmente
4. Chamar AdmanMetricDiffService::compute direto pras empresas → diffs por empresa oscilam entre chamadas

## Contexto técnico

- Caminho: DesempenhoScoreService::computeVarMargem() → MetricDiffDispatcher->compute(company, periodo, 'adman') → AdmanMetricDiffService::compute() → resolveField() (gate por comparison_mode).
- Modo bônus: period_key='last_closed_month', comparison_mode='previous_equal_length_window', current=junho 01–30, baseline=maio 02–31. resolveField() usa .diff NATIVO da Adman (diff_source='adman_diff') quando não-nulo; senão calculated_fallback sobre adman_metrics local.
- computeVarMargem agrega: média dos contribution_margin_pct.diff_pct não-nulos das empresas; expõe n_com_margem_real.
- AdmanMetricDiffService cacheia por dia BRT (Cache::put 1440 min).

## ELO CRÍTICO com auditoria resolvida `audit-margem-luiz-ana` (2026-07-10)

Aquela auditoria provou: desde o cutover `c85b86f` (01/06), empresas com ml_tokens.status='active' são EXCLUÍDAS do `adman:sync` (SyncAdmanData.php:83) — que é a ÚNICA fonte de `contribution_margin` no `adman_metrics` local. O `ml:sync` (MercadoLivreService::syncCompany) NÃO popula margem/CMV. Luiz tem 96% da carteira ML-OAuth ativa, Ana 83%.

**Implicação para ESTE bug:** para empresas ML-driven (a maioria da carteira do Luiz/Ana/Danilo), o `adman_metrics` local tem margem NULL → o `calculated_fallback` do AdmanMetricDiffService não tem o que somar → margem só pode vir do **.diff NATIVO da Adman (API ao vivo)**. A conta Adman em si continua existindo e recalculando junho (a dashboard Adman externa mostra margem). Se essa API recalcula/assenta a margem de junho ao longo do tempo (lag de CMV), cada recompute pega um instante diferente → a instabilidade observada. Concentra-se exatamente nas carteiras ML-heavy.

## Hipóteses iniciais (a testar)

1. **Margem só vem do .diff nativo pra empresas ML-driven** (fallback local nulo pós-cutover) E **o .diff nativo da Adman para junho ainda está assentando** (lag de CMV) → cada chamada retorna valor diferente. [PRINCIPAL — conecta com audit-margem-luiz-ana]
2. **O .diff nativo compara janelas mal-alinhadas** (baseline maio 02–31 vs current junho 01–30 — tamanhos/limites diferentes no lado Adman) produzindo diffs de margem % inflados/instáveis. Ver project_adman_diff_janela_gate.
3. **Cache por-dia-BRT do AdmanMetricDiffService mascara/expõe valores em momentos diferentes** — mas o valor SUBJACENTE da API é que muda.

## PERGUNTA CRÍTICA (responder PRIMEIRO — decide a gravidade)

O bônus de competência FECHADA lê um SNAPSHOT mensal congelado (SnapshotDesempenhoScores / ConsolidarMesDesempenho / DesempenhoScoreSnapshot) ou o cálculo AO VIVO (computeCached)? Se congelado num ponto de fechamento, a volatilidade NÃO afeta o pagamento (só a tela ao vivo). Se ao vivo, é problema real de acertividade do bônus.

## Direções de fix candidatas (NÃO decidir antes de investigar)

a) Para contribution_margin_pct, preferir o calculated_fallback do adman_metrics local ASSENTADO em vez do .diff nativo (mas o local é nulo pra ML-driven — precisa de fonte de CMV).
b) Congelar a margem no snapshot de fechamento (ConsolidarMesDesempenho) e o bônus ler só o snapshot.
c) Gate/tolerância no lag (ex.: só usar margem quando a cobertura de dias-com-margem for suficiente).

## Arquivos suspeitos

- app/Services/Metrics/AdmanMetricDiffService.php (resolveField, gate .diff nativo, fallback, cache)
- app/Services/DesempenhoScoreService.php (computeVarMargem, computeOficial, snapshot vs live)
- app/Console/Commands/SnapshotDesempenhoScores.php, ConsolidarMesDesempenho.php (congela ou não?)
- app/Models/DesempenhoScoreSnapshot.php
- app/Services/AdmanService.php (fetchPerformance/.diff nativo — o que a API retorna)
- Referência: .planning/debug/resolved/audit-margem-luiz-ana.md (cutover ML → sem margem local)

## Current Focus

- **hypothesis:** CONFIRMADA (revisada) — a instabilidade não vem do valor da Adman "assentando" ao longo do tempo (testado e refutado agora), e sim de FALHAS TRANSITÓRIAS na chamada ao vivo (.diff nativo / percentageMargin) por empresa — rate-limit 429 comprovado em massa nos logs da VPS — combinadas com a ausência de rede de segurança determinística pras empresas ML-driven no momento do congelamento do snapshot mensal. O gate atual SEMPRE prioriza a leitura ao vivo (.diff nativo) sobre o calculated_fallback local quando `comparison_mode='previous_equal_length_window'`, mesmo quando dado local confiável já existe (margem local ML-driven foi restaurada por `adman:sync-margem` desde 2026-07-10).
- **next_action:** nenhuma (goal = find_root_cause_only; fix não aplicado nesta rodada). Ver Resolution para as 3 direções avaliadas.
- **test:** concluído
- **expecting:** concluído

## Evidence

- timestamp: 2026-07-23T15:05:00-03:00
  checked: `SnapshotDesempenhoScores.php` (grava `mes_referencia=NULL`, diário 13:30) vs `ConsolidarMesDesempenho.php` (grava `mes_referencia=YYYY-MM-01`, congelamento mensal) + `routes/console.php` linhas 225-240.
  found: `desempenho:consolidar-mes` roda `lastDayOfMonth('14:00')` — ou seja, para congelar a competência de JUNHO ele precisa rodar no ÚLTIMO DIA de JULHO (31/07 14h BRT), não no dia 1. Hoje é 23/07 — o congelamento oficial de junho AINDA NÃO ACONTECEU pelo cron.
  implication: qualquer linha `desempenho_score_snapshots` com `mes_referencia=2026-06-01` existente HOJE não veio do cron oficial — foi gerada manualmente/fora de banda.

- timestamp: 2026-07-23T15:07:00-03:00
  checked: `DesempenhoScoreSnapshot::mensal()->whereDate('mes_referencia','2026-06-01')` na VPS (tinker)
  found: 11 rows já existem pra junho/2026, incluindo user_id=3 (Luiz). `created_at=updated_at=2026-07-22 20:59:54` (ONTEM, fora do schedule oficial — bateu com o checkpoint da Fase 109 mencionado no trigger).
  implication: existe sim um snapshot "congelado" pra junho, mas foi criado por uma execução manual/fora-de-banda, não pelo cron. Ele SERÁ SOBRESCRITO pelo cron oficial em 31/07 14h (mesma chave `updateOrCreate([user_id, mes_referencia])`).

- timestamp: 2026-07-23T15:08:00-03:00
  checked: breakdown_json do snapshot mensal de Luiz (user 3, mes_referencia=2026-06-01)
  found: `var_margem_pct=6.79`, `nota_final=4.97`, `pontos_componentes.margem=5`, `periodo.comparison_mode=previous_equal_length_window`, `bonus.competence_month=2026-06`, `bonus.payment_month=2026-07`. O valor 6.79 é muito próximo do 1º valor relatado no trigger (+6.83, medido por volta do mesmo horário) — consistente com ter sido o snapshot que gerou aquela leitura.
  implication: o número que HOJE alimentaria pagamento (se consultado via `RelatorioBonificacaoController`/`PerformanceController`) é este 6.79% congelado — não os valores voláteis medidos depois (-3.25/+8.63), que vieram de recomputes live fora do fluxo de snapshot (via `cache:clear`+recompute direto, conforme reprodução).

- timestamp: 2026-07-23T15:10:00-03:00
  checked: `PerformanceController::index()` linhas 123-201 e `RelatorioBonificacaoController::montarLinhas()` linhas 98-116.
  found: ambos são "snapshot-first" — em mês FECHADO, usam `DesempenhoScoreSnapshot::mensal()->whereDate('mes_referencia', $mes)->breakdown_json` quando existe e tem chave `componentes`; só caem para `computeCached()` (live) quando o snapshot não existe ou está malformado.
  implication: RESPOSTA À PERGUNTA CRÍTICA — HOJE (23/07), o bônus de junho de Luiz já está "congelado" por um snapshot pré-existente (não-oficial), então a volatilidade ao vivo NÃO está afetando o número exibido/pagável agora. MAS esse congelamento é FRÁGIL: (1) foi capturado de uma ÚNICA passada ao vivo, sem retry/reconciliação de qualidade; (2) será SOBRESCRITO pelo cron oficial em 31/07 14h BRT por outra passada ao vivo igualmente sujeita à mesma instabilidade — o número que efetivamente paga o bônus de julho (referente a junho) ainda não está definitivo.

- timestamp: 2026-07-23T15:21:00-03:00
  checked: `AdmanMetricDiffService::compute()` chamado 3× seguidas (segundos de intervalo) pra 3 empresas ML-driven de Luiz (150 LUCCAUTO, 216 LYAMDECOR, 217 DROSSI), 1º teste só invalidando a chave de cache EXTERNA do diff service.
  found: valores 100% estáveis nas 3 chamadas (69.3%/68.52%/41.11% respectivamente) — MAS teste inválido: não limpei a chave de cache INTERNA (`adman:account_metrics_detailed:...`, TTL 24h) de `AdmanService::fetchAccountMetricsDetailedCached()`, que é a fonte real de `percentageMargin`/`liquidMargin`. As 3 chamadas provavelmente leram o MESMO valor cacheado internamente.
  implication: teste refeito com `forceRefresh=true` — ver evidência seguinte.

- timestamp: 2026-07-23T15:21:30-03:00 a 15:21:51-03:00
  checked: `AdmanService::fetchAccountMetricsDetailedCached(..., forceRefresh: true)` chamado 3× seguidas (live de verdade, sem NENHUM cache) pras mesmas 3 empresas.
  found: valores IDÊNTICOS nas 3 chamadas por empresa (LUCCAUTO: percentageMargin.value=18.79/diff=69.19 nas 3x; LYAMDECOR: 39.41/68.52 nas 3x; DROSSI: 23.04/41.11 nas 3x).
  implication: REFUTA a hipótese "Adman ainda está assentando/recalculando CMV de junho ao longo do tempo" (H2 original) — pelo menos no instante atual (23/07, ~15h), o valor de margem retornado pela Adman por empresa é ESTÁVEL entre chamadas segundos-apart. A API não está "flutuando" o dado em si.

- timestamp: 2026-07-23T15:23:00-03:00 a 15:26:22-03:00
  checked: `computeVarMargem` reimplementado manualmente — 26 empresas elegíveis de Luiz, 2 passadas COMPLETAS (~3min de intervalo), cache TOTALMENTE bypassado (chave externa do diff service + chave interna do AdmanService, ambas `Cache::forget` por empresa antes de cada chamada).
  found: passada 1 (15:23:13): n_com_margem_real=25, n_missing=1 (empresa 392 "Empresa teste", sem dado real), n_partial=4, avg_pct=3.26. Passada 2 (15:26:22): EXATAMENTE os mesmos números — n_com_margem_real=25, mesma empresa faltante, avg_pct=3.26.
  implication: em condições de baixa concorrência de tráfego Adman (agora), o agregado é 100% estável entre passadas ~3min apart — reforça que a API não flutua o VALOR em si; a variação reportada no trigger precisa de outra explicação (falha transitória, não drift de dado).

- timestamp: 2026-07-23T15:27:00-03:00
  checked: `storage/logs/laravel.log` na VPS — grep por "rate limit\|429\|AdmanMetricDiff\|Adman/AccountMetricsDetailed"
  found: 30.388 ocorrências históricas de "rate limit (429) após 3 tentativas" no log (RuntimeException lançada por `AdmanService::fetchPerformance` linha 320), 56 SÓ HOJE (23/07) — mas majoritariamente originadas de OUTROS processos concorrentes (`[MLB SyncTodasVendas]`, `[MLB SyncPub]`), não do caminho `AdmanMetricDiffService`. ZERO ocorrências de `[AdmanMetricDiff]` (tag de warning específica desse service) e ZERO de `Adman/AccountMetricsDetailed` (tag de falha do `fetchAccountMetricsDetailedCached`) em TODO o log.
  implication: confirma que a Adman API sofre rate-limit 429 SEVERO e FREQUENTE, mas causado por outros consumidores concorrentes (jobs de sync ML/MLB rodando via queue workers em paralelo) — não pelo próprio `AdmanMetricDiffService`, que aparentemente ainda não foi pego num momento de colisão de tráfego (daí meus 2 passes terem saído limpos). A instabilidade reportada no trigger é CONSISTENTE com esse mecanismo: quando uma passada de `computeVarMargem()` (até 26 chamadas HTTP sequenciais por profissional) coincide no tempo com uma rajada de outro job batendo na MESMA conta/API-key Adman, uma ou mais empresas falham transitoriamente (fail-open → `null`), caem fora de `n_com_margem_real` NAQUELA passada, e a média muda — sem que o valor "real" de nenhuma empresa individual tenha mudado. É um problema de DISPONIBILIDADE/CONCORRÊNCIA da chamada, não de o dado em si ser instável.

- timestamp: 2026-07-23T15:30:00-03:00
  checked: cobertura de `contribution_margin` local (`adman_metrics`) em junho/2026 pras 26 empresas elegíveis de Luiz + `SyncAdmanMargem.php` (comando `adman:sync-margem`, criado 2026-07-10 pelo audit `audit-margem-luiz-ana`).
  found: 23/26 empresas são ML-driven (`ml_tokens.status='active'`); TODAS as 23 já têm ALGUM dia com `contribution_margin IS NOT NULL` em junho (provavelmente cobertura parcial: maio pré-cutover + backfill/sync diário desde 10/07). O comando roda diário às 11:20 BRT (`--dias=1`, só "ontem") — backfill retroativo completo depende de execução manual com `--dias=N`, não confirmado se cobriu 100% do gap 01/06-09/07.
  implication: a "rede de segurança" (calculated_fallback local) para empresas ML-driven JÁ EXISTE DE NOVO desde 10/07 (contrariando a suposição original do trigger de que seria sempre NULL) — mas o gate atual em `AdmanMetricDiffService::resolveField()` NUNCA a usa quando `comparison_mode='previous_equal_length_window'` e a leitura ao vivo retorna não-nulo, mesmo que essa leitura ao vivo tenha vindo de uma chamada que colidiu com rate-limit de outro processo. O fallback determinístico e mais barato (soma de dado já sincronizado, sem HTTP) está disponível mas não é considerado.

## Eliminated

- **Hipótese 2** ("Adman ainda está assentando/recalculando CMV de junho ao longo do tempo" — o valor em si muda entre consultas): ELIMINADA para o momento testado — 2 testes diretos (per-empresa com forceRefresh; agregado completo com 2 passadas ~3min apart) mostraram valores 100% idênticos. Não descarta que isso possa ter sido verdade nos primeiros dias após o fechamento do mês (mais próximo de 30/06-02/07), mas não é o mecanismo ativo agora (23/07, ~23 dias depois).
- **Hipótese "janela mal-alinhada" (baseline maio vs current junho)**: NÃO re-testada empiricamente por escopo/tempo, mas já é endereçada arquiteturalmente pelo gate ADM-02 (`isJanelaIgual`) documentado no docblock do `AdmanMetricDiffService` — a Fase 101 already comprovou (research) que o baseline nativo da Adman só é semanticamente igual ao do resolver quando `comparison_mode='previous_equal_length_window'`, exatamente a condição do gate. Risco residual baixo, não é a causa principal da instabilidade observada.

## Resolution

**root_cause:**
A instabilidade de `var_margem_pct` no modo bônus/fechado NÃO é causada por o valor da Adman "flutuar" ao longo do tempo (testado e refutado agora) — é causada pela combinação de:

1. `AdmanMetricDiffService::resolveField()` SEMPRE prioriza a leitura AO VIVO (.diff nativo / `percentageMargin`) sobre o `calculated_fallback` local quando `comparison_mode='previous_equal_length_window'` (o caso do bônus/mês fechado) e a chamada retorna algo não-nulo — MESMO quando dado local determinístico e já sincronizado está disponível (que existe de novo para empresas ML-driven desde a correção `adman:sync-margem` de 2026-07-10).
2. A chamada ao vivo depende de até 2 HTTP requests síncronos por empresa (`fetchPerformance` + `fetchAccountMetricsDetailedCached`), executados SEQUENCIALMENTE para todas as ~24-26 empresas da carteira de um profissional dentro de `computeVarMargem()`. A API da Adman sofre rate-limit 429 comprovadamente FREQUENTE (30k+ ocorrências históricas no log, 56 só hoje) — majoritariamente por CONCORRÊNCIA com outros jobs (sync ML/MLB) batendo na mesma conta/API-key, não por limite inerente a esta chamada específica.
3. Quando uma chamada individual falha transitoriamente (rate-limit/timeout), o código é fail-open (`\Throwable` capturado, retorna `null`) — a empresa cai fora de `n_com_margem_real` NAQUELA passada específica. Como `computeVarMargem()` é uma média simples não-ponderada sobre um portfólio pequeno (n≈25) com diffs individuais que variam muito entre empresas (medi +41% a +69% em 3 empresas de Luiz agora mesmo), perder ou ganhar 1-3 empresas entre passadas é suficiente para mover a média agregada em dezenas de pontos percentuais — batendo com o padrão relatado (+6,83 → −3,25 → +8,63).
4. O congelamento mensal (`ConsolidarMesDesempenho`) não tem NENHUM mecanismo de retry/reconciliação/qualidade mínima antes de persistir — grava o resultado de uma ÚNICA passada `compute()` ao vivo, sujeita ao mesmo risco de colisão de rate-limit no exato instante em que roda.

**PERGUNTA CRÍTICA — resposta:** HOJE (23/07), o bônus de junho JÁ está coberto por um snapshot mensal (`mes_referencia=2026-06-01`) para os 11 profissionais elegíveis, incluindo Luiz — mas esse snapshot foi criado FORA DO SCHEDULE OFICIAL (`created_at=2026-07-22 20:59:54`, manual/checkpoint Fase 109), não pelo cron `desempenho:consolidar-mes` (agendado só para 31/07 14h BRT — `lastDayOfMonth`). `PerformanceController`/`RelatorioBonificacaoController` são snapshot-first, então a volatilidade ao vivo medida hoje NÃO está afetando o número atualmente exibido/pagável (Luiz: var_margem_pct=6,79%, nota_final=4,97, já congelado). PORÉM esse congelamento é FRÁGIL e PROVISÓRIO: (a) foi uma única amostra ao vivo sem retry/validação; (b) será SOBRESCRITO pelo cron oficial em 31/07 (`updateOrCreate` na mesma chave user+mês), cujo resultado — sujeito ao MESMO mecanismo de instabilidade — é que efetivamente vai determinar o valor pago em agosto/julho. **Gravidade: MODERADA-ALTA** — não é "o dashboard ao vivo paga errado a cada view" (cenário mais grave descartado), mas é "o número final que vai pagar o bônus de junho ainda vai ser capturado por uma amostra única e não-reconciliada em 31/07, sujeita a rate-limit de terceiros no exato instante do cron".

**Direções de fix avaliadas (NÃO aplicadas — goal find_root_cause_only):**

**(a) Priorizar `calculated_fallback` local sobre `.diff` nativo quando cobertura local for suficiente** (inverter/condicionar a prioridade atual do gate).
- Prós: elimina a dependência de HTTP síncrono no momento do congelamento pra empresas com dado local bom; determinístico, sem risco de rate-limit; reaproveita os guards já cicatrizados (`margem_dias`/dias-comuns).
- Contras: cobertura local de ML-driven só existe de novo desde 10/07 — precisa confirmar (não confirmado nesta rodada) que o backfill retroativo cobriu 100% de junho, senão troca uma instabilidade por dado incompleto/enviesado; muda o "diff_source" default documentado como decisão da Fase 101 (ADM-02), exige revisão de decisão arquitetural.

**(b) Retry/reconciliação de qualidade em `ConsolidarMesDesempenho` antes de persistir o snapshot mensal** (ex.: exigir `quality.status='complete'` ou repetir N vezes/aguardar até estabilizar, com alerta se não convergir).
- Prós: ataca o ÚNICO ponto que realmente importa pro pagamento (o instante do freeze), sem mexer na arquitetura de leitura ao vivo já decidida na Fase 101/102; simples de escopar.
- Contras: aumenta o tempo de execução de um job que já é pesado (15-20 users × até 26 empresas, ~512MB de memory_limit já é um guard existente pra isso); não resolve a volatilidade cosmética exibida no dashboard ANTES do freeze (mês em curso / auditorias manuais), só o valor final pago.

**(c) Gate de tolerância/cobertura mínima antes de aceitar `.diff` nativo** (estender o padrão de guard `margem_dias`/dias-comuns, já usado no `calculated_fallback`, para também vetar o `.diff` nativo quando a cobertura de sincronização local da empresa for baixa — sinal indireto de "essa empresa é de risco pra falha ao vivo").
- Prós: reaproveita padrão já testado/battle-tested (fix Luiz 09/07, audit Tomelin 13/07); mudança pequena e localizada.
- Contras: sozinho não resolve nada — só torna o `null` mais frequente/explícito quando a chamada falha; precisa ser combinado com (a) pra ter efeito real (ter PRA ONDE cair quando vetar o `.diff` nativo).

**Recomendação (não decidida, é observação, não aplicar sem sessão de fix dedicada):** (a)+(c) combinadas removem a dependência estrutural de HTTP síncrono bem-sucedido no momento do freeze; (b) é uma rede de segurança barata e complementar independente da escolha entre (a)/(c). Antes de implementar (a), validar empiricamente a completude da cobertura local de junho pras ~23 empresas ML-driven de Luiz (não feito nesta rodada por escopo).

**fix:** não aplicado (goal = find_root_cause_only, conforme solicitado).

## Ambiente / autorização

- Autorização permanente para VPS/tinker. DB local pode estar corrompido — dados reais estão em produção.
- Local: C:\xampp\php\php.exe artisan. VPS: plink.exe (creds em deploy.sh, root@177.7.53.164, /var/www/ecf_admin). tinker via arquivo base64 (evita escaping).
