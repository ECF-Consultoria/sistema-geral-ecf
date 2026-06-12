---
phase: 34-cadastro-comercial-otimizado-hubspot
verified: 2026-06-12T00:00:00Z
status: human_needed
score: 23/23 must-haves verificados
overrides_applied: 0
re_verification:
  previous_status: null
  previous_score: null
  gaps_closed: []
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Smoke real do webhook HubSpot end-to-end"
    expected: "Cadastrar Private App no HubSpot (cliente), copiar client_secret + access_token p/ .env do VPS, registrar URL https://admin.ecfconsultoria.com.br/api/webhooks/hubspot com subscription deal.propertyChange→dealstage, mover um deal de teste para Fechado Ganho, ver Company aparecer em /companies com badge Empresa nova + linha em hubspot_eventos (status=processado, company_id_criada preenchido)."
    why_human: "Webhook depende de credenciais reais do HubSpot do cliente + custom props (cnpj, nicho, dor, vende_ml, faturamento_mensal, servico_ecf) realmente existirem no portal. Testes automatizados usam Http::fake."
  - test: "Wizard /comercial/nova com campos do close e máscaras"
    expected: "Acessar Comercial → Nova Empresa, digitar CNPJ → ver máscara 00.000.000/0000-00 aplicar, digitar 11 dígitos no telefone → ver máscara (00) 00000-0000 trocar para (00) 0000-0000 em 10 dígitos, preencher nicho/dor/vende_ml/faturamento_mensal/marketplaces extras (checkboxes 5 opções)/email colaborador no bloco opcional do passo 2, submit cria company com todos os campos persistidos."
    why_human: "Comportamento dinâmico de mascara (react-imask auto-switch 10/11 dígitos) + UX do bloco opcional não verificável via grep."
  - test: "Tag Empresa nova + botão Marcar como visto"
    expected: "Em /companies, na aba Pendências, ver empresas recém-cadastradas com badge amarelo 'Empresa nova'. Como admin, clicar no botão verde Check inline na linha — pendência some sem reload, empresa_nova=false + empresa_nova_visto_em + empresa_nova_visto_por populados. Como não-admin, botão não aparece."
    why_human: "Gate visual por role + interação preserveScroll + feedback visual de remoção da pendência."
  - test: "Companies/Show — seção Informações do Close"
    expected: "Abrir ficha de uma empresa que tem nicho/dor/vende_ml/etc., ver nova seção 'Informacoes do Close' com 6 campos read-only formatados (faturamento em R$, vende_ml como Sim/Nao/—, marketplaces como chips, dor multiline)."
    why_human: "Renderização visual da seção em layout real."
  - test: "Máscara mobile (touch)"
    expected: "Em dispositivo mobile real (não emulador), abrir wizard Comercial → digitar CNPJ + telefone via teclado numérico mobile — confirmar que IMaskInput aceita digitação rápida sem perder caracteres e teclado numérico aparece."
    why_human: "Comportamento de teclado virtual + foco de input só observável em hardware real."
  - test: "Modal admin /companies edita 6 campos novos"
    expected: "Como admin em /companies, abrir modal Editar, preencher os 6 campos do close + email_colaborador. Submit persiste tudo. Labels claras: 'Email do cliente (NPS)' vs 'Email colaborador ECF'."
    why_human: "UX de labels e separação semântica D-07 só auditável visualmente."
---

# Phase 34: Cadastro Comercial Otimizado + HubSpot — Verification Report

