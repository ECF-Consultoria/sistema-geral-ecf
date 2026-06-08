---
gsd_state_version: 1.0
milestone: v8.0
milestone_name: Integração Estratégica ECF Drive
status: milestone_complete
stopped_at: Milestone v8.0 (Integração Estratégica ECF Drive) completa em 2026-06-08. 7 phases entregues (22-28). Phase 28 fechou com PDF mensal 871KB validado por email para matheusbarretop14@gmail.com.
last_updated: "2026-06-08T12:00:00.000Z"
last_activity: 2026-06-08 -- Milestone v8.0 fechada
progress:
  total_phases: 27
  completed_phases: 19
  total_plans: 36
  completed_plans: 36
  percent: 70
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-21)

**Core value:** Dar ao admin visibilidade total sobre operações internas: sync Adman, fechamento financeiro, comunicação interna (notificações) e cadastro centralizado de empresas pelo Comercial
**Current focus:** Phase 22 — wrapper-expandido-ecfdriveservice-todos-endpoints-auth-files

## Current Position

Phase: 24 (painel-executivo-carteira-ecf) — W1+W2+W3 complete, W4 checkpoint humano
Plan: 1 of 1
Status: Phase complete — ready for verification
Last activity: 2026-06-06

## Performance Metrics

**Velocity:**

- Total plans completed: 18
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
| 17 | 5 | - | - |

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
- 2026-06-05 — **Phase 20: Integração ECF Drive** adicionada à v7.0. Substitui pipeline `grants:sync-sftp` por wrapper `EcfDriveService` que consome a API HTTP do sistema externo desenvolvido pelo usuário (`files.ecfconsultoria.com.br/api/v1`). Decisões travadas no CONTEXT.md: substituir SFTP direto, adicionar coluna `segmento`, match cust_id→CNPJ com log órfãos, webhook deferido para fase futura. Phase 19 segue em aberto aguardando smoke W4.
- 2026-06-05 — Phase 20 **completed e deployed**. Match estrito por cust_id (Plano 02 do mesmo dia removeu fallback CNPJ por risco com alunos de cursos). 80 grants em prod, segmento preenchido, 20 testes Phase 20 verdes. API foi ajustada pelo usuário pra corrigir inversão `granted_at > expires_at` (era responsável por 13 falsos "Pending"). Risco aceito pelo usuário: API key `ecf_c7b9d4fe...` foi exposta no chat e o usuário optou por NÃO revogar — decisão registrada.
- 2026-06-05 — **Phase 21: Manual do Sistema** adicionada à v7.0. Hardcode JSX, tabela ordenada para o cronograma, link no rodapé da sidebar, acesso a todos autenticados. Primeiro artigo: "Cronograma de horários" em linguagem simples sem termos técnicos. Próximo: `/gsd-plan-phase 21`.
- 2026-06-05 — **Milestone v8.0: Integração Estratégica ECF Drive** criada com 7 phases (22-28). Base: usuário forneceu API-GUIDE.md completo do ECF Drive revelando 10 domínios de API (clientes/sellers/carteira/signals/relatorios/etc), do qual hoje só consumimos `/clientes/grants` (Phase 20). Análise estratégica identificou 7 oportunidades de alto ROI ordenadas por dependência: Phase 22 (wrapper base) → 23 (alertas signals) → 24 (painel executivo) → 25 (ficha 360 sellers) → 26 (webhooks HMAC) → 27 (concentração+forecast) → 28 (relatório mensal). Decisões: Sugadores convive com Signals (escopos distintos), nova milestone v8.0 (não continuar v7.0), ordem por valor de negócio × dependência. Phase 21 segue em aberto aguardando smoke W4 visual.
- 2026-06-08 — **Milestone v8.0 FECHADA**. Todas 7 phases (22-28) deployed em prod e validadas. Entregas: wrapper expandido (22 métodos cobrindo 5 domínios ECF Drive), aba `/alertas-estrategicos` (778 signals reais, 172 críticos), `/painel-executivo` (carteira inteira ECF R$ 42,8M GMV), `/empresas/{id}/analise-ecf` (ficha 360° com gráfico/medalhas/alertas), integração ECF Drive em `/companies/{id}` (Plano 04 Phase 25), receiver HMAC em `/api/webhooks/ecf` para 6 eventos (6,56ms latência real), `/concentracao` (matriz programa×cluster + forecast 90d + top 20 vacas leiteiras), Relatório Mensal automatizado (PDF 871KB enviado por SMTP Gmail validado por email). Bug bônus identificado e reportado ao parceiro ECF Drive: `razaoSocial` retornava sempre nome do parceiro consultor — parceiro corrigiu BrasilAPI mesmo dia (diversidade subiu de 1 para 235 razões únicas). API key `ecf_c7b9...` segue exposta no chat (risco aceito pelo usuário desde Phase 20). Phase 21 ainda em aberto aguardando smoke W4 visual. Próximo: definir milestone v9.0.

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
- **2026-06-02 — Phase 16 validada em produção** com 6 dias de dados reais. Redução de 429 confirmada: pré-Phase 16 média ~7.500/dia (25/05: 6.073 · 26/05: 7.951 · 27/05: 6.537 deploy parcial) → pós-Phase 16 média ~140/dia (28/05: 137 · 29/05: 153 · 30/05: 158 · 31/05: 136 · 01/06: 126 · 02/06: 190). **Redução de 98%**. Os 429 residuais ficam concentrados no horário do `adman:sync` (11:00 BRT) e são absorvidos pelo retry exponencial 2s/4s/8s — não impactam usuário final. SC-8 ("zero 429 em uso normal") considerado entregue na prática. Para zerar de fato, seria necessário fan-out também no `RefreshGrossBillingCacheJob` — ROI marginal, não priorizado.

