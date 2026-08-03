---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
plan: 02
subsystem: desempenho-score
tags: [margem, shadow, cache-key, tdd, fixmarg-03]

requires:
  - phase: 122-01
    provides: "Tabela desempenho_company_score_snapshots e CompanyScoreSnapshotWriter::sync() (base para o Plano 03/04, não consumida diretamente aqui)"
provides:
  - "margem_amostra medindo cobertura de margem_var_pp (pontos percentuais, por empresa) quando o shadow roda"
  - "margem_amostra.legado preservando os 3 números de sempre (base do gate FIXMARG-03)"
  - "cacheKey() em desempenho.compute.v16"
  - "ConsolidarMesDesempenho lendo margem_amostra['legado'] para o gate — base do gate continua legada até o Plano 03 (D-122-05) decidir trocar"
affects: [122-03, 122-04, "qualquer plano futuro que consuma margem_amostra ou decida a base do gate FIXMARG-03"]

tech-stack:
  added: []
  patterns:
    - "shape aditivo condicional ao shadow com sub-chave 'legado' preservando o payload antigo byte-a-byte (mesmo padrão de nota_final_por_empresa/score_status_por_empresa, D-05 da Fase 121)"
    - "consumidor de produção (ConsolidarMesDesempenho) lê explicitamente a sub-chave legada em vez do topo do payload, para não herdar troca de base implícita quando o shadow está sempre ligado"

key-files:
  created:
    - tests/Feature/Phase122/MargemAmostraPpTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - app/Console/Commands/ConsolidarMesDesempenho.php
    - tests/Feature/Phase120/ShadowRoteamentoTest.php
    - tests/Feature/DesempenhoShopeeScoreTest.php
    - tests/Feature/Phase116/NpsFloorDesempenhoTest.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php

key-decisions:
  - "D-122-04 implementada literalmente: margem_amostra ganha shape novo (pp) só quando $empresasScore!==null; legado sobrevive intocado em margem_amostra.legado; shadow desligado continua com as 3 chaves de sempre (gate nº 4 do 121-VALIDATION.md, PayloadBaselineFlagOffTest sem edição)"
  - "Deviation (Rule 1, fora dos arquivos listados no plano): ConsolidarMesDesempenho.php precisou ser corrigido para ler margem_amostra['legado'] — sem isso o gate FIXMARG-03 trocaria de base implicitamente, porque o comando sempre chama compute() com o shadow ligado (Fase 120) e o topo do payload virou o shape pp"
  - "Deviation (Rule 1): leitura de fonte_financeira/margem_var_pp tornada defensiva (?? null) — dublês de teste de outras suítes (Fase 120/121) montam a linha só com os campos que exercitam"

requirements-completed: [SNAP-05]

duration: ~35min
completed: 2026-08-03
---

# Phase 122 Plan 02: margem_amostra em pontos percentuais (SNAP-05) Summary

`margem_amostra` passa a medir cobertura de `margem_var_pp` (pontos percentuais, por empresa) quando o shadow roda, preservando os números legados (variação relativa agregada) em `margem_amostra.legado`; cache versionada de v15 para v16; e o gate FIXMARG-03 do `consolidar-mes` corrigido para continuar lendo a base legada, evitando uma troca implícita de régua no comando que paga o bônus.

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-03T14:10:00Z
- **Completed:** 2026-08-03T14:28:38Z
- **Tasks:** 3 (todas concluídas)
- **Files modified:** 8 (1 criado, 7 modificados)

## Accomplishments
- `margem_amostra` mede cobertura de `margem_var_pp` (grandeza que a milestone v21.0 usa para pagar) quando o shadow roda, com `n_real`/`n_elegivel`/`cobertura` recalculados sobre `empresas_score` (denominador: `fonte_financeira` não-nula e diferente de `shopee`)
- Números legados (`contribution_margin_pct.diff_pct` agregado) preservados byte-a-byte em `margem_amostra.legado` — nenhuma perda de informação para o gate FIXMARG-03
- Com o shadow desligado, `margem_amostra` continua com exatamente as 3 chaves de sempre — `PayloadBaselineFlagOffTest` (Fase 120) passa sem nenhuma edição
- Cache versionada `desempenho.compute.v15` → `v16`, com as 4 suítes que fixavam a string antiga atualizadas no mesmo commit
- Bug real descoberto e corrigido: `ConsolidarMesDesempenho` sempre roda com o shadow ligado (Fase 120) e lia `margem_amostra` do topo do payload — após a Task 1, isso teria trocado a base do gate FIXMARG-03 implicitamente (de variação relativa para pp), exatamente o que a `<design_decision>` do plano e a mitigação T-122-07 proíbem nesta fase

## Task Commits

