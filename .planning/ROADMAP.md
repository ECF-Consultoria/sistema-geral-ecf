# Roadmap: ECF Admin — Setor Dev + Administrativo

## Overview

Evolução do painel de administração interno do ECF Admin em dois milestones principais:
**v1.0 — Setor Dev** (diagnóstico Adman, fila de jobs, observabilidade, configurações) e
**v2.0 — Administrativo Fechamento** (faturamento por empresa, faixas de investimento, total
a cobrar). Cada fase entrega uma capacidade completa e verificável que o admin pode usar
imediatamente.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

### Milestone v1.0 — Setor Dev

- [x] **Phase 1: Diagnóstico Adman** - Admin pode inspecionar e controlar o sync Adman por empresa sem acessar o servidor
- [ ] **Phase 2: Monitoramento de Jobs** - Admin pode ver o estado da fila de jobs, incluindo falhas com detalhes completos *(pausado — retomar em v3.0)*
- [ ] **Phase 3: Observabilidade** - Admin pode ver logs de erro do sistema e informações do ambiente de execução *(pausado — retomar em v3.0)*
- [ ] **Phase 4: Configurações** - Admin pode visualizar e editar flags de configuração do sistema via painel *(pausado — retomar em v3.0)*

### Milestone v2.0 — Administrativo Fechamento

- [x] **Phase 5: Fundação Fechamento** - Banco de dados e campos Company que suportam tipo de serviço, datas de contrato e renomeação de sidebar
- [ ] **Phase 6: Backend Fechamento** - Aggregation query sobre adman_metrics, cálculo de faixa e props Inertia entregues ao frontend
- [ ] **Phase 7: UI Fechamento** - Reescrita de Financeiro.jsx como Fechamento com lista de empresas, barras de progresso e total consolidado

## Phase Details

### Phase 1: Diagnóstico Adman
**Goal**: Admin pode inspecionar e controlar o sync Adman de cada empresa sem precisar de acesso ao servidor ou Artisan
**Mode:** mvp
**Depends on**: Nothing (first phase)
**Requirements**: DEV-01, DEV-02, DEV-03, DEV-04
**Success Criteria** (what must be TRUE):
  1. Admin vê uma lista de empresas com a data/hora exata do último sync Adman de cada uma
  2. Admin clica em uma empresa e vê o payload bruto retornado pela API Adman (ou a mensagem de erro HTTP) daquele sync
  3. Admin vê o diff do sync: quantos registros foram criados, atualizados e ignorados
  4. Admin clica em "Disparar sync" de uma empresa específica e o sync é enfileirado e executado em background
**Plans**: 3 plans

Plans:
- [x] 01-01-PLAN.md — Fundação de dados e contrato de testes (migration + AdmanSyncLog + Company rels + 5 testes RED)
- [x] 01-02-PLAN.md — Backend end-to-end: DevController + AdmanService logging + rotas (testes GREEN)
- [x] 01-03-PLAN.md — UI inline SyncAdmanSection + npm run build + checkpoint humano

**UI hint**: yes

### Phase 2: Monitoramento de Jobs
**Goal**: Admin pode acompanhar o estado da fila de jobs em tempo real e investigar falhas sem abrir o servidor
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: DEV-05
**Success Criteria** (what must be TRUE):
  1. Admin vê contadores de jobs pendentes, em execução e falhados
  2. Admin expande um job falhado e lê o payload completo e a stack trace da exceção
  3. Admin pode retentar ou descartar um job falhado via botão na interface
**Plans**: TBD
**UI hint**: yes

### Phase 3: Observabilidade
**Goal**: Admin pode ler logs de erro e inspecionar o ambiente de execução diretamente no painel
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: DEV-06, DEV-07
**Success Criteria** (what must be TRUE):
  1. Admin vê as entradas mais recentes de erro e warning do log do Laravel sem acesso ao servidor
  2. Admin vê informações do ambiente: versão PHP, driver de fila, driver de cache e uptime do processo
  3. Admin pode filtrar os logs por nível (error / warning) e ver a mensagem completa de cada entrada
**Plans**: TBD
**UI hint**: yes

### Phase 4: Configurações
**Goal**: Admin pode visualizar e editar flags de configuração do sistema diretamente no painel, sem editar arquivos .env no servidor
**Mode:** mvp
**Depends on**: Phase 3
**Requirements**: DEV-08
**Success Criteria** (what must be TRUE):
  1. Admin vê uma lista de configurações/flags do sistema com seus valores atuais
  2. Admin edita o valor de uma flag e salva — a mudança persiste entre sessões
  3. Alterações de configuração ficam registradas no activity log com o usuário que as fez
**Plans**: TBD
**UI hint**: yes

### Phase 5: Fundação Fechamento
**Goal**: Banco de dados e model Company preparados para suportar tipo de serviço e datas de contrato; sidebar renomeado para "Fechamento"
**Mode:** mvp
**Depends on**: Phase 1 (infra admin existente)
**Requirements**: FCH-01, FCH-02, FCH-03, CFG-01
**Success Criteria** (what must be TRUE):
  1. A rota `/administrativo/financeiro` continua acessível e o label no sidebar exibe "Fechamento" (não "Financeiro")
  2. A migration `add_service_fields_to_companies` existe e foi executada; as colunas `service_type` (enum: polo/assessoria/incubadora), `contract_start`, `contract_end` e `additional_service` existem na tabela `companies`
  3. Todas as empresas cadastradas aparecem na tela Fechamento, incluindo aquelas sem integração Adman — estas exibem badge "Sem integração"
  4. Admin consegue salvar o tipo de serviço de uma empresa (POLO / Assessoria / Incubadora) via formulário inline; o valor persiste após recarregar a página
  5. Admin consegue salvar as datas de início e encerramento de contrato de uma empresa; os valores persistem após recarregar a página
