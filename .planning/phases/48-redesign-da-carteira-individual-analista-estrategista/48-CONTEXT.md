# Phase 48: Redesign da carteira individual (analista + estrategista) — Context

**Gathered:** 2026-06-29
**Status:** Ready for research
**Source:** Síntese lean — briefing UI 2026-06-12 (`48-UI-BRIEF.md`) + briefing 2026-06-29 Items 2/3/4/5 (`.planning/todos/pending/270629-melhorias-carteira-desempenho-gamificacao-ml.md`)

<domain>
## Phase Boundary

Modernizar a tela de carteira individual (`/portfolio/{id}` ou rota equivalente — research vai confirmar) para:

1. **UI mais moderna e operacional** — hero card com gradiente + faturamento em destaque (formato `R$ X.XXM`), KPIs simplificados e acionáveis, gráfico com tooltip, tabela orientada a ação, painel lateral estratégico. Mobile responsiva.
2. **Indicador de crescimento por empresa** na listagem — mini-gráfico sparkline (verde subindo / vermelho descendo / cinza regular) em cada linha.
3. **Histórico de NPS** do dono da carteira — bloco mostrando notas recebidas ao longo do tempo, média do período, última nota, count de avaliações.
4. **Remover meta agregada da carteira** — DECISÃO LOCKED. Meta é por empresa (já existe), definida no onboarding. Tirar cards "Meta da carteira", "% atingido", "R$ restante pra meta". Categoria "atingimento de meta" no PortfolioScoreService vira média ponderada de % das empresas individuais.
5. **Bloco diferenciado por função:**
   - Carteira ANALISTA: bloco de Sugadores (resolvidos / pendentes / não-resolvidos)
   - Carteira ESTRATEGISTA: bloco de PPAs (concluídos no período)

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED

- **DENTRO** da Phase 48:
  - Redesign UI da carteira individual (briefing detalhado em 48-UI-BRIEF.md)
  - Mini-gráfico crescimento por empresa na listagem
  - Histórico NPS do profissional
  - Remover meta agregada da carteira + ajuste PortfolioScoreService categoria "atingimento de meta"
  - Bloco diferenciado analista vs estrategista (UI dos counters — backend dos counters é Phase 47)
- **FORA** da Phase 48:
  - Página de carteira CONSOLIDADA admin (visão de todos profissionais — não é a individual, é a Carteiras/Index — provavelmente não está em escopo aqui)
  - Modificação do fluxo de entrada de empresa pra incluir meta no onboarding — seed `.planning/seeds/270629-modificar-entrada-empresa-meta-onboarding.md` (futuro, post-Phase 48)
  - Backend dos counters de sugador/PPA pro score — Phase 47 (independente; Phase 48 mostra os counters via contagem direta dos models se 47 não estiver pronta)
  - Rankings em /performance — Phase 49
  - Histórico longitudinal de scores na página /performance — Phase 46

### Direção visual locked (do briefing 48-UI-BRIEF.md)

- **Hero card:** gradiente, faturamento em destaque, formato compacto `R$ X.XXM` (K para milhares, M para milhões com 2 decimais)
- **Indicadores no hero:** crescimento vs período anterior (chip), % meta atingido (chip — agora calculado como média das empresas individuais), R$ restante (chip — soma dos restantes individuais)
- **KPIs secundários** (poucos e acionáveis): Empresas na carteira, Meta da carteira (média ponderada), Prioridade do dia (empresas exigindo ação), Investimento Ads (valor + cobertura)
- **Gráfico:** Realizado / Meta acumulada (soma das empresas) / Projeção; tooltip rico
- **Tabela de empresas** orientada a ação: Empresa / Status / Faturamento / Meta / Margem / Ads / Ação recomendada / **+ Mini-gráfico crescimento** (item 2 do briefing 2026-06-29)
- **Painel lateral estratégico:** ações estratégicas (ativar ads parados, renovar grants vencidos, abaixo de 50% meta, quedas), top faturamento, **+ Histórico NPS** (item 3 do briefing 2026-06-29)
- **Responsivo:** card principal mantém topo no mobile; KPIs empilham; tabela vira cards; sem overflow horizontal

### Princípios de produto (briefing UI seção "Resultado esperado")

A tela deve priorizar TOMADA DE DECISÃO, não exibição de métricas. Profissional deve responder rapidamente:
- Qual é o faturamento atual da carteira?
- Estamos perto ou longe da meta?
- Quais empresas merecem atenção hoje?
- Quais empresas sustentam o resultado?
- Qual ação prática devo tomar em cada empresa?

### Diferenciação analista vs estrategista

- **Analista** (`role=consultor` + cargo analista): bloco de Sugadores no painel lateral ou em seção dedicada
  - Counters: resolvidos / pendentes / não-resolvidos (queries diretas em `sugadores` table por `user_id` do dono)
  - Sinergia com Phase 47: quando 47 entregar, este bloco também mostra impacto no score
- **Estrategista** (cargo estrategista): bloco de PPAs no painel lateral ou em seção dedicada
  - Counter: PPAs concluídos no período (queries em models relevantes — research vai mapear)
  - Sinergia com Phase 47: idem analista

### Claude's Discretion (decidir no planner ou execução)

- Caminho de migração da query/service de carteira: refator total vs reescrita componente-por-componente
- Componentes a extrair pra reuso (talvez `Carteira/HeroCard.jsx`, `Carteira/TabelaEmpresas.jsx`, `Carteira/SparklineCrescimento.jsx`, `Carteira/NpsHistory.jsx`)
- Critério de classificação do mini-gráfico crescimento: % de variação threshold (ex: ±2% = regular) — decidir com base em distribuição real
- Library de sparkline: `recharts` puro vs `react-sparklines` — `recharts` já está no projeto, preferir
- Testes: PHPUnit Feature (backend) + visual UAT (frontend) — sem testes JS de UI pesada
- Forma do "Ação recomendada" da tabela: derivar de regras (status + delta) ou usar campo livre — decidir baseado no que existe hoje

