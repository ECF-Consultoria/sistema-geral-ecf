# Phase 79: NPS multi-modelo — disparo por serviços cobertos + snapshot de atribuições por serviço (v16.0) - Context

**Gathered:** 2026-07-14
**Status:** Ready for planning
**Source:** `.planning/milestones/v16.0-brief.md` (DEC-B) + `prompt-ajustes-shopee-nps-v2.md` + decisões do usuário (AskUserQuestion) + mapa do NPS v15.

<domain>
## Phase Boundary

Fazer o NPS operar **multi-modelo por serviços cobertos**: uma empresa com serviços em áreas diferentes (ML + Shopee) recebe **um NPS por modelo/área**, e cada resposta atribui as médias **só aos responsáveis dos serviços cobertos por aquele modelo**. Congelar tudo em snapshot (histórico imutável). **NÃO** reescrever o bônus (Fase 80) — só deixar as atribuições prontas.

**IN SCOPE:**
1. Tabelas novas: `nps_response_scores` (médias por dimensão), `nps_response_covered_services` (snapshot dos serviços cobertos pelo modelo no momento da resposta), `nps_score_assignments` (média × user × role × service_type, congelado).
2. **Submit** (`NpsController::submitResponseV15`): além de gravar `nps_response_answers`, calcular as médias por dimensão (`NpsScoreCalculator`), congelar os serviços cobertos do modelo, e gravar as atribuições aos responsáveis dos serviços cobertos ∩ ativos.
3. **Disparo** (`NpsDispararMensal`): de "só o principal pra todos" → **1 envio por modelo com envio automático cujos serviços cobertos batem com um serviço ATIVO da empresa** (DEC-79-A estrito).
4. **Modelos**: "NPS Padrão" (is_default) passa a cobrir os serviços de **Performance/ML**; criar via seed o modelo **"NPS Shopee"** cobrindo o serviço **Shopee** (DEC-79-B).

**OUT (Fase 80):** reescrever `DesempenhoScoreService` para ler das atribuições; relatórios por serviço/papel/pessoa; aposentar "só o principal conta". Nesta fase o bônus continua lendo `->principal()` (inalterado).
</domain>

<decisions>
## Implementation Decisions

### DEC-79-A — Disparo ESTRITO por serviços cobertos (escolha do usuário)
- Para cada empresa × cada modelo com `envio_automatico_mensal=true`: gerar 1 envio SE a empresa tem contrato ATIVO de um serviço que está em `nps_template_service_scopes` do modelo. Sem serviço coberto por nenhum modelo → **NENHUM NPS** (sem fallback).
- **Blindagem de rollout:** logar (`Log::warning` estruturado) as empresas que HOJE receberiam o principal mas ficariam SEM NPS no novo modo (nenhum serviço coberto) — visibilidade antes de o comportamento mudar. Preservar os guards atuais (active + canal de contato + estrategista).
- **NPS Padrão** deve ter como serviços cobertos os serviços de **setor performance** (senão empresas ML param de receber) — configurar via seed (linkar todos os serviços ativos setor=performance ao template principal em `nps_template_service_scopes`).

### DEC-79-B — Seed do modelo "NPS Shopee" (versionado)
- Migration/seed idempotente cria o template **"NPS Shopee"** (`is_default=false`, `active=true`, `envio_automatico_mensal=true`, `priority` > 0 p/ preceder o fallback se necessário) espelhando o "NPS Padrão": 3 perguntas (dimensão estrategista obrigatória, analista, empresa) tipo escala + 5 opções (peso 1..5) cada. E um `nps_template_service_scopes` linkando o template ao **serviço Shopee** (setor shopee). Espelhar o padrão do seed do NPS Padrão (migration Phase 68 `2026_07_07_100004`).

### DEC-79-C — Tabelas de snapshot (adaptar ao schema v15 existente)
- `nps_response_scores`: (`id`, `nps_response_id` FK, `company_id`, `dimensao` [estrategista/analista/empresa], `score_sum`, `question_count`, `average_score`, `calculated_at`). Uma linha por dimensão calculada.
- `nps_response_covered_services`: (`id`, `nps_response_id` FK, `servico_id` FK, `service_setor` snapshot, `captured_at`). Snapshot dos serviços cobertos pelo modelo no momento da resposta.
- `nps_score_assignments`: (`id`, `nps_response_id` FK, `nps_response_score_id` FK, `company_id`, `servico_id`, `service_setor`, `role` [analyst/strategist → consultor/estrategista], `user_id` FK, `average_score`, `assigned_at`). Congelado — trocar responsável/modelo depois NÃO altera.
- FKs com `nullOnDelete`/cascade conforme padrão; cross-driver (SQLite testes + MySQL prod); índices para leitura por `(user_id, role)` e `(service_setor)`.

### DEC-79-D — Cálculo e atribuição no submit
1. Calcular média por dimensão via `NpsScoreCalculator::compute($response, $dim)` para estrategista/analista/empresa; gravar em `nps_response_scores` (com sum/count).
2. Congelar os serviços cobertos do modelo (`nps_template_service_scopes` do template da survey) em `nps_response_covered_services`.
3. Buscar a interseção: serviços cobertos ∩ serviços ATIVOS da empresa. Para cada serviço dessa interseção:
   - média do bloco **Analista** → o(s) analista(s) (`company_users` role=consultor, `servico_id` = esse serviço) → 1 `nps_score_assignments` (role=analyst).
   - média do bloco **Estrategista** → estrategista(s) (`company_users` role=estrategista, servico_id) → assignment (role=strategist).
   - Responsável faltante → **não criar atribuição vazia; registrar pendência** (log).
