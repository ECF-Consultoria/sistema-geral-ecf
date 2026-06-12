# Phase 34: Cadastro Comercial Otimizado + Integração HubSpot

**Gathered:** 2026-06-12
**Status:** Ready for execution (lean — sem discuss/research/plan-check)
**Source:** Pedido direto do usuário.

<domain>
## Phase Boundary

### O que esta fase entrega

1. **Mais informações capturadas no cadastro** — quando o Comercial fecha negócio com o cliente, ele já coleta info valiosa (nicho, dor, faturamento, marketplaces extras, se já vende no ML). Hoje essa info se perde. Esta phase persiste tudo no `companies` e expõe na ficha da empresa para que estrategista/analista tenham contexto desde o primeiro dia.

2. **Webhook HubSpot → Cadastro automático.** O time comercial usa HubSpot como CRM. Quando um deal vira "Fechado Ganho" no HubSpot, um POST chega na VPS, valida HMAC, busca os dados do deal + company associada via API HubSpot, e cria a empresa no ECF Admin automaticamente. Elimina retrabalho de cadastrar nos 2 sistemas.

3. **Correção do `email_colaborador`** — hoje a pendência "Sem email colaborador" olha o campo `email_cliente`, que tem semântica diferente. Cria coluna nova `email_colaborador` (email criado pela ECF para acesso colaborador no ML) e separa do `email_cliente` (email do proprietário, usado pelo NPS).

4. **Tag "Empresa nova"** na aba Pendências de `/companies` — destaca empresas recém-cadastradas que ainda não tiveram triagem do admin. Sai via botão "Marcar como visto" no card/linha.

5. **Máscaras de CNPJ e telefone** nos forms de cadastro/edição — UX consistente, evita típico erro de copy-paste de CNPJ sem pontuação.

### Estado atual investigado (2026-06-12)

- `companies` tem `email_cliente` (Phase 31), `telefone` (Quick 260611-eml), `marketplace` (enum único, Phase 18.5).
- Pendências no `CompanyController::index` linhas 95-104 são calculadas in-line via array_filter. Pendência `sem_email_colaborador` (linha 100) olha errado: `(! $c->email_cliente)` — bug semântico identificado pelo usuário.
- `EcfWebhookController` (Phase 26) é referência arquitetural sólida para HMAC: lê raw body com `$request->getContent()` (não `$request->all()` — bytes precisam bater), header `X-ECF-Signature: sha256=<hex>`, `hash_equals` timing-safe, secret em `config('services.ecf.webhook_secret')`, NUNCA loga secret.
- `Comercial/NovaEmpresa.jsx` ganhou wizard de 2 passos (Quick 260612-jyx — outro dev). Vou estender com mais campos no passo 2 ou criar passo 3.
- Autopop `companies:importar-drive` populou `email_cliente` com email do proprietário (vem do Drive). Isso está SEMANTICAMENTE correto — o erro era a pendência checar email_cliente em vez de email_colaborador.

</domain>

<decisions>
## Implementation Decisions

### Schema (D-01)

**D-01 — Migration única adicionando colunas em `companies` (LOCKED).**

```sql
ALTER TABLE companies ADD COLUMN:
  - nicho                VARCHAR(255) NULL          -- ex: "Moda feminina", "Auto peças"
  - dor                  TEXT NULL                  -- dor/pain do cliente capturada no close
  - vende_ml             TINYINT(1) NULL            -- nullable (null = desconhecido, 1 = sim, 0 = não)
  - faturamento_mensal   DECIMAL(12,2) NULL         -- faturamento mensal estimado em R$
  - marketplaces_extras  JSON NULL                  -- array de strings: ["shopee","amazon","magalu","temu","tiktok"]
  - email_colaborador    VARCHAR(255) NULL          -- email criado pela ECF para acesso colaborador no ML
  - empresa_nova         TINYINT(1) DEFAULT 1       -- 1 = ainda não foi vista pelo admin
  - empresa_nova_visto_em TIMESTAMP NULL            -- quando o admin marcou como visto
  - empresa_nova_visto_por BIGINT NULL FK users     -- quem marcou
```

Migration defensiva (`Schema::hasColumn`) para tolerar re-runs.

### Tabela HubSpot eventos (D-02)

**D-02 — Tabela `hubspot_eventos` para auditoria + replay (LOCKED).**

```sql
CREATE TABLE hubspot_eventos:
  - id BIGINT PK
  - signature_valid TINYINT(1) NOT NULL   -- false = ataque ou mismatch
  - portal_id VARCHAR(50) NULL
  - object_type VARCHAR(50) NULL          -- 'deal', 'company', etc
  - object_id VARCHAR(100) NULL           -- ID do objeto no HubSpot
  - subscription_type VARCHAR(100) NULL   -- 'deal.propertyChange'
  - property_name VARCHAR(100) NULL       -- 'dealstage'
  - property_value TEXT NULL              -- novo valor
  - payload JSON NOT NULL                 -- payload completo
  - status ENUM('recebido','processado','ignorado','erro') DEFAULT 'recebido'
  - erro_msg TEXT NULL
  - company_id_criada BIGINT NULL FK companies SET NULL
  - processado_em TIMESTAMP NULL
  - created_at, updated_at
  - INDEX (status, created_at)
  - INDEX (object_id)
```

