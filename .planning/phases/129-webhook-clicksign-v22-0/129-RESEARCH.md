# Fase 129: Webhook Clicksign (v22.0) - Pesquisa

**Pesquisado:** 2026-08-12
**Domínio:** Recepção e validação de webhook de terceiro (Clicksign API v3), idempotência,
processamento assíncrono via fila, download de arquivo grande via streaming.
**Confiança:** MÉDIA — o núcleo bloqueante (fórmula do HMAC) segue sem resolução possível por
documentação; só resolve por medição real (gate A1, já decidido no CONTEXT). O vocabulário de
eventos ganhou fontes novas nesta sessão (MÉDIA, doc oficial, não empírico). Padrões Laravel
(rota de webhook, idempotência, streaming) são ALTA confiança — precedente vivo no próprio
código do projeto.

## Summary

Esta pesquisa não teve como objetivo decidir a fórmula do HMAC — o CONTEXT já travou isso como
medição contra webhook real (D-08). O objetivo foi levantar tudo que TEM fonte (mesmo que só
documental) para que a rota-sonda saiba o que procurar, e para que o schema de
`contrato_assinatura_eventos` (DADOS-03) nasça na forma certa em vez de descoberta por tentativa
e erro.

Três achados novos desta sessão mudam o que o planejamento deve prever:

1. **O vocabulário de eventos da API v3 tem fonte oficial completa** (`docs/eventos`, MÉDIA
   confiança) — 24 nomes documentados, incluindo `refusal` (recusa) e `deadline` (expiração).
   Isso responde, no nível de documentação (não empírico), os Gates #6 e #7: **existem, sim,
   nomes de evento distintos** para os dois casos. Os gates continuam "abertos" no sentido do
   ROADMAP porque nunca foram DISPARADOS de verdade contra este projeto — mas o planejamento
   não parte mais do zero.

2. **Achado crítico para a D7 (CLICK-05): o evento `deadline` pode fechar o envelope com
   assinatura PARCIAL.** A documentação descreve que, ao vencer o prazo, se o documento tem
   pelo menos uma assinatura ele é **finalizado** (mesmo destino final de `document_closed`);
   sem nenhuma assinatura, ele é cancelado. Isso bate com o campo `deadline_partial_signature_action`
   (default `"closed"`) já MEDIDO em `CLICKSIGN-SANDBOX-EMPIRICO.md` §5. **Consequência
   direta:** reconsultar o envelope e ver `status == "closed"` NÃO é garantia de que todos os
   signatários obrigatórios assinaram — pode ser um fechamento forçado por prazo. O gate de
   liberação (D7/CLICK-05) precisa checar a situação individual de CADA signatário obrigatório
   (a tabela `contrato_assinatura_signatarios` já existe para isso, D-09 da Fase 125), nunca só
   o status do envelope.

3. **A doc oficial mistura DUAS formas de payload diferentes, e uma delas parece
   desatualizada/de outra versão da API.** A forma documentada do corpo do POST que a Clicksign
   envia AO NOSSO webhook (`{"event":{"name","data","occurred_at"},"document":{...}}`) é
   estruturalmente diferente da forma JSON:API medida empiricamente para o RECURSO de eventos
   (`GET .../events` → `{"data":[{"type":"events","attributes":{"name","data","created"}}]}`,
   `CLICKSIGN-SANDBOX-EMPIRICO.md` §3). E o exemplo do objeto `document` dentro do payload de
   webhook usa sintaxe e nomes de campo (`downloads.original_file_url`, chave `key` na raiz) que
   **não batem** com a forma v3 medida (`files.original`, `data.attributes`). **Isto é
   exatamente o padrão de erro que já mordeu esta integração duas vezes antes** — não confiar
   na forma do `document` dentro do payload de webhook até medir contra um webhook real.

**Recomendação primária:** a rota-sonda (D-07/D-08) deve gravar o payload bruto ANTES de
qualquer parsing de forma, e o comando de verificação (D-09) deve tratar a forma do corpo como
desconhecida até a primeira medição real — inclusive se o `document` embutido vier vazio, com
campos a mais, ou com nomes diferentes do que a doc mostra.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|---|---|---|---|
| Recepção HTTP do webhook (rota, leitura de raw body) | API / Backend | — | Endpoint público sem sessão; Laravel controller é a fronteira correta |
| Validação de assinatura HMAC | API / Backend | — | Segurança de borda; nunca no client-side, nunca na fila (precisa responder rápido) |
| Gravação bruta do evento (`contrato_assinatura_eventos`) | API / Backend + Database | — | Escrita síncrona rápida, antes de qualquer I/O externo |
| Idempotência por `payload_hash` | Database | API / Backend | Garantia real é a constraint única do banco (MariaDB); o `firstOrCreate`/check em código é só UX |
| Reconsulta do estado agregado do envelope | API / Backend (Job) | — | Chamada de saída à Clicksign, potencialmente lenta — nunca síncrona na resposta HTTP |
| Decisão de liberação operacional (`rotearServico`) | API / Backend (Job) | Database | Depende de ler `contrato_assinatura_signatarios` local, não só do payload |
| Download do PDF assinado (streaming) | API / Backend (Job) + Storage | — | Job de fila; grava em disco privado (`storage/app`), nunca em disco público |
| Resposta HTTP síncrona ao provedor (200/401/5xx) | API / Backend | — | Decisão de status code cabe só na janela síncrona (validar+gravar+enfileirar), nunca pós-fila |

## Package Legitimacy Audit