4. Média da **Empresa** → fica em `nps_response_scores` (dimensao=empresa) ligada ao company_id (sem assignment de pessoa).
5. Idempotência: no máx 1 resposta por `company_id + ciclo + template_id` (dedup composto já existe — Phase 68-04).

### DEC-79-E — Bônus intocado nesta fase
- NÃO alterar `DesempenhoScoreService`/`->principal()`. NPS Padrão continua `is_default=true` → o bônus atual segue lendo as respostas do principal (performance). As atribuições novas ficam prontas para a Fase 80 consumir. Manter fallback dual-path.

### Claude's Discretion
- Nomes exatos de colunas/tabelas (adaptar ao existente — o brief nota `nps_response_answers` já existe).
- Se o cálculo no submit reusa `NpsScoreCalculator` (sim) e onde extrair o helper de atribuição.
- Mapeamento role: "analyst"↔`consultor`, "strategist"↔`estrategista` (manter a convenção do projeto).
- Como o seed do NPS Padrão linka os serviços performance (todos os ativos setor=performance vs um "guarda-chuva").
</decisions>

<constraints>
## Constraints
- **Testes em `tests/Feature/V16/`**.
- Cross-driver (SQLite testes + MySQL prod) — migrations de tabela nova + FK; validar no VPS (memória [[project_mysql_drop_index_fk]] / [[project_enum_setor_sqlite_check]]).
- Motor NPS v15 vivo — NÃO quebrar o fluxo atual (submit legacy Phase 31 + submit v15 + disparo). Dedup composto (`company_id, month_reference, template_id`) preservado.
- Bônus intocado (Fase 80). Manter `->principal()` funcionando.
- Dev em paralelo (anunciar-ml) — reconciliar antes de deploy; deploy confirmado caso-a-caso.
- pt-BR; sem libs novas.
</constraints>

<canonical_refs>
## Canonical References
- Tabelas v15: `2026_07_07_100001_create_nps_templates_v15_tables.php` (nps_templates, nps_template_questions/options, `nps_template_service_scopes` :204, `nps_response_answers` :217-266 com question_dimensao_snapshot/option_peso_snapshot). `nps_surveys.template_id` (2026_07_07_100002). Dedup composto (2026_07_07_100004 / Plan 68-04).
- Seed do NPS Padrão a espelhar: `2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php` (template + 3 perguntas + 15 opções, DB::table idempotente).
- `app/Services/Nps/NpsScoreCalculator.php:65-113` (compute por dimensão = SUM(option_peso_snapshot)/N perguntas do template naquela dimensão).
- `app/Services/Nps/NpsTemplateService.php:70-112` (resolveForCompany via `nps_template_service_scopes`; priority DESC + is_default fallback).
- `app/Http/Controllers/NpsController.php`: `submitResponseV15 :601-731` (grava NpsResponse + answers — ADICIONAR scores/covered_services/assignments), `respond :449-454`.
- `app/Console/Commands/NpsDispararMensal.php:133-225` (HOJE força principal; MUDAR para iterar modelos × empresas por serviços cobertos; preservar guards :145-203).
- Responsáveis por-serviço (Phase 76): `company_users.servico_id`; `Company::consultorDoServico($id)/estrategistaDoServico($id)`.
- Serviço Shopee (Phase 75 seed) + setor performance (`Servico::SETOR_PERFORMANCE/SETOR_SHOPEE`).
- Bônus (NÃO tocar, contexto Fase 80): `DesempenhoScoreService.php:281-348` (`->principal()`).
</canonical_refs>

<validation>
## Validation Architecture (Nyquist)
Feature tests em `tests/Feature/V16/`:
1. Tabelas novas criadas (schema + FKs) cross-driver.
2. Seed NPS Shopee: template + 3 perguntas + opções + service scope = serviço Shopee; idempotente.
3. Disparo estrito: empresa performance → gera survey do NPS Padrão; empresa shopee → NPS Shopee; empresa performance+shopee → 2 surveys; empresa sem serviço coberto → 0 survey (+ log). Dedup: não duplica por company+mês+template.
4. Submit calcula scores por dimensão (nps_response_scores) + congela covered_services + gera assignments só para responsáveis dos serviços cobertos ∩ ativos.
5. Atribuição por serviço: NPS Shopee → média analista vai pro analista Shopee (servico_id shopee), NÃO pro analista ML; responsável faltante → sem assignment + pendência.
6. Empresa: média empresa fica em scores (dimensao=empresa), sem assignment de pessoa.
7. Regressão: submit legacy Phase 31 + submit v15 atual + bônus `->principal()` inalterados (suite NPS verde).
</validation>

<deferred>
## Deferred (Fase 80)
- Reescrever `DesempenhoScoreService::computeNpsMedio` para ler de `nps_score_assignments` (fallback dual-path + bump de cache).
- Relatórios por serviço/papel/pessoa + dedup.
- Aposentar "só o principal conta".
</deferred>

---
*Phase: 79 — v16.0 (v2)*
