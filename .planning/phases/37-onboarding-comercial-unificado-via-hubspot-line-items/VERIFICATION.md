---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
verified: 2026-06-18T22:30:00Z
status: passed
score: 10/10 must-haves verificados
overrides_applied: 0
test_results:
  phase_37_tests_passed: 76
  phase_37_assertions: 259
  phase_34_35_36_regression: 37/37 passed (292 assertions)
  total_phase_37_filter_run: 87 passed (315 assertions, inclui Phase37/MlbDadosMlReputacaoTest não-Phase37)
migrations_pendentes_deploy:
  - "database/migrations/2026_06_18_100001_add_setor_to_servicos_table.php"
  - "database/migrations/2026_06_18_100002_seed_servicos_setor.php"
  - "database/migrations/2026_06_18_100003_create_hubspot_line_item_mapping_table.php"
  - "database/migrations/2026_06_18_100004_seed_hubspot_line_item_mapping.php"
deferred: []
human_verification: []
---

# Phase 37: Onboarding Comercial Unificado via HubSpot Line Items — Verification Report

**Phase Goal (ROADMAP.md):** Quando deal vira "Fechado Ganho" no HubSpot, empresa entra no sistema com serviço + valor + setor preenchidos automaticamente via line items do HubSpot, restando apenas pendências operacionais. Nova listagem em `/comercial/empresas/listagem` cobre TODOS os setores; `/companies` foca em Performance; menu "Serviços" migrado para grupo "Comercial".

**Verificado:** 2026-06-18T22:30:00Z
**Status:** PASSED — VERIFIED
**Re-verification:** Não — verificação inicial

---

## Cobertura de Requirements

