# Phase 116: NPS não respondido conta como nota mínima (1) - Research

**Researched:** 2026-07-27
**Domain:** Regra de negócio de agregação NPS (Laravel 12 + Eloquent, sem dependência externa nova)
**Confidence:** HIGH (todas as claims abaixo vêm de leitura direta do código-fonte e dos testes existentes nesta sessão — nenhuma foi apenas assumida de treinamento)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D1 — Retroatividade: SIM, com backfill**
Decisão do usuário (2026-07-27): a regra vale **retroativamente** nas competências já fechadas. O usuário foi informado e aceitou que isso pode mudar notas e possivelmente **quem bateu o bônus em meses fechados**.

**Mitigação obrigatória:** o comando de backfill roda primeiro em modo `--dry-run` produzindo um **relatório antes/depois por pessoa e competência**, para o usuário conferir o impacto **antes** de aplicar. Só aplica com confirmação explícita.

**D2 — Quando o 1 passa a valer**
Vale **desde o disparo**, enquanto a competência ainda está aberta (a média já reflete o não respondido). Quando o mês fecha sem resposta, o 1 vira **definitivo** — resposta que chegue depois do fechamento da competência não reescreve a nota daquela competência.

**D3 — Só conta o que foi disparado**
Só vira nota 1 o NPS **efetivamente disparado** (existe survey/envio para aquela empresa+responsável+competência). Empresa sem disparo **nunca** entra como 1 — senão pune quem não tinha o que enviar. Este é o invariante mais importante da fase.

**D4 — Escala e valor**
Nota mínima = **1** (não 0). Confirmar no plano qual é o piso real da escala usada em `nps_score_assignments` / `NpsResponseScore` e usar o mínimo dessa escala.

### Claude's Discretion

- Arquitetura de implementação: materializar linhas de nota 1 vs. agregar em tempo de leitura vs. terceira via (decisão do plan-phase, com trade-offs explícitos — ver `Architecture Patterns` abaixo).
- Nome exato do enum/coluna/tabela nova, se houver.
- Onde exatamente na UI da área NPS o texto explicativo aparece.

### Deferred Ideas (OUT OF SCOPE)

- Mudar a mecânica de disparo do NPS, os modelos/templates, os canais (email/Digisac).
- Mudar os pesos do bônus.
</user_constraints>

<phase_requirements>
## Phase Requirements

Nenhum REQ-ID pré-existia para esta fase (não mapeado em REQUIREMENTS.md — este é o primeiro trabalho da milestone/tema). Proponho os IDs abaixo, no padrão do projeto (prefixo temático + número), mapeados 1:1 aos "Critérios de aceite" do CONTEXT.md. **Não editei REQUIREMENTS.md** — cabe ao planner/usuário formalizar.

| ID proposto | Descrição | Suporte da pesquisa |
|----|-------------|------------------|
| NPSFLOOR-01 | NPS disparado e não respondido aparece como nota 1 na média da área NPS | Q1, Q2 (NpsController::index `$agregarMedia`/`$cards`), Q3 (recomendação de arquitetura) |
| NPSFLOOR-02 | Mesma nota 1 refletida no score de Desempenho/bonificação | Q2 (DesempenhoScoreService::computeNpsMedio), Q4 (janela M+1) |
| NPSFLOOR-03 | Empresa sem disparo não entra na conta (D3) | Q1 ("faltantes" já existe e é DISTINTO de "pendente/expirado"), Q5 |
| NPSFLOOR-04 | Empresa invalidada na competência não puxa 1 (C2) | Q5 (`BonusInvalidacao::companyIdsInvalidadas`) |
| NPSFLOOR-05 | Responsável CONSOLIDADO sem assignment por gap conhecido não vira 1 indevido (C3) | Q5 (`Company::responsavelDoServicoOuConsolidado`) |
| NPSFLOOR-06 | Não respondido parcial (multi-modelo) conta 1 só no modelo/serviço não respondido (C5) | Q6 (granularidade survey×serviço×role) |
| NPSFLOOR-07 | Resposta que chega depois do fechamento da competência não reescreve a nota (D2) | Q4 (achado crítico: agrupamento por `month_reference`, não por `completed_at`) |
| NPSFLOOR-08 | Comando de backfill idempotente com `--dry-run` e relatório antes/depois por pessoa e competência | Q9 (padrão `NpsBackfillAssignmentsConsolidado`) |
| NPSFLOOR-09 | UI da área NPS explicita a regra em linguagem simples, sem jargão | UI hint do CONTEXT + Q1 (StatCard em `Nps/Index.jsx`) |
| NPSFLOOR-10 | Suite de testes existente verde (cacheKey/fixtures atualizadas) | Q8 (inventário exato de testes que hardcodam a versão) |
</phase_requirements>

## Summary

O sistema hoje **não tem nenhum registro** para "NPS disparado e não respondido" — `nps_score_assignments`/`nps_response_scores` só nascem quando `NpsSnapshotService::registrar()` roda dentro do submit real da resposta (`NpsController::submitResponseV15`). Um survey `pending` ou `expired` (nunca respondido) simplesmente não aparece em nenhuma das ~11 queries de agregação já mapeadas no codebase (call-sites herdados 1:1 da Fase 96 AB-96-3, que precisou fazer o mesmo tipo de mapeamento exaustivo para a invalidação manual). A fonte de verdade de "foi disparado" já existe e está bem isolada: a tabela `nps_surveys` (chave `company_id + template_id + month_reference`), com status `pending|completed|expired` — mas o `status` só transiciona para `expired` de forma **preguiçosa** (só quando alguém clica no link vencido), então a condição correta para "não respondido" é `status != 'completed'`, nunca confiar em `status = 'expired'` sozinho.

A tentativa óbvia de "materializar nota 1 direto em `nps_score_assignments`" esbarra num bloqueio de schema real: `nps_score_assignments.nps_response_id` e `.nps_response_score_id` são FKs **NOT NULL** (`constrained()->cascadeOnDelete()`), então não é possível inserir uma linha de "não respondido" sem uma `NpsResponse`/`NpsResponseScore` reais — e criar respostas sintéticas contaminaria toda a semântica de "isso é uma resposta de verdade" usada por 10+ telas (confiança/auditoria, contagem de respondidos, listagem "NPS Respondidos"). A recomendação desta pesquisa (detalhada em Architecture Patterns) é uma **terceira via**: uma tabela nova, de grão `survey` (não `response`), alimentada por um comando idempotente no molde exato de `NpsBackfillAssignmentsConsolidado`, consumida pelos mesmos ~11 call-sites via um **novo terceiro ramo** no padrão de união disjunta que `DesempenhoScoreService::computeNpsMedio()` já usa (`notasPorAtribuicao()` + `notasLegado()` → adicionar `notasImputadas()`).

Um achado crítico não documentado no CONTEXT: os agregados da área NPS (`NpsController::index`) agrupam por **`month_reference`** (mês do disparo), não por `completed_at` (mês da resposta real). Isso significa que, sem uma trava explícita, uma resposta que chega depois do fechamento da competência **reescreveria** a média daquele mês só de existir — violando D2 diretamente. A "trava de fechamento" (D2) precisa ser um estado persistido (não um cálculo ad-hoc em cada tela), e o mecanismo já existe em espírito no `DesempenhoScoreService::computeNpsWindow()` (comparação por data, não por timestamp — mesmo pitfall documentado na Fase 105).

