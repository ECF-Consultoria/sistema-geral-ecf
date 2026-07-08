# Requirements: ECF Admin — Milestone v15.0

**Defined:** 2026-07-07
**Milestone:** v15.0 — NPS Templates
**Core Value:** Transformar o NPS de "3 perguntas fixas + extras globais" em "modelos configuráveis de formulário", com pesos por opção, cálculo por dimensão, dedup mensal, dashboards de pendência e UX limpa. Zero uso de Promotor/Neutro/Detrator — escala 1-5 sempre.

## v15.0 Requirements

Requirements do milestone atual. Cada um mapeia para exatamente uma phase no ROADMAP.md.

### NPS-A — Schema, modelos e migração de dados existentes

- [x] **NPS-A-01**: Sistema tem tabelas `nps_templates`, `nps_template_questions`, `nps_template_options`, `nps_template_service_scopes` e `nps_response_answers` criadas com constraints e índices apropriados
- [x] **NPS-A-02**: Modelos Eloquent + relationships definidos (`NpsTemplate hasMany Questions`, `Question hasMany Options`, `Template belongsToMany Servico` via pivot, `NpsResponse hasMany Answers`)
- [x] **NPS-A-03**: Migração de dados legados cria template seed "NPS Padrão" e retro-associa 100% dos `nps_surveys` existentes; nenhuma survey fica órfã sem `template_id`
- [x] **NPS-A-04**: `nps_response_answers` armazena snapshot completo (question_texto, option_label, peso) por resposta — mudanças futuras no template não alteram histórico

### NPS-B — Backend: regras de negócio, cálculo e dispatch

- [ ] **NPS-B-01**: `NpsTemplateService` resolve o template correto para uma empresa dado seus serviços ativos (via `nps_template_service_scopes`); retorna o template default se nenhum específico aplicar
- [ ] **NPS-B-02**: `NpsScoreCalculator` computa nota por dimensão (`estrategista`, `analista`, `empresa`, `geral`) como média dos pesos das opções escolhidas nas perguntas daquela dimensão; retorna `null` para dimensão sem perguntas correspondentes
- [ ] **NPS-B-03**: Sistema bloqueia gerar/responder NPS duplicado para mesma `(company_id, month_reference, template_id)` — via unique index parcial + guard no controller. Link redundante mostra tela "Já respondida no mês"
- [x] **NPS-B-04**: Comando `nps:disparar-mensal` usa `NpsTemplateService` pra resolver template correto por empresa; empresas sem template aplicável são puladas com log
- [ ] **NPS-B-05**: Validação server-side do formulário público é dinâmica — deriva regras (obrigatoriedade, tipo, range de pesos) do template snapshot associado à survey, não de defaults hardcoded

### NPS-C — UI de configuração (admin)

- [ ] **NPS-C-01**: Admin pode criar, editar e desativar templates de NPS via `/nps/configuracao` (novo layout multi-template)
- [ ] **NPS-C-02**: Admin pode adicionar, editar, reordenar e excluir perguntas dentro de um template (`escala`, `opcoes` como tipos suportados)
- [ ] **NPS-C-03**: Admin pode configurar opções de resposta de cada pergunta com label visível ao cliente + peso interno (1-5+) + ordem; reordenação drag-and-drop ou arrows
- [ ] **NPS-C-04**: Admin pode marcar dimensão de cada pergunta (`estrategista`, `analista`, `empresa`, `geral`) e obrigatoriedade
- [ ] **NPS-C-05**: Admin pode associar templates a tipos de serviço via pivot `nps_template_service_scopes` — decide qual template cada tipo de empresa recebe
- [ ] **NPS-C-06**: Admin vê preview live do formulário público renderizado a partir do template em edição, sem persistir mudanças

### NPS-D — Formulário público (cliente respondendo)

