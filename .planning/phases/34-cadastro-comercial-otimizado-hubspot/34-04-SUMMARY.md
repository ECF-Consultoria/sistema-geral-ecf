---
phase: 34-cadastro-comercial-otimizado-hubspot
plan: 04
subsystem: backend
tags: [webhook, hubspot, hmac, integration, controller, service, config, route, tests]
dependency_graph:
  requires:
    - hubspot_eventos (tabela — Plan 34-01)
    - HubspotEvento (model — Plan 34-01)
    - Company.fillable contendo nicho/dor/vende_ml/faturamento_mensal/empresa_nova (Plan 34-01)
  provides:
    - Endpoint POST /api/webhooks/hubspot (HMAC v3 + processamento sincrono)
    - HubspotApiClient (wrapper Http withToken para CRM v3)
    - config('services.hubspot.*') com mapeamento configuravel D-05
    - Cadastro automatico de Company quando deal=closedwon
    - Vinculacao automatica de ContratoServico quando Servico::nome bate
  affects:
    - config/services.php (bloco hubspot novo)
    - .env.example (12 vars HUBSPOT_* documentadas)
    - routes/web.php (rota nova fora de CSRF, throttle 60/min)
tech_stack:
  added:
    - HubSpot CRM API v3 (GET /crm/v3/objects/deals|companies)
    - HMAC v3 (base64(hmac_sha256(secret, METHOD+URI+body+ts))) com hash_equals
  patterns:
    - Receiver receber → validar HMAC → gravar HubspotEvento → processar → 200
    - Idempotencia via lookup em hubspot_eventos.object_id+company_id_criada
    - Logging estruturado canal ecf-webhooks (compartilhado com Phase 26)
    - Http::fake nos testes para impedir trafego real a api.hubapi.com
key_files:
  created:
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - app/Services/HubspotApiClient.php
    - tests/Feature/Phase34HubspotWebhookTest.php
  modified:
    - config/services.php
    - .env.example
    - routes/web.php
decisions:
  - D-03 HMAC v3 timing-safe + replay 5min
  - D-04 processamento sincrono inline + 200 sempre (HubSpot nao retenta)
  - D-04 idempotencia via HubspotEvento.object_id+company_id_criada
  - D-05 mapeamento configuravel deal/company props via config + env
metrics:
  duration_min: 28
  completed_date: 2026-06-12
  tasks_completed: 4
  files_created: 3
  files_modified: 3
  commits: 4
  tests_added: 6
  tests_total_green: 42
requirements:
  - REQ-34-10
  - REQ-34-11
  - REQ-34-12
---

# Phase 34 Plan 04: Webhook HubSpot — receiver + HMAC + processamento Summary

Receiver completo do webhook HubSpot em `/api/webhooks/hubspot`: HMAC v3 timing-safe com replay-window 5min, decodifica batch de eventos, filtra por `deal.propertyChange` + `dealstage=closedwon`, busca dados do deal e da company associada via API HubSpot CRM v3 com Bearer token, cria `Company` (empresa_nova=true, status=pendente) + opcionalmente `ContratoServico` se o nome do servico bater no catalogo. Eventos invalidos viram `HubspotEvento(signature_valid=false)` para auditoria; eventos com erro de processamento retornam 200 mas marcam `status=erro` (HubSpot nao retenta). Idempotencia D-04 garante que reentregas do mesmo deal nao duplicam empresa. Suite total da phase: 42 verdes, zero regressao.

## Tasks Executadas

| # | Task | Commit | Arquivos |
|---|------|--------|----------|
| 1 | config/services.php (bloco hubspot) + .env.example (12 vars) | `3634fd4` | config/services.php, .env.example |
| 2 | HubspotApiClient (wrapper Http withToken) | `4d0c56d` | app/Services/HubspotApiClient.php |
| 3 | HubspotWebhookController + rota POST com throttle 60/min | `1cc56cd` | app/Http/Controllers/Api/HubspotWebhookController.php, routes/web.php |
| 4 | Suite Phase34HubspotWebhookTest (6 cases) | `39e3177` | tests/Feature/Phase34HubspotWebhookTest.php |

## Decisoes Tomadas

### HMAC v3: `(int) (microtime(true) * 1000)` para timestamp now

HubSpot manda timestamp como string em ms (epoch). O controller compara com `(int) (microtime(true) * 1000)` — float-multiplication ANTES de int-cast para preservar precisao em ms. Janela de 5 min em ms (`5 * 60 * 1000`) declarada como constante `REPLAY_WINDOW_MS`.

### Truncamento defensivo do raw body em request invalida

