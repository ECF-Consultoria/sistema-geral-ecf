---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: Fluxo Comercial
status: executing
stopped_at: "Phase 14 Plan 14-06 concluido — migration 3 aplicada localmente e 6 colunas legacy removidas de companies; backend limpo; app/ sem referencias funcionais aos campos legacy; phase14:verificar-cobranca retorna 0 divergencias pos-drop; testes focados pos-drop 9/9 (101 assertions). Proximo: Plan 14-07 cleanup frontend."
last_updated: "2026-05-26T21:10:00.000Z"
last_activity: 2026-05-26 -- Plan 14-06 executed (drop local + backend cleanup + 9 testes focados pos-drop verdes)
progress:
  total_phases: 14
  completed_phases: 6
  total_plans: 19
  completed_plans: 18
  percent: 56
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-21)

**Core value:** Dar ao admin visibilidade total sobre operações internas: sync Adman, fechamento financeiro, comunicação interna (notificações) e cadastro centralizado de empresas pelo Comercial
**Current focus:** Phase 14 — consolida-o-do-modelo-de-servi-os-frente-b

## Current Position

Phase: 14 (consolida-o-do-modelo-de-servi-os-frente-b) — EXECUTING
Plan: 7 of 7 (Plans 14-01 a 14-06 concluidos)
Status: Plan 14-06 complete — migration 3 aplicada localmente; `companies` nao tem mais as 6 colunas legacy; backend limpo de reads/writes legacy. Ready for Plan 14-07 (cleanup frontend dos 5 JSX consumers).
Last activity: 2026-05-26 -- Plan 14-06 executed (schema pos-drop confirmado, phase14 0 divergencias, testes focados 9/9)

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
| Phase 14-consolida-o-do-modelo-de-servi-os-frente-b P03 | 10 | 3 tasks | 9 files |
| Phase 14-consolida-o-do-modelo-de-servi-os-frente-b P04 | 8 | 3 tasks | 6 files |
| Phase 14-consolida-o-do-modelo-de-servi-os-frente-b P05 | 17 | 4 tasks | 7 files |

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

### Decisões do Plan 14-03 (registradas)

- **5 chamadas reais de `CobrancaCalculator::novo`** em `AdminController` (não 3 — cada um dos 3 sites tem variações pai/filha): `fechamento()` 1×, `gerarRelatorio()` pai+filhas 2×, `gerarRelatorioGeral()` pai+filhas 2×
- **`?: null` no caller preserva semântica legacy** "null quando vazio" — helper sempre retorna float; caller decide
- **Chaves legacy permanecem nos mappers Inertia** ao lado de `servicos_contratados` (estratégia de COEXISTÊNCIA Wave 2 — removidas só no Plan 14-06)
- **`labelFromTypes()` mantido como `@deprecated` proxy** — evita quebrar as 6 chamadas em 3 Blade views (refator no Plan 14-05)
- **`EmpresaCadastradaNotification` aceita `string|array` via union type** — backward compat com 1 caller em `ComercialController` que ainda passa string (refator no Plan 14-04); explode('+') no path string
- **Filtro slug→nome em `gerarRelatorioGeral`:** request continua aceitando o slug legacy; lookup interno mapeia para `nome` do catálogo antes do `whereHas`
- **Job payload formato string** `"Nome (R$ X,XX), Outro (R$ Y,YY)"` em vez de array — facilita consumo no email-template Blade
- **Testes usam `viewData('page')['props']`** + `assertEqualsWithDelta` para evitar falsos negativos por serialização JSON `4700.0` → `4700`
- **Falhas pré-existentes em `AdminFechamentoControllerTest` (5 testes)** documentadas em `deferred-items.md` — fora de escopo do plan (SCOPE BOUNDARY do executor)

### Decisões do Plan 14-05 (registradas)

