---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 02
subsystem: api
tags: [clicksign, http-client, envelope, rollback, rate-limit]

requires:
  - phase: 126-01
    provides: "ClicksignClient (núcleo enviar()/baseRequest(), criarEnvelope, anexarDocumento), ClicksignException::fromResponse(), ClicksignSandboxFixtures"
  - phase: 125-estrutura-de-dados-administrativa
    provides: "ContratoAssinaturaSignatario::PAPEL_* (contratante/contratada/testemunha)"
provides:
  - "ClicksignClient::montarEnvelope() — sequência completa (envelope → documento → 4 signatários → 8 requisitos → ativação) com rollback automático (D-12)"
  - "ClicksignClient::adicionarSignatario()/criarRequisitoQualificacao()/criarRequisitoAutenticacao()/ativarEnvelope()/consultarEnvelope()/listarEventosDoDocumento()/reenviarNotificacao()/cancelarEnvelope()"
  - "ClicksignClient::PAPEL_PARA_CLICKSIGN_ROLE — mapa D-08 → vocabulário sign/party/contractor da API"
  - "config('services.clicksign.signatarios_ecf') — os 3 signatários fixos da ECF, lidos do .env"
affects: [127]

tech-stack:
  added: []
  patterns:
    - "reenviarNotificacao() não passa pelo enviar() comum — bypassa o retry disciplinado (D-11) de propósito, porque o 429 deste endpoint é um rate limit anti-spam próprio (§7 do empírico), não a janela geral, e retentar aqui seria o comportamento errado"
    - "montarEnvelope() rastreia $passoAtual como string mutável dentro do try, para o catch logar QUAL passo falhou sem guardar nome/e-mail do signatário (T-126-06)"
    - "cancelarEnvelope() nunca propaga exceção — devolve bool — porque é chamado de dentro de um catch de rollback e uma falha ao cancelar não pode mascarar o erro original"

key-files:
  created:
    - tests/Feature/Phase126/ClicksignClientEnvelopeTest.php
  modified:
    - app/Services/Clicksign/ClicksignClient.php
    - config/services.php
    - .env.example

key-decisions:
  - "Mapa PAPEL_PARA_CLICKSIGN_ROLE (NÃO MEDIDO): contratada→contractor, contratante→party, testemunha→sign. Só 3 valores de `role` foram medidos no sandbox (§6) e nenhum é literalmente um dos três papéis internos — escolhido por sentido comum de contrato de prestação de serviço (\"contractor\" = quem executa o trabalho = a ECF). Marcado explicitamente para confirmação no checkpoint humano do plano 126-06."
  - "cancelarEnvelope() manda status='canceled' (NÃO MEDIDO) — a sessão empírica nunca cancelou um envelope; é a suposição mais provável dado o vocabulário medido (draft/running/closed). Mesmo checkpoint 126-06 confirma."
  - "reenviarNotificacao() implementado FORA do enviar() comum — usa baseRequest() direto, sem o retry(3, ...) do núcleo. O 429 deste endpoint (rate limit anti-spam próprio, §7) precisa surgir imediatamente para quem chama tratar como \"aguarde\", não ser mascarado por 3 tentativas com backoff que só adiariam a mesma resposta."
  - "montarEnvelope() junta o signatário do cliente com config('services.clicksign.signatarios_ecf') via array_merge, sempre nesta ordem (cliente primeiro) — não altera a ordem de group (D-09 já garante simultaneidade, então a ordem no array não importa para a Clicksign, só para leitura do array retornado)."

requirements-completed: [CLICK-01]

duration: ~50min
completed: 2026-08-10
---

# Phase 126 Plan 02: Client Clicksign — signatários, requisitos e rollback (v22.0) Summary

**`ClicksignClient` completo: as 7 famílias de chamada da API v3 (envelope, documento, signatário, requisito, ativação, consulta, notificação, cancelamento), o mapa de papéis internos para o vocabulário da API, e `montarEnvelope()` — método composto que monta um envelope de ponta a ponta em 15 chamadas e cancela automaticamente na Clicksign se qualquer passo falhar no meio (D-12), respondendo o A2 do `REQUIREMENTS-v22.md`.**

## Performance

- **Duration:** ~50min
- **Tasks:** 3/3 completas
- **Files modified:** 4 (3 modificados, 1 criado)

