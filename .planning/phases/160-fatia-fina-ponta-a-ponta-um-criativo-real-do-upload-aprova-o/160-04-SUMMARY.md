---
phase: 160-fatia-fina-ponta-a-ponta-um-criativo-real-do-upload-aprova-o
plan: 04
subsystem: ml-publicador
tags: [laravel, creative-engine, retencao, scheduler, seguranca, log]

requires:
  - phase: 160-01
    provides: "ml_anuncio_criativos (referencias_apagadas_em), MlAnuncioCriativo (LIMITE_MINUTOS/encerrarSeTravada/referenciasVivas), ReferenciaEfemeraService::apagar()"
  - phase: 160-02
    provides: "GerarCriativoIaJob, GeminiImageProvider (docblock SEGURANCA ja escrito)"
  - phase: 160-03
    provides: "criativoAprovar() — ponto onde a deleção na aprovação foi plugada"
provides:
  - "Deleção da referência NA HORA da aprovação (criativoAprovar chama ReferenciaEfemeraService::apagar())"
  - "Comando `creative:limpar-referencias` — varredura diária por idade do REGISTRO, --dry-run, --orfaos opt-in"
  - "Agendamento diário (04:10 BRT) em routes/console.php, rede de segurança da deleção da aprovação"
  - "Teste-guarda GEN-05/OPS-01 (CreativeSegredoLogTest) — chave/prompt/base64 nunca em log, chave nunca em arquivo versionado"
affects: []

tech-stack:
  added: []
  patterns:
    - "Varredura seleciona pela LINHA da tabela (created_at + referencias_apagadas_em), nunca pelo disco — molde MlAcervoCleanup"
    - "Janela de retenção duas ordens de grandeza acima da vida máxima do job que ela protege (48h vs LIMITE_MINUTOS=12) — a folga é o que torna a varredura estruturalmente incapaz de apagar arquivo em uso"
    - "Captura de log por evento (MessageLogged), não Log::spy(), quando a asserção precisa cobrir QUALQUER nível sem exigir contagem mínima por nível"

key-files:
  created:
    - app/Console/Commands/LimparReferenciasCriativos.php
    - tests/Feature/Phase160/CriativoRetencaoTest.php
    - tests/Unit/Phase160/CreativeSegredoLogTest.php
  modified:
    - config/services.php
    - .env.example
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/console.php

key-decisions:
  - "Contagem de registros/arquivos/bytes do relatório é calculada a partir dos METADADOS (referenciasVivas()) ANTES de qualquer apagamento, do mesmo jeito em --dry-run e na execução real — é isso que garante que o dry-run reporte exatamente o que a execução real reportaria, sem precisar reler o disco duas vezes."
  - "Teste-guarda de segredo usa o evento `Illuminate\\Log\\Events\\MessageLogged` em vez de `Log::spy()` puro: `Mockery\\Mock::shouldHaveReceived()` já executa a verificação (com `atLeast()->once()` implícito) no instante em que é chamado — antes de qualquer `->withArgs()`/`->zeroOrMoreTimes()` encadeado conseguir afrouxar a contagem. Isso faz uma asserção genérica 'nenhuma chamada de QUALQUER nível vaza o segredo' explodir em falso positivo para o nível que não foi chamado nenhuma vez (medido ao rodar o teste pela primeira vez). O listener do evento captura mensagem+contexto de toda chamada real, em qualquer nível, sem exigir cardinalidade."
  - "GerarCriativoIaJob e GeminiImageProvider já respeitavam a disciplina de segredo desde a 160-02 (docblock 'SEGURANÇA §17' do provider) — o teste-guarda passou de primeira, sem nenhum ajuste de produção. Confirma a hipótese do plano: 'uma falha aqui aponta para o job novo', e não houve falha."

requirements-completed: [FOTO-03, GEN-05, OPS-01]

duration: ~40min
completed: 2026-10-02
---

# Phase 160 Plan 04: Retenção em duas camadas + teste-guarda de segredo Summary

**A foto de referência do cliente é apagada na hora da aprovação e, para o que escapar, uma varredura diária que seleciona pela LINHA da tabela — nunca pelo disco — torna-se estruturalmente incapaz de apagar um arquivo que algum job ainda possa estar lendo; um teste-guarda prova que chave, prompt e base64 nunca vazam para log.**

## Performance

- **Duration:** ~40 min
- **Completed:** 2026-10-02
- **Tasks:** 2/2 completas
- **Files modified:** 7 (3 criados, 4 modificados)

## Accomplishments

