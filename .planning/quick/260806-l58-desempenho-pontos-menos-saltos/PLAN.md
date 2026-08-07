---
id: 260806-l58
slug: desempenho-pontos-menos-saltos
status: in-progress
date: 2026-08-06
---

# Quick 260806-l58 — Desempenho: pontos como unidade principal, menos saltos de tela

## Natureza do trabalho

**100% apresentação.** Não altera cálculo, não bumpa `desempenho.compute.v17`, não
roda `desempenho:consolidar-mes`, não recalcula competência fechada e não liga
`metrics.performance_company_first_score`.

## Achados da leitura que condicionam o plano

**A1 — Os dois guards são idênticos, não há exposição nova.**
`PortfolioController::show()` (:178-187) e
`PerformanceController::autorizadoParaVerDesempenhoDe()` usam exatamente a mesma
regra: `isAdmin() || id === user->id || (isLider() && user está em setor liderado)`.
Levar nota e faixa de bônus para o portfólio **não amplia** o público que já lê
esse dado em `/performance/{id}`.

**A2 — Precedência de leitura da nota: snapshot ANTES de `computeCached()`.**
`PerformanceController::show()` (:1345-1355) tenta `DesempenhoScoreSnapshot::mensal()`
e só cai em `computeCached()` quando não há snapshot. Se o portfólio usasse
`computeCached()` direto, mês fechado exibiria número **recalculado ao vivo** —
divergente da outra tela e sujeito à fragilidade de fronteira do §2 do learning
(0,24 p.p. já tirou bônus de alguém entre duas leituras da mesma competência).
O portfólio vai espelhar a mesma precedência. Bônus: mês fechado nem chama compute.

**A3 — `CompanyScoreSnapshotReader` não está injetado no `PortfolioController`.**
`DesempenhoScoreSnapshot` (:7) e `DesempenhoScoreService` (:22) já estão. Falta só
o reader.

**A4 — Os 4 metadados já são tooltip no ranking.**
`Performance/Index.jsx:707-711` põe empresas únicas / vínculos de serviço /
vínculos sem fonte financeira no `title` da coluna Empresas. O bloco de 4 tiles do
`FaixaBonusCard` é eco disso.

**A5 — `resumo.margem_media_pct` só é consumido pelo `AdminCarteira.jsx`.**
Remover o KPI deixa o campo do payload sem leitor. Fica no payload (remover é
churn adjacente ao cálculo, sem ganho) — só sai da tela.

## Propostas para as duas decisões abertas

**P1 — Bloco de 4 metadados: CONDENSAR e fundir com o card "Info carteira".**
Hoje a `Show` gasta um grid de 4 colunas dentro do card de bônus **e** um card
inteiro logo abaixo só para `X empresas com baseline · Y na carteira`. São dois
blocos sobre o mesmo assunto: composição da carteira. Viram uma linha só, dentro
do card de bônus, com os 4 números no `title`:

    12 de 15 empresas com baseline · 18 vínculos · 3 sem fonte financeira

Ganho: −1 card inteiro, −1 grid de 4 colunas, nenhum número perdido. Mesmo padrão
de tooltip que o ranking já usa (A4).

**P2 — Banners de período: componente compartilhado de UMA linha.**
Os três textos longos viram `Components/Desempenho/PeriodoBanner.jsx`, com os
textos em `lib/desempenhoLabels.js`. Uma linha visível + explicação longa no
`title`:

    Em curso   → "Mês em curso · 1–14/08 vs 1–14/07 · NPS entra com piso 1,0"
    Fechado    → "Mês fechado · baseline 01/06–30/06"
    Bônus atual→ "Bônus da competência junho/2026 · pago em julho"

Ganho: o mesmo texto para de existir em 3 arquivos (é o que a `desempenhoLabels`
já faz pelos motivos e pela margem).

