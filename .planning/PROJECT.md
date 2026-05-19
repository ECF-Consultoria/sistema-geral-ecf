# ECF Admin — Setor Dev

## What This Is

Sistema de administração interna do ECF Admin com dois módulos principais: **Setor Dev**
(diagnóstico de sync Adman, fila de jobs, logs e configurações) e **Módulo Administrativo**
(fechamento mensal — faturamento por empresa, faixa de investimento e total a cobrar).
Acessível exclusivamente via role `admin`.

## Core Value

Dar ao admin visibilidade total sobre operações internas: o sync Adman e o fechamento
financeiro de cada empresa — sem precisar de acesso direto ao servidor.

## Current Milestone: v2.0 Administrativo — Fechamento

**Goal:** Admin pode acompanhar o faturamento de cada empresa no ML, ver em qual faixa de investimento ela está, e ter o total a cobrar no mês.

**Funcionalidades:**
- Listar empresas com tipo de serviço (POLO / Assessoria / Incubadora) e datas de contrato
- Exibir faturamento mensal por empresa via Adman API
- Calcular faixa de investimento com base na tabela de progressão (faturamento_adm.md)
- Barra de progresso: posição na faixa atual e distância para a próxima faixa
- Campo de serviço adicional reservado (sem lógica neste milestone)
- Total consolidado a cobrar no mês corrente

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

### Active (v2.0 — Administrativo Fechamento)

<!-- Escopo do milestone atual — Fechamento Administrativo -->

- [ ] **ADM-01**: Admin pode ver lista de empresas com tipo de serviço e datas de contrato
- [ ] **ADM-02**: Admin pode ver o faturamento mensal de cada empresa via Adman API
- [ ] **ADM-03**: Admin pode ver a faixa de investimento de cada empresa baseada na tabela de progressão
- [ ] **ADM-04**: Admin pode ver barra de progressão com posição na faixa atual e distância para a próxima
- [ ] **ADM-05**: Admin pode ver o total consolidado a cobrar de todas as empresas no mês corrente
- [ ] **ADM-06**: Cada empresa tem campo de serviço adicional reservado (visível, sem lógica de valor)

### Pausado (v1.0 — Setor Dev, retomar em v3.0)

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
| Evoluir `/dev/desenvolvimento` existente | Rota e layout já funcionam, evita duplicidade | — Pending |
| Log de sync armazenado no banco (nova tabela) | Permite histórico persistente sem depender de arquivos de log | — Pending |
| Jobs disparados via API Inertia (não WebSockets) | Suficiente para o volume atual, sem complexidade adicional | — Pending |
| Acesso apenas role admin | Dados sensíveis (payloads API, configurações) não devem vazar para consultores | — Pending |

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
*Last updated: 2026-05-19 after milestone v2.0 start — Administrativo Fechamento*
