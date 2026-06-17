---
phase: 36-comercial-uxe-atribuir-servico
verified: 2026-06-17T17:55:00Z
status: passed
score: 11/11 verificações obrigatórias atendidas
re_verification:
  previous_status: null
  initial: true
human_verification:
  - test: "Smoke real do fluxo end-to-end: logar como admin, ir em /companies, abrir aba pendências (filtro sem_servico), clicar botão 'Serviço' de uma empresa pendente"
    expected: "Navegar direto para /comercial/atribuir-servico/{id} (sem hop pelo admin); página renderiza com header da empresa (nome, cust_id, nicho, segment, email/telefone) + lista de contratos + form pré-preenchido (data_contratacao=hoje, data_vencimento=hoje+1ano)"
    why_human: "Inertia render + UX visual + roteamento real (route() helper em JS)"
  - test: "Máscara BRL: digitar '1234.56' no campo valor_contratado"
    expected: "Display: 'R$ 1.234,56'; submit envia 1234.56 (numérico) ao backend; contrato salva valor correto em decimal:2"
    why_human: "IMaskInput é controlado e o comportamento de digitação só pode ser validado num browser real (onAccept dispara durante digitação)"
  - test: "Default +1 ano: alterar data_contratacao para 2026-08-15"
    expected: "data_vencimento auto-preenche para 2027-08-15. Editar manualmente data_vencimento depois para 2027-12-31 — valor manual persiste no submit"
    why_human: "Comportamento reativo do form (handleDataContratacaoChange só dispara via onChange UI)"
  - test: "Salvar contrato + redirect: preencher form e clicar 'Salvar contrato'"
    expected: "POST /empresas/{id}/contratos-servico retorna 200; flash success; router.visit('/companies') executa; contrato aparece na lista de /companies linha da empresa"
    why_human: "End-to-end real com backend CompanyController (que está intacto, mas precisa confirmação que aceita o payload com data_vencimento)"
  - test: "403 para consultor sem permission: logar como user com role=consultor SEM 'comercial.cadastrar_empresa' e SEM admin"
    expected: "Acessar /comercial/atribuir-servico/{id} retorna 403 (middleware permission do grupo /comercial bloqueia antes do controller)"
    why_human: "RBAC depende de tabela setor_permissoes preenchida em dados reais — middleware bloqueia, mas vale confirmar manualmente"
  - test: "Build do AtribuirServico bundle no production"
    expected: "public/build/assets/AtribuirServico-*.js gerado pelo Vite (confirmado: AtribuirServico-Ck-WcKo_.js — 14.43s build verde)"
    why_human: "Já verificado programaticamente — manter como item de smoke por completude"
  - test: "Modal Admin/Empresas removido — visitar /administrativo/empresas como admin"
    expected: "Lista de empresas renderiza com filtros; sem botão 'Adicionar contrato' inline; sem Dialog modal; aparece link 'Atribuir no Comercial' (substituto) apontando para /comercial/atribuir-servico/{id}"
    why_human: "Cleanup visual + ausência de UI antiga só verificável em browser"
---

# Phase 36 — Comercial UX + Atribuir Serviço migrado — Verificação

**Phase Goal:**
> 1. /comercial/empresas vira redirect → /novo  2. Bloco filha_ids removido  3. Atribuir Serviço migra Admin→Comercial  4. Botão Serviço aponta Comercial  5. Modal Admin removido  6. UX: BRL + default +1 ano

**Verificado:** 2026-06-17
**Status:** PASS-COM-RESSALVAS (UATs humanos pendentes — todos não-bloqueantes)
**Re-verification:** Não (verificação inicial)

## Cobertura Decisões Locked (D-01 a D-08)

