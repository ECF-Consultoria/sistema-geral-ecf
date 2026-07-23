# 109-04 — Checkpoint de validação humana (SUMMARY)

**Plano:** 109-04 (checkpoint, autonomous:false)
**Concluído:** 2026-07-23
**Resultado:** ✅ VALIDADO pelo usuário (deploy em produção + números reais confirmados)

## O que foi validado

Deploy da Fase 109 em produção (https://admin.ecfconsultoria.com.br) via `deploy.sh` — push→pull→build→migrate (nothing to migrate)→cache→restart workers. Caches aquecidos (`shopee:warm-diff` 26 empresas/52 OK; `desempenho:warm-cache` 24 OK).

### Validação numérica real (competência 2026-06 "Bônus atual")

Só existem 3 responsáveis com carteira Shopee. Todos com `score_status='official'` (Shopee deixou de bloquear — objetivo central):

| Responsável | Carteira | Nota final | Observação |
|---|---|---|---|
| Gustavo (16) | 11 Shopee + 19 perf | 2,78 | margem real (ML) preservada; placeholder dilui proporcionalmente |
| Felipe (21) | 25 Shopee + 4 perf | 3,79 | Shopee-pesado; margem componente ~1,4 (placeholder domina) |
| Matheus (28) | 14 Shopee (só-Shopee) | 3,63 | após backfill de maio |

**Impacto do placeholder margem=1 (isolando a dimensão), medido em prod:** Gustavo −0,61 (tinha margem ML ótima, diluída), Felipe −0,30 (margem ML já fraca), Matheus (só-Shopee) piso conservador. Regressão-zero confirmada: 9 profissionais sem Shopee com nota intocada estruturalmente.

### Correção de dados durante o checkpoint (Matheus)

Matheus (só-Shopee) vinha com `faturamento=null` e `status=partial` porque a integração Shopee começou 01/06 → sem baseline de maio para o "Bônus atual" de junho. Backfill de maio via `shopee:sync --from=2026-05-01 --to=2026-05-31` nas 6 lojas conectadas (API Shopee devolve histórico de maio) → faturamento passou a computar (régua 5,0), nota **2,95 (partial) → 3,63 (official)**. Cache bustado (`cache:clear`) + re-warm.

## Achado SEPARADO (fora do escopo 109 → vira /gsd:debug)

Durante a validação, o `cache:clear` + recompute fresco expôs que a **margem % do `.diff` nativo da Adman é instável** no modo bônus: mesmo mês fechado (junho) retorna diffs materialmente diferentes a cada recompute (média do Luiz +6,83 → −3,25 → +8,63 em minutos; swings de empresa de ±40–77%). Causa provável: lag de assentamento da margem/CMV de junho na Adman ([[project_adman_margem_lag_janela]], [[project_adman_diff_janela_gate]]). **NÃO é regressão da Fase 109** (Shopee não toca o caminho Adman; regressão-zero provada em teste). Aberto como investigação dedicada (/gsd:debug). Impacto no bônus a determinar (depende de o bônus ler snapshot congelado vs cálculo ao vivo).

## Requisitos fechados
SHOP-CAR-02, SHOP-DES-02 (validação visual + numérica). Fase 109 completa nos 4 planos.
