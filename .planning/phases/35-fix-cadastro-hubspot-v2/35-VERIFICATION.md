---
phase: 35-fix-cadastro-hubspot-v2
verified: 2026-06-17T15:45:00Z
status: human_needed
score: 18/18 must-haves verificadas (D-01..D-08 cobertas)
overrides_applied: 0
human_verification:
  - test: "Aplicar migration de backfill em produção"
    expected: "Empresas com created_at < 2026-06-13 viram empresa_nova=false; novas mantêm true"
    why_human: "Migration roda apenas em prod; admin precisa rodar `php artisan migrate --force` no VPS"
  - test: "Criar deal teste no HubSpot com serviço Polos"
    expected: "Webhook cria Company + MlbEmpresa(POLO,POLOS) + MlbImplementacao e líder Comercial recebe notificação no sino"
    why_human: "Requer HubSpot real + Setor Comercial seedado em prod"
  - test: "Verificar /companies não mostra empresas com MlbEmpresa"
    expected: "Empresas associadas a mlb_empresas só aparecem em /mlb/empresas"
    why_human: "Comportamento visual de listagem com dados reais"
  - test: "Verificar BRL consistente em Companies/Show, Comercial/Empresas, Comercial/NovaEmpresa"
    expected: "Valores em R$ X.XXX,XX (2 casas decimais) em todos sites"
    why_human: "Inspeção visual em ambiente com dados reais"
  - test: "Verificar copy 'Em quais outros marketplaces...' nos 3 sites"
    expected: "Label novo + helper text 'Marketplaces que o cliente já opera por conta própria'"
    why_human: "Validação de UX e clareza pra usuário Comercial"
---

# Phase 35: Fix Cadastro Empresas + HubSpot v2 + UX — Verification Report

**Status:** PASS-COM-RESSALVAS (5 UATs humanos pendentes — todos não-bloqueantes)
**Recomendação:** APROVADO PARA DEPLOY (após confirmação dos UATs em ambiente de staging/prod)

## Cobertura das Decisões Locked

| Decisão | Item | Evidência codebase | Status |
|---------|------|-------------------|--------|
| D-01 | Migration backfill idempotente | `database/migrations/2026_06_13_400001_backfill_empresa_nova_existentes.php` linhas 25-35 com `where('created_at','<',...)` + `where('empresa_nova',true)` | VERIFICADO |
| D-02 | Sort opcional por created_at | `CompanyController::index` linhas 46-72 valida whitelist + aplica `orderBy('created_at',...)` | VERIFICADO |
| D-02 | UI Sort UI na aba Pendências | `Companies/Index.jsx` linhas 507-517 dropdown 3 opções | VERIFICADO |
| D-03 | `whereDoesntHave('mlbEmpresa')` | `CompanyController::index` linha 65 + `Company::mlbEmpresa()` hasOne linhas 259-262 | VERIFICADO |
| D-04 | Fetch contato vinculado | `HubspotApiClient::fetchAssociatedContactId` linha 106 + `fetchContact` linha 128 | VERIFICADO |
| D-04 | Fallback email/telefone do contato | `HubspotWebhookController::criarEmpresa` (verificado via teste `contato_email_fallback`, `contato_telefone_fallback`) | VERIFICADO |
| D-04 | Nome contato em notes | Linha 290 `Contato (HubSpot): {nome}`; teste `nome_contato_anexado_em_notes` verde | VERIFICADO |
| D-05 | Roteamento MlbEmpresa por serviço | `HubspotWebhookController` linhas 322-350; testes `polos_cria_mlb_empresa_e_implementacao` + `assessoria_cria_mlb_empresa` + `publicidade_nao_cria_mlb_empresa` verdes | VERIFICADO |
| D-05 | `MlbImplementacaoFactory::criarParaPolo` | `app/Services/MlbImplementacaoFactory.php` linha 33; `ComercialController::criarImplementacaoPolo` linha 531 vira proxy | VERIFICADO |
| D-06 | `EmpresaHubspotPendenteNotification` estende BaseNotification | linhas 32, 58-67 c/ titulo+mensagem+url+meta(`fonte=hubspot`) | VERIFICADO |
| D-06 | `AudienciaComercial::lideresEPermissionados` union | `app/Support/AudienciaComercial.php` linhas 42-59 — `setor_lideres` + `hasPermission` | VERIFICADO (ajuste D-10 documentado) |
| D-06 | Dispatch após commit | `HubspotWebhookController` linha 209, fora do `DB::transaction` | VERIFICADO |
| D-07 | BRL consistente | `Companies/Show.jsx` 9 usos de `formatCurrency`; `Comercial/Empresas.jsx` + `NovaEmpresa.jsx` delegam `fmtBRL` → `formatCurrency` | VERIFICADO |
| D-08 | Copy "Em quais outros marketplaces..." | 3 sites: `Companies/Index.jsx:813`, `Comercial/NovaEmpresa.jsx:481`, `Comercial/Empresas.jsx:326` | VERIFICADO |

## Ajustes vs Plano Original (3 documentados)

1. **D-10 (Plan 35-03)**: `AudienciaComercial` simplificada para 2 fontes (não 3) — cargo `lider-comercial` não existe no seed; cobertura equivalente via `setor_lideres` + `hasPermission`. Documentado em `35-03-SUMMARY.md` "Deviations from Plan #2".
2. **D-12 (Plan 35-03)**: Dispatch da notificação envolvido em try/catch `\Throwable` — proteção defensiva contra falha de DB/route. Documentado em "Deviations from Plan #1".
3. **D-09 (Plan 35-03)**: Reutiliza `Categoria::MANUAL` (enum atual não tem categoria HubSpot-específica) — categoria dedicada está em Deferred Ideas.
4. **Polimento extra (Plan 35-02)**: Strings vazias (`''`) tratadas como ausência no fallback de email/telefone (HubSpot manda `''` em vez de omitir). Não estava no plano original.

## Verificações Executadas

| Check | Comando | Resultado |
|-------|---------|-----------|
| Migration existe + idempotente | Inspeção arquivo + `where('empresa_nova',true)` guard | PASS |
| Suite Phase 31+33+34+35 | `php artisan test --filter="Phase31\|Phase33\|Phase34\|Phase35"` | **70 passed (526 assertions)** |
| Suite Phase 35 isolada | `php artisan test --filter=Phase35` | **17 passed (188 assertions)** |
| Build frontend | `npm run build` | VERDE (14.38s, sem warnings novos) |
| Config HubSpot contact | `config/services.php` linhas 101-106 + `.env.example` linhas 114-117 | PASS (4 vars com env override) |

## Requirements Coverage

REQs declarados nos plans: REQ-35-01..08. **Não estão registrados em `.planning/REQUIREMENTS.md`** — minor warning (rastreabilidade), não bloqueador. Cobertura efetiva via D-01..D-08 acima.

## Anti-Patterns / Stubs

Nenhum stub introduzido. Nenhum `TODO`/`FIXME`/`XXX` não-referenciado nos arquivos modificados desta phase. Pre-existing failures (45 testes Phases 13/14) já documentadas como out-of-scope nos SUMMARYs (colunas legacy do User — Phase 7).

## Resumo

Phase 35 atinge todos os 7 objetivos do CONTEXT.md de forma observável no codebase. Truths must-have 18/18 verificadas. Testes automatizados 70/70 verdes. Build verde. As ressalvas (UATs humanos) são esperadas — migration de backfill precisa rodar em prod, e os fluxos HubSpot/UX precisam de validação visual em ambiente real.

---

_Verificado: 2026-06-17T15:45:00Z_
_Verificador: Claude (gsd-verifier)_