**Primary recommendation:** criar uma tabela `nps_imputed_assignments` (grão: 1 linha por `survey_id` × `servico_id` × `role`, com `status` `provisorio`/`definitivo`), alimentada por um comando `nps:materializar-nao-respondidos --dry-run` (mesmo molde de `NpsBackfillAssignmentsConsolidado`), consumida como um 3º ramo disjunto em `DesempenhoScoreService::computeNpsMedio()` e replicada nos ~11 call-sites já mapeados pela Fase 96 (tabela completa em Q2 abaixo).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Detecção de "survey disparado sem resposta" | API/Backend (`NpsSurvey.status`, `month_reference`) | Database/Storage | Já é a fonte de verdade hoje (usada por `NpsPendingService`/`NpsController`) — só falta consumi-la para nota, não redesenhar |
| Materialização da nota 1 (grão survey×serviço×role) | API/Backend (novo `NpsSnapshotService`-like service + command) | Database/Storage (tabela nova) | Espelha o padrão já usado pela Fase 79 (`NpsSnapshotService`) — snapshot congelado, não cálculo ao vivo |
| Agregação de médias (área NPS, Desempenho, Carteira, Metas) | API/Backend (controllers/services já mapeados) | — | Não existe camada única de agregação no codebase (achado da Fase 96, reconfirmado aqui) — cada call-site precisa da mesma mudança |
| "Vira definitivo" (trava de fechamento de competência) | API/Backend (comparação de data, sem timestamp) | Database/Storage (`status` persistido na linha imputada) | Precisa ser um ESTADO gravado, não um `now()` recalculado a cada leitura — senão uma resposta tardia reescreve a média (achado crítico desta pesquisa) |
| UI explicativa ("não respondido conta como nota mínima") | Frontend Server/SSR (props do Inertia) | Browser (`Nps/Index.jsx`, `StatCard`) | Backend já expõe `contadores`/`cards` prontos; só precisa de mais 1-2 chaves no payload + 1 linha de texto no componente existente |

## Standard Stack

Nenhuma dependência nova. Esta fase é 100% lógica de domínio sobre a stack já em produção (Laravel 12 Eloquent + Inertia/React já existentes no módulo NPS).

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^12.0 (já instalado) | Migrations, Eloquent, Console Command, Cache | Já é a base de TODO o módulo NPS/Desempenho |
| — | — | — | Nenhuma lib nova necessária |

### Alternatives Considered
Não aplicável — não há decisão de biblioteca nesta fase.

**Installation:** nenhuma — não há `npm install`/`composer require` nesta fase.

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote externo novo (nem PHP/Composer, nem JS/npm) — é extensão de código Laravel/Eloquent puro sobre serviços e migrations já existentes no projeto. `slopcheck` não precisa rodar.

## Architecture Patterns

### Q1 — Onde "não respondido" é invisível hoje (mapa completo do dado)

**Fonte de verdade de "foi disparado":** tabela `nps_surveys` (`app/Models/NpsSurvey.php`), chave lógica `(company_id, template_id, month_reference)` com dedup garantido por `NpsDispararMensal::handle()` (guard `NpsSurvey::where('company_id',...)->whereDate('month_reference',...)->where('template_id',...)->exists()`, `app/Console/Commands/NpsDispararMensal.php:223-231`). Cada linha representa **1 modelo aplicável disparado para 1 empresa em 1 mês** (multi-modelo desde a Fase 79/v16.0 — 1 survey por modelo cujos "serviços cobertos" intersectam um contrato ATIVO da empresa).

**Coluna de status:** `nps_surveys.status` — enum `['pending', 'completed', 'expired']` (migration `2026_04_26_152218_create_nps_surveys_table.php:21`). **Achado crítico:** a transição para `'expired'` é **lazy** — só acontece em `NpsController::respond()` quando alguém clica no link vencido (`app/Http/Controllers/NpsController.php:990-1009`, comentário na linha 997-998: *"único lugar do codebase que transiciona o status para 'expired' — expiração é lazy, não há job agendado"*). Consequência prática: a maioria dos surveys vencidos **permanece com `status='pending'` no banco para sempre**, mesmo tendo expirado há meses. **A condição correta para "não respondido" é `status != 'completed'`**, nunca `status = 'expired'` isoladamente. O próprio `NpsController::index()` já resolve isso com uma closure de apresentação (`$statusEfetivo`, linha 513-521: `completed` se `status==='completed'`; senão `expired` se `expires_at` passou; senão `pending`) — mas essa closure é só para exibição, não altera o dado nem é usada em nenhuma agregação de nota.

**Onde `nps_score_assignments` nasce:** `NpsSnapshotService::registrar()` (`app/Services/Nps/NpsSnapshotService.php:95-212`), chamado DENTRO da transação de `NpsController::submitResponseV15` **depois** de gravar as `nps_response_answers` reais. Em 3 passos:
1. `nps_response_scores` — 1 linha por dimensão (estrategista/analista/empresa) com `average_score` calculado por `NpsScoreCalculator::compute()`.
2. `nps_response_covered_services` — snapshot de quais serviços a empresa tinha ATIVOS no momento (via `$survey->template->serviceScopes()`).
3. `nps_score_assignments` — 1 linha por (serviço coberto ∩ contrato ativo da empresa) × (papel `consultor`/`estrategista`), resolvendo o responsável via `Company::responsavelDoServicoOuConsolidado($role, $servico->id)` (fallback consolidado — ver Q5).

Chaves gravadas em `nps_score_assignments`: `nps_response_id` (NOT NULL FK), `nps_response_score_id` (NOT NULL FK), `company_id`, `servico_id` (nullable, `nullOnDelete`), `service_setor` (string congelada), `role` (`consultor`|`estrategista`), `user_id`, `average_score`, `assigned_at`.

**Não existe hoje NENHUMA coluna/estado que distinga "enviado" de "respondido" além do próprio `NpsSurvey.status`** — não há um segundo enum redundante. `status='completed'` + `completed_at` preenchido = respondido; qualquer outro estado = não respondido (independente de estar dentro ou fora do prazo).

**"Faltantes" já é um conceito SEPARADO e já implementado** (`NpsController::index()`, variável `$faltantes`, linhas 396-501): empresas ATIVAS com contrato ativo no setor coberto por um modelo automático que **não têm NENHUM `NpsSurvey` no mês** (nem pending, nem completed). Isso é exatamente o invariante D3 ("empresa sem disparo nunca entra") — a distinção entre "faltante" (sem survey) e "pendente/expirado" (survey existe, sem resposta) **já existe na UI hoje**, só não é usada para nota. A nova regra deve consumir exatamente o segundo grupo (survey existe + `status != 'completed'`), nunca o primeiro.

### Q2 — Inventário completo de consumidores da média de NPS

Este inventário foi **herdado por precedente direto** do mapeamento exaustivo feito na Fase 96 (AB-96-3, invalidação manual de resposta) — aquela fase precisou fazer o MESMO tipo de varredura ("todo lugar que agrega NPS") para garantir que uma resposta invalidada saísse de todos os lugares. A tabela abaixo é a mesma lista de 11 call-sites, **reconfirmada por leitura direta nesta sessão** (arquivos/métodos ainda existem com a mesma responsabilidade; números de linha podem ter mudado desde 2026-07-17 — o plano deve re-grep antes de editar, não confiar cegamente nos números abaixo).

