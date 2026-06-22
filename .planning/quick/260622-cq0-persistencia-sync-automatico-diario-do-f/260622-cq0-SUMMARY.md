---
quick_id: 260622-cq0
status: complete
date: 2026-06-22
branch: deploy/precificacao-onboarding
---

# Quick 260622-cq0 — Persistência durável + sync diário do Faturamento por Polo (/polos)

## Problema

Na VPS, o **Faturamento por Polo** zerava (R$0) depois de um tempo, obrigando a
clicar "Sincronizar" à mão e gerando espera até recarregar.

**Causa raiz (confirmada no código):**
1. O `/polos` lê faturamento/ADS do mês corrente **só do cache da Adman**, cuja chave
   inclui `cacheDay()` (data BRT de hoje). À meia-noite a chave rotaciona → cache do
   novo dia vazio → `getCached*Many` devolve `hasEntry=false` → R$0 para todas as
   empresas. Em produção o cache é Redis (`REDIS_CACHE_DB=2`): flush/restart também zera.
2. **Não havia agendamento** que aquecesse a janela mensal dos polos — só o botão manual.

## Solução (backend-only)

| # | Mudança | Arquivos | Commit |
|---|---------|----------|--------|
| 1 | Tabela durável `polos_faturamento_snapshots` + model + gravação no Job (anti-clobber) | migration, `app/Models/PoloFaturamentoSnapshot.php`, `app/Jobs/SyncPolosFaturamentoJob.php` | `a75b9dc` |
| 2 | Fallback no controller (mês corrente) quando o cache do dia está frio + agendamento diário 13:00 BRT | `app/Http/Controllers/PolosController.php`, `routes/console.php` | `bc6b886` |
| 3 | Testes (persistência + fallback) | `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` | `e9792e8` |

**Como resolve os 4 comportamentos esperados:**
- **Persistência mesmo após restart/inatividade** → snapshot em tabela (sobrevive a
  flush/restart do Redis e à rotação da chave de cacheDay).
- **Exibe os últimos dados, sem zeros** → `faturamentoAdmanDoMes`/`adsAdmanDoMes` caem
  no snapshot para os `cust_ids` sem cache do dia (cache do dia continua preferencial).
- **Sync automático diário** → `Schedule::job(SyncPolosFaturamentoJob)->dailyAt('13:00')`
  (`sync-polos-faturamento-d1`), no fim da cascata D-1.
- **UI atualiza sozinha após o sync** → `/polos` renderiza server-side; a próxima visita
  já lê os valores frescos (cache + snapshot), sem "Sincronizar" manual.

## Verificação

- `php -l` limpo nos 5 arquivos PHP.
- `php artisan schedule:list` lista `sync-polos-faturamento-d1` (`0 13 * * *`).
- `php artisan test tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` → **4 passed (42 assertions)**.
- **Zero regressão nova**: `Phase38/PolosControllerTest` continua com **6 falhas
  PRÉ-EXISTENTES** (idênticas contra o controller pré-mudança `394a747`). Causa das
  falhas é independente: fixtures marcam o mês `FECHADO` sem `MESES_NO_PROGRAMA`, e a
  reconstrução de roster por CSV (adicionada em trabalho anterior do /polos) devolve
  roster vazio → `statusDist` zerado. Não tratado aqui (fora do escopo).
- Backend-only: nada em `resources/js/**` → `npm run build` dispensado.

## Pendências para o deploy (NÃO executado — requer autorização)

1. Rodar a migration na VPS: `php artisan migrate --force` (cria `polos_faturamento_snapshots`).
2. O snapshot nasce **vazio**: até o 1º sync após o deploy, o fallback não tem o que
   servir. Popular imediatamente com `php artisan polos:warm` (síncrono, ~12 min) ou
   aguardar o cron das 13:00 BRT. A partir daí a página fica sempre populada.
3. Cron `schedule:run` já configurado (CLAUDE.md) — o novo job entra automaticamente.
