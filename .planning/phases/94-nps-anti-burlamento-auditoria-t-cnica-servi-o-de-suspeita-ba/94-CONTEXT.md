# Phase 94: NPS Anti-Burlamento — auditoria técnica + serviço de suspeita (backend) — Context

**Gathered:** 2026-07-16
**Status:** Ready for planning
**Source:** PRD Express Path (`PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md`, seções 1-2 + trilha de eventos; escopo reduzido no import de 2026-07-16)

<domain>
## Phase Boundary

Toda abertura e resposta de link NPS deixa rastro técnico (IP, user-agent, horários, duração) e um serviço central (`NpsSuspicionService`) avalia e persiste se a resposta é suspeita — **backend only**, sem nenhuma mudança visível para quem responde e sem nenhuma exibição na UI interna (a UI admin-only é a Fase 95; endurecimento/bloqueio é a Fase 96).

**FORA desta fase:** badges/filtros/seção de auditoria na UI (Fase 95); bloqueio de resposta de usuário logado (Fase 96 — aqui apenas MARCA como suspeita); configuração de IPs pela UI (Fase 96 — aqui é `.env`/config); qualquer coisa de Digisac ou unicidade mensal (já entregues na v15.5/v16.0).

</domain>

<decisions>
## Implementation Decisions

### Rastro de abertura (AB-94-1)
- GET `/nps/{token}` (`NpsController::respond`) registra no survey: `first_opened_at` (só na primeira), `last_opened_at` (sempre), `open_count` (incrementa), IP e user-agent da abertura
- Aberturas múltiplas preservam o primeiro registro — nunca sobrescrever `first_opened_at`

### Rastro de resposta (AB-94-2)
- Submit (`NpsController::submitResponse`) registra na resposta: `response_ip_address`, `response_user_agent`, `response_duration_seconds` (delta entre criação/geração do survey e o submit)
- Registrar SEMPRE, para todo submit — a coleta é silenciosa e universal

### Trilha de eventos (AB-94-3)
- Tabela `nps_survey_events`: `id`, `survey_id` (FK), `event_type` (generated | opened | submitted | expired | sent_email | sent_digisac), `ip_address` nullable, `user_agent` nullable, `user_id` nullable, `metadata` json nullable, `created_at`
- Fluxos existentes passam a emitir eventos: geração manual de link, disparo mensal (email → `sent_email`, Digisac → `sent_digisac`), abertura, submit, expiração
- A trilha é auditoria viva — preferida no plano original justamente para facilitar investigações

### NpsSuspicionService (AB-94-4)
- Serviço central em `app/Services/Nps/` avalia no submit e persiste `is_suspicious` (bool) + `suspicion_reasons` (json, textos em pt-BR) na resposta
- Regra 1 — IP interno ECF: IP da resposta ∈ `ECF_INTERNAL_IPS` (lista) ou `ECF_INTERNAL_CIDRS` (redes) → suspeita. Motivo: "Resposta enviada a partir da rede interna da ECF."
- Regra 2 — resposta rápida: `generated_at → responded_at` ≤ janela configurável (default 60s) → suspeita. Motivo: "Resposta enviada em menos de 1 minuto após geração do link." (texto deve refletir a janela configurada)
- Regra 3 — combinação IP interno + rápida → severidade maior. Motivo: "Link gerado e respondido rapidamente a partir da rede interna."
- Regra 4 — sessão autenticada: abertura/resposta com usuário interno logado → **marca como suspeita** (motivo: "Resposta realizada em sessão autenticada de usuário interno."). NÃO bloquear nesta fase — bloqueio é Fase 96
- Config em `.env`: `ECF_INTERNAL_IPS`, `ECF_INTERNAL_CIDRS`, janela em segundos — expostos via arquivo de config (ex.: `config/nps.php` ou seção em config existente do NPS)

### Retrocompatibilidade (AB-94-5)
- Todos os campos novos nullable; nenhum backfill obrigatório
- Surveys/respostas legadas sem rastro continuam funcionando em todas as telas e agregações
- Migration deve seguir as armadilhas conhecidas do projeto: branch SQLite para enum/CHECK se aplicável, `->nullable()` antes de `nullOnDelete`, idempotência

### Claude's Discretion
- Onde exatamente guardar os campos de abertura (colunas no `nps_surveys` vs. derivar de `nps_survey_events`) — decidir no planejamento considerando custo de query; o plano original sugere colunas agregadas (`first_opened_at`, `last_opened_at`, `open_count`) + tabela de eventos
- Nome/estrutura exata do config (novo `config/nps.php` vs. chave em config existente)
- Detecção de severidade: representação de "severidade maior" dentro de `suspicion_reasons` (ex.: campo `severity` no json)
- Como capturar IP atrás de proxy (X-Forwarded-For / trusted proxies) — seguir o setup real do VPS
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano original e roadmap
- `PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md` — plano canônico do usuário (seções 1, 2 e 9/Fase 1)
- `.planning/ROADMAP.md` — seção "Phase 94" (REQs AB-94-1..5 e success criteria)

### Código NPS existente (pontos de integração)
- `app/Http/Controllers/NpsController.php` — `respond()` (~linha 519) e `submitResponse()` (~linha 669); geração manual de link
- `app/Console/Commands/NpsDispararMensal.php` — disparo mensal email + Digisac (emitir `sent_email`/`sent_digisac`)
- `app/Models/NpsSurvey.php` — survey (token, expires_at, status, month_reference, template_id)
- `app/Models/NpsResponse.php` — resposta (destino de `is_suspicious`/`suspicion_reasons`)
- `app/Services/Nps/` — namespace onde nasce o `NpsSuspicionService` (padrão: `NpsPendingService`, `NpsSnapshotService`)
- `app/Services/Digisac/NpsDigisacDispatchService.php` — fluxo de envio Digisac (ponto de emissão `sent_digisac`)

</canonical_refs>

<specifics>
## Specific Ideas

- Motivos de suspeita em pt-BR legível (regra do projeto: zero jargão sem explicação — memória de feedback 2026-07-07)
- O cliente que responde NÃO percebe nada — mesma UX pública
- Nada de suspeita aparece em NENHUMA tela nesta fase (nem para admin) — exposição é a Fase 95; porém os dados devem nascer prontos para a 95 consumir
- Janela de tempo configurável (default 60s) — nunca hardcode

</specifics>

<deferred>
## Deferred Ideas

- UI de confiança admin-only (badge/filtros/auditoria) → Fase 95
- Bloqueio de resposta em sessão interna, IPs pela UI, invalidação manual → Fase 96
- Generalização do módulo Digisac (tabela polimórfica `digisac_messages`) → backlog, quando houver segundo consumidor

</deferred>

---

*Phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba*
*Context gathered: 2026-07-16 via PRD Express Path*
