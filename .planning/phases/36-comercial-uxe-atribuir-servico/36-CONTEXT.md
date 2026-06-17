# Phase 36: Comercial UX + Atribuir Serviço migrado

**Gathered:** 2026-06-17
**Status:** Ready for execution (lean — sem discuss/research/plan-check)
**Source:** Pedido direto do usuário pós-uso real Phase 35.

<domain>
## Phase Boundary

### O que esta fase entrega

1. **Simplifica `/comercial/empresas`** — hoje mistura listagem + edit form. Vira só o cadastro (já que listagem está em `/companies` e `/mlb/empresas`). Remove o bloco "Empresas vinculadas" (filha_ids) — vinculação de grupo é pós-cadastro.

2. **Função "Atribuir Serviço" migra do Admin pro Comercial** — quem sabe o que cliente fechou é o time Comercial. UI do modal é movida de `/administrativo/empresas` (Admin/Empresas.jsx) pra uma página dedicada do Comercial. Botão "Serviço" nas pendências de `/companies` redireciona pra essa nova página, JÁ com a empresa pré-selecionada e modal aberto (não pra listagem genérica).

3. **UX do modal "Atribuir Contrato"**:
   - Campo `valor_contratado` ganha máscara BRL (`R$ 0,00` formatado durante digitação)
   - `data_vencimento` default = `data_contratacao + 1 ano` (auto-preenche ao escolher início)

### Estado atual investigado (2026-06-17)

- `/comercial/empresas` (ComercialController::empresas) — listagem completa (todas empresas active) + modal de edit com bloco "Empresas vinculadas" (filha_ids). Redundante com `/companies`.
- `/comercial/empresas/novo` (ComercialController::create) — form de cadastro (wizard 2 passos com close fields da Phase 34). NÃO tem campo grupo. Mantém intacto.
- `/administrativo/empresas` (AdminController::empresas) — listagem de empresas com modal "Atribuir contrato" (Admin/Empresas.jsx linhas 178-368). Modal hoje: `servico_id`, `valor_contratado` (input number cru), `data_contratacao` (default today), `data_vencimento` (vazio), `observacoes`, `ativo`.
- `Companies/Index.jsx` linha 600 — botão "Serviço" na pendência `sem_servico` redireciona pra `route('admin.empresas')?empresa={id}`. Cai na listagem genérica.
- Endpoint backend dos contratos: `/empresas/{company}/contratos-servico` (POST/PUT) — provavelmente em CompanyController ou rota dedicada. Permanece — só muda quem consome.

### Permissão do Comercial

- `ComercialController` usa `permission:comercial.cadastrar_empresa` + admin short-circuit. A função "Atribuir Serviço" terá a mesma permission (faz sentido: quem cadastra empresa também atribui serviço).

</domain>

<decisions>
## Implementation Decisions

### Listagem de `/comercial/empresas` (D-01)

**D-01 — Redirect 302 pra `/comercial/empresas/novo` (LOCKED).**

`ComercialController::empresas()` vira redirect pra `comercial.empresas.novo`. Mantém a rota nomeada `comercial.empresas` viva pra não quebrar links existentes (sidebar, bookmarks). Remove logic de query + render Inertia + servicos_disponiveis carregamento.

`Comercial/Empresas.jsx` — apaga arquivo. Não usado mais.

Sidebar `AppLayout.jsx` — item "Empresas" do Comercial passa a apontar pra `comercial.empresas.novo` (se hoje aponta pra `comercial.empresas`, ajustar).

### Bloco "Empresas vinculadas" filha_ids (D-02)

**D-02 — Deletado junto com Comercial/Empresas.jsx (LOCKED).**

Como Plan 36-01 apaga `Comercial/Empresas.jsx`, o bloco filha_ids some junto. Backend (`ComercialController::update`) mantém aceitando `filha_ids` por compat com modal Admin/Companies/Index.jsx que pode usar mesmo endpoint — verificar; se não, simplifica também o validate.

A gestão de grupos vinculados continua via `/companies` (admin) e `GruposManager` componente.

### Página "Atribuir Serviço" no Comercial (D-03)

**D-03 — Nova rota + nova página (LOCKED).**

- Rota: `GET /comercial/atribuir-servico/{company}` → `ComercialController::atribuirServico(Company $company)`. Permission: `comercial.cadastrar_empresa` ou admin.
- Página: `resources/js/Pages/Comercial/AtribuirServico.jsx` — recebe `company`, `contratos`, `servicos_disponiveis`. Mostra:
  - Header: nome empresa + cust_id + nicho/segmento (info contextual)
  - Lista de contratos ativos da empresa (read-only) com badges
  - Form de NOVO contrato (default data_contratacao=today, data_vencimento=+1 ano, servico_id placeholder, valor com máscara BRL)
  - Botão "Salvar e voltar" → POST `/empresas/{id}/contratos-servico` → redirect pra `/companies` ou ficar na página
- Sem modal em outras páginas — usuário acessa diretamente esta rota.

### Endpoint POST/PUT contratos-servico (D-04)

**D-04 — Manter inalterado (LOCKED).**

Endpoints `/empresas/{company}/contratos-servico` (POST e PUT/{contrato}) já existem. Página Comercial usa mesma rota. Backend não precisa mudar.

### Botão "Serviço" das pendências (D-05)