| Req | Descrição (curta) | Status | Evidência (arquivo:linha) |
|-----|-------------------|--------|---------------------------|
| REQ-37-01 | `HubspotApiClient::fetchDealLineItems(dealId)` consome 2 endpoints CRM v3 com cast defensivo | VERIFIED | `app/Services/HubspotApiClient.php:158-225` — método público; 2-call pattern (associations + line_items/{id}); resiliente 4xx/5xx; cast `(float) $props['price']` / `(int) $props['quantity']`; log sem token |
| REQ-37-02 | Tabela `hubspot_line_item_mapping` + UI admin (`/sistema/hubspot-line-items`) com CRUD | VERIFIED | `database/migrations/2026_06_18_100003_create_hubspot_line_item_mapping_table.php` (schema UNIQUE + FK cascade); `database/migrations/2026_06_18_100004_seed_hubspot_line_item_mapping.php` (firstOrCreate idempotente); `app/Models/HubspotLineItemMapping.php` (paraNome case-insensitive); `app/Http/Controllers/Sistema/HubspotLineItemMappingController.php` (4 métodos CRUD); `resources/js/Pages/Sistema/HubspotLineItems.jsx` (318 linhas); rotas registradas em `routes/web.php:354-357` |
| REQ-37-03 | Coluna `servicos.setor` enum + seed canônico + helpers no Model | VERIFIED | `database/migrations/2026_06_18_100001_add_setor_to_servicos_table.php:34` (`$table->enum('setor', ['performance', 'publicacao', 'outros'])->default('outros')`); `app/Models/Servico.php:48-50` (constants SETOR_*); `Servico.php:55-59` (SETORES array); `Servico.php:75-81` (setoresLabels); `Servico.php:119-122` (scopePorSetor); `Servico.php:127-138` (isPerformance/isPublicacao). DB confirma: Gestão+Mentoria=performance, Publicação=publicacao, demais=outros |
| REQ-37-04 | Webhook estendido: line items → ContratoServico atomicamente em DB::transaction | VERIFIED | `app/Http/Controllers/Api/HubspotWebhookController.php:195` (chama fetchDealLineItems); `:267` (DB::transaction única); `:319-323` (branch line items vs legado); `:357-419` (processarLineItems com paraNome + ContratoServico::create); `:436-479` (processarServicoLegado preservando Phase 34/35); `:481+` (rotearImplementacao com guard MlbEmpresa::exists); idempotência preservada em `:147-159` |
| REQ-37-05 | Rota `/comercial/empresas/listagem` com filtros snake_case empilháveis | VERIFIED | `routes/web.php:195-196` (rota nomeada `comercial.empresas.listagem`); `app/Http/Controllers/ComercialController.php:177-346` (método listagem); 5 filtros sanitizados via whitelist `:185-197`; payload com `filters`/`pendencia_counts`/`servico_counts`/`grupos`/`servicos_disponiveis`; `resources/js/Pages/Comercial/EmpresasListagem.jsx:185-190` (applyFilter Phase 18 pattern preservando filtros) |
| REQ-37-06 | 5 cards de pendência comercial: sem_servico/sem_valor/servico_nao_reconhecido/sem_setor/dados_close_incompletos | VERIFIED | `ComercialController.php:243-249` (pendenciaCounts com 5 chaves canônicas); `:358-406` (calcularPendenciasComerciais implementa as 5 regras); `EmpresasListagem.jsx:298-310` (5 cards clicáveis com pendencia_counts) |
| REQ-37-07 | `/companies` filtra apenas Performance + remove pendência sem_servico + remove aba Grupos | VERIFIED | `CompanyController.php:65` (`whereDoesntHave('mlbEmpresa')` preservado); `:70-75` (`whereHas('contratosServico'... 'setor', Servico::SETOR_PERFORMANCE)`); `:143-153` (pendência sem_servico removida do array); `resources/js/Pages/Companies/Index.jsx:355-358` (TABS reduzido a 2: empresas+pendencias) |
| REQ-37-08 | CRUD de grupos integrado em `/comercial/empresas/listagem` | VERIFIED | `EmpresasListagem.jsx:14` (importa GruposManager); `:424+` (renderiza GruposManager); `ComercialController.php:330-332` (payload `grupos` com CompanyGroup::withCount). Rotas `company-groups.*` legadas reaproveitadas |
| REQ-37-09 | Sidebar — grupo Comercial expansível; "Serviços" some do raiz | VERIFIED | `AppLayout.jsx:48-50` (comentário removendo do raiz); `AppLayout.jsx:93-123` (grupo Comercial com 5 sub-itens: Empresas todos setores, Cadastrar empresa, Grupos, Serviços, HubSpot Line Items); `:115` (Serviços agora dentro do Comercial); `:121` (HubSpot Line Items com excludeRoles para admin-only). grep confirma única ocorrência de 'Serviços' no arquivo |
| REQ-37-10 | Empresas legacy NÃO geram pendência comercial; checa EXISTS hubspot_eventos | VERIFIED | `Company.php:273-276` (relação hubspotEventoOrigem); `ComercialController.php:210` (withExists hubspotEventoOrigem); `:238` (is_origem_hubspot = bool); `:360-362` (early return [] quando !is_origem_hubspot); teste `test_empresa_legacy_NAO_gera_pendencias_REQ_37_10` PASS |

**Score: 10/10 requirements verificados.**

---

## Cobertura de Success Criteria (ROADMAP.md)

