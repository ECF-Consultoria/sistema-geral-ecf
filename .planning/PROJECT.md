# ECF Admin — Setor Dev

## What This Is

Sistema de administração interna do ECF Admin com módulos principais: **Setor Dev**
(diagnóstico de sync Adman, fila de jobs, logs e configurações), **Módulo Administrativo**
(fechamento mensal — faturamento por empresa, faixa de investimento e total a cobrar) e
**Sistema de Notificações** (sino no header, criação manual com targeting e disparos
automáticos a partir de eventos de metas). Acesso administrativo é exclusivo via role
`admin`; o sino de notificações é exposto a todo usuário autenticado.

## Core Value

Dar ao admin visibilidade total sobre operações internas: o sync Adman, o fechamento
financeiro de cada empresa e a comunicação interna (notificações de metas e mensagens
manuais) — sem precisar de acesso direto ao servidor.

## Current Milestone: v14.0 Confiabilidade + Polish

**Goal:** Consolidar acertividade dos dados (fontes ML + Adman unificadas em uma mesma superfície), polir usabilidade de Metas + parâmetros de desempenho, corrigir bugs UX relatados por operadores em teste e refinar detecção de Sugadores — antes de escalar novas features sobre uma base ainda instável.

**Target features:**
- **A. Confiabilidade + Multi-Fonte:** Dashboard Mercado Livre + carteira individual + dashboards de analista/estrategista unificando empresas fonte-ML e fonte-Adman
- **B. Metas (UX + Onboarding):** melhorar usabilidade da tela e visualização de progresso; incluir meta na criação/onboarding da empresa (resolve seed `270629`)
- **C. Parâmetros de Desempenho:** adicionar dimensão "forma de uso do sistema" (ex: qtd de análises de sugadores rodadas)
- **D. Bug Fixes UX:** OAuth ML erro cosmético cliente-side; filtro "conectada ao Mercado Livre" em /companies; badge ML na carteira; sidebar collapse-arrow vs botão voltar
- **E. Sugadores refinements (escopo raso):** falsos-negativos (By Mobile, KAPRAKASA); "dados defasados" ao copiar MLBs (Desk Design); página de config mais simples

**Key context:**
- Herda 66 items "deferred" do fechamento da v13.0 — verificar overlap ao definir REQs, sem incluir automaticamente
- Deploy gate ativo: `deploy.sh` exige confirmação caso-a-caso (outro dev em paralelo)
- Prioridade dura: **acertividade > velocidade** (não é polish superficial — é confiabilidade)
- Sugadores (bloco E) tem escopo propositalmente raso — usuário vai aprofundar em milestone dedicada depois

## Recently Shipped: ✅ v13.0 Reorganização Multi-Marketplace *(2026-07-06)*

**Delivered:** 4 phases (56, 57, 58, 59), 8 plans, 100% em produção. Arquivo completo em `.planning/milestones/v13.0-ROADMAP.md`.

**Shipped features:**
- ✓ Menu lateral reorganizado (pasta Mercado Livre aberta; Publicação transversal; ECF Dashboard no topo; Shopee/Amazon apontam pras rotas dedicadas)
- ✓ Modelo N:N `company_marketplaces` formalizado + 126 rows backfilled + accessors legacy preservam contrato
- ✓ Dashboard ECF agregado (`Dashboard/EcfShell.jsx` aspirational + hero card + prévia KPIs) + dashboards por marketplace (`/dashboard/{ecf,mercadolivre,shopee,amazon}`)
- ✓ Filtro `?marketplace=` validado por whitelist + Publicação confirmed transversal (grep + suite dinâmica)
- ✓ Desacoplamento cirúrgico: 2 fixes MED em Company/Admin (accessor `cust_id` unificado — corrigiu naming + ordem invertida em bug real)

**Deferred to v14+:** agregação real cross-marketplace no ECF Dashboard, migração completa pra pivot N:N em queries transversais, refactor de MlbController separando transversal vs. ML-específico, integração real de Shopee/Amazon.

**Milestones paralelas ainda ativas:**
- v11.0 (Migração Sugadores Adman→ML) — Phase 44 BLOCKED em checkpoint humano DevCenter ML
- v12.0 (Carteira + Desempenho + Gamificação) — Phase 47 congelada, Phase 53 STANDBY