| # | Arquivo | Método | Como agrega hoje | O que falta para a regra nova |
|---|---------|--------|-------------------|-------------------------------|
| 1 | `app/Services/DesempenhoScoreService.php` | `notasPorAtribuicao()` (~L827) | JOIN `nps_score_assignments` × `nps_responses` × `nps_surveys`, `GROUP BY (response_id, role)`, `MAX(average_score)` | Merge de um 3º ramo `notasImputadas()` na união disjunta de `computeNpsMedio()` (~L770-796) |
| 2 | `app/Services/DesempenhoScoreService.php` | `notasLegado()` (~L897) | `NpsSurvey::with('response')->principal()->whereIn(company_id,...)`, filtra em PHP | Idem — union com o ramo imputado, respeitando o predicado de skip (`->principal()` só no ramo legado, DEC-80-D) |
| 3 | `app/Http/Controllers/PerformanceController.php` | `notasNpsDoUsuarioPorResposta()` ramo (A) (~L752) | Mesmo JOIN de #1, alimenta 3 widgets da carteira (coluna NPS/heatmap/últimas respostas) | Mesmo union do #1 |
| 4 | `app/Http/Controllers/PerformanceController.php` | `notasNpsDoUsuarioPorResposta()` ramo (B) legado (~L793) | `NpsSurvey::with('response')->principal()`, dimensão por cargo | Mesmo union do #2 |
| 5 | `app/Http/Controllers/DashboardController.php` | `adminDashboard()`/`buildRanking()` widgets NPS (~L765, ~L1317) | `NpsSurvey::with('response')` + `NpsScoreCalculator::compute()` em PHP | Precisa incluir surveys não respondidos com nota=1 no mesmo array antes do `avg()`/`filter()` |
| 6 | `app/Http/Controllers/DashboardController.php` | `userDashboard()` (~L1440-1502) | Mesmo padrão do #5, escopo `$user->companies()` | Idem #5 |
| 7 | `app/Http/Controllers/DashboardController.php` | `avgNotaDimensao()` helper (~L1509-1523), consumido por `buildRanking` | `NpsScoreCalculator::compute($response, $dimensao)` iterando surveys | Precisa aceitar surveys sem `response` e retornar 1.0 nesse caso, em vez de pular |
| 8 | `app/Http/Controllers/PortfolioController.php` | "Histórico NPS mensal do profissional" (~L2247-2260) | Implementação PRÓPRIA e simples (single-path, SEM `NpsScoreAssignment`) — **confirmado NÃO compartilhar código com PerformanceController** | Precisa do próprio ajuste, separado de #3/#4 |
| 9 | `app/Jobs/CalculateGoalResults.php` | `computeNps()` (~L205-230) | `NpsResponse::query()->whereHas('survey',...)`, `NpsScoreCalculator::compute()` | Precisa somar surveys sem resposta como nota=1 na mesma query/coleção |
| 10 | `app/Http/Controllers/CompanyController.php` | `show()` — payload `nps_surveys` (~L517-524) + `buildRanking`-like (~L1317-1350) e `avgNotaDimensao` (~L1509) | `company->npsSurveys->map(...)` via `NpsScoreCalculator` | Precisa expor "não respondido = 1" no card de média da empresa (`avgNps`) sem quebrar a lista "NPS Respondidos" (que deve continuar mostrando só respostas reais) |
| 11 | `app/Http/Controllers/NpsController.php` | `index()` — `$agregarMedia`/`$cards`/`$serieMeses` (~L606-659) | `NpsResponse::query()->whereHas('survey', $responsesFilter)->get()`, `$notaDe()` por dimensão | Precisa injetar 1 "nota sintética 1.0" por dimensão aplicável para cada survey não respondido do universo filtrado |

**Achado adicional (não estava na Fase 96, específico desta fase):** o cache-busting do bônus (`DesempenhoScoreService::cacheKey()` + `Cache::forget()`) precisa ser disparado não só quando uma resposta chega, mas também quando um survey **vira definitivo** (nota 1 travada) — hoje só existe cache-busting no fluxo de resposta/invalidação (`NpsController::bustarCacheDoBonus()`, ~L1875-1895), nunca no "não-evento" de uma competência fechar sem resposta.

### Q3 — Materializar vs. agregar (com recomendação)

**Bloqueio de schema para "materializar direto em `nps_score_assignments`" (Opção a do CONTEXT):** a migration `2026_07_14_200001_create_nps_snapshot_tables.php` define:
```php
$table->foreignId('nps_response_id')->constrained('nps_responses')->cascadeOnDelete();       // NOT NULL
$table->foreignId('nps_response_score_id')->constrained('nps_response_scores')->cascadeOnDelete(); // NOT NULL
```
Não é possível inserir uma linha de "não respondido" nesta tabela sem uma `NpsResponse`/`NpsResponseScore` reais (as FKs são obrigatórias, não `nullable()`). As únicas formas de contornar seriam: (i) alterar o schema para `nullable()` — mas isso quebra a garantia implícita usada por outras 10+ telas de que "toda linha aqui = uma resposta real existe"; ou (ii) criar uma `NpsResponse`/`NpsResponseScore` **sintéticas** — o que contaminaria contagens de "respondidos" (`NpsController::index` conta `status='completed'` na tabela `nps_surveys`, então isso sozinho não quebra, MAS qualquer tela que faça `$survey->response !== null` para decidir "foi respondido" — ex.: `confiancaDe()`, linha 744, `"survey ainda pendente — sem resposta, sem veredito"` — passaria a mentir).

**Opção (b) do CONTEXT — agregação em tempo de leitura, pura:** exigiria replicar, em CADA um dos 11 call-sites da Q2, a MESMA lógica de "quais surveys deste user/empresa/mês estão sem resposta E têm contrato ativo E não estão invalidados" — a Fase 96 já documentou esse exato risco como "Pitfall 4: esquecer 1 dos N call-sites deixa a resposta invalidada meio-contando" (`96-RESEARCH.md` linha 495-499). Sem uma camada compartilhada, o risco de divergência entre telas é ALTO (o próprio `96-RESEARCH.md` linha 497 admite: *"não existe uma camada única de agregação NPS no codebase"*).

**Terceira via recomendada (nem (a) puro nem (b) puro):** uma tabela nova, de grão **survey** (não response), populada por um serviço/command dedicado — não por cálculo ad-hoc em cada tela:

- Tabela `nps_imputed_assignments` (nome sugerido): `survey_id` (FK para `nps_surveys`, obrigatória — survey SEMPRE existe por definição), `company_id`, `servico_id`, `service_setor`, `role`, `user_id`, `dimensao` (`estrategista`|`analista`|`empresa` — a dimensão empresa não gera assignment de pessoa hoje, mas pode precisar de linha própria para os cards da área NPS), `nota` (decimal, sempre = piso da escala = **1.00**, ver Q7), `status` (`provisorio`|`definitivo`), `locked_at` (nullable — quando virou definitivo), `created_at`/`updated_at`.
- Populada por um serviço `NpsImputationService` (nome sugerido) chamado tanto por um **comando idempotente** (`nps:materializar-nao-respondidos`, molde de `NpsBackfillAssignmentsConsolidado` — ver Q9) quanto, opcionalmente, por um hook no fluxo de disparo/expiração para manter o dado sempre fresco entre execuções do comando.
- Reusa **exatamente** `Company::responsavelDoServicoOuConsolidado($role, $servico->id)` para resolver o responsável (mesma fonte de verdade do `NpsSnapshotService::registrar()`, já corrigida para o gap CONSOLIDADO — ver Q5) e a mesma interseção "serviços cobertos pelo modelo ∩ contrato ATIVO" que `NpsSnapshotService` já calcula.
- Quando uma resposta REAL chega para um survey que já tinha linha imputada `provisorio`, a linha imputada deve ser **removida** (idempotência: o comando roda de novo e não recria porque o survey virou `completed`).
- Quando a linha vira `definitivo` (competência fechou sem resposta), ela é **congelada para sempre** — mesmo que uma resposta chegue depois, a linha definitiva não é apagada nem alterada (isso é o que garante D2/NPSFLOOR-07; ver Q4 para o mecanismo exato de "quando fecha").
- Cada um dos 11 call-sites da Q2 ganha um **terceiro ramo de união disjunta** (reaproveitando o padrão que `DesempenhoScoreService::computeNpsMedio()` já usa para os ramos A/atribuição e B/legado — `$notas->merge(...)`), lendo desta tabela nova em vez de recalcular a lógica de "quem é responsável + está invalidado + tem contrato ativo" em cada tela.

**Por que esta terceira via é melhor que (a)/(b) puras:**
- **Idempotência:** grão único (`survey_id`, `servico_id`, `role`, `dimensao`) com `unique()` — rodar o comando várias vezes não duplica (mesmo padrão de `NpsBackfillAssignmentsConsolidado`, que já usa exatamente esse tipo de guard).
- **Nenhuma poluição de "isso é uma resposta real":** `nps_responses`/`nps_response_scores`/`nps_score_assignments` continuam 100% reais — os 10+ lugares que hoje assumem "linha aqui = resposta existe" continuam corretos sem nenhuma mudança.
- **Divergência entre telas minimizada:** a lógica de "quem conta, quem não conta" (contrato ativo, responsável correto, invalidação) mora em UM serviço, não em 11 cópias.
- **Resposta tardia não reescreve:** o `status` `definitivo` é um ESTADO GRAVADO, não um cálculo — resolve o achado crítico da Q4 (agregações por `month_reference` reescreveriam a média se dependessem só de "existe resposta ou não" recalculado ao vivo).
- **Custo de query aceitável:** mesma ordem de grandeza das tabelas de snapshot já existentes (`nps_response_scores`/`nps_score_assignments`), que já são lidas em JOIN por `DesempenhoScoreService`/`PerformanceController` sem problema de performance reportado.
- **Backfill retroativo natural:** o mesmo comando que popula prospectivamente também cobre o retroativo — rodar 1x contra todo o histórico com `--dry-run` primeiro (D1).