- **URL crua nas chamadas Inertia (router.post/put/delete)** em vez de `route('empresas.contratos.store', ...)` — evita acoplamento ao Ziggy/route helper; se nome nomeado mudar, JSX não quebra silenciosamente. Decisão pre-flight Task 0.
- **Accessor `service_type_label` (snake_case) em vez de método `serviceTypeLabel()`** — Plan 14-03 implementou como accessor Eloquent (`getServiceTypeLabelAttribute`), na Blade lê como `$company->service_type_label`. Fix (Rule 1 - Bug) aplicado nas 3 Blade views durante Task 3.
- **ServiceBadge fallback legacy mantido (compat até Plan 14-07)** — prioriza `servicos_contratados`, cai em `tipo` legacy se ausente. Preserva renderização durante transição em produção. Removido no cleanup final do Plan 14-07.
- **GraficoContrato substituído por GraficoCobranca** — antigo agrupava por `contract_type` (fixo/progressão — colunas a serem dropadas no Plan 14-06); novo agrupa por `tipo_cobranca` (mensal/única — campo de `contratos_servico`). Alinhamento com catálogo.
- **FechamentoRow exibe 'N contratos ativos'** em vez de range `contract_start/end` — datas individuais moram em cada contrato (visíveis no modal Edit).
- **AdminController::fechamento passa `servicos_disponiveis`** (catálogo ativo via `Servico::where('ativo', true)`) — alimenta o select do modal Add sem fetch separado.
- **Modal usa `Dialog` do shadcn/ui** (mesmo de `Companies/Show.jsx`) — consistência visual e UX.
- **UAT humano (Task 5) deferido como débito em `deferred-items.md`** — usuário decidiu pular checkpoint visual baseado nos 28/28 testes automatizados verdes + 202 assertions na suíte combinada Phase 14. 12 itens de verificação visual listados para próxima sessão de uso real. NÃO bloqueia Plan 14-06 (gate depende do `phase14:verificar-cobranca`, não da UI).
- **5 JSX consumers restantes lendo legacy** (Admin/Empresas, Comercial/Empresas, Mlb/Empresas, Companies/Index, fallback do ServiceBadge em Admin/Financeiro) — cleanup no Plan 14-07.

### Decisões do Plan 14-04 (registradas)

- **Helper `servicoDisparaImplementacao` PUBLIC STATIC** (não privado) — permite chamada via `self::` dentro do `store()` E testes diretos sem instanciar controller
- **Roteamento por NOME canônico (str_contains case-sensitive)** — D-02 garante Title Case no catálogo; variantes lowercase nunca entram via fluxo normal
- **`store().servicos.*.valor_contratado` nullable** — cliente pode omitir e cai no `valor_padrao` do catálogo
- **`update()` enxuto IGNORA silenciosa campos legacy** — validação não inclui `service_type`/`additional_service_price`/etc.; payload legacy enviado pelo cliente (UI Comercial/Empresas.jsx pré-refator) não causa erro 422
- **`empresas()` reconstrói `service_type[]`** via mapa nome→slug — compat UI Comercial/Empresas.jsx até Plan 14-07
- **Rota `comercial.empresas.novo` migrada para `create()`** (antes era `index()` redirect noop) — agora retorna página com prop `servicos_disponiveis`
- **`slugSetorParaServico()` novo, `resolverSlugsSetores()` `@deprecated`** — preserva chamada legacy para evitar quebra silenciosa; ambos removidos no Plan 14-06
- **Phase13ComercialTest documentada como obsoleta** em `deferred-items.md` — cobertura equivalente reproduzida em `Phase14ComercialTest` (COM-04/05/06/08); deletion/port adiada para quick task pós Plan 14-06
- **Activity log passa `servicos` (array)** em vez de `service_type` legacy

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

(nenhum bloqueante para Plan 14-07. Plan 14-06 dropou localmente as 6 colunas legacy e limpou backend; suites antigas de coexistencia precisam de cleanup no gate de regression, registrado em deferred-items.md.)

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
| Phase 14 | UAT humano Plan 14-05 (12 itens UX visual Fechamento) | pending-human-uat | 2026-05-26 |
| Phase 14 | `phase14:verificar-cobranca` no host (gate Plan 14-06) | pending-host-run | 2026-05-26 |
| Phase 14 | `Phase13ComercialTest` obsoleta (cobertura em Phase14ComercialTest) | port/deletion | 2026-05-26 |
| Phase 14 | `AdminFechamentoControllerTest` 5 testes falhando pré-existentes | quick task | 2026-05-26 |
| Phase 14 | Suites de coexistencia pos-drop obsoletas | pending-regression-cleanup | 2026-05-26 |

## Session Continuity

Last session: 2026-05-26T21:10:00Z
Stopped at: Phase 14 Plan 14-06 concluido — migration 2026_05_27_100003 aplicada localmente; Schema::hasColumn confirmou service_type=0 e additional_service_price=0; backend limpo das chaves legacy; phase14 pre e pos-drop retornou 0 divergencias em 0 empresas; testes focados pos-drop passaram 9/9 (101 assertions). Proximo: Plan 14-07 cleanup frontend dos 5 JSX consumers.
