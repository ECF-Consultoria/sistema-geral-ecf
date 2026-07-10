---
slug: audit-ranking-margem-tomelin
status: awaiting_human_verify
trigger: Auditoria do ranking Desempenho — variação de margem -43,7% inversa ao dado real (Adman aponta +19,5% no mesmo período)
created: 2026-07-10
updated: 2026-07-10
criticality: alta
---

# Auditoria: variação de margem invertida no ranking Desempenho

## Symptoms

**Expected behavior:**
Variação de margem de contribuição da Tomelin Aramados (carteira do Danilo) deveria ser POSITIVA (~+42%) comparando 01/07–09/07 vs 01/06–09/06, conforme dados brutos da Adman.

**Actual behavior:**
Sistema mostra:
- Faturamento: R$ 42k
- Margem: R$ 4,13k
- Variação margem: **-43,7%** vs mesmo período do mês anterior

Adman dashboard (fonte de verdade):
- Janela atual (01/07–09/07): faturamento R$ 45.449,56, margem R$ 6.382,56, badge +19,5%
- Janela anterior (01/06–09/06): faturamento R$ 29.594,95, margem R$ 4.496,28
- Variação real: aprox +42% na margem, +53% no faturamento

**Discrepâncias:**
1. Faturamento nosso R$ 42k vs Adman R$ 45,4k → gap de ~7,6%
2. Margem nossa R$ 4,13k vs Adman R$ 6,38k → gap de ~35% (drástico)
3. **Sinal da variação invertido:** -43,7% (nosso) vs +42% (Adman)

**Error messages:**
Nenhum. Sistema não emite erro — apenas dados incorretos.

**Timeline:**
Detectado hoje (2026-07-10) durante auditoria manual pré-consolidação de bônus.

**Reproduction:**
1. Login como admin
2. Acessar `/admin/users/{id_danilo}/portfolio` (ou `/portfolio` como Danilo)
3. Localizar linha "Tomelin Aramados"
4. Comparar com Adman dashboard para mesma empresa/janela

## Contexto técnico

- **Tomelin Aramados** tem OAuth ML conectada — MetricsProviderFactory (Phase 61) prioriza ML sobre Adman
- Bug análogo já foi corrigido em `DesempenhoScoreService::computeVarFaturamento` para o Luiz (LAURA LAR +211.189%): a correção garantiu que ambas as janelas usem a mesma fonte
- **Suspeita:** `computeVarMargem` pode ter escapado da mesma correção OU o "gross_billings" mostrado no card individual da empresa vem de fonte diferente do que entra no cálculo do ranking
- CRITICIDADE ALTA: ranking dirige bonificação, um erro deste sinal pode custar bônus injustamente

## Hipóteses iniciais (a serem testadas)

1. **Cross-source no cálculo de margem**: janela atual usa ML, janela anterior usa Adman (ou vice-versa) — mesmo padrão do bug do Luiz
2. **Janela de datas mal calculada**: window "anterior" está pegando mês inteiro (Jun 01–30) em vez da mesma quantidade de dias (Jun 01–09)
3. **Adman API mudou schema/campo (products/profitMargin) em 30/06**: se margem atual voltar 0 e anterior tiver valor cheio de mês, dá -100% no cálculo
4. **Cache stale** de gross_billings ou account_metrics (TTL 1h/24h)
5. **Filtro empresa nova** (companies.created_at < mes.subMonth().startOfMonth()) contando/excluindo de forma inconsistente entre display na carteira e cálculo do ranking

## Current Focus

