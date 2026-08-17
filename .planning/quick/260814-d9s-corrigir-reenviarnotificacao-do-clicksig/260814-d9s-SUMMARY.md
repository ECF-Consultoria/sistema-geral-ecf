---
phase: quick-260814-d9s
plan: 01
subsystem: integrations
tags: [clicksign, jsonapi, http-client, tdd, sandbox-medicao]

requires:
  - phase: 126-clicksign-envelope-client
    provides: ClicksignClient com enviar()/baseRequest() e o método reenviarNotificacao() (sem corpo, o bug)
provides:
  - reenviarNotificacao() corrigido enviando corpo JSON:API medido contra o sandbox real
  - teste que assevera o CORPO do POST (não só o status), fechando a lacuna que deixou o bug passar
  - registro empírico (§14) do corpo aceito por POST /envelopes/{id}/signers/{id}/notifications
affects: [131-tela-reenvio-clicksign, clicksign-client]

tech-stack:
  added: []
  patterns:
    - "stdClass() vazio para forçar objeto JSON:API em vez de array PHP vazio (mesma disciplina de anexarDocumentoPorModelo())"
    - "medição contra sandbox real via script de scratchpad temporário, nunca Http::fake() para descobrir forma de payload desconhecida"

key-files:
  created: []
  modified:
    - app/Services/Clicksign/ClicksignClient.php
    - tests/Feature/Phase126/ClicksignClientEnvelopeTest.php
    - tests/Fixtures/ClicksignSandboxFixtures.php
    - .planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md

key-decisions:
  - "Corpo mínimo aceito é {\"data\":{\"type\":\"notifications\",\"attributes\":{}}} — MEDIDO por 2xx real contra o sandbox, não deduzido"
  - "attributes precisa serializar como objeto (new \\stdClass()), nunca array PHP vazio — reincidência da armadilha do §9.6 (template.data)"

requirements-completed: [CLICK-01]

duration: 62min
completed: 2026-08-14
---

# Quick 260814-d9s: Corrigir reenviarNotificacao() do Clicksign Summary

**`reenviarNotificacao()` do Clicksign passou a enviar o corpo JSON:API (`data.type=notifications`,
`data.attributes={}`) medido ao vivo contra o sandbox, e um teste novo assevera o corpo enviado —
não só o status — fechando a lacuna que deixava um `POST` vazio passar despercebido.**

## Performance

- **Duration:** ~62 min
- **Started:** 2026-08-14T09:41:00-03:00 (aprox., primeira requisição de sondagem)
- **Completed:** 2026-08-14T09:45:23-03:00 (último commit de código) + registro do empírico
- **Tasks:** 3/3
- **Files modified:** 4

## Accomplishments

- Medido ao vivo (2 POSTs reais contra o sandbox, dentro do orçamento de 4 requisições) o corpo
  aceito por `POST /envelopes/{id}/signers/{id}/notifications`: `data.type = "notifications"` com
  `data.attributes` presente como objeto vazio.
- `ClicksignClient::reenviarNotificacao()` corrigido para enviar esse corpo — deixa de receber
  `data deve ser informado(a)` da API v3, tanto em teste quanto (esperado) em produção.
- Novo teste (`reenviar_notificacao_envia_corpo_jsonapi_com_data_type_notifications`) assevera o
  corpo via `Http::assertSent()` inspecionando `$request['data']`. Provado manualmente que ele
  REPROVA quando o segundo argumento do `post()` é removido (ciclo RED confirmado, depois
  restaurado para GREEN).
- Tratamento do 429 anti-spam (sem retry, `Http::assertSentCount(1)`, mensagem pt-BR) permanece
  intacto e verde.
- Achado registrado em `CLICKSIGN-SANDBOX-EMPIRICO.md` §14, com ponteiro adicionado na §7 para
  quem ler o achado antigo do rate limit primeiro não repita o erro de mandar `POST` vazio.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Medir empiricamente o corpo aceito** — sem commit (medição em script de scratchpad,
   fora do repositório; nenhum arquivo do repo alterado, conforme o plano)