### HubSpot — Validação HMAC v3 (D-03)

**D-03 — HMAC v3 com timestamp (LOCKED).**

HubSpot v3 manda:
- Header `X-HubSpot-Signature-v3`: HMAC SHA-256 base64
- Header `X-HubSpot-Request-Timestamp`: epoch ms

Cálculo: `base64(hmac_sha256(client_secret, METHOD + URI + body + timestamp))`. Rejeita se timestamp > 5 min de diferença (replay).

Secret: `config('services.hubspot.client_secret')` lido de `.env` `HUBSPOT_CLIENT_SECRET`.

**Defesa adicional:** se header ausente OU signature inválida OU timestamp antigo → grava `signature_valid=false` (auditoria) e retorna `401`. Não vaza secret no log.

### HubSpot — Fluxo de processamento (D-04)

**D-04 — Processamento síncrono inline (LOCKED).**

Webhook recebe → valida HMAC → grava `hubspot_eventos` → processa inline (síncrono, dentro do request). Se falhar, marca `status=erro` com `erro_msg` e retorna 200 (HubSpot não re-tenta). Reprocessamento manual via comando `php artisan hubspot:reprocessar-evento {id}` (deferred para futuro).

Processamento:
1. Filtrar evento — só processa se `subscription_type === 'deal.propertyChange'` e `property_name === 'dealstage'` e `property_value === HUBSPOT_STAGE_FECHADO_GANHO_ID` (configurável via .env).
2. Fetch deal completo via `GET /crm/v3/objects/deals/{object_id}?properties=...` (Bearer access token de `.env` `HUBSPOT_ACCESS_TOKEN`).
3. Fetch company associated via `GET /crm/v3/objects/deals/{object_id}/associations/companies` → pega o primeiro company_id → `GET /crm/v3/objects/companies/{company_id}?properties=...`.
4. Mapeia campos (D-05) → cria `Company` + `ContratoServico` em `DB::transaction`.
5. Idempotência: antes de criar, checa `hubspot_eventos.object_id` já tem `company_id_criada !== null` → pula (não cria duplicata).

### HubSpot — Mapeamento de campos (D-05)

**D-05 — Mapeamento configurável via .env (LOCKED).**

Defaults (matchem HubSpot standard + custom props comuns):

| Campo ECF              | HubSpot prop            | env var override                  |
|------------------------|-------------------------|-----------------------------------|
| companies.name         | company.name            | HUBSPOT_PROP_COMPANY_NAME         |
| companies.cnpj         | company.cnpj            | HUBSPOT_PROP_COMPANY_CNPJ         |
| companies.email_cliente| company.email           | HUBSPOT_PROP_COMPANY_EMAIL        |
| companies.telefone     | company.phone           | HUBSPOT_PROP_COMPANY_PHONE        |
| companies.nicho        | deal.nicho              | HUBSPOT_PROP_DEAL_NICHO           |
| companies.dor          | deal.dor                | HUBSPOT_PROP_DEAL_DOR             |
| companies.vende_ml     | deal.vende_ml           | HUBSPOT_PROP_DEAL_VENDE_ML        |
| companies.faturamento_mensal | deal.faturamento_mensal | HUBSPOT_PROP_DEAL_FATURAMENTO  |
| servico_id padrão      | deal.servico_ecf        | HUBSPOT_PROP_DEAL_SERVICO         |
| contrato.valor         | deal.amount             | (fixo — campo nativo HubSpot)     |

Empresa criada sai com `empresa_nova=true` (default), `status='pendente'`, e contrato_servico se o serviço bateu com o catálogo (`Servico::where('nome', $servicoNome)->first()`). Se serviço não bate, grava o nome em `notes` e segue sem contrato (admin completa depois).

### Empresa nova: marcar como visto (D-06)

**D-06 — Endpoint dedicado, botão na linha (LOCKED).**

- `POST /companies/{company}/marcar-visto` (role:admin)
- Atualiza `empresa_nova=false`, `empresa_nova_visto_em=now()`, `empresa_nova_visto_por=auth()->id()`.
- Botão "Marcar como visto" (ícone Check) aparece na linha da empresa em `Companies/Index.jsx` quando `pendencias.includes('empresa_nova')` e usuário é admin.
- Pendência `empresa_nova` = `$c->empresa_nova` (boolean direto).

### Pendência "sem_email_colaborador" — fix (D-07)

**D-07 — Corrigir bug semântico (LOCKED).**

`CompanyController::index` linha 100: `(! $c->email_cliente)` → `(! $c->email_colaborador)`. Empresas vão ressurgir com a pendência (autopop do Drive não populou esse campo) — é o comportamento desejado.

