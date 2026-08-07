# Stack Research — Integração Clicksign (API v3 / Envelope)

**Domínio:** Assinatura eletrônica de contratos (Clicksign API v3) integrada a um módulo Administrativo Laravel 12 + Inertia + React já em produção
**Pesquisado:** 2026-08-07
**Confiança geral:** MÉDIA — a documentação oficial da Clicksign (`developers.clicksign.com`) é a fonte primária, mas várias páginas de referência (endpoints específicos) retornaram 404 ou conteúdo incompleto via fetch automatizado; itens sensíveis (HMAC, autenticação) foram triangulados em 3+ fetches independentes da mesma página oficial e convergiram, mas **DEVEM ser validados empiricamente em sandbox antes de codificar em produção** — este projeto já foi mordido por assumir formato de API sem verificar (ver `project_hubspot_associations_id_nao_toobjectid.md`).

Este documento cobre **apenas o que é novo** para a integração Clicksign. Laravel 12, Inertia, React, Tailwind, DomPDF, `spatie/laravel-activitylog`, queue `database` e PHPUnit já estão instalados e não são re-pesquisados aqui.

---

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| Laravel HTTP Client (`Http::` facade, já no core do Laravel 12) | — (nenhuma dependência nova) | Client HTTP para a Clicksign API v3 | Não existe SDK PHP oficial maduro para a v3 (ver seção "O que NÃO usar"). O projeto já usa exatamente este padrão em `HubspotApiClient` — `Http::withHeaders/withToken`, `$res->throw()`, resiliência com `!$res->ok()` + `Log::warning`. Seguir o mesmo padrão mantém consistência e zero dependência nova. |
| PHP nativo `hash()` + `hash_equals()` | PHP 8.2+ (já instalado) | Validação da assinatura do webhook Clicksign | Ver seção 3 (Webhook) — a Clicksign usa SHA256 do body concatenado com o secret, não HMAC clássico com padding. `hash('sha256', $body . $secret)` + `hash_equals()` nativo do PHP cobrem 100% do requisito sem biblioteca extra. |
| `base64_encode()` nativo do PHP | PHP 8.2+ (já instalado) | Empacotar o PDF gerado pelo DomPDF para envio à Clicksign | A API v3 recebe o documento como base64 dentro do JSON (`content_base64`), não como upload multipart. `$pdf->output()` (string binária do DomPDF) já é exatamente o que `base64_encode()` espera — zero conversão extra, zero dependência nova. |

### Supporting Libraries

Nenhuma. Este é o ponto central da pesquisa: **a integração Clicksign v3 não exige nenhum pacote Composer novo.** Tudo é coberto por Laravel HTTP Client + funções nativas do PHP.

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| *(nenhuma)* | — | — | — |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| Conta sandbox Clicksign (`sandbox.clicksign.com`) | Ambiente de teste isolado, com rate limit próprio (20 req/10s) | Gerar em Configurações → API → "Generate Access Token" na conta sandbox. Token de sandbox é distinto do de produção — **nunca reaproveitar o mesmo token entre ambientes**. |
| Postman/Insomnia collections oficiais (`github.com/clicksign/docs-clicksign-api`) | Exemplos de request prontos (criar envelope, documento, signatário) | Repositório oficial da Clicksign mantém coleções Postman/Insomnia atualizadas para a v3 — útil para validar o payload exato antes de codificar o `ClicksignClient`, já que várias páginas de referência individuais retornaram 404 no fetch. |

## Installation

```bash
# Nenhum pacote PHP novo — Http:: já é parte de illuminate/http (Laravel 12 core)
# Nenhum pacote JS novo — a UI de contratos (Fase 11 do plano) usa Inertia/React já instalados
```

```env
# .env.example — adicionar (já especificado no plano canônico, confirmado contra a doc oficial)
CLICKSIGN_ENV=sandbox
CLICKSIGN_BASE_URL=https://sandbox.clicksign.com/api/v3
CLICKSIGN_ACCESS_TOKEN=
CLICKSIGN_WEBHOOK_SECRET=
```

---

## Respostas às perguntas específicas

### 1. SDK/pacote PHP oficial ou comunitário para a API v3

**Não existe pacote maduro para a v3 (Envelope). Usar Http client puro.**

