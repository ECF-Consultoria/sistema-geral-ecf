---
phase: 118-nps-por-empresa-v21-0
plan: 02
subsystem: nps
tags: [nps, desempenho, bonus, laravel, phpunit, tdd]

# Dependency graph
requires:
  - phase: 118-01
    provides: "NpsPorEmpresaService::notasNpsPorEmpresa() com os 3 ramos, janela M+1 e shape auditável"
provides:
  - "D-03 aplicada de fato: filtro de leitura por serviço do vínculo, com fallback consolidado OBRIGATÓRIO"
  - "Empresa com Performance e Shopee pesando 1x (NPSE-05)"
  - "Invalidação por competência (BonusInvalidacao) aplicada ANTES do piso da D-04 (NPSE-04)"
  - "Log::warning('[NPS por Empresa] ...') no gap de atribuição do responsável consolidado"
  - "8º método do teste de coerência entre call-sites, documentando a divergência bônus x área de NPS como intencional (NPSE-06)"
affects: [119-calculo-nota-empresa, 120-agregacao-nota-profissional]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Vínculo nasce consolidado (servico_id NULL) e responde aos surveys ANTES de virar específico — molde de fixture para exercitar o filtro de LEITURA da D-03 em vez de deixar a resolução na ESCRITA decidir sozinha"
    - "Log::spy() + shouldHaveReceived/shouldNotHaveReceived filtrando por tag na mensagem, não por contagem total de chamadas — porque outro serviço (NpsSnapshotService) também loga 'warning' no mesmo fluxo"

key-files:
  created:
    - tests/Feature/Phase118/NpsPorEmpresaServicoTest.php
    - tests/Feature/Phase118/NpsPorEmpresaInvalidacaoTest.php
  modified:
    - app/Services/Desempenho/NpsPorEmpresaService.php
    - tests/Feature/Phase116/NpsFloorRegressaoTest.php

key-decisions:
  - "Opção 1 do risco registrado: a divergência D-04 x D3-da-Fase-116 é TOLERADA e DOCUMENTADA (não estendida às telas) — fixada em código no 8º método do NpsFloorRegressaoTest"
  - "Corrigido bug de metadado na flag `consolidado` do 118-01: ela só marcava 'nenhum vínculo vivo' (fonte B), não capturava o vínculo vivo com servico_id NULL (o caminho OBRIGATÓRIO da D-03) — corrigido para tratar as duas situações como consolidado"

requirements-completed: [NPSE-04, NPSE-05, NPSE-06]

# Metrics
duration: 66min
completed: 2026-07-28
---

# Phase 118 Plan 02: D-03 (fallback consolidado), invalidação antes da D-04, e a divergência documentada — Summary

**Filtro de leitura por serviço do vínculo com fallback consolidado obrigatório, invalidação por competência aplicada antes do piso de NPS, e o 8º método que registra em código a divergência aprovada entre o bônus por empresa e a área de NPS**

## Performance

- **Duration:** 66 min
- **Tasks:** 3
- **Files modified:** 4 (2 novos, 2 editados)

## Accomplishments

- **D-03 provada nos dois sentidos.** Vínculo consolidado (`servico_id NULL`) usa a média de TODOS os surveys da empresa; vínculo específico lê só o survey do seu serviço, com a nota descartada preservada em `notas_brutas` para auditoria (D-05); nota do ramo legado (sem `servico_id`) sobrevive ao filtro em qualquer vínculo.
- **NPSE-05 provada.** Empresa com Performance e Shopee aparece 1x na Collection, com 1 nota — não duplica por serviço.
- **D-01 reforçada com um teste sensível a contaminação real** (pesos diferentes por dimensão), não apenas coincidente.
- **NPSE-04 provada nos dois sentidos.** Empresa invalidada sai de TUDO (nem nota real, nem piso 1.0), com um teste de controle explícito (sem invalidação) para provar que a ausência tem a causa certa; a chave é sempre a competência financeira M, nunca o mês de coleta.
- **T-118-03 mitigada.** `Log::warning('[NPS por Empresa] ...')` dispara exatamente quando `origem = 'sem_nps'` e `houve_survey = true` (gap de atribuição do responsável consolidado) e NÃO dispara na D-04 genuína (empresa sem disparo nenhum) — par de testes provando os dois lados.
- **NPSE-06 provada.** 8º método em `NpsFloorRegressaoTest` prova, no MESMO cenário-espelho e na MESMA janela de julho, os dois lados da divergência aprovada: `janela_aberta` (nota null, excluída) enquanto o cliente está no prazo, `sem_nps` (nota 1.0) depois que a janela fecha, e a sentinela `0.0` do agregado da Fase 116 continua intocada para os dois papéis.

