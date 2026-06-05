# Phase 24: Painel Executivo Carteira ECF (resumo + histórico + breakdowns)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 22 (wrapper) + Phase 23 (validou padrão de aba consumindo ECF Drive)
**Milestone:** v8.0 — Integração Estratégica ECF Drive

## Goal

Criar **aba `/painel-executivo`** com visão consolidada da carteira inteira ECF (~1238 sellers, R$ 42,8M GMV em maio/26), complementar ao Dashboard operacional atual (que mostra apenas as empresas DA NOSSA CARTEIRA ATIVA, ~172). É o **dashboard estratégico** que abre quando o admin loga para visão executiva.

Dados vêm de 3 endpoints do ECF Drive:
- `/carteira/resumo` — KPIs do mês atual + MoM
- `/carteira/historico?periodicidade=mensal` — série 12 meses para gráficos
- `/carteira/breakdown?dimensao=programa|frete|cluster|localidade` — decomposição por dimensão

## Origem da fase

API-GUIDE.md §6 documenta o domínio `/carteira/*` como "A visão executiva". Dados reais validados no smoke W4 Phase 22:
- `carteiraResumo()` retorna GMV R$ 42.859.191 em maio/26 com MoM -11.74%
- Sellers ativos: 1238 (+15.7% vs abril)
- Investimento ADS: R$ 1.540.459 (-16.7% MoM)
- Distribuição programa: 56% POLOS (697) + 44% CPP (541) — POLOS volume, CPP receita

## Decisões já travadas

### D-01: Posição "estratégica" vs "operacional"

Dashboard atual (`/dashboard`) mostra **NOSSA carteira** — empresas que prestamos serviço (consultor/mentor visualizam seus clientes; admin vê tudo). Painel Executivo (`/painel-executivo`) mostra **TODA a carteira ECF do ML** — incluindo sellers que NÃO são clientes nossos. **Não substitui o Dashboard.** São complementares: Dashboard = operacional, Painel Executivo = estratégico.

### D-02: Acesso

**Só admin** nesta fase. Consultores/mentores não veem (foco em performance dos seus clientes). Possível extender para `gestor` em fase futura se houver pedido.

### D-03: Item na sidebar

**No topo da sidebar**, dentro da seção principal — próximo do Dashboard (são complementares). Ícone `LineChart` ou `BarChart3` do lucide-react. Sem badge dinâmico (não há "contagem" relevante).

### D-04: 3 seções da página

1. **Resumo (KPI cards)**: GMV, vendas, sellers ativos, investimento ADS, GMV/ADS, GMV Full/Flex/ME2, visitas — cada um com valor atual + delta MoM + seta colorida
2. **Histórico (gráfico de linha)**: 12 meses, série de GMV mensal + linha secundária de sellers ativos
3. **Breakdown (4 abas/dropdown)**: programa | frete | cluster | localidade — gráfico de pizza ou tabela

### D-05: Período padrão

Mês mais recente disponível (resposta do `/carteira/resumo`). Não há seletor de período nesta fase (vem em fase futura se necessário).

### D-06: Try/catch global

Mesmo padrão da Phase 23: se ECF Drive cair, página carrega com props vazias + flash error "Não foi possível buscar dados do painel agora. Tente em alguns segundos."

### D-07: Caching defensivo

Wrapper já cacheia (`carteiraResumo` 5min, `carteiraHistorico` 24h, `carteiraBreakdown` 1h — Phase 22). Não duplicar cache no controller.

### D-08: Visualizações

Reusar `recharts` que já está no projeto (Dashboard atual usa). Não introduzir dep nova.

### D-09: Breakdown — escolha de dimensão via tab/select

UI simples: 4 tabs no topo da seção breakdown (Programa, Frete, Cluster, Localidade). Cada tab carrega o mesmo gráfico (pizza + tabela ao lado), mudando só os dados. Sem fetch incremental por aba — controller traz as 4 breakdowns de uma vez (4 chamadas leves cacheadas 1h).

### D-10: Sem persistência local

ECF Drive é fonte da verdade. Controller chama wrapper a cada pageload (cache do wrapper absorve).

## KPI cards — layout proposto

```
┌──────────────────┬──────────────────┬──────────────────┬──────────────────┐
│ GMV Total        │ Vendas           │ Sellers Ativos   │ Investimento ADS │
│ R$ 42,8M         │ 357.531          │ 1.238            │ R$ 1,54M         │
│ ▼ -11,74% MoM    │ ▲ +1,83% MoM     │ ▲ +15,70% MoM    │ ▼ -16,70% MoM    │
└──────────────────┴──────────────────┴──────────────────┴──────────────────┘
┌──────────────────┬──────────────────┬──────────────────┬──────────────────┐
│ GMV ADS          │ GMV Full         │ GMV Flex         │ Visitas          │
│ R$ 15,3M         │ R$ 15,2M         │ R$ 763k          │ 12,69M           │
│ ▼ -7,08%         │ ▼ -16,23%        │ ▲ +11,05%        │ ▼ -5,92%         │
└──────────────────┴──────────────────┴──────────────────┴──────────────────┘
```

8 cards em 2 linhas. Cor da seta: verde (positivo), vermelho (negativo), cinza (sem mudança).

## Success Criteria