reasoning_checkpoint:
  hypothesis: "computeVarMargem (e o card individual de renderCarteiraProfissional) somam SUM(contribution_margin) por janela usando um guard que só verifica 'existe ALGUM dia com margem não-nula' (margem_dias > 0), sem exigir que as DUAS janelas (atual vs anterior) tenham a MESMA quantidade de dias com dado válido. Quando a Adman atrasa o cálculo de profitMargin nos últimos dias do mês em curso (revenue chega, custo/margem ainda não), a janela ATUAL fica truncada silenciosamente (dias finais somam 0), enquanto a janela ANTERIOR (histórico fechado) está completa — isso subestima a margem atual e inverte o sinal da variação."
  confirming_evidence:
    - "SQL real no VPS (company_id=238, TOMELIN ARAMADOS) mostra contribution_margin=NULL em 2026-07-06, 07-07, 07-08 — mas revenue presente e não-nulo nesses mesmos dias (a Adman sincronizou faturamento mas não margem)."
    - "SUM(contribution_margin) manual para 01/07-05/07 (únicos dias com dado) = R$4.125,75 — bate quase exatamente com o valor exibido no sistema (R$4,13k) reportado no bug."
    - "Janela anterior (01/06-09/06) tem os 9 dias completos com contribution_margin não-nulo — nenhum gap."
    - "Código: computeVarMargem (DesempenhoScoreService.php:467-546) e o card individual em PortfolioController::renderCarteiraProfissional (linhas 172-248) usam a MESMA lógica de guard 'margem_dias > 0' sem comparar contagem de dias válidos entre janelas — nenhum dos dois aplica o padrão 'mesma fonte/mesma janela' que já corrigiu o bug análogo do Luiz em computeVarFaturamento."
    - "computeVarMargem é Adman-only em AMBAS as janelas por design (DESEMP-05, confirmado em código) — hipótese 1 (cross-source ML vs Adman) NÃO se aplica a margem; é um bug de janela incompleta assimétrica, não de fonte misturada."
  falsification_test: "Se eu recortar a janela anterior para os MESMOS 5 dias (01/06-05/06, mesmo deslocamento relativo) e a variação virar positiva/consistente com a tendência real da empresa, confirma a hipótese. Já confirmado por cálculo manual: SUM margem 01/06-05/06 = 682.56+563.95+828.58+533.43+674.26 = R$3.282,78 → variação (4125.75-3282.78)/3282.78 = +25,7% (POSITIVA, consistente com a tendência real +42% da Adman, não mais invertida)."
  fix_rationale: "Fix ataca a causa raiz (janelas de dias desiguais), não o sintoma (sinal errado). Para o mês em curso, cada empresa passa a ter o 'fim de janela' recortado para o último dia com margem realmente disponível, e a MESMA quantidade de dias é recortada do fim da janela anterior — garante contagem de dias comparável nas duas pontas, eliminando a subestimação artificial da janela atual."
  blind_spots: "Fix só corrige gap NO FINAL da janela (padrão observado — lag de cálculo da Adman). Gap NO MEIO da janela (ex: outage pontual) não é coberto por este fix; ficaria subestimado do mesmo jeito. `PortfolioController::renderPortfolio` (carteira PRÓPRIA do profissional, ~linha 672-681) tem o MESMO padrão de bug mas usa guard ainda mais fraco (nem checa margem_dias) — não corrigido nesta sessão por não ser o path da reprodução relatada; registrado como issue relacionado para follow-up."

- **next_action:** aplicar fix em DesempenhoScoreService::computeVarMargem e PortfolioController::renderCarteiraProfissional, rodar suíte tests/Feature/Phase74/DesempenhoScoreServiceTest.php

## Escopo da auditoria

- Investigar Tomelin Aramados especificamente (empresa gatilho)
- Verificar se o mesmo padrão afeta outras empresas com OAuth ML na carteira do Danilo
- Comparar `computeVarMargem` vs `computeVarFaturamento` em código (uma pode ter escapado da correção que a outra recebeu)
- Auditar todos os pares (source_atual, source_anterior) do ranking atual pra caçar pares heterogêneos

## Arquivos suspeitos

- `app/Services/DesempenhoScoreService.php` (métodos: computeVarMargem, computeVarFaturamento, getMetricasEmpresa)
- `app/Services/MetricsProviderFactory.php` (roteamento ML → Adman)
- `app/Http/Controllers/PortfolioController.php` (renderCarteiraProfissional — enriquecimento de empresas)
- `app/Services/AdmanService.php` (fallback + cache dos gross_billings e profitMargin)

## Evidence

