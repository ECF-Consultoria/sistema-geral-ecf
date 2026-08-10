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

## Current Milestone: v22.0 Administrativo + Clicksign

**Goal:** Contrato assinado passa a ser a porta de entrada do operacional. Hoje a empresa vai direto do fechamento comercial para o setor operacional; passa a existir uma etapa administrativa no meio — gerar contrato, enviar pela Clicksign, aguardar a assinatura de todas as partes e só então liberar.

**Target features (fases a partir da 124):**
- ✅ **Refatoração sem quebrar o fluxo atual** *(Fase 124, concluída 2026-08-07)*: `PendenciasComerciaisService` e `EmpresaOperacionalRouter` extraídos; `ComercialController::store()` e `HubspotWebhookController::criarEmpresa()` religados ao roteador e o código duplicado removido (`criarImplementacaoPolo()` e `rotearImplementacao()` não existem mais). Kill switch `administrativo_bloqueio_ativo` instalado, lido num ponto só, provado dos dois lados e **desligado**. Refatoração pura comprovada por diff nominal vazio contra baseline congelada.
- ✅ **Estrutura de dados administrativa** *(Fase 125, concluída 2026-08-10)*: `contrato_assinaturas` e `contrato_assinatura_signatarios` criadas, com models, factories e 30 testes. Migrations provadas contra o **MariaDB de produção** (batches 110/111) — as três cicatrizes de schema do projeto (enum+SQLite, FK 1830, índice 1059) confirmadas evitadas no engine real. `contrato_assinatura_eventos` (com `payload_hash` único para idempotência de webhook) fica para a Fase 129.
- **Integração Clicksign (API v3, conceito de Envelope):** client HTTP, criação de envelope, documento, signatários, requisitos e notificação. Sandbox até homologação.
- **PDF do contrato** via DomPDF, com o texto jurídico isolado para troca futura.
- **Webhook Clicksign → liberação do operacional**, idempotente por evento.
- **Tela administrativa de contratos** + permissão `admin.contratos` + badge de status na listagem Comercial.
- **Rede de segurança (não estava no plano original, entrou como requisito de primeira classe):** kill switch sem deploy, alerta de contrato preso além de N dias, e liberação manual pelo admin com registro de quem liberou e por quê.

**Key context:**
- Plano canônico: `plano-administrativo-clicksign.md` (raiz) · Requirements: `.planning/REQUIREMENTS-v22.md`
- **Risco central:** a partir do deploy, nenhuma empresa nova chega ao operacional até o contrato ser assinado. Se a Clicksign falhar, ficar em sandbox ou o webhook não chegar, a operação para de receber empresas **sem alarme**. Por isso a rede de segurança é requisito, não melhoria futura, e a ordem de entrega precisa permitir ir a produção em modo "observa mas não bloqueia" antes de ligar o bloqueio.
- **Código recém-mexido:** o caminho a ser alterado (`criarEmpresa` → `persistirContratos` → `rotearImplementacao`) teve 3 bugs corrigidos em 05-06/08/2026, um deles zerando contratos de R$ 3.000 (`hs_mrr = 0` lido como valor). Ler as quick tasks `260805-eqk`, `260805-ohs` e `fast-260806` antes de planejar mudanças ali.
- **Idempotência:** `persistirContratos()` já tem guard por `hubspot_line_item_id` que faz replay ignorar contrato existente — precedente direto para a idempotência exigida no webhook Clicksign.
- **Verificado contra o código:** `ComercialController::servicoDisparaImplementacao()` existe e é reusado pelo webhook. *(Atualizado pela Fase 124: `store()` não cria mais `MlbEmpresa` inline e `rotearImplementacao()` deixou de existir — a mecânica e o guard anti-duplicidade vivem em `EmpresaOperacionalRouter`.)*
- **Terceiro caminho de entrada descoberto na Fase 124:** `MlbController::ativarEmpresaPendente()` (`/mlb/empresas`) cria `MlbEmpresa`+`MlbImplementacao` por cópia inline, **fora** do roteador e do kill switch. Coberto agora por **FLUXO-09**, mapeado para a Fase 133 — sem ele o bloqueio teria porta dos fundos.
- **Fora de escopo:** Conta Azul, regularidade financeira, fechamento mensal progressivo.
- Outro dev trabalha na mesma branch `main` em sessão paralela e deploya com frequência — árvore compartilhada.
- pt-BR em tudo