- [ ] **NPS-D-01**: Formulário público `/nps/{token}` renderiza dinamicamente a partir do template snapshot associado à survey — sem hardcode de 3 perguntas fixas
- [ ] **NPS-D-02**: Perguntas com opções renderizam como radio group com estilo cinza no estado padrão + amarelo no estado ativo (opção selecionada); mobile-friendly
- [ ] **NPS-D-03**: Perguntas obrigatórias são visualmente marcadas; submit desabilita até que todas obrigatórias estejam preenchidas (validação client-side + confirmação server-side)
- [ ] **NPS-D-04**: Formulário preserva telas `ThankYou`, `AlreadyCompleted` e `Expired` — comportamento inalterado
- [ ] **NPS-D-05**: Formulário público não usa jargão técnico (`unified`, `dimensao`, `snapshot`) — labels apresentadas ao cliente em pt-BR simples

### NPS-E — Dashboards e pendências de resposta

- [ ] **NPS-E-01**: Sistema tem configuração global "dia de cobrança mensal" (int 1-31) que dispara marcação de pendência a partir daquele dia do mês
- [ ] **NPS-E-02**: Listagem de empresas em carteira (`Portfolio/Show.jsx` e `Companies/Index.jsx`) mostra badge/indicador visual quando empresa está em pendência de NPS do mês corrente
- [ ] **NPS-E-03**: Dashboard do analista/estrategista mostra contagem/lista de empresas pendentes de NPS no mês corrente na sua carteira
- [ ] **NPS-E-04**: Sistema tem `NpsPendingService` que retorna a lista de empresas pendentes por carteira; base preparada para futura integração com sistema de notificações (não obrigatório integrar nesta phase, mas contrato definido)
- [ ] **NPS-E-05**: Dashboards existentes (`Dashboard/Admin.jsx`, `Performance/Dashboard.jsx`, `Companies/Show.jsx`) leem nota via `NpsScoreCalculator` e exibem médias por dimensão respeitando `template_snapshot`

### NPS-F — Limpeza de legado + testes E2E

- [ ] **NPS-F-01**: Zero cálculo `>=9 Promotor / >=7 Neutro / else Detrator` restante no código (grep confirma remoção em `PerformanceController.php`, `Performance/Dashboard.jsx`, qualquer outro loco)
- [ ] **NPS-F-02**: Referências legadas a `score_overall`, `score_consultant`, `score_mentor` removidas de `Companies/Show.jsx` (fechamento do Plan 31-05 nunca finalizado)
- [ ] **NPS-F-03**: `CalculateGoalResults` (`app/Jobs/CalculateGoalResults.php:155`) implementa cálculo real para `metric='nps'` usando `NpsScoreCalculator` — não cai mais no branch `null`
- [ ] **NPS-F-04**: Suite de tests E2E cobre: criação de template, perguntas com pesos, resposta pública, cálculo por dimensão, bloqueio de duplicata, dispatch idempotente, empresa pendente aparece corretamente, template sem analista funciona sem quebrar

## Future Requirements

<!-- Reconhecidos mas fora do escopo desta milestone. -->

### NPS avançado

- **NPS-FUTURE-01**: UI para ajustar janelas de expiração (7d manual / 30d auto) via config sem deploy
- **NPS-FUTURE-02**: UI para ajustar horário do disparo mensal (hoje 9h America/Sao_Paulo) via config sem deploy
- **NPS-FUTURE-03**: Integração real com sistema de notificações (in-app + email interno) quando pendência é detectada — hoje NPS-E-04 só prepara o contrato
- **NPS-FUTURE-04**: Suporte a mais tipos de pergunta no template (`sim_nao`, `texto`, `multipla` — hoje só `escala` e `opcoes`)
- **NPS-FUTURE-05**: A/B testing de templates (mesma empresa/serviço recebe templates diferentes por período pra medir engajamento)

### Herança pausada (v14.0)

