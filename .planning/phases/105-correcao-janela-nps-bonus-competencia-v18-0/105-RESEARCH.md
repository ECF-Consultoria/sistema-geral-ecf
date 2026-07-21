# Phase 105: Correção — janela do NPS no bônus por competência (v18.0) - Research

**Pesquisado em:** 2026-07-21
**Domínio:** `DesempenhoScoreService` (motor de bônus), `MetricPeriodResolver`, `NpsScoreAssignment`/`NpsSurvey`, cron de consolidação mensal
**Confiança:** HIGH (código lido diretamente, sem dependência externa)

## Resumo

O bônus de competência M hoje lê o NPS do **mesmo mês M** (`computeNpsMedio($user, $mes)` filtra `nps_surveys.completed_at` entre `$mes->startOfMonth()` e `$mes->endOfMonth()`, onde `$mes` é a própria competência). A regra confirmada pelo usuário exige que o componente NPS leia as respostas **completadas em M+1**, mantendo o financeiro (`computeVarFaturamento`/`computeVarMargem`) em M. O ponto de injeção mais estreito é a chamada a `computeNpsMedio` dentro de `DesempenhoScoreService::compute()` (linha 332): hoje passa `$mes`, precisaria passar uma "janela de NPS" = `$mes->copy()->addMonthNoOverflow()`.

O achado mais importante não é o código — é o **timing**: o disparo do NPS (`nps:disparar-mensal`) roda diariamente às 09:00 e dispara por empresa no dia do mês correspondente ao aniversário do cadastro (`DAY(companies.created_at)`, com clamp). Isso significa que as respostas de "NPS coletado em M+1" chegam **espalhadas ao longo de todo o mês M+1**, não concentradas no início. O comando `desempenho:consolidar-mes` roda no dia 1 do mês seguinte à competência às 14:00 — ou seja, no dia 1 de M+1, quando a janela de NPS (M+1) **mal começou a ser coletada**. Congelar o snapshot de M nesse momento usando a nova regra captura uma fração ínfima (ou zero) das respostas de M+1, o que é estruturalmente pior que o bug atual. Este é o Pitfall central da fase e a razão pela qual o escopo/timing precisam de decisão explícita do usuário antes de planejar.

**Recomendação primária:** aplicar o deslocamento +1 apenas no cálculo do componente NPS (via um parâmetro de janela separado passado a `computeNpsMedio`, não uma mudança de `$mesReferencia` inteiro), e tratar separadamente — como decisão do usuário, não escolha técnica — (a) se "Em curso" também desloca, e (b) quando o `desempenho:consolidar-mes` de fato pode congelar M com segurança dado que M+1 só fecha a coleta no fim do próprio M+1.

## Mapa de Responsabilidade Arquitetural

| Capacidade | Camada Primária | Camada Secundária | Racional |
|------------|-----------------|--------------------|----------|
| Resolver janela financeira (current/baseline) | `MetricPeriodResolver` (Service) | — | Já centraliza `last_closed_month`/`YYYY-MM`/`current_month`; fonte única de verdade de janelas (Fase 100/102) |
| Determinar mês/janela do NPS a somar | `DesempenhoScoreService::computeNpsMedio` (Service, privado) | `MetricPeriodResolver` (se decidido expor `nps_window`) | Hoje é 100% interno ao service; qualquer nova janela de NPS deve nascer aqui ou no resolver, nunca no controller |
| Query de respostas NPS por período | `NpsScoreAssignment`/`NpsSurvey` (Model/Query) | — | `completed_at` já é a coluna canônica (DEC-80-B0); não recalcular em outro lugar |
| Congelamento mensal do bônus | `ConsolidarMesDesempenho` (Command/cron) | `DesempenhoScoreService::compute()` | Grava snapshot; depende de QUANDO a janela de NPS de M+1 está "fechada" — decisão de timing, não só de código |
| Exibição do "Em curso" vs "Bônus atual" | `PerformanceController` (Controller) | `Performance/Dashboard.jsx` | Já resolve `modo=em_curso|bonus_atual` e decide `$mesReferencia`; é o ponto que precisa saber SE a regra +1 se aplica ao modo operacional |