**Não se aplica.** Nenhum pacote Composer ou npm novo é necessário para esta fase — confirmado
por `STACK.md` da milestone (seção "Supporting Libraries": "Nenhuma... a integração Clicksign
v3 não exige nenhum pacote Composer novo") e reconfirmado nesta sessão: HMAC via `hash()`/
`hash_hmac()`/`hash_equals()` nativos do PHP, download via `Http::withOptions(['sink' => ...])`
(Laravel HTTP Client, já instalado), fila via driver `database` (já configurado). Nenhum
`composer require` ou `npm install` nesta fase.

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|---|---|---|
| CLICK-03 | Recusa webhook cuja assinatura não confere (gate A1 bloqueante) | Seção "Lacuna prioritária" — candidatas de fórmula, precedente `HubspotWebhookController`, disciplina `hash_equals` + raw body |
| CLICK-04 | Webhook repetido não duplica evento/signatário/assinatura/`MlbEmpresa`/implementação | Seção "Idempotência" — precedente `payload_hash` único + guard de efeito, padrão SQLSTATE 23000 já usado na Fase 127 |
| CLICK-05 | Liberação só a partir do estado agregado reconsultado do envelope | Seção "Achado crítico: `deadline` pode fechar parcial" — o gate precisa checar signatários, não só `status` |
| CLICK-06 | Processamento pesado na fila, não na requisição HTTP | Seção "Padrão Laravel: webhook rápido + Job" — precedente `AnalyzeCompanySugadoresJob`, `GerarContratoAssinaturaJob` |
| CLICK-11 | PDF assinado baixado e guardado localmente | Seção "Download do PDF assinado via streaming" — `Http::withOptions(['sink'=>...])`, disciplina do link de 5 minutos (D-12) |
| DADOS-03 | Todo evento recebido é gravado bruto, evento repetido nunca processado 2x | Seção "Vocabulário de eventos" + "Idempotência" — schema de `contrato_assinatura_eventos`, precedente `hubspot_eventos` |
</phase_requirements>

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** A liberação é POR SERVIÇO, cada um no seu tempo — via
  `EmpresaOperacionalRouter::rotearServico(Company, string $nomeServico)`.
- **D-02:** Uma ficha operacional só, enriquecida. Guard de ficha única precisa existir no
  caminho por-serviço, não pode ser herdado do guard atual (`guardPorEmpresa`).
- **D-03:** "Liberada" é estado próprio, gravado independente de gerar ficha. Criar ficha é
  consequência da liberação, nunca a definição dela.
- **D-04:** Contrato recusado ou expirado NÃO mexe no cadastro. Desativar serviço é decisão
  humana, nunca automática.
- **D-05:** Tabela nova de liberações, com histórico (quem, quando, por quê, por qual via).
- **D-06:** A reconsulta da D7 é do envelope QUE MUDOU, não de todos os da empresa (rate limit
  medido de 20/min no sandbox).
- **D-07:** O webhook real chega por TÚNEL para a máquina local (ngrok/cloudflared). VPS
  recusado — risco de publicar trabalho de outra sessão.
- **D-08:** Varrer VÁRIAS variantes de HMAC num único webhook real (hex vs base64, ordem
  body+secret vs secret+body, com/sem timestamp). Se a varredura ampla não bater, a fase PARA.
- **D-09:** A rota-sonda é temporária; a capacidade vira comando Artisan (padrão
  `ClicksignSondarModelo.php`), reaproveitável no cutover de produção.
- **D-10:** Resposta HTTP depende do TIPO de erro — 5xx se transitório (retry seguro via
  dedup), 200 se erro é do payload em si. Janela síncrona é estreita: validar assinatura,
  gravar evento, enfileirar — só isso é classificável no momento da resposta.
- **D-11:** Job morto marca estado de falha legível + log, sem canal de alerta novo (a 130 lê).
- **D-12:** Todo retry de download reconsulta o envelope para obter link fresco (nunca reusar
  link do payload — expira em 5 min).
- **D-13:** Disco privado (`storage/app`), servido por rota autenticada — nunca `public`.
- **D-14:** Falha permanente de download NÃO prende a liberação da empresa; contrato fica
  "assinado, PDF pendente".

### Claude's Discretion

D-08, D-09, D-11, D-12, D-13, D-14 (listadas acima) — decisões estruturais já foram tomadas
pelo usuário (D-01 a D-07, D-10); estas são de detalhe, cada uma com motivo registrado.

### Deferred Ideas (OUT OF SCOPE)

- Alerta de contrato preso e job de reconciliação — Fase 130 (REDE-02/03/04).
- Liberação manual auditada — Fase 130 (SC3).
- Tela do Administrativo mostrando contratos e liberações — Fase 131.
- Ligar o bloqueio do roteamento operacional — Fase 133 (modo observação até lá).
</user_constraints>

## Lacuna Prioritária: Vocabulário de Eventos do Webhook v3

### 1. Nomes de evento, forma do payload de cada um

**Fonte:** `developers.clicksign.com/v3.0/docs/eventos` (WebFetch, 2026-08-12) — **confiança
MÉDIA** (doc oficial, mas não corroborado por webhook real disparado deste projeto).

Lista completa documentada (24 nomes, agrupados por área):

| Grupo | Eventos | O que significa |
|---|---|---|
| Ciclo de vida do documento | `upload`, `add_image`, `close`, `auto_close`, `deadline`, `document_closed`, `cancel` | upload = documento anexado; close = finalização MANUAL; auto_close = finalização automática após última assinatura; deadline = prazo atingido; document_closed = documento pronto para download; cancel = cancelamento manual |
| Signatários | `add_signer`, `remove_signer`, `sign`, `signature_started`, `refusal` | refusal = "ocorre quando um documento é recusado" |
| Autenticação | `attempts_by_whatsapp_exceeded`, `attempts_by_liveness_or_facematch_exceeded`, `liveness_refused`, `facematch_refused`, `biometric_refused`, `documentscopy_refused`, `ocr_refused` | fora do escopo desta fase (D-07: só `auth: "email"`) |
| Configuração | `update_deadline`, `update_auto_close`, `update_locale`, `custom` | alteração de atributos do envelope pós-criação |
| WhatsApp (termo de aceite) | `acceptance_term_enqueued/sent/completed/refused/canceled/expired/error` | fora do escopo (D-07: canal é e-mail) |

⚠️ **Divergência com a medição empírica:** os 7 eventos MEDIDOS de verdade contra um envelope
real e assinado (`CLICKSIGN-SANDBOX-EMPIRICO.md` §3) foram: `upload`,
**`update_block_after_refusal`**, `add_signer`, `signature_started`, `sign`, `auto_close`,
`document_closed`. O nome `update_block_after_refusal` **não aparece** na lista documentada
acima — é um evento de CONFIGURAÇÃO (o atributo `block_after_refusal` mudou), não de recusa em
si. **Recomendação:** tratar a lista documentada como não-exaustiva; o schema de
`contrato_assinatura_eventos.name` deve ser STRING livre (nunca `enum` de banco — já é a
convenção do projeto), porque a Clicksign pode emitir nomes que nenhuma das duas fontes cobre.

### 2. Header e fórmula da assinatura — AMBÍGUO, confirmado nesta sessão

**Header:** `Content-Hmac`, formato `Content-Hmac: sha256=<hash>` — isto está CONVERGENTE em
todas as fontes (doc oficial, `STACK.md`, `PITFALLS.md`) e é o único ponto de confiança ALTA
desta seção.

**Fórmula — transcrição literal obtida nesta sessão** (WebFetch direto em
`docs/seguranca-de-webhooks`, pedindo transcrição palavra-por-palavra, não resumo):

> "Quando um novo *webhook* é cadastrado, automaticamente é gerado um código **HMAC SHA256
> Secret**... A cada disparado de *webhook*, a Clicksign calcula o **Hash SHA256 da soma do
> Body da requisição com o Secret** e adiciona essa informação ao cabeçalho... O servidor que
> receber a requisição deve fazer o mesmo cálculo de Hash... basta calcular o Hash SHA256 da
> soma do Body da requisição recebida com o Secret já conhecido."

Isto é literalmente **"soma"** (concatenação), não "chave" — uma leitura estritamente literal
do texto favorece `hash('sha256', $body . $secret)` (ordem `body+secret`, já que "soma do Body
... com o Secret" lista o Body primeiro). Mas o NOME do recurso ("HMAC SHA256 Secret") sugere
HMAC clássico (`hash_hmac('sha256', $body, $secret)`).

**Um fetch anterior, na mesma sessão, com prompt menos literal, interpretou o texto como "esta
é a implementação clássica de HMAC-SHA256... a página recomenda usar hash_hmac()"** — mas ao
pedir transcrição literal do texto, **essa afirmação não se sustentou**: não há nenhuma menção
literal a `hash_hmac`, funções nativas por linguagem, nem exemplo de código na página, nesta
sessão. **Isto é a prova prática de por que a doc não pode ser "lida uma vez e confiada"** — a
mesma página gerou duas interpretações diferentes dependendo de como foi perguntada. Reforça a
decisão do CONTEXT (D-08) de nunca resolver isso por leitura, só por medição.

**Candidatas para a varredura da D-08** (nenhuma eleita vencedora — decisão do gate A1):

| # | Fórmula | Fonte | Leitura que a favorece |
|---|---|---|---|
| 1 | `hash('sha256', $body . $secret)` | `STACK.md`, leitura literal desta sessão | "soma do Body com o Secret" lido como concatenação, Body primeiro |
| 2 | `hash('sha256', $secret . $body)` | Nenhuma fonte direta — variante de ordem | Mesma leitura, ordem invertida (a doc não deixa claro qual "soma" primeiro) |
| 3 | `hash_hmac('sha256', $body, $secret)` | `PITFALLS.md`, nome do recurso ("HMAC SHA256 Secret") | Nomenclatura sugere HMAC clássico com `$secret` como chave |
| 4 | `hash_hmac('sha256', $secret, $body)` | Variante de troca de parâmetros — erro comum de quem inverte a ordem dos argumentos do `hash_hmac()` do PHP | Cobre o erro de digitação mais provável ao implementar #3 |

**Confirmado nesta sessão, sem ambiguidade:** o hash final é representado em **hex** (o exemplo
do header é `sha256=20dbdb06a522...`, 64 caracteres hex, não base64) — a saída de
`hash_hmac(..., true)` (binário) teria que passar por `bin2hex()`, ou usar `hash_hmac(...,
false)` direto (que já devolve hex). A D-08 não precisa varrer hex-vs-base64 como o CONTEXT
original sugeria — **isso já está resolvido pela doc, o único eixo de varredura real é a
fórmula (soma vs HMAC) e a ordem dos operandos.**

### 3. Gate #6 — expiração de prazo emite evento distinguível?

**Achado novo desta sessão, MÉDIA confiança (doc oficial + campo já medido, mas o disparo em si
nunca foi observado por este projeto):** sim, existe o evento `deadline`. Fonte
(`docs/evento-deadline`, WebFetch 2026-08-12):

> "O evento `deadline` dispara quando o prazo de assinatura do documento é atingido. Neste
> momento, o sistema realiza mudanças automáticas de status: se o documento tem pelo menos uma
> assinatura, ele é finalizado; caso contrário, é cancelado."

Payload documentado:
```json
{
  "event": {
    "name": "deadline",
    "data": { "reached_at": "2023-03-27T14:11:21.973-03:00" }
  },
  "document": { }
}
```

**Isto CORROBORA o campo `deadline_partial_signature_action` (default `"closed"`) já MEDIDO
empiricamente** em `CLICKSIGN-SANDBOX-EMPIRICO.md` §5 — duas fontes independentes (doc + campo
medido) convergem para o mesmo comportamento. Isso eleva a confiança de BAIXA (só doc) para
MÉDIA, mas **não é ALTA** porque o disparo do evento em si — o POST chegando de verdade com
`name: "deadline"` — nunca foi observado.

**⚠️ Consequência que muda o desenho da CLICK-05 (D7):** se o envelope pode ser fechado
(`status: "closed"`) por expiração de prazo com assinatura PARCIAL, então **`status == "closed"`
sozinho não é suficiente para liberar o operacional.** O gate precisa, adicionalmente, contar
signatários com `situacao == 'assinou'` na tabela `contrato_assinatura_signatarios` e comparar
com os signatários OBRIGATÓRIOS do contrato (o cliente/contratante — não necessariamente os 3
fixos da ECF, cujo papel é formal). Isto não contradiz a D7 ("estado agregado reconsultado") —
**reforça** que "estado agregado" precisa incluir a granularidade de signatário, não só o
enum de topo do envelope. Recomendação: a regra de liberação deve ser algo como "envelope
fechado E cliente (papel `contratante`) tem `situacao == 'assinou'`", nunca só "envelope
fechado".

**Recomendação para o planejamento:** tratar como recomendação de design com confiança MÉDIA,
não como fato cravado — mas testar EXPLICITAMENTE o cenário "envelope closed via deadline com
1 de 2 signatários pendente" no teste automatizado da fila (fixture sintética, já que o gate
empírico real de "deixar um envelope vencer" segue não executado por este projeto).

### 4. Gate #7 — recusa de signatário emite evento distinguível?

**Achado novo desta sessão, MÉDIA confiança:** sim, `refusal`. Fonte (`docs/evento-refusal`,
WebFetch 2026-08-12), payload documentado:

```json
{
  "event": {
    "name": "refusal",
    "data": {
      "signer": { "key": "...", "email": "...", "name": "...", "sign_as": ["administrator"] },
      "refusal": { "reasons": ["array de motivos"], "comment": "string" },
      "account": { "key": "..." }
    }
  },
  "document": { }
}
```

A doc **não especifica** o que acontece com o `status` do envelope depois de uma recusa — se o
envelope continua `running` (outros signatários podem ainda assinar) ou se recusa força
`closed`/cancelamento. **Isto continua sem resposta e é o tipo de pergunta que só a medição
resolve** (deixar um signatário recusar no sandbox, dentro do gate empírico ainda não
executado). Note que o `refusal.reasons` sugere categorias pré-definidas de motivo (a Clicksign
oferece opções ao signatário na tela de recusa) — se confirmado, isso é dado estruturado útil
para a tela de auditoria da Fase 131, não string livre.

**Recomendação:** gravar `refusal.reasons` e `refusal.comment` dentro do JSON bruto do evento
(a coluna `payload`/`data` de `contrato_assinatura_eventos`) mesmo sem promover a colunas
próprias nesta fase — promoção pode vir na 131 se a tela precisar filtrar por motivo.

### 5. Gate #11 — política de retry e garantia de ordem

**Confirmado NEGATIVAMENTE nesta sessão, com 3 fontes distintas checadas:**
`docs/introducao-a-webhooks`, `docs/melhores-praticas-webhooks`, `docs/seguranca-de-webhooks`.
**Nenhuma delas documenta** número de tentativas, intervalo entre retries, timeout esperado,
nem garantia de ordem entre eventos do mesmo envelope. A única afirmação relacionada
encontrada: *"Qualquer resposta fora do intervalo 2XX informará que você não recebeu seu
webhook, incluindo o 301 Redirect"* (`melhores-praticas-webhooks`) — confirma que HTTP não-2xx
é tratado como falha de entrega pelo lado da Clicksign (o que motiva retry do lado dela, mas
sem revelar quantas vezes nem quando).

**Isto reconfirma, não contradiz, o que `PITFALLS.md` já havia concluído em 2026-08-07** ("não
é uma lacuna de pesquisa, é o dado"). **Tratamento obrigatório para o planejamento: assumir
pior caso — at-least-once, sem garantia de ordem, retry desconhecido.** Nenhum design pode
depender de "o evento X sempre chega antes do Y".

### Nota de confiabilidade sobre a forma do CORPO do webhook (não do recurso de eventos)

Uma distinção que NENHUMA pesquisa anterior da milestone havia isolado: existem **duas formas
de payload diferentes** documentadas para "eventos" na Clicksign v3, e é fácil confundir uma
com a outra ao desenhar o schema:

| Payload | Onde aparece | Forma | Confiança |
|---|---|---|---|
| Recurso de eventos (consulta) | `GET /envelopes/{id}/documents/{id}/events` ou `GET /envelopes/{id}/events` | JSON:API — `{"data":[{"id","type":"events","attributes":{"name","data","created"}}],"meta":{"record_count"},"links":{...}}` | **ALTA** — medido empiricamente (§3 do empírico) E confirmado pela doc (`reference/eventos-do-envelope`) nesta sessão, convergentes |
| Corpo do POST que a Clicksign ENVIA para o nosso webhook | requisição de saída da Clicksign para a URL cadastrada | Não-JSON:API — `{"event":{"name","data","occurred_at"},"document":{...}}` | **BAIXA-MÉDIA** — só documental (`docs/evento-close`, `docs/evento-document-closed`, `docs/evento-deadline`, `docs/evento-refusal`), nunca observado por este projeto |

**A forma do `document` embutido no corpo do webhook, mostrada nos exemplos da doc, usa nomes
de campo que CONTRADIZEM o que já foi medido empiricamente para a API v3** (`downloads.
original_file_url` no exemplo da doc vs. `files.original` medido em §7 do empírico; chave `key`
solta na raiz vs. `data.attributes.*` do JSON:API). Isso é consistente com o padrão já
registrado nesta milestone duas vezes (`content_base64` sem Data URI, `Authorization` com
`Bearer`) — **a doc de exemplo às vezes reflete uma versão anterior da API ou um objeto
composto de forma inconsistente entre páginas.**

**Recomendação concreta para o schema de `contrato_assinatura_eventos` (DADOS-03):** a coluna
que guarda o payload bruto deve ser um JSON genérico (`$table->json('payload')`, sem promover
`document.*` a colunas específicas nesta fase) — exatamente o padrão que `hubspot_eventos` já
usa. Promover campos do `document` embutido para colunas só depois que a primeira medição real
(D-07/D-08) mostrar a forma verdadeira. Isso é reforço direto de uma disciplina que a
`CLICKSIGN-SANDBOX-EMPIRICO.md` §9.1/§10.2 já registrou como "lição que vale para toda a
milestone: forma de resposta não é contrato de entrada" — aqui o corolário é "forma de
documentação de exemplo não é forma de payload real".

## Padrão Laravel: Rota de Webhook Fora do CSRF, Validação Timing-Safe, Fila

**Confiança ALTA** — precedente vivo e testado no próprio código do projeto
(`app/Http/Controllers/Api/HubspotWebhookController.php`, `routes/web.php:75-83`).

### Rota — nenhuma mudança de infraestrutura necessária

`bootstrap/app.php:21-24` já isenta `api/webhooks/*` de CSRF:
```php
$middleware->validateCsrfTokens(except: [
    'implementacao/*',
    'api/webhooks/*',   // Phase 26 — receivers HMAC
]);
```
A rota nova (`/api/webhooks/clicksign`) cai automaticamente nesta regra — **não precisa tocar
em `bootstrap/app.php`**. Seguir o padrão exato de `routes/web.php:80-83`:
```php
// pt-BR: Receiver de webhooks Clicksign — Fase 129 (CLICK-03/04/05/06)
// URL cadastrada no painel Clicksign: https://admin.ecfconsultoria.com.br/api/webhooks/clicksign
// (endereço TEMPORÁRIO de túnel durante a medição do gate A1, D-07)
// Autenticacao via Content-Hmac (formula ainda em varredura — D-08)
// CSRF isento por bootstrap/app.php (api/webhooks/*) + withoutMiddleware (defensivo)
Route::post('/api/webhooks/clicksign', [\App\Http\Controllers\Api\ClicksignWebhookController::class, 'receive'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('webhooks.clicksign');
```

### Validação — raw body, `hash_equals`, nunca `$request->input()`

Precedente exato em `HubspotWebhookController::receive()` (linhas 68-90): ler
`$request->getContent()` ANTES de qualquer parsing, comparar com `hash_equals()` (nunca `===`,
timing attack), truncar o raw body ao gravar evento inválido (`RAW_BODY_MAX_BYTES = 65_000`,
mesmo padrão a reaplicar). A DIFERENÇA estrutural para a Clicksign (não copiar 1:1):

| Aspecto | HubSpot (existente) | Clicksign (nova) |
|---|---|---|
| Header de assinatura | `X-HubSpot-Signature-v3` | `Content-Hmac` |
| Timestamp separado | `X-HubSpot-Request-Timestamp` | **Não existe** — sem replay window nativa (ver Pitfall 5 do `PITFALLS.md`) |
| Fórmula | `base64(hmac_sha256(secret, METHOD+URI+body+ts))` | Candidatas na seção "Lacuna prioritária" acima — NÃO copiar a do HubSpot |
| Representação | base64 | hex (`sha256=<hex>`) |

**Réplica correta:** reusar a DISCIPLINA (raw body, `hash_equals`, log sem secret, truncamento),
não a fórmula nem a presença de timestamp.

### Gravação bruta + fila — precedente `AnalyzeCompanySugadoresJob` / `GerarContratoAssinaturaJob`

Padrão já estabelecido: controller grava o evento (rápido, síncrono) e despacha
`ProcessarEventoClicksignJob::dispatch($evento)` para a fila `database`. O job faz a reconsulta
do envelope (`ClicksignClient::consultarEnvelope()`), atualiza `contrato_assinatura_signatarios`,
decide liberação (D7) e dispara `EmpresaOperacionalRouter::rotearServico()`. `failed()` grava
estado de falha legível (D-11) — mesmo padrão de `GerarContratoAssinaturaJob::failed()`
(`127-05-SUMMARY.md`, `status = erro` + `erro_mensagem` podada de e-mail).

## Idempotência por `payload_hash`

**Precedente direto e já usado neste projeto na Fase 127** para a mesma classe de problema
(dois cliques/duas filas tentando criar o mesmo registro simultaneamente):

```php
try {
    ContratoAssinaturaEvento::create([...]);
} catch (\Illuminate\Database\QueryException $e) {
    if ((string) $e->getCode() === '23000') {
        // já existe — idempotência funcionou, não é erro
        return response()->json(['ok' => true, 'duplicado' => true]);
    }
    throw $e;
}
```
Fonte: `127-06-SUMMARY.md` — "a garantia é a constraint composta capturada por
`catch (QueryException)` com `(string) $e->getCode() === '23000'` (SQLSTATE, não
`errorInfo[1]` do MySQL) — precedente copiado literalmente de `NpsController.php:1835`."
**Usar `$e->getCode()` (SQLSTATE), nunca `$e->errorInfo[1]`** — MariaDB e SQLite retornam o
SQLSTATE de forma consistente entre drivers; o código de erro numérico específico do MySQL
(`errorInfo[1] == 1062`) não existe no SQLite dos testes, e isso já foi uma armadilha registrada
no projeto.

**Diferença importante em relação ao `hubspot_eventos`:** aquela tabela NÃO tem um índice único
— a idempotência do HubSpot é feita por CONSULTA (`where('object_id', ...)->exists()`), não por
constraint de banco. **Para a Clicksign, o CONTEXT já decidiu que a garantia é
`payload_hash` UNIQUE** (nível de banco, não só de consulta) — mais forte, e correto: duas
requisições HTTP concorrentes (retry real da Clicksign) podem passar pela verificação de
"existe?" ao mesmo tempo (corrida clássica de leitura-depois-escrita) se a única garantia for
uma consulta antes do insert. A constraint única é a única forma que sobrevive a essa corrida.

**Cálculo do hash:** `hash('sha256', $rawBody)` — sobre o BODY BRUTO recebido (não sobre o JSON
decodificado, que pode reordenar chaves), coluna `payload_hash` STRING(64) com índice único
nomeado à mão (a tabela `contrato_assinatura_eventos` provavelmente vai colidir com o limite de
64 caracteres do MariaDB para nome de índice autogerado — seguir a disciplina já aplicada em
`contrato_assinatura_signatarios`: nomear toda FK/índice à mão).

**Segundo guard necessário — efeito colateral, não só ingestão (Pitfall 3 do `PITFALLS.md`):**
idempotência de INGESTÃO (não gravar o mesmo payload duas vezes) NÃO impede que dois eventos
DIFERENTES do mesmo envelope (ex.: `sign` de A e depois `document_closed`) tentem liberar a
empresa duas vezes. `EmpresaOperacionalRouter::rotearServico()` precisa continuar idempotente
por si só — o guard existente (`MlbEmpresa::where('company_id',...)->exists()`) já cobre o
efeito final, mas a "liberação" (D-05, tabela nova) precisa do mesmo tipo de guard: checar se já
existe liberação para (empresa, serviço) antes de criar uma nova linha, não confiar em "este
evento específico ainda não processei".

## Corrida de Dois Webhooks Idênticos (MariaDB vs. SQLite)

O projeto já documentou 5 armadilhas de MariaDB que o SQLite dos testes não pega (ver
`129-CONTEXT.md`, migrations recentes da Fase 125/127). A relevante para esta fase:

- **Nome de índice único acima de 64 caracteres é falha SILENCIOSA no MariaDB** — cria a tabela
  SEM o índice, migration fica `Pending`, e a garantia de idempotência simplesmente NÃO EXISTE
  em produção mesmo com o código parecendo certo. Nomear `payload_hash` (unique) à mão, curto:
  ex. `cae_payload_hash_uniq`. Testar o comprimento antes de commitar — a tabela
  `contrato_assinatura_eventos` já é um nome de 29 caracteres; qualquer FK autogerada para ela
  (`contrato_assinatura_eventos_contrato_assinatura_id_foreign`) passa dos 64 — nomear também.
- **SQLite não é confiável para provar a corrida real** — dois processos concorrentes
  (webhook real + retry da Clicksign chegando quase simultâneo) só se comportam como o MariaDB
  de produção sob MariaDB de verdade. Um teste de corrida "de verdade" (dois `INSERT`
  concorrentes via múltiplas conexões) não é prático em PHPUnit padrão; a prática já aceita no
  projeto (Fase 127) é: testar o CATCH do `QueryException` diretamente (inserir manualmente uma
  vez, tentar inserir de novo no mesmo teste, sem paralelismo real) — cobre a lógica de
  idempotência sem simular a corrida de rede.

## Testando Webhook com Assinatura HMAC em PHPUnit sem Secret no Repositório

**Precedente:** `Phase34HubspotWebhookTest.php:61-65` — o teste calcula a assinatura ELE MESMO,
usando um secret de FIXTURE (`self::SECRET`, uma constante do teste, nunca o `.env` real):

```php
private function assinatura(string $body, string $ts, string $secret = self::SECRET): string
{
    $methodUriBody = 'POST' . $url . $body . $ts;
    return base64_encode(hash_hmac('sha256', $methodUriBody, $secret, true));
}
```

O secret real (produção/sandbox) fica só no `.env` gitignored — o teste usa um valor fixo
qualquer, e o `config('services.clicksign.webhook_secret')` é sobrescrito via `config()->set()`
no `setUp()` do teste para bater com o mesmo valor.

**⚠️ Ressalva já registrada por `PITFALLS.md` (Pitfall 5) e que se aplica INTEGRALMENTE aqui:**
um teste que calcula o HMAC usando a MESMA função que o código de produção usa dá **falso
verde** — prova que o código é consistente consigo mesmo, não que a fórmula bate com a
Clicksign real. **A Fase 129 precisa de DOIS tipos de teste:**

1. **Teste de unidade da rota** (padrão acima) — usa a MESMA fórmula do código, prova que o
   fluxo (ler raw body, comparar, gravar evento, enfileirar) funciona mecanicamente.
2. **Fixture calculada FORA do código de produção** (exigência já travada no ROADMAP, citada em
   `129-CONTEXT.md` linha 98: "o fixture de HMAC do teste automatizado **deve** ser calculado
   fora do código de produção") — um valor hex fixo, gerado uma única vez por uma ferramenta
   independente (ex.: a "ferramenta de validação" que a própria doc da Clicksign menciona em
   `seguranca-de-webhooks`, ou um script `php -r` isolado rodado manualmente uma vez), colado
   como string literal no teste. Prova que a fórmula do CÓDIGO bate com a fórmula da
   DOCUMENTAÇÃO/FERRAMENTA, não só consigo mesma. **Só depois do gate A1 resolver a fórmula
   real** (via webhook do túnel) é que este fixture pode ser recalculado com a fórmula
   confirmada.

## Download do PDF Assinado via Streaming

**Confiança MÉDIA-ALTA** — feature bem estabelecida do Guzzle (base do Laravel HTTP Client),
não específica deste projeto; múltiplas fontes convergentes (Laravel News, blogs técnicos
independentes), sem contradição entre elas.

```php
// Streaming direto pra disco — nunca carregar o PDF inteiro em memória com ->body()
$destino = storage_path("app/contratos/{$contrato->id}/assinado.pdf");
Http::withOptions(['sink' => $destino])
    ->timeout(30)
    ->get($linkFrescoDoS3);
```

**Por que importa aqui especificamente:** o link `files.signed`/`files.original` é uma URL S3
pré-assinada com `X-Amz-Expires=300` (5 minutos, MEDIDO em `CLICKSIGN-SANDBOX-EMPIRICO.md` §7).
Isso não muda a mecânica do streaming, mas define a JANELA de tempo em que o download precisa
completar — para um PDF de contrato (tipicamente KB, não MB, ver §9.3 do empírico: contrato
real de 15 páginas ~180KB), 5 minutos é folga ampla mesmo com streaming, não é um requisito de
performance apertado. O requisito real é **não deixar o link envelhecer ANTES de começar o
download** — ou seja, o job precisa chamar `consultarEnvelope()` (ou o endpoint de documento)
IMEDIATAMENTE antes do `Http::get()`, nunca reusar um link recebido minutos antes no payload do
evento (isto já é a D-12, travada no CONTEXT).

**Disco:** `storage/app` (privado), NUNCA `storage/app/public` — D-13 já trava isso. Servir por
rota autenticada (`Storage::disk('local')->response($path)` ou similar), não por URL direta.

**Tratamento de falha (D-14):** se o `Http::withOptions(['sink'=>...])` falhar (timeout, 404 no
link expirado, erro de rede), o job NÃO deve bloquear a liberação da empresa — a liberação já
aconteceu (D7, baseada na assinatura confirmada, não no download). O contrato fica com
`pdf_assinado_path` NULL e um estado próprio ("assinado, PDF pendente") para o Administrativo
ser avisado, mesma via da REDE-03 (Fase 130).

## Resposta HTTP ao Provedor (D-10 aplicada à janela síncrona real)

A D-10 do CONTEXT já resolveu o PRINCÍPIO (5xx para erro transitório, 200 para erro de
payload). A pesquisa contribui a MATRIZ CONCRETA de decisão para a janela síncrona real
(validar assinatura → gravar evento → enfileirar):

| Situação na janela síncrona | Status HTTP | Justificativa |
|---|---|---|
| Assinatura não confere (`hash_equals` falha) | 401 | Não é "erro" a corrigir por retry — se o secret está errado, retry não resolve. Gravar como `signature_valid=false` (padrão `hubspot_eventos`) |
| JSON malformado / body vazio | 200 (ou 400 — decisão do plano) | D-10: "erro é do payload em si — reenviar não conserta". Gravar o raw body truncado como evento inválido para investigação, mas não incentivar retry infinito |
| Banco indisponível ao tentar gravar o evento | 5xx | Transitório — dedup por `payload_hash` torna reenvio seguro |
| Falha ao ENFILEIRAR (`dispatch()` lança exceção — fila indisponível) | 5xx | Mesma lógica — o evento já foi gravado (se a gravação passou), mas o processamento não foi agendado; melhor a Clicksign reenviar do que perder o evento silenciosamente |
| Assinatura válida, evento gravado, enfileirado com sucesso | 200 | Sucesso — o que acontece DEPOIS (na fila) nunca deve influenciar este status code, por definição da janela síncrona (D-10, ressalva já registrada no CONTEXT) |

**Nota:** diferente do HubSpot (`HubspotWebhookController::receive()` sempre responde `200` até
para timestamp/signature inválidos, decisão histórica daquela integração), aqui a D-10 pede
`401` explícito para assinatura inválida — não copiar o padrão 200-sempre do HubSpot sem
verificar contra a decisão travada desta fase.

## Common Pitfalls

### Pitfall A: Confiar em `status == "closed"` como sinônimo de "todos assinaram"
**O que dá errado:** liberar o operacional porque o envelope reconsultado mostra `closed`, sem
checar se foi um fechamento normal (todos assinaram) ou forçado por `deadline` com assinatura
parcial.
**Por que acontece:** a doc não deixa isso óbvio — "closed" parece, à primeira vista, sempre
significar "concluído com sucesso".
**Como evitar:** o gate de liberação (CLICK-05) checa `status == closed` E TODOS os signatários
com papel obrigatório têm `situacao == 'assinou'` em `contrato_assinatura_signatarios`.
**Sinal de alerta:** contrato liberado ao operacional com signatário `pendente`/`recusou` na
tabela de signatários.
**Fase:** 129, gate CLICK-05.

### Pitfall B: Copiar a fórmula/estrutura de replay window do HubSpot
Já documentado extensamente em `PITFALLS.md` Pitfall 5 — reforçado nesta sessão: a Clicksign
não tem timestamp de assinatura nativo, então não há "janela de replay" possível do mesmo jeito
que o HubSpot tem. Mitigar replay via idempotência de evento (`payload_hash`), não via checagem
de idade.

### Pitfall C: Promover campos do `document` embutido no webhook antes de medir a forma real
Documentado na seção "Nota de confiabilidade" acima — a doc mistura exemplos de formas
diferentes de API. Manter `contrato_assinatura_eventos.payload` como JSON genérico até a
primeira medição real confirmar a forma.

### Pitfall D: Nome de índice/FK único acima de 64 caracteres na tabela nova
Já é o padrão-armadilha #3 documentado em todas as migrations recentes deste projeto
(2026_08_10/12). A tabela `contrato_assinatura_eventos` (28 caracteres) tem margem apertada
para qualquer FK/índice autogerado — nomear tudo à mão desde o primeiro rascunho da migration.

### Pitfall E: Testar a rota de HMAC só com fixture gerada pelo próprio código
Ver seção "Testando webhook com assinatura HMAC" acima — falso verde documentado, exigência já
travada no ROADMAP de ter um segundo teste com fixture externa.

## Code Examples

### Núcleo de validação HMAC — placeholder até o gate A1 decidir a fórmula
```php
// pt-BR: NÃO commitar isto como fórmula final antes do gate A1 (D-08) confirmar
// contra webhook real do sandbox via túnel. As 4 candidatas estão documentadas
// em 129-RESEARCH.md — "Lacuna Prioritária" §2.
$rawBody = $request->getContent();
$secret  = (string) config('services.clicksign.webhook_secret');
$headerRecebido = (string) $request->header('Content-Hmac', '');

// Candidata #1 (leitura literal da doc — "soma do Body com o Secret"):
$calculado1 = 'sha256=' . hash('sha256', $rawBody . $secret);
// Candidata #2 (ordem invertida):
$calculado2 = 'sha256=' . hash('sha256', $secret . $rawBody);
// Candidata #3 (nomenclatura "HMAC" — hash_hmac já devolve hex com $binary=false):
$calculado3 = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
// Candidata #4 (parâmetros trocados, erro comum):
$calculado4 = 'sha256=' . hash_hmac('sha256', $secret, $rawBody);

// A rota-sonda (D-08) loga TODAS as 4 sem o secret e sem decidir sozinha:
Log::channel('ecf-webhooks')->info('[Clicksign Sonda HMAC] Varredura de formulas', [
    'recebido' => $headerRecebido,
    'bate_1'   => hash_equals($calculado1, $headerRecebido),
    'bate_2'   => hash_equals($calculado2, $headerRecebido),
    'bate_3'   => hash_equals($calculado3, $headerRecebido),
    'bate_4'   => hash_equals($calculado4, $headerRecebido),
]);
```

### Idempotência via constraint de banco (precedente 127-06)
```php
// Fonte: 127-06-SUMMARY.md — precedente copiado de NpsController.php:1835
try {
    $evento = ContratoAssinaturaEvento::create([
        'contrato_assinatura_id' => $contrato->id,
        'name'                   => $nomeEvento,
        'payload'                => $payloadDecodificado,
        'payload_hash'           => hash('sha256', $rawBody),
    ]);
} catch (\Illuminate\Database\QueryException $e) {
    if ((string) $e->getCode() === '23000') {
        return response()->json(['ok' => true]); // já processado — idempotência OK
    }
    throw $e;
}
```

### Download streaming com link fresco (D-12)
```php
// pt-BR: SEMPRE reconsultar antes de baixar — o link do payload/evento pode
// ter mais de 5 minutos (D-12, link expira em X-Amz-Expires=300).
$documento = $client->consultarEnvelope($contrato->clicksign_envelope_id);
$linkFresco = $documento['links']['files']['signed'] ?? null;

if ($linkFresco === null) {
    throw new ClicksignException('Link de download não disponível para este envelope.');
}

Http::withOptions(['sink' => $destino])->timeout(30)->get($linkFresco);
```

## State of the Art

| Abordagem antiga (assumida na pesquisa de 2026-08-07) | Abordagem atual (esta sessão) | Quando mudou | Impacto |
|---|---|---|---|
| "Confirmar em sandbox se existe evento distinguível para expiração/recusa" (gap aberto) | Nomes confirmados na doc (`deadline`, `refusal`) — MÉDIA confiança, disparo real ainda não observado | 2026-08-12 (esta sessão) | Reduz a incerteza de "sabemos que existe" — schema pode nomear as colunas/branches com confiança, só falta o disparo real |
| Suposição de que `status == closed` = sucesso total | Doc + campo medido (`deadline_partial_signature_action`) mostram que `closed` pode ser fechamento FORÇADO parcial | 2026-08-12 (esta sessão) | Muda o desenho do gate CLICK-05 — precisa checar signatários, não só status |
| Fórmula do HMAC "quase resolvida" por leitura cuidadosa da doc | Confirmado que a MESMA página gera leituras diferentes dependendo de como é perguntada — só medição resolve | 2026-08-12 (esta sessão) | Reforça (não muda) a decisão já travada D-08 — mas com evidência fresca de por que a leitura sozinha nunca teria bastado |

**Ainda sem mudança:** retry policy e ordem de entrega seguem NÃO documentadas — 3 fontes
checadas nesta sessão, todas negativas.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | O corpo do webhook real segue a forma `{"event":{"name","data","occurred_at"},"document":{...}}` (não JSON:API) | Lacuna Prioritária §1, "Nota de confiabilidade" | Se a forma real for outra (ex.: JSON:API como o recurso de eventos), o parsing do controller pode falhar em ler `name`/`data` do lugar errado. Mitigação já recomendada: gravar `payload` como JSON genérico, não fazer parsing rígido antes de medir |
| A2 | O evento `deadline` fecha o envelope com assinatura parcial (`deadline_partial_signature_action: closed`) | Lacuna Prioritária §3 | Se o comportamento real for diferente (ex.: envelope fica preso em `running` mesmo após deadline), o gate CLICK-05 fica mais simples do que o previsto (mas a checagem extra de signatários não faz mal nesse caso — é estritamente mais segura) |
| A3 | `refusal` não fecha automaticamente o envelope (outros signatários continuam podendo assinar) | Lacuna Prioritária §4 | Não confirmado nem negado pela doc — se recusa fechar o envelope, o fluxo de "aguardar outros signatários após uma recusa" nunca vai acontecer, simplificando (não quebrando) o design |
| A4 | Registro de webhook na Clicksign é por CONTA (não por envelope) — `/webhooks` CRUD é global | "Padrão Laravel" (nota lateral, endpoints listados) | Se for por envelope, o cutover de produção (Fase 132) precisa de lógica de registro por envelope criado, não uma URL fixa configurada uma vez no painel — mudaria o desenho de 132, não desta fase |

**Nenhuma das claims acima é bloqueante para a Fase 129** — todas são refinamento do desenho,
não pré-condição. A única claim genuinamente bloqueante (fórmula do HMAC) já está fora desta
tabela porque o CONTEXT trava que ela NUNCA deve ser assumida — só medida (gate A1).

## Open Questions

> **Estado após o planejamento (2026-08-13):** nenhum destes três itens ficou como lacuna de
> plano — os três são endereçados pelo desenho da fase. Ver a resolução anotada em cada item.

1. **(ENDEREÇADO — gate A1, plano 129-02)** **Confirmação empírica de A1/A2/A3 acima** — só resolve com o gate A1 (webhook real via
   túnel, D-07/D-08) e com os gates #6/#7/#11 do ROADMAP (deixar um envelope vencer / recusar
   assinatura / observar reentrega real). Nenhum destes é fazível só com pesquisa documental.
2. **(ENDEREÇADO — observação oportunista no gate A1, plano 129-02)** **`/webhooks` é por conta
   ou por envelope?** Não confirmado nesta sessão (a doc de
   `reference/webhooks` não especificou o escopo). Recomendação: perguntar ao suporte
   (`ajuda@clicksign.com`) ou inferir pelo painel humano da Clicksign durante o gate A1 (o
   usuário já vai estar na tela cadastrando a URL do túnel — é a oportunidade barata de
   observar isso).
3. **(CONTORNADO POR DESENHO — plano 129-05 Task 2 decide por `$evento->name`, não pelo status)**
   **`document.status` pode ter um valor terminal específico para "cancelado por expiração
   sem nenhuma assinatura"** (a doc diz "cancelado" — é o mesmo `canceled` de cancelamento
   manual, ou existe uma distinção)? Não confirmado. Se for o mesmo `canceled`, a D-04/D-06 da
   Fase 125 (que já trata `expirado` como estado PRÓPRIO no NOSSO banco, distinto de
   `cancelado`) precisa decidir isso a partir de QUAL evento chegou (`deadline` vs `cancel`),
   não do status final do envelope — reforça a importância de gravar o `name` do evento, não só
   reconsultar o status.

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config `phpunit.xml` |
| Config file | `phpunit.xml` — testsuites `Unit` (`tests/Unit`) e `Feature` (`tests/Feature`) |
| Comando rápido | `php artisan test --filter=Clicksign` (ou `vendor/bin/phpunit --filter=Clicksign`) |
| Suíte completa | `php artisan test` (roda `tests/Unit` + `tests/Feature`, driver SQLite in-memory) |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| CLICK-03 | Webhook com `Content-Hmac` inválido é recusado (401) e gravado como inválido | feature | `php artisan test --filter=ClicksignWebhookAssinaturaTest` | ❌ Wave 0 |
| CLICK-03 | Fixture de HMAC calculada FORA do código de produção bate com o cálculo do controller | feature | `php artisan test --filter=ClicksignWebhookHmacFixtureExternaTest` | ❌ Wave 0 (só pode ser preenchido com valor real DEPOIS do gate A1) |
| CLICK-04 | Dois POSTs com o mesmo `payload_hash` não criam dois eventos | feature | `php artisan test --filter=ClicksignWebhookIdempotenciaTest` | ❌ Wave 0 |
| CLICK-04 | Dois eventos DIFERENTES do mesmo envelope não liberam a empresa duas vezes | feature | `php artisan test --filter=ClicksignLiberacaoIdempotenteTest` | ❌ Wave 0 |
| CLICK-05 | `status == closed` com signatário obrigatório `pendente` NÃO libera | unit/feature | `php artisan test --filter=GateLiberacaoOperacionalTest` | ❌ Wave 0 |
| CLICK-05 | `status == closed` com todos os signatários `assinou` LIBERA via `rotearServico` | feature | `php artisan test --filter=GateLiberacaoOperacionalTest` | ❌ Wave 0 |
| CLICK-06 | Webhook válido responde rápido e o processamento pesado vai para a fila (`Queue::assertPushed`) | feature | `php artisan test --filter=ClicksignWebhookDespachaFilaTest` | ❌ Wave 0 |
| CLICK-11 | Download do PDF assinado grava em `storage/app` (privado) via streaming | feature | `php artisan test --filter=DownloadPdfAssinadoTest` | ❌ Wave 0 |
| CLICK-11 | Falha de download não bloqueia liberação (D-14) | feature | `php artisan test --filter=DownloadPdfFalhaNaoBloqueiaTest` | ❌ Wave 0 |
| DADOS-03 | Evento recebido é gravado bruto mesmo quando assinatura é inválida | feature | `php artisan test --filter=ClicksignWebhookAssinaturaTest` | ❌ Wave 0 (compartilha arquivo com CLICK-03) |

### Sampling Rate

- **Por commit de tarefa:** `php artisan test --filter=Clicksign` (roda só os testes desta
  fase — ciclo curto, precedente de todas as fases 125-128).
- **Por merge de wave:** `php artisan test --filter="Phase125|Phase126|Phase127|Phase128|Phase129"`
  (suíte cumulativa da cadeia Clicksign, mesmo padrão usado em `128-05` / `127-06`).
- **Gate de fase:** suíte completa (`php artisan test`) verde antes de `/gsd:verify-work`.

### Wave 0 Gaps

- [ ] `tests/Feature/ClicksignWebhookAssinaturaTest.php` — cobre CLICK-03 + DADOS-03 (assinatura
  válida/inválida, gravação bruta em qualquer caso)
- [ ] `tests/Feature/ClicksignWebhookHmacFixtureExternaTest.php` — fixture EXTERNA (não gerada
  pelo código), inicialmente com um placeholder marcado `@group gate-a1-pendente` até o gate A1
  fechar a fórmula real — ver Pitfall E
- [ ] `tests/Feature/ClicksignWebhookIdempotenciaTest.php` — cobre CLICK-04 (ingestão)
- [ ] `tests/Feature/ClicksignLiberacaoIdempotenteTest.php` — cobre CLICK-04 (efeito) + Pitfall
  A (dois eventos diferentes não liberam duas vezes)
- [ ] `tests/Unit/GateLiberacaoOperacionalTest.php` (ou Feature, a depender de onde a lógica
  viver) — cobre CLICK-05 + Pitfall A (envelope closed parcial não libera)
- [ ] `tests/Feature/DownloadPdfAssinadoTest.php` — cobre CLICK-11 (streaming + D-12 + D-13 +
  D-14), com `Http::fake()` simulando o sink
- [ ] `app/Models/ContratoAssinaturaEvento.php` + migration `contrato_assinatura_eventos` —
  schema ainda não existe (DADOS-03), precisa nascer antes de qualquer teste acima rodar
- [ ] `app/Http/Controllers/Api/ClicksignWebhookController.php` — não existe ainda
- [ ] `app/Jobs/ProcessarEventoClicksignJob.php` (nome sugerido) — não existe ainda
- [ ] Comando `clicksign:verificar-assinatura` (D-09) — molde `ClicksignSondarModelo.php`, não
  existe ainda; troca a rota-sonda temporária depois que o gate A1 fechar

## Security Domain

### Applicable ASVS Categories

| Categoria ASVS | Aplica | Controle padrão |
|---|---|---|
| V2 Authentication | Não (webhook server-to-server, sem sessão de usuário) | — |
| V3 Session Management | Não | — |
| V4 Access Control | Parcial | Rota pública por natureza (webhook), mas a rota de DOWNLOAD do PDF (D-13) exige autenticação — `middleware(['auth', 'role:admin'])`, mesmo padrão do resto do módulo Administrativo |
| V5 Input Validation | Sim | JSON decodificado validado antes de uso (`is_array()`, checagem de chaves esperadas); nunca confiar cegamente na forma documentada (ver "Nota de confiabilidade") |
| V6 Cryptography | Sim | `hash_equals()` (comparação timing-safe, nativa do PHP) — nunca `===`/`==` na comparação de HMAC; secret nunca logado, nunca commitado (`.env` gitignored) |

### Known Threat Patterns for este stack

| Padrão | STRIDE | Mitigação padrão |
|---|---|---|
| Forjar webhook sem secret válido para liberar empresa sem contrato de verdade | Spoofing | Validação HMAC bloqueante (CLICK-03) — já é a linha de defesa central desta fase |
| Timing attack na comparação da assinatura | Information Disclosure (leva a Spoofing) | `hash_equals()`, nunca `===` |
| Replay de uma requisição de webhook capturada (sem timestamp nativo para detectar) | Spoofing / Repudiation | Sem defesa criptográfica direta (a Clicksign não fornece timestamp assinado) — mitigado por idempotência: reprocessar um evento já visto não causa efeito colateral novo (CLICK-04) |
| Vazar PII do signatário (nome, e-mail, IP, CPF) em log de erro | Information Disclosure | Já é convenção do projeto (`ClicksignClient::enviar()` nunca loga corpo de resposta/payload inteiro — só campos nomeados). Aplicar a mesma disciplina no log da rota de webhook |
| Link de download do PDF assinado acessível sem autenticação (URL adivinhável) | Information Disclosure / Elevation of Privilege | D-13 já trava: disco privado + rota autenticada, nunca `storage/app/public` |
| DoS via POST de payload gigante no endpoint de webhook | Denial of Service | `throttle:60,1` (mesmo padrão de `routes/web.php:82` para o HubSpot); truncar raw body ao gravar evento inválido (`RAW_BODY_MAX_BYTES`) evita banco inchar com payload malicioso |

## Sources

### Primary (confiança ALTA)
- `app/Http/Controllers/Api/HubspotWebhookController.php` — precedente vivo de validação HMAC,
  raw body, gravação de evento inválido, replay window
- `app/Services/Clicksign/ClicksignClient.php` — client HTTP já existente, headers medidos,
  disciplina de log seguro
- `database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php` e
  `..._100001_create_contrato_assinatura_signatarios_table.php` — schema já existente que esta
  fase consome
- `database/migrations/2026_06_12_300002_create_hubspot_eventos_table.php` — precedente de
  schema de evento bruto de provedor
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — medições reais contra o sandbox (tem
  precedência sobre qualquer doc)
- `tests/Feature/Phase34HubspotWebhookTest.php` — precedente de teste de fixture HMAC
- `bootstrap/app.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php` — infraestrutura
  de rota/CSRF/rate limit já configurada

### Secondary (confiança MÉDIA)
- `https://developers.clicksign.com/v3.0/docs/eventos` — WebFetch direto, 2026-08-12: lista de
  24 nomes de evento
- `https://developers.clicksign.com/v3.0/docs/evento-deadline` — WebFetch direto, 2026-08-12:
  payload + comportamento de fechamento parcial
- `https://developers.clicksign.com/v3.0/docs/evento-refusal` — WebFetch direto, 2026-08-12:
  payload do evento de recusa
- `https://developers.clicksign.com/v3.0/docs/evento-close.md` — WebFetch direto, 2026-08-12
- `https://developers.clicksign.com/v3.0/docs/evento-document-closed.md` — WebFetch direto,
  2026-08-12
- `https://developers.clicksign.com/v3.0/reference/eventos-do-envelope.md` — WebFetch direto,
  2026-08-12: forma JSON:API do recurso de eventos, convergente com a medição empírica
- `https://developers.clicksign.com/docs/seguranca-de-webhooks` — WebFetch com pedido de
  transcrição literal, 2026-08-12: texto exato da fórmula de HMAC (ambíguo, ver seção dedicada)
- `https://developers.clicksign.com/v3.0/reference/webhooks.md` — WebFetch direto, 2026-08-12:
  endpoints CRUD de `/webhooks`
- Laravel News / blogs técnicos (yellowduck.be, harrisrafto.eu) — WebSearch, 2026-08-12:
  `Http::withOptions(['sink' => ...])` para streaming de download

### Tertiary (confiança BAIXA — marcado para validação)
- `https://developers.clicksign.com/v3.0/docs/exemplo-documento` — WebFetch, 2026-08-12: exemplo
  do objeto `document`, mas com forma que PARECE desatualizada/inconsistente com a v3 medida
  (ver "Nota de confiabilidade") — não usar para desenhar schema sem medição
- `https://developers.clicksign.com/v3.0/docs/introducao-a-webhooks.md`,
  `.../melhores-praticas-webhooks.md` — WebFetch, 2026-08-12: confirmação NEGATIVA (ausência de
  info sobre retry/ordem), útil como evidência de que a lacuna é do provedor, não da pesquisa

## Metadata

**Confidence breakdown:**
- Padrões Laravel (rota, idempotência, streaming, testes): ALTA — precedente vivo no código
- Vocabulário de eventos (nomes, existência de `refusal`/`deadline`): MÉDIA — doc oficial,
  nunca disparado de verdade por este projeto
- Fórmula do HMAC: SEM RESOLUÇÃO POR PESQUISA (por desenho — gate A1 é medição, D-08)
- Retry/ordem de entrega: CONFIRMADO NEGATIVO (doc não documenta, 3 fontes checadas)
- Forma exata do corpo do webhook (`document` embutido): BAIXA — suspeita fundada de
  inconsistência entre páginas da doc

**Research date:** 2026-08-12
**Valid until:** Até o gate A1 fechar (evento de medição real via túnel) — depois disso, esta
pesquisa deve ser tratada como HISTÓRICA para a fórmula do HMAC (a fórmula real substitui as
candidatas). Para o vocabulário de eventos e forma de payload, revalidar se a Clicksign anunciar
mudança de versão da API (sem prazo previsto, domínio estável).