**Trade-off aceito:** esta abordagem adiciona 1 tabela + 1 serviço + toques nos 11 call-sites (mesmo custo de "tocar todo mundo" que a opção (b) teria) — mas com a vantagem de que a lógica de decisão (quem entra, quem não entra) fica centralizada, reduzindo o Pitfall 4 da Fase 96 a "esqueci de ler a tabela nova em algum lugar" (mais fácil de testar exaustivamente com 1 teste por call-site, igual ao molde `NpsInvalidacaoCallSitesTest`) em vez de "esqueci de reimplementar a regra de negócio em algum lugar".

### Q4 — Janela de competência e "vira definitivo"

**Para a ÁREA NPS (cards/série do `NpsController::index`):** o agrupamento por mês usa `nps_surveys.month_reference` (surveys automáticos) OU `created_at` como fallback (surveys manuais sem `month_reference`) — **nunca `completed_at`** (`app/Http/Controllers/NpsController.php:581-587`, `632-639`). **Achado crítico não antecipado pelo CONTEXT:** isso significa que, sem uma trava explícita, uma resposta que chega em agosto para um survey de `month_reference=junho` **já entra na média de junho hoje** (comportamento atual, correto para o caso "respondeu atrasado mas dentro do razoável"). Ao introduzir "nota 1 vira definitiva quando o mês fecha", esse mesmo mecanismo passaria a **reescrever** uma competência já fechada com nota 1 assim que uma resposta chegasse — violando D2 diretamente. Isso só é resolvido se a nota definitiva for um ESTADO GRAVADO (linha `status=definitivo` na tabela nova) que os call-sites SEMPRE preferem sobre uma resposta tardia para aquele `survey_id` — não um recálculo "tem resposta? usa; senão usa 1".

**"Mês fecha" para a área NPS = fim do calendário do `month_reference` do survey** (ou do mês de `created_at`, para surveys manuais). Não há hoje nenhum job/evento explícito de "fechamento de competência da área NPS" — o `NpsPendingService::diaCobranca()` (default dia 25, `Configuracao::get('nps_dia_cobranca', 25)`) é sobre **cobrança/lembrete**, não sobre fechamento definitivo; ele só afeta quando a empresa aparece como "pendente" na UI, não quando a nota vira 1.

**Para o DESEMPENHO/bônus:** já existe uma janela deslocada e um gate de fechamento EXPLÍCITO e testado — `DesempenhoScoreService::computeNpsWindow()` (`app/Services/DesempenhoScoreService.php:713-737`). Modelo: competência financeira M usa NPS coletado em M+1 (`$mesNps = $mes->addMonthNoOverflow()`). Mês em curso → piso 1.0 provisório (já implementado desde 2026-07-21, ver `cacheKey()` comentário v7). Mês fechado → usa `computeNpsMedio($mesNps)`; se resultado é `0.0` (sentinela "vazio"), decide entre excluir (`null`, M+1 ainda coletando) ou penalizar (`0.0`, M+1 já fechou) via:
```php
$janelaNpsFechada = now()->startOfDay()->gte($mesNps->copy()->endOfMonth()->startOfDay());
```
**Pitfall documentado e já resolvido na Fase 105:** a comparação é por **DATA** (`startOfDay()`), nunca por timestamp puro — o cron `desempenho:consolidar-mes` congela no último dia de M+1 às 14h, e `endOfMonth()` é 23:59:59 do MESMO dia; comparar por timestamp cru (`endOfMonth() < now()`) dá sempre falso nesse instante exato, classificando errado. Este MESMO padrão de comparação deve ser reusado (não reinventado) para decidir quando as linhas `nps_imputed_assignments` viram `definitivo`.

**Testes que documentam esta régua (ler antes de planejar):** `tests/Feature/V18/JanelaNpsBonusTest.php` (mecânica exclui-vs-penaliza, boundary exato do cron) e `tests/Feature/V18/ConsolidarMesJanelaNpsTest.php` (congelamento via `DesempenhoScoreSnapshot`). O comando `ConsolidarMesDesempenho` (`app/Console/Commands/ConsolidarMesDesempenho.php`) já é o "vira definitivo" do BÔNUS (grava snapshot congelado via `DesempenhoScoreSnapshot::updateOrCreate`) — mas ele congela o SCORE FINAL do bônus, não a linha crua de NPS; a nova tabela imputada precisa do próprio mecanismo de "definitivo" independente dele (a área NPS não passa pelo `ConsolidarMesDesempenho`).

### Q5 — Os três casos que não podem virar 1 indevidamente

**Caso 1 — Empresa sem disparo:** já coberto por construção na arquitetura recomendada — a tabela `nps_imputed_assignments` tem `survey_id` como FK obrigatória; sem `NpsSurvey`, não há como criar a linha. O comando de materialização deve iterar `NpsSurvey::where('status', '!=', 'completed')`, nunca `Company::all()` — isso sozinho impede o caso 1 por construção (mesmo padrão que `$faltantes` de `NpsController::index()` já usa para nunca contar empresa sem survey).

**Caso 2 — Empresa invalidada na competência (`bonus_invalidacoes`):** `BonusInvalidacao::companyIdsInvalidadas(Carbon $competencia)` (`app/Models/BonusInvalidacao.php:68-74`) retorna os `company_id` invalidados numa competência (mês financeiro M, sempre `startOfMonth()`). Já é consumido em `DesempenhoScoreService::compute()` (linha 381) e propagado como `$invalidadas` para `computeNpsWindow`/`computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` (`whereNotIn('company_id', $invalidadas->all())`). **Importante: este mecanismo é ESPECÍFICO do módulo Desempenho/bônus** — a área NPS (`NpsController`) NÃO filtra por `bonus_invalidacoes` hoje (ela usa outro mecanismo, `NpsResponse.invalidated_at`, para invalidação MANUAL de uma resposta suspeita — conceito diferente, não confundir). Logo: o novo ramo `notasImputadas()` em `DesempenhoScoreService` **deve** respeitar `$invalidadas` (mesmo padrão dos outros dois ramos); já na área NPS, não existe hoje noção de "empresa invalidada por competência" — se a regra nova precisar respeitar isso também na área NPS, é uma decisão nova do plano (o CONTEXT não define isso, e não há precedente — sinalizar como pergunta aberta).

**Caso 3 — Responsável CONSOLIDADO sem assignment por gap:** já resolvido pelo `Company::responsavelDoServicoOuConsolidado(string $role, int $servicoId)` (`app/Models/Company.php:246-263`), que cai para o slot `servico_id=NULL` quando não há linha específica do serviço na pivot `company_users` — a MESMA fonte de verdade que `NpsSnapshotService::registrar()` já usa desde o fix de 2026-07-22 (debug `nps-assignment-consolidado`). **A regra de ouro: a nova imputação deve reusar EXATAMENTE este método (nunca `consultorDoServico()`/`estrategistaDoServico()` puros, que só casam `servico_id` exato)** — fazendo isso, o gap do consolidado não pode se manifestar como "1 indevido", porque o próprio resolvedor de responsável já é o corrigido. Distinguir por "existe o disparo/survey" (sempre existe, é o gate) e não por "existe assignment" (pode faltar por outros motivos que não são culpa do responsável) é o que já está descrito no CONTEXT C3 e é diretamente suportado por este método.

### Q6 — Multi-modelo e não-respondido parcial

O disparo multi-modelo (Fase 79/v16.0, DEC-79-A) cria **1 `NpsSurvey` por modelo aplicável** por empresa/mês (`NpsDispararMensal::handle()`, loop `foreach ($modelosAplicaveis as $modelo)`, linha ~219). Cada modelo cobre um conjunto de serviços (`nps_template_service_scopes`); `NpsResponseCoveredService` congela, POR RESPOSTA, quais serviços o modelo cobria. Como cada modelo vira um `NpsSurvey` próprio, **a granularidade correta da nota 1 já é por survey** (que por sua vez já é por modelo/serviços cobertos daquele modelo) — não é preciso nenhuma lógica nova de "granularidade parcial dentro de 1 survey": se a pessoa respondeu o modelo do serviço A (survey A completou) e não respondeu o modelo do serviço B (survey B ainda pending/expired), **survey B por si só já é a unidade de "não respondido"** que a tabela nova precisa materializar — survey A não gera linha imputada (tem resposta real), survey B gera.