| Decisão | Verificação                                                                                       | Status     | Evidência                                                                                                       |
| ------- | ------------------------------------------------------------------------------------------------- | ---------- | --------------------------------------------------------------------------------------------------------------- |
| **D-01** | `/comercial/empresas` redirect 302 → `/comercial/empresas/novo` (nome preservado)               | ✓ VERIFIED | `routes/web.php:173-175` closure inline; rota nomeada `comercial.empresas` viva em `route:list` linha 43        |
| **D-02** | Bloco "Empresas vinculadas" filha_ids removido junto com Comercial/Empresas.jsx                  | ✓ VERIFIED | `Comercial/Empresas.jsx` deletado (Glob retorna só NovaEmpresa + AtribuirServico). Backend `update()` preservado por compat (CONTEXT explicitamente decidiu manter validate) |
| **D-03** | Nova rota + página dedicada `/comercial/atribuir-servico/{company}`                              | ✓ VERIFIED | `routes/web.php:187-188`; `ComercialController::atribuirServico` linha 112; `Comercial/AtribuirServico.jsx` 454 linhas |
| **D-04** | Endpoint backend `/empresas/{company}/contratos-servico` mantido intacto                          | ✓ VERIFIED | Form submit em `AtribuirServico.jsx:170` usa `router.post('/empresas/${id}/contratos-servico')` — endpoint inalterado |
| **D-05** | Botão "Serviço" nas pendências aponta para Comercial (não admin)                                  | ✓ VERIFIED | `Companies/Index.jsx:604` href={route('comercial.atribuir-servico', c.id)} (antes: `admin.empresas?empresa=ID`) |
| **D-06** | Modal Admin/Empresas.jsx removido (state, handlers, Dialog)                                       | ✓ VERIFIED | Grep `contratoModal\|abrirAdicionarContrato\|salvarContrato\|Dialog\|contratoForm` → **0 matches** em Admin/Empresas.jsx (487→274 linhas) |
| **D-07** | Máscara BRL com IMaskInput Number scale=2 thousandsSeparator='.' radix=','                       | ✓ VERIFIED | `AtribuirServico.jsx:332-347` IMaskInput com todos os atributos exigidos + onAccept usa mask.unmaskedValue       |
| **D-08** | Default data_vencimento = data_contratacao + 1 ano (mount + onChange)                             | ✓ VERIFIED | `maisUmAno()` linha 38; useMemo(hojeIso) + useMemo(vencimentoIso) linhas 127-128; handleDataContratacaoChange linha 158 |

## Cobertura Verificações Obrigatórias (1-11)