## Milestone anterior: v21.0 Desempenho por nota individual de empresa

**Goal:** Trocar a granularidade do motor de bonificação: em vez de agregar os componentes da carteira e só então aplicar a régua, calcular primeiro a nota de **cada empresa** (`nota_empresa = (NPS + faturamento + margem_pp) / 3`) e derivar a nota do profissional como a média das notas das empresas. Junto disso, a dimensão de margem migra de variação relativa para **pontos percentuais** — o que também resolve, como opção (A), a pendência de fragilidade estrutural da métrica de margem do bônus.

**Target features (7 fases, 117-123):**
- **F117. Margem em pontos percentuais + probe:** `AdmanMetricDiffService` expõe `prev_value` e `diff_pp` sem mexer no que os consumidores atuais leem; gate humano de estabilidade de `percentageMargin.prev` antes de amarrar pagamento nele.
- **F118. NPS por empresa:** serviço que agrupa a nota de NPS por `company_id` preservando os três ramos, a janela M+1, as dedupes e as invalidações. **Bloqueada até a Fase 116 fechar.**
- **F119. Score por empresa:** `CompanyScoreService` produz o fato por empresa com os três componentes pontuados; régua de faturamento por empresa, régua de margem sobre pp.
- **F120. Agregação + feature flag:** `nota_final` vira a média das notas das empresas atrás de `metrics.performance_company_first_score`, com `empresas_score` em shadow nos dois modos.
- **F121. Comparação antigo × novo:** comando de comparação por competência e medição da distribuição real de pp na carteira; gate humano antes de ligar a flag.
- **F122. Persistência por empresa:** `desempenho_company_score_snapshots` + comandos de fechamento + reconsolidação de competências fechadas.
- **F123. Telas e relatórios:** margem explicada sem jargão, lista de empresas com nota no detalhe do profissional, fallback para snapshots antigos.

**Key context:**
- Plano canônico: `plano-implementacao-desempenho-por-empresa.md` (raiz) · Requirements: `.planning/REQUIREMENTS-v21.md`
- **Decisão do usuário 2026-07-27 (margem):** pp derivado de `percentageMargin.value − prev`. Reabre deliberadamente o hotfix `a413e823` de 24/07 — pp não é expressável pelo `.diff` nativo. `prev` nunca foi validado: probe é gate da Fase 117.
- **Decisão do usuário 2026-07-27 (régua):** a régua atual (−5/−2/+1/+4) é reusada lida como pp, sem recalibrar. Usuário ciente da compressão na faixa 3-4 (leitura do Luiz `~−0,59 pp → nota 3`).
- **Decisão em aberto:** empresa sem baseline — resolver no discuss-phase da Fase 120; a proposta do plano (§3.4) contradiz `DESEMP-06` e a trava da Fase 109.
- **Prazo externo não coberto:** o freeze de junho/2026 (31/07 14h BRT) é decisão separada — esta milestone não fica pronta a tempo.
- Alto risco: muda o número que paga bônus. Nada vai a produção sem o gate de comparação da Fase 121.
- Fases 1-116 preservadas (convenção de anexar milestones deste roadmap); a Fase 116 segue em execução em paralelo.
- pt-BR em tudo

## Milestone anterior: v18.0 Períodos, competência de bônus e variação via Adman

**Goal:** Padronizar a regra de período em todas as telas críticas (carteira, desempenho, bônus) via um resolvedor único `MetricPeriodResolver`, separando leitura **operacional** (mês em curso vs. mesmo intervalo do mês anterior) de leitura **oficial de bônus** (mês fechado com competência: julho paga junho fechado, baseline de janela de mesmo tamanho). E passar a usar a **variação pronta da Adman** (`percentageMargin.diff` / `profitMargin.diff`) em vez de recalcular margem na mão, com fallback marcado quando a Adman não trouxer o diff. Continuação direta da v17.0: aquela acertou *quem* entra na conta; esta acerta *qual período* e *de onde vem a variação*.

