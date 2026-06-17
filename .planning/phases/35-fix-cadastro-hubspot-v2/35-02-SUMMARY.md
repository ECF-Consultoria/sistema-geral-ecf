---
phase: 35-fix-cadastro-hubspot-v2
plan: 02
subsystem: hubspot-webhook + mlb-empresa
tags: [hubspot, webhook, contato, mlb-empresa, factory, fallback]
requires:
  - Phase 34 Plan 34-04 (webhook HubSpot HMAC v3 + HubspotApiClient)
  - Phase 13/14 (ComercialController::servicoDisparaImplementacao + criarImplementacaoPolo)
  - Phase 35 Plan 35-01 (Company::mlbEmpresa hasOne)
provides:
  - App\Services\MlbImplementacaoFactory::criarParaPolo (factory reutilizavel)
  - HubspotApiClient::fetchAssociatedContactId + fetchContact (D-04)
  - HubspotWebhookController fetcha contato vinculado + cria MlbEmpresa por servico (D-04 + D-05)
  - config('services.hubspot.props.contact') com 4 envs HUBSPOT_PROP_CONTACT_*
affects:
  - /api/webhooks/hubspot (cadastro automatico via HubSpot agora cria MlbEmpresa quando servico dispara)
  - Comercial/Empresas (proxy interno do criarImplementacaoPolo, comportamento identico)
tech-stack:
  added: []
  patterns:
    - "Factory estatica para extrair logica reutilizavel entre controllers"
    - "Fallback chain de propriedades (company.X > contact.X) com strings vazias tratadas como ausencia"
    - "Http::fake com ordem de patterns (specifico antes do catch-all wildcard)"
key-files:
  created:
    - app/Services/MlbImplementacaoFactory.php
    - tests/Feature/Phase35HubspotV2Test.php
  modified:
    - app/Http/Controllers/ComercialController.php
    - app/Services/HubspotApiClient.php
    - config/services.php
    - .env.example
    - app/Http/Controllers/Api/HubspotWebhookController.php
decisions:
  - "D-04: Fetch do contato vinculado e resiliente — falha do GET ou ausencia retorna null e fluxo segue (deal+company sao o minimo viavel)"
  - "D-04: Prioridade Company > Contato para email/telefone. Strings vazias da Company tratadas como ausencia (HubSpot manda '' em vez de omitir)"
  - "D-04: firstname + lastname concatenados (trim) em notes como 'Contato (HubSpot): {nome}' — gancho semantico para futura coluna contato_nome"
  - "D-05: ComercialController::servicoDisparaImplementacao e a fonte unica de verdade. Webhook delega ao helper estatico — sem duplicar regras de matching"
  - "D-05: Polos cria MlbEmpresa(POLO, projeto=POLOS) + MlbImplementacao via factory. Assessoria/Incubadora criam so MlbEmpresa do tipo correspondente. Publicidade/Gestao/Publicacao sem MlbEmpresa (comportamento preservado)"
  - "Factory extraida 1:1 — ComercialController::criarImplementacaoPolo vira proxy de 1 linha pra preservar API interna"
metrics:
  duration_minutes: ~30
  tasks_completed: 7
  files_modified: 5
  files_created: 2
  commits: 5
completed_at: 2026-06-17T15:05Z
---

# Phase 35 Plan 35-02: HubSpot v2 — contato vinculado + MlbEmpresa + factory Summary

Wave 2 da Phase 35 (paralelo ao Plan 35-03). Webhook HubSpot agora completa o
cadastro automatico: alem de Company + ContratoServico (Plan 34-04), fetcha o
**contato vinculado** ao deal pra preencher email/telefone fallback (D-04) e
cria **MlbEmpresa + MlbImplementacao** quando o servico dispara implementacao
(D-05). Logica de criacao da implementacao Polos extraida para `MlbImplementacaoFactory`
estatica reutilizavel — mesma fonte de verdade entre fluxo Comercial e webhook.

## Escopo Entregue

### 1. `App\Services\MlbImplementacaoFactory` (NOVO)

```php
class MlbImplementacaoFactory {
    public static function criarParaPolo(MlbEmpresa $empresa, array $handoff = []): MlbImplementacao
}
```

- Logica 1:1 extraida de `ComercialController::criarImplementacaoPolo`
  (Phase 13/14): merge dos defaults de `MlbConfiguracao::implementacaoPadroes`
  com handoff per-cadastro (ex: `gmail_colaborador`). Token aleatorio Str::random(48).
- `ComercialController::criarImplementacaoPolo` vira proxy de 1 linha que
  delega a factory — preserva API interna do controller (testes do fluxo
  Comercial continuam validos).
