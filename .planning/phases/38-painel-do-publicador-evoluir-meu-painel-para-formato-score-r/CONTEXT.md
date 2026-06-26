# Contexto — Fase 38: Painel do Publicador (evoluir Meu Painel para formato score + radar)

> Decisões travadas com o usuário em 2026-06-26 via AskUserQuestion. **Não re-perguntar.**

## Objetivo

Replicar a estrutura visual da tela **"Carteira"** dos analistas de ADS (score 0–100 +
gráfico de pontos/radar de 5 eixos + KPIs grandes + evolução do faturamento) **para o time
de publicação**, porém alimentada por dados do publicador. O publicador passa a ver o
próprio desempenho num painel rico, no lugar do "Meu Painel" atual (lista de KPIs simples).

## Decisões travadas (produto)

| Decisão | Escolha |
|---|---|
| **Nome da seção** | "Painel do Publicador" |
| **Eixos do radar/score (5)** | Meta · Produtividade · Pontualidade · Conversão · Qualidade |
| **Escopo da tela** | **Evoluir o "Meu Painel" existente** (NÃO criar tela separada) |
| **Visão** | **Só individual** — cada publicador vê o seu; sem tela consolidada admin/líder |

## Alvo a evoluir (não trocar rota/permissão)

- **Rota mantida:** `GET /mlb/meu-painel` → `MlbController@meuPainel`
  (`app/Http/Controllers/MlbController.php:531`) → permissão `mlb.meu_painel`
  → `Inertia::render('Mlb/MeuPainel', ...)`.
- **Página:** `resources/js/Pages/Mlb/MeuPainel.jsx` (nav em `AppLayout.jsx:133`, oculto p/ admin).
- **Helpers já existentes (reaproveitar):** `calcularKpis()`, `metaParaMes()`,
  `metaCadastrada()`, `mesesDisponiveis()` no `MlbController`.
- Props atuais já passadas: `kpis, evolucaoDiaria, topEmpresas, feedbacks, meta, mesRef,
  meses, problemas, ticketEvolucao, ticketAtual`.

## Referência de layout a espelhar (analistas ADS)

- `resources/js/Pages/Portfolio/Show.jsx` — KPIs grandes, `RadialBarChart` (score 0–100),
  `RadarChart` (5 eixos), `LineChart` (evolução do faturamento), cards de detalhe.
- `app/Services/PortfolioScoreService.php` — cálculo do score ponderado e normalização das
  dimensões para 0–100 (usar como **referência**, adaptando aos dados do publicador).

## Substituições (Carteira ADS → Painel do Publicador)

### 1. KPIs do topo (3, no lugar de TACOS/Faturamento/Margem/Gasto Ads)
- **Faturamento** = `SUM(mlb_publicacoes.net_billing)` dos anúncios do publicador
  (`user_id`) no mês.
- **Anúncios Feitos** = `COUNT` de `mlb_publicacoes` por `user_id` no mês (`tipo != 'variacao'`).
- **Vendas no Mês** = `vendido` / `SUM(vendas_qty)` + taxa de conversão.

### 2. Radar / score 0–100 — 5 eixos do publicador
- **Meta** = atingimento da meta de anúncios do mês (`feito / metaParaMes`).
- **Produtividade** = volume de anúncios (vs meta/média de pares).
- **Pontualidade** = % de SKUs/entregas no prazo (sem atraso) —
  `mlb_empresas.skus_estagio1/2/3` (flag `atrasado`) + `prazo_estagio*`.
- **Conversão** = % de anúncios com venda no mês.
- **Qualidade** = anúncios sem problema / feedbacks resolvidos
  (`mlb_publicacoes.problema`, `comentario_resolvido`, `revisado`).

### 3. Cards de detalhe (no lugar de Cresc.ajustado/Crescendo/Meta/Recuperação/NPS)
Atingimento da meta · Vendas/conversão · Entregas com atraso · Qualidade (problemas/feedbacks) · Produtividade.

### 4. Gráfico de Evolução do faturamento
Manter — `net_billing` diário/acumulado do publicador (hoje a evolução é de nº de publicações;
adicionar/ajustar para faturamento).

## Mapeamento de dados (tudo já existe — sem migration)

| Métrica | Fonte |
|---|---|
| Faturamento por anúncio | `mlb_publicacoes.net_billing` (vínculo via `user_id`) |
| Anúncios feitos | `COUNT mlb_publicacoes` por `user_id`/mês (`tipo != 'variacao'`) |
| Vendas / conversão | `mlb_publicacoes.vendido`, `vendas_qty` |
| Entregas com atraso | `mlb_empresas.skus_estagio1/2/3` (flag `atrasado`) + `prazo_estagio1/2/3`; ver `MlbController` ~462-488 e `MlbEmpresa` |
| Meta de anúncios | `mlb_meta_historico` (via `metaParaMes()` / `metaCadastrada()`) |
| Qualidade | `mlb_publicacoes.problema`, `problema_em`, `comentario_resolvido`, `revisado` |
| Empresa responsável | `mlb_empresas.responsavel_id` |

Publicador = `User` com cargo no setor `publicacao`.

## Constraints

- Stack fixa: **Laravel 12 + Inertia + React**. **Nenhuma migration nova** (dados já existem).
- Tailwind tokens `ecf-*`, dark theme, `cn()`, componentes `ui/` e `recharts` já no projeto.
- Comentários em **pt-BR**.
- **NÃO fazer deploy.** Rodar `npm run build` ao final de qualquer alteração de frontend.

## Fora de escopo (não fazer nesta fase)

- Tela consolidada admin/líder (cards de todos os publicadores) — visão é só individual.
- Migrations / coleta de dados nova.
- Mudar rota, nome de rota ou permissão (`mlb.meu_painel` permanece).
