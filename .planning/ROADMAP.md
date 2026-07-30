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
- [ ] 120-03-PLAN.md — A bifurcação: `computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa`, denominador só de empresas `complete` (D-01), cobertura de 70% governando `official`/`partial` (D-02/D-03), só-Shopee `official` sem código especial e os cenários espelho da D-05 [AGRE-01, AGRE-05, AGRE-06]

> **Planos 01 e 02 fechados em 2026-07-30.** Cache em `v14` (a Fase 119.1 consumiu `v13` antes). Shadow (`$incluirEmpresasScore`) roda com garantia em `desempenho:consolidar-mes`/`desempenho:warm-cache` (guard do `Cache::remember`, C-02) e contagem zero comprovada em leitura interativa (D-04). Flag `metrics.performance_company_first_score` continua `false`. Falta o Plano 03 (bifurcação de `nota_final`).

### Phase 121: Comparação antigo × novo e validação da régua em pp (v21.0)

**Goal:** Antes de ligar a flag em produção, existe evidência numérica de quanto a nota de cada profissional muda e de como a régua reusada se comporta sobre a distribuição real de pontos percentuais da carteira.
**Requirements**: ROLL-01, ROLL-02, ROLL-03
**Depends on:** Fase 120
**Success Criteria** (o que deve ser VERDADE):

  1. `php artisan desempenho:comparar-score-empresa --mes=YYYY-MM` reporta por profissional: `nota_antiga`, `nota_nova`, `delta`, empresas total/complete/partial e a maior causa do delta
  2. A comparação roda sobre a última competência fechada e as 7 amostras de risco do plano §6 são conferidas manualmente
  3. A distribuição real de `margem_var_pp` da carteira inteira é medida e apresentada — confirmando ou refutando que a régua reusada (D2) produz dispersão aceitável
  4. **GATE:** o usuário aprova explicitamente o delta antes de qualquer ativação de flag em produção

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


---
*Roadmap atualizado: 2026-07-20 — Milestone v18.0 (Períodos, competência de bônus e variação via Adman) anexada: 5 fases (100-104) cobrindo as 23 REQs (PER/ADM/BON/CAR/UIP) do REQUIREMENTS-v18.md, estrutura vinda do plano canônico do usuário (plano-carteira-desempenho-multi-servico.md, seções "Regra de período/fechamento/pagamento" e "Regra de variação de margem via Adman"). Numeração com buffer 97-99 reservado para a milestone NPS Anti-Burlamento do dev paralelo (Fases 94-96, ainda em aberto). Fundação em 100 (`MetricPeriodResolver`) e 101 (`AdmanMetricDiffService`), independentes entre si; 102 e 103 dependem de ambas; 104 depende de 102+103. Baseline oficial de bônus usa janela de mesmo tamanho (N dias imediatamente anteriores), não mês calendário — decisão do usuário 2026-07-17. Fases 60-96 preservadas intactas.*

*Roadmap atualizado: 2026-07-24 — Milestone v20.0 (Handoff Comercial HubSpot) anexada: 5 fases (111-115) derivadas do plano canônico `prompt-claude-otimizacao-comercial-hubspot.md`, preservando a estrutura de 10 estágios do prompt. Fundação (111) → HubspotValueResolver + handoff service (112, núcleo do valor mensal×anual) → enriquecimento contato/empresa + dedup (113) → UI Comercial + replay (114) → E2E + doc (115). Trabalho ADITIVO: fluxo legado (Fases 34-37) e testes atuais preservados; nenhum teste chama HubSpot real. Conflict-detection do import: só INFO (sem locked-decisions HubSpot pré-CONTEXT); 1 WARNING = mudança semântica de como `valor_contratado` é populado em closes futuros (histórico intacto). Fases 100-110 (v18/v19) preservadas.*

*Roadmap atualizado: 2026-07-27 — Milestone v21.0 (Desempenho por nota individual de empresa) anexada: 7 fases (117-123) cobrindo as 38 REQs (MPP/NPSE/EMPS/AGRE/ROLL/SNAP/UIEM) do REQUIREMENTS-v21.md, derivadas do plano canônico `plano-implementacao-desempenho-por-empresa.md`. Conflict-detection do import: 3 BLOCKERS, todos resolvidos por decisão do usuário em 2026-07-27 — (1) fonte de margem em pp reabre o hotfix a413e823 de 24/07, (2) régua atual reusada como pp sem recalibrar, (3) empresa sem baseline segue como decisão em aberto para o discuss-phase da Fase 120. Correções ao plano verificadas contra o código: `cacheKey` já está em `v12` (alvo real `v13`, com 4 suítes hardcoded), `adman:diff` em `v5` (→`v6` ok). **Fase 118 bloqueada até a Fase 116 fechar** (116-06/07/08) — adiciona um 4º call-site da regra de piso de NPS. Fases 117 e 121 são gates humanos explícitos (estabilidade de `prev`; delta antigo×novo). Esta milestone é a opção (A) da pendência `.planning/todos/pending/metrica-margem-bonus-fragil.md`, mas NÃO fica pronta antes do freeze de junho em 31/07 14h BRT — o freeze é decisão separada. `phases.clear` NÃO foi executado: Fases 1-116 preservadas, incluindo a 116 em execução, seguindo a convenção de anexar milestones deste roadmap.*