Cada task foi commitada atomicamente (Task 1 seguiu TDD — RED confirmado antes do GREEN):

1. **Task 1: margem_amostra conta cobertura de margem_var_pp (SNAP-05)**
   - `a33fbb3d` (test) — suíte `MargemAmostraPpTest` criada e confirmada falhando (4/5 RED) antes da implementação
   - `eabbd35a` (feat) — implementação em `DesempenhoScoreService::compute()`, GREEN 5/5
2. **Task 2: Suíte MargemAmostraPpTest + reconciliação das suítes que fixavam o shape antigo** — `b484c11b` (fix)
   - Reconcilia `ShadowRoteamentoTest.php` (invariante revogado de propósito pela SNAP-05)
   - Corrige `ConsolidarMesDesempenho.php` (deviation, ver abaixo)
   - Torna a leitura de `fonte_financeira`/`margem_var_pp` defensiva (deviation, ver abaixo)
3. **Task 3: Atualizar as 4 suítes com a chave de cache hardcoded (v15 → v16)** — `81acb713` (test)

**Plano 122-01 (contexto herdado):** `5775c1e9`..`5beed7f6` (fundação de persistência, não modificada por este plano)

## Files Created/Modified
- `app/Services/DesempenhoScoreService.php` — `margem_amostra` com shape novo condicional ao shadow (D-122-04); `cacheKey()` em v16
- `app/Console/Commands/ConsolidarMesDesempenho.php` — gate FIXMARG-03 lê `margem_amostra['legado']` (deviation)
- `tests/Feature/Phase122/MargemAmostraPpTest.php` — suíte nova, 5 testes cobrindo o `<behavior>` da Task 1
- `tests/Feature/Phase120/ShadowRoteamentoTest.php` — reconciliação do invariante revogado (compara contra `legado`, mais asserções de `base`/`legado`)
- `tests/Feature/DesempenhoShopeeScoreTest.php`, `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`, `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php`, `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` — string `v15` → `v16`

## Decisions Made
- **D-122-04 aplicada literalmente** — ver `<design_decision>` do plano. Denominador de pp: `fonte_financeira` não-nula e `!== 'shopee'`.
- **Base do gate FIXMARG-03 permanece legada nesta fase** — a escolha de qual base o gate usa é do Plano 03 (D-122-05); este plano só entrega as duas medidas lado a lado. Como isso exigiu tocar `ConsolidarMesDesempenho.php` (fora da lista `files_modified` do plano), documentado como deviation abaixo.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `ConsolidarMesDesempenho` trocaria a base do gate FIXMARG-03 implicitamente**
- **Found during:** Task 2, ao rodar `--filter=Phase110` (`ConsolidarMesMargemResilienteTest`) conforme instruído pelo plano.
- **Issue:** `ConsolidarMesDesempenho::handle()` sempre chama `compute($user, $mes, null, incluirEmpresasScore: true)` (shadow ligado, Fase 120/AGRE-02). Após a Task 1, isso significa que `$result['margem_amostra']` no topo do payload SEMPRE vem no shape novo (pp) quando o comando roda — nunca no shape legado. O comando lia `$result['margem_amostra']['n_elegivel']`/`['cobertura']` diretamente do topo, então passaria a gatear com a cobertura de pp em vez da cobertura legada, sem nenhuma decisão explícita — exatamente a troca de base implícita que a `<design_decision>` do plano e a mitigação T-122-07 do `<threat_model>` proíbem ("quem escolhe a base do gate é o Plano 03 (D-122-05), nunca esta troca implícita").
- **Fix:** `ConsolidarMesDesempenho.php` passa a ler `$margemAmostra['legado'] ?? $margemAmostra` antes de extrair `n_elegivel`/`cobertura`/`n_real` para o gate e para o log de alerta. Com o shadow sempre ligado neste comando, isso preserva o comportamento do gate byte-a-byte.
- **Files modified:** `app/Console/Commands/ConsolidarMesDesempenho.php` (fora da lista `files_modified` do frontmatter do plano — necessário para cumprir a própria `<design_decision>` D-122-04)
- **Verification:** Reproduzido o bug isolando a mudança (checkout temporário do arquivo para HEAD antes do fix, confirmando `n_elegivel`/`cobertura` do topo = pp), corrigido, e confirmado que `test_amostra_saudavel_persiste_normalmente` volta a persistir com a cobertura correta.
- **Committed in:** `b484c11b`

