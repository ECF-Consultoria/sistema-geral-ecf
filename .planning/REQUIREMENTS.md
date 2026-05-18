# Requirements: ECF Admin — Setor Dev

**Defined:** 2026-05-18
**Core Value:** Tornar o sync Adman completamente observável e controlável sem precisar de acesso direto ao servidor

## v1 Requirements

### Diagnóstico Adman

- [ ] **DEV-01**: Admin pode ver data/hora do último sync Adman por empresa
- [ ] **DEV-02**: Admin pode ver o payload bruto retornado pela API Adman (ou erro HTTP) de cada sync
- [ ] **DEV-03**: Admin pode ver o diff do sync: quantos registros foram criados, atualizados e ignorados
- [ ] **DEV-04**: Admin pode disparar o sync Adman de uma empresa específica manualmente via botão

### Monitoramento de Jobs

- [ ] **DEV-05**: Admin pode ver status da fila de jobs (pendentes, em execução, falhados com detalhe do erro)

### Observabilidade

- [ ] **DEV-06**: Admin pode ver logs recentes do sistema (errors e warnings) sem acessar o servidor
- [ ] **DEV-07**: Admin pode ver informações do ambiente (versão PHP, driver de fila, driver de cache, uptime)

### Configurações

- [ ] **DEV-08**: Admin pode visualizar e editar configurações/flags do sistema

## v2 Requirements

### Alertas

- **ALERT-01**: Admin recebe notificação quando um job falha
- **ALERT-02**: Admin recebe alerta quando sync Adman não roda há mais de X horas

### Histórico

- **HIST-01**: Admin pode ver histórico completo de syncs por empresa (paginado)
- **HIST-02**: Admin pode exportar logs de sync para CSV

## Out of Scope

| Feature | Motivo |
|---------|--------|
| Acesso por roles não-admin | Dados sensíveis (payloads API, configs) não devem vazar |
| Deploy / CI via painel | Complexidade fora do escopo atual |
| Edição de código pelo navegador | Escopo de IDE |
| Monitoramento de infraestrutura externa | Além do processo Laravel |
| WebSockets / streaming em tempo real | Polling suficiente para o volume atual |

## Traceability

| Requisito | Fase | Status |
|-----------|------|--------|
| DEV-01 | — | Pending |
| DEV-02 | — | Pending |
| DEV-03 | — | Pending |
| DEV-04 | — | Pending |
| DEV-05 | — | Pending |
| DEV-06 | — | Pending |
| DEV-07 | — | Pending |
| DEV-08 | — | Pending |

**Cobertura:**
- v1 requirements: 8 total
- Mapeados para fases: 0
- Não mapeados: 8 ⚠️

---
*Requirements defined: 2026-05-18*
*Last updated: 2026-05-18 after initial definition*