| Pacote | Última versão/release | Cobre v3 (Envelope)? | Veredito |
|--------|------------------------|------------------------|----------|
| `clicksign/clicksign-php` (oficial) | v1.0.0, publicado 2015-09-14, último update 2020-01-28 | **NÃO.** README do próprio repositório oficial marca a lib como "**DESCONTINUADA: Biblioteca PHP da Clicksign versão Classic**" | Não usar. Abandonado há 6 anos, cobre só a API Classic (v1/v2) pré-Envelope. |
| `mateusgalasso/clicksign` (comunitário, Laravel) | 1.0, publicado 2023-07-24 (última atualização do metadata do pacote em 2026-07-24, sem indicar novo release de código), ~377 installs | **NÃO.** Código-fonte usa endpoints `/api/v1/documents` e `/api/v1/lists` contra `app.clicksign.com` / `sandbox.clicksign.com` — API legada, não o conceito de Envelope da v3 | Não usar. Baixa adoção (377 installs), API errada para este projeto, e não tem sinal de manutenção ativa que acompanhe a v3. |

**Conclusão:** seguir o padrão já estabelecido em `HubspotApiClient.php` — um client HTTP fino (`app/Services/Clicksign/ClicksignClient.php`, como já desenhado no plano canônico) sobre `Http::` do Laravel. É a abordagem correta e não uma solução de contorno: a própria Clicksign, na documentação de "Primeiros Passos" da v3, direciona a integração via chamadas REST diretas (`application/vnd.api+json`), não via SDK.

### 2. Autenticação da API v3

**Header:** `Authorization` — confirmado na doc oficial (`developers.clicksign.com/docs/primeiros-passos`).

**Formato do valor — ATENÇÃO, ambiguidade real entre fontes da própria Clicksign:**
- O exemplo literal da doc de "Primeiros Passos" mostra o token **sem prefixo `Bearer`**: `Authorization: {{access_token}}`.
- Outra passagem da mesma documentação também menciona o formato `Authorization: Bearer SEU_TOKEN`.

Isso é relevante porque o helper `Http::withToken($token)` do Laravel **sempre** adiciona o prefixo `Bearer ` automaticamente — se a Clicksign exigir o token puro (sem `Bearer`), usar `Http::withToken()` direto vai quebrar a autenticação silenciosamente (retorna 401). **Recomendação:** não usar `Http::withToken()`; usar `Http::withHeaders(['Authorization' => $token, ...])` e testar contra o sandbox no primeiro request antes de assumir qualquer formato — exatamente o tipo de suposição que já causou bug em produção neste projeto (ver `HubspotApiClient`, bug do `toObjectId`).

**Headers obrigatórios em toda requisição:**
```
Authorization: <access_token>
Accept: application/vnd.api+json
Content-Type: application/vnd.api+json
```
A API v3 segue a especificação JSON:API (`application/vnd.api+json`), não JSON solto — os payloads têm envelope `{ "data": { "type": "...", "attributes": {...} } }`.

**Diferença sandbox vs. produção:** o **mecanismo** de autenticação é idêntico (mesmo header, mesmo formato) nos dois ambientes — não há OAuth, PKCE ou fluxo diferente. A diferença é puramente o **token em si** (gerado separadamente em cada conta/ambiente) e a **URL base**:
- Sandbox: `https://sandbox.clicksign.com/api/v3` (confirmado, literal na doc oficial)
- Produção: `https://app.clicksign.com/api/v3` (**confiança MÉDIA** — convergência de WebSearch, não localizei uma citação literal da doc oficial que afirme isso explicitamente; confirmar contra o próprio dashboard de produção da Clicksign ou suporte antes do go-live)

### 3. Validação de webhook

**Header da assinatura:** `Content-Hmac` — confirmado na doc oficial (`developers.clicksign.com/docs/seguranca-de-webhooks`), formato `Content-Hmac: sha256=<hash>`.

**Algoritmo — ponto crítico, não é HMAC clássico:**
A documentação oficial descreve, em português, textualmente (convergente em 3 fetches independentes da mesma página): *"a Clicksign calcula o Hash SHA256 da soma do Body da requisição com o Secret"*, com o aviso explícito *"não formate o JSON antes do cálculo"* (ou seja: usar o raw body, byte a byte, sem re-serializar).

