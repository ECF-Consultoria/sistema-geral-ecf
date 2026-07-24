---
quick_id: 260724-dho
title: Corrigir badge de plataforma na carteira (alinhar ao serviço do vínculo)
status: completo
---

# Quick 260724-dho — Badge de plataforma na carteira reflete o serviço do vínculo Summary

Backend da carteira (`renderCarteiraProfissional`/`transparencia`) reordenado para que `fonte`/`has_ml_oauth` sigam `$fonteFinanceiraVencedora` (fonte financeira do vínculo do profissional), não mais o `mlToken`/`adman_account_id` GLOBAL da empresa.

## O que foi feito

### Task 1 — Backend: alinhar badge à fonte do vínculo
- `app/Http/Controllers/PortfolioController.php::transparencia()`: bloco de cálculo de `$fonte` (linhas ~296-311) agora testa `$fonteFinanceiraVencedora === 'shopee'` PRIMEIRO — só cai no ramo ML/adman quando a fonte vencedora do vínculo não é Shopee. `has_ml_oauth` virou `$temMl && $fonteFinanceiraVencedora !== 'shopee'`.
- `PortfolioController.php::renderCarteiraProfissional()`: mesma correção. Introduzida variável local `$temMlBadge = $temMl && $fonteFinanceiraVencedora !== 'shopee'` e usada nos 4 pontos onde `has_ml_oauth` era montado (empresa sem vínculo elegível; shopee sem dados sincronizados; shopee com dados; ramo principal ML/adman).
- `renderPortfolio()` (self-view `/portfolio`, Show.jsx): **nenhuma mudança necessária**. Ao ler o código (linhas ~1541-1697), o bloco financeiro já tem um `if ($fontesPorEmpresaSelf->get($c->id) === 'shopee') { ... return [...'has_ml_oauth' => false...] }` que retorna cedo — implementado na Fase 109 (SHOP-CAR-01/02). Como esse `return` acontece ANTES da linha `$hasMlOauth = (bool) ($c->mlToken...)` (linha ~1673, hoje ~1691), essa linha só é alcançada quando a fonte vencedora do vínculo do próprio profissional NÃO é Shopee — comportamento já correto. Documentado como deviation abaixo (Rule 2 análogo — verificação, não fix).

O número financeiro (faturamento/margem) não foi tocado em nenhuma das 3 funções — já estava correto antes desta correção (fonte real dos dados via `$fonteFinanceiraVencedora`/`$diffDispatcher`).

### Task 2 — Teste + build
- `tests/Feature/PortfolioShopeeCarteiraTest.php`: 3 casos novos:
  - `test_transparencia_empresa_dual_mltoken_ativo_mas_vinculo_shopee_mostra_fonte_shopee` — empresa com `MlToken` ativo (criado via novo helper `criarMlTokenAtivo()`) + vínculo Shopee do profissional → `fonte='shopee'`, `has_ml_oauth=false`.
  - `test_carteira_individual_empresa_dual_mltoken_ativo_mas_vinculo_shopee_mostra_fonte_shopee` — mesmo cenário na tela `Portfolio/AdminCarteira` (`portfolio.show` admin→outro).
  - `test_carteira_individual_empresa_performance_pura_mantem_badge_ml` — regressão de guarda: empresa performance pura com `mlToken` ativo continua com `fonte` em `['ml','ml_adman']` e `has_ml_oauth=true`.
- Nenhum arquivo `.jsx` foi tocado (o front já consumia `fonte`/`has_ml_oauth` do payload corretamente via `FonteBadge`/SVG gated por `c.has_ml_oauth`) — `npm run build` **não executado**, conforme regra do plano ("se nada mudar no JSX, não precisa build").

## Verificação

```
php artisan test tests/Feature/PortfolioShopeeCarteiraTest.php
Tests: 12 passed (64 assertions)
```

Suíte completa `--filter=Portfolio`: 46 passed, 3 failed. As 3 falhas são em `Tests\Feature\Phase61\PortfolioMultiFonteE2ETest` e `Tests\Feature\Phase61\PortfolioSourceEnrichmentTest` — ambas testam a chave `user_portfolios`, que é produzida por uma função DIFERENTE (`renderCarteirasConsolidadas`, ~linha 1378), fora do diff desta task (confirmado via `git diff -U0` — hunks só em `transparencia()` linhas 296-316 e `renderCarteiraProfissional()` linhas 641-859). Reproduzem em isolamento (sem a suíte Shopee), portanto são pré-existentes e fora do escopo deste quick task (Scope Boundary rule).

## Deviations from Plan

### Verificação sem alteração (não é bug)

**1. [Verificação] `renderPortfolio()` (self-view) já estava correto**
- **Encontrado durante:** Task 1, leitura de `renderPortfolio()` antes de editar.
- **Situação:** O plano presumia que `has_ml_oauth` em `renderPortfolio()` (linha ~1687/1691) precisava do mesmo gate. Na leitura do código, a Fase 109 já havia implementado um `return` antecipado com `has_ml_oauth => false` para empresas cuja fonte vencedora do vínculo do próprio profissional é Shopee — antes da linha que lê `c->mlToken` globalmente. Portanto essa linha só é alcançada para empresas cuja fonte vencedora NÃO é Shopee (correto pela regra de desempate "adman vence").
- **Ação:** Nenhuma mudança de código. Documentado aqui para rastreabilidade.

### Pré-existente, fora do escopo

**2. [Fora de escopo] 3 falhas em `Phase61/PortfolioMultiFonteE2ETest` e `Phase61/PortfolioSourceEnrichmentTest`**
- **Encontrado durante:** Verificação ampla (`--filter=Portfolio`) após Task 2.
- **Situação:** `user_portfolios` retorna vazio (esperado 1). Função afetada (`renderCarteirasConsolidadas`, ~linha 1378) não foi tocada por este quick task.
- **Ação:** Não corrigido (fora do escopo desta task — ver Scope Boundary rule). Reproduzido também rodando a suíte isolada (`php artisan test tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php`), confirmando que não é efeito colateral do meu diff.

## Arquivos modificados

- `app/Http/Controllers/PortfolioController.php` — commit `c621a9fc`
- `tests/Feature/PortfolioShopeeCarteiraTest.php` — commit `f962c5a1`

## Self-Check

- `app/Http/Controllers/PortfolioController.php` existe — FOUND
- `tests/Feature/PortfolioShopeeCarteiraTest.php` existe — FOUND
- commit `c621a9fc` — FOUND (`git log --oneline`)
- commit `f962c5a1` — FOUND (`git log --oneline`)

## Self-Check: PASSED

## Não deployado

Conforme constraint do plano — deploy será feito depois pelo orquestrador junto do item operacional.