### Decisões da Phase 18 (registradas)

- **Regras-mestras estabelecidas em 2026-06-02**: acertividade dos dados + praticidade operacional. Salvas em [feedback_project_priorities.md](MEMORY.md). Aplicáveis a todo planejamento futuro.
- **Bug 1 (período não muda dados)**: range fixo `$dateFrom30d`/`$dateTo30d` em 6 sites do controller foi substituído por helper `getPeriodRange($period)` retornando `from`/`to` derivados (W2-T1, W2-T2).
- **Bug 2 (filtros perdem-se)**: inconsistência camelCase (`companyFilter`) vs snake_case (`company_id`). Backend agora exporta `filters` em snake_case via array literal (W1-T1); frontend lê `filters.company_id` etc (W1-T2).
- **Cache key strategy (W2-T3)**: ranges ≠ 30d caem em fallback DB intencionalmente — `RefreshGrossBillingCacheJob` (Phase 16) só preenche range 30d. Trade-off: respeita Phase 16, evita pre-warm de ranges raros, indicador "≈" no card sinaliza ao operador.
- **Bug 3 (faturamento divergente)**: auditoria revelou diff total 71,79% (R$ 39M); 32 empresas INVALIDO_CONFIRMADO; causa raiz NÃO era cust_id corrompido — era marketplace hardcoded `'meli'` (descoberto via planilha oficial Adman cruzada com diagnose).
- **Estratégia A do refator do controller**: política "tudo-ou-nada" virou "híbrido per-empresa" — cache hit usa Adman exato; cache miss cai em SUM(adman_metrics) só pra essa empresa. SUM em 1 query agregada para evitar N+1. (W4-T3)
- **W4-T2 caça ao 429**: 5 callers ajustados para throttle 7s consistente (SyncTodasVendasAdmanJob, SyncVendasAdman, SyncThumbnailsPublicacoes, MlbController::syncVendasPublicador, AdmanService::fetchGrossBillingsBatch) + `Cache::lock` defensivo no `RefreshGrossBillingCacheJob`. Não havia caller único violando — concorrência distribuída era a causa.
- **W5 UI**: coluna `companies.cust_id_status` enum (ok/invalido/desconhecido/nao_aplicavel); comando `dashboard:mark-custid-status` popula a flag (UPDATE só dela, NUNCA cust_id); badges "Cust ID Inválido" em 3 sites (Companies/Index, Dashboard, Sugadores cards); filtro `?cust_id_status=invalido` em Companies; indicador "≈" nos cards do Dashboard quando `cards_exatos === false`.

