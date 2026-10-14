---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 04
subsystem: Bling ERP — ciclo OAuth e keep-alive
tags: [bling, oauth, cache-lock, rate-limit, scheduler, state]

requires:
  - phase: 184-01
    provides: "model BlingConexao (cast encrypted, $hidden, estaConfigurado(), expiraEm())"
provides:
  - "BlingOAuthService::urlDeAutorizacao()/consumirState()/trocarCode()/renovar()/revogar()/acessoValido() — ciclo OAuth completo do Bling"
  - "comando bling:renovar-tokens — keep-alive espalhado, no máximo 10 renovações por minuto"
  - "agendamento horário em routes/console.php (só acréscimo)"
affects:
  - "184-06 (BlingClient): vai chamar BlingOAuthService::acessoValido() como ponto único de leitura do token"
  - "184-07 (link assinado e landing): vai chamar urlDeAutorizacao()/consumirState()/trocarCode()"
  - "184-11 (desconectar na tela): vai chamar revogar()"

tech-stack:
  added: []
  patterns:
    - "Molde de OAuth copiado de MercadoLivreService: state em cache de uso único, Cache::lock por entidade (não global) na renovação, fresh() dentro do lock, só revoga em erro de credencial real"
    - "Sleep::sleep() do próprio Laravel como colaborador fingível (Sleep::fake() nos testes) em vez de sleep()/usleep() direto — permite testar espaçamento sem esperar de verdade"

key-files:
  created:
    - app/Services/Bling/BlingOAuthService.php
    - app/Console/Commands/BlingRenovarTokens.php
    - tests/Feature/Bling/BlingOAuthServiceTest.php
    - tests/Feature/Bling/BlingRenovarTokensTest.php
  modified:
    - routes/console.php

decisions:
  - "renovar() grava SEMPRE o refresh_token devolvido pelo Bling (suposição 5 do RESEARCH §5, não medida — TODO(184-13) citando a suposição e pedindo confirmação na conta de teste)"
  - "429 nunca retenta na hora dentro de renovar(): status fica intacto e o erro vai para last_error — a retentativa fica para a PRÓXIMA chamada (do comando ou do BlingClient), nunca em loop apertado"
  - "revogar() tenta o endpoint no Bling mas apaga o token local INDEPENDENTE do resultado (T-184-23, risco aceito): desconectar é ação do nosso lado"
  - "bling:renovar-tokens usa Sleep::sleep() (facade do Laravel) em vez de um Closure injetável customizado — é o mecanismo já blessed pelo framework para pausas fingíveis em teste, evitando reinventar o que Sleep::fake() já resolve"
  - "agendamento HORÁRIO (não diário): o access_token do Bling dura ~6h e o refresh_token 30 dias — uma rodada por hora já espalha a carga naturalmente, e uma rodada diária única reproduziria a mesma rajada que o padrão do ml:refresh-tokens tem"

requirements-completed: [BLING-09, BLING-10, BLING-11, BLING-12, BLING-13]

metrics:
  duration: "~55 min"
  completed: 2026-10-13
---

# Phase 184 Plan 04: Ciclo OAuth do Bling e keep-alive espalhado Summary

`BlingOAuthService` com as quatro travas que a API do Bling exige (state de uso único, code trocado
uma vez só, renovação serializada por conexão, keep-alive espalhado) e o comando
`bling:renovar-tokens`, que renova no máximo 10 conexões por minuto — nunca a rajada do
`ml:refresh-tokens`, que bloquearia o IP da VPS para todos os clientes do Bling a partir de ~20 contas.

## Performance

- **Duration:** ~55 min
- **Completed:** 2026-10-13
- **Tasks:** 3/3
- **Files modified:** 5 (4 criados + `routes/console.php` com só acréscimo)

## Accomplishments

- `urlDeAutorizacao()` gera `state` aleatório (`Str::uuid()`) de uso único em cache (TTL 7 dias),
  preso a empresa/ator/destino, e a URL **nunca** leva `redirect_uri`/`scope` — o Bling os ignora e
  mandá-los esconderia um erro de cadastro do app.
- `consumirState()` é a idempotência que impede o reuso do `code`: a segunda chamada com o mesmo
  `state` devolve `null` e o callback para antes de falar com o Bling de novo.