A única sutileza: o `role` (consultor/estrategista) e o `servico_id`/`service_setor` da linha imputada devem vir da **interseção** "serviços cobertos pelo template do survey B" ∩ "contratos ATIVOS da empresa HOJE" — exatamente a mesma interseção que `NpsSnapshotService::registrar()` calcula na Etapa 3 (`$ativos = $company->contratosServico()->active()->pluck('servico_id')->all()`). Reusar essa mesma função de interseção (extraindo-a para um método compartilhado, se necessário) evita duplicar a regra de negócio.

### Q7 — Escala real da nota

**Escala confirmada: 1 a 5**, não 0-10. `NpsTemplateOption.peso` é `int` com contrato documentado *"peso numérico 1..5"* (`app/Models/NpsTemplateOption.php:13-21`: *"peso 1..5 hardcoded"*, *"limite 1..5 do peso (UI bloqueia > 5)"*). `NpsResponseScore.average_score`/`NpsScoreAssignment.average_score` são `decimal(5,2)` = `score_sum / question_count`, onde `score_sum` é `SUM(option_peso_snapshot)` — sempre dentro de `[1.00, 5.00]` quando há pelo menos 1 pergunta com peso respondida (perguntas não respondidas ainda entram no divisor, mas nunca geram peso negativo — o pior caso teórico de uma resposta 100% vazia em perguntas com peso seria `0/N = 0.0`, mas isso é o caso "resposta existe mas não preencheu nada", diferente de "não respondeu o survey"). **Não há ambiguidade a sinalizar**: a escala do template é 1-5, então "nota mínima(1)" pedida pelo usuário mapeia diretamente e sem interpretação para o **piso real da escala do próprio produto** — D4 do CONTEXT já está correto e não precisa de nenhuma leitura alternativa. `DesempenhoScoreService` NÃO reescala essa nota — ela entra direto na média do bônus (`computeNpsMedio`) na mesma escala 1-5; o único piso "sintético" hoje é o `1.0` de `computeNpsWindow()` para mês em curso (que é conceitualmente diferente — é "ainda não coletou", não "não respondeu depois de coletar").

### Q8 — Impacto no cache e nos testes

**Versão atual (confirmada por leitura, não assumida):** `desempenho.compute.v11.{userId}.{periodKey}` (`app/Services/DesempenhoScoreService.php:300`, helper público `cacheKey()`). Um bump para v12 é obrigatório nesta fase — o comentário do próprio método já documenta a régua ("Sem este bump o Redis continuaria servindo o bônus errado por até 7 dias mesmo com código novo em prod").

**Arquivos que hardcodam a versão ATUAL (`v11`) como valor esperado — QUEBRAM se não forem atualizados junto com o bump:**
| Arquivo | Linha | O que assume |
|---------|-------|---------------|
| `tests/Feature/DesempenhoShopeeScoreTest.php` | 363 | `assertSame('desempenho.compute.v11.'.$user->id.'.current_month', $chave)` |
| `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` | 246, 347 | `sprintf('desempenho.compute.v11.%d.%s', ...)` |
| `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` | 232, 258, 260, 277 | 4 asserções com `v11` literal |

