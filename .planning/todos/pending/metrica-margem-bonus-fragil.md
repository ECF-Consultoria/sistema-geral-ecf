# DECISÃO DE NEGÓCIO PENDENTE — Métrica de margem do bônus é estruturalmente frágil

**Criado:** 2026-07-23
**Criticidade:** alta — afeta pagamento de bônus
**PRAZO:** decidir/agir antes de **31/07 14h BRT** (congelamento oficial de junho via `desempenho:consolidar-mes`)

## O achado

A métrica `var_margem_pct` do bônus de desempenho é: **média, entre as empresas do profissional, da VARIAÇÃO RELATIVA da margem% ponderada por receita** (`fallbackMargemPct` → `diffPctGuardado = (atual−anterior)/anterior*100`, com margem% = `SUM(contribution_margin)/SUM(revenue)*100` por janela).

Essa construção **amplifica ruído**: empresas individuais oscilam ±20–80% rotineiramente (ex.: junho vs maio, carteira do Luiz — LAURA LAR 32%→6% = −81%; ROSSI DECOR +65%; POZELAR +36%; DROSSI +29%; WEHOUSE −20%). Um punhado de empresas com denominador pequeno domina a média. O agregado do Luiz deu **+6,79% (ao-vivo), −12,6% (local do serviço) e +3,11% (réplica com dias diferentes)** — três valores "válidos" e totalmente diferentes, dependendo da fonte e dos dias incluídos.

Não é bug de código — é **design da métrica**. A régua `reguaMargem` (±5 → régua 1..5) opera sobre esse número relativo frágil.

## Relação com a Fase 110 (já deployada 2026-07-23)

A Fase 110 resolveu a instabilidade por **rate-limit 429** (ao-vivo → fallback local determinístico) — melhoria real, MANTER. Mas ela expôs a fragilidade da métrica em si. **Consequência do deploy:** o freeze oficial de 31/07 vai usar o valor determinístico LOCAL (Luiz ~−12,6% → régua margem 1), diferente do snapshot atual congelado em 22/07 (Luiz +6,79% → régua 5). Ou seja: se nada for decidido, a nota de margem de vários profissionais MUDA no freeze de 31/07.

## Opções de métrica (a comparar com números antes de decidir)

- **(A) Pontos percentuais**: (margem% jun − margem% mai), ponderado. Estável, interpretável. (leitura rápida do Luiz deu ~−0,59pp = praticamente neutro).
- **(B) Nível agregado**: (SUM margem jun / SUM receita jun) vs maio — UM número por profissional, sem média de relativos por empresa. Mais robusto.
- **(C) Relativa com cap**: manter relativa mas limitar swing por empresa (ex.: ±X%).

## Ação recomendada
1. Levar a decisão da métrica ao time (é regra de bônus).
2. Antes de 31/07: OU corrigir a métrica, OU decidir conscientemente congelar junho com a métrica atual (relativa) sabendo do impacto, OU adiar o freeze.
3. Também pendente (menor): empresas sem baseline de maio (começaram em junho — ex.: Dmov, OUZOR) ainda caem no `.diff` ao-vivo; refinar o gate pra virarem `null` (variação indefinida) em vez de usar ao-vivo volátil.

Ver: `.planning/debug/margem-adman-diff-instavel.md`, memória `project_adman_margem_diff_instavel_bonus`.