Isso descreve **SHA256(body + secret)** — concatenação de string seguida de hash simples — e **não** o HMAC criptográfico clássico (que usa padding interno/externo com a chave, via `hash_hmac()`). A nomenclatura da própria Clicksign ("HMAC SHA256 Secret") é enganosa nesse ponto: o nome sugere HMAC, mas a fórmula descrita é concatenação + SHA256 simples.

**Isso é uma armadilha real para este código-base**, porque o reflexo natural em PHP seria escrever `hash_hmac('sha256', $body, $secret)` — o padrão usado em toda integração de webhook (Stripe, GitHub, HubSpot). Se a Clicksign de fato faz `hash('sha256', $body . $secret)`, o código com `hash_hmac()` vai falhar 100% das validações, silenciosamente, até alguém investigar por que nenhum webhook nunca bate a assinatura.

**Recomendação concreta:**
1. Implementar como `hash('sha256', $rawBody . $secret)` primeiro (fiel à descrição literal da doc).
2. Validar contra um webhook real disparado do sandbox antes de confiar — comparar o `Content-Hmac` recebido com o cálculo local, logando os dois valores (nunca o secret) em ambiente de dev até bater.
3. Se não bater, testar `hash_hmac('sha256', $rawBody, $secret)` como segundo candidato.
Este passo de validação empírica é obrigatório antes de ligar o gate de liberação operacional — a Fase 9 do plano (webhook) não deve ir a produção sem esse teste feito contra o sandbox real.

**O que é assinado:** o **raw body** da requisição (não os campos individuais, não um subconjunto). Consequência direta para o controller: `ClicksignWebhookController::receive()` **precisa ler o body cru** (`$request->getContent()`) **antes** de qualquer parsing/normalização Laravel, exatamente como o plano canônico já previu ("Ler raw body" — Fase 9, item 1). Não usar `$request->input()`/`$request->all()` para calcular a assinatura — o array normalizado do Laravel não é garantidamente byte-idêntico ao body recebido.

**Rota de webhook precisa ficar fora do CSRF** (já é o padrão do projeto para rotas públicas de webhook, ver `/implementacao/*` no CLAUDE.md) — mas a proteção real aqui é a validação de `Content-Hmac`, não o CSRF (que não se aplica a chamadas server-to-server de qualquer forma).

**IPs fixos para allowlist de firewall (bônus, não pedido mas relevante para hardening):**
- Produção: `34.204.113.69`
- Sandbox: `3.232.199.65`

### 4. Biblioteca extra para validação — não é necessária

`hash()` e `hash_equals()` são funções nativas do PHP (core, sem extensão), disponíveis desde muito antes do PHP 8.2. `hash_equals()` já faz comparação em tempo constante (proteção contra timing attack) — não há necessidade de nenhuma dependência adicional (`paragonie/*`, etc. — o projeto já tem `sodium_compat` para outro propósito, não é relevante aqui).

```php
// Núcleo da validação — sem dependência nova
$calculado = 'sha256=' . hash('sha256', $rawBody . $secret);
if (!hash_equals($calculado, $headerRecebido)) {
    abort(401, 'Assinatura Clicksign inválida');
}
```

### 5. Rate limits da API v3

Confirmado na doc oficial (`developers.clicksign.com/docs/limite-de-requisicoes`):

| Ambiente | Limite |
|----------|--------|
| Produção | 50 requisições por conta / 10 segundos (~300/min) |
| Sandbox | 20 requisições por conta / 10 segundos (~120/min) |

**Headers de resposta:** `X-Rate-Limit`, `X-Rate-Limit-Remaining`, `X-Rate-Limit-Reset` (Unix time UTC de quando o limite reseta).

**Ao exceder:** HTTP `429 Too Many Requests`.

**Implicação de design:** o volume da ECF (dezenas de empresas/mês) está muito abaixo do limite mesmo em sandbox — não há necessidade de fila de rate-limiting dedicada. Mas o `ClicksignClient` deve tratar 429 explicitamente (retry com backoff ou, no mínimo, log + status `erro` na `contrato_assinatura` em vez de estourar exceção não tratada), porque a criação de um envelope completo é uma sequência de N chamadas (criar envelope → adicionar documento → N signatários → N requisitos → notificação) e picos de reprocessamento/replay de webhook podem acumular chamadas.

Downloads de documento via S3 não contam para o limite (irrelevante para o fluxo de criação, relevante se a Fase 11 da UI expuser `clicksign_download_url` diretamente).

