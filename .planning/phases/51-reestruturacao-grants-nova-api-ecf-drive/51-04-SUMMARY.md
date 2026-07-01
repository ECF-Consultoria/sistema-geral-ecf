---
plan: 51-04
status: complete
type: human-uat
completed_at: 2026-07-01
---

# Plan 51-04 — SUMMARY

UAT humano em produção APROVADO em 2026-07-01 após 3 rodadas de correção. Detalhes em [51-04-UAT.md](51-04-UAT.md).

## Phase 51 fechada

- Plan 51-01: ✅ migration 8 campos + fillable + mapToDb (12/12 testes verdes)
- Plan 51-02: ✅ EcfDriveService.grantsResumo/grantsDistribuicao + controller com fallback + universo no_grant (22/22 testes verdes)
- Plan 51-03: ✅ UI 5 buckets + Divergência + badge offline + 4 colunas opcionais (build verde)
- Plan 51-04: ✅ UAT APROVADO após 3 rodadas de correção

## Correções pós-UAT (todas na Wave 4)

1. **`fecbe72`** — Mapeamento shape real do payload (era aninhado; API é flat) + labels de cards clareados + linha 5 buckets removida + "Empresas totais: 125" removido
2. **`f856031`** — Universo "sem grant" expandido: UNION Company + MlbEmpresa por cust_id, match via ml_cust_id, dedup por cust_id (152 assertions)
3. **`d6526b1`** — Tooltips explicativos pt-BR em cada card (via `title` + `cursor-help`)
4. **`112341a`** — Tabela `overflow-x-auto` + `min-w-[92rem]` para não cortar em viewports estreitos

## Insights operacionais capturados

- **API real diverge do CONTEXT presumido:** payload é flat, não aninhado. Sempre inspecionar payload real via SSH tinker antes de mapear.
- **Company e MlbEmpresa são universos praticamente disjuntos:** só 1 mlb_empresa tem company_id linkado (de 202). Match entre eles é sempre por cust_id.
- **Grants ML = 396 vs Local = 61:** sync `grants:sync-ecf` (03:00 BRT) pode não estar cobrindo o universo completo. TODO fora do escopo da Phase 51.
- **CompanyGrant.company_id é NOT NULL** — grants sempre precisam de Company associada. MlbEmpresa "com grant" implica que existe Company com mesmo cust_id.

## Próximas phases v12.0

- Phase 47 (scoring por função) — depende só de Phase 44 agora (46 destravou)
- Phase 50 (gamificação OAuth ML) — independente
- Phase 44 — pausada por checkpoint humano (acesso DevCenter ML)
