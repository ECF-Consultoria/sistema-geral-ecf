---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 07
subsystem: api
tags: [laravel, inertia, mercadolivre, queue, phase134]

# Dependency graph
requires:
  - phase: 134-02
    provides: "Schema ml_acervo_itens + model MlAcervoItem (constantes ORIGEM_*/MOTIVO_*/SEVERIDADE_*, helpers naoAvaliadoBuyBox()/saudeMlNaoSeAplica())"
  - phase: 134-04
    provides: "SyncMlAcervoCompanyJob (camada barata) — o que atualizarAgora() enfileira"
  - phase: 134-05/134-06
    provides: "config('mlb_acervo.*') — defasagem_horas, rotacao_n, saude_ml_disponivel"
provides:
  - "Rota mlb.anuncios.meus (GET) + mlb.anuncios.meus.atualizar (POST), no mesmo grupo role:admin de routes/mlb_anuncios.php"
  - "MlbAnuncioController::meus() — listagem paginada (50/pág), ordenada por gravidade, triagem agregada, selo de defasagem, tudo lido só do banco (D-05)"
  - "MlbAnuncioController::atualizarAgora() — enfileira SyncMlAcervoCompanyJob e devolve na hora, rejeitando empresa sem MlToken/sem token ativo"
  - "Contrato de props (empresa, sub, subTotais, anuncios, triagem, filtros, defasagem, saudeMlDisponivel, rotacaoN) para o plano 134-08 consumir"