## Achados por tópico do `<focus>`

### 1. Como o mês do NPS é determinado hoje

`DesempenhoScoreService::computeNpsMedio(User $user, Carbon $mes)` (linha 558) recebe `$mes` = a própria competência e monta `$inicio`/`$fim` = `startOfMonth()`/`endOfMonth()` de `$mes`. Dois caminhos disjuntos somam notas dentro dessa janela:

- **(A) `notasPorAtribuicao`** (linha 611) — filtra `nps_score_assignments` via JOIN em `nps_surveys as s` por `s.completed_at BETWEEN [$inicio, $fim]` **[VERIFIED: código lido]**. Esta é a coluna canônica travada por **DEC-80-B0** (docblock linha 585-596): NÃO usar `assigned_at` (data de gravação, quebra em backfills) nem `month_reference` (mês do DISPARO, não da resposta, e NULL em várias linhas).
- **(B) `notasLegado`** (linha 678) — mesma janela, filtra `NpsSurvey::whereBetween('completed_at', [$inicio, $fim])`.

Conclusão: a "janela do NPS" hoje é **idêntica** à janela financeira — ambas usam `$mes` (a competência). A correção não precisa tocar a query em si (já usa `completed_at`, a coluna certa); precisa apenas mudar **qual `$mes` é passado** para `computeNpsMedio`.

### 2. Onde injetar o deslocamento +1

`compute()` (linha 311) chama:
```php
$nps = $this->computeNpsMedio($user, $mes);          // hoje: mesma competência
```
Ponto de injeção de menor raio: trocar para
```php
$mesNps = $mes->copy()->addMonthNoOverflow();
$nps = $this->computeNpsMedio($user, $mesNps);
```
Isso NÃO exige mudar a assinatura pública de `compute()`/`computeCached()`/`computeOficial()` — nenhum call-site externo muda. `$periodo` (financeiro) permanece inalterado; só a variável local passada ao componente NPS muda. Esta é a superfície mínima e não colide com `MetricPeriodResolver` (que resolve janelas financeiras, não a janela de NPS).

**Alternativa descartada (maior raio):** expor `nps_window` dentro do array `$periodo` do `MetricPeriodResolver`. Tecnicamente mais "limpo" arquiteturalmente, mas `MetricPeriodResolver` é consumido por `computeVarFaturamento`/`computeVarMargem` e por outras telas fora do bônus (Fase 100) — misturar uma regra específica-do-NPS ali aumenta o raio de mudança sem necessidade, já que `computeNpsMedio` é privado e só tem 1 caller. Recomendado manter a mudança local ao `DesempenhoScoreService`.

Interessante: `MetricPeriodResolver::resolveLastClosedMonth()` (linha 185) já calcula `bonus_payment_month = competência + 1 mês` **[VERIFIED: código lido, linha 206]** — é literalmente o mês que a nova regra de NPS precisa. Mas esse campo só é populado no modo `last_closed_month` (não em `YYYY-MM` genérico nem em `current_month` — ver linhas 142-143, 245-246, 292-293). Ou seja, **não dá pra depender de `$periodo['bonus_payment_month']`** como fonte da janela de NPS sem antes decidir se TODOS os modos (não só `last_closed_month`) devem ganhar esse metadado — é outro sub-ponto de escopo.

### 3. Escopo: só oficial vs também "Em curso" (DECISÃO DO USUÁRIO)

`compute()` é chamado por 3 caminhos hoje, e `computeOficial()` **não é chamado por nenhum controller/comando** — é uma API pública documentada mas não usada; o caminho real do "bônus oficial" é `computeCached($user, $mesReferencia)` sem override, onde `$mesReferencia` vem de `?modo=bonus_atual` no `PerformanceController` (linha 102-104, resolve `last_closed_month` e usa `bonus_competence_month`).