**Arquivos que hardcodam versões ANTIGAS (v5/v6) como "chave órfã reconhecível" — NÃO quebram com o bump** (confirmado lendo o código: eles usam a versão antiga só como "lixo no cache", e a versão atual é sempre obtida via `$service->cacheKey()`, nunca hardcoded):
- `tests/Feature/V16/BonusDualPathRegressaoTest.php:538,562` — `$chaveV5` é literal (poison key), `$chaveV6` vem de `$service->cacheKey(...)` (dinâmico).
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php:443,460` — mesmo padrão.

**Testes de janela/invalidação que exercitam `computeNpsMedio`/`computeNpsWindow` e precisarão de novos cenários (não necessariamente vão QUEBRAR, mas vão precisar de NOVOS casos de teste para cobrir a regra nova):** `tests/Feature/V16/BonusAtribuicoesNpsTest.php`, `tests/Feature/V16/AtribuicaoPorServicoNpsTest.php`, `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php`, `tests/Feature/V18/JanelaNpsBonusTest.php`, `tests/Feature/V18/ConsolidarMesJanelaNpsTest.php`, `tests/Feature/BonusInvalidacaoEmpresaTest.php`, `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` — nenhum deles hoje semeia um `NpsSurvey` sem resposta esperando nota 1; todos assumem "sem resposta = 0 contribuição para a média" (comportamento atual). Ao ativar a imputação, qualquer teste que crie um survey `pending`/`expired` sem querer (fixture "de passagem") pode passar a puxar a média para baixo com nota 1 onde antes era ignorado — **auditar fixtures de survey `pending` nesses arquivos antes de ativar a feature**, não só os que explicitamente testam "sem resposta".

### Q9 — Padrão de command de backfill

Dois comandos existentes definem o molde a seguir (`NpsBackfillAssignmentsConsolidado.php` e `NpsBackfillDivisorTextoLivre.php` — o primeiro é o mais próximo e foi lido em detalhe):

**Estrutura do padrão (`NpsBackfillAssignmentsConsolidado`):**
1. **Assinatura:** `--dry-run` (só mostra o diff, read-only) + `--force` (pula confirmação interativa). Sem `--force` e sem `--dry-run`, o comando SEMPRE mostra o diff primeiro e pede `$this->confirm(...)` antes de gravar.
2. **Passo 1 (sempre executa, mesmo sem `--dry-run`):** roda a lógica em modo dry-run internamente (`$this->snapshotService->backfillAssignments($response, dryRun: true)`), agrega estatísticas (`criados`, `pulos_ja_existentes`, `pulos_sem_responsavel`) e monta um array de detalhe.
3. **Exibição:** `$this->table([...], [...])` para o diff linha-a-linha ANTES de gravar, e outra tabela para as estatísticas agregadas.
4. **Confirmação:** se não há nada a corrigir (`stats['criados'] === 0`), sai cedo com mensagem. Se `--dry-run`, sai sem gravar. Senão, `$this->confirm()` com default `false` — só grava se o operador confirmar (ou `--force`).
5. **Passo 2 (gravação real):** reprocessa SÓ os IDs identificados no passo 1 (evita re-escanear tudo), com `chunkById(200, ...)` para não estourar memória.
6. **Idempotência:** a lógica de negócio em si (`backfillAssignments()`) já é idempotente por natureza — verifica `NpsScoreAssignment::where(...)->exists()` antes de criar, então rodar o comando 2x não duplica nada.
7. **Cache-busting pós-gravação:** para cada resposta que ganhou assignment novo, busta o cache do bônus dos usuários afetados (`Cache::forget($scoreService->cacheKey($userId, $mesCompetencia))`), usando a MESMA régua de competência (`completed_at` menos 1 mês, NPSWIN-03) que `NpsController::bustarCacheDoBonus()` usa — **duplicado intencionalmente** no comando (comentário explícito: "para não editar `NpsController.php` neste debug — arquivo compartilhado com outra sessão ativa").
8. **Logging:** `Log::info(...)` estruturado no fim com as stats completas.

**O comando novo (`nps:materializar-nao-respondidos` ou nome equivalente) deve seguir EXATAMENTE este molde**, com a adição específica pedida pelo D1: o relatório do `--dry-run` precisa ser **"antes/depois por pessoa e competência"** (não só "quantas linhas seriam criadas") — ou seja, para cada usuário afetado, mostrar a nota média ANTES (sem a imputação) e DEPOIS (com a imputação) na competência afetada, e se isso muda a faixa de bônus (`BonusFaixa::classificar()`) da pessoa naquele mês — este é o requisito mais forte de UX do backfill e vai além do que `NpsBackfillAssignmentsConsolidado` já faz (que só mostra o assignment em si, não o antes/depois da nota consolidada).

### Q10 — Dados reais (quantificação)

**Não verificável localmente nesta sessão.** `tasklist | grep mysqld` não retornou nenhum processo MariaDB/MySQL rodando na máquina local (memória do projeto já registrava histórico de corrupção do MariaDB local em 2026-06-25). Não tentei subir o serviço para não repetir esse incidente. O planner/executor deve rodar a quantificação (contagem de `nps_surveys` com `status != 'completed'` agrupado por `month_reference`, e usuários afetados via `responsavelDoServicoOuConsolidado`) no ambiente de execução real (local com MariaDB de pé, ou direto no VPS em modo leitura) antes de decidir o volume do backfill retroativo.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Resolver responsável de um serviço (incl. fallback consolidado) | Nova query `company_users` ad-hoc no serviço de imputação | `Company::responsavelDoServicoOuConsolidado($role, $servicoId)` | Já corrigido para o gap CONSOLIDADO (2026-07-22); reimplementar arrisca reintroduzir o mesmo bug |
| Interseção "serviços cobertos pelo modelo ∩ contrato ativo" | Nova query duplicando a lógica | Extrair/reusar a lógica já em `NpsSnapshotService::registrar()` (`$ativos = $company->contratosServico()->active()->pluck('servico_id')->all()`) | Fonte única já testada e usada em produção |
| Determinar "está dentro do prazo ou já fechou" (janela de competência) | `now() < endOfMonth($mes)` direto | Padrão de `computeNpsWindow()`: `now()->startOfDay()->gte($mes->endOfMonth()->startOfDay())` | Bug de boundary já documentado e corrigido na Fase 105 — comparar por timestamp cru falha no instante exato do cron |
| Report de "quantas linhas seriam criadas" no backfill | Script PHP avulso fora do padrão Artisan | Molde `NpsBackfillAssignmentsConsolidado` (`--dry-run`/`--force`, `$this->table()`, stats agregadas) | Consistência com os 2 comandos de backfill já existentes no módulo NPS |

**Key insight:** todo o "ferramental" que esta fase precisa (resolução de responsável, interseção de serviços cobertos, janela de fechamento por data, molde de comando idempotente) **já existe e já está testado em produção** dentro do próprio módulo NPS/Desempenho — o trabalho genuíno desta fase é (1) decidir o grão da tabela nova e (2) tocar os 11 call-sites com disciplina de teste, não construir infraestrutura nova.

## Common Pitfalls

### Pitfall 1: Confiar em `nps_surveys.status = 'expired'` para achar "não respondido"
**What goes wrong:** a maioria dos surveys vencidos nunca teve o link clicado depois de vencer, então continuam com `status='pending'` no banco para sempre — uma query `where('status', 'expired')` erraria a grande maioria dos casos.
**Why it happens:** a transição para `expired` é lazy (só no GET do link), não há job agendado que varra e atualize em massa.
**How to avoid:** usar sempre `status != 'completed'` (nunca `status = 'expired'` isolado) como gate de "não respondido", com a data de fechamento calculada separadamente (não lida do `status`).
**Warning signs:** contagem de "não respondidos" muito menor do que a contagem visual da UI (`$contadores['pendentes'] + $contadores['expirados']` em `NpsController::index`).

### Pitfall 2: Agrupar por `month_reference` sem travar o "definitivo" permite resposta tardia reescrever a média
**What goes wrong:** uma resposta que chega depois do fechamento da competência entra silenciosamente na média daquele mês (comportamento ATUAL do `NpsController::index`, que agrupa por `month_reference`/`created_at`, nunca por `completed_at`) — violando D2 diretamente se a nota 1 imputada for só um cálculo ao vivo em vez de um estado gravado.
**Why it happens:** o agrupamento por mês na área NPS nunca precisou lidar com "definitivo" antes desta fase — sempre foi aceitável que uma resposta atrasada contasse para o mês do disparo.
**How to avoid:** persistir o `status=definitivo` como estado gravado na tabela de imputação; os call-sites devem preferir SEMPRE a linha definitiva sobre uma resposta tardia para o mesmo `survey_id`, nunca recalcular "tem resposta? usa-a" ao vivo para competências já fechadas.
**Warning signs:** teste que popula uma resposta real ANOS depois do fechamento e observa a média do mês fechado mudar.

### Pitfall 3: Reimplementar a resolução de responsável sem o fallback consolidado
**What goes wrong:** usar `Company::consultorDoServico()`/`estrategistaDoServico()` (que exigem `servico_id` exato na pivot) em vez de `responsavelDoServicoOuConsolidado()` reintroduz o MESMO gap que a Fase 79/debug `nps-assignment-consolidado` já corrigiu — responsável consolidado (pivot com `servico_id=NULL`) fica sem imputação nenhuma, ou pior, sem imputação ele pareceria "sem responsável = sem 1", inflando a nota da pessoa por engano.
**Why it happens:** os dois pares de métodos parecem intercambiáveis à primeira vista; só o segundo tem o fallback.
**How to avoid:** usar SEMPRE `responsavelDoServicoOuConsolidado()` na nova lógica de imputação — nunca os métodos "puros".
**Warning signs:** teste `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php` como referência de cenário mínimo a replicar para a imputação.

### Pitfall 4: Esquecer 1 dos 11 call-sites deixa "meio-contando" (mesmo pitfall da Fase 96, reaplicado)
**What goes wrong:** o bônus reflete a nota 1 mas o widget "últimas respostas" ou o card de média da área NPS não — o usuário perde confiança na feature.
**Why it happens:** não existe camada única de agregação NPS no codebase (confirmado nesta pesquisa e já documentado na Fase 96) — cada tela reimplementa sua própria leitura.
**How to avoid:** usar a tabela de 11 call-sites (Q2) como checklist obrigatório de execução — 1 teste de regressão por call-site, no molde de `NpsInvalidacaoCallSitesTest`.
**Warning signs:** qualquer PR/plano que toque menos de ~6 arquivos (`DesempenhoScoreService`, `PerformanceController`, `DashboardController`, `PortfolioController`, `CalculateGoalResults`, `NpsController`, `CompanyController`) quase certamente deixou algum call-site descoberto.

### Pitfall 5: Bônus invalidado por competência (`bonus_invalidacoes`) não filtrar a nova imputação em `DesempenhoScoreService`
**What goes wrong:** empresa que o admin tirou explicitamente do bônus daquele mês volta a puxar nota 1 pela nova regra — o `bonus_invalidacoes` vira "furo" pelo qual a punição do NPS não respondido escapa.
**Why it happens:** o novo ramo `notasImputadas()` é código novo; se não repassar o `$invalidadas` já resolvido em `compute()` (linha 381), o filtro simplesmente não é aplicado.
**How to avoid:** `notasImputadas()` deve receber o MESMO parâmetro `?Collection $invalidadas` que `notasPorAtribuicao()`/`notasLegado()` já recebem, e aplicar `whereNotIn('company_id', $invalidadas->all())` da mesma forma.
**Warning signs:** teste `tests/Feature/BonusInvalidacaoEmpresaTest.php` estendido com um cenário "empresa invalidada + survey sem resposta" deve continuar dando nota SEM o componente NPS (ou 0 respostas), nunca com nota 1 imputada.

## Code Examples

### Padrão de união disjunta já usado (extensível para o 3º ramo)
```php
// Source: app/Services/DesempenhoScoreService.php:770-796 (computeNpsMedio, lido nesta sessão)
private function computeNpsMedio(User $user, Carbon $mes, ?Collection $invalidadas = null): float
{
    $inicio = $mes->copy()->startOfMonth();
    $fim    = $mes->copy()->endOfMonth();
    $invalidadas = $invalidadas ?? collect();

    $notas = collect();

    // (A) Atribuições congeladas da Fase 79 — todas as áreas
    $notas = $notas->merge(
        $this->notasPorAtribuicao($user, $inicio, $fim, $invalidadas)->pluck('average_score')
    );

    // (B) Caminho legado — só as respostas que o snapshot não cobriu
    $notas = $notas->merge($this->notasLegado($user, $inicio, $fim, $invalidadas));

    // (C) NOVO — nesta fase: notas imputadas (não respondido = 1), disjunto de A/B
    // $notas = $notas->merge($this->notasImputadas($user, $inicio, $fim, $invalidadas));

    if ($notas->isEmpty()) {
        return 0.0; // DESEMP-03 — sem respostas no mês força nps = 0
    }

    return round($notas->avg(), 2);
}
```

### Gate de "não respondido" correto (nunca usar `status='expired'` isolado)
```php
// Source: app/Http/Controllers/NpsController.php:513-521 (statusEfetivo, lido nesta sessão)
// Molde de closure já usado para apresentação — a lógica de negócio da
// imputação deve usar o MESMO critério de fundo (status != 'completed'),
// mas persistindo o resultado, não recalculando em toda leitura.
$statusEfetivo = function ($s) {
    if ($s->status === 'completed') {
        return 'completed';
    }
    if ($s->expires_at && $s->expires_at->isPast()) {
        return 'expired';
    }
    return 'pending';
};
```

### Resolução de responsável com fallback consolidado (reusar, não reimplementar)
```php
// Source: app/Models/Company.php:246-263 (responsavelDoServicoOuConsolidado, lido nesta sessão)
public function responsavelDoServicoOuConsolidado(string $role, int $servicoId): \Illuminate\Support\Collection
{
    $especificos = $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', $role)
        ->wherePivot('servico_id', $servicoId)
        ->distinct('users.id')
        ->get();

    if ($especificos->isNotEmpty()) {
        return $especificos;
    }

    return $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', $role)
        ->wherePivotNull('servico_id')
        ->distinct('users.id')
        ->get();
}
```

### Gate de fechamento por DATA, nunca por timestamp cru (Pitfall já corrigido na Fase 105)
```php
// Source: app/Services/DesempenhoScoreService.php:734 (computeNpsWindow, lido nesta sessão)
// CORRETO — imune ao boundary do cron que congela às 14h do último dia:
$janelaFechada = now()->startOfDay()->gte($mesReferencia->copy()->endOfMonth()->startOfDay());
// ERRADO — daria sempre falso no instante exato 14:00 < 23:59:59:
// $janelaFechada = $mesReferencia->copy()->endOfMonth()->lt(now());
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| NPS não respondido é simplesmente ignorado (invisível) na média | NPS não respondido conta nota 1 (piso da escala) desde o disparo, definitivo quando a competência fecha | Esta fase (116) | Média de NPS de todo mundo cai — esperado e desejado pelo usuário (senso de dever no envio) |

