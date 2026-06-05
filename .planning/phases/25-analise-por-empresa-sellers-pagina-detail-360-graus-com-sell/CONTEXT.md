# Phase 25: Análise por Empresa (ficha 360° via Sellers)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 22 (wrapper sellers/*) + Phase 23 (padrão de tradução de termos ML/CPP/POLOS)
**Milestone:** v8.0 — Integração Estratégica ECF Drive

## Goal

Criar **ficha 360° de cada empresa** (`/empresas/{company}/analise-ecf`) que consolida em uma única página tudo que sabemos sobre ela a partir do ECF Drive — métricas, medalhas, ranking, alertas. Destrava substituição parcial do drilldown Adman MCP (frágil, 429 frequente — Phase 19 lutou contra isso) por dados oficiais ML via ECF Drive.

## Origem da fase

Pedido natural após Phase 23 (Alertas Estratégicos): quando o usuário vê "Queda de faturamento — RELOJOARIA WENUS", ele quer **clicar e ver tudo** sobre essa empresa. Hoje, clicar no nome da empresa nos Alertas levava ao Dashboard com filtro — visão pobre. Phase 25 entrega a ficha completa.

## Decisões já travadas

### D-01: Rota standalone (não embedded no Companies/Show)

Nova rota `/empresas/{company}/analise-ecf` em vez de adicionar aba ao `Companies/Show.jsx` existente. Razões:
- `Companies/Show.jsx` já tem responsabilidades próprias (CRUD, sugadores, grant)
- Análise ECF Drive é feature independente que pode ter UI focada
- Permite lazy load (usuário só carrega quando explicitamente pede)

### D-02: Lookup cust_id via accessor `Company::cust_id`

Já existe no model — prioriza `ml_store_id ?? adman_account_id`. Mesma estratégia usada pelo `RefreshGrossBillingCacheJob` e Adman batch.

### D-03: Acesso

**admin + consultor + mentor** (mesmo padrão Phase 23/24). Para consultor/mentor: garantir que só veem empresas da SUA carteira via verificação `$user->companies()->where('id', $company->id)->exists()` — exceto admin que vê todas.

### D-04: Endpoints consumidos

Wrapper Phase 22 já tem todos:

| Wrapper method | Endpoint | Cache |
|---|---|---|
| `seller(custId)` | `/sellers/{custId}` | proxy |
| `sellerMetricasMensal(custId)` | `/sellers/{custId}/metricas/mensal` | 1h |
| `sellerMedalhas(custId)` | `/sellers/{custId}/medalhas` | 6h |
| `sellerSignals(custId)` | `/sellers/{custId}/signals` | 5min |

### D-05: 5 seções da página

1. **Header**: nome + cust_id + medalha atual (programa + nível) + segmento ML + cadência grant
2. **KPI cards (snapshot mês atual)**: GMV, TSI (transações), ACOS, TACOS, scores Full e PADS, margem
3. **Gráfico evolução 12 meses**: linha GMV + linha investimento ADS (duplo eixo Y, mesmo padrão Phase 24)
4. **Histórico de medalhas**: timeline mostrando promoções/rebaixamentos ao longo do tempo
5. **Alertas ativos**: lista de signals daquele seller (não-ackeados primeiro)

### D-06: Reuso de componentes Phase 24

`KpiCard` (com helpText) e o pattern do `HistoricoChart` podem ser reusados/copiados. Sem nova dependência.

### D-07: Onde linkar

3 pontos de entrada para esta página:
1. **Cards de Alertas Estratégicos**: quando alerta tem company resolvida, nome vira link para `/empresas/{id}/analise-ecf`
2. **Dashboard**: futuro (não nesta fase) — botão "Ver análise ECF" em cards de empresa
3. **Companies/Index** (lista de empresas): futuro — coluna ou botão de ação

### D-08: Tradução de termos do classificador interno do ML

Reusar `CLUSTER_LABELS`, `FRETE_LABELS`, `PROGRAMA_LABELS` da Phase 24. Mover para arquivo compartilhado `resources/js/lib/ecfDriveLabels.js` (refactor leve).

### D-09: Try/catch global

Mesmo padrão Phases 23/24. Se ECF Drive cair, página carrega com props vazias + flash error pt-BR.

### D-10: Empresa sem cust_id

Algumas empresas não têm `adman_account_id` nem `ml_store_id` (Shopee/Amazon que não foram cadastradas via planilha, ou empresas cadastradas só pelo Comercial). Página exibe mensagem clara: "Esta empresa não tem ID de lojista do Mercado Livre cadastrado — análise ECF Drive não disponível."

### D-11: Empresa fora da carteira ECF Drive

Empresa pode ter cust_id mas não estar no universo do ECF Drive (cust_id de uma empresa Shopee no Adman não corresponde a nada ML). `seller()` retorna 404. Mostrar mensagem: "Esta empresa não foi encontrada na base do parceiro ECF Drive."

## Success Criteria

1. **Nova rota** `/empresas/{company}/analise-ecf` → `EmpresaAnaliseEcfController::show`. Middleware: `auth, verified, role:admin,consultor,mentor`.

2. **Controller** com try/catch global, lookup cust_id via accessor, chamadas paralelas (5 chamadas wrapper), retorna props ou erro pt-BR.

