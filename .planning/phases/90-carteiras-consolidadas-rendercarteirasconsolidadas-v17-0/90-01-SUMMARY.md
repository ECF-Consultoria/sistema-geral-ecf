---
phase: 90-carteiras-consolidadas-rendercarteirasconsolidadas-v17-0
plan: 01
subsystem: backend-carteira
tags: [laravel, controller-refactor, carteira, multi-servico, tdd, cart-06, cart-07]
requires:
  - CarteiraContextService (Fase 88)
  - Refatoração renderCarteiraProfissional (Fase 89, CART-01..05)
provides:
  - renderCarteirasConsolidadas() sem herança financeira entre profissionais (CART-06)
  - Filtro ?contexto= (todos/performance/shopee) nas duas telas de carteira (CART-07 backend)
affects:
  - resources/js/Pages/Portfolio/Carteiras.jsx (consome companies_count, empresas_unicas, vinculos_servico, totais, contexto — UI ainda não atualizada, Plan 90-02)
  - resources/js/Pages/Portfolio/AdminCarteira.jsx (consome vinculos_servico, vinculos_sem_fonte_financeira, contexto — UI ainda não atualizada)
tech-stack:
  added: []
  patterns:
    - "Dedup financeiro por company_id único (não por vínculo) — mesma receita CART-04/05 da Fase 89, replicada no map() de cards"
    - "Filtro de contexto com whitelist explícita (ASVS V5) em helper privado dedicado, nunca repassando input cru ao service"
key-files:
  created:
    - tests/Feature/V16/CarteirasConsolidadasContextoTest.php
  modified:
    - app/Http/Controllers/PortfolioController.php
decisions:
  - "companies_count mantido como alias temporário de empresas_unicas — remoção fica pro Plan 90-02, junto da troca do .jsx (evita quebrar Carteiras.jsx:82 num commit backend-only)"
  - "Card 100% fora do contexto filtrado DESAPARECE da grid (nunca zera) — mesmo comportamento do ->filter() já existente"
  - "Totais do topo usam UNIÃO de company_id de todos os vínculos exibidos, nunca soma de empresas_unicas entre cards"
  - "renderPortfolio() (auto-visualização legada admin) permanece INTOCADO — débito técnico documentado desde a Fase 89, reafirmado aqui"
metrics:
  duration: ~35min
  completed: 2026-07-16
---

# Phase 90 Plan 01: Carteiras consolidadas via CarteiraContextService + filtro de contexto Summary

Refatoração de `renderCarteirasConsolidadas()` (visão admin de cards por profissional) pra resolver vínculos via `CarteiraContextService::forUser()` — mesma receita de dedup financeiro que a Fase 89 já provou em `renderCarteiraProfissional()` — eliminando a dupla contagem em que Felipe (estrategista Shopee) "herdava" o faturamento ML de empresas geridas por outro profissional. Adicionado também o filtro `?contexto=` (todos/performance/shopee) funcional nas DUAS telas de carteira (backend).

## O que foi feito

- **Task 1 (RED):** `tests/Feature/V16/CarteirasConsolidadasContextoTest.php` — 10 testes cobrindo o cenário-chave travado (Luiz/Felipe compartilhando empresa X), dedup ML+Shopee na mesma empresa, regressão do analista ML comum, filtro de contexto nas duas telas, união de `company_id` nos totais do topo, e `source_counts` gated por elegibilidade. Todos falharam por asserção (payload atual não tinha `empresas_unicas`/`totais`/`contexto`) — RED confirmado antes de qualquer mudança no controller.
- **Task 2 (GREEN):**
  - Novo helper privado `contextoFiltro(Request $request): array` — whitelist explícita `todos|performance|shopee`, valor fora da whitelist cai em `todos` (nunca repassado cru ao service).
  - `renderCarteirasConsolidadas()`: dentro do `map()` por profissional, troca `estrategistaCompanies()/consultorCompanies()` por `CarteiraContextService::forUser($u, ['role' => ..., 'active' => true, 'setor' => $contextoFiltro['setor']])`. Dedup financeiro via `$vinculos->where('financial_metrics_eligible', true)->pluck('company_id')->unique()` — `AdmanMetric` só é consultado para empresas elegíveis, nunca por-vínculo.
  - Contadores por card (`empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira`) via `CarteiraContextService::contadores()`, sem reinventar lógica.
  - `source_counts` (flag `unified_metrics_enabled`, Phase 61) movido pra DENTRO do map principal, reaproveitando `$companyIdsElegiveis` — eliminado o segundo `map()` que recomputava as relações antigas sem filtro de elegibilidade.
  - Totais agregados do topo (`totais.empresas_unicas`, `totais.vinculos_servico`) acumulados via referência (`&$vinculosExibidosTotal`) fora do map — união real de `company_id`, nunca soma ingênua entre cards.
  - `renderCarteiraProfissional()`: mesma origem agora aceita `'setor' => $contextoFiltro['setor']`; `resumo` ganha `vinculos_servico`/`vinculos_sem_fonte_financeira`; prop `contexto` adicionada.
  - `companies_count` mantido como alias temporário de `empresas_unicas` (Plan 90-02 remove junto da troca do `.jsx`).