- Imports `Str` e `MlbConfiguracao` removidos do controller (movidos pra
  factory). `MlbImplementacao` continua importado pelo type hint do retorno.

### 2. `HubspotApiClient` (Phase 35 Plan 35-02 — D-04)

2 metodos novos:

```php
public function fetchAssociatedContactId(string $dealId): ?string
public function fetchContact(string $id, array $properties): array
```

Mesma forma do `fetchAssociatedCompanyId`/`fetchCompany`: GET com Bearer
token. `fetchAssociatedContactId` retorna null em qualquer erro (resiliente).
`fetchContact` re-lanca `$res->throw()` em 4xx/5xx pro caller decidir
tratamento (warning silencioso no webhook controller).

### 3. `config/services.php` + `.env.example`

Novo bloco `hubspot.props.contact`:

```php
'contact' => [
    'firstname' => env('HUBSPOT_PROP_CONTACT_FIRSTNAME', 'firstname'),
    'lastname'  => env('HUBSPOT_PROP_CONTACT_LASTNAME', 'lastname'),
    'email'     => env('HUBSPOT_PROP_CONTACT_EMAIL', 'email'),
    'phone'     => env('HUBSPOT_PROP_CONTACT_PHONE', 'phone'),
],
```

`.env.example` documenta as 4 vars HUBSPOT_PROP_CONTACT_* alinhadas com
defaults canonicos do HubSpot.

### 4. `HubspotWebhookController::criarEmpresa` (D-04 + D-05)

**Fetch do contato (em `processar`):**
- Depois de `fetchAssociatedCompanyId` + `fetchCompany`, chama
  `fetchAssociatedContactId` + `fetchContact`.
- Sem contato associado: `$contactId === null` → fluxo segue sem fallback.
- 4xx/5xx no `fetchContact`: log warning em `ecf-webhooks` channel +
  segue como se nao houvesse contato (nao bloqueia cadastro).

**Em `criarEmpresa($deal, $hubCompany, $hubContact)`:**
- `email_cliente`: prioridade `company.email` > `contact.email`. Strings
  vazias (`''`) tratadas como ausencia.
- `telefone`: idem prioridade `company.phone` > `contact.phone`.
- `firstname + ' ' + lastname` (trim) → linha `Contato (HubSpot): {nome}`
  anexada em `company.notes` (gancho para coluna futura).

**Roteamento MlbEmpresa (D-05):**
- Depois de criar Company + (opt) ContratoServico, chama
  `ComercialController::servicoDisparaImplementacao($servicoNome)`:
  - `'polos'` → `MlbEmpresa(tipo=POLO, projeto=POLOS, company_id=...)` +
    `MlbImplementacaoFactory::criarParaPolo($mlbEmp)`.
  - `'assessoria'` → `MlbEmpresa(tipo=ASSESSORIA, company_id=...)`.
  - `'incubadora'` → `MlbEmpresa(tipo=INCUBADORA, company_id=...)`.
  - `null` (Publicidade/Gestao/Publicacao) → so Company.
- Todo o roteamento dentro do `DB::transaction` — atomico com Company +
  ContratoServico.

### 5. Suite `Phase35HubspotV2Test.php` (NOVO)

7 cases (30 assertions):

| Test | Cenario | Verifica |
| --- | --- | --- |
| `polos_cria_mlb_empresa_e_implementacao` | servico=Polos SP | Company + MlbEmpresa(POLO,POLOS) + MlbImplementacao c/ token |
| `assessoria_cria_mlb_empresa` | servico=Assessoria Premium | Company + MlbEmpresa(ASSESSORIA), 0 MlbImplementacao |
| `publicidade_nao_cria_mlb_empresa` | servico=Publicidade ML | 1 Company, 0 MlbEmpresa, 0 MlbImplementacao |
| `contato_email_fallback_quando_company_email_vazio` | company.email='' + contato.email | email_cliente = contato.email |
| `contato_telefone_fallback_quando_company_phone_vazio` | company.phone='' + contato.phone | telefone = contato.phone |
| `sem_contato_associado_fluxo_completa_sem_erro` | results=[] em /associations/contacts | status=processado, sem fallback |
| `nome_contato_anexado_em_notes` | firstname+lastname preenchidos | notes contem "Contato (HubSpot): Maria Souza" |

Helpers `Http::fake` com **ordem importante**: patterns mais especificos
(`/associations/companies`, `/associations/contacts`) ANTES do catch-all
`deals/9876*` — caso contrario o catch-all matchearia as URLs de
associations e quebraria a logica.

## Verificacao