</decisions>

<specifics>
## Specific Ideas

### Formato de valores compactos (locked do briefing UI)

- `R$ 891.040,00` → `R$ 891K`
- `R$ 3.469.401,00` → `R$ 3.46M`
- `R$ 20.200.857,90` → `R$ 20.20M`

Criar helper utility (provavelmente `resources/js/lib/formatBRL.js` ou estender `formatCurrency` existente) com modo compacto opcional.

### Tabela de empresas — exemplo do briefing UI

| Empresa | Status | Faturamento | Meta | Margem | Ads | Ação | **Crescimento** |
|---|---:|---:|---:|---:|---:|---|---|
| COMILOPARTSFILIAL | Saudável | R$ 8.45M | 96% | 44.2% | R$ 318K | Manter ritmo | 📈 verde |
| RELOJOARIA WENUS | Crítico | R$ 3.46M | 84% | 37.5% | R$ 82K | Renovar grant | 📉 vermelho |
| Dmov | Atenção | R$ 4.14K | 45% | 21.0% | R$ 0 | Ativar Ads | ➡ cinza |

### Painel lateral estratégico — exemplo do briefing UI

Seções sugeridas:
- **Ações estratégicas:** ativar ads parados / renovar grants vencidos / abaixo de 50% meta / quedas vs período anterior
- **Top faturamento:** ranking das empresas com maior faturamento
- **Histórico NPS:** gráfico de evolução + média + última nota (NOVA — item 3 do briefing 2026-06-29)

### Mockup HTML existente

O briefing UI menciona `carteira-analistas-ui-proposta.html` como referência visual estática. Research deve checar se esse arquivo existe no repo ou foi compartilhado externamente. Se existir, usar como source visual.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Briefings e captura

- `.planning/phases/48-redesign-da-carteira-individual-analista-estrategista/48-UI-BRIEF.md` — briefing UI completo (237 linhas) — **FONTE PRIMÁRIA**
- `.planning/todos/pending/270629-melhorias-carteira-desempenho-gamificacao-ml.md` — briefing umbrella 2026-06-29 (Items 2, 3, 4, 5)
- `.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md` — briefing umbrella anterior (referência para contexto)

### Patterns existentes (a investigar/reusar)

- `app/Http/Controllers/PortfolioController.php` (assumido nome — research confirma) — endpoint da carteira individual
- `app/Services/PortfolioScoreService.php` — categoria "atingimento de meta" precisa migrar (média de % por empresa)
- `resources/js/Pages/Portfolio/` ou `resources/js/Pages/Carteira/` — page React a redesignar
- `resources/js/lib/utils.js` (ou similar) — helpers de formato (`formatCurrency`) — estender com modo compacto
- Phases 31-33 (NPS mensal automatizado + customização + perguntas customizadas) — fonte do histórico NPS por profissional
- Phase 15 (sugadores cards empresa eficiência) — pattern de counters por usuário (sugadores resolvidos/pendentes)
- Pattern Recharts: ver `resources/js/Pages/Polos/RoseChart.jsx` ou similares — referência de uso atual no projeto

### Arquivos a investigar (research vai listar)

- `routes/web.php` — rota da carteira individual (provavelmente `portfolio.show` ou `carteira.show`)
- `app/Models/User.php` — relations: companies (via company_users), NPS responses recebidos
- `app/Models/Company.php` — relação com User (analista/estrategista) via pivot company_users
- `app/Models/Sugador.php` — counters de sugadores por dono
- Models de PPA (existir? Phase 31+ tem algo sobre PPA — research investiga)
- Tabelas relevantes: `nps_responses` (Phases 31-33), `sugadores`, talvez `ppas` ou similar

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade
- `feedback_gsd_language_pt_br` — pt-BR em commits/comentários/UI
- `feedback_perguntar_antes_deploy_v9` — confirmar caso-a-caso antes do deploy
- `project_atribuicao_profissionais` — pivot company_users; analista = role consultor; cargo via user_setores
- `project_mariadb_local_corrompido` — testes via SQLite in-memory + Mockery
- `project_legacy_columns_rename` — Phase 7 renomeou colunas User legacy (`publication_role/setor/cargo` → `*_legacy`)

</canonical_refs>

<deferred>
## Deferred Ideas

- **Modificação do fluxo de entrada de empresa** (definir meta no onboarding) — seed `.planning/seeds/270629-modificar-entrada-empresa-meta-onboarding.md`. Trigger natural: quando Phase 48 fechar (carteira sem meta agregada deixa o gap visível).
- **Carteira CONSOLIDADA admin** (visão de TODOS profissionais — `/portfolio/carteiras` ou similar) — não está em escopo da Phase 48 individual. Pode virar Phase futura se houver demanda.
- **Backend dos counters de sugador/PPA pra score** — Phase 47 (entrega o cálculo de score com novo balanceamento; Phase 48 só consome a contagem direta dos models).
- **Histórico longitudinal de scores** — Phase 46 (entrega `desempenho_score_snapshots`; Phase 48 não depende — usa scoring atual).
- **Rankings por função em /performance** — Phase 49 (usa o score diferenciado da Phase 47).

</deferred>

---

*Phase: 48-redesign-da-carteira-individual-analista-estrategista*
*Context gerado: 2026-06-29 (síntese lean — briefing UI + umbrella 2026-06-29, sem discuss-phase interativo per memory `feedback_lean_planning`)*
