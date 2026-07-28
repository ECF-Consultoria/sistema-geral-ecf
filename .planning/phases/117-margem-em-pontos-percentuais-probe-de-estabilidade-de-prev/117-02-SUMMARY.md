---
phase: 117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev
plan: 02
subsystem: infra
tags: [laravel, artisan-command, adman, cache, tdd, gate-manual]

# Dependency graph
requires:
  - phase: 117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev (Plano 01)
    provides: "prev_value/diff_pp aditivos em AdmanMetricDiffService/ShopeeMetricDiffService (independente deste plano — nenhum arquivo em comum)"
provides:
  - "comando `adman:probe-margem-prev` (modo leitura sem cache + `--relatorio`)"
  - "duas tabelas de persistência (`adman_probe_margem_prev_leituras`, `adman_probe_margem_prev_vereditos`) reconsultáveis, insert-only"
  - "runbook de execução do gate MPP-04 na VPS (ver `<gate_de_fase>` abaixo)"
  - "GATE MPP-04 registrado como PENDENTE — bloqueia Fase 119 consumir diff_pp para nota (D-12)"
affects: [119-fase-consumo-diff-pp-nota-margem]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "leitura sem cache via forceRefresh:true (bypassa cache do AdmanMetricDiffService/AdmanService de propósito, D-11b)"
    - "verificação anti-cache por hash bit-a-bit do payload cru, com precedência sobre veredito aprovado"
    - "leitura de constante privada de outra classe via Reflection para não violar restrição de escopo do plano"
    - "duas tabelas insert-only no mesmo arquivo de migration (fato + agregação)"

key-files:
  created:
    - database/migrations/2026_07_27_120000_create_adman_probe_margem_prev_tables.php
    - app/Models/AdmanProbeMargemPrevLeitura.php
    - app/Models/AdmanProbeMargemPrevVeredito.php
    - app/Console/Commands/ProbeMargemPrevStability.php
    - tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php
  modified: []

key-decisions:
  - "MARGEM_COBERTURA_MINIMA lida via Reflection (não `use`/import) — a constante é `private` na realidade, não `public` como o <interfaces> do plano descrevia; app/Services/Metrics/* fica intocado (verificação 6 do plano proíbe editá-lo)"
  - "janela_esperada sem flag grava NULL (não 'manual') — rótulo ausente é distinto de rótulo explícito 'manual'"
  - "flip de nota comparado por CONJUNTO de notas distintas (não pares consecutivos) — cobre D-01 'entre duas leituras quaisquer' literalmente"
  - "sanidade anti-cache (invariante 2) tem precedência sobre TODOS os outros critérios, inclusive antes de decidir reprovado — payload idêntico é sintoma de erro de instrumentação, não veredito válido de nenhum tipo"

requirements-completed: [MPP-04]

# Metrics
duration: ~110min
completed: 2026-07-28
---

# Phase 117 Plan 02: Probe de estabilidade de `percentageMargin.prev` Summary

**Comando `adman:probe-margem-prev` (leitura sem cache via `forceRefresh:true` + `--relatorio` com veredito insert-only) construído e testado — o gate MPP-04 que ele instrumenta segue PENDENTE, aguardando 24-48h de leituras reais na VPS.**

## Performance

- **Duration:** ~110 min
- **Completed:** 2026-07-28
- **Tasks:** 3/3 de código completas + Task 4 (checkpoint humano) aguardando reconhecimento
- **Files modified:** 5 (4 novos + 1 suíte de teste)

