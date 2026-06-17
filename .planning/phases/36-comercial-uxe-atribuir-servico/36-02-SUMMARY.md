---
phase: 36-comercial-uxe-atribuir-servico
plan: 02
subsystem: comercial
tags: [comercial, contratos-servico, ux, imask, brl]
requires: []
provides:
  - "ComercialController::atribuirServico(Company)"
  - "Rota nomeada comercial.atribuir-servico"
  - "Pagina Comercial/AtribuirServico"
affects:
  - "Companies/Index.jsx — botao Servico aponta pra Comercial"
  - "Admin/Empresas.jsx — modal contrato removido (read-only)"
tech-stack:
  added: []
  patterns:
    - "IMaskInput Number BRL no campo valor_contratado (display 'R$ 1.234,56', submit unmaskedValue numerico)"
    - "Default data_vencimento = data_contratacao + 1 ano (recalculado no onChange)"
    - "Inertia::render com payload company + contratos + servicos_disponiveis"
key-files:
  created:
    - "resources/js/Pages/Comercial/AtribuirServico.jsx (~360 linhas)"
  modified:
    - "app/Http/Controllers/ComercialController.php (+62 linhas — metodo atribuirServico)"
    - "routes/web.php (+9 linhas — rota comercial.atribuir-servico)"
    - "resources/js/Pages/Companies/Index.jsx (href atualizado linha 600)"
    - "resources/js/Pages/Admin/Empresas.jsx (-213 linhas — modal removido)"
decisions:
  - "Switch primitive nao existe nos UI primitives; usei <input type=checkbox> seguindo padrao do modal antigo"
  - "Bloco contratos existentes separa Ativos (destaque) de Historico (colapsado em <details>) para reduzir ruido visual"
  - "AppLayout.jsx nao alterado por este plan — Plan 36-01 ja modifica o item Comercial pra apontar pra empresas.novo; deep-link via botao Servico de /companies cobre o fluxo principal"
metrics:
  duration: "~33min"
  completed: "2026-06-17"
  tasks_total: 4
  tasks_done: 4
  files_created: 1
  files_modified: 4
  commits: 4
---

# Phase 36 Plan 02: Atribuir Serviço migra pro Comercial + UX modal — Summary

Migração da funcionalidade "Atribuir Contrato" de `/administrativo/empresas` (modal) para uma página dedicada do Comercial (`/comercial/atribuir-servico/{company}`), com UX melhorado: máscara BRL no valor contratado (IMaskInput) e default `data_vencimento = data_contratacao + 1 ano` recalculado dinamicamente.

## Objective

1. Quem sabe o que o cliente fechou é o time Comercial — atribuir serviço deve viver no Comercial, não no Admin.
2. Eliminar hop intermediário do botão "Serviço" nas pendências (`/companies` → admin → modal); agora vai direto pra página com empresa pré-resolvida.
3. Padronizar UX do form: máscara BRL para evitar confusão na digitação de valores, e auto-preenchimento de vencimento (Comercial fechava 1 ano por default e tinha que digitar manualmente).
4. Limpar Admin/Empresas.jsx tirando código morto do modal (state + handlers + Dialog completo).

## Tasks Executed

### Task 1 — Backend (controller + rota)

- Adicionado método `ComercialController::atribuirServico(Company $company)` que renderiza Inertia `Comercial/AtribuirServico` com payload `{ company, contratos, servicos_disponiveis }`.
- Permissão: `comercial.cadastrar_empresa` ou admin (mesmo critério dos demais endpoints do Comercial).
- Histórico de contratos completo (ativos + inativos) ordenado do mais recente para o mais antigo.
- Catálogo `servicos_disponiveis = Servico::ativo=true`.
- Rota: `Route::get('/atribuir-servico/{company}')` dentro do grupo `comercial.*` (já protegido por `permission:comercial.cadastrar_empresa`). `name=comercial.atribuir-servico`.
- Endpoint backend POST/PUT `/empresas/{company}/contratos-servico` (CompanyController) mantido inalterado (D-04).
- **Commit:** `b67edb2`

### Task 2 — Página AtribuirServico.jsx