Os 3 caminhos que passam por `computeNpsMedio`:
1. **`PerformanceController::index`** (ranking `/performance`) — `modo=em_curso|bonus_atual` OU `?mes=YYYY-MM` OU nada (default = mês corrente).
2. **`PerformanceController::show`** (carteira individual, linha 356) — sempre mês corrente (`Carbon::now()->startOfMonth()`).
3. **`ConsolidarMesDesempenho`** (cron mensal) — sempre mês anterior ao hoje (o mês que acabou de fechar).

**Opção "só oficial"** (aplicar +1 apenas quando `$mes` é um mês FECHADO — i.e., `computeOficial()`/consolidação/`?mes=` passado): a tela "Em curso" (mês corrente, ex.: julho enquanto ainda é julho) continuaria mostrando NPS do **próprio mês corrente** (comportamento atual, sem deslocamento) — porque a competência de julho só teria "NPS de agosto" depois que agosto começar a existir, o que é logicamente impossível para o "Em curso". Consequência prática: o preview parcial mostrado durante o mês (`/performance` sem `?modo=`) continua semanticamente "errado" pela nova regra de negócio (mistura NPS de julho com financeiro de julho, quando o bônus real de julho vai usar NPS de agosto) — mas é a única leitura disponível: **não existe** dado de M+1 enquanto M ainda está em curso.

**Opção "ambos"** (a regra +1 se aplica sempre, inclusive ao "Em curso"): a tela operacional passaria a mostrar **NPS do mês seguinte ao mês corrente** — que ainda não existe (0 respostas, sempre). O card de NPS do "Em curso" ficaria permanentemente 0.0/vazio durante todo o mês, só populando quando a competência virar "mês fechado" e a leitura mudar para `computeOficial`. Isso muda visivelmente o dashboard operacional (hoje mostra NPS parcial do mês corrente coletado até a data) e quebra o contrato documentado no bump de cache v3→v4/v5 (`DesempenhoScoreService.php` linhas 199-230) de que a v5 é "byte-idêntica à v17 pro caminho operacional" — qualquer mudança no "Em curso" invalida esse contrato implícito e provavelmente exige um NOVO bump de versão de cache (`desempenho.compute.v5` → `v6`), já que o shape numérico do componente NPS muda mesmo sem mudar o formato do array.

**Caso de borda que expõe a tensão:** hoje (2026-07-21) o último mês fechado é **junho**, e junho+1 = **julho** já tem respostas parciais (o mês está em curso, mas o disparo já rodou para empresas com aniversário nos dias 1-21). Então `computeOficial()`/`?modo=bonus_atual` para junho HOJE já teria dado real e completo (julho terminou de coletar só no dia 31). Mas se alguém consultar a competência de **julho** (mês em curso) HOJE, M+1 = agosto, que não existe — 0 respostas garantidas. Ou seja, mesmo dentro da "opção só oficial", o comportamento de "Em curso" fica sempre com 0 NPS até fechar — não há meio-termo.

### 4. Timing do congelamento (`ConsolidarMesDesempenho`) — DECISÃO CRÍTICA

`Schedule::command('desempenho:consolidar-mes')->monthlyOn(1, '14:00')` **[VERIFIED: routes/console.php linha 197-199]** roda no **dia 1** do mês seguinte à competência, sempre consolidando "mês anterior ao hoje" por default (linha 71 do command: `Carbon::today()->subMonthNoOverflow()->startOfMonth()`).

Combinado com o disparo de NPS (`nps:disparar-mensal`, diário 09:00, dispara por empresa no dia do mês = `DAY(companies.created_at)` com clamp — **[VERIFIED: NpsDispararMensal.php linhas 57, 174-177]**), o cenário fica:

- Consolidação de **junho** roda dia **1 de julho, 14:00**.
- Pela regra nova, o NPS de junho = respostas com `completed_at` em **julho inteiro** (01/07 a 31/07).
- No momento em que o cron roda (01/07 14:00), **NENHUMA** empresa com aniversário de cadastro nos dias 2-31 ainda recebeu o disparo desse mês — só as com aniversário no dia 1 (e mesmo essas, só se o cliente já respondeu em poucas horas). Na prática, **quase 0% das respostas de julho existem no dia 1**.
- Congelar o snapshot de junho nesse momento com a regra +1 grava um NPS artificialmente baixo/zerado — o MESMO SINTOMA do bug original (Felipe: 0 respostas → 0.0 → nota 1.5), só que agora **estruturalmente garantido todo mês**, não um incidente pontual.

**Consequência direta:** o `desempenho:consolidar-mes` não pode continuar rodando no dia 1. Ou (a) muda para rodar no **início do mês seguinte a M+1** (ex.: consolidar junho só em 01/agosto, depois que julho — a janela de NPS — fechou por completo), atrasando o congelamento do bônus em ~1 mês adicional vs. hoje; ou (b) passa a reprocessar/atualizar o snapshot de junho depois, quando julho fechar (uma segunda passada tipo "fechar definitivamente" após a janela de NPS encerrar), o que implica dois estados de snapshot (provisório vs. definitivo) — mudança de modelo de dados, não só de cron.

Não há opção que preserve "congela no dia 1, mês fechado imediatamente" com a regra de negócio confirmada — os dois requisitos são matematicamente incompatíveis (a janela de NPS de M só fecha no fim de M+1, mas o cron de hoje congela M no início de M+1). Isso PRECISA ir para o discuss-phase como decisão de timing, não pode ser resolvido só no código.

### 5. Interação com a v16 (Fase 79/80) — dual-path do NPS

Nenhuma colisão encontrada. **DEC-80-B0** (já resolvido, não é um deferred item pendente) travou `completed_at` como a única fonte de mês da resposta, exatamente para evitar a armadilha de `month_reference` (mês do DISPARO, que diverge do mês da resposta) — isso na verdade **facilita** a regra +1: como a query já usa `completed_at` (a data real da resposta) e não `month_reference`, mudar a janela passada para `computeNpsMedio` é suficiente; não é preciso tocar `notasPorAtribuicao`/`notasLegado` nem o `NpsSnapshotService`. A busca em `.planning/STATE.md` não encontrou nenhum item explícito "DEC-80-B0 pendente" na tabela de Deferred Items — é uma decisão já fechada e documentada no código, não uma dívida aberta.

`nps_score_assignments` (Fase 79) continua sendo o congelamento de QUEM era responsável no dia da resposta — não tem relação com QUANDO a resposta conta para o bônus. A regra +1 é ortogonal a esse congelamento.

### 6. Pitfalls adicionais verificados

- **Sessão paralela NPS anti-burlamento (Fases 94-96):** grep confirmou que essas fases adicionaram `invalidated_at` (soft-invalidação por admin) — já contemplado pelo filtro `whereNull('r.invalidated_at')` em `notasPorAtribuicao`. Não alteraram a semântica de `completed_at`. Nenhum pitfall novo daí.
- **`computeNpsMedio` usado pelo dual-path (Fase 80):** confirmado — é chamado 1x dentro de `compute()`; qualquer mudança de assinatura (`Carbon $mes` → `Carbon $mesNps`) não quebra os dois ramos internos (A/B), que só recebem `$inicio`/`$fim` derivados do parâmetro já deslocado.
- **Cache versionado (`desempenho.compute.v5...`):** a mudança de regra de negócio no componente NPS invalida TODOS os valores cacheados sob a chave v5 (mesmo padrão dos bumps v1→v5 documentados no próprio arquivo). Um bump v5→v6 é obrigatório junto com a correção — sem ele, o Redis serve o número errado por até 7 dias (mês fechado) mesmo com o código certo em prod.
- **`AdmanMetricDiffService` / Http isolation nos testes:** não afetado — a mudança é isolada ao componente NPS, não toca `computeVarFaturamento`/`computeVarMargem`.
- **`BonusAtribuicoesNpsTest` (Fase 80, `tests/Feature/V16/`) e `DesempenhoScoreServiceTest` (Fase 74, fixture Carlos):** ambos fixam `completed_at` das respostas DENTRO do mesmo mês da competência testada — vão precisar de fixtures ajustadas (respostas em M+1) para continuar exercitando o caminho feliz pós-correção. Ver `## Validação Numérica` abaixo.