- `trocarCode()` troca o `code` com as credenciais **só** no header `Authorization: Basic` (nunca no
  corpo), header `enable-jwt: 1`, URL **com** `/Api/v3` (a forma sem `/Api/v3` dá 403), e **zero**
  retentativa — reusar um `code` ainda válido revoga o cliente no Bling.
- `renovar()` trava por `Cache::lock("bling-refresh-{id}")` **por conexão**, nunca por uma chave
  global (que serializaria clientes diferentes entre si); grava **sempre** o `refresh_token` novo;
  **429 nunca retenta na hora** e mantém a conexão `ativa`; **5xx mantém a conexão ativa**; só revoga
  em `invalid_grant`/`UNAUTHORIZED_ERROR`.
- `revogar()` tenta o endpoint `/Api/v3/oauth/revoke` no Bling, mas apaga o token local **independente**
  do resultado — desconectar é ação do nosso lado, não pode depender do ERP do cliente estar no ar.
- `acessoValido()` é o ponto único de leitura que o `BlingClient` (plano 06) vai usar.
- `bling:renovar-tokens` seleciona só conexão `ativa` com `refresh_token`, perto de expirar
  (`--janela`, padrão 120 min), ordenada pela mais perto de vencer; espaça em lotes de
  `MAX_POR_MINUTO = 10` (metade do teto real de 20, de propósito) via `Sleep::sleep()`; um erro numa
  conexão não interrompe as demais (mesmo padrão de `AdmanService::syncAll()`); `--dry-run` lista sem
  chamar o Bling.
- `routes/console.php` ganhou o agendamento horário, com `withoutOverlapping(55)`, `onOneServer()` e
  `runInBackground()` — só acréscimo, provado por `git diff` sem nenhuma linha removida.

## Task Commits

1. **Task 1: BlingOAuthService — state, URL de autorização e troca do code** — `d0c31e53` (feat)
2. **Task 2: Renovação travada por conta e revogação** — `f5e5f845` (feat)
3. **Task 3: Comando bling:renovar-tokens com keep-alive espalhado** — `a563295d` (feat)

**Plan metadata:** (este commit — docs)

_Nota: as três tasks têm `tdd="true"`, mas seguem a convenção já registrada nos planos anteriores da
fase ("não há onda de andaimes vermelhos") — teste e código nasceram no mesmo commit de cada task.
Como as Tasks 1 e 2 produzem código no MESMO arquivo (`BlingOAuthService.php`), o conteúdo foi escrito
por completo primeiro para validar o desenho inteiro, e então **retroativamente separado em duas
versões** (Task 1 só com `urlDeAutorizacao`/`consumirState`/`trocarCode`; Task 2 acrescentando
`renovar`/`revogar`/`acessoValido`) para preservar um commit por task, no mesmo espírito dos commits
incrementais do plano 02 sobre `TradutorBlingParaPortal.php`._

## Files Created/Modified

- `app/Services/Bling/BlingOAuthService.php` (359 linhas) — o ciclo OAuth completo
- `app/Console/Commands/BlingRenovarTokens.php` — o keep-alive espalhado
- `tests/Feature/Bling/BlingOAuthServiceTest.php` (16 testes) — as quatro travas
- `tests/Feature/Bling/BlingRenovarTokensTest.php` (5 testes) — o teto de 10/min e a tolerância a erro
- `routes/console.php` — agendamento horário (só acréscimo)

## Decisions Made

