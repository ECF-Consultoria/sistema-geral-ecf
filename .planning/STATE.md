---
gsd_state_version: 1.0
milestone: v4.1
milestone_name: Eficiência Operacional Sugadores
status: executing
stopped_at: Phase 17 UI-SPEC approved
last_updated: "2026-06-01T20:42:53.946Z"
last_activity: 2026-06-01 -- Phase 17 execution started
progress:
  total_phases: 17
  completed_phases: 7
  total_plans: 24
  completed_plans: 19
  percent: 41
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-21)

**Core value:** Dar ao admin visibilidade total sobre operações internas: sync Adman, fechamento financeiro, comunicação interna (notificações) e cadastro centralizado de empresas pelo Comercial
**Current focus:** Phase 17 — coleta-de-dados-ml-intelig-ncia-de-an-ncios-do-mercado-livre

## Current Position

Phase: 17 (coleta-de-dados-ml-intelig-ncia-de-an-ncios-do-mercado-livre) — EXECUTING
Plan: 1 of 5
Status: Executing Phase 17
Last activity: 2026-06-01 -- Phase 17 execution started

## Performance Metrics

**Velocity:**

- Total plans completed: 13
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
| 14 | 7 | - | - |

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
| Phase 14-consolida-o-do-modelo-de-servi-os-frente-b P06 | 35 | 4 tasks | 13 files |
| Phase 14-consolida-o-do-modelo-de-servi-os-frente-b P07 | 45 | 4 tasks | 9 files |

## Accumulated Context

### Roadmap Evolution

- 2026-06-01 — Novo **Milestone v5.0 — Inteligência de Anúncios ML**; adicionada **Phase 17: Coleta de Dados ML (Fase 1 — sem IA)**. Decisões travadas via probe ML (busca `/sites/MLB/search` = 403; usar `/products/search` + `/highlights` + `/trends` via app token). Não depende da Phase 16. Próximo: `/gsd-plan-phase 17`.

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

### Decisões do Plan 14-07 (registradas)

- **Admin/Empresas e Comercial/Empresas duplicam a UI de contratos** em vez de criar componente compartilhado — alinhado ao plan; refator comum fica para fase futura.
- **Comercial/Empresas usa `/comercial/empresas/novo` para criação** — o formulário inline antigo foi removido; o fluxo novo com `servicos[]` ja foi entregue no Plan 14-04.
- **ComercialController::empresas passa contratos com shape completo** — necessario para Add/Edit/Desativar no modal da listagem Comercial.
- **Admin/Financeiro ServiceBadge sem fallback** — le apenas `servicos_contratados`.
- **Grep final limpo em `resources/js/Pages`** para os 6 campos removidos.
- **Smoke visual humano nao executado nesta sessao** — validacao feita por build, regressao focada, grep e `phase14:verificar-cobranca`; UAT real fica em deferred item.

### Decisões do Plan 14-02 (registradas)

- `firstOrCreate` (não `updateOrCreate`) no catálogo `servicos` — preserva ajustes manuais de `valor_padrao` feitos via UI `/servicos` em re-runs da migration
- Guards de idempotência usam tripla `(company_id, servico_id, valor_contratado)->exists()` — combinação exata por contrato, sem precisar de UNIQUE constraint no pivot
- Migration 2 envolvida em `DB::transaction` + `Company::chunk(100)` — atomicidade + sem OOM em prod
- Cache local `Servico::pluck('id', 'nome')` atualizado in-place quando `additional_service` dispara `firstOrCreate` de novo serviço (evita re-query)
- `toDateString()` explícito em ambos branches de `data_contratacao` — normaliza Carbon ↔ string consistente entre SQLite (testes) e MySQL (prod)
- `(float)` explícito em `valor_contratado` antes de comparações `where(...)` — cast `decimal:2` retorna string em SQLite (Pitfall 4)
- `down()` no-op informativo em ambas migrations — reverter dados de migration exige backup do DB
- Sistema em **COEXISTÊNCIA** após Plan 14-02: campos legacy AINDA populados E `contratos_servico` populado; runtime AINDA lê dos legacy até o Plan 14-03

### Decisões da Phase 15 (registradas)

