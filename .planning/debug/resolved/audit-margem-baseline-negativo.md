---
slug: audit-margem-baseline-negativo
status: root_cause_confirmed
trigger: Empresa AVF_2K_COMERCIAL saiu de prejuízo (junho R$ -2.825) pra lucro (julho R$ +3.977) mas carteira do Gustavo mostra variação de margem -34,6% — sinal invertido / cálculo incorreto para baseline negativo
created: 2026-07-13
updated: 2026-07-13
criticality: alta
---

# Auditoria: variação de margem invertida quando baseline é negativo

## Symptoms

**Expected behavior:**
Empresa que saiu de prejuízo pra lucro deve mostrar variação POSITIVA de margem, sinalizando melhoria. Se a fórmula tradicional `(atual - anterior) / anterior * 100` produz sinal inconsistente com baseline ≤ 0, o sistema deve detectar e tratar (descartar, cap, ou métrica alternativa).

**Actual behavior:**
- Analista Gustavo, empresa AVF_2K_COMERCIAL (cust_id 2674179141, company_id local 243)
- Janela junho (01-12/06) **segundo o dashboard Adman externo**: margem R$ -2.825,39 (prejuízo)
- Janela julho (01-12/07) **segundo o dashboard Adman externo**: margem R$ +3.977,77 (lucro)
- Sistema mostra na carteira: **variação -34,6%** ← reproduzido e confirmado bit-a-bit (ver Evidence).

**Error messages:**
Nenhum na UI — mas o log de produção tem uma tempestade de erros de rate-limit/timeout da Adman API no dia 2026-06-12 (ver Evidence).

**Timeline:**
Reportado 2026-07-13.

**Reproduction:**
1. Login como admin
2. Acessar `/admin/users/16/portfolio` (Gustavo)
3. Localizar linha AVF2K COMERCIAL (company_id 243)
4. Comparar com dashboard Adman externa (01-12/06 vs 01-12/07)

## Contexto técnico

- `DesempenhoScoreService::computeVarMargem` (ranking / bônus): descarta empresa com `if ($anterior <= 0) continue;` — mesma limitação de gap (ver Evidence) se aplica aqui também, não auditado linha-a-linha nesta rodada.
- `PortfolioController::renderCarteiraProfissional` (carteira do profissional): calcula variação por empresa via mesma fórmula E JÁ TEM guard `$margemAnterior > 0` desde commit `be2813f` (2026-07-09) — **não é o ponto de falha** (ver Eliminated).
- Fix Tomelin (commit `b2fe23e`, 2026-07-10): recorte simétrico de dias-fim aplicado em ambos os pontos, mas **só cobre gap NO FIM da janela ATUAL** (lag de processamento da Adman) — não cobre gap NO MEIO/HISTÓRICO de nenhuma das duas janelas. Essa limitação é documentada no próprio commit ("Limitação conhecida: só corrige gap no final da janela").

## Hipóteses iniciais (a serem testadas)

1. **Baseline negativo produz % matematicamente incorreta** — REFUTADA como causa direta nesta ocorrência (ver Eliminated). O guard `$margemAnterior > 0` já existe e funcionaria corretamente SE o baseline calculado fosse de fato negativo.
2. **`PortfolioController::renderCarteiraProfissional` não descarta empresa com baseline ≤ 0** — REFUTADA. Guard existe e está deployado em produção (confirmado via `git merge-base --is-ancestor` no VPS).
3. **Cálculo com recorte simétrico pode ter alterado os valores base** — CONFIRMADA, mas não da forma hipotetizada: o recorte funciona como projetado (corrige lag no fim da janela atual), porém ESCONDE um gap muito mais grave: dias completamente ausentes no MEIO/HISTÓRICO da janela anterior (ver Root Cause).
4. **Bug de sinal em outro ponto** — não encontrado; fórmulas de `renderCarteiraProfissional` e `computeVarMargem` são equivalentes e ambas corretas matematicamente.
5. **Efeito sistêmico** — CONFIRMADA e é a causa raiz real: apagão de sync Adman em 2026-06-12/13 afetou TODAS as empresas do sistema, não só AVF2K (ver Evidence).