1. **Nova rota** `/painel-executivo` → `PainelExecutivoController::index` → `Inertia::render('PainelExecutivo/Index')`. Middleware: `auth, verified, role:admin`.

2. **`PainelExecutivoController::index`** carrega em try/catch global:
   - `carteiraResumo()` → todos os KPIs MoM
   - `carteiraHistorico(periodicidade='mensal')` → 12 meses
   - 4 chamadas `carteiraBreakdown('programa'|'frete'|'cluster'|'localidade')` → 4 datasets
   - Em erro: props vazias + flash error
   - Cache no wrapper absorve overhead

3. **`PainelExecutivo/Index.jsx`** com 3 seções:
   - **Seção 1 — KPI cards**: 8 cards em grid 2x4 (ou 4x2 em mobile), valor + delta MoM + seta colorida
   - **Seção 2 — Gráfico histórico**: linha 12 meses GMV + secundária Sellers Ativos (recharts `LineChart`)
   - **Seção 3 — Breakdown**: 4 tabs (Programa/Frete/Cluster/Localidade), cada uma com gráfico de pizza + tabela detalhada

4. **Item na sidebar**:
   - Posição: dentro da seção principal, próximo do Dashboard
   - Ícone: `LineChart` (lucide-react)
   - Label: "Painel Executivo"
   - Apenas admin (filtrar via `user.isAdmin()`)

5. **Formatação pt-BR**:
   - Moeda: `R$ 42,8M`, `R$ 1.540.459,82`
   - Percentual: `-11,74%`, `+15,70%`
   - Números grandes: abreviação `M` (milhão), `k` (mil) quando > 1M
   - Datas: `mai/26` no eixo X do gráfico

6. **Testes Feature** (mínimo 5):
   - 200 admin
   - 302 guest
   - 403 consultor/mentor/publicador
   - Props têm `resumo`, `historico`, `breakdowns: {programa, frete, cluster, localidade}`
   - Erro ECF Drive → `erro` em props + estrutura vazia

7. **Build OK**, sem warnings novos além dos existentes.

## Mapa de arquivos

### Backend novos
- `app/Http/Controllers/PainelExecutivoController.php`

### Backend modificados
- `routes/web.php` — adiciona grupo `/painel-executivo` middleware `auth, verified, role:admin`

### Frontend novos
- `resources/js/Pages/PainelExecutivo/Index.jsx`
- `resources/js/Pages/PainelExecutivo/components/KpiCard.jsx` (card reutilizável com delta MoM)
- `resources/js/Pages/PainelExecutivo/components/HistoricoChart.jsx` (LineChart 12m)
- `resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx` (tabs + pizza + tabela)

### Frontend modificados
- `resources/js/Layouts/AppLayout.jsx` — adiciona item "Painel Executivo" no topo da sidebar (só admin)

### Testes novos (em `tests/Feature/Phase24/`)
- `PainelExecutivoControllerTest.php` (5 testes mínimo)

### Não tocar
- `Dashboard` atual (decisão D-01 — complementar, não substitui)
- `EcfDriveService` (Phase 22)
- Migration / model / activity log

## Pitfalls antecipados

1. **5 chamadas ao ECF Drive no `index`** (resumo + histórico + 4 breakdowns) — 5x latência. Mitigação: cache do wrapper absorve (5min/24h/1h). Em cold cache pode demorar ~5-10s na primeira carga. Aceitar: page load lento mas dados precisos.

2. **Recharts SSR/hydration** — gráficos com dados dinâmicos podem flash. Mitigação: já é padrão do Dashboard atual.

3. **Formatação de moeda BRL com abreviação** — pode usar helper já existente. Conferir `lib/utils.js`.

4. **Sidebar com 1 item a mais quebrando layout** — testar mobile.

5. **`/carteira/breakdown` aceita dimensao=localidade** que retorna top 50 — limitar exibição na tabela pra top 20.

6. **Gráfico histórico — meses sem dados** — mitigação: usar 0 nos meses sem registro.

## Não-objetivos

- Filtros customizados de período (vem em fase futura se necessário)
- Drill-down para sellers individuais (Phase 25)
- Comparação ano-a-ano (fase futura)
- Exportação CSV/PDF (fase futura)
- Forecast (Phase 27)
- Concentração de receita (Phase 27)

## Cross-cutting constraints

- pt-BR em tudo
- `npm run build` após cada JSX
- Sem deploy automático (W4 humano — eu rodo deploy direto, smoke é validação do usuário)
- snake_case nas props
- Reusar shadcn (Card, Badge) + recharts (já no projeto)
- Sem migration
- Try/catch global em chamadas ECF
- ECF Drive é fonte da verdade

## Referências

- API-GUIDE.md §6 — Carteira
- `EcfDriveService::carteiraResumo`, `carteiraHistorico`, `carteiraBreakdown` (Phase 22)
- `resources/js/Pages/Dashboard/Admin.jsx` — padrão de KPI cards + recharts
- Phase 23 (Alertas) — padrão de try/catch global + props vazias em erro

## Memory persistente relevante

- **Lean planning** — direto pro PLAN
- **GSD output em pt-BR**
- **Autorização permanente para deploy** — não pedir confirmação
- **Acertividade** — dados oficiais ML via ECF Drive
- **Praticidade** — abre, vê tudo, decide