**Target features (5 fases, 100-104 — buffer 97-99 p/ milestone NPS do dev paralelo):**
- **F100. `MetricPeriodResolver`:** service único que resolve janela atual + comparativa por modo (operacional / oficial-bônus / mês-fechado / custom), competência de bônus, datas inclusivas, timezone America/Sao_Paulo, label pra UI. Nenhum controller monta período na mão.
- **F101. `AdmanMetricDiffService`:** lê `percentageMargin.diff`/`profitMargin.diff`/revenue diff da Adman (hoje o `.diff` é descartado no `AdmanService`); prefere o diff oficial, fallback calculado marcado `diff_source`; persiste diff por período (snapshot/campos).
- **F102. Desempenho oficial por competência:** `DesempenhoScoreService` usa o resolver; ranking oficial de bônus em julho usa competência junho fechada; `var_margem_pct` usa `percentageMargin.diff` da Adman; operacional segue mostrando mês em curso marcado como parcial.
- **F103. Carteira por período:** `renderCarteiraProfissional`/`renderCarteirasConsolidadas` usam o resolver + filtro de período; financeiro usa diff da Adman quando disponível.
- **F104. UI de período:** toggle Em curso / Bônus atual / Mês fechado no ranking e na carteira; payload carrega `periodo` + `bonus.competence_month`/`payment_month`; filtro de período nas telas de resultado do núcleo.

**Key context:**
- Plano canônico (expandido): `plano-carteira-desempenho-multi-servico.md` (raiz) — seções "Regra de período/fechamento/pagamento", "Regra de variação de margem via Adman", Fases 0 e 2-5
- **Decisão do usuário 2026-07-17 (baseline):** mês fechado compara com os N dias imediatamente anteriores, janela de mesmo tamanho (junho = 01/06–30/06 vs **02/05–31/05**), NÃO mês calendário
- **Decisão do usuário 2026-07-17 (escopo):** núcleo — Resolver + Desempenho + Carteira + Adman diff. A propagação geral (Fase 7 do plano: dashboards, detalhe de empresa, metas, relatórios) fica para a milestone seguinte
- Alto risco: muda o número que a v17.0 acabou de deployar (v17 computa julho em curso; v18 faz o oficial usar junho fechado) — comunicar ao time
- Reusa fundação da v17.0: `CarteiraContextService`, elegibilidade financeira, os 6 metadados, `score_status`, cache versionado (bump necessário)
- Fatos diários guardam valor do dia; diffs de período ficam em snapshot/retorno com fonte declarada — não transformar diff de período em fato diário
- Deploy gate ativo — dev paralelo em milestone NPS (fases 94-96+); numeração de fases com buffer p/ evitar colisão no ROADMAP compartilhado
- pt-BR em tudo

## Milestone anterior: v17.0 Carteira e Desempenho multi-serviço

**Goal:** Corrigir a arquitetura de Carteira e Desempenho para suportar empresas com Performance/Mercado Livre e Shopee ao mesmo tempo — sem duplicar empresa e sem misturar métricas financeiras de ML em carteiras/vínculos Shopee. **Empresa compartilhada ≠ métrica compartilhada.** O score de desempenho permanece **único por profissional**; a separação existe no universo da carteira, nas fontes de dados e na elegibilidade das métricas, nunca na nota final.

**Target features (6 fases, 88-93):**
- **F88. Camada de contexto:** novo `CarteiraContextService` — retorna vínculos de serviço do profissional (user×company×servico×setor×role), resolve fonte financeira e elegibilidade (`financial_metrics_eligible`), deduplica empresa única vs. vínculos de serviço
- **F89. Carteira individual:** `renderCarteiraProfissional` por contexto de serviço; Shopee aparece com "sem fonte financeira"; soma financeiro só de vínculos elegíveis; absorve o bug de exibição de `/companies` (responsável certo por serviço)
- **F90. Carteiras consolidadas:** `renderCarteirasConsolidadas` sem puxar faturamento ML pra quem só cuida da empresa em Shopee; separa empresas únicas de vínculos de serviço; sem dupla contagem
- **F91. Desempenho único com elegibilidade:** `DesempenhoScoreService::computeUniverso` por vínculos; financeiro (var. faturamento/margem) só de vínculo elegível; NPS já vem de `nps_score_assignments`; status da nota `official`/`partial`/`blocked`
- **F92. UI de Desempenho:** ranking único + metadados (empresas únicas, vínculos, vínculos sem fonte, status parcial); filtros de auditoria por setor sem criar segundo score
- **F93. Menu:** grupo transversal "Gestão ECF" (Carteira/Desempenho/Metas) fora do grupo Mercado Livre