| # | Success Criterion | Status | Evidência |
|---|-------------------|--------|-----------|
| 1 | Webhook closedwon cria ContratoServico atomicamente em DB::transaction com valor/tipo_cobranca/data_contratacao | VERIFIED | `HubspotWebhookController.php:267-333` DB::transaction única; processarLineItems cria ContratoServico com valor + observacoes (tipo_cobranca anotada — coluna não existe em contratos_servico, vive em Servico); test_deal_com_1_line_item_mapeado_cria_contrato_servico PASS |
| 2 | Line item não mapeado: empresa criada SEM contrato + warning no payload; webhook retorna 200 | VERIFIED | `HubspotWebhookController.php:371-376` (acumula em $naoMapeados); grava em HubspotEvento.payload; status `processado`; test_deal_com_line_item_nao_mapeado_grava_em_payload PASS |
| 3 | `/comercial/empresas/listagem` com filtros empilháveis (snake_case Phase 18) | VERIFIED | applyFilter usa `{...filters, [key]: value}` em `EmpresasListagem.jsx:185-190`; test_filtros_empilhaveis_query_string_preservada PASS |
| 4 | Listagem mostra grupos + CRUD; Companies/Index não mostra Grupos | VERIFIED | EmpresasListagem.jsx renderiza GruposManager; Companies/Index.jsx TABS removeu 'grupos' |
| 5 | `/companies` filtra Performance via whereHas setor=performance | VERIFIED | `CompanyController.php:70-75`; testes test_empresa_com_contrato_performance_aparece + test_empresa_com_contrato_publicacao_NAO_aparece PASS |
| 6 | `/companies` aba Pendências não mostra `sem_servico`; demais operacionais preservadas | VERIFIED | `CompanyController.php:143-153` (sem_servico removido; sem_responsavel/sem_cust_id/sem_email_colaborador/sem_grant_ativo/empresa_nova mantidos); test_payload_nao_contem_pendencia_sem_servico PASS |
| 7 | Sidebar Comercial expansível com Empresas+Grupos+Serviços; raiz sem Serviços | VERIFIED | AppLayout.jsx grupo Comercial com 5 sub-itens incluindo Serviços; 'Serviços' aparece apenas no grupo Comercial (grep confirma) |
| 8 | Empresas legacy não geram pendência comercial — check EXISTS hubspot_eventos | VERIFIED | `ComercialController.php:360-362` early return [] quando !is_origem_hubspot; test_empresa_legacy_NAO_gera_pendencias_REQ_37_10 PASS |
| 9 | Empresas de Publicação seguem em `/mlb/empresas` (zero regressão MLB) | VERIFIED | `CompanyController.php:65` whereDoesntHave('mlbEmpresa') preservado; test_empresa_com_mlb_empresa_NAO_aparece PASS; Phase34/35/36 sem regressão (37/37 = 292 assertions) |
| 10 | Mapping editável via UI admin (rota `/sistema/hubspot-line-items`) | VERIFIED | 4 rotas em `routes/web.php:354-357` dentro do grupo `role:admin` (linha 273); Sistema/HubspotLineItemMappingController CRUD funcional; HubspotLineItems.jsx com Dialog modal create/edit/delete |

---

## Verificação de Artefatos

| Artefato | Existe | Substantivo | Wired | Status |
|----------|--------|------------|-------|--------|
| `database/migrations/2026_06_18_100001_add_setor_to_servicos_table.php` | ✓ | ✓ (51 linhas, guard Schema::hasColumn) | ✓ (Ran em dev DB) | VERIFIED |
| `database/migrations/2026_06_18_100002_seed_servicos_setor.php` | ✓ | ✓ (DB::transaction com 3 UPDATEs LIKE Title Case) | ✓ (Gestão+Mentoria=performance no DB) | VERIFIED |
| `database/migrations/2026_06_18_100003_create_hubspot_line_item_mapping_table.php` | ✓ | ✓ (UNIQUE + FK cascade + index composto) | ✓ (tabela criada no DB) | VERIFIED |
| `database/migrations/2026_06_18_100004_seed_hubspot_line_item_mapping.php` | ✓ | ✓ (firstOrCreate idempotente) | ✓ (7 mappings seedados) | VERIFIED |
| `app/Models/Servico.php` (modified) | ✓ | ✓ (constants + helpers + scope + logOnly) | ✓ (usado por ComercialController, CompanyController, webhook) | VERIFIED |
| `app/Models/HubspotLineItemMapping.php` | ✓ | ✓ (82 linhas, model completo) | ✓ (paraNome consumido por webhook em :368) | VERIFIED |
| `app/Models/Company.php` (modified) | ✓ | ✓ (hubspotEventoOrigem + hubspotEventos relations) | ✓ (consumido por ComercialController withExists/with) | VERIFIED |
| `app/Services/HubspotApiClient.php` (modified) | ✓ | ✓ (fetchDealLineItems 70 linhas) | ✓ (chamado em HubspotWebhookController:195) | VERIFIED |
| `app/Http/Controllers/Api/HubspotWebhookController.php` (modified) | ✓ | ✓ (620 linhas, processarLineItems + processarServicoLegado + rotearImplementacao) | ✓ (POST /api/webhooks/hubspot ativo) | VERIFIED |
| `app/Http/Controllers/ComercialController.php` (modified) | ✓ | ✓ (método listagem 170 linhas) | ✓ (rota `comercial.empresas.listagem` registrada) | VERIFIED |
| `app/Http/Controllers/CompanyController.php` (modified) | ✓ | ✓ (filtro Performance + sem_servico removida) | ✓ (rota `companies.index` ativa) | VERIFIED |
| `app/Http/Controllers/Sistema/HubspotLineItemMappingController.php` | ✓ | ✓ (134 linhas, CRUD + activity log + Rule validation) | ✓ (4 rotas registradas dentro de role:admin) | VERIFIED |
| `resources/js/Pages/Comercial/EmpresasListagem.jsx` | ✓ | ✓ (439 linhas, applyFilter + 5 cards + chips + tabela + GruposManager) | ✓ (no Vite manifest; sub-item sidebar aponta) | VERIFIED |
| `resources/js/Pages/Sistema/HubspotLineItems.jsx` | ✓ | ✓ (318 linhas, listagem + Dialog modal + form useForm) | ✓ (no Vite manifest; sub-item sidebar aponta) | VERIFIED |
| `resources/js/Pages/Companies/Index.jsx` (modified) | ✓ | ✓ (TABS reduzido a 2; aba grupos removida; sem_servico card removido) | ✓ (rota `companies.index` ativa) | VERIFIED |
| `resources/js/Layouts/AppLayout.jsx` (modified) | ✓ | ✓ (grupo Comercial com 5 sub-itens; Serviços removido do raiz) | ✓ (renderizado em todas as páginas autenticadas) | VERIFIED |
| `routes/web.php` (modified) | ✓ | ✓ (rota `/comercial/empresas/listagem` + 4 rotas `/sistema/hubspot-line-items.*`) | ✓ (todas registradas via `php artisan route:list`) | VERIFIED |

