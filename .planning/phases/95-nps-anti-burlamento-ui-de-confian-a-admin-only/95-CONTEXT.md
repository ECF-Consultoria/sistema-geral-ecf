# Phase 95: NPS Anti-Burlamento — UI de confiança admin-only — Context

**Gathered:** 2026-07-16
**Status:** Ready for planning
**Source:** PRD Express Path (`PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md`, seção 3; ROADMAP Fase 95)

<domain>
## Phase Boundary

Admin enxerga a camada de confiança construída na Fase 94 (badge na listagem, filtros, seção de auditoria técnica no detalhe); **qualquer outro papel não recebe nem sinal de que ela existe — inclusive no payload Inertia**. A Fase 94 está COMPLETA: dados de rastro, `nps_survey_events` e vereditos `is_suspicious`/`suspicion_reasons` já são persistidos.

**FORA desta fase:** bloqueio de resposta de usuário logado, configuração de IPs pela UI, invalidação manual (tudo Fase 96); qualquer mudança nas páginas PÚBLICAS de resposta (`Respond`/`ThankYou`/etc. ficam intocadas).

</domain>

<decisions>
## Implementation Decisions

### Badge na listagem (AB-95-1)
- Listagem de NPS respondidos ganha indicador visual de confiança APENAS para role `admin`:
  - **verde** = confiável (sem motivos de suspeita)
  - **amarelo** = atenção (suspeita de severidade média — 1 regra isolada)
  - **vermelho** = suspeita (severidade alta — combinação IP interno + resposta rápida, conforme JSON `suspicion_reasons.severity` da Fase 94)
- Linha do NPS para admin mostra o status de confiança junto dos dados existentes (empresa, mês, nota, respondido em, canal)
- Para não-admin a listagem permanece IDÊNTICA à de hoje — sem coluna, badge ou qualquer indício

### Seção de auditoria no detalhe (AB-95-2)
- No detalhe/modal do NPS, seção "Auditoria" visível só para admin com:
  - Gerado em / Gerado por
  - Aberto em (first/last + contagem de aberturas)
  - Respondido em / Tempo até resposta
  - IP da abertura / IP da resposta
  - User-agent
  - Canal de envio (email/Digisac/manual — derivável de `nps_survey_events`)
  - Motivos de suspeita (textos pt-BR gravados pela Fase 94)
- Exemplo de exibição de motivo: "Resposta enviada a partir da rede interna da ECF." — linguagem clara, zero jargão técnico cru (regra sistêmica do projeto)

### Filtros (AB-95-3)
- Filtro apenas para admin: **Todos / Confiáveis / Com alerta / Suspeitos**
- Não-admin não vê o filtro nem consegue usá-lo via query string (validação no backend)

### Blindagem de payload (AB-95-4)
- Para não-admin o controller NÃO envia NENHUM campo de suspeita/auditoria nos props Inertia — ocultação no backend, nunca só na renderização
- Teste automatizado deve provar: payload de não-admin não contém `is_suspicious`, `suspicion_reasons`, IPs, user-agent, timestamps de abertura
- O gate de role usa o padrão existente do projeto (`User::isAdmin()` / role `admin`)

### Claude's Discretion
- Onde exatamente fica o badge na listagem (coluna própria vs. ícone junto da nota) — seguir o layout atual da tela de NPS respondidos
- Componente de badge: reutilizar padrão de badge existente no projeto (tokens `ecf-*`, dark theme)
- Como derivar "canal de envio" (evento `sent_email`/`sent_digisac` mais recente vs. metadata) — decidir no planejamento lendo o que a Fase 94 gravou
- Se a seção de auditoria carrega os eventos completos (`nps_survey_events`) ou só os campos agregados — balancear utilidade vs. custo de query
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Fase 94 (fundação — o que já existe)
- `.planning/phases/94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba/94-01-SUMMARY.md` — schema, `NpsSurveyEvent`, `NpsSuspicionService`, `config/nps.php`
- `.planning/phases/94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba/94-02-SUMMARY.md` — controller instrumentado, shape do JSON `suspicion_reasons`
- `app/Services/Nps/NpsSuspicionService.php` — semântica de severidade
- `app/Models/NpsSurveyEvent.php` / `app/Models/NpsResponse.php` / `app/Models/NpsSurvey.php`

### Superfície de UI (onde a fase mexe)
- `app/Http/Controllers/NpsController.php` — actions que montam a listagem/detalhe de NPS respondidos (admin)
- `resources/js/Pages/Nps/Index.jsx` — listagem NPS (tela interna)
- `resources/js/Layouts/AppLayout.jsx` — gate de role no front (padrão existente)
- `PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md` — seção 3 (exibição na UI)

</canonical_refs>

<specifics>
## Specific Ideas

- Rótulos pt-BR simples: "Confiável" / "Atenção" / "Suspeita" — nunca "flag", "fraud score" etc.
- Regra do projeto: todo elemento novo segue tokens `ecf-*`, dark-first, `cn()` para composição
- `npm run build` obrigatório ao final (fase toca frontend)
- A camada continua registrando tudo por baixo dos panos para todos os usuários — só a EXIBIÇÃO é admin-only

</specifics>

<deferred>
## Deferred Ideas

- Bloqueio de resposta em sessão interna, IPs pela UI, invalidação manual → Fase 96

</deferred>

---

*Phase: 95-nps-anti-burlamento-ui-de-confian-a-admin-only*
*Context gathered: 2026-07-16 via PRD Express Path*