**D-05 — Apontar pra rota nova do Comercial (LOCKED).**

`Companies/Index.jsx` linha 600:
```jsx
// ANTES:
href={`${route('admin.empresas')}?empresa=${c.id}`}
// DEPOIS:
href={route('comercial.atribuir-servico', c.id)}
```

E remove o ícone "Atribuir contrato" do `Admin/Empresas.jsx` se quisermos limpeza completa (D-06).

### Limpeza no Admin (D-06)

**D-06 — Remover modal de atribuir contrato do Admin/Empresas.jsx (LOCKED).**

- Remove o state `contratoModal`, helpers `abrirAdicionarContrato`, `abrirEditarContrato`, `salvarContrato`, `escolherServico`
- Remove o Dialog do contrato (linhas ~368 em diante)
- Remove os botões inline de "Adicionar contrato" / "Editar" no card da empresa
- Admin/Empresas.jsx ainda mostra listagem + filtros, só perde a ação de atribuir contrato (admin acessa via `/comercial/atribuir-servico/{id}` se precisar)

### UX do modal: máscara BRL (D-07)

**D-07 — IMaskInput Number BRL (LOCKED).**

Padrão react-imask com mask `Number`:
```jsx
<IMaskInput
  mask={Number}
  scale={2}
  thousandsSeparator="."
  radix=","
  mapToRadix={['.']}
  padFractionalZeros
  normalizeZeros
  value={String(form.valor_contratado || '')}
  onAccept={(v, mask) => setForm({ ...form, valor_contratado: mask.unmaskedValue })}
  placeholder="R$ 0,00"
  className="..."
/>
```

`onAccept` recebe value mascarado e a instância da mask → use `mask.unmaskedValue` pra pegar o número puro (`1234.56`) que vai no submit. Display: `R$ 1.234,56`.

### UX do modal: default data_vencimento +1 ano (D-08)

**D-08 — Auto-fill no onChange de data_contratacao (LOCKED).**

```jsx
function handleDataContratacaoChange(novaData) {
  const vencimento = novaData
    ? new Date(novaData).setFullYear(new Date(novaData).getFullYear() + 1)
    : null;
  const vencimentoIso = vencimento ? new Date(vencimento).toISOString().slice(0, 10) : '';
  setForm({ ...form, data_contratacao: novaData, data_vencimento: vencimentoIso });
}
```

Usuário pode sobrescrever `data_vencimento` manualmente — auto-fill só acontece quando data_contratacao muda. Default inicial ao abrir modal: hoje + 1 ano (preenche os 2 campos).

### Claude's Discretion

- Layout da página Comercial/AtribuirServico.jsx — pode ser uma tela full (não modal) com 2 colunas (info empresa esquerda, form direita) ou stack mobile-friendly
- Comportamento pós-submit — `router.visit('/companies')` ou ficar na mesma página com flash
- Editar contrato existente nesta página — pode ou não, fica a critério. Acho que pode (caso usuário precise corrigir valor/data)

</decisions>

<canonical_refs>
## Canonical References

### Comercial atual
- `app/Http/Controllers/ComercialController.php::empresas` linhas 69-138 — remove
- `app/Http/Controllers/ComercialController.php::update` — pode simplificar (sem filha_ids)
- `resources/js/Pages/Comercial/Empresas.jsx` — apagar
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` — INTACTO
- `routes/web.php` rota `comercial.empresas` — vira redirect

### Admin atual (a remover modal)
- `app/Http/Controllers/AdminController.php::empresas` — fica
- `resources/js/Pages/Admin/Empresas.jsx` linhas 178-368 — remove modal de contrato

### Endpoint contratos-servico (backend)
- Buscar onde está hoje (`/empresas/{company}/contratos-servico` POST/PUT). Provavelmente `CompanyController` ou `ContratoServicoController`. Permanece.

### Companies/Index.jsx
- Linha 600 — ajustar href

### react-imask
- Já instalado (Phase 34) — usar `IMaskInput`

</canonical_refs>

<specifics>
## Specific Ideas

- **Backend redirect**: `Route::get('/comercial/empresas', fn() => redirect()->route('comercial.empresas.novo'))->name('comercial.empresas');` direto na rota — não precisa de método controller.
- **Sidebar**: o item "Empresas" do Comercial provavelmente aponta pra `route('comercial.empresas')`. Vira "Cadastrar empresa" apontando pra `comercial.empresas.novo` (label opcional).
- **AtribuirServico permission**: dentro do controller, mesmo padrão `permission:comercial.cadastrar_empresa` ou admin short-circuit.
- **Test coverage**: 3-4 cases — `test_pagina_atribuir_servico_renderiza_com_company`, `test_pagina_403_para_quem_nao_tem_permissao`, `test_redirect_/comercial/empresas_vai_pra_novo`, `test_modal_data_vencimento_default_1_ano_via_BACKEND_se_aplicavel`.

</specifics>

<deferred>
## Deferred Ideas

- Reescrever endpoint contratos-servico (provavelmente nem precisa)
- Toggle "Editar contrato existente" na página Comercial/AtribuirServico (planner decide)
- Histórico de contratos (já feito? verificar — fora desta phase)

</deferred>

---

*Phase: 36-comercial-uxe-atribuir-servico*
*Context gathered: 2026-06-17*