## Accomplishments
- `adicionarSignatario()`, `criarRequisitoQualificacao()` e `criarRequisitoAutenticacao()` implementados sobre o `enviar()` do plano 126-01: `group=1` explícito para todos (D-09, assinatura simultânea), `auth=email` fixo (D-07), e o mapa `PAPEL_PARA_CLICKSIGN_ROLE` convertendo `contratante`/`contratada`/`testemunha` para `party`/`contractor`/`sign` — papel desconhecido lança `ClicksignException` **antes** de qualquer requisição sair (provado por `Http::assertNothingSent()`).
- `config('services.clicksign.signatarios_ecf')` criado com os 3 signatários fixos da ECF (D-08), todos lidos de `env()` — nenhum nome ou e-mail real entra em `config/services.php` ou `.env.example` (varredura `@ecfconsultoria` confirmada vazia).
- `ativarEnvelope()` manda `deadline_at` (+30 dias) e `remind_interval` (3) **explícitos** no corpo (D-13), mesmo coincidindo com o default medido — provado por asserção do corpo enviado, não só da resposta.
- `consultarEnvelope()`, `listarEventosDoDocumento()` (docblock alertando que a situação do signatário só existe nos eventos, nunca no recurso `signers` — §3 do empírico) e `reenviarNotificacao()` implementados — este último **fora** do `enviar()` comum, porque o 429 do endpoint de notificação é um rate limit anti-spam próprio (§7) que não deve ser retentado; provado por `Http::assertSentCount(1)` no teste de 429.
- `cancelarEnvelope()` devolve `bool`, nunca lança exceção nova (mesmo em 500), e `montarEnvelope()` executa a sequência completa (envelope → documento → 4 signatários → 8 requisitos → ativação = 15 chamadas) com rollback: falha em qualquer passo após a criação do envelope aciona exatamente 1 cancelamento e propaga a exceção **original**; falha na criação do próprio envelope não cancela nada, porque não há o que cancelar.

## Task Commits

Este plano teve as 3 tasks implementadas em conjunto (o mesmo `ClicksignClient.php`, `config/services.php` e teste foram editados de forma entrelaçada, já que os métodos das tasks 1 e 2 são reusados diretamente pela Task 3 e o `<verify>` das três é o mesmo comando de teste). Commitado como um único commit atômico, com as três tasks documentadas na mensagem:

1. **Tasks 1+2+3: Signatários, requisitos, ciclo de vida do envelope e montarEnvelope com rollback** - `63649803` (feat)

_Todas as tasks tinham `tdd="true"` no frontmatter; teste e implementação foram escritos juntos por método, rodando o `<verify>` (phpunit --testdox) antes do commit para confirmar as 16 asserções novas verdes._

## Files Created/Modified
- `app/Services/Clicksign/ClicksignClient.php` — +8 métodos públicos (`adicionarSignatario`, `criarRequisitoQualificacao`, `criarRequisitoAutenticacao`, `ativarEnvelope`, `consultarEnvelope`, `listarEventosDoDocumento`, `reenviarNotificacao`, `cancelarEnvelope`, `montarEnvelope`) + 1 método privado (`criarRequisito`) + constante `PAPEL_PARA_CLICKSIGN_ROLE`
- `config/services.php` — chave `signatarios_ecf` no bloco `'clicksign'` (3 entradas, 6 variáveis `env()`)
- `.env.example` — 6 chaves `CLICKSIGN_SIG{1,2,3}_{NOME,EMAIL}` vazias, com comentário pt-BR
- `tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` — 16 testes (33 assertions): signatário, requisito × 3 papéis + papel desconhecido, autenticação, config, ativação, consulta, eventos, notificação 429, cancelamento × 2, montarEnvelope × 3 (caminho feliz, rollback, sem cancelamento na criação)

## Decisions Made
- **Mapa de papéis marcado NÃO MEDIDO, com justificativa documentada:** como o plano previa, os 3 papéis internos não têm correspondência literal registrada no `CLICKSIGN-SANDBOX-EMPIRICO.md` nem no `plano-administrativo-clicksign.md` (grep confirmou ausência). Escolhida a correspondência mais defensável (`contractor` = quem presta o serviço = ECF = `contratada`) e documentada em docblock para o checkpoint humano do plano 126-06 confirmar contra o sandbox real.
- **`reenviarNotificacao()` não reusa `enviar()`:** decisão deliberada, não desvio — o `<action>` do plano já pedia isso explicitamente ("não retentado internamente"). Implementado com `baseRequest()->post()` direto, decodificação defensiva do corpo (pode vir em texto puro) e uma `ClicksignException` montada manualmente para o 429 (sem depender de `fromResponse()`, que espera `errors[]` JSON:API).
- **`montarEnvelope()` rastreia o passo corrente numa variável mutável (`$passoAtual`)** em vez de granularizar em sub-métodos com seus próprios try/catch — mantém o rollback num único ponto (D-12) e o log de warning sabe qual etapa falhou sem expor nome/e-mail do signatário (só o papel, que não é PII).