**Deprecated/outdated:** nenhum comportamento anterior é removido — a fase é 100% aditiva sobre o snapshot congelado da Fase 79 e a janela de bônus da Fase 105.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Os números de linha citados na tabela de 11 call-sites (Q2) podem ter mudado desde a leitura desta sessão (2026-07-27) — código em evolução ativa (múltiplas fases recentes tocaram `DesempenhoScoreService`/`PerformanceController`) | Q2 | Baixo-médio — o plano/executor deve re-grep pelo NOME do método antes de editar, nunca confiar cegamente no número de linha (mesmo aviso que o próprio `96-RESEARCH.md` já fazia sobre si mesmo) |
| A2 | A área NPS (`NpsController`) não aplica `bonus_invalidacoes` hoje — só `DesempenhoScoreService` aplica. Confirmado por ausência de `BonusInvalidacao`/`bonus_invalidacoes` em `NpsController.php` (grep não encontrou nenhuma ocorrência) | Q5, Pitfall 5 | Médio — se o plano decidir que a área NPS TAMBÉM deve respeitar invalidação por competência, é uma decisão NOVA de escopo, não uma correção de bug; sinalizar como pergunta aberta |
| A3 | O nome de tabela/serviço sugerido (`nps_imputed_assignments`/`NpsImputationService`) é só uma PROPOSTA desta pesquisa — o planner tem liberdade total (CONTEXT: "Claude's Discretion") para escolher outro nome/grão, desde que preserve as garantias de idempotência e "vira definitivo" descritas | Q3 | Baixo — é só uma sugestão de nomenclatura, não uma decisão travada |

**Nenhuma outra claim deste documento foi tagueada `[ASSUMED]`** — todo o resto (schema das migrations, comportamento dos services/controllers, testes existentes, cache key versão v11) foi confirmado lendo o código-fonte diretamente nesta sessão.

## Open Questions (RESOLVIDAS)

> As 3 perguntas abaixo foram levadas ao usuário e **resolvidas** em `116-CONTEXT.md`
> (decisões D5, D6 e D7, tomadas em 2026-07-27). Ficam registradas aqui com a resolução
> anexada, para rastreabilidade — nenhuma delas está em aberto.

1. **A área NPS (não-bônus) deve respeitar `bonus_invalidacoes` também?**
   - What we know: hoje só o Desempenho respeita; a área NPS tem seu PRÓPRIO mecanismo de invalidação manual (`NpsResponse.invalidated_at`), conceitualmente diferente.
   - What's unclear: o CONTEXT fala de "empresa invalidada" (C2) no contexto do bônus, mas a entrega 3 do CONTEXT diz "mesma regra em qualquer outro consumidor" — não fica claro se isso inclui respeitar `bonus_invalidacoes` na área NPS também, ou só a invalidação manual de resposta (que já é respeitada lá).
   - **RESOLVIDA — D5 (`116-CONTEXT.md`):** o usuário decidiu o OPOSTO da recomendação abaixo: empresa invalidada na competência (`bonus_invalidacoes`) **não puxa nota 1 nem no bônus nem na área NPS**. Isso adiciona o filtro de invalidação por competência à área NPS — capacidade NOVA, planejada e testada no plano 116-03.
   - Recommendation (histórica): perguntar ao usuário no discuss-phase, ou o plano assume "não" (área NPS mostra a nota 1 mesmo para empresa bonus-invalidada, pois ela reflete a realidade operacional do envio, não a elegibilidade financeira) e documenta a decisão explicitamente.

2. **Surveys manuais (sem `month_reference`, criados via `NpsController::generate()`) entram na regra?**
   - What we know: `NpsDispararMensal` sempre popula `month_reference`; `NpsController::generate()` (disparo manual, `auto_generated=false`) pode ou não popular — não confirmado nesta sessão com leitura direta do método `generate()`.
   - What's unclear: se um survey manual nunca é respondido, ele deveria virar nota 1 também? A resposta lógica é "sim" (D3 fala em "efetivamente disparado", sem distinguir manual/automático), mas o agrupamento por mês desses surveys usa `created_at` como fallback — o plano precisa confirmar que a lógica de imputação também cobre esse fallback.
   - **RESOLVIDA — D6 (`116-CONTEXT.md`):** sim, disparo manual segue a mesma regra (regra única, sem brecha de "escolher o canal que não conta"). O fallback `created_at` para surveys sem `month_reference` é coberto pela imputação (plano 116-01, `competencia_nps`) e pelo gancho em `NpsController::generate()` (plano 116-06).
   - Recommendation (histórica): o plano deve ler `NpsController::generate()` (não lido em profundidade nesta sessão) antes de finalizar o grão da tabela de imputação.

3. **A dimensão "empresa" (que não gera `NpsScoreAssignment` de pessoa hoje) deve gerar imputação?**
   - What we know: `NpsSnapshotService::DIMENSAO_ROLE` explicitamente NÃO inclui a dimensão `empresa` (só analista/estrategista viram assignment de pessoa); a dimensão empresa só existe em `nps_response_scores` e é consumida pelos `$cards['empresa']` da área NPS.
   - What's unclear: se um survey não respondido deve gerar uma "nota empresa = 1" para os cards da área NPS mesmo sem gerar assignment de pessoa nenhuma.
   - **RESOLVIDA — D7 (`116-CONTEXT.md`):** sim. A dimensão `empresa` também recebe a nota 1, com linha própria na tabela de imputação (sem `role`/`user_id`) — plano 116-01 (grão) + plano 116-03 (cards).
   - Recommendation (histórica): sim, provavelmente — os `$cards` da área NPS mostram 3 médias (estrategista/analista/empresa) e a regra do usuário fala em "área NPS" de forma ampla; a tabela de imputação deve ter uma linha para a dimensão empresa também (sem `role`/`user_id`, só para fins de card agregado), ou um mecanismo equivalente.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| MariaDB/MySQL local | Quantificação real do volume de backfill (Q10) | ✗ (mysqld não está rodando nesta máquina) | — | Rodar a quantificação no VPS (leitura, sem escrita) ou subir o MariaDB local antes de planejar o backfill em detalhe |
