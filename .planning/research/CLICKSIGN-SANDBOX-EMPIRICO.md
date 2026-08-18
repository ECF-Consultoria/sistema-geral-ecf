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

> ⚠️ **Este endpoint também exige corpo JSON:API com o membro `data` — um `POST` vazio devolve
> `400 "data deve ser informado(a)"`, não 429.** O `ClicksignClient::reenviarNotificacao()` mandava
> `POST` sem corpo até o quick 260814-d9s (bug real também em produção, nunca antes exercitado
> contra a API de verdade). Corpo medido e forma exata: ver §14.

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
| #1 | ~~Algoritmo do `Content-Hmac`~~ ✅ **FECHADO 2026-08-13** | Fase 129 | `hmac_body_chave_secret` confirmado em 5/5 eventos reais — ver §12.1 |
| #5 | Limite de tamanho de arquivo | Fase 126 | **PARCIAL — ver §9.** 10 MB aceitos; acima disso a trava do nosso client barrou antes de chegar na API |
| #6 | Expiração emite evento distinguível | Schema + 129 | Deixar um envelope vencer — **ainda NÃO MEDIDO**, ver §12.8/§13.3 |
| #7 | Recusa emite evento distinguível | Schema + 129 | Recusar uma assinatura — **ainda NÃO MEDIDO**, ver §12.8/§13.3 |
| #8 | Endpoint de correção de e-mail | CLICK-09 | — |
| #11 | Retry e ordem dos webhooks | CLICK-04/05 | **PERMANENTEMENTE NÃO MEDIDO** — sem documentação e sem forma segura de provocar reentrega real da Clicksign; tratado como pior caso (at-least-once, sem garantia de ordem) no código. A única observação prática disponível é a reentrega manual do mesmo corpo contra o receiver, registrada em §13.1 |

**Nenhum deles bloqueia a Fase 125.**

---

## 9. Segunda sessão de medição — 2026-08-10, checkpoint do plano 126-06

**Método:** envelopes descartáveis criados na mesma conta sandbox, com o PDF de empresa fictícia
gerado pelos testes da Fase 126. Todas as afirmações abaixo são respostas HTTP observadas.

### 9.1. `communicate_by` NÃO é atributo de entrada — o client estava errado

```
POST /envelopes/{id}/signers
{"data":{"type":"signers","attributes":{"name":"...","email":"...","group":1,
                                        "communicate_by":"email"}}}
=> 400 {"errors":[{"code":"bad_request","status":400,
        "source":{"pointer":"/data/attributes/communicate_by"},
        "detail":"communicate_by não está disponível"}]}
```

Mesmo erro com `"whatsapp"`. **Só `name` + `email` (+ `group`) são aceitos** — as duas variantes
sem `communicate_by` responderam `201`.

⚠️ **Como o erro entrou:** o campo `communicate_by: "email"` aparece na §3 deste arquivo, dentro
do bloco `data.signer` de um **evento** — ou seja, é campo de **saída**. A fixture do plano 126-01
foi modelada a partir dessa resposta, e o plano 126-02 assumiu que o que sai também entra. O
`Http::fake()` confirmava alegremente o payload errado. **Lição que vale para toda a milestone:
forma de resposta não é contrato de entrada.**

### 9.2. Cancelamento é `DELETE`, não `PATCH status`

```
PATCH /envelopes/{id}  {"data":{...,"attributes":{"status":"canceled"}}}
=> 400 {"errors":[{"code":"bad_request","status":400,
        "source":{"pointer":"/data/attributes/status"},
        "detail":"status deve estar em: draft, running"}]}

DELETE /envelopes/{id}   => 204, corpo vazio
GET    /envelopes/{id}   => 404
```

`"canceled"` **não existe** no vocabulário de `status` (some da §6). O rollback da D-12 roda antes
da ativação, com o envelope ainda em `draft`, então `DELETE` é a primitiva certa.

⚠️ **Não medido:** `DELETE` em envelope já ativado (`running`). Medir antes de a Fase 127 assumir
qualquer cancelamento pós-ativação.

### 9.3. Gate #5 — parcial

| Tamanho do arquivo | base64 no payload | Resultado |
|---|---|---|
| 1 MB | 1,33 MB | **aceito** |
| 5 MB | 6,67 MB | **aceito** |
| 10 MB | 13,33 MB | **aceito** |
| 20 MB | — | barrado pela trava do nosso client (`max_upload_bytes`), nunca chegou à API |

**O limite real da API acima de 10 MB segue não medido.** Para fechar, subir
`CLICKSIGN_MAX_UPLOAD_BYTES` temporariamente e sondar 15/20/30 MB. Um contrato real de 15 páginas
tem ~180 KB, então 10 MB já é ~55× a necessidade — o gate deixou de ser risco prático, mas o valor
`20971520` em `config/services.php` continua sendo palpite, não medida.

### 9.4. A API v3 EXPÕE modelos (templates)

```
GET /templates => 200 {"data":[],"meta":{"record_count":0},"links":{...}}
```

