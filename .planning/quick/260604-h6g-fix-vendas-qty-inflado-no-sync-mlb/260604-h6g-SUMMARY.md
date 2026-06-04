---
quick_id: 260604-h6g
slug: fix-vendas-qty-inflado-no-sync-mlb
date: 2026-06-04
status: complete
commit: 2852305
trello: "https://trello.com/c/PBeLV7jq (#35)"
---

# Quick Task 260604-h6g — Resumo

## Causa raiz (confirmada em prod)
`vendas_qty` era gravado como `soldQuantity.value` da janela do sync, via
`GREATEST(COALESCE(vendas_qty,0), qty)`, casando por `mlb_code` global. Janelas largas
(ano/histórico) produziam valores cumulativos; o GREATEST congelava o máximo histórico
(nunca corrigia pra baixo); e `extrairMlbsVendidos` pulava `qty<=0`, então anúncios sem
venda nunca eram zerados. Evidência: BD id 1090 (MLB5826796048) travado em 109 un.
enquanto a API atual retorna soldQuantity.value=0 e status=closed.

## O que foi feito
- **Novo `app/Services/VendasSyncService.php`**: `syncEmpresa(custId, from, to, userId=null)`
  faz fetch + extract + **reset do escopo** (cust_id + `data` na janela [+ user_id]) +
  **apply com valor exato** (sem GREATEST), em transação. `extrairMlbsVendidos` usa `max`.
- **4 caminhos delegam ao service** (sem GREATEST, sem match global):
  - `MlbController::syncVendasPublicador` (com escopo user_id)
  - `MlbController::syncVendasAdman`
  - `SyncTodasVendasAdmanJob::handle` (removidas a extração duplicada + imports mortos)
  - comando `mlb:sync-vendas` (removida extração duplicada; throttle 7s mantido)
- **`Vendas.jsx` (SyncModal)**: removidos atalhos ano/histórico e date pickers livres;
  sincroniza sempre o mês exibido (`mesRef`).

## Semântica nova
`vendas_qty` = vendas do **mês sincronizado** para aquela loja (cust_id). Re-sync corrige
pra baixo (inclusive zera anúncios sem venda). Cada publicação reflete as vendas do mês
em que está datada (a página de Vendas já filtra por mês).

## Arquivos
- `app/Services/VendasSyncService.php` (novo)
- `app/Http/Controllers/MlbController.php`
- `app/Jobs/SyncTodasVendasAdmanJob.php`
- `app/Console/Commands/SyncVendasAdman.php`
- `resources/js/Pages/Mlb/Vendas.jsx`

## Verificação
- `php -l` nos 4 PHPs → OK · `php artisan list` registra `mlb:sync-vendas` · service resolve no container.
- `npm run build` → OK.

## Commit / Deploy
- Código: `2852305` (branch `fix-vendas-qty`, base origin/main — evita clobbar Phases 18/19).
- Deploy: fast-forward em origin/main + `deploy.sh` + re-sync corretivo (`mlb:sync-vendas`).

## Observações
- Limitação do modelo: `vendas_qty` é uma coluna única por publicação; meses passados só
  se corrigem re-sincronizando aquele mês (a página filtra por mês de publicação).
- `MlbController::extrairMlbsVendidos` (privado) ficou sem uso após a delegação — mantido
  por ora (read-only/baixo risco); pode ser removido num cleanup futuro.