## Decisões de Escopo/Timing a levar ao usuário

Estas DUAS decisões bloqueiam o plano — não têm resposta técnica única, são escolhas de produto/processo:

**D1 — Escopo do deslocamento +1: só "oficial" (mês fechado) ou também "Em curso"?**
- **Só oficial:** "Em curso" continua lendo NPS do próprio mês corrente (preview imperfeito, mas com dado). Nenhuma mudança visível no dashboard operacional hoje. Nenhum novo bump de cache pro caminho operacional (só pro oficial).
- **Ambos:** "Em curso" passa a mostrar NPS de um mês que ainda não existe → sempre 0/vazio até o mês fechar. Quebra o contrato "byte-idêntico ao operacional" e exige tratamento de UI explícito ("NPS ainda não disponível — fecha em X dias").
- **Recomendação da pesquisa:** Só oficial. É logicamente impossível mostrar NPS de M+1 enquanto M está em curso (M+1 não aconteceu), então "ambos" na prática significa "esconder o card de NPS o mês inteiro" — mudança de UX maior que o bug que está sendo corrigido.

**D2 — Quando `desempenho:consolidar-mes` pode congelar de fato o snapshot de M?**
- **Opção A — Atrasar o cron:** consolidar M só quando M+1 tiver fechado por completo (ex.: dia 1 de M+2 em vez de dia 1 de M+1). Bônus fica visível ~1 mês depois do que hoje.
- **Opção B — Snapshot provisório + refechamento:** manter o cron no dia 1 de M+1 (rápido, como hoje) gravando um snapshot PROVISÓRIO (aviso de "NPS ainda em coleta"), e rodar um segundo passe no início de M+2 que sobrescreve com o valor definitivo. Exige estado novo (provisório vs. definitivo) no `DesempenhoScoreSnapshot` e process de "trava" (hoje o bônus pago em M+1 baseado no snapshot de M mudaria de valor um mês depois — impacto no processo de pagamento já feito).
- **Recomendação da pesquisa:** nenhuma das duas é puramente técnica — Opção A é mais simples de implementar mas atrasa o pagamento do bônus em relação ao processo atual (hoje: junho fecha, bônus é pago em julho baseado no snapshot de 01/07; com Opção A o snapshot de junho só sai em 01/08, um mês de atraso). Opção B preserva o timing de pagamento mas introduz números que MUDAM depois de já pagos — risco financeiro/de confiança se a diretoria já processou a folha com o valor provisório. Esta decisão tem que vir da diretoria/gestão financeira, não é uma escolha de engenharia.

## Validação Numérica (SC4 do ROADMAP)

Caso-prova disponível em prod: Felipe, competência junho — NPS lido de junho (0 respostas) → 0.0 → nota 1.5. Com a correção, `computeNpsMedio($felipe, Carbon::parse('2026-07-01'))` (chamando a janela de julho para a competência de junho) deve retornar a média das 13 respostas coletadas em julho → ~4.97 → nota final ~3.5. Este teste de regressão numérica (`assertEqualsWithDelta`) deve ser o Wave 0 gap principal do plano.

## Common Pitfalls

