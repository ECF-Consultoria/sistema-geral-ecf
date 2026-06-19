---
phase: quick-260619-iwj
plan: 01
subsystem: polos
tags: [adman, ads, investment, polos, frontend, backend, cache]
key-files:
  modified:
    - app/Http/Controllers/PolosController.php
    - app/Jobs/SyncPolosFaturamentoJob.php
    - resources/js/Pages/Polos/Empresas.jsx
decisions:
  - "ADS somente via cache (getCachedAccountMetricsMany / fetchAccountMetricsCached) — sem HTTP síncrono na request; warm pelo job"
  - "Mês fechado: ADS=R$0 por design — sem fonte CSV para investment (limitação documentada)"
  - "Limiares ADS configuráveis via Configuracao: polo_ads_teto=3000, polo_ads_alerta1=1000, polo_ads_alerta2=2000"
  - "semanal() faz HTTP por semana para ADS (4 chamadas, mas já é o padrão do faturamento semanal)"
metrics:
  duration: "~25 min"
  completed: "2026-06-19"
  tasks_completed: 3
  files_modified: 3
---

# Quick 260619-iwj: ADS Adman (investment) na /polos/empresas

**Uma linha:** Coluna ADS na tabela /polos/empresas com barra de progresso mensal colorida por limiar (verde/amarelo/vermelho) e gasto semanal na linha expandida, alimentada pelo cache Adman via `getCachedAccountMetricsMany`.

## Tarefas Executadas

| # | Arquivo | Mudança principal | Commit |
|---|---------|-------------------|--------|
| T1 | `PolosController.php` | Novo `adsAdmanDoMes()`, campo `ads` por empresa, `adsLimites` nas props, ADS no `semanal()` | `f0690be` |
| T2 | `SyncPolosFaturamentoJob.php` | Warm do cache de `investment` via `fetchAccountMetricsCached(forceRefresh:true)` por cust_id | `a3a896e` |
| T3 | `Empresas.jsx` | Coluna ADS com barra colorida, helper `corAds()`, ADS por semana na expansão, build OK | `a293925` |

## O que foi implementado

### Backend (PolosController)

**Novo método `adsAdmanDoMes(array $ativos, string $mesSel): array`**
- Espelha exatamente `faturamentoAdmanDoMes`
- Usa `getCachedAccountMetricsMany` (SÓ-CACHE, sem HTTP) — extrai `['value']['investment']`
- try/catch `\Throwable` defensivo — Adman offline vira `[]`, ADS=R$0, página não quebra
- Loga quantidade de cust_ids sem cache com tag `[Polos]`

**`agregarPorPolo()`**
- Novo param `array $adsAdman = []` (opcional, retrocompatível com `index()`)
- Campo `'ads' => $ads` adicionado ao array de detalhe por empresa

**`montarPolos()`**
- Busca `$adsMes` via `adsAdmanDoMes()` (sempre, mesmo em mês fechado — retorna `[]` nesse caso)
- Calcula `$adsLimites` lendo `Configuracao::get('polo_ads_teto', 3000)` etc.
- Retorna `adsLimites` no `compact()`

**`todasEmpresas()`**
- Prop `adsLimites` adicionada no render de sucesso e no array `$vazio` defensivo

**`semanal()`**
- Para cada semana: busca ADS via `fetchAccountMetricsCached()` (sem forceRefresh — usa cache se disponível)
- Retorna `'ads'` por semana e `'totalAds'` no JSON

### Backend (SyncPolosFaturamentoJob)

- Contador `$adsComValor` inicializado junto de `$ok` e `$comValor`
- Dentro de cada iteração: chama `fetchAccountMetricsCached(forceRefresh:true)` após `fetchGrossBilling`
- Sem usleep extra entre as duas chamadas do mesmo cust_id (throttle de 7s já separa cust_ids)
- Log final incluído: `N com ADS>0`

### Frontend (Empresas.jsx)

- Helper `corAds(gasto, limites)`: verde < alerta1, amarelo >= alerta1, vermelho >= alerta2
- Prop `adsLimites` com default `{teto:3000, alerta1:1000, alerta2:2000}`
- Nova coluna ADS ordenável (`<Th campo="ads">`) após "% da meta"
- `colSpan={8}` ajustado para `colSpan={9}` (linha vazia e linha expandida)
- Célula ADS: barra `w-24 h-1.5` com cor de `corAds()` + `formatCurrency(ads) / formatCurrency(teto)` tabular-nums
- Título da expansão: "Faturamento e ADS por semana"
- Cada card semanal: rótulo "ADS" + valor formatado + barra fina `h-1` colorida por `corAds()`

## Verificações

- `php -l PolosController.php` — sem erros
- `php -l SyncPolosFaturamentoJob.php` — sem erros
- `npm run build` — concluído sem erros em 13.44s (5063 módulos transformados)

## Deviações do Plano

Nenhuma — plano executado exatamente como especificado.

## Limitações Conhecidas

- **Mês fechado:** ADS=R$0 por design. O cache Adman não tem histórico de empresas que saíram do programa; sem fonte CSV para `investment`. Comportamento esperado e documentado no PLAN.md.
- **ADS semanal:** usa `fetchAccountMetricsCached` sem `forceRefresh` — lê cache se disponível, sem HTTP se frio. Em cache frio (antes do warm) ADS semanal fica R$0 e volta a popular após o usuário clicar em Sincronizar.

## Self-Check

- [x] `f0690be` existe em `git log`
- [x] `a3a896e` existe em `git log`
- [x] `a293925` existe em `git log`
- [x] `adsAdmanDoMes` presente em `PolosController.php`
- [x] `getCachedAccountMetricsMany` usado em `PolosController.php`
- [x] `fetchAccountMetricsCached` usado em `SyncPolosFaturamentoJob.php` e `PolosController.php`
- [x] `adsLimites` presente em `Empresas.jsx`
- [x] `colSpan={9}` presente em `Empresas.jsx` (ambas as ocorrências)
- [x] Build OK sem erros

## Self-Check: PASSED