3. **Frontend `EmpresaAnaliseEcf/Show.jsx`** com 5 seções listadas em D-05.

4. **Linkar a partir dos Alertas Estratégicos**: `AlertaCard.jsx` muda o link da empresa de Dashboard para `/empresas/{id}/analise-ecf` quando há company resolvida.

5. **Acesso seguro**: admin vê todas; consultor/mentor só veem empresas da sua carteira.

6. **Empty states**:
   - Sem cust_id → mensagem explicativa
   - cust_id mas sem dados no ECF Drive → mensagem explicativa
   - ECF Drive offline → flash error
   - Sem alertas → "Nenhum alerta para este seller"
   - Sem medalhas → "Sem histórico de medalhas registrado"

7. **Refactor pequeno**: extrair `CLUSTER_LABELS/FRETE_LABELS/PROGRAMA_LABELS` da Phase 24 BreakdownTabs para `lib/ecfDriveLabels.js`. Atualizar BreakdownTabs e Show.jsx para importar de lá.

8. **Testes Feature** (mínimo 6): 200 admin + 200 consultor com empresa na carteira + 403 consultor sem essa empresa + 403 publicador + 200 sem cust_id (com mensagem) + erro ECF (com flash).

## Mapa de arquivos

### Backend novos
- `app/Http/Controllers/EmpresaAnaliseEcfController.php`

### Backend modificados
- `routes/web.php` — adiciona 1 rota

### Frontend novos
- `resources/js/Pages/EmpresaAnaliseEcf/Show.jsx`
- `resources/js/Pages/EmpresaAnaliseEcf/components/EmpresaHeader.jsx`
- `resources/js/Pages/EmpresaAnaliseEcf/components/HistoricoMedalhas.jsx`
- `resources/js/Pages/EmpresaAnaliseEcf/components/AlertasDoSeller.jsx`
- `resources/js/Pages/EmpresaAnaliseEcf/components/EvolucaoEmpresaChart.jsx`
- `resources/js/lib/ecfDriveLabels.js` (NOVO — refactor extraído da Phase 24)

### Frontend modificados
- `resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx` — importa labels do novo `lib/ecfDriveLabels.js`
- `resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx` — quando company resolvida, link vai pra `/empresas/{id}/analise-ecf` (não mais Dashboard)

### Testes novos
- `tests/Feature/Phase25/EmpresaAnaliseEcfControllerTest.php` (6+ testes)

### Não tocar
- `EcfDriveService` (Phase 22 — usa como está)
- `CompanyController` (Phase 25 não toca no fluxo existente)
- `Dashboard` (link das phases 23 deixa de apontar pra lá em casos específicos)

## Pitfalls antecipados

1. **5 chamadas ao ECF Drive no controller** (seller + metricasMensal + medalhas + signals + rankingDoSeller) — cache do wrapper absorve. Cold cache ~5-8s na primeira vez. Aceitar.

2. **`/sellers/{custId}` pode retornar 404** quando cust_id não existe no ECF Drive (típico de empresas Shopee/Amazon ou cust_ids antigos). Capturar 404 separado da exceção geral e mostrar mensagem específica (D-11).

3. **Endpoint medalhas vazio** quando seller é novato. UI mostra "Sem histórico de medalhas registrado" sem quebrar layout.

4. **Endpoint signals do seller pode ter 778 alertas** se for seller crítico — limitar `?limit=20` na chamada do wrapper.

5. **Authorization mentor/consultor**: relação `User->companies()` é via pivot `company_users`. Verificar que `where('id', $company->id)` no exists() bate. Reusar pattern do Sugadores/MlbsByCompany (Phase 19).

6. **Refactor labels do Phase 24**: testes Phase 24 podem falhar se os labels mudaram de import path. Rodar suite antes de commit.

## Não-objetivos

- Comparação entre 2+ sellers (Phase 25.1 ou futura — `/sellers/comparar` existe no wrapper)
- Edição de qualquer campo (read-only)
- Histórico de campanhas (Adman MCP — mantém Sugadores)
- Ranking dinâmico/refresh (snapshot mensal)
- Export PDF (futura)
- Compartilhar link público
- Dashboard executivo (Phase 24)
- Concentração/forecast (Phase 27)

## Cross-cutting constraints

- pt-BR em tudo
- `npm run build` no fim
- Try/catch global
- Tradução de termos ML reusada (ecfDriveLabels.js)
- Sem migration / model / activity log
- Reusar shadcn + recharts + lucide-react já no projeto
- ECF Drive é fonte da verdade

## Referências

- API-GUIDE.md §5 — Sellers (mina de ouro estratégica)
- `EcfDriveService::seller, sellerMetricasMensal, sellerMedalhas, sellerSignals` (Phase 22)
- Phase 23 (`AlertaCard.jsx` lookup company) — padrão a estender com link para análise
- Phase 24 (`KpiCard.jsx`, breakdown labels) — componentes a reusar/refatorar
- Memory `feedback_lean_planning.md` — pular research/discuss

## Memory persistente relevante

- **Lean planning** — direto pro PLAN
- **GSD output em pt-BR**
- **Autorização permanente para deploy**
- **Acertividade** — dados oficiais ML via ECF Drive
- **Praticidade** — tudo sobre 1 empresa num único lugar
