# Phase 23: Alertas Estratégicos (signals — caixa de entrada do comercial)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 22 (EcfDriveService::listSignals + ackSignal)
**Milestone:** v8.0 — Integração Estratégica ECF Drive

## Goal

Criar **a primeira aba visível da Milestone v8.0**: `/alertas-estrategicos` que consome `/signals` da API ECF Drive em polling diário (MVP — webhook em Phase 26) e exibe os 778 alertas estratégicos como uma **caixa de entrada do comercial**. Cada alerta tem severidade, tipo, empresa relacionada e ação "marcar como visto".

**Por que esta fase importa agora:** a Phase 22 (wrapper) é invisível ao usuário; o usuário relatou que não percebeu mudança no sistema após a Phase 22. Esta phase entrega o **primeiro valor visível** da v8.0 — uma aba nova com 778 alertas categorizados que o time comercial pode acionar imediatamente.

## Origem da fase

API-GUIDE.md §7 expõe `/signals` com 5 tipos de alertas detectados automaticamente:

| Tipo | Severidade | Trigger |
|---|---|---|
| `seller.gmv_queda_mom` | warning, critical | GMV mês-a-mês < -30% (critical se < -50%) |
| `seller.queda_visitas` | warning, critical | Visitas mês-a-mês < -40% (critical se < -60%) |
| `seller.medalha_rebaixada` | warning, critical | Mudança em NIVEL_SOLUCION para pior |
| `seller.score_critico` | warning | `score_final_full < 30` ou `score_qualidade_final < 30` |
| `seller.oportunidade_pads` | info | GMV mensal > R$ 10k mas inv_pads = 0 ou score_pads = 0 |

**Status atual em prod (smoke W4 Phase 22):** **778 alertas detectados**, sendo 61 críticos. Cada um é uma oportunidade de ação comercial — sem esta aba, ficam invisíveis.

## Decisões já travadas

### D-01: Convivência com Sugadores (não substituir)

Sugadores (Phase 15+19) detecta no nível **adgroup** (campanhas individuais) com critério próprio "gasto sem venda". Continua operando exatamente como hoje. Alertas Estratégicos é nível **seller** e usa critérios da plataforma ML/ECF Drive. **São complementares.**

### D-02: Polling MVP (webhook na Phase 26)

Nesta phase usamos polling: aba carrega dados via `EcfDriveService::listSignals()` (cache 1min). Phase 26 adiciona webhook `signal.detected` para push real-time.

### D-03: Sem persistência local nesta fase

Alertas vivem na API ECF Drive (eles mantêm `ack_at`). UI consome direto. Ack via `POST /signals/:id/ack` invalida cache local (já implementado na Phase 22).

**Implicação:** se ECF Drive cair, aba mostra erro amigável. Aceitável (cache 1min cobre intermitências, e o time pode usar Sugadores enquanto isso).

### D-04: Acesso

Restrito a roles **admin** + **consultor** + **mentor** (mesmo padrão de Dashboard). Publicadores MLB e analistas não precisam — não é caixa de entrada deles.

### D-05: Localização no menu

**Item dedicado no topo da sidebar**, dentro da seção principal (ao lado de Dashboard, Sugadores). Ícone `Bell` ou `AlertTriangle` do lucide-react. Badge numérico mostrando contagem de alertas críticos não-ackeados (visual urgência).

### D-06: UI principal — lista filtrada

Layout de **caixa de entrada do email**:
- Filtros no topo: Severidade (info/warning/critical), Tipo (5 opções), Status (não-visto / todos / visto)
- Lista paginada (50 por página) com cards por alerta
- Cada card: severidade colorida + tipo legível + empresa (lookup por cust_id) + payload resumido (ex: "GMV caiu 76% em maio") + data de detecção + botão "Marcar como visto"
- Sem filtro por empresa nesta phase (Phase 25 trará isso)

### D-07: Tradução dos tipos para PT-BR

Os `event_type` da API são técnicos. UI mostra labels amigáveis:

