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
| #5 | Limite de tamanho de arquivo | Fase 126 | **PARCIAL — ver §9.** 10 MB aceitos; acima disso a trava do nosso client barrou antes de chegar na API |
| #6 | Expiração emite evento distinguível | Schema + 129 | Deixar um envelope vencer |
| #7 | Recusa emite evento distinguível | Schema + 129 | Recusar uma assinatura |
| #8 | Endpoint de correção de e-mail | CLICK-09 | — |
| #11 | Retry e ordem dos webhooks | CLICK-04/05 | Só com webhook ativo |

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

### 11.3. A interface chama de "documento" o que a API chama de "envelope"

Não existe menu "Envelopes" na interface. Envelope em `draft` aparece em **Rascunhos**, e a lista
mostra o **nome do arquivo** (`contrato-1.docx`), **não** o `name` do envelope — este só aparece
depois que o documento sai do rascunho. Custa uma busca frustrada a quem for guiar alguém pela tela.

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