| PHPUnit / `php artisan test` | Validação das mudanças (Wave 0 e sampling) | Presumido ✓ (phpunit.xml presente, `composer.json` tem script `test`) — não executado nesta sessão de pesquisa (fora de escopo) | phpunit ^11.5.50 | — |

**Missing dependencies with no fallback:** nenhuma — o backfill pode ser quantificado no VPS como fallback.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), Laravel Feature Tests (`Illuminate\Foundation\Testing\RefreshDatabase`) |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --filter=NpsFloorTest` (nome de suite sugerido — o plano deve criar 1+ arquivo `tests/Feature/Phase116/*`) |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| NPSFLOOR-01 | Survey não respondido conta nota 1 nos cards da área NPS | Feature | `php artisan test --filter=NpsFloorAreaNpsTest` | ❌ Wave 0 |
| NPSFLOOR-02 | Survey não respondido conta nota 1 no `DesempenhoScoreService::compute()` | Feature | `php artisan test --filter=NpsFloorDesempenhoTest` | ❌ Wave 0 |
| NPSFLOOR-03 | Empresa sem NENHUM survey no mês nunca vira 1 | Feature (regressão do invariante D3) | mesmo arquivo do NPSFLOOR-01/02, caso dedicado | ❌ Wave 0 |
| NPSFLOOR-04 | Empresa com `bonus_invalidacoes` na competência não puxa 1 no Desempenho | Feature | estender `tests/Feature/BonusInvalidacaoEmpresaTest.php` | ✅ existe, precisa de novo cenário |
| NPSFLOOR-05 | Responsável consolidado (gap conhecido) não vira 1 indevido | Feature | estender `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php` | ✅ existe, precisa de novo cenário |
| NPSFLOOR-06 | Não respondido parcial (1 modelo respondido, outro não) conta 1 só no modelo faltante | Feature | novo teste dedicado (multi-survey, mesma empresa) | ❌ Wave 0 |
| NPSFLOOR-07 | Resposta tardia (depois do fechamento) não reescreve a nota definitiva | Feature | novo teste dedicado, no molde de `JanelaNpsBonusTest` | ❌ Wave 0 |
| NPSFLOOR-08 | Comando de backfill idempotente + `--dry-run` com relatório antes/depois | Feature (Console) | novo teste dedicado, no molde de comandos de backfill existentes | ❌ Wave 0 |
| NPSFLOOR-10 | Suite existente permanece verde após bump de cacheKey | Regressão | `php artisan test` (full suite) | Requer atualização dos 4 arquivos listados em Q8 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Phase116` (ou o nome de suite escolhido pelo plano)
- **Per wave merge:** `php artisan test --filter=Nps` (toda a suite NPS) + `php artisan test --filter=Desempenho`
- **Phase gate:** Full suite (`php artisan test`) verde antes de `/gsd:verify-work`, incluindo os 4 arquivos de cacheKey hardcoded (Q8) atualizados para a nova versão.

### Wave 0 Gaps
- [ ] `tests/Feature/Phase116/NpsFloorAreaNpsTest.php` — cobre NPSFLOOR-01/03/09
- [ ] `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` — cobre NPSFLOOR-02/04/05/07
- [ ] `tests/Feature/Phase116/NpsFloorMultiModeloTest.php` — cobre NPSFLOOR-06
- [ ] `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` — cobre NPSFLOOR-08
- [ ] Atualizar (não criar) os 4 arquivos de Q8 (`DesempenhoShopeeScoreTest`, `NpsInvalidacaoRespostaTest`, `DesempenhoMetadadosCacheTest`) para a nova versão da cacheKey
- [ ] Auditar fixtures `pending`/`expired` "de passagem" nos 7 arquivos listados em Q8 (não são gaps de framework, são risco de fixture desatualizada)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Não | Nenhuma mudança de autenticação nesta fase |
| V3 Session Management | Não | N/A |
| V4 Access Control | Sim (parcial) | O comando de backfill/materialização é `php artisan` (acesso já restrito a quem tem acesso ao servidor/CI) — nenhum endpoint HTTP novo é necessário para a materialização em si; se o plano expuser um botão admin para disparar o comando pela UI, deve reusar `EnsureUserHasRole` (`role:admin`), mesmo padrão de todo o painel `/dev` |
| V5 Input Validation | Sim | Nenhum input de usuário final nesta fase (é processamento interno); se houver flags de competência no comando (`--mes=YYYY-MM`), validar formato antes de usar em query (mesmo padrão de `$mesFiltro` em `NpsController::index`, regex `^\d{4}-\d{2}$`) |
| V6 Cryptography | Não | N/A |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Comando de backfill rodado sem `--dry-run` por engano, aplicando nota 1 em massa retroativamente sem revisão | Tampering (dado financeiro do bônus) | Seguir o padrão já estabelecido: `--dry-run` sempre roda primeiro internamente, tabela de diff impressa, confirmação interativa obrigatória sem `--force` (D1 do CONTEXT já exige isso) |
| SQL injection via flag de mês/competência do comando novo | Tampering | Usar Carbon::parse + regex de validação (nunca interpolar string em `whereRaw`), mesmo padrão de `MetricPeriodResolver`/`NpsController::index` |

## Sources

### Primary (HIGH confidence — leitura direta do código nesta sessão)
- `app/Models/NpsSurvey.php`, `NpsSurveyEvent.php`, `NpsResponse.php`, `NpsResponseScore.php`, `NpsScoreAssignment.php`, `NpsResponseCoveredService.php`, `BonusInvalidacao.php`, `Company.php` (métodos `consultorDoServico`/`estrategistaDoServico`/`responsavelDoServicoOuConsolidado`)
- `app/Services/Nps/NpsSnapshotService.php`, `NpsScoreCalculator.php`, `NpsPendingService.php`
- `app/Services/DesempenhoScoreService.php` (compute, computeCached, cacheKey, computeNpsWindow, computeNpsMedio, notasPorAtribuicao, notasLegado)
- `app/Services/Metrics/MetricPeriodResolver.php`
- `app/Http/Controllers/NpsController.php`, `PerformanceController.php`, `DashboardController.php`, `PortfolioController.php`, `CompanyController.php`
- `app/Jobs/CalculateGoalResults.php`
- `app/Console/Commands/NpsDispararMensal.php`, `NpsBackfillAssignmentsConsolidado.php`
- `database/migrations/2026_04_26_152218_create_nps_surveys_table.php`, `2026_07_14_200001_create_nps_snapshot_tables.php`
- `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php`, `.planning/phases/96-nps-anti-burlamento-endurecimento-e-gest-o/96-RESEARCH.md` (mapa de call-sites reconfirmado)
- `tests/Feature/V18/JanelaNpsBonusTest.php`, `tests/Feature/V16/CriaCenarioResponsaveis.php`
- `.planning/config.json` (flags `nyquist_validation`, `features.language`)

### Secondary (MEDIUM confidence)
- Nenhuma — toda a pesquisa foi feita por leitura direta do repositório (sem WebSearch/Context7, pois o domínio é 100% interno ao projeto).

### Tertiary (LOW confidence)
- Nenhuma.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma dependência nova, confirmado por ausência de qualquer necessidade de pacote
- Architecture (mapa de call-sites, schema, recomendação de tabela nova): HIGH — schema lido diretamente das migrations, comportamento lido diretamente dos services/controllers, recomendação fundamentada em bloqueio de schema REAL (FK NOT NULL) e em precedente já existente no próprio código (união disjunta de `computeNpsMedio`)
- Pitfalls: HIGH — todos os 5 pitfalls foram confirmados lendo código real (status lazy, agrupamento por month_reference, fallback consolidado, inventário de call-sites da Fase 96, invalidação por competência)

**Research date:** 2026-07-27
**Valid until:** 2026-08-10 (14 dias — módulo NPS/Desempenho tem sofrido mudanças frequentes nas últimas semanas; números de linha e versão de cacheKey devem ser reconfirmados se o plano não for executado logo em seguida)