2. **Task 2a: RED — trava o corpo com teste** — `15602476` (test)
3. **Task 2b: GREEN — corrige reenviarNotificacao()** — `60eeafbf` (fix)
4. **Task 3: Registra o achado no empírico** — `5520e11c` (docs)

_Nota: Task 2 seguiu `tdd="true"` com ciclo RED→GREEN completo: o teste novo foi escrito, provado
que reprova com o `post()` sem corpo (RED, verificado manualmente sem commit do estado quebrado),
e só então o fix foi restaurado e commitado (GREEN)._

## Files Created/Modified

- `app/Services/Clicksign/ClicksignClient.php` — `reenviarNotificacao()` agora envia
  `['data' => ['type' => 'notifications', 'attributes' => new \stdClass()]]`; docblock documenta a
  medição e referencia `CLICKSIGN-SANDBOX-EMPIRICO.md` §14
- `tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` — 3 testes novos: corpo enviado
  (assertSent), 2xx desembrulha `data`, 2xx sem `data` devolve `[]`
- `tests/Fixtures/ClicksignSandboxFixtures.php` — `notificacaoEnviada()`, cópia anonimizada da
  resposta 201 medida
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — nova §14 (sétima sessão de medição) +
  ponteiro na §7

## Decisions Made

- **Corpo mínimo confirmado por 2xx, não por dedução:** a primeira tentativa de medição falhou
  (`400 "attributes deve ser um hash"`) por um bug do PRÓPRIO script de sondagem (array PHP vazio
  serializa como `[]`, não `{}`) — não por exigência real da API. Isso foi diagnosticado a partir
  da mensagem de erro (que aponta `/data/attributes`) antes de gastar mais uma tentativa do
  orçamento, e registrado explicitamente no empírico para não confundir sessões futuras.
- **`new \stdClass()` em vez de array vazio** — mesma disciplina já estabelecida em
  `anexarDocumentoPorModelo()` para `template.data` (§9.6 do empírico); reaplicada aqui porque é
  exatamente a mesma classe de armadilha do PHP/JSON.

## Deviations from Plan

None — plano executado exatamente como escrito. A Task 1 encontrou um efeito colateral (bug do
próprio script de sondagem, não do código de produção) que foi corrigido dentro da própria task de
medição, sem sair do orçamento de 4 requisições nem do escopo.

## Issues Encountered

- A primeira tentativa de POST na Task 1 devolveu `400` por um bug de serialização do script de
  sondagem (array PHP vazio → `[]` em vez de `{}`), não por uma exigência real da API. Resolvido
  reescrevendo a tentativa com `new \stdClass()`, dentro da mesma requisição contabilizada no
  orçamento (2 POSTs usados de 3 permitidos). Nenhum 429 foi observado nesta sessão.

## User Setup Required

None — nenhuma configuração externa necessária.

## Next Phase Readiness

- `reenviarNotificacao()` está pronto para ser chamado pela tela de reenvio (CLICK-07, Fase 131) —
  o corpo correto já sai por baixo do capô.
- Continua **não medido**: se `attributes.message` aceita uma mensagem customizada de entrada (a
  resposta sugere que existe, mas isso está fora do escopo deste quick — precisa de medição
  dedicada antes de qualquer feature que dependa disso).
- O e-mail de reenvio não chegando na caixa do signatário (achado da Fase 126/130, §7) continua em
  aberto — não é escopo deste quick.

---
*Quick task: 260814-d9s*
*Completed: 2026-08-14*

## Self-Check: PASSED

Todos os 5 arquivos citados existem no repositório e os 3 hashes de commit (`15602476`,
`60eeafbf`, `5520e11c`) foram confirmados em `git log --oneline --all`.
