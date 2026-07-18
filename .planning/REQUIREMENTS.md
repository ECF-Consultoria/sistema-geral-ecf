# Requirements: ECF Admin — Milestone v17.0

**Defined:** 2026-07-16
**Milestone:** v17.0 — Carteira e Desempenho multi-serviço
**Core Value:** Corrigir a dupla contagem de faturamento e a atribuição cruzada quando uma empresa tem responsáveis diferentes por setor (Performance/ML e Shopee). Empresa compartilhada ≠ métrica compartilhada. Score único por profissional; a separação existe na carteira, nas fontes de dados e na elegibilidade das métricas — nunca na nota final.
**Plano canônico:** `plano-carteira-desempenho-multi-servico.md` (raiz do projeto).

## v17.0 Requirements

Cada requirement mapeia para exatamente uma phase no ROADMAP.md.

### CTX — Camada de contexto de carteira (Fase 88)

- [x] **CTX-01**: `CarteiraContextService::forUser($user, $filters)` retorna os vínculos de serviço ativos do profissional, cada um com `company_id`, `company_name`, `servico_id`, `servico_nome`, `setor`, `role`, `role_label`
- [x] **CTX-02**: Cada vínculo marca `has_financial_source` / `financial_source` / `financial_metrics_eligible` — `true`/`adman` para setor `performance`, `false`/`null` para `shopee` (até existir fonte Shopee)
- [x] **CTX-03**: O serviço resolve elegibilidade financeira por `servicos.setor`, cobrindo TODOS os serviços de performance (Gestão id 6 E Mentoria id 7), sem hardcode de `servico_id`
- [x] **CTX-04**: O serviço deduplica corretamente — distingue "empresas únicas" de "vínculos de serviço"; a mesma empresa com dois vínculos do mesmo profissional não é contada duas vezes como empresa
- [x] **CTX-05**: Compatibilidade legado — `servico_id` preenchido tem prioridade; `servico_id null` com contrato performance ativo é tratado como Performance legado; `servico_id null` com contrato Shopee NÃO assume responsável Shopee automaticamente

### CART — Carteira individual e consolidada (Fases 89, 90)

- [x] **CART-01**: A carteira individual (`renderCarteiraProfissional`) usa `CarteiraContextService`; empresa com Performance + Shopee aparece UMA vez como empresa, podendo exibir dois vínculos de serviço
- [x] **CART-02**: Vínculo Shopee aparece na carteira com estado explícito "sem fonte financeira", sem faturamento/margem de ML
- [x] **CART-03**: Soma financeira (`SUM(revenue)`, `SUM(contribution_margin)`, `ad_spend`, `tacos`) considera apenas vínculos com `financial_metrics_eligible = true`
- [x] **CART-04**: Profissional responsável APENAS por Shopee de uma empresa que também tem ML NÃO recebe faturamento/margem de ML como se fosse dele
- [x] **CART-05**: Profissional responsável por ML E Shopee da mesma empresa NÃO duplica faturamento no filtro "Todos" (métrica ML contada uma vez)
- [x] **CART-06**: A carteira consolidada (`renderCarteirasConsolidadas`, visão admin) mostra cards por profissional com contagem correta, separando empresas únicas de vínculos de serviço, sem puxar faturamento ML pra quem só cuida em Shopee
- [x] **CART-07**: A UI de carteira tem filtro de contexto (Todos / Performance-ML / Shopee), badges de serviço por linha e contadores (empresas únicas vs. vínculos de serviço)
- [x] **CART-08**: A tela `/companies` (painel Performance) exibe o responsável do SERVIÇO DE PERFORMANCE na coluna Analista/Estrategista — nunca o responsável Shopee; a pendência "sem responsável" acusa falta do responsável de performance especificamente

### DESEMP — Desempenho único com elegibilidade (Fases 91, 92)