Em `gravarInvalido()`, o raw body eh gravado em `payload->raw` truncado a 65KB via `mb_strcut` (multibyte-safe, nao corta no meio de char UTF-8). Decisao do plan: evitar estouro de disco se atacante mandar payload gigante para forcar 401-loops. O motivo (`timestamp invalido`, `signature invalida`, `json invalido`) e o IP do peer ficam no payload para investigacao.

### Tolerancia de objeto unico no payload

HubSpot legitimo SEMPRE manda array de eventos no body. Por defesa-em-profundidade, se o JSON decodificado tem chave `objectId` ou `subscriptionType` no root (objeto unico), o controller embrulha em array antes de iterar — evita falha silenciosa caso o cliente mande no formato errado. Demais formatos invalidos caem em `gravarInvalido` com motivo `json invalido`.

### `fetchAssociatedCompanyId` resiliente (retorna null em 4xx/5xx)

Diferente de `fetchDeal`/`fetchCompany` que chamam `$res->throw()`, o `fetchAssociatedCompanyId` retorna null em qualquer erro do endpoint de associations. Justificativa: deal sem company associado eh cenario VALIDO no HubSpot. Se o endpoint retornar 404 ou 500 transitorio, a empresa eh criada so com dados do deal (name=dealname, cnpj/email/phone=null) — admin completa depois. Forcar erro aqui geraria empresa em branco como "erro" no log + manual replay.

### `vende_ml` tri-state: null/true/false

HubSpot prop `vende_ml` pode vir como `'true'`, `'false'`, vazia, ou ausente. O controller usa `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` para mapear corretamente — vazio/ausente vira `null` (estado "Nao sei" do D-01), strings booleanas viram bool. Casamento com cast Eloquent `'vende_ml' => 'boolean'` no Company (cuidado: cast Eloquent transforma null em false em algumas versoes — o teste T4 verifica `assertTrue($company->vende_ml)` para a string `'true'`).

### `data_contratacao` no ContratoServico = hoje

Quando o webhook cria contrato, usa `now()->toDateString()` como `data_contratacao` (data do evento, nao um campo do deal HubSpot). Justificativa: deal HubSpot nao tem campo de "data de inicio do contrato" estavel — comercial fecha hoje, contrato comeca hoje. Admin pode ajustar manualmente depois via UI de contratos.

### Throttle `60,1` em vez do throttler nomeado `ecf-webhook`

Plan original sugeria `throttle:60,1`. Phase 26 usa `throttle:ecf-webhook` (600/min). Manti `60,1` (60 req/min por IP) conforme plan — HubSpot legitimo manda muito menos que isso e o limite eh mais agressivo (defesa contra spam). Se necessario, futuro plan pode trocar para um throttler nomeado em `AppServiceProvider`.

### Reutilizei o canal `ecf-webhooks` para logging

Em vez de criar canal novo `hubspot-webhooks`, o controller loga em `Log::channel('ecf-webhooks')`. Justificativa: canal ja existe (`config/logging.php` linha 135), rotativo diario, segregado de `laravel.log`, e webhook eh "tipo Phase 26" (receivers HMAC de parceiro externo). Centralizar facilita debugging. Eventos no log sao prefixados com `[HubSpot Webhook]` vs `[ECF Webhook]` para distincao visual.

## Verificacao

- [x] `php artisan test --filter=Phase34HubspotWebhookTest` → 6/6 verdes (49 assertions)
- [x] `php artisan test --filter="Phase31|Phase33|Phase34"` → 42/42 verdes (233 assertions, ZERO regressao)
- [x] `php artisan route:list | grep hubspot` mostra rota `POST api/webhooks/hubspot` com `webhooks.hubspot`
- [x] CSRF cobertura confirmada via bootstrap/app.php (`api/webhooks/*` em except) + `withoutMiddleware` defensivo na rota
- [x] HubspotApiClient com `Http::withToken` (Bearer access_token) — nao loga token
- [x] Controller NUNCA loga client_secret nem raw body completo (so motivo + tamanho)

## Desvios do Plano

### Auto-fixed (Rule 1/2/3)

**1. [Rule 3 - Blocker] Suite inicial falhou por `withHeaders()->call()` nao injetar defaultHeaders**