**P3 — O link "Detalhes sobre as empresas da carteira": MANTER, renomeado.**
O critério do pedido é "só continua existindo se levar a algo que a tela de destino
tem e a de origem não". O portfólio tem 4 coisas que a `Show` não tem: faturamento
em R$, ADS/TACoS, vínculos de serviço por empresa e conexão ML. Então o link fica,
mas dizendo o que entrega: **"Ver operação da carteira (faturamento, ADS, serviços)"**.
E ganha o par inverso no portfólio: da nota, "Ver como a nota foi formada".
Cada tela passa a apontar para o que a outra tem de exclusivo, em vez de para "mais
detalhes" genérico.

## Tarefas

### T1 — `lib/desempenhoLabels.js`: textos curtos de período
- `PERIODO_EM_CURSO_*`, `PERIODO_FECHADO_*`, `PERIODO_BONUS_*` (curto + título longo).
- Helper `resumoCarteiraLinha({ comBaseline, carteira, vinculos, semFonte })` para P1.
- Sem régua, sem agregação — só texto e formatação, como o resto do arquivo.

### T2 — `Components/Desempenho/PeriodoBanner.jsx` (novo)
Componente de apresentação puro (sem router/usePage/fetch), 3 modos, uma linha.

### T3 — `Performance/Show.jsx`
- 4 `ParametroCard` → **pontos** como valor principal:
  - NPS: `resultado.pontos_componentes.nps` — **não** `componentes.nps_medio`
    (é outro número; usar o errado faz o card não fechar com a conta do card de bônus)
  - Faturamento: `pontos_componentes.faturamento`, `var_faturamento_pct` no sublabel
  - Margem: `pontos_componentes.margem`, `var_margem_pp` no sublabel
- **Remover** o card "Absenteísmo" (sem fonte de dados).
- Grid 4 → 3 colunas.
- P1: 4 tiles + card "Info carteira" → uma linha com tooltip.
- P2: banner longo → `PeriodoBanner`.
- P3: link renomeado.

### T4 — `PortfolioController::renderCarteiraProfissional()`
- Injetar `CompanyScoreSnapshotReader` no construtor.
- Nota do mês pela precedência da A2 (snapshot mensal → `computeCached()`).
  **Nunca `compute()`** (§5).
- Detalhe por empresa só quando `$periodo['is_closed']`, via
  `paraUsuario($user->id, $mesSelecionado)`; merge por `company_id` nas linhas de
  `$empresas`. `tem_detalhe_empresas` derivado da EXISTÊNCIA de linhas, nunca de
  `is_closed` isolado (mês fechado antigo não tem detalhe gravado).
- **Zero cálculo por empresa ao vivo** — reabriria o fan-out de HTTP por empresa
  que produziu página de 70s (§5).

### T5 — `Portfolio/AdminCarteira.jsx`
- KPI "Margem média" → **ponto geral do mês**: nota 0–5 + faixa + conta
  `(nps+fat+margem)/n = nota`, mesmo formato do ranking.
- Tabela "Empresas em carteira" mantida, com coluna **NPS**, pontos sob
  faturamento, pontos sob margem e coluna **Nota** — sempre em texto pequeno
  abaixo do número, nunca como valor principal.
- Mês em curso: aviso `AVISO_SEM_DETALHE_EM_CURSO`, jamais número ao vivo.
- P2 no banner, P3 no link inverso.

### T6 — Build e conferência
`npm run build` + rodar as suítes de desempenho vizinhas para provar que nada de
cálculo se moveu.

## Verificação

- [ ] `npm run build` verde
- [ ] Suítes de desempenho sem falha nova contra o baseline
- [ ] Nenhum diff em serviço de cálculo, régua, agregação ou snapshot
- [ ] Card de NPS da `Show` fecha com a conta do card de Faixa de bônus
- [ ] Mês fechado consolidado: pontos por empresa aparecem nas duas telas
- [ ] Mês em curso: as duas telas mostram o aviso, nenhuma dispara cálculo

## Fora de escopo

- Deploy (pare antes e chame o usuário).
- Qualquer mudança de cálculo, régua, agregação, snapshot ou feature flag.
- Absenteísmo: card removido, não implementado.