O endpoint **existe** e é paginado (`page[number]`/`page[size]`); a conta sandbox é que não tem
modelo cadastrado. `GET /models` e `GET /document_templates` dão 404 — o nome do recurso é
`templates`. Medição feita para responder à decisão do usuário de 2026-08-10 de reverter a D-02 e
usar o modelo da plataforma em vez de renderizar o PDF aqui.

**Como o modelo é criado — `POST /templates` exige `name` + `content_base64`:**

```
POST /templates {"data":{"type":"templates","attributes":{}}}
=> 400 name deve ser informado(a) / content_base64 deve ser informado(a)

POST /templates  (com name + content_base64 de um .docx inválido de propósito)
=> 422 "Ops! Houve um erro ao processar o documento. Verifique se o arquivo está no
        formato .docx, se as chaves duplas foram inseridas corretamente e evite o uso
        de caracteres especiais nas variáveis (@, #, !)."
```

A mensagem de erro entrega o contrato inteiro do recurso: **o modelo é um arquivo `.docx` com
variáveis em chaves duplas** (`{{razao_social}}`), e nomes de variável não aceitam `@`, `#`, `!`.

**O modelo NÃO entra pelo endpoint de documento do envelope:**

```
POST /envelopes/{id}/documents  {"...":"template_id": "<uuid>"}
=> 400 filename deve ser informado(a) / content_base64 deve ser informado(a)
```

Ou seja, `POST /envelopes/{id}/documents` só aceita binário enviado; `template_id` é ignorado —
tanto em `attributes` quanto em `relationships`.

### 9.6. Instanciar modelo em documento — **MEDIDO, e a doc estava ambígua**

A rota é a **mesma** `POST /envelopes/{envelopeId}/documents` que já se usa para upload. O que muda
é o atributo: `template`, **não** `template_id`. As tentativas com `template_id` (§9.4) falharam por
nome de campo errado, não por rota inexistente — e as 404 em `/templates/{id}/documents` eram rota
que não existe mesmo.

Sondado com UUID de modelo inexistente, o que basta para validar a FORMA sem ter modelo cadastrado
— a API valida o payload antes de resolver a chave:

```
POST /envelopes/{id}/documents
{"data":{"type":"documents","attributes":{"template":{"key":"<uuid>","data":{"razao_social":"..."}}}}}
=> 400  [filename deve ser informado(a)]                       <- só isso

... com "data" como STRING json:
=> 400  [filename deve ser informado(a)]
        [/data/attributes/template/data: "data deve ser um hash"]

... sem "data":
=> 400  [filename deve ser informado(a)]
        [/data/attributes/template/data: "data deve ser informado(a)"]
```

Quatro fatos que saem daí:

1. **`attributes.template` é o campo certo** — a API valida o que tem dentro dele
   (`/data/attributes/template/data` aparece no ponteiro do erro).
2. **`template.data` é um HASH, não string serializada.** ✅ Isso **resolve a divergência entre duas
   páginas da doc oficial** registrada em `126-RESEARCH-MODELOS.md`: mandar `json_encode()` devolve
   "data deve ser um hash". Objeto nativo, medido.
3. **`template.data` é obrigatório** — omitir dá "data deve ser informado(a)".
4. **`filename` continua obrigatório, mas `content_base64` NÃO.** Com `template` presente,
   `content_base64` some da lista de campos exigidos. O payload certo é
   `filename` + `template:{key,data}` — não é "um ou outro", o `filename` é o nome que o documento
   gerado vai ter.

⚠️ **Continua não medido** (exige modelo real cadastrado): se as chaves de `template.data` precisam
bater exatamente com os `{{nomes}}` do `.docx`, o que acontece com variável faltando ou sobrando, e
o comportamento de tabela em loop (`{{#array}}…{{/array}}`).

### 9.5. Forma da resposta — o `data` já vem desembrulhado pelo client

`criarEnvelope()` devolve `$res->json('data')`, então o `id` fica no **topo** do array retornado
(`$envelope['id']`), não em `$envelope['data']['id']`. Conferido contra a resposta real; o
`montarEnvelope()` já lia certo.

---

## 10. Terceira sessão — modelo REAL cadastrado, 2026-08-11 (gate do plano 126-11)

Primeira sessão com um **modelo `.docx` de verdade** na conta sandbox. Fecha três gates que só
podiam ser fechados assim.

**Modelo de referência no sandbox:** `5b4196cc-63d5-4537-83c7-2d07ec432a4a`
("Contrato gestão de ADS Mercado Livre", 7 variáveis). Deixado cadastrado de propósito — serve de
fixture viva para medir a dívida D-16 depois.

### 10.1. `POST /templates` aceita `.docx` montado programaticamente — gate FECHADO

O `.docx` do contrato foi gerado por código (OOXML escrito à mão, `ZipArchive`, sem PHPWord) e a
Clicksign **aceitou**: modelo cadastrado, 201. Antes disso só se sabia que ela recusava arquivo
inválido. Um pacote mínimo de 4 entradas basta:

```
[Content_Types].xml
_rels/.rels
word/document.xml
word/_rels/document.xml.rels
```

