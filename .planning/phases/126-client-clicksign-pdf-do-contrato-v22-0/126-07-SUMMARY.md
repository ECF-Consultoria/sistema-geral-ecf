---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 07
subsystem: api
tags: [clicksign, http-client, tdd, templates, docx]

# Dependency graph
requires:
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 126-02)
    provides: "ClicksignClient com enviar()/baseRequest()/montarEnvelope()/cancelarEnvelope() e ClicksignException"
provides:
  - "listarModelos(), criarModelo(), excluirModelo() — CRUD do recurso templates"
  - "anexarDocumentoPorModelo() e montarEnvelopePorModelo() — caminho de documento por modelo (D-16)"
  - "montarEnvelopeComum() privado, com o rollback D-12 compartilhado entre upload e modelo"
  - "ClicksignException::fromResponse() com 403 diagnosticando a causa certa (email da API x conta sem acesso a modelos)"
affects: [126-10, 126-11, 127]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Closure injetada em método privado para compartilhar sequência com rollback entre dois caminhos de anexação de documento"
    - "Fixture com rótulo ENTRADA/SAÍDA + MEDIDO/NÃO MEDIDO no docblock (D-15)"

key-files:
  created:
    - tests/Feature/Phase126/ClicksignClientModeloTest.php
  modified:
    - app/Services/Clicksign/ClicksignClient.php
    - app/Exceptions/ClicksignException.php
    - tests/Fixtures/ClicksignSandboxFixtures.php
    - tests/Feature/Phase126/ClicksignClientTest.php

key-decisions:
  - "template.data serializa como stdClass vazio quando $variaveis está vazio, nunca array PHP vazio (evita '[]' no JSON — medido §9.6)"
  - "Guarda de nome de variável usa /^[a-z0-9_]+$/i (literal do plano), rejeitando chave numérica e @/#/! antes de qualquer requisição"
  - "enviar() ganhou parâmetro opcional $query (default []) em vez de mudar assinatura para os chamadores existentes"
  - "403 agora sempre cita o detail da API + as duas causas conhecidas, em vez de assumir uma única causa fixa"

patterns-established:
  - "montarEnvelopeComum(dadosEnvelope, signatarioCliente, passoAnexar, Closure) — novo caminho de documento no client não duplica sequência/rollback"

requirements-completed: [CLICK-01]

# Metrics
duration: ~35min
completed: 2026-08-10
---

# Phase 126 Plan 07: Caminho de modelo no ClicksignClient Summary

**`ClicksignClient` ganha CRUD de `templates` e `anexarDocumentoPorModelo()`/`montarEnvelopePorModelo()` na forma exata medida contra o sandbox (§9.6), com o rollback D-12 compartilhado com o caminho de upload via um método privado + closure — e o 403 de "conta sem acesso a modelos" deixa de ser confundido com o de "e-mail da API não configurado".**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-10T18:00Z (aprox.)
- **Completed:** 2026-08-10T21:12Z
- **Tasks:** 3/3 completos
- **Files modified:** 5 (1 criado, 4 modificados)

## Accomplishments

- `listarModelos()`, `criarModelo()`, `excluirModelo()` — CRUD completo do recurso `templates`, todos passando pelo `enviar()` existente (retry D-11, log seguro, `ClicksignException` de graça).
- `anexarDocumentoPorModelo()` monta o payload exato medido na §9.6 do empírico: `attributes.filename` + `attributes.template.{key,data}`, nunca `content_base64` nem `template_id`. `template.data` sai como objeto JSON (`{}`) mesmo vazio — a armadilha central desta fase (array PHP vazio serializa como `[]`, e a API recusa).
- Duas guardas client-side antes de qualquer requisição: nome do modelo tem que terminar em `.docx`; nome de variável só aceita `/^[a-z0-9_]+$/i` (chave numérica ou `@`/`#`/`!` lança antes de gastar requisição da janela de 20).
- `montarEnvelope()` (upload) e `montarEnvelopePorModelo()` (modelo) agora compartilham a sequência de 4 signatários + 8 requisitos + ativação + rollback via `montarEnvelopeComum()` privado, que recebe a forma de anexar como closure — nenhum dos dois rollbacks pode dessincronizar do outro por construção.
- 403 da Clicksign passou a compor a mensagem com o `detail` real da API e citar as duas causas conhecidas (e-mail da API não configurado × conta em plano sem acesso a Modelos/Automação) em vez de assumir sempre a primeira causa.
- `ClicksignClientEnvelopeTest.php` (o teste do caminho de upload) **não foi tocado** — confirmado por `git status --porcelain` vazio antes do commit da Task 3 — prova de que a refatoração do rollback não regrediu o caminho existente.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Testes e fixtures do caminho de modelo (RED)** - `d42204a6` (test)
2. **Task 2: CRUD do recurso `templates` + 403 com diagnóstico certo (GREEN parcial)** - `287ca0cf` (feat)
3. **Task 3: `anexarDocumentoPorModelo()` + `montarEnvelopePorModelo()` com rollback compartilhado (GREEN)** - `39f95b6a` (feat)

**Plan metadata:** ver commit deste SUMMARY.

## Files Created/Modified