- `criativoAprovar()` agora chama `ReferenciaEfemeraService::apagar($criativo)` dentro de `try/catch (\Throwable)` logo após marcar o criativo como aprovado — uma falha ao apagar a referência nunca desfaz uma aprovação que já chegou ao Mercado Livre (testado com a imagem gerada permanecendo no disco e `criativoStatus()` devolvendo `referencias: []` sem erro).
- `creative:limpar-referencias` varre por idade do `created_at` do `MlAnuncioCriativo`, nunca por timestamp de arquivo — a amarra central do plano contra o incidente do ECF Drive (2026-09-14). O teste `test_criativo_vivo_nao_e_tocado` prova isso diretamente: um criativo `rodando` criado agora, com referência em disco, sobrevive intacto a uma execução da varredura.
- Registro velho ainda `pendente`/`rodando` (50h) é encerrado por `encerrarSeTravada()` ANTES de ter a referência apagada — nada fica em andamento para sempre, e a referência não escapa da varredura só porque o status não chegou a `pronto`/`erro`.
- `--dry-run` relata exatamente a mesma contagem (registros/arquivos/bytes) que a execução real reportaria, sem escrever nenhuma coluna nem apagar nenhum arquivo — os dois caminhos somam a partir dos mesmos metadados, antes de qualquer apagamento.
- `--orfaos` (desligado por padrão) é o único ramo que decide por disco: remove diretório de `creative-referencias/` sem registro correspondente na tabela e com mais de 48h de idade — testado nos dois sentidos (diretório recente preservado, diretório antigo removido).
- Janela de retenção virou configuração de primeira classe: `services.creative.retencao_referencias_horas` (env `CREATIVE_RETENCAO_HORAS=48`), documentada com a conta que a justifica (48h = duas ordens de grandeza acima de `LIMITE_MINUTOS=12`).
- Teste-guarda `CreativeSegredoLogTest` prova GEN-05/OPS-01 nos dois caminhos (sucesso e falha com Gemini 500 em todos os modelos): nenhuma chamada de log, em nenhum nível, contém a chave, o prompt completo ou o base64 (enviado ou recebido); `.env.example` traz `GEMINI_API_KEY=` vazio; nenhum arquivo de `app/`/`config/` contém o prefixo `AIza`.

## Task Commits

1. **Task 1: Retenção em duas camadas — deleção na aprovação e varredura agendada (TDD: teste RED confirmado antes da implementação)** - `bb22095c` (feat, com teste)
2. **Task 2: Teste-guarda de segredo — chave, base64 e prompt nunca em log nem em arquivo versionado** - `fab0dc02` (test)

_Task 1 seguiu RED→GREEN dentro do mesmo commit (teste escrito e executado contra o comando ausente — `CommandNotFoundException` confirmado — antes de escrever `LimparReferenciasCriativos.php`); Task 2 é puramente um teste-guarda sobre código já existente (160-02), por isso só existe o commit `test`, sem `feat` — nenhuma produção precisou mudar._

## Files Created/Modified

- `app/Console/Commands/LimparReferenciasCriativos.php` - comando `creative:limpar-referencias`, molde `MlAcervoCleanup`
- `config/services.php` - `services.creative.retencao_referencias_horas` (default 48h, via `CREATIVE_RETENCAO_HORAS`)
- `.env.example` - `CREATIVE_RETENCAO_HORAS=48` documentado; `GEMINI_API_KEY=` confirmado vazio (já estava)
- `app/Http/Controllers/MlbAnuncioController.php` - `criativoAprovar()` chama `referenciaEfemera->apagar()` no fim, em `try/catch`
- `routes/console.php` - `Schedule::command('creative:limpar-referencias')->dailyAt('04:10')->withoutOverlapping()`
- `tests/Feature/Phase160/CriativoRetencaoTest.php` - 9 testes (deleção na aprovação, varredura, dry-run, idempotência, --orfaos)
- `tests/Unit/Phase160/CreativeSegredoLogTest.php` - 4 testes (sucesso, falha, `.env.example`, grep `AIza`)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: contagem de registros/arquivos/bytes calculada a partir dos metadados ANTES de apagar (dry-run e execução real reportam o mesmo número), e o teste-guarda de segredo trocou `Log::spy()` por um listener do evento `MessageLogged` depois de medir, na primeira execução, que `shouldHaveReceived()` verifica com `atLeast(1)` implícito no instante da chamada — antes de qualquer encadeamento conseguir afrouxar para "zero ou mais".

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking issue] `Log::spy()` + `shouldHaveReceived($nivel)->withArgs()->zeroOrMoreTimes()` falhava para nível sem nenhuma chamada**
- **Found during:** Task 2, primeira execução do teste-guarda
- **Issue:** `Mockery\Mock::shouldHaveReceived($method)` (ver `vendor/mockery/mockery/library/Mockery/Mock.php:802-817`) cria a expectativa com `atLeast()->once()` e **já chama `$director->verify()` antes de retornar** — ou seja, a verificação "foi chamado pelo menos 1 vez" acontece no instante de `Log::shouldHaveReceived('debug')`, antes de qualquer `->withArgs()`/`->zeroOrMoreTimes()` encadeado ter chance de afrouxar a contagem. Isso fazia a asserção genérica "para todo nível, nenhuma chamada vaza o segredo" (varrendo `info`/`warning`/`error`/`debug`) lançar `InvalidCountException` só porque um dos níveis não foi chamado naquele cenário — um falso positivo que não tem nada a ver com segredo vazado.
- **Fix:** Trocado por um listener do evento `Illuminate\Log\Events\MessageLogged` (`Event::listen`), que captura mensagem+contexto de TODA chamada real de log, em qualquer nível, sem exigir cardinalidade mínima por nível. Canal `logging.default = 'null'` no `setUp()` evita escrever em `storage/logs/laravel.log` durante o teste, sem impedir o evento de disparar (o `Illuminate\Log\Logger` despacha `MessageLogged` por cima de qualquer handler Monolog).
- **Files modified:** `tests/Unit/Phase160/CreativeSegredoLogTest.php` (nenhum arquivo de produção)
- **Verification:** `CreativeSegredoLogTest` — 4/4 verde, 25 assertions
- **Commit:** `fab0dc02`