| API event_type | Label PT-BR | Cor |
|---|---|---|
| `seller.gmv_queda_mom` | Queda de faturamento | Vermelho (critical) / Amarelo (warning) |
| `seller.queda_visitas` | Queda de visitas | Vermelho/Amarelo |
| `seller.medalha_rebaixada` | Medalha rebaixada | Vermelho/Amarelo |
| `seller.score_critico` | Score crítico | Amarelo |
| `seller.oportunidade_pads` | Oportunidade de ADS | Azul (info) |

### D-08: Lookup cust_id → empresa nossa

Para cada alerta, tentamos resolver `cust_id` → `Company` em prod (`adman_account_id` ou `ml_store_id`, mesma estratégia do Plano 02 Phase 20). Se bate: mostra nome da empresa + link pra Dashboard com filtro. Se NÃO bate (cust_id de fora da carteira): mostra "Cliente externo" + cust_id bruto.

### D-09: Backend mínimo — controller que delega

`AlertasController::index` chama wrapper, faz lookup de companies, retorna props Inertia. `AlertasController::ack($id)` chama `EcfDriveService::ackSignal()`. Sem migration, sem model novo, sem activity log (a fonte da verdade é o ECF Drive).

## Inventário dos 778 alertas (smoke Phase 22)

Baseado em listSignals com limit alto. Vamos confirmar contagens exatas no smoke W4 desta fase.

| Tipo | Estimativa | Ação comercial |
|---|---|---|
| `seller.gmv_queda_mom` | ~92 | Ligar pro cliente: "vimos queda no faturamento" |
| `seller.queda_visitas` | ~132 | Investigar SEO/ranking dos produtos |
| `seller.medalha_rebaixada` | ? | Plano de recuperação |
| `seller.score_critico` | ~519 | Onboarding de qualidade |
| `seller.oportunidade_pads` | ~35 | Pitch de PADS |
| **Total** | **778** (61 críticos) | |

## Success Criteria

1. **Nova rota** `/alertas-estrategicos` → `AlertasController::index` → `Inertia::render('AlertasEstrategicos/Index')`. Middleware: `auth, verified, role:admin|consultor|mentor`.

2. **Nova rota** `POST /alertas-estrategicos/{id}/ack` → `AlertasController::ack` que invoca `EcfDriveService::ackSignal($id)`. Validate Inertia + back() com flash success.

3. **`AlertasController::index`**:
   - Lê query params: `severity` (info/warning/critical), `event_type`, `acked` (true/false), `page` (default 1)
   - Chama `$ecf->listSignals(['severity' => ..., 'event_type' => ..., 'acked' => ..., 'page' => ..., 'limit' => 50])`
   - Faz lookup de companies por cust_id em batch (1 query — `Company::whereIn('adman_account_id', ...)->orWhereIn('ml_store_id', ...)->get()`)
   - Retorna props: `signals`, `companies_map` (cust_id → {id, name, slug}), `stats` (totais por severidade/tipo), `filters` (eco dos query params)

4. **`AlertasEstrategicos/Index.jsx`**:
   - Header com 3 cards: total críticos / total warnings / total info (KPI cards)
   - Linha de filtros (severidade Select + tipo Select + checkbox "mostrar já vistos")
   - Lista paginada (50/page) com card por alerta:
     - Badge de severidade (cor)
     - Label PT-BR do tipo
     - Nome da empresa (link Dashboard ?company=N) OU "Cliente externo: {custId}"
     - Resumo: payload formatado pt-BR (ex: "GMV caiu 76,46% (de R$ 47.315 para R$ 11.135)")
     - Data: "há 2 horas" via dateFns
     - Botão "Marcar como visto" inline (router.post com preserveScroll)

5. **Item na sidebar**:
   - Posição: dentro da seção principal, próximo de Sugadores
   - Ícone: `AlertTriangle` (lucide-react)
   - Label: "Alertas Estratégicos"
   - Badge numérico vermelho ao lado quando há críticos não-ackeados (lookup via shared prop)

6. **Shared prop `alertas_criticos_count`**:
   - Adicionar em `HandleInertiaRequests::share()` retornando `EcfDriveService::listSignals(['severity' => 'critical', 'acked' => false, 'limit' => 1])['total']` com cache 5min (chamada leve)
   - Frontend AppLayout lê e mostra badge

