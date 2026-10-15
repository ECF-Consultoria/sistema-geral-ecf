# Roadmap: ECF Admin — Milestone v15.0 NPS Templates

## Overview

Reescrita completa do módulo NPS baseado em **modelos configuráveis de formulário**. Sistema atual (v13.0 herdado) é rígido — 3 perguntas fixas (estrategista/analista/empresa) escala 1-5 + perguntas customizadas globais. Escopo v15.0 introduz templates por tipo de serviço, perguntas customizáveis com opções e pesos ajustáveis, cálculo por dimensão (estrategista/analista/empresa/geral), bloqueio de duplicata mensal, dashboards de pendência com "dia de cobrança" configurável e UX limpa do formulário público. **Zero uso de Promotor/Neutro/Detrator** — escala 1-5 sempre.

Prioridade dura: **não quebrar histórico**. Seed "NPS Padrão" cobrindo 100% do legado (zero survey órfã); dashboards atuais continuam funcionando durante toda a migração.

Histórico completo dos milestones anteriores (v1.0–v13.0): `.planning/MILESTONES.md` + arquivos em `.planning/milestones/`. v14.0 pausada — ROADMAP preservado em `.planning/milestones/v14.0-ROADMAP-wip.md`.

## Phases

**Phase Numbering:**

- Continuidade monotônica após v14.0 (última phase planejada: 67). v15.0 começa em Phase 68.
- Reservado 68–73 para os 6 blocos NPS-A a NPS-F
- Phase 74 adicionada 2026-07-09 — módulo Desempenho (bloco DESEMP) fora da milestone NPS original, mas anexado à v15.0 como tail
- Integer phases (68-74): trabalho planejado da milestone
- Decimal phases (68.1, 69.2…): reservadas para inserções urgentes durante execução

- [ ] **Phase 68: Schema, modelos e seed retroativo "NPS Padrão"** — 5 tabelas novas (`nps_templates`, `nps_template_questions`, `nps_template_options`, `nps_template_service_scopes`, `nps_response_answers`) + alter em `nps_surveys`/`nps_responses` + seed retro-associa 100% do histórico legado ao template padrão
- [ ] **Phase 69: Backend — regras de negócio, cálculo e dispatch** — `NpsTemplateService::resolveForCompany` (priority DESC + is_default fallback), `NpsScoreCalculator` por dimensão via `AVG(option_peso_snapshot)`, unique index parcial split por driver (MySQL virtual column / SQLite partial), guard QueryException 23000, comando `nps:disparar-mensal` usa template correto por empresa
- [x] **Phase 70: UI de Configuração (admin)** — CRUD de templates em `/nps/configuracao` (novo layout multi-template) + perguntas com dimensão/obrigatoriedade + opções com label/peso/ordem (Up/Down zero-deps) + associação template↔serviço + preview live do formulário
- [x] **Phase 71: Formulário público dinâmico** — `/nps/{token}` renderiza a partir do template snapshot; radio group cinza/amarelo ativo, mobile-friendly, marcador de obrigatoriedade, telas `ThankYou`/`AlreadyCompleted`/`Expired` preservadas, labels sem jargão técnico
- [x] **Phase 72: Dashboards + pendências + dia de cobrança** — Config global "dia de cobrança" (1-31), badge de pendência em `Portfolio/Show.jsx` e `Companies/Index.jsx`, contagem/lista no dashboard do analista/estrategista, `NpsPendingService` como contrato base, dashboards existentes leem via `NpsScoreCalculator`
- [x] **Phase 73: Limpeza de legado + testes E2E** — Remove `>=9 Promotor/>=7 Neutro/else Detrator` do `PerformanceController.php:301` + `Performance/Dashboard.jsx`; limpa refs `score_overall/consultant/mentor` em `Companies/Show.jsx` (fechamento do Plan 31-05); implementa `metric='nps'` em `CalculateGoalResults.php:155` usando `NpsScoreCalculator`; suite E2E completa
- [x] **Phase 74: Módulo Desempenho — simplificação para 4 parâmetros + bonificação** — reescrita da lógica de score da equipe Performance conforme spec da diretoria/gestão (2026-07-09). Substitui `PortfolioScoreService` (6 métricas ponderadas) por engine simplificada de 4 parâmetros: NPS médio, % variação de faturamento, % variação de margem de contribuição, absenteísmo (standby). Réguas 1-5 pontos por métrica, nota final média, faixas de bônus configuráveis via UI admin. Reescreve `Performance/{Dashboard,Index,Show}.jsx`, atualiza `SnapshotDesempenhoScores` cron, adiciona doc no `/manual` sincronizado com config (completed 2026-07-09)

## Phase Details

### Phase 68: Schema, modelos e seed retroativo "NPS Padrão"

**Goal**: Ter todas as tabelas + modelos Eloquent + seed retroativo que permitam representar templates configuráveis e associar 100% do histórico legado ao template padrão sem quebrar dashboards atuais.
**Depends on**: Nada (fundação)
**Requirements**: NPS-A-01, NPS-A-02, NPS-A-03, NPS-A-04
**Success Criteria** (o que deve ser VERDADE):

  1. Migration cria as 5 tabelas novas (`nps_templates`, `nps_template_questions`, `nps_template_options`, `nps_template_service_scopes`, `nps_response_answers`) com FKs, índices e constraints conforme spec do research (§1)
  2. Modelos Eloquent (`NpsTemplate`, `NpsTemplateQuestion`, `NpsTemplateOption`, `NpsResponseAnswer`) têm relationships definidas (`hasMany`/`belongsToMany`) e casts corretos
  3. Seed "NPS Padrão" existe com `is_default=true`, cobre as 3 perguntas legadas (estrategista/analista/empresa) escala 1-5 e retro-associa **100%** dos `nps_surveys` existentes via `template_id` — nenhuma survey fica órfã
  4. `nps_response_answers` armazena snapshot congelado (`question_texto_snapshot`, `question_dimensao_snapshot`, `option_label_snapshot`, `option_peso_snapshot`) — mudanças futuras no template não alteram histórico gravado
  5. Dashboards existentes (NPS mensal, `Performance/Dashboard.jsx`) continuam renderizando dados legados sem quebra visual pós-migration

**Plans**: 5 plans em 4 waves — 68-01 (Wave 1: 3 migrations schema) → 68-02 (Wave 2: 4 Models + 4 Factories) + 68-04 (Wave 2: migration dedup_key virtual + unique parcial split por driver) → 68-03 (Wave 3: seed NPS Padrão + retro-associação idempotente) → 68-05 (Wave 4: 3 arquivos de teste Feature — schema, seed, backward-compat)

- [x] 68-01-PLAN.md — 3 migrations criando 5 tabelas novas + alter template_id em nps_surveys + score_* nullable em nps_responses
- [x] 68-02-PLAN.md — 4 Models Eloquent novos (NpsTemplate/Question/Option/Answer) + updates em NpsSurvey/NpsResponse + 4 factories
- [x] 68-03-PLAN.md — migration de seed template NPS Padrão + retro-associação 100% das surveys legadas via UPDATE transacional idempotente
- [x] 68-04-PLAN.md — migration dedup_key virtual + unique index parcial split por driver (MySQL virtual column, SQLite partial index)
- [ ] 68-05-PLAN.md — 3 testes Feature (NpsSchemaTest 8+, NpsSeedRetroactiveTest 6+, NpsBackwardCompatTest 5+) validando SC1-SC5 do ROADMAP

### Phase 69: Backend — regras de negócio, cálculo e dispatch

**Goal**: Regras de negócio implementadas em services + validação server-side + dedup mensal garantido no DB + dispatch mensal usando template correto por empresa.
**Depends on**: Phase 68 (precisa das tabelas e da coluna virtual dedup)
**Requirements**: NPS-B-01, NPS-B-02, NPS-B-03, NPS-B-04, NPS-B-05
**Success Criteria** (o que deve ser VERDADE):

  1. `NpsTemplateService::resolveForCompany(Company)` retorna o template correto respeitando `priority DESC + is_default` fallback e usando `nps_template_service_scopes` (research §4)
  2. `NpsScoreCalculator::compute(NpsResponse, dimensao)` calcula média dos `option_peso_snapshot` das answers da dimensão pedida; retorna `null` quando não há perguntas dessa dimensão (não zero, não erro)
  3. Segunda tentativa de responder NPS para o mesmo (`company_id`, `month_reference`, `template_id`) é bloqueada pelo DB (unique index parcial via virtual column MySQL / partial SQLite conforme research §2); controller captura `QueryException 23000` e mostra tela "Já respondida no mês"
  4. Comando `nps:disparar-mensal` chama `NpsTemplateService::resolveForCompany` por empresa; empresas sem template aplicável (nem default) são puladas com `Log::warning` estruturado — comando **não crasha** o batch
  5. Validação server-side do formulário público (`NpsController::submit`) deriva regras de obrigatoriedade e range de peso do template snapshot da survey, não de defaults hardcoded

**Plans**: 6 plans em 4 waves — 69-01 (Wave 1: NpsTemplateService) + 69-02 (Wave 1: NpsScoreCalculator) paralelos → 69-04 (Wave 2: NpsController::generate) + 69-05 (Wave 2: NpsDispararMensal) paralelos → 69-03 (Wave 3: NpsController::submitResponse dinâmico + guard 23000) → 69-06 (Wave 4: suite E2E integração 5 fluxos)

- [x] 69-01-PLAN.md — NpsTemplateService::resolveForCompany (priority DESC + is_default fallback + RuntimeException guard)
- [x] 69-02-PLAN.md — NpsScoreCalculator::compute (AVG option_peso_snapshot por dimensão, null-safe)
- [x] 69-03-PLAN.md — NpsController::submitResponse validação dinâmica + snapshot per-row + guard QueryException 23000
- [x] 69-04-PLAN.md — NpsController::generate usa NpsTemplateService (associa template_id no survey manual)
- [x] 69-05-PLAN.md — NpsDispararMensal usa NpsTemplateService com skip-log guard (empresas sem template não crasham batch) — 2026-07-08 (5/5 tests + 72/72 regressão)
- [ ] 69-06-PLAN.md — Suite Feature E2E integrando os 5 SC (dispatch + generate + dedup 23000 + validação dinâmica + snapshot)

### Phase 70: UI de Configuração (admin)

**Goal**: Admin consegue criar e editar templates de NPS completos (perguntas + opções + pesos + associação com serviço) e enxergar preview do formulário público antes de publicar.
**Depends on**: Phase 69 (backend precisa estar pronto para validar payloads e servir preview via `NpsTemplateService`)
**Requirements**: NPS-C-01, NPS-C-02, NPS-C-03, NPS-C-04, NPS-C-05, NPS-C-06
**Success Criteria** (o que deve ser VERDADE):

  1. Admin acessa `/nps/configuracao`, vê lista de templates existentes (padrão + criados), consegue criar, editar título/descrição/ativo, e desativar sem apagar (soft flag)
  2. Dentro de um template, admin adiciona/edita/remove perguntas escolhendo tipo (`escala` gera 5 opções 1-5 auto-editáveis conforme research §5; `opcoes` inicia vazio); ordem controlada por Up/Down + input `type=number` (zero-deps conforme research §3)
  3. Para cada pergunta, admin configura opções (label visível ao cliente + peso interno 1-5 + ordem), marca dimensão (`estrategista`/`analista`/`empresa`/`geral`) e obrigatoriedade
  4. Admin associa o template a um ou mais tipos de serviço via UI de pivot `nps_template_service_scopes`; feedback visual mostra empresas afetadas
  5. Preview live renderiza o formulário público a partir do estado atual do form de edição, sem persistir no banco — usa o mesmo componente que a Phase 71 vai construir para o `/nps/{token}` real

**Plans**: 6 plans em 4 waves — Wave 1 paralelo (70-01 CRUD templates + 70-02 CRUD perguntas com auto-gerar options escala + 70-03 CRUD opções com peso 1..5) → Wave 2 (70-04 sync service scopes + preview endpoint stateless + empresas-afetadas) → Wave 3 (70-05 reescrita Configuracao.jsx com 6 componentes filhos + preview live debounced + legado preservado sob /textos-legado) → Wave 4 (70-06 Feature tests 24 cobrindo SC1-SC5)

- [x] 70-01-PLAN.md — NpsTemplateController CRUD + FormRequests + 4 rotas admin-only + guard is_default
- [x] 70-02-PLAN.md — NpsTemplateQuestionController CRUD + auto-5-options em tipo=escala + SWAP reorder + scopeBindings
- [x] 70-03-PLAN.md — NpsTemplateOptionController CRUD + peso 1..5 + guard mínimo 1 opção em escala + scopeBindings
- [x] 70-04-PLAN.md — syncServicos + empresasAfetadas (reusa NpsTemplateService Plan 69-01) + preview endpoint stateless
- [x] 70-05-PLAN.md — Reescrita Configuracao.jsx multi-template com 6 componentes filhos + PreviewFormulario portável para Phase 71 + zero libs novas
- [x] 70-06-PLAN.md — Suite Feature tests Phase70 (24 testes) cobrindo SC1-SC5 + baseline regressão zero

**UI hint**: yes

### Phase 71: Formulário público dinâmico

**Goal**: Cliente responde `/nps/{token}` a partir do template snapshot da survey — sem hardcode das 3 perguntas antigas — com UX limpa (cinza/amarelo), mobile-friendly e labels sem jargão técnico.
**Depends on**: Phase 70 (form público reusa componentes do preview) e Phase 69 (validação server-side)
**Requirements**: NPS-D-01, NPS-D-02, NPS-D-03, NPS-D-04, NPS-D-05
**Success Criteria** (o que deve ser VERDADE):

  1. `/nps/{token}` renderiza as perguntas dinamicamente a partir do `template_snapshot_json` da survey — nunca hardcoded; abrir survey de template A e survey de template B mostra formulários distintos
  2. Perguntas com opções renderizam como radio group com estilo cinza no estado padrão + amarelo (`ecf-yellow`) no estado ativo/selecionado; layout responsivo em telas mobile (≤ 400px de largura)
  3. Perguntas obrigatórias são visualmente marcadas (asterisco + texto "obrigatório"); botão de submit fica desabilitado até que todas obrigatórias tenham resposta; server-side devolve 422 com mensagem clara se cliente contornar client-side
  4. Fluxo pós-submit preserva as telas `ThankYou`, `AlreadyCompleted` e `Expired` existentes — comportamento inalterado; token expirado ainda renderiza tela `Expired`
  5. Nenhuma label apresentada ao cliente contém jargão técnico (`unified`, `dimensao`, `snapshot`, `estrategista`, `analista` só se corresponder a papel do time visível ao cliente) — textos em pt-BR simples

**Plans**: 3 plans em 3 waves — Wave 1 (71-01 backend NpsController::respond eager-load + inject template prop dual-path) → Wave 2 (71-02 refactor PreviewFormulario controlled + reescrita Respond.jsx + RespondLegado.jsx preserva Phase 33) → Wave 3 (71-03 Feature tests 10 cobrindo SC1-SC5)

- [x] 71-01-PLAN.md — NpsController::respond eager-load template.questions.options + inject template prop condicional (null em legacy)
- [x] 71-02-PLAN.md — PreviewFormulario controlled props + reescrita Respond.jsx delegando ao RespondLegado quando template null
- [x] 71-03-PLAN.md — Suite Feature Phase71 (10 testes) cobrindo SC1-SC5 + baseline regressão zero

**UI hint**: yes

### Phase 72: Dashboards + pendências + dia de cobrança

**Goal**: Consultoria (analista/estrategista/admin) enxerga claramente quais empresas ainda não responderam o NPS do mês corrente, com base preparada para futura notificação interna.
**Depends on**: Phase 69 (precisa de `NpsScoreCalculator` + `NpsTemplateService` para saber quem deveria ter respondido)
**Requirements**: NPS-E-01, NPS-E-02, NPS-E-03, NPS-E-04, NPS-E-05
**Success Criteria** (o que deve ser VERDADE):

  1. Sistema tem configuração global "dia de cobrança mensal" (int 1-31) que dispara marcação de pendência a partir daquele dia do mês corrente (editável via UI admin ou config file — implementação a definir no plan)
  2. Listagem de empresas em carteira (`Portfolio/Show.jsx` e `Companies/Index.jsx`) mostra badge/indicador visual quando empresa está em pendência de NPS do mês corrente
  3. Dashboard do analista/estrategista mostra contagem + lista das empresas pendentes de NPS no mês corrente dentro da sua carteira; admin vê versão consolidada
  4. `NpsPendingService::forCarteira(User)` existe e retorna a lista de empresas pendentes por carteira; contrato de retorno documentado para futura integração com sistema de notificações (integração real fica para NPS-FUTURE-03)
  5. Dashboards existentes (`Dashboard/Admin.jsx`, `Performance/Dashboard.jsx`, `Companies/Show.jsx`) leem médias por dimensão via `NpsScoreCalculator` respeitando `template_snapshot` — números batem com o novo cálculo

**Plans**: 4 plans em 4 waves — Wave 1 (72-01 NpsPendingService + config dia_cobranca admin CRUD + widget Configuracao.jsx) → Wave 2 (72-02 dashboards backend recebem nps_pendentes prop + CompanyController::show usa NpsScoreCalculator dual-path) → Wave 3 (72-03 NpsPendingBadge + NpsPendingWidget componentes + integração em 5 páginas) → Wave 4 (72-04 Feature tests 16-17 cobrindo SC1-SC5 + baseline regressão zero)

- [x] 72-01-PLAN.md — NpsPendingService (forCarteira/isPendente/diaCobranca clamp 1..31) + PATCH admin dia_cobranca + widget config Configuracao.jsx
- [x] 72-02-PLAN.md — Dashboards backend recebem nps_pendentes + CompanyController::show usa NpsScoreCalculator para v15 (legado preservado)
- [x] 72-03-PLAN.md — NpsPendingBadge (Portfolio/Show, Companies/Index) + NpsPendingWidget (Dashboard/Admin, Dashboard/User, Performance/Dashboard) — orange-500 tokens
- [x] 72-04-PLAN.md — Suite Feature Phase72 (16 tests) cobrindo SC1-SC5 + baseline regressão zero

**UI hint**: yes

### Phase 73: Limpeza de legado + testes E2E

**Goal**: Zero uso de Promotor/Neutro/Detrator no código; refs legadas de scores removidas; `metric='nps'` no `CalculateGoalResults` implementado de verdade; suite E2E cobrindo os 10 critérios de aceite do brief.
**Depends on**: Phase 72 (novos consumers em cima antes de desativar código legado)
**Requirements**: NPS-F-01, NPS-F-02, NPS-F-03, NPS-F-04
**Success Criteria** (o que deve ser VERDADE):

  1. `grep -rn "Promotor\|Neutro\|Detrator"` em `app/` e `resources/js/` retorna zero resultado — cálculo em `PerformanceController.php:301` e `Performance/Dashboard.jsx` foi removido
  2. `Companies/Show.jsx` não contém mais refs a `score_overall`, `score_consultant`, `score_mentor` — nota exibida vem exclusivamente do novo cálculo via `NpsScoreCalculator`; `CompanyController::show` deixa de compor essas chaves como fallback
  3. `CalculateGoalResults.php:155` implementa cálculo real para `metric='nps'` usando `NpsScoreCalculator` — meta de NPS tem progresso; branch `null` só é atingido quando não há resposta no período
  4. Suite E2E (`tests/Feature/Phase73/`) cobre: criação de template + perguntas com pesos, resposta pública, cálculo por dimensão (incluindo dimensão sem perguntas retornando null), bloqueio de duplicata (unique index parcial), dispatch idempotente pelo comando, empresa pendente aparece corretamente, template sem analista funciona sem quebrar
  5. Suite completa `php artisan test` continua verde (delta = 0 vs baseline pré-Phase 73) — zero regressão em dashboards, sugadores, metas, publicação

**Plans**: 4 plans em 3 waves — Wave 1 (73-01 backend cleanup PerformanceController + DashboardController) → Wave 2 (73-02 CalculateGoalResults metric='nps' + 73-03 frontend cleanup Performance/Dashboard.jsx + Companies/Show.jsx) → Wave 3 (73-04 E2E suite)

- [x] 73-01-PLAN.md — PerformanceController.php:301 remove classificação + DashboardController promotores/neutros/detratores → positivas/negativas + $scoreField → NpsScoreCalculator (COMPLETO 2026-07-08 — commits 9a00de6 + 623336a; SC#1 backend atendido, delta zero preservado)
- [x] 73-02-PLAN.md — CalculateGoalResults.php:155 implementa metric='nps' real via NpsScoreCalculator dual-path (COMPLETO — commit a607262)
- [x] 73-03-PLAN.md — Performance/Dashboard.jsx cor por threshold direto + Companies/Show.jsx remove refs obsoletas (COMPLETO — commits 803dcbc + 4360a53 fix Admin.jsx shape positivas/negativas)
- [x] 73-04-PLAN.md — Suite E2E Phase73 (NpsV15E2ETest 5 tests linear + NpsGoalMetricNpsTest 3 tests) (COMPLETO — commit d8d0c39, 8 tests / 83 assertions)

**UI hint**: yes

### Phase 74: Módulo Desempenho — simplificação para 4 parâmetros + bonificação

**Goal**: Substituir o `PortfolioScoreService` atual (6 métricas ponderadas com pesos por categoria) por um `DesempenhoScoreService` de **4 parâmetros** (NPS médio, % variação de faturamento vs mês anterior, % variação de margem de contribuição vs mês anterior, absenteísmo em standby), com cálculo por **média direta em escalas naturais**, consolidação **mensal fechada** (dia 1 do mês seguinte após sync Adman), faixas de bônus editáveis pelo admin via UI dedicada e artigo dinâmico no `/manual` sincronizado com a config.
**Depends on**: Phase 72 (`NpsScoreCalculator` dual-path); MetricsProviderFactory (Phase 61 flow ML-first + Adman fallback)
**Requirements**: DESEMP-01, DESEMP-02, DESEMP-03, DESEMP-04, DESEMP-05, DESEMP-06, DESEMP-07, DESEMP-08, DESEMP-09, DESEMP-10, DESEMP-11, DESEMP-12, DESEMP-13, DESEMP-14
**Success Criteria** (o que deve ser VERDADE):

  1. Fixture Carlos (NPS 4.25 + var_fat 3% + var_margem 2.8%) retorna `nota_final = 3.35` e `faixa_bonus = 'sem_bonus'` em teste feature
  2. Tabela `bonus_faixas` criada com 4 rows seed (`sem_bonus`, `basico`, `intermediario`, `maximo`) editáveis via `/desempenho/configuracao` (role:admin)
  3. Comando `desempenho:consolidar-mes` roda dia 1 de cada mês às 14:00 BRT (`monthlyOn(1, '14:00')`) e grava snapshot com `mes_referencia = YYYY-MM-01`; comando `desempenho:snapshot-scores` (diário 13:30) PRESERVA schedule e grava com `mes_referencia = NULL`
  4. Regra "2 meses consecutivos intermediário → máximo" testada com snapshot [junho: intermediario, julho: intermediario] → julho retorna `maximo`
  5. `grep -r "PortfolioScoreService" app/ resources/js/` retorna 0 matches ativos (código v1 deletado); dashboard `Performance/Dashboard.jsx` filtra `mes_referencia >= '2026-08-01'` e Absenteísmo mostra placeholder "Em breve"; artigo `/manual/desempenho-bonificacao` renderiza faixas em tempo real

**Plans**: 10 plans em 5 waves — Wave 1 paralelo (74-01 ALTER desempenho_score_snapshots + 74-02 CREATE bonus_faixas + Model BonusFaixa + seed) → Wave 2 (74-03 DesempenhoScoreService) → Wave 3 paralelo (74-04 refactor 3 controllers + reescrita SnapshotDesempenhoScores + novo ConsolidarMesDesempenho + delete v1 + 74-05 DesempenhoConfigController + FormRequest + rotas + sidebar) → Wave 4 paralelo (74-06 Performance/{Dashboard,Index,Show}.jsx reescritas + 74-07 Desempenho/Configuracao.jsx + 74-08 Manual/Artigos/DesempenhoBonificacao.jsx + ManualController::show evoluído) → Wave 5 paralelo (74-09 DesempenhoScoreServiceTest fixture Carlos + 11 testes + 74-10 DesempenhoConfigControllerTest 11 + ConsolidarMesDesempenhoCommandTest 7 + regressão zero)

- [x] 74-01-PLAN.md — Migration ALTER desempenho_score_snapshots (add mes_referencia + drop/create unique + índice mes_referencia+score) + Model DesempenhoScoreSnapshot (fillable/cast + scopes mensal/diario)
- [x] 74-02-PLAN.md — Migration CREATE bonus_faixas + migration seed 4 faixas + Model BonusFaixa (LogsActivity + classificar static) + Factory
- [x] 74-03-PLAN.md — DesempenhoScoreService completo (compute + computeNpsMedio + computeVarFaturamento ML-first + computeVarMargem Adman-only + computeAbsenteismo null placeholder + computeNotaFinal média direta + classificarFaixa + promoverPor2MesesConsecutivos + computeUniverso sem_carteira)
- [x] 74-04-PLAN.md — Refactor 3 controllers para DesempenhoScoreService + reescrita interna SnapshotDesempenhoScores + novo ConsolidarMesDesempenho + schedule mensal `monthlyOn(1,'14:00')` + DELETE PortfolioScoreService.php (COMPLETO 2026-07-09 — commits 13b6ee1 + 1a94faf)
- [x] 74-05-PLAN.md — DesempenhoConfigController (index/updateFaixa/toggleActive) + UpdateBonusFaixaRequest (validação range + sobreposição pt-BR) + 3 rotas admin + sidebar "Configuração Desempenho"
- [x] 74-06-PLAN.md — Performance/{Dashboard,Index,Show}.jsx reescritas — 4 cards de parâmetros + faixa de bônus + toggle mês fechado/parcial/diário + filtro sem_carteira no ranking + badge "Em breve" no Absenteísmo (COMPLETO 2026-07-09 — commits 7b5cf38 + 8a0fd84 + 6c49160; PerformanceController::show() adaptado como Rule 2 deviation)
- [x] 74-07-PLAN.md — Desempenho/Configuracao.jsx (UI React admin — CRUD faixas inline + validação inline + toast + toggle ativo/inativo) (COMPLETO 2026-07-09 — commit a257441)
- [x] 74-08-PLAN.md — Manual/Artigos/DesempenhoBonificacao.jsx + artigos.js entry + ManualController::show evoluído (passa bonus_faixas prop) + Manual/Show.jsx spread artigoProps (COMPLETO 2026-07-09 — commit 1d42f92; artigo dinâmico em sync com bonus_faixas, sem cache)
- [x] 74-09-PLAN.md — Suite tests/Feature/Phase74/DesempenhoScoreServiceTest (12 testes verdes / 38 asserções — fixture Carlos âncora `nota_final=3.35 sem_bonus`, dual-path NPS legacy, empresa nova, provider ML-first/Adman-fallback, promoção 2 meses, sem_carteira pt-BR) (COMPLETO 2026-07-09 — commit 980c013)
- [x] 74-10-PLAN.md — Suites tests/Feature/Phase74/DesempenhoConfigControllerTest (11 testes verdes: 403 não-admin, CRUD, sobreposição, toggle) + ConsolidarMesDesempenhoCommandTest (7 testes verdes: --mes flag, idempotência, sem_carteira pula, ranking_pos, diário preservado com mes_referencia=null) + regressão zero em 4 suites Feature legadas adaptadas ao shape v2 (32 testes verdes / 198 asserções) (COMPLETO 2026-07-09 — commits 24134c0 + 096d72f)

**UI hint**: yes

## Phase Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 68. Schema, modelos e seed retroativo | 4/5 | In Progress|  |
| 69. Backend regras de negócio | 5/6 | In Progress|  |
| 70. UI de Configuração | 6/6 | Complete | 2026-07-08 |
| 71. Formulário público | 3/3 | Complete | 2026-07-08 |
| 72. Dashboards + pendências | 4/4 | Complete | 2026-07-08 |
| 73. Limpeza legado + testes E2E | 4/4 | Complete | 2026-07-08 |
| 74. Módulo Desempenho (4 parâmetros + bonificação) | 10/10 | Complete    | 2026-07-09 |

## Dependencies

**Sequencial obrigatório:**

- **68 → 69** — backend precisa das tabelas + coluna virtual dedup
- **69 → 70** — UI Config precisa do `NpsTemplateService` para validar payloads e servir preview
- **69 → 72** — dashboards precisam de `NpsScoreCalculator` + `NpsPendingService`
- **70 → 71** — form público reusa componentes do preview live de config
- **72 → 73** — limpeza só depois que todos os novos consumers estiverem em cima
- **72 → 74** — Phase 74 reusa `NpsScoreCalculator` dual-path da Phase 72; Phase 61 fornece `MetricsProviderFactory` ML-first + Adman fallback

**Paralelizáveis:**

- **70 e 72** podem rodar em paralelo após 69 (UI Config e dashboards não se tocam)
- **74** é independente de 68/69 (não usa templates NPS; consome apenas o `NpsScoreCalculator` API estável)

## Coverage Map

Todas as 29 REQs de v15.0 NPS + 14 REQs de DESEMP mapeadas para exatamente uma phase:

| Categoria | REQ | Phase |
|-----------|-----|-------|
| NPS-A (Schema) | NPS-A-01 | Phase 68 |
| NPS-A | NPS-A-02 | Phase 68 |
| NPS-A | NPS-A-03 | Phase 68 |
| NPS-A | NPS-A-04 | Phase 68 |
| NPS-B (Backend) | NPS-B-01 | Phase 69 |
| NPS-B | NPS-B-02 | Phase 69 |
| NPS-B | NPS-B-03 | Phase 69 |
| NPS-B | NPS-B-04 | Phase 69 |
| NPS-B | NPS-B-05 | Phase 69 |
| NPS-C (UI Config) | NPS-C-01 | Phase 70 |
| NPS-C | NPS-C-02 | Phase 70 |
| NPS-C | NPS-C-03 | Phase 70 |
| NPS-C | NPS-C-04 | Phase 70 |
| NPS-C | NPS-C-05 | Phase 70 |
| NPS-C | NPS-C-06 | Phase 70 |
| NPS-D (Form público) | NPS-D-01 | Phase 71 |
| NPS-D | NPS-D-02 | Phase 71 |
| NPS-D | NPS-D-03 | Phase 71 |
| NPS-D | NPS-D-04 | Phase 71 |
| NPS-D | NPS-D-05 | Phase 71 |
| NPS-E (Dashboards) | NPS-E-01 | Phase 72 |
| NPS-E | NPS-E-02 | Phase 72 |
| NPS-E | NPS-E-03 | Phase 72 |
| NPS-E | NPS-E-04 | Phase 72 |
| NPS-E | NPS-E-05 | Phase 72 |
| NPS-F (Limpeza) | NPS-F-01 | Phase 73 |
| NPS-F | NPS-F-02 | Phase 73 |
| NPS-F | NPS-F-03 | Phase 73 |
| NPS-F | NPS-F-04 | Phase 73 |
| DESEMP (Desempenho v2) | DESEMP-01 | Phase 74 |
| DESEMP | DESEMP-02 | Phase 74 |
| DESEMP | DESEMP-03 | Phase 74 |
| DESEMP | DESEMP-04 | Phase 74 |
| DESEMP | DESEMP-05 | Phase 74 |
| DESEMP | DESEMP-06 | Phase 74 |
| DESEMP | DESEMP-07 | Phase 74 |
| DESEMP | DESEMP-08 | Phase 74 |
| DESEMP | DESEMP-09 | Phase 74 |
| DESEMP | DESEMP-10 | Phase 74 |
| DESEMP | DESEMP-11 | Phase 74 |
| DESEMP | DESEMP-12 | Phase 74 |
| DESEMP | DESEMP-13 | Phase 74 |
| DESEMP | DESEMP-14 | Phase 74 |

**Cobertura:** 29/29 v15.0 NPS REQs + 14/14 DESEMP REQs mapeadas ✓ — zero órfãos, zero duplicatas.

## Decisões técnicas travadas (research)

Ver `.planning/research/v15-nps-templates-schema.md` para detalhes:

1. **Snapshot per-row em `nps_response_answers`** (não JSON no survey, não Spatie ActivityLog) — colunas `question_*_snapshot` + `option_*_snapshot` + índice `(response_id, question_dimensao_snapshot)`
2. **Unique index parcial via generated column virtual (MySQL) / partial index (SQLite)** — split por `DB::connection()->getDriverName()`; NULL não colide em unique; controller trata `QueryException 23000` para UX
3. **Drag-and-drop = sem library** — Up/Down buttons + input `type="number"` de ordem, mantém padrão zero-deps v13/v14
4. **Precedência via `priority` DESC + `is_default` fallback** — determinístico, sem depender de `pivot.created_at`
5. **`escala` = 5 opções auto-geradas + editáveis** — 1 tabela `nps_template_options` unificada, `NpsScoreCalculator` uniforme via `AVG(option_peso_snapshot)`

**Phase 74 (Desempenho v2)** — decisões locked em `.planning/phases/74-.../74-SPEC.md` + `74-CONTEXT.md`:

6. **`bonus_faixas` como tabela dedicada** (não `Configuracao` key/value) — permite LogsActivity + validação de sobreposição + join direto para artigo dinâmico do Manual
7. **Big bang v1→v2 no mesmo commit** (D-06/DESEMP-14) — sem `@deprecated`, sem coexistência; snapshots antigos ficam preservados mas UI filtra `mes_referencia >= '2026-08-01'`
8. **Duas modalidades coexistindo na mesma tabela** (D-02) — snapshot diário (`mes_referencia=NULL`) + mensal (`mes_referencia=YYYY-MM-01`); unique key novo `(user_id, ref_date, mes_referencia)` permite ambos
9. **Fixture Carlos como âncora bloqueante** (D-28/DESEMP-01) — teste dedicado que trava `nota_final=3.35` + `faixa_bonus=sem_bonus`; se este falha, cálculo divergiu da decisão da diretoria

## Constraints herdadas

- Stack Laravel 12 + Inertia.js + React (nada novo)
- Design system `ecf-*` tokens, dark theme, `cn()` utility, componentes shadcn/ui
- NPS atual (v13.0 herdado) fica preservado durante toda a migração — dashboards continuam funcionando com dados legados via seed "NPS Padrão"
- Comentários em pt-BR
- Escala 1-5 SEMPRE — nunca 0-10 clássico NPS
- Deploy gate ativo — perguntar antes de deploy.sh (outro dev em paralelo)

### Phase 75: Empresas Shopee — habilitar NPS para clientes atendidos na Shopee (sem métricas/API)

**Goal:** Permitir cadastrar (pelo Comercial) empresas atendidas SÓ na Shopee — sem ML, sem métricas/API — e gerar NPS em nome delas, via uma aba "Empresas" da Shopee enxuta (pendências mínimas pro NPS + atribuição Analista/Estrategista), gated pela permission `shopee.empresas`, sem nenhuma mudança no motor de NPS.
**Requirements**: DEC-1, DEC-2, DEC-3, DEC-4, DEC-5 (decisões LOCKED do 75-CONTEXT.md)
**Depends on:** Backend NPS (Phases 68–73). Independente do Desempenho v2 (Phase 74).
**Plans:** 5/5 plans executed — VERIFICATION: passed-with-notes (checkpoint visual humano pendente)

Plans:

- [x] 75-01-PLAN.md — Fundação de dados: enum servicos.setor→'shopee' (cross-driver) + constante Servico::SETOR_SHOPEE + seed do serviço "Shopee" [DEC-1]
- [x] 75-02-PLAN.md — Permission key `shopee.empresas` no catálogo estático [DEC-3]
- [x] 75-03-PLAN.md — Cadastro Comercial de empresa Shopee sem ML (sem MlbEmpresa) [DEC-1]
- [x] 75-04-PLAN.md — Backend da aba: ShopeeEmpresasController + rotas gated + pendências + atribuição + NPS gerável [DEC-2, DEC-4, DEC-5]
- [x] 75-05-PLAN.md — Frontend: página Shopee/Empresas.jsx enxuta + grupo Shopee no menu + verificação visual [DEC-3, DEC-4, DEC-5]

### Phase 76: Responsáveis por serviço — company_users com dimensão de serviço (fundação v16.0)

**Goal:** A pivot company_users ganha dimensao de servico (servico_id): a atribuicao de responsaveis passa a ser por-servico (corrige o risco da Phase 75 — atribuir Shopee nao apaga o responsavel ML) e TODO o comportamento consolidado atual (carteira, pendencias, notificacoes, bonus) permanece identico, provado por teste de regressao.
**Requirements**: DEC-A1, DEC-A2, DEC-A3
**Depends on:** Phase 75
**Plans:** 4/4 plans executed — VERIFICATION passed-with-notes (FK MySQL a validar no VPS pós-deploy)

Plans:

- [x] 76-01-PLAN.md — Fundacao de testes V16 + migration cross-driver (servico_id + unique 4-col) + data-migration idempotente (DEC-A1)
- [x] 76-02-PLAN.md — Relacoes consolidadas blindadas (distinct) + variantes service-aware + invariante/carteira nao dobra (DEC-A2)
- [x] 76-03-PLAN.md — Reescrita das 3 escritas escopadas por servico_id + teste de isolamento ML×Shopee (DEC-A3)
- [x] 76-04-PLAN.md — Regressao dos leitores Grupo A/B + phase gate suite completa (DEC-A2)

### Phase 77: Setor Shopee organizacional — cargos e usuários (Felipe/Gustavo) (v16.0)

**Goal:** Existe um Setor organizacional "Shopee" (RBAC) com cargos analista/estrategista e a permission `shopee.empresas`; Felipe (`consultor.02`) é estrategista + líder do setor Shopee; Gustavo (`suporte.11`) é analista no setor Shopee e no Performance — tudo por migration idempotente que pula usuários ausentes sem erro.
**Requirements**: DEC-77-1, DEC-77-2, DEC-77-3, DEC-77-4
**Depends on:** Phase 76
**Plans:** 1/1 plans complete — VERIFICATION passed (9 testes; Felipe/Gustavo reais a validar no VPS pós-deploy)

Plans:

- [x] 77-01-PLAN.md — Migration idempotente do Setor Shopee (cargos + permissão + wiring Felipe/Gustavo por email) + suite Feature V16 provando os 6 pontos de validação

### Phase 78: Comercial e aba Shopee — gerenciar serviço/responsáveis e revisar ações (revisa Phase 75) (v16.0)

**Goal:** Aba /shopee/empresas EXCLUSIVA do líder do Setor Shopee (+ admin): selects listam só profissionais do Setor Shopee; botão "Resolver" na aba Pendências abre popup (atribuir Analista/Estrategista Shopee + contato); remover "Gerar NPS"; Excluir = cancelar só o serviço Shopee. Comercial NÃO atribui responsável (empresa vai pra Pendências).
**Requirements**: DEC-78-1..DEC-78-4 (78-CONTEXT.md). DEC-78-5 CANCELADO (correção do usuário: quem atribui é o líder, não o Comercial).
**Depends on:** Phase 76 (por-serviço) + Phase 77 (Setor Shopee)
**Plans:** COMPLETA (78-01/02 + acesso líder-only) — executada inline, deployada.

Plans:

- [x] 78-01-PLAN.md — Backend: selects escopados ao Setor Shopee + pendência sem_responsavel por-serviço + endpoints resolver() e cancelarServico() [DEC-78-1,2,4] — deployado
- [x] 78-02-PLAN.md — Frontend: remover Gerar NPS + botão Resolver → popup (selects Shopee + email) + Excluir=cancelar serviço [DEC-78-2,3,4] — deployado (checkpoint visual pendente)
- [x] Acesso líder-only — /shopee/empresas exclusivo do líder do Setor Shopee (User::effectivePermissions + migration remove grant de membros); gate líder→200/membro→403
- [~] 78-03 CANCELADO — Comercial NÃO atribui responsável (correção do usuário: quem atribui é o líder na aba Pendências)

### Phase 79: NPS multi-modelo — disparo por serviços cobertos + snapshot de atribuições por serviço (v16.0)

**Goal:** O NPS opera multi-modelo por "Serviços cobertos": empresa com serviços em áreas diferentes (ML + Shopee) recebe 1 NPS por modelo; cada resposta congela (snapshot) as médias por dimensão, os serviços cobertos e as atribuições média×pessoa SÓ aos responsáveis dos serviços cobertos ∩ ativos. Bônus intocado (Fase 80); zero regressão no NPS atual.
**Requirements**: DEC-79-A, DEC-79-B, DEC-79-C, DEC-79-D, DEC-79-E
**Depends on:** Phase 78 (76 obrigatória; 77 desejável)
**Plans:** 4/4 plans complete

Plans:

- [x] 79-01-PLAN.md — Wave 1: migrations das 3 tabelas de snapshot (nps_response_scores/covered_services/score_assignments) + models (DEC-79-C)
- [x] 79-02-PLAN.md — Wave 1: seed idempotente do NPS Shopee + link performance→NPS Padrão em service_scopes (DEC-79-B, DEC-79-A)
- [x] 79-03-PLAN.md — Wave 2: disparo estrito no NpsDispararMensal (1 envio/modelo por serviços cobertos, guard template_id, log rollout) (DEC-79-A)
- [x] 79-04-PLAN.md — Wave 2: snapshot no submit (NpsSnapshotService: scores/covered/assignments) + regressão do bônus (DEC-79-D, DEC-79-E)

### Phase 80: Bônus e relatórios — DesempenhoScoreService lê atribuições por serviço + recortes por papel/pessoa (v16.0)

**Goal:** O NPS de um profissional passa a somar as atribuicoes congeladas dele (nps_score_assignments) de TODAS as areas (ML + Shopee — Ajuste 3), via dual-path por resposta que preserva o bonus historico IDENTICO. A nota do NPS Shopee (validada em prod: Decoral -> Gustavo 3.11 analista / Felipe 2.25 estrategista) passa a aparecer no ranking /performance e no widget de carteira. Zero mudanca em bonificacao de meses sem atribuicao.
**Requirements**: DEC-80-A, DEC-80-B0, DEC-80-B, DEC-80-C, DEC-80-D, DEC-80-E
**Depends on:** Phase 79
**Plans:** 3/3 plans complete

Plans:

- [x] 80-01-PLAN.md — Service: computeNpsMedio dual-path (atribuicoes + legado) + dedup + isolamento (DEC-80-A, DEC-80-B0, DEC-80-B, DEC-80-D)
- [x] 80-02-PLAN.md — Regressao historica + mes misto + bump de cache v2->v3 + ancora Carlos (DEC-80-B, DEC-80-C, DEC-80-E)
- [x] 80-03-PLAN.md — Widgets do /performance: coluna NPS, ultimas respostas e heatmap via atribuicoes + npm run build (DEC-80-E)

### Phase 81: NPS config UX — duplicar/excluir modelo + modal gerar-link multi-step (modelo→empresas por serviço coberto) (v16.0)

**Goal:** Na config do NPS dá pra DUPLICAR um modelo (clone completo, is_default=false) e EXCLUIR um modelo (bloqueando o principal e modelos com respostas — sugerir arquivar; histórico preservado). O modal "Gerar link" do /nps vira modelo-first e filtra as empresas pelos serviços cobertos do modelo (modelo sem scopes → todas). Zero regressão no CRUD/gerar-link atuais.
**Requirements**: DEC-81-1, DEC-81-2, DEC-81-3
**Depends on:** Fase 79 (NPS multi-modelo). Independente da Fase 80 (bônus).
**Plans:** 4/4 executed — testes verdes (checkpoint visual + deploy pendentes; sobe junto com a Fase 79)

Plans:

- [x] 81-01-PLAN.md — Backend: duplicate() + destroy() com guardas + rotas + testes (DEC-81-1, DEC-81-2)
- [x] 81-02-PLAN.md — Backend: endpoint empresas-elegiveis (scope∩contrato, fallback, carteira, grupo auth/verified) + teste (DEC-81-3)
- [x] 81-03-PLAN.md — Frontend: botões Duplicar/Excluir no editor da config (DEC-81-1, DEC-81-2)
- [x] 81-04-PLAN.md — Frontend: modal gerar-link modelo-first + filtro reativo (DEC-81-3)

### Phase 82: Planilha Excel-like na grade de anúncio em massa (glide-data-grid) — módulo MLB/Anúncios

**Goal:** A aba "Em massa" de `/mlb/anuncios` deixa de ser uma `<table>` HTML com um `<input>` por célula e passa a ser uma **planilha de verdade**, com a sensação de Excel/Google Sheets — mantendo 100% das validações que impedem dado inválido de chegar ao Mercado Livre. Palavras do usuário: "quero uma interface extremamente próxima do Excel, mas adaptada para edição e publicação de anúncios".
**Requirements**: SHEET2-01, SHEET2-02, SHEET2-03, SHEET2-04, SHEET2-05, SHEET2-06, SHEET2-07, SHEET2-08
**Depends on:** Nada. (A Phase 81 do roadmap é NPS, sem relação — ver NOTA DE NUMERAÇÃO abaixo.) A quick task `260715-jgi` (abas Individual/Em massa) já está em prod e é independente.
**Plans:** 7 plans / 7 waves (cadeia sequencial — todos os plans tocam o mesmo componente de grade, sem paralelismo possível)

**Requisitos (capacidades pedidas pelo usuário):**

- **SHEET2-01** — Seleção de múltiplas células (range), incluindo múltiplos retângulos.
- **SHEET2-02** — Fill handle: arrastar a alça da célula replica valores **vertical E horizontalmente**.
- **SHEET2-03** — Copiar/colar entre células **e** colar dados vindos direto do Excel/Google Sheets.
- **SHEET2-04** — Navegação por teclado: Tab, Enter e setas.
- **SHEET2-05** — Seleção de linhas e colunas.
- **SHEET2-06** — Campos com valores pré-definidos (ex: Gênero — atributos `value_type=list` do ML) mantêm **aparência de planilha**, mas ao editar exibem **dropdown só com as opções válidas**.
- **SHEET2-07** — Zero regressão nas validações e no ciclo de vida atuais (ver "Não pode regredir").
- **SHEET2-08** — Tema do canvas mapeando os tokens `ecf-*` (dark), coerente com o resto do sistema.

**Decisão técnica travada (não reabrir — decidida pelo usuário em 2026-07-15):**
Usar **glide-data-grid** (MIT, canvas, React 18 — o projeto está em `react ^18.2`). Cobre nativamente: `fillHandle` + `onFillPattern` + `allowedFillDirections` (SHEET2-02), `rangeSelect: multi-rect` (SHEET2-01), `getCellsForSelection`/`onPaste`/`coercePasteValue` com split tab/newline (SHEET2-03), keybindings (SHEET2-04), `rowSelect`/`columnSelect` (SHEET2-05), `theme` por objeto JS (SHEET2-08). Dropdown (SHEET2-06) vem de `@glideapps/glide-data-grid-cells` (DropdownCell). Peer deps: `lodash`, `marked`, `react-responsive-carousel` (+ dep `@linaria/react`).
Alternativas descartadas com motivo: **react-data-grid** (v7 beta exige React 19; v6 estável exige React 16; range selection é [issue aberta #1037](https://github.com/adazzle/react-data-grid/issues/1037)), **AG Grid** (fill handle e range selection são Enterprise pago), **Handsontable** (licença comercial), **construir do zero em DOM** (usuário optou pela lib).

**Escopo:** só o frontend da grade — `resources/js/Pages/Mlb/AnunciarMassa.jsx` (1.321 linhas). Backend **não muda**: as rotas `mlb.anuncios.massa.colunas` / `massa.produtos` / `rascunho.store|update|destroy` / `validar` / `publicar-lote` já existem e servem.

**Não pode regredir** (a grade atual já faz, e é o valor do módulo): abas por categoria (SHEET-03, cápsulas ~linha 637 — **não** confundir com o `ModoAnuncioTabs` novo); colunas dinâmicas = 10 campos base + SÓ os obrigatórios da categoria ativa (SHEET-02); autosave por linha com debounce (store na criação, update depois); erros **locais bloqueantes** (`errosLocaisLinha`) × avisos do ML (orientativos); GTIN EAN-13 gerado sem repetir na aba; título limitado a `max_title_length`; badge de origem da linha; puxar produtos do cliente (SHEET-04); validar tudo + publicar em lote (`PublishBar`).

**Risco principal:** glide-data-grid renderiza em **canvas**, não em DOM. As affordances visuais atuais (realce vermelho inset de erro na linha, `OrigemBadge`, contador de título) são Tailwind hoje e precisam virar custom cells / theme overrides no canvas. Estilizar via objeto `theme` com os tokens `ecf-*` (`ecf-bg` #050507, `ecf-card` #0f1116, `ecf-yellow` #ffe600) — classes Tailwind não alcançam o canvas.

**NOTA DE NUMERAÇÃO:** o módulo de anúncios vinha usando "Phase 75–82" **só em comentários de código e mensagens de commit**, colidindo com as fases NPS deste ROADMAP (a "Phase 79" daqui é NPS multi-modelo; a "Phase 79" citada em `routes/mlb_anuncios.php` é duplicar-tier). Esta **Phase 82 é a primeira fase real do módulo de anúncios no ROADMAP** — daqui pra frente a numeração do módulo é a do ROADMAP.

Plans:

- [ ] 82-01-PLAN.md — Wave 1: fundação — instalar glide-data-grid + peers, `<div id="portal">` no Blade (gotcha de falha silenciosa) e extração dos helpers puros para `gradeMassaUtils.js` [SHEET2-06, SHEET2-07]
- [ ] 82-02-PLAN.md — Wave 2: `GradeAnuncioGlide.jsx` — DataEditor em canvas com tema `ecf-*`, colunas dinâmicas por categoria, `getCellContent`/`onCellsEdited` e autosave preservado; remove a `<table>` [SHEET2-07, SHEET2-08]
- [ ] 82-03-PLAN.md — Wave 3: copiar/colar nativo com coerção de domínio (reusa `parseDimensoes`/`casarValueList`/`normalizarTipoAnuncio`) + `DropdownCell` nos campos de valor fechado; apaga o paste manual [SHEET2-03, SHEET2-06, SHEET2-07]
- [ ] 82-04-PLAN.md — Wave 4: seleção multi-retângulo, fill handle bidirecional, teclado nativo, seleção de linha/coluna + toolbar de lote (EAN-13 e remover) sobre as linhas selecionadas [SHEET2-01, SHEET2-02, SHEET2-04, SHEET2-05, SHEET2-07]
- [ ] 82-05-PLAN.md — Wave 5: realce de erro local (vermelho) × aviso do ML (âmbar) via `getRowThemeOverride`, coluna de status e painel de diagnóstico por linha [SHEET2-07, SHEET2-08]
- [ ] 82-06-PLAN.md — Wave 6: custom cell renderer da bolinha de origem (canvas 2D, escopado às colunas do "puxar produtos") e editor do Título com contador via `provideEditor` [SHEET2-07]
- [ ] 82-07-PLAN.md — Wave 7: varredura de restos + gates (`npm run build`, suíte completa) + **checkpoint visual humano** cobrindo SHEET2-01..08 (canvas não é testável e a grade não abre em localhost) [SHEET2-01..08]

### Phase 83: Planilha — correções de publicação em massa, avisos do ML visíveis e ganhos rápidos (módulo MLB/Anúncios)

**Goal:** O publicador consegue **publicar em massa e entender o que aconteceu** sem sair da tela: o botão destrava, cada linha mostra publicado/erro/aviso com o motivo legível, e o erro completo da API do ML é lido por inteiro. Mais os ajustes de comportamento que faltam para a planilha parecer planilha (Delete, preço 129,99, remover categoria).
**Requirements**: FIX-83-1, FIX-83-2, FIX-83-3, FIX-83-4, FIX-83-5, FIX-83-6
**Depends on:** Phase 82 (a planilha em canvas)
**Plans:** 5 plans / 4 waves (wave 2 é paralela — os planos 02 e 03 tocam arquivos diferentes)

**Requisitos (do feedback do usuário em 2026-07-15, após usar a planilha em prod):**

- **FIX-83-1 — Loading eterno da publicação em massa (BUG ATIVO, prioridade máxima).** Publicar em massa trava a tela em "publicando" para sempre. **Causa confirmada:** `AnunciarMassa.jsx::publicarLote` chama `setPublicandoLote(true)` e só chama `setPublicandoLote(false)` **dentro do `catch`** — não há `finally`, e o caminho de sucesso nunca destrava. Agrava: `router.reload({only:['rascunhos']})` é recarga parcial do Inertia e **preserva o estado local do React**, então o componente não remonta e o estado não zera.
- **FIX-83-2 — O resultado da publicação não chega na tela onde ela começou.** O `PublicarAnuncioMlJob` grava `status=erro` + `validation_errors` no rascunho, mas a grade dispara **um único `router.reload` após 1500ms** enquanto o backend **escalona os jobs a 3s por posição** (`publicarLote`, BULK-02) — com 4 linhas o último termina em ~12s, muito depois da única recarga. Resultado: a grade fica em "publicando" e o erro só aparece no wizard individual. Precisa de polling até a fila drenar (ou push), com resultado por linha: Publicado ✅ / Erro ❌ + motivo / Aviso ⚠.
- **FIX-83-3 — Erro da API do ML é ilegível.** A mensagem (`Erro 400 em POST /items — item.attributes...`) aparece cortada, sem como ver a resposta completa. Precisa de "ver detalhes" (modal/expandir/tooltip — qualquer um) mostrando o retorno íntegro. Precedente no projeto: `9e5a640 fix(anunciar-ml): erro completo expansivel` já resolveu isso no wizard — reusar a abordagem.
- **FIX-83-4 — Avisos do ML invisíveis.** A `PublishBar` diz "4 com avisos do ML" mas não há como ver quais. Os dados **já existem** em `l.valida.erros` (gravado por `validarTudo`) — falta só a UI: expandir por linha, no formato "linha 4 → atributo obrigatório faltando".
- **FIX-83-5 — Preço não aceita vírgula.** Hoje só `129.99`; o padrão brasileiro `129,99` precisa ser aceito e convertido internamente para o formato da API. Ponto de coerção: a grade (entrada) e/ou `montarPayloadLinha` (saída, hoje faz `Number(l.price)` — que devolve `NaN` com vírgula).
- **FIX-83-6 — Ganhos rápidos de planilha:** (a) tecla **Delete** apaga o conteúdo das células selecionadas — a lib tem `onDelete` nativo (verificado no `.d.ts` instalado); (b) botão **remover categoria** ao lado do "+ Nova categoria" (hoje só dá para adicionar; a remoção precisa decidir o que fazer com as linhas da aba).

**Fora do escopo desta fase (já mapeado, fases próprias):**

- **Ctrl+Z / Ctrl+Y** — a lib **não tem undo/redo nativo** (verificado no `.d.ts`); exige arquitetura de histórico de estado. Decisão do usuário: **undo/redo local da grade** (~50 ações, cobre edição/paste/fill/delete; não desfaz linha já persistida). → Phase 84.
- **Validação local prévia antes de chamar a API** (evitar 400 desnecessário) → depende do schema saber o que é obrigatório por categoria → Phase 85.
- **Arquitetura de schema de colunas** (fixas + dinâmicas por categoria, provider por marketplace para ML/Amazon/Shopee/Magalu) → Phase 85.
- **Cores por grupo de coluna, grupos colapsáveis e identidade visual das variações** → dependem do schema da Phase 85 (cor e collapse viram propriedade do grupo, não código solto) → Phase 86. Referência do usuário: o template Amazon `2026-01-04 19-31-31.xlsm` (aba "Modelo", `TemplateType=fptcustom`, 477 colunas) usa **10 grupos por cor** — pêssego `FCD5B4` (135 col, básicos), verde `92D050` (140, opcionais), azul `8DB4E2` (52, dimensões), rosa `CC9999` (48, baterias), vermelho `FF0000` (27, preço), bege `BBA680` (32), azul claro `B7DEE8` (24), amarelo `FFFF00` (9, **imagens**), coral `FF8080` (4, **variações**), laranja `F8A45E` (4). Usar o **padrão**, não o conteúdo (é template de vestuário da Amazon, não do ML). `onGroupHeaderClicked` existe na lib e viabiliza o collapse.

Plans:

- [ ] 83-01-PLAN.md — Funções puras + o 1º runner de teste JS do projeto: `normalizarPreco` (FIX-83-5), campos de status em `linhaVazia`/`linhaPublicavel` e `mesclarStatusRascunhos` — o merge por id, peça central da fase (FIX-83-2) [wave 1]
- [ ] 83-02-PLAN.md — `AnunciarMassa.jsx`: useEffect de merge da prop `rascunhos`, polling condicional de 3s com teto de segurança e `publicarLote` com `finally` (FIX-83-1 + FIX-83-2 — o mesmo bug) [wave 2]
- [ ] 83-03-PLAN.md — `GradeAnuncioGlide.jsx`: glifos publicado/erro por linha (FIX-83-2), Delete funcional em todas as colunas (FIX-83-6a) e preço com vírgula no ponto único de escrita (FIX-83-5) [wave 2]
- [ ] 83-04-PLAN.md — `AnunciarMassa.jsx`: painel DOM abaixo da grade com o erro completo do ML expansível (FIX-83-3) + avisos por linha (FIX-83-4), contadores da PublishBar e "remover categoria" movendo as linhas para "Sem categoria" (FIX-83-6b) [wave 3]
- [ ] 83-05-PLAN.md — Varredura, gates da fase e checkpoint visual dos 6 requisitos **em produção** (não verificável em localhost: 0 empresas com `ml_token`) [wave 4]

### Phase 84: Planilha — undo/redo local (Ctrl+Z / Ctrl+Y) (módulo MLB/Anúncios)

**Goal:** Ctrl+Z desfaz e Ctrl+Y (ou Ctrl+Shift+Z) refaz as edições da planilha, como no Excel — cobrindo digitação, paste, fill handle e Delete, com o autosave sendo re-disparado nas linhas revertidas.
**Requirements**: UNDO-84-1, UNDO-84-2, UNDO-84-3
**Depends on:** Phase 83
**Plans:** 0 plans

**Requisitos:**

- **UNDO-84-1 — Ctrl+Z desfaz / Ctrl+Y e Ctrl+Shift+Z refazem.** Histórico local de ~50 ações. Escopo decidido pelo usuário: **undo local da grade** — cobre edição de célula, paste, fill e delete; **não** desfaz criação/remoção de linha já persistida no banco (exigiria endpoint de restauração e reconciliar ids).
- **UNDO-84-2 — O autosave acompanha o desfazer.** Reverter o estado sem re-salvar deixaria a tela mostrando o valor antigo e o banco com o novo — pior que não ter undo. As linhas que mudaram no undo/redo precisam ser re-agendadas no autosave.
- **UNDO-84-3 — Feedback na tela.** Botões Desfazer/Refazer na toolbar da grade (o atalho é invisível; um botão desabilitado comunica "não há o que desfazer").

**Fatos técnicos verificados (no `.d.ts` da lib instalada):**

- A lib **não tem undo/redo nativo** — nada em `ConfigurableKeybinds` (que tem `downFill`, `rightFill`, `clear`, `delete`, `search`, navegação…), nem em `ForcedKeybinds` (`copy`/`cut`/`paste`). **Ctrl+Z não é interceptado pela lib** e borbulha até o wrapper DOM — dá para capturar sem brigar com o teclado nativo (SHEET2-04).
- **O snapshot é barato:** o estado `abas` já é imutável (todo `setAbas` cria objetos novos), então guardar histórico é guardar **referências**, não clonar dados.

**Nota sobre o gate da Fase 82:** existe um gate estrutural proibindo `onKeyDown` em `GradeAnuncioGlide.jsx` — ele nasceu para impedir a reimplementação da navegação nativa (setas/Tab/Enter). Undo **não é navegação**; o gate precisa ser refinado para proibir a reimplementação de navegação e continuar permitindo atalhos que a lib não trata.

Plans:

- [ ] TBD (run /gsd-plan-phase 84 to break down)

Plans:

- [ ] TBD (run /gsd-plan-phase 84 to break down)

### Phase 85: Planilha — colunas que faltam para publicar (foto, atributos de variação) e validação local prévia (módulo MLB/Anúncios)

**Goal:** A planilha passa a ter **todas as colunas necessárias para o anúncio publicar**, e avisa **antes** de chamar a API o que falta preencher — em vez de gastar a chamada e voltar 400. Motivado por erro real de produção reportado pelo usuário em 2026-07-15.
**Requirements**: COL-85-1, COL-85-2, COL-85-3, COL-85-4
**Depends on:** Phase 84
**Plans:** 5 plans / 3 waves (wave 1 e wave 2 são paralelas — file ownership disjunto)

**O erro real que motivou a fase** (retorno do ML numa publicação em massa de verdade):

```
item.attributes.missing_required  → "The attributes [COLOR, SIZE] are required for
                                     category MLB108791 and channel marketplace"
item.listing_type_id.requiresPictures → "Item pictures are mandatory for listing type gold_pro"
shipping.lost_me1_by_user   (warning — não bloqueia)
item.shipping.mandatory_free_shipping (warning — não bloqueia)
```

**Requisitos:**

- **COL-85-1 — Foto (o bloqueio mais grave).** `montarPayloadLinha` manda `pictures: []` **sempre**: a grade em massa publica todo anúncio sem foto nenhuma. Anúncio **Premium (`gold_pro`) não publica sem foto** — então todo Premium do lote falha 100% das vezes, independente do que for preenchido. **Decisão (validada no código que já funciona): coluna de URL.** O wizard já publica com `pictures: [{ source: imagemUrl }]` (`AnunciarML.jsx:1568`) — o ML aceita foto por URL, sem upload. É também o que o template Amazon de referência do usuário faz ("URL da imagem principal" + 9 "URL de Imagem Adicional"), mantém a metáfora de planilha e permite colar do Excel. Prever a coluna principal + adicionais.
- **COL-85-2 — Atributos obrigatórios que a grade esconde (causa raiz do `[COLOR, SIZE]`).** `MlbAnuncioController::colunasCategoria` filtra `tags.allow_variations !== true` **mesmo quando `tags.required === true`**. Isso está certo no wizard (lá esses atributos vão para as variações), mas na grade em massa — onde 1 linha = 1 anúncio **simples, sem variação** — eles somem da planilha e o ML os exige. O próprio erro aponta a saída: *"Check the attribute is present in the **attributes list** or in all variation's attribute_combination"*. Para anúncio sem variação, atributo required+allow_variations deve virar **coluna normal** e ir na lista `attributes`. Cuidado: não regredir o wizard, que usa o mesmo endpoint/serviço.
- **COL-85-3 — Validação local ANTES de chamar a API.** Hoje `errosLocaisLinha` só cobre título/preço/estoque/marca/modelo. Precisa cobrir o que o ML exige de fato: foto quando `tier = gold_pro`, e **todos** os atributos obrigatórios da categoria (não só BRAND/MODEL). Objetivo declarado do usuário: "evita chamadas desnecessárias para a API". A régua tem que continuar sendo a MESMA da `PublishBar` e do realce da grade (fonte única — duas implementações fariam a grade mostrar 3 publicáveis com 4 linhas verdes).
- **COL-85-4 — Warnings ≠ erros.** `shipping.lost_me1_by_user` e `mandatory_free_shipping` voltam como `type: warning` e **não bloqueiam** a publicação. A tela não pode tratá-los como falha (a distinção erro-local × aviso-do-ML já existe desde a Fase 82 — preservar).

**Fora do escopo (fase própria):**

- **Variações de verdade** (1 anúncio com N variações: `variations[].attribute_combinations`, estoque e foto por variação) — a grade é 1 linha = 1 anúncio; variação exige repensar o modelo de linha (linha-pai/linha-filha) e é fase separada. Esta fase resolve o caso "anúncio simples com atributos obrigatórios preenchidos", que é o que o erro real pede.
- **Cores por grupo de coluna, grupos colapsáveis, identidade visual das variações** → Phase 86.

**Achados do planejamento que mudaram a fase** (verificados na fonte, não presumidos):

- **O risco crítico de COL-85-2 não existe.** `colunasCategoria` (grade) e `atributos()` (wizard) são **métodos distintos**: o wizard consome `mlb.anuncios.meta.atributos` (devolve cru; filtra no cliente em `AnunciarML.jsx:1102`), a grade consome `mlb.anuncios.massa.colunas` (filtra no servidor). `grep` confirma **1 consumidor** de `massa.colunas`. **Nada de `?contexto=massa`** — parametrizar seria complexidade sem causa. O único ponto compartilhado é `MlCatalogoMetaService::atributos` (leitura crua), intocado.
- **A foto não precisa de backend.** `ItemBuilderBase::montarComum` (`app/Services/Mlb/Publicacao/Builders/ItemBuilderBase.php:23`) já repassa `'pictures' => $d['pictures'] ?? []` para a API. O caminho já roda em produção pelo wizard — `pictures: []` é hardcode do frontend. A fase toca **1 arquivo PHP** (o filtro de COL-85-2) e o resto é frontend.
- **Campos de foto são planos** (`imagemUrl`, `imagemUrl2`…`imagemUrl6`), não array: `editarCelula` escreve `{ ...l, [campo]: valor }`, então paste, fill handle, Delete e o undo/redo da Fase 84 funcionam nas colunas novas **sem um ramo a mais** em `onCellsEdited`.
- **Quantidade decidida: 1 principal + 5 adicionais** (discricionário). O ML aceita até 10; a referência 1+9 do usuário vem de um `.xlsm` de 477 colunas que ninguém renderiza como grade viva — a nossa é canvas visível o tempo todo, e 10 colunas de foto passariam a ocupar mais largura que os 10 campos base, em toda categoria. Grupos colapsáveis (que resolveriam) são Phase 86. Custo de mudar de ideia = acrescentar ids em `CAMPOS_FOTO` (fonte única).
- **Foto nunca vem do cliente:** `montarProdutosDoCliente` não devolve campo de imagem — "puxar produtos" não preenche foto, e as colunas ficam fora de `COLS_COM_ORIGEM`.
- **`linhaDeRascunho` precisa de round-trip:** hoje não lê `payload.pictures`. Sem a volta, o publicador digita as URLs, reabre a página e perde tudo — voltando a publicar sem foto **em silêncio**.
- **Alcance assumido:** generalizar `errosLocaisLinha` faz linhas hoje verdes acenderem **vermelhas** e a `PublishBar` mostrar **menos** publicáveis. É o objetivo (é o 400 `missing_required` aparecendo antes da chamada) — e precisa estar em destaque no SUMMARY para não virar bug reportado.

Plans:

- [ ] 85-01-PLAN.md — Wave 1: `colunasCategoria` para de esconder required+allow_variations (COLOR/SIZE), GRID segue fora + Feature Phase85 com regressão do wizard [COL-85-2]
- [ ] 85-02-PLAN.md — Wave 1: funções puras — `CAMPOS_FOTO`/`urlsFotos`/`temFoto`, `linhaVazia` com fotos e `errosLocaisLinha` generalizada (todos os obrigatórios + foto no gold_pro), fonte única [COL-85-1, COL-85-3, COL-85-4]
- [ ] 85-03-PLAN.md — Wave 2: `AnunciarMassa.jsx` — `pictures` no payload, round-trip `linhaDeRascunho` e painel de pendências ("falta: foto, Cor") [COL-85-1, COL-85-3]
- [ ] 85-04-PLAN.md — Wave 2: `GradeAnuncioGlide.jsx` — 6 colunas no grupo "Fotos" entre base e ficha técnica + gates da distinção erro-local × aviso-do-ML [COL-85-1, COL-85-4]
- [ ] 85-05-PLAN.md — Wave 3: varredura, 4 gates juntos (build, test:js, Phase85, Phase82 + baseline Phase75) e **checkpoint humano em produção** (não verificável em localhost: 0 empresas com `ml_token`) [COL-85-1..4]

### Phase 86: Histórico de anúncios publicados + "Anunciar Semelhante" (módulo MLB/Anúncios)

**Goal:** O publicador vê o **histórico dos anúncios já publicados** da empresa e consegue criar um anúncio novo **a partir de um existente** — tudo já vem preenchido, ele altera só o que muda. Espelha o "Anunciar semelhante" do próprio Mercado Livre.
**Requirements**: HIST-86-1, HIST-86-2, HIST-86-3
**Depends on:** Phase 85

**Requisitos:**

- **HIST-86-1 — Aba "Histórico".** O `ModoAnuncioTabs` (hoje Individual | Em massa) ganha uma **3ª aba** (decisão do usuário), com a lista dos anúncios `status=publicado` da empresa fixada: foto (capa), título, preço, tipo (Clássico/Premium), data de publicação e **link para o anúncio no ML**. Busca por título/SKU. Escopo por empresa e o mesmo gate de hoje (`role:admin`; o `responsavel_id` segue dormant).
- **HIST-86-2 — "Anunciar semelhante".** Botão por item do histórico: clona o anúncio e **abre o rascunho novo no wizard individual** (decisão do usuário — é 1 anúncio de cada vez, "altero só o que quero"). **A lógica já existe e roda em produção:** `MlbAnuncioController::duplicarComoTemplate` → `criarTemplateInterno` clona `category_id`, `sku_origem`, `listing_tier` e o **payload inteiro** (título, preço, atributos e agora as fotos da Phase 85), zerando `ml_item_id`/`_classico`/`_premium` → vira rascunho novo. A rota `mlb.anuncios.rascunho.duplicar-template` já existe. Esta fase **reusa**, não reimplementa.
- **HIST-86-3 — Endpoint do histórico.** `massa()` e `index()` **não devolvem** `status=publicado` (o `whereIn` traz só rascunho/validado/erro/publicando) — por isso o anúncio some da tela depois de publicar. Precisa de consulta própria, paginada, ordenada por publicação desc.

**Contexto técnico já verificado:**

- `MlAnuncioRascunho::STATUS_PUBLICADO` = `'publicado'`; `ml_item_id` guarda o anúncio no ML (mais `ml_item_id_classico`/`_premium` do par Clássico+Premium da Phase 79 do módulo).
- O link do anúncio no ML já tem precedente: commit `9e5a640` ("link correto do anuncio ML — produto.mercadolivre MLB-num") — reusar a mesma montagem em vez de inventar.
- O painel "Rascunhos recentes" do wizard (`AnunciarML.jsx:2394`) é o precedente visual de listagem deste módulo.

**Fora do escopo:** editar/pausar/encerrar anúncio publicado direto pelo histórico (é gestão de anúncio, não criação); sincronizar status/estoque do ML de volta.

**Plans:** 3/3 plans complete

Plans:

- [ ] 86-01-PLAN.md — Wave 1: backend — rota `mlb.anuncios.historico` + `historico()` paginado (só `publicado`, escopo por empresa, ordem por publicação desc, busca título/SKU) + prop `abrir_rascunho_id` no `wizard()` + Feature `Phase86` [HIST-86-3, HIST-86-1, HIST-86-2]
- [ ] 86-02-PLAN.md — Wave 1: `anuncioHistoricoUtils.js` (fonte única do link do ML, do commit 9e5a640) + página `AnunciosHistorico.jsx` (cards com foto/título/preço/tier/data/link, busca, paginação) + 3ª aba no `ModoAnuncioTabs` [HIST-86-1]
- [ ] 86-03-PLAN.md — Wave 2: botão "Anunciar semelhante" → POST na rota `duplicar-template` que **já existe** → redirect ao wizard com `?rascunho=N`; wizard auto-abre via `abrirRascunho` e passa a importar `linkAnuncioMl` [HIST-86-2]
- [ ] 86-04-PLAN.md — Wave 3: varredura do contrato 01×02, 6 gates juntos (build, test:js, Phase86, Phase82, Phase81, baseline Phase75) e **checkpoint humano em produção** (não verificável em localhost: 0 empresas com `ml_token` e 0 publicados)

### Phase 87: Planilha — cores por grupo de coluna e grupos colapsáveis (padrão Amazon) (módulo MLB/Anúncios)

**Goal:** Achar informação na planilha vira fácil: cada **grupo de colunas tem sua cor** (padrão Amazon) e os grupos **recolhem/expandem** com um clique (padrão Excel). Deixou de ser cosmético — com as características secundárias da Fase 85, uma categoria grande produz dezenas de colunas.
**Requirements**: VIS-87-1, VIS-87-2, VIS-87-3

**Requisitos:**

- **VIS-87-1 — Cor por grupo.** Todas as colunas do mesmo grupo compartilham a cor, como na aba "Modelo" do template Amazon que o usuário mandou como referência. Grupos: Status, Dados básicos, Preço/Estoque, Identificação (SKU/GTIN), Dimensões, Fotos, Ficha técnica (obrigatórios), Características secundárias.
- **VIS-87-2 — Grupos colapsáveis.** Clicar no cabeçalho do grupo recolhe/expande (`+ Dimensões` ↔ `- Dimensões`). "Características secundárias" nasce **recolhido** (é o grupo que mais infla). Dados básicos e Preço/Estoque não recolhem (são o mínimo para trabalhar).
- **VIS-87-3 — Zero regressão.** Recolher um grupo esconde a coluna da tela, mas **não apaga dado nem muda o payload**: `montarPayloadLinha` lê o estado da linha, não as colunas visíveis. E o paste mapeia pelas colunas **visíveis** — o que já é a regra de hoje.

**Fatos técnicos verificados no `.d.ts` da lib instalada:**

- `GridColumn.themeOverride?: Partial<Theme>` — **cor por coluna** existe nativamente (VIS-87-1 é declarativo, não desenho manual).
- `onGroupHeaderClicked?: (colIndex, event) => void` — o clique no cabeçalho de grupo existe; o collapse em si é da aplicação (filtrar as colunas do grupo recolhido).

**Referência do usuário (já extraída do `2026-01-04 19-31-31.xlsm`, aba "Modelo"):** template Amazon `fptcustom`, 477 colunas em **10 grupos por cor** — pêssego `FCD5B4` (135, básicos), verde `92D050` (140, opcionais), azul `8DB4E2` (52, dimensões), rosa `CC9999` (48), vermelho `FF0000` (27, preço), bege `BBA680` (32), azul claro `B7DEE8` (24), amarelo `FFFF00` (9, **imagens**), coral `FF8080` (4, **variações**), laranja `F8A45E` (4). Usar o **padrão** (cor por grupo, obrigatórios na frente), adaptando os tons ao dark theme `ecf-*` — as cores da Amazon são para planilha branca e não podem ser copiadas cruas.

Plans:

- [ ] 87-01 — cores por grupo + collapse (execução direta)

Plans:

- [ ] TBD (run /gsd-plan-phase 87 to break down)

### Phase 88: Camada de contexto — CarteiraContextService (v17.0)

**Goal:** Existe uma fonte única e confiável de vínculos de carteira por serviço (`CarteiraContextService`) que resolve setor, papel e elegibilidade financeira sem depender de `company_id` consolidado — fundação para toda a milestone v17.0.
**Requirements**: CTX-01, CTX-02, CTX-03, CTX-04, CTX-05
**Depends on:** Nada (fundação da milestone v17.0)
**Plans:** 1/1 plans complete

**Success Criteria** (o que deve ser VERDADE):

1. `CarteiraContextService::forUser($user, $filters)` retorna vínculos de serviço ativos com `company_id`, `company_name`, `servico_id`, `servico_nome`, `setor`, `role`, `role_label`, testado nos 4 cenários do plano canônico: só Performance, só Shopee, Performance+Shopee na mesma empresa, mesmo profissional nos dois serviços da mesma empresa
2. Cada vínculo expõe `has_financial_source`/`financial_source`/`financial_metrics_eligible` corretos — `true`/`'adman'` para setor `performance` (cobrindo Gestão id 6 E Mentoria id 7, resolvido via `servicos.setor` sem hardcode de `servico_id`), `false`/`null` para `shopee`
3. A mesma empresa com dois vínculos do mesmo profissional (ex.: ML + Shopee) é contada como 1 empresa única e 2 vínculos de serviço no retorno do service — não duplica empresa
4. Compatibilidade legado respeitada: `servico_id` preenchido tem prioridade; `servico_id null` com contrato Performance ativo resolve como Performance legado; `servico_id null` com contrato Shopee ativo NÃO atribui responsável Shopee automaticamente

Plans:

- [x] 88-01-PLAN.md — CarteiraContextService (forUser + contadores) + suite Feature V16 cobrindo CTX-01..05 (4 cenários canônicos, ramos legado CTX-05, Mentoria sem hardcode, filtros)

### Phase 89: Carteira individual — renderCarteiraProfissional por contexto (v17.0)

**Goal:** A carteira individual usa o `CarteiraContextService` em vez de `$user->companies()`; Shopee aparece com "sem fonte financeira"; a tela `/companies` deixa de misturar responsável ML com responsável Shopee.
**Requirements**: CART-01, CART-02, CART-03, CART-04, CART-05, CART-08
**Depends on:** Phase 88
**Plans:** 2/2 plans complete

**Success Criteria** (o que deve ser VERDADE):

1. Empresa com Performance + Shopee aparece UMA única vez como empresa na carteira individual, exibindo os dois vínculos de serviço separadamente
2. Vínculo Shopee aparece na carteira com estado explícito "sem fonte financeira" — sem faturamento/margem de ML
3. Soma financeira (`SUM(revenue)`, `SUM(contribution_margin)`, `ad_spend`, `tacos`) considera apenas vínculos `financial_metrics_eligible = true` — validado por teste dedicado do analista Shopee de empresa que também tem ML
4. Profissional responsável por ML e Shopee da mesma empresa não duplica faturamento no filtro "Todos" — a métrica ML conta uma única vez
5. A tela `/companies` (painel Performance) exibe o responsável do SERVIÇO DE PERFORMANCE na coluna Analista/Estrategista — nunca o responsável Shopee; a pendência "sem responsável" acusa falta do responsável de performance especificamente

Plans:

- [x] 89-01-PLAN.md — renderCarteiraProfissional consome CarteiraContextService: dedup financeiro por elegibilidade + ad_spend/tacos + badges de vínculo na AdminCarteira (CART-01..05)
- [x] 89-02-PLAN.md — CART-08: relações analistaPerformance/estrategistaPerformance no Company, reapontamento de /companies (index/show) + pendência OR + checkpoint visual da fase

### Phase 90: Carteiras consolidadas — renderCarteirasConsolidadas (v17.0)

**Goal:** A visão admin de carteiras consolidadas mostra cards por profissional com contagem correta, sem puxar faturamento ML para quem só cuida da empresa em Shopee, com filtro de contexto e contadores de auditoria.
**Requirements**: CART-06, CART-07
**Depends on:** Phase 89 (individual antes de consolidada)
**Plans:** 2/2 plans complete

**Success Criteria** (o que deve ser VERDADE):

1. Cards por profissional na carteira consolidada mostram contagem correta, separando empresas únicas de vínculos de serviço
2. Profissional responsável apenas por Shopee de uma empresa que também tem ML NÃO aparece com faturamento/margem ML puxado dessa empresa
3. A UI de carteira (individual e consolidada) tem filtro de contexto funcional (Todos / Performance-ML / Shopee)
4. Badges de serviço aparecem por linha e contadores (empresas únicas vs. vínculos de serviço) ficam visíveis no topo da tela

Plans:

- [x] 90-01-PLAN.md — Backend TDD: renderCarteirasConsolidadas via CarteiraContextService (dedup + contadores + source_counts) + filtro ?contexto= nas 2 funções + totais por união (CART-06, CART-07)
- [ ] 90-02-PLAN.md — Frontend: select de contexto + contadores em Carteiras.jsx e AdminCarteira.jsx + remoção do alias companies_count + npm run build + checkpoint visual (CART-07)

**UI hint**: yes

### Phase 91: Desempenho único com elegibilidade — DesempenhoScoreService (v17.0)

**Goal:** `DesempenhoScoreService::computeUniverso` deriva o universo dos vínculos de serviço ativos do profissional (não de `company_id` consolidado); financeiro só entra por vínculo elegível; a nota expõe status `official`/`partial`/`blocked`, sem nunca criar score separado por marketplace.
**Requirements**: DESEMP-01, DESEMP-02, DESEMP-03, DESEMP-04, DESEMP-05, DESEMP-06, DESEMP-07
**Depends on:** Phase 88 (usa o mesmo universo de vínculos do `CarteiraContextService`)
**Plans:** 2 plans

**Success Criteria** (o que deve ser VERDADE):

1. `computeUniverso` deriva o universo de vínculos de serviço ativos do profissional, retornando empresas únicas e empresas elegíveis para financeiro — não usa mais `$user->companies()`
2. O score permanece ÚNICO por profissional — não existe implementação de "Score ML" / "Score Shopee" / "Score Geral" separados em nenhum ponto do código
3. `computeNpsMedio` continua lendo `nps_score_assignments` — NPS Shopee E NPS Performance entram no mesmo NPS médio do profissional (teste de regressão da v16.0 preservado)
4. `computeVarFaturamento` e `computeVarMargem` usam apenas vínculos com `financial_metrics_eligible = true` — profissional só-Shopee não recebe variação financeira baseada em ML (teste dedicado)
5. O retorno do service expõe os metadados `empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira`, `score_status`, `componentes_disponiveis`
6. A nota expõe status `official`/`partial`/`blocked`; profissional apenas-Shopee sem fonte financeira recebe `blocked` (decisão do usuário 2026-07-16, até a diretoria aprovar régua de bônus sem financeiro)
7. A regra `sem_carteira` remove do ranking apenas o profissional SEM nenhum vínculo ativo — quem tem vínculo Shopee (ainda que sem financeiro) permanece no ranking

Plans:

- [ ] 91-01-PLAN.md — TDD: computeUniverso via CarteiraContextService + score_status (official/partial/blocked) + metadados + bump cache v4 (DESEMP-01/03/04/05/06/07)
- [ ] 91-02-PLAN.md — Gate DESEMP-02 (ausência de score separado) + auditoria de consumidores + declarações de escopo + roteiro tinker pós-deploy

### Phase 92: UI de Desempenho — ranking + metadados (v17.0)

**Goal:** A UI de Desempenho mantém o ranking único e exibe os metadados por profissional (empresas únicas, vínculos de serviço, vínculos sem fonte, status da nota); filtros de auditoria por setor não criam segundo score oficial.
**Requirements**: DESEMP-08
**Depends on:** Phase 91
**Plans:** 2/2 plans complete

**Success Criteria** (o que deve ser VERDADE):

1. A UI de Desempenho mantém ranking único — não bifurca em telas/rankings separados por marketplace
2. Cada linha do ranking exibe os metadados por profissional: empresas únicas, vínculos de serviço, vínculos sem fonte financeira, status da nota (oficial/parcial/bloqueada)
3. Filtro de auditoria por setor de atuação (Todos/Performance/Shopee) muda apenas a visualização — não recalcula nem persiste um segundo score oficial

Plans:

- [ ] 92-01-PLAN.md — Backend: passthrough dos 6 metadados no ranking + filtro ?contexto= view-only + correção do comparacaoContextual (blocked fora dos pares + tamanho_amostra + self-view)
- [ ] 92-02-PLAN.md — Frontend: badge de status (Aguarda régua Shopee/Parcial/Oficial) + metadados por linha + select de contexto + self-view do blocked em Portfolio/Show.jsx + npm run build + checkpoint visual

**UI hint**: yes

### Phase 93: Menu — grupo transversal "Gestão ECF" (v17.0)

**Goal:** Carteira e Desempenho (e Metas, quando fizer sentido) saem do grupo "Mercado Livre" para um grupo transversal "Gestão ECF"; o grupo Mercado Livre mantém apenas telas realmente ML.
**Requirements**: MENU-01
**Depends on:** Nada (independente — pode ir por último)
**Plans:** 1 plano (93-01) — reorganização visual do menu

**Success Criteria** (o que deve ser VERDADE):

1. O menu lateral (`AppLayout.jsx`) mostra um grupo "Gestão ECF" contendo Carteiras, Desempenho e Metas
2. O grupo "Mercado Livre" mantém apenas telas realmente ML (Dashboard, Empresas, Sugadores, PPA, Grants) — Carteira/Desempenho não aparecem mais lá
3. O grupo "Shopee" permanece com suas telas (Empresas, Dashboard) sem alteração de comportamento — só a reorganização de Carteira/Desempenho muda

Plans:

- [ ] 93-01-PLAN.md — Menu: novo grupo transversal "Gestão ECF" (Carteira, Desempenho, Metas) + enxugar grupo Mercado Livre

**UI hint**: yes

### Phase 94: NPS Anti-Burlamento — auditoria técnica + serviço de suspeita (backend)

**Goal:** Toda abertura e resposta de link NPS deixa rastro técnico (IP, user-agent, horários, duração) e um serviço central avalia e persiste se a resposta é suspeita — sem nenhuma mudança visível para quem responde. Origem: `PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md` (seções 1, 2 e trilha de eventos; seções 4-8 do plano foram descartadas no import por já estarem entregues na v15.5/v16.0 — Digisac client/config/mapeamento/envio/aba e unicidade mensal).
**Requirements**: AB-94-1, AB-94-2, AB-94-3, AB-94-4, AB-94-5
**Depends on:** Nada (independente da v17.0 em andamento)
**Plans:** 3/3 plans complete

Plans:
**Wave 1**

- [x] 94-01-PLAN.md — Fundação: schema de rastro + nps_survey_events + config .env + NpsSuspicionService (4 regras)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 94-02-PLAN.md — NpsController: rastro de abertura/resposta + veredito de suspeita + eventos opened/expired/submitted/generated-manual

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 94-03-PLAN.md — NpsDispararMensal: eventos generated/sent_email/sent_digisac + linha do tempo E2E + gate de regressão

**Requisitos:**

- **AB-94-1 — Rastro de abertura.** Todo GET em `/nps/{token}` registra `first_opened_at`, `last_opened_at`, `open_count`, IP e user-agent da abertura no survey (campos nullable).
- **AB-94-2 — Rastro de resposta.** Todo submit registra `response_ip_address`, `response_user_agent` e `response_duration_seconds` (delta `created_at` do survey → submit) na resposta.
- **AB-94-3 — Trilha de eventos.** Tabela `nps_survey_events` (survey_id, event_type: generated|opened|submitted|expired|sent_email|sent_digisac, ip, user-agent, user_id nullable, metadata json) — auditoria viva; os fluxos existentes (geração manual, disparo mensal email/Digisac, expiração) passam a emitir eventos.
- **AB-94-4 — NpsSuspicionService.** Serviço central avalia no submit e persiste `is_suspicious` + `suspicion_reasons` (json, motivos em pt-BR): (a) IP da resposta pertence a IP/CIDR interno da ECF (config `ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS`); (b) resposta ≤ janela configurável (default 60s) após a geração do link; (c) resposta em sessão autenticada de usuário interno (nesta fase: marca, não bloqueia); (a)+(b) combinados = severidade maior.
- **AB-94-5 — Retrocompatibilidade.** Surveys/respostas legadas sem dados técnicos continuam funcionando em todas as telas e agregações — campos novos nullable, nenhum backfill obrigatório.

**Success Criteria** (o que deve ser VERDADE):

1. Abrir um link NPS registra horário/IP/user-agent e incrementa `open_count`; abrir de novo atualiza `last_opened_at` sem perder o primeiro registro
2. Responder registra IP, user-agent e duração; a resposta ganha veredito (`is_suspicious` + motivos) calculado pelo `NpsSuspicionService`
3. Resposta vinda de IP interno da ECF OU respondida dentro da janela curta após geração é marcada suspeita com motivo legível em pt-BR
4. `nps_survey_events` acumula a linha do tempo completa de um survey (gerado → enviado → aberto → respondido)
5. Nada muda para o cliente que responde (mesma UX) e nada quebra para dados legados sem rastro

### Phase 95: NPS Anti-Burlamento — UI de confiança admin-only

**Goal:** Admin enxerga a camada de confiança (badge na listagem, filtros, seção de auditoria técnica no detalhe); qualquer outro papel não recebe nem sinal de que ela existe — inclusive no payload.
**Requirements**: AB-95-1, AB-95-2, AB-95-3, AB-95-4
**Depends on:** Phase 94
**Plans:** 2/2 plans complete

Plans:
**Wave 1**

- [x] 95-01-PLAN.md — Backend: payload admin-only `confianca`/`auditoria` + filtro server-side com blindagem (AB-95-1..4)

**Wave 2** *(blocked on Wave 1 completion)*

- [ ] 95-02-PLAN.md — Frontend: badge tri-estado, filtro e seção Auditoria em `Nps/Index.jsx` + checkpoint visual (AB-95-1..3)

**Requisitos:**

- **AB-95-1 — Badge na listagem.** Listagem de NPS respondidos ganha indicador de confiança (verde confiável / amarelo atenção / vermelho suspeita) visível apenas para role `admin`.
- **AB-95-2 — Seção de auditoria no detalhe.** Detalhe do NPS mostra, só para admin: gerado em/por, aberto em, respondido em, tempo até resposta, IPs (abertura/resposta), user-agent, canal de envio e motivos de suspeita.
- **AB-95-3 — Filtros.** Filtro Todos / Confiáveis / Com alerta / Suspeitos, apenas para admin.
- **AB-95-4 — Blindagem de payload.** Para não-admin o controller NÃO envia nenhum campo de suspeita/auditoria no props Inertia — ocultação no backend, nunca só na renderização.

**Success Criteria** (o que deve ser VERDADE):

1. Admin vê badge, filtros e seção de auditoria; consultor/mentor vê a listagem idêntica à de hoje, sem coluna, badge ou filtro novo
2. Inspecionar o payload Inertia logado como não-admin não revela `is_suspicious`, motivos, IPs ou user-agent
3. Motivos de suspeita aparecem em linguagem clara pt-BR (sem jargão técnico cru)

**UI hint**: yes

### Phase 96: NPS Anti-Burlamento — endurecimento e gestão

**Goal:** A camada passa de observar para agir: usuário interno logado é bloqueado de responder, IPs internos são configuráveis pela UI e admin pode invalidar resposta suspeita com efeito nas agregações.
**Requirements**: AB-96-1, AB-96-2, AB-96-3
**Depends on:** Phase 95
**Plans:** 5/5 plans complete

- [x] 96-01-PLAN.md — AB-96-1: bloqueio de submit em sessão interna (7º event_type `blocked` + página amigável)
- [x] 96-02-PLAN.md — AB-96-2: IPs/CIDRs internos configuráveis pela UI (Configuracao ∪ .env)
- [x] 96-03-PLAN.md — AB-96-3: fundação da invalidação (flag + scopeValida + ação admin + cache-busting + UI)
- [x] 96-04-PLAN.md — AB-96-3: aplicar scopeValida nos 8 call-sites de agregação (bônus/dashboards/metas)
- [x] 96-05-PLAN.md — Gate final: regressão Nps+V16+Desempenho + build + checkpoint visual

**Requisitos:**

- **AB-96-1 — Bloqueio de sessão interna.** Resposta em sessão autenticada de usuário interno é bloqueada (upgrade do "marcar" da Fase 94) com mensagem amigável; evento registrado em `nps_survey_events`.
- **AB-96-2 — IPs pela UI.** IPs/CIDRs internos da ECF configuráveis pelo painel (NPS > Configuração), com o `.env` como fallback/default.
- **AB-96-3 — Invalidação manual.** Admin pode invalidar uma resposta suspeita (com trilha no activitylog); resposta invalidada sai das agregações (dashboards NPS, médias, snapshots que alimentam bônus) de forma consistente — atenção especial a `nps_response_scores`/`nps_score_assignments` (fonte do bônus v16.0).

**Success Criteria** (o que deve ser VERDADE):

1. Usuário interno logado que abre um link NPS não consegue submeter resposta; o bloqueio fica auditado
2. Admin gerencia a lista de IPs internos pela UI sem tocar em `.env`/deploy
3. Invalidar resposta remove seu efeito de dashboards e do NPS médio do Desempenho (assignments), com registro de quem invalidou e quando

### Phase 97: Redesign da Dashboard Mercado Livre (v-dash)

**Goal:** Reformular por completo a dashboard do setor Mercado Livre (`Dashboard/Admin.jsx` + `DashboardController::adminDashboard`) seguindo o mockup do usuário: filtros práticos (rascunho→aplicar, chips, colapsável) que propagam a TODOS os widgets, 4 KPIs com variação vs período anterior e links para áreas completas, gráfico de evolução interativo (Faturamento/Margem), widget de detratores de NPS, score da equipe pela nota oficial, e novas empresas do mês.
**Requirements**: DASH-97-1, DASH-97-2, DASH-97-3, DASH-97-4, DASH-97-5, DASH-97-6, DASH-97-7
**Depends on:** Fase 96 (usa `scopeValida()` nas leituras de NPS) — já executada
**Plans:** 3/4 plans executed

**Success Criteria** (o que deve ser VERDADE):

1. Os filtros (Período/Empresa/Grupo/Estrategista/Analista) usam rascunho→Aplicar com chips removíveis, e **todos os widgets** (KPIs, gráfico, NPS ruim, score da equipe, novas empresas) refletem o recorte aplicado — incluindo os 2 que hoje ignoram (`performance_equipe` e `nps_pendentes`)
2. Filtrar em `/dashboard/mercadolivre` preserva o recorte `marketplace='meli'` (corrige o bug do `route('dashboard')` hardcoded); a `/dashboard` genérica não regride
3. Os 4 KPIs (Faturamento, Margem ponderada, NPS médio, Empresas ativas) mostram valor + variação vs período anterior + link para a área completa
4. Gráfico "Evolução no período" com abas Faturamento/Margem, série diária do recorte e hover interativo (tooltip + Pico/Menor)
5. Widget "NPS ruim" lista respostas nota ≤5 do recorte (excluindo invalidadas — Fase 96), com link para o NPS completo; "Score da equipe" usa `DesempenhoScoreService.nota_final` (0–5) respeitando o recorte; "Novas empresas no mês" usa início de `contratos_servico` no mês
6. Estados de carregando/vazio/erro reais; `npm run build` exit 0

**UI hint**: yes

Plans:

**Wave 1**

- [x] 97-01-PLAN.md — Backend: janelas período-anterior (deltas KPIs), margem ponderada, série diária de margem, contagem de empresas novas (D3) e base do fix do marketplace no payload

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 97-02-PLAN.md — Backend: widgets no recorte (Score da equipe filtrado + nps_pendentes via forCompanies), NPS ruim com scopeValida (Fase 96) e novas empresas detalhadas
- [x] 97-03-PLAN.md — Frontend: filtros rascunho→aplicar + chips + colapsável, correção da navegação do marketplace e 4 KPIs com delta/link

**Wave 3** *(blocked on Wave 2 completion)*

- [ ] 97-04-PLAN.md — Frontend: gráfico Faturamento/Margem interativo, cards NPS ruim/Score/Novas empresas, estados reais + checkpoint visual

## Dependências — Iniciativa NPS Anti-Burlamento (Fases 94-96)

- **94** é fundação (schema + captura + serviço de suspeita) — 95 e 96 dependem dela
- **95** depende de **94** — a UI lê os campos e vereditos persistidos
- **96** depende de **95** — endurecimento e gestão sobre a camada já visível
- Independente da milestone v17.0 (Fases 88-93) — frentes convivem, sem arquivos em comum previstos

## Coverage Map — Iniciativa NPS Anti-Burlamento

| REQ | Fase |
|-----|------|
| AB-94-1..5 | Fase 94 |
| AB-95-1..4 | Fase 95 |
| AB-96-1..3 | Fase 96 |

**Fora de escopo (decidido no import de 2026-07-16):** seções 4-8 do `PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md` — módulo Digisac (client, config, mapeamento empresa×grupo, envio NPS via Digisac, aba Envio Automático) e unicidade mensal do link já estão entregues (v15.5/v16.0: `DigisacClient`, `config/digisac.php`, colunas `digisac_*` em `companies`, `nps_digisac_envios`, `EnvioAutomatico.jsx`, guards de duplicata em `NpsDispararMensal` e `NpsController`). Generalização do Digisac para outros setores (tabela polimórfica `digisac_messages`) fica para quando existir um segundo consumidor.

## Dependências — Milestone v17.0 (Fases 88-93)

- **88** é fundação — todas as demais fases da milestone dependem do `CarteiraContextService`
- **89 → 90** — carteira individual antes da consolidada (a consolidada reusa os componentes da individual)
- **91** depende de **88** — usa o mesmo universo de vínculos do `CarteiraContextService`
- **92** depende de **91** — UI de Desempenho consome os metadados calculados pelo service
- **93** é independente — pode ser executada a qualquer momento, inclusive por último

## Coverage Map — Milestone v17.0

| Categoria | REQ | Fase |
|-----------|-----|------|
| CTX (Contexto) | CTX-01 | Fase 88 |
| CTX | CTX-02 | Fase 88 |
| CTX | CTX-03 | Fase 88 |
| CTX | CTX-04 | Fase 88 |
| CTX | CTX-05 | Fase 88 |
| CART (Carteira) | CART-01 | Fase 89 |
| CART | CART-02 | Fase 89 |
| CART | CART-03 | Fase 89 |
| CART | CART-04 | Fase 89 |
| CART | CART-05 | Fase 89 |
| CART | CART-08 | Fase 89 |
| CART | CART-06 | Fase 90 |
| CART | CART-07 | Fase 90 |
| DESEMP (Desempenho) | DESEMP-01 | Fase 91 |
| DESEMP | DESEMP-02 | Fase 91 |
| DESEMP | DESEMP-03 | Fase 91 |
| DESEMP | DESEMP-04 | Fase 91 |
| DESEMP | DESEMP-05 | Fase 91 |
| DESEMP | DESEMP-06 | Fase 91 |
| DESEMP | DESEMP-07 | Fase 91 |
| DESEMP | DESEMP-08 | Fase 92 |
| MENU (Menu) | MENU-01 | Fase 93 |

**Cobertura:** 22/22 REQs v17.0 mapeadas ✓ — zero órfãos, zero duplicatas.
---
*Roadmap criado: 2026-07-07 — Milestone v15.0 (NPS Templates) — 6 phases (68-73) cobrindo 29 REQs; granularity=standard*
*Roadmap atualizado: 2026-07-09 — Phase 74 (Módulo Desempenho v2) adicionada como tail da milestone, cobrindo 14 REQs DESEMP em 10 plans / 5 waves*
*Roadmap atualizado: 2026-07-15 — Phase 82 (Planilha Excel-like, glide-data-grid) planejada: 7 plans / 7 waves cobrindo SHEET2-01..08; cadeia sequencial por file ownership único (`GradeAnuncioGlide.jsx`)*
*Roadmap atualizado: 2026-07-15 — Phase 85 planejada: 5 plans / 3 waves cobrindo COL-85-1..4. O risco crítico previsto (regredir o wizard) NÃO existe: `colunasCategoria` e `atributos()` já são métodos separados, com 1 consumidor cada — sem parametrização. Foto = 0 mudança de backend (`ItemBuilderBase` já repassa `pictures`); a fase toca 1 arquivo PHP e 3 JS; nenhum pacote novo*
*Roadmap atualizado: 2026-07-15 — Phase 83 planejada: 5 plans / 4 waves cobrindo FIX-83-1..6. FIX-83-1 e FIX-83-2 são o MESMO bug (falta o merge prop→estado), tratados como um bloco; wave 2 é paralela (`AnunciarMassa.jsx` × `GradeAnuncioGlide.jsx`); 100% frontend — nenhuma rota, migration ou pacote novo*
*Roadmap atualizado: 2026-07-15 — Phase 86 planejada: 4 plans / 3 waves cobrindo HIST-86-1..3. HIST-86-2 e a clonagem NÃO são reimplementados: `duplicarComoTemplate`/`criarTemplateInterno` (Phase 81, 6 testes) já clonam o payload inteiro e zeram os `ml_item_id` — a fase liga o botão neles e o front redireciona (a rota devolve JSON e mantém o consumidor vivo em `AnunciarML.jsx:1355`). `Mlb/Historico.jsx` já é de outro módulo → página nova = `AnunciosHistorico.jsx`. Busca por título atravessa JSON (`payload->title`): verificada de fato em MariaDB (prod) e SQLite (phpunit) no planejamento. Nenhum pacote novo*
*Roadmap atualizado: 2026-07-16 — Iniciativa NPS Anti-Burlamento anexada via /gsd-import de `PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md`: 3 fases (94-96) cobrindo AB-94-1..5, AB-95-1..4, AB-96-1..3. Escopo reduzido no import: seções Digisac e unicidade mensal do plano descartadas por já estarem entregues (v15.5/v16.0). Cadeia 94→95→96, independente da v17.0.*
*Roadmap atualizado: 2026-07-16 — Milestone v17.0 (Carteira e Desempenho multi-servico) anexada: 6 fases (88-93) cobrindo as 22 REQs (CTX/CART/DESEMP/MENU) do REQUIREMENTS.md, estrutura vinda do plano canonico do usuario (plano-carteira-desempenho-multi-servico.md). Fundacao em 88 (CarteiraContextService); 89->90 (individual antes de consolidada); 91 depende de 88, 92 depende de 91; 93 independente. Fases 60-87 preservadas intactas.*

### Phase 100: MetricPeriodResolver (v18.0)

**Goal:** Existe um resolvedor único de período (`MetricPeriodResolver`) que resolve janela atual + janela comparativa por modo (operacional / oficial-bônus / mês-fechado / custom), competência de bônus, datas inclusivas, timezone America/Sao_Paulo e label pra UI — nenhum controller do núcleo (Fases 102-104) monta período na mão.
**Requirements**: PER-01, PER-02, PER-03, PER-04, PER-05, PER-06
**Depends on:** Nada (fundação da milestone v18.0)
**Plans:** 1 plan em 1 wave

**Success Criteria** (o que deve ser VERDADE):

1. `MetricPeriodResolver::resolve($filtros)` retorna o shape completo (`mode`, `period_key`, `current_start/end`, `baseline_start/end`, `days_count`, `comparison_mode`, `timezone`, `data_fresh_until`, `bonus_payment_month`, `bonus_competence_month`, `is_current_month`, `is_closed`) com datas inclusivas e timezone `America/Sao_Paulo`
2. Modo operacional resolve `01/mês..último dia confiável` vs. mesmo intervalo do mês anterior — nunca compara N dias do mês atual com o mês anterior inteiro, nunca usa dia ainda não consolidado pela fonte
3. Modo oficial/bônus resolve a competência do último mês fechado com baseline de janela de mesmo tamanho (exemplo canônico: em julho/2026 → competência junho/2026, pagamento julho/2026, atual `01/06..30/06`, baseline `02/05..31/05`); modo mês fechado selecionado e período custom seguem a mesma regra de janela-de-mesmo-tamanho (ex.: maio/2026 → `01/05..31/05` vs `31/03..30/04`; custom `01/06..15/06` → baseline `17/05..31/05`)
4. Suite `tests/Unit/MetricPeriodResolverTest.php` cobre os 4 casos obrigatórios do plano canônico (mês atual em 20/07; último fechado em 20/07; filtro junho; custom 01/06..15/06) — todos verdes
5. O resolver é a única fonte de período do núcleo — nenhum controller/tela consumidor (Fases 102-104) monta janela ou calcula "mês passado" manualmente fora dele; contrato único documentado e verificável por gate nos consumidores

Plans:

- [ ] 100-01-PLAN.md — MetricPeriodResolver (service puro) + suite unitária: modos operacional/oficial-bônus/mês-fechado/custom + 4 casos obrigatórios

### Phase 101: AdmanMetricDiffService (v18.0)

**Goal:** Existe uma camada dedicada (`AdmanMetricDiffService`) que lê a variação pronta da Adman (`.diff`) em vez de recalcular margem/faturamento na mão — hoje o `AdmanService` descarta o `.diff` e lê só `['value']` — com fallback marcado quando a Adman não trouxer o diff para a janela.
**Requirements**: ADM-01, ADM-02, ADM-03, ADM-04, ADM-05
**Depends on:** Nada (independente do resolver — pode ser planejada/executada em paralelo à Fase 100)
**Plans:** 2/2 plans complete

**Success Criteria** (o que deve ser VERDADE):

1. `AdmanMetricDiffService` lê `revenue`, `profitMargin.value`/`.diff` e `percentageMargin.value`/`.diff` da resposta/cache Adman — cobrindo o gap atual do `AdmanService`, que descarta o `.diff`
2. O service prefere o diff oficial da Adman (`diff_source='adman_diff'`); só usa fallback calculado quando o diff não existe para a janela consultada, marcando `diff_source='calculated_fallback'`
3. O diff de período é persistido/retornado com contexto de período e fonte — não vira fato diário; fato diário continua guardando o valor do dia, snapshot/retorno de período guarda a comparação da janela
4. **[REFRAMADO por research empírico 2026-07-17]** Sem backfill de coluna — a arquitetura é live-read (fato diário `AdmanMetric` não recebe colunas de diff de período; o research provou que `raw_data.diff` é sempre DIÁRIO, não de período, e que `percentageMargin` nunca esteve no `raw_data`). O helper `lerDiffDiarioRawData(scope='daily')` expõe o diff diário legítimo do `raw_data` COM guard anti-confusão (nunca retorna `diff_source='adman_diff'`), impedindo que a Fase 102 confunda diff diário com diff de período. Aceito pelo usuário 2026-07-17.
5. Labels separados sem ambiguidade — Margem R$ (`profitMargin`) distinta de Margem % (`percentageMargin`); teste garante que `percentageMargin.value` nunca é usado como se fosse variação manual de `contribution_margin`

Plans:

- [ ] 101-01-PLAN.md — AdmanMetricDiffService (núcleo): leitura ao vivo do diff de período com gate por comparison_mode + fallback calculado + leitura detalhada aditiva de account-metrics (ADM-01/02/03/05)
- [ ] 101-02-PLAN.md — ADM-04 reframado: helper de diff DIÁRIO do raw_data (scope=daily) + guard anti-confusão (Pitfall 1); sem backfill de colunas (live-read)

### Phase 102: Desempenho oficial por competência (v18.0)

**Goal:** `DesempenhoScoreService` passa a consumir o `MetricPeriodResolver` e o `AdmanMetricDiffService`: o ranking oficial de bônus usa a competência do mês fechado (julho paga junho) e `var_margem_pct` usa o diff pronto da Adman — preservando o score único e as invariantes de elegibilidade financeira da v17.0.
**Requirements**: BON-01, BON-02, BON-03, BON-04, BON-05
**Depends on:** Phase 100 (`MetricPeriodResolver`), Phase 101 (`AdmanMetricDiffService`)
**Plans:** 2 plans

**Success Criteria** (o que deve ser VERDADE):

1. `DesempenhoScoreService` consome o `MetricPeriodResolver` — o cálculo de var. faturamento/margem usa `period.current_*`/`period.baseline_*`, não `now()`/`startOfMonth` inline
2. Ranking oficial de bônus em julho/2026 usa competência junho/2026 fechada (atual `01/06..30/06` vs `02/05..31/05`) — o score de junho é exibido/pago em julho
3. `var_margem_pct` usa `percentageMargin.diff` da Adman via `AdmanMetricDiffService` quando disponível; fallback calculado só quando ausente, marcado — nenhum teste aceita variação manual quando `adman_diff` existe
4. O retorno do service adiciona `periodo` (janelas atual/baseline) e `bonus` (`payment_month`, `competence_month`) aos metadados; score único preservado — sem score por marketplace (invariante da v17.0)
5. Leitura operacional segue disponível (mês em curso) mas marcada como operacional/parcial; a régua de elegibilidade financeira da v17.0 (`financial_metrics_eligible`, `score_status`) permanece intacta

Plans:

- [ ] 102-01-PLAN.md — Núcleo do cálculo: janelas via MetricPeriodResolver + margem via AdmanMetricDiffService + fixtures densos e âncora recalibrada (BON-01/02/03)
- [ ] 102-02-PLAN.md — Metadados periodo/bonus + cache v5 com period_key + invariantes v17 + regressão dual-path (BON-02/04/05)

### Phase 103: Carteira por período (v18.0)

**Goal:** `renderCarteiraProfissional` e `renderCarteirasConsolidadas` usam o `MetricPeriodResolver` + filtro de período e a variação financeira vem do diff da Adman quando disponível — coerência de janela entre todos os blocos da tela, sem regredir a elegibilidade financeira da v17.0.
**Requirements**: CAR-01, CAR-02, CAR-03
**Depends on:** Phase 100 (`MetricPeriodResolver`), Phase 101 (`AdmanMetricDiffService`)
**Plans:** 2 plans

**Success Criteria** (o que deve ser VERDADE):

1. `renderCarteiraProfissional` e `renderCarteirasConsolidadas` resolvem período via `MetricPeriodResolver`; quando o filtro for mês fechado, o cálculo não usa `now()` nem "mês em curso"
2. A soma financeira da carteira usa as janelas do resolver (atual/baseline) e a variação de margem vem do diff da Adman via `AdmanMetricDiffService` quando disponível; elegibilidade financeira da v17.0 preservada (Shopee sem fonte continua sem entrar)
3. Todos os cards/tabelas/séries da carteira leem `period.current_start/end` e `period.baseline_start/end` — coerência de janela entre todos os blocos da mesma tela

Plans:

- [ ] 103-01-PLAN.md — Carteira individual: periodo via MetricPeriodResolver + variacao de margem via AdmanMetricDiffService (contribution_margin_value) + payload periodo (CAR-01/02/03)
- [ ] 103-02-PLAN.md — Carteira consolidada: janela do resolver para as somas + payload periodo, escopo minimo sem variacao nova (CAR-01/03)

### Phase 104: UI de período (v18.0)

**Goal:** O ranking `/performance` e a carteira exibem um toggle de contexto de período (Em curso / Bônus atual / Mês fechado) e o payload carrega `periodo` + `bonus.competence_month`/`payment_month` — a tela nunca deixa o usuário confundir número em curso com número de pagamento.
**Requirements**: UIP-01, UIP-02, UIP-03, UIP-04
**Depends on:** Phase 102 (Desempenho oficial por competência), Phase 103 (Carteira por período)
**Plans:** TBD

**Success Criteria** (o que deve ser VERDADE):

1. O ranking `/performance` e a carteira exibem um toggle/segmento de contexto de período — "Em curso" / "Bônus atual" / "Mês fechado" (+ mês específico) — com rótulos sem jargão
2. O payload Inertia dessas telas carrega `periodo` (janelas + label) e, no modo bônus, `bonus.competence_month`/`payment_month`; a tela mostra a competência avaliada e o mês de pagamento
3. Filtro de período disponível nas telas de resultado do núcleo (carteira individual, consolidada, ranking); toda comparação exibida vem da janela resolvida, não de cálculo próprio da tela
4. A tela indica claramente quando está em modo operacional/parcial vs. oficial de bônus — para não confundir o número em curso com o número de pagamento

Plans:

- [ ] TBD (run /gsd:plan-phase 104 to break down)

**UI hint**: yes

### Phase 105: Correção — janela do NPS no bônus por competência (v18.0)

**Goal:** O componente NPS do bônus de competência M passa a ler as respostas coletadas em M+1 (o mês de pagamento) — não as do próprio mês M. Regra do usuário 2026-07-21: "o NPS rodando AGORA (julho) conta pra nota de junho paga este mês; o NPS de agosto contará pro bônus de julho". O financeiro (faturamento/margem) continua na competência M; só o NPS é deslocado +1 mês.
**Requirements**: NPSWIN-01 (janela +1 no caminho fechado), NPSWIN-02 (exclui-vs-0.0 no em-curso/coleta), NPSWIN-03 (cron fim-do-mês + cache bump v6 + bust por competência), NPSWIN-04 (regressões: dual-path, score único, elegibilidade, âncora recalculada)
**Depends on:** Phase 102 (computeOficial/closed-month), Phase 100 (resolver — pode precisar de uma janela de NPS separada da financeira)
**Origem:** bug exposto pela validação numérica pós-deploy da v18 — Felipe competência junho deu 1.50 (NPS lido de junho=0 respostas→0.0) quando deveria ser ~3.5 (NPS lido de julho=13 respostas→4.97). Confirmado em prod 2026-07-21.

**Success Criteria** (a refinar no discuss/research — escopo aberto por decisão do usuário):

1. computeOficial(M) lê o componente NPS das atribuições coletadas em M+1 (não em M); financeiro segue em M
2. Decisão de escopo pendente (discuss): a regra +1 vale só pro bônus oficial de mês fechado, ou também pra tela "Em curso"? Impacto em snapshots e no caminho operacional (byte-idêntico à v17) a mapear
3. Regressões preservadas: score único, elegibilidade financeira, o caminho operacional atual não regride sem decisão explícita
4. Validação numérica em prod pós-fix: os profissionais com NPS coletado em M+1 refletem o número correto (Felipe junho ~3.5, não 1.5)

**Plans:** 3 plans

Plans:

- [ ] 105-01-PLAN.md — Deslocamento +1 da janela de NPS no caminho fechado + mecânica exclui/0.0 + cache bump v5→v6 (NPSWIN-01/02)
- [ ] 105-02-PLAN.md — Cron desempenho:consolidar-mes congela no fim do mês de coleta (D2), consolidando a competência certa (NPSWIN-03)
- [ ] 105-03-PLAN.md — NpsController bust por competência (X−1) + regressão âncoras (janela M+1, golden documentado) (NPSWIN-03/04)

### Phase 106: Fix timeout do mês fechado — warm cache + degradação graciosa (v18.0)

**Goal:** O ranking /performance nos modos "Bônus atual"/"Mês fechado" nunca dá tela branca por timeout. O `WarmDesempenhoCache` agendado passa a aquecer também a competência do último mês fechado (mantém "Bônus atual" sempre rápido), e a tela degrada com "calculando…" quando o mês pedido está frio, em vez de computar ~14 profissionais ao vivo na requisição (N+1 Adman → >300s → tela branca).
**Requirements**: PERF-01 (a definir na fase)
**Depends on:** Phase 102 (closed-month compute), Phase 104 (toggles Bônus atual/Mês fechado)
**Origem:** bug de produção reportado pelo usuário 2026-07-21 — clicar "Bônus atual"/"Mês fechado" carrega longo e dá tela branca. Causa: 0 snapshots mensais → ranking computa todos ao vivo; ~8.9s/profissional cold (Adman N+1) × ~14 = >125s + concorrência > timeout web 300s. WarmDesempenhoCache só aquece o mês corrente. Band-aid aplicado: cache de junho aquecido manualmente em prod (dura ~7d).

**Success Criteria** (a refinar):

1. `WarmDesempenhoCache` aquece o mês corrente E a competência do último mês fechado (via MetricPeriodResolver last_closed_month) — "Bônus atual" sempre quente
2. O ranking no modo fechado, quando o mês pedido está FRIO, não computa tudo ao vivo na requisição — degrada (estado "calculando…"/warm em background), sem estourar o timeout web
3. Nenhuma tela branca por timeout em nenhum modo; "Em curso" (já aquecido) intocado
4. Não regride os números (v18/105) nem a régua — só o CAMINHO de carregamento

Plans:

- [ ] 106-01-PLAN.md — Backend: warm de 2 alvos (corrente + último fechado) + wrapper isCached (SC1/SC2)
- [ ] 106-02-PLAN.md — Controller: gate quente/frio no modo fechado + dispatch warm com lock (SC2/SC3)
- [ ] 106-03-PLAN.md — Frontend: estado "calculando…" + poll parcial com teto (SC2/SC3)

## Dependências — Milestone v18.0 (Fases 100-104)

- **100** (`MetricPeriodResolver`) e **101** (`AdmanMetricDiffService`) são fundação independente uma da outra — nenhuma depende da outra; podem ser planejadas/executadas em paralelo
- **102** depende de **100** e **101** — o cálculo oficial de bônus precisa do resolver de período e do diff da Adman para `var_margem_pct`
- **103** depende de **100** e **101** — mesma razão da 102, aplicada à carteira
- **104** depende de **102** e **103** — a UI de período consome o payload (`periodo`, `bonus`) que 102/103 passam a expor
- Independente das Fases 94-96 (NPS Anti-Burlamento, dev paralelo) — numeração com buffer 97-99 evita colisão

## Coverage Map — Milestone v18.0

| Categoria | REQ | Fase |
|-----------|-----|------|
| PER (MetricPeriodResolver) | PER-01 | Fase 100 |
| PER | PER-02 | Fase 100 |
| PER | PER-03 | Fase 100 |
| PER | PER-04 | Fase 100 |
| PER | PER-05 | Fase 100 |
| PER | PER-06 | Fase 100 |
| ADM (AdmanMetricDiffService) | ADM-01 | Fase 101 |
| ADM | ADM-02 | Fase 101 |
| ADM | ADM-03 | Fase 101 |
| ADM | ADM-04 | Fase 101 |
| ADM | ADM-05 | Fase 101 |
| BON (Desempenho oficial) | BON-01 | Fase 102 |
| BON | BON-02 | Fase 102 |
| BON | BON-03 | Fase 102 |
| BON | BON-04 | Fase 102 |
| BON | BON-05 | Fase 102 |
| CAR (Carteira por período) | CAR-01 | Fase 103 |
| CAR | CAR-02 | Fase 103 |
| CAR | CAR-03 | Fase 103 |
| UIP (UI de período) | UIP-01 | Fase 104 |
| UIP | UIP-02 | Fase 104 |
| UIP | UIP-03 | Fase 104 |
| UIP | UIP-04 | Fase 104 |

**Cobertura:** 23/23 REQs v18.0 mapeadas ✓ — zero órfãos, zero duplicatas.

### Phase 109: Shopee em Carteira e Desempenho (regra do ML, sem margem por ora)

**Goal:** Empresas do setor Shopee (conectadas via API — ex: Ale Peças, Baraoshop) passam a aparecer nas carteiras de quem cuida e na aba Desempenho, usando a MESMA regra de período do Mercado Livre. "Em curso" = dia 01 do mês atual até hoje vs mesmo intervalo do mês passado; "Bônus atual" = mês fechado vs janela equivalente anterior (badge de crescimento/queda). Fonte: `shopee_metrics` (diária: `revenue`=faturamento, `ad_expense`=investimento; SEM margem/CMV). Uma fase cobre Carteira + Desempenho juntos (compartilham a mesma fonte de diff).

**Requirements**: SHOP-CAR-01 (Shopee elegível financeiro na carteira), SHOP-CAR-02 (faturamento+investimento por período, mesma janela do ML), SHOP-DES-01 (Shopee entra no score/ranking de Desempenho), SHOP-DES-02 (margem placeholder=1 até haver dado, arquitetura future-ready) — a refinar no plan.

**Depends on:** Phase 100 (`MetricPeriodResolver` — janelas em-curso/fechado, agnóstico de fonte), Phase 101 (`AdmanMetricDiffService` — contrato de diff a espelhar), Phase 103/104 (Carteira/UI por período)

**Escopo cirúrgico** (fonte única hoje = `AdmanMetricDiffService::compute()`):

1. `CarteiraContextService::flagsFinanceirasPorSetor()` — habilitar branch `Servico::SETOR_SHOPEE` como elegível financeiro (`financial_source='shopee'`); hoje só `performance` é elegível.
2. Criar `ShopeeMetricDiffService` com o MESMO contrato de retorno do `AdmanMetricDiffService` (revenue + investimento com `diff_pct`; `contribution_margin_*` = null), lendo `shopee_metrics` no MESMO `$periodo`.
3. Dispatcher por fonte (Adman vs Shopee) nas ~4 chamadas diretas a `admanDiffService->compute()`: `DesempenhoScoreService::computeVarFaturamento()`/`computeVarMargem()`, `PortfolioController::renderCarteiraProfissional()`/`transparencia()`.
4. Régua de margem / `score_status` tolerante a margem ausente (não bloquear/`partial` indevido); bump da cacheKey do desempenho (v9→v10, atualizar strings nos testes); warm cache Shopee equivalente ao `WarmAdmanDiffCache`. `MetricPeriodResolver` NÃO muda. UI (Transparencia/AdminCarteira/filtro `shopee` do Performance) já recebe `fonte='shopee'` — passa a receber número real.

**Decisão de negócio** (usuário 2026-07-23): a nota do Desempenho mantém as 3 dimensões **Faturamento + Margem + NPS**. Como Shopee ainda NÃO tem dado de margem, a **nota de margem das empresas Shopee = 1** (piso da régua 1-5) como placeholder, com arquitetura pronta para receber margem real no futuro. ⚠️ Margem=1 puxa a média/ranking pra baixo em profissional só-Shopee — a VERIFICATION deve sinalizar esse impacto com números reais para confirmação.

**Ressalvas de dados:** Ads (investimento) só tem ~6 meses de histórico → comparação de investimento em janela antiga fica `null`. Dias sem venda não geram linha → tratar ausência como zero ao somar/comparar períodos. Cobertura histórica de faturamento depende de backfill já rodado (`MIN(reference_date)` por empresa).

**Plans:** 4/4 plans complete

Plans:

- [x] 109-01-PLAN.md — Fundacao: ShopeeMetricDiffService (espelha contrato Adman, margem null) + MetricDiffDispatcher + branch shopee elegivel (SHOP-CAR-01)
- [x] 109-02-PLAN.md — Carteira: dispatch por fonte em transparencia/AdminCarteira/Carteiras consolidada + UI Shopee (faturamento+investimento, margem "-") + build (SHOP-CAR-01/02)
- [x] 109-03-PLAN.md — Desempenho: dispatch por fonte + margem placeholder=1 (future-ready) + score_status tolerante + cacheKey v9->v10 + warm cache Shopee (SHOP-DES-01/02)
- [x] 109-04-PLAN.md — Checkpoint: validacao visual carteira Shopee + confirmacao numerica do impacto de margem=1 no ranking so-Shopee (SHOP-CAR-02, SHOP-DES-02)

### Phase 110: Fix margem Adman: preferir fallback local deterministico + blindar congelamento (rate-limit)

**Goal:** Estabilizar a nota de MARGEM do bônus de desempenho, hoje volátil por rate-limit 429 na leitura ao vivo da Adman (root cause em `.planning/debug/margem-adman-diff-instavel.md`). (a) `AdmanMetricDiffService::resolveField()` passa a preferir o `calculated_fallback` LOCAL determinístico (cobertura de junho ~100% confirmada) sobre o `.diff` nativo ao vivo; (c) gate de cobertura mínima antes de depender do ao-vivo (null explícito em vez de fail-open); (b) `ConsolidarMesDesempenho` ganha retry/reconciliação de qualidade antes de persistir o snapshot mensal (é o snapshot que PAGA o bônus). PRAZO: fechar antes do congelamento oficial de junho em 31/07 14h BRT.

**Requirements**: FIXMARG-01 (fallback local prioritário p/ margem quando cobertura suficiente), FIXMARG-02 (gate de cobertura + null explícito, sem fail-open na média), FIXMARG-03 (congelamento mensal resiliente a falha transitória: retry/reconciliação/recusa)

**Depends on:** Phase 101 (AdmanMetricDiffService), Phase 102/105 (compute oficial + congelamento). Diagnóstico: /gsd:debug margem-adman-diff-instavel (root cause: rate-limit 429 concorrente, NÃO lag).

**Success Criteria:**

1. Recomputes sucessivos da margem de um profissional só-performance (ex.: Luiz) no mesmo mês fechado dão valor ESTÁVEL (determinístico do local), não swinga com rate-limit concorrente.
2. Cobertura insuficiente + ao-vivo indisponível → margem null explícita (fora da média), nunca fail-open silencioso que polui `n_com_margem_real`.
3. `desempenho:consolidar-mes` não persiste snapshot com componente de margem vindo de amostra com falhas; retry/reconcilia ou recusa+alerta.
4. Números convergem pro determinístico local (que bate com a dashboard Adman); sem novo viés; regressão preservada (cacheKey bump se compute() mudar).

**Plans:** 2/2 plans complete

Plans:

- [x] 110-01-PLAN.md — AdmanMetricDiffService: contribution_margin_pct prefere calculated_fallback LOCAL sobre .diff nativo quando cobertura >= 80% + gate de cobertura + null explicito + cacheKey v10->v11 (FIXMARG-01/02)
- [x] 110-02-PLAN.md — ConsolidarMesDesempenho resiliente: compute() expoe margem_amostra + gate de cobertura no congelamento (recusa+alerta, preserva snapshot anterior) (FIXMARG-03)

---

## Milestone v20.0 — Handoff Comercial HubSpot (enriquecimento + valor mensal×anual)

**Spec de referência:** `prompt-claude-otimizacao-comercial-hubspot.md` (raiz do repo, 2026-07-23). Transforma a integração HubSpot→Comercial num "handoff operacional": empresa/contrato chegam com dados máximos e confiáveis, valor operacional correto (mensal quando o serviço é mensal), origem HubSpot persistida estruturada para auditoria/replay, dedup básica e pendências claras quando a inferência não é segura. **Aditivo — preserva 100% do fluxo legado (Fases 34–37) e todos os testes atuais.**

**Numeração:** continuidade após Phase 110 (v19). Fases 111–115. Fundação (111) → núcleo do valor (112) → enriquecimento/dedup (113) → UI/replay (114) → E2E/docs (115).

**Constraint dura (critério de aceite âncora):** deal fechado ganho com line item mensal R$ 3.000 + deal amount/ARR R$ 36.000 → `contratos_servico.valor_contratado = 3.000`; os R$ 36.000 ficam só em campo de auditoria. Nenhum teste chama o HubSpot real (`Http::fake` sempre); tokens nunca em log; propriedade HubSpot ausente = null no snapshot, nunca falha o webhook.

### Phase 111: Fundação — descoberta de propriedades, API client ampliado e campos estruturados (v20.0)

**Goal:** A base do handoff existe sem mudar comportamento: `config/services.php` aceita as novas props HubSpot por env (deal/company/contact/line_item) com fallback seguro; comando `hubspot:inspect-properties` valida nomes internos reais da conta via Properties API (sem vazar token); `HubspotApiClient` busca line items e associações com o conjunto ampliado de propriedades; migrations defensivas adicionam os campos estruturados de origem HubSpot em `companies` e `contratos_servico`. Fluxo legado e testes atuais intactos.
**Requirements**: HUB-API-01 (config props por env), HUB-API-02 (inspect-properties command), HUB-API-03 (fetchDealLineItems + associações ampliadas), HUB-SCHEMA-01 (colunas companies), HUB-SCHEMA-02 (colunas contratos_servico)
**Depends on:** Nada (fundação). Fases 34–37 já em prod.
**Success Criteria** (o que deve ser VERDADE):

  1. `config('services.hubspot.props')` expõe deal (observacao/description/closed_won_reason/closedate/pipeline/hs_mrr/hs_arr/hs_tcv/hs_acv/hs_currency), company (domain/industry/annualrevenue/city/state/country) e contact (mobilephone/jobtitle/hs_additional_emails), cada um via `env()` com default; ausência não quebra nada
  2. `php artisan hubspot:inspect-properties --objects=deals,line_items,companies,contacts` imprime nome interno + label + type + fieldType por objeto; nenhum token aparece na saída/log
  3. `HubspotApiClient::fetchDealLineItems` retorna as props mínimas do prompt (name/description/price/amount/quantity/hs_product_id/hs_sku/recurringbillingfrequency/hs_recurring_billing_period/hs_recurring_billing_start_date/hs_recurring_billing_end_date/hs_line_item_currency_code/hs_mrr/hs_arr/hs_tcv/hs_acv); métodos novos `fetchAssociated*Ids`/`fetchCompanies`/`fetchContacts` coexistem com os atuais sem quebrá-los
  4. Migration defensiva (`Schema::hasColumn`) adiciona em `companies`: `hubspot_deal_id`/`hubspot_company_id`/`hubspot_contact_id` (index), `nome_contato`, `cargo_contato`, `hubspot_domain`, `hubspot_observacao`, `hubspot_snapshot` (json) — com rollback
  5. Migration defensiva adiciona em `contratos_servico`: `hubspot_line_item_id` (index), `hubspot_product_id`, `hubspot_billing_frequency`, `hubspot_billing_period`, `hubspot_currency`, `hubspot_valor_original` (decimal 12,2), `hubspot_valor_original_tipo`, `hubspot_valor_normalizado_mensal` (decimal 12,2), `hubspot_valor_confidence`, `hubspot_valor_warning`, `hubspot_snapshot` (json) — com rollback

**Plans:** 3/3 plans complete

- [x] 111-01-PLAN.md — Config props ampliadas (services.hubspot.props) + comando hubspot:inspect-properties [HUB-API-01, HUB-API-02]
- [x] 111-02-PLAN.md — HubspotApiClient: fetchDealLineItems com props completas + 5 métodos de associação/batch [HUB-API-03]
- [x] 111-03-PLAN.md — Migrations defensivas companies (8 cols) + contratos_servico (11 cols) + fillable/casts dos models [HUB-SCHEMA-01, HUB-SCHEMA-02]

### Phase 112: HubspotValueResolver + extração do handoff service (v20.0) — NÚCLEO

**Goal:** A regra de valor mensal×anual vira uma classe testável isolada (`HubspotValueResolver`, TDD-first) e a normalização do webhook é extraída para um `HubspotDealHandoffService` fino, deixando o controller como orquestrador. `valor_contratado` passa a receber o valor **operacional** correto (mensal quando o serviço é mensal), com o valor bruto/anual e a proveniência gravados nos campos de auditoria da Fase 111.
**Requirements**: HUB-VAL-01 (resolver mensal×anual), HUB-VAL-02 (multi-line-item), HUB-VAL-03 (proveniência+confidence+warning gravados), HUB-VAL-04 (handoff service extraído), HUB-VAL-05 (controller fino, comportamento preservado)
**Depends on:** Phase 111 (colunas de auditoria + props ampliadas)
**Success Criteria** (o que deve ser VERDADE):

  1. `HubspotValueResolver::resolve(Servico, lineItem, dealProps)` retorna `[valor_operacional, valor_original, valor_original_tipo, normalizado_mensal, billing_frequency, billing_period, confidence, warning]` conforme spec §Fase 6 do prompt
  2. Casos-âncora passam: monthly price 3000 + deal amount 36000 → operacional 3000 (high); annually price 36000 + P1Y → operacional 3000; `hs_mrr=3000`/`hs_arr=36000` → usa MRR; serviço único amount 36000 → **não** divide por 12; sem line item, deal.amount 36000 + `valor_padrao` 3000 (tolerância 5%) → 3000 com warning de inferência; sem line item, deal.amount 35000 sem `valor_padrao` compatível → marca `valor_revisar`
  3. `HubspotDealHandoffService::build()` recebe o deal/lineItems/propsDeal já buscados (decisão de discrição: a busca HTTP e a criação de Company ficam no controller até a Fase 113) e devolve DTO único (`deal_data`/`line_items`/`contracts_to_create`/`warnings`/`confidence`; `company_data`/`contact_data` nullable reservados p/ Fase 113); controller passa a: validar → idempotência → chamar handoff → persistir (DB::transaction) → atualizar `hubspot_eventos` → notificar Comercial
  4. Cada `ContratoServico` criado grava `valor_contratado` = valor operacional + os campos `hubspot_valor_*` (original/tipo/normalizado_mensal/confidence/warning) preenchidos; multi-line-item resolve cada item e cria contratos separados por `hubspot_line_item_id` distinto
  5. Regressão zero: `Phase34HubspotWebhookTest`, `Phase35HubspotV2Test`, `Phase37*` continuam verdes; fluxo legado sem line items segue usando `deal.amount` com a nova coerção conservadora

**Plans:** 3/3 plans complete

Plans:

- [x] 112-01-PLAN.md — HubspotValueResolver (classe pura, TDD) + suite unitária dos 6 casos-âncora [HUB-VAL-01]
- [x] 112-02-PLAN.md — HubspotDealHandoffService + DTO HubspotHandoffData consumindo o resolver (multi-line-item) [HUB-VAL-02, HUB-VAL-04]
- [x] 112-03-PLAN.md — Controller fino delega ao handoff + persiste colunas de auditoria + E2E 36k→3k [HUB-VAL-02, HUB-VAL-03, HUB-VAL-05]

### Phase 113: Enriquecimento de contato/empresa + escolha de contato principal + dedup (v20.0)

**Goal:** O webhook deixa de pegar só o primeiro contato: busca todos os contatos associados, escolhe o principal de forma determinística e grava nome/cargo/telefone/email estruturados (não só em `notes`). Antes de criar `Company`, procura empresa existente (hubspot_company_id → cnpj → email → domain → nome normalizado) e enriquece em vez de duplicar; match fraco vira warning/pendência, não merge agressivo. Snapshot guarda todos os contatos.
**Requirements**: HUB-CONTATO-01 (todos os contatos + principal determinístico), HUB-CONTATO-02 (campos estruturados), HUB-DEDUP-01 (match forte enriquece), HUB-DEDUP-02 (match fraco = warning/pendência), HUB-DEDUP-03 (snapshot completo)
**Depends on:** Phase 112 (handoff service é o ponto de extensão)
**Success Criteria** (o que deve ser VERDADE):

  1. Deal com vários contatos escolhe o principal por prioridade (label útil futuro → email+telefone → email → telefone/mobilephone → primeiro); regra isolada e testada
  2. `companies.nome_contato` (firstname+lastname) e `companies.cargo_contato` (jobtitle) gravados estruturados; `email_cliente`/`telefone` seguem company e caem pro contato principal (incl. `mobilephone`) quando a company não tem; `notes` deixa de ser fonte única
  3. Match forte (`hubspot_company_id` ou `cnpj`) enriquece campos **vazios** sem sobrescrever preenchidos manualmente; adiciona contrato novo só se não existir `hubspot_line_item_id` igual — sem duplicar empresa/contrato
  4. Match fraco só por nome normalizado não faz merge automático de campos críticos; gera warning `possivel_duplicidade` (pendência na listagem se a UI suportar, senão no `hubspot_eventos.payload`)
  5. `companies.hubspot_snapshot` (ou tabela auxiliar) guarda deal/company/**todos os contatos**/line_items normalizados; regressão zero na suite

**Plans:** 3/3 plans executed — Phase 113 COMPLETA

Planos:

- [x] 113-01-PLAN.md — Unidades puras (TDD): HubspotContactSelector (contato principal determinístico) + HubspotNameNormalizer (dedup anti-falso-positivo)
- [x] 113-02-PLAN.md — Fetch batch de contatos + campos estruturados da Company + hubspot_snapshot completo + DTO company_data/contact_data
- [x] 113-03-PLAN.md — Dedup: HubspotCompanyMatcher + match forte enriquece (guard hubspot_line_item_id) + match fraco = warning possivel_duplicidade

### Phase 114: UI Comercial (campos+pendências novas) + comando de replay (v20.0)

**Goal:** A listagem Comercial expõe os dados enriquecidos (contato, cargo, observação, IDs HubSpot, valor operacional×original+frequência+confiança+warning) sem lotar a tela, com pendências novas (`sem_contato`/`valor_revisar`/`possivel_duplicidade`). Existe `hubspot:reprocess-event {id}` para recriar contratos faltantes depois que o admin cadastra um mapping ausente — sem duplicar company/contrato.
**Requirements**: HUB-UI-01 (novos campos na listagem), HUB-UI-02 (pendências novas origem-HubSpot), HUB-REPLAY-01 (comando de replay idempotente)
**Depends on:** Phase 113 (dados estruturados + snapshot para replay)
**Success Criteria** (o que deve ser VERDADE):

  1. `ComercialController::listagem()` + `Comercial/EmpresasListagem.jsx` exibem `nome_contato`/`cargo_contato`/`hubspot_observacao`/`hubspot_deal_id`/`hubspot_company_id` e o bloco de valor (operacional/original/frequência/confiança/warning) — detalhes em tooltip/drawer, não poluindo a grade
  2. Novas pendências `sem_contato`, `valor_revisar` e `possivel_duplicidade` aparecem **apenas** para empresas de origem HubSpot, coerentes com as já existentes
  3. `php artisan hubspot:reprocess-event {hubspot_evento_id}` reprocessa evento que ficou sem serviço por mapping ausente e cria/atualiza o contrato faltante; roda idempotente (não duplica company/contrato)
  4. O comando loga resumo estruturado (evento id, deal id, company id, contratos criados/atualizados/ignorados, warnings) no canal `ecf-webhooks`; nenhum token no log
  5. Regressão zero na listagem e no webhook; `Phase37ComercialListagemTest` continua verde

**Plans:** 3/3 plans complete

Plans:

- [x] 114-01-PLAN.md — Backend: payload enriquecido (contato/IDs + bloco de valor por contrato) + 3 pendências novas (sem_contato/valor_revisar/possivel_duplicidade) só origem HubSpot + counts/whitelist + gate Phase37 [HUB-UI-01, HUB-UI-02]
- [x] 114-02-PLAN.md — Frontend: EmpresasListagem.jsx estende mapas de pendência + modal de detalhes HubSpot leve (contato/observação/IDs + valor com confiança colorida) + npm run build + checkpoint visual [HUB-UI-01, HUB-UI-02]
- [x] 114-03-PLAN.md — Comando hubspot:reprocess-event {id} idempotente reusando handoff/dedup (reprocessarEvento público) + suite replay Http::fake (efeito prático + idempotência) [HUB-REPLAY-01]

### Phase 115: Suite E2E + documentação da regra de valor (v20.0)

**Goal:** Cobertura de teste dos fluxos novos (valor, enriquecimento, dedup, replay, listagem) com `Http::fake` sempre, e doc curta explicando a regra mensal×anual em `CLAUDE.md` ou novo doc técnico. Fecha os critérios de aceite do prompt.
**Requirements**: HUB-TEST-01 (resolver), HUB-TEST-02 (enriquecimento), HUB-TEST-03 (dedup), HUB-TEST-04 (replay), HUB-TEST-05 (listagem), HUB-DOC-01 (doc da regra)
**Depends on:** Phases 111–114
**Success Criteria** (o que deve ser VERDADE):

  1. `HubspotValueResolverTest` cobre os 6 casos do prompt (monthly, annually P1Y, MRR vs ARR, serviço único, inferência por tolerância, `valor_revisar`)
  2. `PhaseHubspotEnrichmentTest` prova: contato email+telefone escolhido, `mobilephone` como fallback, `nome_contato` estruturado, IDs HubSpot gravados, snapshot com deal/company/contact/line_items
  3. `PhaseHubspotDedupTest` prova: enriquece por CNPJ sem duplicar, novo contrato por `hubspot_company_id` sem duplicar, match fraco por nome → warning/pendência (sem merge agressivo)
  4. `PhaseHubspotReplayTest` prova: line item sem mapping não cria contrato → admin cadastra mapping → replay cria o contrato e zera o efeito prático da pendência
  5. `PhaseHubspotComercialListagemEnrichmentTest` prova contato/observação/confiança/warning na listagem e `valor_revisar` só para origem HubSpot; doc da regra de valor escrita; nenhum teste chama HubSpot real; tokens nunca em log

**Plans:** 3/3 plans complete

Plans:

- [x] 115-01-PLAN.md — Auditoria + gate das suítes nucleares: resolver (6 casos), enriquecimento e dedup [HUB-TEST-01, HUB-TEST-02, HUB-TEST-03]
- [x] 115-02-PLAN.md — Auditoria replay + listagem + suíte nova de invariantes transversais (guarda anti-rede-real + tokens fora do log) [HUB-TEST-04, HUB-TEST-05]
- [x] 115-03-PLAN.md — Doc técnico da regra de valor mensal×anual em docs/hubspot-regra-de-valor.md [HUB-DOC-01]

### Phase 116: NPS não respondido conta como nota mínima (1)

**Goal:** Todo NPS efetivamente disparado e não respondido passa a valer nota 1 (mínima) em **todos** os consumidores da média de NPS — área NPS, Desempenho/bonificação e demais telas —, criando senso de dever no envio. A nota 1 vale desde o disparo (competência aberta) e vira definitiva quando o mês fecha sem resposta. Inclui backfill retroativo das competências já fechadas, com relatório de impacto antes/depois por pessoa e competência.
**Requirements**: NPSFLOOR-01, NPSFLOOR-02, NPSFLOOR-03, NPSFLOOR-04, NPSFLOOR-05, NPSFLOOR-06, NPSFLOOR-07, NPSFLOOR-08, NPSFLOOR-08b, NPSFLOOR-08c, NPSFLOOR-09, NPSFLOOR-10, NPSFLOOR-11, NPSFLOOR-12
**Depends on:** Nada bloqueante — base NPS multi-modelo (v16.0) e auditoria de bônus por competência (v19) já em produção
**UI hint:** Sim — a tela de NPS precisa explicitar a regra "não respondido = 1" em linguagem simples
**Plans:** 8/8 plans complete

Plans:
**Wave 1**

- [x] 116-01-PLAN.md — Fundação: tabela `nps_imputed_assignments` + model + `NpsImputationService` (materialização idempotente, provisório/definitivo, API de leitura) [NPSFLOOR-03, NPSFLOOR-05, NPSFLOOR-06, NPSFLOOR-07, NPSFLOOR-11, NPSFLOOR-12]

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 116-02-PLAN.md — Desempenho/bônus: 3º ramo `notasImputadas()` + bump de cacheKey v11→v12 + reconciliação das suítes de bônus e das fixtures pendentes [NPSFLOOR-02, NPSFLOOR-04, NPSFLOOR-05, NPSFLOOR-07, NPSFLOOR-10]
- [x] 116-03-PLAN.md — Área NPS: cards das 3 dimensões, série de 12 meses, invalidação por competência (capacidade nova) e "definitivo ganha da resposta tardia" [NPSFLOOR-01, NPSFLOOR-03, NPSFLOOR-04, NPSFLOOR-06, NPSFLOOR-11, NPSFLOOR-12]
- [x] 116-04-PLAN.md — Carteira do profissional: `PerformanceController` + `PortfolioController` [NPSFLOOR-01, NPSFLOOR-02, NPSFLOOR-10]
- [x] 116-05-PLAN.md — Dashboards, página da empresa e meta de NPS: `DashboardController` + `CompanyController` + `CalculateGoalResults` [NPSFLOOR-01, NPSFLOOR-03, NPSFLOOR-10, NPSFLOOR-12]

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 116-06-PLAN.md — Comando `nps:materializar-nao-respondidos` (dry-run com relatório antes/depois por pessoa e competência, reconsolidação verificada do snapshot mensal, rollback), ganchos no disparo e agendamento diário [NPSFLOOR-08, NPSFLOOR-08b, NPSFLOOR-08c, NPSFLOOR-07, NPSFLOOR-11]
- [x] 116-07-PLAN.md — UI da área NPS: rodapé separando respondidas × sem resposta + frase explicativa sem jargão + `npm run build` [NPSFLOOR-09]

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 116-08-PLAN.md — Fechamento: teste de coerência entre call-sites, suíte completa, doc operacional e gate humano do backfill retroativo [NPSFLOOR-08, NPSFLOOR-08b, NPSFLOOR-08c, NPSFLOOR-10]

---

## Milestone v21.0 — Desempenho por nota individual de empresa (Fases 117-123)

**Plano canônico:** `plano-implementacao-desempenho-por-empresa.md` (raiz) · **Requirements:** `.planning/REQUIREMENTS-v21.md`

Troca de granularidade do motor de bonificação: sair de componentes agregados por profissional e calcular a nota de **cada empresa** primeiro. `nota_empresa = (NPS + faturamento + margem_pp) / 3`; `nota_profissional = média(nota_empresa)`. A régua deixa de ser aplicada depois da média e passa a ser aplicada empresa por empresa. Margem migra de variação relativa para **pontos percentuais**.

**Decisões travadas (2026-07-27):** (D1) margem usa `percentageMargin.value − prev`, reabrindo deliberadamente o hotfix `a413e823` de 24/07 porque pp não é expressável pelo `.diff` nativo; (D2) a régua atual (−5/−2/+1/+4) é reusada lida como pp, sem recalibrar, com o usuário ciente da compressão na faixa 3-4; (D3) empresa primeiro, profissional depois; (D4) baseline `previous_equal_length_window` intocada; (D5) placeholder de margem Shopee 1.0 preservado.

**Decisão em aberto:** tratamento de empresa sem baseline — resolver no discuss-phase da Fase 120 (ver REQUIREMENTS-v21.md).

### Phase 117: Margem em pontos percentuais + probe de estabilidade de `prev` (v21.0)

**Goal:** `AdmanMetricDiffService` passa a expor `prev_value` e `diff_pp` sem alterar nada que os consumidores atuais leem, e a estabilidade de `percentageMargin.prev` é medida e apresentada **antes** de qualquer fase amarrar pagamento de bônus nesse campo.
**Requirements**: MPP-01, MPP-02, MPP-03, MPP-04, MPP-05, MPP-06
**Depends on:** Nada — aditivo e independente
**Success Criteria** (o que deve ser VERDADE):

  1. Cada métrica expõe `prev_value` e `diff_pp` ao lado de `value`/`diff_pct`/`diff_source`; nenhum consumidor existente muda de comportamento e `diff_pct` continua idêntico
  2. `contribution_margin_pct.diff_pp = value − prev_value` só quando `comparison_mode === 'previous_equal_length_window'` e ambos numéricos; `null` em todo outro caso (inclusive `same_interval_previous_month`)
  3. Fixture conhecida comprova: `value=27,47` + `prev=24,08` → `diff_pp=3,39`, com `diff_pct` ainda em `14,09`; sem `prev`, `diff_pp=null`
  4. Cache `adman:diff:v5` → `v6`; shape velho não é servido para o shape novo
  5. **GATE:** o probe de estabilidade de `prev` (N leituras da mesma empresa, competência fechada) é rodado e o relatório de variância apresentado ao usuário. Se `prev` oscilar, a milestone para aqui — não se paga bônus com fonte instável

**Plans:** 2/2 plans executed — **FASE NÃO COMPLETA: GATE MPP-04 PENDENTE**

Plans:

- [x] 117-01-PLAN.md — Shape aditivo: `prev_value` nas 3 métricas, `diff_pp` só em `contribution_margin_pct`, indicador de cobertura no `quality`, cache `adman:diff:v6` + `shopee:diff:v2`, e gate de não-regressão dos consumidores [MPP-01, MPP-02, MPP-03, MPP-05, MPP-06]
- [x] 117-02-PLAN.md — Probe `adman:probe-margem-prev`: leitura sem cache (`forceRefresh`), persistência das leituras + veredito reconsultável, agregação com detecção de flip de nota e sanidade anti-cache [MPP-04] — **código entregue e testado (24 testes); o PROBE NÃO FOI EXECUTADO**

> ⚠️ **GATE MPP-04 PENDENTE — a Fase 117 NÃO pode ser marcada como completa.**
> Todo o código está entregue e verificado (61 testes verdes: 25 Adman + 12 Shopee + 24 probe), mas o probe exige deploy na VPS e 24-48h de coleta contra a Adman real, com pelo menos uma leitura dentro de 11:00-12:00 BRT. **Nada foi deployado** (2026-07-28).
> Enquanto o veredito não for aprovado pelo usuário, a **Fase 119 não pode consumir `diff_pp`** para calcular nota.
> Runbook de execução: `117-02-SUMMARY.md` § `<gate_de_fase>`.
> Bloqueio de deploy em 2026-07-28: ver `117-deferred-items.md` § "Bloqueio de publicação".

### Phase 118: NPS por empresa (v21.0)

**Goal:** Existe um serviço que devolve a nota de NPS agrupada por empresa, preservando exatamente os três ramos, a janela M+1, a dedupe e as invalidações que já existem — sem inventar origem de dados nova.
**Requirements**: NPSE-01, NPSE-02, NPSE-03, NPSE-04, NPSE-05, NPSE-06
**Depends on:** **Fase 116 fechada** (116-06 backfill retroativo, 116-07, 116-08 teste de coerência) — esta fase adiciona um 4º call-site da regra de piso de NPS
**Success Criteria** (o que deve ser VERDADE):

  1. `NpsPorEmpresaService::notasNpsPorEmpresa()` devolve nota por `company_id` com contagem e origem por ramo (`assignments` / `legacy` / `imputadas`)
  2. Os três ramos e as dedupes por `(response_id, role)` e `(survey_id, role)` produzem exatamente os mesmos números que o cálculo agregado atual, quando somados de volta
  3. Competência M lê NPS de M+1; mês em curso usa piso `1.0`; M+1 encerrado sem resposta usa `0.0` → `1.0` pelo clamp
  4. Empresa invalidada na competência não entra; empresa com Performance **e** Shopee não duplica NPS
  5. O teste de coerência entre call-sites da 116-08 conhece este call-site e continua verde

**Plans:** 2/2 plans complete

Plans:

- [x] 118-01-PLAN.md — `NpsJanelaResolver` (régua de LEITURA da janela M+1) + `NpsPorEmpresaService`: os 3 ramos agrupados por `company_id` com shape auditável, reconciliação contra os ramos originais e os 3 casos da janela [NPSE-01, NPSE-02, NPSE-03]
- [x] 118-02-PLAN.md — D-03 (survey do serviço do vínculo + fallback consolidado), invalidação por competência antes do piso da D-04, log do gap de atribuição e o 8º método do teste de coerência entre call-sites [NPSE-04, NPSE-05, NPSE-06]

### Phase 119: Score por empresa (v21.0)

**Goal:** Existe um fato por empresa com os três componentes já pontuados e a `nota_empresa` calculada, com a régua de faturamento aplicada por empresa e a de margem aplicada sobre pontos percentuais.
**Requirements**: EMPS-01, EMPS-02, EMPS-03, EMPS-04, EMPS-05, EMPS-06, EMPS-07
**Depends on:** Fases 117 e 118.

> 🔁 **GATE MPP-04 REPOSICIONADO em 2026-07-29 (decisão do usuário).** Antes o gate bloqueava esta fase. Agora ele bloqueia a **Fase 120**, antes de ligar a flag.
> **Razão:** o risco que o gate protege é o número **passar a pagar bônus**, e isso não acontece aqui — a Fase 119 é aditiva, sem consumidor, e seus testes usam `Http::fake()`, sem tocar a Adman. O código fica correto independentemente de `percentageMargin.prev` ser estável em produção. O gate estava posicionado cedo demais: barrava escrever código quando o que ele protege é ativar o cálculo.
> **Risco residual aceito:** se o veredito vier `reprovado`, a Fase 119 já estará escrita — mas o custo é código parado, não bônus errado.
**Success Criteria** (o que deve ser VERDADE):

  1. `CompanyScoreService` produz uma linha por empresa no contrato do plano §3.1, com `status` e `quality` explicando por que uma empresa ficou incompleta
  2. A régua de faturamento é aplicada por empresa antes de qualquer média; a de margem lê `margem_var_pp` e **nunca** `diff_pct`
  3. `nota_empresa = round((nps + faturamento + margem) / 3, 2)`; o caso âncora do plano fecha: NPS 4,6 + faturamento +8% (5) + margem +3,2 pp (4) → `4,53`
  4. `MetricDiffDispatcher::compute()` é chamado uma única vez por empresa (hoje são duas chamadas indiretas)
  5. Adman vence Shopee na fonte financeira; empresa Shopee usa `margem_pontos = 1.0` marcado como `quality.margin_source = placeholder_shopee`

**Plans:** 3/3 plans complete

Plans:

- [x] 119-02-PLAN.md — `CompanyScoreService` aditivo: réguas duplicadas byte a byte com teste de equivalência (C-03), `computeEmpresasScore()` completo (universo, invalidação, fonte vencedora, NPS 1×, dispatcher 1×, nota estrita + parcial, status/quality) e os casos âncora 4,53 e régua-por-empresa × régua-da-média [EMPS-01, EMPS-02, EMPS-04]
- [x] 119-03-PLAN.md — Provas duras: margem pontuada sobre `diff_pp` com a fixture divergente MPP-06 (4 pontos, não 5) e contagem de 1 chamada do dispatcher por empresa com guard de fonte nula [EMPS-03, EMPS-05]
- [x] 119-04-PLAN.md — Fonte vencedora Adman×Shopee com placeholder `1.0` marcado, taxonomia completa de `status`/`quality.motivos`, reconciliação old×new e registro do risco régua-da-média para a Fase 120 [EMPS-06, EMPS-07]

### Phase 119.1: NPS manual, sem duplicidade e por grupo de empresas (INSERTED)

**Goal:** O NPS deixa de sair sozinho e passa a ser um ato deliberado do responsável. (a) O agendamento diário do disparo automático é desligado — o comando continua existindo para uso manual em massa. (b) Fica impedido gerar um segundo link para a mesma empresa + mesmo modelo + mesma competência, fechando a brecha do disparo manual (onde `month_reference` nasce NULL e o índice único da Fase 68 não pega). (c) Passa a existir NPS de GRUPO: um único link cuja nota replica para todas as empresas do grupo que tenham os mesmos responsáveis no serviço coberto; as demais não recebem nota e seguem valendo 1 até alguém gerar link individual. (d) Como contrapartida ao desligamento do automático, empresa ELEGÍVEL que passou a competência sem nenhum link gerado também conta nota 1 — o que **inverte deliberadamente o invariante D3 da Fase 116**.
**Requirements**: NPSMAN-01, NPSMAN-02, NPSMAN-03, NPSMAN-04, NPSMAN-05, NPSMAN-06, NPSMAN-07, NPSMAN-08, NPSMAN-09, NPSMAN-10, NPSMAN-11, NPSMAN-12, NPSMAN-13
**Depends on:** Fase 116 (regra "não respondido = 1", tabela de imputação e os ~9 consumidores já ligados) e Fase 118 (`NpsPorEmpresaService` — o padrão de leitura que o item (d) generaliza). Independente das Fases 120-123 (v21.0).
**UI hint:** Sim — tela de geração de link (bloqueio de duplicidade + prévia de quais empresas do grupo serão cobertas) e a área NPS refletindo notas de grupo
**Por que INSERTED:** trabalho urgente pedido em 2026-07-29, adiantado porque as Fases 120-123 estão bloqueadas até a atualização da Adman das 11h.
**Plans:** 9/9 plans complete

Plans:

- [x] 119.1-01-PLAN.md — Fundação: `NpsElegibilidadeService` (fonte única de "quem deveria ter recebido") + desligamento do agendamento diário (wave 1)
- [x] 119.1-02-PLAN.md — Guard de duplicidade no disparo manual, devolvendo o link que já existe (wave 2)
- [x] 119.1-03-PLAN.md — D1 no bônus: 4º ramo de leitura em `computeNpsMedio()` + cache `v13` + 5 hash-gates da Fase 119 (wave 2)
- [x] 119.1-04-PLAN.md — D1 na área NPS + segmentação (não remoção) dos testes de D3 da Fase 116 (wave 3)
- [x] 119.1-05-PLAN.md — NPS de grupo: tabela âncora `nps_group_surveys` + `NpsGrupoCoberturaService` (quem entra, quem fica de fora e por quê) (wave 2)
- [x] 119.1-06-PLAN.md — NPS de grupo: prévia, geração do link, resposta pública e fan-out em N surveys-espelho reais (wave 3)
- [x] 119.1-07-PLAN.md — UI: aviso de link já existente, prévia de cobertura do grupo, motivo "falta cadastrar o contato" + `npm run build` (wave 4, tem checkpoint) — **checkpoint humano aprovado 2026-07-30**
- [x] 119.1-09-PLAN.md — D1 nos 4 consumidores restantes: carteira, dashboards/ranking, página da empresa e meta de NPS + piso retroativo da janela rolante (wave 4)
- [x] 119.1-08-PLAN.md — Fechamento: gate de coerência entre call-sites, regressão da janela da rotina, doc operacional atualizado (wave 5, **depende do 09**)

> **Plano 07 fechado em 2026-07-30.** `Nps/Index.jsx` reusa o modal de link para avisar duplicidade (individual e grupo), mostra a prévia de cobertura do grupo com os 5 motivos de exclusão distintos (`responsavel_diferente`, `sem_servico_contratado`, `sem_servico_em_comum`, `ja_tem_link`, `empresa_inativa` — **o doc operacional do Plano 08 deve listá-los separadamente, não colapsar num motivo genérico**), e explica o motivo de cada faltante (inclusive "falta cadastrar o contato", D5). `Nps/Respond.jsx` passou a postar em `survey.submit_url` — sem isso o NPS de grupo do Plano 06 não funcionava de fato. Deviation autorizada: prop `grupos` adicionada em `NpsController::index()` (fora do `files_modified` declarado), escopada como `NpsGrupoController::autorizarAcessoAoGrupo`. `--filter=Phase119_1` 102/102 (baseline exata). Lembrete: `public/build/` é gitignored — o deploy precisa rodar `npm run build` no servidor.

> + **Plano 09 adicionado em 2026-07-29, com a fase em execução.** O SUMMARY do 119.1-04 registrou que D1 ficou em apenas **2 de 6 consumidores** (bônus + área NPS); os outros 4 (carteira, dashboards/ranking, página da empresa, meta de NPS) seguiam com a sentinela antiga, cada um com um teste de GAP dedicado provando a divergência. O usuário foi consultado e decidiu ligar os 4 que faltavam — é decisão dele, não escopo especulativo. Sem o 09, o `must_haves.truths` do 119.1-08 ("todos os lugares que mostram nota de NPS concordam sobre quem conta nota 1") seria falso: por isso o 09 entra na wave 4 e o 08 permanece na wave 5. O 09 **não** toca `DesempenhoScoreService.php`, então o cache segue em `v13` e o aviso de `v14` continua sendo da Fase 120.

> ⚠ **Efeito na Fase 120:** o critério de sucesso 3 da Fase 120 previa subir a chave de cache de `v12` para `v13`. A Fase 119.1 entrou na frente e consumiu o `v13` — a Fase 120 deve subir para `v14`, atualizando junto os 4 arquivos de teste com a string hardcoded.

> 🔁 **Resposta da Fase 120 (2026-07-29) — e um aviso de volta para a 119.1.**
> A Fase 120 **não** fixou `v14`. Ela resolve a versão como **corrente + 1 em tempo de execução**, lendo o literal antes de editar e extraindo a versão anterior de `git show HEAD:` no gate — funciona em qualquer ordem de execução entre as duas fases.
>
> ⚠️ **O `119.1-03-PLAN.md` faz o bump HARDCODED `v12` → `v13`.** Se a Fase 120 executar primeiro, ela consome o `v13`, e o grep por `v12` do plano da 119.1 **não encontrará nada** — a mudança de shape/comportamento da 119.1 (4º ramo em `computeNpsMedio`) poderia ser deployada **sem bump próprio**, servindo payload velho do Redis por até 7 dias em mês fechado.
> **Sugestão para a sessão da 119.1:** trocar o bump hardcoded pela mesma resolução dinâmica "corrente + 1" antes de executar, ou confirmar a ordem de execução entre as duas sessões. Achado do plan-check da Fase 120 — não alterei o plano de vocês.

### Phase 120: Agregação do profissional + feature flag (v21.0)

**Goal:** A nota do profissional passa a ser a média das notas das empresas, atrás de feature flag, com `empresas_score` calculado em shadow nos dois modos e todas as chaves legadas do payload preservadas.
**Requirements**: AGRE-01, AGRE-02, AGRE-03, AGRE-04, AGRE-05, AGRE-06
**Depends on:** Fase 119 — **e o GATE MPP-04 APROVADO pelo usuário antes de ligar a flag** (reposicionado da Fase 119 em 2026-07-29).

> 🚦 **GATE MPP-04 — bloqueia a ATIVAÇÃO, não a escrita.**
> A flag `metrics.performance_company_first_score` **não pode ser ligada** enquanto o probe de estabilidade de `percentageMargin.prev` (Fase 117) não tiver rodado na VPS com o desenho amostral completo e o veredito não tiver sido aprovado pelo usuário.
> **Desenho amostral exigido (D-01..D-04 da Fase 117):** ≥5 rodadas em 24-48h · zero flip de faixa da régua entre leituras · cobertura de `prev` não-nulo ≥ 80% · **≥1 leitura sob contenção real** (`--janela=contencao_11h`, entre 11:00 e 12:00 BRT, quando 8 jobs agendados disputam a mesma API-key da Adman) · payloads **não** idênticos bit-a-bit (identidade bit-a-bit ⇒ `instrumentacao_suspeita`, que nunca é sucesso).
> **Como fechar:** `php artisan adman:probe-margem-prev --relatorio --mes=<competência>`, conferindo o veredito por **reconsulta a `adman_probe_margem_prev_vereditos`**, nunca por stdout. Runbook completo em `117-02-SUMMARY.md`.
> 🔴 **VEREDITO: `reprovado`** — apurado em 2026-07-29 11:56 BRT, conferido por reconsulta a `adman_probe_margem_prev_vereditos` (`cobertura_prev = 0.6415`, `total_rodadas = 5`).
>
> **A flag NÃO pode ser ligada.** O `percentageMargin.prev` é estável quando a API Adman está livre, mas **desaparece para um terço da carteira sob contenção real**:
>
> | Condição | Rodadas | Cobertura de `prev` | Falhas de HTTP |
> |---|---|---|---|
> | Folgada (madrugada ×2, tarde, manual) | 4 | **92,5%** | **0 em 212** |
> | **Contenção real (11:02)** | 1 | **64,2%** | **15 de 53 (28,3%)** |
>
> As empresas 1, 2, 3, 4 e 6 passaram nas quatro rodadas folgadas e **falharam** na de contenção — mesmo conjunto, comportamento mudando junto com a condição. É a causa-raiz da Fase 110 reproduzida ao vivo: *"empresa que falha sai da média"*. E ela **não aparece como flip de nota**, porque empresa que falha não produz nota nenhuma — some.
>
> ⚠️ **O gate errou antes de acertar.** A primeira execução devolveu `aprovado` por três defeitos no próprio cálculo, corrigidos em `48aa1b30` e `fe0f6e91`: (1) cobertura agregada — 4 rodadas boas diluíam a ruim de 64,2% até 86,8%, acima do piso; (2) contagem de rodadas contava leituras (5 viravam 262), então a guarda de "mínimo 5" nunca era exercida; (3) falha de HTTP não entrava no veredito — o ponto cego central. Teste de regressão em `test_rodadas_boas_nao_diluem_rodada_ruim_de_cobertura`.
>
> **Decisão de plano B pendente do usuário** — o `117-CONTEXT.md` deliberadamente não pré-decidiu: congelar `prev` em snapshot diário · voltar ao cálculo local determinístico · tirar margem em pp do bônus · ou tornar a leitura resiliente (retry/backoff) antes de reavaliar.
> **Se reprovar:** a flag não liga e a decisão de plano B (congelar `prev` em snapshot × voltar ao cálculo local) volta ao usuário — o `117-CONTEXT.md` deliberadamente não pré-decidiu.

**UI hint:** Não — só payload; telas ficam na Fase 123
**Success Criteria** (o que deve ser VERDADE):

  1. Com a flag ligada, `nota_final` é exatamente a média das `nota_empresa`; com a flag desligada, o número não muda em relação a hoje
  2. `empresas_score` é anexado ao payload nos **dois** modos, para auditoria antes da virada
  3. `cacheKey()` sobe uma versão (regra: **corrente + 1** — `v12`→`v13`, ou `v13`→`v14` se a Fase 119.1 chegar antes, ver nota acima) e as 4 suítes com a string hardcoded são atualizadas junto — `DesempenhoShopeeScoreTest`, `Phase116/NpsFloorDesempenhoTest`, `Phase96/NpsInvalidacaoRespostaTest`, `V18/DesempenhoMetadadosCacheTest`
  4. As chaves legadas continuam presentes (`empresas_carteira`, `empresas_com_baseline`, `margem_amostra`, `componentes_disponiveis`, `score_status`, `faixa_bonus`, `faixa_promovida`, `componentes.var_margem_pct`)
  5. Empresa sem baseline segue a decisão do discuss-phase, sem contradizer `DESEMP-06` nem a trava da Fase 109 — profissional só-Shopee continua produzindo `nota_final`

**Plans:** 3 plans

Plans:

- [x] 120-01-PLAN.md — Teste dourado de byte-equivalência (substituto do gate de hash das Fases 117-119), bump da chave de cache antes da mudança de shape, feature flag nascendo `false` e a superfície aditiva: parâmetro de shadow, `empresas_score` e `componentes.var_margem_pp` [AGRE-02, AGRE-03, AGRE-04]
- [x] 120-02-PLAN.md — Roteamento do shadow: `consolidar-mes` e `warm-cache` com o guard do `Cache::remember` (C-02), prova de contagem zero na leitura interativa (D-04) e de que o shadow não contamina nenhum número legado [AGRE-02]
- [x] 120-03-PLAN.md — A bifurcação: `computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa`, denominador só de empresas `complete` (D-01), cobertura de 70% governando `official`/`partial` (D-02/D-03), só-Shopee `official` sem código especial e os cenários espelho da D-05 [AGRE-01, AGRE-05, AGRE-06]

> **Planos 01 e 02 fechados em 2026-07-30.** Cache em `v14` (a Fase 119.1 consumiu `v13` antes). Shadow (`$incluirEmpresasScore`) roda com garantia em `desempenho:consolidar-mes`/`desempenho:warm-cache` (guard do `Cache::remember`, C-02) e contagem zero comprovada em leitura interativa (D-04). Flag `metrics.performance_company_first_score` continua `false`.
>
> **Plano 03 fechado em 2026-07-30 — FASE 120 COMPLETA (3/3 planos).** Bifurcação implementada: `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` novos e separados dos legados (intocados); constante `COBERTURA_MINIMA_SCORE_STATUS=0.7` amarrada por docblock a `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO`. `AgregacaoProfissionalTest` (7/7) fecha AGRE-01/05/06. 4 cenários espelho acrescentados (nunca reescritos) a `DesempenhoShopeeScoreTest` fecham o gate nº 3 — cada um congela o par LEGADO×NOVO na mesma fixture: so-performance 2.50/partial×null/partial, so-Shopee (1 empresa) 2.33/official idêntico nos dois ramos, misto e invalidação official×partial (blend tapa a margem ausente com o placeholder Shopee vs. cobertura por empresa expõe a incompletude) — primeiro dado numérico concreto para a Fase 121. `--filter=Phase120` 18/18, `--filter=Desempenho` 14 failed/98 passed (baseline exata). **Flag CONTINUA `false`** — ligar depende do GATE MPP-04 (hoje `reprovado`) aprovar E do delta da Fase 121 ser aceito.

### Phase 121: Comparação antigo × novo e validação da régua em pp (v21.0)

**Goal:** Antes de ligar a flag em produção, existe evidência numérica de quanto a nota de cada profissional muda e de como a régua reusada se comporta sobre a distribuição real de pontos percentuais da carteira.
**Requirements**: ROLL-01, ROLL-02, ROLL-03
**Depends on:** Fase 120
**Success Criteria** (o que deve ser VERDADE):

  1. `php artisan desempenho:comparar-score-empresa --mes=YYYY-MM` reporta por profissional: `nota_antiga`, `nota_nova`, `delta`, empresas total/complete/partial e a maior causa do delta
  2. A comparação roda sobre a última competência fechada e as 7 amostras de risco do plano §6 são conferidas manualmente
  3. A distribuição real de `margem_var_pp` da carteira inteira é medida e apresentada — confirmando ou refutando que a régua reusada (D2) produz dispersão aceitável
  4. **GATE:** o usuário aprova explicitamente o delta antes de qualquer ativação de flag em produção

**Plans:** 5/5 plans complete

Plans:

- [x] 121-01-PLAN.md — Fundações: o shadow expõe a nota nova (D-05) e as duas tabelas insert-only do comparador
- [x] 121-02-PLAN.md — O comando: competências fixas, uma chamada de compute() por profissional, releitura interleaved e persistência (gate nº 1)
- [x] 121-03-PLAN.md — Decomposição da causa do delta com resíduo explícito e réguas espelho (gate nº 2)
- [x] 121-04-PLAN.md — Relatório reconsultado, 7 amostras de risco e histograma de margem_var_pp (gate nº 3)
- [x] 121-05-PLAN.md — Execução real e o GATE do usuário sobre o delta (D-04)

### Phase 122: Persistência por empresa e comandos (v21.0)

**Goal:** O detalhe por empresa vira fato auditável e persistido, e o fechamento mensal passa a gravá-lo — com o caminho de reconsolidação de competências fechadas incluído no rollout.
**Requirements**: SNAP-01, SNAP-02, SNAP-03, SNAP-04, SNAP-05, SNAP-06
**Depends on:** Fase 121 (gate aprovado)
**Success Criteria** (o que deve ser VERDADE):

  1. `empresas_score` é persistido em `desempenho_score_snapshots.breakdown_json`
  2. Tabela `desempenho_company_score_snapshots` com `unique(user_id, company_id, mes_referencia)` explica o resumo empresa por empresa
  3. `ConsolidarMesDesempenho`, `SnapshotDesempenhoScores` e `WarmDesempenhoCache` gravam as linhas por empresa; invalidar empresa remove as linhas daquela competência
  4. `margem_amostra` conta cobertura de `margem_var_pp`
  5. O rollout inclui `desempenho:consolidar-mes --mes=` para competências fechadas, e o gate `FIXMARG-03` (cobertura < 0,7) é conferido por reconsulta ao snapshot, nunca por stdout

**Plans:** 6/6 plans complete

Plans:

- [x] 122-01-PLAN.md — Fundações: tabela `desempenho_company_score_snapshots`, model e writer idempotente com trava de congelamento [SNAP-02]
- [x] 122-02-PLAN.md — `margem_amostra` conta cobertura de `margem_var_pp` (legado preservado em sub-chave) + bump de cache v15→v16 [SNAP-05]
- [x] 122-03-PLAN.md — Os três comandos gravam as linhas por empresa; gate FIXMARG-03 escolhe a base pelo estado da flag [SNAP-01, SNAP-03]
- [x] 122-04-PLAN.md — Invalidação por competência remove as linhas por empresa daquela competência [SNAP-04]
- [x] 122-05-PLAN.md — `desempenho:verificar-consolidacao` (read-only, exit code binário) + runbook de rollout [SNAP-06]
- [x] 122-06-PLAN.md — Execução do rollout em produção e registro da evidência reconsultada (checkpoint) [SNAP-06]

> **Waves:** 1 = {122-01, 122-02} · 2 = {122-03, 122-04} · 3 = {122-05} · 4 = {122-06}
> **Esta fase NÃO liga flag nenhuma** — `metrics.performance_company_first_score` continua `false` e o GATE MPP-04 continua `reprovado`.

### Phase 123: Telas e relatórios (v21.0)

**Goal:** As telas explicam a regra nova em linguagem simples e mostram a nota de cada empresa, sem quebrar snapshots antigos.
**Requirements**: UIEM-01, UIEM-02, UIEM-03, UIEM-04
**Depends on:** Fase 122
**UI hint:** Sim — a dimensão de margem muda de unidade e precisa ser explicada sem jargão
**Success Criteria** (o que deve ser VERDADE):

  1. A margem é rotulada e explicada em linguagem simples ("quantos pontos percentuais a margem subiu ou caiu"), sem termo não auto-explicativo
  2. O detalhe do profissional lista as empresas da carteira com a nota de cada uma e seus três componentes
  3. Snapshot antigo sem `empresas_score` renderiza no visual anterior; sem `var_margem_pp`, exibe `var_margem_pct` com rótulo legado
  4. Relatório de Bonificação e Auditoria de Bônus exibem `nota_empresa` lendo a mesma fonte que o ranking
  5. `npm run build` rodado e checkpoint visual aprovado

**Plans:** 8/8 plans complete

Plans:

- [x] 123-01-PLAN.md — Fundações compartilhadas: `CompanyScoreSnapshotReader` + `desempenhoLabels.js` [UIEM-01, UIEM-02, UIEM-04]
- [x] 123-02-PLAN.md — Backend do detalhe por empresa + desbloqueio do dropdown de mês fechado [UIEM-02, UIEM-03]
- [x] 123-03-PLAN.md — Auditoria de Bônus — coluna de nota por empresa (metade de UIEM-04) [UIEM-04, UIEM-03]
- [x] 123-04-PLAN.md — Performance/Show.jsx — margem sem jargão + lista de empresas com nota [UIEM-01, UIEM-02, UIEM-03]
- [x] 123-05-PLAN.md — Relatório de Bonificação — linha expansível por profissional (fecha UIEM-04) [UIEM-04]
- [x] 123-06-PLAN.md — Checkpoint visual humano (fecha a fase) [UIEM-01, UIEM-02, UIEM-03, UIEM-04]
- [x] 123-07-PLAN.md — Gap closure: autorização por usuário-alvo em show()/evolucao() (CR-01) + payload enxuto do reader (WR-03) + regex de mês (WR-01) [UIEM-02, UIEM-03]
- [x] 123-08-PLAN.md — Gap closure: Auditoria de Bônus snapshot-first para nota_final (CR-02) [UIEM-04]

> **Waves:** 1 = {123-01} · 2 = {123-02, 123-03} · 3 = {123-04} · 4 = {123-05} · 5 = {123-06}
> UIEM-04 e UIEM-03 só fecham de fato quando 123-05 e 123-04 rodarem — 123-03 entrega só a metade da Auditoria de Bônus.
> Gap closure (2026-08-04, de `123-VERIFICATION.md`): {123-07, 123-08} — wave 6, sem overlap de arquivos entre si, rodam em paralelo.

## Milestone v22.0 — Administrativo + Clicksign (Fases 124-133)

**Plano canônico:** `plano-administrativo-clicksign.md` (raiz) · **Requirements:** `.planning/REQUIREMENTS-v22.md` · **Pesquisa:** `.planning/research/SUMMARY.md` (+ STACK, FEATURES, ARCHITECTURE, PITFALLS)

**Goal:** Contrato assinado passa a ser a porta de entrada do operacional. A empresa deixa de ir direto do fechamento comercial para a operação e passa por uma etapa administrativa: gerar contrato → enviar pela Clicksign → aguardar a assinatura de todas as partes → só então liberar. O gate vale para os **dois** caminhos de entrada (webhook HubSpot e cadastro manual do Comercial).

**Nota de contagem:** a pesquisa/requirements desta milestone somam **39 REQ-IDs** (FLUXO 7 + DADOS 6 + CLICK 11 + PDF 3 + REDE 6 + UI 6), não 33 — a contagem de 33 do brief inicial não bate com o `REQUIREMENTS-v22.md` real; a cobertura abaixo reflete os 39 REQ-IDs efetivamente escritos no arquivo.

**Regra dura de sequenciamento (não é opcional):** desligar o roteamento automático (Fase 133) e ter o webhook que libera a empresa de volta (Fase 129) NUNCA vão a produção em momentos diferentes sem proteção. A saída escolhida é a (b) do PITFALLS.md: o bloqueio nasce **atrás de uma flag `Configuracao` (`administrativo_bloqueio_ativo`) desligada por padrão** desde a Fase 124. Todas as Fases 124-132 vão a produção com a flag **desligada** — nada muda no comportamento observável do roteamento operacional até a Fase 133 ligar o interruptor. REDE-01 (kill switch), REDE-02 (alerta de preso), REDE-03 (liberação manual) e REDE-06 (modo observação) existem e já foram testados **antes** da Fase 133 — nunca depois.

**Decisões travadas (usuário, 2026-08-07):** D1 geração/envio sempre manuais nesta milestone (sem disparo automático) · D2 gate vale para HubSpot e manual · D3 entram reconciliação + prazo por contrato como diferenciais · D4 rede de segurança é requisito de primeira classe, não melhoria futura · D5 `recusado` e `expirado` são estados próprios, nunca colapsados em `cancelado`/`erro` · D6 PDF assinado é baixado e guardado localmente · D7 liberação operacional só a partir de estado reconsultado, nunca do payload isolado do evento.

**Decisões em aberto, resolvidas no discuss-phase da fase indicada:** A1 algoritmo do `Content-Hmac` — **BLOQUEANTE da Fase 129**, duas fórmulas contraditórias na pesquisa, resolução só por webhook real de sandbox · A2 rollback de envelope montado pela metade — Fase 127 · A3 resposta HTTP do webhook em erro interno — Fase 129 · A4 quais das 7 pendências comerciais valem para empresa cadastrada à mão — Fase 128.

**Reuso já identificado (não construir do zero):** `Configuracao` (kill switch) · `BonusInvalidacao`/`BonusAuditoriaController` (molde de override manual auditado) · guard `MlbEmpresa::where('company_id')->exists()` linha ~938 (backstop de liberação duplicada) · `RelatorioMensalPdfService` + `relatorio-bonificacao.blade.php` (DomPDF pt-BR resolvido) · `remind_interval` nativo da Clicksign (dispensa scheduler próprio) · padrão de 2 camadas de idempotência do webhook HubSpot.

**Risco central:** o caminho alterado (`criarEmpresa` → `persistirContratos` → `rotearImplementacao`) teve 3 bugs corrigidos em 05-06/08/2026, um deles zerando contratos de R$ 3.000. A Fase 124 (extração dos services) mexe exatamente nesse código e exige gate de regressão forte. Outro dev trabalha na mesma branch `main` em paralelo.

### Phase 124: Extração de services sem mudar comportamento + kill switch instalado (v22.0)

**Goal:** A duplicação de regra e de mecânica entre o caminho HubSpot e o caminho Comercial deixa de existir, e existe um interruptor pronto para bloquear o roteamento automático — mas ainda apagado, então nada muda no que o usuário observa hoje.
**Requirements**: FLUXO-03, FLUXO-04, FLUXO-05, FLUXO-06, FLUXO-07, REDE-01
**Depends on:** Nada (fundação)
**Success Criteria** (o que deve ser VERDADE):

  1. Comercial, HubSpot e a futura tela Administrativa calculam pendência comercial pela mesma função (`PendenciasComerciaisService::calcular`); a suíte de regressão comprova resultado idêntico ao comportamento anterior para toda empresa hoje cadastrada
  2. Uma empresa criada pelo HubSpot ou pelo Comercial continua sendo roteada ao operacional imediatamente, na mesma hora — nenhuma mudança de comportamento observável nesta fase, apesar do código interno estar reorganizado em `EmpresaOperacionalRouter` (métodos `rotearCadastro()` e `rotearServico()` — são DOIS de propósito: a D-01 proíbe o roteador derivar os serviços sozinho, e a D-08 exige preservar a divergência entre os caminhos; o nome `liberarEmpresa()` volta a fazer sentido na Fase 129/130, quando a liberação acontece dias depois da criação)
  3. Um cadastro manual do Comercial com dados do wizard (ex.: `gmail_colaborador`) preserva esse dado na implementação criada — teste de regressão explícito prova que a extração não perdeu esse preenchimento (achado da pesquisa de arquitetura)
  4. Reprocessar um evento antigo do HubSpot (`hubspot:reprocess-event`) contra uma empresa que já tem `MlbEmpresa` continua sem criar nada duplicado nem prender a empresa retroativamente
  5. Existe uma chave `Configuracao` (`administrativo_bloqueio_ativo`, default `false`) que, ligada manualmente em ambiente de teste, interrompe a chamada automática ao roteamento — comprovado por teste isolado, ainda não usada em produção

**Plans:** 5/5 plans complete

Plans:

- [x] 124-01-PLAN.md — Caracteriza o roteamento do cadastro manual antes da extração (gmail_colaborador, Incubadora, divergência D-08, inércia do interruptor)
- [x] 124-02-PLAN.md — Caracteriza o roteamento do webhook HubSpot antes da extração (Incubadora, assimetria do gmail, FLUXO-05, FLUXO-06)
- [x] 124-03-PLAN.md — Congela o baseline nominal e extrai `PendenciasComerciaisService` (FLUXO-03)
- [x] 124-04-PLAN.md — Cria `EmpresaOperacionalRouter` com o interruptor inerte instalado (REDE-01)
- [x] 124-05-PLAN.md — Religa os dois controllers ao roteador, remove o código duplicado e fecha o gate de regressão

> **Waves:** 1 = {124-01, 124-02} · 2 = {124-03, 124-04} · 3 = {124-05}
> Os testes de caracterização (wave 1) existem ANTES de qualquer refatoração — é isso que caracteriza refatoração pura. O gate compara por NOME de teste (`baseline-antes.txt` × `baseline-depois-05.txt`), nunca por contagem.

### Phase 125: Estrutura de dados administrativa (v22.0)

**Goal:** O processo de assinatura de cada empresa passa a ser um dado persistido e consultável, com estados que nunca confundem "cliente recusou" com "a API caiu".
**Requirements**: DADOS-01, DADOS-02, DADOS-04
**Depends on:** Nada (fundação, paralela à Fase 124)
**Success Criteria** (o que deve ser VERDADE):

  1. Toda empresa pode ter um `ContratoAssinatura` com estado atual e datas de envio, assinatura e liberação — schema comprovado por migration + factory testadas
  2. Cada signatário de um contrato é registrado com papel, contato e situação individual de assinatura, vinculado ao contrato correto
  3. `recusado` e `expirado` existem como estados próprios do model, nunca colapsados em `cancelado` ou `erro` — teste unitário comprova que os dois nunca resolvem para o mesmo valor interno
  4. Gate empírico #9 (formato do certificado de autenticação do signatário) resolvido e refletido no campo de payload do signatário

**Plans:** 3/3 plans complete

Plans:
**Wave 1**

- [x] 125-01-PLAN.md — Tabela contrato_assinaturas, model com os 7 estados, factory e relação em Company (DADOS-01, DADOS-04)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 125-02-PLAN.md — Tabela contrato_assinatura_signatarios, model, factory e a evidência do Gate #9 (DADOS-02)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 125-03-PLAN.md — Guarda estática das 3 cicatrizes de schema + gate de MariaDB real (checkpoint humano)

### Phase 126: Client Clicksign + PDF do contrato (v22.0)

**Goal:** O sistema sabe conversar com a Clicksign sem nunca vazar credencial, e sabe gerar um PDF de contrato correto em pt-BR — os dois blocos prontos para serem combinados na fase seguinte.
**Requirements**: CLICK-01, PDF-01, PDF-02, PDF-03
**Depends on:** Nada (fundação, paralela às Fases 124/125)

> 🔁 **REVERSÃO EM 2026-08-10, com a fase em execução (decisão do usuário).** No checkpoint humano
> do plano 126-06 o usuário abriu o PDF gerado, apontou que ele não se parece com o contrato real da
> ECF, e decidiu **usar o modelo cadastrado na Clicksign** em vez de renderizar aqui — *"se ficarmos
> gerando o contrato por aqui perdemos todo o benefício da plataforma"*. Isso reverte as decisões
> travadas D-01 e D-02 (ver bloco de revisão no topo de `126-CONTEXT.md`, decisões D-16/D-17/D-18).
>
> **Efeito nos Success Criteria:** o nº 3 continua valendo em conteúdo (os dados corretos precisam
> chegar ao documento), mas quem renderiza passa a ser a Clicksign. Os nº 4 e nº 5 **caem** — o texto
> jurídico sai do git e vai para o modelo `.docx`, e não há mais layout nosso para quebrar.
>
> **Efeito nos planos:** 126-01 a 126-04 seguem válidos e executados. O **126-05 está SUPERADO** —
> as views e o `gerar()`/`gerarESalvar()` viram código morto e saem num plano dedicado. O **126-06
> foi descartado**: dois dos seus três gates perderam o sentido, e o que ele mediu de fato está em
> `126-06-CHECKPOINT.md` (achou e corrigiu 2 bugs reais do client).
>
> **Bônus do gate humano:** a medição contra o sandbox real derrubou os dois pontos `NÃO MEDIDO` do
> client — `communicate_by` não é aceito na entrada (quebrava 100% dos envelopes no primeiro
> signatário) e o cancelamento é `DELETE`, não `PATCH status=canceled`. Ambos corrigidos em
> `d5256f3a`. Lição registrada em `CLICKSIGN-SANDBOX-EMPIRICO.md` §9.1: **forma de resposta da API
> não é contrato de entrada** — a fixture foi modelada a partir da resposta e o `Http::fake()`
> confirmava o payload errado.
**Success Criteria** (o que deve ser VERDADE):

  1. `ClicksignClient` faz as chamadas HTTP (envelope, documento, signatário, requisito, notificação, consulta, cancelamento) contra o sandbox e nenhuma linha de log jamais contém o token — comprovado por teste que inspeciona o conteúdo logado em cenário de erro
  2. Gate empírico resolvido e aplicado: formato do header `Authorization` (#2), formato de `content_base64` (#4), limite de tamanho de upload (#5)
  3. O PDF do contrato traz razão social, CNPJ, contato, serviços contratados e valores de uma empresa real do banco (não só fixture curta), sem quebra de layout
  4. O texto jurídico das cláusulas vive isolado da lógica de montagem de dados (view/config separado), permitindo trocar o texto sem mexer em código
  5. PDF gerado com nome de empresa real extremo (longo, com caractere especial) mantém acentuação pt-BR correta e não corta cláusula no meio de uma página — reusando literalmente o precedente de `RelatorioMensalPdfService`

**Plans:** 12/12 plans complete

Plans:
**Wave 1**

- [x] 126-01-PLAN.md — Fundação do client: bloco `config('services.clicksign')`, `ClicksignException`, núcleo HTTP (header sem `Bearer`, retry só 429/5xx, log que não vaza token), `criarEnvelope`/`anexarDocumento` com Data URI, fixtures anonimizadas do sandbox (CLICK-01)
- [x] 126-03-PLAN.md — Migration aditiva `pdf_path`/`pdf_assinado_path` + `$fillable` na mesma entrega + guarda estática das 3 armadilhas de schema + states `comSnapshot()`/`comEmpresaDeNomeExtremo()` na factory (PDF-01)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 126-02-PLAN.md — Signatários, requisitos (`agree`+`role` / `provide_evidence`+`auth email`), ativação com prazo 30d e lembrete 3d explícitos, consulta, notificação, cancelamento e `montarEnvelope()` com rollback (CLICK-01)
- [x] 126-04-PLAN.md — `ContratoPdfService::montarDados()`: leitura exclusiva do `servicos_snapshot`, formatação pt-BR e placeholder visível `A DEFINIR` + `campos_pendentes` (PDF-01, PDF-02)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] ~~126-05-PLAN.md~~ — **SUPERADO pela reversão de 10/08/2026.** Foi executado (views `contratos/pdf.blade.php` e `contratos/clausulas.blade.php`, `gerar()`/`gerarESalvar()`, 13 testes), mas a renderização passa a ser da Clicksign. O código sai num plano dedicado, depois que o caminho de modelo estiver funcionando

**Wave 4** *(blocked on Wave 3 completion)*

- [x] ~~126-06-PLAN.md~~ — **DESCARTADO pela reversão de 10/08/2026.** Dos 3 gates: a inspeção visual do PDF perdeu o sentido; as 2 confirmações jurídicas foram respondidas (`companies.name` mistura razão social e nome fantasia → entrada da Fase 131; placeholder `A DEFINIR` mantido); o gate #5 ficou parcial (10 MB aceitos) e a migration no MariaDB segue pendente de autorização. Registro completo em `126-06-CHECKPOINT.md`

**Wave 5** *(replanejamento de 10/08/2026 — o caminho de MODELO da Clicksign, sobre os planos 01-04 preservados)*

- [x] 126-07-PLAN.md — Métodos de modelo no `ClicksignClient`: CRUD do recurso `templates` (listar/criar/excluir, `content_base64` como Data URI de `.docx`), `anexarDocumentoPorModelo()` com `filename` + `template:{key,data}` na forma medida na §9.6 do empírico, `montarEnvelopePorModelo()` com o mesmo rollback (D-12), e o 403 de conta sem acesso a modelos deixando de ser diagnosticado como e-mail da API não configurado (CLICK-01)
- [x] 126-08-PLAN.md — **Checkpoint de decisão do usuário:** como um contrato com N serviços vira documento (4 opções, incluindo a tabela em loop `{{#servicos}}` que a pesquisa encontrou) e quem aparece nomeado no rodapé do modelo; fecha as tensões 2.2 e 2.3 como D-19/D-20 e produz a lista FINAL de variáveis do `.docx` (PDF-01, PDF-02)

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 126-09-PLAN.md — `ContratoVariaveisModeloService`: a ponte do array aninhado de `montarDados()` para o hash plano de `template.data`, com mapa explícito (nada de achatamento automático), regras de nome vigiadas por regex e `nomes()` consultável sem contrato (PDF-01)

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 126-10-PLAN.md — Comando `clicksign:sondar-modelo`: dry-run por padrão, guarda de ambiente, contagem declarada de requisições contra a janela medida de 20/min, e a tabela de confronto entre as variáveis que o código emite e os `{{nomes}}` do `.docx` real (CLICK-01)

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 126-11-PLAN.md — **Gate de medição com `.docx` real** (autonomous: false): o usuário entrega o arquivo, a sondagem roda contra o sandbox e fecha os 7 itens em aberto — inclusive a **dívida da D-16** (excluir o modelo derruba o documento já gerado?) — e o usuário confirma visualmente o documento que a Clicksign gera (CLICK-01, PDF-01, PDF-03)

**Wave 9** *(blocked on Wave 8 completion)*

- [x] 126-12-PLAN.md — Remoção do caminho superado do plano 126-05: `contratos/pdf.blade.php`, `contratos/clausulas.blade.php`, `gerar()`/`gerarESalvar()` e `ContratoPdfServiceTest.php`. `montarDados()`, as colunas `pdf_path`/`pdf_assinado_path` e o dompdf permanecem; `ContratoPdfDadosTest` tem que passar **sem ser editado** (PDF-02)

### Phase 127: Service administrativo de contrato — orquestração (v22.0)

**Goal:** Existe um único ponto que decide se uma empresa está pronta para contrato, monta o envelope na Clicksign com prazo e lembrete configurados, e nunca gasta uma chamada HTTP com dado que já sabia estar incompleto.
**Requirements**: CLICK-02, CLICK-08, DADOS-06, REDE-05
**Depends on:** Fases 125, 126

> ⚠️ **ENTRADA OBRIGATÓRIA DA FASE 126 — ler antes de planejar.** A Fase 126 provou o caminho de
> modelo contra a API real e o gate humano foi aprovado, mas deixou **quatro coisas que esta fase
> precisa resolver**, todas registradas na **D-21** de `126-CONTEXT.md` e no `126-11-SUMMARY.md`:
>
> 1. **Um modelo `.docx` por serviço** (D-21 superou a D-19). Empresa com N serviços recebe **N
>    contratos** → 2 serviços = 30 chamadas contra a janela **medida** de 20/min. **Espaçar a
>    geração não é opcional.**
> 2. **Escolher o modelo pelo serviço** — hoje há um único `CLICKSIGN_TEMPLATE_ID`. Código que não
>    existe.
> 3. **Variável faltando vira BRANCO no contrato, sem erro nenhum** (§10.5 do empírico). Não há
>    resposta HTTP que denuncie. Regra: recadastrou o `.docx`, roda `clicksign:sondar-modelo` antes
>    de gerar contrato de cliente.
> 4. **Não existe pré-visualizar sem enviar** (§10.4). Ver o contrato preenchido exige ativar o
>    envelope, o que dispara e-mail ao cliente. Se o Comercial quiser conferir antes, é pela
>    interface web da Clicksign — decisão de produto desta fase.
>
> ✅ A **dívida D-16 está fechada** (§10.6): excluir o modelo **não** derruba documento já gerado.
**Success Criteria** (o que deve ser VERDADE):

  1. `ContratoClicksignService::iniciarParaEmpresa()` recusa continuar (sem chamar a Clicksign) quando falta e-mail do cliente, CNPJ válido ou nome do contato — devolve erro claro apontando o campo, antes de gerar PDF ou criar envelope
  2. Empresa com dados completos gera envelope na Clicksign com documento, signatários e requisitos de assinatura corretos, e o lembrete automático nativo (`remind_interval`) vem configurado — sem scheduler próprio
  3. Um contrato pode ter prazo de assinatura diferente do padrão do sistema, refletido no requisito criado na Clicksign
  4. Decisão A2 tomada e aplicada: quando a montagem falha no meio (documento criado, signatário falhou), o comportamento — cancelar o envelope parcial na Clicksign ou marcar `erro` com o id para retomada — é determinístico e coberto por teste
  5. `iniciarParaEmpresa()` chamado duas vezes para a mesma empresa não cria dois envelopes — idempotente por si só, independente de qualquer guard de webhook

**Plans:** 7/7 plans complete

Plans:

- [x] 127-01-PLAN.md — D-06: trava de unicidade vira (empresa + serviço); migration, model e factory
- [x] 127-02-PLAN.md — D-02: `ClicksignClient` ganha caminho que para no rascunho (`$ativar`)
- [x] 127-03-PLAN.md — REDE-05: checagem dedicada de dados mínimos, sem I/O
- [x] 127-04-PLAN.md — Modelo `.docx` por serviço + prazo/lembrete configuráveis (D-03/CLICK-08/DADOS-06)
- [x] 127-05-PLAN.md — `GerarContratoAssinaturaJob` + bucket de rate limit `clicksign-envelope` (D-01)
- [x] 127-06-PLAN.md — `ContratoClicksignService::iniciarParaEmpresa()`: bloqueio, congelamento, idempotência
- [x] 127-07-PLAN.md — Gate humano: 3 medições da fase (inclui autorização para tocar produção)

### Phase 128: Gatilhos do fluxo em modo observação (v22.0)

**Goal:** A decisão de gerar contrato passa a acontecer nos dois pontos de entrada de empresa, rodando lado a lado com o roteamento automático de hoje — sem desligar nada ainda.
**Requirements**: REDE-06, FLUXO-08
**Depends on:** Fases 124, 127
**Decisões a resolver aqui:** A4 (quais das 7 pendências valem para cadastro manual). ~~A5~~ já foi respondida: **só Polos é isento**; os outros 8 serviços exigem contrato (tabela na D9 de `REQUIREMENTS-v22.md`)
**Dimensionamento:** Gestão (149 empresas) é o volume real da fila; Gestão de ADS Shopee (30) entra SÓ por cadastro manual — o gate manual da D2 vale para essas 30, não é caso de borda
**Success Criteria** (o que deve ser VERDADE):

  0. A lista de serviços que EXIGEM contrato é um dado configurável, não um `if` espalhado; empresa cujo serviço é **Polos** não entra no fluxo administrativo em momento nenhum e continua indo direto para a operação (D9 — Polos não tem contrato), e não aparece como pendente na tela do Administrativo
  1. Empresa criada pelo webhook HubSpot **cujo serviço exige contrato** passa por `PendenciasComerciaisService::calcular` como GATE administrativo; sem pendência, `ContratoClicksignService::iniciarParaEmpresa()` é chamado de verdade (envelope real criado no sandbox) — e a empresa CONTINUA sendo roteada ao operacional na mesma hora, porque `administrativo_bloqueio_ativo` está desligado
  2. Empresa cadastrada à mão pelo Comercial passa pelo mesmo GATE e pelo mesmo disparo de contrato que o caminho HubSpot — decisão A4 (quais das 7 pendências valem para cadastro manual) tomada e aplicada
  3. Com pendência comercial em aberto segundo a regra do GATE, a empresa fica marcada `aguardando_comercial` e nenhuma chamada é feita à Clicksign
  4. Em nenhum momento desta fase uma empresa deixa de ser roteada ao operacional imediatamente — o desvio administrativo existe no código e roda em paralelo, mas a flag `administrativo_bloqueio_ativo` continua desligada (a mecânica da flag em si já foi construída e provada na Fase 124; aqui o foco é o desvio e a decisão A4)

**Plans:** 6/6 plans complete

Plans:

- [x] 128-01-PLAN.md — Coluna `servicos.exige_contrato` (D-03/FLUXO-08), Polos isento já na migration
- [x] 128-02-PLAN.md — `PendenciasComerciaisService::calcularUniversais()` sem quebrar a listagem do Comercial (D-01)
- [x] 128-03-PLAN.md — `GatilhoContratoAdministrativoService`: os dois portões da D-02 + isenção de Polos (SC0/SC3)
- [x] 128-04-PLAN.md — Gate ligado nos dois pontos de entrada, fora da transação + invariante do roteamento (SC1/SC2/SC4)
- [x] 128-05-PLAN.md — Reavaliação automática por Observer com campos-gatilho fixos e anti-laço (D-04)
- [x] 128-06-PLAN.md — Gate humano: envelope real no sandbox, invariante medido, Polos fora do fluxo

> ⚠️ **Esta fase é o coração do REDE-06 (modo observação).** O pior caso de bug aqui é um `ContratoAssinatura` com status errado — nunca uma empresa presa fora do operacional, porque o roteamento imediato antigo continua rodando em paralelo enquanto a flag estiver desligada.

### Phase 129: Webhook Clicksign (v22.0)

**Goal:** Quando a Clicksign avisa que algo mudou num contrato, o sistema confia apenas no que reconsultou, nunca no evento isolado — e a fórmula do HMAC deixa de ser uma dúvida de documentação para virar um fato verificado contra o sandbox real.
**Requirements**: CLICK-03, CLICK-04, CLICK-05, CLICK-06, CLICK-11, DADOS-03
**Depends on:** Fases 125, 126, 127
**Success Criteria** (o que deve ser VERDADE):

  1. **GATE A1 (bloqueante):** um webhook real do sandbox Clicksign foi disparado, as duas fórmulas de HMAC candidatas (`hash('sha256', body+secret)` vs. `hmac_sha256(secret, body)`) foram calculadas e logadas — nunca o secret — e a fórmula vencedora foi implementada com teste automatizado cujo fixture de HMAC foi calculado fora do código de produção
  2. Webhook com assinatura inválida é recusado (401) mas o evento é gravado bruto mesmo assim; webhook repetido (mesmo `payload_hash`) nunca duplica evento, signatário, assinatura, `MlbEmpresa` nem implementação operacional; decisão A3 (resposta HTTP diferenciada entre erro de validação e erro de processamento interno) tomada e testada
  3. Um evento de conclusão chegando antes de um evento de assinatura individual (ordem trocada) não libera a empresa cedo nem deixa de liberar — a decisão de liberar sempre reconsulta o estado agregado do envelope, nunca confia em qual evento chegou por último; recusa e expiração (gate empírico #6/#7, quando distinguíveis no payload) gravam estado próprio, nunca `cancelado`/`erro`
  4. O processamento pesado (buscar envelope, atualizar signatários, decidir liberação) roda em job de fila — a resposta HTTP ao provedor é rápida; retry/ordem de entrega (gate empírico #11) tratados como pior caso, sem garantia assumida
  5. Quando o envelope conclui, o PDF assinado é baixado da Clicksign e guardado no storage do próprio sistema — deixa de depender de `clicksign_download_url` para sempre

**Plans:** 6/7 plans executed

Plans:

- [x] 129-01-PLAN.md — Instrumentação do gate A1: `contrato_assinatura_eventos` (DADOS-03), varredura das 4 candidatas de HMAC, comando `clicksign:verificar-assinatura` (D-09) e rota-sonda temporária (D-07/D-08)
- [x] 129-02-PLAN.md — **GATE A1 (bloqueante, humano):** medição contra webhook real via túnel, fórmula fixada, fixture calculada FORA do código de produção, rota-sonda removida
- [x] 129-03-PLAN.md — Receiver de produção: rota `/api/webhooks/clicksign`, 401 com gravação bruta, dedup por `payload_hash`, matriz de resposta da D-10 e processamento na fila (CLICK-03/04/06)
- [x] 129-04-PLAN.md — Liberação operacional: `contrato_liberacoes` (D-05), gate da CLICK-05 (fechamento parcial NAO libera) e `EmpresaOperacionalRouter::liberarEmpresa()` — ponto único compartilhado com a Fase 130
- [x] 129-05-PLAN.md — Liberação por webhook, imunidade à ordem de entrega (gate #11 como pior caso) e estados próprios de recusa/expiração sem mexer no cadastro (D5/D-04)
- [x] 129-06-PLAN.md — PDF assinado (CLICK-11): download por streaming com link fresco (D-12), disco privado + rota autenticada (D-13), falha não bloqueia a liberação (D-14)
- [ ] 129-07-PLAN.md — Gate humano final: circuito ponta a ponta contra o sandbox real e registro do que continua não medido — **PARCIAL 2026-08-13:** Task 2 (auto) concluída — receiver de produção provado ponta a ponta pela internet (200/401/reentrega, corpo sintético), decisão A3 fechada, `129-GATE.md`/`CLICKSIGN-SANDBOX-EMPIRICO.md`/`REQUIREMENTS-v22.md` atualizados, suíte 346/1128 verde. **Task 1 (checkpoint humano) aberta** — rodada real de assinatura/recusa contra o sandbox pendente; ver `129-07-SUMMARY.md`

### Phase 130: Rede de segurança — reconciliação, alerta e liberação manual (v22.0)

**Goal:** Se a Clicksign falhar silenciosamente, a falha para de ser invisível: o sistema se corrige sozinho quando consegue, e quando não consegue avisa a equipe no sino em vez de deixar a empresa parada sem ninguém saber — e sempre existe um jeito de destravar uma empresa presa, com registro de quem e por quê.

> **Nota de redação (2026-08-14):** o texto original prometia *"alguém sabe em minutos, não em dias"*. A D-01 do `130-CONTEXT.md` travou o canal como sino in-app — o aviso chega **quando alguém abre o sistema**, não em minutos. A implementação está correta e a decisão foi consciente (e-mail e WhatsApp foram recusados por quebrarem o canal único do projeto); era o goal que prometia um canal que a fase nunca teve. Reescrito após a verificação goal-backward concordar com essa leitura. Se "em minutos" virar requisito de verdade, é fase própria — o `DigisacClient` já existe e hoje só serve ao NPS.
**Requirements**: REDE-02, REDE-03, REDE-04, DADOS-05
**Depends on:** Fases 124, 126, 129
**Success Criteria** (o que deve ser VERDADE):

  1. Um comando agendado reconsulta periodicamente a Clicksign para todo contrato em `enviado`/`aguardando_assinaturas` e corrige o estado local quando a assinatura já concluiu do lado da Clicksign mas o webhook nunca chegou — testado manualmente em sandbox com pelo menos um caso corrigido de fato; gate empírico #10 (granularidade da consulta de envelope) resolvido e suficiente
  2. Um alerta dispara quando uma empresa está sem liberação e com envio há mais dias que o aceitável — disparo comprovado pelo menos uma vez em sandbox
  3. Um admin libera uma empresa ao operacional preenchendo motivo (campo obrigatório); a liberação registra quem liberou e por quê, e usa o mesmo `EmpresaOperacionalRouter::liberarEmpresa()` do fluxo automático — testada ponta a ponta pelo menos uma vez
  4. Liberar manualmente uma empresa que o webhook também está tentando liberar ao mesmo tempo (corrida) não cria `MlbEmpresa` duplicada

⚠️ **Success Criteria 1-3 (SC1/SC2/SC3) NÃO estão confirmados ainda** — todos os 7 planos foram
executados, mas os 3 gates humanos em sandbox do plano 130-07 não puderam ser aprovados na
sessão de 2026-08-13 (nenhuma ferramenta do executor abre navegador/assina na Clicksign). SC2
teve a metade técnica provada por reconsulta ao banco; SC1 e SC3 continuam pendentes de ação
real do usuário. Ver `130-GATE.md` para o roteiro de retomada. SC4 já está provado (herdado do
CR-01 da Fase 129, reconfirmado no plano 130-04).

**Plans:** 7/7 plans complete

Plans:
**Wave 1**

- [x] 130-01-PLAN.md — Fundação de dados: 3ª via de liberação, lista fechada de motivos e carimbo de último alerta (wave 1)
- [x] 130-02-PLAN.md — Audiência da rede de segurança + recorte único de "empresa parada há tempo demais" (wave 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 130-03-PLAN.md — Reconciliação: comando diário + job que reconsulta a Clicksign e corrige sozinho (wave 2)
- [x] 130-04-PLAN.md — Liberação manual só-admin com motivo obrigatório + prova do SC4 (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 130-05-PLAN.md — Alerta de contrato preso no sino, com causa, próximo passo e cooldown (wave 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 130-06-PLAN.md — Auto-monitoramento da varredura + agendamento dos 3 comandos (wave 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 130-07-PLAN.md — Gates humanos em sandbox: SC1, SC2, SC3 e gate empírico #10 (wave 5)

### Phase 131: Tela administrativa — completar cadastro + contratos + badge Comercial + permissões (v22.0)

**Goal:** O Administrativo completa o cadastro que o Comercial deixou pela metade e enxerga o estado real de cada contrato sem abrir o banco, e o Comercial para de se perguntar "para onde foi essa empresa depois do fechamento".
**Requirements**: ADM-01, ADM-02, ADM-03, UI-01, UI-02, UI-03, UI-04, UI-05, UI-06, CLICK-07, CLICK-09, CLICK-10
**Depends on:** Fases 125, 127, 129, 130
**UI hint:** yes
**Success Criteria** (o que deve ser VERDADE):

  0. Um usuário do Administrativo completa, na própria tela, os dados que a empresa não trouxe do Comercial (CNPJ, Gmail do colaborador, datas de início e término do contrato); a tela mostra o que ainda falta para poder gerar contrato (D8 — a cobrança NÃO volta para o Comercial)
  0b. ~~O campo Gmail do colaborador sai do formulário do Comercial NESTA MESMA fase — nunca antes, para não existir janela em que ninguém consegue cadastrar o dado (ADM-03)~~ → **REESCRITO em 2026-08-15, após a verificação.** O critério partia de uma premissa errada: presumia um campo só. São **dois**. O que o requisito descreve (`companies.email_colaborador`, o e-mail que a ECF cria para ter acesso à conta do cliente no Mercado Livre) **já havia saído** do Comercial na quick `260805-eqk`, e seguiu editável em `/companies` esse tempo todo — **a janela sem ninguém cadastrando o dado nunca existiu**. O que restou no formulário do Comercial é o `gmail_colaborador` do **Polos**, outro campo, de um serviço isento de contrato (D9), que esta fase decidiu **manter** (D-12). Redação correta: *"o Administrativo passa a ter onde preencher o `email_colaborador` na própria tela; nenhum campo é removido do Comercial nesta fase."*

  1. Um usuário do Administrativo filtra a lista de contratos por situação, busca por empresa, e vê um resumo com a contagem de cada situação
  2. ~~O botão "Gerar contrato" só **aparece** quando a empresa está com o cadastro completo~~ → **REESCRITO em 2026-08-15, após a verificação.** O usuário escolheu o oposto no discuss desta fase (D-03, `131-DISCUSSION-LOG.md` P3): o botão fica **visível e desabilitado**, com a lista do que falta ao lado — esconder faria o Administrativo não descobrir que a ação existe, e o botão cinza com a lista ensina o caminho para destravar. **O propósito do critério é preservado com mais rigor que o texto:** além de o botão não clicar, há revalidação no servidor que devolve 422, provada por teste — esconder o botão sozinho não impediria um POST direto. Redação correta: *"o botão fica desabilitado com o que falta ao lado enquanto houver pendência, e o servidor recusa a geração mesmo se a tela for contornada; clicar dispara o mesmo fluxo manual da Fase 127."*
  3. A listagem do Comercial mostra em que pé está o contrato de cada empresa, sem precisar abrir outra tela
  4. Um usuário reenvia a notificação de assinatura para quem ainda não assinou, corrige o e-mail de um signatário sem cancelar o contrato (gate empírico #8 resolvido para o endpoint certo), e cancela um contrato em andamento informando o motivo — a tela deixa claro que corrigir e-mail é diferente de trocar a pessoa (a segunda exige cancelar e reemitir)
  5. Só quem tem a permissão `admin.contratos` vê o módulo no menu e acessa as rotas; nenhum texto da tela usa jargão de Clicksign ou de assinatura eletrônica sem explicação

**Plans:** 6/6 plans complete

⚠️ Escopo corrigido pelas medições de 2026-08-14 (registradas no `131-CONTEXT.md` D-12/D-13/D-14 e nos gates #8 e #8b do `REQUIREMENTS-v22.md`):

- **ADM-03 já está cumprida** — o campo saiu do formulário do Comercial na quick `260805-eqk`; o `gmail_colaborador` do Polos fica onde está. Nenhum trabalho de remoção nesta fase.
- **CLICK-09** — não existe endpoint de correção de e-mail na v3 (404 medido). Vale o RAMO B: a tela explica que corrigir e-mail e trocar a pessoa colapsam em cancelar e reemitir.
- **CLICK-10** — cancelar envelope em andamento não é possível pela API (403/404/400 medidos). A tela registra autor+motivo+data e instrui a concluir no painel; `cancelarEnvelope()` não é chamado.

Plans:
**Wave 1**

- [x] 131-01-PLAN.md — Fundação: permission `admin.contratos`, colunas do cancelamento solicitado e módulo único dos 7 estados (wave 1)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 131-02-PLAN.md — Badge de contrato na listagem do Comercial, sem N+1 (wave 2)
- [x] 131-03-PLAN.md — Lista administrativa de contratos: filtro, busca e resumo de 7 contagens (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 131-04-PLAN.md — Detalhe da empresa: completar cadastro e gerar contrato (wave 3)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 131-05-PLAN.md — Ações do contrato: reenviar aviso, RAMO B do ajuste e registro de cancelamento (wave 4)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 131-06-PLAN.md — Absorção da liberação manual e remoção da tela da Fase 130 (wave 5)

### Phase 132: Cutover sandbox → produção (checkpoint humano) (v22.0)

**Goal:** A troca de sandbox para a Clicksign de produção acontece checada por um humano, não assumida como "só trocar a URL".
**Requirements**: nenhum REQ-ID novo — fase de checkpoint de risco operacional (gate empírico #3); PITFALLS.md exige que o cutover não fique embutido numa fase técnica
**Depends on:** Fases 126, 127, 129, 130, 131
**Success Criteria** (o que deve ser VERDADE):

  1. Checklist de cutover conferido manualmente: `CLICKSIGN_ENV=production`, `CLICKSIGN_BASE_URL` de produção, `CLICKSIGN_ACCESS_TOKEN` de produção, `CLICKSIGN_WEBHOOK_SECRET` de produção
  2. A URL `/api/webhooks/clicksign` foi cadastrada manualmente na conta de PRODUÇÃO da Clicksign (não só sandbox) — confirmado, não assumido
  3. O primeiro envelope de produção foi criado com uma empresa de teste controlada (ex.: a própria ECF Consultoria), não com um cliente real, e o webhook de produção chegou e foi processado corretamente
  4. Gate empírico #3 (URL base de produção) confirmado contra o ambiente real
  5. **CHECKPOINT HUMANO:** usuário aprova explicitamente que o cutover está correto antes de qualquer contrato real de cliente ser gerado em produção

**Plans:** 4/4 plans complete

Plans:
**Wave 1**

- [x] 132-01-PLAN.md — Corrige a grafia de `CLICKSIGN_ENV` (D-01), documenta o estacionamento das credenciais no `.env.example` e escreve o roteiro `132-GATE.md` com o procedimento de voltar atrás (wave 1, autônomo)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 132-02-PLAN.md — Publica a correção, troca as variáveis de produção e confirma a URL base contra a API real (SC1 + SC4 / gate empírico #3) (wave 2, checkpoint humano)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 132-03-PLAN.md — Cadastra o aviso automático na conta de produção e emite o primeiro envelope contra empresa fictícia, provando que o webhook chega (SC2 + SC3) (wave 3, checkpoint humano)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 132-04-PLAN.md — Prova a rede de segurança com o mesmo envelope (D-04, SC1 da Fase 130 + gate #10) e colhe a aprovação explícita da virada (SC5) (wave 4, checkpoint humano)

### Phase 133: Liga o bloqueio — ativação real (v22.0)

**Goal:** A partir de agora, contrato assinado é de fato a porta de entrada do operacional — e existe uma saída rápida se algo der errado.
**Requirements**: FLUXO-01, FLUXO-02, FLUXO-09
**Depends on:** Fases 128, 130, 131, 132
**Success Criteria** (o que deve ser VERDADE):

  1. Com `administrativo_bloqueio_ativo=true`, uma empresa criada pelo webhook HubSpot **cujo serviço exige contrato** já não é roteada ao operacional na mesma transação — fica aguardando a etapa administrativa
  2. Uma empresa cadastrada à mão pelo Comercial segue exatamente o mesmo caminho, sem atalho
  2b. Empresa de **Polos continua indo direto para a operação mesmo com o bloqueio ligado** (D9 — Polos não tem contrato); provado por teste com a chave ligada, e conferido em produção no dia do rollout

  3. Uma empresa só chega ao operacional depois que o webhook confirma assinatura completa (reconsultada) ou um admin libera manualmente com motivo registrado
  4. Desligar a chave `administrativo_bloqueio_ativo` sem deploy volta o sistema ao roteamento imediato de antes, imediatamente
  5. **(FLUXO-09)** Com o bloqueio ligado, a ativação manual do time de Publicação (`MlbController::ativarEmpresaPendente()`, tela `/mlb/empresas`) também não cria ficha operacional — provado por teste. Lacuna descoberta na verificação da Fase 124: esse método cria `MlbEmpresa`+`MlbImplementacao` por cópia inline, fora do `EmpresaOperacionalRouter` e sem consultar a chave

**Plans:** 4/5 plans executed — **ATIVADO em 2026-08-19.** Checkpoint corrigido na mesma conversa (primeira resposta foi `parar`, o usuário se corrigiu: "Desculpa me enganei, todos os pontos estão testados"); decisão final **`ligar-agora`**. Chave `administrativo_bloqueio_ativo` está **LIGADA** em produção desde 2026-08-19 (~09:05 BRT), conferida por reconsulta (`bloqueioAtivo()` = `ligado`), commit implantado `c4043014`, `mlb_empresas` idêntica antes/depois (488|488). 133-05 desbloqueado e pendente. Ver `133-ROLLOUT.md` e `133-04-SUMMARY.md`.

Plans:
**Wave 1**

- [x] 133-01-PLAN.md — Exceção por serviço em `rotear()` (Polos nunca é bloqueado) + os 4 testes do Phase124 trocados de cenário [wave 1]
- [x] 133-02-PLAN.md — Porta dos fundos do time de Publicação (FLUXO-09) + registro da dívida das duas rotas extras (D-06) [wave 1]
- [x] 133-03-PLAN.md — A tela `/administrativo/contratos` conta a consequência: faixa condicional sem jargão (D-04) [wave 1]

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 133-04-PLAN.md — Roteiro de rollout (`133-ROLLOUT.md`) escrito; checkpoint das 4 pré-condições confirmado (`ligar-agora`) em 2026-08-19; deploy + ativação da chave (Task 3) **EXECUTADA** — `administrativo_bloqueio_ativo` LIGADA em produção [wave 2]

**Wave 3** *(blocked on Wave 2 completion — DESBLOQUEADO: ativação real ocorreu em 2026-08-19)*

- [ ] 133-05-PLAN.md — Conferência da faixa e do primeiro cadastro real de Polos por reconsulta ao banco (D-05) [wave 3]

> 🚦 **CHECKPOINT HUMANO — bloqueia a ATIVAÇÃO, não a escrita.** A flag `administrativo_bloqueio_ativo` só pode ser ligada em produção depois de confirmar, com o usuário: (a) o webhook chegou de forma confiável durante o período de observação (Fase 128/129 rodando em produção por tempo suficiente); (b) o alerta de contrato preso já disparou pelo menos uma vez em sandbox (Fase 130); (c) a liberação manual foi testada em produção ao menos uma vez (Fase 130); (d) o cutover para produção Clicksign foi concluído e aprovado (Fase 132). Rollback de código sozinho nunca é o plano de saída — desligar a flag é.
>
> **Resultado em 2026-08-19:** primeira rodada, (a)/(b)/(c) responderam "Não / não sei" — decisão inicial `parar` (histórico preservado no `133-ROLLOUT.md`). Na mesma conversa o usuário se corrigiu: as quatro pré-condições foram confirmadas. Decisão final: **ligar-agora**. Deploy autorizado explicitamente e executado (commit `c4043014`); chave **LIGADA** em produção, conferida por reconsulta ao banco. Próximo: plano 133-05 — verificação em produção nas primeiras 48h e primeiro cadastro real de Polos.

## Fase avulsa — Módulo Anunciar Mercado Livre (fora de milestone)

### Phase 134: "Meus Anúncios" — saúde analítica do anúncio publicado

**Goal:** Quem cuida dos anúncios de uma empresa abre uma tela e, em segundos, sabe quais anúncios estão saudáveis, quais estão perdendo venda e por quê — com dado real vindo da API do Mercado Livre, não com a análise de formulário que hoje só existe durante a criação e some no instante em que o anúncio é publicado.

**Requirements**: (fase avulsa — sem REQ-IDs de milestone; requisitos derivados do `134-CONTEXT.md`)
**Depends on:** Phase 86 (Histórico dos publicados — a aba sobrevive e continua sendo a base do "Anunciar semelhante em massa")

**Success Criteria** (o que deve ser VERDADE):

  1. Existe a rota `mlb.anuncios.meus` por empresa, e ela é a **aba inicial** do módulo — Meus Anúncios | Individual | Em massa | Histórico
  2. A tela lista **todos os anúncios ativos da conta ML do cliente**, não só os que este módulo publicou, e cada linha diz de onde o anúncio veio (ECF · time · legado do cliente)
  3. A tela lê **exclusivamente do banco** — nenhuma chamada síncrona ao ML no caminho do request; a coleta é comando agendado + job por empresa
  4. O topo da tela é triagem acionável agrupada por motivo (pausado, sem estoque, ficha incompleta, …), e clicar num motivo filtra a lista
  5. Coleta falha ou está velha → a tela mostra o último snapshot com selo de defasagem, nunca uma tela em branco
  6. O bloco "Rascunhos recentes" saiu do aside do wizard e vive na sub-aba Rascunhos, junto com o "Publicar lote"; a "Saúde do anúncio" continua intacta no aside do wizard

**Plans:** 10/10 plans complete

Plans:
**Wave 1**

- [x] 134-01-PLAN.md — Sondagem D-21 (saúde do ML), config da fase e fixtures reais da API [wave 1]
- [x] 134-02-PLAN.md — Schema: ml_acervo_itens + ml_acervo_metricas_diarias, com índices nomeados à mão [wave 1]

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 134-03-PLAN.md — Nota ECF em PHP (base 86) + teste de concordância com o scorer JS [wave 2]

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 134-04-PLAN.md — Coleta camada barata: scroll_id, multiget de 20, upsert, selo de origem e série diária [wave 3]
- [x] 134-05-PLAN.md — Camada cara: visitas e price_to_win em rotação por fatia (D-23) [wave 3]

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 134-06-PLAN.md — Comandos mlb:sync-acervo e mlb:acervo-cleanup + agendamento diário [wave 4]
- [x] 134-07-PLAN.md — Rota mlb.anuncios.meus: listagem, triagem, ordenação, defasagem e Atualizar agora [wave 4]

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 134-08-PLAN.md — 4ª aba + tela Publicados: triagem acionável, tabela e selos de honestidade [wave 5]

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 134-09-PLAN.md — Sub-aba Rascunhos com card clicável + saída do bloco do wizard (D-16) [wave 6]

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 134-10-PLAN.md — Modal de Detalhe: checklist dos sinais e série de 90 dias (Recharts) [wave 7]

> **Fora de escopo (fase própria):** qualquer write na API do ML (pausar, editar, mover anúncio) — ação destrutiva na conta do cliente em produção, exige confirmação dupla, `activity_log` e undo, na mesma linha do todo `260626-acoes-ml-mover-sgi-pausar-via-api.md`. Também fora: abrir o módulo ao time de publicação (`role:admin` → `permission:mlb.anunciar`).

### Phase 135: Onboarding Geral por Serviço — motor dirigido por template com passos automáticos

**Goal:** Qualquer serviço do catálogo (não só Polos) passa a ter onboarding de verdade: a Coordenação escolhe o serviço, o sistema monta o checklist a partir de um template versionado, e os passos que o sistema já sabe responder — anúncios ativos/inativos, faturamento, reputação, medalha, grants — chegam **preenchidos** em vez de virarem formulário para alguém digitar o que já está no banco.

**Requirements**: (fase avulsa — sem REQ-IDs de milestone; requisitos derivados do `135-CONTEXT.md`)
**Depends on:** Phase 134 (`ml_acervo_itens` é a fonte do passo automático de anúncios ativos/inativos; `mlb:sync-acervo --company` é o gatilho sob demanda para empresa recém-onboardada)

**Success Criteria** (o que deve ser VERDADE):

  1. Existe `/onboarding` cobrindo **qualquer** serviço do catálogo, ancorado em `Company × Servico` — um onboarding por contrato, não um por empresa
  2. O onboarding de Polos (`mlb_implementacoes`, `/mlb/implementacao`, `/implementacao/{token}`) continua **byte-a-byte intocado** e em produção; o motor novo nasce ao lado
  3. Contrato de serviço criado por **qualquer** dos 4 caminhos (HubSpot webhook · Comercial · Company/Show · CompanyGroup) gera onboarding em `rascunho` — via Observer em `ContratoServico`, não por lógica duplicada em cada controller
  4. Onboarding em `rascunho` não corre SLA e não expõe link; só vira `andamento` quando a Coordenação confirma o responsável (sugerido por `responsavelDoServicoOuConsolidado()`)
  5. Passo tem **dono** (`cliente` · `interno` · `sistema`), dependência declarada, SLA próprio e condição de existência — passo dependente nasce bloqueado e destrava sozinho
  6. Os **5 passos automáticos** do template de Gestão se resolvem sem digitação humana, por resolvers registrados em código: `adman_account_id`, grant Adman (sonda `fetchPerformance` por `cust_id` — ver D-18), `ml_tokens.status`, `ml_acervo_itens`, `MercadoLivreService::fetchUserInfo()`. São 4 passos com `dono=sistema` **+ o passo 5** (`dono=cliente`): `dono` diz quem precisa **agir**, `auto_fonte` diz como o sistema **verifica** — eixos independentes (D-19)
  7. Resolver distingue **"ainda não coletado"** de **"realmente zero"** — empresa nova dispara `mlb:sync-acervo --company` e só então lê; nunca aceita tabela vazia como resposta
  8. Admin monta e edita templates pela UI, escolhendo `auto_fonte` de um **catálogo fechado** de resolvers (nunca texto livre), com guarda contra ciclo em `depende_de`
  9. Salvar template publica versão N+1 e vale só para onboardings novos; os em andamento seguem na versão em que nasceram, com ação explícita para migrá-los
  10. Cliente recebe **um link por empresa** que agrega os passos `dono=cliente` de todos os serviços ativos; passo de mesma `chave` em serviços diferentes fecha uma vez só
  11. O painel responde "o que está travando, há quantos dias e de quem é a bola" — não uma barra de porcentagem

**Plans:** 12/13 plans executed

Plans:
**Wave 1**

- [x] 135-01-PLAN.md — Wave 0: baseline de regressao do Polos, ContratoServicoFactory e scaffold da suite [wave 1]

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 135-02-PLAN.md — Schema do motor: 5 tabelas versionadas (com disponivel_em) + 5 models com catalogo fechado [wave 2]

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 135-03-PLAN.md — Catalogo fechado de resolvers: Contract, resultado de 3 estados, registry + 2 resolvers locais [wave 3]
- [x] 135-04-PLAN.md — Template de Gestao v1 (13 passos) + engine de montagem, dependencias e condicoes [wave 3]

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 135-05-PLAN.md — Observer de ContratoServico nos 4 call-sites + transicao rascunho→andamento [wave 4]
- [x] 135-06-PLAN.md — Resolvers de rede (sonda de grant Adman, metricas da conta) + Job assincrono [wave 4]
- [x] 135-08-PLAN.md — CRUD de template: versao N+1 imutavel, guarda de ciclo, migracao explicita [wave 4]

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 135-07-PLAN.md — Resolver de acervo (SC-07: vazio ≠ zero) + comando de reavaliacao agendado [wave 5]
- [x] 135-09-PLAN.md — Painel operacional (backend): permission core.onboarding, props de "o que trava" [wave 5]
- [x] 135-10-PLAN.md — Tela 2: builder de template (React) [wave 5]

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 135-11-PLAN.md — Link publico por empresa + Tela 3: portal do cliente [wave 6]
- [x] 135-12-PLAN.md — Tela 1: painel operacional (React) + item de menu [wave 6]

**Wave 7** *(blocked on Wave 6 completion)*

- [~] 135-13-PLAN.md — Gate de regressao do Polos, mapa de evidencia SC/D e verificacoes manuais [wave 7] — Tasks 1-2 OK (gate do Polos APROVADO); Task 3 AGUARDANDO GATE HUMANO

- [ ] TBD (run /gsd-plan-phase 135 to break down)

> **Escopo da v1:** só o template de **Gestão (Performance)** — 13 passos, 5 automáticos. Publicação, Shopee, Assessoria/Incubadora/Implantação ganham template depois, sem tocar no motor.
>
> **Fora de escopo (já é a milestone v22.0, Fases 124-133):** "Contrato Solicitado → Administrativo", revisão e assinatura de contrato via Clicksign. Contrato assinado é **pré-requisito** do onboarding nascer, não passo dele.
>
> **Fora de escopo (fase própria):** geração automática do relatório inicial de onboarding (cenário, métricas, estrutura, pontos de atenção, oportunidades, próximos passos). Os passos 7 e 8 já produzem quase todo o dado e `RelatorioMensalPdfService` é o molde — mas montar o PDF é fase separada.
>
> **Assunção fechada pelo usuário em 2026-08-11 (D-18 do `135-CONTEXT.md`):** "Grant com o Sistema ECF" (item 11 do fluxo do cliente) = autorizar o app ECF por OAuth → `ml_tokens` — **confirmado**. "Grant com a Consultoria" = grant com a **Adman** (`api.adman.com.br`), a plataforma que a consultoria usa — **corrigido**: não é `company_grants`. O que `company_grants` guarda (populado por `SyncGrantsFromEcfDrive`/`SyncGrantsFromSftp`) é o programa de parceiros do ML — medalha, programa, iniciativa — dado que a ECF *recebe*, não acesso que o cliente *concede*; segue como fonte dentro do passo 7, não como passo próprio. Pendência para o `RESEARCH.md`: qual chamada do `AdmanService` serve de sonda barata de "grant ativo para este cust_id" — se nenhuma servir, o passo 4 cai para dono `interno` com checagem manual. **Nada disso muda a arquitetura do motor.**

### Phase 136: Métricas manuais por empresa/mês no Desempenho

**Goal:** Permitir que o admin decida, por empresa e por mês, se faturamento e margem vêm da API ou de um valor lançado à mão — sem o qual carteira Shopee fica estruturalmente sem margem (a plataforma não expõe CMV) e empresa sem conexão OAuth fica sem faturamento nenhum. A margem manual se lança pelo **CMV do mês**: o sistema já tem o faturamento e deriva `margem % = (fat − CMV) / fat`, e daí os p.p. contra o mês anterior — mantém o "antes → depois" da tela e o número auditável. Vale **só para competência em curso e não consolidada** (a trava de congelamento continua valendo), é **admin-only**, e o lançamento acontece em **tela própria, em grade empresa × mês**, porque o CMV chega em lote no fechamento. Inclui a correção do desempate de fonte financeira: hoje `'adman'` vence sobre `'shopee'` sem verificar se a empresa tem conta Adman, o que faz a mesma empresa mostrar faturamento para um profissional e nada para outro.

**Requirements**: TBD — a unidade de rastreabilidade desta fase sao os IDs de decisao do `136-CONTEXT.md` (D-01..D-12 + D-EXC-01), todos cobertos pelos planos abaixo.
**Depends on:** Phase 135
**Plans:** 6/7 plans executed

Plans:

**Wave 1** *(paralelos, sem sobreposicao de arquivos)*

- [x] 136-01-PLAN.md — D-10: resolvedor unico de fonte financeira nos 3 call-sites + bump de cache v19->v20 + rotacao do gate de hash da Fase 119 + baseline de falhas [wave 1]
- [x] 136-02-PLAN.md — Fundacao de dados: tabela `desempenho_metricas_manuais`, model com activitylog, helper de competencia consolidada e FormRequest (D-01/D-02/D-07/D-09/D-12) [wave 1]

**Wave 2** *(blocked on Wave 1)*

- [x] 136-03-PLAN.md — `ManualMetricOverrideService` + fiacao no motor de nota + liberacao da margem Shopee com CMV manual + rastro no snapshot (D-01..D-08, D-03, D-EXC-01) [wave 2]
- [x] 136-06-PLAN.md — Comando read-only `desempenho:relatorio-impacto-fonte` (D-11) [wave 2]

**Wave 3** *(blocked on Wave 2)*

- [x] 136-04-PLAN.md — Backend da grade: controller, rotas admin-only e escrita transacional com lock (D-01/D-02/D-07/D-09/D-12) [wave 3]

**Wave 4** *(blocked on Wave 3)*

- [x] 136-05-PLAN.md — Front: grade empresa x mes, selo discreto por metrica em `/performance/{user}` e item de menu (D-04) [wave 4]

**Wave 5** *(blocked on Wave 4)*

- [ ] 136-07-PLAN.md — Gate de regressao contra a baseline, conferencia do FIXMARG-03 por exit code e checkpoint humano bloqueante [wave 5]

> **Leitura deliberada do goal (D-09):** "em curso **e** nao consolidada" e aplicado como **"nao consolidada"**, nao como "mes corrente". Julho/2026 esta fechado pelo calendario e nao consolidado (esperando NPS coletado em agosto) — e precisamente o caso que a fase precisa atender, e D-05 (celula manual compara mes cheio x mes cheio) torna o valor de mes cheio impossivel antes do fim do mes. Ler literalmente "em curso" entregaria uma tela inutil.
>
> **Fora de escopo, nao planejado:** recalibrar reguas; reconsolidar competencias fechadas (a fase entrega so o relatorio read-only de impacto, D-11); mudar a agregacao (faturamento usa mediana, margem usa media — de proposito); mexer no piso de NPS; corrigir o lock global por mes do `WarmDesempenhoDispatcher`.

### Phase 137: Fechamento mensal — faturamento por empresa/grupo contra a tabela progressiva

**Goal:** Dar ao Administrativo uma tela de fechamento que responda, para cada empresa e cada GRUPO, qual foi o faturamento do mês fechado e em que faixa da tabela progressiva isso o coloca — subiu, manteve ou caiu. É a contrapartida operacional dos contratos das Fases 124-133: o contrato define a tabela, o fechamento a aplica. A rotina real é executada no primeiro dia útil do mês, referente ao mês anterior (em 01/09 fecha-se agosto), e cobre empresas de Mercado Livre **e** Shopee.

Quatro coisas precisam mudar em relação ao que existe hoje em `/financeiro` (`AdminController::fechamento()`):

1. **Janela fechada, sem acumulativo.** Hoje o mês corrente usa janela MÓVEL de 30 dias (`Carbon::now()->subDays(30)`) e só meses passados usam mês-calendário. O usuário pediu explicitamente que "não deve ter acumulativo — o valor mostrado deve ser de janelas fechadas, mês a mês".

2. **Grupos vêm do Comercial.** A tela tem uma função de grupos própria e antiga; o Comercial já tem grupos de empresas cadastrados e em uso. Manter duas fontes de verdade para a mesma coisa é o problema a eliminar. O faturamento do grupo é a SOMA das empresas-irmãs, e é a soma que determina a faixa (exemplo do usuário: grupo Lyam, duas empresas).

3. **A tabela progressiva vira dado estruturado.** Hoje ela só existe como TEXTO dentro dos modelos `.docx` da Clicksign — e são tabelas DIFERENTES por serviço (Gestão/ML, Shopee, Brigada). Sem as faixas como dado, não há como calcular faixa nenhuma.

4. **Cadastro manual da tabela por empresa.** A geração de contrato pelo sistema é recente; a maioria das empresas já existentes está em contrato de tabela progressiva sem que o sistema saiba disso. Precisa haver um jeito de lançar as faixas de uma empresa "como se estivesse fazendo contrato, mas só para o sistema saber as faixas" — incluindo empresas com tabela antiga ou fora do padrão, que existem e são legítimas.

**Requirements**: D-01, D-02, D-02b, D-04 a D-13 (decisões travadas em `137-CONTEXT.md` — a fase não tem REQ-IDs no `REQUIREMENTS.md` raiz, que parou na v17.0; a unidade de rastreabilidade é o D-ID, mesma convenção da Fase 136). D-03 saiu do escopo: ficou obsoleto com a correção de D-02.
**Depends on:** Phase 136 (nenhuma dependência de código conhecida; ordem de fila)
**Plans:** 4/9 plans executed

Plans:
- [x] 137-01-PLAN.md — Schema e models das faixas de faturamento (por serviço e por empresa) + seed das três tabelas medidas (D-02b)
- [x] 137-02-PLAN.md — Schema e models do snapshot congelado por competência + auditoria de reconsolidação
- [x] 137-03-PLAN.md — FechamentoFaixaResolver (herança serviço→empresa) e FechamentoRollupService (ML+Shopee em mês-calendário)
- [x] 137-05-PLAN.md — Writer idempotente + comandos `fechamento:consolidar-mes` e `fechamento:verificar-consolidacao`
- [x] 137-06-PLAN.md — FechamentoController: cadastro manual das faixas e ações de fechar/refazer competência
- [x] 137-07-PLAN.md — `AdminController::fechamento()` migrado: mês-calendário, grupos do Comercial e leitura do congelado
- [x] 137-08-PLAN.md — Relatórios PDF e email mensal na fonte central; constante `FAIXAS` apagada das duas cópias
- [x] 137-09-PLAN.md — UI: tabela de faixas por empresa, estados de ausência visível e composição ML+Shopee
- [x] 137-10-PLAN.md — UI: status/fechar/refazer competência, fim do acumulado e checkpoint humano conferindo as três tabelas contra o contrato

> **Estado atual medido (2026-09-02, antes de qualquer plano):** a tela é a rota `/financeiro`, `AdminController::fechamento()` (~linha 126). Existem também `EnviarRelatorioFechamentoJob`, `RelatorioFechamentoMail` e o model `FechamentoRecebido` — precisam ser mapeados antes de qualquer mudança, porque a fase mexe no que eles produzem.
>
> **Perguntas do discuss-phase — todas fechadas em 2026-09-02** (`137-CONTEXT.md`, D-01 a D-13): o faturamento sai de `adman_metrics` + `shopee_metrics` somados na mesma janela de mês-calendário (D-05/D-06/D-07); a tabela mora por serviço com exceção por empresa, all-or-nothing (D-01/D-13); empresa sem tabela aparece como `A DEFINIR` com CTA de cadastro, nunca R$ 0 (D-04); e o fechamento vira registro congelado por competência, com reconsolidação permitida mediante motivo registrado (D-11/D-12).

### Phase 138: Tabela do grupo e aviso de mudança de faixa

**Goal:** Fechar as duas lacunas que o uso real do fechamento revelou no primeiro dia (2026-09-03),
depois que agosto foi fechado em produção com 201 empresas e 15 grupos.

**1. Grupo passa a ter tabela própria.** Hoje o grupo NÃO tem tabela: ele é classificado pela tabela
da empresa-âncora — o membro que mais faturou no mês, com desempate por menor id
(`ConsolidarMesFechamento`, ~linha 271). Funciona enquanto as irmãs compartilham a mesma tabela, mas
tem dois defeitos: um grupo que negociou tabela própria não tem onde registrá-la, e se as irmãs têm
tabelas diferentes **quem manda muda de mês para mês**, silenciosamente, conforme quem faturou mais.

Decisão do usuário (2026-09-03): criar tabela de grupo cadastrável pela tela, com precedência:

```
1. tabela própria do GRUPO      <- nova
2. tabela própria da EMPRESA    <- já existe (empresa_faixas_faturamento)
3. tabela do SERVIÇO (padrão)   <- já existe (servico_faixas_faturamento)
```

**2. Aviso de mudança de faixa para os admins.** Pedido do usuário. O dado já é calculado: o
snapshot grava `evolucao` comparando a competência com a anterior — falta só notificar a partir dele.

Decisão do usuário (2026-09-03): avisar **nos dois sentidos, subida e queda**. Queda de faixa
significa cobrar menos, e é exatamente o tipo de mudança que ninguém percebe sozinha. Entrar/sair de
`A DEFINIR` fica de fora por ora — com 74 empresas hoje sem faixa, viraria ruído.

**Origem:** uso real do fechamento de agosto/2026, no mesmo dia do deploy da Fase 137.

**Estado medido em produção (2026-09-03, agosto fechado):** 201 empresas no snapshot — 127 `ok`,
69 `sem_integracao`, 4 `sem_tabela`, 1 `sem_faturamento`. 15 grupos, todos conferidos por `SELECT`
(0 divergências entre a soma das empresas e o snapshot do grupo). Apenas 1 empresa cai na faixa
aberta.

**Dependências:** Fase 137 completa e em produção. Reusa `FechamentoFaixaResolver`,
`FechamentoRollupService`, `FechamentoSnapshotWriter` e o `FechamentoController` — nenhum deles é
reescrito.

**Plans:** 6 plans em 3 waves (wave 1: 01 e 02 em paralelo · wave 2: 03 e 04 em paralelo · wave 3:
05 e 06 em paralelo)

Plans:
- [x] 138-01-PLAN.md — Tabela do grupo: migration, model e precedência no `FechamentoFaixaResolver`
- [x] 138-02-PLAN.md — Idempotência do aviso (`notificado_em`/`notificado_faixa_ordem`), categoria `faixa_alterada` e rótulo na tela de notificações
- [x] 138-03-PLAN.md — `fechamento:consolidar-mes` classifica o grupo pela tabela do grupo, com âncora como fallback rastreável
- [x] 138-04-PLAN.md — Props da tela de fechamento: tabela do grupo e herança visível nos ramos aberto e fechado
- [x] 138-05-PLAN.md — `FechamentoFaixaNotifier`: aviso agregado de subida e queda de faixa, com trava contra o "Refazer"
- [x] 138-06-PLAN.md — CRUD e tela da tabela do grupo (checkpoint humano de conferência visual)

**Fase 138 concluída em 2026-09-04** — 6/6 planos entregues, checkpoint humano do plano 06 aprovado
pelo usuário ("Aprovado") após conferência em produção (deploy feito pelo orquestrador; o ambiente
local tem 0 grupos cadastrados e não permitiria abrir o accordion de grupo). D-01 fechado: grupo pode
ter tabela própria cadastrável pela tela, com precedência grupo → empresa → serviço, e a herança da
tabela da empresa que mais faturou no mês agora é visível na tela (antes era silenciosa). D-02/D-03
fechados: aviso de mudança de faixa (subida e queda) com trava de idempotência sequencial + lock de
concorrência. Gate final `Phase122|Phase136|Phase137|Phase138`: 276 testes / 1452 asserções / 0
falhas. Ver `138-06-SUMMARY.md` para a observação operacional sobre o primeiro "Refazer" de
agosto/2026 disparar o aviso inicial (efeito de primeira carga, esperado).

---

### Phase 139: Redesenho da tela de Fechamento

**Goal:** Tornar a tela de fechamento simples e intuitiva. O usuário usou o resultado das Fases 137 e
138 em produção e disse: "as coisas estão funcionando, mas a UI/UX está difícil de entender — tem
que ser mais simples e intuitivo". Ele produziu um handoff de design completo
(`design_handoff_fechamento/`) e pediu que fosse desenvolvido.

O redesenho reduz a tela a três perguntas: **quanto vamos receber**, **quem subiu de faixa**, e
**como esse valor foi calculado** para cada empresa.

**Decisões do usuário (2026-09-04), detalhadas em `139-CONTEXT.md`:**

| widget | decisão |
|---|---|
| Serviços contratados | manter (barra empilhada no lugar do donut) |
| Tipo de cobrança | remover |
| Distribuição de faixas | remover |
| Total consolidado | reduzir a "Total a receber" |
| Subiram de faixa | criar, em destaque |

O Total consolidado encolhe porque o sistema **não sabe se o cliente pagou**: na captura de
produção, "Recebido", "Inadimplente" e "A receber" estão os três vazios ("0 pagadores com dados") —
um terço da tela ocupado por informação que não existe.

**Fidelidade visual (D-02):** estrutura, hierarquia, widgets e comportamento seguem o design com
fidelidade; **cores e tipografia continuam as do ECF Admin** (tokens `ecf-*`). A paleta do handoff é
próxima mas não idêntica à do projeto, e adotá-la faria esta tela destoar de todas as outras. A
fonte mono para números foi oferecida ao usuário e recusada.

**O risco da fase (D-04):** quatro dados que o design pede **não existem hoje** — `faixa_ordem_anterior`
exposta como prop, a mensalidade da faixa anterior (base do ganho do upgrade), os totais do widget
"Total a receber" (incluindo mês anterior, variação e faturamento gerado), e a reconstrução da ordem
anterior no ramo congelado, onde o snapshot guarda só `evolucao`.

**Regressão zero (D-05):** `Financeiro.jsx` tem ~1300 linhas e concentra trabalho das Fases 137/138
verificado em produção — estados de ausência distintos, composição ML+Shopee, "a partir de" na faixa
sem teto, a tabela de grupo com a frase de herança, o estado da competência com fechar/refazer e a
confirmação de sucesso, e a trava que impede a palavra "acumulado" de voltar.

**Achado do planejamento (2026-09-04):** `TotalConsolidado` filtra por `cobranca_mensal_grupo`, prop
que o backend **nunca emitiu** — é por isso que o widget mostra "0 pagadores com dados" e os três
valores vazios na captura de produção. O total novo é somado no backend, sobre as mesmas linhas que a
tela lista.

**Plans:** 6 planos em 6 waves (sequenciais: os dois primeiros mexem em `AdminController.php`, os
três seguintes em `Financeiro.jsx` — sem sobreposição de arquivo entre planos de waves diferentes não
haveria como paralelizar sem conflito). Backend primeiro: uma tela redesenhada esperando dado
inexistente é o pior ponto de partida possível.

Plans:
- [x] 139-01-PLAN.md — Faixa anterior e ganho do upgrade nos quatro caminhos de montagem de linha (`FechamentoComparativoService`)
- [x] 139-02-PLAN.md — Prop `totais`: total a receber, mês passado, variação, faturamento gerado e os números dos upgrades
- [x] 139-03-PLAN.md — Cabeçalho e os três widgets do topo (Total a receber, Subiram de faixa, Serviços contratados)
- [x] 139-04-PLAN.md — Lista em quatro colunas com barra de progresso, filtros em chips e estado vazio
- [x] 139-05-PLAN.md — Área expandida em três passos e tabela progressiva com a faixa atual destacada
- [ ] 139-06-PLAN.md — Trava de contrato da UI, build, gate e conferência humana em produção (checkpoint)
- [x] 139-07-PLAN.md — Remove o marcador de "recebido" dos seis pontos onde vivia (tela, controller, rota, e-mail mensal, dois PDFs) — plano emergente, decisão do usuário em 2026-09-04 após medição em produção (usado 1 vez, nunca mais)

---

### Phase 140: Extrair as tabelas progressivas dos contratos do Clicksign

**Goal:** Parar de cobrar por tabela assumida. Hoje 127 empresas têm mensalidade calculada por uma
tabela que o sistema herdou do serviço, sem contrato nem cadastro que a confirme — R$ 460.500/mês sem
lastro. O cadastro manual existe desde a Fase 137 e **nunca foi usado**; digitar ~124 tabelas à mão é
o caminho óbvio e o mais caro.

Esta fase lê os contratos que já existem no Clicksign e transforma cada um numa proposta conferível.

**Origem:** o usuário levantou a possibilidade ("isso pode consultar no Clicksign") e autorizou a
investigação, feita em 2026-09-08 contra a conta de produção. Os achados estão medidos no
`140-CONTEXT.md` e não devem ser re-medidos.

**O que a investigação estabeleceu:**

| achado | consequência |
|---|---|
| 429 envelopes, **123** de gestão de ADS | o material existe |
| PDFs baixáveis, com **CNPJ e razão social** no texto | a leitura é viável |
| **nem todo contrato tem tabela** — 6 de 11 são valor fixo | o sistema classifica em faixa quem não deveria |
| **DESK DESIGN tem tabela de 12 faixas** começando em R$ 2.250 | a "tabela fora do padrão" existe e já custa dinheiro |
| só **10 de 201** empresas têm CNPJ | a chave exata de casamento não existe |
| casamento por nome: **9 de 14**, com falsos positivos plausíveis | escrita automática está fora |

O caso DESK DESIGN é o argumento da fase: o contrato dela diz R$ 2.250 abaixo de 100 mil, e o sistema
cobra R$ 3.000 por assumir a tabela padrão.

**Estratégia acordada (D-06):** começar pelo **comando de leitura que gera só um relatório** — sem
tela, sem escrita. O usuário confere a qualidade real do casamento nas 123 linhas e decide se vale
construir a tela de conferência e a escrita auditada. Só a segunda etapa grava, e sempre com
confirmação humana do vínculo.

**Decisão de implementação em aberto:** não há extrator de PDF no servidor (`pdftotext`, `PyPDF2` e
`pymupdf` ausentes) e o `dompdf` do projeto só gera. Escolher entre adicionar uma biblioteca de
leitura ou instalar poppler.

**Requisitos (locais desta fase, não existem no REQUIREMENTS raiz):**

| ID | O que é |
|---|---|
| TAB-01 | Listar e baixar os contratos do Clicksign dentro da validade do link (D-01, D-02) — ✅ concluído em 140-01 (2026-09-08) |
| TAB-02 | Ler o texto do contrato mesmo quando o arquivo vier como ZIP (D-02) — ✅ concluído em 140-02 (2026-09-08) |
| TAB-03 | Reconhecer as duas notações de tabela progressiva (D-04) — ✅ concluído em 140-02 (2026-09-08) |
| TAB-04 | Distinguir contrato com tabela de contrato de valor fixo (D-03) — ✅ concluído em 140-02 (2026-09-08) |
| TAB-05 | Palpite de empresa com grau de confiança honesto, sem falso positivo com cara de acerto (D-05) |
| TAB-06 | Relatório conferível, fora do repositório (D-06) |
| TAB-07 | Guardar o que foi lido, para conferir depois sem re-ler (D-06) — ✅ concluído em 140-04 (2026-09-09) |
| TAB-08 | Tela de conferência sem jargão e sem falsa certeza (D-06) |
| TAB-09 | Escrita só depois de confirmação humana, gravando faixas + CNPJ + razão social (D-05, D-06) |

**Plans:** 5 plans em 4 waves. ⚠️ Corte duro entre relatório e o resto: as waves 3 e 4 só começam
depois de o usuário aprovar a rodada real do relatório (checkpoint do 140-03).

Plans:
- [x] 140-01-PLAN.md — wave 1 — ler o acervo do Clicksign: listar envelopes, filtrar gestão de ADS, baixar o arquivo dentro dos 299s
- [x] 140-02-PLAN.md — wave 1 — ler o texto do contrato (PDF e ZIP) e extrair tabela, valor fixo, CNPJ e razão social
- [ ] 140-03-PLAN.md — wave 2 — palpite de empresa com confiança honesta + comando `clicksign:extrair-tabelas` (só relatório) + **checkpoint: rodada real e decisão de continuar** — ⚠️ a rodada real completa já rodou em produção (85 contratos, 49 tabelas, 29 valor fixo, 0 casamentos com segurança) e o checkpoint foi respondido pelo usuário no prompt do 140-04, mas falta `140-03-SUMMARY.md` formal — pendência do coordenador
- [x] 140-04-PLAN.md — wave 3 — guardar as propostas lidas (`--gravar`), sem tocar em cobrança
- [ ] 140-05-PLAN.md — wave 4 — tela de conferência + confirmação auditada que grava a tabela e completa o cadastro

---

### Phase 141: A tabela progressiva passa a ser da empresa e do grupo

**Goal:** Corrigir a premissa que atravessa as Fases 137, 138 e 139. O usuário, olhando o fechamento
em produção em 2026-09-09, estabeleceu que **a tabela progressiva é da empresa ou do grupo — nunca do
serviço** — e que o faturamento das plataformas é **somado** para achar **uma única** faixa.

Não é ajuste de tela: muda como a mensalidade é calculada.

**O caso que abriu a fase:** BARAOSHOP VARIEDADES faturou R$ 488.262,90 em agosto, caiu na faixa 1,
cujo valor é R$ 3.000 — e a tela cobra **R$ 5.500**. A regra atual é *"faixa + soma dos contratos
mensais"*, então soma R$ 3.000 da faixa com R$ 2.500 do contrato de Shopee. Pela regra nova, soma-se
**faturamento**, não **mensalidade**: as duas plataformas somam, dão uma faixa, e é ela que se cobra.

**As regras, como o usuário as definiu (detalhe em `141-CONTEXT.md`):**

| regra | consequência |
|---|---|
| tabela é da empresa/grupo | `servico_faixas_faturamento` deixa de ser régua aplicável |
| faturamento das plataformas soma | ML + Shopee viram um número só para achar a faixa |
| Mentoria **não** entra na soma | serviço sem tabela não contribui faturamento |
| mensalidade = valor da faixa | a soma de mensalidades deixa de existir |
| sem tabela → valor do contrato | é o caso dos 29 contratos de valor fixo já identificados |

**O problema de transição:** hoje **127 das 201 empresas** são classificadas pela tabela do serviço, e
**nenhuma** tem tabela própria cadastrada. Aplicar a regra sem mais nada esvazia o fechamento.

**O caminho existe por causa da Fase 140:** a leitura dos contratos do Clicksign produziu 85
propostas em produção — **49 com tabela** e 29 de valor fixo — e a tela de conferência já está
construída. As 49 viram tabelas de empresa, que é a única forma que passa a valer. As 18 réguas
distintas medidas ali confirmam que a tabela é mesmo por empresa: duas divergem já na terceira faixa,
e quem fatura R$ 2,5 milhões paga R$ 6.000 numa e R$ 7.500 na outra.

**Fora de escopo:** a tela de cadastro (mostrar as faixas, máscara de dinheiro, mover para a página
de contrato da empresa, fechamento só leitura) é fase própria, a pedido do usuário.

**Requirements:** [TPE-01, TPE-02, TPE-03, TPE-04, TPE-05, TPE-06, TPE-07, TPE-08] — definidos nesta
fase (o `REQUIREMENTS.md` da raiz parou na v17.0):

| ID | Requisito |
|----|-----------|
| TPE-01 | Só entra na soma o faturamento de plataforma em que a empresa tem serviço contratado que é cobrado por tabela progressiva (Mentoria fica fora) |
| TPE-02 | A mensalidade é o valor da faixa, e só isso — a soma de mensalidades deixa de existir |
| TPE-03 | Empresa sem tabela progressiva cobra o valor fixo do contrato, em estado visível na tela |
| TPE-04 | A tabela aplicável é da empresa ou do grupo; a do serviço deixa de ser régua |
| TPE-05 | A tabela do serviço vira modelo de partida do cadastro e semente da materialização das tabelas presumidas |
| TPE-06 | Toda tabela de empresa carrega procedência: cadastro manual, contrato assinado ou presumida do serviço — presumida nunca conta como confirmada |
| TPE-07 | A virada é comandada por flag desligada por padrão, com comparação ANTES × DEPOIS em produção e gate humano |
| TPE-08 | Competência já congelada não muda quando a regra vira (D-11 da Fase 137) |

**Plans:** 7 plans em 4 waves

Plans:
- [x] 141-01-PLAN.md — Elegibilidade de plataforma: `servicos.usa_tabela_progressiva` + rollup que só soma plataforma contratada (wave 1)
- [x] 141-02-PLAN.md — Flag de corte `fechamento_tabela_por_empresa_ativa` + `CobrancaCalculator::mensalidade()` + estado `valor_fixo` (wave 1)
- [x] 141-03-PLAN.md — Transição: procedência da tabela da empresa + comando `fechamento:materializar-tabelas` (wave 1)
- [x] 141-04-PLAN.md — Virada da régua no motor: resolver sem a tabela do serviço + consolidação pela regra nova (wave 2)
- [x] 141-05-PLAN.md — `fechamento:comparar-mensalidade`: o delta ANTES × DEPOIS que precede a virada (wave 3)
- [x] 141-06-PLAN.md — Os cinco ramos da tela de Fechamento + remoção do paliativo da composição (wave 3)
- [x] 141-07-PLAN.md — Virada em produção com gate humano e registro dos números reais (wave 4)

**Fase 141 FECHADA em 2026-09-10.** Virada real em produção: 168 empresas ganharam tabela própria
presumida (materialização), delta ANTES×DEPOIS conferido pelo usuário (total a receber caiu de
R$ 2.486.700,91 para R$ 736.450,97 — queda validada como correção, não regressão), chave
`fechamento_tabela_por_empresa_ativa` ligada, e agosto/2026 reconsolidado sob a regra nova a pedido
explícito do usuário. Números completos e procedimento de rollback em
`.planning/learnings/fechamento-tabela-por-empresa.md`. Pendências que sobrevivem à fase: as 168
tabelas presumidas seguem sem conferência contra o contrato real (tela da Fase 140), 32 empresas
sem tabela (maioria cadastro de teste) e 3 contratos com valor de R$ 250.000 reportados ao usuário
como provável erro de cadastro.

---

### Phase 142: O cadastro da tabela progressiva vai para o contrato

**Goal:** Tornar utilizável o cadastro da tabela progressiva. O usuário pediu a fase com estas
palavras: *"a parte do cadastro de tabela progressiva pelo sistema deve melhorar bastante, acho que
deve haver uma fase só para isso"*.

**Os quatro pedidos** (detalhe em `142-CONTEXT.md`):

| pedido | hoje |
|---|---|
| mostrar as faixas da tabela própria | mostra só a frase "Tabela própria desta empresa" |
| máscara de dinheiro nos campos | valores crus (`499999.99`), fáceis de errar por um zero |
| cadastro dentro de `/administrativo/contratos/empresa/{id}`, em página exclusiva | vive dentro do fechamento |
| fechamento continua mostrando tabela e faixa, sem cadastrar/editar | cadastra e edita por ali |

**A dívida que esta fase paga:** o "só mostra a frase" foi decisão consciente do plano `137-09` — o
backend não expunha as linhas da tabela própria, e o executor preferiu abrir o formulário em branco
com aviso a preenchê-lo com valores adivinhados que poderiam sobrescrever preço real. O custo aceito
era "quem edita redigita tudo", e na época havia **zero** tabelas próprias.

**Depois da Fase 141 são 169** — 168 delas marcadas como presumidas, todas esperando conferência
contra o contrato real. O que era dívida barata virou o gargalo do trabalho que vem pela frente.

**Uma decisão para o planejamento:** a tela de conferência das propostas lidas do Clicksign
(`/administrativo/contratos/tabelas`, Fase 140) já vive no módulo de contratos e faz coisa parecida.
Duas telas que cadastram tabela em lugares diferentes é como este problema começou — decidir se
convivem, se uma leva à outra, ou se viram a mesma coisa.

**Plans:** 2/4 plans executed

Plans:
- [x] 142-01-PLAN.md — expõe as LINHAS da tabela própria nas props e cria a porta única de escrita (`GravarTabelaEmpresaService`), com trilha de auditoria e a trava de "tabela copiada nunca sobrescreve tabela conferida". Registra a decisão de projeto sobre conviver com a tela da Fase 140.
- [x] 142-02-PLAN.md — rotas, controller e autorização da página exclusiva dentro do módulo de contratos, mais o botão em `/administrativo/contratos/empresa/{id}`.
- [x] 142-03-PLAN.md — máscara de dinheiro (react-imask), grade da tabela virando componente compartilhado e a página `Admin/TabelaEmpresa.jsx` com o formulário abrindo preenchido com o que está gravado.
- [ ] 142-04-PLAN.md — fechamento passa a mostrar as faixas da tabela própria e deixa de cadastrar/editar; retarget das travas antigas e gate ampliado com checkpoint humano.

---
## Milestone v23.0 — Fluxo de Entrada de Novas Empresas (Fases 150-156)

**Plano canônico:** `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` (transcrição fiel do PDF *Fluxo de Entrada de Novas Empresas*, ECF Consultoria, 01/09/2026, + levantamento técnico contra `origin/main` `695711f5`) · **Requirements:** `.planning/REQUIREMENTS-v23.md` · **Pesquisa:** não executada — decisão D0, o PDF já é a especificação funcional e o cruzamento com o código foi feito na abertura.

**Goal:** Um único cadastro de empresa atravessa HubSpot → Comercial → Administrativo → Coordenação → Onboarding → Em operação, com etapa e status explícitos, checklist obrigatório por etapa, travas que impedem avanço incompleto, e histórico datado por evento para medir SLA.

**Decisões travadas (usuário, 2026-09-01):** D0 sem pesquisa de domínio nesta abertura · D1 os 9 status moram em `companies.etapa`, coluna **nova** e aditiva — `companies.status` fica exatamente como está (string livre, default `'ativo'`, semântica de contrato ativo/inativo) · D2 "Em operação" vira a etapa 9, atingida só depois do onboarding concluído; o cálculo hoje derivado em `CompanyController.php:179` (`tem analista OU tem estrategista`) sobrevive como fallback para quem não tiver etapa — a aba "Empresas" de `/companies` passa a listar coisa diferente, de propósito · D3 o checklist gera e marca sozinho o que o sistema já sabe fazer (link ADMA, link/conexão ECF via `OnboardingLinkService::paraEmpresa`, Grant da consultoria); o resto (grupo de WhatsApp, e-mail colaborador, envio da mensagem) é marcação manual com registro de quem e quando · D4 o destino do §6 é resolvido por `company_marketplaces` (N:N da v13.0), não "Mercado Livre" literal — hoje produz o mesmo resultado visível (126 meli / 0 shopee / 0 amazon) · D5 **HubSpot e Clicksign se integram, nunca se reconstroem** — `HubspotWebhookController`/`HubspotDealHandoffService` (§1) e o grupo Contrato inteiro entregue pelas Fases 126/127/129/132 + a tela da Fase 131 (§3) são consumidos, nunca recriados · D6 pendência é sinalizador paralelo à etapa, nunca status principal — precedente direto: o flag `problema_desconsidera_meta` do Painel Polos, que tem um único ponto de decisão (`PolosController::desconsideraDaMeta()`) e nunca deixa a leitura direta do flag se espalhar (`.planning/learnings/painel-polos-status-e-meta.md` §1).

**Reuso já identificado (não construir do zero):** `HubspotWebhookController` + `HubspotDealHandoffService` + `HubspotCompanyMatcher` (§1) · Clicksign das Fases 126/127/129/132 (`ClicksignClient`, `ContratoClicksignService`, `ClicksignWebhookController`) e a tela administrativa da Fase 131 (grupo Contrato do §3) · `DefinicaoOnboarding` (VERSAO 17) + `OnboardingEngineService` (§9) · os dois responsáveis (analista/estrategista) já no schema, entregues pela Fase 135 · `spatie/laravel-activitylog` já aplicado a `Company` (base do §12) · `companies.ml_link_url`/`ml_link_generated_at`, `OnboardingLinkService::paraEmpresa`, `company_grants`/`SyncGrantsFromSftp` (peças soltas do §5 a amarrar num checklist, não recriar).

**Risco central:** `companies.etapa` nasce ao lado de `companies.status`, que já significa outra coisa (string livre, default `'ativo'`, migration `2026_05_25_100001` com backfill em produção) sobre uma tabela com ~500 registros reais. A Fase 150 mexe em **migration sobre tabela com dado de produção** e por isso é fase GSD obrigatória (baseline de testes + `VERIFICATION.md`) pelo próprio `CLAUDE.md` — ver o alerta na própria fase. `em_operacao` hoje é lido ao vivo pela aba "Empresas" de `/companies` em produção; o backfill da Fase 150 precisa preservar 100% do que essa tela mostra hoje antes de a Fase 155 tratar o cálculo derivado como fallback secundário.

**Ordem de construção:** a máquina de estados (137) é fundação — nada mais tem onde gravar etapa sem ela. Comercial (138) é o primeiro ponto de entrada real na etapa. Administrativo (139) depende de haver empresa chegando em `Aguardando Administrativo` (138) e do grupo Contrato já entregue pela v22.0 (D5). Comunicação (140) monta a mensagem com dado que o checklist administrativo já gera (139). Distribuição+Responsáveis (141) só existe depois do Administrativo concluir e mover a empresa (139). Onboarding (142) depende dos responsáveis estarem definidos (141). Histórico (143) fecha por último porque precisa que todo evento das fases 150-142 já esteja acontecendo para ter o que listar na timeline.

### Phase 143: O grupo de cobrança acima dos subgrupos

**Goal:** Fazer o fechamento cobrar pelo grupo econômico inteiro, sem quebrar o NPS. Hoje alguns
`company_groups` são, na prática, **subgrupos** — e a cobrança sai fatiada.

**A descoberta que reenquadra a fase:** o pessoal não errou, o sistema empurrou para lá. O link de
NPS de grupo é `unique(company_group_id, template_id, month_reference)` — **um por grupo, por
modelo, por mês** — e a cobertura já exclui empresa cuidada por outra pessoa. Para mandar NPS a dois
subgrupos no mesmo mês, a única saída era cadastrá-los como grupos separados.

**O caso concreto, medido em produção (2026-09-14):** o grupo MPozenato de verdade é
MPozenato + DRossi + Gran Belo + Lyam — 10 empresas, R$ 12.679.411,83 em agosto, hoje cobradas em
**quatro** mensalidades que somam **R$ 33.500**. Como um grupo só, cai para **R$ 21.000** (tabela do
MPozenato, vinda de contrato) ou **R$ 12.000** (as presumidas). A correção **derruba** a cobrança em
R$ 12.500 a R$ 21.500 por mês — direção que o usuário conhece e aceita.

**A forma (D-03):** `parent_id` em `company_groups`. Os grupos de hoje **ficam com os mesmos ids** e
viram subgrupos; o fechamento passa a agregar pela **raiz**. NPS não sente nada. Recusada a
alternativa de grupos próprios do administrativo — a Fase 137 existiu para acabar com as duas listas.

**Falta construir:** a tabela do grupo (`grupo_faixas_faturamento` existe no schema, está **zerada**
e nunca teve UI) pelos dois caminhos que o usuário definiu — cadastro no administrativo e
identificação do contrato no Clicksign com conferência humana na tela de match.

**Escala desconhecida por dado:** 145 das 203 empresas estão sem CNPJ, então não dá para achar os
outros casos automaticamente. A montagem é curadoria humana — a tela precisa resolver sozinha.

Plans: a planejar

---

### Phase 150: Máquina de estados — os 9 status de `companies.etapa` (v23.0) — ✅ COMPLETA (11/11 — G1+G2+G3+G4+G5+G6 fechados)

**Goal:** Cada empresa carrega uma etapa própria entre os 9 status do §10, gravada e transicionada por um único serviço central, com pendência declarável em paralelo sem nunca sobrescrever a etapa — e as ~500 empresas já cadastradas migram sem quebrar o que a tela "Empresas" de `/companies` mostra hoje.
**Requirements**: ETAPA-01, ETAPA-02, ETAPA-03, ETAPA-04, ETAPA-05, ETAPA-06
**Depends on:** Nada (fundação)

> ⚠️ **Migration em tabela com dado de produção — fase GSD obrigatória.** `ETAPA-01` cria `companies.etapa` e `ETAPA-02` faz backfill sobre a tabela `companies` com ~500 registros reais em produção. Pelo `CLAUDE.md` (§ "GSD obrigatório — `/gsd-plan-phase` → `/gsd-execute-phase`"), esta fase **não** é trabalho direto: exige baseline de testes antes da migration e `VERIFICATION.md` ao final. Ler `.planning/learnings/painel-polos-status-e-meta.md` antes de desenhar o ponto único de decisão de transição (ETAPA-03) — é o mesmo padrão que já existe para `problema_desconsidera_meta`.

**Success Criteria** (o que deve ser VERDADE):

  1. Toda empresa tem uma etapa entre as 9 do §10 visível no cadastro: as ~500 já cadastradas recebem etapa no backfill, quem já estava em operação (analista OU estrategista, cálculo atual) entra direto na etapa 9, e quem ficar sem etapa continua resolvido pelo cálculo antigo como fallback — a tela "Empresas" de `/companies` não perde nenhuma empresa que mostra hoje (ETAPA-01, ETAPA-02, D2)
  2. Não existe outro ponto do código que grave `companies.etapa` além de um único serviço de transição — toda mudança de etapa, de qualquer controller ou job, passa por ele (ETAPA-03)
  3. Uma empresa pode ter pendência marcada (ex.: "Contrato não assinado") permanecendo na mesma etapa — marcar ou desmarcar pendência nunca move a etapa (ETAPA-04, D6)
  4. Pelo menos uma listagem existente pode ser filtrada por etapa e, separadamente, por "com pendência" (ETAPA-05)
  5. Tentar avançar uma etapa sem os requisitos cumpridos é recusado com uma mensagem que nomeia o requisito faltante (ex.: "contrato não assinado"), nunca um erro genérico (ETAPA-06)

**Plans:** 11/11 plans complete

Plans:
**Wave 1**

- [x] 150-01-PLAN.md — Ambiente de teste Inertia + baseline verde registrada antes da migration [wave 0]
- [x] 150-02-PLAN.md — ETAPA-01: coluna `companies.etapa` (nullable, sem default) + as 9 constantes no model `Company` [wave 1]

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 150-03-PLAN.md — ETAPA-06 + ETAPA-03: `EtapaTransicaoService` (par puro/efeito) + tabela `company_etapa_transicoes` (D-16 = tabela dedicada) [wave 2]
- [x] 150-04-PLAN.md — ETAPA-04: pendência paralela declarada em 4 colunas de `companies` (D-18 = colunas) + ponto único de leitura [wave 2]

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 150-05-PLAN.md — ETAPA-02: comando `etapa:backfill` em dois baldes + contagens por reconsulta ao banco [wave 3]
- [x] 150-06-PLAN.md — ETAPA-03: regressão de segurança do ponto único de escrita + varredura estática de `app/` [wave 3]

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 150-07-PLAN.md — ETAPA-05: filtros server-side por etapa e por pendência em `/companies` + checkpoint humano [wave 4]

**Wave 5 — gap closure** *(achados CRITICAL de `150-VERIFICATION.md` e `150-REVIEW.md`)*

- [x] 150-08-PLAN.md — G1: fecha os 3 bypasses do gate estático do ponto único de escrita de `companies.etapa` [wave 5]
- [x] 150-09-PLAN.md — G2: histórico de etapa sobrevive à exclusão permanente do ator (`ON DELETE SET NULL`) [wave 5]

**Wave 6 — gap closure** *(blocked on Wave 5 completion)*

- [x] 150-10-PLAN.md — G4 + G5 + G6: `transicionar()` decide sobre a linha travada · backfill chaveado por id · frontmatter de 150-01 [wave 6]
- [x] 150-11-PLAN.md — G3: filtros de `/companies` deixam de se apagar entre si + checkpoint humano [wave 6]

### Phase 151: Área Comercial conectada à etapa + reorganização em Contrato e Entrada (v23.0) — ✅ COMPLETA (9/9)

**Goal:** A venda marcada GANHA no HubSpot chega na Área Comercial já na etapa "Aguardando Administrativo", sem cadastro manual, e o Comercial passa a ser a única casa da gestão de entrada — com as listagens **Contrato** e **Entrada** exibindo os 8 campos mínimos do §2, e a empresa saindo de lá só quando entra em "Aguardando Distribuição".
**Requirements**: COMERC-01, COMERC-02, COMERC-03
**Depends on:** Fase 150
**UI hint:** yes

> 🔁 **Reorganização decidida em 2026-09-02, depois deste ROADMAP escrito.** O Administrativo é absorvido pela Área Comercial em dois módulos, **Contrato** e **Entrada**. Enunciado na letra, mapa arquivo-a-arquivo e armadilhas medidas em `.planning/seeds/151-153-admin-no-comercial-dois-modulos-260902.md`; decisões travadas em `151-CONTEXT.md`. Esta fase entrega **a casca** — navegação, as duas listagens e o nascimento na etapa 1; os itens de checklist dentro dos módulos são a Fase 152.
>
> ⚠️ **Não existe aba "Empresas Ganhas".** Contrato e Entrada **são** as listagens (D-04), separadas por **processo pendente, não por etapa** (D-05): a mesma empresa pode estar nas duas ao mesmo tempo, e por isso separar as duas listas por `companies.etapa` está **proibido**.

**Success Criteria** (o que deve ser VERDADE):

  1. Uma venda marcada GANHA no HubSpot chega na Área Comercial já na etapa "Aguardando Administrativo", sem nenhum cadastro manual adicional — e o cadastro manual do próprio Comercial (`ComercialController::store`) nasce na mesma etapa, pelo mesmo `EtapaTransicaoService` (COMERC-01, D-13)
  2. As listagens **Contrato** e **Entrada** mostram, cada uma, por empresa, os 8 campos mínimos do §2 — nome, serviço contratado, setor/segmento (setor ECF, D-12), origem da venda, responsável comercial e data da venda (buscados no HubSpot, D-08/D-09), informações principais do cliente, status do contrato e existência de pendências, demais dados comerciais do HubSpot (COMERC-02)
  3. Pendência do fluxo (a declarada na Fase 150) e pendências do cadastro (as **7** de `PendenciasComerciaisService`, contadas no serviço real em 2026-09-02 — o "8" do §2 estava errado) aparecem em colunas separadas e nomeadas — nunca somadas num número só (COMERC-02, D-11)
  4. Uma empresa some da listagem **Entrada** só no instante em que entra na etapa "Aguardando Distribuição" — continua visível enquanto está em "Administrativo Concluído" (COMERC-03, D-07). **A listagem Contrato não tem corte por etapa** — ver a nota abaixo
  5. `Administrativo › Contratos` vira o módulo **Contrato** dentro do Comercial preservando a permission própria `admin.contratos` (o `ContratoAdminPermissaoTest` da Fase 131 segue verde), `Administrativo › Empresas` sai do menu sem que rota/controller sejam apagados, e o módulo **Entrada** existe como casca com chave de permissão própria no catálogo (D-15, D-16)

> ⚠️ **A listagem Contrato NÃO tem corte por etapa — divergência deliberada do SC#4** (decisão do
> usuário em 2026-09-02, tensão levantada pelo `gsd-plan-checker`). O universo da tela da Fase 131
> é `active = true` **E** ter `ContratoServico` ativo cujo serviço exige contrato
> (`ContratoAdminController::index()`, linhas 66-70) — `companies.etapa` **não entra na query**.
> Aplicar ali o corte da etapa 5 removeria da visão do Administrativo toda empresa já em operação
> com contrato ativo (renovação, recontratação, cancelamento), quebrando uma tela que já está em
> produção. **Contrato é ferramenta administrativa contínua: o critério de saída dela é o estado do
> contrato, não a etapa.** O corte da etapa 5 (D-07/COMERC-03) vale só para a listagem **Entrada**.

**Plans:** 9/9 plans complete — Fase 151 COMPLETA. Deploy NÃO autorizado — ver `151-09-SUMMARY.md` § "Pendências obrigatórias para a VPS" antes de subir.

Plans:
**Wave 0** *(medições antes de qualquer código — gate do `CLAUDE.md`)*

- [x] 151-01-PLAN.md — Baseline de testes registrada antes da migration das 3 colunas (D-09) [wave 0]
- [x] 151-02-PLAN.md — Checkpoint humano: nome interno da property de owner medido na conta HubSpot real + escopo `crm.objects.owners.read` (D-08) [wave 0] — **⚠️ NÃO medido de fato**: credencial ausente no `.env` local (401) impediu a medição real; `hubspot_owner_id` foi ASSUMIDO com autorização explícita do usuário, e o escopo `crm.objects.owners.read` ficou `NÃO CONFIRMADO`. Ambas viram pendência obrigatória do plano 151-09, a medir na VPS. Ver `138-HUBSPOT-MEDICOES.md` e `151-02-SUMMARY.md`

**Wave 1** *(blocked on Wave 0 completion)*

- [x] 151-03-PLAN.md — COMERC-02: colunas `hubspot_owner_id`/`hubspot_owner_nome`/`data_venda` + property no config + `fetchOwner()` + `HubspotOwnerResolver` (D-08/D-09/D-12) [wave 1]

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 151-04-PLAN.md — COMERC-02: webhook persiste owner e data da venda nos dois ramos + comando de retroativo com dry-run (D-10/D-14) [wave 2]
- [x] 151-05-PLAN.md — COMERC-02 + COMERC-03: módulo Entrada — permission própria, módulo, rota, `ComercialEntradaController` com os 8 campos e as duas pendências separadas (D-02/D-04/D-05/D-06/D-07/D-11/D-12/D-15) [wave 2]
- [x] 151-06-PLAN.md — COMERC-02: listagem Contrato ganha os 8 campos do §2 e a etapa, sem mudar a query do universo (D-03/D-06/D-11) [wave 2]

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 151-07-PLAN.md — COMERC-01: ator de sistema "Sistema HubSpot" (D-17) + nascimento na etapa 1 nas duas portas, webhook e cadastro manual (D-13/D-14) [wave 3]
- [x] 151-08-PLAN.md — COMERC-02: navegação reorganizada — `Entrada.jsx`, Contrato movido para o Comercial e `admin.empresas` fora do menu (D-01/D-02/D-15/D-16) [wave 3]

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 151-09-PLAN.md — Checkpoints humanos finais: conta de sistema não-logável em local e VPS, render das duas listagens, regressão contra a baseline (D-01/D-15/D-16/D-17) [wave 4] — os dois checkpoints humanos foram aprovados; suíte reexecutada nesta sessão (252 tests/921 assertions, OK), sem regressão contra a baseline do 151-01. Ver `151-09-SUMMARY.md`

### Phase 152: Checklist administrativo + trava de finalização (v23.0) — ✅ COMPLETA (10/10, gate humano aprovado)

**Goal:** Dentro do cadastro da empresa existe um checklist com os 9 itens do §5 — o que o sistema já sabe gerar se marca sozinho, o grupo Contrato só reflete o Clicksign já entregue, e o botão FINALIZAR ENTRADA ADMINISTRATIVA só libera quando tudo está pronto, movendo a empresa para o marketplace do contrato.
**Requirements**: ADMIN-01, ADMIN-02, ADMIN-03, ADMIN-04, ADMIN-05, ADMIN-06
**Depends on:** Fases 150, 138
**UI hint:** yes

> 🔒 **D5 — nada aqui reconstrói assinatura.** O grupo Contrato **lê** o estado do envelope Clicksign entregue pelas Fases 126/127/129/132 (`ContratoClicksignService`, `ClicksignWebhookController`) e pela tela da Fase 131. Nenhum plano desta fase cria cliente HTTP de assinatura, webhook de contrato novo, ou lógica paralela de "contrato assinado" — só leitura do estado que já existe.

> ✅ **Contagem FECHADA em 2026-09-09 — valem os 9 itens do §5** (D-01 do `152-CONTEXT.md`). Os 3 excluídos do §3 (`acompanhar a assinatura`, `gerar mensagem de boas-vindas`, `inserir todos os links necessários`) são passos intermediários que desembocam em item já controlado. Para serviço isento de contrato o checklist nasce com **6** itens — o grupo Contrato não existe (D-07), em vez de existir marcado "não aplicável" (proibido pela D-02).

**Success Criteria** (o que deve ser VERDADE):

  1. Os módulos **Contrato** e **Entrada** da Área Comercial mostram o checklist da empresa, cada item com estado Pendente/Concluído — os 4 itens de Contrato mudam sozinhos conforme o envelope Clicksign avança (revisado → enviado → assinado), sem nenhuma marcação manual nesse grupo (ADMIN-01, ADMIN-02)
  2. Clicar para gerar o link ADMA, a conexão com o sistema ECF ou o Grant da consultoria dispara a geração real pelos serviços já existentes (`ml_link_url`, `OnboardingLinkService::paraEmpresa`, `SyncGrantsFromSftp`) e marca o item sozinho quando termina (ADMIN-03)
  3. Marcar manualmente "Grupo de WhatsApp criado", "E-mail colaborador criado" ou "Boas-vindas enviada" registra quem marcou e quando, visível ao reabrir o item (ADMIN-04)
  4. O botão FINALIZAR ENTRADA ADMINISTRATIVA fica desabilitado enquanto qualquer item obrigatório está pendente ou o contrato não está assinado, e habilita no instante em que o último requisito é cumprido — nunca antes (ADMIN-05)
  5. Clicar em FINALIZAR ENTRADA ADMINISTRATIVA move a mesma empresa (mesmo `company_id`, nenhum cadastro novo) para a etapa "Aguardando Distribuição" e para o módulo do marketplace do contrato, resolvido por `company_marketplaces` (ADMIN-06, D4)

> ⚠️ **Correções do `152-CONTEXT.md` aos critérios 1 e 2 acima** (decisões travadas com o usuário em 2026-09-09; o texto original fica preservado, mas **quem verifica esta fase mede pelas correções**):
> - Critério 1 — o grupo Contrato tem **3** itens, não 4, e o item "Contrato revisado" é **marcação manual com autoria** (D-06), exceção registrada junto ao ADMIN-02 em `REQUIREMENTS-v23.md` (D-19). "Contrato assinado" fecha por assinatura **ou** por `ContratoLiberacao` (D-16), e com 2+ envelopes manda o mais atrasado (D-18). As duas seções aparecem numa **ficha única** aberta pelas duas listagens (D-08/D-17), não em duas telas.
> - Critério 2 — o nome é **Adman**, não ADMA, e o link é **fixo e igual para todas as empresas**, em `config('services.adman.register_url')`; o item 6 é manual (D-04). O "Grant da consultoria" é o **OAuth do Mercado Livre**, nada a ver com `SyncGrantsFromSftp`, e fecha somente quando o cliente **conectou** (`ml_tokens.status = active`), nunca quando o link foi gerado (D-05).
> - Além dos ADMIN-01..06 — o progresso do checklist passa a **dirigir as etapas 2, 3 e 4** da máquina de estados da Fase 150, por `EtapaTransicaoService` (D-15). Sem isso o FINALIZAR nasceria morto, porque a etapa 5 só é alcançável a partir da 4.

**Plans:** 10/10 plans executed (10 planos em 9 waves) — **FASE COMPLETA**

Plans:
**Wave 1**

- [x] 152-01-PLAN.md — Baseline de testes 137/138, link fixo do Adman em `config/services.php`/`.env.example` (D-04) e registro das exceções ao ADMIN-02 em `REQUIREMENTS-v23.md` (D-19)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 152-02-PLAN.md — Migration `checklist_administrativo_itens` ancorada em `company_id` e model com autoria `withTrashed` (D-10/D-11)
- [x] 152-03-PLAN.md — Contrato de resolver, value object de 3 estados e catálogo fechado dos 9 itens, com montagem condicional do grupo Contrato (D-01/D-03/D-07)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 152-04-PLAN.md — Os 4 resolvers automáticos (itens 2, 3, 7 e 8), com a segunda fonte do item 3 (D-16) e a agregação de múltiplos envelopes (D-18)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 152-05-PLAN.md — `ChecklistAdministrativoService`: montagem, progresso com denominador do catálogo e marcação manual com autoria (D-07/D-10/D-11/D-13)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 152-06-PLAN.md — `FinalizarEntradaAdministrativaService`: régua pura da trava (ADMIN-05) e transição 4→5 com destino determinístico (ADMIN-06/D-12)

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 152-07-PLAN.md — `ChecklistEtapaSincronizadorService`: o progresso dirige as etapas 2, 3 e 4, incluindo o salto 2→4 da empresa isenta (D-15)

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 152-08-PLAN.md — Rota da ficha em OR (`admin.contratos,comercial.entrada`) e os 4 endpoints do checklist, com a seção Contrato gated por módulo (D-08/D-09/D-17)

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 152-09-PLAN.md — UI: componentes do checklist, seção em `ContratoDetalhe.jsx`, ação "Abrir" em `Entrada.jsx` e `npm run build` (D-05 — copiar link, nunca abrir)

**Wave 9** *(blocked on Wave 8 completion)*

- [x] 152-10-PLAN.md — Regressão final contra a baseline e os dois checkpoints humanos de conferência visual

### Phase 153: Mensagem de boas-vindas generalizada (v23.0) — ✅ COMPLETA (trabalho direto)

**Goal:** O item "Gerar mensagem de boas-vindas" do módulo **Entrada** tem uma mensagem pronta, preenchida com os dados reais da empresa, para qualquer serviço contratado — não só Polos — editável direto no painel sem depender de deploy.
**Requirements**: COMUNIC-01, COMUNIC-02, COMUNIC-03
**Depends on:** Fase 152
**UI hint:** yes

> 🔁 **Sobrevive inteira à reorganização da Fase 151** (decisão do usuário, 2026-09-02). "Gerar mensagem de boas-vindas" é um dos 8 itens do módulo **Entrada**, e a Fase 152 entrega o *item de checklist*; esta fase continua dona do **motor** da mensagem — os 6 blocos do §4, funcionar para qualquer serviço, e o texto padrão editável sem deploy. Mesma separação de risco da D-01 da Fase 151: checklist + trava do FINALIZAR é um risco, motor de template é outro.

**Success Criteria** (o que deve ser VERDADE):

  1. Abrir o item "Boas-vindas" do checklist mostra uma mensagem já preenchida com os dados da empresa aberta, pronta para copiar (COMUNIC-01)
  2. A mensagem contém os 6 blocos do §4 — boas-vindas, e-mail colaborador, link da ADMA, link/Grant da consultoria, link de conexão com o sistema, orientações sobre as conexões que o cliente precisa fazer — e nenhum bloco fica vazio quando o dado correspondente já existe (COMUNIC-02)
  3. Uma empresa de um serviço diferente de Polos recebe a mesma mensagem corretamente preenchida, e um admin edita o texto padrão direto em Padrões Globais, sem precisar de deploy (COMUNIC-03)

**Plans:** sem planos GSD — conduzida como **trabalho direto** (`CLAUDE.md` → "GSD por RISCO"): feature nova em módulo existente, migration CRIA tabela em vez de alterar tabela com dado em produção. As decisões de desenho estão em `.planning/phases/153-mensagem-de-boas-vindas-generalizada-v23-0/153-DECISOES.md`, escritas ANTES do código; os testes vieram no mesmo commit. 25 testes / 81 assertions.

### Phase 154: Distribuição pela Coordenação e chegada aos responsáveis (v23.0) — ✅ COMPLETA (trabalho direto)

**Goal:** A Coordenação vê a fila de quem terminou o Administrativo e tem contrato assinado, distribui analista e estrategista com um clique registrando quem distribuiu, e os dois responsáveis recebem a empresa automaticamente com destaque de novo cliente.
**Requirements**: DISTRIB-01, DISTRIB-02, DISTRIB-03, DISTRIB-04, RESP-01, RESP-02
**Depends on:** Fase 152
**UI hint:** yes

**Success Criteria** (o que deve ser VERDADE):

  1. A fila de distribuição da Coordenação lista só empresas com Administrativo concluído, contrato assinado, e ainda sem analista/estrategista definidos (DISTRIB-01)
  2. Os seletores de analista e de estrategista mostram só colaborador ativo e habilitado para a função — ninguém inativo ou sem a habilitação aparece (DISTRIB-02)
  3. Confirmar distribuição grava analista, estrategista, o coordenador logado que confirmou, e a data/hora — e move a empresa para a etapa "Aguardando Onboarding" (DISTRIB-03, DISTRIB-04)
  4. Assim que a distribuição é confirmada, a empresa aparece automaticamente em Minhas Empresas do analista e do estrategista escolhidos, com destaque visual de "novo cliente" e a indicação "onboarding pendente" — sem nenhuma ação adicional de ninguém (RESP-01, RESP-02)

**Plans:** sem planos GSD — **trabalho direto** (`CLAUDE.md` → "GSD por RISCO"). A fase **não tem migration nenhuma**: reusa o enum de `company_users.role` e a linha de transição 5→6 como auditoria, então não altera tabela com dado em produção. Decisões em `.planning/phases/154-distribuicao-pela-coordenacao-v23-0/154-DECISOES.md`, escritas ANTES do código. 24 testes / 74 assertions.

### Phase 155: Onboarding plugado na máquina de estados (v23.0) — ✅ COMPLETA (trabalho direto)

**Goal:** O motor de onboarding por serviço, já entregue na Fase 135, passa a nascer e avançar junto com a etapa da empresa — sem recriar a régua — e trava quem não tem os dois responsáveis definidos.
**Requirements**: ONBRD-01, ONBRD-02, ONBRD-03, ONBRD-04
**Depends on:** Fase 154

> 🔒 Ler `.planning/learnings/onboarding-regua-congelada.md` antes de planejar: mudar a definição de onboarding não alcança quem já roda. `DefinicaoOnboarding` (VERSAO 17) é **reusada**, nunca reescrita — esta fase muda o gatilho e a leitura de etapa em volta dela, não o motor.

**Success Criteria** (o que deve ser VERDADE):

  1. Iniciar o onboarding move a etapa de "Aguardando Onboarding" para "Onboarding em andamento" (ONBRD-01)
  2. Concluir todas as atividades previstas do onboarding move a etapa para "Onboarding concluído" e, na sequência, para "Em operação" (ONBRD-02)
  3. O checklist de onboarding aberto continua sendo o gerado por `DefinicaoOnboarding`/`OnboardingEngineService` conforme o serviço contratado — nenhuma tela ou tabela nova de checklist nasce nesta fase (ONBRD-03)
  4. Tentar iniciar o onboarding de uma empresa sem analista e sem estrategista definidos é recusado (ONBRD-04)

**Plans:** sem planos GSD — **trabalho direto** (`CLAUDE.md` → "GSD por RISCO"), sem migration. `DefinicaoOnboarding` **não foi tocada** e a `VERSAO` continua 17, com teste que falha se alguém a reescrever (ONBRD-03). Decisões em `.planning/phases/155-onboarding-na-maquina-de-estados-v23-0/155-DECISOES.md`. 10 testes / 24 assertions; regressão de 795 testes incluindo as suítes de onboarding.

### Phase 156: Histórico e rastreabilidade para SLA (v23.0) — ✅ COMPLETA (trabalho direto)

**Goal:** Toda empresa tem uma timeline datada, do momento em que chega do HubSpot até entrar em operação, que permite ver quanto tempo ela passou em cada etapa — a base para medir SLA e apontar gargalo.
**Requirements**: HIST-01, HIST-02, HIST-03
**Depends on:** Fases 150, 138, 139, 140, 141, 142 (precisa que toda transição das fases anteriores já esteja emitindo evento para ter o que listar)
**UI hint:** yes

**Success Criteria** (o que deve ser VERDADE):

  1. Abrir uma empresa mostra uma timeline com data, horário, ação e usuário responsável por cada evento relevante do fluxo de entrada, sobre a base `spatie/laravel-activitylog` já aplicada a `Company` (HIST-01)
  2. A timeline de uma empresa que passou pelo fluxo completo mostra, no mínimo, os 7 eventos do exemplo do §12 — recebida do HubSpot, contrato enviado, contrato assinado, administrativo concluído, analista definido, estrategista definido, enviada para onboarding (HIST-02)
  3. É possível ler, para qualquer empresa, quanto tempo ela passou em cada etapa — dado suficiente para apontar gargalo sem abrir o banco (HIST-03)

**Plans:** sem planos GSD — **trabalho direto**, sem migration e **sem gravar nada**: a timeline é leitura agregada do que as Fases 150-155 já registram. Decisões em `.planning/phases/156-historico-e-sla-v23-0/156-DECISOES.md`. 11 testes / 86 assertions.

> **Fora de escopo desta milestone (Future Requirements do `REQUIREMENTS-v23.md`):** criar o grupo de WhatsApp via API do Digisac, provisionar e-mail colaborador automaticamente, enviar a mensagem de boas-vindas pelo sistema (o PDF pede "pronta para copiar", não envio automático), painel de SLA agregado (HIST-03 entrega o dado por empresa, o painel é produto separado), e a segunda parte da especificação funcional (o PDF se declara "a primeira parte"). **Fora de escopo declarado pelo PDF:** o Trello não integra este fluxo. **Fora de escopo por já estar entregue:** reconstruir a ingestão do HubSpot ou a assinatura de contrato (D5); reescrever `DefinicaoOnboarding` (ONBRD-03); fechar a v22.0 — a Fase 133 e o plano `133-05` seguem abertos, são trabalho daquela milestone.

## Fase avulsa — Pessoa com dois cargos (fora de milestone)

> Número 158 reservado para "Metas do Dev v3", ainda não publicada no `origin/main`.

### Phase 159: Pessoa com dois cargos (estrategista + analista) e junção das contas do Danilo

**Goal:** Uma pessoa que atende as duas funções — estrategista numas lojas, analista noutras, as duas em algumas — passa a existir como UM usuário com UMA nota de desempenho. Hoje o Danilo é dois usuários (`users` 15 e 35) porque o cadastro só comporta um cargo por setor e o formulário da empresa descarta a mesma pessoa nos dois papéis; a nota sai em duas contas e a média delas não é a nota certa.
**Depends on:** nenhuma (o cálculo já suporta papel duplo — regra D-02 da Fase 118 — quando a resposta de NPS tem atribuição por papel; o ramo legado usa um cargo só por pessoa e é medido antes da junção, D-09)
**Por que GSD:** altera `user_setores` (tabela com dado em produção) e a junção das contas mexe em snapshots e atribuições de NPS que alimentam bonificação.
**Success Criteria:**
  1. Uma pessoa pode ter os cargos analista e estrategista no mesmo setor, marcados pela tela /users
  2. A tela da empresa grava a mesma pessoa como analista e estrategista, e salvar de novo não apaga nenhum dos papéis; o histórico registra os dois
  3. Quem tem os dois cargos aparece nos dois selects de responsável
  4. Ranking de desempenho e Relatório de Bonificação filtrados por cargo mostram a pessoa em cada aba de cargo que ela tem, com a mesma nota; sem filtro, uma vez só
  5. A nota de quem acumula os dois papéis numa loja continua sendo a média das duas perguntas de NPS, com a loja pesando 1× — sem mudança de cálculo
  6. Comando de junção (dry-run por padrão) passa lojas, atribuições de NPS e imputações do user 35 para o 15 a partir da competência 2026-09, sem tocar competência consolidada; conferido por reconsulta ao banco

**Plans:** 7/8 executados (código no ar em `c7a58b3b`, 2026-10-01); 159-08 adiado — decisões em `.planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/159-CONTEXT.md`

Plans:
- [x] 159-01-PLAN.md — baseline de testes (D-12), unique de user_setores por cargo (D-01) e /users com dois cargos no mesmo setor (D-02) · wave 1
- [x] 159-02-PLAN.md — /administrativo/setores por cargo (D-10) e consumidores de Setor::membros sem duplicar pessoa · wave 2
- [x] 159-03-PLAN.md — empresa com a mesma pessoa nos dois papéis (D-03), selects (D-04), NPS por função (D-07) e menu (D-08) · wave 2
- [x] 159-04-PLAN.md — ranking, relatório, auditoria e portfolio nas duas abas com a mesma nota (D-05, SC5) · wave 2
- [x] 159-05-PLAN.md — comando usuarios:unificar-contas: dry-run, travas, censo (D-11), carteira, backup e desfazer (D-06) · wave 2
- [x] 159-06-PLAN.md — junção de NPS, imputações, snapshots, PPAs/onboardings e medição do ramo legado (D-09) · wave 3
- [x] 159-07-PLAN.md — regressão final, deploy com o usuário presente, SHOW INDEX no MariaDB e medições só de leitura · wave 4 (checkpoint)
- [ ] 159-08-PLAN.md — junção 35 → 15 em produção com decisão e verificação humanas, segundo passe até 31/10 14:00 · wave 5 (checkpoint) — **ADIADO pelo usuário em 2026-10-01**: junção bloqueada pelo consolidar-mes quebrado (learnings §10.1); ver `.planning/todos/pending/159-juncao-danilo-segundo-passe.md`

## Fase avulsa — Publicador no sistema interno (fora de milestone)

### Phase 164: Publicador no sistema interno (/mlb/anuncios), para Polos e Incubadora

**Goal:** A equipe ECF publica no Mercado Livre pelo sistema interno, em `/mlb/anuncios`, escolhendo o programa (Polos | Incubadora | Gestão, D23) e a empresa; o que o cliente preparou no Portal (Lista SKUs, títulos planejados, Precificação) entra já preenchido e ligado ao Portal, e empresa sem Portal tem os produtos cadastrados no próprio Publicador. O motor do Publicador do piloto (`app/Support/Publicador`, `app/Services/Publicador`, tabelas `pub_*`, conferência e publicação em fila) é reaproveitado inteiro; o Anunciar sai do Portal do Cliente.
**Depends on:** nenhuma fase GSD — continua o Publicador do Portal (branch `feat/publicador-ml-261001`, em produção desde `13eedbb8`/`675e6c49`, piloto #459). Desenho e decisões D12–D19: `.planning/publicador-ml-spec/17-publicador-interno.md`; motor: `16-analise-do-portal.md`.
**Por que GSD:** a migration altera `pub_rascunhos`, que já tem dado em produção (2 rascunhos de teste da #459) — decisão do usuário em 2026-10-02 (D19).
**Success Criteria:**

  1. `/mlb/anuncios` abre o Publicador: escolha Polos | Incubadora | Gestão (D23), lista das empresas do programa com conta do ML, situação do Portal (sincronizado / nunca / sem Portal) — só admins (D17)
  2. "Sincronizar do Portal" cria, para cada oferta da Lista SKUs da empresa ainda sem produto no Publicador, um produto ligado à oferta; é idempotente e não apaga nada
  3. Produto ligado ao Portal herda título planejado e preço da Precificação ao vivo; o que a equipe digita no Publicador vence (D16); publicar cadastra o MLB na aba Anúncios da oferta
  4. Empresa sem Portal (MlbEmpresa sem Company) tem os produtos cadastrados no Publicador e publica com o token ancorado em `ml_tokens.mlb_empresa_id` (D15)
  5. Os 2 rascunhos existentes em produção continuam abrindo, agora pelo seu produto; a migration roda no MariaDB local com `--path` sem perda
  6. Meus Anúncios, Em massa e Histórico continuam funcionando; só o assistente individual é trocado; o "Anunciar por IA" vira botão dentro do Publicador (D14)
  7. O Anunciar sai do Portal do Cliente para todos os clientes (menu, rotas e allowlist), sem afetar Lista SKUs, Precificação, Anúncios, Planejamento e Mapeamento (D18)
  8. Layout das telas pelo Stitch (projeto "ECF Admin — Identidade"), conferido no navegador sem erro de console

**Plans:** 15/15 concluídos (2026-10-02) — **FASE FECHADA no código**; verificação `human_needed` (8/8 critérios conferidos no código; 6 itens de conferência humana em `164-HUMAN-UAT.md`, quase todos dependem do deploy). Code review: 13 achados corrigidos no escopo do usuário (`164-REVIEW-FIX.md`); 14 warnings e 16 infos registrados como dívida em `164-REVIEW.md`. DEPLOYADA em 2026-10-03 (`01da6664`).

Plans:
**Wave 1**

- [x] 164-01-PLAN.md — baseline de testes, pub_produtos (oferta SET NULL, D27) + pub_rascunhos.produto_id com backfill (oferta_id dormente), modelo PubProduto, conferência no MariaDB local · wave 1

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 164-02-PLAN.md — motor: rascunho nasce/abre pelo produto; efetivos e régua só com oferta; estado por produto e resumo de prontidão; oferta apagada no Portal congela título/preço e não leva o histórico (D27) · wave 2
- [x] 164-03-PLAN.md — programa da MlbEmpresa (D13), contas liberadas por âncora (D21) e backend da entrada Polos · Incubadora · Gestão · wave 2
- [x] 164-04-PLAN.md — mesa do editor: base dos cards, Produto e categoria, Ficha técnica, Fotos + normalização tipográfica · wave 2

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 164-05-PLAN.md — mesa do editor: Variações e estoque, Clássico e Premium, Logística, Descrição · wave 3
- [x] 164-06-PLAN.md — Sincronizar do Portal, produtos da empresa, cadastro manual, casca do editor e comando do D20 · wave 3
- [x] 164-07-PLAN.md — motor: conta do ML pelo produto (Company ou MlbEmpresa), trava de publicação por conta liberada (D21) e conferência só local sem foto em conta não liberada (D26) · wave 3

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 164-08-PLAN.md — API JSON do editor interno por produto + CategoriaBuscaService extraído do Portal · wave 4
- [x] 164-09-PLAN.md — Anunciar por IA grava no rascunho novo (dados do anúncio e variações) · wave 4
- [x] 164-10-PLAN.md — tela A: entrada do Publicador em /mlb/anuncios e componentes compartilhados · wave 4

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 164-11-PLAN.md — tela B: produtos da empresa; aba Individual leva ao Publicador; abas sem Company desabilitadas · wave 5
- [x] 164-12-PLAN.md — hook do editor (estado, salvamento, fila, derivados testados) e hook da IA · wave 5

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 164-13-PLAN.md — tela C: editor "mesa de anúncio" (barra, faixa, 7 cards, lateral, IA) · wave 6

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 164-14-PLAN.md — conferência visual isolada (SQLite + php -S + Puppeteer) e aprovação do usuário · wave 7 (checkpoint)

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 164-15-PLAN.md — Anunciar sai do Portal (rotas, allowlist, menu), código morto removido, gate final contra a baseline e learnings · wave 8

---
*Roadmap atualizado: 2026-07-20 — Milestone v18.0 (Períodos, competência de bônus e variação via Adman) anexada: 5 fases (100-104) cobrindo as 23 REQs (PER/ADM/BON/CAR/UIP) do REQUIREMENTS-v18.md, estrutura vinda do plano canônico do usuário (plano-carteira-desempenho-multi-servico.md, seções "Regra de período/fechamento/pagamento" e "Regra de variação de margem via Adman"). Numeração com buffer 97-99 reservado para a milestone NPS Anti-Burlamento do dev paralelo (Fases 94-96, ainda em aberto). Fundação em 100 (`MetricPeriodResolver`) e 101 (`AdmanMetricDiffService`), independentes entre si; 102 e 103 dependem de ambas; 104 depende de 102+103. Baseline oficial de bônus usa janela de mesmo tamanho (N dias imediatamente anteriores), não mês calendário — decisão do usuário 2026-07-17. Fases 60-96 preservadas intactas.*

*Roadmap atualizado: 2026-07-24 — Milestone v20.0 (Handoff Comercial HubSpot) anexada: 5 fases (111-115) derivadas do plano canônico `prompt-claude-otimizacao-comercial-hubspot.md`, preservando a estrutura de 10 estágios do prompt. Fundação (111) → HubspotValueResolver + handoff service (112, núcleo do valor mensal×anual) → enriquecimento contato/empresa + dedup (113) → UI Comercial + replay (114) → E2E + doc (115). Trabalho ADITIVO: fluxo legado (Fases 34-37) e testes atuais preservados; nenhum teste chama HubSpot real. Conflict-detection do import: só INFO (sem locked-decisions HubSpot pré-CONTEXT); 1 WARNING = mudança semântica de como `valor_contratado` é populado em closes futuros (histórico intacto). Fases 100-110 (v18/v19) preservadas.*

*Roadmap atualizado: 2026-07-27 — Milestone v21.0 (Desempenho por nota individual de empresa) anexada: 7 fases (117-123) cobrindo as 38 REQs (MPP/NPSE/EMPS/AGRE/ROLL/SNAP/UIEM) do REQUIREMENTS-v21.md, derivadas do plano canônico `plano-implementacao-desempenho-por-empresa.md`. Conflict-detection do import: 3 BLOCKERS, todos resolvidos por decisão do usuário em 2026-07-27 — (1) fonte de margem em pp reabre o hotfix a413e823 de 24/07, (2) régua atual reusada como pp sem recalibrar, (3) empresa sem baseline segue como decisão em aberto para o discuss-phase da Fase 120. Correções ao plano verificadas contra o código: `cacheKey` já está em `v12` (alvo real `v13`, com 4 suítes hardcoded), `adman:diff` em `v5` (→`v6` ok). **Fase 118 bloqueada até a Fase 116 fechar** (116-06/07/08) — adiciona um 4º call-site da regra de piso de NPS. Fases 117 e 121 são gates humanos explícitos (estabilidade de `prev`; delta antigo×novo). Esta milestone é a opção (A) da pendência `.planning/todos/pending/metrica-margem-bonus-fragil.md`, mas NÃO fica pronta antes do freeze de junho em 31/07 14h BRT — o freeze é decisão separada. `phases.clear` NÃO foi executado: Fases 1-116 preservadas, incluindo a 116 em execução, seguindo a convenção de anexar milestones deste roadmap.*

*Roadmap atualizado: 2026-08-07 — Milestone v22.0 (Administrativo + Clicksign) anexada: 10 fases (124-133) cobrindo os 39 REQ-IDs (FLUXO/DADOS/CLICK/PDF/REDE/UI) do REQUIREMENTS-v22.md, derivadas do plano canonico `plano-administrativo-clicksign.md` e corrigidas pela pesquisa (STACK/FEATURES/ARCHITECTURE/PITFALLS). Ordem de construcao dita pelo PITFALLS.md: extracao pura de services + kill switch inerte (124) -> schema (125) -> client+PDF (126) -> service de orquestracao com REDE-05 na mesma fase que gera o envelope (127) -> gatilhos em modo observacao/REDE-06 (128) -> webhook com GATE A1 bloqueante do HMAC (129) -> rede de seguranca REDE-02/03/04 (130) -> tela administrativa (131) -> cutover checkpoint humano dedicado (132) -> liga o bloqueio com checkpoint humano (133). Bloqueio nasce atras da flag `Configuracao.administrativo_bloqueio_ativo` desligada por padrao desde a Fase 124 ate a Fase 133 — nenhuma fase intermediaria muda o roteamento operacional observavel. Decisoes em aberto A1-A4 atribuidas as fases 129 (A1 bloqueante, A3) e 127 (A2) e 128 (A4). Fases 1-123 preservadas.*

*Roadmap atualizado: 2026-09-02 — **Fase 137 (Fechamento mensal)** anexada. Origem: brief do usuario em 02/09, logo apos declarar a parte de contratos concluida. E a contrapartida operacional das Fases 124-133: o contrato define a tabela progressiva, o fechamento a aplica. Quatro mudancas pedidas sobre a tela `/financeiro` existente: (1) acabar com o acumulativo — hoje o mes corrente usa janela MOVEL de 30 dias e so meses passados usam mes-calendario; (2) parar de manter grupos proprios e reusar os grupos de empresas do Comercial, ja cadastrados; (3) transformar a tabela progressiva em DADO estruturado (hoje so existe como texto nos .docx da Clicksign, e sao tabelas diferentes por servico: Gestao/ML, Shopee e Brigada); (4) permitir cadastrar a tabela de uma empresa a mao, porque a geracao de contrato pelo sistema e recente e a maioria das empresas existentes esta em tabela progressiva sem o sistema saber — incluindo tabelas antigas/fora do padrao, que sao legitimas. Fase NAO planejada: as decisoes de modelagem (onde mora a tabela, override por empresa, se o fechamento vira registro congelado por competencia) ficaram explicitamente para o discuss-phase. Fases 1-136 preservadas.*

*Roadmap atualizado: 2026-09-03 — **Fase 138 (Tabela do grupo e aviso de mudanca de faixa)** anexada. Origem: uso real do fechamento no mesmo dia em que a Fase 137 foi para producao e agosto/2026 foi fechado. Duas lacunas que so o uso revelou: (1) grupo nao tem tabela propria — e classificado pela tabela da empresa que mais faturou no mes, entao grupo com tabela negociada nao tem onde registra-la e, se as irmas divergem, o criterio muda de mes para mes sem aviso; (2) nao ha notificacao quando uma empresa muda de faixa, embora o snapshot ja calcule `evolucao`. Decisoes do usuario em 2026-09-03: tabela de grupo com precedencia sobre a da empresa, e aviso nos DOIS sentidos (subida e queda — queda significa cobrar menos). Entrar/sair de `A DEFINIR` fica fora por ora, para nao virar ruido com as 74 empresas hoje sem faixa. Fases 1-137 preservadas.*

*Roadmap atualizado: 2026-09-04 - **Fase 139 (Redesenho da tela de Fechamento)** anexada. Origem: o usuario usou a tela em producao depois das Fases 137 e 138 e disse que a UI/UX estava dificil de entender. Ele produziu um handoff de design completo em `design_handoff_fechamento/` (README com tokens e comportamento, prototipo HTML e captura da tela atual) e pediu que fosse desenvolvido. Cinco decisoes de widget dele: manter Servicos contratados, remover Tipo de cobranca e Distribuicao de faixas, reduzir o Total consolidado a 'Total a receber' (o sistema nao sabe se o cliente pagou) e criar um widget em destaque para as empresas que subiram de faixa. Decisao de fidelidade tomada em 2026-09-04: estrutura e comportamento do design com fidelidade, cores e tipografia do ECF Admin — a paleta do handoff e proxima mas nao identica a do projeto e faria a tela destoar das outras. Fases 1-138 preservadas.*

*Roadmap atualizado: 2026-09-08 - **Fase 140 (Extrair as tabelas progressivas do Clicksign)** anexada. Origem: depois da Fase 139 ficou medido que 127 empresas cobram por tabela assumida (R$ 460.500/mes sem lastro) e que o cadastro manual da Fase 137 nunca foi usado. O usuario levantou consultar o Clicksign e autorizou a investigacao, feita em 2026-09-08 contra a conta de producao: 429 envelopes, 123 de gestao de ADS, PDFs baixaveis com CNPJ e razao social no texto. Dois achados mudaram o desenho: nem todo contrato tem tabela progressiva (6 de 11 da amostra sao valor fixo) e existe tabela fora do padrao em uso — DESK DESIGN com 12 faixas comecando em R$ 2.250, contra os R$ 3.000 que o sistema cobra por assumir a tabela padrao. O casamento com a empresa e o elo fraco: so 10 de 201 empresas tem CNPJ, e o casamento por nome acerta 9 de 14 com falsos positivos plausiveis (GRAFICA ADHARA -> Filipe Adada), entao escrita automatica ficou FORA por decisao. Estrategia acordada: primeiro um comando de leitura que gera so relatorio, sem tela e sem escrita; a tela de conferencia e a escrita auditada dependem de o relatorio se mostrar bom. Fases 1-139 preservadas.*

*Roadmap atualizado: 2026-09-09 - **Fase 141 (A tabela progressiva passa a ser da empresa e do grupo)** anexada. Origem: correcao de premissa feita pelo usuario em 2026-09-09 olhando o fechamento em producao. Ela atravessa as Fases 137/138/139 e muda o CALCULO da mensalidade, nao a tela. Regras: a tabela e da empresa ou do grupo, nunca do servico; o faturamento das plataformas com tabela e SOMADO para achar UMA faixa; Mentoria nao entra na soma por nao ter tabela; a mensalidade e o valor da faixa, entao a soma de mensalidades deixa de existir. Caso que abriu a fase: BARAOSHOP faturou R$ 488.262,90, caiu na faixa 1 de R$ 3.000 e a tela cobra R$ 5.500, porque soma o contrato de Shopee. Problema de transicao: 127 de 201 empresas sao classificadas hoje pela tabela do servico e NENHUMA tem tabela propria — aplicar a regra sem mais nada esvazia o fechamento. O caminho existe por causa da Fase 140, que leu 85 contratos do Clicksign (49 com tabela, 29 de valor fixo) e cuja tela de conferencia ja esta construida. A tela de cadastro e fase propria, a pedido do usuario. Fases 1-140 preservadas.*

*Roadmap atualizado: 2026-09-10 - **Fase 141 FECHADA.** Virada em produção (2026-09-09/10, commit `e98e25ed`): 168 empresas materializadas com tabela própria presumida (`origem='presumida_servico'`), delta ANTES×DEPOIS apresentado e aprovado pelo usuário (total a receber R$ 2.486.700,91 → R$ 736.450,97 — a queda é a correção da fórmula antiga que somava contrato à faixa, confirmada pelo usuário com o caso Camillo Parts R$ 522.500,00 → R$ 12.000,00, faixa 7 para faixa 7, "Mudam de faixa: 0"), chave `fechamento_tabela_por_empresa_ativa` ligada, e agosto/2026 reconsolidado sob a regra nova a pedido explícito do usuário (soma de faixas R$ 460.500,00 → R$ 466.500,00, 127 → 129 empresas com faixa). Gate `Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Quick260909`: 553 testes / 2642 asserções / 0 falhas. Números completos, procedimento de rollback sem deploy e pendências (168 tabelas presumidas ainda sem conferência contra o contrato real, 32 empresas sem tabela, 3 contratos de R$ 250.000 a investigar) em `.planning/learnings/fechamento-tabela-por-empresa.md`. Fases 1-141 preservadas.*

*Roadmap atualizado: 2026-09-10 - **Fase 142 (O cadastro da tabela progressiva vai para o contrato)** anexada. Pedido do usuario em 2026-09-09 olhando o fechamento em producao, com quatro itens: mostrar as faixas da tabela propria (hoje sai so a frase), mascara de dinheiro nos campos, mover o cadastro para dentro da pagina de contrato da empresa em pagina exclusiva, e deixar o fechamento so de leitura. O primeiro item e divida consciente do plano 137-09, que preferiu formulario em branco com aviso a valores adivinhados que sobrescreveriam preco real — custo aceito quando havia ZERO tabelas proprias. Depois da Fase 141 sao 169, das quais 168 presumidas esperando conferencia, entao a divida virou gargalo. Fases 1-141 preservadas.*
*Roadmap atualizado: 2026-09-01 — Milestone v23.0 (Fluxo de Entrada de Novas Empresas) anexada: 7 fases (137-143) cobrindo os 31 REQ-IDs (ETAPA/COMERC/ADMIN/COMUNIC/DISTRIB/RESP/ONBRD/HIST) do REQUIREMENTS-v23.md, derivadas do PDF `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` — pesquisa de domínio deliberadamente pulada (D0). Ordem dita pelo próprio fluxo do PDF: máquina de estados dos 9 status (137, fundação) → Comercial religado à etapa (138) → checklist administrativo + trava de finalização (139) → mensagem de boas-vindas generalizada (140) → distribuição da Coordenação + chegada aos responsáveis, DISTRIB e RESP fundidos numa fatia vertical só (141) → onboarding plugado na máquina de estados (142) → histórico e SLA por último, porque depende de toda transição anterior já emitir evento (143). Fase 150 mexe em migration sobre `companies` com dado de produção (~500 registros) e por isso é fase GSD obrigatória, com baseline de testes e VERIFICATION — sinalizado explicitamente na própria fase. D5 travada na abertura: HubSpot e Clicksign se integram, nunca se reconstroem — nenhuma fase desta milestone cria cliente de assinatura, webhook de contrato ou ingestão de deal ganho; o grupo Contrato do checklist (Fase 152) só lê o estado entregue pelas Fases 126/127/129/132 da v22.0. `phases.clear` NÃO foi executado — Fases 1-136 preservadas, incluindo os três blocos de "Posição paralela" com gate humano aberto (Fases 133, 135, 136) da v22.0/avulsas, seguindo a convenção de anexar milestones deste roadmap.*

*Roadmap atualizado: 2026-10-02 - **Fase 164 (Publicador no sistema interno)** anexada como fase avulsa. Origem: depois do piloto do Publicador no Portal do Cliente (02/10), o usuario decidiu levar o Publicador para `/mlb/anuncios` no sistema interno (API de gerar imagens nao e coisa do cliente; atende Polos e Incubadora), com sincronizacao ligada ao Portal e cadastro de produtos para empresa sem Portal. GSD por alterar `pub_rascunhos` com dado em producao (D19). Fases 1-159 preservadas.*

*Roadmap atualizado: 2026-10-02 - **Fase 164 FECHADA no código** (worktree `C:/tmp/ecf-publicador-spec-261001`, branch `feat/publicador-ml-261001`, sem push nem deploy). 15 planos em 8 waves; gate visual 164-14 aprovado pelo usuário; gate final contra a baseline sem falha nova (Publicador 376/1874, PortalCliente 231/1872, test:js 645 com as 2 falhas pré-existentes). Code review achou 4 BLOCKERs (trava D21 só no clique, exclusão de empresa apagando o histórico, duas perdas de edição no editor) — corrigidos com mais 9 warnings, por escolha do usuário. Verificação `human_needed`: 6 itens em `164-HUMAN-UAT.md` (2 rascunhos da #459 em produção, trava no MariaDB real, salvamento do editor no navegador, D20, E2E na #459, Portal sem Anunciar em produção). Fases 1-159 preservadas.*

*Roadmap atualizado: 2026-10-02 - A fase do **Publicador no sistema interno** foi **renumerada de 160 para 164**: a milestone v24.0 (Creative Engine, outro dev) chegou à origin/main reservando 160-163 enquanto esta fase rodava. Os commits antigos dela seguem com "160" no assunto (histórico); pasta, planos e referências passaram a 164.*

*Roadmap atualizado: 2026-10-03 - **Fase 164 DEPLOYADA** (`01da6664`): integrada com a v24.0 até a Fase 161 dela, com a ponte "Gerar criativos no assistente antigo" na tela de produtos. A integração de verdade do Creative Engine no card de Fotos do Publicador fica para a Fase 165 (aditiva, combinada com o outro dev).*

## Fase avulsa — Creative Engine no Publicador novo (fora de milestone)

### Phase 165: Creative Engine no Publicador novo — gerador de imagens dentro do editor

**Goal:** o publicador gera o kit de criativos por IA (Creative Engine da v24.0) sem sair do editor do Publicador interno (`/mlb/anuncios`, editor em 3 etapas `cab40d48`, que substituiu as 3 colunas em 04/10) — a partir do rascunho do Publicador, não do assistente antigo — e as imagens que ele aprova viram fotos do próprio rascunho (fotos gerais ou da variação), que só sobem ao Mercado Livre na conferência/publicação, com a mesma trava de conta liberada.
**Requirements**: CE165-01, CE165-02, CE165-03, CE165-04, CE165-05, CE165-06, CE165-07, CE165-08, CE165-09, CE165-10, CE165-11, CE165-12 (definidos em `165-RESEARCH.md`)
**Depends on:** Phase 164 (Publicador interno; editor em 3 etapas `cab40d48`) e Phase 161 (kit de 7 da v24.0, em produção). **Coordenação:** a ordem com a Fase 162 (validador) do outro dev precisa ser combinada antes da execução — recado enviado pelo usuário em 2026-10-04.
**Plans:** 8 plans

**Escopo combinado (só ACRESCENTA, nada do que existe muda de comportamento):**
- coluna anulável `pub_rascunho_id` em `ml_anuncio_criativos` e `ml_anuncio_criativo_kits` (tabelas COM dado em produção → por isso esta fase é GSD: baseline de testes, VERIFICATION);
- segundo caminho no `CreativeContextBuilder::paraCriativo` lendo o rascunho do Publicador; o caminho do `payload` do assistente antigo fica byte a byte igual;
- endpoints novos em `mlb.anuncios.publicador.criativos.*` reaproveitando planejar/gerar/regenerar/aprovar do kit, com o kit endereçado pelo `id` escopado ao rascunho (nenhum token de criativo chega ao navegador — D-13);
- imagem aprovada vira `pub_imagens` no grupo certo (galeria geral ou variação) via `ImagemAssetService`; nunca envio direto ao ML;
- tela: o `BlocoDeFotos` (fotos de cada variação e "Fotos para todas as variações", etapa Detalhes) ganha "Gerar com IA", que abre um painel NATIVO do Publicador (`Mesa/PainelCriativos.jsx` + `useCriativosDoPublicador`, D-08) — `PainelCriativosIa`/`KitCriativosGrade` do assistente antigo ficam intocados;
- fora: `MlPublicacaoService`, `CreativeKitPublicacao`, gate do PUB-03, Fase 162.

Plans:
- [ ] 165-01-PLAN.md — checkpoint de coordenação com o outro dev, baseline de testes (commit próprio), migration aditiva (`pub_rascunho_id`, `pub_grupo`, `pub_imagem_id`) e models
- [ ] 165-02-PLAN.md — adaptador `ContextoCriativoDoPublicador`, ramo novo no `CreativeContextBuilder` (valor da variação como fato) e trait de teste
- [ ] 165-03-PLAN.md — serviços: imagem aprovada vira `pub_imagens` (D26, dedupe, sem truncar) e referências efêmeras das fotos do rascunho
- [ ] 165-04-PLAN.md — controller, rotas `publicador.criativos.*` e presenter (atual, planejar, gerar, status, binários, aprovar uma)
- [ ] 165-05-PLAN.md — regenerar, aprovar o kit, limitadores (429 em pt-BR) e D-13 (nenhum token no navegador; regressão das rotas antigas)
- [ ] 165-06-PLAN.md — hook `useCriativosDoPublicador` e painel `Mesa/PainelCriativos.jsx`
- [ ] 165-07-PLAN.md — "Gerar com IA" no `BlocoDeFotos` (link, não amarelo), Provider no `Editor.jsx` e prop `criativos_ia`
- [ ] 165-08-PLAN.md — gate final contra o baseline, learnings §11 e conferência visual sem custo (checkpoint)

---

## Fase avulsa — Alavancas no Publicador (fora de milestone)

### Phase 166: Alavancas no Publicador — promoções, cupons, publicidade e atacado da conta do cliente

**Goal:** no Publicador interno (`/mlb/anuncios`), depois de escolher a empresa, a equipe escolhe entre **Publicar** (o fluxo atual da Fase 164) e **Alavancas**: uma área para ver, analisar e — onde a API do Mercado Livre permite — criar e alterar as alavancas de venda da conta do cliente: Central de promoções (convites, candidatos, inscrever/alterar/tirar anúncio, lista de exclusão das campanhas automáticas, desconto individual, campanha do vendedor, leve X pague Y), cupons do vendedor, publicidade (Product Ads) e atacado (preço por quantidade).
**Requirements**: AL166-01, AL166-02, AL166-03, AL166-04, AL166-05, AL166-06, AL166-07, AL166-08, AL166-09, AL166-10, AL166-11, AL166-12, AL166-13, AL166-14, AL166-15, AL166-16, AL166-17, AL166-18, AL166-19, AL166-20 (definidos em `166-RESEARCH.md`; decisões D-01..D-13 em `166-CONTEXT.md`)
**Depends on:** Phase 164 (Publicador interno, seleção de empresa e conta ML com token). Toca a Fase 41/44 (Sugadores) só se a publicidade reaproveitar o `MercadoLivreAdsService`.
**Plans:** 16/16 plans — código COMPLETO em 2026-10-05 (verificação `human_needed`: 20/20 requisitos, 13/13 decisões; falta só a prova real na #459, pós-deploy — `166-HUMAN-UAT.md`)

**Já sabido (pesquisa de 2026-10-04, `166-PESQUISA-API.md`):**
- Central de promoções e cupons: API completa (`/seller-promotions`, `app_version=v2`); cupom do vendedor só no Brasil; reputação verde, item ativo e exposição paga para criar desconto/campanha/cupom.
- Publicidade: leitura documentada (campanhas, anúncios, ad groups, métricas, bonificações); escrita NÃO está na documentação brasileira e nunca foi provada (permissão "Advertising" do app ECF no DevCenter pendente desde 2026-06-27).
- Atacado: só contas com a tag `business`; o formato absoluto (`/prices/standard/quantity`) é descontinuado em 2026-10-27 — usar o % B2B (`/prices/price-per-quantity`, header `x-version`).
- Afiliados: fora — não há API para o vendedor.
- Escrita em conta de cliente é o objetivo da fase, mas a regra do usuário de 2026-10-01 (só a #459 recebe escrita de teste) precisa ser revista na discussão antes de qualquer POST/PUT/DELETE real.

Plans:
- [x] 166-01-PLAN.md — baseline de testes (commit próprio), trava própria das Alavancas (`AlavancasLiberadas` + config) e a tabela `pub_alavanca_escritas` com o desenho escrito
- [x] 166-02-PLAN.md — cliente do Publicador com cabeçalhos (host fixo em produção), `MapeadorErroAlavanca`, contexto da conta para as duas âncoras, cache por conta e o cenário de teste
- [x] 166-03-PLAN.md — núcleo de escrita: contrato `AcaoAlavanca`, `EscritorAlavancas` (trava, vendedor, histórico antes do envio, 423/5xx), assinatura da prévia e guarda do caminho único
- [x] 166-04-PLAN.md — matriz `TiposDePromocao`, datas e alertas (D-12), produtos da conta ao vivo e leitura da Central de promoções
- [x] 166-05-PLAN.md — publicidade só leitura pelos endpoints atuais (guarda contra os desligados), leitura de cupons, panorama e o comando `publicador:sondar-alavancas`
- [x] 166-06-PLAN.md — análise: quanto a loja recebe (normal × promoção), ML banca como estimativa, margem pela Precificação e alertas
- [x] 166-07-PLAN.md — ações de convite pela matriz (inscrever/alterar/tirar/tirar de todas) e desconto individual
- [x] 166-08-PLAN.md — campanha do vendedor e leve X pague Y, lista de exclusão das campanhas automáticas e cupons do vendedor
- [x] 166-09-PLAN.md — atacado em % B2B: leitura com versão, recomendações sob a trava e gravação com `X-Version`
- [x] 166-10-PLAN.md — rotas e controller de leitura (página, JSON, histórico por empresa), só admin, duas âncoras
- [x] 166-11-PLAN.md — escrita HTTP: registro das ações, prévia assinada, confirmar (403 fora da lista) e lote por job na fila `high`
- [x] 166-12-PLAN.md — tela: barra Publicar | Alavancas, página da área, panorama, histórico, aba Publicidade e gates de fonte
- [x] 166-13-PLAN.md — tela: janela de confirmação, análise e convites do ML
- [x] 166-14-PLAN.md — tela: produtos da conta (com "tirar de todas as promoções"), desconto individual, campanhas do vendedor e campanhas automáticas
- [x] 166-15-PLAN.md — tela: abas Cupons e Atacado, na ordem final das abas
- [x] 166-16-PLAN.md — gate final contra o baseline, migration no MariaDB local, conferência visual sem custo, prova real na #459 (checkpoint) e learnings §12

### Phase 167: Cadastro de Produto no Mapeamento Estrutural — o cliente cadastra seus produtos (aba Produtos da planilha de Planejamento Estrutural)

**Goal:** a aba **Produtos** da planilha `3Planejamento_Estrutural_ECF.xlsx` (a que o Emerson apresentou na reunião da Incubadora de 2026-10-05) vira sistema, como submódulo novo **Produtos** no Mapeamento Estrutural do Portal do Cliente: o cliente (e a equipe, pelo acesso de equipe ao portal) cadastra cada produto com código, grupo/variação, nome, família (linha de design), ambiente(s), categoria do ML, volumes (C×L×A e peso de cada), peso total e custo — por tela ou importando a planilha — e cada produto se liga às ofertas simples da Lista SKUs. É a base da geração automática das ofertas (fase seguinte): "cadastrar o produto uma vez" em vez de cadastrar cada anúncio.
**Requirements**: PR167-01, PR167-02, PR167-03, PR167-04, PR167-05, PR167-06, PR167-07, PR167-08, PR167-09, PR167-10, PR167-11, PR167-12, PR167-13, PR167-14 (definidos em `167-RESEARCH.md`; decisões D-01..D-22 em `167-CONTEXT.md`; contrato de tela em `167-UI-SPEC.md`)
**Depends on:** Mapeamento Estrutural do Portal (`estrutura_*`, ADR PORTAL-01/02) e Phase 164 (Publicador lê as ofertas do Mapeamento pelo "Sincronizar do Portal").
**Plans:** 21/21 plans — código COMPLETO em 2026-10-06 (tela aprovada pelo usuário; revisão de código com 5 bloqueios, 16 avisos e 22 informativos, 40 corrigidos — `167-REVIEW-FIX.md`; verificação `human_needed` 30/30 — `167-VERIFICATION.md`). **DEPLOYADA em 2026-10-06** (push `553253d1..9d54db33`, migrations no lote 164, 515 ofertas antes e depois, nenhuma ligada). Falta: frete real com "pode" e `mimes:xlsx` com exportações reais

**Já sabido (análise de 2026-10-05, na conversa com o usuário):**
- A planilha tem 6 abas: Produtos (o cliente preenche) → Planejamento ("identificação da oferta": 1 linha por oferta, com composição, logística e preço) → Cronograma (data por capacidade, feito + link, checklist de 13 alavancas) → Parâmetros → Frete ML Verde → Resumo. Esta fase cobre SÓ a aba Produtos e a ligação produto → oferta simples.
- Regra de combinação medida na planilha (70 produtos → 199 ofertas: 70 Simples, 46 Combo, 44 Kit, 39 Combit — as 4 FASES que `EstruturaOferta` já tem): das 83 ofertas com 2+ produtos, **0 misturam família** e 82 dividem ao menos um ambiente; família + ambiente dão 105 pares possíveis e só 43 foram usados (o 3º filtro, "faz sentido", fica para a fase de geração). Por isso família e ambiente precisam nascer como dado estruturado, não texto livre.
- Ambiente é múltiplo e a planilha já tem grafias divergentes ("Sala estar"/"Sala Estar", "Quarto/Sala estar") → lista da própria empresa (criada uma vez, depois escolhida), com marcação múltipla (D-05 do 167-CONTEXT).
- Conflito de nome: no Onboarding/Precificação, "família" significa "o mesmo produto em várias cores"; na planilha isso é Grupo + Variação, e Família é a linha de design.
- Produto pode ter vários volumes (ex.: cristaleira em 2 caixas, cômoda em 3); o peso total é a soma.
- A planilha tem catálogo e CUSTOS reais de cliente: NÃO commitar o `.xlsx`; ela fica na raiz do checkout principal (`C:/xampp/htdocs/ecf_admin/3Planejamento_Estrutural_ECF.xlsx`) para leitura local.
- Ligar oferta → produto altera `estrutura_ofertas`, tabela com dado em produção — é o motivo de esta fase ser GSD (regra do CLAUDE.md).
- Fora desta fase (fases seguintes): geração automática das ofertas, logística e frete de kits/combos (a logística provável, o peso cubado e o frete ME2 POR VARIAÇÃO entraram nesta fase — D-15/D-16 do 167-CONTEXT), grade de MC 30/20/10/0 e tarifa por categoria pela API, cronograma por capacidade e checklist de alavancas.

Plans:
- [x] 167-01-PLAN.md — baseline de testes (commit próprio), desenho do schema, 6 tabelas novas + models e o ALTER `estrutura_ofertas.variacao_id` provado no MariaDB local
- [x] 167-02-PLAN.md — regras puras: config global do ML, NumeroBr, VolumesTexto, logística provável (ME2/Full/ME1/pendente, pacote empilhado), tabela de frete ECF e pendências
- [x] 167-03-PLAN.md — famílias e ambientes como listas da empresa; oferta ligada no EstruturaOfertaService (criar, sincronizar, proteger na Lista SKUs)
- [x] 167-04-PLAN.md — SpreadsheetGrid estendido de forma aditiva (colar crescendo, Tab entre linhas, coluna picker, aparência portal, ações e nota por linha)
- [x] 167-05-PLAN.md — frete ME2: estimativa pela tabela ECF e cotação real pela conta do cliente, em lote e com cache (nada gravado)
- [x] 167-06-PLAN.md — normalizador de linha, linha da tela com campos calculados no servidor e gravarLinhas (produto, variações, volumes, listas, categoria)
- [x] 167-07-PLAN.md — oferta simples ligada a cada variação (sem tocar nas antigas) e exclusão de variação pela regra da Lista SKUs (D-22)
- [x] 167-08-PLAN.md — Precificação lê o custo da variação nas ofertas ligadas; Publicador herda sem mudança
- [x] 167-09-PLAN.md — planilha-modelo .xlsx, leitor seguro e importação com prévia (acrescentar e atualizar, nada apagado)
- [x] 167-10-PLAN.md — controller, rotas com throttle próprio, allowlist do domínio, Produtos 1º do menu, entrada do Mapeamento (D-21) e testes de acesso
- [x] 167-11-PLAN.md — tela de Produtos: tabela editável com gravação por linha, + variação, estado vazio e confirmação de exclusão
- [x] 167-12-PLAN.md — Lista SKUs com selo "do Produtos" e campos protegidos; Precificação com o custo do produto somente leitura
- [x] 167-13-PLAN.md — família/ambiente escolhidos na célula (criar uma vez) e editor de volumes dentro da grade
- [x] 167-14-PLAN.md — categoria real do ML na célula, sugestões em lote revisadas e consulta de fretes no ML
- [x] 167-15-PLAN.md — janelas de importação com prévia e de famílias e ambientes; menu Planilha
- [x] 167-16-PLAN.md — celular: cartões por produto e formulário em Sheet
- [x] 167-17-PLAN.md — gate final contra o baseline, prova final no MariaDB, gabarito da planilha real, conferência visual (checkpoint) e learnings §31
- [x] 167-18-PLAN.md — fechamento de lacuna do checkpoint do 167-17 (D-23/D-24): sem planilha na tela — lista de cartões + ficha do produto (painel lateral no computador, folha de baixo no celular); importação por arquivo mantida
- [x] 167-19-PLAN.md — fechamento de lacuna (D-25..D-30, REF-2): ficha do produto em PÁGINA INTEIRA com URL própria (`/portal/estrutura/produtos/novo` e `/{id}`, allowlist sem curinga), regra do painel extraída para `useFichaProduto`, dados gerais, variações, volumes e calculados só leitura; fim do painel lateral
- [x] 167-20-PLAN.md — fechamento de lacuna (D-25/D-26/D-30, REF-1 e REF-3): topo das referências (trilha em círculos, ações com busca), seletor Visual grande / Lista guardado no navegador, cartões grandes e horizontais com bolinha de cor, "Falta" com detalhe e ⋮ com ações reais; volta da ficha preservando busca, página, modo e rolagem
- [x] 167-21-PLAN.md — passe de FIDELIDADE: capturas a 1586×992 dos 3 estados comparadas lado a lado com as referências (até 2 rodadas de ajuste), fluxo lista → ficha → lista provado no navegador, UI-SPEC "Revisão D-25..D-30" e gate final; a conferência humana volta para a Task 3 do 167-17


### Phase 168: Geração de ofertas a partir dos produtos — Combo, Kit e Combit sugeridos (aba Planejamento da planilha)

**Goal:** a aba **Planejamento** da `3Planejamento_Estrutural_ECF.xlsx` ("identificação da oferta") vira sistema. A partir dos produtos da Fase 167, o Mapeamento Estrutural **sugere** as ofertas que juntam produtos — **Combo** (mesmo produto, mais unidades), **Kit** (produtos diferentes) e **Combit** (kit com mais unidades de um item) — com a logística e o frete estimado de cada conjunto. A pessoa revisa e aceita, e as aceitas viram ofertas da Lista SKUs pela mesma regra de composição, já entrando na Precificação. Na planilha, 70 produtos viraram ~199 ofertas montadas à mão.
**Requirements**: PR168-01, PR168-02, PR168-03, PR168-04, PR168-05, PR168-06, PR168-07, PR168-08, PR168-09, PR168-10, PR168-11, PR168-12, PR168-13, PR168-14, PR168-15 (definidos em `168-RESEARCH.md`); decisões D-01..D-31 em `168-CONTEXT.md` (D-24..D-31: redesenho da tela de sugestões pela referência do usuário, 07/10)
**Depends on:** Phase 167 (produtos, variações, oferta simples ligada, logística e frete por volumes).
**Plans:** 19/21 plans executed

**Decidido com o usuário (06/10/2026, "faça o recomendado"):**

- Combinações: só pares de TIPO de uma lista curta da ECF (mesa + cadeira, mesa + banco, cama + criado-mudo...). Por cima, as regras duras da planilha: nunca misturar família e exigir ambiente em comum. Sem IA.
- Quantidades: padrão por tipo (cadeira 2/4/6, banqueta 2/3/4, mesa 1), que dá para mudar em cada produto.
- Nada vira oferta sozinho; descartada não volta.
- "Qual promoção dá mais lucro" fica para a fase de margem.

Plans:
**Wave 1**

- [x] 168-01-PLAN.md — baseline de testes G1..G8 antes de qualquer código (Onda 0)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 168-02-PLAN.md — vocabulário de tipos da ECF, inferência do tipo e lista-semente de pares medida na planilha real (checkpoint D-20)
- [x] 168-03-PLAN.md — peças puras: chave da composição, quantidades e variações em paralelo
- [x] 168-04-PLAN.md — peças puras: nome/SKU/avisos/porquê sugeridos e logística/custo do conjunto
- [x] 168-05-PLAN.md — lógica de navegador (marcação, edição, aceite com enviar injetado, guarda de saída) com testes comportamentais

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 168-06-PLAN.md — 4 tabelas novas só aditivas, semente aprovada, models e prova no MariaDB 10.4
- [x] 168-07-PLAN.md — gerador puro (regras duras, Combit dirigido, só 2 itens) e gabarito sintético 15/5/8

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 168-08-PLAN.md — retrato do catálogo da empresa (consultas fixas, escopo) e SugestoesService::gerar
- [x] 168-09-PLAN.md — backend do admin da ECF para tipos e pares (role:admin)

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 168-10-PLAN.md — lista: abas, painel sobre o conjunto, filtros/página no servidor, logística e frete da página, cotação sob demanda
- [x] 168-11-PLAN.md — aceitar (regera, lock, criar da Lista SKUs), descartar, restaurar e tipo do produto
- [x] 168-12-PLAN.md — tela admin Tipos e pares e extração do DevCard

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 168-13-PLAN.md — controller, rotas no portal.auth com throttle próprio e allowlist sem curinga

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 168-14-PLAN.md — página Sugestões de ofertas: aba Sugestões em cartões, barra de marcadas e guarda de saída

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 168-15-PLAN.md — abas Sem tipo e Descartadas, janela de tipo e entradas por Produtos e Lista SKUs

**Wave 9** *(blocked on Wave 8 completion)*

- [ ] 168-16-PLAN.md — gate final, MariaDB, gabarito real, prova no navegador (puppeteer) e conferência visual (checkpoint)

**Wave 10** *(lacuna do checkpoint do 168-16: redesenho pela referência `168-REF-1`)*

- [x] 168-17-PLAN.md — backend mínimo do redesenho: filtro Status por avisos com contagens, resumo dos cartões, ambientes do grupo e hora da carga
- [x] 168-18-PLAN.md — peças do redesenho: linha compacta da sugestão, edição em linha, cartões de resumo, barra de filtros, grupo e abas

**Wave 11** *(blocked on Wave 10 completion)*

- [x] 168-19-PLAN.md — página no desenho da referência, Sem tipo e Descartadas no container novo, saída do desenho antigo e build

**Wave 12** *(blocked on Wave 11 completion)*

- [x] 168-20-PLAN.md — prova no navegador (T1..T21) e passe de fidelidade com capturas comparadas à referência

**Wave 13** *(blocked on Wave 12 completion)*

- [ ] 168-21-PLAN.md — gate G1..G8, contrato de tela final e volta ao checkpoint humano (retomada da Task 3 do 168-16)

---

### Phase 176: Ficha do portal completa até o Publicador: estoque por variação, descrição do produto e Sincronizar que leva categoria, ficha técnica, variações, imagens, medidas e descrição preenchendo só o vazio

**Renumerada 172 → 176 em 2026-10-08:** o outro dev anexou a "Phase 172: Publicador Etapa 1 — Navegação" no mesmo dia; as Etapas 2–4 do handoff dele devem ocupar 173–175, por isso 176. Commits, comentários de código e nomes de teste desta fase seguem com "172" no texto (histórico) — leia-se 176.

**Goal:** tudo o que o cliente preenche na ficha do produto do portal chega ao Publicador ao clicar em "Sincronizar do Portal": a ficha ganha **estoque por variação** e **descrição do produto** (sob a regra de sigilo), e o Sincronizar passa a juntar as cores de um produto em UM rascunho (uma variação por cor, com SKU e estoque próprios) e a levar categoria, ficha técnica, medidas do pacote e fotos (WebP convertida), preenchendo só o vazio e sem nunca publicar; Combo, Kit e Combit recebem o que dá para derivar; a descrição do cliente vira matéria-prima da descrição MAG T8, gerada sozinha no rascunho vazio.
**Requirements**: FP176-01, FP176-02, FP176-03, FP176-04, FP176-05, FP176-06, FP176-07, FP176-08, FP176-09 (locais da fase, definidos em `176-RESEARCH.md`; decisões D-01..D-16 em `176-CONTEXT.md` — D-10..D-16 prevalecem sobre a pesquisa)
**Depends on:** Phase 168 (Geração de ofertas)
**Plans:** 13 plans

Plans:
**Wave 1**

- [x] 176-01-PLAN.md — baseline de testes (commit próprio) e as 3 colunas aditivas (estoque, descrição, `pub_produtos.estrutura_produto_id`) provadas no MariaDB local com `--path`

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 176-02-PLAN.md — estoque por variação na ficha do portal, ponta a ponta (0 ≠ vazio, não herda da 1ª variação)
- [x] 176-03-PLAN.md — agrupamento D-06: um produto do Publicador por produto do portal, adoção do legado sem publicação, compostas como hoje, `situacaoPortal`
- [x] 176-04-PLAN.md — peças puras: campo do portal -> valor do rascunho (multivalor D-13) e composição (estoque, principal D-12, pacotes, descrições)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 176-05-PLAN.md — descrição do produto na ficha do portal (endpoint, bloco, Salvar) e sigilo dos campos novos
- [x] 176-06-PLAN.md — preço efetivo por variante no rascunho agrupado (editor, conferência e criativos)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 176-07-PLAN.md — leitor do portal escopado por empresa, WebP -> JPG, foto guardada sem envio ao ML e rascunho criado sem ler a conta

**Wave 5** *(blocked on Wave 4 completion)*

- [x] 176-08-PLAN.md — preenchimento do rascunho agrupado: categoria, ficha, pacote, uma variação por cor com SKU e estoque, só no vazio e idempotente
- [x] 176-09-PLAN.md — descrição MAG T8 a partir da ficha + descrição do cliente (Job na fila high, cache por pedido) e `estado().portal.descricao_cliente`

**Wave 6** *(blocked on Wave 5 completion)*

- [x] 176-10-PLAN.md — fotos por grupo da variação (só grupo vazio, motivos no resumo) e Combo/Kit/Combit no rascunho
- [x] 176-11-PLAN.md — editor: painel "Descrição do cliente", disparo automático uma vez e botão de gerar/regerar

**Wave 7** *(blocked on Wave 6 completion)*

- [x] 176-12-PLAN.md — botão Sincronizar dispara o preenchimento por produto, resumo na tela, provas de isolamento, D-10 e nada no ML

**Wave 8** *(blocked on Wave 7 completion)*

- [x] 176-13-PLAN.md — portão final contra o baseline, learnings e conferência visual do usuário numa cópia local da #459 (checkpoint; sem deploy)

## Milestone v24.0 — Creative Engine (Fases 160-163)

**Plano canônico:** `plano-incubadora-v1` (raiz do repo), §§7-20 · **Requirements:** `.planning/REQUIREMENTS-v24.md` · **Spike V0.1 (já entregue, não replanejar):** quick task `261001-nkx`, medições completas em `.planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md` (seções 1-20).

**Goal:** transformar a etapa visual do publicador numa linha de produção assistida por IA — o operador sobe as fotos originais do cliente, clica em gerar, revisa, regenera o que não prestou, aprova, e as imagens aprovadas seguem para a publicação no Mercado Livre. A aprovação humana continua obrigatória em toda fase.

**Decisões travadas (usuário, 2026-10-01/02 — D-01 a D-06 do REQUIREMENTS-v24.md, NÃO reabrir):** D-01 o Creative Engine mora DENTRO de `/mlb/anuncios` sob `role:admin` (`routes/mlb_anuncios.php`, name `mlb.anuncios.*`), não no módulo Incubadora · D-02 a foto original é **upload efêmero** — fica em disco privado com retenção curta, nunca acervo permanente, sem integração programática com o Google Drive do cliente · D-03 modo de renderização único desta milestone é `FULL_AI`; `COMPOSITE` fica preparado na arquitetura mas **não se constrói agora** (medição do spike nas seções 17-19 das notas mostrou que texto/número DADO no prompt sai correto nos três modelos — `COMPOSITE` não é pré-requisito de confiabilidade, só controle fino de layout futuro) · D-04 modelo default `gemini-3.1-flash-image` em 2K (único sem erro de contagem entre os conferidos no kit de 7; `lite` descartado para ambientação, `pro` alcançável por env) · **D-05 fatia fina ponta a ponta primeiro** — é a decisão que molda a Fase 160 e proíbe roadmap de camada-por-camada nesta milestone · D-06 o validador usa o Gemini como **juiz de visão** (imagem gerada + fotos originais + Product Truth → JSON estruturado), custo aceito (~+US$ 0,30/kit).

**Reuso já identificado (não construir do zero):** `App\Services\Creative\Contracts\ImageGenerationProvider` + `GeminiImageProvider` + DTOs `CreativeGenerationRequest`/`CreativeGenerationResult` + `FalhaDeGeracaoTrocavel` (spike V0.1, intactos) · o padrão job-fila-`high`+polling já maduro em `App\Services\Ia\AnaliseAnuncioService` / `GerarAnaliseAnuncioIaJob` / `PainelAnunciarIa.jsx` (trava anti-loop `LIMITE_MINUTOS=15` de `ml_anuncio_ia_analises` é o precedente direto para VAL-06) · `App\Services\Mlb\Publicacao\MlImagemService::enviar()` para o envio real ao ML (PUB-02 proíbe caminho novo) · `ml_anuncio_rascunhos.payload.pictures` como destino das imagens aprovadas.

**Risco central:** o plano canônico nomeia tabelas `creative_projects`/`creative_assets`, mas a convenção real do módulo é prefixo `ml_` com colunas snake_case em pt-BR (`ml_anuncio_rascunhos`, `ml_anuncio_ia_analises`) — a Fase 160 decide o nome real na hora de planejar, não herda o nome do plano canônico literalmente. Risco secundário conhecido (não é regressão, é característica medida): os três modelos de imagem erram contagem de peça esporadicamente (§17-19 das notas) — é exatamente por isso que VAL (Fase 162) e a regeneração manual (Fase 161) não são enfeite, são a rede de segurança que o spike mediu como necessária.

**Ordem de construção (decrescente em risco, D-05):** a Fase 160 é a fundação arriscada — primeira vez que upload efêmero, context/truth builder, job assíncrono novo e aprovação chegam a produção juntos, mas entrega SÓ uma imagem, atrás de chave desligada. A Fase 161 escala de 1 para 7 com planejamento dinâmico e concorrência — não é usável ter 7 imagens sem conseguir regenerar a ruim ou aprovar o kit todo, por isso planner, geração paralela, regeneração manual, aprovação de kit e os guard-rails de publicação nascem **juntos** na mesma fase (anti-padrão de camada evitado de propósito). A Fase 162 adiciona confiabilidade automática (validador Gemini-juiz + regeneração automática) por cima de um kit que já funciona manualmente. A Fase 163 fecha o "→ V1" do nome da milestone: custo visível por projeto e a métrica real do POC contra anúncios já produzidos à mão.

### Phase 160: Fatia fina ponta a ponta — um criativo real, do upload à aprovação — ✅ COMPLETA (5/5, checkpoint humano aprovado em produção 2026-10-02)

**Goal**: o operador sobe a foto original de um produto já cadastrado no publicador, pede a geração de UMA imagem via Gemini, vê o resultado ao lado da foto original, aprova, e a imagem aprovada entra no rascunho do anúncio — mínima e feia, mas real e usável em produção, atrás de uma chave que a equipe desliga sem deploy.
**Depends on**: Nada como fase (fundação desta milestone); reusa o provider Gemini da V0.1 (spike `261001-nkx`), já em produção.
**Requirements**: FOTO-01, FOTO-02, FOTO-03, FOTO-04, FOTO-05, CTX-01, CTX-02, CTX-03, TRUTH-01, TRUTH-02, TRUTH-03, TRUTH-04, GEN-01, GEN-02, GEN-05, GEN-06, APROV-01, APROV-05, APROV-06, PUB-01, PUB-02, OPS-01, OPS-03

> ⚠️ **Nomenclatura de tabela nova.** O plano canônico (§10) sugere `creative_projects`/`creative_assets`, mas a convenção real do módulo é prefixo `ml_` + snake_case pt-BR (`ml_anuncio_rascunhos`, `ml_anuncio_ia_analises`). Decidir o nome real no planejamento desta fase, não herdar o nome do plano literalmente — ler `.planning/quick/261001-nkx-.../261001-nkx-NOTAS-PUBLICADOR.md` §3 antes de desenhar a migration.
> ⚠️ **Reusar o padrão job+fila+polling já maduro**, não inventar um novo: mesmo desenho de `GerarAnaliseAnuncioIaJob` (fila `high`, nunca `default` — medido em produção com 170 jobs represados e 395s de espera) e `PainelAnunciarIa.jsx` (componente separado com polling). Nenhum código novo inline em `AnunciarML.jsx` (2857 linhas).

**Success Criteria** (o que deve ser VERDADE):

  1. Na etapa de criativos do publicador (`/mlb/anuncios`), o operador sobe uma ou mais fotos originais do produto sem sair do fluxo; upload inválido (tipo/tamanho) é recusado com mensagem em pt-BR, e o escopo por empresa é conferido no servidor antes de aceitar (FOTO-01, FOTO-04, FOTO-05)
  2. As fotos enviadas nunca ficam acessíveis por URL pública/adivinhável e são apagadas automaticamente depois de cumprirem seu papel na geração — o sistema não acumula acervo de imagem de cliente (FOTO-02, FOTO-03)
  3. Clicar em "Gerar com IA" dispara um job na fila `high` (nunca `default`) que monta o contexto a partir do que o anúncio já tem — produto, marca, título, descrição, categoria, atributos, variações e as fotos enviadas como bytes — sem formulário duplicado, aplica o Product Truth (só fatos do cadastro ou de leitura conferida; contagem nunca por suposição; claims proibidas explícitas; o que não está no Truth fica fora do prompt) e devolve UMA imagem gerada pelo Gemini, sem bloquear a requisição HTTP (CTX-01, CTX-02, CTX-03, TRUTH-01, TRUTH-02, TRUTH-03, TRUTH-04, GEN-01, GEN-02)
  4. O operador vê a imagem gerada ao lado da foto original usada como referência num componente separado com polling (padrão `PainelAnunciarIa`), aprova, e só então ela entra em `ml_anuncio_rascunhos.payload.pictures` respeitando o fluxo real de envio ao Mercado Livre via `MlImagemService::enviar()` — nenhuma imagem não aprovada chega ao rascunho (APROV-01, APROV-05, APROV-06, PUB-01, PUB-02)
  5. O módulo nasce atrás de uma chave que a equipe desliga sem deploy; a chave do Gemini nunca aparece em log, payload de log ou base64 registrado; e um duplo clique em "Gerar com IA" não dispara dois jobs para o mesmo produto (OPS-01, OPS-03, GEN-05, GEN-06)

**Plans**: 5 plans (fatias verticais, uma por wave — a próxima só começa quando a anterior está demonstrável)
- [ ] 160-01-PLAN.md — Upload efêmero da foto de referência: tabela `ml_anuncio_criativos`, chave liga/desliga sem deploy e painel separado na etapa 5
- [ ] 160-02-PLAN.md — Gerar UMA imagem de verdade: contexto + Product Truth + prompt, job na fila `high` e revisão lado a lado com polling
- [ ] 160-03-PLAN.md — Aprovar: envio ao ML por `MlImagemService::enviar()` e gravação em `payload.pictures`
- [ ] 160-04-PLAN.md — Retenção da foto efêmera (deleção na aprovação + varredura por idade do registro) e teste-guarda de segredo em log
- [ ] 160-05-PLAN.md — Checkpoint humano: prova real com foto de cliente, números literais e a chave de volta ao desligado

**UI hint**: yes

### Phase 161: Kit dinâmico de 7, geração paralela, regeneração e aprovação do kit

**Goal**: em vez de uma imagem fixa, o sistema planeja e gera um kit completo de 7 criativos escolhidos dinamicamente conforme o produto, com progresso por imagem, regeneração individual sem refazer o kit inteiro, aprovação do kit como um todo, e guarda-corpos antes de publicar — a experiência real de produção que a Fase 160 só provou em miniatura.
**Depends on**: Fase 160 (precisa do pipeline ponta a ponta — upload, contexto, truth, geração, aprovação, publicação — já provado com 1 imagem antes de escalar para 7).
**Requirements**: PLAN-01, PLAN-02, PLAN-03, PLAN-04, GEN-03, GEN-04, APROV-02, APROV-03, PUB-03, PUB-04, OPS-04

**Success Criteria** (o que deve ser VERDADE):

  1. Antes de gerar qualquer imagem, o sistema planeja no mínimo 7 criativos: o slot 1 é sempre a imagem principal e os slots 2-7 são escolhidos dinamicamente conforme produto, categoria e fatos disponíveis no Product Truth — nunca uma lista fixa — e o planejador só propõe slot cujo fato o Product Truth sustenta; o plano fica gravado para auditoria (PLAN-01, PLAN-02, PLAN-03, PLAN-04)
  2. Os 7 assets geram em paralelo controlado respeitando limite de custo/cota, e o operador acompanha o progresso de cada imagem individualmente na mesma tela — a falha de gerar uma não trava as outras 6 (GEN-03, GEN-04)
  3. O operador regenera uma imagem individual do kit sem precisar refazer as outras 6, e consegue tanto aprovar imagem por imagem quanto aprovar o kit inteiro de uma vez quando o critério mínimo é atingido (APROV-02, APROV-03)
  4. Antes de publicar, o sistema confere que existe kit aprovado com o mínimo de imagens atingido e que o limite de imagens da categoria do Mercado Livre é respeitado — publicar fora dessas condições é recusado (PUB-03, PUB-04)
  5. Gerar, regenerar e aprovar exigem permissão explícita verificada no servidor — não é só o gate `role:admin` do módulo (OPS-04)

**Plans**: 5 plans (fatias verticais, uma por wave — a próxima só começa quando a anterior está demonstrável, mesmo desenho da Fase 160)
- [ ] 161-01-PLAN.md — Kit planejado: tabela de kit + colunas aditivas, catálogo de slots, planner híbrido com reconciliação determinística, permissão explícita e os 7 slots visíveis na tela (sem gerar imagem)
- [ ] 161-02-PLAN.md — As 7 imagens de verdade: prompt por slot, unicidade do job por criativo, despacho em ondas com teto de custo e progresso por imagem
- [ ] 161-03-PLAN.md — Regenerar a ruim, aprovar imagem por imagem e aprovar o kit, com a ordem dos slots e o limite de fotos da categoria
- [ ] 161-04-PLAN.md — Guarda-corpo da publicação: kit aprovado obrigatório e re-aplicação das imagens no único chokepoint de publicação
- [ ] 161-05-PLAN.md — Checkpoint humano: kit real de 7 com cota paga (~US$ 0,71), números lidos da tabela e estado deixado em produção
**UI hint**: yes

### Phase 162: Validação automática (Gemini como juiz), regeneração automática e alerta de risco

**Goal**: toda imagem gerada passa por um validador que usa o próprio Gemini como juiz de visão antes de chegar à revisão humana, reprova automaticamente falha de fidelidade ao produto, regenera uma vez sozinho usando o motivo da rejeição, e avisa o operador em pt-BR quando algo ficou arriscado — a rede de segurança que o spike (§17-19 das notas) mediu como necessária, porque os três modelos erram contagem esporadicamente e em slots diferentes.
**Depends on**: Fase 161 (precisa do kit de 7 e da regeneração manual já existirem — o validador decide QUANDO acionar regeneração automática sobre a mesma infraestrutura).
**Requirements**: VAL-01, VAL-02, VAL-03, VAL-04, VAL-05, VAL-06, APROV-04

> ⚠️ **Reusar a disciplina de trava anti-loop já existente**, não inventar uma nova: `ml_anuncio_ia_analises.LIMITE_MINUTOS=15` é o precedente direto para VAL-06 (geração viva além do limite vira erro com mensagem, a tela nunca pergunta para sempre).

**Success Criteria** (o que deve ser VERDADE):

  1. Toda imagem gerada é validada automaticamente antes de chegar à revisão humana: o validador recebe a imagem gerada, as fotos originais e o Product Truth, e devolve um JSON estruturado que explica o problema — nunca só uma nota numérica (VAL-01, VAL-02, VAL-03)
  2. Fidelidade ao produto é critério eliminatório: imagem que altera o produto é reprovada automaticamente, nunca só penalizada por nota (VAL-04)
  3. Imagem reprovada é regenerada automaticamente uma vez usando o motivo da rejeição, com tentativas limitadas por asset — nunca um loop infinito (VAL-05, VAL-06)
  4. Quando a validação aponta risco (inclusive depois da regeneração automática), a tela mostra o alerta ao lado da imagem com o motivo legível em pt-BR — nunca um código de erro técnico (APROV-04)

**Plans**: 5 plans
**UI hint**: yes

Plans:
- [ ] 162-01-PLAN.md — juiz de visão: contrato novo de julgamento, caminho aditivo no GeminiImageProvider, prompt em pt-BR pedindo JSON, reconciliação no servidor, colunas de validação e comando `creative:validar-criativo`
- [ ] 162-02-PLAN.md — validação automática no fluxo: `ValidarCriativoIaJob` na fila `creative`, trava de tempo (VAL-06) e gate de aprovação por slot e do kit (com confirmação de risco)
- [ ] 162-03-PLAN.md — regeneração automática uma vez pelo motivo da rejeição, dentro do orçamento de regeneração já existente
- [ ] 162-04-PLAN.md — alerta de risco em pt-BR na `KitCriativosGrade`, campos de validação na whitelist do `kit.status` e aprovação com risco assumido em dois passos
- [ ] 162-05-PLAN.md — gate contra a baseline, checkpoint humano com custo declarado (até ~US$ 1,00) e learnings do validador

### Phase 163: Fechamento do POC — custo por projeto e medição com anúncios reais

**Goal**: a equipe consegue ver o custo de cada projeto de criativos e decidir, com anúncios reais já produzidos à mão, se o Creative Engine está pronto para uso contínuo em produção — o "→ V1" do nome desta milestone.
**Depends on**: Fases 160, 161 e 162 (custo por projeto só é completo depois que os três tipos de chamada — planejamento, geração e validação — existem; e o POC real precisa do kit de 7 com validação funcionando).
**Requirements**: OPS-02

**Success Criteria** (o que deve ser VERDADE):

  1. Para qualquer projeto de criativos, é possível ver a quantidade de chamadas de planejamento, geração e validação, e as tentativas por asset — sem abrir log ou banco diretamente (OPS-02)
  2. A equipe roda o Creative Engine contra pelo menos um anúncio real já produzido manualmente pela equipe e registra, lado a lado, os números do §"Métricas de aceite do POC" do REQUIREMENTS-v24.md — percentual aprovado sem regeneração, média de regenerações por kit, tempo até o kit ficar revisável, custo médio por kit e motivos mais comuns de rejeição (OPS-02 + avaliação qualitativa do POC, sem REQ-ID próprio — métrica de aceite, não comportamento de sistema)
  3. A chave de ativação do Creative Engine (OPS-03, Fase 160) permanece desligada em produção até essa medição real sustentar a decisão de ligar — ligar é decisão humana explícita, não consequência automática de passar os testes

**Plans**: TBD
**UI hint**: yes

**Fora de escopo desta milestone** (REQUIREMENTS-v24.md, não mapear em nenhuma fase): modo `COMPOSITE`/template engine de texto e badges — não é pré-requisito, medido no spike · integração programática com o Google Drive do cliente (D-02) · segmentação/recorte de produto para Product Lock mais forte · comparação com outros provedores de imagem · variantes A/B, geração em lote e biblioteca de templates por categoria · refazer qualquer parte do publicador existente.

#### Coverage Map — Milestone v24.0

| Requirement | Phase | Status |
|---|---|---|
| FOTO-01, FOTO-02, FOTO-03, FOTO-04, FOTO-05 | Phase 160 | Pending |
| CTX-01, CTX-02, CTX-03 | Phase 160 | Pending |
| TRUTH-01, TRUTH-02, TRUTH-03, TRUTH-04 | Phase 160 | Pending |
| GEN-01, GEN-02, GEN-05, GEN-06 | Phase 160 | Pending |
| APROV-01, APROV-05, APROV-06 | Phase 160 | Pending |
| PUB-01, PUB-02 | Phase 160 | Pending |
| OPS-01, OPS-03 | Phase 160 | Pending |
| PLAN-01, PLAN-02, PLAN-03, PLAN-04 | Phase 161 | Pending |
| GEN-03, GEN-04 | Phase 161 | Pending |
| APROV-02, APROV-03 | Phase 161 | Pending |
| PUB-03, PUB-04 | Phase 161 | Pending |
| OPS-04 | Phase 161 | Pending |
| VAL-01, VAL-02, VAL-03, VAL-04, VAL-05, VAL-06 | Phase 162 | Pending |
| APROV-04 | Phase 162 | Pending |
| OPS-02 | Phase 163 | Pending |

**Cobertura:** 42/42 REQ-IDs do REQUIREMENTS-v24.md mapeados — FOTO(5) CTX(3) TRUTH(4) PLAN(4) GEN(6) VAL(6) APROV(6) PUB(4) OPS(4). Nenhum órfão, nenhuma duplicata.

---

*Roadmap atualizado: 2026-10-02 — **Milestone v24.0 (Creative Engine)** anexada: 4 fases (160-163) cobrindo os 42 REQ-IDs (FOTO/CTX/TRUTH/PLAN/GEN/VAL/APROV/PUB/OPS) do REQUIREMENTS-v24.md, derivadas do plano canônico `plano-incubadora-v1` §§7-20 e corrigidas pelas medições reais do spike V0.1 (`261001-nkx-NOTAS-PUBLICADOR.md`, seções 1-20) — pesquisa de arquitetura dispensada porque a investigação já foi feita no spike. Estrutura deliberadamente NÃO em camadas (fundação→contexto→planner→geração→validação→UI): por decisão explícita do usuário (D-05), a Fase 160 entrega uma fatia fina ponta a ponta — upload, contexto, Product Truth, UMA imagem gerada, aprovação humana e entrada no rascunho, tudo atrás de chave desligada — e cada fase seguinte enriquece sem nunca ficar isolada numa camada técnica: a Fase 161 escala de 1 para 7 imagens com planejamento dinâmico, geração paralela, regeneração manual e aprovação de kit (entregues juntos de propósito, porque um kit de 7 sem conseguir corrigir a imagem ruim não é usável), a Fase 162 soma o validador Gemini-como-juiz e a regeneração automática por cima do kit que já funciona manualmente, e a Fase 163 fecha o "→ V1" do nome da milestone com custo visível por projeto e a medição real do POC contra anúncios já produzidos à mão. Apenas 4 fases em vez da faixa sugerida de 5-7: os 42 requisitos se agrupam naturalmente em 3 blocos de risco decrescente mais o fechamento do POC — forçar uma 5ª fase exigiria separar partes que a própria regra anti-camada deste roadmap proíbe separar (ex.: aprovar o kit sem poder regenerar a imagem ruim do mesmo kit). Numeração contínua a partir de 160 (última fase existente: 159, da pessoa com dois cargos, fora de milestone). `phases.clear` NÃO foi executado — Fases 1-159 preservadas integralmente, incluindo a milestone v22.0 em 71% e a Fase 159-08 adiada pelo usuário em 2026-10-01 (ver `.planning/todos/pending/159-juncao-danilo-segundo-passe.md`); nenhuma fase, decisão ou numeração anterior foi tocada.*

*Roadmap atualizado: 2026-10-04 - **Fase 165 (Creative Engine no Publicador novo)** anexada como fase avulsa, fora da milestone v24.0 (que é do outro dev). Origem: o usuário pediu o gerador de imagens dentro do Publicador novo e escolheu a integração de verdade (não a ponte por rascunho espelho). GSD porque altera `ml_anuncio_criativos` e `ml_anuncio_criativo_kits`, que têm dado em produção. Execução espera o ok do outro dev sobre a ordem com a Fase 162. Fases 1-164 preservadas.*

## Milestone v25.0 — Creative Engine V2 (Fases 168-171)

**Plano canônico:** seis melhorias do usuário em 2026-10-07, depois de usar o Creative Engine (Fase
165) em produção · **Requirements:** `.planning/REQUIREMENTS-v25.md` · **Base:** v24.0 (Creative
Engine, Fases 160-163, em produção) e Fase 165 (Creative Engine dentro do Publicador, em produção).

**Goal:** o Creative Engine deixa de entregar um kit de imagens genérico e passa a entregar um
resultado que uma empresa especialista em anúncios de marketplace assinaria: capa certa por tipo de
anúncio, ambiente brasileiro sutil nos slots ambientados, uma etapa própria no editor onde o fato do
produto vira texto real na imagem, identidade visual e logo opcional por conta, e um acervo navegável
que reaproveita o que já foi pago em vez de regerar. **Não é correção de defeito** — tudo que existe
hoje funciona; é evolução de resultado.

**Decisões travadas (usuário, 2026-10-07 — D1 a D6 do REQUIREMENTS-v25.md, NÃO reabrir):** D1 quarta
etapa "Imagens" entre Detalhes e Condições de venda no editor do Publicador — reversão parcial e
deliberada de uma decisão do próprio usuário de 04/10 ("no Mercado Livre são 3 fases"), porque a
nova etapa também recebe identidade visual (D2) e acervo (D4) · D2 identidade visual cadastrada por
CONTA de marketplace (âncora dual `Company`/`MlbEmpresa`), nunca por empresa cliente nem por produto
· D3 logo é arquivo real sobreposto pelo sistema, nunca desenhado pela IA, e opcional · D4 as imagens
geradas já são guardadas para sempre (comportamento atual, não uma escolha desta milestone) — o
trabalho é tornar esse acervo navegável e reutilizável · D5 ambiente brasileiro subentendido (nunca
explícito) só nos slots `lifestyle`/`lifestyle_uso`/`composicao`, nunca em `hero`/`white_background`
· D6 capa diferente entre Clássico e Premium do mesmo produto — troca de ORDEM de envio por
`listing_type_id`, não geração de imagens diferentes.

**O achado que molda a Fase 169:** o usuário relatou imagens sem texto nenhum, apesar de pedir
pontos fortes e medidas. Medido: a trava que exige fato verificado antes de aceitar um slot de texto
(`CreativeSlotCatalog`) **funcionou como projetada** — um produto real em produção tinha só um
atributo de SEO e descrição vazia, então nenhum dos 7 slots escolhidos aceitava texto. A causa raiz
não é a trava; é a ordem do editor, que deixa a pessoa gerar ANTES de preencher qualquer fato do
produto (Fotos é a primeira seção de Detalhes, acima da Ficha técnica). D1 existe para corrigir essa
ordem; TXT (Fase 169) garante que, mesmo com D1, o operador tenha como confirmar fato à mão quando o
cadastro automático não bastar, e que a tela explique quando não há fato suficiente — nunca afrouxar
TRUTH-02/03 da v24.0 para aceitar número por suposição.

**Ordem de construção escolhida — avalia e confirma a sugestão original, com um ajuste de
agrupamento:** a sugestão (5+6 primeiro, depois 1+2, depois 3, depois 4) está certa na essência e foi
adotada quase literalmente, com uma mudança: as frentes 5 (capa) e 6 (ambiente) viram UMA fase, não
duas.

1. **Fase 168 — Capa alternada + ambiente brasileiro (D6 + D5).** Primeiro porque são as duas únicas
   frentes sem nenhuma dependência de UI nem de coordenação externa: tocam só
   `CreativeSlotCatalog`/ordem de publicação e `CreativePromptBuilder` — mudança de prompt e de
   ordem de envio, sem migration, sem tela nova. Todo kit gerado a partir do deploy desta fase já sai
   melhor, mesmo antes de qualquer trabalho na etapa nova. Juntar as duas numa fase só (em vez de duas
   fases de ~1-2 planos cada) evita o anti-padrão de fase-tarefa-única deste roadmap: a entrega
   observável de ambas é a mesma — "o próximo kit gerado já sai melhor, sem mudar nenhuma tela" — e
   forçar uma 5ª fase para separá-las violaria a regra de calibrar granularidade pelo trabalho real,
   não por um número-alvo.
2. **Fase 169 — Etapa de Imagens + texto real no kit (D1 + o achado de texto, frentes 1+2).** Em
   seguida porque é a frente que o usuário mais sentiu falta e a única que dá sentido a tocar no
   coração do Publicador. As duas frentes nascem juntas de propósito: entregar SÓ a etapa reordenada
   (sem a confirmação de fato e o aviso da tela) seria uma fase de infraestrutura que não resolve o
   que o usuário pediu — o cadastro já preenchido continua não tendo fato suficiente até alguém
   confirmar à mão; entregar SÓ a confirmação de fato sem a etapa nova deixaria a UI de Fotos ainda
   antes dos campos de detalhamento, contradizendo a própria causa raiz medida. Depende de
   negociação explícita com o ECF Dev (dono do Publicador) ANTES de tocar em `Editor.jsx`/`apoio.js`
   — gate humano, não detalhe de rodapé; registrado também em
   `.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md`.
3. **Fase 170 — Identidade visual por conta + logo opcional (D2 + D3, frente 3).** Depois da etapa
   nova existir, porque o próprio usuário definiu a etapa Imagens como o lugar que recebe a
   identidade visual — cadastrar identidade antes de ter onde ela aparece no fluxo do operador
   adiantaria trabalho sem lugar para pousar. D2 e D3 ficam juntos porque são uma única capacidade
   observável: "toda imagem desta conta sai com a mesma cara de marca, e opcionalmente com o logo de
   verdade em cima" — não dois produtos diferentes.
4. **Fase 171 — Acervo navegável e reuso (D4, frente 4).** Por último, de propósito: é a frente de
   menor urgência medida (as imagens já não se perdem hoje; só não são navegáveis) e a que mais se
   apoia nas anteriores — vive na mesma etapa Imagens (D1) e fica mais útil depois que identidade e
   capa já deixam o acervo com imagens melhores para reaproveitar.

### Phase 168: Capa alternada entre Clássico e Premium + ambiente brasileiro subentendido

**Goal**: todo kit gerado a partir desta fase já sai melhor sem o operador fazer nada diferente — a
capa de um anúncio Clássico nunca repete a capa do Premium do mesmo produto, e as imagens dos slots
ambientados (`lifestyle`, `lifestyle_uso`, `composicao`) sugerem um ambiente brasileiro sem citar o
país, enquanto `hero` e `white_background` continuam exatamente como hoje.
**Depends on**: Nada como fase — reusa `CreativeSlotCatalog` e `CreativePromptBuilder` (v24.0) e a
publicação por `listing_type_id` do Publicador (Fase 164), já em produção.
**Requirements**: CAPA-01, CAPA-02, CAPA-03, CAPA-04, AMB-01, AMB-02, AMB-03, AMB-04

**Success Criteria** (o que deve ser VERDADE):

  1. Publicando o mesmo produto como Clássico e como Premium, a primeira foto enviada ao Mercado
     Livre é diferente entre os dois anúncios — nunca a mesma imagem na posição de capa dos dois
     (CAPA-01, CAPA-02)
  2. Publicando o produto só como Clássico OU só como Premium, a capa continua sendo a primeira foto
     aprovada, como hoje — a troca de ordem só existe quando os dois tipos coexistem no mesmo produto
     (CAPA-03, CAPA-04)
  3. Uma imagem gerada no slot `lifestyle`, `lifestyle_uso` ou `composicao` mostra um ambiente com
     sinais sutis de Brasil (luz, acabamentos, plantas, paleta) sem nenhum símbolo nacional, bandeira,
     verde-amarelo ou referência a futebol (AMB-01, AMB-02)
  4. Uma imagem gerada no slot `hero` ou `white_background` não muda em nada — mesmo prompt de hoje,
     sem ambientação (AMB-03)

**Plans**: TBD

### Phase 169: Etapa de Imagens no editor + texto real no kit, a partir dos fatos de Detalhes

**Goal**: o editor do Publicador ganha uma quarta etapa — Produto → Detalhes → Imagens → Condições de
venda —, de modo que o operador preenche os fatos do produto antes de gerar; nessa etapa, ele também
confirma ou complementa pontos fortes e medidas quando o cadastro automático não basta, e pelo menos
um slot do kit sai com texto real (benefícios ou medidas) sempre que o Product Truth sustentar —
nunca por suposição, e a tela diz claramente quando não há fato suficiente para isso.
**Depends on**: Fase 165 (painel nativo de geração por IA dentro do editor, já em produção) e **gate
humano explícito: negociação e aprovação da mudança de etapas com o ECF Dev (dono do Publicador)
ANTES de tocar em `Editor.jsx`, `apoio.js` (`ETAPAS`) ou `usePublicador`** — registrado em
`.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md`.
**Requirements**: EDIMG-01, EDIMG-02, EDIMG-03, EDIMG-04, TXT-01, TXT-02, TXT-03, TXT-04, TXT-05,
REND-01, REND-02

**Success Criteria** (o que deve ser VERDADE):

  1. O editor do Publicador mostra 4 etapas na ordem Produto → Detalhes → Imagens → Condições de
     venda; a navegação, a URL (`?etapa=`) e o `sessionStorage` por produto continuam funcionando como
     nas 3 etapas atuais (EDIMG-01)
  2. A seção "Fotos e variações" sai de Detalhes e aparece em Imagens, com o painel "Gerar com IA" da
     Fase 165 funcionando sem duplicação de código; uma pendência de foto do servidor leva à etapa
     Imagens, não mais a Detalhes (EDIMG-02, EDIMG-03, EDIMG-04)
  3. Na etapa Imagens, o operador confirma ou digita um ponto forte ou uma medida que falta no
     cadastro, e essa confirmação conta como fato verificado (de origem humana, distinta do cadastro)
     no Product Truth daquele produto (TXT-01, TXT-02)
  4. Com fato suficiente (do cadastro e/ou confirmado à mão), o kit de 7 inclui pelo menos um slot com
     texto real — benefícios ou medidas — em vez de só slots sem fato; sem fato suficiente, a tela diz
     isso em pt-BR e indica o que falta (TXT-03, TXT-04)
  5. Todo campo novo que uma tela desta fase exibe é coberto por um teste que renderiza o componente
     de verdade com o JSON real do presenter — nenhum campo chega como objeto a um lugar que espera
     texto (REND-01, REND-02)

**Plans**: TBD
**UI hint**: yes

### Phase 170: Identidade visual por conta de marketplace

**Goal**: cada conta de marketplace (não cada empresa cliente, não cada produto) tem uma identidade
visual cadastrada uma vez — cores, fontes, forma, filtros, estilo — que entra automaticamente no
prompt de toda geração daquela conta, e pode, opcionalmente, ter um arquivo de logo real sobreposto
pelo sistema depois da imagem gerada, nunca desenhado pela IA.
**Depends on**: Fase 169 (a etapa Imagens é o lugar de onde o cadastro de identidade é acessado,
decisão do próprio usuário) e Fase 162/v24.0 (validador Gemini-juiz, para decidir a ordem com LOGO-05).
**Requirements**: IDENT-01, IDENT-02, IDENT-03, IDENT-04, IDENT-05

⚠️ **LOGO-01..05 saíram desta fase em 2026-10-07, por decisão do usuário** — palavras dele:
*"Elimine o logo do planejamento, vamos seguir sem ele, a identidade visual por empresa é mais
importante"*. Não foi esquecimento: ficam adiados, sem fase atribuída. Na mesma conversa ele pediu
que a identidade fosse **texto livre simples** ("pode ser apenas um texto mesmo onde o usuário
escreve as cores da empresa de preferência hexadecimal, fonte que a empresa usa"), não formulário
estruturado.

**Success Criteria** (o que deve ser VERDADE):

  1. É possível cadastrar, uma vez por conta de marketplace, uma identidade visual (cores, fontes,
     forma, filtros, estilo), acessível a partir da etapa Imagens do Publicador, só para `role:admin`
     (IDENT-01, IDENT-04)
  2. Toda imagem gerada para qualquer produto dessa conta reflete a identidade cadastrada no prompt,
     sem o operador repetir nada; conta sem identidade cadastrada gera exatamente como hoje (IDENT-02,
     IDENT-03)
  3. Alterar a identidade de uma conta não reprocessa imagens já geradas — só vale para gerações
     futuras (IDENT-05)
  4. O texto de identidade nunca autoriza afirmar fato novo sobre o produto (TRUTH-02/03): ele é
     forma — cor, fonte, filtro, estilo —, e em capa isolada (`hero`/`white_background`) não
     introduz cenário, objeto, texto nem marca d'água, para não ferir a moderação do Mercado Livre

**Plans**: TBD
**UI hint**: yes

### Phase 171: Acervo navegável e reuso das imagens já geradas

**Goal**: o operador encontra, por conta, por empresa e por produto, as imagens que o Creative Engine
já gerou — e que o sistema já guarda hoje, sem apagar — e consegue reaproveitar uma delas num anúncio
novo ou numa nova tentativa, sem pagar por uma geração que já existe.
**Depends on**: Fase 169 (a etapa Imagens é o lugar de onde o acervo é acessado, decisão do próprio
usuário) e Fase 170 (identidade/logo deixam o acervo com imagens melhores para reaproveitar, ainda
que sem dependência funcional estrita).
**Requirements**: ACERVO-01, ACERVO-02, ACERVO-03, ACERVO-04, ACERVO-05

**Success Criteria** (o que deve ser VERDADE):

  1. O operador navega as imagens já geradas filtrando por conta, por empresa e por produto, sem
     precisar gerar nada novo para ver o que já existe (ACERVO-01)
  2. O operador escolhe uma imagem do acervo e a usa num anúncio novo ou numa nova tentativa do mesmo
     produto, sem disparar nova geração nem novo custo (ACERVO-02)
  3. A navegação e o reuso endereçam cada imagem por id numérico escopado — nenhum token de criativo
     aparece no navegador (ACERVO-03)
  4. Reaproveitar uma imagem de uma empresa/conta não a disponibiliza para outra empresa/conta
     (ACERVO-05); nenhuma rotina passa a apagar imagem gerada — o comportamento de retenção de 48h
     continua restrito só às fotos de referência (ACERVO-04)

**Plans**: 2 planos — `171-01` (acervo no servidor: serviço, 3 rotas e reaproveitar) e `171-02` (a lista na etapa Imagens, com teste de render real)
**UI hint**: yes

**Fora de escopo desta milestone** (REQUIREMENTS-v25.md, não mapear em nenhuma fase): reabrir a
decisão da quarta etapa de outra forma · integração programática com o Google Drive do cliente ·
afrouxar TRUTH-02/03 da v24.0 · reconstruir o validador Gemini-juiz ou o motor de geração · edição
manual de imagem pelo operador (recorte, desenho, texto arrastável) · comparação com outros
provedores, variantes A/B, geração em lote · refazer qualquer parte do Publicador fora do
estritamente necessário para D1.

#### Coverage Map — Milestone v25.0

| Requirement | Phase | Status |
|---|---|---|
| CAPA-01, CAPA-02, CAPA-03, CAPA-04 | Phase 168 | Pending |
| AMB-01, AMB-02, AMB-03, AMB-04 | Phase 168 | Pending |
| EDIMG-01, EDIMG-02, EDIMG-03, EDIMG-04 | Phase 169 | Pending |
| TXT-01, TXT-02, TXT-03, TXT-04, TXT-05 | Phase 169 | Pending |
| REND-01, REND-02 | Phase 169 | Pending |
| IDENT-01, IDENT-02, IDENT-03, IDENT-04, IDENT-05 | Phase 170 | Pending |
| LOGO-01, LOGO-02, LOGO-03, LOGO-04, LOGO-05 | — (adiados em 07/10) | Deferred |
| ACERVO-01, ACERVO-02, ACERVO-03, ACERVO-04, ACERVO-05 | Phase 171 | Pending |

**Cobertura:** 34/34 REQ-IDs do REQUIREMENTS-v25.md mapeados — CAPA(4) AMB(4) EDIMG(4) TXT(5)
REND(2) IDENT(5) LOGO(5) ACERVO(5). Nenhum órfão, nenhuma duplicata.

---

*Roadmap atualizado: 2026-10-07 — **Milestone v25.0 (Creative Engine V2)** anexada: 4 fases (168-171)
cobrindo os 34 REQ-IDs (CAPA/AMB/EDIMG/TXT/REND/IDENT/LOGO/ACERVO) do REQUIREMENTS-v25.md, derivadas
de seis melhorias pedidas pelo usuário em 2026-10-07 depois de usar o Creative Engine (Fase 165) em
produção — não é correção de defeito, é evolução de resultado. Estrutura deliberadamente NÃO em
camadas: a Fase 168 (capa alternada Clássico×Premium + ambiente brasileiro subentendido) junta as
duas frentes mais baratas e sem dependência de UI numa fase só, para melhorar todo kit novo antes de
qualquer trabalho na etapa do editor; a Fase 169 junta a quarta etapa "Imagens" do editor com o texto
real no kit de propósito — nenhuma das duas resolve o problema relatado pelo usuário sozinha, e
depende de gate humano explícito de coordenação com o ECF Dev (dono do Publicador) antes de tocar em
`Editor.jsx`/`apoio.js`, registrado em `.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md`; a Fase 170
(identidade visual por conta + logo opcional) e a Fase 171 (acervo navegável e reuso) vêm depois
porque o próprio usuário definiu a etapa Imagens da Fase 169 como o lugar que as recebe. Ordem
escolhida quase idêntica à sugestão original do usuário ("5+6, depois 1+2, depois 3, depois 4"), com
um ajuste: as frentes 5 e 6 (capa e ambiente) foram fundidas numa fase só, por entregarem a mesma
capacidade observável ("o próximo kit já sai melhor, sem mudar tela nenhuma") sem precisar de uma 5ª
fase para separá-las. Achado que molda a Fase 169: a trava de texto do `CreativeSlotCatalog` (v24.0)
funcionou como projetada — um produto real em produção não tinha fato suficiente para nenhum slot de
texto; a causa raiz é a ordem do editor (Fotos antes dos campos de detalhamento), não a trava, que
segue intacta (TRUTH-02/03 da v24.0 não são reabertas). Numeração contínua a partir de 168 (última
fase existente: 167, Cadastro de Produto no Mapeamento Estrutural). `phases.clear` NÃO foi executado
— Fases 1-167 preservadas integralmente, incluindo a milestone v22.0 em 71%, a Fase 159-08 adiada e a
v24.0 (Fases 160-163) em produção; nenhuma fase, decisão ou numeração anterior foi tocada.*

## Fase avulsa — Publicador, reorganização do módulo (handoff de design, fora de milestone)

### Phase 172: Publicador Etapa 1 — Navegação

**Goal:** mudar só a forma de chegar e de se localizar em `/mlb/anuncios` — item próprio no menu, uma barra da conta única em todas as telas da empresa, um só nível de abas no lugar de `AreaTabs` + `ModoAnuncioTabs`, o nome "Publicador" nas trilhas, o programa inicial lembrado da sessão, e `/incubadora/publicador` descontinuado com redirect. **Nenhuma regra de negócio, nenhuma migration, toda URL de `/mlb/anuncios` continua funcionando.**

**Depends on:** nada (é a primeira etapa do handoff `design_handoff_publicador/`).

**Plans**: 7 planos em 3 waves — `172-01` (componentes `BarraDaConta`/`AbasDaConta`), `172-02` (prop `conta` nos controllers + programa inicial por sessão), `172-06` (redirect da Incubadora + item de menu) na wave 1; `172-03` (Produtos + Alavancas), `172-04` (Meus Anúncios + Histórico), `172-05` (Em massa + assistente antigo + rótulos) na wave 2; `172-07` (gates + checkpoint humano da lista "Sem regressão") na wave 3.

**Requirements**: NAV-01..NAV-06, derivados da seção 1 de `design_handoff_publicador/ETAPA-1-navegacao.md`.

**Duas decisões do usuário em 2026-10-08 que SOBREPÕEM a spec:**
1. **O módulo NÃO nasce oculto** — a spec mandava escondê-lo no Controle Dev até a liberação; ele decidiu liberar direto. Isso contornou um defeito confirmado no código: `app/Support/Modules.php:191` registra `route_prefix => 'mlb.anunciar'` (sem ponto final) enquanto as rotas reais são `mlb.anuncios.*`, e `rotaOculta()` só casa por prefixo quando a string termina em ponto — logo marcar esse módulo como oculto não esconde nada. **O defeito continua lá**, registrado em `deferred-items.md`.
2. **Programa inicial só lembra o último escolhido** (sessão → Polos). O ramo "setor do usuário → programa" da spec foi cortado: não existe no código e seria feature nova. ⚠️ Consequência assumida: o critério de aceite *"Usuário do setor Incubadora entra em /mlb/anuncios já no programa Incubadora"* **não é entregue** nesta fase.

*Roadmap atualizado: 2026-10-08 — Fase 172 anexada como fase avulsa, fora de milestone, seguindo a convenção deste roadmap de anexar sem tocar no que existe. Origem: o pacote `design_handoff_publicador/` (Etapas 1 e 2 especificadas; 3 e 4 só desenhadas). A conferência código×spec foi feita antes do planejamento e achou três divergências: o `route_prefix` do módulo (acima), a ausência completa de "programa inicial por setor" no código, e testes JS de gate por regex que a spec não menciona e que o critério de aceite "npm run test:js verde" obrigaria a tratar (`publicador-alavancas.test.js` e `publicador-entrada.test.js` leem `Produtos.jsx` por literal). Fases 1-171 preservadas.*

### Phase 173: Publicador Etapa 2 — Visão geral e Configurações da conta

**Goal:** duas abas novas na barra da conta — uma **Visão geral** que vira o destino ao abrir uma empresa (indicadores, "O que fazer agora", situação dos produtos, últimas publicações, integrações, identidade e quem publicou) e uma tela de **Configurações da conta** com a identidade visual em lugar próprio. **Nenhuma migration; nenhuma chamada ao Mercado Livre durante o carregamento.**

**Depends on:** Fase 172 (Etapa 1), em produção desde 08/10.

**Plans**: 7 planos em 4 waves — `173-01` (contagem por situação, busca de empresas, identidade por conta), `173-02` (extrai triagem e defasagem de `meus()`), `173-03` (abas novas + seletor de empresa com busca) na wave 1; `173-04` (o cálculo de risco: dedup entre as duas fontes de publicação) na wave 2; `173-05` (resto do painel + rotas) na wave 3; `173-06` (página Visão geral) e `173-07` (página Configurações) na wave 4.

**Três respostas que o handoff exigia antes do plano, já investigadas:**
1. O editor novo grava a publicação em `pub_publicacoes`: data em `iniciada_em`/`concluida_em`, autor no JSON `ator` (`{equipe, id, nome}`, gravado por `PublicacaoService::atorParaGravar()`). ⚠️ `ator.id` **nem sempre é usuário do sistema** (quando `equipe` é falso, é cliente pelo portal), e as linhas migradas do assistente antigo não têm `id` nenhum.
2. `creative_identidades_conta` guarda **data mas não autor** — "Salvo por" é impossível sem coluna nova, que a spec proíbe. Só "Salvo em".
3. A extrair: a contagem por situação de `MlbPublicadorEntradaController::produtos()` e os blocos de triagem/defasagem de `MlbAnuncioController::meus()`, mantendo `motivosTriagemDef()` como fonte única dos motivos.

**Decisão do usuário (08/10):** no bloco "Quem publicou", o ranking mostra **só a equipe**; publicações feitas pelo cliente no portal viram uma linha à parte e as migradas aparecem como "origem antiga" — ninguém some da conta e o ranking não mistura equipe com cliente.

*Roadmap atualizado: 2026-10-08 (segunda revisão) — **mudança de rumo decidida pelo usuário**, motivada pela divergência (a) acima: *"está acontecendo muita bagunça por causa desse antigo, vamos descontinuar o antigo e usar apenas o novo para tudo"*. Medido em produção antes de planejar: o assistente antigo tem **6 rascunhos, todos inacabados, e ZERO publicações** (nenhuma em 90 dias), enquanto o editor novo tem 12 produtos e 3 publicações, todas recentes — e a aba Histórico, que lê só a fonte antiga, está **vazia para todas as empresas**. Logo o defeito não era a Visão geral divergir do Histórico: era o Histórico olhar para a fonte que ninguém usa. Decisão dele: **"Histórico agora, descontinuar depois"** — nesta fase a Visão geral E o Histórico passam a ler o editor novo (fonte única, sem dedup); a remoção do antigo, que implica reconstruir o **Em massa** (a decisão 3 do handoff mandava mantê-lo lá) e definir o destino dos 6 rascunhos, vira etapa própria. Efeito nos planos: o cálculo de dedup — isolado numa wave por ser o mais arriscado — **deixou de existir**, as plans 04 e 05 originais foram fundidas, entrou uma plan nova para o Histórico, e a fase passou de 4 para **3 waves**, seguindo com 7 planos. "Anunciar semelhante" e duplicar lote ficam **desabilitados com explicação** para itens do editor novo (a rotina de duplicar só existe para o modelo antigo; construí-la é recurso novo, não troca de fonte) — sem perda real, já que hoje o Histórico está vazio e ninguém os usa.*

*Roadmap atualizado: 2026-10-08 — Fase 173 anexada, segunda etapa do pacote `design_handoff_publicador/`. O planejamento encontrou duas divergências entre a spec e o código que valem registro: (a) `MlbAnuncioController::historico()` lê **só** a fonte legada, então a "Visão geral" — que a spec manda somar as duas fontes — pode exibir um número que a aba Histórico de destino não reproduz, contrariando o critério "todo número bate com a aba"; (b) `Produtos.jsx` ignora a querystring hoje (filtro é estado local), então os links de "O que fazer agora" exigem um ajuste de leitura da URL que o handoff não previa. Fases 1-172 preservadas.*

### Phase 174: Descontinuar o assistente antigo de anúncios (exceto o Em massa)

**Goal:** fechar a última porta de entrada do Publicador para o assistente antigo que já não serve a nada — o rodapé "Gerar criativos no assistente antigo" da tela Produtos, obsoleto desde a Fase 165 — e registrar por escrito o que fica de pé, para ninguém tentar apagar o que sustenta o Em massa.

**Depends on:** Fases 165, 172 e 173, todas em produção.

**Plans**: 1 plano, wave única — `174-01` (remove a ponte dos criativos no controller e na página, ajusta os 2 testes que a travavam, e grava `174-DECISOES.md`).

**Decisão do usuário (08/10):** *"vamos descontinuar o antigo e usar apenas o novo para tudo"*, refinada no mesmo dia para **"reconstruir o Em massa mas futuramente, não agora"**. Logo esta fase NÃO apaga o assistente antigo: o Em massa (`AnunciarMassa.jsx`, `GradeAnuncioGlide.jsx`, `MlbAnuncioController`, tabela `ml_anuncio_rascunhos`) continua inteiro, e os 6 rascunhos inacabados em produção continuam alcançáveis.

*Roadmap atualizado: 2026-10-08 — Fase 174 anexada. O planejamento conferiu os caminhos de entrada um a um e a fase encolheu: `MeusAnuncios.jsx`, `ModalDetalheAnuncio.jsx` e `RascunhosPainel.jsx` abrem o wizard sempre com um `rascunho_id` **já existente** (nunca criam rascunho novo), então são caminho de resgate, não porta; "Anunciar semelhante" já saiu desabilitado com explicação na Fase 173; e `ModoAnuncioTabs.jsx`/`AreaTabs.jsx`, sem uso desde a 172, só podem ser apagados depois de uma semana em produção (revisitar a partir de 2026-10-15). A bagunça que motivou a decisão era **texto desatualizado**, não fluxo duplicado. Fases 1-173 preservadas. **Planejada, não executada** — adiada pelo usuário em favor da Etapa 3.*

### Phase 175: Publicador Etapa 3 — Produto e Fases

**Goal:** a tela do Produto e as Fases — um kit (Fase 2) é um `PubProduto` novo, com SKU próprio, ligado ao produto base, que herda o rascunho da Fase 1 por clone e pede confirmação só do que muda. **Primeira etapa do handoff com migration.** O núcleo de publicação (`App\Support\Publicador`) e os MLBs já publicados não mudam.

**Depends on:** Fases 172 e 173 (Etapas 1 e 2), em produção desde 08/10.

**Requirements**: derivados da seção 1 de `design_handoff_publicador/ETAPA-3-produto-fases.md`.

**Cinco respostas que o protocolo do handoff exigia antes do plano, levantadas no código em 08/10:**
1. **O clone cobre as tabelas, com três ressalvas.** `pub_variante_eixo_valores` não é citada na §5 e exige remapear ids antigos → novos (sem isso o kit nasce com variante sem combinação); `pub_rascunhos.identificacao`, que a §5 manda copiar, é **coluna morta** (nada em `app/` lê ou grava); e o `RascunhoSnapshot` já é quase o clone pronto, faltando só `dominio_id` e `schema_hash`, que precisam de cópia explícita para o kit não renegociar o schema da categoria.
2. **⚠️ Defeito que a §5 introduziria ao pé da letra:** "imagens e atribuições (mesmo arquivo, sem reupload)". O caminho é namespaced por rascunho (`publicador/{rascunho_id}/{sha}.jpg`) e `ImagemAssetService::remover()` apaga o arquivo do disco **antes** da linha — copiar a linha com o mesmo `caminho` faz "tirar uma foto do kit" apagar o arquivo debaixo do produto base. Decisão: copiar os bytes para o caminho novo e **preservar `ml_picture_id`/`ml_url`** (mesma conta do ML ⇒ continua sem reupload, que era o objetivo).
3. **O `planejar` não precisa de referência de outro produto.** `selecionarFotos()` só aceita foto do próprio rascunho; como o clone já copiou a foto 1 do base, a capa usa a cópia do próprio kit. Nada muda no endpoint. Kit de **1 slot** já funciona no núcleo (`array_slice(..., max($quantidade, 1))`), mas `$quantidade` vem do config global lido dentro do `PlanejarKitCriativosJob` — precisa de um terceiro parâmetro no construtor do job, **sem migration**.
4. **"Sugerir com IA": serviço irmão, não reaproveitamento direto.** O formato do `PalavrasChaveService` é o certo (pedir → Job → cache → estado, "nada é gravado no rascunho por trás da pessoa"), mas o ramo de título chama a API de termos mais buscados do ML e monta título de SEO — prompt errado para um kit. Entra um `SugestaoKitIaService` com o mesmo contrato, reusando `AnaliseAnuncioService` e o `max_title_length` do `CategorySchemaRepository`, **sem tocar em `ALVOS` nem no `executar()`**, que estão em produção. No momento do painel o rascunho do kit ainda não existe: o pedido é escopado ao rascunho do base, com chave de cache própria.
5. **Não existe fonte externa de estoque.** `EstruturaOferta` não tem coluna de estoque — o Portal não carrega isso. O estoque mora só em `pub_variantes.estoque` e `estoque_depositos` (JSON `store_id → quantidade`), digitado no editor, e `EditorRascunhoService` deriva `estoque` como soma dos depósitos quando há depósitos. Logo o `floor(base ÷ N)` é por variante e por depósito, e o recálculo dispara na gravação de dados de variante. ⚠️ O editor **não tem trava para rascunho publicado** (só criativos e IA têm `INTOCAVEIS`), então o estoque do base pode mudar depois de publicado e o recálculo vai reescrever o rascunho de um kit já publicado — é para isso que serve o aviso "Estoque no ML difere do calculado".

*Roadmap atualizado: 2026-10-08 — Fase 175 anexada, terceira etapa do pacote `design_handoff_publicador/`, aprovada pelo usuário depois da conferência código×spec acima. Duas decisões de produto ficaram em aberto e só afetam o painel da capa: se a capa de kit em categoria de móveis deve sair **ambientada** (consequência do `categoriaMoveis` com 1 slot, em vez de fundo branco) e se o kit pode mesmo existir com preço vazio, estado em que não publica. Fases 1-174 preservadas.*

*Fase 175 CONCLUÍDA em 2026-10-09 — 11 planos (os 6 passos aprovados pelo usuário, com 5 deles divididos por camada, mais o `175-11` de fechamento). Suíte do Publicador de 871 para **1141** verdes; JS 1573 pass / 2 falhas pré-existentes; `npm run build` verde com `Produto.jsx`, `Produtos.jsx` e `VisaoGeral.jsx` no manifest. Migration validada no MariaDB local (migrate → rollback → migrate, `up()` idempotente). **Decisões do usuário durante a execução** (em `175-DECISOES.md`): a capa do kit gera **DUAS** imagens — `lifestyle` + `hero` — e o operador escolhe (~R$ 1,10, não ~R$ 0,55), e o preço segue vazio mas **com aviso no painel** vindo do servidor. O gate de coordenação do Creative Engine foi mostrado ao usuário e liberado por ele ("já combinei, pode seguir"); ⚠️ a lista mostrada tinha 4 arquivos e a execução precisou de um 5º, `CreativePlanner` (3 linhas, 5º parâmetro com default vazio) — o ECF Dev precisa saber. **Dois furos achados só na execução e fechados pelo `175-11`:** a prop `criativos_ia` não chegava à tela do Produto (a caixa da capa nunca apareceria) e o prompt não pedia a composição de N unidades (entrou pela `cena` do plano do slot, sem tocar o `CreativePromptBuilder`). **NÃO entregue:** a ação "Usar estoque calculado" da §6 — exige endpoint que não existe, registrada em `deferred-items.md` item 4. **Não provado:** que o modelo obedece à composição — só uma geração real na conta #459 (~R$ 1,10) prova. Os IDs `FASE-01..FASE-12` foram cunhados no frontmatter dos planos e **não existem em nenhum `REQUIREMENTS*.md`** (o da raiz segue parado na v17.0). Fases 1-174 preservadas.*


## Milestone v26.0 — Redesign do Publicador de Anúncios (Fases 177-182)

**Plano canônico:** o pacote de design `new_layout_publicador/` (README de 245 linhas + 4 protótipos
`.dc.html`) · **Requirements:** `.planning/REQUIREMENTS-v26.md` (139 REQ-IDs) · **Base:** Fases 164,
165, 172, 173, 175 e 176 e as quicks de 10/10 (`261010-nke`, `261010-rie`, `261010-t02b`), todas em
produção.

**Goal:** as três telas centrais do Publicador — **header global**, **Visão Geral** e **Publicações**
— passam a ser o layout novo **reproduzido com fidelidade de pixel**, não o layout atual maquiado: a
fonte, os tamanhos, os pesos, as cores, os raios, as sombras, os espaçamentos, as alturas de controle
e a posição dos CTAs são os do protótipo. A tela **Desempenho** fica registrada para depois, por
bloqueio de dado medido.

### ⚠️ Este requisito já fracassou uma vez — e a causa está documentada

> *"Quero que reproduza fielmente o layout, **já tentei fazer um redesign e não saiu como eu esperava
> porque vc adaptava muito o novo layout ao sistema atual**, e como falei quero que o layout mude
> totalmente para algo novo, moderno que o novo layout que criamos. **Obedeça fontes, tamanhos de
> fontes e etc...**"* — usuário, 2026-10-11

Por isso **todo** critério de sucesso desta milestone é escrito para que *"adaptei ao que já
existia"* seja **reprovação explícita**, não interpretação possível (FID-01, FID-02): cada fase
entrega, no plano, uma **tabela de conferência item a item** contra o protótipo, nas 20 dimensões que
o README exige, com o valor do protótipo e o valor implementado lado a lado. E as justificativas
*"ficou mais parecido com o resto do sistema"*, *"mantive o token `ecf-*` por consistência"*, *"o
componente que já existe faz parecido"* e *"o Tailwind não tem esse passo"* **não são aceitas**.

### Decisões travadas (usuário, 2026-10-10/11 — D1 a D9 do REQUIREMENTS-v26.md, NÃO reabrir)

**D1** reproduzir, não adaptar · **D2** o escopo do design novo é o **módulo** Publicador — o
`AppLayout` (header `h-[60px] shrink-0` + sidebar) fica como está, e é dentro do `<main>`
(`overflow-y-auto`) que o `sticky top-0` do header novo funciona · **D3** os tokens novos
(`#0A0A0A`, `#111111`, `#FACC15`, Geist) **contrariam o `CLAUDE.md`** ("tokens `ecf-*` — manter
consistência") **de propósito, por decisão do usuário** · **D4** sem barra lateral de módulo
(recusada em 10/10: *"não concordo, e além disso não tem espaço"*) · **D5** funcional é o padrão,
enfeite é exceção justificada item a item, reusando `SeloExemplo.jsx` + `dadosDeExemplo.js` que já
existem · **D6** Desempenho por último e **nada nasce decorativo** · **D7** melhorar sem regredir ·
**D8** gate de aparência velha sai, gate de comportamento fica, com justificativa **por asserção** ·
**D9** o header vem primeiro.

### Os cinco achados que moldam a divisão em fases

1. **O header atinge 11 páginas.** `BarraDaConta`/`AbasDaConta` são consumidos por
   `Mlb/AnunciarML`, `Mlb/AnunciarMassa`, `Mlb/AnunciosHistorico`, `Mlb/MeusAnuncios` e
   `Mlb/Publicador/{Alavancas, Configuracoes, Produto, Produtos, PublicacaoEmLote, Tarefas,
   VisaoGeral}` — é o item de maior alcance da milestone e o motivo de vir primeiro.
2. **Um gate de teste codifica a aparência antiga e vai brigar.**
   `tests/js/publicador-entrada.test.js` fecha a tipografia em **24/15/13/11px**, os pesos em **400 e
   700** e **proíbe amarelo sólido** (`assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/)`, linha
   55) sobre 17 arquivos. O layout novo usa 10.5 a 34px, pesos 400/500/600/700 e `#FACC15` cheio nos
   CTAs — **são incompatíveis por construção**. O custo é baixo: só 12 arquivos de `tests/js/`
   asseveram classe/tamanho (1 a 4 asserções cada); os 38 do módulo testam comportamento.
3. **A tarifa do Mercado Livre não está no banco.** "Quanto você recebe" usa a tarifa e o frete que
   o ML responde **agora** (`/sites/MLB/listing_prices` + `shipping_options/free`). Não há tarifa
   persistida por anúncio — logo a coluna "Margem x%" de 50 linhas não pode ser calculada junto da
   lista. O motor existe e é reaproveitável: `AnaliseAlavancasService` já monta
   `margem {valor, percentual, custo, imposto_percentual}` com tarifa **cacheada** (1 h) e é exposto
   por um endpoint **JSON lazy** (`MlbAlavancasController::analise`). É isso que separa a lista
   (Fase 180) do painel de rentabilidade (Fase 181).
4. **A metodologia da publicação é derivável sem coluna nova:**
   `ml_acervo_itens.rascunho_id` → `pub_rascunhos.produto_id` → `pub_produtos`
   (`fase`, `quantidade_kit`, `produto_base_id`); `rascunho_id` nulo = **Legado**. Tipo vem de
   `listing_type_id` (`gold_special` = Clássico, `gold_pro` = Premium), Catálogo de
   `catalog_listing`. **Nenhuma migration é prevista nesta milestone.**
5. **Desempenho está sem dado, e isso foi medido.** `ml_acervo_metricas_diarias` tem **1.374.527
   linhas**, mas `sold_quantity` é **acumulado da vida do anúncio** (44 em 23/08 → 45 em 10/10), a
   série é **esparsa (média de 1,3 dias por anúncio, máximo 89)**, `visitas` é **NULL em 100%** das
   linhas e **não existe tabela de pedidos** (`mlb_sync_vendas_logs`: 6 linhas). "Vendas por dia
   empilhado por fase", "Visitas" e "Conversão" não têm fonte. Com D6 ("nada nasce decorativo"), a
   fase **espera** em vez de nascer enfeite.

### A divisão proposta — avaliação da sugestão original

A sugestão era 4 fases (header · Visão Geral · Publicações · Desempenho registrada). **A ordem está
certa e foi mantida**, com **duas subdivisões** e o header seguindo primeiro:

- **Publicações virou duas fases (180 e 181).** Era, de longe, a maior: o protótipo
  `Publicacoes v2.dc.html` tem 656 linhas, a tabela tem 9 colunas em duas visões, há 8 famílias de
  filtro, um painel de 460px com rentabilidade, ações em lote e navegação por teclado — e, por cima
  disso, a tela de hoje (`MeusAnuncios.jsx`, 668 linhas) entrega 8 colunas de triagem de saúde que
  **não podem sumir** (D7). O corte natural não é por camada: é pelo **achado 3** — a lista inteira
  se sustenta com leitura de banco; a rentabilidade e as escritas (pausar/reativar, lote) dependem de
  chamada ao ML e são a única parte que escreve. Entregar a lista sem o painel já é uma tela
  utilizável; entregar o painel sem a lista não é nada.
- **O modal "Nova publicação" virou fase própria (178).** Não por tamanho, mas porque ele é a única
  parte do header que precisa de **dado novo do servidor** (produtos agrupados por próximo passo:
  prontos para Fase 2 / sem publicação / rascunhos) e porque ele é a **resposta nova para a "entrada
  da Fase 2"**, que o usuário adiou em 10/10 esperando exatamente este redesenho — decisão de
  produto, não só de aparência. Mantê-lo dentro da 177 faria a fase do header carregar uma decisão
  de fluxo junto da troca de design em 11 páginas.
- **Por que a 177 não fica com um CTA morto:** até a 178, o botão "Nova publicação" e a busca ⌘K do
  header levam ao destino que o botão equivalente de hoje leva (a seleção de produtos da conta) — com
  a aparência nova e o comportamento atual. Nenhum clique sem destino, em nenhuma fronteira de fase
  (EXMP-03).

1. **Fase 177 — Tokens, Geist e o `PublicadorHeader` global.** Primeiro porque as outras telas o
   consomem e porque atinge as 11 páginas; é também onde a camada de tokens, os componentes de
   identidade e a política de gates nascem, para as fases seguintes só consumirem.
2. **Fase 178 — Modal "Nova publicação" (busca ⌘K e agrupamento por próximo passo).**
3. **Fase 179 — Visão Geral.**
4. **Fase 180 — Publicações: lista, busca, filtros e tabela agrupada.**
5. **Fase 181 — Publicações: painel de inspeção, rentabilidade e ações em lote.**
6. **Fase 182 — Desempenho.** ⚠️ **REGISTRADA, NÃO PLANEJADA.**

### Phase 177: Tokens, fonte Geist e o `PublicadorHeader` global nas 11 páginas

**Goal**: abrir qualquer tela do Publicador passa a mostrar o header do layout novo — marca, seletor
de empresa com busca, pill e popover de conexão, abas-pílula com contadores reais, busca ⌘K,
Configurações fora das abas e o CTA primário amarelo — sobre a camada de design nova (Geist + Geist
Mono, `#0A0A0A`/`#111111`/`#FACC15`, raios, sombras, espaçamentos e alturas do README), sem que nada
que as 11 páginas entregam hoje se perca.

**Depends on**: Fases 172 e 173 (barra da conta e abas únicas, em produção desde 08/10). Nada mais.

**Requirements**: FID-01..06, TOK-01..15, IDENT-01..08, HEAD-01..15, GATE-01..06, REND-01..05,
REG-01..06

**Success Criteria** (o que deve ser VERDADE):

  1. Nas **11 páginas** que hoje consomem `BarraDaConta`/`AbasDaConta`, o topo é o header novo:
     sticky dentro do `<main>`, linha 1 de 60px e nav de 48px, fundo `rgba(10,10,10,0.86)` com
     `backdrop-filter: saturate(140%) blur(14px)` e borda inferior `#1C1C1C`; o `AppLayout` (header
     de 60px e sidebar) não mudou em nada (HEAD-01, HEAD-14, D2)
  2. O texto do módulo está em **Geist**, SKU/MLB/códigos/atalhos em **Geist Mono**, toda métrica com
     `tabular-nums`, e nenhum arquivo redesenhado usa `ecf-bg`, `ecf-card`, `ecf-yellow`,
     `font-display` nem `border-white/[0.08]` — conferível por gate de fonte (TOK-01, TOK-02,
     FID-04)
  3. Clicar no nome da empresa abre um dropdown de **360px** com busca por nome ou código, grupos
     RECENTES/TODAS (RESULTADOS durante a busca), check amarelo na empresa atual e rodapé com a
     contagem **real** de empresas; escolher outra empresa navega **preservando a aba atual**
     (HEAD-04, HEAD-05)
  4. Clicar na pill de conexão abre um popover de **320px** com Mercado Livre e ERP, cada um com meta
     e status, e o rodapé "Última coleta há X · Atualizar agora" que **enfileira** a coleta — nunca
     coleta em processo, nunca escreve no ML (HEAD-06, HEAD-07, REG-06)
  5. As abas são pílulas de 34px — Visão geral · Produtos (N) · Publicações (N) · Desempenho ·
     Alavancas — com contadores **reais** do servidor, dot vermelho na aba Publicações quando houver
     publicação com erro, a ativa com fundo `#161616`, ícone e contador amarelos e barra amarela de
     2px por baixo; Configurações saiu das abas e virou o ícone de 34px à direita (HEAD-08..HEAD-10)
  6. Nenhuma capacidade de hoje se perdeu: nome e chave da conta, `SeloConta`, `SeloPortal`,
     `AvisoContaTravada`, slot de ações por página, **D23** com o literal exato "Disponível só para
     empresas cadastradas no sistema", contador de produtos e badge de alavancas pendentes (HEAD-11,
     REG-01, REG-06).
     ⚠️ **Exceção:** as sub-abas **No ar | Histórico** NÃO precisam sobreviver — o usuário autorizou
     removê-las em 11/10/2026 (LIST-25). É a única capacidade de hoje que esta milestone descarta de
     propósito. **O atalho sai, o dado não:** a Fase 180 tem de provar que o filtro de status alcança
     o que "Histórico" alcançava, e **parar e perguntar** se não alcançar.
  7. ⌘K/Ctrl+K e **N** fora de input abrem a busca, **Esc** fecha dropdown/popover e o clique fora
     fecha por backdrop transparente; nenhum botão do header fica sem destino (HEAD-13, EXMP-03)
  8. Os gates de **aparência antiga** saíram dos arquivos redesenhados com justificativa **por
     asserção** e os de **comportamento** ficaram intactos — nominalmente: `@/Components/ui/select`,
     `dangerouslySetInnerHTML`, **D-11** e **D-22** continuam gateados; e entrou um gate novo com o
     vocabulário novo e fechado (GATE-01..03)
  9. Todo campo novo que o header exibe tem teste de **render real** com o campo **objeto, nulo e
     ausente**, e a saída não tem `[object Object]`, `NaN` nem `undefined` (REND-01, REND-02)
  10. `npm run build` verde, manifest conferido pelo JSON, baselines respeitadas (PHP Publicador
      1761/0; JS 2210 / 2209 pass / 1 fail pré-existente) (GATE-04)
  11. O plano da fase traz a **tabela de conferência item a item** contra `PublicadorHeader.dc.html`
      nas 20 dimensões, com valor do protótipo × valor implementado (FID-01)

**Plans**: TBD
**UI hint**: yes

### Phase 178: Modal "Nova publicação" — a entrada da Fase 2 por próximo passo

**Goal**: apertar "Nova publicação" (ou ⌘K, ou N) abre um modal de 620px que pergunta "Qual produto
você quer publicar?" e responde **pelo próximo passo de cada produto** — PRONTOS PARA FASE 2 com
"Criar combo", SEM PUBLICAÇÃO com "Publicar Fase 1", RASCUNHOS com "Continuar" — com todo CTA levando
ao destino real.

**Depends on**: Fase 177 (o header que hospeda os dois botões de abertura) e Fase 175 (Criar fase /
kit da Fase 2, em produção desde 09/10).

**Requirements**: NOVA-01..10

**Success Criteria** (o que deve ser VERDADE):

  1. O modal tem **620px**, abre a `top: 110px` sobre backdrop `rgba(0,0,0,0.65)`, com cabeçalho de
     60px, input de 16px, a tecla Esc em mono 11px e corpo com `max-height: 420px` (NOVA-01)
  2. Os resultados vêm agrupados **nesta ordem** — PRONTOS PARA FASE 2 (CTA "Criar combo" em tint
     amarelo), SEM PUBLICAÇÃO (CTA "Publicar Fase 1"), RASCUNHOS (CTA "Continuar") —, cada grupo com
     o seu hint, e grupo sem item **não aparece** (NOVA-02, NOVA-03)
  3. Digitar "espelho" acha "Espelho Redondo Bisotê" e digitar "esp-rd" acha pelo SKU: a busca ignora
     acento e caixa e cobre nome e SKU (NOVA-04)
  4. Os três grupos vêm do **servidor**, das fontes que já existem (`prontosParaFase2()`, situação e
     fase dos produtos, rascunhos da conta) — nenhuma classificação feita no cliente, nenhum número
     inventado (NOVA-08)
  5. Cada CTA leva ao destino real: "Criar combo" ao fluxo de Criar fase, "Publicar Fase 1" ao editor
     do produto, "Continuar" ao rascunho — **nenhum item de enfeite nesta lista** (NOVA-07)
  6. O modal abre por ⌘K/Ctrl+K, por **N** fora de input e pelos **dois** botões do header, fecha com
     Esc ou clique fora, e o input recebe foco ao abrir (NOVA-09)
  7. O rodapé traz o glyph de Fase 2 e a frase literal "A Fase 2 usa o mesmo Publicador e reaproveita
     categoria, atributos e ficha técnica da Fase 1." (NOVA-06)
  8. Busca sem resultado mostra "Nenhum produto encontrado" — não um grupo vazio (NOVA-05)

**Plans**: 4 planos em 4 waves (178-01 contrato do servidor, 178-02 corpo, 178-03 casca portada, 178-04 fechamento medido) — executados em 12/10/2026
**UI hint**: yes

### Phase 179: Visão Geral — o cockpit da conta

**Goal**: a Visão Geral responde "o que devemos fazer agora nesta conta?" no layout novo: banner de
integração só quando há problema, a Jornada dos produtos com anel de 176px e quatro estágios
clicáveis que abrem um painel, "O que fazer agora" com severidade, expandir e adiar, Saúde da conta,
Publicações no Mercado Livre, a faixa de Desempenho, Publicações recentes e a Atividade com chips.

**Depends on**: Fase 177 (header, tokens e componentes de identidade) e Fase 178 (os CTAs de estágio
e de ação reaproveitam os destinos do modal).

**Requirements**: VG-01..20, EXMP-01..04

**Success Criteria** (o que deve ser VERDADE):

  1. A página tem a ordem vertical do README com gap de 20px, conteúdo em `max-width: 1600px` e
     `padding: 24px 36px 80px`; o seletor de período que a tela tem hoje **saiu**, e a UI diz que
     Jornada, Saúde e Ações mostram o estado atual enquanto Recentes e Atividade mostram os últimos
     30 dias (VG-01, VG-03)
  2. A Jornada mostra o anel de **176px** (r=74, stroke 14, gaps de 3px) com os quatro segmentos na
     ordem Fase 1+2 → Fase 1 → Em andamento → Sem publicação, o percentual de 34px no centro, os
     quatro estágios numerados com pilha de até 4 tiles + "+N", e a barra segmentada de 8px com a
     hachura no "Sem publicação" (VG-04, VG-05, VG-07)
  3. O estágio "próximo passo" (Prontos para Fase 2 se > 0, senão Sem publicação) leva o badge
     amarelo "PRÓXIMO PASSO" e o CTA amarelo; ao abrir a tela, "Prontos para Fase 2" já está aberto
     (VG-06, VG-08)
  4. Clicar num estágio seleciona ele (`#181818`, borda `#3F3F46`), derruba os outros segmentos do
     anel e da barra para opacidade 0.3-0.35 e abre um painel com até 6 produtos — tile, nome, SKU,
     mapa de cobertura F1/F2 × C/P, nota de contexto e CTA —, com "Ver todos os N →" para Produtos
     filtrada; clicar de novo fecha (VG-08)
  5. "O que fazer agora" ordena Crítico → Atenção → Oportunidade, filtra por severidade com
     contagens, dá à primeira ação o badge "RECOMENDADO AGORA" com CTA amarelo cheio e fundo
     `#131313`, e expande a sub-lista com motivo colorido, "Abrir →" e "Adiar até amanhã" (VG-09,
     VG-10, VG-11)
  6. O adiamento de uma ação **persiste por usuário até o dia seguinte** — recarregar a página não
     traz a ação de volta (VG-11)
  7. As ações que o sistema sustenta são reais (erro, prontos para Fase 2, sem publicação, rascunhos
     parados, pausadas, reconectar ML/ERP) e a ação "publicação com prejuízo" **não aparece** até a
     rentabilidade existir — nunca como enfeite (VG-12, EXMP-01)
  8. Saúde da conta mostra o status geral no topo e as três linhas (ML, ERP, Sincronização), com
     "Atualizar" e spinner na de Sincronização e fundo tint nas linhas com problema (VG-13)
  9. "Publicações no Mercado Livre" mostra o total em 30px, a "% ativas", a barra empilhada e a
     legenda **clicável** que leva a Publicações já filtrada, mais o bloco do acervo de imagens
     (VG-14)
  10. A faixa de Desempenho é um link inteiro para a tela Desempenho, com 4 mini-KPIs (valor 20px,
      delta colorido, sparkline 64×26); os KPIs sem dado **não aparecem como número inventado**
      (VG-15, EXMP-01)
  11. Publicações recentes mostra 5 linhas com tile 40px, badge NOVA, glyph de fase, tipo, MLB,
      status e quem/quando; a Atividade agrupa por HOJE/ONTEM/data com ícone circular de 30px por
      tipo, ator + verbo + alvo, e miniaturas de 34px nos criativos (VG-16, VG-17)
  12. Os **6 cenários** do protótipo funcionam: com pendências, conta saudável, ML desconectado, ERP
      desconectado, produtos sem publicação (anel 0%) e sem produtos (empty state na Jornada com
      "Cadastrar produto" e "Enviar link do Portal ao cliente") (VG-19)
  13. **"Nunca medimos" continua diferente de "medimos e é zero"** em todo indicador, e nenhum bloco
      que hoje é real regrediu a exemplo — a Atividade da equipe segue com as 3 fontes reais (VG-18,
      VG-20)
  14. Todo bloco sem dado real carrega **uma** pilha `SeloExemplo`, consome só `dadosDeExemplo.js` e
      nunca mistura real e exemplo no mesmo número (EXMP-02)
  15. O plano traz a tabela de conferência item a item contra `Visao Geral.dc.html` (FID-01)

**Plans**: 11 planos em 4 waves (179-01 Jornada servidor, 179-02 Acoes+Adiar, 179-02B a regra unica de "pronto para Fase 2", 179-03 Cockpit servidor, 179-04 tokens, 179-05 controller+rota, 179-06 Jornada JSX, 179-07 Acoes+Saude+Publicacoes JSX, 179-08 Desempenho+Recentes+Atividade JSX, 179-09 composicao, 179-10 fechamento medido) — executados em 12-13/10/2026
**UI hint**: yes

### Phase 180: Publicações — lista, busca, filtros e tabela agrupada por produto

**Goal**: a tela de Publicações passa a ser a lista operacional do layout novo: busca central de
54px que acha por produto, título, SKU e MLB, abas de status, oito famílias de chips de filtro e a
tabela agrupada por produto com o mapa de cobertura F1/F2 × C/P — sem perder nada do que a tela de
hoje entrega.

**Depends on**: Fase 177 (header, tokens, identidade) e Fase 179 (os links de "Publicações
filtrada" da Visão Geral apontam para os filtros desta tela).

**Requirements**: LIST-01..25

**Success Criteria** (o que deve ser VERDADE):

  1. O cabeçalho tem H1 "Publicações" com o subtítulo do README, "● Sincronizado há X" e "Atualizar"
     com spinner que depois mostra "agora" (LIST-01)
  2. Voltar do Publicador mostra a faixa de sucesso (`#14120A`, borda `rgba(250,204,21,0.35)`) com o
     resumo do que foi criado e o botão **"Localizar na lista"**, que preenche a busca, limpa os
     filtros, volta à visão Por produto e abre o painel da nova publicação (LIST-02)
  3. A busca central tem **54px** e raio 14px, foco com borda amarela e ring
     `rgba(250,204,21,0.18)`, ignora acento e caixa e acha o anúncio **pelo nome do produto**, pelo
     título, pelo SKU e pelo MLB — no servidor (LIST-03, LIST-21)
  4. As abas de status (Todas, Ativas, Pausadas, Com erro com dot, Em revisão) têm contagens
     calculadas sobre os **demais** filtros, e os chips de Fase, Tipo, Em catálogo, Com vendas,
     Responsável e Publicada funcionam com o visual de ativo em tint amarelo + borda
     `rgba(250,204,21,0.55)`, com "Limpar filtros" quando houver filtro (LIST-05, LIST-07)
  5. A tabela usa o grid literal do README
     (`40px | minmax(300px,1fr) | 136 | 104 | 136 | 112 | 68 | 150 | 64`), linhas de 62px
     (confortável) / 50px (compacta), com os cinco fundos de linha distintos (selecionada `#221D07`,
     foco `#1C1C1C`, marcada `#18160C`, nova `#14120A`, padrão `#111111`) (LIST-08, LIST-15)
  6. Na visão **Por produto** (default), a linha de grupo de 58px traz chevron, tile 38px, nome + tag
     "PRODUTO", "SKU · Estoque", o mapa de cobertura F1/F2 × C/P, "N publicações" (ou "x de N"
     quando filtrado), "Ver produto" e o link "Fase 2 não criada · Criar combo" quando não há F2; as
     publicações aparecem abaixo com recuo de 30px e o grupo colapsa ao clicar, mas a busca ativa
     força os grupos abertos (LIST-09)
  7. Alternar para **Lista** mostra linhas planas com tile e o nome do produto no meta (LIST-10)
  8. A **metodologia** de cada linha vem do dado real (`rascunho_id` → `pub_rascunhos.produto_id` →
     `pub_produtos.fase`/`quantidade_kit`), sem rascunho mostra **Legado**, o tipo vem de
     `listing_type_id` e Catálogo de `catalog_listing` — nada inferido do título (LIST-20)
  9. Copiar o MLB vira check verde por **1,3 s**; a publicação nova leva o badge NOVA; a célula
     Publicada mostra avatar com iniciais + data relativa + responsável, e diz quando o autor é
     desconhecido em vez de inventar (LIST-11, LIST-13)
  10. A célula Preço mostra "Margem x%" ou "▲ Prejuízo" quando o dado chega e **"—"** enquanto não
      chega — nunca um número estimado nem zero (LIST-12)
  11. Os atalhos funcionam: `/` foca a busca, ↑/↓ e j/k navegam, Enter abre, Esc fecha menu → sai da
      busca → fecha o painel, e ↓/Enter dentro da busca vai ao primeiro resultado (LIST-18)
  12. Recarregar a página com filtros aplicados devolve o mesmo estado — os filtros, a busca e a
      publicação selecionada estão na URL (LIST-19)
  13. **Nada regrediu:** banner de defasagem, "Ainda não coletamos os anúncios desta empresa", aviso
      de SKU ainda não coletado, sub-abas Publicados|Rascunhos com contadores, sub-abas
      No ar|Histórico, paginação de 50, ordenação por gravidade e **D-11** (a tela não escreve no
      ML nesta fase) (LIST-16, LIST-17, LIST-23)
  14. Os **chips de triagem** dos 5 motivos + "Não avaliado" seguem na tela, no vocabulário novo, com
      o total vindo de `triagem.total` do servidor — nunca da soma dos chips (LIST-22)
  15. A **Nota ECF continua aparecendo como "de 86"**, nunca renormalizada para 100 (LIST-24, D-22)
  16. O plano traz a tabela de conferência item a item contra `Publicacoes v2.dc.html` (FID-01)

**Plans**: 14 planos em 9 waves (180-01 tokens, 180-02 PublicacoesDaListaService, 180-03 escopo+busca por produto+aba "Com erro", 180-04 controller, 180-04B as 3 props que faltavam, 180-05 cabecalho+faixa, 180-06 busca+abas+toggle, 180-07 chips+popovers, 180-08 tabela+7 celulas, 180-09 linha de grupo+rodape+vazios, 180-10 composicao+cirurgia, 180-10B as 2 decisoes do usuario, 180-11 atalhos+URL, 180-12 fechamento medido) — executados em 13-14/10/2026. Fechamento em `180-FECHAMENTO.md`: 126 mutacoes/126 pegas, FID-01 com 173 medidas e 44 divergencias (43 a favor do prototipo, 1 em que ele perdeu), 16/16 criterios.
**UI hint**: yes

### Phase 181: Publicações — painel de inspeção, rentabilidade por venda e ações em lote

**Goal**: clicar numa publicação abre o painel de 460px à direita com identificação, motivo
explicado, as três ações (abrir no ML, editar no Publicador, pausar/reativar), a **Rentabilidade por
venda** com repasse, custo, lucro, margem e preço de equilíbrio, os sinais de desempenho e saúde, a
origem e as publicações irmãs do mesmo produto — e a barra de ações em lote embaixo.

**Depends on**: Fase 180 (a lista que o painel inspeciona) e o endpoint lazy
`MlbAlavancasController::analise` + `AnaliseAlavancasService` (em produção).

**Requirements**: INSP-01..12

**Success Criteria** (o que deve ser VERDADE):

  1. O painel tem **460px**, entra com `transform .28s cubic-bezier(.2,.8,.2,1)` e sombra
     `-24px 0 64px rgba(0,0,0,0.6)`, fica acima do header e mostra "PUBLICAÇÃO · x de N" com ↑ ↓ e ×
     (INSP-01, INSP-02)
  2. A identificação traz tile de 84px, os chips de fase/tipo/catálogo/status, título de 17px e o MLB
     em mono (INSP-03)
  3. Publicação que não está ativa mostra a caixa do motivo com **texto de ajuda específico daquele
     motivo**, usando os motivos reais do sistema (INSP-04)
  4. "Pausar / Reativar" troca o status na hora e é a **única** escrita no ML que a tela ganha: com a
     trava de conta do módulo, registro antes do HTTP e 5xx/rede tratados como incerto, sem reenvio
     por conta própria (INSP-05)
  5. A **Rentabilidade por venda** mostra Preço de venda, Tarifa e frete ML (negativo), Repasse
     estimado, Custo do produto (× unidades na Fase 2), Lucro estimado + margem, com a linha de lucro
     em tint verde ou vermelho; com prejuízo aparece "Este preço gera prejuízo" e o preço mínimo para
     empatar (INSP-06, INSP-12)
  6. Os números de rentabilidade vêm de `SimuladorVoceRecebe::calcular`,
     `DadosEfetivosService::custosDoProduto` e da tarifa já cacheada de `AnaliseAlavancasService` —
     **nenhum cálculo de margem novo foi escrito**, e o painel nunca discorda do card "Quanto você
     recebe" do editor para o mesmo preço (INSP-06, REG-04)
  7. "Vendas" é declarado como **acumulado da vida do anúncio**, e "Visitas"/"Conversão" dizem **"não
     medido"** quando não há dado — nunca 0 nem estimativa (INSP-07)
  8. Os sinais de saúde ECF que saíram das colunas estão aqui: Nota ECF "de 86", saúde do ML,
     `performance_score`, buybox, o checklist de sinais e a série de até 90 dias, pelo endpoint lazy
     que já existe (INSP-07, LIST-24)
  9. "Publicações deste produto" lista **todas** as irmãs (F1/F2 × tipo, com Catálogo quando houver)
     com status e preço, clicáveis, ordenadas fase → unidades → tipo, com a atual em destaque
     (INSP-09)
  10. Marcar linhas mostra a barra flutuante com "N selecionadas · Pausar · Reativar · ×" — **sem
      "Exportar CSV", dispensado pelo usuário em 11/10/2026** —, e o checkbox do cabeçalho marca
      **todas as visíveis**; sem escrita disponível para a conta, os botões ficam `disabled=` com
      explicação (INSP-10, GATE-05)
  11. Com o painel aberto, ↑/↓ e j/k movem a seleção e **o painel acompanha**; Esc fecha (INSP-11)
  12. O plano traz a tabela de conferência item a item do painel e da barra de lote contra
      `Publicacoes v2.dc.html` (FID-01)

**Plans**: TBD
**UI hint**: yes

### Phase 182: Desempenho — REGISTRADA, NÃO PLANEJADA

**Goal (quando a fase existir)**: vendas e produção de publicações por período, com faixa de 4 KPIs
num único card com divisórias, gráfico de barras empilhadas de 300px por dia/hora separando Fase 1 e
Fase 2, "Publicações que mais venderam" e o comparativo Fase 1 × Fase 2.

**Depends on**: Fases 177 e 180 — **e uma decisão do usuário** sobre coletar vendas por dia pela API
de pedidos do Mercado Livre (se coletar, qual janela, com que custo de chamada e onde persistir).

**Requirements**: DESEMP-01..08 (**Deferred** — nenhuma plan será escrita até a decisão)

**⚠️ Por que esta fase espera — o achado do dado, medido em 2026-10-10/11:**

- `ml_acervo_metricas_diarias` tem **1.374.527 linhas** (`company_id, ml_item_id, data, visitas,
  sold_quantity, nota_ecf, health_ml`).
- **`sold_quantity` é ACUMULADO da vida do anúncio** — amostra real: 44 em 23/08 → 45 em 10/10. Não
  é "vendas do dia".
- **A série é ESPARSA: média de 1,3 dias por anúncio** (máximo 89). Não dá curva de 30 dias.
- **`visitas` é NULL em 100%** das 1,37 milhão de linhas, e `visitas_30d` em `ml_acervo_itens` é
  camada cara rotativa (null = não avaliado).
- **Não existe tabela de pedidos** — só `mlb_sync_vendas_logs`, com **6 linhas**.
- **Sem fonte:** "vendas por dia empilhado por fase", "Visitas", "Conversão".
- **Já reais:** "Publicações criadas no período", "Criativos com IA (packs)", "Fase 1 × Fase 2 em
  publicações criadas".

Com a decisão do usuário **"Desempenho por último — nada nasce decorativo"** (D6), a tela **não nasce
com série sintética** e a fase fica registrada. O que a destrava é a decisão de produto sobre coletar
pedidos, não trabalho de UI.

**Plans**: nenhuma — fase registrada, não planejada.
**UI hint**: yes

#### Dependências — Milestone v26.0 (Fases 177-182)

```
177 (tokens + header)
 ├── 178 (modal Nova publicação)
 │     └── 179 (Visão Geral — reusa os destinos dos CTAs do modal)
 │           └── 180 (Publicações — lista; recebe os links filtrados da Visão Geral)
 │                 └── 181 (Publicações — painel, rentabilidade, lote)
 └── 182 (Desempenho) ── BLOQUEADA por decisão do usuário sobre coletar pedidos do ML
```

### Phase 183: Histórico de publicações — o LOTE publicado, no layout novo

**Goal**: o que o Publicador publicou volta a ser alcançável no layout novo. A Fase 180 tirou o
atalho "No ar | Histórico" da tela de Publicações **por decisão informada do usuário em 13/10/2026**
— ele recebeu tres opcoes, com o aviso literal de que a rota ficaria **sem nenhum link vivo** ate a
fase que a reconstruisse, e escolheu "sai agora, Historico vira fase propria". ⚠️ **Nada foi
apagado:** a rota `mlb.anuncios.historico`, o `MlbAnuncioController::historico()`, a pagina
`AnunciosHistorico.jsx`, o util `anuncioHistoricoUtils.js` e os testes continuam no repositorio,
com gate de diff vazio na Fase 180 provando isso. Esta fase decide **onde** aquilo reaparece e o
veste com os tokens novos.

**Depends on**: Fase 180 (a tela de Publicacoes no layout novo) e Fase 181 (o painel de inspecao,
que pode ser o lugar natural do lote).

**Requirements**: a definir no planejamento — nenhum REQ-ID da v26.0 cobre esta fase, porque ela
nasceu de uma decisao tomada DENTRO da Fase 180.

**O inventario do que SO existe nesta fonte** (medido no planejamento da Fase 180; tudo em
`app/Http/Controllers/MlbAnuncioController.php::historico()`, `:287-441`, salvo indicacao):

  1. **O agrupamento por LOTE, reconstruido em PHP.** `$chaveLote = categoria + dia de conclusao`
     (`:372-373`, `groupBy` em `:376`, o `map` do grupo em `:377-412`). ⚠️ **Nao existe coluna de
     lote no banco** — a publicacao em massa cria N itens sem vinculo, e a chave e remontada em
     memoria. Qualquer tentativa de ler "lote" do banco falha.
  2. **A fonte: o que o Publicador publicou**, nao o snapshot da conta ML. 4-join
     `pub_publicacao_itens → pub_publicacoes → pub_rascunhos → pub_produtos` (`:306-317`), com
     `status = PubPublicacaoItem::CREATED` (`app/Models/PubPublicacaoItem.php:22`) — ⚠️
     `SENT`/`PENDING`/`FAILED`/`UNKNOWN` **nao** sao "publicado" — e escopo de dupla ancora
     (`pub_produtos.company_id` OR `mlb_empresa_id`).
  3. **O payload ENVIADO ao ML** — titulo e preco **do momento da publicacao**, que o acervo nunca
     teve: `data_get($i->payload,'family_name')` (`:393`) e `payload['price']` (`:394`).
  4. **A foto da publicacao.** O payload so guarda o `ml_picture_id`; a URL exibivel mora em
     `pub_imagens.ml_url` (`:346-348`, `:354-357` em lote, `:359-368` com o fallback para a primeira
     foto do rascunho quando o id nao casa — dado legado). ⚠️ **Nunca inventa URL.**
  5. **`sku_origem` por `PubProduto::skuExibido()`** (`:346`, `:396`) — a convencao de SKU de tela do
     modulo, nao a coluna crua.
  6. **`pode_duplicar`**, hoje `false` em **todo** item (`:280-283`, `:407`), porque **nao existe
     rotina de clonar um `PubRascunho`** — so existe para `MlAnuncioRascunho`
     (`duplicarComoTemplate` `:1099`, `duplicarLoteComoTemplate` `:1136`). ⚠️ A tela **desabilita,
     nunca esconde**. Construir o equivalente para o editor novo e **recurso novo**, nao troca de
     fonte, e e candidato natural de escopo desta fase.
  7. **`listing_tier` cru do ML** (`gold_special`/`gold_pro`), indexado pelo `rotuloTier()` do front
     (`:398-401`, `resources/js/Pages/Mlb/anuncioHistoricoUtils.js`) — **nao** pelo rotulo interno.
  8. **A busca propria:** `payload->family_name` e `pub_produtos.sku`, agrupada para o `orWhere` nao
     anular o escopo (`:321-326`).
  9. **`total_lotes`** no resumo (`:437-438`) — sem equivalente em `meus()`.
  10. **A paginacao por GRUPO, 12 por pagina**, via `LengthAwarePaginator` montado a mao sobre a
      colecao agrupada (`:414-424`) — ⚠️ **outra** paginacao, nao a de 50 por anuncio.
  11. **A categoria resolvida por nome** (`$this->nomeCategoria()`, `:384`, metodo em `:3319`).

**O que esta fase precisa DECIDIR** (a Fase 180 nao decide):

  - **Onde** o lote publicado reaparece: um terceiro modo de visao **dentro** de Publicacoes (ao lado
    de "Por produto" e "Lista"), um bloco no painel de inspecao da Fase 181, ou tela propria no
    layout novo.
  - Se **`pode_duplicar`** sai do `false` permanente — o que exige clonagem de `PubRascunho`, recurso
    novo.
  - ⚠️ **NAO reabrir sem decisao nova do usuario** a troca de fonte de 2026 (`:271-279`): o
    assistente antigo tinha **ZERO publicacoes** em toda conta de teste e as publicacoes reais nao
    apareciam em lugar nenhum. Foi por isso que o Historico passou a ler a fonte do Publicador.

**O que ela HERDA sem reescrever**: `AnunciosHistorico.jsx` (375 linhas), `anuncioHistoricoUtils.js`,
a rota em `routes/mlb_anuncios.php:430`, `tests/Feature/Phase86/HistoricoAnunciosTest.php` e
`tests/js/estrutura-anuncios-historico.test.js`. E `SeletorEmpresaBusca.jsx:45` ja trata
`rotaAtual === 'mlb.anuncios.historico'`, entao o seletor de empresa continua funcionando la — sem
trabalho.

**Plans**: TBD
**UI hint**: yes

#### Coverage Map — Milestone v26.0 (REQ-ID × fase)

| Requirement | Phase | Status |
|---|---|---|
| FID-01..FID-06 (6) | Phase 177 | **Entregue** (em producao 12/10) |
| TOK-01..TOK-15 (15) | Phase 177 | **Entregue** (em producao 12/10) |
| IDENT-01..IDENT-08 (8) | Phase 177 | **Entregue** (em producao 12/10) |
| HEAD-01..HEAD-15 (15) | Phase 177 | **Entregue** (em producao 12/10; HEAD-01 corrigido para z-30 na quick 261012-mbw) |
| GATE-01..GATE-06 (6) | Phase 177 | **Entregue** (em producao 12/10) |
| REND-01..REND-05 (5) | Phase 177 | **Entregue** (em producao 12/10) |
| REG-01..REG-06 (6) | Phase 177 | **Entregue** (em producao 12/10) |
| NOVA-01..NOVA-10 (10) | Phase 178 | **Entregue** (em producao 12/10, conferido e aprovado pelo usuario) |
| VG-01..VG-20 (20) | Phase 179 | **Entregue** (15 plenos; VG-14/15/17/19 parciais por falta de DADO, causa declarada na 179-10) |
| EXMP-01..EXMP-04 (4) | Phase 179 | **Entregue** (nenhum bloco nasceu de exemplo: tudo real) |
| LIST-01..LIST-25 (25) | Phase 180 | **Entregue** (25/25 com veredicto; LIST-23 com desvio declarado em 1 de 8 itens, por decisao do usuario em 13/10/2026) |
| INSP-01..INSP-12 (12) | Phase 181 | Pending |
| DESEMP-01..DESEMP-08 (8) | Phase 182 | **Deferred** (bloqueio de dado + decisão do usuário) |

**Cobertura:** **139/139 REQ-IDs** do `REQUIREMENTS-v26.md` mapeados — FID(6) TOK(15) IDENT(8)
HEAD(15) NOVA(10) VG(20) LIST(24) INSP(12) REG(6) GATE(6) REND(5) EXMP(4) DESEMP(8). Nenhum órfão,
nenhuma duplicata.

⚠️ **FID, TOK, IDENT, GATE, REND e REG são transversais:** estão mapeados na **Fase 177** porque é
onde nascem, mas **valem e são conferidos em todas as fases** da milestone — cada fase repete a
tabela de conferência (FID-01), o inventário de não-regressão (REG-01) e os testes de render real
(REND-01).

#### Coverage Map — item do README × fase

| Item do `new_layout_publicador/README.md` | Fase |
|---|---|
| Fidelity · 1440px, min-width 1240, max-width 1600, padding 0 36px | 177 (FID-05) |
| Design Tokens · Fonte (Geist, Geist Mono, tabular-nums, tracking, overlines) | 177 (TOK-01..03) |
| Design Tokens · Cores (bg, border, text, accent, semânticas, fase1, tiles oklch) | 177 (TOK-04..07, TOK-13) |
| Design Tokens · Raios · Sombras · Espaçamento · Alturas de controles | 177 (TOK-08..11) |
| Interactions & Behavior · transições e spinner | 177 (TOK-12) |
| Assets · ícones SVG/Lucide, fontes, tiles com iniciais | 177 (TOK-01, TOK-13, TOK-14) |
| Componentes de identidade · glyph de metodologia, labels de fase | 177 (IDENT-01, IDENT-02) |
| Componentes de identidade · Tipo (Clássico/Premium/Catálogo) | 177 (IDENT-03) |
| Componentes de identidade · Status com forma | 177 (IDENT-04) |
| Componentes de identidade · mapa de cobertura F1/F2 × C/P | 177 (IDENT-05) |
| Componentes de identidade · badge NOVA | 177 (IDENT-06) |
| Interactions · tooltips pelo componente do codebase | 177 (IDENT-07) |
| 0. PublicadorHeader · linha 1 (marca, seletor, pill, busca 250px, gear, CTA) | 177 (HEAD-03..08) |
| 0. PublicadorHeader · dropdown de empresa 360px | 177 (HEAD-05) |
| 0. PublicadorHeader · popover de conexão 320px | 177 (HEAD-07) |
| 0. PublicadorHeader · linha 2 (abas-pílula, contadores, dot de erro) | 177 (HEAD-09, HEAD-10) |
| 0. PublicadorHeader · State Management (`companyMenuOpen`, `companyQuery`, `statusPopoverOpen`) | 177 (HEAD-05, HEAD-07, HEAD-13) |
| 0. PublicadorHeader · modal "Nova publicação" 620px e `newPubModalOpen`/`newPubQuery` | **178** (NOVA-01..10) |
| 1. Visão Geral · a. banner de integração | 179 (VG-02) |
| 1. Visão Geral · b. Jornada (anel, 4 estágios, barra, painel do estágio) | 179 (VG-03..08) |
| 1. Visão Geral · c. O que fazer agora (severidade, expandir, adiar) | 179 (VG-09..12) |
| 1. Visão Geral · c. Saúde da conta | 179 (VG-13) |
| 1. Visão Geral · c. Publicações no Mercado Livre + acervo de imagens | 179 (VG-14) |
| 1. Visão Geral · d. faixa de Desempenho (4 mini-KPIs, sparkline 64×26) | 179 (VG-15) |
| 1. Visão Geral · e. Publicações recentes | 179 (VG-16) |
| 1. Visão Geral · e. Atividade recente (chips, timeline, ícones, miniaturas) | 179 (VG-17) |
| 1. Visão Geral · os 6 `scenario` | 179 (VG-19) |
| 1. Visão Geral · State Management (`selectedStage`, `severityFilter`, `expandedActions`, `snoozedActions`, `activityFilter`, `syncing`) | 179 (VG-08, VG-09, VG-11, VG-13) |
| 1. Visão Geral · "o período só existe na tela Desempenho" | 179 (VG-03) |
| 2. Publicações · cabeçalho + Sincronizado/Atualizar | 180 (LIST-01) |
| 2. Publicações · faixa de sucesso pós-publicação + "Localizar na lista" | 180 (LIST-02) |
| 2. Publicações · busca central 54px (+ a nota "a busca não achava pelo nome do produto") | 180 (LIST-03, LIST-04) |
| 2. Publicações · abas de status + ordenação + toggle de visão | 180 (LIST-05, LIST-06) |
| 2. Publicações · chips de filtro (fase, tipo, catálogo, vendas, responsável, data) | 180 (LIST-07) |
| 2. Publicações · tabela (grid de 9 colunas, grupo, lista, células, fundos, rodapé) | 180 (LIST-08..16) |
| 2. Publicações · célula Preço com margem/prejuízo | 180 (LIST-12) + 181 (INSP-06) |
| 2. Publicações · empty state | 180 (LIST-17) |
| 2. Publicações · teclado (`/`, ↑↓/jk, Enter, Esc) | 180 (LIST-18) + 181 (INSP-11) |
| 2. Publicações · State Management + "refletir filtros e `selectedId` na URL" | 180 (LIST-19) |
| 2. Publicações · painel de inspeção 460px (identificação, motivo, ações, rentabilidade, desempenho, origem, irmãs) | **181** (INSP-01..09) |
| 2. Publicações · ações em lote | **181** (INSP-10) |
| 2. Publicações · "avisos financeiros nunca dependem só de cor" | 181 (INSP-12) |
| 3. Desempenho · cabeçalho + período global | **182** (DESEMP-01) |
| 3. Desempenho · faixa de 4 KPIs num card | **182** (DESEMP-02) |
| 3. Desempenho · vendas por dia/hora empilhado por fase | **182** (DESEMP-03, DESEMP-05 — sem fonte) |
| 3. Desempenho · Publicações que mais venderam + Fase 1 × Fase 2 | **182** (DESEMP-04) |
| 3. Desempenho · State Management (`period`, `hoveredBar`) | **182** (DESEMP-01, DESEMP-03) |

**Explicitamente FORA de escopo, com motivo:**

| Item | Motivo |
|---|---|
| `support.js` | O próprio README diz: runtime necessário só para abrir os protótipos no navegador, **não faz parte da implementação**. Não foi lido. |
| Os SVGs inline dos protótipos, copiados crus | O README manda **usar a biblioteca de ícones do codebase** (TOK-14) — `lucide-react`. |
| Os dados mock dos protótipos (empresas, produtos, publicações, vendas) | O README manda mapear cada número para a fonte real antes de implementar; o que não tem fonte vira `dadosDeExemplo.js` + `SeloExemplo` (EXMP-02) ou **não nasce** (EXMP-04). |
| Os literais "48 empresas sob gestão", "128 imagens · 9 packs", "Conta ECFDEV02 · token renova em 4 h" | Viram contagem/meta reais do servidor (HEAD-05, HEAD-07, VG-14). |
| O gráfico de vendas por dia e os blocos de conversão **na Visão Geral** | A `renderVals()` de `Visao Geral.dc.html` tem `bars`/`tops`/`yTicks`/`periods` como **código morto** — o template da Visão Geral só renderiza `kpis` + "Ver desempenho →". A faixa de KPIs é o que o README especifica (VG-15); o gráfico pertence à tela Desempenho (182). |
| "Visitas" e "Conversão" como número | `visitas` é NULL em 100% das 1.374.527 linhas; o painel diz "não medido" (INSP-07). |
| Barra lateral de módulo | Recusada pelo usuário em 10/10. |
| Redesenhar o corpo de Produtos, Produto, Alavancas, Tarefas, Publicação em lote, Configurações, Em massa e o assistente antigo | Fora do pacote de design — só o header novo chega a elas (HEAD-15). O estado intermediário (header novo, corpo antigo) é aceito e declarado. |
| Trocar os tokens `ecf-*` ou a fonte do resto do sistema | D2: o escopo é o módulo Publicador. |

**Decisões do usuário — as quatro RESPONDIDAS em 11/10/2026** (registro completo no fim de
`REQUIREMENTS-v26.md`; nenhuma segue pendente):

1. **A aba "Desempenho" NÃO aparece por enquanto** (*"não mostre o link da tela desempenho por
   enquanto"*) → **HEAD-09** passa a quatro abas: Visão geral · Produtos · Publicações · Alavancas.
   ⚠️ **Desvio deliberado da fidelidade** — o README pede cinco. A aba volta com a Fase 182, e a 182
   carrega esse gatilho. A recomendação era outra e ele decidiu diferente: não reabrir.
2. **"No ar | Histórico" deixa de existir; "Rascunhos" entra no MESMO grupo segmentado das abas de
   status**, abaixo da busca (*"'No ar e historico' não precisa existir, ja o rascunho adicione
   integrado no mesmo grupo de botões que tem abaixo da barra de busca"*) → **LIST-25** (novo).
   ⚠️ **Única remoção de funcionalidade existente autorizada nesta milestone**, contra a regra-mestra
   "melhorar sem regredir". A guarda do LIST-25 é obrigatória: **o atalho sai, o dado não** — se
   "Histórico" for o único caminho para algum dado, a Fase 180 **para e pergunta**.
3. **"Exportar CSV" fica FORA** (*"pode desconsiderar"*) → **INSP-10** sem o botão; item movido para
   a tabela de fora-de-escopo **por dispensa do usuário**, não por limitação técnica.
4. **"Publicação com prejuízo" só depois da Fase 181** (ele concordou) → **VG-12** mantido: até lá a
   ação **não aparece**. Dependência **179 → 181** marcada, para a Visão Geral não ser lida como
   incompleta por isso.

---

### Phase 184: Conexão com o Bling (ERP) — OAuth por link de autorização do cliente

**Goal**: a loja do cliente fica conectada ao Bling dele, e o sistema passa a ler produtos e estoque
de lá. A conexão nasce em **Configurações da loja no Publicador**
(`/mlb/anuncios/publicador/empresas/{conta}/configuracoes`, hoje com a linha "ERP · declarado no
onboarding" só-leitura em `PainelVisaoGeralService::integracoes()`), **não** no Portal do cliente.

**Depends on**: nada das fases 177-183 (milestone v26.0). Toca `Configuracoes.jsx` e
`PainelVisaoGeralService::integracoes()`, que a v26.0 declarou FORA do pacote de redesign (HEAD-15:
"corpo antigo" nessas telas) — logo não disputa arquivo com a Fase 180/181 em curso.

**Requirements**: a definir no planejamento. A pesquisa está feita:
`.planning/seeds/261010-integracao-bling-erp.md` (10/10/2026) — API v3, OAuth, limites, schema das
tabelas, mapa de campos Bling→Portal e 10 riscos. Esta fase é a **Fase 0 + Fase 1 (MVP)** daquele
seed, com as decisões da §9 respondidas em 13/10/2026 (abaixo).

**Decisões do usuário — 13/10/2026** (respostas à §9 do seed):

1. **Quem autoriza: o CLIENTE, por link.** O botão nas Configurações **gera um link de autorização**
   (com validade) que a equipe envia ao cliente; ele autoriza no Bling dele. Isso **substitui** a
   recomendação do seed ("o cliente conecta pelo Portal") e também a alternativa "a equipe conecta
   com a senha do ERP em mãos". Implica: estado intermediário `aguardando_cliente`, tela de retorno
   pública amigável (exigência da homologação do Bling) e nenhuma senha de ERP trafegando pela equipe.
2. **O app do Bling ainda não existe** — criar nesta fase, como app **público** (até 10 contas sem
   homologação; a carteira passa disso, então a homologação entra como item seguinte, fora desta fase).
   `BLING_CLIENT_ID`, `BLING_CLIENT_SECRET` e `BLING_REDIRECT_URI` só no `.env`.
3. **Escopos: todos de uma vez** — Produtos, Estoques, Depósitos, Categorias de produtos, Campos
   customizados, Dados básicos da empresa **+ Produtos-Lojas, Canais de venda e Anúncios**. Motivo:
   mudar escopo depois **revoga todos os clientes já conectados** (seed §6).
   ⚠️ **Tensão declarada, aceita pelo usuário:** combinada com a decisão 1, é **o cliente** que vê a
   tela de consentimento — e ela lista "Anúncios" e "Lojas". Isso expõe a plataforma de venda, contra
   a regra de sigilo que o resto do Portal segue (`assertSemOrigem`, risco 10 do seed). Não reabrir:
   o usuário recebeu o trade-off por escrito e escolheu assim.

**Fora desta fase** (ficam para a 185 e para a Fase 2 do seed): webhooks, fotos, GTIN/marca, kits do
Bling virando ofertas do Planejamento, releitura de saldo ao publicar, painel `/bling-oauth` no Admin
e qualquer **escrita** no Bling.

**Requirements:** BLING-01..BLING-32 (família criada no planejamento de 13/10/2026 — a lista completa,
com o requisito de cada plano, está no campo `requirements` de cada PLAN.md e no mapa por tarefa de
`184-VALIDATION.md`).

**Plans:** 13 plans em 8 ondas

Plans:
- [x] 184-01-PLAN.md — tabelas `bling_conexoes` e `bling_vinculos`, models com token cifrado, `config/services.php` e degradação sem credenciais (onda 1)
- [x] 184-02-PLAN.md — fixtures dos payloads do Bling e o tradutor puro (formatos S e V) (onda 1)
- [x] 184-03-PLAN.md — ator do sistema (`origem = 'bling'`, sem causer) e escrita sem agendar o preparo pela IA (onda 1)
- [x] 184-04-PLAN.md — `BlingOAuthService` (state único, troca do code, renovação travada, revogação) e keep-alive espalhado (onda 2)
- [x] 184-05-PLAN.md — tradutor: eixo composto, unidade de medida, multivolume, e `SaldoDoBling` (onda 2)
- [x] 184-06-PLAN.md — `BlingClient` só de leitura, com limitador por conta e global por IP, 401/429 (onda 3)
- [x] 184-07-PLAN.md — link assinado de 7 dias, landing pública amigável e callback com conferência de CNPJ (onda 4)
- [x] 184-08-PLAN.md — importador do Bling (prévia e aplicar) e Job de leitura em fatias por `release()` (onda 4)
- [x] 184-09-PLAN.md — estoque do Bling: gravação só da coluna, varredura em blocos e comando `bling:estoque` (onda 5)
- [x] 184-10-PLAN.md — estoque só-leitura e Ref travada na variação vinculada (regra no serviço + ficha) (onda 6)
- [x] 184-11-PLAN.md — estado da conexão como fonte única e as 7 ações da tela, admin-only (onda 6)
- [x] 184-12-PLAN.md — o bloco do Bling nas Configurações da loja, com os 7 estados (onda 7)
- [ ] 184-13-PLAN.md — checkpoint humano: app no Bling, `.env`, autorização real e as 8 medições (onda 8)

**Ondas:** 1 → {01, 02, 03} · 2 → {04, 05} · 3 → {06} · 4 → {07, 08} · 5 → {09} · 6 → {10, 11} ·
7 → {12} · 8 → {13}

---

### Phase 185: SKU do combo no Bling — kit com composição criado por POST

**Goal**: o combo que o Publicador cria deixa de ser um SKU que o ERP do cliente não conhece. Depois
de criado, o sistema **cria o kit no Bling** (`POST /produtos`, `formato E`, com
`estrutura.componentes` apontando o produto-base vinculado e `quantidade = N`), para que a venda do
combo baixe o estoque dos componentes no Bling e não fique "sem produto".

**Depends on**: Fase 184 (sem vínculo `bling_vinculos` do produto-base não há `componentes[].produto.id`
para mandar; o kit teria de nascer sem composição, que é justamente o que não resolve o estoque).

**Requirements**: a definir no planejamento. É a **Fase 3** do seed
`.planning/seeds/261010-integracao-bling-erp.md`, restrita aos kits/combos da ECF.

**O que o código JÁ faz** (medido em 13/10/2026 — esta fase NÃO reconstrói nada disso):

  1. **O SKU do combo já é gerado e já é distinto do unitário.**
     `PreviaDaFaseService::skuSugerido()` (`app/Services/Publicador/PreviaDaFaseService.php:109`)
     devolve `{SKU base}-KIT{N}` para fase criada no Publicador e `{SKU base}-CB{N}` para combo do
     Planejamento do Portal (decisão 7 do docblock, 09/10/2026). O corte de 120 caracteres tira o
     COMEÇO, nunca o sufixo.
  2. **O SELLER_SKU por variante também.** `PreviaDaFaseService::sellerSkuSugerido()` (`:118`), com
     `MlbPublicadorFaseController::sellerSkusParaGravar()` (`:531`) deixando a pessoa sobrescrever.
  3. **O SKU é o MESMO no Clássico e no Premium** — os dois anúncios saem do mesmo rascunho e das
     mesmas `pub_variantes`; `gold_special` e `gold_pro` só mudam `listing_type_id`, título e preço
     (`EstruturaPublicacao::LISTING_TYPES`). Nada a fazer aqui: já é garantido por construção.
  4. **O unitário usa o SKU do produto** — `PubProduto::skuExibido()` (`app/Models/PubProduto.php:240`),
     gravado como atributo `SELLER_SKU` por `EditorRascunhoService` (`:103`).

**Portanto o trabalho desta fase é só a ESCRITA no Bling**, não a geração do código.

**Decisão do usuário — 13/10/2026**: mandar ao Bling como **kit com composição** (`formato E`), não
como produto simples. Motivo aceito: o produto simples criaria um estoque paralelo no ERP e a venda do
combo **não** baixaria o estoque do produto-base — ou seja, não resolveria o problema que motivou o
pedido ("se não pode bagunçar a gestão de estoque").

**Pontos que o planejamento tem de resolver** (do seed §7 e §8):
- Quando disparar: na criação da fase, no Confirmar, ou só depois da publicação do anúncio.
- O que fazer quando o produto-base **não** está vinculado ao Bling (recusar, enfileirar, ou criar o
  base antes).
- `POST /estoques` **não é idempotente** e `PUT /produtos` substitui o produto inteiro (usar PATCH).
- Quem autoriza escrever no ERP fiscal do cliente (§9, pergunta 4 do seed — ainda em aberto para a
  escrita de produtos que só existem no Portal; **esta fase cobre apenas kits/combos**).

**Plans:** 0 plans

Plans:
- [ ] TBD (rodar `/gsd-plan-phase 185` para quebrar em planos)

---

*Roadmap atualizado: 2026-10-11 — **Milestone v26.0 (Redesign do Publicador de Anúncios)** anexada:
6 fases (177-182) cobrindo os 139 REQ-IDs do `REQUIREMENTS-v26.md`, derivados do pacote
`new_layout_publicador/` (README + 4 protótipos) e da instrução do usuário de 2026-10-11.
**Numeração contínua a partir de 177** (maior fase existente: 176); `phases.clear` **NÃO** foi
executado e **nada** anterior foi tocado — Fases 1-176 preservadas integralmente, incluindo a Fase
174 planejada e adiada, a v25.0 (168-171) e o trabalho em aberto do outro dev. O `STATE.md` **não
foi alterado** de propósito: o init devolve `current_milestone: v22.0`, que está desatualizado, e
`state.advance-plan` já corrompeu o STATE multi-milestone deste projeto. **O que este roadmap tem de
diferente de um redesign comum:** este requisito **já fracassou uma vez**, e a causa documentada foi
adaptar o layout novo ao sistema atual — por isso FID-01/FID-02 transformam "adaptei ao que já
existia" numa **reprovação explícita**, com tabela de conferência item a item nas 20 dimensões do
README e uma lista fechada de justificativas **não** aceitas. **Avaliação da divisão sugerida pelo
usuário** (header · Visão Geral · Publicações · Desempenho registrada): ordem mantida, com duas
subdivisões justificadas — Publicações virou **duas** fases (180 lista / 181 painel+rentabilidade+lote)
porque o protótipo tem 656 linhas, 9 colunas em 2 visões e 8 famílias de filtro, e porque o corte
natural é o achado de que **a tarifa do ML não está no banco** (a lista se sustenta com leitura de
banco; a rentabilidade e as escritas dependem de chamada ao ML, pelo endpoint lazy de
`AnaliseAlavancasService`, cuja `margem {valor, percentual, custo, imposto_percentual}` é reusada em
vez de recalculada); e o **modal "Nova publicação"** virou a Fase 178 porque é a única parte do
header que precisa de dado novo do servidor e porque é a **resposta à "entrada da Fase 2" que o
usuário adiou em 10/10 esperando este redesenho** — decisão de produto, não de aparência. O header
seguiu **primeiro** (D9): atinge **11 páginas** conferidas. **Tensões declaradas, não escondidas:**
(a) os tokens novos contrariam o `CLAUDE.md` por decisão do usuário (D3); (b)
`publicador-entrada.test.js` **codifica a aparência antiga** — tipografia fechada em 24/15/13/11px,
pesos só 400/700 e veto a amarelo sólido na linha 55 — e sai das telas redesenhadas com justificativa
**por asserção**, enquanto os gates de comportamento (`ui/select`, `dangerouslySetInnerHTML`, D-11,
D-22) ficam intactos (GATE-01..03); (c) a tela de hoje (`MeusAnuncios.jsx`) entrega **8 colunas de
triagem de saúde** que o layout novo não tem — elas **não são apagadas**: migram para o painel de
inspeção e para os chips, e a Nota ECF continua "de 86" (LIST-22, LIST-24, INSP-07); (d) as 11
páginas ficam com **header novo e corpo antigo** até as fases seguintes, estado aceito e declarado
que **não** autoriza harmonizar o header com o corpo antigo. **Nenhuma migration prevista** — a
metodologia da publicação é derivável por `ml_acervo_itens.rascunho_id` → `pub_rascunhos.produto_id`
→ `pub_produtos`. A **Fase 182 (Desempenho) fica registrada e não planejada** por bloqueio de dado
medido: `sold_quantity` é acumulado da vida do anúncio, a série de `ml_acervo_metricas_diarias` é
esparsa (1,3 dias por anúncio em média), `visitas` é NULL em 100% das 1.374.527 linhas e não existe
tabela de pedidos — com a decisão do usuário "nada nasce decorativo" (D6), a fase espera a decisão de
produto sobre coletar pedidos pela API do ML, não trabalho de UI.*

---

## Milestone v27.0 — Aprovação humana antes de publicar (Fases 186-194)

**Plano canônico:** `design_handoff_publicador/README-v2.md` (327 linhas) + `Aprovacao.dc.html`
(1.432 linhas, a classe `Component` é a especificação de comportamento) + `Publicacoes v2.dc.html`
(referência da seção A) · **Requirements:** `.planning/REQUIREMENTS-v27.md` (**229 REQ-IDs**) ·
**Base:** Fases 177-180 da v26.0, **no ar e aprovadas** (tokens + header, modal "Nova publicação",
Visão Geral e a tela de Publicações).

**Goal:** o sistema passa a publicar de forma **autônoma** com um **portão de aprovação humana antes
de ir ao ar** — uma fila onde cada oferta é conferida foto por foto e preço por preço, com bloqueios
calculados no servidor, e onde **nada vai ao Mercado Livre sem alguém aprovar**.

> *"uma das coisas de maior interesse é que o sistema funcione de forma autônoma para publicar mas
> que tenha uma aprovação antes de ir para ar"* — usuário, registrando a decisão da gestão da ECF na
> reunião de **14/10/2026**

⚠️ **O usuário decidiu em 14/10 PAUSAR a v26.0.** A **Fase 181** (painel de inspeção) e a **Fase 183**
(Histórico) ficam para depois, porque **a aprovação destrava a automação e vale mais que o
acabamento**. Nada foi apagado: as duas seguem registradas neste roadmap.

### ⚠️ O VALOR da milestone: a automação que a gestão pediu JÁ EXISTE, pronta e DESLIGADA

`app/Services/Publicador/Criativos/CriativosAutomaticosService.php` (10/10/2026) tem a cadeia
completa — produto chega do Portal com ficha e fotos → título → Modelo → descrição → **criativos
automáticos** — com **sete** condições de segurança, travada em `publicador.criativos_auto.ativo`
(`config/publicador.php:217`, env `PUBLICADOR_CRIATIVOS_AUTO_ATIVO`, padrão `false`). O docblock diz
textualmente que ela espera *"depois que o dono do Creative Engine ajustar o lado dele"*.

➡️ **O portão de aprovação é o que torna seguro ligar aquela chave.** Hoje, ligar significaria imagem
de IA ao ar sem conferência humana; com a fila, significa imagem de IA entrando numa fila onde alguém
aprova foto por foto antes de publicar. **São peças da mesma decisão** — e é por isso que a **Fase
194 (ligar a automação) é o destino da milestone, não um apêndice**.

### Decisões travadas (D1..D14 do `REQUIREMENTS-v27.md` — NÃO reabrir)

**D1** reproduzir, não adaptar (o protótipo ganha: placar de ~146 divergências nas Fases 177-180, e
em UMA só o protótipo errou) · **D2** dois eixos nunca misturados — o andamento interno nunca aparece
na lista de Publicadas e o status do ML nunca aparece na fila · **D3** "Em revisão" nunca sem
qualificar · **D4** nada nasce decorativo, custo ou frete ausente **nunca** vira zero · **D5** reusar
o Creative Engine, nunca reescrever · **D6** "Resultado estimado por venda", **nunca** "lucro
líquido" · **D7** cor nunca é o único sinal, e sem jargão na tela · **D8** as taxas do README são
fictícias e **não** entram como literal nem como config (a fonte real é melhor e já existe) ·
**D9** fatia vertical, servidor antes do render, **fechamento medido como plano próprio** · **D10**
esta milestone **TEM migrations** · **D11** o D-11 vale para a **LISTA**; a **FILA escreve** ·
**D12** a seção A é dividida de propósito (rename antes, abas com a rota) · **D13** editor, Mesa,
Creative Engine, Bling e `MlPublicacaoService` **não são tocados** · **D14** o usuário pode querer
parar no meio, e nenhuma fase deixa botão sem destino na sua fronteira.

### Os sete achados medidos que moldam a divisão em fases

1. **A automação existe e está desligada** (acima). A milestone é o portão que a destrava.
2. **O modal "Gerar novamente com IA" é COMPOSIÇÃO do que já existe, não serviço novo.** Medido em
   14/10: `GeminiImageProvider::gerarImagem`,
   `CreativePromptBuilder::paraSlot(…, $regeneracao, $ajusteOperador)`,
   `CreativePermissao::podeRegenerar(User)` e
   `ReferenciaEfemeraService::guardar(MlAnuncioCriativo, array $arquivos)` — todos em
   `app/Services/Creative/`. **CHAMAR, nunca reimplementar** (TERR-01).
3. **A ponte Publicador → Creative Engine já existe e é indexada.**
   `ml_anuncio_criativos.pub_imagem_id` → `pub_imagens.id` (nullable, `nullOnDelete`, índice
   `ml_criativos_pubimg_idx`), criada pela migration da Fase 165 e preenchida por
   `PublicadorCriativoAprovacaoService:117`. ➡️ **Nenhuma coluna nova para alcançar o criativo a
   partir da imagem revisada** (IA-05).
4. ⚠️ **A original de referência é DURÁVEL, mas o VÍNCULO com ela morre em 48 h.**
   `ml_anuncio_criativos.referencias` é o único lugar onde ele está gravado, e é apagado na aprovação
   **e** pela varredura `creative:limpar-referencias`
   (`app/Console/Commands/LimparReferenciasCriativos.php`,
   `services.creative.retencao_referencias_horas`). A original em si é **uma linha de `pub_imagens`
   do próprio rascunho**. ➡️ O vínculo durável é **dado novo NOSSO** (**DADO-15**, na Fase 186), e
   **a retenção alheia NÃO é tocada** (**TERR-11**) — se parecer necessário, **para e pergunta**.
   ⚠️ **Consequência declarada:** na estreia **nenhuma** imagem antiga terá original de referência, e
   a comparação lado a lado mostra o estado **"original de referência não disponível"**
   (**IMG-13**) — nunca painel vazio, nunca a imagem de IA repetida no lugar da original.
5. **São 12 estruturas de dado novas ⇒ esta é a primeira milestone com migration desde a v26.0**,
   que declarou nenhuma. ⚠️ `pub_rascunhos` tem **6** estados e eles são de **PUBLICAÇÃO**
   (`DRAFT`, `VALIDATED`, `PUBLISHING`, `PUBLISHED`, `PARTIALLY_PUBLISHED`, `FAILED`) — eixo
   diferente dos 8 de **andamento interno**; e `PubImagem.status` é de **UPLOAD**
   (`pending`/`uploaded`/`failed`), não de revisão.
6. ⚠️ **As taxas do README são fictícias, e neste sistema a fonte real é MELHOR do que config.**
   Comissão e tarifa fixa vêm da **API do Mercado Livre** (`GET /sites/MLB/listing_prices`, via
   `SimuladorVoceRecebe`, em `AnaliseAlavancasService:212` e `EditorRascunhoService:412`); o imposto
   vem de `EstruturaPrecificacaoParametros::daEmpresa()['imposto']` com a **exceção da oferta
   vencendo** (`DadosEfetivosService:216-232`); o custo, de
   `DadosEfetivosService::custosDoProduto()`. ➡️ **Nenhum dos números do README entra como literal**
   (FIN-02). ⚠️ **A única exceção é a margem mínima (12%): `grep -rn "margem_minima"` em `app/` e
   `config/` voltou VAZIO — é configuração nova** (FIN-09).
7. ⚠️ **A granularidade do desenho não é a da fila de publicação de hoje.** O desenho trabalha por
   **OFERTA** (Clássico e Premium com andamento, preço, resultado e versão **separados**), enquanto
   `pub_fila_publicacao_itens` trabalha por **PRODUTO** (Clássico + Premium, todas as cores, numa
   publicação só) — e **já guarda um conceito de conferência**: `validacao_id`, `plano_hash`,
   `revisao`, `ciente`, `precisa_revisar`. ➡️ **DADO-03 e PUB-02 obrigam o planejamento a resolver
   isso explicitamente e a NÃO duplicar aqueles campos.**

### ⚠️ A ordem da seção A — a dependência que podia inverter a fase 1

A seção A (abas no cabeçalho de Publicações + renomear o status do ML) **parece** a fase 1 óbvia.
**Não é — e foi dividida de propósito (D12):**

- **A2, o rename, vem PRIMEIRO (Fase 186, primeiro plano).** É independente e seguro: muda **rótulo**,
  nunca **chave de dado** (`em_revisao`/`pending` continuam iguais, então URL, filtro salvo e teste de
  comportamento continuam funcionando — TERR-08). E **tem de vir antes**: esta milestone introduz o
  estado interno **"Em revisão pela ECF"**, e o README é taxativo — *"Nunca use 'Em revisão' sem
  qualificar"*. Renomear depois criaria uma janela em que **dois "Em revisão" diferentes convivem na
  tela**, e toda asserção negativa escrita nesse meio-tempo seria ambígua (⚠️ "Em revisão" é
  **substring** de "Em revisão pela ECF" — a armadilha da asserção negativa cega). **Valor imediato:**
  se o usuário parar depois da 186, ele já ganhou a desambiguação que pediu.
- **A1, as abas, vem com a FASE QUE CRIA A ROTA (Fase 187).** O contador da aba "Aguardando
  aprovação" vem dos **estados internos** (`awaiting` + `ecf_review` + `changes`) e o clique **navega
  para a rota nova**. Sem a área existir, **a aba aponta para o vazio** — exatamente o que a régua
  "nada nasce decorativo" proíbe, e o usuário **já exerceu essa régua três vezes** na milestone
  anterior.
  **Alternativa considerada e recusada:** entregar a aba na 186 desabilitada ou com tooltip. Recusada
  porque uma aba com contador **real** que não leva a lugar nenhum é a definição de enfeite — pior
  que ausência, porque promete.

### ⚠️ A fronteira do D-11, declarada para o primeiro executor não ler contradição

`tests/js/estrutura-meus-anuncios.test.js` (≈ `:250`, `:256`, `:285` — **identificar por SEMÂNTICA**,
nunca por número de linha) prova duas coisas **intocáveis**: **a LISTA de Publicações nunca escreve
no Mercado Livre** (D-11) e **a Nota ECF é "de 86"** (D-22).

⚠️ **Esta milestone ESCREVE** — aprovar, regenerar imagem, publicar. **Não é contradição:** o D-11 é
um gate **sobre os arquivos da lista**. A área nova vive em `aprovacao/` e ganha **gate de escrita
próprio** (trava de conta, registro **antes** do HTTP, `5xx`/rede tratados como **incerto**, sem
reenvio por conta própria, `UNKNOWN` nunca reenviado sem reconciliar pelo SKU — **RN-93**).
➡️ **O D-11 não é estendido à área nova e não é afrouxado na lista** (D11, PUB-07).

### Camadas, portal e o que não se mexe

⚠️ O header é **`z-30`** e a ordem travada é **filtros 10 < header 30 < menu lateral e painel 40**
(`tests/js/publicador-header-zindex-ordem.test.js`). O painel de **1140px** e o lightbox desta
milestone **respeitam isso e não alteram o header**. ⚠️ O `backdrop-filter` do header **cria
containing block para `position:fixed`** (medido na Fase 178) ⇒ overlay por **portal**; e ⚠️ **o
renderizador de servidor do React NÃO renderiza portal** — as peças internas saem como **named
exports** para poderem ser conferidas, e **conteúdo portado não herda a fonte** (REND-05).

⚠️ **Território intocado:** `app/Services/Creative/`, os endpoints `criativo*` do
`MlbAnuncioController`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx`,
`app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php` (⚠️ **o caminho real NÃO é
`app/Services/Creative/`** — o aviso de coordenação dizia isso e estava errado), o editor
(`Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa/` — **SEM** o `Mlb` no meio),
`App\Support\Publicador`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `AppLayout.jsx`,
`app/Models/Bling*`, `app/Services/Bling/`, `app/Services/Portal/` (sessão paralela, Fases 184/185),
a Visão Geral (179) e o corpo de Publicações (180) além da seção A.

### Fases desta milestone, e o que cada uma entrega de VALOR

| Fase | Entrega de valor ao usuário | Migration |
|---|---|---|
| **186** | "Em revisão" deixa de ser ambíguo na tela; e o portão passa a **existir no servidor**, com bloqueios e composição financeira conferíveis por teste | ⚠️ **SIM — a espinha (12 estruturas)** |
| **187** | **A fila existe e é alcançável:** a aba leva a ela, dá para ver o que está esperando aprovação, filtrar e aprovar em lote | não |
| **188** | **APROVAR DE VERDADE** — conferir fotos e preço e liberar a oferta. É o coração do pedido da gestão | não |
| **189** | Conferir a foto **de perto**, com zoom e marcadores, e agir dali | não |
| **190** | Comparar a imagem de IA **lado a lado com a original**, com checklist de fidelidade e defeitos | não |
| **191** | Conferir **preço, conteúdo do anúncio e o histórico** na mesma página, com a prévia do que vai ser enviado | não |
| **192** | **Corrigir a foto errada sem sair da revisão** — regenerar só aquela imagem, com problemas, instrução e referências | não |
| **193** | **O portão fecha o ciclo:** aprovar faz publicar, e falha preserva a revisão com "Tentar de novo" | provável (1 coluna de ligação) |
| **194** | **A automação ligada atrás do portão** — a decisão da gestão de 14/10 entregue | não |

### Phase 186: O portão no servidor — vocabulário, os 8 estados, bloqueios e composição financeira

**Goal**: o portão de aprovação passa a **existir como dado e como regra** — os 8 estados de
andamento interno, a versão da oferta, o conjunto de fotos compartilhado, o estado de aprovação por
imagem, o checklist, os defeitos, os ajustes com escopo, o histórico, a exceção autorizada, o frete
informado, a origem por linha financeira, as verificações automáticas com coordenadas e o vínculo
durável com a original de referência —, com os **8 bloqueios** e o **cálculo financeiro** conferidos
contra `info()` e `fin()` do protótipo; e, na tela que já está no ar, **"Em revisão" deixa de ser
ambíguo**: o status do Mercado Livre passa a se chamar "Em análise pelo Mercado Livre".

**Depends on**: Fases 177-180 (no ar). Nada mais.

**Requirements**: VOC-01..08, ABA-03, ABA-04, AND-01..10, DADO-01..15, BLOQ-01..12, FIN-01..14,
MIG-01..09 — e os transversais **FID-01..08, GATE-01..14, REND-01..08, TERR-01..11**, que nascem
aqui e **valem e são conferidos em TODAS as fases** da milestone.

⚠️ **MIGRATION — a maior da série.** O planejamento quebra a espinha em migrations **por assunto**
(oferta + versão · conjunto + imagem + verificações automáticas · checklist + defeitos · ajustes +
histórico + exceção · frete + origem financeira · vínculo durável de referência), **nunca uma
migration monolítica**, e cada uma com a **tabela de decisão de schema no docblock antes do código**
(MIG-07).

**Success Criteria** (o que deve ser VERDADE):

  1. Na tela de Publicações **que já está no ar**, o status do ML aparece como **"Em análise pelo
     Mercado Livre"** e, na aba de filtro, **"Em análise pelo ML"** — em
     `identidade/SeloDeStatusDaPublicacao.jsx` e `publicacoes/AbasDeStatus.jsx`. ⚠️ **A chave de dado
     não mudou** (`em_revisao`/`pending`): URL com filtro, filtro salvo e os testes de comportamento
     continuam passando (ABA-03, TERR-08)
  2. **"Em revisão" não aparece em nenhum lugar sem qualificar**, provado por gate de ausência
     **recortado ao corpo** e com **isca** — ⚠️ a negativa cega quebraria a tela certa, porque
     "Em revisão" é **substring** de "Em revisão pela ECF" (VOC-01, GATE-13)
  3. Os **8 estados de andamento interno** existem com transições declaradas, e **os dois eixos nunca
     se misturam**: gate provando que nenhum rótulo interno aparece nos componentes de `publicacoes/`
     e que nenhum status do ML aparece nos de `aprovacao/` (AND-01, AND-02, D2)
  4. Os **8 bloqueios** de `info()` são calculados **no servidor, por oferta**, cada um com texto e
     ação de correção; ⚠️ a cadeia `freight → loss → margin` é **exclusiva e nesta ordem**: faltando
     dado **não existe** bloqueio de prejuízo nem de margem, porque **não há resultado**
     (BLOQ-01, BLOQ-02)
  5. **Custo ou frete ausente NUNCA vira zero:** o resultado estimado é `null` e a tela diz
     **"Incompleto"**; equilíbrio e preço-alvo **só existem quando todos os dados existem**
     (FIN-05, FIN-06, D4)
  6. ⚠️ **Nenhuma taxa do README entrou como literal nem como config:** comissão e tarifa fixa vêm da
     API do ML via `SimuladorVoceRecebe`, o imposto de
     `EstruturaPrecificacaoParametros::daEmpresa()` com a exceção da oferta vencendo, o custo de
     `DadosEfetivosService::custosDoProduto()` — e há **gate de ausência** de `0.12`, `0.17` e `6.75`
     nos arquivos novos. **A margem mínima é a ÚNICA configuração nova** (FIN-02, FIN-03, FIN-09)
  7. A **composição financeira** fecha com a **ESTRUTURA e as INVARIANTES** de `fin()` — as 7 linhas
     na ordem, os rótulos, a propagação de `null`, a composição de `fixedSum`, e
     `equilibrio = fixedSum/(1−vr)` / `precoAlvo = fixedSum/(1−vr−minimo)` — com os **números vindos
     das fontes reais**; e **cada linha carrega origem `Confirmado`/`Estimado`/`Pendente` + nota de
     fonte real** — ⚠️ a nota nomeia a fonte, não é texto decorativo (FIN-01, DADO-13, VOC-04)
     ⚠️⚠️ **CORRIGIDO EM 14/10/2026 — a redação anterior ("fecha com a de `fin()` para os 10 estados")
     CONTRADIZIA o critério 6 e era ARITMETICAMENTE IMPOSSÍVEL.** As taxas de `fin()` são **ENTRADA
     fictícia**, e o próprio protótipo as rótula assim (`Aprovacao.dc.html:1064-1066`: *"taxa de
     exemplo"*, *"exemplo"*, `src: 'est'`). Com as fontes reais o exemplo `o1` sai de +R$ 70,82 /
     14,46% para +R$ 12,03 / 2,46% — **inverte de aprovável para bloqueado**. ➡️ **"O protótipo
     ganha" vale para a FÓRMULA, os RÓTULOS e a ORDEM, NUNCA para os NÚMEROS** — obedecer os
     números forçaria hardcodear 0.12/0.17/0.06/6.75, que o critério 6 proíbe.
     ⚠️ E a seção F do README é **prosa**: só `info()` decide bloqueio (o `o3` que ela chama de
     "margem baixa" dá **prejuízo** pela cadeia exclusiva). **Não misture as duas fontes.**
  8. ⚠️ **Antes de declarar o frete do vendedor "Pendente", está PROVADO que não há fonte** — existem
     `FreteParaAPrecificacaoService`, `FreteDoPublicadoService`, `ConferenciaDeFrete` e
     `TabelaFreteEcf`; havendo fonte, a linha nasce `Confirmado`/`Estimado` com a nota real (FIN-07)
  9. **A versão manda na aprovação:** aprovar grava `approvedBy`/`approvedAt`/`approvedVersion`, e as
     **7 mudanças relevantes** (reprovar, substituir, regenerar, adicionar, reordenar, trocar a
     principal, salvar preço, confirmar frete) incrementam a versão e **reabrem** a aprovação com
     registro no histórico — ⚠️ mudança de foto reabre **todas as ofertas do conjunto**
     (BLOQ-05, BLOQ-06)
  10. **O conjunto de fotos é compartilhado de verdade:** aprovar uma foto no Clássico vale para o
      Premium do mesmo produto e formato, provado por teste (DADO-04)
  11. ⚠️ **O vínculo durável imagem gerada → `pub_imagens` de referência existe** (DADO-15), é
      **nosso**, **não** se apoia em `ml_anuncio_criativos.referencias` (efêmero, 48 h) e
      **`app/Services/Creative/` tem diff vazio**, com a retenção alheia intocada (TERR-11)
  12. ⚠️ **A granularidade está RESOLVIDA e REGISTRADA:** o docblock diz se a oferta é tabela nova ou
      projeção de `pub_rascunhos` × `listing_type_id`, e como ela se liga a `pub_fila_publicacao_itens`
      **sem duplicar** `validacao_id`/`plano_hash`/`revisao`/`ciente` (DADO-03).
      ⚠️ **CORRIGIDO EM 14/10/2026:** são **4 colunas + 1 VALOR DE STATUS**, não 5 colunas —
      `precisa_revisar` **não é coluna em lugar nenhum**: é um valor do `status` de
      `pub_fila_publicacao_itens` (`2026_10_10_100000_...:45`, `status` string(20)). Um gate que
      afirme `hasColumn('precisa_revisar') === false` é **vazio por construção** — a prova certa
      é que aquele `status` segue sendo a única fonte do valor.
      ⚠️ **E o eixo VARIANTE existe no schema e NÃO pode ser descartado:**
      `ResolvedorGruposImagem::chaveDoGrupo()` dá **um grupo por valor de eixo que define foto,
      mais `GENERAL`** (3 cores = 4 grupos), e `pub_publicacao_itens` é `listing_type_id` **+
      `variante_chave``. **Decisão do usuário, 14/10/2026: o bloqueio de fotos considera TODOS os
      grupos do rascunho** — a oferta só fica aprovável com as fotos de todas as variações **e** as
      gerais conferidas. **Aprovação por cor foi oferecida e RECUSADA** (aprovar já publica e não há
      desfazer, então foto não conferida não pode ir ao ar).
  13. Toda migration obedece MIG-01..09: índice de nome **curto** (erro 1059), `nullOnDelete` com
      `->nullable()` (erro 1830), **sem `enum`** (CHECK do SQLite), `up()` idempotente, **sem
      try/catch em volta de DDL**, e **migrate → rollback → migrate provado no local**
  14. Os gates desta milestone nascem com a régua medida: **dois gates por elemento** (CSS **e**
      HTML), isca em toda sonda, **ANSI removido antes de filtrar** saída de teste, **contagem de
      testes comparada** (não só "0 fail"), range `<inicial>..HEAD` e `test -e` em cada alvo
      (GATE-02..GATE-14)
  15. `tests/js/publicador-vocabulario-novo.test.js` foi **estendido**: o diretório `aprovacao/` entra
      na lista do GATE-03 daquele arquivo (`:230`) e **todos** os arquivos novos entram em
      `ARQUIVOS_NOVOS` (`:36`) — ⚠️ **a constante é de propósito, e arquivo fora dela faz o gate
      passar por omissão** (VOC-07)
  16. Baselines respeitadas (PHP Publicador **2103 / 16.034 / 0**; JS **4482 / 4481 pass / 1 fail**
      pré-existente em `estrutura-grade-glide.test.js:122`, ⚠️ **não corrigir**), `npm run build`
      verde e manifest conferido **pelo JSON** (GATE-14)
  17. O plano traz a tabela FID-01 da seção A contra `Publicacoes v2.dc.html` e **declara se mediu por
      SCRIPT ou por LEITURA** (FID-01, FID-02)

**Plans**: 9 planos em 6 waves, planejados 14/10/2026 (186-01 baselines+rename das 3 ocorrencias+harness de gate, 186-02 abre `aprovacao/` com o vocabulario e o VOC-07, 186-03 conjunto compartilhado+revisao por imagem+verificacoes x/y+checklist+defeitos, 186-04 a oferta do portao+8 estados+versao, 186-05 ajustes com escopo+historico+excecao, 186-06 frete informado+composicao congelada+DADO-15, 186-07 composicao financeira+a unica config nova, 186-08 os 8 bloqueios+aprovar/reabrir/lote, 186-09 fechamento medido dos 17 criterios). **6 migrations / 12 tabelas.** Verificados por `gsd-plan-checker` em 14/10: REPROVARAM na 1a rodada (18 dos 26 gates eram vazios — os casos 12 e 13 do learnings, confirmados por medicao independente), revisados em `7d873cf3` com harness unico `tests/gates/verificar-testes.sh` e manifesto de contagens por plano.
**UI hint**: yes

### Phase 187: A fila "Aguardando aprovação" — e a aba que leva a ela

**Goal**: existe uma fila onde se vê **o que está esperando aprovação**: a faixa de 9 colunas de
andamento, "PRÓXIMO A REVISAR", os filtros com popovers e a tabela agrupada em três níveis (produto →
conjunto de fotos → oferta), com seleção em lote — e a tela de Publicações ganha **as duas abas**, com
o contador real levando até aqui.

**Depends on**: Fase 186 (os estados, os bloqueios e a composição financeira que a tabela exibe).

**Requirements**: ABA-01, ABA-02, ABA-05, ABA-06, FILA-01..16, MOD-11, MOD-12

**Migration**: não.

**Success Criteria** (o que deve ser VERDADE):

  1. No cabeçalho de Publicações, **o subtítulo saiu** e entraram **duas abas** alinhadas à base
     (`gap:20px`, `margin-bottom:-1px`, altura 40px, 14px), com o `<header>` ganhando
     `border-bottom: 1px solid #1F1F1F` e a coluna do H1 passando a `gap:14px` (ABA-01)
  2. **"Aguardando aprovação"** mostra o contador em **pill amarela** (`#FACC15`, texto `#0A0A0A`,
     11.5px/700, altura 20px, `min-width` 22px) com o número **real** de ofertas em `awaiting` +
     `ecf_review` + `changes`, **vindo do servidor** — nunca somado no cliente — e **navega para a
     rota nova** (ABA-02, ABA-05)
  3. ⚠️ **A aba não aponta para o vazio em nenhum momento:** a rota existe nesta mesma fase, e
     clicá-la abre a fila com dado real (D4)
  4. A **lista de Publicações continua mostrando só ofertas que existem no Mercado Livre**: oferta em
     preparação ou em aprovação aparece **apenas** na área nova; e busca, filtros, agrupamento,
     painel, lote, atalhos e header **não mudaram** (ABA-06, TERR-05)
  5. A **faixa de andamento** é um grid de **9 colunas** (`repeat(9,minmax(0,1fr))`, `gap:6px`) com
     dot, rótulo 11.5px e número 22px/600; clicar filtra; o ativo tem `#181818` e borda `#3F3F46`;
     ⚠️ **as contagens são calculadas sobre os DEMAIS filtros**, não sobre o recorte já filtrado por
     etapa (FILA-03)
  6. **"PRÓXIMO A REVISAR"** mostra a oferta certa pela regra literal do protótipo — a `awaiting`
     **não tocada** com maior espera, senão a primeira `live` não tocada, senão a primeira `live` — em
     faixa `#14120A` / borda `rgba(250,204,21,0.35)`, com "Pronto para aprovar" ou "N pendências" e o
     botão amarelo "Revisar agora" (FILA-04)
  7. Busca (produto, título, SKU, **ignorando acento e caixa, no servidor**), segmentados de
     Modalidade e Formato, e os **popovers** de Responsável e Motivo de bloqueio com **contagem**,
     ficando com tint amarelo quando ativos; "Limpar filtros" e "N de M ofertas" à direita
     (FILA-05, FILA-06)
  8. A tabela usa o **grid literal**
     `32px 24px minmax(170px,1fr) 104px 118px 92px 108px 76px 112px 164px 96px`, com a **linha de
     produto** de 52px (`#151515`, tag PRODUTO, "N ofertas"), a **sub-linha de conjunto** de 38px
     (`#0F0F0F`, recuo 62px, mini-quadrados de 10px, "x/y aprovadas", o pill **"Mesmas fotos em
     Clássico e Premium · aprovação compartilhada"** e "Revisar fotos do conjunto") e a **linha da
     oferta** com altura **mínima** de 64px (FILA-07..FILA-09)
  9. Cada célula da oferta bate com o protótipo: anel de progresso nos 4 estados com `title` próprio,
     título em **peso 600 quando não analisada**, meta em uma linha com ellipsis (SKU · ⚡/✎ · vN ·
     EXCEÇÃO), ◆ no Premium, "N fotos · N IA" com quadrados de 12px e resumo colorido,
     **"Incompleto"** em âmbar quando falta dado, margem com barra 0–40% e o traço do mínimo a 30%,
     responsável **em âmbar acima de 24 h**, pill de andamento com a sub-linha do primeiro bloqueio
     ("Bloqueado: frete pendente +1") e a ação certa por estado (FILA-10..FILA-13, AND-10)
  10. A **ordenação** é a literal de `ORD` (falha → aguardando/em revisão → ajustes → aprovado →
      publicando → publicado → preparando; dentro de cada grupo, **maior espera primeiro**) (AND-08)
  11. Marcar linhas mostra a barra flutuante "N selecionadas · Aprovar K para publicação · ×", o
      checkbox do cabeçalho marca **todas as visíveis**, e o **modal de lote** lista **todas** as
      selecionadas com ✓/× **e o motivo**, aprovando **só as aptas, cada uma na versão indicada**
      (FILA-15, MOD-11, BLOQ-10)
  12. Os **toasts** ficam no topo central, somem em ~3,8 s e usam os literais do README; ⚠️ "Publicado
      e confirmado no Mercado Livre · MLB…" **só** aparece com MLB confirmado (MOD-12, VOC-08, AND-04)
  13. ⚠️ **Nada de `localStorage`** (é artefato do protótipo) e **nenhum "Restaurar dados de
      exemplo"**; os filtros e a seleção estão na **URL** (COL-02, COL-03)
  14. Render adverso de **todo** campo novo (objeto, nulo, ausente; coleção nula/string/número; prop
      inteira nula), sem `[object Object]`/`NaN`/`undefined`/`Invalid Date`, e ⚠️ **nenhuma flag fora
      do callback do `.map()`** (REND-01..04)
  15. O plano traz a tabela FID-01 da fila contra `Aprovacao.dc.html` e **declara como mediu**
      (FID-01, FID-02)

**Plans**: TBD
**UI hint**: yes

### Phase 188: Revisão simples — o painel de 1140px onde a aprovação acontece

**Goal**: clicar em "Revisar" abre o painel amplo com **fotos e preço no mesmo peso**, as pendências
com botão de correção em cada uma, e o rodapé onde se **aprova para publicação** — mais os quatro
modais que o painel precisa (solicitar ajustes, solicitar exceção, informar frete, reprovar imagem).
**É aqui que o portão passa a ser usável.**

**Depends on**: Fases 186 (bloqueios, versão, composição) e 187 (a fila que o painel navega).

**Requirements**: REVS-01..14, MOD-01..05, IMG-01..06, COL-01..06

**Migration**: não.

**Success Criteria** (o que deve ser VERDADE):

  1. O painel tem `width: min(1140px, 100vw − 100px)`, fundo `#0D0D0D`, borda esquerda `#2E2E2E`,
     sombra `-24px 0 64px rgba(0,0,0,0.6)` e backdrop `rgba(0,0,0,0.55)`; ⚠️ **por PORTAL** (o
     `backdrop-filter` do header cria containing block) e **acima do header sem alterá-lo**, com o
     teste de z-index existente intacto (REVS-01, TERR-06)
  2. Esc e clique fora fecham; **↑/↓ navegam pela fila** (pulando `preparando`) e **←/→ trocam a
     foto**; o topo de 54px traz "REVISÃO SIMPLES", "x de N na fila", ↑ ↓, "Abrir revisão detalhada ↗"
     e × (REVS-02, REVS-03)
  3. As **duas colunas têm o mesmo peso** — é decisão de desenho declarada no README (dar o mesmo
     destaque a fotos e preço) e **não é reequilibrada** (REVS-06)
  4. A coluna de fotos mostra "x de y aprovadas", a imagem **1:1** com posição #n, ★ PRINCIPAL,
     ✦ "Gerada por IA"/"Original", estado e os **marcadores numerados nas coordenadas x/y reais**, a
     grade de **6 colunas** com borda por estado e o bloco da imagem selecionada com as verificações
     automáticas rotuladas **"VERIFICAÇÃO AUTOMÁTICA · APOIO"** (REVS-07..REVS-10)
  5. ⚠️ **"Aprovar N sem alerta" aprova SÓ as pendentes SEM alerta automático** — foto com alerta
     continua para conferência individual, e o toast diz isso (IMG-05)
  6. A coluna de preço traz os 3 KPIs (**"Resultado estimado por venda"**, nunca "lucro líquido"), o
     aviso de alteração não conferida, a **composição completa com selo de origem por linha**,
     "Informar frete confirmado" quando faltar, a **simulação com o selo "SIMULAÇÃO · NÃO SALVA"** e a
     comparação clicável com a oferta irmã (REVS-11, FIN-11, FIN-13, VOC-03)
  7. A simulação **nunca é persistida nem enviada ao ML**, só pode ser salva quando a oferta é `live`,
     e salvar **incrementa a versão**, limpa o aviso de alteração e registra no histórico
     (FIN-11, FIN-12)
  8. **"Aprovar para publicação" fica `disabled=` enquanto houver bloqueio** — gate com `/disabled=/`,
     ⚠️ **nunca** `/disabled/`, que casa com `disabled:opacity-40` — e só funciona com **zero
     bloqueios e andamento `live`** (BLOQ-04, GATE-05)
  9. **Cada pendência tem o seu botão de correção**, e ele leva ao lugar certo: fotos abre **a primeira
     com alerta ou a primeira pendente**, frete abre o modal de frete, prejuízo/margem abrem a
     exceção, alteração de preço **confere sem alterar o preço**, ajuste e título abrem a revisão
     detalhada na aba certa (REVS-12, BLOQ-07)
  10. Sem bloqueio, o rodapé mostra o literal verde **"Fotos, preço e conteúdo conferidos"**; com
      bloqueio, o motivo em vermelho com o primeiro abreviado (BLOQ-12)
  11. **Exceção autorizada libera SÓ `loss` e `margin`** — nunca fotos, frete, alteração, ajuste ou
      título — e exige responsável + justificativa de **pelo menos 10 caracteres**, ficando no
      histórico como "§ Exceção autorizada por X · data · justificativa" (BLOQ-08, MOD-02, DADO-11)
  12. **Pedir ajuste move as ofertas afetadas para `changes`** conforme o **escopo** (`offer` / `set`
      / `product`), com a caixa "Encaminhar para" mostrando o destino; **resolver o último ajuste
      aberto devolve a oferta para `awaiting`** (MOD-01, AND-07, DADO-09)
  13. **Reprovar imagem exige motivo**, tira a foto do conjunto final **na hora**, manda para "Fora da
      publicação", **promove a próxima a principal se era a principal** e **reabre a aprovação de
      todas as ofertas do conjunto** (MOD-04, IMG-01, IMG-06)
  14. **Confirmar frete** grava valor + origem (cotação / tabela do cliente / simulador), mostra a
      **prévia do resultado e da margem antes de confirmar** e **incrementa a versão** (MOD-03,
      DADO-12)
  15. Os modais têm a casca comum (**560px**, topo **96px**, backdrop `rgba(0,0,0,0.65)`,
      `max-height: calc(100vh - 140px)`, raio 16px) e ⚠️ **declaram a fonte do módulo por conta
      própria** — conteúdo portado não herda fonte (MOD-05, REND-05)
  16. **A revisão parcial fica salva no SERVIDOR** e outra pessoa continua de onde a primeira parou;
      salvar move `awaiting → ecf_review` com o registro "Salvou revisão · x de y fotos aprovadas"
      (COL-01, AND-06)
  17. ⚠️ **Concorrência tratada:** se a `version` mudar enquanto alguém revisa, a tela **avisa e reabre
      as verificações afetadas** — nunca sobrescreve em silêncio (COL-04)
  18. ⚠️ **O painel nunca mostra status do Mercado Livre e nunca escreve no ML nesta fase** (AND-02,
      REVS-14)
  19. Render adverso de todo campo novo, incluindo ⚠️ **chave vinda do servidor com guarda de tipo
      ANTES de qualquer `obj[chave]`/`hasOwnProperty`** — os dois **lançam** com `{toString:null}` e
      derrubam a página inteira (REND-03)
  20. O plano traz a tabela FID-01 do painel e dos 4 modais contra `Aprovacao.dc.html`, declarando
      como mediu (FID-01, FID-02)

**Plans**: TBD
**UI hint**: yes

### Phase 189: Imagem ampliada — o lightbox com zoom de 2,5× que segue o cursor

**Goal**: dá para olhar a foto **de perto**: tela cheia, imagem 1:1 de até 78vh, zoom de 2,5× seguindo
o cursor, marcadores numerados batendo com as verificações automáticas, miniaturas, e a lateral de
320px com metadados, original de referência, verificações e as ações de aprovar/reprovar/regenerar.

**Depends on**: Fase 188 (o painel que abre o lightbox e as ações de imagem que ele repete).

**Requirements**: AMPL-01..07

**Migration**: não.

**Success Criteria** (o que deve ser VERDADE):

  1. Tela cheia com fundo `rgba(5,5,5,0.94)` e grid `minmax(0,1fr) 320px`, **acima do painel** na
     ordem de camadas e **por portal**, sem alterar o header (AMPL-01, TERR-06)
  2. A imagem é **1:1 com até 78vh**, o zoom é **2,5× seguindo o cursor**, e os **marcadores
     numerados** coincidem com os pontos listados na lateral (AMPL-02, AMPL-06)
  3. ⚠️ **O zoom NÃO usa `duration-[Nms]`, `delay-[…]` nem `ease-[…]`** — essas classes **não
     compilam neste projeto** (`tailwindcss-animate` as torna ambíguas) e a transição não acontece com
     todos os testes de render passando. A duração vai por `style={{ transitionDuration }}` ou token,
     com gate provando ausência do arbitrário e **`duration-200` como controle** (AMPL-03, GATE-09)
  4. A lateral de 320px traz metadados (origem, posição, variação, estado), a **original de
     referência** com "Comparar lado a lado na revisão detalhada" levando à aba Fotos **na mesma
     imagem**, as verificações automáticas e os botões de ação (AMPL-04..AMPL-07)
  5. ⚠️ **Sem original disponível, a lateral mostra o estado declarado "original de referência não
     disponível"** — nunca quadro vazio, nunca a imagem de IA repetida (IMG-13)
  6. As travas de estado são as mesmas da revisão simples: só `live` edita, e imagem em
     `regenerating` **não aceita ação** (AMPL-07, IMG-09)
  7. ←/→ trocam a foto e Esc fecha devolvendo o foco ao painel, na mesma imagem
  8. O plano traz a tabela FID-01 do lightbox, declarando como mediu (FID-01, FID-02)

**Plans**: TBD
**UI hint**: yes

### Phase 190: Revisão detalhada — a página, e a aba Fotos com a comparação lado a lado

**Goal**: existe uma página própria de revisão, com a barra fixa que volta **para a mesma posição da
fila com os filtros mantidos**, o seletor Clássico | Premium, as quatro abas com badge e o resumo de
aprovação — e a **aba Fotos** completa: galeria arrastável, "Fora da publicação" e "Originais
disponíveis", a comparação **"Gerada por IA" × "Original de referência" com zoom 1×–4×
sincronizado**, o checklist de fidelidade, os defeitos e as quatro ações de imagem.

**Depends on**: Fases 186 (checklist, defeitos, vínculo durável de referência), 188 (as ações de
imagem e o rodapé) e 189 (o lightbox, que esta página também abre).

**Requirements**: REVD-01..09, IMG-07..13, MOD-06

**Migration**: não — o dado é o da Fase 186 (DADO-07, DADO-08, DADO-15).

**Success Criteria** (o que deve ser VERDADE):

  1. A barra fica **fixa abaixo do header**, com "← Fila de aprovação" voltando **para a mesma posição
     e com os filtros mantidos**, "Posição x de N · filtros mantidos", ↑ ↓ entre ofertas e
     "REVISÃO DETALHADA" (REVD-01)
  2. O seletor **Clássico | Premium** mostra **preço e resultado de cada um**, e a aba aberta está na
     **URL** (`…/aprovacao/:offerId/:aba`) (REVD-02, REVD-03, COL-03)
  3. Cada aba tem **badge de estado** e o **resumo de aprovação** à direita lista Fotos x/y · Preço ·
     Conteúdo · Ajustes · Validações técnicas, cada item com ✓ ou ! **e texto** (REVD-03, REVD-04,
     VOC-05)
  4. O **rodapé fixo é o mesmo da revisão simples, SEM "Próximo"** (REVD-05)
  5. A aba Fotos usa o grid `290px | 1fr | 330px`; a galeria tem linhas arrastáveis com ⋮⋮, #pos,
     miniatura de 46px, rótulo, "✦ IA"/"Original", estado, selo **NOVA** e as ações ✦ / ★ / ▲▼
     (REVD-06)
  6. **"FORA DA PUBLICAÇÃO"** mostra reprovadas e substituídas com **hachura vermelha**, e **original
     pode ser recolocada** (voltando a `pending` e saindo de fora); **"ORIGINAIS DISPONÍVEIS"** tem
     "+ Adicionar" (REVD-07, IMG-08)
  7. A comparação lado a lado tem **zoom 1×–4× SINCRONIZADO pelo cursor** nas duas imagens, com
     alternância para imagem única, e o **"Conjunto final"** diz "N fotos vão para a publicação · N
     ainda precisam de aprovação" (REVD-08)
  8. ⚠️⚠️ **Sem original de referência, o painel da esquerda mostra o estado declarado "original de
     referência não disponível", com ícone e texto — nunca quadro vazio, nunca a imagem de IA
     duplicada no lugar da original, nunca zero. E isso vale para TODO o acervo de hoje: na estreia,
     NENHUMA imagem antiga terá original**, porque o vínculo só passa a ser gravado na geração
     (DADO-15 escrito nas Fases 192 e 194) e **o dado antigo foi apagado pela retenção de 48 h — não
     há backfill possível** (IMG-13, §4b do REQUIREMENTS)
  9. **"FIDELIDADE À ORIGINAL"** traz os 7 critérios (⚠️ "Quantidade do kit" **só em combos**) com
     ✓ Confere / × Diverge e o contador "x de y conferidos"; **"DEFEITOS ENCONTRADOS"** traz os 6
     chips (REVD-09, DADO-07, DADO-08)
  10. **"Reprovar com motivo" vem PRÉ-PREENCHIDO com os critérios divergentes e os defeitos
      marcados** (REVD-09)
  11. **"Substituir por original"**: grade de originais do produto com as que já estão na galeria em
      `disabled=`; a original entra **na mesma posição**, **mantém a aprovação se já estava aprovada**
      e, se não, entra `pending` (MOD-06, IMG-03)
  12. Reordenar e trocar a principal mantêm a galeria consistente (a principal é sempre a primeira), e
      **toda** ação que muda o conjunto final **reabre a aprovação de todas as ofertas do conjunto**
      (IMG-07, IMG-06)
  13. Imagem em `regenerating` **não aceita** aprovar, reprovar, substituir nem regenerar (IMG-09)
  14. **Galeria vazia é bloqueio (`nophoto`)**, não tela vazia silenciosa (IMG-12)
  15. Render adverso de toda a aba, com a prop inteira nula e com grupo nulo — ⚠️ **na Fase 178 grupo
      nulo derrubava o painel inteiro e só o render adverso pegou** (REND-01..03)
  16. O plano traz a tabela FID-01 da página e da aba Fotos, declarando como mediu (FID-01, FID-02)

**Plans**: TBD
**UI hint**: yes

### Phase 191: Revisão detalhada — Preço e custos, Conteúdo do anúncio, Pendências e histórico

**Goal**: as outras três abas da revisão detalhada: os **dois cards Clássico × Premium** com a tabela
"Composição | Origem e atualização | Valor", a **tabela de campos do anúncio** com contador /60 e
"Comentar" em cada campo mais a **prévia do que será enviado**, e os **ajustes + a linha do tempo de
versões**.

**Depends on**: Fase 190 (a casca da página e as abas) e Fase 188 (o modal de ajuste, reusado por
cada campo).

**Requirements**: REVD-10..18

**Migration**: não.

**Success Criteria** (o que deve ser VERDADE):

  1. **Preço e custos** mostra **dois cards lado a lado**, Clássico e Premium, com **o card em revisão
     destacado**, cada um com KPIs e **bloqueios financeiros com botão de correção** (REVD-10,
     REVD-11)
  2. A tabela **"Composição | Origem e atualização | Valor"** fecha em **"Resultado estimado por venda
     · margem de contribuição"**, e o **frete pago pelo comprador aparece como "não entra"** — nunca
     somado (REVD-12, FIN-08)
  3. Valor ausente aparece como **"Pendente"** em vermelho, **nunca `R$ 0,00`**, e o total diz
     **"Incompleto"** (VOC-04, FIN-05)
  4. A nota de composição do combo é literal ("N un. × R$ x = R$ y"), e a **exceção registrada**
     aparece quando houver (REVD-13, FIN-14)
  5. **Conteúdo do anúncio** traz a tabela de campos: título com contador **/60** em verde ou
     vermelho, descrição, categoria, atributos obrigatórios com ✓, variações, SKU, estoque (⚠️ em
     combos, também **"N kits disponíveis"**) e composição do kit (REVD-14)
  6. ⚠️ **Cada campo tem "Comentar", que abre o modal de ajuste JÁ COM O ITEM selecionado** — nenhum
     botão que abre o modal vazio (REVD-14, MOD-01)
  7. A **prévia fixa** mostra o que será enviado — foto principal, miniaturas **na ordem final**,
     título, preço, modalidade, formato e disponibilidade — ⚠️ refletindo o **conjunto final**, não a
     galeria original (REVD-15)
  8. **Pendências e histórico** traz os ajustes como cards com **item → destino** (⚠️ em **âmbar**
     quando vai para o cadastro do produto), texto, autor/data, estado e **"Marcar como resolvido"**,
     mais "+ Solicitar ajuste" (REVD-16)
  9. A **linha do tempo** mostra os **8 tipos de evento** com ícone próprio, em ordem, com quem e
     quando (REVD-17, DADO-10)
  10. Oferta `approved` mostra a caixa verde literal "Aprovado na versão N por X. **Qualquer alteração
      em fotos, preço ou conteúdo reabre a aprovação.**" — **e ela de fato reabre** (REVD-18,
      BLOQ-06)
  11. ⚠️ **Data nunca por `new Date()` no cliente** — `Invalid Date` e o deslocamento de fuso de
      data-only em BRT já morderam este projeto (COL-06)
  12. Render adverso das três abas, e ⚠️ **teste que depende de `now()` ancorado com
      `Carbon::setTestNow()`** — o "aguardando há X" reprovaria por horário (REND-01, REND-08)
  13. O plano traz a tabela FID-01 das três abas, declarando como mediu (FID-01, FID-02)

**Plans**: TBD
**UI hint**: yes

### Phase 192: "Gerar novamente com IA" — corrigir a foto errada sem sair da revisão

**Goal**: quando a imagem de IA sai errada, dá para **regenerar só aquela imagem** de dentro da
revisão: chips de problema, instrução livre, referências originais já marcadas e **envio de até 6
fotos na hora** — chamando o Creative Engine que **já existe**, nunca reimplementando.

**Depends on**: Fases 188 (o painel e as ações de imagem), 190 (a aba Fotos, de onde também se
regenera) e 186 (o vínculo durável de referência, que esta fase passa a **escrever**).

⚠️ **BLOQUEADA por duas respostas do outro dev** (ver "Decisões em aberto"): como os **chips
estruturados** se encontram com o `$ajusteOperador` de **texto livre** (IA-03), e se ele pretende
mexer em `validacao_status`, no `PublicadorCriativoAprovacaoService` ou nos endpoints de criativo nas
próximas semanas. ⚠️ **A segunda pergunta antiga — se o criativo é alcançável a partir da imagem
revisada — já está RESPONDIDA por medição: SIM**, por `ml_anuncio_criativos.pub_imagem_id`.

**Requirements**: IA-01..10, MOD-07..10

**Migration**: não — escreve o DADO-15 criado na Fase 186.

**Success Criteria** (o que deve ser VERDADE):

  1. ⚠️ **Nada foi reimplementado:** a regeneração **chama** `GeminiImageProvider::gerarImagem`,
     `CreativePromptBuilder::paraSlot(…, $regeneracao, $ajusteOperador)`,
     `CreativePermissao::podeRegenerar(User)` e `ReferenciaEfemeraService::guardar(…)`, e
     **`app/Services/Creative/`, os endpoints `criativo*` e o `PublicadorCriativoAprovacaoService`
     têm diff VAZIO**, provado **commit por commit** (não por range — há sessão paralela) e com
     `test -e` em cada alvo (IA-01, TERR-01, GATE-07)
  2. O modal regenera **SÓ ESTA IMAGEM**, e o pedido inclui **a imagem atual e a posição**, os
     **problemas marcados**, a **instrução livre** e as **referências** (originais + fotos enviadas)
     (IA-02)
  3. Os **8 chips de problema** são os literais do README, e **a decisão sobre estruturado × texto na
     nossa borda está registrada** em `.planning/coordenacao/` antes de uma linha de código (IA-03)
  4. As **originais do produto são selecionáveis com a referência daquela imagem JÁ MARCADA**, e o
     **envio de fotos** aceita clique ou arrastar, **até 6**, só `image/*`, com prévia e remover
     (IA-04, MOD-08, MOD-09)
  5. O criativo é alcançado a partir da imagem revisada por `pub_imagem_id` (indexado), e **imagem
     vinda de upload, que não tem criativo, é tratada como caso válido** — não como erro (IA-05)
  6. **"Gerar nova versão" só habilita com um problema marcado OU instrução com mais de 5
     caracteres** (IA-06)
  7. Durante a geração a imagem mostra **"Gerando nova versão…"** (estado `regenerating`, com ícone e
     texto) e **nenhuma ação é aceita** naquela imagem (IA-07, IMG-09)
  8. Ao terminar, a nova versão entra **na MESMA POSIÇÃO como NOVA e `pending`**, a anterior vai para
     "Fora da publicação" como `replaced`, **a nova vira principal se a anterior era**, a aprovação do
     conjunto **reabre**, e o histórico ganha "Nova versão gerada: … · aguardando revisão" (IA-08,
     IMG-02, IMG-06)
  9. ⚠️ **A regeneração é assíncrona de verdade** — fila do projeto, **nunca HTTP síncrono na
     requisição**, e **nunca `setTimeout` simulando conclusão** (artefato do protótipo); ⚠️ **job
     interativo vai para a fila `high`**, porque a `default` de produção é congestionada e já segurou
     webhook por horas (IA-09)
  10. ⚠️ **O vínculo durável imagem gerada → `pub_imagens` de referência é GRAVADO aqui** (DADO-15), e
      a comparação lado a lado da Fase 190 passa a ter original **para as imagens geradas daqui para
      frente** — o acervo antigo segue em IMG-13, por falta de dado, **não** por falta de tela
  11. Quem não passa em `CreativePermissao::podeRegenerar` **não vê a ação habilitada**, com
      `disabled=` e explicação — nunca um clique que falha calado (IA-10, GATE-05)
  12. O plano traz a tabela FID-01 do modal, declarando como mediu (FID-01, FID-02)

**Plans**: TBD
**UI hint**: yes

### Phase 193: Do aprovado ao ar — o portão fecha o ciclo

**Goal**: aprovar deixa de ser um carimbo e passa a **publicar**: a oferta aprovada vira publicação de
verdade pela fila que já existe, `publicando` bloqueia edição, `publicado` só com **MLB confirmado**,
e a falha **preserva a revisão aprovada** com "Tentar de novo".

**Depends on**: Fases 186 (versão e `approvedVersion`), 187 (a fila e o lote) e 188 (o botão que
aprova).

✅ **DESBLOQUEADA — o usuário decidiu em 14/10/2026: aprovar JÁ PUBLICA.** A aprovação enfileira na
hora, sem segundo clique. ⚠️ **O rótulo do botão NÃO muda: continua o literal do protótipo, "Aprovar
para publicação"** (Fase 188, critério 8) — ele **já** diz que a oferta vai para publicação, e trocar
para "Aprovar e publicar" seria adaptar o protótipo sem necessidade, o erro que fez o redesign
anterior falhar. **A decisão do usuário muda o COMPORTAMENTO, não o texto.**
A razão é a própria decisão da gestão: *"o sistema funcione de forma autônoma
para publicar mas que tenha uma aprovação antes de ir para ar"* — **o portão É a aprovação**, e exigir
um segundo ato criaria uma pilha de "aprovados que ninguém publicou", que dependeria de alguém
lembrar. ⚠️ **As duas alternativas foram oferecidas e RECUSADAS:** publicar em dois atos, e publicar
com janela para desfazer. **Não reabrir, e não introduzir o segundo clique "por segurança".**
⚠️ **Consequência que esta fase TEM de honrar:** como não há desfazer, a reversibilidade mora toda
**antes** do clique — por isso os bloqueios da 186 e a exceção registrada da 188 são o que impede
publicar errado, e a falha de publicação **preserva a revisão aprovada** em vez de voltar à fila.

**Requirements**: PUB-01..10

**Migration**: ⚠️ **provável, e pequena** — a coluna de ligação entre a oferta aprovada e a fila de
publicação (`pub_fila_publicacao_itens` / `pub_publicacao_itens`). **Nada além disso**, e sob a mesma
disciplina MIG-01..09. O planejamento decide se a ligação é coluna nova ou derivável.

**Success Criteria** (o que deve ser VERDADE):

  1. **Nada vai ao Mercado Livre sem passar pelo portão:** oferta publicada por este caminho tem
     `approvedBy` e `approvedVersion` registrados, e isso aparece no histórico (PUB-01, PUB-08)
  2. ⚠️ **A publicação REUSA a fila que existe** (`FilaPublicacaoService`, `AgendadorDaFila`,
     `publicador:fila-publicacao` de minuto em minuto) e **a diferença de granularidade oferta ×
     produto está resolvida e registrada**, sem duplicar
     `validacao_id`/`plano_hash`/`revisao`/`ciente`/`precisa_revisar` (PUB-02, DADO-03)
  3. ⚠️ **`app/Services/Mlb/Publicacao/MlPublicacaoService.php` tem diff VAZIO** e o **gate de conta
     travada do módulo continua valendo** (PUB-03, TERR-03)
  4. `publicando` **bloqueia edição**; `publicado` **só com MLB confirmado** (sem MLB o estado não
     avança e o toast de "Publicado e confirmado" **não dispara**); `falha` **preserva
     `approvedVersion` e toda a revisão**, e "Tentar de novo" muda direto para `publishing`
     (PUB-04, AND-04, BLOQ-11)
  5. O erro exibido é o **erro real** registrado, e a frase "Nenhuma oferta foi criada; a revisão foi
     preservada" aparece **só quando é verdade** (PUB-05)
  6. ⚠️ **A escrita obedece a disciplina do módulo:** trava de conta, **registro ANTES do HTTP**,
     `5xx`/rede tratados como **incerto** (`UNKNOWN`), **sem reenvio por conta própria**, e `UNKNOWN`
     nunca reenviado sem reconciliar pelo SKU (**RN-93**) (PUB-06)
  7. ⚠️ **A fronteira do D-11 está provada:** o gate D-11 de `tests/js/estrutura-meus-anuncios.test.js`
     continua **intacto** (a lista não escreve), **não foi estendido** à área nova, e a área nova tem
     **gate de escrita próprio** (PUB-07, D11)
  8. A decisão do usuário sobre enfileirar automaticamente está **registrada** e o código faz o que
     ela diz — nunca os dois caminhos ao mesmo tempo (PUB-09)
  9. **Nenhuma rota, botão ou coluna sem destino** nesta fase (PUB-10, D4)
  10. O fechamento mede o caminho completo numa oferta real, **em produção, por checkpoint humano na
      conta 459** — ⚠️ **nenhum executor afirma ter visto a tela**: o banco local não tem empresa com
      `ml_token` e `ml_acervo_itens` está vazia (GATE-14)

**Plans**: TBD
**UI hint**: yes

### Phase 194: A automação ligada atrás do portão — e o fechamento da milestone

**Goal**: a decisão da gestão de 14/10 entregue: `publicador.criativos_auto.ativo` é **ligado**, com
as sete condições de segurança intactas, **uma empresa por vez** — e a imagem automática entra na
**fila de aprovação** em vez de ir ao ar.

**Depends on**: Fases 186-193 — a fila, a revisão, a regeneração e o caminho da publicação.

⚠️ **BLOQUEADA por uma resposta do outro dev:** o que falta do lado dele para destravar
(`AUTO-07`). A fase **não liga nada** sem a resposta registrada em `.planning/coordenacao/`.

**Requirements**: AUTO-01..08

**Migration**: não.

**Success Criteria** (o que deve ser VERDADE):

  1. Oferta criada automaticamente entra na fila em **`preparando`**, com a frase **real** do que
     falta (ex.: "calculando frete e composição de preço"), e **não abre revisão** até estar pronta
     (AUTO-01, AND-03)
  2. Quando o preparo termina, a oferta vira **"Aguardando aprovação"** — ⚠️ **nunca `aprovado`,
     nunca publicada direto** (AUTO-02)
  3. A origem aparece na meta da linha como **⚡ Automática** ou **✎ Manual** (AUTO-03, FILA-11)
  4. ⚠️ **As sete condições de segurança do `CriativosAutomaticosService` seguem INTACTAS** —
     inclusive `publicador_criativos_auto_companies`, `publicador_criativos_auto_usuario` e o hash de
     fotos + ficha que impede repedir o mesmo produto. **Nenhuma foi removida nem afrouxada**
     (AUTO-04)
  5. A chave é **desligável sem deploy**, e o roteiro de desligamento está escrito no fechamento
     (AUTO-05)
  6. ⚠️ **A ativação é por EMPRESA, começando por UMA**, com roteiro de conferência humana em
     produção antes de ampliar (AUTO-06)
  7. A resposta do outro dev está **registrada** em `.planning/coordenacao/` antes de a chave virar
     (AUTO-07)
  8. **O fechamento da milestone MEDE o portão funcionando:** quantas ofertas automáticas entraram na
     fila, quantas foram aprovadas, quantas foram reprovadas e **por quê** — é o número que prova que
     a automação está segura (AUTO-08)
  9. O fechamento repete, para a milestone inteira, o que fez a v26.0 funcionar: **campanha de
     mutação** com contagem travada e restauração por sha256 (⚠️ **sobrevivente é INVESTIGADA**), a
     conferência das baselines **re-medidas na sessão** (não copiadas), o manifest **pelo JSON** e o
     inventário de **não-regressão** (GATE-10, GATE-14)

**Plans**: TBD
**UI hint**: yes

#### Dependências — Milestone v27.0 (Fases 186-194)

```
186 (vocabulário + espinha de dados: 8 estados, bloqueios, financeiro, 12 estruturas) ⚠️ MIGRATION
 └── 187 (fila "Aguardando aprovação" + as abas da seção A)
       └── 188 (revisão simples: painel 1140px + 4 modais)  ← o portão passa a ser USÁVEL
             ├── 189 (lightbox com zoom 2,5×)
             │     └── 190 (revisão detalhada: casca + aba Fotos + comparação lado a lado)
             │           ├── 191 (abas Preço e custos · Conteúdo · Pendências e histórico)
             │           └── 192 (Gerar novamente com IA) ── BLOQUEADA: 2 respostas do outro dev
             └── 193 (do aprovado ao ar) ── BLOQUEADA: 1 decisão do usuário (enfileirar automático?)
                   └── 194 (ligar a automação + fechamento) ── BLOQUEADA: 1 resposta do outro dev
```

**Onde se pode parar sem deixar nada pela metade:** depois da **188** (dá para aprovar de verdade,
com a fila e o painel), depois da **191** (a revisão completa) e depois da **193** (o ciclo fechado).
Parar na **189** ou na **192** também é íntegro — são acréscimos à revisão que já funciona.

#### Coverage Map — Milestone v27.0 (REQ-ID × fase)

| Requirement | Phase | Status |
|---|---|---|
| VOC-01..VOC-08 (8) | Phase 186 | Pending |
| ABA-03, ABA-04 (2) | Phase 186 | Pending |
| AND-01..AND-10 (10) | Phase 186 | Pending |
| DADO-01..DADO-15 (15) | Phase 186 | Pending |
| BLOQ-01..BLOQ-12 (12) | Phase 186 | Pending |
| FIN-01..FIN-14 (14) | Phase 186 | Pending |
| MIG-01..MIG-09 (9) | Phase 186 | Pending |
| FID-01..FID-08 (8) | Phase 186 (**transversal**) | Pending |
| GATE-01..GATE-14 (14) | Phase 186 (**transversal**) | Pending |
| REND-01..REND-08 (8) | Phase 186 (**transversal**) | Pending |
| TERR-01..TERR-11 (11) | Phase 186 (**transversal**) | Pending |
| ABA-01, ABA-02, ABA-05, ABA-06 (4) | Phase 187 | Pending |
| FILA-01..FILA-16 (16) | Phase 187 | Pending |
| MOD-11, MOD-12 (2) | Phase 187 | Pending |
| REVS-01..REVS-14 (14) | Phase 188 | Pending |
| MOD-01..MOD-05 (5) | Phase 188 | Pending |
| IMG-01..IMG-06 (6) | Phase 188 | Pending |
| COL-01..COL-06 (6) | Phase 188 | Pending |
| AMPL-01..AMPL-07 (7) | Phase 189 | Pending |
| REVD-01..REVD-09 (9) | Phase 190 | Pending |
| IMG-07..IMG-13 (7) | Phase 190 | Pending |
| MOD-06 (1) | Phase 190 | Pending |
| REVD-10..REVD-18 (9) | Phase 191 | Pending |
| IA-01..IA-10 (10) | Phase 192 | Pending |
| MOD-07..MOD-10 (4) | Phase 192 | Pending |
| PUB-01..PUB-10 (10) | Phase 193 | Pending |
| AUTO-01..AUTO-08 (8) | Phase 194 | Pending |

**Cobertura:** **229/229 REQ-IDs** do `REQUIREMENTS-v27.md` mapeados — FID(8) VOC(8) ABA(6) AND(10)
DADO(15) BLOQ(12) FIN(14) FILA(16) REVS(14) AMPL(7) REVD(18) MOD(12) IMG(13) IA(10) PUB(10) AUTO(8)
COL(6) MIG(9) TERR(11) GATE(14) REND(8). **Nenhum órfão, nenhuma duplicata.**

⚠️ **FID, GATE, REND e TERR são transversais:** estão mapeados na **Fase 186** porque é onde nascem,
mas **valem e são conferidos em todas as fases** — cada fase repete a tabela de conferência (FID-01),
o render adverso (REND-01) e a prova de território (TERR-01..11).

#### Coverage Map — item do handoff × fase

| Item do `README-v2.md` / `Aprovacao.dc.html` | Fase |
|---|---|
| A1 · abas no cabeçalho de Publicações | **187** (ABA-01, ABA-02, ABA-05) |
| A2 · renomear "Em revisão" → "Em análise pelo Mercado Livre" | **186** (VOC-01, ABA-03) |
| A3 · o que não muda | 187 (ABA-06) |
| B · modelo de dados (oferta, conjunto, revisão de imagem, checklist, ajuste, histórico, composição) | **186** (DADO-01..15) |
| C · andamento interno × status do ML | **186** (AND-01..10) |
| D · bloqueios, aprovação, imagens, exceção, ajustes, lote | **186** (BLOQ-01..12) + 188 (IMG-01..06) + 187 (BLOQ-10) |
| D · cálculo financeiro, simulação, equilíbrio, preço-alvo | **186** (FIN-01..14) |
| E1 · fila (cabeçalho, faixa de 9, próximo a revisar, filtros, tabela em 3 níveis, lote, rodapé) | **187** (FILA-01..16) |
| E2 · revisão simples (painel 1140px, 2 colunas, pendências, rodapé) | **188** (REVS-01..14) |
| E3 · imagem ampliada (lightbox, zoom 2,5×, lateral 320px) | **189** (AMPL-01..07) |
| E4 · revisão detalhada — barra, abas, resumo, aba Fotos | **190** (REVD-01..09) |
| E4 · abas Preço e custos · Conteúdo · Pendências e histórico | **191** (REVD-10..18) |
| E5 · modais Solicitar ajustes · exceção · frete · reprovar imagem · casca comum | **188** (MOD-01..05) |
| E5 · modal Substituir por foto original | **190** (MOD-06) |
| E5 · modal Gerar novamente com IA (chips, instrução, referências, envio de fotos) | **192** (MOD-07..10, IA-01..10) |
| E5 · modal Aprovar em lote · Toasts | **187** (MOD-11, MOD-12) |
| F · os 10 estados de exemplo | **186** (conferência de `fin()`/`info()`) + 187 (render da fila) |
| G · persistência e colaboração (revisão parcial, URL, concorrência) | **188** (COL-01..06) |
| — · do aprovado ao ar (publicando, publicado com MLB, falha, tentar de novo) | **193** (PUB-01..10) |
| — · ligar `publicador.criativos_auto.ativo` atrás do portão | **194** (AUTO-01..08) |

**Explicitamente FORA de escopo, com motivo:**

| Item | Motivo |
|---|---|
| `support.js`, `image-slot.js` | O README diz: runtime só do protótipo. **Não foram lidos.** |
| `localStorage` (`KEY='ecf-aprovacao-v1'`), "Restaurar dados de exemplo", o `setTimeout` de conclusão da geração | Artefatos de protótipo (COL-02, IA-09). |
| As taxas 12% / 17% / 6,75 / 6% como literal ou config | A fonte real é melhor e já existe (FIN-02, FIN-03). **Gate de ausência** dos três números. |
| Os dados mock (Poltrona Beny, Mesa Tora, Cadeira Oslo, os 10 `OFF`, as pessoas de `EMP`) | Viram dado real; o que não tem fonte vira **Pendente/indisponível**, **nunca zero** (D4). |
| Backfill do vínculo com a original de referência para o acervo antigo | **Impossível:** o dado foi apagado pela retenção de 48 h. O passado fica em **IMG-13** (estado declarado). |
| Mudar a retenção de 48 h ou o `creative:limpar-referencias` | **TERR-11** — território do outro dev, janela por decisão de custo/privacidade da v24.0 (D-02). **Para e pergunta.** |
| **Fase 181** (painel de inspeção) e **Fase 183** (Histórico) da v26.0 | **Pausadas pelo usuário em 14/10/2026.** Seguem registradas, nada apagado. |
| Alterar `MlPublicacaoService`, `App\Support\Publicador`, `AppLayout` ou o gate de conta travada | TERR-03, PUB-03. |
| Alterar `app/Services/Creative/`, os endpoints `criativo*` ou o `PublicadorCriativoAprovacaoService` | TERR-01 — outro dev **ativo**. |
| Redesenhar o corpo de Produtos, Produto, Alavancas, Tarefas, Em massa, Configurações | Fora do pacote de design. |
| Corrigir `tests/js/estrutura-grade-glide.test.js:122` | Falha pré-existente e intencional. |
| Deploy e push | Não autorizados nesta sessão. |

**⚠️ Decisões em aberto — quem bloqueia o quê:**

| # | Decisão | De quem | Bloqueia |
|---|---|---|---|
| 1 | Os **chips de problema** estruturados viram texto na nossa borda, ou o problema é guardado estruturado do lado do Creative Engine (serve ao `CreativeJuiz`)? (IA-03) | **outro dev** | Fase **192** |
| 2 | Ele pretende mexer em `validacao_status`, no `PublicadorCriativoAprovacaoService` ou nos endpoints de criativo nas próximas semanas? Se sim, combinar a ordem | **outro dev** | Fase **192** |
| ~~3~~ | ✅ **RESPONDIDA em 14/10/2026 — aprovar JÁ PUBLICA**, sem segundo clique (PUB-09). ⚠️ O rótulo do botão **continua o do protótipo, "Aprovar para publicação"** — mudou o comportamento, não o texto. Dois atos e janela de desfazer foram oferecidos e **recusados** | usuário | **nada** |
| 4 | O que falta do lado do Creative Engine para ligar `publicador.criativos_auto.ativo`? (AUTO-07) | **outro dev** | Fase **194** |
| ~~5~~ | ✅ **RESPONDIDA em 14/10/2026 — basta REGISTRAR quem liberou**, como no protótipo: marca a exceção, escreve o motivo, e fica gravado quem foi e quando (BLOQ-09). Aprovação em dois passos foi oferecida e **recusada** — não travar a oferta esperando um segundo aprovador, e não criar aviso para isso | usuário | **nada** |
| 6 | Vale oferecer **"recolocar a original manualmente"** para o acervo antigo, que nunca terá o vínculo? (IMG-13) | **usuário** | nada — melhoria futura |

| ~~7~~ | ✅ **DECIDIDA 14/10 — "Embalagem" e "Outros custos" não têm fonte no repositório** (medido). "Outros custos" nasce **R$ 0,00 `Confirmado`** (é o que o próprio protótipo faz: `other: {v: 0, src: 'conf', note: 'Cadastro do produto'}`). "Embalagem" nasce **`Pendente` VISÍVEL e NÃO anula o resultado** — o resultado sai marcado como **estimado por baixo**. ⚠️ É **exceção DELIBERADA à regra "faltando qualquer item o resultado é null"**, e a única: sem ela o painel diria "Incompleto" em **100%** das ofertas e **nada poderia ser aprovado**. As outras duas opções foram oferecidas e recusadas: campo novo de custo de embalagem (trabalho a mais nesta fase) e as duas em zero (assumiria embalagem de graça, inflando a margem) | usuário | **nada** |
| ~~8~~ | ✅ **DECIDIDA 14/10 — fotos de produto com variação: o bloqueio considera TODOS os grupos.** A oferta só fica aprovável com as fotos de **todas** as variações **e** as **gerais** conferidas. ⚠️ **Aprovação cor por cor foi oferecida e RECUSADA**, pela razão que amarra tudo: **aprovar já publica e não há desfazer**, então foto não conferida não pode ir ao ar. Isto resolve o eixo VARIANTE que o plano havia descartado (ver critério 12) | usuário | **nada** |
| ~~9~~ | ✅ **DECIDIDA 14/10 — cada margem diz de onde vem.** O projeto tem **duas** margens de propósito: a do portão e do editor usa a **tarifa real do ML** (`sale_fee_amount`), a da visão rápida do lote usa o **parâmetro da Precificação** (`ResumoRapidoService:384-391`) — e o docblock do `EditorRascunhoService:370-372` diz **"diferente DE PROPÓSITO... não unificar"**. ⚠️ Então **a tela nomeia a fonte de cada número**: no portão *"com a tarifa real do Mercado Livre"*, no lote *"estimada pela sua Precificação"*. Deixar sem texto foi oferecido e recusado — sem isso a divergência chega como **bug de dado**, contra a regra-mestra de acertividade | usuário | **nada** |

| ~~10~~ | ✅ **DECIDIDA 14/10 — o atalho "preço para a margem mínima" APARECE, avisando que não inclui embalagem.** Consequência da decisão 7: como a embalagem fica `Pendente`, `equilibrio` e `precoAlvo` saem **um pouco baixos** e por isso vêm marcados. Escondê-los foi oferecido e recusado — eles são a ferramenta principal da tela e ficariam **vazios em toda oferta**. ⚠️ **Corolário obrigatório, pela mesma razão:** `loss`/`margin` **disparam** sobre o valor estimado por baixo (o resultado é **otimista**, a margem real é menor) — **não bloquear seria o erro grave**, porque aprovar já publica e não há desfazer | usuário | **nada** |
| ~~11~~ | ✅ **DECIDIDA 14/10 — preço por variação: manda o PIOR CASO**, o **menor preço ativo do alvo**, e **a tela nomeia a variação** ("pior caso entre N variações"). Se a cor mais barata dá prejuízo, a oferta **trava**. "Um resultado por cor, lado a lado" foi oferecido e **recusado** por ser aumento de escopo real — mudaria as telas das Fases **188-191**, feitas para um número por oferta | usuário | **nada** |

| ~~12~~ | ✅ **DECIDIDA 15/10/2026 — mudança relevante em oferta JÁ APROVADA faz ela VOLTAR para "Aguardando aprovação"**, com o registro "Aprovação reaberta" no histórico (BLOQ-06). ⚠️ O executor do 186-08 **PAROU e devolveu** em vez de acrescentar o par por conta própria, porque `PubAprovacaoOferta::TRANSICOES` é **lista fechada** (`aprovado` só ia para `publicando`) e há gate contando **12** pares. **O gate fez o trabalho dele.** ⚠️ **Autorizado:** acrescentar `aprovado → aguardando_aprovacao` (13º par) e **REMEDIR** o gate `transicoes` do `contagens-186-04.txt`. ⚠️ **E o gate passa a asseverar o CONJUNTO EXATO de pares, não só a contagem** — 13 pares podem ser alcançados por um par perigoso substituindo um seguro, e a mensagem do gate ("transição a mais aqui publica sem ninguém ter conferido") **não se aplica a este par**: ele NÃO publica, ele DESAPROVA. As duas alternativas foram oferecidas e recusadas: manter "Aprovada" marcada como alterada (⚠️ furo medido: nesse estado a oferta **não é editável**, então ninguém consertaria a foto que acabou de trocar) e reabrir como "Ajustes solicitados" (rótulo que mente) | usuário | **nada** |
| 13 | ⚠️ **EM ABERTO, e NÃO bloqueia a Fase 186:** `PUBLICADO => []` é **terminal** — não há transição nenhuma saindo de "Publicado". A decisão 12 cobre a janela entre aprovar e publicar, que é o que o BLOQ-06 pede. **Mexer na foto de um anúncio que JÁ ESTÁ NO AR é outro fluxo** (editar publicado), e quem decide é a **Fase 193**. Registrado aqui para ninguém descobrir depois | usuário / Fase 193 | **nada na 186** |

**⚠️ RESPONDIDAS por medição em 14/10/2026, não reabrir:**

- ~~"A regeneração de UMA imagem com instrução e referências enviadas existe?"~~ → **existe**, nas
  quatro peças (achado 2).
- ~~"O `MlAnuncioCriativo` é alcançável a partir da imagem que a fila revisa?"~~ → **sim**, por
  `ml_anuncio_criativos.pub_imagem_id`, indexado (achado 3). **Nenhuma coluna nova.**
- ~~"O vínculo imagem de IA → original de referência está gravado?"~~ → **está, mas é EFÊMERO (48 h)**
  (achado 4). Virou **DADO-15** (vínculo durável, nosso), **IMG-13** (estado declarado para todo o
  acervo de hoje) e **TERR-11** (não mexer na retenção alheia).

---

*Roadmap atualizado: 2026-10-14 — **Milestone v27.0 (Aprovação humana antes de publicar)** anexada:
**9 fases (186-194)** cobrindo os **229 REQ-IDs** do `REQUIREMENTS-v27.md`, derivados de
`design_handoff_publicador/README-v2.md` + `Aprovacao.dc.html` e da **decisão da gestão da ECF de
14/10/2026**. **Numeração contínua a partir de 186** (maior fase existente: **185**, conferida por
`grep "^### Phase" .planning/ROADMAP.md | tail` — ⚠️ o `phase.add` do GSD numera pelo DIRETÓRIO e já
colidiu duas vezes neste projeto); `phases.clear` **NÃO** foi executado e **nada** anterior foi
tocado — Fases 1-185 preservadas integralmente, incluindo a **181** e a **183**, **pausadas pelo
usuário em 14/10** e **não apagadas**, e o trabalho em aberto da sessão paralela (184/185). O
`STATE.md` **não foi alterado** de propósito (o `state.advance-plan` já corrompeu o STATE deste
projeto **seis** vezes). **O que esta milestone tem de diferente:** ela não é acabamento, é
**destravamento** — a automação que a gestão pediu **já existe, pronta e desligada** em
`CriativosAutomaticosService`, e o portão de aprovação é o que torna seguro ligar aquela chave, o que
faz a **Fase 194 ser o destino da milestone e não um apêndice**. **É também a primeira milestone com
migration desde a v26.0** (que declarou nenhuma): são **12 estruturas novas**, concentradas na Fase
186 e quebradas **por assunto**, nunca numa migration monolítica — ⚠️ `pub_rascunhos` tem 6 estados e
eles são de **PUBLICAÇÃO**, não de aprovação, e `PubImagem.status` é de **UPLOAD**, não de revisão:
são eixos diferentes e **não se reusam**. **A seção A foi dividida de propósito:** o **rename** vem na
Fase 186, isolado, porque esta milestone introduz "Em revisão pela ECF" e renomear depois criaria uma
janela com dois "Em revisão" na tela (e "Em revisão" é **substring** de "Em revisão pela ECF" —
asserção negativa cega quebraria a tela certa); as **abas** vêm na Fase 187, com a rota que elas
abrem, porque uma aba com contador real que não leva a lugar nenhum é a definição de enfeite — régua
que o usuário **já exerceu três vezes** na milestone anterior. **Tensões declaradas, não escondidas:**
(a) **esta milestone ESCREVE** (aprovar, regenerar, publicar) enquanto o gate **D-11** prova que a
lista **não** escreve — a fronteira está declarada (o D-11 vale para a LISTA; a FILA escreve, com gate
de escrita próprio) para o primeiro executor não ler contradição; (b) as taxas do README são
**fictícias** e neste sistema a fonte real é **melhor que config** — comissão e tarifa fixa vêm da API
do ML (`/sites/MLB/listing_prices` via `SimuladorVoceRecebe`), o imposto de
`EstruturaPrecificacaoParametros` com a exceção da oferta vencendo, o custo de
`DadosEfetivosService::custosDoProduto()`, e **a margem mínima é a única config nova** (`grep` de
`margem_minima` voltou vazio); (c) a granularidade do desenho é por **OFERTA** e a da fila de
publicação de hoje é por **PRODUTO**, que já guarda `revisao`/`ciente`/`validacao_id` — resolver isso
**sem duplicar** é requisito explícito (DADO-03, PUB-02); (d) ⚠️ **o vínculo com a original de
referência é efêmero e morre em 48 h**, então na estreia **nenhuma** imagem antiga terá original para
comparar, e isso aparece como **estado declarado** ("original de referência não disponível"), nunca
como painel vazio — **sem backfill possível**, e **sem tocar a retenção do outro dev**. **Quatro
decisões seguem em aberto e bloqueiam fases nomeadas** (chips estruturados e a ordem com o outro dev →
192; enfileirar automaticamente → 193; o que falta para ligar a chave → 194), e **três perguntas
antigas foram RESPONDIDAS por medição** em 14/10 — a regeneração de uma imagem existe, o criativo é
alcançável por `pub_imagem_id` (nenhuma coluna nova) e o vínculo com a original existe mas é efêmero.*