## Task Commits

1. **Task 1: D-03 — serviço do vínculo, fallback consolidado e a empresa que pesa 1× (NPSE-05)** - `a95daec7` (feat)
2. **Task 2: NPSE-04 — invalidação antes do piso, e o log que quebra o silêncio do gap de atribuição** - `085f92cc` (feat)
3. **Task 3: NPSE-06 — o 8º método do teste de coerência entre call-sites** - `94df1fab` (test)

**Plan metadata:** (próximo commit) `docs: complete plan`

## Files Created/Modified

- `tests/Feature/Phase118/NpsPorEmpresaServicoTest.php` (novo) — 6 testes: fallback consolidado, leitura específica nos dois sentidos, empresa Performance+Shopee 1x, ramo legado sobrevive ao filtro, dimensão empresa não contamina.
- `tests/Feature/Phase118/NpsPorEmpresaInvalidacaoTest.php` (novo) — 6 testes: controle sem invalidação, invalidação remove a empresa com e sem nota, chave por competência M provada nos dois sentidos, gap de atribuição logado, D-04 genuína não loga.
- `app/Services/Desempenho/NpsPorEmpresaService.php` (editado) — fix na flag `consolidado` (D-03) + `Log::warning` no passo 9 (D-04).
- `tests/Feature/Phase116/NpsFloorRegressaoTest.php` (editado, só por inserção) — item 7 no docblock + 8º método.

## Decisions Made

- **Opção 1 do risco registrado** (ver `<decisao_do_risco_registrado>` do plano): a divergência entre o bônus por empresa e a área de NPS é tolerada e documentada, nunca eliminada estendendo o fallback da D-04 às telas. Fixada em código no 8º método, com comentário nomeando a decisão, a data (2026-07-28) e o documento (`118-CONTEXT.md` `<risks>`).
- **Ajuste no `NpsPorEmpresaService` (impacto no contrato da Fase 119):** a Task 1 revelou que a flag `consolidado` herdada do Plano 118-01 só cobria a situação "nenhum vínculo vivo" (fonte B) e não capturava o caminho OBRIGATÓRIO da D-03 — vínculo vivo com `servico_id NULL`. Corrigido para que `consolidado = true` também quando QUALQUER vínculo vivo do par `(company_id, role)` for o slot consolidado. Isso é uma correção de METADADO, não de comportamento: o filtro de leitura (passo 7, `servicosVivosDoPar()`) já tratava as duas situações como "sem filtro" desde o 118-01 — só a flag informativa estava incompleta. A Fase 119 pode confiar em `consolidado` para decidir se deve mostrar "responsável consolidado" na UI de auditoria, por exemplo.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Flag `consolidado` não capturava o vínculo vivo com `servico_id NULL`**
- **Found during:** Task 1, primeira execução de `test_d03_vinculo_consolidado_usa_a_media_de_todos_os_surveys_da_empresa`
- **Issue:** `$consolidado = $vinculosDaEmpresa->isEmpty();` só era `true` quando a empresa vinha exclusivamente pela "fonte B" (nenhum vínculo vivo em `CarteiraContextService::forUser()`). O cenário do teste tinha um vínculo VIVO com `servico_id NULL` (o caminho obrigatório da D-03), que `forUser()` resolve como ramo legado com `servico_id => null` — então `$vinculosDaEmpresa` não estava vazia, e a flag saía `false` mesmo com o filtro de leitura corretamente aplicando o fallback (sem filtro de serviço).
- **Fix:** `$consolidado = $vinculosDaEmpresa->isEmpty() || $vinculosDaEmpresa->contains(fn ($v) => $v['servico_id'] === null);` — a flag agora reflete as duas situações que levam ao mesmo fallback "sem serviço conhecido".
- **Files modified:** `app/Services/Desempenho/NpsPorEmpresaService.php`
- **Verification:** `test_d03_vinculo_consolidado_usa_a_media_de_todos_os_surveys_da_empresa` passou a verde; os outros 5 testes de `NpsPorEmpresaServicoTest` e os 24 testes de `Phase118` (118-01) continuaram verdes.
- **Committed in:** `a95daec7` (parte do commit da Task 1)