### 6. Upload de documento: base64 no JSON, não multipart

Confirmado na doc oficial (`developers.clicksign.com/reference/api-upload-documentos`, e reforçado na doc de "Documentos"):

```
POST /envelopes/{envelopeId}/documents
Content-Type: application/vnd.api+json
```

```json
{
  "data": {
    "type": "documents",
    "attributes": {
      "filename": "contrato-empresa-123.pdf",
      "content_base64": "data:application/pdf;base64,<conteúdo base64 do PDF>",
      "metadata": { "company_id": "123" }
    }
  }
}
```

Nota sobre o formato exato do valor de `content_base64`: a doc mostra em um trecho o padrão de data URI completo (`data:<mimetype>;base64,<data>`) e em outro trecho refere-se a ele apenas como "Base64 do arquivo" — **não confirmado com 100% de certeza se o prefixo `data:application/pdf;base64,` é obrigatório ou se apenas a string base64 pura basta.** Recomenda-se testar ambos contra o sandbox; incluir o prefixo é a opção mais segura porque é o formato mais citado na doc e é retrocompatível (a maioria das implementações JSON:API de upload aceita o data URI completo).

**Implicação direta para o DomPDF já instalado:**
```php
// Nenhuma dependência nova — DomPDF já gera string binária via ->output()
$pdfBinario = Pdf::loadView('pdf.contrato-prestacao-servico', $dados)->output();
$base64 = 'data:application/pdf;base64,' . base64_encode($pdfBinario);
```
Não é necessário salvar o PDF em disco/storage antes de enviar (embora possa valer a pena persistir uma cópia local para auditoria/reenvio — decisão de arquitetura, não de stack). Não há upload multipart, então não há necessidade de `Illuminate\Http\UploadedFile` fake nem de manipular streams — é só string → base64 → chave JSON.

**Limite de tamanho de arquivo:** **não confirmado na documentação pública** consultada. Recomenda-se assumir um limite conservador (contratos da ECF são tipicamente poucas páginas, o PDF gerado por DomPDF deve ficar bem abaixo de qualquer limite razoável) e, se necessário, confirmar com o suporte Clicksign (`ajuda@clicksign.com`) antes de ir a produção.

---

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Http client puro (`Http::` facade) seguindo padrão `HubspotApiClient` | `mateusgalasso/clicksign` (pacote Laravel) | Nunca, para este projeto — o pacote fala com a API v1/legada (`/api/v1/documents`), não com o conceito de Envelope da v3 exigido pelo plano canônico. Só faria sentido se o projeto decidisse voltar para a API Classic, o que contradiz o requisito. |
| `hash('sha256', $body . $secret)` para validar `Content-Hmac` | `hash_hmac('sha256', $body, $secret)` | Se a validação empírica contra o sandbox mostrar que o cálculo literal descrito na doc (concatenação simples) não bate, testar o HMAC clássico como segundo candidato — ver seção 3. |
| Base64 embutido no JSON para envio do PDF | Multipart/form-data | Não é opção — a API v3 da Clicksign para criação de documento via upload não expõe endpoint multipart na documentação consultada; o único formato documentado é `content_base64` dentro do JSON:API. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|--------------|
| `clicksign/clicksign-php` (Composer) | Oficialmente descontinuado desde 2020, cobre só a API Classic pré-Envelope | `Http::` facade, client próprio (`ClicksignClient.php`) |
| `mateusgalasso/clicksign` (Composer) | Fala com endpoints `/api/v1/*` (legado), não com o modelo de Envelope da v3 exigido pela integração | `Http::` facade, client próprio |
| `Http::withToken($token)` do Laravel sem verificação | Adiciona `Bearer ` automaticamente ao header `Authorization`; a doc oficial da Clicksign mostra o exemplo canônico **sem** esse prefixo — risco de 401 silencioso | `Http::withHeaders(['Authorization' => $token, ...])`, validado contra sandbox antes de assumir o formato |
| `hash_hmac()` sem antes validar contra webhook real do sandbox | A doc oficial descreve concatenação simples (body + secret) + SHA256, não HMAC com padding — usar `hash_hmac()` direto pode nunca bater a assinatura, falhando 100% dos webhooks silenciosamente | `hash('sha256', $rawBody . $secret)` como primeira tentativa, com teste empírico obrigatório |
| Qualquer forma de enviar o PDF via multipart/form-data | Não é o formato documentado pela API v3 para criação de documento | JSON com `content_base64` |
| Polling do status do envelope como fluxo principal | A própria doc da Clicksign desaconselha (mudanças a rate-limit de polling "não são autorizadas"; usar Webhooks) — e o plano canônico já define isso como requisito ("Não usar polling como fluxo principal") | Webhook `Content-Hmac` validado, idempotente por `payload_hash` |