**Legado histórico:**
- v3.0 Notificações entregue (sino, targeting, disparos automáticos)
- v4.0 Fluxo Comercial + v4.1/4.2 Sugadores + v5.0 Inteligência ML + v6.0 Dashboard + v7.0 Sugadores Foco + v8.0 ECF Drive + v9.0 Notificações 2.0 + v9.5 Sugadores Robustos + v11.0 Migração ML + v12.0 Carteira/Desempenho — 40+ phases concluídas ao longo do projeto

## Requirements

### Validated

<!-- Já entregue e funcionando no sistema atual -->

- ✓ Rota `/dev/desenvolvimento` acessível por admins — existente
- ✓ Página `Dev/Desenvolvimento.jsx` com design system ECF (dark theme, ecf-* tokens, DevCard) — existente
- ✓ `AdmanService` com integração à API Adman — existente
- ✓ Jobs assíncronos via Laravel Queue (`AnalyzeCompanySugadoresJob`) — existente
- ✓ Activity log via `spatie/laravel-activitylog` — existente
- ✓ Middleware `role:admin` para controle de acesso — existente
- ✓ Comandos de diagnóstico Artisan (`DiagnosticSyncVendas`, `InspecionarAdman`) — existente

**v13.0 — Reorganização Multi-Marketplace (shipped 2026-07-06):**
- ✓ **DATA-01/02/03**: Modelo N:N `company_marketplaces` + helpers + accessors legacy — Phase 57
- ✓ **DASH-01**: `/dashboard/ecf` shell "em construção" com prévia agregada — Phase 58 (agregação real deferida v14+)
- ✓ **DASH-02**: `/dashboard/mercadolivre` mantém dashboard atual com filter=meli — Phase 58
- ✓ **DASH-03**: `/dashboard/shopee` + `/dashboard/amazon` renderizam shells dedicados — Phase 58
- ✓ **CROSS-01**: AUDIT.md documenta os 3 hotspots (Comercial/Company/Admin) — Phase 59
- ✓ **CROSS-02**: Publicação confirmed transversal via grep + suite dinâmica — Phase 59
- ✓ **CROSS-03**: Zero regressão (delta = 0 vs baseline 955 tests) — Phase 59

### Active (v14.0 — Confiabilidade + Polish)

<!-- Escopo do milestone atual. REQ-IDs definidos em `.planning/REQUIREMENTS.md`. -->

Categorias-alvo: **DATA** (unificação fontes ML/Adman), **DASH** (dashboards multi-fonte), **META** (usabilidade + onboarding + seed 270629), **PERF** (parâmetro "uso do sistema"), **UX** (bug fixes: OAuth, filtro, badge, sidebar), **SUGA** (refinements rasos).

### Legado (v3.0 — Sistema de Notificações, shipped 2026-05)

<!-- Categorias históricas mantidas como referência. -->

Categorias-alvo: SINO (UI header), HIST (página de histórico), ENVIO (criação manual com targeting),
AUTO-METAS (disparos automáticos), PERM (permissão `notificacoes.criar`), POLL (atualização real-time + cleanup).

### Entregue (v2.0 — Administrativo Fechamento)

<!-- Funcional em produção desde 2026-05-19; encerramento formal via /gsd:complete-milestone pendente. -->

- ✓ **ADM-01**: Admin pode ver lista de empresas com tipo de serviço e datas de contrato
- ✓ **ADM-02**: Admin pode ver o faturamento mensal de cada empresa via Adman API
- ✓ **ADM-03**: Admin pode ver a faixa de investimento de cada empresa baseada na tabela de progressão
- ✓ **ADM-04**: Admin pode ver barra de progressão com posição na faixa atual e distância para a próxima
- ✓ **ADM-05**: Admin pode ver o total consolidado a cobrar de todas as empresas no mês corrente
- ✓ **ADM-06**: Cada empresa tem campo de serviço adicional reservado (visível, sem lógica de valor)

### Pausado (v1.0 — Setor Dev, retomar em v4.0)

<!-- Empurrado de v3.0 → v4.0 quando v3.0 foi reorientado para Notificações. -->

- [ ] **DEV-05**: Admin pode ver status da fila de jobs (pendentes, em execução, falhados, com detalhes do erro)
- [ ] **DEV-06**: Admin pode ver logs recentes do sistema (errors e warnings) sem acessar o servidor
- [ ] **DEV-07**: Admin pode ver informações do ambiente (versão PHP, driver de fila, driver de cache, uptime)
- [ ] **DEV-08**: Admin pode visualizar e editar configurações/flags do sistema

### Out of Scope