- `tests/Feature/Phase126/ClicksignClientModeloTest.php` - 19 testes cobrindo listarModelos/criarModelo/excluirModelo/anexarDocumentoPorModelo/montarEnvelopePorModelo/403
- `tests/Fixtures/ClicksignSandboxFixtures.php` - 5 fixtures novas do recurso `templates`, cada uma rotulada ENTRADA/SAÍDA e MEDIDO/NÃO MEDIDO
- `app/Services/Clicksign/ClicksignClient.php` - 5 métodos novos + `enviar()` com `$query` opcional + `montarEnvelope()`/`montarEnvelopePorModelo()` refatorados sobre `montarEnvelopeComum()`
- `app/Exceptions/ClicksignException.php` - `fromResponse()` compõe o 403 com o `detail` da API via `mensagem403()` privado
- `tests/Feature/Phase126/ClicksignClientTest.php` - asserção do teste de 403 reforçada (exige também "Configurações → API")

## Decisions Made

- **`template.data` vazio vira `stdClass`, não array PHP vazio.** É o ponto que a §9.6 do empírico mediu como divisor de água (`[]` → 400 "data deve ser um hash"). O teste inspeciona o JSON bruto da requisição (`$request->body()`), não o array PHP decodificado, porque `[]` e `{}` são indistinguíveis nesse nível.
- **Regex de validação de variável usa exatamente `/^[a-z0-9_]+$/i`**, copiado literalmente do texto do plano — mais restritivo que o estritamente medido (só `@`/`#`/`!` foram confirmados como proibidos), mas evita qualquer caractere não testado chegar à API e gastar uma requisição da janela de 20.
- **`enviar()` ganhou `$query` como último parâmetro opcional** (default `[]`) em vez de introduzir um método `enviarComQuery()` paralelo — mantém um único ponto de política (retry, log, exceção) para todos os chamadores, inclusive o novo `listarModelos()`.
- **Refactor do rollback via closure**, não via herança nem interface: `montarEnvelopeComum(dadosEnvelope, signatarioCliente, passoAnexar, Closure $anexarDocumento)` recebe a forma de anexar como função anônima. Mantém `montarEnvelope()` e `montarEnvelopePorModelo()` como métodos públicos finos, sem duplicar a sequência de 4 signatários/8 requisitos/ativação/rollback.
- **403 passou a citar sempre as DUAS causas conhecidas**, mesmo quando o `detail` já aponta uma delas claramente — decisão deliberada do plano (linha "as DUAS causas conhecidas como caminho de verificação"), para não presumir qual das duas está ativa numa conta real sem o operador conferir.

## Deviations from Plan

None - plano executado exatamente como escrito. As únicas escolhas de implementação (regex exata, `stdClass` vs `array` vazio, assinatura de `montarEnvelopeComum()`) já estavam sob "Claude's Discretion" do CONTEXT.md ou explicitamente descritas no `<a_forma_medida>`/`<action>` do próprio plano.

## Issues Encountered

None. RED confirmado na Task 1 (19 falhas por método inexistente, nenhuma falha de asserção), GREEN parcial confirmado na Task 2 (CRUD + 403 verdes, 10 métodos de modelo ainda ausentes), GREEN completo confirmado na Task 3.

## User Setup Required

None - nenhuma configuração de serviço externo. Nenhuma chamada real à API da Clicksign foi feita (regra do ambiente desta sessão); tudo verificado com `Http::fake()`.

## ⚠️ O que este plano NÃO prova

Repetindo a ressalva do próprio plano, porque é fácil perder de vista depois que a suíte fica verde: **`Http::fake()` confirma que o payload é o que decidimos enviar — não que a Clicksign aceita esse payload.** Foi exatamente esse ponto cego que deixou `communicate_by` (§9.1 do empírico) passar nos testes da Fase 126-02 e quebraria 100% dos envelopes em produção.

Continuam **NÃO MEDIDOS** contra o sandbox real (delegados ao plano 126-11, que precisa de um `.docx` cadastrado):
- Se `template.data` aceita as chaves exatamente como `{{nomes}}` do `.docx`, e o que acontece com variável faltando ou sobrando.
- O comportamento de tabela em loop (`{{#array}}…{{/array}}`).
- Se excluir um modelo (`excluirModelo()`) remove documento já gerado/assinado — dívida da D-16 ainda aberta (`126-RESEARCH-MODELOS.md` §3, Open Question 2). Docblock do método já avisa para não excluir modelo com envelope `running`.
- O 403 de "conta sem acesso a modelos" (`erro403ContaSemAcessoAModelos()`) é fixture **NÃO MEDIDA** — texto copiado literalmente da documentação oficial (`api-criar-modelo`), nunca observado contra o sandbox real. Se a Clicksign mudar essa mensagem, o teste continua passando (ele testa a composição, não a string exata contra a API).

## Known Stubs

Nenhum. Todos os métodos novos são funcionais de ponta a ponta contra `Http::fake()` — não há placeholder, retorno vazio hardcoded nem TODO pendente no código de produção.

## Next Phase Readiness

- O `ClicksignClient` está pronto para o plano 126-11 cadastrar um `.docx` real no sandbox e medir os pontos listados acima — nenhuma mudança de assinatura é esperada, só confirmação de comportamento.
- A Fase 127 pode chamar `montarEnvelopePorModelo()` de dentro de um job (D-14) assim que tiver `template_id` (config, ainda não criada) e `montarDados()` (plano 126-04) como produtor de `$variaveis`.
- O caminho de upload (`montarEnvelope()`/`anexarDocumento()`) permanece intacto e testado — nenhum bloqueio para os planos que ainda dependem dele.

---
*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Completed: 2026-08-10*

## Self-Check: PASSED

Todos os arquivos citados e os 4 hashes de commit (`d42204a6`, `287ca0cf`, `39f95b6a`, `a7484bd9`) foram confirmados no disco e no `git log`.
