---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: Fluxo Comercial
status: executing
stopped_at: "Phase 14 Plan 14-02 concluído — migrations seed (6 servicos canônicos) + data legacy (contratos derivados) + 5/5 testes Phase14MigrationTest verdes. Sistema em COEXISTÊNCIA. Próximo: Plan 14-03 (refator dos consumers PHP)"
last_updated: "2026-05-26T19:03:27.417Z"
last_activity: 2026-05-26 -- Plan 14-02 executed (3 commits, 5/5 tests passed, 46 assertions)
progress:
  total_phases: 14
  completed_phases: 6
  total_plans: 19
  completed_plans: 14
  percent: 43
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-21)

**Core value:** Dar ao admin visibilidade total sobre operações internas: sync Adman, fechamento financeiro, comunicação interna (notificações) e cadastro centralizado de empresas pelo Comercial
**Current focus:** Phase 14 — consolida-o-do-modelo-de-servi-os-frente-b

## Current Position

Phase: 14 (consolida-o-do-modelo-de-servi-os-frente-b) — EXECUTING
Plan: 3 of 7 (Plans 14-01 e 14-02 concluídos)
Status: Plan 14-02 complete — sistema em COEXISTÊNCIA (campos legacy populados E contratos_servico populados). Ready for Plan 14-03 (refator dos consumers PHP).
Last activity: 2026-05-26 -- Plan 14-02 executed (3 commits, 5/5 tests passed, 46 assertions)

## Performance Metrics

**Velocity:**

- Total plans completed: 6
- Average duration: ~15 min/plan
- Total execution time: ~1.5 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1. Diagnóstico Adman | 3/3 | ~45 min | ~15 min |
| 5. Fundação Fechamento | 3/3 | ~45 min | ~15 min |
| 6. Backend Fechamento | 2/2 | - | - |
| 7. UI Fechamento | 1/1 | - | - |
| 8. Fundação de Notificações | 0/? | - | - |
| 9. Backend de Leitura, Contador e Polling | 0/? | - | - |
| 10. UI do Sino e Página de Histórico | 0/? | - | - |
| 11. Disparos Automáticos de Metas | 0/? | - | - |
| 12. Criação Manual, Permissão na UI de Setores e Cleanup | 0/? | - | - |

*Updated after each plan completion*
| Phase 06-backend-fechamento P01 | 2 | 2 tasks | 3 files |
| Phase 13-reestruturacao-cadastro-empresas P01 | 25min | 2 tasks | 7 files |
| Phase 13-reestruturacao-cadastro-empresas P02 | 20min | 2 tasks | 3 files |
| Phase 13-reestruturacao-cadastro-empresas P03 | 7min | 2 tasks | 6 files |
| Phase 14-consolida-o-do-modelo-de-servi-os-frente-b P01 | 5min | 2 tasks | 4 files |
| Phase 14-consolida-o-do-modelo-de-servi-os-frente-b P02 | 4 | 3 tasks | 3 files |

## Accumulated Context

### Decisões herdadas do v1.0

- ✓ Evoluir página `/dev/desenvolvimento` existente — rota e layout já funcionam
- ✓ Log de sync armazenado no banco (tabela `adman_sync_logs`) — concluído na Phase 1
- ✓ Jobs disparados via API Inertia (sem WebSockets) — padrão estabelecido

### Decisões do v2.0

- Faturamento = SUM(adman_metrics.revenue) GROUP BY company_id — sem chamada API Adman em tempo de requisição
- Rota `/administrativo/financeiro` mantida; apenas o label sidebar muda para "Fechamento"
- Arquivo alvo da reescrita UI: `resources/js/Pages/Admin/Financeiro.jsx`
- 3 estados de empresa: `sem_integracao` (badge), `sem_dados`, `ok`
- Faixa máxima (> R$5M): exibir "Faixa máxima" sem barra de progresso
- Total consolidado soma apenas empresas com estado `ok`
- Período coberto sempre exibido na UI (ex: "01/05 a 18/05"), calculado de adman_metrics
- Tabela de progressão de faixas implementada como constante no backend (não editável via UI neste milestone)

### Decisões do 05-02 (registradas)

- `Validator::make()` manual em `updateFechamento()` para retornar 422 JSON sem depender do header X-Inertia
- Cast `date:Y-m-d` (explícito) no Company model para garantir formato ISO no SQLite em testes

### Entregues na Phase 5

- ✓ Migration `add_service_fields_to_companies` executada (service_type, contract_start, contract_end, additional_service)
- ✓ Company.$fillable, $casts (date:Y-m-d), logOnly atualizados
- ✓ AdminController: fechamento() + updateFechamento() com validação
- ✓ routes/web.php: GET → fechamento(), PATCH /financeiro/{company} → admin.financeiro.update
- ✓ Financeiro.jsx reescrito com FechamentoList/Row/Accordion/ServiceForm/ServiceBadge/IntegrationBadge
- ✓ AppLayout.jsx: label "Financeiro" → "Fechamento"
- ✓ npm run build: 0 erros, 9/9 testes Fechamento verdes

### Decisões do v3.0 (roadmap 2026-05-21)

