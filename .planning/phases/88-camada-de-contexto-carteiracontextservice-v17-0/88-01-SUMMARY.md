---
phase: 88-camada-de-contexto-carteiracontextservice-v17-0
plan: 01
subsystem: backend
tags: [carteira, servico, portfolio, v17.0, fundacao]
requires: []
provides:
  - "App\\Services\\Portfolio\\CarteiraContextService"
affects:
  - "Fases 89-92 (Carteira individual, Carteiras consolidadas, Desempenho unico, UI de Desempenho) — consumidores futuros, nenhum reapontado nesta fase"
tech-stack:
  added: []
  patterns:
    - "Service de leitura puro (stateless, sem cache) — DB::table com joins, seguindo o precedente de Company::consultorDoServico()/estrategistaDoServico()"
    - "Shape de retorno documentado via docblock @return array, sem DTO (convencao ja estabelecida pelo DesempenhoScoreService)"
key-files:
  created:
    - app/Services/Portfolio/CarteiraContextService.php
    - tests/Feature/V16/CarteiraContextServiceTest.php
  modified: []
decisions:
  - "Sem cache — queries locais baratas (268 linhas de pivot em prod), zero HTTP, diferente do DesempenhoScoreService::computeCached()"
  - "Nao chama MetricsProviderFactory — vocabularios distintos (financial_source = setor->fonte constante; o factory decide provider tecnico ML vs Adman)"
  - "CTX-05 (ramos legado servico_id NULL) implementado como DEFESA contra escrita futura sem servico_id, nao como migracao — 0 linhas reais nos ramos legados em prod (medido via VPS)"
  - "Setores polos/publicacao/outros caem no branch default sem fonte financeira ate segunda ordem"
  - "User::companies() permanece intocado como carteira consolidada legada"
metrics:
  duration: "~25min"
  completed: 2026-07-16
---

# Phase 88 Plan 01: Camada de contexto — CarteiraContextService Summary

Novo `App\Services\Portfolio\CarteiraContextService` — fonte única de vínculos carteira×serviço da v17.0, resolvendo setor/papel/elegibilidade financeira POR VÍNCULO (não por empresa consolidada), com suite Feature de 12 testes provando os 4 cenários canônicos + CTX-01..05.

## O que foi construído

`CarteiraContextService::forUser(User $user, array $filters = []): Collection` monta vínculos de duas fontes normalizadas no mesmo shape:

1. **`company_users.servico_id` PREENCHIDO** — prioridade (CTX-05). Join direto em `companies`/`servicos`.
2. **`servico_id NULL`** — resolvido como Performance legado SE a empresa tiver contrato performance ativo (`whereExists` em `contratos_servico`); nunca promovido a Shopee automaticamente. Linhas cujo (company_id, role) já apareça na fonte 1 são excluídas (o preenchido vence).

Cada vínculo carrega `has_financial_source`/`financial_source`/`financial_metrics_eligible` derivados de `servicos.setor` via `match` (cobre Gestão E Mentoria automaticamente, sem hardcode de `servico_id`).

`CarteiraContextService::contadores(Collection $vinculos): array` computa `empresas_unicas` vs `vinculos_servico` (dedup CTX-04) SEM colapsar vínculos — nunca usa `distinct()` como `User::companies()`.

Filtros `setor` (aplicado em memória, pós-resolução) e `role` (idem); `active` default `true`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - bloqueio de verificação] Referência textual literal a `MetricsProviderFactory` no docblock quebrava o grep de verificação**
- **Found during:** Task 3 (gate de regressão)
- **Issue:** O docblock do service explicava por que o service NÃO chama `MetricsProviderFactory` mencionando a classe por nome 3x. A verificação do plano roda `grep -rn "MetricsProviderFactory" app/Services/Portfolio/` esperando 0 resultados (pitfall de vocabulário) — o grep literal capturava as menções de documentação mesmo sem import/uso funcional.
- **Fix:** Reescrita a explicação sem repetir o nome exato da classe (referenciando "o factory de provider técnico de métricas" + apontando para `88-RESEARCH.md`), preservando a explicação para o próximo dev.
- **Files modified:** `app/Services/Portfolio/CarteiraContextService.php`
- **Commit:** `9755c63`

### Estrutura de commits (TDD por task)

Task 1 (4 cenários canônicos + contadores) e Task 2 (ramos CTX-05/Mentoria/inativa/filtros) foram executadas como um único ciclo de design de teste seguido por dois ciclos RED/GREEN distintos, respeitando o limite de cada task:

- `3749832` test(88-01): RED — 5 testes canônicos (classe inexistente, `BindingResolutionException` confirmado)
- `06a68b0` feat(88-01): GREEN — service criado, 5/5 testes canônicos passam
- `b9cc06f` test(88-01): +7 testes de borda (CTX-05a/b/c, CTX-03 Mentoria, empresa inativa, filtro setor, filtro role) — GREEN imediato, pois o design da Task 1 já implementava os dois ramos (preenchido + legado) e a resolução de flags por setor completos. Não é um caso do "fail-fast trap" (teste passando antes de qualquer implementação existir) — a funcionalidade já existia desde o Task 1 GREEN, só faltava cobertura dedicada.
- `9755c63` fix(88-01): correção do docblock (ver acima)

## Verificação / Regressão

- `tests/Feature/V16/CarteiraContextServiceTest.php` — **12/12 verdes, 44 assertions**.
- `tests/Feature/V16/` completo — **117/117 verdes, 519 assertions**.
- `--filter=Desempenho` — **55 passed / 1 falha pré-existente conhecida** (`PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200`, 403≠200 — documentada como não-relacionada nas notas da Fase 80, não tocada por esta fase).
- `--filter=Nps` — **207/207 verdes, 1244 assertions**.
- Fronteira inviolável confirmada: `git diff --stat` de todos os commits desta fase toca exatamente 2 arquivos (`app/Services/Portfolio/CarteiraContextService.php` + `tests/Feature/V16/CarteiraContextServiceTest.php`); `grep -rn "CarteiraContextService" app/Http app/Console resources/js` → 0 resultados; `git diff --quiet app/Models/User.php` → sem diff.
- `grep -rn "MetricsProviderFactory" app/Services/Portfolio/` → 0 resultados (após a correção acima).
- Nenhum arquivo do módulo MLB/Anúncios (dev paralelo) tocado. `npm run build` NÃO executado (nenhum `.jsx` tocado, conforme constraint).

## Known Stubs

Nenhum — service de leitura completo, sem placeholder, sem dado mockado.

## Threat Flags

Nenhuma superfície nova de rede/auth/schema introduzida — service interno de leitura, sem rota HTTP, consumido apenas pelo próprio teste nesta fase. Threat model do plano (T-88-01, T-88-02, T-88-SC) cobre integralmente o escopo entregue.

## Self-Check: PASSED

- `app/Services/Portfolio/CarteiraContextService.php` — FOUND
- `tests/Feature/V16/CarteiraContextServiceTest.php` — FOUND
- Commit `3749832` — FOUND (`git log --oneline --all | grep 3749832`)
- Commit `06a68b0` — FOUND
- Commit `b9cc06f` — FOUND
- Commit `9755c63` — FOUND