**Phase Goal:** (1) Persistir info valiosa capturada no close; (2) Webhook HubSpot → cria empresa quando deal vira Fechado Ganho; (3) Fix bug semântico sem_email_colaborador; (4) Tag Empresa nova + botão Marcar como visto; (5) Máscaras CNPJ/Telefone via react-imask.
**Verified:** 2026-06-12
**Status:** human_needed (todos os 23 must-haves técnicos VERIFICADOS via código; 6 UATs humanos pendentes)
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | Migrations close_fields + hubspot_eventos rodaram em local | VERIFIED | `Schema::hasTable('hubspot_eventos')=true`; 9 colunas confirmadas via tinker |
| 2 | Tabela companies tem 9 colunas novas | VERIFIED | Todas as 9 columns: true via `Schema::hasColumn` |
| 3 | Tabela hubspot_eventos tem schema D-02 (13 colunas + 2 indexes) | VERIFIED | Migration 300002 — signature_valid, portal_id, object_type, object_id, subscription_type, property_name, property_value, payload, status enum, erro_msg, company_id_criada, processado_em, indexes status+created_at e object_id |
| 4 | Company model com 9 fillable + 6 casts corretos | VERIFIED | `app/Models/Company.php:34-51` — vende_ml=>bool, empresa_nova=>bool, marketplaces_extras=>array, faturamento_mensal=>decimal:2, empresa_nova_visto_em=>datetime |
| 5 | HubspotEvento model com fillable + casts + companyCriada() | VERIFIED | `app/Models/HubspotEvento.php` — 12 fillable, casts (bool/array/datetime), relação BelongsTo Company |
| 6 | Bug D-07 corrigido: pendência sem_email_colaborador olha email_colaborador | VERIFIED | `CompanyController.php:117` — `(! $c->email_colaborador) ? 'sem_email_colaborador' : null` |
| 7 | Pendência empresa_nova adicionada em CompanyController::index | VERIFIED | `CompanyController.php:121` |
| 8 | Payload /companies inclui 9 campos novos | VERIFIED | `CompanyController.php:72-79` index + 328-333 show com cast (float) defensivo no faturamento |
| 9 | Rota POST /companies/{company}/marcar-visto registrada com role:admin | VERIFIED | route:list mostra middleware `EnsureUserHasRole:admin` + `companies.marcar-visto` |
| 10 | marcarVisto atualiza empresa_nova + visto_em + visto_por | VERIFIED | `CompanyController.php:578-589` |
| 11 | ComercialController::store valida 6 campos novos | VERIFIED | linhas 211-217 — nicho, dor, vende_ml, faturamento_mensal, marketplaces_extras (+`*` Rule::in), email_colaborador |
| 12 | ComercialController::update aceita os mesmos 6 campos | VERIFIED | linhas 389-397 |
| 13 | CompanyController::store/update valida 6 campos novos | VERIFIED | linhas 443-451 (idêntico ao Comercial; Rule::in([5 markets])) |
| 14 | Companies/Index.jsx PENDENCIAS const tem empresa_nova + botão Check | VERIFIED | `Companies/Index.jsx:98` (label Empresa nova, classe ecf-yellow) + linhas 559-568 (botão Check inline, gate `isAdmin && pendencias.includes('empresa_nova')`) |
| 15 | Companies/Show.jsx tem seção "Informações do Close" | VERIFIED | `Companies/Show.jsx:684-733` — bloco com nicho/faturamento/vende_ml/marketplaces/email_colaborador/dor |
| 16 | Comercial/Empresas.jsx edit form tem 6 campos + IMaskInput | VERIFIED | linhas 4, 124-129, 193-194, 238 — IMaskInput em CNPJ + Telefone, 6 campos do close |
| 17 | Comercial/NovaEmpresa.jsx wizard tem bloco close + IMaskInput | VERIFIED | 54 ocorrências em busca; bloco "Informações do close (opcional)" no passo 2; IMaskInput controlado por useForm com transform() vende_ml |
| 18 | config/services.php tem bloco hubspot D-05 | VERIFIED | linhas 71-92 — client_secret, access_token, stage_fechado_ganho_id, props.deal (5), props.company (4) |
| 19 | .env.example documenta 12 vars HUBSPOT_* | VERIFIED | linhas 101-112 (3 root + 5 deal props + 4 company props = 12 vars) |
| 20 | HubspotApiClient com 3 métodos fetch + Bearer token | VERIFIED | `app/Services/HubspotApiClient.php` — fetchDeal, fetchAssociatedCompanyId (resiliente null), fetchCompany, Http::withToken |
| 21 | HubspotWebhookController valida HMAC v3 timing-safe + grava evento + processa síncrono | VERIFIED | linhas 56-113 — replay window 5min, hash_equals, gravarInvalido() trunca raw 65KB, processar() inline com try/catch, idempotência D-04 (linha 134-137 com exclude self) |
| 22 | Rota POST /api/webhooks/hubspot fora de CSRF + throttle:60,1 | VERIFIED | route:list mostra middleware `ThrottleRequests:60,1`; `routes/web.php:65-68` + `withoutMiddleware(ValidateCsrfToken)` |
| 23 | react-imask 7.x em package.json + node_modules | VERIFIED | package.json:47 `"react-imask": "^7.6.1"`; node_modules/react-imask/package.json existe |

**Score:** 23/23 must-haves técnicos verificados

### Cobertura das Decisões Locked