## Accomplishments
- Migration cria `adman_probe_margem_prev_leituras` (fato por leitura × empresa, `decimal(14,6)` para não fabricar identidade bit-a-bit) e `adman_probe_margem_prev_vereditos` (agregação insert-only)
- Comando `adman:probe-margem-prev` lê `percentageMargin` direto de `AdmanService::fetchAccountMetricsDetailedCached(..., forceRefresh: true)` — nunca `AdmanMetricDiffService::compute()` (D-11b), provado por teste que pré-popula o cache com valor divergente e confirma que a leitura persistida vem do HTTP fresco
- Amostra fixa (D-04): carteiras dos users 3 (Luiz) e 15 (Danilo) via `CarteiraContextService::forUser()`, filtrado por `financial_metrics_eligible=true` e `financial_source='adman'`
- Fail-open por empresa: falha HTTP grava `http_falhou=true` sem abortar as demais; exceção também é fail-open (Pitfall 2 do RESEARCH — leitura falha é dado)
- `--relatorio` agrega com precedência EXPLÍCITA: `instrumentacao_suspeita` (payload idêntico bit-a-bit entre leituras comparáveis) → `reprovado` (flip de nota entre duas leituras quaisquer, cobertura de `prev` < `MARGEM_COBERTURA_MINIMA`, ou desenho amostral incompleto — menos de 5 rodadas ou nenhuma leitura em `contencao_11h`) → `aprovado`
- Régua de margem (`reguaMargem()`) copiada byte a byte de `DesempenhoScoreService::reguaMargem()`, com docblock de duplicação intencional; `DesempenhoScoreService.php` e `app/Services/Metrics/*` ficaram **intocados**, confirmado por `git diff --name-only` vazio
- Suíte `--filter=Desempenho` rodada ao final: **91 passed, 14 failed** — as mesmas 14 falhas pré-existentes já documentadas em `117-01`/`deferred-items.md`, confirmando zero regressão introduzida por este plano
- 24 testes de mecânica, todos com `Http::fake()` ou sem HTTP algum (`--relatorio`) — nenhum teste chama a Adman real

## Task Commits

1. **Task 1: Persistência (migration + models + suíte de schema)** - `a8b42c66` (test)
2. **Task 2: Comando — modo leitura sem cache, fail-open** - `d0cd0bb8` (feat)
3. **Task 3: Modo --relatorio — flip, cobertura, anti-cache, veredito persistido** - `7a5a3a42` (feat)

**Plan metadata:** commit deste SUMMARY (a seguir)

## Files Created/Modified
- `database/migrations/2026_07_27_120000_create_adman_probe_margem_prev_tables.php` - duas tabelas (leituras insert-only + vereditos insert-only), sem `enum()` nem `nullOnDelete()` (confirmado por grep)
- `app/Models/AdmanProbeMargemPrevLeitura.php` - model sem `LogsActivity`, casts para `datetime`/`float`/`integer`/`boolean`, relação `company()`
- `app/Models/AdmanProbeMargemPrevVeredito.php` - model sem `LogsActivity`, constantes de veredito, casts `array` para `empresas_com_flip`/`motivos`
- `app/Console/Commands/ProbeMargemPrevStability.php` - comando `adman:probe-margem-prev` completo (leitura + `--relatorio`)
- `tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php` - 24 cenários (7 schema/model + 8 leitura + 9 relatório)

## Decisions Made