7. **Acessibilidade**:
   - Tabela é navegável por teclado (botões ack focusable)
   - Cores não são única indicação (texto também)
   - Loading states em ações (botão ack vira spinner durante request)

8. **Testes Feature**:
   - `AlertasControllerTest`: index retorna 200 auth admin / consultor / mentor; 403 publicador MLB; 302 guest
   - Index com filtros: chama wrapper com os params certos (mockando wrapper)
   - Ack: POST chama wrapper.ackSignal() com ID certo
   - Mínimo 6 testes

## Mapa de arquivos

### Backend novos
- `app/Http/Controllers/AlertasController.php` (NOVO) — `index()`, `ack(int $id)`

### Backend modificados
- `routes/web.php` — adiciona grupo `/alertas-estrategicos` com middleware
- `app/Http/Middleware/HandleInertiaRequests.php` — adiciona `alertas_criticos_count` em shared

### Frontend novos
- `resources/js/Pages/AlertasEstrategicos/Index.jsx` (NOVO)
- `resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx` (NOVO — card individual)
- `resources/js/Pages/AlertasEstrategicos/components/StatsHeader.jsx` (NOVO — 3 KPI cards)

### Frontend modificados
- `resources/js/Layouts/AppLayout.jsx` — adiciona item "Alertas Estratégicos" com badge de críticos

### Testes novos (em `tests/Feature/Phase23/`)
- `AlertasControllerTest.php` (6 testes mínimo)

### Não tocar (escopo bloqueado)
- `EcfDriveService` (Phase 22 — usar como está)
- Sugadores (decisão D-01)
- Migration / model / activity log (decisão D-03)
- Webhook (Phase 26)

## Pitfalls antecipados

1. **API ECF Drive offline ao carregar `/alertas-estrategicos`** — wrapper lança RuntimeException. Mitigação: `AlertasController::index` cathes Throwable e retorna props vazias + flash error "Não foi possível buscar alertas agora. Tente em alguns segundos."

2. **Lookup de companies devagar com 778 alertas paginados** — 50 alertas por página = 50 cust_ids → 1 query `whereIn`. Mitigação: índice já existe em `adman_account_id` e `ml_store_id`.

3. **Shared prop `alertas_criticos_count` adiciona latência em CADA pageload** — Mitigação: cache 5min + try/catch que retorna `null` em erro (badge some). Mesmo padrão de `sugadores_pendentes` que já existe.

4. **Payload variável entre tipos de alerta** — cada `event_type` tem payload diferente. Mitigação: função PT-BR `formatPayload(eventType, payload)` no JSX que tem switch por tipo.

5. **Filtros nos query params + back button** — Inertia preserva, mas testar com 3 filtros simultâneos.

6. **Ack via POST com Inertia (preserveScroll, preserveState)** — UX: clica ack → card some da lista (filtrada por acked=false) sem scroll resetar.

## Não-objetivos

- Webhook real-time (Phase 26)
- Notificação no sino do header (Phase 26 com webhook signal.detected → cria notification)
- Drilldown por empresa (Phase 25)
- Histórico de ações ackeadas
- Configuração de thresholds dos alertas (vive no ECF Drive)
- Comentários / atribuição de responsável

## Cross-cutting constraints

- pt-BR em tudo
- `npm run build` após cada JSX
- Sem deploy automático (W4 é gate humano)
- snake_case nas props Inertia
- Reusar componentes shadcn (Card, Badge, Select, Button)
- Sem migration
- ECF Drive é fonte da verdade — não duplicar dados localmente

## Referências

- API-GUIDE.md §7 — Signals
- `EcfDriveService::listSignals`, `ackSignal` (Phase 22)
- Phase 19 (Sugadores) — padrão de "caixa de entrada" do operador
- Phase 12 (Notificações cleanup) — padrão de filtro por status
- Memory `feedback_lean_planning.md` — pular research/discuss
- Memory `feedback_project_priorities.md` — acertividade + praticidade

## Memory persistente relevante

- **Lean planning** — direto pro PLAN
- **GSD output em pt-BR**
- **Acertividade** — alertas vêm direto da fonte (ECF Drive), sem duplicação
- **Praticidade** — 1 clique pra ack, lookup de empresa direto, badge numérico no menu
