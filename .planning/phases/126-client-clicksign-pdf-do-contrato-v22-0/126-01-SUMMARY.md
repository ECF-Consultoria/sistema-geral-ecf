---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 01
subsystem: api
tags: [clicksign, http-client, laravel-http, retry, logging, pii]

requires:
  - phase: 125-estrutura-de-dados-administrativa
    provides: "ContratoAssinatura/ContratoAssinaturaSignatario + .env.example com as 5 chaves CLICKSIGN_*"
provides:
  - "config('services.clicksign') lido via config(), nunca env() direto"
  - "App\\Exceptions\\ClicksignException::fromResponse() com mensagens pt-BR distintas por status"
  - "App\\Services\\Clicksign\\ClicksignClient::criarEnvelope()/anexarDocumento() sobre um núcleo enviar() seguro"
  - "Tests\\Fixtures\\ClicksignSandboxFixtures — payloads anonimizados reutilizáveis do sandbox"
affects: [126-02, 127]

tech-stack:
  added: []
  patterns:
    - "Http::withHeaders() + ->contentType() (nunca Http::withToken()) para APIs que exigem token puro no Authorization"
    - "enviar() como ponto único de retry/log/exceção — todo método novo do client herda a garantia sem repetir a lógica"
    - "Http::retry($times, $sleepMs, $when, throw:false) com callback baseado em status HTTP (429/5xx/ConnectionException) para decidir retry sem lançar exceção não tratada"

key-files:
  created:
    - app/Exceptions/ClicksignException.php
    - app/Services/Clicksign/ClicksignClient.php
    - tests/Fixtures/ClicksignSandboxFixtures.php
    - tests/Feature/Phase126/ClicksignConfigEFixturesTest.php
    - tests/Feature/Phase126/ClicksignClientTest.php
    - tests/Feature/Phase126/ClicksignClientNaoVazaTokenTest.php
  modified:
    - config/services.php
    - .env.example

key-decisions:
  - "Content-Type fixado via ->contentType() DEPOIS de ->withHeaders(), nunca dentro do array de withHeaders() — PendingRequest já seta 'Content-Type: application/json' no construtor (asJson()), e withHeaders() faz array_merge_recursive; colocar Content-Type nas duas chamadas viraria um array de dois valores em vez de sobrescrever."
  - "Retry implementado com Http::retry(3, callback de ms, when, throw:false) em vez de laço manual com usleep — quando o Laravel 12 suporta o callback 'when' baseado em status HTTP, não há motivo para reinventar o laço (research recomendava usar Http::retry() se disponível)."
  - "Guarda de tamanho do upload (gate #5) verifica strlen($pdfBinario) ANTES de montar o payload — nenhuma chamada HTTP sai quando o PDF excede o limite, provado por Http::assertNothingSent()."
  - "Mensagens de 401/403 testadas por conteúdo distintivo em dois testes independentes, não por Http::fake() duplo no mesmo teste — Illuminate\\Http\\Client\\Factory::fake() acumula stubs e resolve pelo PRIMEIRO match; um segundo fake() para a mesma URL, no mesmo teste, não substitui o primeiro."

requirements-completed: [CLICK-01]

duration: 55min
completed: 2026-08-10
---

# Phase 126 Plan 01: Client Clicksign + PDF do contrato (v22.0) Summary

**Fundação do `ClicksignClient` que fala com a API v3 da Clicksign com token puro no header, `content_base64` como Data URI completo, retry disciplinado (só 429/5xx) e log que nunca vaza a credencial — provado por 30 testes automatizados, incluindo uma guarda estática que reprova qualquer variável de resposta/exceção inteira passada para `Log::`.**

## Performance

- **Duration:** ~55min
- **Started:** 2026-08-10T18:00:00Z (aprox.)
- **Completed:** 2026-08-10T18:55:00Z (aprox.)
- **Tasks:** 3/3 completas
- **Files modified:** 7 (2 modificados, 5 criados)

