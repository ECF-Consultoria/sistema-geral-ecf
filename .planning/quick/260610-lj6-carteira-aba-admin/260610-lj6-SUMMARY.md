---
quick_id: 260610-lj6
slug: carteira-aba-admin
type: execute
mode: quick
status: complete
files_modified:
  - app/Http/Controllers/DashboardController.php
  - app/Http/Controllers/PortfolioController.php
  - resources/js/Pages/Dashboard/Admin.jsx
files_created:
  - resources/js/Pages/Portfolio/Carteiras.jsx
commits:
  - hash: 6a089f2
    message: "fix(carteira): aba Carteira mostra visao consolidada para admin; widget removido do Dashboard"
---

# Quick Task 260610-lj6 — Aba Carteira bifurca por papel (admin vs profissional)

## Resumo executivo

A aba "Carteira" do sidebar (rota `portfolio.own`) agora responde de forma diferente
conforme o papel do user logado:

- **Admin** → `Portfolio/Carteiras.jsx` (nova page): cards de TODOS analistas +
  estrategistas (visão consolidada que estava como widget no Dashboard Admin), com
  métricas agregadas no período (TACOS, faturamento, margem, gasto em ads). Inclui
  seletor de período (1/7/30/180 dias).
- **Não-admin** (analista/estrategista/mentor/consultor) → `Portfolio/Show.jsx`
  inalterado, mostrando carteira pessoal como antes.

Widget consolidado **removido** do `Dashboard/Admin.jsx`. Lógica de cálculo
(analistas + estrategistas via cargo no pivot, TACOS = SUM(ad_spend)/SUM(revenue)*100)
migrada de `DashboardController` para `PortfolioController::renderCarteirasConsolidadas`.

## Bifurcação implementada

```php
// app/Http/Controllers/PortfolioController.php
public function own(Request $request)
{
    $user = $request->user();
    if ($user->isAdmin()) {
        return $this->renderCarteirasConsolidadas($request);
    }
    return $this->renderPortfolio($request, $user);
}
```

## Limpezas no Dashboard

- `DashboardController::adminDashboard`: removido bloco `$todosProfissionais` +
  `$userPortfolios`; removida key `'user_portfolios'` do `Inertia::render`.
  `$analistas` e `$estrategistas` permanecem (continuam alimentando os filtros).
- `Dashboard/Admin.jsx`: removida prop `user_portfolios = []` da assinatura e o
  bloco JSX inteiro do widget "Carteiras por profissional".

## Smoke pós-deploy sugerido

1. Login admin → clicar "Carteira" no sidebar → deve ver `Portfolio/Carteiras` com
   cards de Ana Julia, Danilo, Gabriela Aguiar, Gustavo, Maycon Gomes, Stefani,
   Débora Lima, Douglas, Luiz Henrique, Nathalia Martins, Rubens (os que tiverem
   empresas na carteira).
2. Login não-admin (ex: Stefani) → clicar "Carteira" → deve ver `Portfolio/Show`
   com as empresas DELA (comportamento de antes).
3. Dashboard Admin → confirmar que o widget "Carteiras por profissional" sumiu.

## Path-safety incident (mitigado pelo executor)

A primeira tentativa de Edit do executor resolveu para o checkout do main em vez
do worktree. Detectado via verify automatizado, arquivos copiados para o worktree
correto e main revertido com `git checkout --`. Estado final consistente. Sem
impacto no resultado final.
