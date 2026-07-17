# Phase 96: NPS Anti-Burlamento — endurecimento e gestão — Context

**Gathered:** 2026-07-17
**Status:** Ready for planning
**Source:** PRD Express Path (`PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md`, seções 2.4/6 + Fase 6 do plano; ROADMAP Fase 96)

<domain>
## Phase Boundary

A camada de confiança passa de **observar** (Fases 94/95) para **agir e gerir**:
1. Usuário interno logado é BLOQUEADO de responder (upgrade do "marcar" da Fase 94).
2. IPs/CIDRs internos da ECF ficam configuráveis pela UI (não só `.env`).
3. Admin pode INVALIDAR uma resposta suspeita, com efeito consistente nas agregações (dashboards, NPS médio, bônus).

Fases 94 (backend de rastro + `NpsSuspicionService` + `nps_survey_events`) e 95 (UI admin-only) estão COMPLETAS e no ar em produção.

**FORA desta fase:** qualquer coisa de Digisac; refinamento das regras de suspeita além do que já existe; correlação histórica sofisticada (abertura logada → submit deslogado) — se surgir, é follow-up.

</domain>

<decisions>
## Implementation Decisions

### AB-96-1 — Bloqueio de sessão interna
- Resposta (submit) em sessão autenticada de usuário interno é BLOQUEADA — upgrade da Regra 4 da Fase 94 (que hoje só marca)
- Mensagem amigável ao usuário (não um 500/erro cru) — explicar que aquela sessão não pode responder; sem jargão
- O bloqueio é registrado como evento em `nps_survey_events` (novo tipo de evento ou metadata em evento existente — decidir no planejamento; o CONTEXT da Fase 94 travou 6 tipos, então avaliar se cria 7º `blocked` ou usa metadata)
- A ABERTURA (GET) continua permitida e registrada; o bloqueio é no SUBMIT (POST) — não revelar demais ao usuário interno o que dispara

### AB-96-2 — IPs internos pela UI
- IPs/CIDRs internos da ECF configuráveis pelo painel (tela NPS > Configuração — `resources/js/Pages/Nps/Configuracao.jsx`), apenas admin
- `.env` (`ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS` de `config/nps.php`, criados na Fase 94) permanece como fallback/default
- Persistência: decidir no planejamento (tabela de config, `settings`, ou coluna JSON) seguindo o padrão de config já usado no NPS
- `NpsSuspicionService` passa a ler a lista efetiva (UI ∪/precedência sobre `.env`) — a Fase 94 já lê de `config/nps.php`; estender a fonte sem quebrar a assinatura pública do service (suíte Nps verde)

### AB-96-3 — Invalidação manual
- Admin pode invalidar uma resposta suspeita (ação na UI da Fase 95 — listagem/modal), com trilha no `spatie/activitylog` (quem invalidou e quando)
- Resposta invalidada SAI das agregações de forma consistente:
  - Dashboards NPS / médias
  - Snapshots que alimentam o bônus — ATENÇÃO especial a `nps_response_scores` e `nps_score_assignments` (fonte do bônus v16.0, gravados pelo `NpsSnapshotService`)
- Mecanismo de invalidação: decidir no planejamento — flag `invalidated_at`/`invalidated_by` em `nps_responses` + filtro em TODAS as agregações, OU soft-exclusão dos snapshots. Preferir a abordagem que garanta que NENHUMA query de bônus/dashboard conte a resposta invalidada (o risco é esquecer um call-site)
- Idealmente reversível (revalidar) — decidir no planejamento

### Claude's Discretion
- 7º event_type `blocked` vs metadata em evento existente
- Estrutura de persistência dos IPs pela UI
- Flag de invalidação vs remoção de snapshot — priorizar consistência total das agregações
- Se a invalidação recalcula/remove os snapshots do `NpsSnapshotService` na hora ou apenas marca e filtra na leitura
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Fundação anti-burlamento (Fases 94/95 — já no ar)
- `app/Services/Nps/NpsSuspicionService.php` — Regra 4 (sessão autenticada) a ser endurecida; leitura de IPs de `config/nps.php`
- `config/nps.php` — chaves `ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS`/janela (Fase 94)
- `app/Models/NpsSurveyEvent.php` — tipos de evento (avaliar `blocked`)
- `app/Http/Controllers/NpsController.php` — `respond()`/`submitResponseV15()`/`submitResponseLegacy()` (ponto do bloqueio); `index()` (ação de invalidação admin)
- `.planning/phases/94-.../94-01-SUMMARY.md` e `94-02-SUMMARY.md`
- `.planning/phases/95-.../95-01-SUMMARY.md` (shape confianca/auditoria no payload)

### Agregações que a invalidação precisa respeitar (CRÍTICO — não esquecer call-site)
- `app/Services/Nps/NpsSnapshotService.php` — grava `nps_response_scores`, `nps_response_covered_services`, `nps_score_assignments` (fonte do bônus)
- Dashboards/métricas NPS que leem essas tabelas (mapear no research: DesempenhoScoreService, widgets home, DashboardController)
- Memória do projeto: `[[project_nps_modelo_principal]]` — o bônus lê `nps_score_assignments`

### UI de config e listagem
- `resources/js/Pages/Nps/Configuracao.jsx` — tela onde entram os IPs pela UI
- `resources/js/Pages/Nps/Index.jsx` — listagem/modal onde entra a ação "invalidar" (admin)

</canonical_refs>

<specifics>
## Specific Ideas

- Mensagens ao usuário e labels em pt-BR claro (regra sistêmica — zero jargão)
- Invalidação com trilha via `spatie/activitylog` (padrão do projeto em modelos principais)
- Baseline de regressão: suíte Nps verde (264 atual) + suítes V16/Desempenho (bônus) — a invalidação MEXE na fonte do bônus, então regressão do bônus é obrigatória
- `npm run build` ao final (toca frontend)
- Deploy exige preencher os IPs reais — mas agora será pela UI, não `.env`

</specifics>

<deferred>
## Deferred Ideas

- Correlação histórica abertura-logada → submit-deslogado (refinamento da detecção) — follow-up
- Generalização do Digisac (tabela polimórfica) — backlog

</deferred>

---

*Phase: 96-nps-anti-burlamento-endurecimento-e-gest-o*
*Context gathered: 2026-07-17 via PRD Express Path*