### Máscaras (D-08)

**D-08 — react-imask para CNPJ e telefone (LOCKED).**

Já instalado? Verificar. Caso não esteja, instalar `react-imask`. Sites: `Comercial/NovaEmpresa.jsx`, `Comercial/Empresas.jsx`, `Companies/Index.jsx` modal.

Padrões:
- CNPJ: `00.000.000/0000-00`
- Telefone: `(00) 0000-0000` ou `(00) 00000-0000` (auto switch por tamanho)

Backend NÃO valida formato (string max 18/20), só fricção UX.

### Marketplaces extras (D-09)

**D-09 — Cast `array` + UI checkbox group (LOCKED).**

Companies.$casts = `['marketplaces_extras' => 'array']`. UI: 5 checkboxes (Shopee, Amazon, Magalu, Temu, Tiktok). Constante JS `MARKETPLACES_EXTRAS = ['shopee','amazon','magalu','temu','tiktok']` com labels pt-BR.

### Onde os novos campos aparecem (D-10)

**D-10 — 4 sites afetados (LOCKED).**

| Site                              | Modo     | Campos novos                                    |
|-----------------------------------|----------|------------------------------------------------|
| Comercial/NovaEmpresa.jsx wizard  | criar    | nicho, dor, vende_ml, faturamento_mensal, mp+extras |
| Comercial/Empresas.jsx (edit)     | editar   | mesmos + email_colaborador                     |
| Companies/Index.jsx modal admin   | criar/editar | mesmos + email_colaborador                  |
| Companies/Show.jsx                | exibir   | nova seção "Informações do Close"             |

### Claude's Discretion

- Layout do passo 3 do wizard vs expandir passo 2 (planner escolhe)
- Posição do botão "Marcar como visto" (inline na linha ou modal de detalhes)
- Mensagens de erro do webhook HubSpot (geral mas não exporta detalhes pra fora)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read.**

### HMAC reference (replicar pattern)
- `app/Http/Controllers/EcfWebhookController.php` — modelo de validação HMAC + log estruturado + 401 timing-safe

### Comercial atual (wizard 2 passos)
- `app/Http/Controllers/ComercialController.php::store()` — validation + DB::transaction + handoff Polos. Adicionar campos novos aqui.
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` — wizard 2 passos. Adicionar mais campos.
- `resources/js/Pages/Comercial/Empresas.jsx` — edit inline form. Adicionar campos novos + email_colaborador.

### Admin companies
- `app/Http/Controllers/CompanyController.php::index()` linhas 95-104 (pendências), 374-388 (store), 401-416 (update), 56-105 (payload)
- `resources/js/Pages/Companies/Index.jsx` — PENDENCIAS const linha 80, modal admin
- `resources/js/Pages/Companies/Show.jsx` — adicionar seção "Informações do Close"

### Config + .env
- `config/services.php` — adicionar bloco `hubspot` (access_token, client_secret, stage_fechado_ganho_id, props *)
- `.env.example` — documentar novas vars `HUBSPOT_*`
- `routes/api.php` ou `routes/web.php` — onde adicionar rota webhook (Phase 26 usa web.php fora do middleware CSRF)

</canonical_refs>

<specifics>
## Specific Ideas

- **HubSpot API base URL:** `https://api.hubapi.com`. SDK PHP oficial é overkill — usar `Http::withToken(...)->get(...)` direto.
- **Idempotência do webhook:** mesma deal entrando 2x (HubSpot retry) deve criar 1 empresa só. Check `hubspot_eventos.object_id` + `company_id_criada != null` antes.
- **Defesa contra spam:** middleware throttle 60/min na rota do webhook (HubSpot legítimo manda muito menos que isso).
- **Empresa nova visível pra todos:** a pendência aparece pra qualquer usuário com acesso a /companies (admin, comercial). Só o botão "Marcar como visto" é restrito a admin.
- **Email colaborador no autopop:** comando `companies:importar-drive` continua populando SÓ `email_cliente`. NÃO tocar em `email_colaborador` — é responsabilidade humana.
- **CNPJ no HubSpot:** custom property; provavelmente o usuário criou. Se vier vazio, salvo `null` (não obriga).

</specifics>

<deferred>
## Deferred Ideas

- Comando `hubspot:reprocessar-evento {id}` (Plan 34-04 grava `status=erro`; manualmente via Tinker funciona)
- UI admin pra visualizar log de `hubspot_eventos` (similar a `/nps/emails-enviados`)
- Atualização de empresa existente via HubSpot (hoje só CREATE)
- Webhook bidirecional (ECF Admin → HubSpot)
- Mapeamento múltiplo de serviços (deal pode vir com vários)
- Validação CNPJ via dígito verificador

</deferred>

---

*Phase: 34-cadastro-comercial-otimizado-hubspot*
*Context gathered: 2026-06-12*