- Acesso por roles não-admin (consultor, mentor, publication roles) — segurança
- Deploy ou CI/CD via painel — complexidade fora do escopo
- Edição de código pelo navegador — escopo de IDE, não de painel
- Monitoramento de infraestrutura externa (servidor, banco) — além do processo Laravel

## Context

O ECF Admin é um sistema interno Laravel 12 + Inertia.js + React usado pela ECF Consultoria
para gestão de clientes de marketing digital (agências e assessorias). Os módulos principais
são Sugadores (análise de contas Adman), MLB (publicações Mercado Livre) e dashboards.

O sync Adman é o processo mais crítico e mais opaco: o `AdmanService` faz chamadas HTTP
à API `ad-man.io/v1`, processa os dados por empresa, e grava no banco. Hoje, quando algo
falha, o dev precisa acessar o servidor diretamente ou rodar comandos Artisan (`InspecionarAdman`,
`DiagnosticSyncVendas`) para entender o que aconteceu.

Já existem comandos de diagnóstico úteis que podem ser expostos via painel:
- `app/Console/Commands/DiagnosticSyncVendas.php`
- `app/Console/Commands/InspecionarAdman.php`
- `app/Console/Commands/SyncThumbnailsPublicacoes.php`

A tabela `failed_jobs` do Laravel já registra jobs falhados com payload e exceção completa.
O `spatie/laravel-activitylog` já registra eventos de todos os modelos principais.

## Constraints

- **Stack**: Laravel 12 + Inertia.js + React — nenhuma mudança de stack
- **Design**: Tailwind com tokens `ecf-*`, dark theme, componente `DevCard` e `cn()` já existentes — manter consistência
- **Acesso**: Exclusivo para role `admin` via middleware `EnsureUserHasRole` já configurado
- **Comentários**: Em pt-BR conforme convenção do projeto
- **Deploy**: Não executar deploy sem autorização explícita do usuário

## Key Decisions

| Decisão | Racional | Resultado |
|---------|----------|-----------|
| Evoluir `/dev/desenvolvimento` existente | Rota e layout já funcionam, evita duplicidade | ✓ v1.0 |
| Log de sync armazenado no banco (nova tabela) | Permite histórico persistente sem depender de arquivos de log | ✓ v1.0 |
| Jobs disparados via API Inertia (não WebSockets) | Suficiente para o volume atual, sem complexidade adicional | ✓ v1.0 |
| Acesso apenas role admin para Setor Dev e Administrativo | Dados sensíveis (payloads API, configurações) não devem vazar para consultores | ✓ v1.0/v2.0 |
| Notificações usam tabela nativa `notifications` do Laravel | Convenção do framework, polimórfica via `notifiable_id/type`, payload JSON flexível | — v3.0 |
| Atualização do contador via polling ~60s + revalidação Inertia | Atende UX sem exigir WebSockets/Reverb; sem nova infra de broadcast | — v3.0 |
| Nova permission_key `notificacoes.criar` (admin always, líder via AUTO_LIDERANCA) | Granular e atribuível via UI de setores existente; abrange Admin + Líderes + Administrativo com 1 chave | — v3.0 |
| Cleanup de notificações lidas > 30d via scheduled command | Mantém tabela enxuta sem perder janela útil de auditoria | — v3.0 |
| Targeting (individual/setor/líderes/todos) resolvido no dispatch | Expande para `user_ids` no momento do envio; evita lógica de "audiência" no read path | — v3.0 |

## Evolution

Este documento evolui a cada transição de fase e marco de milestone.

**Após cada transição de fase** (via `/gsd-transition`):
1. Requirements invalidados? → Mover para Out of Scope com motivo
2. Requirements validados? → Mover para Validated com referência da fase
3. Novos requirements emergiram? → Adicionar em Active
4. Decisões a registrar? → Adicionar em Key Decisions
5. "What This Is" ainda preciso? → Atualizar se divergiu

**Após cada milestone** (via `/gsd:complete-milestone`):
1. Revisão completa de todas as seções
2. Verificar Core Value — ainda é a prioridade certa?
3. Auditar Out of Scope — motivos ainda válidos?
4. Atualizar Context com estado atual

---
*Last updated: 2026-07-07 — **Milestone v14.0 (Confiabilidade + Polish) aberta.** Escopo: unificação fontes ML/Adman em dashboards e carteiras, Metas UX + onboarding (resolve seed 270629), parâmetro "uso do sistema", bug fixes UX (OAuth, filtro ML, badge, sidebar), refinements rasos de Sugadores. Prioridade acertividade > velocidade. Deploy gate ativo.*
