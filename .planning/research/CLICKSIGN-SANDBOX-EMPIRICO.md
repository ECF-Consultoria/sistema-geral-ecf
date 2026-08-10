# Clicksign API v3 — achados empíricos contra o sandbox real

**Data:** 2026-08-10 (envelope criado em 2026-08-07, assinado em 2026-08-10)
**Ambiente:** `https://sandbox.clicksign.com/api/v3`
**Método:** envelope real criado, documento anexado, signatário adicionado, envelope ativado e
**efetivamente assinado**. Todas as afirmações abaixo são respostas HTTP observadas, não leitura
de documentação.

> **Por que este arquivo existe:** a pesquisa da milestone (`STACK.md`, `FEATURES.md`) marcou
> vários pontos como confiança MÉDIA, e **dois deles estavam errados**. Este documento tem
> precedência sobre a pesquisa onde houver divergência.

---

## 1. Autenticação — gate #2 FECHADO

| Variante | HTTP | Corpo |
|---|---|---|
| `Authorization: <token>` | **200** | ok |
| `Authorization: Bearer <token>` | **401** | `Access Token inválido` |

**O token vai puro, sem prefixo.** Consequência para o código: **não usar `Http::withToken()`**
do Laravel — ele prefixa `Bearer ` sempre e daria 401 silencioso. Usar
`Http::withHeaders(['Authorization' => $token, ...])`.

**Armadilha adicional:** antes de salvar o e-mail do usuário da API em Configurações → API, a
resposta é **403** com `"E-mail do usuário da API não configurado"`. Note que 403 ≠ 401: o token
já estava certo, quem barrou foi a camada seguinte. Tratar os dois códigos com mensagens
diferentes no client.

### Headers obrigatórios
```
Authorization: <access_token>
Accept: application/vnd.api+json
Content-Type: application/vnd.api+json
```

### URLs base — gate #3 FECHADO
- Sandbox: `https://sandbox.clicksign.com/api/v3`
- Produção: `https://app.clicksign.com/api/v3` (literal na doc de "Informações gerais")

### Rate limit — medido
```
X-Rate-Limit: 20
X-Rate-Limit-Remaining: 19
X-Rate-Limit-Reset: <unix timestamp>
```
**Sandbox dá 20**, não os 50 que a doc de produção cita. O job de reconciliação da REDE-04 tem
que respeitar isso. Paginação JSON:API: `page[number]` / `page[size]`, default 20.

---

## 2. `content_base64` — gate #4 FECHADO, A DOC ESTÁ ERRADA

O exemplo oficial mostra base64 puro (`"JVBERi0xLjQK..."`). **Isso dá 400:**

```json
{"errors":[{"code":"bad_request","status":400,
 "source":{"pointer":"/data/attributes/content_base64"},
 "detail":"content_base64 Formatação do campo inválida. O valor deve ser um Data URI completo."}]}
```

**O correto é `data:application/pdf;base64,<base64>`.** Seguir o exemplo da documentação teria
custado uma sessão de debug na Fase 126.

---

## 3. Gate #9 — onde vive a evidência de autenticação do signatário

### O que NÃO tem a evidência (verificado por diff antes × depois)

- `GET /envelopes/{id}/signers/{signerId}` — **depois da assinatura, o único campo que mudou foi
  `modified`.** Sem `signed_at`, sem status, sem IP, sem método. O recurso do signatário **não
  informa nem se aquela pessoa assinou.**
- `GET /envelopes/{id}/requirements` — idem, só `modified`.

### Onde ESTÁ

`GET /envelopes/{envelopeId}/documents/{documentId}/events` — evento `name: "sign"`,
em `attributes.data.signer`:

```json
{
  "sign_as": "contractor",
  "key": "3ec39713-...",
  "email": "...", "name": "...",
  "auths": ["email"],
  "address": "203.0.113.10",
  "latitude": null, "longitude": null,
  "selfie_enabled": false,
  "handwritten_enabled": false,
  "official_document_enabled": false,
  "liveness_enabled": false,
  "facial_biometrics_enabled": false,
  "federal_data_validation": null,
  "documentation": null, "has_documentation": false,
  "phone_number": null, "phone_number_hash": null,
  "communicate_by": "email",
  "url": "https://sandbox.clicksign.com/notarial/widget/signatures/{signerId}/redirect"
}
```
Fora de `signer`, no mesmo evento: `log_version` (ex.: `"1.1495.0"`), `secret_hmac`, `account.key`,
`account.timestamp_signature_functionality`. O **carimbo de tempo da assinatura** é o `created`
do próprio evento.

`GET /envelopes/{id}/events` devolve a mesma lista (7 eventos, idênticos, no teste com 1 documento).

### Eventos observados, em ordem cronológica

| Evento | Quando dispara | Serve para |
|---|---|---|
| `upload` | documento anexado | auditoria |
| `update_block_after_refusal` | atributo alterado | auditoria |
| `add_signer` | signatário vinculado ao documento | auditoria |
| `signature_started` | a pessoa **abriu** a página de assinatura | distinguir "abriu e não concluiu" de "nem abriu" — REDE-02 |
| `sign` | assinou | **evidência do gate #9** |
| `auto_close` | fechamento automático (`auto_close: true`) | — |
| `document_closed` | documento finalizado | gatilho de liberação (D7) |

---

## 4. Consequências diretas para o schema da Fase 125

1. **A situação individual do signatário (D-09) só existe no nosso banco.** Não há como
   reconsultar a Clicksign e perguntar "fulano assinou?" — só varrer eventos e deduzir. Persistir
   `pendente` / `assinou` / `recusou` deixa de ser conveniência e vira a única fonte consultável.

