---
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
plan: 03
subsystem: comercial
tags: [refactor, service-extraction, comercial, hubspot, regression-safety]

# Dependency graph
requires: ["124-01", "124-02"]
provides:
  - "baseline-antes.txt — referencia imutavel do gate nominal de 6 arquivos, reusada pelo 124-05"
  - "App\\Services\\Comercial\\PendenciasComerciaisService — fonte unica das 7 pendencias comerciais (FLUXO-03)"
  - "ComercialController::listagem() consumindo o service via injecao de metodo"
affects: [124-04, 124-05]

# Tech tracking
tech-stack:
  added: []
  patterns: ["Service com metodo publico unico calcular(Company): array, corpo copiado literalmente do controller"]

key-files:
  created:
    - app/Services/Comercial/PendenciasComerciaisService.php
    - .planning/phases/124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst/baseline-antes.txt
    - .planning/phases/124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst/baseline-depois-03.txt
  modified:
    - app/Http/Controllers/ComercialController.php

key-decisions:
  - "Comando de gate (6 arquivos, testdox, um processo so) confirmado reproduzivel — grava-se aqui na integra para reuso do 124-05"
  - "Bug encontrado e corrigido inline (Rule 1): a closure do ->each() nao capturava $pendencias do escopo externo — PHP exige 'use ($pendencias)' explicito; sem isso a rota /comercial/empresas quebrava com 500 (Undefined variable). Nao e desvio do plano, e a mecanica normal de closures em PHP que o plano nao detalhou"

patterns-established:
  - "Extracao pura: corpo do metodo movido byte a byte, incluindo comentarios historicos (hotfix 2026-06-19, quick task 260805-eqk) e a cache estatica dentro do escopo do metodo"

requirements-completed: [FLUXO-03]

duration: ~25min
completed: 2026-08-07
---

# Fase 124 Plano 03: Baseline nominal + extracao do PendenciasComerciaisService Summary

**Baseline nominal congelado antes de qualquer refatoracao (68 linhas, unica falha pre-existente ja catalogada) e `ComercialController::calcularPendenciasComerciais()` extraido para `App\Services\Comercial\PendenciasComerciaisService::calcular()` com corpo copiado literalmente — diff nominal pos-extracao contra o baseline retornou vazio.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-08-07
- **Tasks:** 3/3
- **Files modified:** 2 (+2 baselines .txt)

## Accomplishments

- `baseline-antes.txt` capturado ANTES de tocar em qualquer código de produção — 68 linhas, gate de 6 arquivos (`Phase35HubspotV2Test`, `Phase14ComercialTest`, `Phase37ComercialListagemTest`, `ComercialControllerHelperTest`, `Phase124RegressaoComercialTest`, `Phase124RegressaoHubspotTest`), única falha `Phase14Comercial::test_update_ignora_campos_legacy` (pré-existente, catalogada na pesquisa)
- `App\Services\Comercial\PendenciasComerciaisService::calcular(Company $c): array` criado com o corpo copiado literalmente das linhas 467-578 do controller: early-return `is_origem_hubspot` intacto, `static $matchCache` intacta, os 7 slugs na mesma ordem, todos os comentários históricos preservados
- `ComercialController::listagem()` passou a injetar o service por assinatura de método e a consumi-lo dentro do `each()`
- `HubspotWebhookController::calcularPendencias()` (homônimo, outro conceito) não foi tocado
- `baseline-depois-03.txt` capturado com o MESMO comando do gate — `diff baseline-antes.txt baseline-depois-03.txt` retornou **vazio** (exit code 0): zero regressão comprovada por nome de teste, não por contagem

## Task Commits

1. **Task 1: Capturar o baseline nominal ANTES de qualquer refatoração** - `e5d9573f` (test)
2. **Task 2: Extrair PendenciasComerciaisService com o corpo copiado literalmente** - `baa5022c` (refactor)
3. **Task 3: Provar zero regressão contra o baseline nominal** - `0cbe8c38` (test)

## Files Created/Modified