## Accomplishments
- `config('services.clicksign')` criado espelhando o bloco `'hubspot'`, fechando o Pitfall 5 do RESEARCH (`grep -n "clicksign" config/services.php` antes retornava vazio).
- `ClicksignException::fromResponse()` produz mensagens pt-BR distintas para 401 (token inválido/ausente), 403 (e-mail do usuário da API não configurado) e 429 (rate limit), preservando `httpStatus`/`codigoApi`/`ponteiro` sem jamais receber o token.
- `ClicksignClient::criarEnvelope()` e `anexarDocumento()` funcionando sobre um núcleo `enviar()` único: header sem `Bearer`, `content_base64` como Data URI completo, retry só em 429/5xx/falha de conexão (nunca em 4xx de dado), guarda de tamanho antes de qualquer requisição.
- Success Criteria 1 da fase ("nenhuma linha de log jamais contém o token") provado em sucesso e em erro (400/401/429/500), na mensagem e no contexto serializado — incluindo uma verificação manual de que a guarda de fato pega uma linha leaky introduzida deliberadamente (confirmado e revertido, sem sobrar no diff final).

## Task Commits

Each task was committed atomically:

1. **Task 1: Config, exceção e fixtures anonimizadas do sandbox** - `3dd6482a` (feat)
2. **Task 2: Núcleo do ClicksignClient — headers, retry, criarEnvelope e anexarDocumento** - `cffdb3ba` (feat)
3. **Task 3: Prova de que nenhum log vaza o token (Success Criteria 1)** - `365a7a6c` (test)

_Nenhuma task usou o fluxo TDD RED→GREEN separado em commits distintos — o `tdd="true"` do frontmatter foi seguido escrevendo teste + implementação juntos por task, rodando o comando `<verify>` antes de cada commit para confirmar verde._

