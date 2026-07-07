# Roadmap: ECF Admin — Milestone v14.0 Confiabilidade + Polish

## Overview

Milestone de consolidação: v13.0 entregou a arquitetura multi-marketplace (pivot `company_marketplaces` N:N + shells de dashboard por marketplace), mas deixou a superfície ainda instável — dados ML e Adman coexistem sem regra unificada, dashboards e carteiras quebram silenciosamente quando a fonte diverge, Metas carecem de usabilidade + onboarding, o parâmetro "forma de uso do sistema" é conceito novo, e há bugs UX pontuais + falso-negativos de Sugadores relatados por operadores em teste. A v14.0 fecha essa dívida em 8 phases seguindo a ordem lógica **base → dashboards → metas → uso → polish → sugadores** para não construir novas features sobre alicerce oscilante.

Prioridade dura: **acertividade > velocidade**. Nada aqui é polish superficial — é confiabilidade de dados exibidos ao operador.

Histórico completo dos milestones anteriores (v1.0–v13.0): `.planning/MILESTONES.md` + arquivos em `.planning/milestones/`.

## Phases

**Phase Numbering:**
- Continuidade monotônica após v13.0 (última phase: 59). v14.0 começa em Phase 60.
- Integer phases (60-67): trabalho planejado da milestone
- Decimal phases (60.1, 61.2...): reservadas para inserções urgentes durante execução

- [ ] **Phase 60: Base multi-fonte (backend ML+Adman unificado)** — Camada de dados única sobre `company_marketplaces` que lê Adman e ML sem quebrar quando uma fonte é ausente, com regra de precedência documentada em ADR
- [ ] **Phase 61: Dashboards multi-fonte + indicador de origem** — Dashboard ML, dashboards Analista/Estrategista e carteira individual passam a respeitar a fonte de cada empresa; badge visual de origem (ML/Adman/Agregado) em toda métrica
- [ ] **Phase 62: Metas — apresentação clara + edição rápida** — Tela de Metas mostra progresso mensal com clareza (chart + % + valor absoluto), edição inline/bulk e histórico visível
- [ ] **Phase 63: Metas — onboarding obrigatório + tratamento de legacy + activity log** — Fluxo de cadastro de empresa exige meta inicial mensal (resolve seed 270629); empresas legadas ganham flag visível "Meta não definida"; alterações registradas em `activity_log`
- [ ] **Phase 64: Parâmetro de uso — captura event-based** — Camada backend event-based que grava "análises de sugadores rodadas" e permite adicionar novos eventos de uso sem refactor
- [ ] **Phase 65: Dashboard de desempenho — dimensão "uso do sistema"** — Painel/coluna dedicada a métricas de uso no dashboard de desempenho, convivendo com KPIs comerciais existentes
- [ ] **Phase 66: Bug fixes UX (OAuth ML, filtro companies, sidebar)** — Correções pontuais reportadas por operadores em teste: erro cosmético do OAuth ML, filtro "conectada ao ML" em `/companies`, hierarquia visual sidebar recolher-vs-voltar
- [ ] **Phase 67: Sugadores refinements — investigação + fixes + UX config** — Root-cause de falsos-negativos (By Mobile, KAPRAKASA) e "dados defasados" (Desk Design); simplificação da página de configuração

## Phase Details

### Phase 60: Base multi-fonte (backend ML+Adman unificado)
**Goal**: Estabelecer camada de leitura unificada sobre `company_marketplaces` que suporta empresa fonte-ML, fonte-Adman ou ambas, com regra de precedência explícita e testável.
**Depends on**: Nothing (primeira phase da milestone v14.0; herda pivot `company_marketplaces` de Phase 57)
**Requirements**: DATA-04, DATA-06
**Success Criteria** (o que precisa ser VERDADE):
  1. Cálculo agregado de métricas de uma empresa lê `adman_metrics` (fonte Adman) E tabelas ML nativas (fonte ML) sem quebrar quando uma das fontes é ausente — cobertura para os 3 casos: só-Adman, só-ML, ambos
  2. Empresas conectadas a AMBAS as fontes têm métricas conciliadas sem duplicação, com regra de precedência documentada em ADR versionado dentro de `.planning/`
  3. Testes automatizados validam o comportamento nos 3 casos (só-Adman / só-ML / ambos) usando `RefreshDatabase` + fixtures mínimas
  4. Consumidores atuais de `adman_metrics` continuam funcionando sem regressão (delta = 0 na suite baseline)
**Plans**: TBD

### Phase 61: Dashboards multi-fonte + indicador de origem
**Goal**: Dashboards e carteiras passam a exibir métricas corretas independentemente da fonte da empresa, com indicador visual claro de origem em cada métrica exibida.
**Depends on**: Phase 60
**Requirements**: DATA-05, DASH-04, DASH-05, DASH-06
**Success Criteria** (o que precisa ser VERDADE):
  1. Dashboard Mercado Livre (`/dashboard/mercadolivre`) exibe KPI unificado que soma empresas fonte-ML + fonte-Adman num único número, sem duplicar nem ignorar
  2. Dashboards de Analista e Estrategista renderizam a carteira sem lançar erro quando uma empresa é ML-only (sem Adman) — empresas Adman-only continuam aparecendo normalmente
  3. Carteira individual exibe badge visual "ML" ao lado do nome de cada empresa conectada ao Mercado Livre
  4. Cada métrica renderizada na UI carrega indicador visual da fonte (badge ou tooltip: ML, Adman, ou Agregado)