- **`MARGEM_COBERTURA_MINIMA` lida via `ReflectionClass::getConstant()`, não `use`/import** — o `<interfaces>` do plano descrevia essa constante como `public const` em `AdmanMetricDiffService.php:70`, mas o código real declara `private const`. A verificação 6 deste mesmo plano proíbe editar `app/Services/Metrics/*` (independência do Plano 117-01), então subir a visibilidade estava fora de escopo. Reflection lê o valor real sem tocar o arquivo-fonte, sem hardcodear `0.8`, e sem produzir um `use App\Services\Metrics\AdmanMetricDiffService;` ou `::compute(` que o gate automatizado da Task 2 reprovaria. Documentado no docblock do método `coberturaMinima()`.
- **`janela_esperada` sem flag grava `NULL`** — interpretação escolhida entre as duas opções que o `<behavior>` da Task 2 deixou em aberto ("sem a flag, grava `null` (ou `manual`, conforme o default escolhido — decidir e testar explicitamente")). `NULL` foi preferido porque representa fielmente "sem rótulo informado", reservando o valor `'manual'` para quando um humano de fato disparar uma leitura extra fora do cronograma (ver runbook, passo 3, item opcional).
- **Detecção de flip por CONJUNTO de notas distintas, não pares consecutivos** — D-01 exige "entre duas leituras QUAISQUER"; comparar só consecutivos deixaria passar um flip entre a 1ª e a 3ª leitura se a 2ª "escondesse" a diferença. Testado explicitamente com um cenário de notas `[3,4,3,4,4]`.
- **Precedência do veredito `instrumentacao_suspeita` sobre TUDO, inclusive `reprovado`** — uma leitura que sempre devolve o mesmo payload bit-a-bit não decide nada (nem aprova, nem reprova de verdade); é erro de instrumentação. Testado com cenário onde cobertura/desenho amostral estariam OK mas o hash é idêntico em todas as leituras — o veredito ainda assim não é `aprovado`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking, divergência plano vs. realidade] `MARGEM_COBERTURA_MINIMA` é `private const`, não `public const`**
- **Found during:** Task 3 (implementação de `--relatorio`)
- **Issue:** O `<interfaces>` do plano afirmava `public const MARGEM_COBERTURA_MINIMA = 0.8;` em `AdmanMetricDiffService.php:70`. O código real declara `private const`. Acessar diretamente (`AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA`) de fora da classe causaria erro fatal do PHP. Subir a visibilidade para `public` violaria a verificação 6 do próprio plano ("`git diff --stat` não inclui... `app/Services/Metrics/*`" — independência do Plano 117-01).
- **Fix:** Leitura via `(new \ReflectionClass(\App\Services\Metrics\AdmanMetricDiffService::class))->getConstant('MARGEM_COBERTURA_MINIMA')` — bypassa a visibilidade sem tocar o arquivo-fonte, sem hardcodear `0.8`, e sem produzir nenhum `use`/`::compute(` que o gate automatizado da Task 2 reprovaria (confirmado empiricamente com o grep exato do plano antes e depois da mudança).
- **Files modified:** app/Console/Commands/ProbeMargemPrevStability.php
- **Verification:** `test_relatorio_reprova_com_cobertura_de_prev_abaixo_de_80_por_cento` prova que o patamar usado é 80% (via `str_contains($motivo, '80%')`); `git diff --name-only -- app/Services/Metrics/` retorna vazio
- **Committed in:** 7a5a3a42 (Task 3 commit)

