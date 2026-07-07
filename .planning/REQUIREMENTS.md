# Requirements: ECF Admin — Milestone v14.0

**Defined:** 2026-07-07
**Milestone:** v14.0 — Confiabilidade + Polish
**Core Value:** Consolidar acertividade dos dados (fontes ML + Adman unificadas) e polir usabilidade antes de escalar novas features sobre uma base ainda instável.

## v14.0 Requirements

Requirements do milestone atual. Cada um será mapeado para exatamente uma phase no ROADMAP.md.

### DATA — Unificação de fontes de dados (ML + Adman)

<!-- Continua numeração de DATA-01/02/03 já entregues em v13.0 (Phase 57). -->

- [ ] **DATA-04**: Sistema calcula métricas agregadas de uma empresa lendo tanto `adman_metrics` (fonte Adman) quanto tabelas ML nativas (fonte ML), sem quebrar quando uma das fontes está ausente
- [ ] **DATA-05**: Cada métrica exibida na UI carrega indicador visual da fonte (badge/tooltip: ML, Adman, ou Agregado)
- [ ] **DATA-06**: Empresas conectadas a AMBAS as fontes têm métricas conciliadas sem duplicação — regra de precedência documentada em ADR

### DASH — Dashboards e Carteira multi-fonte

<!-- Continua numeração de DASH-01/02/03 já entregues em v13.0 (Phase 58). -->

- [ ] **DASH-04**: Dashboard Mercado Livre (`/dashboard/mercadolivre`) contabiliza empresas com fonte-ML E fonte-Adman num único KPI unificado (não duplica, não ignora)
- [ ] **DASH-05**: Dashboard do Analista e Estrategista respeita a fonte de cada empresa da carteira sem quebrar — empresas ML-only não geram erro Adman; empresas Adman-only aparecem normalmente
- [ ] **DASH-06**: Carteira individual exibe badge visual "ML" ao lado do nome de empresa conectada ao Mercado Livre

### META — Metas: usabilidade + onboarding

<!-- Consolida seed 270629 (meta no onboarding). -->

- [ ] **META-01**: Usuário visualiza a meta atribuída de uma empresa e o progresso mensal numa apresentação clara (chart + percentual + valor absoluto)
- [ ] **META-02**: Fluxo de criação/onboarding de empresa inclui campo obrigatório "meta inicial mensal" (Mercado Livre)
- [ ] **META-03**: Empresas legadas sem meta ganham tratamento explícito na UI (flag visível "Meta não definida" com CTA para definição) — sem default arbitrário
- [ ] **META-04**: Tela de gestão de metas suporta edição rápida (inline ou bulk) e mostra histórico de alterações
- [ ] **META-05**: Alterações de meta são registradas em `activity_log` com autor, valor anterior e novo valor

### PERF — Parâmetro "forma de uso do sistema"

- [ ] **PERF-01**: Sistema captura métrica "quantidade de análises de sugadores rodadas" por usuário/empresa/período (exemplo canonical do parâmetro de uso)
- [ ] **PERF-02**: Dashboard de desempenho exibe dimensão "uso do sistema" complementar aos KPIs comerciais existentes (coluna, gráfico ou painel dedicado)
- [ ] **PERF-03**: Arquitetura de captura suporta adicionar novos eventos de uso no futuro (ex: qtd de acessos ao MLB, freq de edição de metas) sem refactor — event-based extensível

### UX — Bug Fixes e polish

- [ ] **UX-01**: Fluxo OAuth Mercado Livre não exibe tela de erro pro cliente quando a conexão foi bem-sucedida no admin — investigar e corrigir erro cosmético client-side (redirect, callback, ou race condition)
- [ ] **UX-02**: `/companies` tem filtro "Conectada ao Mercado Livre" com opções (Sim / Não / Qualquer) integrado aos filtros existentes
- [ ] **UX-03**: Sidebar reorganiza a hierarquia visual entre botão de recolher (hoje fixo na rolagem) e botão voltar (não-fixo) para eliminar confusão dos operadores em teste