affects: [134-08, 134-09, 134-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Requests de teste com X-Inertia/X-Inertia-Version simulam navegação client-side (JSON puro) — evita depender do manifest Vite de uma página que ainda não existe (mesmo padrão de tests/Feature/Phase58/DashboardShellsBackendTest.php)"
    - "Gate de zero-write escopado ao domínio mercadolibre.com (Http::assertNotSent com closure), não Http::assertNothingSent() bruto — o middleware global HandleInertiaRequests dispara uma chamada não relacionada (signals críticos via EcfDriveService) em toda página Inertia do app"
    - "Triagem em UMA query agregada (selectRaw com SUM/CASE), reaproveitando o mesmo escopo (company_id + busca + status) da listagem, sem o filtro de motivo"
    - "escopoAcervo() como método privado único, chamado 2x (listagem e triagem) — a fronteira por company_id nunca se repete em texto solto"

key-files:
  created:
    - tests/Feature/Phase134/MeusAnunciosTest.php
    - tests/Unit/Phase134/OrdenacaoGravidadeTest.php
  modified:
    - routes/mlb_anuncios.php
    - app/Http/Controllers/MlbAnuncioController.php

key-decisions:
  - "Triagem aplica o MESMO filtro de status da listagem (inclusive o default 'ativos' de D-03) — instrução explícita do plano ('mesma query base... com os mesmos filtros de status e busca'). Consequência assumida: sob o filtro padrão, um item 'pausado' (status=paused) não entra na conta da triagem, porque não entra na query base — para ver o chip 'Pausado' contar, o usuário (ou o teste) precisa status=todos/pausados. Não é bug: é o comportamento literal descrito no plano, e a tela (134-08) pode expor isso via o próprio Select de status."
  - "Gate D-05 nos testes usa Http::assertNotSent(closure sobre mercadolibre.com), não assertNothingSent() — o middleware global HandleInertiaRequests::countSignalsCriticos() (EcfDriveService) chama https://files.ecfconsultoria.com.br em TODA página Inertia do app, inclusive esta. É ruído de uma feature global preexistente, fora do escopo desta fase; o que D-05 trava é especificamente o ML."
  - "subTotais.publicados = contagem total de MlAcervoItem da empresa (sem filtro de status/busca) — mesmo raciocínio de subTotais.rascunhos (contador da sub-aba, não da lista filtrada)."
  - "listing_tier no contrato de props é um alias direto da coluna listing_type_id (gold_special/gold_pro) — nenhuma tradução, a tela decide o rótulo (rotuloTier(), já existente)."

requirements-completed: [D-01, D-02, D-03, D-04, D-05, D-08, D-09, D-11, D-12, D-15, D-18, D-21]

# Metrics
duration: ~20min
completed: 2026-08-10
---

# Phase 134 Plan 07: Rota "Meus Anúncios" — listagem, triagem, ordenação por gravidade e "Atualizar agora" Summary

**`mlb.anuncios.meus` lê exclusivamente do banco (D-05, provado por `Http::assertNotSent` escopado ao domínio do ML), ordena por gravidade em 3 níveis de desempate que funcionam sem a camada cara (D-12), agrega a triagem em uma única query, e o botão "Atualizar agora" só enfileira — nunca coleta em processo.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-10T18:19Z (aprox., logo após o 134-06)
- **Completed:** 2026-08-10T18:39Z
- **Tasks:** 3/3
- **Files modified:** 4 (2 criados, 2 modificados)

## Accomplishments

- `routes/mlb_anuncios.php` ganhou `mlb.anuncios.meus` (GET) e `mlb.anuncios.meus.atualizar` (POST), **dentro do grupo `role:admin` já existente** — nenhum middleware novo, nenhuma reordenação das ~30 actions já em produção (D-15).
- `MlbAnuncioController::meus()` — mesmo topo de `historico()` (`loadMissing('mlToken')` + `abort_unless(..., 404)`), filtros `busca`/`status`/`motivo`/`sub` validados contra listas fechadas (nunca interpolação de querystring em SQL), ordenação `severidade desc → nota_ecf null por último → nota_ecf asc → ml_item_id asc` (D-12), paginação `paginate(50)->withQueryString()`, triagem em um único `selectRaw` agregado (D-09), selo de defasagem que nunca devolve resposta vazia (D-08). **Zero `Http::`/`MercadoLivreService`/`MlCatalogoMetaService` dentro da action** — confirmado por grep.
- `MlbAnuncioController::atualizarAgora()` — molde exato de `ShopeeOAuthController::sync()`: valida token ativo, `SyncMlAcervoCompanyJob::dispatch($company)`, `Log::info`, `back()->with('success', ...)` com a copy literal do UI-SPEC ("Coleta enfileirada — pode levar alguns minutos..."). Nenhuma chamada síncrona ao ML.
- `escopoAcervo()` e `motivosTriagemDef()` extraídos como métodos privados — a fronteira por `company_id` (T-134-01) e a whitelist fechada de motivos vivem em um lugar só, reusados pela listagem e pela triagem.
- `tests/Feature/Phase134/MeusAnunciosTest.php` — 10 testes: item legado aparece (D-01), escopo por empresa na lista e na triagem (T-134-01), busca agrupada não fura escopo, 404 sem token, 403 para consultor (D-15), zero chamada ao ML no GET (D-05), selo de origem nos 3 casos (D-04), degradação graciosa com selo + caso "nunca coletado" (D-08), triagem fecha com a própria conta e o clique filtra (D-09), "Atualizar agora" enfileira/rejeita (T-134-02).
- `tests/Unit/Phase134/OrdenacaoGravidadeTest.php` — 4 testes: crítico > atenção > saudável, pior nota primeiro dentro da mesma severidade, nota nula vai para o fim (não para o topo), ordem estável entre dois requests via tie-break por `ml_item_id`.
- Suíte completa da fase (`--filter=Phase134`): **51 testes, 337 assertions, verde**.

## Gates provados manualmente (quebrar → falhar → reverter)

1. **Teste `nenhuma_chamada_sincrona_ao_ml_no_request`** (D-05): inserido temporariamente `Http::get('https://api.mercadolibre.com/sites/MLB')` no topo de `meus()` → teste caiu (`Unexpected request was recorded` / `Failed asserting that true is false`). Revertido, suíte volta a verde.
2. **Teste `triagem_agrupa_por_motivo_e_o_clique_filtra`** (D-09): `triagem.total` trocado temporariamente para `array_sum(array_column($chips, 'count'))` (soma dos chips) → teste caiu (`Failed asserting that 5 is identical to 4`, exatamente o cenário do item com 2 motivos duplicando a conta). Revertido, suíte volta a verde.

Suíte completa (`--filter=Phase134`) verde após cada reversão: 51 testes, 337 assertions.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Rotas + action meus()** - `917a5606` (feat)
2. **Task 2: Action atualizarAgora()** - `571fcb17` (feat)
3. **Task 3: Testes Feature + Unit (gates de escopo, zero-write, defasagem, triagem, ordenação)** - `de4f245b` (test)

**Plan metadata:** commit a fazer nesta execução (docs: complete plan).

## Files Created/Modified

- `routes/mlb_anuncios.php` - 2 rotas novas, aditivas, no grupo `role:admin` existente
- `app/Http/Controllers/MlbAnuncioController.php` - `meus()`, `atualizarAgora()`, `escopoAcervo()`, `motivosTriagemDef()`
- `tests/Feature/Phase134/MeusAnunciosTest.php` - 10 testes de escopo, zero-write, defasagem, triagem, autorização do "Atualizar agora"
- `tests/Unit/Phase134/OrdenacaoGravidadeTest.php` - 4 testes de ordenação por gravidade

## Decisions Made

- **Triagem obedece o mesmo filtro de status da listagem** (inclusive o default `ativos` de D-03) — segue a instrução literal do plano. Sob o filtro padrão, o chip "Pausado" fica em 0 até o usuário trocar para `todos`/`pausados`, porque um item `paused` não entra na query base sob `status=active`. Documentado aqui para quem tocar em 134-08 não estranhar o comportamento.
- **Gate D-05 nos testes escopado ao domínio `mercadolibre.com`**, não `Http::assertNothingSent()` — o middleware global `HandleInertiaRequests` chama `https://files.ecfconsultoria.com.br/api/v1/signals` (contagem de alertas críticos, `EcfDriveService`) em TODA página Inertia do app, independente da rota. É comportamento preexistente e fora de escopo; o `assertNotSent(fn ($r) => str_contains($r->url(), 'mercadolibre.com'))` prova exatamente o que D-05 exige.
- **`subTotais.publicados`** = contagem total de `MlAcervoItem` da empresa, sem os filtros de status/busca da listagem — mesmo raciocínio de contador de sub-aba já usado para `subTotais.rascunhos`.

## Deviations from Plan

None além das descritas acima (que são decisões explícitas dentro do espaço deixado pelo plano, não desvios de comportamento) - plano executado conforme escrito. Nenhuma trava do `<travas_deste_plano>` foi relaxada: D-05 (zero chamada síncrona), D-04 (`considerado()` não entra na listagem), D-12 (ordenação por `ORDER BY` no banco), D-03 (default `ativos`, listas fechadas), D-02 (`company_id` em toda query), D-15 (mesmo gate `role:admin`), D-18 ("não avaliado" nunca vira status inventado) e o filtro de motivo por `LIKE` sobre whitelist fechada (nunca `whereJsonContains`).

## Issues Encountered

- A primeira versão dos testes usava `$response->viewData('page')['props']` (full-page render), o que exigia o manifest Vite de `Mlb/MeusAnuncios.jsx` — página que só nasce no plano 134-08. Resolvido com o padrão já estabelecido em `tests/Feature/Phase58/DashboardShellsBackendTest.php`: headers `X-Inertia`/`X-Inertia-Version` fazem o Inertia devolver JSON puro (sem `@vite`), lido via `$response->json('props')`.
- `Http::assertNothingSent()` falhava mesmo sem nenhuma chamada ao ML, por causa da chamada global de `HandleInertiaRequests` a `files.ecfconsultoria.com.br` (contagem de signals críticos). Resolvido escopando a asserção ao domínio `mercadolibre.com` (ver Decisions Made).

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano.

## Next Phase Readiness

- Contrato de props (`empresa`, `sub`, `subTotais`, `anuncios`, `triagem`, `filtros`, `defasagem`, `saudeMlDisponivel`, `rotacaoN`) pronto para o plano 134-08 (`resources/js/Pages/Mlb/MeusAnuncios.jsx`) consumir exatamente como especificado no bloco `<interfaces>` do plano.
- `atualizarAgora()` pronto para o botão "Atualizar agora" do 134-08 postar em `route('mlb.anuncios.meus.atualizar', company)`.
- A listagem de Rascunhos em si (sub-aba `rascunhos`) permanece para o 134-09 — este plano só entrega o contador (`subTotais.rascunhos`).
- Nenhum bloqueio conhecido para os próximos planos da fase.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-10*

## Self-Check: PASSED

- FOUND: routes/mlb_anuncios.php
- FOUND: app/Http/Controllers/MlbAnuncioController.php
- FOUND: tests/Feature/Phase134/MeusAnunciosTest.php
- FOUND: tests/Unit/Phase134/OrdenacaoGravidadeTest.php
- FOUND: .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/134-07-SUMMARY.md
- FOUND commit: 917a5606
- FOUND commit: 571fcb17
- FOUND commit: de4f245b
