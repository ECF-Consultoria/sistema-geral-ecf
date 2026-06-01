---
quick_id: 260601-ml3
slug: cutover-adman-ml
status: complete
completed: 2026-06-01
commit: c85b86f
---

# Summary — Cutover Adman → ML por empresa (Opção A)

## Problema
Vincular o Mercado Livre numa empresa já conectada na Adman a jogava num
estado híbrido quebrado: o `cust_id` (`ml_store_id ?: adman_account_id`)
passava a ser o Seller ID do ML, que a Adman não reconhece → KPIs da página
viravam "—", sugadores parava, e os dois `:sync` gravavam a mesma linha
`adman_metrics` (ML sobrescrevia a Adman → linhas mistas).

## Decisão (escolhida pelo usuário)
**Opção A** — "token ML ativo → ML assume". Empresa com `ml_token.status =
active` é **ML-driven**: usa o caminho ML e o sistema **para de chamar a Adman**
para ela. Sem token ativo, comportamento Adman inalterado. Reversível.

Dashboard: **refino adiado** (já funciona via modo banco; o ganho real vem no
fim da migração ao remover o caminho de cache da Adman).

## Mudanças
| Arquivo | Mudança |
|---------|---------|
| `Company.php` | accessor `is_ml_driven` (token ML status active) |
| `SyncAdmanData.php` | `adman:sync` exclui ML-driven (`whereDoesntHave('mlToken', active)`) — fim do conflito de escrita |
| `CompanyController::show` | roteia por `is_ml_driven`: ML-driven agrega `adman_metrics`; senão API Adman. `ml_metrics` idem |
| `Show.jsx` | `isMlDriven = ml_token?.status === 'active'` (hint de Margem) |
| `SugadorAnalysisService:96` | `adman_account_id ?: ml_store_id` (chamada é à Adman → ID Adman primeiro) |

## Verificação
- `php -l` ok nos 4 PHP; `npm run build` ✓.
- Empresas ML-only existentes (sem adman_account_id) seguem no caminho ML
  (comportamento idêntico). A mudança de roteamento só afeta **empresas duais**
  (adman_account_id + token ML ativo), que antes quebravam.

## Processo de migração (por empresa)
1. Vincular o ML (gera token ativo → vira ML-driven automaticamente).
2. Backfill 30d: `php artisan ml:sync --company=ID --from=… --to=…` (sobrescreve
   linhas Adman antigas com dados ML, p/ a página mostrar histórico limpo).
3. `adman:sync` deixa de processá-la sozinho (sem conflito).
4. Reversível: desvincular ML → volta pra Adman.

## Follow-up
- Refino do dashboard (separar Adman-cache vs ML-DB por empresa; ACOS por
  empresa para ML) — fazer no fim da migração, junto com a remoção do caminho
  Adman.
- Detecção de sugadores via ML (hoje Adman-only).