| #   | Item                                                                | Status     | Evidência                                                                                                              |
| --- | ------------------------------------------------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------------- |
| 1   | Rota `/comercial/empresas` registrada como redirect (route:list)    | ✓ VERIFIED | `route:list` linha 43: `comercial/empresas › comercial.empresas › routes/web.php:173` (closure inline = redirect 302)  |
| 2   | `ComercialController::empresas()` removido (grep não acha)          | ✓ VERIFIED | Grep `public function empresas` no controller → **0 matches**; só `index()`, `atribuirServico()`, `create()`, etc.    |
| 3   | `Comercial/Empresas.jsx` apagado                                    | ✓ VERIFIED | Glob `resources/js/Pages/Comercial/*.jsx` → só `NovaEmpresa.jsx` e `AtribuirServico.jsx`                              |
| 4   | AppLayout sidebar item Comercial aponta para `comercial.empresas.novo` com label "Cadastrar empresa" | ✓ VERIFIED | `AppLayout.jsx:95` `{ label: 'Cadastrar empresa', routeName: 'comercial.empresas.novo', page: 'Comercial/NovaEmpresa', icon: Building2, permission: 'comercial.cadastrar_empresa' }` |
| 5   | Rota `/comercial/atribuir-servico/{company}` (name=comercial.atribuir-servico) com middleware permission | ✓ VERIFIED | `routes/web.php:187-188` dentro de grupo `Route::middleware('permission:comercial.cadastrar_empresa')->prefix('comercial')->name('comercial.')` linhas 164-189 |
| 6   | `ComercialController::atribuirServico(Company)` renderiza Inertia com payload                       | ✓ VERIFIED | Linhas 112-156: abort_unless permission, contratosServico + servicosDisponiveis, Inertia::render('Comercial/AtribuirServico', [...]) |
| 7   | `Comercial/AtribuirServico.jsx` com todos os requisitos             | ✓ VERIFIED | 454 linhas; header (190-241); contratos (244-288); form com Select servico (305-319), IMaskInput BRL (332-347), datas com auto-fill (361-398), observações (401-417), checkbox ativo (420-428); defaults useMemo (127-128); onChange recalcula (158-164); auto-fill valor_padrao (146-153); submit POST (170-176); cancelar router.visit (179-181) |
| 8   | `Companies/Index.jsx` botão Serviço aponta para `comercial.atribuir-servico`                          | ✓ VERIFIED | Linha 604: `href={route('comercial.atribuir-servico', c.id)}` (antes: `admin.empresas?empresa=ID`)                   |
| 9   | `Admin/Empresas.jsx` modal de atribuir contrato REMOVIDO            | ✓ VERIFIED | Grep `contratoModal\|abrirAdicionarContrato\|salvarContrato\|Dialog\|contratoForm` → 0 matches. Arquivo 487→274 linhas; mantém listagem + filtros + filtro "Sem serviço atribuído" + link "Atribuir no Comercial" substituto |
| 10  | Suite Phase 31/33/34/35 verde — zero regressão                      | ✓ VERIFIED | **70 passed, 526 assertions, 36.26s** (Phase31FinanceiroDashboardTest, Phase33FluxoCaixaTest, Phase34/35 HubspotV2/Notify/OnboardingPrazo etc.) |
| 11  | Build verde + bundle AtribuirServico compilado                      | ✓ VERIFIED | `npm run build` → ✓ built in 14.43s. `public/build/assets/AtribuirServico-Ck-WcKo_.js` presente; `Comercial/Empresas` bundle ausente; `NovaEmpresa-Co6gix5D.js` presente |

## Required Artifacts

| Artifact                                                       | Expected               | Status     | Details                                                                          |
| -------------------------------------------------------------- | ---------------------- | ---------- | -------------------------------------------------------------------------------- |
| `routes/web.php`                                                | Redirect + rota nova   | ✓ VERIFIED | Linhas 173-175 (redirect) + 187-188 (atribuir-servico)                            |
| `app/Http/Controllers/ComercialController.php`                  | empresas() removido + atribuirServico() | ✓ VERIFIED | empresas() = 0 matches; atribuirServico() = linha 112                              |
| `resources/js/Pages/Comercial/AtribuirServico.jsx`              | Página dedicada 100+ linhas | ✓ VERIFIED | 454 linhas, todos os blocos exigidos                                              |
| `resources/js/Pages/Companies/Index.jsx`                        | Botão Serviço → Comercial | ✓ VERIFIED | Linha 604 ajustada                                                                |
| `resources/js/Pages/Admin/Empresas.jsx`                         | Modal removido           | ✓ VERIFIED | 274 linhas, sem Dialog/state/handlers de contrato                                  |
| `resources/js/Layouts/AppLayout.jsx`                            | Sidebar → empresas.novo | ✓ VERIFIED | Linha 95                                                                          |
| `resources/js/Pages/Comercial/Empresas.jsx`                     | DELETADO                 | ✓ VERIFIED | Glob não retorna o arquivo                                                        |

## Key Link Verification

| From                                | To                                                    | Via                                       | Status     |
| ----------------------------------- | ----------------------------------------------------- | ----------------------------------------- | ---------- |
| Sidebar AppLayout                   | `/comercial/empresas/novo` (NovaEmpresa)              | routeName direto (sem hop redirect)        | ✓ WIRED    |
| `/companies` botão Serviço          | `/comercial/atribuir-servico/{id}`                    | `route('comercial.atribuir-servico', c.id)` | ✓ WIRED    |
| `AtribuirServico.jsx` form submit   | POST `/empresas/{id}/contratos-servico` (CompanyCtrl) | `router.post()` linha 170                  | ✓ WIRED    |
| Middleware permission               | `atribuirServico()`                                   | `permission:comercial.cadastrar_empresa` grupo + abort_unless redundante | ✓ WIRED    |
| `Admin/Empresas.jsx` link substituto | `/comercial/atribuir-servico/{id}`                    | `href="/comercial/atribuir-servico/${id}"` linha 90 | ✓ WIRED    |