- Todas as REQs de v14.0 que ainda não foram entregues ficam preservadas em `.planning/milestones/v14.0-REQUIREMENTS-wip.md`:
  - **META-02, META-03, META-05** (Phase 63 planejada)
  - **PERF-01, PERF-02, PERF-03** (Phase 64/65 sem plans)
  - **UX-01, UX-02, UX-03** (Phase 66 sem plans)
  - **SUGA-01, SUGA-02, SUGA-03, SUGA-04** (Phase 67 sem plans)

## Out of Scope

| Feature | Motivo |
|---------|--------|
| NPS clássico 0-10 | Decisão explícita do usuário — escala 1-5 é a definitiva. Zero uso de Promotor/Neutro/Detrator |
| Retentar/rerender survey já respondida (idempotência) | Depois de responder, survey vira read-only. Editar histórico compromete audit trail |
| Migração automática das perguntas customizadas globais existentes em templates separados | Serão migradas como "perguntas extras" dentro do template "NPS Padrão" seed; separação manual fica pra Future se demandado |
| Sistema de notificação interna (in-app + email) integrado nesta phase | NPS-E-04 prepara contrato `NpsPendingService`; integração real fica pra NPS-FUTURE-03 |
| Modelo de perguntas com tipo `texto`/`sim_nao`/`multipla` no template | Hoje `NpsPerguntaCustomizada` suporta esses tipos mas na v15.0 template usa só `escala` e `opcoes` — extensão fica pra NPS-FUTURE-04 |
| Deploy automatizado da milestone | Deploy gate ativo — [[feedback_perguntar_antes_deploy_v9]] |

## Traceability

<!-- Preenchido pelo gsd-roadmapper em 2026-07-07 após ROADMAP.md v15.0. -->

| Requirement | Phase | Status |
|-------------|-------|--------|
| NPS-A-01 | Phase 68 | Complete |
| NPS-A-02 | Phase 68 | Complete |
| NPS-A-03 | Phase 68 | Complete |
| NPS-A-04 | Phase 68 | Complete |
| NPS-B-01 | Phase 69 | Pending |
| NPS-B-02 | Phase 69 | Pending |
| NPS-B-03 | Phase 69 | Pending |
| NPS-B-04 | Phase 69 | Done (2026-07-08 — Plan 69-05) |
| NPS-B-05 | Phase 69 | Pending |
| NPS-C-01 | Phase 70 | Pending |
| NPS-C-02 | Phase 70 | Pending |
| NPS-C-03 | Phase 70 | Pending |
| NPS-C-04 | Phase 70 | Pending |
| NPS-C-05 | Phase 70 | Pending |
| NPS-C-06 | Phase 70 | Pending |
| NPS-D-01 | Phase 71 | Pending |
| NPS-D-02 | Phase 71 | Pending |
| NPS-D-03 | Phase 71 | Pending |
| NPS-D-04 | Phase 71 | Pending |
| NPS-D-05 | Phase 71 | Pending |
| NPS-E-01 | Phase 72 | Pending |
| NPS-E-02 | Phase 72 | Pending |
| NPS-E-03 | Phase 72 | Pending |
| NPS-E-04 | Phase 72 | Pending |
| NPS-E-05 | Phase 72 | Pending |
| NPS-F-01 | Phase 73 | Pending |
| NPS-F-02 | Phase 73 | Pending |
| NPS-F-03 | Phase 73 | Pending |
| NPS-F-04 | Phase 73 | Pending |

**Coverage:**
- v15.0 requirements: 29 total
- Mapped to phases: 29 ✓
- Unmapped: 0

**Distribuição por phase:**
- Phase 68 (NPS-A — Schema): 4 REQs
- Phase 69 (NPS-B — Backend): 5 REQs
- Phase 70 (NPS-C — UI Config): 6 REQs
- Phase 71 (NPS-D — Form público): 5 REQs
- Phase 72 (NPS-E — Dashboards): 5 REQs
- Phase 73 (NPS-F — Limpeza + testes): 4 REQs

---
*Requirements defined: 2026-07-07*
*Last updated: 2026-07-07 — traceability preenchida pelo gsd-roadmapper (29/29 REQs mapeadas para Phase 68-73)*