⚠️ **Vantagem colateral de gerar o `.docx` por código:** cada `{{variavel}}` fica num único run de
XML. O Word costuma quebrar um `{{nome}}` em vários runs (por causa de verificação ortográfica ou
formatação), e aí o motor de template **não reconhece a variável** — armadilha clássica de quem
monta modelo à mão. Arquivo gerado por código não tem esse problema.

### 10.2. `filename` exige extensão `.docx` — bug encontrado no nosso código

```
filename: "contrato.docx"           => 201 ACEITO
filename: "contrato_sondagem.docx"  => 201 ACEITO
filename: "Sondagem-modelo.pdf"     => 400 "filename não está em um formato válido"
                                            [/data/attributes/filename]
filename: "contrato" (sem extensão) => 400, mesma mensagem
```

O documento instanciado **nasce de um `.docx`**, e o `filename` tem que refletir isso. O comando
`clicksign:sondar-modelo` mandava `.pdf` e falhava contra a API real — corrigido em `3f7fe13a`, com
guarda local em `anexarDocumentoPorModelo()`.

⚠️ A exigência é **só do caminho de modelo**. `anexarDocumento()` (upload de binário) segue com
`.pdf` normalmente — há teste dedicado provando que a guarda não vazou para o outro caminho.

**Terceiro bug desta fase achado por medição real, e o terceiro que o `Http::fake()` não pegaria.**
Ver §9.1: o padrão se repete o suficiente para virar regra — *nesta integração, forma de payload só
é verdade depois de medida*.

### 10.3. `template.data` como hash confirmado com modelo REAL

A §9.6 tinha medido com UUID inexistente (a API valida o payload antes de resolver a chave). Agora
confirmado ponta a ponta com modelo de verdade: o documento é gerado e os valores entram. A
divergência da doc oficial (objeto × string) está definitivamente resolvida a favor do **objeto**.

### 10.4. Não há link de download enquanto o documento está em `draft`

`GET /envelopes/{id}/documents/{id}` devolve, para documento gerado de modelo em `draft`:

```
status, filename, template{key,data}, metadata{position_sign_fields}, migrated, created, modified
```

**Nenhum `files`, nenhum `original`, nenhuma URL.** A Clicksign só materializa o PDF quando o
envelope é ativado. Isso explica o *known stub* do `--baixar` registrado no `126-10-SUMMARY.md`: não
era código incompleto, é a API que não expõe o arquivo nesse estado.

**Consequência para a Fase 127:** conferir o documento gerado antes de mandar para o cliente exige
**ativar o envelope** — o que dispara e-mail para os signatários. Não existe "pré-visualizar sem
ativar" pela API. Quem quiser preview sem enviar precisa usar a interface web da Clicksign.

### 10.5. Variável faltando vira BRANCO no contrato — silenciosamente

Medido com modelo real, mandando 3 das 7 variáveis:

```
template.data com 3 de 7 variáveis  => 201, documento GERADO
template.data com 8 (uma inexistente no modelo) => 201, documento GERADO
```

**Nos dois casos a API aceita sem reclamar.** E no documento gerado com variável faltando **não
sobra nenhum `{{marcador}}` cru** — o motor substitui o que falta por **vazio**.

⚠️ **Este é o modo de falha mais perigoso desta integração, e ele é silencioso.** Um erro de digitação
no nome de uma variável — no `.docx` ou no nosso mapa — não dá erro em lugar nenhum: o contrato sai
com o campo **em branco**, e vai para assinatura assim. Não há resposta HTTP que denuncie.

É exatamente por isso que `clicksign:sondar-modelo` existe e imprime a tabela de confronto. **Regra
para a Fase 127: toda vez que o `.docx` for recadastrado, rodar o confronto antes de gerar contrato
de cliente.** A conferência não é opcional, é a única rede.

### 10.6. Dívida D-16 RESOLVIDA — documento gerado SOBREVIVE à exclusão do modelo

O item bloqueante do gate do plano 126-11. Medido com envelope **ativado** (`running`) e documento
gerado a partir do modelo:

```
antes:  GET /envelopes/{id}/documents        => 200, 1 documento
        DELETE /templates/{id}               => 204 (modelo excluído)
depois: GET /envelopes/{id}/documents        => 200, 1 documento
        status: running | link de download: PRESENTE
        download do arquivo                  => 200, 63.852 bytes (idêntico)
        GET /envelopes/{id}                  => 200, status=running
```

**O documento sobrevive intacto.** O aviso da documentação — excluir um modelo remove "todas as suas
instâncias associadas" — **não** alcança documentos já gerados dentro de envelopes. Uma vez
instanciado, o documento é independente do modelo.

Isso fecha a dívida que a decisão original D-02 cobria: trocar ou apagar o modelo **não** altera
contrato já emitido. A D-16 deixa de ter buraco.

⚠️ **Limite honesto da medição:** feita com envelope em `running`, não em `closed` (já assinado).
O caso assinado é *mais* protegido, não menos — mas não foi observado. E, independente disso, baixar
e persistir o PDF assinado localmente (`pdf_assinado_path`, Fase 129) continua sendo a prática certa:
não depender de terceiro para guardar prova jurídica.