- [x] **DESEMP-01**: `DesempenhoScoreService::computeUniverso` deriva o universo dos vínculos de serviço ativos do profissional (não de `company_id` consolidado), retornando empresas únicas e empresas elegíveis para financeiro
- [x] **DESEMP-02**: O score permanece ÚNICO por profissional — nenhum score separado por marketplace (sem "Score ML" / "Score Shopee" / "Score Geral")
- [x] **DESEMP-03**: `computeNpsMedio` continua lendo `nps_score_assignments` — NPS Shopee E NPS Performance entram no mesmo NPS médio do profissional (comportamento da v16.0 preservado)
- [x] **DESEMP-04**: `computeVarFaturamento` e `computeVarMargem` usam apenas vínculos com `financial_metrics_eligible = true`; profissional só-Shopee não recebe variação financeira baseada em ML
- [x] **DESEMP-05**: O service retorna metadados: `empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira`, `score_status`, `componentes_disponiveis`
- [x] **DESEMP-06**: A nota expõe status `official` / `partial` / `blocked`; profissional apenas-Shopee sem fonte financeira recebe `blocked` (decisão do usuário 2026-07-16, até a diretoria aprovar régua de bônus sem financeiro)
- [x] **DESEMP-07**: A regra `sem_carteira` remove do ranking apenas o profissional SEM nenhum vínculo ativo — quem tem vínculo Shopee (ainda que sem financeiro) permanece no ranking
- [x] **DESEMP-08**: A UI de Desempenho mantém ranking único e exibe os metadados por profissional (empresas únicas, vínculos, vínculos sem fonte, status da nota); filtros de auditoria por setor não criam segundo score oficial

### MENU — Reorganização de navegação (Fase 93)

- [x] **MENU-01**: Carteira e Desempenho (e Metas, se fizer sentido) saem do grupo "Mercado Livre" para um grupo transversal "Gestão ECF"; o grupo Mercado Livre mantém apenas telas realmente ML

## Critérios de aceite globais (do plano canônico)

- Nenhuma empresa é duplicada em nenhuma tela
- Nenhuma atribuição Shopee altera responsável Performance, e vice-versa
- `company_users.servico_id` é respeitado em todos os fluxos novos; `servico_id null` segue como legado
- `User::companies()` NÃO é removido — permanece como fallback legado documentado

## Out of Scope (v17.0)

- **Fonte financeira de Shopee** — não há API/importação Shopee ainda; vínculos Shopee ficam `financial_metrics_eligible=false`. Quando existir, entra sem mudar a arquitetura.
- **Régua de bônus para Shopee sem financeiro** — decisão de diretoria; até lá, nota `blocked`. Esta milestone entrega o MECANISMO dos três status, não a política.
- **Nova tabela `company_services`** — o plano decide explicitamente reusar `contratos_servico` + `company_users.servico_id`; não criar tabela nova agora.
- **Score separado por marketplace** — proibido por design.

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| CTX-01 | Fase 88 | Complete |
| CTX-02 | Fase 88 | Complete |
| CTX-03 | Fase 88 | Complete |
| CTX-04 | Fase 88 | Complete |
| CTX-05 | Fase 88 | Complete |
| CART-01 | Fase 89 | Complete |
| CART-02 | Fase 89 | Complete |
| CART-03 | Fase 89 | Complete |
| CART-04 | Fase 89 | Complete |
| CART-05 | Fase 89 | Complete |
| CART-08 | Fase 89 | Complete |
| CART-06 | Fase 90 | Complete |
| CART-07 | Fase 90 | Complete |
| DESEMP-01 | Fase 91 | Complete |
| DESEMP-02 | Fase 91 | Complete |
| DESEMP-03 | Fase 91 | Complete |
| DESEMP-04 | Fase 91 | Complete |
| DESEMP-05 | Fase 91 | Complete |
| DESEMP-06 | Fase 91 | Complete |
| DESEMP-07 | Fase 91 | Complete |
| DESEMP-08 | Fase 92 | Complete |
| MENU-01 | Fase 93 | Complete |