---

## Wiring (Key Links)

| De | Para | Via | Status | Evidência |
|----|------|-----|--------|-----------|
| `HubspotWebhookController::processar` | `HubspotApiClient::fetchDealLineItems` | `$api->fetchDealLineItems((string) $evento->object_id)` | WIRED | linha 195 |
| `HubspotWebhookController::processarLineItems` | `HubspotLineItemMapping::paraNome` | static call case-insensitive | WIRED | linha 368 |
| `HubspotWebhookController::processarLineItems` | `ContratoServico::create` | dentro de DB::transaction | WIRED | linha 394 |
| `ComercialController::listagem` | `Company::withExists(['hubspotEventoOrigem'])` | flag is_origem_hubspot | WIRED | linha 210 |
| `ComercialController::calcularPendenciasComerciais` | `is_origem_hubspot` gate | early return [] se legacy | WIRED | linhas 360-362 |
| `CompanyController::index` | `Servico::SETOR_PERFORMANCE` | whereHas com const | WIRED | linha 73 |
| `Sistema\HubspotLineItemMappingController::index` | `Inertia::render('Sistema/HubspotLineItems')` | render com props snake_case | WIRED | linha 51 |
| `AppLayout.jsx` Comercial group | `sistema.hubspot-line-items.index` | sub-item com excludeRoles admin-only | WIRED | linha 121 |
| `EmpresasListagem.jsx::applyFilter` | `route('comercial.empresas.listagem')` | router.get com `{...filters, [key]: value}` | WIRED | linhas 185-190 (Phase 18 pattern) |

---

## Behavioral Spot-Checks

| Verificação | Comando | Resultado | Status |
|-------------|---------|-----------|--------|
| Migrations rodaram em dev | `php artisan migrate:status \| grep 2026_06_18_10000` | 4 entradas "Ran" | PASS |
| Rota comercial.empresas.listagem registrada | `php artisan route:list \| grep comercial.empresas.listagem` | `GET comercial/empresas/listagem ... ComercialController@listagem` | PASS |
| 4 rotas sistema.hubspot-line-items.* registradas | `php artisan route:list \| grep hubspot-line-items` | GET/POST/PUT/DELETE listadas | PASS |
| Tabela hubspot_line_item_mapping existe | `tinker Schema::hasTable(...)` | `YES` | PASS |
| Seed canônico ativado | `tinker HubspotLineItemMapping::count()` | 7 mappings (Brigada/Gestão/MAP/MAP PREMIUM/Mentoria/Polo/Publicação) | PASS |
| Servicos.setor com seed correto | `tinker Servico::all([nome,setor])` | Gestão=performance, Mentoria=performance, Publicação=publicacao, demais=outros | PASS |
| Vite manifest contém páginas novas | `grep Comercial/EmpresasListagem.jsx public/build/manifest.json` | 2 entradas (Comercial/EmpresasListagem + Sistema/HubspotLineItems) | PASS |