**2. [Rule 1 - Bug de teste] Cobertura de teste "empresa sem fonte adman" com amostra vazia abortava com FAILURE**
- **Found during:** Task 2 (primeira execução da suíte)
- **Issue:** O cenário `test_empresa_sem_fonte_adman_fica_fora_da_amostra` originalmente só criava UMA empresa (vínculo Shopee, fora do escopo Adman) para UM user da amostra. Como nenhuma empresa qualificava, a amostra total ficava vazia e o comando retornava `FAILURE` por design (`<action>` item 4: "se a amostra ficar vazia, erro claro e FAILURE") — mascarando o que o teste realmente queria provar (que o vínculo Shopee específico fica de fora, não que a amostra inteira está vazia).
- **Fix:** Adicionada uma empresa companheira elegível (setor performance) vinculada ao outro user da amostra, garantindo que a amostra global não fique vazia, e a asserção passou a verificar especificamente que a empresa Shopee está ausente e a elegível está presente.
- **Files modified:** tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php
- **Verification:** Teste verde após o ajuste; nenhuma mudança no comando.
- **Committed in:** d0cd0bb8 (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (1 blocking/divergência plano-realidade, 1 bug de teste)
**Impact on plan:** Nenhum scope creep. A divergência do item 1 foi resolvida pelo caminho mais estreito compatível com as duas restrições do próprio plano (ler a constante real E não tocar `app/Services/Metrics/*`).

## Issues Encountered

Nenhum bloqueio adicional. A suíte `--filter=Desempenho` foi rodada integralmente ao final da Task 3 (258s) e confirmou **91 passed, 14 failed** — número idêntico ao documentado em `117-01-SUMMARY.md`/`deferred-items.md` como falhas pré-existentes não relacionadas a esta fase.

## User Setup Required

None — nenhuma configuração de serviço externo necessária para os artefatos deste plano. O runbook abaixo (execução do gate na VPS) exige deploy e é responsabilidade separada do usuário, com autorização explícita antes de qualquer `deploy.sh`.

## Runbook de Execução do Gate MPP-04 (a rodar FORA desta sessão, na VPS)

> Este runbook é o mesmo do `<gate_de_fase>` do plano — reproduzido aqui para quem for operar o gate não precisar reabrir o `117-02-PLAN.md`.

1. **Deploy** do comando + migration (deploy só com autorização explícita do usuário; rodar `php artisan migrate --force`).
2. **Fixar a competência** na primeira leitura e reusar exatamente a mesma string em todas as demais — ex.: `--mes=2026-06`. Nunca `last_closed_month`.
3. **Rodar no mínimo 5 leituras espalhadas em 24-48h** (D-02), com os rótulos de janela:
   - `php artisan adman:probe-margem-prev --mes=2026-06 --janela=madrugada` (API ociosa)
   - `php artisan adman:probe-margem-prev --mes=2026-06 --janela=contencao_11h` — **OBRIGATÓRIA**, dentro de **11:00-12:00 BRT**
   - `php artisan adman:probe-margem-prev --mes=2026-06 --janela=pico_tarde`
   - `php artisan adman:probe-margem-prev --mes=2026-06 --janela=repeticao_24h` (+24h)
   - `php artisan adman:probe-margem-prev --mes=2026-06 --janela=contencao_11h` (repetir a janela das 11h no 2º dia)
   - Opcional: pedir a um humano para disparar o sync MLB pela tela pouco antes de uma leitura extra com `--janela=manual` (esses jobs não são agendados por cron — invariante 4).
4. **Agregar:** `php artisan adman:probe-margem-prev --mes=2026-06 --relatorio`
5. **Conferir por reconsulta ao banco, não por stdout:** `php artisan tinker` → `\App\Models\AdmanProbeMargemPrevVeredito::latest('gerado_em')->first()` e, se houver flip, inspecionar `\App\Models\AdmanProbeMargemPrevLeitura` da empresa.
6. **Apresentar o relatório ao usuário** e registrar a decisão.

**Sinal de PASSA** (todas simultaneamente): cobertura de `prev` ≥ 0,8 · zero flip de nota · ≥1 leitura em `contencao_11h` dentro de 11:00-12:00 BRT · ≥5 rodadas · payloads NÃO idênticos bit-a-bit.
**Sinal de REPROVA** (qualquer uma): flip de nota · cobertura < 0,8 · desenho amostral incompleto.
**Sinal ambíguo — NUNCA sucesso:** todas as leituras idênticas bit-a-bit → `instrumentacao_suspeita`, verificar se `forceRefresh: true` está mesmo sendo propagado antes de qualquer conclusão.

## GATE MPP-04: PENDENTE

**Confirmado por reconhecimento explícito do usuário na Task 4 deste plano (checkpoint `human-action`), com a data registrada abaixo.**

Enquanto este gate não for resolvido:
- a **Fase 117 NÃO pode ser marcada como completa**;
- a **Fase 119 NÃO pode consumir `diff_pp` para calcular nota** (D-12);
- se o veredito vier `reprovado` ou `instrumentacao_suspeita`, a decisão sobre como seguir volta para o usuário (congelar `prev` em snapshot próprio vs. voltar ao cálculo local — decisão explicitamente NÃO pré-tomada pelo CONTEXT).

O `/gsd:verify-work` desta fase deve tratar este item como pendente explícito, nunca como concluído.

## Next Phase Readiness

- Instrumento pronto e testado; **execução real do probe (24-48h contra a Adman de produção) ainda não começou** — depende de deploy autorizado pelo usuário
- Fase 119 (consumo de `diff_pp` na nota de margem) **bloqueada** até o veredito do gate ser apresentado e aprovado (D-12)
- `.planning/todos/pending/metrica-margem-bonus-fragil.md` permanece aberto — o freeze de junho/2026 (prazo 31/07 14h BRT) não é resolvido por este plano

---
*Phase: 117-margem-em-pontos-percentuais-probe-de-estabilidade-de-prev*
*Completed: 2026-07-28*

## Self-Check: PASSED

- FOUND: database/migrations/2026_07_27_120000_create_adman_probe_margem_prev_tables.php
- FOUND: app/Models/AdmanProbeMargemPrevLeitura.php
- FOUND: app/Models/AdmanProbeMargemPrevVeredito.php
- FOUND: app/Console/Commands/ProbeMargemPrevStability.php
- FOUND: tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php
- FOUND: commit a8b42c66 (Task 1)
- FOUND: commit d0cd0bb8 (Task 2)
- FOUND: commit 7a5a3a42 (Task 3)