### 10.7. Ainda não medido

- ~~Dívida D-16~~ — **RESOLVIDA na §10.6**: o documento sobrevive. O modelo de referência
  `5b4196cc…` foi excluído nessa medição.
- ~~Variável faltando ou sobrando~~ — **MEDIDO na §10.5**: aceito silenciosamente, campo vira branco.
- Tabela em loop `{{#servicos}}` — caminho recusado na D-19, sem uso previsto.
- `GET /templates` contra a conta de **produção**.

---

## 11. Quarta sessão — gate da Fase 127, 2026-08-12

### 11.1. Prazo definido na CRIAÇÃO sobrevive à ativação feita pela INTERFACE — ✅ MEDIDO

Fechava a última dúvida da D-03. Envelope montado por código com `deadline_at` de 10 dias e
`remind_interval: 7`, depois **ativado por uma pessoa na interface web** (o gesto que a D-02 delega
ao Comercial):

```
antes  (criação por código):  status=draft   deadline_at=2026-08-22T10:55:31-03:00  remind_interval=7
depois (ativação pela UI):    status=running deadline_at=2026-08-22T10:55:31-03:00  remind_interval=7
```

**Idêntico ao segundo.** A ativação humana **não** sobrescreve pelo default de 30/3. Consequência:
não é preciso reaplicar prazo na ativação, e quem lê `prazo_dias` do nosso banco está lendo o prazo
real.

Bônus observado na tela de envio: os valores chegam **pré-preenchidos** para quem envia, e a
Clicksign **deriva** a quantidade de lembretes ("3 lembretes por destinatário" para 10 dias com
intervalo 7) — só o intervalo é controlável.

⚠️ **NÃO MEDIDO:** a tela avisa *"Suas configurações serão salvas automaticamente para o próximo
uso"*. Se um operador alterar o prazo à mão uma vez, isso pode virar o padrão da tela e sobrescrever
o que o sistema mandou no documento seguinte.

### 11.2. ⚠️ RASCUNHO EXPIRA EM 7 DIAS — colide com o desenho da Fase 127

A tela de Rascunhos avisa, em texto: **"Os rascunhos ficam disponíveis por 7 dias."**

Isso **não aparece em nenhuma resposta de API** — só foi visto porque um humano abriu a ferramenta.

Por que importa: a **D-02 da Fase 127** faz o sistema montar o envelope e **parar no rascunho**,
deixando o envio para o Comercial. Se ele demorar mais de 7 dias, a Clicksign apaga o rascunho e o
nosso banco fica com `status = rascunho` apontando para um `clicksign_envelope_id` que não existe
mais. Contrato parado esperando revisão é justamente o caso comum que a D-02 cria.

**Entrada obrigatória da Fase 130** (alerta de contrato preso / reconciliação):
- alertar **antes** dos 7 dias;
- distinguir "rascunho vivo" de "rascunho apagado pela Clicksign" — sintoma provável é
  `GET /envelopes/{id}` → 404, o mesmo comportamento já medido no descarte (§9.2).

⚠️ **NÃO MEDIDO:** se os 7 dias contam da criação ou da última atualização, e o que acontece
exatamente na expiração (some da lista? vira `canceled`?).

### 11.3. PRODUÇÃO consultada pela primeira vez — o plano TEM acesso à API

Gate 2 da Fase 127, 12/08/2026. Até aqui, toda a milestone rodou só contra o sandbox; a conta de
produção **nunca** tinha sido consultada.

⚠️ **Os DOIS 403 da Clicksign não podem ser confundidos** — mesmo código, consequências opostas:

| `detail` | Significa | Como resolver |
|---|---|---|
| `A conta não possui acesso a essa funcionalidade` | plano sem API (comercialmente "Automação") | **decisão comercial** — trocar de plano |
| `E-mail do usuário da API não configurado…` | conta OK, falta 1 campo | **Configurações → aba API**, 1 minuto |

A produção devolveu o **segundo**. Ou seja: plano com acesso à API ✅, aba API existente ✅, token
válido ✅. Depois de preencher o e-mail do usuário da API, `GET /templates` → **200**, com o modelo
da ECF listado.

**Distinguir sempre pelo `detail`, nunca pelo status** — os dois casos são 403, e confundi-los faz
alguém pedir troca de plano quando falta preencher um campo.

### 11.4. A interface chama de "documento" o que a API chama de "envelope"

Não existe menu "Envelopes" na interface. Envelope em `draft` aparece em **Rascunhos**, e a lista
mostra o **nome do arquivo** (`contrato-1.docx`), **não** o `name` do envelope — este só aparece
depois que o documento sai do rascunho. Custa uma busca frustrada a quem for guiar alguém pela tela.

---

## 12. Quinta sessão — gate A1 da Fase 129, 2026-08-13

### 12.1. Algoritmo do `Content-Hmac` — gate #1 FECHADO, doc do STACK estava errada