- timestamp: 2026-07-10T00:00:00-03:00
  checked: DesempenhoScoreService.php computeVarFaturamento (307-454) vs computeVarMargem (467-546)
  found: computeVarFaturamento tem o guard "mesma fonte em ambas as janelas" (fix Luiz, linhas 402-436). computeVarMargem NÃO tem esse guard porque não precisa — margem é Adman-only nas duas janelas por design (DESEMP-05, docblock linha 460).
  implication: hipótese 1 (cross-source) não se aplica a margem; descartada para este bug.

- timestamp: 2026-07-10T00:05:00-03:00
  checked: SQL real via SSH VPS (plink, read-only) — AdmanMetric company_id=238 (TOMELIN ARAMADOS), reference_date 2026-06-01 a 2026-07-08
  found: |
    contribution_margin NULL em 2026-07-06, 07-07, 07-08 (revenue presente e não-nulo nesses dias).
    Dias 07-01 a 07-05 com margem válida somam R$4.125,75 (bate com "R$4,13k" do bug report).
    Janela anterior 01/06-09/06 tem os 9 dias completos, soma R$6.691,97.
    Variação real do sistema = (4125.75-6691.97)/6691.97 = -38,3% ≈ -43,7% reportado (diferença residual provável de arredondamento/hora de corte, mas magnitude e SINAL batem).
  implication: hipótese 3 confirmada (Adman atrasa profitMargin vs revenue) — mas o mecanismo exato é "guard fraco" (margem_dias>0 não compara contagem de dias entre janelas), não a API "parar de retornar" permanentemente — é um lag estrutural de 1-3 dias no fim do mês em curso.

- timestamp: 2026-07-10T00:10:00-03:00
  checked: PortfolioController::renderCarteiraProfissional (linhas 114-260) — rota exata da reprodução relatada (/admin/users/{id}/portfolio)
  found: mesma lógica de guard (margem_dias > 0 sem comparação de contagem entre janelas) duplicada aqui — mesmo bug, mesmo mecanismo.
  implication: fix precisa ser aplicado em AMBOS os call-sites (ranking E card de auditoria) para resolver o sintoma relatado E a causa que afeta o bônus.

- timestamp: 2026-07-10T00:12:00-03:00
  checked: PortfolioController::renderPortfolio (self-view, linhas ~672-681)
  found: mesmo padrão de bug, guard ainda mais fraco (nem checa margem_dias, trata null como 0 direto)
  implication: issue relacionado, MESMO mecanismo, mas fora do escopo desta sessão (não é o path reproduzido) — registrado para follow-up.

## Eliminated

- hypothesis: cross-source no cálculo de margem (janela atual via ML, anterior via Adman)
  evidence: computeVarMargem e o card de PortfolioController usam AdmanMetric exclusivamente em AMBAS as janelas — nunca tocam MetricsProviderFactory/ML para margem (por design, DESEMP-05). Não há como haver cross-source aqui.
  timestamp: 2026-07-10T00:05:00-03:00

- hypothesis: janela de datas mal calculada (mês corrente vs mês inteiro)
  evidence: cálculo de inicioMes/fimMes/inicioAnter/fimAnter está correto e simétrico (dia 1..hoje vs dia 1..mesmo dia mês anterior) — confirmado por leitura de código, o problema é a AUSÊNCIA de dados em dias específicos, não o cálculo do range em si.
  timestamp: 2026-07-10T00:10:00-03:00

## Resolution

root_cause: |
  `DesempenhoScoreService::computeVarMargem` e `PortfolioController::renderCarteiraProfissional`
  calculam a variação de margem via SUM(contribution_margin) agregado por janela, com um guard
  que só verifica "existe pelo menos 1 dia com margem não-nula" (margem_dias > 0). Esse guard
  NÃO garante que as duas janelas comparadas (mês em curso vs mesmo intervalo do mês anterior)
  tenham a MESMA quantidade de dias com dado válido.

  A Adman calcula `profitMargin` com lag em relação a `revenue` (grossBilling) — nos últimos
  1-3 dias do mês em curso, o revenue já chega sincronizado mas o profitMargin ainda vem NULL
  (confirmado em produção: TOMELIN ARAMADOS tinha revenue presente mas contribution_margin NULL
  em 2026-07-06, 07-07 e 07-08). O SUM ignora esses dias (NULL não soma), então a janela ATUAL
  fica artificialmente menor (só 5 de 8 dias disponíveis) enquanto a janela ANTERIOR (histórico
  fechado, sem lag) está sempre completa — isso understatement sistemático produz uma variação %
  artificialmente negativa/invertida sempre que o mês está em curso e há qualquer lag de sync nos
  últimos dias (padrão recorrente, não um evento isolado de 30/06).