- `php artisan test --filter="Phase35HubspotV2Test"` → **7 passed (30 assertions)**, 1.49s.
- `php artisan test --filter="Phase34HubspotWebhook"` → **6 passed (49 assertions)** — zero regressao no baseline.
- `php artisan test --filter="Phase31|Phase33|Phase34|Phase35HubspotV2"` → **60 passed (368 assertions)**.
- `php artisan test --filter="Phase35HubspotV2|Phase35HubspotNotify"` → **11 passed (46 assertions)** — coexistencia com Plan 35-03 confirmada.
- `npm run build` → verde, sem warnings novos.

## Deviations from Plan

Nenhuma — escopo executado conforme planejado.

**Ponto interessante (pequeno polimento adicional):**
- Strings vazias da Company HubSpot (`email = ''`, `phone = ''`) sao tratadas
  como ausencia no fallback — HubSpot pode mandar string vazia em vez de
  omitir o campo, e queremos que o fallback do contato funcione nesse caso.
  O plano nao explicitava esse detalhe; foi adicionado defensivamente
  (`($val !== null && $val !== '')`).

## Authentication Gates

Nenhum — execucao autonoma com `Http::fake`, sem chamadas reais a HubSpot.

## Coexistencia com Plan 35-03 (Wave 2 paralelo)

Plan 35-03 ja foi commitado quando este Plan estava em execucao
(`6bdb0a8 feat(35-03): HubspotWebhookController dispara notificacao Comercial pos-criacao`).
Ambos tocam o `HubspotWebhookController` em pontos diferentes:
- **35-02**: extende `processar()` (fetch contato) + `criarEmpresa()`
  (fallback contato + roteamento MlbEmpresa).
- **35-03**: dispatch da notification APOS o commit da `criarEmpresa`.

Sem conflito de merge — pontos disjuntos. Suite combinada
(`Phase35HubspotV2|Phase35HubspotNotify`) passa **11/11**.

## Ponto interessante encontrado (HubSpot API)

A ordem dos patterns em `Http::fake` e critica quando ha catch-all com
wildcard. O padrao `'api.hubapi.com/crm/v3/objects/deals/9876*'` matcheia
TAMBEM `deals/9876/associations/companies` e `deals/9876/associations/contacts`.
Solucao: declarar as associations especificas ANTES do catch-all. O
`Phase34HubspotWebhookTest` original ja seguia esse padrao implicitamente
(declarava o `/associations/companies` antes do `deals/9876*`); replicamos
a convencao + estendemos pra `/associations/contacts`.

## Pre-existing Issues (Out of Scope)

A suite completa (`php artisan test` sem filtro) tem 45 falhas pre-existentes
em testes das Phases 13/14 — todas relacionadas a colunas legacy do User
renomeadas na Phase 7 (referencia: project memory
`project_legacy_columns_rename.md`). **Nao** sao causadas por este plano.

## Commits

| Hash      | Mensagem                                                                          |
| --------- | --------------------------------------------------------------------------------- |
| `eff5362` | feat(35-02): extrai MlbImplementacaoFactory + proxy no ComercialController        |
| `30cc3b0` | feat(35-02): HubspotApiClient ganha fetchAssociatedContactId + fetchContact       |
| `7fa41be` | feat(35-02): config hubspot.props.contact + .env.example HUBSPOT_PROP_CONTACT_*   |
| `7b34ba7` | feat(35-02): webhook HubSpot fetcha contato + cria MlbEmpresa por servico         |
| `452936a` | test(35-02): suite Phase 35 HubSpot v2 — contato + MlbEmpresa por servico         |

## Self-Check

- [x] `app/Services/MlbImplementacaoFactory.php` FOUND (novo)
- [x] `tests/Feature/Phase35HubspotV2Test.php` FOUND (novo)
- [x] `app/Http/Controllers/ComercialController.php` modified (proxy + imports limpos)
- [x] `app/Services/HubspotApiClient.php` modified (2 metodos novos)
- [x] `config/services.php` modified (props.contact)
- [x] `.env.example` modified (4 vars novas)
- [x] `app/Http/Controllers/Api/HubspotWebhookController.php` modified (fetch contato + MlbEmpresa)
- [x] Commits `eff5362`, `30cc3b0`, `7fa41be`, `7b34ba7`, `452936a` presentes em `git log`
- [x] `npm run build` verde
- [x] Phase34HubspotWebhookTest 6/6 verde (zero regressao no HMAC v3)
- [x] Phase35HubspotV2Test 7/7 verde (30 assertions)
- [x] Phase 31+33+34+35HubspotV2 = 60 passed (368 assertions) — baseline preservado
- [x] Coexistencia Plan 35-03: Phase35HubspotV2 + Phase35HubspotNotify = 11 passed (46 assertions)

**Self-Check: PASSED**