- **Encontrado durante:** Task 4 (primeira execucao da suite)
- **Issue:** Tests 3-6 retornaram 401 em vez de 200. Investigacao mostrou que o controller recebia `tsHdr=''` (vazio) e `allHdrs` so com headers default do Symfony — `X-HubSpot-*` nao chegavam.
- **Causa raiz:** Bug-prone da framework Laravel: `withHeaders([...])` armazena em `$defaultHeaders`, mas `MakesHttpRequests::call()` NAO aplica `transformHeadersToServerVars($this->defaultHeaders)` automaticamente — so quando chamado via `->json()`/`->post()`/`->get()`. Quando se usa `->call()` direto (necessario para passar raw body como 7o argumento), os defaultHeaders ficam orfaos.
- **Fix:** Adicionado helper `servidor(array $headers): array` que converte headers para o formato `$_SERVER` (HTTP_X_* prefix, dash→underscore, uppercase) e passei explicitamente como 6o argumento de `call()`. Aplicado em todos os 6 testes.
- **Commit:** `39e3177`

**2. [Rule 2 - Robustez] Idempotencia exclui o proprio evento na checagem**

- **Encontrado durante:** Task 3 (implementacao do receiver)
- **Issue:** A checagem `HubspotEvento::where('object_id', $id)->whereNotNull('company_id_criada')->exists()` iria sempre retornar true SE o controller gravasse o evento ANTES de processar (que eh exatamente o caso aqui — `create` antes de `processar`).
- **Fix:** Adicionado `->where('id', '!=', $evento->id)` na query de idempotencia para excluir o evento atual.
- **Justificativa:** Sem isso, o 1o evento ja entraria como "ignorado: deal ja processado" no proprio evento dele. T4 falharia.

### Architectural (Rule 4)

Nenhum.

## Conflito de Wave 2 (não-deviation)

Durante a execucao deste plan, as Plans 34-02 (wizard comercial) e 34-03 (admin UI) rodaram em paralelo no mesmo working tree. **O commit `1cc56cd` (Task 3 deste plan) incluiu acidentalmente `app/Http/Controllers/ComercialController.php`** — file que pertence ao Plan 34-02. O sequencia exata:

1. Plan 34-02 ja tinha modificado `ComercialController.php` no working tree (sem commit ainda).
2. Eu (34-04) rodei `git add app/Http/Controllers/Api/HubspotWebhookController.php routes/web.php` — staging restrito aos meus arquivos.
3. Mas o git encontrou que `routes/web.php` ja tinha modificacoes acumuladas e auto-incluiu o `ComercialController.php`? Nao — eh impossivel via `git add` com paths explicitos.
4. Mais provavelmente: outro agente rodou `git add -A` no mesmo intervalo entre meu `add` e `commit`.

Mitigation aplicada: **NAO revertir** — Plan 34-02 ja segue para o commit seguinte (`4961793`) e o seu codigo ja estava no commit `1cc56cd`. Removendo, eu quebraria a Plan 34-02. Em commits subsequentes (Task 4), apliquei staging granular `git add tests/Feature/Phase34HubspotWebhookTest.php` (so meu arquivo) — sem regressao.

**Lesson learned para futuros waves paralelas:** sempre passar caminhos relativos COMPLETOS para `git add` E inspecionar `git status` ANTES do commit. Ou — melhor — orquestrar wave 2 por worktrees git separados (uma por plan).

## Pontos Interessantes da HubSpot v3

### Spec do HMAC v3

Confirmacao do CONTEXT D-03:

```
Signature input string = HTTP method + URI + raw body + timestamp
HMAC = base64(hmac_sha256(client_secret, signature_input_string, raw=true))
Header: X-HubSpot-Signature-v3: <base64>
Header: X-HubSpot-Request-Timestamp: <epoch_ms>
Janela de replay: 5 minutos
```

Fontes oficiais (treinamento — nao acessei docs online nesta sessao):
- https://developers.hubspot.com/docs/api/webhooks/validating-requests
- v3 substituiu v2 e v1 (deprecated, ainda funcionais)
- `URI` no signature input inclui scheme+host+path+query (= `$request->fullUrl()` no Laravel)

### Format de evento no payload

HubSpot manda array, sempre. Estrutura de cada evento:

```json
{
  "portalId": 12345,
  "objectType": "DEAL",
  "objectId": 9876,
  "subscriptionType": "deal.propertyChange",
  "propertyName": "dealstage",
  "propertyValue": "closedwon",
  "changeSource": "CRM_UI",
  "eventId": 9999999,
  "subscriptionId": 1234,
  "occurredAt": 1234567890123
}
```

O controller hoje so consome `portalId`, `objectType`, `objectId`, `subscriptionType`, `propertyName`, `propertyValue` — mas grava o `$evt` completo em `payload` para deferred analise (DLQ replay manual via Tinker).

### Authentication com Private App

Bearer token via `Http::withToken($token)`. Private App e' criado em **Settings → Integrations → Private Apps** no HubSpot. Diferente de OAuth — token nao expira (a menos que admin revogue) — ideal para webhook backend. Scopes necessarios:
- `crm.objects.deals.read` (fetchDeal)
- `crm.objects.companies.read` (fetchCompany)
- (associations vem automaticamente com os 2 acima)