## Files Created/Modified
- `config/services.php` - bloco `'clicksign'` (env, base_url, access_token, webhook_secret, api_user_email, max_upload_bytes)
- `.env.example` - `CLICKSIGN_MAX_UPLOAD_BYTES=20971520` (gate #5, NÃO MEDIDO)
- `app/Exceptions/ClicksignException.php` - exceção própria com `httpStatus`/`codigoApi`/`ponteiro` e `fromResponse()` (D-10)
- `app/Services/Clicksign/ClicksignClient.php` - client HTTP: `baseRequest()`, `enviar()`, `criarEnvelope()`, `anexarDocumento()`
- `tests/Fixtures/ClicksignSandboxFixtures.php` - 11 métodos estáticos com payloads anonimizados (RFC 5737, UUID sintético, `@example.com`)
- `tests/Feature/Phase126/ClicksignConfigEFixturesTest.php` - 9 testes (config, exceção, varredura de PII)
- `tests/Feature/Phase126/ClicksignClientTest.php` - 10 testes (header, Data URI, retry, guarda de tamanho)
- `tests/Feature/Phase126/ClicksignClientNaoVazaTokenTest.php` - 11 testes (sucesso/erro + guarda estática)

## Decisions Made
- **Content-Type via `->contentType()` após `->withHeaders()`:** descoberta durante a implementação (não estava no RESEARCH nem no CONTEXT) — o construtor de `PendingRequest` já chama `asJson()`, que seta `'Content-Type' => 'application/json'` nos `$options['headers']`. Como `withHeaders()` usa `array_merge_recursive`, incluir `Content-Type` dentro do array passado a `withHeaders()` faria a mesma chave em ambos os lados virar um array de dois valores (`['application/json', 'application/vnd.api+json']`) em vez de sobrescrever — quebrando silenciosamente o header exigido pela Clicksign. `->contentType()` sempre atribui direto, sem esse risco. Documentado em docblock no próprio `baseRequest()`.
- **Retry via `Http::retry()` nativo, não laço manual:** o RESEARCH sugeria usar `Http::retry()` "se ele disparar para status HTTP no Laravel 12" com fallback para laço manual. Confirmado por leitura do vendor (`PendingRequest::send()`) que o parâmetro `$when` recebe a exceção com `->response` acessível, permitindo decidir por status HTTP sem laço próprio. Usado `retry(3, callback de ms crescente, when, throw:false)` — o `throw:false` evita que o Laravel lance a `RequestException` bruta (que poderia vazar via `transferStats`), deixando o `ClicksignClient` inspecionar a resposta final e converter para `ClicksignException` com campos extraídos.
- **Testes de 401/403 divididos em dois métodos:** durante a execução, um teste que chamava `Http::fake()` duas vezes no mesmo método (uma para 401, outra para 403) falhou porque `Illuminate\Http\Client\Factory::fake()` acumula stubs numa `Collection` e resolve pelo primeiro match — o segundo `fake()` para a mesma URL não substituiu o primeiro. Corrigido dividindo em dois testes independentes, cada um verificando o texto distintivo da própria mensagem.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Content-Type sobrescrito incorretamente por `array_merge_recursive` se colocado dentro de `withHeaders()`**
- **Found during:** Task 2 (leitura do vendor `PendingRequest.php` antes de escrever `baseRequest()`)
- **Issue:** O padrão ingênuo `Http::withHeaders(['Content-Type' => '...', 'Authorization' => '...'])` produziria um header `Content-Type` com dois valores (array), já que o construtor do `PendingRequest` sempre chama `asJson()` primeiro (setando `Content-Type: application/json`), e `withHeaders()` faz merge recursivo em vez de substituição simples para chaves repetidas.
- **Fix:** `Content-Type` é setado via `->contentType('application/vnd.api+json')` — chamada separada que sempre sobrescreve, nunca dentro do array de `withHeaders()`.
- **Files modified:** `app/Services/Clicksign/ClicksignClient.php`
- **Verification:** `ClicksignClientTest::header_authorization_e_o_token_puro_sem_bearer` assere o valor exato do header `Content-Type` via `Http::assertSent()`.
- **Committed in:** `cffdb3ba` (parte do commit da task)

Nenhum outro desvio: as 3 tasks foram executadas como escritas no plano, sem necessidade de Rule 2/3/4.

## Issues Encountered
- Nenhum bloqueio. A única fricção real foi a descoberta do comportamento de `array_merge_recursive` em `withHeaders()` (documentada acima) — resolvida antes de qualquer teste falhar em produção, por leitura direta do vendor.

## Deferred/Skipped
- Nada desta plano foi adiado. A migration D-03 (`pdf_path`/`pdf_assinado_path`), os demais métodos do envelope (signatário/requisito/notificação/consulta/cancelamento — D-12) e o `ContratoPdfService` ficam para os próximos planos da Fase 126 (126-02 em diante), conforme o roadmap da fase.

## User Setup Required
Nenhum. `CLICKSIGN_ACCESS_TOKEN` e demais chaves seguem vazias em `.env.example`; o `.env` local (gitignored) já tem as credenciais do sandbox usadas na sessão de pesquisa empírica.

## Next Steps
- Plano 126-02: mapa D-08 (`contratante`/`contratada`/`testemunha` → `sign`/`party`/`contractor`), métodos `adicionarSignatario()`, `adicionarRequisito()`, `ativarEnvelope()`, `cancelarEnvelope()` sobre o mesmo `enviar()`.
- D-12 (rollback): o cancelamento automático em caso de falha no meio da criação do envelope ainda não está implementado — é escopo do próximo plano que compõe as chamadas.

## Known Stubs
Nenhum. Este plano não introduz UI nem dado que alimente tela — é infraestrutura pura (client HTTP), sem caminho de renderização a verificar.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. Os 5 threats (T-126-01 a T-126-05) mapeados no PLAN.md foram todos mitigados exatamente como descrito:
- T-126-01 (log do client): provado por `ClicksignClientNaoVazaTokenTest`.
- T-126-02 (token em config): `config('services.clicksign.access_token')`, sem `env()` fora de `config/services.php`.
- T-126-03 (PII em fixture): provado por `ClicksignConfigEFixturesTest` (3 varreduras: e-mail, IP, UUID).
- T-126-04 (payload ecoado em erro): `enviar()` só loga `contexto`/`status`/`codigo`/`ponteiro`.
- T-126-05 (rate limit): retry limitado a 3 tentativas, só em 429/5xx.

## Self-Check: PASSED

Arquivos verificados:
- FOUND: config/services.php (bloco `'clicksign'` presente)
- FOUND: .env.example (chave `CLICKSIGN_MAX_UPLOAD_BYTES`)
- FOUND: app/Exceptions/ClicksignException.php
- FOUND: app/Services/Clicksign/ClicksignClient.php
- FOUND: tests/Fixtures/ClicksignSandboxFixtures.php
- FOUND: tests/Feature/Phase126/ClicksignConfigEFixturesTest.php
- FOUND: tests/Feature/Phase126/ClicksignClientTest.php
- FOUND: tests/Feature/Phase126/ClicksignClientNaoVazaTokenTest.php

Commits verificados:
- FOUND: 3dd6482a
- FOUND: cffdb3ba
- FOUND: 365a7a6c

Suíte da fase: `tests/Feature/Phase126/` — 30 testes, 74 assertions, 100% verde.
