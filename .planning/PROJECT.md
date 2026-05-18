# ECF Admin — Setor Dev

## What This Is

Painel de diagnóstico interno para desenvolvedores administradores do ECF Admin.
Concentra visibilidade de sync Adman, fila de jobs, logs e configurações do sistema
numa área acessível exclusivamente via role `admin`, evoluindo a página
`/dev/desenvolvimento` já existente.

## Core Value

Tornar o sync Adman completamente observável e controlável sem precisar de acesso
direto ao servidor — ver o que aconteceu, quando, com quais dados, e disparar
manualmente quando necessário.

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

### Active

<!-- Escopo do milestone atual -->

- [ ] **DEV-01**: Admin pode ver data/hora do último sync Adman por empresa
- [ ] **DEV-02**: Admin pode ver o payload bruto retornado pela API Adman (ou erro HTTP) de cada sync
- [ ] **DEV-03**: Admin pode ver o diff do sync: quantos registros foram criados, atualizados e ignorados
- [ ] **DEV-04**: Admin pode disparar o sync Adman de uma empresa específica manualmente via botão
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
*Last updated: 2026-05-18 after initialization*