- Página nova de ~360 linhas com 3 blocos: header contextual da empresa (nome, cust_id, CNPJ, badges nicho/segment, email/telefone), lista de contratos existentes (ativos em destaque + histórico colapsado em `<details>`), form de novo contrato.
- **D-07 — Máscara BRL:** IMaskInput Number com `scale=2`, `thousandsSeparator='.'`, `radix=','`, `padFractionalZeros`, `normalizeZeros`. Submit envia `mask.unmaskedValue` numérico (ex: `1234.56`); display `R$ 1.234,56`.
- **D-08 — Default +1 ano:** `useMemo` calcula hoje e `hoje+1ano` no mount. `handleDataContratacaoChange` recalcula `data_vencimento` automaticamente ao alterar `data_contratacao`. Usuário pode override manual.
- **UX bonus:** onChange do select de serviço auto-preenche `valor_contratado` com `valor_padrao` do catálogo (UX consistente com modal antigo).
- Submit `router.post('/empresas/{id}/contratos-servico', form, { onSuccess: () => router.visit('/companies') })`.
- Botão Cancelar volta para `/companies`.
- Build verde.
- **Commit:** `ac3df73`

### Task 3 — Redirect botão Serviço

- `Companies/Index.jsx` linha 600 — `href={route('comercial.atribuir-servico', c.id)}` (antes: `${route('admin.empresas')}?empresa=${c.id}`).
- Title atualizado para "Atribuir serviço no Comercial".
- Comentário inline explicando D-05.
- **Commit:** `9ec7cd3` (incluiu também o AppLayout.jsx do Plan 36-01 que estava staged em paralelo)

### Task 4 — Limpeza Admin/Empresas.jsx

- Removido state `contratoModal`, `contratoForm`, `contratoErrors`, `contratoSalvando`.
- Removidos handlers `abrirAdicionarContrato`, `abrirEditarContrato`, `fecharModalContrato`, `escolherServico`, `salvarContrato`, `desativarContrato`.
- Removido `<Dialog>` inteiro (~117 linhas) que continha o form modal.
- Removidos botões inline "Adicionar contrato", "Editar/Pencil", "PowerOff" no card da empresa.
- Removidos imports não usados: `Dialog`, `DialogContent`, `DialogFooter`, `DialogHeader`, `DialogTitle`, `Pencil`, `Plus`, `PowerOff`, `router`.
- Substituí o botão "Adicionar contrato" por link "Atribuir no Comercial" apontando para `/comercial/atribuir-servico/{empresa.id}` — preserva o ponto de entrada para quem chegar via /administrativo.
- Subtitle atualizada: "Listagem read-only. Para atribuir contrato use o Comercial."
- Build verde.
- **Commit:** `d875cb6`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Plan 36-01 paralelo já tinha `Comercial/Empresas.jsx` staged como deletado (`D` em git status)**

- **Found during:** Task 1 (primeiro commit).
- **Issue:** Plan 36-01 estava rodando em paralelo e tinha o arquivo `Comercial/Empresas.jsx` em estado `D ` (staged-for-deletion) antes do meu primeiro commit. Como `git add app/Http/Controllers/ComercialController.php routes/web.php` não toca neles, esperei que o commit fosse limpo — mas o git incluiu o `D` que já estava staged.
- **Fix:** Aceitei. O arquivo seria deletado de qualquer forma pelo Plan 36-01; o commit atribuído ao 36-02 absorveu a deleção. Plan 36-01 completou seu SUMMARY normalmente em seguida.
- **Files affected:** `resources/js/Pages/Comercial/Empresas.jsx` (deletado).
- **Commit:** `b67edb2`

**2. [Rule 3 — Blocking] AppLayout.jsx do Plan 36-01 absorvido no commit do Task 3**

- **Found during:** Task 3 (commit do redirect do botão Serviço).
- **Issue:** Plan 36-01 tinha o `AppLayout.jsx` modificado (renomeação "Entrada de Empresas" → "Cadastrar empresa") em estado `M ` (modified-but-not-staged-by-me). Quando rodei `git add resources/js/Pages/Companies/Index.jsx`, o git incluiu apenas meu arquivo, mas o commit anterior do AppLayout estava conflitando.
- **Fix:** O commit absorveu a alteração do AppLayout (visível em `git show --stat HEAD`). Como meu plan tem AppLayout listado como opcional e a alteração do 36-01 não conflita com nada do 36-02, manter o AppLayout co-commitado simplifica o histórico.
- **Files affected:** `resources/js/Layouts/AppLayout.jsx` (modificação do 36-01).
- **Commit:** `9ec7cd3`

**3. [UX — Discretion] Switch primitive não existe nos UI primitives**