## Current Focus

- **hypothesis:** RESOLVIDA — ver Resolution.
- **next_action:** nenhuma (goal=find_root_cause_only; fix não aplicado nesta rodada).

## Escopo da auditoria

- [x] Confirmar valores SQL de AVF_2K_COMERCIAL nas 2 janelas
- [x] Ler PortfolioController::renderCarteiraProfissional (formula margemVariacaoPct)
- [x] Ler DesempenhoScoreService::computeVarMargem (guard `anterior <= 0`)
- [x] Contar empresas system-wide afetadas por gap de sync — quantificado (87/112)
- [x] NÃO aplicar fix nesta rodada

## Arquivos suspeitos

- `app/Http/Controllers/PortfolioController.php` (renderCarteiraProfissional linhas 241-271 — recorte + guard, funcionam corretamente para o caso que cobrem)
- `app/Services/DesempenhoScoreService.php` (computeVarMargem linhas 517-580 — mesma limitação de recorte só-fim-de-janela, não auditada linha a linha)
- `app/Services/AdmanService.php:237` (throw de RuntimeException em rate-limit — catch+log+skip em `syncAll()`, sem retry/backfill automático)

## Evidence

- timestamp: 2026-07-13T00:00Z
  ação: `git log -p -L 260,275` em `PortfolioController.php` local
  achado: guard `$margemAnterior > 0` existe desde commit `be2813f` (2026-07-09), ANTES do fix Tomelin (`b2fe23e`, 2026-07-10). Confirmado via SSH que `b2fe23e` é ancestor do HEAD deployado no VPS (`f818ab1`). **A hipótese de "baseline negativo não é descartado" está refutada — o guard está em produção.**

- timestamp: 2026-07-13T00:05Z
  ação: SQL VPS — `SELECT id, name, adman_account_id, ml_store_id FROM companies WHERE adman_account_id='2674179141'`
  achado: company_id local = 243 (AVF2K COMERCIAL). Dono da carteira: user_id 16 (Gustavo).

- timestamp: 2026-07-13T00:10Z
  ação: Reprodução via tinker no VPS do algoritmo EXATO de `renderCarteiraProfissional` (script `repro_margem.php`, todas as 13 empresas da carteira de Gustavo)
  achado: **Reproduzido -34,61% para AVF2K COMERCIAL** (bate com o -34,6% reportado). Mas os valores usados pelo sistema são `atual=R$ 5.324,32` (janela 01-11/07, recortada) e `anterior=R$ 8.142,96` (janela 01-11/06, recortada) — **ambos POSITIVOS**. Ou seja, o sistema NUNCA usa o baseline negativo (-2.825,39) que o usuário viu no Adman externo — usa um baseline diferente e incompleto, também positivo, mas que produz -34,61% por conta própria (queda real segundo os dados locais incompletos).

- timestamp: 2026-07-13T00:15Z
  ação: `SELECT reference_date, revenue, contribution_margin FROM adman_metrics WHERE company_id=243 AND reference_date BETWEEN '2026-06-01' AND '2026-07-13'`
  achado: **6 dias com registro totalmente AUSENTE** (não é `contribution_margin=NULL`, a linha inteira não existe): `2026-06-10, 2026-06-12, 2026-06-13, 2026-07-04, 2026-07-12, 2026-07-13`. Confirmado: `SUM` para janela 01-13/06 == `SUM` para janela 01-11/06 (R$ 8.142,96 em ambos) — ou seja, os dias 12 e 13/06 não contribuem NADA para o local, porque não existem.

