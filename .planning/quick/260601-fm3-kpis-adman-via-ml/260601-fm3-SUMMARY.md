---
quick_id: 260601-fm3
slug: kpis-adman-via-ml
status: complete
completed: 2026-06-01
commit: a2e8237
---

# Summary — KPIs Adman via API do Mercado Livre

## O que foi feito

Empresas **ML-only** no painel de empresas (`Companies/Show`) agora exibem a
mesma grade de 4 KPIs financeiros que apareciam na Adman — **Faturamento, ACOS,
TACOS e Margem % (30d)** — alimentados exclusivamente pelos dados da API do
Mercado Livre já gravados em `adman_metrics`.

## Arquivos alterados

| Arquivo | Mudança |
|---------|---------|
| `app/Http/Controllers/CompanyController.php` | Ramo ML-only do `show()` agrega 30 dias de `adman_metrics`: `revenue_30d=Σrevenue`, `tacos_30d=Σad_spend/Σrevenue×100`, `acos_30d=Σad_spend/Σad_revenue×100` (ad_revenue de `raw_data.ads`), `ad_investment_30d=Σad_spend`, `margin_pct_30d=null`. |
| `resources/js/Pages/Companies/Show.jsx` | Removido `kpi30` e o bloco condicional ML de 3 cards (Faturamento/Ad Spend/TACOS). Grade única de 4 cards p/ Adman e ML. Card Margem % ganha ícone `Info` + tooltip quando `null` e ML-only. |

## Decisões aplicadas

- **Margem %** → mantida como **N/D** (`—`) para ML-only com tooltip "Requer CMV
  — indisponível na API do Mercado Livre". A API do ML não expõe CMV nem
  impostos necessários para a margem de contribuição da Adman.
- **ACOS** → reproduzido via `ad_revenue` (receita atribuída a anúncios), que já
  estava em `raw_data.ads` de cada linha — **sem migração**.
- **Invest. Ads (30d)** → passa a preencher para ML-only via `ad_investment_30d`.

## Verificação

- `php -l CompanyController.php` → sem erros de sintaxe.
- `npm run build` → ✓ built in ~10s, sem erros.
- `isMlOnly` e `mlKpis` preservados (`mlKpis` ainda usado no `MlConnectionCard`).

## Observações / follow-up

- **Margem % real** exige nova fonte de dados (CMV por SKU + regime tributário).
  Fica como feature separada se o time decidir reproduzi-la fielmente.
- ACOS histórico depende de `raw_data.ads.ad_revenue` presente nas linhas; dias
  sem esse dado caem para `—` (ACOS null) sem quebrar os demais KPIs.
- **Não deployado** — aguardando autorização explícita (convenção do projeto).

## Commit

`a2e8237` — feat(company): KPIs Adman para empresas ML-only via API do Mercado Livre
(branch `quick/260601-kpis-adman-via-ml`)