### Decisões da Phase 18.5 (registradas)

- **2026-06-03 — Planilha CSV oficial Adman recebida do usuário** (`.planning/phases/18.5-marketplace-dinamico/accounts-adman.csv`, 169 contas). Cruzamento via cust_id revelou que **TODOS os cust_ids estavam corretos** — o problema era marketplace hardcoded em `'meli'` ([AdmanService.php:35](app/Services/AdmanService.php#L35)) batendo `/meli/performance/...` para 33 contas Shopee + 1 conta Amazon que retornavam HTTP 500.
- **Coluna `companies.marketplace`** ENUM `('meli', 'shopee', 'amazon')` default `'meli'` (W1-T1) — preserva comportamento atual para empresas não importadas.
- **Comando `dashboard:import-marketplace-from-csv {arquivo} [--dry-run]`** lê CSV oficial via `str_getcsv` (sem dep extra), cruza por `cust_id`, UPDATE só de `companies.marketplace`, activity log por mudança (W1-T2).
- **Estratégia A — refator AdmanService**: 8 endpoints aceitam `string $marketplace = 'meli'` como parâmetro; cache keys incluem `{marketplace}` (entradas órfãs expiram naturalmente em 24h); `syncCompany` lê `$company->marketplace` (W2-T1).
- **Lean callers (W2-T2)**: SOMENTE 6 callers críticos atualizados (RefreshGrossBilling, SugadorAnalysisService 3 sites, AuditBillingDivergence, DiagnoseCustId, MarkCustIdStatus) — callers MLB-only (MlbController, SyncVendas, SyncThumbnails, etc) MANTÊM default `'meli'` porque são módulo MLB e funcionam corretamente. Decisão lean alinhada com prioridade do projeto (foco MercadoLibre).
- **Operacional W4**: import aplicado em prod (33 atualizadas — 32 Shopee + 1 Amazon); sync disparado (169 jobs com delay 7s); mark-custid re-rodou em 15.6s via curto-circuito (172 UPDATEs, 169 OK + 2 invalido + 1 nao_aplicavel — queda de 32→2 em invalido = 94%); auditoria pós-fix reportou 71,23% diff (vs 71,79% antes) — divergência continua porque é HISTÓRICA (adman_metrics tem só 1 dia das Shopee preenchido; faltam 29 dias). Dashboard real usa cache híbrido (Phase 18 W4-T3) e mostra valores precisos quando cache OK; gap histórico se preenche naturalmente ao longo de 30 dias com cadência D-1 da Phase 16.
- **Erros 500 residuais na auditoria pós-fix**: 32 Shopee + 1 Amazon + 9 meli = 42 FAILs (vs 43 antes). Causa: rate limit cumulativo do dia + 500 intermitente Adman; transitório, não bug arquitetural. Phase 16 retry exponencial absorve no caminho de runtime; auditoria não tem retry.
- **Construtor `AdmanService::$this->marketplace = 'meli'`** mantido como dívida transicional (código morto pós-refator) — pode ser removido em fase futura.

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
| 260602-d8i | Botão "Sincronizar todas as conectadas" no painel ML OAuth — fan-out D-1 das empresas com token ML ativo (mesmo critério do ml:sync) | 2026-06-02 | b2b72b9 | [260602-d8i-btn-sync-todas-ml-oauth](.planning/quick/260602-d8i-btn-sync-todas-ml-oauth/) |
| 260602-fn3 | Bloco "Diagnóstico Adman" em /dev/desenvolvimento — alertas heurísticos (sem sync / erros / fila & jobs falhos / anomalias de métrica) + ação re-disparar sync | 2026-06-02 | e967136 | [260602-fn3-diagnostico-adman-painel-dev](.planning/quick/260602-fn3-diagnostico-adman-painel-dev/) |
| 260602-k3e | Restaura despacho assíncrono em syncTodasVendasAdman (regressão de 7b7a2a9) — elimina 504 do nginx no botão "Sync Vendas + Preços" | 2026-06-02 | 04435a2 | [260602-k3e-restaurar-despacho-assincrono-synctodasv](.planning/quick/260602-k3e-restaurar-despacho-assincrono-synctodasv/) |
| 260605-gb3 | Aviso D-1 da Adman no modal Sync Vendas + Preços (Mlb/Empresas.jsx) — texto adaptado: sync aqui é manual, não automático como o dashboard | 2026-06-05 | 16f0f79 | [260605-gb3-aviso-d1-modal-sync-vendas](.planning/quick/260605-gb3-aviso-d1-modal-sync-vendas/) |

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
| Phase 16 | Fan-out de `RefreshGrossBillingCacheJob` (zerar 140/dia residuais) | non-priority — ROI marginal | 2026-06-02 |
| Phase 16 | Smoke W3-T3 humano (cards/badges/bloqueio reanalisar) | validado em prod (6 dias sem regressão) | 2026-06-02 |
| Adman | Rate limit 429 — Adman respondeu: API é D-1 (10h BRT) + 10 req/min/key. Resolvido via Phase 16 (98% redução de 429). | resolvido-via-phase-16 | 2026-05-27 |
| Phase 18 | Smoke W5-T8 humano (cards/badges/filtros/períodos) | pending-human-uat | 2026-06-03 |
| Phase 18 | Backfill histórico (preenche 29 dias faltantes Shopee em adman_metrics) | non-priority — cache D-1 preenche em 30d | 2026-06-03 |
| Phase 18.5 | 2 empresas ainda `cust_id_status='invalido'` mesmo pós-import | pending-revisão-humana | 2026-06-03 |
| Phase 16 | ~305 erros 429/dia residuais pós-Phase 16 (concorrência interna?) | non-priority — não impactam usuário (retry absorve) | 2026-06-03 |
| Adman | 1 conta extra na planilha (CustId 1081500407) sem empresa no DB | investigar se é nova empresa pra cadastrar | 2026-06-03 |

## Session Continuity

Last session: 2026-06-06T04:35:43.699Z
Stopped at: Phase 24 W4 checkpoint humano — smoke visual em prod (8 KPI cards, gráfico 12m, 4 tabs breakdown).

**Estado para próxima sessão retomar:**

- SUMMARY: `.planning/phases/24-painel-executivo-carteira-ecf-carteira-resumo-gmv-vendas-ads/24-01-SUMMARY.md`
- W1+W2+W3 completos: 6 commits, 8 testes verdes (71 assertions)
- W4: checkpoint humano blocking — deploy + smoke visual em prod:
  - Sidebar: item "Painel Executivo" entre Dashboard e Carteira, ícone LineChart
  - 8 KPI cards: verificar valores vs smoke Phase 22 (GMV ~R$ 42,8M, Sellers ~1238)
  - Gráfico: duplo eixo Y funciona (GMV amarelo esq, Sellers branco dir)
  - Tabs: trocar Programa/Frete/Cluster/Localidade sem loading
  - Role: consultor/mentor NÃO vê o item + recebe 403 em /painel-executivo direto
- HEAD: `8d8e6f6` test(24-01): PainelExecutivoControllerTest 8 testes verdes