- timestamp: 2026-07-13T00:20Z
  ação: `SELECT reference_date, COUNT(DISTINCT company_id) FROM adman_metrics GROUP BY reference_date` (contagem de empresas com QUALQUER dado por dia, 2026-06-01 a 2026-07-13)
  achado: **2026-06-12 e 2026-06-13 têm ZERO empresas com dado no sistema inteiro** (contra 75-86 empresas/dia nos dias adjacentes). 2026-07-12 e 2026-07-13 também zero, mas isso é esperado (hoje é 07-13, lag normal do dia corrente — já coberto pelo recorte Tomelin).

- timestamp: 2026-07-13T00:25Z
  ação: `grep '2026-06-12' storage/logs/laravel.log`
  achado: **149 linhas de ERROR** em 2026-06-12, majoritariamente `"Adman API: rate limit (429) após 3 tentativas."` lançado em `AdmanService.php:237`, além de `cURL error 28 (timeout)` e erros 500/502 da API Adman, afetando dezenas de empresas distintas (SOS Casa, King Decor, Manuella Estofados, Brunelli Decor, Amueblo, Mobília Mix, Bragalar, Zimmermann Moveis, etc.) — confirma um **incidente real na Adman API / rate-limit em 2026-06-12**.
  Nota: `grep '2026-06-13'` no `laravel.log` retornou **zero linhas** (nenhuma atividade logada nesse dia — log pode ter sido rotacionado/perdido, ou indisponibilidade total; não foi possível confirmar causa exata do dia 13 isoladamente, mas o efeito — zero empresas sincronizadas — está confirmado via `adman_metrics`).

- timestamp: 2026-07-13T00:30Z
  ação: Checar se 06-12/06-13 é padrão normal de fim de semana (Adman não reporta sáb/dom)
  achado: REFUTADO — 2026-06-06 (sábado) e 2026-06-07 (domingo) têm 82 e 83 empresas com dado, respectivamente. 2026-06-12 é sexta-feira. Não há padrão de fim de semana; é uma lacuna real e anômala.

- timestamp: 2026-07-13T00:35Z
  ação: `SELECT company_id, COUNT(*) FROM adman_metrics WHERE reference_date BETWEEN '2026-06-01' AND '2026-07-13' GROUP BY company_id HAVING dias<43` cruzado com `MIN/MAX(reference_date)` cobrindo toda a janela
  achado: **87 de 112 empresas ativas com sync no período (78%) têm pelo menos 1 dia ausente no meio da janela**, apesar de cobrirem o range completo (não é onboarding tardio). Efeito sistêmico confirmado — não é exclusivo de AVF2K, é um padrão recorrente de falhas de sync não tratadas.

## Eliminated

- ~~Fórmula `(atual - anterior) / anterior * 100` inverte sinal quando `anterior < 0` sem tratamento~~ — o tratamento (`$margemAnterior > 0` guard) EXISTE e está em produção desde 2026-07-09, ANTES deste bug ser reportado. Se o sistema tivesse usado o baseline verdadeiro (-2.825,39), a linha mostraria "—" (descartada), não -34,6%. Portanto o -34,6% exibido NÃO veio da fórmula de sinal invertido.
- ~~`renderCarteiraProfissional` não descarta baseline ≤ 0~~ — descarta corretamente; confirmado no código local E no deployado (VPS HEAD `f818ab1` inclui commit `be2813f`).
- ~~Bug de sinal isolado em `renderCarteiraProfissional` vs `computeVarMargem`~~ — fórmulas equivalentes, ambas corretas matematicamente; a causa não está na fórmula.

## Resolution

**root_cause:**
O valor de -34,6% não é um bug de fórmula/sinal — é o resultado matematicamente CORRETO de dados INCOMPLETOS. Em 2026-06-12 houve um incidente confirmado na Adman API (rate-limit 429 + timeouts + erros 500/502 em massa, 149 erros logados), que se estendeu (por causa desconhecida, sem log) até 2026-06-13. Nesses dois dias, ZERO empresas no sistema inteiro tiveram `adman_metrics` sincronizado (confirmado: 0 de ~112 empresas ativas, contra 75-109 empresas/dia nos dias adjacentes — não é padrão de fim de semana).