2. **A D7 da milestone precisa de endereço novo.** O estado agregado confiável para liberar é
   `envelope.status == "closed"` + a lista de eventos, **não** o recurso do signatário. Quem
   implementar a Fase 129 vai reconsultar o recurso errado se não souber disso.

3. **O campo de evidência do signatário tem forma conhecida.** Persistir o bloco `data.signer` do
   evento `sign` inteiro (JSON), e promover a colunas próprias o que a tela e a auditoria
   consultam: `auths[]` (métodos), `address` (IP) e o timestamp.

4. **O `contrato_assinatura_eventos` da DADOS-03 (Fase 129) tem forma concreta** — os 7 eventos
   acima, com `name` + `data` (JSON) + `created`.

---

## 5. Defaults que a API preenche sozinha

| Campo | Default observado | Efeito |
|---|---|---|
| `deadline_at` | **+30 dias** da criação | reforça a D-03: `expira_em` local é espelho, não fonte |
| `remind_interval` | `3` | confirma CLICK-08 — lembrete nativo dispensa scheduler próprio |
| `deadline_partial_signature_action` | `"closed"` | campo que a pesquisa não tinha visto |
| `rubric_enabled` | `true` | rubrica habilitada por padrão |
| `group` (signatário) | `1` | ordem de assinatura é nativa |

---

## 6. Vocabulário da API × vocabulário nosso

A API usa dois eixos separados, ambos via `POST /envelopes/{id}/requirements`:

- **Qualificação:** `action: "agree"` + `role: "sign" | "party" | "contractor"`
- **Autenticação:** `action: "provide_evidence"` + `auth:` (16 valores — `email`, `sms`,
  `whatsapp`, `pix`, `icp_brasil`, `handwritten`, `selfie`, `official_document`, `address_proof`,
  `liveness`, `facial_biometrics`, `identity_biometrics`, `auto_signature`, `presential`,
  `embedded_signature`, `biometric`, `documentscopy`)

Ambos exigem `relationships.document` **e** `relationships.signer`.

**Nossa D-08 (`contratante`, `contratada`, `testemunha`) é vocabulário interno** e precisa de um
mapa explícito para `sign`/`party`/`contractor`. No evento `sign`, o `role` aparece como
`sign_as`. Definir esse mapa é tarefa da Fase 126.

---

## 7. Outros achados operacionais

**O link do arquivo expira em 5 minutos.** `files.original`, `files.signed` e `files.ziped` são
URLs S3 pré-assinadas com `X-Amz-Expires=300`. **Guardar `clicksign_download_url` no banco é
guardar um link morto.** Isso eleva a D6 da milestone de boa prática a única opção viável — e a
justificativa hoje escrita ("se a conta for encerrada") subestima o problema por várias ordens de
grandeza: o risco é de minutos, não de anos.

**Reenviar notificação tem rate limit próprio.** `POST /envelopes/{id}/signers/{id}/notifications`
devolveu **429 `Too many requests`** em texto puro (não JSON:API) enquanto a API geral seguia com
19 de 20 requisições disponíveis. É um limite anti-spam separado. A tela do CLICK-07 (Fase 131)
precisa tratar 429 como resposta esperada — "aguarde antes de reenviar" —, não como erro.

**`GET` no endpoint de notificação devolve 404.** Ele é POST-only; 404 aqui não significa
"não existe".

**O e-mail de solicitação não chegou** na caixa do signatário durante o teste (`adm@`), embora a
plataforma tenha registrado o envio. A assinatura foi feita pela interface web do sandbox, logada.
Não investigado a fundo — pode ser spam, alias ou política de domínio. **Se o e-mail for o canal
de produção, isso precisa ser testado de verdade antes da Fase 133.**

---

## 8. O que continua em aberto

| Gate | Item | Trava | Como fechar |
|---|---|---|---|
| #1 | Algoritmo do `Content-Hmac` | Fase 129 | Doc diz `SHA256(body + secret)` — confirmado em 2 leituras, **não** testado contra webhook real. Segundo candidato: `hash_hmac('sha256', $body, $secret)` |
| #5 | Limite de tamanho de arquivo | Fase 126 | O PDF de teste tinha 1,5 KB. Testar com contrato real |
| #6 | Expiração emite evento distinguível | Schema + 129 | Deixar um envelope vencer |
| #7 | Recusa emite evento distinguível | Schema + 129 | Recusar uma assinatura |
| #8 | Endpoint de correção de e-mail | CLICK-09 | — |
| #11 | Retry e ordem dos webhooks | CLICK-04/05 | Só com webhook ativo |

**Nenhum deles bloqueia a Fase 125.**

---

## Dados do teste (sandbox, descartável)

Identificadores e IP **anonimizados** — ver aviso abaixo. Os valores reais estão no envelope de
teste da conta sandbox, se alguém precisar reconferir.

- Envelope: "TESTE gate #9 - ECF Admin", `closed`, criado 2026-08-07 e assinado 2026-08-10

> ⚠️ **Anonimize antes de colar resposta de API aqui.** A primeira versão deste documento trazia
> o IP público real de quem assinou e a chave real do signatário. Alguém copiou esses valores
> daqui para uma factory e um teste (`ContratoAssinaturaSignatarioFactory`), e viraram PII
> permanente no histórico do git — achado WR-07 do code review da Fase 125. O IP de exemplo usado
> acima é da faixa de documentação RFC 5737 (`203.0.113.0/24`); UUIDs de exemplo usam
> `00000000-0000-4000-8000-00000000000N`.

Credenciais do sandbox estão no `.env` local (gitignored). O `.env.example` traz as chaves vazias
com o aviso do `Bearer`.