## Self-Check: PASSED

- [x] FOUND: `app/Http/Controllers/Api/HubspotWebhookController.php`
- [x] FOUND: `app/Services/HubspotApiClient.php`
- [x] FOUND: `tests/Feature/Phase34HubspotWebhookTest.php`
- [x] FOUND: `.planning/phases/34-cadastro-comercial-otimizado-hubspot/34-04-SUMMARY.md`
- [x] FOUND commit: `3634fd4` (config + env)
- [x] FOUND commit: `4d0c56d` (HubspotApiClient)
- [x] FOUND commit: `1cc56cd` (controller + route)
- [x] FOUND commit: `39e3177` (tests)
- [x] FOUND commit: `2c83bfb` (final metadata: SUMMARY + STATE + ROADMAP)
- [x] Rota `api/webhooks/hubspot` registrada em route:list com middleware `throttle:60,1` e CSRF exception (via withoutMiddleware + bootstrap/app.php)
- [x] Suite Phase34HubspotWebhookTest 6/6 verdes (49 assertions)
- [x] Suite total Phase 31+33+34 42/42 verdes (233 assertions, zero regressao)

## Gotchas para Deploy / Operacao

### `.env` no VPS precisa ser populado ANTES do deploy ativar a rota

`HUBSPOT_CLIENT_SECRET` e `HUBSPOT_ACCESS_TOKEN` sao obrigatorios para o webhook funcionar. Em producao:
1. Criar Private App no painel HubSpot do cliente (settings → integrations).
2. Copiar Client Secret (App Settings → Auth) → `HUBSPOT_CLIENT_SECRET`.
3. Copiar Access Token (Private App Details) → `HUBSPOT_ACCESS_TOKEN`.
4. Confirmar `HUBSPOT_STAGE_FECHADO_GANHO_ID` — pegar via API ou painel; default `closedwon` so funciona em pipelines default do HubSpot (clientes customizam!).
5. Verificar nomes das custom props (`nicho`, `dor`, `vende_ml`, `faturamento_mensal`, `servico_ecf`) bem como `cnpj` no HubSpot. Se forem diferentes, override em `HUBSPOT_PROP_*`.
6. Registrar URL do webhook no HubSpot: `https://admin.ecfconsultoria.com.br/api/webhooks/hubspot` + subscriptionType `deal.propertyChange` apontando para a prop `dealstage`.

### Smoke local antes do deploy

```bash
SECRET="seu-secret-aqui"
URL="http://localhost:8000/api/webhooks/hubspot"
TS=$(($(date +%s) * 1000))
BODY='[{"portalId":12345,"objectType":"DEAL","objectId":1,"subscriptionType":"deal.propertyChange","propertyName":"dealstage","propertyValue":"closedwon"}]'
SIG=$(echo -n "POST${URL}${BODY}${TS}" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)
curl -X POST "$URL" \
  -H "X-HubSpot-Signature-v3: $SIG" \
  -H "X-HubSpot-Request-Timestamp: $TS" \
  -H "Content-Type: application/json" \
  -d "$BODY"
```

Esperado: `{"ok":true}` + 1 row em `hubspot_eventos` (status=erro porque vai tentar fetchDeal real com access_token possivelmente vazio em dev — esperado).

### Deferred (NAO entregue nesta phase)

- UI admin em `/dev/hubspot-eventos` para visualizar log de eventos (mencionado em CONTEXT, fora de escopo da Phase 34).
- Comando `php artisan hubspot:reprocessar-evento {id}` para retry manual de eventos com status=erro.
- Webhook bidirecional (ECF Admin → HubSpot) para sincronizar status do cliente.
- Atualizacao de empresa existente via HubSpot (hoje so CREATE).

## CRITICO: NAO Deployar Sozinho

Plan 34-04 entrega o receiver mas DEPENDE de:
- Schema da Phase 34-01 (companies.nicho/dor/etc + hubspot_eventos) — JA deployado se Phase 34 inteira sair junta.
- Plans 34-02 (wizard) e 34-03 (admin UI) — UX necessaria para o admin VER a empresa nova criada pelo webhook + completar info que veio incompleta.

**Agrupar deploy dos 4 plans da Phase 34** (padrao Phase 31/32/33). Subir webhook sem o admin UI = empresas viraiam em "/companies" sem badge "Empresa nova" visivel + sem botao "Marcar como visto" + sem campos de close no payload (estagiario/strategist nao verao a info coletada).
