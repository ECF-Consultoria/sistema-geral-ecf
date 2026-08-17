---
phase: 129-webhook-clicksign-v22-0
plan: 02
subsystem: api
tags: [laravel, clicksign, hmac, webhook, sandbox-empirico]

# Dependency graph
requires:
  - phase: 129-01
    provides: "tabela contrato_assinatura_eventos, ClicksignHmacVarredura (varredura pura), comando clicksign:verificar-assinatura, rota-sonda temporária"
provides:
  - "ClicksignHmacVarredura::FORMULA_CONFIRMADA = hmac_body_chave_secret — medida contra 5/5 eventos reais do sandbox, gate A1 fechado"
  - "Fixture externa (ClicksignHmacFixtureExternaTest) provando a fórmula sem falso verde do Pitfall E"
  - "Registro completo da sessão de medição em 129-GATE.md e CLICKSIGN-SANDBOX-EMPIRICO.md §12"
  - "6 achados operacionais novos (deadline_partial_signature_action=closed confirmado, forma real do payload, rascunho inerte, ausência de link de assinatura na API, rajada de eventos retroativos na ativação, consultarEnvelope desembrulhado)"
affects: ["129-03", "129-04", "129-05", "130", "131", "132"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Fixture de assinatura HMAC calculada por php -r isolado, nunca pelo código de produção (Pitfall E)"
    - "Gate empírico bloqueante fechado por medição real, nunca por leitura de documentação (D-08)"

key-files:
  created:
    - tests/Feature/Phase129/ClicksignHmacFixtureExternaTest.php
  modified:
    - app/Support/Clicksign/ClicksignHmacVarredura.php
    - tests/Unit/Phase129/ClicksignHmacVarreduraTest.php
    - routes/web.php
    - .planning/phases/129-webhook-clicksign-v22-0/129-GATE.md
    - .planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md
    - .planning/REQUIREMENTS-v22.md
  deleted:
    - app/Http/Controllers/Api/ClicksignSondaHmacController.php
    - tests/Feature/Phase129/ClicksignSondaHmacTest.php

key-decisions:
  - "Gate A1 fechado: hmac_body_chave_secret (hash_hmac('sha256', body, secret), hex, prefixo sha256=) — confirmado em 5/5 eventos reais distintos, as outras 3 candidatas falharam nos 5"
  - "STACK.md estava errado (hash('sha256', body . secret)); PITFALLS.md estava certo — decisão A1 do REQUIREMENTS-v22.md resolvida a favor da segunda pesquisa"
  - "Gates #6 (deadline) e #7 (refusal) permanecem NÃO MEDIDOS por esta rodada — janela de sessão foi usada inteira para o gate bloqueante A1; nenhuma suposição foi usada para preenchê-los"
  - "CLICK-03 NÃO marcada como concluída: a recusa real de webhook (401) só existe quando o receiver de produção do plano 129-03 ligar sobre esta fórmula"
  - "Túnel cloudflared e php artisan serve DEIXADOS DE PÉ deliberadamente ao fim desta sessão — usuário sinalizou intenção de medir gates #6/#7 em seguida"

patterns-established:
  - "Teste de fixture externa: FIXTURE_SECRET/FIXTURE_BODY/FIXTURE_EXTERNA literais, comando php -r documentado no docblock, nunca chamando o código de produção para gerar o valor esperado"

requirements-completed: [CLICK-03]
# CLICK-03 está no frontmatter do plano mas NÃO foi marcada como Done em
# REQUIREMENTS-v22.md de propósito (ver key-decisions acima) — só a A1
# (decisão em aberto, não requirement) foi resolvida por este plano.

# Metrics
duration: ~25min (Task 2 apenas; Task 1 foi checkpoint humano medido fora desta sessão de continuação)
completed: 2026-08-13
---

# Phase 129 Plan 02: Gate A1 fechado — fórmula do Content-Hmac medida contra webhook real Summary

**`ClicksignHmacVarredura::FORMULA_CONFIRMADA = hmac_body_chave_secret`, confirmada em 5 de 5 eventos reais do sandbox Clicksign via túnel cloudflared; rota-sonda temporária removida, fixture externa prova a fórmula sem falso verde.**

## Performance

- **Duration:** ~25 min (Task 2, agente de continuação)
- **Started:** 2026-08-13T13:15:00Z (aprox.)
- **Completed:** 2026-08-13T13:39:02Z
- **Tasks:** 2/2 (Task 1 = checkpoint humano, satisfeito por medição real do usuário antes desta sessão de continuação; Task 2 = execução automática)
- **Files modified:** 6 modificados, 1 criado, 2 removidos

## Accomplishments
- **Gate A1 (bloqueante, sem plano B) fechado por medição real**: `hmac_body_chave_secret` bateu em 5 de 5 eventos reais distintos (`add_signer` x4 + `update_deadline`), as outras 3 candidatas falharam nos 5 — não sobrou ambiguidade
- `ClicksignHmacVarredura::confere()` deixou de lançar `RuntimeException` — o fusível de "fórmula ainda não medida" foi desarmado corretamente, com o valor certo
- Teste de fixture externa (`ClicksignHmacFixtureExternaTest`) prova a fórmula contra um hex calculado por um `php -r` isolado, documentado no próprio teste — quebra deliberadamente o falso verde do Pitfall E
- Rota pública temporária `POST /api/webhooks/clicksign-sonda` removida junto do controller e do teste; a verificação sobrevive só no comando `clicksign:verificar-assinatura`, sem superfície pública
- `129-GATE.md` registra o resultado completo: as 4 saídas booleanas, o corpo bruto anonimizado de um evento real, a comparação com as duas formas incompatíveis da doc oficial, e 6 achados operacionais novos — incluindo `deadline_partial_signature_action: "closed"` confirmado ao vivo (hipótese mais importante da pesquisa sobre fechamento parcial)
- `CLICKSIGN-SANDBOX-EMPIRICO.md` ganhou a §12 (quinta sessão) e o gate #1 da tabela §8 foi marcado fechado
- `REQUIREMENTS-v22.md`: A1 marcada como resolvida nas 3 ocorrências (seção de decisões em aberto, tabela de decisões → fase, tabela de gate empírico)

## Task Commits

1. **Task 1: GATE A1 — medição contra webhook real do sandbox (D-07/D-08)** — checkpoint humano, sem commit de código; satisfeito pela medição real do usuário fora desta sessão de continuação (resultado fornecido no prompt desta sessão)
2. **Task 2: Fixar a fórmula medida, fixture externa e remoção da rota-sonda** - `92acfa6f` (feat)

_Nenhuma task teve TDD — plano `type="execute"` padrão. Task 2 incluiu, além dos arquivos listados no plano, a atualização de `tests/Unit/Phase129/ClicksignHmacVarreduraTest.php` (Rule 1 — o teste antigo assumia `FORMULA_CONFIRMADA === null` e teria quebrado após a fixação da fórmula; foi reescrito para provar o comportamento correto de `confere()` agora que o gate fechou)._

## Files Created/Modified
- `app/Support/Clicksign/ClicksignHmacVarredura.php` - `FORMULA_CONFIRMADA = 'hmac_body_chave_secret'`; docblock reescrito registrando data, origem (webhook real, não doc) e o lembrete de reconfirmar contra produção na Fase 132
- `tests/Feature/Phase129/ClicksignHmacFixtureExternaTest.php` (novo) - fixture externa (`FIXTURE_SECRET`/`FIXTURE_BODY`/`FIXTURE_EXTERNA` literais, hex gerado por `php -r` isolado), 3 casos: aceita hex correto, recusa hex alterado em 1 caractere, `calcular()` bate exatamente com o externo
- `tests/Unit/Phase129/ClicksignHmacVarreduraTest.php` - troca o teste que esperava `RuntimeException` por 3 testes que provam `FORMULA_CONFIRMADA` e o comportamento de `confere()` (aceita/recusa)
- `app/Http/Controllers/Api/ClicksignSondaHmacController.php` (removido) - superfície pública temporária, não é mais necessária
- `tests/Feature/Phase129/ClicksignSondaHmacTest.php` (removido) - teste da rota removida
- `routes/web.php` - bloco de rota `webhooks.clicksign.sonda` removido, substituído por comentário explicando onde a capacidade foi para
- `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md` - preenchido por completo: resultado do gate A1, achados operacionais, gates #6/#7 como NÃO MEDIDO, pergunta A4 (conta vs envelope) como não observada, checklist de fechamento
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` - nova §12 (quinta sessão), gate #1 da tabela §8 marcado fechado
- `.planning/REQUIREMENTS-v22.md` - A1 marcada resolvida (3 ocorrências), gate empírico #1 fechado nas duas tabelas

## Decisions Made
- A fórmula vencedora é `hmac_body_chave_secret` — decisão do usuário via medição real, não escolha do executor. Ver `key-decisions` no frontmatter para o registro completo.
- Não marcar CLICK-03 como concluída: a A1 (decisão de algoritmo) está resolvida, mas a *capacidade* de recusar webhook com assinatura inválida só nasce no receiver de produção do plano 129-03.
- Túnel e servidor local deixados de pé por instrução explícita do prompt desta sessão (o usuário pode querer medir os gates #6/#7 em seguida) — registrado como pendência consciente no checklist do `129-GATE.md`, não como esquecimento.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `ClicksignHmacVarreduraTest` teria quebrado após fixar a fórmula**
- **Found during:** Task 2, ao rodar a suíte de verificação antes de prosseguir
- **Issue:** O teste `confere_lanca_enquanto_formula_confirmada_for_nula` assumia `FORMULA_CONFIRMADA === null` e esperava `RuntimeException` — comportamento que deixou de existir assim que a fórmula foi fixada (é exatamente o propósito da task)
- **Fix:** Substituído por 3 testes que provam o novo comportamento correto: `FORMULA_CONFIRMADA` é a chave medida, `confere()` aceita um header calculado com a fórmula confirmada, `confere()` recusa um header que não bate
- **Files modified:** tests/Unit/Phase129/ClicksignHmacVarreduraTest.php
- **Verification:** `php artisan test --filter="ClicksignHmacFixtureExternaTest|ClicksignHmacVarreduraTest"` → 11 passed, 24 assertions
- **Committed in:** 92acfa6f (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug de teste obsoleto)
**Impact on plan:** Necessário para a suíte de verificação do próprio plano passar (`ClicksignHmacFixtureExternaTest|ClicksignHmacVarreduraTest` sai com código 0). Não é scope creep — é consequência direta de fixar a constante que o teste antigo assumia nula.

## Issues Encountered
None além do deviation acima.

## User Setup Required

None — a medição real (túnel + cadastro de webhook no sandbox) já foi feita pelo usuário antes desta sessão de continuação. `CLICKSIGN_WEBHOOK_SECRET` do sandbox já está no `.env` local.

## Next Phase Readiness
- Gate A1 fechado: o plano 129-03 (receiver de produção) pode chamar `ClicksignHmacVarredura::confere()` com confiança — não lança mais e usa a fórmula certa.
- `CLICKSIGN-SANDBOX-EMPIRICO.md` §12 dá ao 129-03 a forma real do corpo do webhook (`event.name`/`document.key`, não JSON:API) — extração de `name`/`clicksign_envelope_id` não precisa mais chutar.
- O achado `deadline_partial_signature_action: "closed"` confirmado ao vivo é insumo direto para o gate de liberação do plano 129-04 (CLICK-05): decidir sempre por reconsulta ao envelope, nunca pelo evento isolado.
- **Pendência real para a fase**: gates #6 (`deadline`) e #7 (`refusal`) continuam NÃO MEDIDOS. O plano 129-05 cobre por fixture sintética, mas a medição real fica em aberto para uma próxima sessão — o túnel e o `php artisan serve` foram deixados rodando de propósito para isso.
- Pergunta A4 (webhook por conta ou por envelope) segue não observada — não bloqueia nada nesta fase, mas importa para o desenho do cutover de produção (Fase 132).
- Nenhum bloqueio para o plano 129-03 iniciar.

## Self-Check: PASSED

- `app/Http/Controllers/Api/ClicksignSondaHmacController.php` → MISSING (removido, esperado)
- `tests/Feature/Phase129/ClicksignSondaHmacTest.php` → MISSING (removido, esperado)
- `tests/Feature/Phase129/ClicksignHmacFixtureExternaTest.php` → FOUND
- `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md` → FOUND, 336 linhas (≥ 40 exigidas)
- Commit `92acfa6f` → FOUND em `git log`
- `grep -c "FORMULA_CONFIRMADA = null" app/Support/Clicksign/ClicksignHmacVarredura.php` → `0`
- `php artisan route:list --path=webhooks` → não lista `clicksign-sonda`
- Suíte cumulativa `Phase124|Phase125|Phase126|Phase127|Phase128|Phase129` → 287 passed / 945 assertions, exit 0 (variação em relação ao baseline 286/955 é exatamente a esperada: -4 testes/-13 assertions da sonda removida, +3 testes/+3 assertions da fixture, +2 testes/+2 assertions líquidos no teste unitário reescrito)

---
*Phase: 129-webhook-clicksign-v22-0*
*Completed: 2026-08-13*