---

**Total deviations:** 1 auto-fixed (1 bug de metadado)
**Impact on plan:** Correção estritamente aditiva de um campo informativo (`consolidado`) — não muda nenhum `nota`/`origem`/`total_notas` calculado. Sem impacto em número de produção (nenhum consumidor lê este serviço ainda, D-06).

## Issues Encountered

None além do já documentado acima.

## Verification Summary

| Gate | Resultado |
|---|---|
| `--filter=NpsPorEmpresaServicoTest` | 6/6 verdes |
| `--filter=NpsPorEmpresaInvalidacaoTest` | 6/6 verdes |
| `--filter=NpsFloorRegressaoTest` | 8/8 verdes (era 7) |
| `--filter=Phase118` | 36/36 verdes (24 do 118-01 + 12 do 118-02) |
| `--filter=BonusInvalidacaoEmpresaTest` | 5/5 verdes, sem edição |
| `git diff -U0` em `NpsFloorRegressaoTest.php` | 0 linhas removidas — as 7 asserções originais intocadas |
| `--filter="Nps\|Desempenho\|Phase116\|Phase118"` | **19 failed / 506 passed** (3199 assertions) — bate EXATAMENTE com a soma das baselines documentadas (14 falhas pré-existentes em `Desempenho` + 5 em `Nps`, ambas de `116-08-SUMMARY.md`/debug de margem já aberto). Zero falhas novas. |
| Aditividade: `sha256sum DesempenhoScoreService.php` | `cfc16da2a8404fba…9edd` — byte-a-byte intocado, confirmado após cada task |

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

A Fase 118 está completa (waves 1 e 2). `NpsPorEmpresaService::notasNpsPorEmpresa()` está pronto como insumo para a Fase 119 (cálculo de `nota_empresa`), com:
- Fallback consolidado da D-03 correto tanto no filtro quanto na flag `consolidado` (útil para a Fase 119 explicar por que uma empresa não teve filtro de serviço aplicado).
- Gap de atribuição do responsável consolidado agora visível em log — a Fase 119/futuro debug pode usar isso para priorizar o backfill de competências anteriores a 2026-07-22 (ainda pendente, ver memória `project_nps_assignment_consolidado_gap`).
- A divergência entre bônus e telas está documentada e travada por teste — nenhuma mudança futura pode "corrigi-la" silenciosamente sem quebrar o 8º método.

Nenhum bloqueio conhecido para a Fase 119.

---
*Phase: 118-nps-por-empresa-v21-0*
*Completed: 2026-07-28*

## Self-Check: PASSED

Todos os arquivos declarados (`NpsPorEmpresaServicoTest.php`, `NpsPorEmpresaInvalidacaoTest.php`, `NpsPorEmpresaService.php`, `NpsFloorRegressaoTest.php`, este SUMMARY) confirmados em disco; os 3 commits de task (`a95daec7`, `085f92cc`, `94df1fab`) confirmados em `git log`.