- `app/Services/Comercial/PendenciasComerciaisService.php` (novo) - classe `PendenciasComerciaisService`, método público `calcular(Company $c): array`, docblock de classe explicando o propósito FLUXO-03 e o aviso sobre o homônimo do webhook
- `app/Http/Controllers/ComercialController.php` (modificado) - método privado `calcularPendenciasComerciais()` removido; `use App\Services\Comercial\PendenciasComerciaisService;` adicionado; `listagem(Request $request, PendenciasComerciaisService $pendencias)`; chamada trocada para `$pendencias->calcular($c)` dentro do `each()` com `use ($pendencias)` na closure
- `.planning/phases/124-.../baseline-antes.txt` (novo) - referência imutável nominal, reusada pelo `124-05`
- `.planning/phases/124-.../baseline-depois-03.txt` (novo) - snapshot pós-extração, idêntico ao baseline-antes por nome de teste

## O comando de gate (para reuso no 124-05)

```bash
"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox --do-not-cache-result \
  tests/Feature/Phase35HubspotV2Test.php \
  tests/Feature/Phase14ComercialTest.php \
  tests/Feature/Phase37ComercialListagemTest.php \
  tests/Unit/ComercialControllerHelperTest.php \
  tests/Feature/Phase124RegressaoComercialTest.php \
  tests/Feature/Phase124RegressaoHubspotTest.php \
  2>&1 | grep -v -E '^(Time:|Memory:|Runtime:|Configuration:|PHPUnit |OK|Tests:|WARNINGS?|$)' \
  > .planning/phases/124-.../baseline-depois-<NN>.txt
```

Comparar sempre por `diff` contra `baseline-antes.txt` — nunca por contagem de verdes/vermelhos.

## Decisions Made

- Comando de gate confirmado reproduzível byte a byte (mesmo filtro grep, mesma ordem de arquivos) — registrado aqui na íntegra para o plano `124-05` reusar sem reconstruir.
- Nenhuma decisão de arquitetura nova; extração pura conforme D-02/D-05 do CONTEXT (que não se aplicam a este plano específico) e o objetivo do plano (early-return e cache estática intactos, decisão A4 não tocada).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Closure do `->each()` não capturava `$pendencias` injetado por assinatura de método**
- **Found during:** Task 2, ao rodar a verificação `Phase37ComercialListagemTest`
- **Issue:** PHP não captura variáveis do escopo externo dentro de closures anônimas automaticamente — é preciso `use (...)` explícito. A troca de `$this->calcularPendenciasComerciais($c)` (método, tem acesso a `$this` implicitamente) para `$pendencias->calcular($c)` (variável local injetada) quebrou a rota `/comercial/empresas` com `ErrorException: Undefined variable $pendencias`, retornando 500 em toda a suíte de listagem
- **Fix:** Adicionado `use ($pendencias)` na assinatura da closure passada a `$todasEmpresas->each(function (Company $c) use ($pendencias) { ... })`
- **Files modified:** `app/Http/Controllers/ComercialController.php`
- **Commit:** `baa5022c` (mesmo commit da Task 2 — corrigido antes de rodar a verificação, não é um commit separado)

Ou: nenhuma outra deviation. O plano previa injeção por assinatura de método "fora do `each()`" para preservar a cache dentro do request — isso foi seguido; o ajuste de `use()` é mecânica padrão de PHP para acessar a variável de fora dentro da closure, não uma mudança de comportamento ou de estratégia.

## Issues Encountered

Nenhum além do bug de closure já documentado acima, corrigido e reverificado antes do commit da Task 2.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `PendenciasComerciaisService` pronto para ser consumido pela Fase 131 (tela do Administrativo) sem mudança adicional
- `baseline-antes.txt` é a referência que os planos `124-04` e `124-05` devem comparar em seus próprios `baseline-depois-<NN>.txt`
- Nenhum bloqueio identificado para os planos seguintes da fase

---
*Phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst*
*Completed: 2026-08-07*

## Self-Check: PASSED

- FOUND: app/Services/Comercial/PendenciasComerciaisService.php
- FOUND: .planning/phases/124-.../baseline-antes.txt
- FOUND: .planning/phases/124-.../baseline-depois-03.txt
- FOUND: commit e5d9573f (test — Task 1)
- FOUND: commit baa5022c (refactor — Task 2)
- FOUND: commit 0cbe8c38 (test — Task 3)