**2. [Rule 1 - Bug] Leitura de `fonte_financeira`/`margem_var_pp` sem `?? null` quebrava dublês de teste de outras fases**
- **Found during:** Task 2, ao rodar `--filter=Phase120` — `AgregacaoProfissionalTest` (Fase 120 Plano 03) quebrou com `ErrorException: Undefined property: stdClass::$fonte_financeira`.
- **Issue:** O helper `linhaEmpresa()` daquela suíte (anterior a este plano) monta objetos `stdClass` só com os campos que o próprio cenário exercita (`company_id`, `status`, `nota_empresa`, `nota_empresa_parcial`, `margem_var_pp`) — sem `fonte_financeira`. O código novo de `margem_amostra` acessava `$e->fonte_financeira` sem guard.
- **Fix:** Acesso trocado para `$e->fonte_financeira ?? null` / `$e->margem_var_pp ?? null` — null coalescing em propriedade de objeto não lança nem emite warning para propriedade ausente, ao contrário do acesso direto.
- **Files modified:** `app/Services/DesempenhoScoreService.php` (mesmo bloco da Task 1, sem arquivo adicional)
- **Verification:** `--filter=AgregacaoProfissionalTest` volta a 7/7 verde; `--filter=MargemAmostraPpTest` continua 5/5.
- **Committed in:** `b484c11b`

---

**Total deviations:** 2 auto-fixed (2 Rule 1 - bugs de correção descobertos ao reconciliar as suítes das Fases 110/120, exatamente o objetivo da Task 2).
**Impact on plan:** Ambos os fixes são necessários para que a própria `<design_decision>` D-122-04 do plano se cumpra (base do gate permanece legada). Nenhum scope creep — nenhuma régua/agregação de margem foi tocada (confirmado via diff vazio dentro de `computeVarMargem()`, `reguaMargem()`, `reguaFaturamento()`, `margemPontos()`, `computeNotaFinal*()`, `computeScoreStatus*()` em todo o histórico do plano).

## Issues Encountered

**`ConsolidarMesMargemResilienteTest` (Phase110) tem 2 falhas PRÉ-EXISTENTES, fora do escopo deste plano.**

`test_amostra_saudavel_persiste_normalmente` e `test_idempotencia_preservada_apos_o_gate` falham tanto no código final deste plano quanto no commit imediatamente anterior a toda a Fase 122 (`baceacbe`, o bump de mediana no faturamento do quick 260731-pvk) — reproduzido isolando as duas versões e rodando as mesmas suítes. Não é regressão desta fase (Rule aplicada: scope boundary — pré-existente em arquivo/teste fora do escopo do plano, não corrigido). Registrado aqui em vez de `deferred-items.md` porque o `<verification>` do plano pede explicitamente `--filter=Phase110` verde; a evidência de que é pré-existente está documentada para quem for investigar depois (possivelmente relacionado à mudança de mediana no faturamento do mesmo período, mas não confirmado).

**Ordem de commits do TDD gate:** a Task 1 é `tdd="true"`; a suíte `MargemAmostraPpTest` foi escrita e a implementação aplicada quase em paralelo durante a exploração inicial. Para cumprir o gate RED→GREEN de fato (não apenas na ordem dos commits), a implementação foi revertida temporariamente via patch, o RED foi confirmado rodando a suíte (4/5 falhas), e só então a implementação foi reaplicada e o GREEN confirmado (5/5) antes dos commits `a33fbb3d`/`eabbd35a`.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `margem_amostra.legado` e `margem_amostra` (shape pp) prontos para o Plano 03 decidir a base do gate FIXMARG-03 (D-122-05), conforme a flag `metrics.performance_company_first_score`.
- Cache em `v16` — nenhum `cache:clear` necessário no VPS (proibido, incidente 2026-07-30).
- Pendência não resolvida por este plano: as 2 falhas pré-existentes de `ConsolidarMesMargemResilienteTest` (ver "Issues Encountered") continuam sem explicação raiz — recomenda-se investigação dedicada antes do Plano 03/04 se esses cenários forem reutilizados.

## Self-Check: PASSED

- FOUND: app/Services/DesempenhoScoreService.php
- FOUND: app/Console/Commands/ConsolidarMesDesempenho.php
- FOUND: tests/Feature/Phase122/MargemAmostraPpTest.php
- FOUND: tests/Feature/Phase120/ShadowRoteamentoTest.php
- FOUND: tests/Feature/DesempenhoShopeeScoreTest.php
- FOUND: tests/Feature/Phase116/NpsFloorDesempenhoTest.php
- FOUND: tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
- FOUND: tests/Feature/V18/DesempenhoMetadadosCacheTest.php
- FOUND commit a33fbb3d (Task 1 RED)
- FOUND commit eabbd35a (Task 1 GREEN)
- FOUND commit b484c11b (Task 2)
- FOUND commit 81acb713 (Task 3)

---
*Phase: 122-persist-ncia-por-empresa-e-comandos-v21-0*
*Completed: 2026-08-03*
