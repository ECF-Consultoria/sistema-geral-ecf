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
- [ ] **Phase 74: Módulo Desempenho — simplificação para 4 parâmetros + bonificação** — reescrita da lógica de score da equipe Performance conforme spec da diretoria/gestão (2026-07-09). Substitui `PortfolioScoreService` (6 métricas ponderadas) por engine simplificada de 4 parâmetros: NPS médio, % variação de faturamento, % variação de margem de contribuição, absenteísmo (standby). Réguas 1-5 pontos por métrica, nota final média, faixas de bônus configuráveis via UI admin. Reescreve `Performance/{Dashboard,Index,Show}.jsx`, atualiza `SnapshotDesempenhoScores` cron, adiciona doc no `/manual` sincronizado com config

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
- [ ] 74-06-PLAN.md — Performance/{Dashboard,Index,Show}.jsx reescritas — 4 cards de parâmetros + faixa de bônus + toggle mês fechado/parcial/diário + filtro sem_carteira no ranking + badge "Em breve" no Absenteísmo
- [ ] 74-07-PLAN.md — Desempenho/Configuracao.jsx (UI React admin — CRUD faixas inline + validação inline + toast + toggle ativo/inativo)
- [ ] 74-08-PLAN.md — Manual/Artigos/DesempenhoBonificacao.jsx + artigos.js entry + ManualController::show evoluído (passa bonus_faixas prop) + Manual/Show.jsx spread artigoProps
- [ ] 74-09-PLAN.md — Suite tests/Feature/Phase74/DesempenhoScoreServiceTest (11+ testes: fixture Carlos âncora, dual-path NPS, empresa nova, provider ML-first/Adman-fallback, promoção 2 meses, sem_carteira)
- [ ] 74-10-PLAN.md — Suites tests/Feature/Phase74/DesempenhoConfigControllerTest (11 testes: 403 não-admin, CRUD, sobreposição, toggle) + ConsolidarMesDesempenhoCommandTest (7 testes: --mes flag, idempotência, sem_carteira pula, ranking_pos, diário preservado com mes_referencia=null) + validação regressão zero
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
| 74. Módulo Desempenho (4 parâmetros + bonificação) | 5/10 | In Progress|  |

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

---
*Roadmap criado: 2026-07-07 — Milestone v15.0 (NPS Templates) — 6 phases (68-73) cobrindo 29 REQs; granularity=standard*
*Roadmap atualizado: 2026-07-09 — Phase 74 (Módulo Desempenho v2) adicionada como tail da milestone, cobrindo 14 REQs DESEMP em 10 plans / 5 waves*