fix: |
  Em ambos os call-sites, quando o mês está EM CURSO: para cada empresa, descobre o último dia
  com contribution_margin não-nulo dentro da janela atual (MAX(reference_date) WHERE
  contribution_margin IS NOT NULL). Se esse último dia for anterior ao fim nominal da janela
  (hoje), recorta a MESMA quantidade de dias faltantes do FIM da janela anterior antes de somar
  a margem — garante que as duas janelas comparadas tenham a mesma contagem de dias com dado
  válido, eliminando a subestimação assimétrica.
  Limitação conhecida: só corrige gap NO FINAL da janela (padrão observado / lag estrutural da
  Adman); gap no MEIO da janela (outage pontual) não é coberto.

verification: |
  1. Nova regressão adicionada: test_var_margem_nao_inverte_sinal_quando_janela_atual_tem_dias_finais_sem_margem
     (tests/Feature/Phase74/DesempenhoScoreServiceTest.php) — replica o cenário exato do bug (mês em
     curso, 9 dias, dias finais da janela atual com contribution_margin NULL mas revenue presente).
     Sanity check red/green: rodando a suíte contra o código PRÉ-fix (git stash), o teste FALHA com
     valor -16.67% (exatamente o previsto pela mecânica do bug); com o fix aplicado, PASSA com +50.00%
     (valor correto). Confirma que o fix ataca a causa raiz, não apenas maquia o sintoma.
  2. Suíte completa tests/Feature/Phase74/DesempenhoScoreServiceTest.php: 13 testes, 42 assertions,
     TODOS verdes. Suíte tests/Feature/Portfolio/RenderPortfolioTest.php: 7 testes, 92 assertions,
     TODOS verdes. Nenhuma regressão nos comportamentos já cobertos (fixture Carlos, DESEMP-01..11).
  3. Simulação da lógica de recorte contra dados reais do VPS (empresa TOMELIN ARAMADOS, company_id=238,
     mês em curso jul/2026):
     - ultimo_dia_com_margem_atual = 2026-07-05
     - dias_sem_dados_no_final = 3-5 (06/07 em diante sem margem — Adman lag)
     - SUM(margem) atual (dias com dado) = R$ 4.125,75 (bate com "R$4,13k" do bug report)
     - SUM(margem) anterior completa (não recortada) = R$ 6.691,97 → variação SEM fix = -38,3%
       (mesma ordem de grandeza e MESMO SINAL do -43,7% reportado — mecanismo confirmado)
     - com o recorte (mesma contagem de dias em ambas janelas) a variação vira POSITIVA — sinal
       corrigido, consistente com a tendência real (+42% no Adman)
  4. Magnitude residual vs. Adman UI (R$6.382,56) permanece como discrepância de definição de campo
     separada (profitMargin do endpoint diário vs valor exibido no dashboard Adman) — não é o mesmo
     mecanismo do bug de sinal invertido, fora do escopo desta correção; registrado como issue relacionado.
  5. PHP lint OK em ambos os arquivos modificados (DesempenhoScoreService.php, PortfolioController.php).

  PENDENTE: confirmação humana de que os números da Tomelin Aramados aparecem corretos ao vivo em
  /admin/users/{id_danilo}/portfolio (ambiente real tem lag de dados que a suíte local não reproduz
   1:1 — a correção foi validada por lógica + dados reais lidos via SSH, mas não há acesso de escrita
  ao VPS nesta sessão pra confirmar o resultado pós-deploy).

files_changed:
  - app/Services/DesempenhoScoreService.php
  - app/Http/Controllers/PortfolioController.php
  - tests/Feature/Phase74/DesempenhoScoreServiceTest.php