### SUGA — Sugadores refinements (escopo raso)

<!-- Usuário disse que vai aprofundar Sugadores em milestone própria depois — aqui só os casos concretos já observados. -->

- [ ] **SUGA-01**: Investigar e corrigir falso-negativo em By Mobile (sugadores que atendem parâmetros de detecção mas não aparecem no sistema)
- [ ] **SUGA-02**: Investigar e corrigir "dados defasados" ao copiar MLBs em Desk Design (erro na coleta, refresh ou cache)
- [ ] **SUGA-03**: Investigar sugadores existentes não encontrados em KAPRAKASA — validar contra o mesmo baseline usado em outras empresas
- [ ] **SUGA-04**: Página de configuração de sugadores fica mais simples (hierarquia visual mais clara, menos opções aparentes por padrão, agrupamento por intenção)

## Future Requirements

<!-- Reconhecidos mas fora do escopo desta milestone. -->

### Sugadores (aprofundamento dedicado)

- **SUGA-FUTURE-01**: Análise sistemática dos casos de "empresas que acham sugadores sem nenhum MLB" — investigação de root cause
- **SUGA-FUTURE-02**: Rework completo do motor de detecção de sugadores (se investigação SUGA-01/03 apontar limitações estruturais)

### Metas (extensões)

- **META-FUTURE-01**: Relatório "empresas abaixo de X% da meta" com trigger de alerta
- **META-FUTURE-02**: Migração automática de empresas legacy com sugestão baseada em revenue médio dos últimos 3 meses

### Multi-marketplace (herdado de v13.0)

- **DATA-FUTURE-01**: Agregação real cross-marketplace no ECF Dashboard (quando >0 empresas tiverem 2+ marketplaces reais)
- **DATA-FUTURE-02**: Migração completa para pivot N:N `whereHas('marketplaces', ...)` em todas queries transversais

## Out of Scope

| Feature | Motivo |
|---------|--------|
| Rework completo do motor de Sugadores | Escopo dedicado em milestone própria — usuário sinalizou aprofundamento posterior |
| Integração real de dados Shopee/Amazon | Ainda não há empresas com esses marketplaces; escopo v15+ quando o primeiro caso aparecer |
| Redesign completo de Metas | v14.0 foca em usabilidade + onboarding, não em novo produto; UX-focused, não feature-focused |
| WebSockets/broadcast para desempenho em tempo real | Polling atual atende — não é o gargalo dessa milestone |
| Migração dos 66 items "deferred" de v13.0 | Herança de v9-v12; endereçar caso-a-caso quando bater no roadmap, não em bulk |

## Traceability

<!-- Preenchido pelo gsd-roadmapper após aprovação. -->

| Requirement | Phase | Status |
|-------------|-------|--------|
| DATA-04     | TBD   | Pending |
| DATA-05     | TBD   | Pending |
| DATA-06     | TBD   | Pending |
| DASH-04     | TBD   | Pending |
| DASH-05     | TBD   | Pending |
| DASH-06     | TBD   | Pending |
| META-01     | TBD   | Pending |
| META-02     | TBD   | Pending |
| META-03     | TBD   | Pending |
| META-04     | TBD   | Pending |
| META-05     | TBD   | Pending |
| PERF-01     | TBD   | Pending |
| PERF-02     | TBD   | Pending |
| PERF-03     | TBD   | Pending |
| UX-01       | TBD   | Pending |
| UX-02       | TBD   | Pending |
| UX-03       | TBD   | Pending |
| SUGA-01     | TBD   | Pending |
| SUGA-02     | TBD   | Pending |
| SUGA-03     | TBD   | Pending |
| SUGA-04     | TBD   | Pending |

**Coverage:**
- v14.0 requirements: 21 total
- Mapped to phases: 0 (aguardando roadmapper)
- Unmapped: 21 ⚠️

---
*Requirements defined: 2026-07-07*
*Last updated: 2026-07-07 — initial definition for milestone v14.0 (Confiabilidade + Polish)*
