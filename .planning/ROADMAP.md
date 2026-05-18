# Roadmap: ECF Admin — Setor Dev

## Overview

Evolução da página `/dev/desenvolvimento` em quatro fatias verticais entregáveis de forma independente: diagnóstico do sync Adman (prioridade máxima), monitoramento da fila de jobs, observabilidade do ambiente, e controle de configurações do sistema. Cada fase entrega uma capacidade completa e verificável que o admin pode usar imediatamente.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Diagnóstico Adman** - Admin pode inspecionar e controlar o sync Adman por empresa sem acessar o servidor
- [ ] **Phase 2: Monitoramento de Jobs** - Admin pode ver o estado da fila de jobs, incluindo falhas com detalhes completos
- [ ] **Phase 3: Observabilidade** - Admin pode ver logs de erro do sistema e informações do ambiente de execução
- [ ] **Phase 4: Configurações** - Admin pode visualizar e editar flags de configuração do sistema via painel

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
- [ ] 01-01-PLAN.md — Fundação de dados e contrato de testes (migration + AdmanSyncLog + Company rels + 5 testes RED)
- [ ] 01-02-PLAN.md — Backend end-to-end: DevController + AdmanService logging + rotas (testes GREEN)
- [ ] 01-03-PLAN.md — UI inline SyncAdmanSection + npm run build + checkpoint humano

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

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Diagnóstico Adman | 0/3 | Planned — Ready to execute | - |
| 2. Monitoramento de Jobs | 0/? | Not started | - |
| 3. Observabilidade | 0/? | Not started | - |
| 4. Configurações | 0/? | Not started | - |
