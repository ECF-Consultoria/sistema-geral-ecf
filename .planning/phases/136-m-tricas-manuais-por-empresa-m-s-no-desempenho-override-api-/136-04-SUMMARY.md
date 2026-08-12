---
phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-
plan: 04
subsystem: api
tags: [laravel, inertia, desempenho, lancamento-manual, rbac, transacao, phpunit]

# Dependency graph
requires:
  - phase: 136-02
    provides: "Tabela desempenho_metricas_manuais, model DesempenhoMetricaManual (METRICAS), StoreMetricaManualRequest e CompanyScoreSnapshotWriter::competenciaConsolidada()"
  - phase: 136-03
    provides: "ManualMetricOverrideService ligado ao CompanyScoreService — o valor lancado ja manda na nota"
provides:
  - "DesempenhoMetricasManuaisController — index() monta a grade empresa x metrica por competencia; lancar() grava com trava sob lock"
  - "Rotas admin-only desempenho.metricas-manuais.index (GET) e desempenho.metricas-manuais.lancar (POST)"
  - "Contrato de props da tela do Plano 05 (mes/meses/consolidada/busca/empresas/metricas)"
affects: ["136-05", "136-06", "136-07"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Trava de competencia consolidada revalidada COM lockForUpdate como primeira operacao da DB::transaction() da escrita — a validacao do FormRequest cobre o antes do submit, o lock cobre a corrida contra desempenho:consolidar-mes"
    - "Leitura da celula por whereDate + lockForUpdate em vez de updateOrCreate com igualdade crua em mes_referencia (o cast date grava 'Y-m-d H:i:s' no SQLite e 'Y-m-d' no MariaDB)"
    - "Custo de API por opt-in: resolve()/compute() so para empresa COM lancamento, e fonte adman so quando isCached() responde true"
    - "withoutVite() + component(nome, shouldExist: false) para testar controller Inertia cuja pagina .jsx e entrega de um plano posterior"

key-files:
  created:
    - app/Http/Controllers/DesempenhoMetricasManuaisController.php
    - tests/Feature/Phase136/MetricaManualRotaAdminTest.php
  modified:
    - routes/web.php

key-decisions:
  - "FinancialSourceResolver (Plano 01) e usado para resolver a fonte da grade em vez de redigitar o desempate — evita criar o quarto call-site duplicado que D-10 acabou de eliminar"
  - "ManualMetricOverrideService NAO foi injetado no controller: nenhuma das duas actions o chama (a grade le lancamentos crus, inclusive ativo=false, que carregarLancamentos() nao devolve)"
  - "updateOrCreate substituido por lookup whereDate + create/save — updateOrCreate chaveado por mes_referencia criaria linha duplicada no SQLite"
  - "api_valor de margem_cmv e o CMV IMPLICITO da API (faturamento - margem de contribuicao em R$); loja Shopee devolve null por construcao"

patterns-established:
  - "bustarCacheDaEmpresa() do lancamento manual apaga APENAS snapshots de origem snapshot_diario/warm_cache da (empresa, competencia) — linha de consolidar_mes nunca e tocada, e a limpeza global de cache pelo Artisan continua proibida"

requirements-completed: ["D-01", "D-02", "D-07", "D-09", "D-12"]

# Metrics
duration: 9min
completed: 2026-08-12
---

# Phase 136 Plan 04: Rotas admin-only e controller da grade de lançamento manual Summary

**As duas rotas `role:admin` e o `DesempenhoMetricasManuaisController`: `index()` monta a grade empresa × métrica marcando a competência consolidada como read-only, e `lancar()` grava dentro de uma transação que revalida a trava de congelamento sob `lockForUpdate()` — perdendo a corrida contra `desempenho:consolidar-mes` com erro visível, nunca em silêncio.**

## Performance

- **Duration:** ~9 min (12:56 → 13:05 UTC, 2026-08-12)
- **Tasks:** 2/2
- **Files modified:** 2 criados, 1 modificado (`routes/web.php`, deixado unstaged — ver "Issues Encountered")

## Accomplishments

- `DesempenhoMetricasManuaisController` (452 linhas) com `index()` e `lancar()`. O universo da grade é empresa `active = true` com pelo menos um vínculo em `company_users` cujo serviço está em setor financeiramente elegível (`performance`/`shopee`), com `distinct` — sem ele a mesma empresa apareceria duplicada, porque `company_users` tem várias linhas por (empresa, papel) desde a Fase 76.
- **A trava de D-09 é a mesma da consolidação, verificada duas vezes.** `CompanyScoreSnapshotWriter::competenciaConsolidada()` alimenta o booleano `consolidada` de cada mês do seletor (a competência congelada continua listada e legível, só read-only) e é revalidada com `comLock: true` como **primeira operação** dentro da `DB::transaction()` do `lancar()`. A recusa é `ValidationException` no campo `mes_referencia`, com mensagem pt-BR dizendo que a consolidação entrou entre a abertura da tela e o envio e que **nada foi gravado**.
- **Reverter para auto preserva a linha e o valor** (D-02/D-12): `ativo = false`, `valor` mantido, `valor_anterior` com o valor corrente, `lancado_por`/`lancado_em` atualizados. Nenhum `delete()` toca `desempenho_metricas_manuais` em lugar nenhum do controller.
- **A grade não dispara HTTP síncrono para empresa sem lançamento** (T-136-17): `MetricPeriodResolver::resolve()` só é chamado na primeira empresa que tem célula lançada, e para fonte `adman` o dispatcher só roda quando `AdmanMetricDiffService::isCached()` responde `true` — senão a célula devolve `api_valor = null` com `api_aquecida = false`. Fonte `shopee` lê `shopee_metrics` do banco e é sempre barata.
- Invalidação de cache pontual no molde de `BonusAuditoriaController::bustarCacheDaEmpresa()`: `Cache::forget($scoreService->cacheKey($userId, $mes))` por profissional vinculado, mais a remoção das linhas de `desempenho_company_score_snapshots` da `(empresa, competência)` **apenas** com origem `snapshot_diario`/`warm_cache`. Limpeza global de cache pelo Artisan não aparece no arquivo (T-136-13, verificado por grep: 0 ocorrências).
- 14 testes novos em `MetricaManualRotaAdminTest`, todos com asserção por **reconsulta ao banco**: 403 para `consultor` nas duas rotas, redirect ao login para visitante, 200 + componente `Desempenho/MetricasManuais` para admin, 422 para métrica fora da whitelist / valor negativo / valor acima do teto / empresa inativa / competência consolidada (com `count()` conferido em zero), o ciclo lançar → editar → reverter, a independência dos dois eixos (D-07) e a ausência de `lancado_por`/nome de usuário nas props (T-136-08).

## Task Commits

Cada task foi commitada atomicamente, com staging por caminho explícito (nunca `git add .`):

1. **Task 1: Controller da grade — listagem por competência e escrita transacional** - `72f04292` (feat)
2. **Task 2: Rotas admin-only + suíte de acesso, trava e ciclo auto→manual→auto** - `6f034605` (test)

O hunk de `routes/web.php` **não entrou em nenhum dos dois commits** — ver "Issues Encountered".

## Files Created/Modified

- `app/Http/Controllers/DesempenhoMetricasManuaisController.php` - `index()` (validação de `mes`/`busca`, seletor de 12 meses com `consolidada`, universo por vínculo financeiro com `distinct`, células com `ativo`/`valor`/`valor_anterior`/`api_valor`/`api_aquecida`), `lancar()` (transação + trava sob lock + semântica ligar/editar/reverter), `bustarCacheDaEmpresa()`, `valoresDaApi()`, `mesesDoSeletor()`, `mesExtenso()`
- `tests/Feature/Phase136/MetricaManualRotaAdminTest.php` - 14 testes de acesso, validação, trava e ciclo de vida da célula
- `routes/web.php` - import do controller (junto de `DashboardController`) e as duas rotas com `->middleware('role:admin')` **por rota**, logo depois de `desempenho.relatorio-bonificacao.pdf` — bloco por-rota, fora do grupo largo que fecha na linha 377

## Decisions Made

- **`FinancialSourceResolver` em vez de redigitar o desempate.** A grade precisa saber qual fonte consultar para a empresa com vínculo nos dois setores. Copiar `'adman' vence` para cá criaria exatamente o quarto call-site duplicado que D-10 acabou de eliminar nesta mesma fase — o resolvedor único é injetado e chamado com `$vinculosElegiveis` + `$companiesById`, o mesmo contrato dos outros três consumidores.
- **`ManualMetricOverrideService` não foi injetado.** O plano listava-o no construtor, mas nenhuma das duas actions tem call-site para ele: `carregarLancamentos()` devolve só linhas `ativo = true` e a grade precisa também das inativas (a célula revertida continua exibindo o valor preservado), e `totalMesCheio()` é privado. Injetar sem chamar seria dependência morta. O valor da API é resolvido com `MetricPeriodResolver` + `MetricDiffDispatcher` diretamente, com a mesma janela de mês cheio.
- **`updateOrCreate` trocado por lookup `whereDate` + `create`/`save`.** O cast `date` do model grava `'2026-08-01 00:00:00'` no SQLite dos testes e `'2026-08-01'` no MariaDB de produção; um `updateOrCreate` chaveado por igualdade em `mes_referencia` não casaria a linha existente no SQLite e criaria uma segunda linha para a mesma célula. O resto do código da fase já usa `whereDate` em toda leitura por competência — a escrita passa a seguir a mesma disciplina, com `lockForUpdate()` na leitura para ficar dentro da mesma serialização da trava.
- **`api_valor` da margem é o CMV implícito.** A API não expõe um "CMV"; o comparável honesto é `faturamento − margem de contribuição em R$` na mesma janela de mês cheio. Loja Shopee devolve `null` por construção (a Shopee não fornece margem) — que é precisamente a razão de o CMV manual existir nesta fase.
- **`api_aquecida = false` também para célula sem lançamento.** A prop significa "o valor da API foi resolvido para esta célula e está disponível sem custo", não "a Adman está aquecida em geral" — coerente com a regra de só pagar HTTP por quem tem lançamento. Documentado no docblock para a tela do Plano 05 não interpretar como "cache frio".

## Deviations from Plan

### Ajustes automáticos

**1. [Rule 3 - Bloqueio] `withoutVite()` na suíte de rotas**
- **Encontrado em:** Task 2, primeira execução dos testes de GET
- **Problema:** `app.blade.php` faz `@vite([... "resources/js/Pages/{$page['component']}.jsx"])`; como `Desempenho/MetricasManuais.jsx` é entrega do Plano 05, os 5 testes de GET quebravam com "Unable to locate file in Vite manifest" — falha por uma ausência que o teste não mede.
- **Correção:** `$this->withoutVite()` no `setUp()`, e `->component('Desempenho/MetricasManuais', false)` no `assertInertia` (o `shouldExist` default do Inertia também exige o arquivo). O nome do componente — que é o contrato deste plano — continua asseverado.
- **Arquivos:** `tests/Feature/Phase136/MetricaManualRotaAdminTest.php`
- **Commit:** `6f034605`

**2. [Rule 1 - Bug] `updateOrCreate` substituído por lookup `whereDate`**
- **Encontrado em:** Task 1, ao escrever a escrita transacional
- **Problema:** o plano especificava `updateOrCreate` pela chave `(company_id, mes_referencia, metrica)`. Com o cast `date` do model, o SQLite armazena `'2026-08-01 00:00:00'`; a cláusula de igualdade do `updateOrCreate` compararia contra `'2026-08-01'` e nunca casaria — criando linha duplicada por célula e furando a semântica de `valor_anterior`.
- **Correção:** leitura com `whereDate` + `lockForUpdate()` e, em seguida, `create` ou `fill()->save()`. Semântica de `updateOrCreate` preservada; a armadilha de banco (learnings §6 — o SQLite dos testes não pega o que o MariaDB pega, e aqui é o inverso) fica registrada em comentário no código.
- **Arquivos:** `app/Http/Controllers/DesempenhoMetricasManuaisController.php`
- **Commit:** `72f04292`

**3. [Rule 2 - Correção crítica] `FinancialSourceResolver` no lugar do desempate redigitado**
- **Encontrado em:** Task 1, ao decidir qual fonte consultar para a empresa com dois vínculos
- **Problema:** o plano não citava o resolvedor; a saída óbvia seria repetir `'adman' vence` no controller — o mesmo erro estrutural que D-10 acabou de corrigir em três call-sites nesta fase.
- **Correção:** injeção e uso de `FinancialSourceResolver::resolverPorEmpresa()`.
- **Arquivos:** `app/Http/Controllers/DesempenhoMetricasManuaisController.php`
- **Commit:** `72f04292`

**4. [Ajuste de acceptance criteria] Docblock reescrito para não conter a string proibida**
- O acceptance criteria exige `grep -c "cache:clear"` = 0 no controller. A primeira versão do docblock citava o comando literalmente **para proibi-lo**, o que faria o gate acusar. Reescrito para "Limpeza global de cache pelo Artisan é PROIBIDA aqui", preservando o aviso e o histórico do incidente de 30/07/2026.

### Testes acrescentados além do plano

Três cenários que o plano não pedia nominalmente, mas que provam os `must_haves`: empresa sem vínculo financeiro e empresa inativa fora da grade; empresa com dois vínculos do mesmo setor aparecendo uma única vez (prova do `distinct`); e a competência consolidada continuando **listada** com `consolidada = true` (D-09 — mês congelado não some do seletor, só fica read-only).

## Issues Encountered

**`routes/web.php` foi editado mas NÃO commitado — de propósito.** Esta árvore de trabalho está sendo editada em paralelo por outra sessão (Fase 135 Plano 08), que tem hunks não commitados no mesmo arquivo: o import de `OnboardingTemplateController` (linha ~39) e o bloco de rotas `/onboarding/templates` (linha ~789). Meus dois hunks foram colocados deliberadamente longe deles — o import junto de `DashboardController` (linha ~19) e as rotas logo após `desempenho.relatorio-bonificacao.pdf` (linha ~561) — de modo que `git diff` produz **quatro hunks bem separados**, dois meus e dois da outra sessão. O staging por hunk é responsabilidade do orquestrador. Nenhum arquivo da Fase 135 foi tocado, stashado, resetado ou commitado; nenhum `git add .`/`-A`/`-u` foi executado em momento algum.

**Baseline de testes reconfirmada, não interpretada contra zero.** `CarteiraPeriodoDiffTest|DesempenhoPeriodoOficialTest|DesempenhoShopeeScoreTest|ConsolidarMesJanelaNpsTest|JanelaNpsBonusTest` fecha em **9 failed / 18 passed** — exatamente o número e exatamente os 9 nomes congelados em `136-BASELINE-TESTES.md`. Nenhuma falha nova, nenhuma falha fora da lista.

## User Setup Required

None — nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- **Contrato de props travado para o Plano 05** (a tela): `mes`, `mes_label`, `meses[] = {valor,label,consolidada}`, `consolidada`, `busca`, `metricas[]` e `empresas[] = {company_id, company_name, fontes[], faturamento{...}, margem_cmv{...}}`, cada eixo com `{ativo, valor, valor_anterior, api_valor, api_aquecida}`. A página a criar é `resources/js/Pages/Desempenho/MetricasManuais.jsx` — quando ela existir, o `withoutVite()`/`shouldExist: false` da suíte pode ser removido (não é obrigatório removê-los).
- **A tela precisa honrar `consolidada`**: com `true`, todas as células ficam read-only. O backend recusa de qualquer forma (dupla camada), mas deixar o campo editável convidaria o admin a digitar um número que será rejeitado.
- **`api_aquecida = false` não significa "sem dado"** — significa "não resolvemos o valor da API para esta célula agora". A tela deve dizer "ainda não aquecido", nunca "R$ 0".
- **Pendência herdada do Plano 02, ainda em pé:** a migration de `desempenho_metricas_manuais` precisa subir contra o MariaDB de produção no deploy. Nenhum deploy foi feito nesta sessão.

---
*Phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-*
*Completed: 2026-08-12*

## Self-Check: PASSED

- `app/Http/Controllers/DesempenhoMetricasManuaisController.php` — FOUND
- `tests/Feature/Phase136/MetricaManualRotaAdminTest.php` — FOUND
- `.planning/phases/136-.../136-04-SUMMARY.md` — FOUND
- Commits `72f04292` e `6f034605` — FOUND em `git log --oneline --all`
- `routes/web.php` — 4 ocorrências de `desempenho.metricas-manuais` (2 rotas × path + name), **unstaged** por desenho