**Plans**: TBD
**UI hint**: yes

### Phase 62: Metas — apresentação clara + edição rápida
**Goal**: Tela de Metas apresenta progresso mensal de forma clara e permite edição rápida sem sair da listagem, com histórico visível.
**Depends on**: Nothing (independente de Phase 60/61; pode executar em paralelo)
**Requirements**: META-01, META-04
**Success Criteria** (o que precisa ser VERDADE):
  1. Usuário abre uma empresa e visualiza meta atribuída + progresso mensal em apresentação única contendo chart + percentual + valor absoluto
  2. Gestor edita meta inline (ou em bulk quando aplicável) sem navegar para outra tela
  3. Histórico de alterações da meta é visível na própria tela de gestão (últimas N alterações + link para log completo)
**Plans**: TBD
**UI hint**: yes

### Phase 63: Metas — onboarding obrigatório + tratamento de legacy + activity log
**Goal**: Novas empresas entram no sistema com meta inicial já definida (resolve seed `270629`); empresas legadas sem meta ganham tratamento explícito na UI; toda alteração de meta é auditável.
**Depends on**: Nothing (independente; pode executar em paralelo com Phase 62, mas coordenar padrão UX com ela)
**Requirements**: META-02, META-03, META-05
**Success Criteria** (o que precisa ser VERDADE):
  1. Fluxo de criação/onboarding de empresa exige "meta inicial mensal" como campo obrigatório para empresas Mercado Livre (validação server-side + UI)
  2. Empresa legada sem meta exibe flag visível "Meta não definida" com CTA de definição — sem default arbitrário atrás dos panos
  3. Cada alteração de meta gera entrada em `activity_log` (via `spatie/laravel-activitylog`) com autor, valor anterior e valor novo — consultável na tela de histórico
**Plans**: TBD
**UI hint**: yes

### Phase 64: Parâmetro de uso — captura event-based
**Goal**: Estabelecer a camada backend que captura eventos de "forma de uso do sistema" de modo extensível — começando por "análises de sugadores rodadas" como exemplo canônico.
**Depends on**: Nothing (independente; consome infra Sugadores já entregue na v11.0)
**Requirements**: PERF-01, PERF-03
**Success Criteria** (o que precisa ser VERDADE):
  1. Sistema captura métrica "quantidade de análises de sugadores rodadas" por usuário/empresa/período — persistida e consultável
  2. Adicionar novo evento de uso no futuro (ex: qtd de acessos ao MLB, freq de edição de metas) exige apenas registrar o evento — sem refactor da camada de captura
  3. Testes automatizados cobrem gravação de eventos + agregação por usuário/empresa/período
**Plans**: TBD

### Phase 65: Dashboard de desempenho — dimensão "uso do sistema"
**Goal**: Painel de desempenho passa a exibir a dimensão "uso do sistema" (capturada na Phase 64) complementarmente aos KPIs comerciais existentes.
**Depends on**: Phase 64
**Requirements**: PERF-02
**Success Criteria** (o que precisa ser VERDADE):
  1. Dashboard de desempenho apresenta a dimensão "uso do sistema" em painel dedicado, coluna ou gráfico — decisão de layout tomada na fase de planning
  2. Métrica de uso convive com KPIs comerciais existentes sem regressão visual nem quebra do layout responsivo
  3. Usuário identifica facilmente empresas/consultores com baixo uso do sistema para orientar ação
**Plans**: TBD
**UI hint**: yes

### Phase 66: Bug fixes UX (OAuth ML, filtro companies, sidebar)
**Goal**: Corrigir bugs UX pontuais reportados por operadores em teste que hoje geram atrito ou desconfiança no sistema.
**Depends on**: Nothing (fixes independentes; podem executar em paralelo com qualquer outra phase)
**Requirements**: UX-01, UX-02, UX-03
**Success Criteria** (o que precisa ser VERDADE):
  1. Fluxo OAuth Mercado Livre NÃO exibe tela de erro para o cliente quando a conexão foi bem-sucedida no admin — root cause do erro cosmético identificado e corrigido (redirect, callback ou race condition)
  2. Página `/companies` tem filtro "Conectada ao Mercado Livre" com opções (Sim / Não / Qualquer) integrado aos filtros existentes, sem quebrá-los
  3. Sidebar reorganiza a hierarquia visual entre botão recolher (fixo na rolagem) e botão voltar (não-fixo) de modo que operadores em teste não confundem mais os dois
**Plans**: TBD
**UI hint**: yes