**Plans**: 3 plans

Plans:

**Wave 1**
- [x] 05-01-PLAN.md — Migration + Company model + stubs de teste Wave 0 (FCH-02, FCH-03)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 05-02-PLAN.md — AdminController fechamento()/updateFechamento() + rota PATCH (FCH-01, FCH-02, FCH-03)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 05-03-PLAN.md — Financeiro.jsx reescrito + AppLayout label + npm run build + checkpoint humano (FCH-01, CFG-01)

Cross-cutting constraints:
- `EnsureUserHasRole` middleware obrigatório em todas as rotas admin (Plans 02, 03)
- Carbon `?->toDateString()` para serialização de datas em props Inertia (Plans 02, 03)
- `npm run build` obrigatório após qualquer edição JSX (Plan 03)

**UI hint**: yes

### Phase 6: Backend Fechamento
**Goal**: Query de aggregation sobre adman_metrics entrega faturamento mensal por empresa com período coberto; calcularFaixa() determina valor a cobrar; props chegam ao frontend via Inertia
**Mode:** mvp
**Depends on**: Phase 5
**Requirements**: FCH-04, FCH-05
**Success Criteria** (what must be TRUE):
  1. A query `SUM(adman_metrics.revenue) GROUP BY company_id` retorna o faturamento acumulado no mês corrente sem nenhuma chamada HTTP à API Adman
  2. O período coberto ("01/05 a 18/05") é calculado dinamicamente com base nos registros presentes em `adman_metrics` e sempre aparece associado ao faturamento da empresa
  3. Empresas sem nenhum registro em `adman_metrics` no mês corrente recebem estado `sem_dados` e não entram no total consolidado
  4. `calcularFaixa()` aplica a tabela de progressão corretamente: dado um faturamento de entrada, retorna a faixa correspondente e o valor mensal a cobrar
  5. O controller Inertia entrega todos os campos necessários (faturamento, faixa, valor, período, estado) para cada empresa no array de props
**Plans**: 2 plans

Plans:

**Wave 1**
- [ ] 06-01-PLAN.md — calcularFaixa() + const FAIXAS + CalcularFaixaTest GREEN + 8 stubs de feature RED (FCH-04, FCH-05)

**Wave 2** *(blocked on Wave 1 completion)*
- [ ] 06-02-PLAN.md — fechamento() expandido com aggregation query + todos os 16 testes GREEN (FCH-04, FCH-05)

Cross-cutting constraints:
- `whereNotNull('revenue')` obrigatório na aggregation para evitar distorção por revenue null (Plans 02)
- `Carbon::parse()` obrigatório antes de `->format('d/m')` em campos retornados por selectRaw (Plan 02)
- `(float)` cast obrigatório antes de passar faturamento para calcularFaixa() (Plan 02)
- Nenhuma mudança de rota — GET /administrativo/financeiro já existe (Plans 01, 02)
- `updateFechamento()` não deve ser alterado em nenhum plano desta fase (Plans 01, 02)

### Phase 7: UI Fechamento
**Goal**: Financeiro.jsx reescrita como tela Fechamento completa: lista de empresas com estado, barra de progresso por faixa, campo de serviço adicional e total consolidado visível
**Mode:** mvp
**Depends on**: Phase 6
**Requirements**: FCH-06, FCH-07, FCH-08
**Success Criteria** (what must be TRUE):
  1. Empresas com faturamento válido exibem barra de progresso mostrando posição na faixa atual e o valor que falta para atingir a próxima faixa
  2. Empresas na faixa máxima (faturamento > R$5M) exibem o texto "Faixa máxima" sem nenhuma barra de progresso
  3. O total consolidado exibido no topo da página soma apenas as empresas com estado `ok` (excluindo `sem_integracao` e `sem_dados`)
  4. O campo de serviço adicional aparece por empresa (valor visível ou placeholder "—"); não há lógica de cálculo associada nesta fase
  5. O período coberto ("01/05 a 18/05") está sempre visível na UI, associado ao bloco de faturamento de cada empresa
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
v1.0 phases execute in order: 1 → 2 → 3 → 4 (phases 2–4 paused for v3.0)
v2.0 phases execute in order: 5 → 6 → 7

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Diagnóstico Adman | 3/3 | Complete | 2026-05-18 |
| 2. Monitoramento de Jobs | 0/? | Paused (v3.0) | - |
| 3. Observabilidade | 0/? | Paused (v3.0) | - |
| 4. Configurações | 0/? | Paused (v3.0) | - |
| 5. Fundação Fechamento | 3/3 | Complete | 2026-05-19 |
| 6. Backend Fechamento | 0/2 | Planned | - |
| 7. UI Fechamento | 0/? | Not started | - |