- **Novo status `auto_resolvido`** (não reutilizar `resolvido`) — separa auditoria do que o sistema limpa automaticamente do que o analista resolveu manualmente. Adicionado a `Sugador::STATUS_TRAVADOS` para evitar reaversion futura.
- **Migration W1-T1 também alterou `sugador_acoes.user_id` para nullable** — pré-requisito invisível do plano original (audit log de auto-resolução não tem user real). Rule 2 deviation aplicada.
- **Auto-resolução roda DENTRO de `analyzeCompany` após o upsert** com `reference_date < hoje` (estritamente menor) — protege contra rerun manual no mesmo dia que recriaria os mesmos pendentes.
- **Cards são a vista default; lista permanece via toggle `view_mode`** — compat com bookmarks/links antigos; lista herda o `SearchableCompanyFilter` do outro dev (filtro cascata user→empresa).
- **`companies_summary` usa query agregada única com `SUM(CASE WHEN)` GROUP BY** — sem N+1 (teste assert máximo 15 queries com 10 empresas + 20 sugadores).
- **`DATE(reference_date)` no SQL** normaliza valores SQLite (datetime) e MySQL (date) — preço: previne uso de index puro na coluna, mas não há index hoje.
- **`navigator.clipboard` com fallback `textarea + execCommand`** — necessário porque intranet HTTP não tem secure context.
- **`listing_id` é o identificador MLB no payload MCP** — `.filter(Boolean)` defensivo no `.join(',')` caso payload mude.
- **Merge com origin/main preservou AMBAS features** (Phase 15 + filtro cascata do outro dev) — são complementares: cards na visão default, combobox cascata no modo lista.
- **Smoke test humano (W4-T2) NÃO foi executado em browser** antes do deploy — testes automatizados 13/13 cobriram backend e props, UX visual fica como item deferred.

### Decisões da Phase 16 (registradas)

- **2026-05-27 — Supervisor `--timeout` elevado de 900s para 1800s** em `/etc/supervisor/conf.d/ecf-worker.conf` no VPS (Phase 16 W1-T4 Sub-task A). Mudança de infra, não-commit. Motivo: o loop interno do `RefreshGrossBillingCacheJob` agora leva ~20min (168 empresas × 7s de throttle) e o timeout antigo de 15min causaria SIGTERM mid-loop. Workers reiniciados via `supervisorctl reread && supervisorctl update ecf-worker`; PIDs novos: `ecf-worker_00=4119849`, `ecf-worker_01=4119850`. Rollback: `sed -i 's/--timeout=1800/--timeout=900/' ... && supervisorctl reread && supervisorctl update ecf-worker`.
- **`RefreshGrossBillingCacheJob` mantido como loop único (não-fan-out)** — decisão W1-T4 Sub-task C. Com `--timeout=1800` + throttle interno 7s + `->withoutOverlapping()` + 1×/dia, não há risco de colisão paralela. Defesa em profundidade: reavaliar se smoke W3-T3 mostrar 429 originados deste job.

### Pending Todos

None.

### Blockers/Concerns

- **Rate limit 429 da Adman**: problema crônico, não relacionado à Phase 15. Endpoint `/sugadores/{id}/mlbs` retorna 502 quando MCP da Adman bate 429. Logs mostram 429 sequencial em vários `SyncAdmanCompanyJob` em produção. Mensagem formal enviada ao grupo da Adman em 2026-05-27 pedindo aumento de limite.

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 260522-lds | Implementar sistema de envio de email do Relatorio Geral de Fechamento | 2026-05-22 | cb4f69a | [260522-lds-implementar-sistema-de-envio-de-email-do](.planning/quick/260522-lds-implementar-sistema-de-envio-de-email-do/) |
| 260526-jgj | Módulo Serviços (Frente A) + ajustes na lista de empresas — coexiste com legacy | 2026-05-26 | 855038e | [260526-jgj-modulo-servicos-frente-a](.planning/quick/260526-jgj-modulo-servicos-frente-a/) |
| 260601-fm3 | KPIs Adman (Faturamento/ACOS/TACOS/Margem%) para empresas ML-only via API do Mercado Livre | 2026-06-01 | a2e8237 | [260601-fm3-kpis-adman-via-ml](.planning/quick/260601-fm3-kpis-adman-via-ml/) |
| 260601-ml1 | Resiliência OAuth ML: corrige revogação por erro transitório, status amigável, Cust ID único | 2026-06-01 | 8d21fed | [260601-ml1-oauth-resiliencia](.planning/quick/260601-ml1-oauth-resiliencia/) |
| 260601-ml2 | Mercado Ads: advertiser_id correto + aggregation_type CAMPAIGN + trata advertiser sem campanhas (404) — destrava ACOS/TACOS/Invest.Ads | 2026-06-01 | fe83661 | [260601-ml2-ads-advertiser-id](.planning/quick/260601-ml2-ads-advertiser-id/) |
| 260601-ml3 | Cutover Adman → ML por empresa (token ML ativo assume); fim do conflito de sync; sugadores usa ID Adman | 2026-06-01 | c85b86f | [260601-ml3-cutover-adman-ml](.planning/quick/260601-ml3-cutover-adman-ml/) |

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
| Phase 14 | Smoke visual Plan 14-07 das 5 telas | pending-human-uat | 2026-05-26 |
| Phase 15 | Smoke visual W4-T2 (11 itens: cards/copy/chip/reanalisar/badge auto_resolvido) | pending-human-uat | 2026-05-27 |
| Adman | Rate limit 429 — aumento de quota pedido ao provedor (mensagem enviada no grupo) | aguardando-resposta-provedor | 2026-05-27 |

## Session Continuity

Last session: 2026-06-01T20:17:17.178Z
Stopped at: Phase 17 UI-SPEC approved