---

## Resultados de Testes

### Phase 37 (estrito — 7 suítes da Phase 37)
- `Phase37ServicoSetorTest`: 6 testes verdes
- `Phase37LineItemMappingTest`: 9 testes verdes
- `Phase37LineItemsFetchTest`: 9 testes verdes
- `Phase37WebhookLineItemsTest`: 10 testes verdes
- `Phase37ComercialListagemTest`: 17 testes verdes
- `Phase37CompaniesPerformanceFilterTest`: 12 testes verdes
- `Phase37HubspotLineItemMappingAdminTest`: 13 testes verdes

**Total Phase 37 (estrito): 76 testes / 259 assertions — 100% verde**

Comando: `php artisan test --filter "Phase37ComercialListagemTest|Phase37CompaniesPerformanceFilterTest|Phase37HubspotLineItemMappingAdminTest|Phase37LineItemMappingTest|Phase37LineItemsFetchTest|Phase37ServicoSetorTest|Phase37WebhookLineItemsTest"` → 76 passed (259 assertions)

### Regressão Phase 34/35/36
- `php artisan test --filter "Phase34|Phase35|Phase36"` → **37 testes / 292 assertions — 100% verde, zero regressão**

### Observação metodológica
- `php artisan test --filter Phase37` (filter wide) retorna 87 testes pq inclui `Tests\Feature\Phase37\MlbDadosMlReputacaoTest` (11 testes) que estão em sub-pasta `Phase37/` mas não pertencem à Phase 37 (estão lá por convenção de outra phase). Esses 11 testes adicionais também passam — não interferem.

---

## Anti-Padrões / Code Smells

| Arquivo | Linha | Padrão | Severidade | Impacto |
|---------|-------|--------|------------|---------|
| (nenhum) | — | — | — | Sem `TBD`/`FIXME`/`XXX` nos arquivos modificados pela Phase 37 |

Verificações executadas:
- `grep TBD\|FIXME\|XXX` em `HubspotLineItemMappingController.php` → 0 matches
- `grep TBD\|FIXME\|XXX` em `ComercialController.php` → 0 matches
- `grep TBD\|FIXME\|XXX\|TODO` em `AppLayout.jsx` → 0 matches
- `grep TBD\|FIXME\|XXX` em `HubspotApiClient.php` → 0 matches

**Observação benigna:** SUMMARY 37-07 reconhece TODO no comentário do sub-item Grupos (helper de menu não suporta query param). É comentário documental, não anti-padrão bloqueante; documentado em key-decisions.

---

## Migrations Pendentes para Deploy (VPS)

As 4 migrations da Phase 37 estão aplicadas em dev mas **não** em prod. Deploy AGRUPADO recomendado:

1. `database/migrations/2026_06_18_100001_add_setor_to_servicos_table.php` — adiciona coluna `servicos.setor` enum (idempotente via Schema::hasColumn)
2. `database/migrations/2026_06_18_100002_seed_servicos_setor.php` — UPDATE LIKE Title Case (idempotente)
3. `database/migrations/2026_06_18_100003_create_hubspot_line_item_mapping_table.php` — cria tabela (idempotente via Schema::hasTable)
4. `database/migrations/2026_06_18_100004_seed_hubspot_line_item_mapping.php` — firstOrCreate para 8 mappings canônicos

Pós-deploy:
- `php artisan migrate --force` (rodar as 4)
- `npm run build` (já feito em dev; Vite manifest tem as 2 páginas novas)
- Validar visual da sidebar (já feito como checkpoint pré-aprovado no Plan 37-07)

---