Ver frontmatter `decisions`. Destaque: o uso de `Illuminate\Support\Sleep` (facade do próprio
Laravel) em vez de inventar um colaborador injetável customizado para a pausa do comando —
`Sleep::fake()` já é exatamente o mecanismo que o `<acceptance_criteria>` do plano pedia ("colaborador
injetável... para que o teste a substitua"), e evita escrever `sleep(`/`usleep(` literalmente no
arquivo (o que o gate do plano proíbe).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Comentário no docblock do comando colidia com o próprio gate que descrevia**
- **Encontrado durante:** Task 3
- **Problema:** o docblock de `BlingRenovarTokens` citava literalmente `sleep()`/`usleep()` para
  explicar por que o comando NÃO os usa — e o próprio texto do comentário fazia o
  `grep -cE "^\s*sleep\(|usleep\(" app/Console/Commands/BlingRenovarTokens.php` devolver 1 em vez de 0.
  Mesma classe de armadilha documentada no `184-02-SUMMARY.md` (comentário em prosa colidindo com o
  gate que ele próprio descreve).
- **Fix:** reescrito o comentário sem a string literal `usleep(`, preservando o mesmo sentido ("pausa
  bloqueante direta no código" em vez de nomear a função).
- **Arquivos modificados:** `app/Console/Commands/BlingRenovarTokens.php`
- **Commit:** `a563295d` (mesma task, antes do commit)

**2. [Rule 1 - Bug] Asserção de teste comparando coluna `encrypted` pela query builder**
- **Encontrado durante:** Task 3
- **Problema:** os testes "renova as três conexões" e "uma conexão com erro 500 não interrompe as
  demais" comparavam `access_token` (cast `encrypted`) via
  `BlingConexao::where('access_token', '!=', 'access-antigo')->count()`. Como a coluna crua no banco
  nunca é literalmente igual ao texto em claro (está cifrada), essa comparação é **sempre verdadeira**
  para QUALQUER linha — inclusive a que falhou e manteve o token antigo. O teste "uma conexão com erro
  500 não interrompe as demais" de fato reprovou com esse bug (contou 3 em vez de 2), expondo o
  problema antes do commit.
- **Fix:** troca para `BlingConexao::all()->filter(fn ($c) => $c->access_token !== 'access-antigo')`,
  que lê pelo model (decifra) em vez da query builder (lê a coluna crua).
- **Arquivos modificados:** `tests/Feature/Bling/BlingRenovarTokensTest.php`
- **Commit:** `a563295d` (mesma task, antes do commit)

Nenhum outro desvio. Plano executado como escrito.

## Known Stubs

Nenhum. Este plano é só serviço de backend e comando de console — não há UI nem fluxo com dado vazio
renderizado.

## Threat Flags

Nenhum novo. Todas as superfícies deste plano (endpoints de OAuth do Bling, `Cache::lock`, o comando
agendado) já estavam no `<threat_model>` do próprio `184-04-PLAN.md` (T-184-16 a T-184-23), e nenhum
arquivo tocado introduz superfície fora dele.

## Issues Encountered

Nenhum bloqueio. As duas correções acima foram encontradas e fechadas durante a própria execução da
Task 3, antes de qualquer commit.

## User Setup Required

None — nenhuma configuração externa. As credenciais reais do Bling (`BLING_CLIENT_ID`/`SECRET`) ficam
para o checkpoint humano do plano 13 (184-13-T1), como já registrado no `184-CONTEXT.md`.

## Next Phase Readiness

- `BlingOAuthService::acessoValido()` está pronto para o `BlingClient` (plano 06) chamar como ponto
  único de leitura do token.
- `urlDeAutorizacao()`/`consumirState()`/`trocarCode()` estão prontos para o link assinado e a landing
  pública (plano 07) chamarem diretamente.
- `revogar()` está pronto para a ação "desconectar" da tela (plano 11).
- Nenhum ajuste de contrato esperado. `artisan test --filter=Bling`: **52 passed** (31 dos planos
  01-03 + 21 novos: 16 do `BlingOAuthServiceTest` + 5 do `BlingRenovarTokensTest`), exit 0, sem
  regressão.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-13*

## Self-Check: PASSED

Arquivos:
- FOUND: app/Services/Bling/BlingOAuthService.php
- FOUND: app/Console/Commands/BlingRenovarTokens.php
- FOUND: tests/Feature/Bling/BlingOAuthServiceTest.php
- FOUND: tests/Feature/Bling/BlingRenovarTokensTest.php

Commits:
- FOUND: d0c31e53 (Task 1)
- FOUND: f5e5f845 (Task 2)
- FOUND: a563295d (Task 3)

`artisan test --filter=Bling`: 52 passed (exit 0) — sem regressão nos 31 testes dos planos 01-03.
`git diff 40ba569e..HEAD --stat -- routes/console.php`: 17 inserções, 0 deleções (só acréscimo).
`grep -rn "retry(" app/Services/Bling/`: 0 linhas.