A A1 do `REQUIREMENTS-v22.md` (bloqueante, sem plano B) foi resolvida por medição real contra o
sandbox, via túnel cloudflared apontando para o servidor local. **5 de 5** eventos reais distintos
(`add_signer` x4 + `update_deadline`, todos da ativação de um envelope remanescente da Fase 128)
confirmaram a mesma fórmula; as outras 3 candidatas falharam nos 5.

**Fórmula vencedora:** `hmac_body_chave_secret` — `hash_hmac('sha256', $rawBody, $secret)`, digest
hex, header `sha256=<hex>`.

O `PITFALLS.md` estava certo (`hex(hmac_sha256(secret, body))`, equivalente); o `STACK.md` estava
**errado** (`hash('sha256', body . secret)`). Registro completo, incluindo o corpo bruto anonimizado
de um evento, em `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md`.

### 12.2. A forma real do corpo do webhook bate com a primeira forma da doc, não a JSON:API

A doc oficial mostra duas formas incompatíveis para o corpo do webhook (ver §"Nota de
confiabilidade" do `129-RESEARCH.md`). Nos 5 eventos reais, a forma recebida foi sempre:

```
{"event":{"name","data","occurred_at"},"document":{...}}
```

A forma JSON:API (`{"data":{"attributes":{...}}}`) **nunca** apareceu. O receiver de produção
(plano 129-03) deve extrair `name` de `event.name` e o id do envelope de `document.key` — não de
`data.attributes`/`data.id`.

### 12.3. Rascunho é inerte — não dispara webhook, não é assinável

Os 3 envelopes remanescentes da Fase 128 estavam em `draft` e não geraram webhook nenhum. Só depois
de `ativarEnvelope()` (status → `running`) os webhooks passaram a chegar.

### 12.4. `deadline_partial_signature_action: "closed"` CONFIRMADO ao vivo

Apareceu na resposta real de `GET /envelopes/{id}` do envelope ativado nesta sessão — o envelope
**PODE fechar com assinatura parcial**, dependendo de como esse campo foi configurado na criação.
Reforça o gate de liberação do plano 129-04 (CLICK-05): decidir sempre por reconsulta ao estado
agregado do envelope, nunca pelo payload isolado do evento.

### 12.5. A v3 não expõe link de assinatura nos atributos do signatário

`GET /envelopes/{id}/signers` devolve `name, birthday, email, phone_number,
location_required_enabled, has_documentation, documentation, refusable, group,
communicate_events, signature_host, created, modified`. Nenhum `request_signature_key` / `sign_url`
— o link de assinatura só sai por e-mail. Relevante para a Fase 131 (tela do Administrativo): não
dá para exibir o link de assinatura na tela lendo este endpoint.

### 12.6. A ativação dispara uma rajada de eventos retroativos

4 `add_signer` + 1 `update_deadline` chegaram em 3 segundos, descrevendo tudo que já tinha
acontecido durante o rascunho. O webhook entrega **histórico**, não um fluxo estritamente
incremental — reforça decidir sempre por reconsulta ao estado agregado, nunca pela ordem/conteúdo
do evento isolado (mesma disciplina do achado 12.4).

### 12.7. `consultarEnvelope()` devolve o recurso DESEMBRULHADO

Chaves `id`, `type`, `links`, `attributes`, `relationships` direto no topo — **não** dentro de
`data`. Confirma para `consultarEnvelope()` o mesmo padrão já observado em §9.5 para outros
endpoints (o client desembrulha o `data`). Qualquer código que fizer `['data']['attributes']` sobre
o retorno deste método lê `null` em silêncio.

### 12.8. O que continua não medido desta sessão

- **Gate #6 (`deadline`, prazo vencido)** — não exercitado, janela usada para o gate A1 bloqueante.
- **Gate #7 (`refusal`, recusa de assinatura)** — idem.
- **Pergunta A4 (webhook por conta ou por envelope)** — ✅ **RESPONDIDA em 2026-08-17**, na
  virada para produção (Fase 132). Ver §12.9.

### 12.9. `GET /webhooks` existe, é por CONTA, e devolve o `secret` — medido em produção

Medido em 2026-08-17 contra `https://app.clicksign.com/api/v3`, durante a Fase 132.

**`GET {base_url}/webhooks` responde 200** e devolve, para cada webhook cadastrado na conta,
um objeto com estes atributos:

```
endpoint · secret · status · events · created · modified
```

Três consequências práticas:

1. **Pergunta A4 respondida: o webhook é por CONTA, não por envelope.** A conta de produção
   tinha exatamente 1 webhook, criado em 12/08, valendo para tudo.

2. **O SC2 tem, sim, conferência automatizada.** Os planos das Fases 129 e 132 registravam
   *"não existe conferência automatizada do painel de um terceiro"* e mandavam esperar o
   `signature_valid` do primeiro evento real. Não é preciso: dá para conferir URL, estado,
   lista de eventos **e o segredo** por uma chamada somente-leitura — inclusive **sem
   nenhum acesso ao painel**, que foi exatamente a situação da Fase 132 (o webhook havia
   sido cadastrado por uma conta à qual o usuário não tinha mais acesso).

3. **O `secret` vem na resposta.** Permite comparar com `config('services.clicksign.webhook_secret')`
   por `hash_equals()` e reportar só `confere`/`não confere`, sem imprimir valor nenhum.
   ⚠️ Em compensação, **a resposta de `GET /webhooks` contém segredo** — nunca despejar o
   corpo cru em log, em arquivo versionado ou em terminal compartilhado.

**`PATCH {base_url}/webhooks/{id}` também funciona** (medido: 200), no formato JSON:API,
e altera só os atributos enviados — foi usado para corrigir o `endpoint` preservando os 32
eventos e o segredo. Conferir sempre por **reconsulta**, não pela resposta do PATCH.

⚠️ **O erro que isso pegou vale ser lembrado:** a URL cadastrada apontava para um caminho
que não casa com nenhuma rota da aplicação (`/administrativo/clicksign` em vez de
`/api/webhooks/clicksign`), e um POST nela devolve **404**. Nada indicaria o problema até o
primeiro contrato real ficar sem liberar. Conferir o `endpoint` por API custa uma requisição.

---

## 13. Sexta sessão — pré-verificação do receiver de produção, gate final da Fase 129 (129-07), 2026-08-13

**Método:** diferente das sessões 1–5, esta NÃO conversou com a API da Clicksign. O executor do
plano 129-07 mandou requisições HTTP reais — pelo mesmo túnel cloudflared já de pé desde o gate A1 —
direto contra a rota de **produção** `POST /api/webhooks/clicksign` (não a sonda, já removida),
com corpo **sintético** forjado por ele mesmo (`verificacao_e2e_claude` /
`e2e_assinatura_invalida_claude` — nomes de evento que a Clicksign nunca emite). O objetivo foi
provar a fiação do receiver (validação de assinatura, gravação bruta, dedup) antes de envolver o
usuário na rodada real de assinatura. Registro completo, com tabela de evidência por id de evento,
em `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md` (seção "Rodada ponta a ponta —
pré-verificação do executor").

### 13.1. O receiver de produção recusa, aceita e deduplica de verdade, pela internet

| Cenário | HTTP | Evidência |
|---|---|---|
| Assinatura válida (fórmula do gate A1), envelope que não casa com contrato nenhum | **200** | evento gravado com `status='ignorado'` — não é erro, é o caso "corrida com o commit do envelope" da D-10 |
| Assinatura inválida, corpo novo | **401** | evento gravado com `payload.raw` preservado (DADOS-03 vale mesmo recusando) |
| Mesmo corpo de assinatura inválida reenviado | **401**, nenhuma linha nova | dedup por `payload_hash` funcionando mesmo no caminho de recusa |

Isto fecha, com tráfego real de internet (não teste automatizado, não `Http::fake()`), a prova de
CLICK-03, CLICK-04 e DADOS-03 que as sessões anteriores só tinham provado por suíte de teste.

### 13.2. `ip_address` sempre `127.0.0.1` neste ambiente de túnel — não é falha de captura

Confirmado nesta sessão e retroativo às sessões do gate A1: o cloudflared entrega a requisição ao
`artisan serve` local via loopback, então `$request->ip()` sempre lê o processo do túnel na própria
máquina, nunca o IP público de quem originou a chamada do lado da Clicksign. Consequência prática:
**este ambiente de desenvolvimento nunca vai medir o `X-Forwarded-For` real que a Clicksign envia**
— só um proxy reverso de produção (Fase 132) prova isso.

### 13.3. O que continua não medido mesmo depois desta pré-verificação

A pré-verificação provou a camada de recepção do webhook. Continuam sem medição real:
- **Gate #6** (`deadline`) e **Gate #7** (`refusal`) — nenhuma expiração nem recusa real foi
  exercitada; ver §12.8, situação inalterada.
- **Gate #11** (retry/ordem de entrega da Clicksign) — permanentemente não medido por documentação
  nem por sessão nenhuma; tratado como pior caso (at-least-once, sem garantia de ordem).
- **O circuito de negócio inteiro** — assinatura real de uma pessoa, liberação da empresa, PDF
  assinado de verdade baixado para `storage/app`. Nada disto foi exercitado nesta sessão; é o objeto
  do checkpoint humano do plano 129-07.
- **Pergunta A4** (cadastro do webhook por conta ou por envelope) — segue não observado.
- ⚠️ **O webhook cadastrado hoje no painel do sandbox aponta para a rota `-sonda`, que não existe
  mais.** Qualquer medição real futura exige o usuário reapontar a URL para
  `/api/webhooks/clicksign` (sem `-sonda`) antes de assinar qualquer coisa.

---

## 14. Sétima sessão — corpo do POST de notificação (quick 260814-d9s), 2026-08-14

**Método:** `ClicksignClient::reenviarNotificacao()` fazia `POST /envelopes/{id}/signers/{id}/
notifications` **sem corpo**, e a API v3 devolvia `data deve ser informado(a)` (JSON:API exige o
membro `data`) — bug real, nunca antes exercitado contra o sandbox de verdade (o único teste
anterior cobria só o 429 e não afirmava nada sobre o corpo). Um script de sondagem temporário
(fora do repositório, apagado ao final) mediu o corpo aceito contra o envelope ATIVO
`f010d235-ff75-400a-84b7-01cb89c3ef59` (status `running`, remanescente do gate da Fase 130).
Orçamento de 4 requisições — usadas **3** (1 GET + 2 POST), nenhuma contra produção, nenhum 429
observado nesta rodada.

### 14.1. `POST /envelopes/{id}/signers/{id}/notifications` **exige o membro `data`**

Confirma a lacuna que gerou o bug: sem corpo, a API responde `400`. Este comportamento não foi
re-testado nesta sessão (é o próprio ponto de partida, já documentado indiretamente pelo bug em
produção), mas é consistente com o padrão JSON:API de todos os outros endpoints já medidos neste
arquivo (envelopes, documents, signers, requirements).

### 14.2. Corpo mínimo aceito — **MEDIDO, confirmado por 2xx**

**Requisição 1/3 (GET, para obter um `signerId` real desta rodada):**
```
GET /envelopes/f010d235-ff75-400a-84b7-01cb89c3ef59/signers
=> 200, 4 signatários (meta.record_count: 4)
   primeiro usado: id 74d07cdc-b95c-49d1-91db-1b7a0aa73b5e
```

**Requisição 2/3 (POST, tentativa 1 — falhou por bug do PRÓPRIO script de sondagem, não da API):**
```
POST /envelopes/{id}/signers/{signerId}/notifications
{"data":{"type":"notifications","attributes":{}}}   <- intenção
```
Corpo real enviado na rede: `{"data":{"type":"notifications","attributes":[]}}` — o script
decodificou o JSON com `json_decode(..., true)`, e `json_encode([])` de um array PHP vazio produz
`"[]"`, não `"{}"`. **A MESMA armadilha já documentada em §9.6** (`anexarDocumentoPorModelo()`:
`template.data` como array PHP vazio vira `[]` e a API recusa como "não é hash").
```
=> 400 {"errors":[{"code":"bad_request","status":400,
        "source":{"pointer":"/data/attributes"},
        "detail":"attributes deve ser um hash"}]}
```
O ponteiro aponta exatamente para `/data/attributes` — confirma que o problema é a FORMA do valor
(array vs. objeto), não conteúdo faltando.

**Requisição 3/3 (POST, tentativa 2 — corrigida, `new \stdClass()` forçando objeto real):**
```
POST /envelopes/{id}/signers/{signerId}/notifications
{"data":{"type":"notifications","attributes":{}}}   <- "attributes" serializado como OBJETO
=> 201
{"data":{"id":"9887200e-9b13-4a13-931d-cd90e19962cb","type":"notifications",
         "attributes":{"message":null,
                        "summary":[{"signer_id":"74d07cdc-b95c-49d1-91db-1b7a0aa73b5e","notified":true}],
                        "created":"2026-08-14T09:41:50.877-03:00"}}}
```

**✅ MEDIDO, confirmado por 2xx:** o corpo mínimo aceito é `data.type = "notifications"` com
`data.attributes` presente como objeto — `{}` vazio é suficiente, nenhum atributo é obrigatório.
A resposta devolve um recurso `notifications` com `id`, `attributes.message` (`null` quando
nenhuma mensagem customizada é enviada), `attributes.summary` (lista de `{signer_id, notified}`,
um item por signatário notificado) e `attributes.created`. `attributes.message` sugere que uma
mensagem customizada é aceita como atributo — **NÃO MEDIDO** nesta sessão (fora do escopo do
quick 260814-d9s, que corrigia só o bug do corpo ausente).

### 14.3. Rate limit anti-spam desta rodada — nenhum 429 observado

Só 2 `POST` foram feitos nesta sessão (a tentativa 1 nem chegou a ser aceita pelo parser — 400 de
validação, não 429). Não refina nem contradiz o achado da §7 (429 medido em sessão anterior); só
confirma que 2 tentativas espaçadas de ~20s não acionam o limite.

### 14.4. Nenhum token, header `Authorization` ou e-mail real registrado

O `signerId` usado (`74d07cdc-b95c-49d1-91db-1b7a0aa73b5e`) e o `id` do recurso de notificação
criado são identificadores opacos, não PII. Nomes/e-mails de signatário devolvidos pelo `GET
/signers` desta rodada foram descartados do registro (anonimizados no scratchpad da sessão) —
não aparecem aqui.

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

---

## 15. Operações que a v3 NÃO permite (medido 2026-08-14, pesquisa da Fase 131)

Três medições contra a sandbox, com envelope real em `running`
(`f010d235-…`, 4 signatários, criado e ativado pela rodada de gates da Fase 130).
Sandbox confirmado antes de cada chamada (`env=sandbox` **e** `base_url` contendo
`sandbox.clicksign.com`). Nenhum token, secret ou header colado aqui.

### 15.1 Corrigir e-mail de signatário — NÃO EXISTE (gate #8, fechado)

```
PATCH /envelopes/{id}/signers/{signerId}   -> 404
PUT   /envelopes/{id}/signers/{signerId}   -> 404
```

⚠️ **O 404 aqui NÃO é o 404 JSON:API da Clicksign** (`{"errors":[...]}`) — é a **página HTML
genérica** de rota inexistente do site. Esse é o mesmo sinal já visto quando a combinação
verbo+rota simplesmente não está na tabela de rotas da API. Distinguir os dois 404 importa:
o JSON:API significa "recurso não encontrado", o HTML significa "essa rota não existe".

**Consequência de produto:** não há como corrigir o e-mail de um signatário depois do envio.
Corrigir e-mail e trocar a pessoa que assina colapsam no mesmo caminho: cancelar e reemitir.

### 15.2 Cancelar envelope em `running` — NÃO EXISTE CAMINHO

```
DELETE /envelopes/{id}                      -> 403 forbidden   (funciona em draft, proibido em running)
POST   /envelopes/{id}/cancel               -> 404             (HTML genérico — rota não existe)
PATCH  /envelopes/{id}  status:"canceled"   -> 400
        └─ corpo: "status deve estar em: draft, running"
```

A mensagem do 400 é a evidência mais forte: **os únicos status que a API aceita DEFINIR são
`draft` e `running`.** Não existe transição para `canceled` por API.

⚠️ **Mas o cancelamento acontece** — a Fase 129 capturou webhook com evento `cancel`
(`129-GATE.md`) e `ContratoAssinatura` tem o estado `cancelado`. A leitura que sobra:
**cancelar é operação de PAINEL, igual assinar.** O sistema não cancela; ele fica sabendo.

Isso ecoa o achado 2 do `129-GATE.md` (a v3 não expõe link de assinatura) e o bloqueio de
assinatura do `130-GATE.md`: **a v3 é uma API de CRIAÇÃO e CONSULTA, não de operação do ciclo
de vida.** Qualquer fase futura que prometer "o usuário faz X pela nossa tela" deve medir antes,
não assumir que existe endpoint.

### 15.3 O que FUNCIONA nesta família

| Operação | Estado | Referência |
|---|---|---|
| Reenviar notificação | ✅ funciona | §14 — corpo JSON:API medido; 429 anti-spam é resposta ESPERADA, em texto puro |
| Cancelar envelope em `draft` | ✅ funciona | `DELETE /envelopes/{id}` |
| Ativar envelope | ✅ funciona | `ativarEnvelope()` — ⚠️ pela API; a tela do sandbox NÃO ativa envelope gerado por modelo (`130-GATE.md`) |

---

## 16. Os rate limits que realmente mandam são os NOSSOS, não os da Clicksign

⚠️ Esta seção documenta configuração **do próprio app** (`app/Providers/AppServiceProvider.php`),
não medição do fornecedor. Está aqui porque é o que de fato restringe qualquer operação contra a
Clicksign — e porque **duas fases seguidas foram surpreendidas por isso**, cada uma por um bucket
diferente.

| Bucket | Limite | Quem usa | Fase que tropeçou |
|---|---|---|---|
| `clicksign-envelope` | **1/min GLOBAL** | `GerarContratoAssinaturaJob` | 131 |
| `clicksign-webhook` | **3/min GLOBAL** | `ProcessarEventoClicksignJob`, reconciliação | 130 |

Compare com a janela **medida** do lado da Clicksign: **20/min** (§8). Os nossos são de 6 a 20 vezes
mais apertados, de propósito — a montagem de um envelope consome ~15 chamadas, então 1/min protege
o orçamento inteiro.

### Consequência de desenho (Fase 130)

Um comando que varre N contratos **não pode** fazer laço HTTP síncrono: estoura o bucket de 3/min
com poucos contratos. O padrão correto é o comando fazer SELECT + `dispatch()` de um job por
contrato, deixando o `RateLimited` espaçar. Foi assim que `clicksign:reconciliar` foi construído.

### Consequência de produto, AINDA EM ABERTO (Fase 131)

**1 envelope por minuto significa que gerar contrato para duas empresas seguidas deixa a segunda
esperando até um minuto** — e a tela não conta isso a ninguém. O usuário vê "Contrato gerado" e a
situação fica em "Não enviado" nesse intervalo. Registrado como lacuna no `131-UAT.md`, não corrigido.

### Armadilha de ambiente que isso expõe

Job barrado pelo `RateLimited` é **liberado de volta para a fila** (`release`). Em produção
(`QUEUE_CONNECTION=database`) ele volta e roda depois — comportamento correto. Mas **na fila `sync`
do ambiente local, job liberado simplesmente SOME**: sem log, sem `failed_jobs`, sem retry.

Medido em 2026-08-14: um `ContratoAssinatura` ficou preso em `rascunho` sem envelope, com
`updated_at` idêntico ao `created_at`, e **a tela não acusou erro nenhum** — pareceu sucesso.
Quem testar geração de contrato localmente precisa saber disso, ou vai diagnosticar um bug de
produção que não existe.