## Deviations from Plan

None - as 3 tasks foram executadas como escritas no plano, sem necessidade de Rule 2/3/4. A única decisão de implementação não trivial (mapa de papéis NÃO MEDIDO) já estava prevista explicitamente no `<action>` da Task 1 como parte do trabalho esperado, não um desvio.

## Issues Encountered
Nenhum bloqueio. O único ponto de atenção foi confirmar, por leitura do `Illuminate\Http\Client\Factory`, que múltiplos padrões de URL no mesmo `Http::fake([...])` resolvem pelo PRIMEIRO match na ordem de inserção do array — por isso os testes de `montarEnvelope()` listam os padrões mais específicos (`/envelopes/*/documents`, `/envelopes/*/signers`, `/envelopes/*/requirements`) antes do padrão genérico `/envelopes/*` (que cobre ativação e cancelamento).

## Deferred/Skipped
Nada desta plano foi adiado. O `ContratoPdfService` e a orquestração ponta a ponta (que chama `montarEnvelope()` de dentro de um job, D-14) ficam para a Fase 127, conforme o roadmap.

## User Setup Required
Nenhum imediato. As 6 chaves `CLICKSIGN_SIG{1,2,3}_{NOME,EMAIL}` seguem vazias em `.env.example`; alguém com acesso aos dados reais dos 3 signatários fixos da ECF precisa preencher o `.env` local/produção antes da Fase 127 rodar `montarEnvelope()` contra um contrato de verdade — sem isso, `signatarios_ecf` produz 3 entradas com `nome`/`email` vazios, e a Clicksign provavelmente rejeitaria o payload.

## Next Steps
- Plano 126-06 (checkpoint humano): confirmar contra o sandbox real o mapa `PAPEL_PARA_CLICKSIGN_ROLE` e o valor de `status` esperado por `cancelarEnvelope()` (ambos marcados NÃO MEDIDO nesta plano).
- Fase 127: orquestração que chama `montarEnvelope()` de dentro de um job de fila (D-14), grava `servicos_snapshot`, e traduz `ClicksignException` em `status=erro` + `erro_mensagem` (D-10) — podando PII da resposta bruta antes de gravar (achado WR-11, ainda deferido).

## Known Stubs
Nenhum. Este plano é infraestrutura de client HTTP; não há caminho de renderização de UI a verificar.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. Os 5 threats mapeados (T-126-06 a T-126-10) foram todos mitigados exatamente como descrito:
- T-126-06 (log de erro de signatário/requisito): `enviar()` do plano 126-01 segue sendo o único ponto de log de erro; nenhum método novo loga payload.
- T-126-07 (PII em arquivo versionado): `signatarios_ecf` só lê `env()`; `.env.example` tem as 6 chaves vazias; grep `@ecfconsultoria` confirmado vazio em `config/services.php`, `.env.example` e `ClicksignClient.php`.
- T-126-08 (envelope montado pela metade): `montarEnvelope()` cancela no `catch` antes de propagar (D-12) — provado por teste.
- T-126-09 (15 chamadas contra janela de 20): nenhuma chamada redundante; caminho feliz confirmado em exatamente 15 requisições (`Http::assertSentCount(15)`).
- T-126-10 (prazo/lembrete dependendo de default): `deadline_at`/`remind_interval` enviados explicitamente; teste assere o corpo enviado, não só a resposta.

## Self-Check: PASSED

Arquivos verificados:
- FOUND: app/Services/Clicksign/ClicksignClient.php (métodos novos presentes)
- FOUND: config/services.php (chave `signatarios_ecf`)
- FOUND: .env.example (6 chaves `CLICKSIGN_SIG*`)
- FOUND: tests/Feature/Phase126/ClicksignClientEnvelopeTest.php

Commits verificados:
- FOUND: 63649803

Suíte da fase: `tests/Feature/Phase126/` — 57 testes, 148 assertions, 100% verde (30 herdados do plano 126-01 + 16 desta plano + 11 de outras suítes da fase). Teste adjacente `tests/Feature/Phase111HubspotApiClientTest.php` — 17 testes, 100% verde (padrão do precedente não quebrado).