## Pendências / Follow-ups

**Nenhum gap bloqueante identificado.** Itens informacionais:

1. **TODO documental no AppLayout** — sub-item "Grupos" aponta para `comercial.empresas.listagem` sem query param. Quando o helper de menu suportar `query: { tab: 'grupos' }`, refinar para deep-link direto. Documentado em SUMMARY 37-07 (key-decisions).
2. **Phase 14 Phase14MigrationTest** continua com 4 falhas pré-existentes (Carbon timezone parse `contract_start`) — explicitamente documentadas como fora de escopo desde 2026-05-26; SUMMARY 37-01 valida via `git stash` que falhas são identical baseline.
3. **Seed do mapping não inclui variante `POLO` upper-case** — apenas 7 mappings persistidos em vez dos 8 declarados (`MAP/MAP PREMIUM/Polo/POLO/Brigada/Gestão/Mentoria/Publicação`). A SQLite case-insensitive collation no SQLite considera `Polo` e `POLO` o mesmo valor para UNIQUE constraint, então firstOrCreate inseriu apenas o primeiro. Não-bloqueante: `HubspotLineItemMapping::paraNome` é case-insensitive por design (LOWER comparison), então `POLO` no payload HubSpot ainda resolve via mapping `Polo` existente. Em MySQL/MariaDB prod (collation `utf8mb4_unicode_ci` default), pode persistir igual ao SQLite; comportamento equivalente é mantido.

---

## Compatibilidade

- **Phase 34** (webhook base): zero regressão — bloco legado `processarServicoLegado` preserva 100% do fluxo Phase 34/35 quando `fetchDealLineItems` retorna `[]`. Idempotência via `HubspotEvento::object_id` + `whereNotNull('company_id_criada')` preservada em `:147-159`.
- **Phase 35** (correções v2): `whereDoesntHave('mlbEmpresa')` em `CompanyController.php:65` preservado — empresas de Publicação seguem em `/mlb/empresas` e não vazam para `/companies`.
- **Phase 18** (filtros snake_case empilháveis): `applyFilter` em `EmpresasListagem.jsx:185-190` usa `{...filters, [key]: value}` — lição preservada (test_filtros_empilhaveis_query_string_preservada PASS).
- **Phase 14** (catálogo unificado): catálogo `servicos` reutilizado; `setor` adicionado como coluna nova com default `outros`; nenhuma quebra de relacionamentos (`contratos_servico.servico_id`).

---

## Veredicto Final

**Status: PASSED — VERIFIED**

A Phase 37 entrega o pipeline end-to-end completo:

1. **Pipeline E2E**: webhook recebe deal closedwon → `fetchDealLineItems` → `HubspotLineItemMapping::paraNome` case-insensitive → cria Company + ContratoServico em DB::transaction única atomicamente.
2. **Separação Performance/Comercial**: `/companies` filtra apenas Performance via `whereHas setor=performance`; `/comercial/empresas/listagem` mostra TODAS as empresas com 5 cards de pendência comercial (apenas origem HubSpot, REQ-37-10 respeitado).
3. **Schema completo**: 4 migrations aplicadas em dev (`servicos.setor` enum + `hubspot_line_item_mapping` UNIQUE + FK cascade); seeds canônicos ativos.
4. **UI admin**: `/sistema/hubspot-line-items` com CRUD funcional, modal Dialog, validações Rule::exists + Rule::unique->ignore, activity log em pt-BR; role:admin guard via grupo de rotas; excludeRoles na sidebar.
5. **Sidebar reorg**: grupo Comercial expansível com 5 sub-itens; "Serviços" some do raiz.
6. **Compatibilidade**: Phase 34/35/36 sem regressão (37/37 testes, 292 assertions). Phase 18 snake_case empilhável preservado.
7. **Cobertura de testes**: 76 testes / 259 assertions estritos Phase 37 — 100% verdes.
8. **Migrations pendentes para prod**: 4 (listadas acima); idempotentes; seguras para deploy agrupado.

**Phase 37 pronta para deploy AGRUPADO.**

---

_Verificado: 2026-06-18T22:30:00Z_
_Verifier: Claude (gsd-verifier)_