## Stack Patterns by Variant

**Se o token exigir prefixo `Bearer` (a confirmar em sandbox):**
- Trocar `Http::withHeaders(['Authorization' => $token])` por `Http::withHeaders(['Authorization' => "Bearer {$token}"])`
- Porque a doc oficial contém as duas variantes em páginas diferentes — a única forma de saber com certeza é testar contra o token real do sandbox.

**Se o cálculo de HMAC descrito (`sha256(body + secret)`) não bater com o header `Content-Hmac` recebido de um webhook real:**
- Trocar para `hash_hmac('sha256', $rawBody, $secret)`
- Porque a nomenclatura "HMAC SHA256 Secret" usada pela Clicksign é ambígua o suficiente para admitir as duas leituras; a validação empírica decide, não a leitura da doc isoladamente.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|------------------|-------|
| Laravel HTTP Client (Laravel 12) | Clicksign API v3 (JSON:API, `application/vnd.api+json`) | Nenhuma incompatibilidade conhecida — Laravel HTTP Client (Guzzle por baixo) lida bem com content-type customizado via `withHeaders()`; não usar `->asJson()` sem sobrescrever o `Content-Type`, pois esse helper força `application/json` puro, não `application/vnd.api+json`. |
| `barryvdh/laravel-dompdf` (já instalado) | `content_base64` da Clicksign | `$pdf->output()` retorna string binária pronta para `base64_encode()` — nenhum passo intermediário de conversão necessário. |

---

## Sources

- `https://developers.clicksign.com/docs/primeiros-passos` — WebFetch oficial: formato do header `Authorization`, headers obrigatórios (`Accept`/`Content-Type: application/vnd.api+json`), URL base sandbox — confiança HIGH (fetch direto, texto literal citado)
- `https://developers.clicksign.com/docs/seguranca-de-webhooks` — WebFetch oficial (3 fetches independentes, convergentes): header `Content-Hmac`, fórmula "Hash SHA256 da soma do Body com o Secret", raw body, IPs fixos de allowlist — confiança MÉDIA-ALTA (texto oficial, mas nenhuma citação literal do PHP de exemplo foi extraída — a página só linka ferramentas externas)
- `https://developers.clicksign.com/docs/limite-de-requisicoes` — WebFetch oficial: 50 req/10s produção, 20 req/10s sandbox, headers `X-Rate-Limit*`, 429 — confiança HIGH
- `https://developers.clicksign.com/reference/api-upload-documentos` — WebFetch oficial: endpoint `POST /envelopes/{id}/documents`, payload `content_base64` — confiança MÉDIA-ALTA (página específica de endpoint teve fetch parcial; formato do prefixo `data:...` não 100% cravado)
- `https://developers.clicksign.com/reference/api-criar-envelope` — WebFetch oficial: `POST /envelopes`, JSON:API, exemplo de payload — confiança MÉDIA-ALTA
- `https://packagist.org/packages/clicksign/clicksign-php` — WebFetch: metadata do pacote, status descontinuado — confiança HIGH
- `https://packagist.org/packages/mateusgalasso/clicksign` + `https://github.com/mateusgalasso/clicksign` — WebFetch: versão, instalações, endpoints legados no código-fonte — confiança MÉDIA (README, não código-fonte linha a linha)
- WebSearch (múltiplas queries) para triangular URL base de produção (`app.clicksign.com/api/v3`) — confiança MÉDIA, **não encontrada citação literal em página oficial fetchada diretamente**
- `app/Services/HubspotApiClient.php` (código do projeto) — padrão de client HTTP a replicar para `ClicksignClient`
- `plano-administrativo-clicksign.md` (raiz do repo) — plano canônico da milestone v22.0, referências de documentação já listadas pelo usuário

---
*Stack research for: Integração Clicksign API v3 (Envelope) — Módulo Administrativo v22.0*
*Researched: 2026-08-07*