---

**Total deviations:** 1 (Rule 3, restrita ao próprio arquivo de teste — nenhum código de produção foi alterado por esta deviation)
**Impact on plan:** Nenhum impacto no comportamento entregue; a mecânica de captura do teste-guarda mudou, a cobertura (sucesso + falha, chave + prompt + base64 nos dois sentidos) permanece exatamente a do `<behavior>` do plano.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum além do já registrado no `<threat_model>` do `160-04-PLAN.md` (T-160-21 a T-160-25) — a implementação seguiu as mitigações descritas ali: janela de 48h >> `LIMITE_MINUTOS`, seleção por linha (nunca por disco) exceto no ramo opt-in `--orfaos`, `--dry-run` disponível para conferência antes da primeira execução em produção, deleção na aprovação com `try/catch` que não desfaz a aprovação em caso de falha, e o teste-guarda de segredo cobrindo sucesso e falha.

## Verification Results

1. `CriativoRetencaoTest` — **9/9 verde** (44 assertions).
2. `CreativeSegredoLogTest` — **4/4 verde** (25 assertions).
3. `phpunit --filter=Criativo` (fatias 1-3, nomenclatura em português) — **41/41 verde**, sem regressão (32 das fatias 1-3 + 9 desta fatia 4).
4. `php artisan schedule:list | grep creative` — comando aparece agendado, `10 4 * * *` (04:10 BRT), "Next Due" calculado corretamente.
5. `grep -rn "AIza" app/ config/ .env.example | wc -l` → **0**.
6. Regressão ampla (fora do `<verification>` do plano, por segurança): `tests/Feature/Phase160` + `tests/Unit/Phase160` + `tests/Unit/GeminiImageProviderTest.php` juntos — **66/66 verde** (261 assertions): os 53 testes já verdes das ondas 1-3 + spike (32 + 10 + 11) permanecem intactos, somados aos 13 novos desta onda (9 + 4).
7. `php artisan creative:limpar-referencias --dry-run` rodado localmente (após `php artisan migrate` aplicar a migration pendente da 160-01 no MySQL local) — relatou `0 registro(s), 0 arquivo(s), 0 byte(s)` sem apagar nada, confirmando o comportamento seguro em banco vazio.

## Self-Check: PASSED

- `app/Console/Commands/LimparReferenciasCriativos.php` — FOUND
- `tests/Feature/Phase160/CriativoRetencaoTest.php` — FOUND
- `tests/Unit/Phase160/CreativeSegredoLogTest.php` — FOUND
- `config/services.php` (bloco `retencao_referencias_horas`) — FOUND
- `routes/console.php` (agendamento `creative-limpar-referencias`) — FOUND
- `app/Http/Controllers/MlbAnuncioController.php` (chamada a `referenciaEfemera->apagar()`) — FOUND
- Commit `bb22095c` — FOUND (git log)
- Commit `fab0dc02` — FOUND (git log)

## Next Phase Readiness

Esta era a ÚLTIMA onda autônoma da Fase 160 (v24.0). A fatia fina ponta a ponta está completa: upload da referência (160-01) → geração real com Gemini (160-02) → aprovação que sobe ao Mercado Livre (160-03) → retenção que não acumula foto de cliente + teste-guarda de segredo (160-04). O plano 160-05 é checkpoint humano e não foi executado por este agente.

---
*Phase: 160-fatia-fina-ponta-a-ponta-um-criativo-real-do-upload-aprova-o*
*Completed: 2026-10-02*
