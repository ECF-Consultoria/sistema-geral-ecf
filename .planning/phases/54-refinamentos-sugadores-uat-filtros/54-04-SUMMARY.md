---
plan: 54-04
status: complete
type: human-uat
completed_at: 2026-07-02
---

# Plan 54-04 — SUMMARY

UAT humano APROVADO em 2026-07-02 após 1 rodada de correção. Detalhes em [54-04-UAT.md](54-04-UAT.md).

## Phase 54 fechada

- Plan 54-01 (Wave 1): ✅ backend filtros — 11/11 testes verdes
- Plan 54-02 (Wave 2): ✅ UI EmpresaListagem — layout 2 colunas + filtro período + click row
- Plan 54-03 (Wave 3): ✅ UI Show.jsx (-172 linhas) + Index.jsx (+51 busca/filtro)
- Plan 54-04 (Wave 4): ✅ UAT APROVADO após fix `932037c`

## Correção pós-UAT

Commit `932037c` — filtro analista consulta cargo (não pivot role).
- Root cause: `company_users.role='analista'` não existe em prod. "Analista" é cargo (`user_setores → cargos`).
- Fix: lista via cargos; filtro via role IN ('consultor', 'estrategista').
- Memory `project_atribuicao_profissionais.md` já documentava esse pattern.

## Descoberta operacional

Sistema tem **2 taxonomias distintas** para "analista":
1. **Cargo** (`user_setores → cargos.slug`) — nomenclatura de Performance/RH. Analistas de Performance têm cargo `analista`.
2. **Role na atribuição** (`company_users.role`) — só 2 valores: `consultor` e `estrategista`. Analistas por cargo ficam como `consultor` na pivot.

Toda feature que quer identificar "analista" precisa checar CARGO, não role. Toda feature que quer identificar "empresas do analista X" precisa checar `company_users.role='consultor' AND user_id=X`.

## Débito técnico registrado

- `ConfigPickerModal` órfão em `Index.jsx` (state e render preservados, trigger removido)
- Bug latente: `<Link>` sem import no modal (dormente enquanto órfão)
- Props `sugador_config` + `can_manage_config` continuam sendo enviadas por `SugadorController::show` (backend cleanup vira seed)

## Próximas phases

- **Phase 53** (Inteligência do detector) — 3 casos falso-positivo, independente
- **Phase 55** (Modernização visual + Magic UI) — depende de Phase 54 (agora destravada)