**Key context:**
- Plano canônico do usuário: `plano-carteira-desempenho-multi-servico.md` (raiz do projeto) — fonte de verdade do escopo e critérios de aceite
- Bug medido em prod: `User::companies()` não distingue serviço → Felipe avaliado sobre 29 empresas gerenciando 4; Matheus sobre 15 gerenciando 0 (carteira de performance inteiramente emprestada do ML)
- **Score ÚNICO** por profissional — proibido criar score separado ML/Shopee/Geral
- Financeiro conta só vínculo com fonte (`financial_metrics_eligible=true`); Shopee sem fonte até ter API própria
- **Profissional só-Shopee → nota `blocked`** até a diretoria aprovar régua de bônus sem financeiro (decisão 2026-07-16)
- `User::companies()` PRESERVADO como legado/fallback; `CarteiraContextService` é a fonte oficial multi-serviço
- Reutiliza fundação da v16.0: `company_users.servico_id`, `servicos.setor`, `nps_score_assignments`
- Deploy gate ativo — outro dev em paralelo (fases 82-87, módulo MLB/Anúncios)
- pt-BR em tudo

## Paused: v14.0 Confiabilidade + Polish *(paused 2026-07-07)*

**Status:** 3/8 fases entregues, pausada para atacar v15.0 NPS Templates.

**Delivered:**
- ✓ Phase 60 — Base multi-fonte (backend ML+Adman unificado): 46 tests verdes, DATA-04/DATA-06 fechados
- ✓ Phase 61 — Dashboards multi-fonte + indicador de origem: 31 tests verdes, DATA-05/DASH-04/DASH-05/DASH-06 fechados
- ✓ Phase 62 — Metas apresentação clara + edição rápida: 19 tests verdes, META-01/META-04 fechados

**Deferred (resumir depois):**
- Phase 63 — Metas: onboarding + legacy + activity_log (**planejada com Task 0 + 4 plans, não executada**; META-02/META-03/META-05)
- Phase 64 — Parâmetro uso do sistema (captura) — sem plans (PERF-01/PERF-03)
- Phase 65 — Dashboard uso do sistema — sem plans (PERF-02)
- Phase 66 — Bug fixes UX (OAuth ML, filtro companies, sidebar) — sem plans (UX-01/UX-02/UX-03)
- Phase 67 — Sugadores refinements — sem plans (SUGA-01/02/03/04)

**Como resumir:** ROADMAP.md original preservado em `.planning/milestones/v14.0-ROADMAP-wip.md`. `.planning/phases/60-63/*` intactas com todos SUMMARY + plans commitados. Rodar `/gsd:new-milestone v14.1` ou reabrir v14.0 quando pronto.

**Commits acumulados v14.0:** 60+ desde main (não deployado — deploy gate ativo).

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

### Active (v15.0 — NPS Templates)

<!-- Escopo do milestone atual. REQ-IDs definidos em `.planning/REQUIREMENTS.md`. -->

Categorias-alvo: **NPS-A** (schema + modelos + seed retroativo), **NPS-B** (backend regras), **NPS-C** (UI configuração), **NPS-D** (formulário público), **NPS-E** (dashboards + pendências), **NPS-F** (limpeza legado + testes).

### Paused (v14.0 — Confiabilidade + Polish)

<!-- Pausada em 2026-07-07 para atacar v15.0 NPS Templates. 3/8 fases entregues. Resumir depois via /gsd:new-milestone v14.1 ou reabertura. Ver `.planning/milestones/v14.0-ROADMAP-wip.md`. -->

Categorias-alvo entregues: **DATA-04/05/06**, **DASH-04/05/06**, **META-01/04** — via phases 60/61/62.
Categorias pendentes: **META-02/03/05** (Phase 63 planejada), **PERF-01/02/03** (Phase 64/65), **UX-01/02/03** (Phase 66), **SUGA-01/02/03/04** (Phase 67).

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
*Last updated: 2026-07-07 — **Milestone v15.0 (NPS Templates) aberta.** v14.0 pausada mid-flight com 3/8 entregues (Phase 60/61/62 verified; 63 planejada não executada; 64-67 sem plans). Escopo v15.0: reescrita completa do módulo NPS baseado em modelos configuráveis de formulário, com pesos ajustáveis por opção, cálculo por dimensão, dedup mensal, dashboards de pendência e UX limpa. Zero uso de Promotor/Neutro/Detrator. Deploy gate ativo.*