### Pitfall 1: Confundir "janela de NPS" com "`$periodoOverride`" do `MetricPeriodResolver`
**O que dá errado:** tentar resolver a janela de NPS através do `MetricPeriodResolver::resolve()` (que só popula `bonus_payment_month` no modo `last_closed_month`).
**Por que acontece:** o nome sugere que toda "janela de bônus" deveria passar por ali.
**Como evitar:** a janela de NPS é derivada localmente em `DesempenhoScoreService` (`$mes->copy()->addMonthNoOverflow()`), independente do `$periodo` financeiro. Não acoplar ao resolver a menos que D1 decida que TODOS os modos precisam do metadado `bonus_payment_month`.
**Sinais de alerta:** testes que fixam `completed_at` no mesmo mês da competência continuam passando "por acaso" (falso positivo) porque o teste não move a resposta pro mês seguinte.

### Pitfall 2: Esquecer o bump de versão de cache
**O que dá errado:** valores antigos (regra errada) continuam servidos do Redis por até 7 dias após o deploy da correção.
**Por que acontece:** o padrão já documentado no arquivo (`v1→v5`) é fácil de esquecer numa correção "pequena" de 1 linha.
**Como evitar:** bump `desempenho.compute.v5` → `v6` em `cacheKey()`, com o mesmo padrão de comentário histórico.
**Sinais de alerta:** número certo no teste automatizado, número errado em prod pós-deploy.

### Pitfall 3: Congelar `ConsolidarMesDesempenho` sem resolver D2 primeiro
**O que dá errado:** implementar só o deslocamento +1 em `compute()`/`computeNpsMedio` sem tocar o cron — o snapshot mensal continua congelando no dia 1, agora sistematicamente com 0 NPS (pior que o bug original, que era um incidente pontual de rate-limit).
**Como evitar:** D2 tem que estar decidido e no plano ANTES de mexer no `compute()` isoladamente — não é seguro fazer só a "metade fácil" (mudar `$mes`) e deixar a metade do timing para depois.

## Fontes

### Primária (HIGH confiança — código lido diretamente)
- `app/Services/DesempenhoScoreService.php` — `compute()`, `computeOficial()`, `computeNpsMedio()`, `notasPorAtribuicao()`, `notasLegado()`, `resolvePeriodo()`, `cacheKey()`
- `app/Services/Metrics/MetricPeriodResolver.php` — `resolveLastClosedMonth()`, `resolveSpecificMonth()`, `resolveCurrentMonth()`, `resolveCustom()`
- `app/Console/Commands/ConsolidarMesDesempenho.php` — schedule, default `$mes`, `updateOrCreate`
- `app/Console/Commands/NpsDispararMensal.php` — lógica de disparo por aniversário de cadastro
- `app/Http/Controllers/PerformanceController.php` — `index()`, `show()`, resolução de `$mesReferencia`/`modo`
- `routes/console.php` — horários reais dos crons (`nps:disparar-mensal` 09:00 diário, `desempenho:consolidar-mes` dia 1 14:00)
- `app/Models/NpsResponse.php` — `scopeValida()`/`invalidated_at`
- `.planning/STATE.md` — busca por deferred items relacionados a `month_reference`/`completed_at`/DEC-80-B0 (nenhum pendente encontrado)
- `.planning/ROADMAP.md` — Phase 105 goal/success criteria/depends on

## Metadados

**Confidence breakdown:**
- Ponto de injeção do código: HIGH — lido diretamente, único call-site de `computeNpsMedio`
- Timing do cron/disparo: HIGH — horários e lógica de aniversário lidos diretamente em `routes/console.php` e `NpsDispararMensal.php`
- Decisões D1/D2: N/A — são decisões de produto, não achados técnicos; mapeadas com as consequências de cada opção

**Data da pesquisa:** 2026-07-21
**Válida até:** próxima mudança em `DesempenhoScoreService`, `MetricPeriodResolver` ou nos crons de NPS/consolidação (domínio ativo, mudou 3x nas últimas 2 semanas — reduzir validade para ~7 dias)