## Regressão

| Suite | Resultado |
|---|---|
| `--filter=CarteirasConsolidadasContextoTest` | 10/10 verde (54 assertions) |
| `--filter=V16` (completo) | 145/145 verde (635 assertions) — inclui os 10 novos + os 5 da Fase 89 (`CarteiraFinanceiroElegibilidadeTest`) |
| `--filter=Nps` | 241/241 verde (1444 assertions) |
| `tests/Feature/Portfolio/` (diretório, escopo literal pedido) | 7/7 verde — `RenderPortfolioTest` intocado |

## Regressão conhecida (fora da fronteira desta plan — não corrigida)

`php artisan test --filter=Portfolio` (filtro por substring, não o diretório) também casa com `tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php` e `PortfolioSourceEnrichmentTest.php`. 3 dos testes desses arquivos falham:

- `PortfolioMultiFonteE2ETest::test_flag_on_portfolio_carteiras_admin_expoe_source_counts_por_user`
- `PortfolioMultiFonteE2ETest::test_flag_off_portfolio_carteiras_admin_nao_expoe_source_counts`
- `PortfolioSourceEnrichmentTest::test_flag_on_portfolio_own_admin_enriquece_user_portfolios_com_source_counts`

**Causa raiz:** essas fixtures (anteriores à Fase 88) anexam empresa à carteira via `$user->companies()->attach($company->id, ['role' => 'consultor', 'assigned_at' => now()])` — pivot sem `servico_id` e SEM criar `contratos_servico` ativo. Sob `CarteiraContextService::forUser()`, o ramo legado (`servico_id NULL`) só resolve como Performance SE a empresa tiver contrato performance ativo (decisão CTX-05, Fase 88) — comportamento correto e intencional, já em vigor em `renderCarteiraProfissional()` desde a Fase 89. Como essas fixtures nunca criam o contrato, o vínculo não resolve, o card desaparece de `user_portfolios`, e as asserções que esperavam o card (`has('user_portfolios', 1, ...)`) falham.

**Por que não foi corrigido nesta plan:** a fronteira explícita desta execução restringe as mudanças a `PortfolioController.php` + o arquivo de teste novo (`CarteirasConsolidadasContextoTest.php`) — não inclui `tests/Feature/Phase61/*`. O comando de regressão pedido explicitamente (`tests/Feature/Portfolio/`, diretório) passa limpo 7/7; essas 3 falhas só aparecem com um filtro por substring mais amplo do que o escopo pedido.

**Recomendação:** follow-up (fora desta plan) adaptar o `setUp()`/fixtures de `PortfolioMultiFonteE2ETest` e `PortfolioSourceEnrichmentTest` pra criar um contrato performance ativo antes de anexar a empresa (mesmo padrão de `CriaCenarioResponsaveis::criarCenarioMlComResponsaveis()`), alinhando essas suítes de Phase 61 com a arquitetura multi-serviço em vigor desde a Fase 88.

## `renderPortfolio()` — gap reafirmado

`renderPortfolio()` (auto-visualização legada, `/admin/users/{user}/portfolio` quando `$atual->id === $user->id`) permanece **intocado** nesta fase — mesmo bug de pivot/distinct já diagnosticado na Fase 89 (Pitfall 2), documentado como débito técnico aceito (baixo risco de exposição: só executa quando admin/líder acessa a PRÓPRIA carteira via rota admin, fluxo incomum). `tests/Feature/Portfolio/RenderPortfolioTest.php` 7/7 verde confirma zero regressão nessa função.

## Deviations from Plan

None (além do item de regressão conhecida documentado acima, que é explicitamente fora da fronteira e não uma mudança de comportamento incorreta — é o comportamento CORRETO da nova arquitetura CTX-05 colidindo com fixtures desatualizadas de outra fase).

## Known Stubs

Nenhum. `companies_count` é um alias INTENCIONAL e documentado (não um stub) — Plan 90-02 remove no mesmo commit que atualiza `Carteiras.jsx`.

## Threat Flags

Nenhum — escopo desta plan é exatamente o mapeado no `<threat_model>` do plano (T-90-01, T-90-02, T-90-03 já cobertos pelas mitigações descritas).

## Self-Check: PASSED

- `tests/Feature/V16/CarteirasConsolidadasContextoTest.php` — FOUND
- `app/Http/Controllers/PortfolioController.php` — FOUND (modificado, `contextoFiltro` presente)
- Commit `test(90-01)`: `8a78b77` — FOUND em `git log`
- Commit `feat(90-01)`: `63cf47c` — FOUND em `git log`