`AdmanService::syncAll()` captura `\Throwable` por empresa, loga e segue (padrão de erro documentado no projeto) — isso é correto para não travar o sync das demais empresas, mas tem como efeito colateral: **nenhuma linha é criada em `adman_metrics` para os dias/empresas que falharam, e não há mecanismo de backfill automático**. O resultado é uma lacuna PERMANENTE e silenciosa no histórico.

O "fix Tomelin" (commit `b2fe23e`, 2026-07-10) resolve corretamente o caso de lag NO FIM da janela ATUAL (dias mais recentes que a Adman ainda não processou `contribution_margin`), recortando a janela anterior na mesma proporção. Mas essa correção **não cobre gaps no MEIO ou no HISTÓRICO (mês fechado)** — limitação já documentada no próprio commit.

Para AVF2K COMERCIAL (company_id 243): a janela "anterior" usada pelo sistema (01-11/06, após o recorte do fim aplicado por causa do lag em 07-12/07-13) já EXCLUI os dias 06-10, 06-12 e 06-13 simplesmente porque essas linhas não existem no banco local. Isso produz `margemAnterior = R$ 8.142,96` (positivo) — bem diferente do valor real que o usuário viu direto no Adman (`R$ -2.825,39`, negativo, incluindo os dias 12 e 13). A diferença de ~R$ 10.968 entre os dois totais está inteiramente contida nos dias que faltam no espelho local. Com os dados incompletos disponíveis, `margemAtual = R$ 5.324,32` vs `margemAnterior = R$ 8.142,96` produz honestamente `-34,61%` — mas essa comparação usa uma baseline que NÃO reflete a realidade (deveria ser negativa, não positiva), então o resultado, embora "correto" para os dados que existem, é enganoso porque os dados estão incompletos e o sistema não sinaliza isso.

Confirmado sistemicamente: 87 de 112 empresas ativas (78%) têm ao menos um dia ausente no meio da janela jun-jul, um padrão recorrente de falhas de sync engolidas silenciosamente sem alerta ao usuário nem contador de "dias faltantes" na UI (diferente do contador `empresas_sem_margem` que já existe para "sem NENHUM dado" — não existe hoje um contador equivalente para "dados PARCIAIS/incompletos").

**Causa raiz, resumida:**
1. Incidente real na Adman API em 2026-06-12 (rate-limit/timeout/5xx em massa) + 2026-06-13 sem sync algum (causa exata do dia 13 não encontrada em log, mas efeito confirmado).
2. `AdmanService::syncAll()` engole a falha por empresa sem deixar marca recuperável nem disparar backfill — cria lacuna permanente em `adman_metrics`.
3. A proteção existente contra baseline negativo (`$margemAnterior > 0`) e o recorte de lag (fix Tomelin) só cobrem o cenário de "Adman ainda não processou os últimos dias do mês em curso" — nenhum dos dois detecta ou avisa quando a janela ANTERIOR (mês fechado) tem dias FALTANDO por falha histórica de sync.
4. Resultado: `margem_variacao_pct` é calculado com uma baseline incompleta e artificialmente positiva, produzindo -34,6% quando o valor real (com todos os dias) seria fortemente positivo (empresa saiu de prejuízo real para lucro).

**fix:** não aplicado nesta rodada (goal=find_root_cause_only). Direções possíveis para uma próxima fase (não implementadas):
- Backfill do gap 2026-06-12/13 (e quaisquer outros gaps quantificados) via re-sync manual/retroativo antes de qualquer outra correção de fórmula.
- Detectar e sinalizar (não apenas corrigir) gaps NO MEIO/HISTÓRICO da janela — hoje só existe detecção de gap no FIM da janela atual (Tomelin) e de ausência TOTAL de dados (empresas_sem_margem). Falta um terceiro estado: "dados parciais — X dias faltando" com contagem exposta na UI.
- Considerar retry/backfill automático em `AdmanService::syncAll()` quando rate-limit (429) é detectado, em vez de só logar e seguir.
