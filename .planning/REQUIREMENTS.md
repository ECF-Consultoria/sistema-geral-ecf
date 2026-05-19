# Requirements: ECF Admin

**Última atualização:** 2026-05-19

---

## Milestone v2.0 — Administrativo Fechamento

**Definido:** 2026-05-19
**Core Value:** Admin pode ver o faturamento de cada empresa, sua faixa de investimento e o total a cobrar no mês — sem acessar o servidor ou planilhas externas.

### Fechamento (FCH)

- [ ] **FCH-01**: Admin pode ver todas as empresas cadastradas na tela Fechamento, incluindo empresas sem integração Adman (exibidas com badge "Sem integração")
- [ ] **FCH-02**: Admin pode configurar o tipo de serviço de cada empresa (POLO / Assessoria / Incubadora)
- [ ] **FCH-03**: Admin pode registrar e ver as datas de início e encerramento do contrato de cada empresa
- [x] **FCH-04**: Admin pode ver o faturamento mensal de cada empresa calculado dos dados diários sincronizados (adman_metrics.revenue), com indicação do período coberto
- [x] **FCH-05**: Admin pode ver a faixa de investimento e o valor mensal a cobrar de cada empresa, calculados automaticamente pela tabela de progressão
- [ ] **FCH-06**: Admin pode ver barra de progresso com posição na faixa atual e quanto falta para a próxima; faixa máxima (>R$5M) exibe "Faixa máxima" sem barra
- [ ] **FCH-07**: Admin pode ver o total consolidado a cobrar no mês (soma apenas de empresas com dados válidos)
- [ ] **FCH-08**: Admin pode ver campo de serviço adicional por empresa (visível, sem lógica de valor neste milestone)

### Configuração (CFG)

- [ ] **CFG-01**: O label "Financeiro" no sidebar e na página é renomeado para "Fechamento"

## Out of Scope (v2.0)

| Feature | Motivo |
|---------|--------|
| Emissão de NF / boleto | Não é sistema de cobrança — é painel de consulta |
| Edição da tabela de faixas via UI | Regra de negócio estável; mudança via deploy |
| Pro-rata de dias no mês | Complexidade sem valor no MVP |
| Régua de dunning / cobrança automática | Fora do escopo do painel administrativo |
| Histórico mensal de fechamentos | v2.1+ |
| Exportação para CSV/Excel | v2.1+ |
| Lógica de valor para serviço adicional | Campo reservado; lógica definida em milestone futuro |

## Traceability v2.0

| Requisito | Fase | Status |
|-----------|------|--------|
| FCH-01 | Phase 5 | Pending |
| FCH-02 | Phase 5 | Pending |
| FCH-03 | Phase 5 | Pending |
| FCH-04 | Phase 6 | Complete |
| FCH-05 | Phase 6 | Complete |
| FCH-06 | Phase 7 | Pending |
| FCH-07 | Phase 7 | Pending |
| FCH-08 | Phase 7 | Pending |
| CFG-01 | Phase 7 | Pending |

**Cobertura v2.0:**
- Requirements: 9 total
- Mapeados para fases: 9
- Não mapeados: 0 ✓

---

## Milestone v1.0 — Setor Dev (referência histórica)

**Definido:** 2026-05-18 | **Status:** Phase 1 completa; Phases 2–4 pausadas (v3.0)

### Entregues (Phase 1 ✓)

- [x] **DEV-01**: Admin pode ver data/hora do último sync Adman por empresa
- [x] **DEV-02**: Admin pode ver o payload bruto retornado pela API Adman (ou erro HTTP) de cada sync
- [x] **DEV-03**: Admin pode ver o diff do sync: quantos registros foram criados, atualizados e ignorados
- [x] **DEV-04**: Admin pode disparar o sync Adman de uma empresa específica manualmente via botão

### Pausados (retomar em v3.0)

- [ ] **DEV-05**: Admin pode ver status da fila de jobs (pendentes, em execução, falhados com detalhe do erro)
- [ ] **DEV-06**: Admin pode ver logs recentes do sistema (errors e warnings) sem acessar o servidor
- [ ] **DEV-07**: Admin pode ver informações do ambiente (versão PHP, driver de fila, driver de cache, uptime)
- [ ] **DEV-08**: Admin pode visualizar e editar configurações/flags do sistema

---

*Requirements v2.0 definidos: 2026-05-19*