| Decisão | Status | Evidência |
|---------|--------|-----------|
| D-01 schema 9 colunas (defensivo) | VERIFIED | Migration 300001 com `Schema::hasColumn` em cada coluna |
| D-02 hubspot_eventos schema completo | VERIFIED | Migration 300002 com 13 colunas + status enum + 2 indexes |
| D-03 HMAC v3 timing-safe + replay 5min | VERIFIED | HubspotWebhookController L66-77, REPLAY_WINDOW_MS=300000 |
| D-04 processamento síncrono + idempotência | VERIFIED | processar() inline, idempotência via object_id+company_id_criada NOT NULL excluindo self |
| D-05 mapeamento configurável .env | VERIFIED | config/services.php props.deal (5) + props.company (4) com env() overrides |
| D-06 botão Marcar como visto inline + role:admin | VERIFIED | Endpoint + JSX gate isAdmin + botão Check verde |
| D-07 fix email_colaborador | VERIFIED | `(! $c->email_colaborador)` em CompanyController.php:117 |
| D-08 react-imask CNPJ/Telefone | VERIFIED | IMaskInput em NovaEmpresa, Empresas, Companies/Index modal |
| D-09 marketplaces JSON array (5 opções) | VERIFIED | Cast 'array' + Rule::in([shopee, amazon, magalu, temu, tiktok]) em 2 controllers |
| D-10 4 sites afetados | VERIFIED | NovaEmpresa.jsx, Empresas.jsx, Companies/Index.jsx modal, Companies/Show.jsx — todos com diff confirmado |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Test suite Phase31/33/34 | `php artisan test --filter="Phase31\|Phase33\|Phase34"` | 42 passed (233 assertions, 20.15s) | PASS |
| Build frontend | `npm run build` | built in 11.56s, 0 erros | PASS |
| Route marcar-visto | `route:list --name=companies.marcar-visto -v` | POST + Auth + Verified + role:admin | PASS |
| Route hubspot webhook | `route:list --name=webhooks.hubspot -v` | POST + throttle:60,1 + sem CSRF | PASS |
| Schema DB local | tinker hasTable+hasColumn | hubspot_eventos:true + 9/9 colunas:true | PASS |

### Anti-Patterns Found

Nenhum blocker ou warning encontrado. Verificação de TODO/FIXME/XXX nos arquivos modificados desta phase não encontrou markers órfãos. Logging do webhook NUNCA expõe client_secret (apenas IP + body_size). Raw body truncado defensivamente a 65KB em `gravarInvalido()`.

### Requirements Coverage

REQ-34-01 a REQ-34-12: todos os 12 requirements mapeados nos 4 SUMMARY frontmatters foram cobertos pelos truths 1-23 acima. Nenhum requirement órfão.

### Gaps Summary

**Nenhum gap técnico bloqueante.** A fundação (Plan 34-01), wizard comercial (34-02), admin UI (34-03) e webhook HubSpot (34-04) entregam todas as funcionalidades prometidas no CONTEXT.md. Todas as 10 decisões locked (D-01..D-10) estão materialiazadas no código. 42/42 testes verdes. Build verde.

**Pendências legítimas (não-bloqueantes):**
- Smoke real do webhook com credenciais HubSpot do cliente (UAT humano)
- Verificação visual mobile da máscara CNPJ/Telefone (UAT humano)
- Confirmação de UX inline do botão Marcar como visto (UAT humano)
- 4 deferred items documentados no Plan 34-04 (comando reprocessar, UI log /dev/hubspot-eventos, UPDATE bidirecional, validação CNPJ DV) — explicitamente fora de escopo da Phase 34.

**Co-autoria silenciosa em commits (não-bloqueante):** Commits `4961793` e `1cc56cd` incluíram diffs de plans paralelos por ausência de worktrees separados. Conteúdo semanticamente correto, separação por plan ficou mista — documentado nos SUMMARYs.

### Recomendação

**APROVADO PARA DEPLOY (sujeito à conclusão dos 6 UATs humanos antes de subir em produção).**

A Phase 34 entrega 100% dos truths declarados no CONTEXT + 100% das decisões locked. Não há gap de implementação no codebase. Os UATs restantes são validação real de UX/integração externa que não pode ser feita programaticamente. Deploy seguro desde que:

1. `.env` do VPS receba `HUBSPOT_CLIENT_SECRET` + `HUBSPOT_ACCESS_TOKEN` + (opcional) overrides `HUBSPOT_PROP_*` ANTES de o webhook ser registrado no painel HubSpot.
2. 4 plans subirem juntos (não isolados), conforme alerta consistente nos SUMMARYs.
3. Smoke do webhook com curl assinado executado pós-deploy antes de cadastrar URL no HubSpot do cliente.

---

_Verificado: 2026-06-12_
_Verifier: Claude (gsd-verifier)_
