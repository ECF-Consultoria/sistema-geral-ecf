---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 06
subsystem: api
tags: [laravel, php, artisan, scheduler, queue, mercadolivre, phase134]

# Dependency graph
requires:
  - phase: 134-04
    provides: "MlAcervoService::coletarCamadaBarata() + SyncMlAcervoCompanyJob — camada barata pronta para o fan-out"
  - phase: 134-05
    provides: "MlAcervoDetalheService::selecionarFatia()/coletarDetalhe() + SyncMlAcervoDetalheJob — camada cara pronta para o fan-out"
provides:
  - "mlb:sync-acervo — comando artisan que enfileira as duas camadas por empresa com token ML ativo, N da rotação como opção (D-23)"
  - "mlb:acervo-cleanup — retenção da série diária (~90 dias) + limpeza opcional de itens órfãos"
  - "Agendamento diário das duas rotinas em routes/console.php (11:35 coleta, 03:40 limpeza), aditivo, com withoutOverlapping()"
affects: [134-07, 134-08, 134-09, 134-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "N de rotação sempre lido via --n= com fallback para config(), nunca constante — mesmo padrão de config-first já usado em mlb_acervo.php"
    - "Exclusão em blocos com limit()+delete() em laço (DELETE...WHERE rowid IN (SELECT...) no SQLite via SQLiteGrammar::compileDeleteWithJoinsOrLimit, LIMIT nativo no MariaDB) — nunca um DELETE único sobre tabela de dezenas de milhões"
    - "Delay de dispatch encadeado por empresa: camada cara começa a contar a partir de onde a camada barata DA MESMA empresa parou, não de um contador global"

key-files:
  created:
    - app/Console/Commands/SyncMlAcervo.php
    - app/Console/Commands/MlAcervoCleanup.php
    - tests/Unit/Phase134/ComandosAcervoTest.php
  modified:
    - routes/console.php

key-decisions:
  - "--company= sem token ativo falha com mensagem clara (Company::find + checagem de mlToken->status), espelhando o comportamento síncrono de SyncMlData::handle() para o mesmo cenário — não reinventado."
  - "--so-barata e --so-detalhe juntos retornam FAILURE com mensagem explícita (mutuamente exclusivas) — sem essa checagem, os dois juntos silenciosamente não enfileirariam nada, um resultado surpreendente que o plano não previu explicitamente mas que a lógica implicava."
  - "Limpeza em routes/console.php dividida em dois pontos de inserção diferentes (não um bloco único): mlb:sync-acervo logo após o bloco ml:sync (11:05→11:35), mlb:acervo-cleanup logo após mlb:sync-vendas-logs-cleanup (03:20→03:40) — instrução explícita da Task 2 para 'preservar a organização atual do arquivo', agrupando por propósito (coleta vs. retenção) em vez de por fase."

requirements-completed: [D-06, D-07, D-19, D-23]

# Metrics
duration: ~15min
completed: 2026-08-10
---

# Phase 134 Plan 06: Comandos artisan + agendamento no scheduler Summary

**`mlb:sync-acervo` enfileira as duas camadas de coleta por empresa com token ML ativo (N da rotação como opção `--n=`, nunca constante) e `mlb:acervo-cleanup` mantém a série diária em ~90 dias — as duas rotinas agora rodam sozinhas todo dia, aditivamente agendadas em `routes/console.php` às 11:35 e 03:40.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-08-10T21:05Z (aprox., logo após o 134-05)
- **Completed:** 2026-08-10T21:14Z
- **Tasks:** 3/3
- **Files modified:** 4 (3 criados, 1 modificado)

## Accomplishments

- `SyncMlAcervo` (`mlb:sync-acervo`) — fan-out diário que despacha `SyncMlAcervoCompanyJob` (camada barata) e, salvo `--so-barata`, os `SyncMlAcervoDetalheJob` da fatia de rotação (camada cara) por empresa. Escopo de empresas reusa **exatamente** o filtro de `SyncMlData` (`active=true` + `mlToken.status=active`, `with('mlToken')`) — nada reinventado.
- **N da rotação (D-23) é opção `--n=`**, com fallback para `config('mlb_acervo.rotacao_n')` só quando a opção não vem — nunca constante literal no arquivo (gate automatizado: `grep` por `$n = [dígito]` em linhas de código dá `0`).
- Delay escalonado: 2s por posição de empresa para a camada barata (padrão já em produção no `ml:sync`, mantido — o `134-RESEARCH.md` §3 registra confiança média sobre folga maior no rate limit e recomenda explicitamente não afrouxar sem confirmação); a camada cara de cada empresa começa a contar a partir de onde a barata DA MESMA empresa parou, para não competir com o multiget na mesma janela.
- `--company=` restringe a uma empresa e falha com mensagem clara se ela não tiver token ativo; `--so-barata`/`--so-detalhe` isolam cada camada e são mutuamente exclusivas (Rule 2 — sem essa checagem, os dois juntos enfileirariam silenciosamente nada).
- `MlAcervoCleanup` (`mlb:acervo-cleanup`) — retenção da série diária (`ml_acervo_metricas_diarias`, `--keep-days=90` default de `config('mlb_acervo.retencao_dias')`) e limpeza opcional de itens órfãos (`--orfaos`, flag desligada por padrão — token temporariamente inativo não pode custar o último snapshot do D-08). Exclusão em blocos de 10.000 linhas em laço, nunca `DELETE` único.
- `routes/console.php` ganhou dois blocos **puramente aditivos**: `mlb:sync-acervo` às 11:35 (logo após `ml:sync` 11:05 e antes do `adman:sync-margem` 11:20 concentrar mais pressão de rate limit) e `mlb:acervo-cleanup` às 03:40 (logo após `mlb:sync-vendas-logs-cleanup` 03:20), ambos com `->withoutOverlapping()`. Nenhum agendamento existente foi tocado.
- `tests/Unit/Phase134/ComandosAcervoTest.php` — 5 testes, todos verdes: fan-out só gera job pra empresa com token; N da opção altera de fato o tamanho da fatia (gate literal do D-23, provado quebrando/revertendo); `--company` restringe a uma empresa; retenção respeita a fronteira exata 90 vs 91 dias; `--orfaos` só apaga com a flag ligada.

## Gate provado manualmente (quebrar → falhar → reverter)

**Teste 2** (D-23, N como opção): `$n` hardcoded para `7` dentro de `SyncMlAcervo::handle()`, ignorando `--n=` → teste caiu (`Failed asserting that actual size 15 matches expected size 10` — 100/7=15, não os 10 esperados de N=10). Revertido, suíte volta a verde (37 testes Phase134, 278 assertions).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Comando mlb:sync-acervo — fan-out das duas camadas** - `9e3ad5c1` (feat)
2. **Task 2: Comando de retenção + agendamento das duas rotinas** - `61f6cd57` (feat)
3. **Task 3: Testes de fan-out, de N como parâmetro e de retenção** - `4c31c923` (test) — inclui o fix de `removerSerieAntiga()` descrito abaixo

**Plan metadata:** commit a fazer nesta execução (docs: complete plan).

## Files Created/Modified

- `app/Console/Commands/SyncMlAcervo.php` - fan-out diário das duas camadas, N como opção
- `app/Console/Commands/MlAcervoCleanup.php` - retenção da série diária + limpeza opcional de órfãos
- `routes/console.php` - dois agendamentos aditivos (11:35 coleta, 03:40 limpeza)
- `tests/Unit/Phase134/ComandosAcervoTest.php` - 5 testes cobrindo D-06/D-07/D-08/D-23

## Decisions Made

- **`--company=` sem token ativo falha com mensagem clara** — espelha exatamente o comportamento de `SyncMlData::handle()` para o mesmo cenário (checagem de `mlToken->status !== 'active'`), sem reinventar.
- **`--so-barata` e `--so-detalhe` juntos são erro explícito**, não silêncio — a combinação literal das duas condições (`if (! $soDetalhe)` e `if (! $soBarata)`) faria nenhuma camada rodar se ambas fossem passadas, um resultado surpreendente. Adicionada checagem `Rule 2` que retorna `FAILURE` com mensagem.
- **Os dois blocos de agendamento entram em pontos DIFERENTES do arquivo**, não um bloco único — a Task 2 pediu explicitamente para preservar a organização atual (agrupar por propósito: coleta ao lado de `ml:sync`, retenção ao lado de `mlb:sync-vendas-logs-cleanup`), não colocá-los juntos como o bloco `<interfaces>` do plano mostrava por conveniência de leitura.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `MlAcervoCleanup::removerSerieAntiga()` não respeitava a fronteira exata de 90 vs 91 dias**
- **Found during:** Task 3, ao rodar `retencao_remove_serie_antiga_e_preserva_a_recente()` pela primeira vez.
- **Issue:** `$limite = now()->subDays($keepDays)` carrega a HORA ATUAL da execução (ex.: 18:12:xx). A coluna `data` tem cast `date`, e o setter do Eloquent grava o valor com o formato completo do grammar (`Y-m-d 00:00:00`) mesmo assim — mesmo achado já documentado no 134-05 (`MlAcervoDetalheService::gravarVisitasSerieDiaria()`). A linha de exatamente 90 dias atrás (gravada à meia-noite) comparava como "menor que" o limite (hora atual, sempre depois da meia-noite) e era removida — quebrando a fronteira exata que o D-07 exige ("mantém 90 e remove 91").
- **Fix:** `$limite = now()->subDays($keepDays)->startOfDay();` — comparação meia-noite contra meia-noite, sem componente de hora.
- **Files modified:** `app/Console/Commands/MlAcervoCleanup.php`
- **Verification:** teste 4 (`retencao_remove_serie_antiga_e_preserva_a_recente`) passa e prova a fronteira exata: `[120, 91, 90, 10]` dias atrás → sobrevivem só `[90, 10]`.
- **Committed in:** `4c31c923` (Task 3 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug de fronteira de data, mesmo padrão de causa raiz já visto no 134-05)
**Impact on plan:** O fix foi necessário para o próprio gate de retenção do D-07 (fronteira exata de dias) funcionar — sem ele, o teste que o plano pede explicitamente ("O teste 4 prova a fronteira exata de 90 contra 91 dias") teria passado só por coincidência de horário de execução, ou falhado de forma intermitente dependendo da hora do dia em que rodasse. Nenhum scope creep.

## Issues Encountered

None além do já documentado em Deviations.

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano. As duas rotinas passam a rodar sozinhas assim que este código for deployado e o cron do Hostinger (`* * * * * php artisan schedule:run`) processar o novo agendamento — nenhuma ação manual adicional.

## Next Phase Readiness

- `mlb:sync-acervo` e `mlb:acervo-cleanup` prontos para produção — o botão "Atualizar agora" do 134-07 pode despachar `SyncMlAcervoCompanyJob::dispatch($company)` diretamente (mesmo job usado pelo comando), sem depender do comando artisan.
- O agendamento em `routes/console.php` é estritamente aditivo — nenhum horário, nome ou comando existente foi alterado. Confirmado por `git diff` (só `+` no arquivo) e por `schedule:list` mostrando as 4 entradas de `ml:sync`/`adman:sync-margem`/`mlb:sync-vendas-logs-cleanup`/etc. intactas ao lado das 2 novas.
- **Não deployado** — autorização de deploy é sempre explícita e separada (per CLAUDE.md). As duas rotinas só passam a rodar sozinhas em produção após o próximo `deploy.sh`.
- Nenhum bloqueio conhecido para os próximos planos da fase.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-10*

## Self-Check: PASSED

- FOUND: app/Console/Commands/SyncMlAcervo.php
- FOUND: app/Console/Commands/MlAcervoCleanup.php
- FOUND: tests/Unit/Phase134/ComandosAcervoTest.php
- FOUND: routes/console.php (modificado)
- FOUND commit: 9e3ad5c1
- FOUND commit: 61f6cd57
- FOUND commit: 4c31c923