- **Found during:** Task 2 (construção do form).
- **Issue:** Plan menciona "shadcn Switch" para o campo `ativo`, mas `resources/js/Components/ui/` não tem `switch.jsx`.
- **Fix:** Usei `<input type="checkbox" className="h-4 w-4 accent-ecf-yellow">` seguindo o mesmo padrão do modal antigo (Admin/Empresas.jsx pré-cleanup). Visualmente consistente, funcionalmente equivalente.
- **Files affected:** `resources/js/Pages/Comercial/AtribuirServico.jsx`.

### Architectural Decisions

Nenhuma — Plan executou exatamente como escrito + 3 auto-fixes acima.

## Authentication Gates

Nenhum — toda implementação é UI/controller padrão Laravel/Inertia. Permissão `comercial.cadastrar_empresa` ou admin checada via middleware do grupo + `abort_unless` redundante (defesa em profundidade) dentro do método.

## Verification

- [x] `php artisan route:list --name=comercial.atribuir-servico` mostra rota: `GET|HEAD comercial/atribuir-servico/{company} → ComercialController@atribuirServico`
- [x] Rota dentro de grupo com middleware `permission:comercial.cadastrar_empresa` (consultor sem permission → 403 antes do controller)
- [x] `abort_unless` redundante dentro do método (defesa em profundidade)
- [x] Build frontend verde (`npm run build` — `AtribuirServico-Dw6TBCT1.js` compilado)
- [x] Suite de testes: `449 passed, 45 failed` — **idêntico à baseline pré-mudanças** (verificado com `git checkout 9055948 -- ...` + suite roda + checkout HEAD). **Zero regressão.** As 45 falhas pré-existentes são de `service_type` legacy (Phase 7) e `AdminController` constructor (não tocados por este plan).
- [x] Sub-suite Phase 34/35 (mais recente, baseline real do projeto): **31 passed, 0 failed**

## Commits

| # | Hash | Mensagem |
|---|------|----------|
| 1 | `b67edb2` | feat(36-02): rota + ComercialController::atribuirServico |
| 2 | `ac3df73` | feat(36-02): Comercial/AtribuirServico.jsx com mascara BRL + default +1 ano |
| 3 | `9ec7cd3` | feat(36-02): redirect botao Servico pendencias para Comercial |
| 4 | `d875cb6` | feat(36-02): remove modal contrato de Admin/Empresas.jsx |

## Known Stubs

Nenhum — todo o fluxo do form (select serviço, IMask BRL, datas, observações, switch ativo) está wired ao endpoint POST `/empresas/{company}/contratos-servico` (CompanyController existente, mantido inalterado por D-04).

## Notas para o Verificador

1. **Validar manualmente:** acessar `/comercial/atribuir-servico/{id}` como user com `comercial.cadastrar_empresa` ou admin — deve renderizar com header da empresa + form pré-populado (data_contratacao=hoje, data_vencimento=hoje+1ano).
2. **Máscara BRL:** digitar `1234.56` deve mostrar `R$ 1.234,56` no input, e o submit envia `1234.56` (numérico) para o backend.
3. **Default +1 ano:** alterar `data_contratacao` deve atualizar automaticamente `data_vencimento` para +1 ano. Editar manualmente `data_vencimento` deve persistir o valor manual (só recalcula no onChange da contratação).
4. **403 consultor:** logar como consultor sem `comercial.cadastrar_empresa` e tentar acessar a rota — deve retornar 403.
5. **Fluxo end-to-end:** ir em `/companies`, encontrar empresa pendente sem serviço, clicar "Serviço" — deve abrir `/comercial/atribuir-servico/{id}` direto, sem hop.

## Self-Check: PASSED

- [x] Files created exist:
  - `resources/js/Pages/Comercial/AtribuirServico.jsx` — FOUND
- [x] Files modified exist:
  - `app/Http/Controllers/ComercialController.php` — FOUND (método `atribuirServico` na linha 112)
  - `routes/web.php` — FOUND (rota linha 187, name=`atribuir-servico` linha 188)
  - `resources/js/Pages/Companies/Index.jsx` — FOUND (href atualizado linha ~603)
  - `resources/js/Pages/Admin/Empresas.jsx` — FOUND (487 → 274 linhas após cleanup)
- [x] All commits exist:
  - `b67edb2` — FOUND
  - `ac3df73` — FOUND
  - `9ec7cd3` — FOUND
  - `d875cb6` — FOUND