- 5 phases (8 a 12) cobrindo 31/31 requirements; numeração continua a sequência v1.0/v2.0
- Ordem de execução: 8 → 9 → 10 → 11 → 12, sem paralelismo entre phases
- Phase 8 entrega fundação (tabela `notifications` + permission_key `notificacoes.criar` + AUTO_LIDERANCA) — sem isso, nada do v3.0 funciona
- Phase 9 entrega backend testável via HTTP antes de qualquer UI (shared prop + polling endpoint + listagem + mark read individual/todos)
- Phase 10 monta UI completa (sino + dropdown + página `/notificacoes` com abas) consumindo o backend da Phase 9
- Phase 11 implementa Observers nos 6 cenários de meta (3 atribuição + 3 atingimento) reutilizando o dispatch da Phase 9 + 10
- Phase 12 fecha o ciclo: criação manual com targeting (4 públicos), exposição da permissão na UI de setores, cleanup diário e activity log apenas de envios manuais
- Targeting (individual/setor/líderes/todos) resolvido no dispatch — expandido para `user_ids` no envio, sem lógica de audiência no read path
- Atualização real-time = polling ~60s + revalidação Inertia em toda navegação; sem WebSockets/broadcast no MVP

### Decisões do Plan 14-01 (registradas)

- `CobrancaCalculator::novo()` aceita `iterable` (não `Collection` estrita) — permite testes unit com objetos anônimos sem container Laravel (Pitfall 4 RESEARCH)
- Helper retorna `float` sempre (nunca `null`) — semântica "null quando vazio" delegada ao caller (será aplicada no Plan 14-03)
- Tabela FAIXAS duplicada no comando `phase14:verificar-cobranca` (não compartilhada com `AdminController::FAIXAS`) — duplicação intencional pois o comando é descartável pós-Phase 14
- Testes do helper usam `assertEqualsWithDelta(esperado, atual, 0.001)` para comparações de float (não `assertEquals`)
- Tolerância de R$ 0,01 em comparações decimais no comando (`abs($legacy - $novo) > 0.01`) — evita falsos positivos de arredondamento

### Decisões do Plan 14-02 (registradas)

- `firstOrCreate` (não `updateOrCreate`) no catálogo `servicos` — preserva ajustes manuais de `valor_padrao` feitos via UI `/servicos` em re-runs da migration
- Guards de idempotência usam tripla `(company_id, servico_id, valor_contratado)->exists()` — combinação exata por contrato, sem precisar de UNIQUE constraint no pivot
- Migration 2 envolvida em `DB::transaction` + `Company::chunk(100)` — atomicidade + sem OOM em prod
- Cache local `Servico::pluck('id', 'nome')` atualizado in-place quando `additional_service` dispara `firstOrCreate` de novo serviço (evita re-query)
- `toDateString()` explícito em ambos branches de `data_contratacao` — normaliza Carbon ↔ string consistente entre SQLite (testes) e MySQL (prod)
- `(float)` explícito em `valor_contratado` antes de comparações `where(...)` — cast `decimal:2` retorna string em SQLite (Pitfall 4)
- `down()` no-op informativo em ambas migrations — reverter dados de migration exige backup do DB
- Sistema em **COEXISTÊNCIA** após Plan 14-02: campos legacy AINDA populados E `contratos_servico` populado; runtime AINDA lê dos legacy até o Plan 14-03

### Pending Todos

None.

### Blockers/Concerns

(nenhum — Plan 14-02 entregou migrations + suíte de testes; pronto para Plan 14-03 — refator dos consumers PHP)

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 260522-lds | Implementar sistema de envio de email do Relatorio Geral de Fechamento | 2026-05-22 | cb4f69a | [260522-lds-implementar-sistema-de-envio-de-email-do](.planning/quick/260522-lds-implementar-sistema-de-envio-de-email-do/) |
| 260526-jgj | Módulo Serviços (Frente A) + ajustes na lista de empresas — coexiste com legacy | 2026-05-26 | 855038e | [260526-jgj-modulo-servicos-frente-a](.planning/quick/260526-jgj-modulo-servicos-frente-a/) |

## Deferred Items

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| Setor Dev | DEV-05: Monitoramento de Jobs | v4.0 | 2026-05-21 |
| Setor Dev | DEV-06: Logs do sistema | v4.0 | 2026-05-21 |
| Setor Dev | DEV-07: Informações do ambiente | v4.0 | 2026-05-21 |
| Setor Dev | DEV-08: Configurações/flags | v4.0 | 2026-05-21 |
| Fechamento | FCH-08 lógica adicional | v2.1+ | 2026-05-19 |
| Histórico | HIST-01: histórico paginado por empresa | v2.1+ | 2026-05-18 |
| Histórico | HIST-02: exportar logs de sync para CSV | v2.1+ | 2026-05-18 |

## Session Continuity

Last session: 2026-05-26T19:01:32Z
Stopped at: Phase 14 Plan 14-02 concluído — migrations seed_servicos_catalog + migrate_legacy_service_data idempotentes + 5/5 testes Phase14MigrationTest verdes (46 assertions). Sistema em COEXISTÊNCIA. Próximo: Plan 14-03 (refator dos consumers PHP — Company, AdminController×3 sites, MlbController, CompanyController, EmpresaCadastradaNotification, EnviarRelatorioFechamentoJob)