### Phase 67: Sugadores refinements — investigação + fixes + UX config
**Goal**: Endereçar casos concretos de falsos-negativos e dados defasados relatados em By Mobile, KAPRAKASA e Desk Design, e simplificar a página de configuração de Sugadores. Escopo propositalmente raso — rework estrutural fica para milestone dedicada.
**Depends on**: Nothing (opera sobre base já migrada em v11.0)
**Requirements**: SUGA-01, SUGA-02, SUGA-03, SUGA-04
**Success Criteria** (o que precisa ser VERDADE):
  1. Root cause dos falsos-negativos de By Mobile e KAPRAKASA está documentado (relatório de investigação em `.planning/phases/67-*/`) e a correção que se aplica está entregue e validada contra as empresas afetadas
  2. "Dados defasados" ao copiar MLBs em Desk Design está corrigido — refresh, cache ou coleta ajustados conforme root cause
  3. Página de configuração de Sugadores tem hierarquia visual mais clara: agrupamento por intenção, menos opções aparentes por padrão, sem perder capacidade avançada
  4. Zero regressão na suite Sugador acumulada (baseline capturado no início da phase)
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
- Phases 60 → 61 são sequenciais (61 depende de 60).
- Phases 62, 63, 64, 66, 67 são independentes e podem executar em paralelo, respeitando o deploy gate ativo (outro dev em paralelo — confirmar `deploy.sh` caso-a-caso).
- Phase 65 depende de Phase 64.

Dependência crítica: Phase 60 é a fundação de dados que destrava Phase 61 (dashboards). Phases META (62/63), PERF (64/65), UX (66) e SUGA (67) não bloqueiam nem são bloqueadas por 60/61.

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 60. Base multi-fonte (backend ML+Adman unificado) | 0/TBD | Not started | - |
| 61. Dashboards multi-fonte + indicador de origem | 0/TBD | Not started | - |
| 62. Metas — apresentação clara + edição rápida | 0/TBD | Not started | - |
| 63. Metas — onboarding + legacy + activity log | 0/TBD | Not started | - |
| 64. Parâmetro de uso — captura event-based | 0/TBD | Not started | - |
| 65. Dashboard desempenho — dimensão "uso do sistema" | 0/TBD | Not started | - |
| 66. Bug fixes UX (OAuth ML, filtro companies, sidebar) | 0/TBD | Not started | - |
| 67. Sugadores refinements — investigação + fixes + UX config | 0/TBD | Not started | - |

## Coverage

**v14.0 requirements mapped: 21/21 ✓**

| Requirement | Phase |
|-------------|-------|
| DATA-04 | Phase 60 |
| DATA-05 | Phase 61 |
| DATA-06 | Phase 60 |
| DASH-04 | Phase 61 |
| DASH-05 | Phase 61 |
| DASH-06 | Phase 61 |
| META-01 | Phase 62 |
| META-02 | Phase 63 |
| META-03 | Phase 63 |
| META-04 | Phase 62 |
| META-05 | Phase 63 |
| PERF-01 | Phase 64 |
| PERF-02 | Phase 65 |
| PERF-03 | Phase 64 |
| UX-01 | Phase 66 |
| UX-02 | Phase 66 |
| UX-03 | Phase 66 |
| SUGA-01 | Phase 67 |
| SUGA-02 | Phase 67 |
| SUGA-03 | Phase 67 |
| SUGA-04 | Phase 67 |

## Milestones (histórico)

Referência rápida — histórico completo em `.planning/MILESTONES.md` e arquivos individuais em `.planning/milestones/`.

- ✅ **v1.0** Setor Dev (Phases 1-4) — shipped ~2026-05
- ✅ **v2.0** Administrativo Fechamento (Phases 5-7) — shipped 2026-05-19
- ✅ **v3.0** Sistema de Notificações (Phases 8-12) — shipped 2026-05
- ✅ **v4.x** Fluxo Comercial + Sugadores (Phases 13-16)
- ✅ **v5.0** Inteligência ML (Phase 17+)
- ✅ **v6.0** Dashboard
- ✅ **v7.0** Sugadores Foco (Phases 19-21)
- ✅ **v8.0** ECF Drive (Phases 22-28) — shipped 2026-06-08
- ✅ **v9.0/v9.5** Notificações 2.0 + Sugadores Robustos (Phases 29+)
- 🚧 **v11.0** Migração Sugadores Adman→ML (Phases 38-43) — Phase 44 BLOCKED em checkpoint humano DevCenter ML
- 🚧 **v12.0** Carteira + Desempenho + Gamificação (Phases 45-55) — Phase 47 congelada, Phase 53 STANDBY
- ✅ **v13.0** Reorganização Multi-Marketplace (Phases 56-59) — shipped 2026-07-06
- 🚧 **v14.0** Confiabilidade + Polish (Phases 60-67) — em execução

---

*Roadmap criado: 2026-07-07 pelo gsd-roadmapper*
*Milestone: v14.0 — Confiabilidade + Polish*
*Coverage: 21/21 requirements mapped ✓*