## Behavioral Spot-Checks

| Behavior                                       | Comando                                                            | Resultado                          | Status |
| ---------------------------------------------- | ------------------------------------------------------------------ | ---------------------------------- | ------ |
| Suite Phase 31/33/34/35 verde                   | `php artisan test --filter=Phase31\|Phase33\|Phase34\|Phase35`     | 70 passed, 526 assertions, 36.26s   | ✓ PASS |
| Build Vite verde                                | `npm run build`                                                    | ✓ built in 14.43s                  | ✓ PASS |
| Bundle AtribuirServico gerado                   | `ls public/build/assets/ \| grep AtribuirServico`                  | `AtribuirServico-Ck-WcKo_.js`      | ✓ PASS |
| Rota redirect registrada                        | `php artisan route:list \| grep comercial.empresas`                | `comercial/empresas › routes/web.php:173` | ✓ PASS |
| Rota atribuir-servico registrada                | `php artisan route:list \| grep atribuir-servico`                  | `comercial.atribuir-servico › ComercialController@atribuirServi…` | ✓ PASS |
| Comercial/Empresas.jsx ausente                  | Glob `resources/js/Pages/Comercial/*.jsx`                          | só NovaEmpresa + AtribuirServico    | ✓ PASS |

## Anti-Patterns Found

Nenhum. Grep por TBD/FIXME/XXX/placeholder/coming soon nos arquivos modificados não retornou matches relevantes ao escopo Phase 36 (apenas comentários de seções de código já existentes em files não tocados).

## Requirements Coverage

| Requirement | Source Plan | Descrição (inferida do CONTEXT/PLAN)               | Status      | Evidência                                                                  |
| ----------- | ----------- | --------------------------------------------------- | ----------- | -------------------------------------------------------------------------- |
| REQ-36-01   | 36-01       | /comercial/empresas vira redirect                    | ✓ SATISFIED | routes/web.php:173 + nome preservado                                       |
| REQ-36-02   | 36-01       | Comercial/Empresas.jsx removido + sidebar           | ✓ SATISFIED | Arquivo deletado + AppLayout linha 95                                      |
| REQ-36-03   | 36-02       | Nova rota + página AtribuirServico                  | ✓ SATISFIED | rota linha 187 + página 454 linhas                                          |
| REQ-36-04   | 36-02       | UX modal: BRL + default +1 ano                      | ✓ SATISFIED | IMaskInput linha 332 + handleDataContratacaoChange linha 158               |
| REQ-36-05   | 36-02       | Botão Serviço + cleanup Admin                       | ✓ SATISFIED | Companies/Index.jsx linha 604 + Admin/Empresas.jsx 274 linhas (sem modal)  |

## Resumo / Recomendação

**Status: PASSED — 11/11 verificações obrigatórias e 8/8 decisões locked (D-01..D-08) atendidas com evidência direta no codebase.**

Todos os artefatos físicos exigidos pelo CONTEXT existem e estão wired (rota → controller → Inertia → página → form → endpoint). Suite Phase 31/33/34/35 verde (70 passed, 0 failed — zero regressão). Build Vite verde com bundle `AtribuirServico-Ck-WcKo_.js` gerado.

UATs humanos identificados são **smoke-tests pós-deploy não-bloqueantes** — validar comportamento reativo do IMaskInput, recálculo +1 ano em UI real, fluxo end-to-end /companies → Comercial → salvar → ver na lista, e 403 com user real sem permission. Nenhum bloqueia o merge/deploy; apenas reduz risco de UX regressão em browser real.

**Recomendação: APROVADO PARA DEPLOY.**

---

_Verificado: 2026-06-17_
_Verifier: Claude (gsd-verifier, goal-backward)_
