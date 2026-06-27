# Phase 44 — Mover adgroup-sugador para SGI via API ML — Context

**Gathered:** 2026-06-26
**Status:** Ready for research/planning
**Escopo revisto na discuss-phase:** Phase 44 foca APENAS em "Mover pra SGI". A ação "Pausar in-place" originalmente proposta no seed virou deferred (Phase 44b/45).

<domain>
## Domain Boundary

Expor 1 ação destrutiva via API ML Product Ads no `Show.jsx` do sugador-adgroup:

- **Mover adgroup-sugador para campanha SGI** (quarentena pausada). Sistema chama `PATCH` no endpoint de adgroup do ML mudando `campaign_id` para a SGI escolhida; opcionalmente cria a SGI nova se o operador pedir.

Eliminar os ~5 cliques redundantes no painel do Mercado Ads e dar rastreabilidade no histórico do sugador (`activity_log` + `Sugador.status = 'movido'`).

**Fora de escopo desta phase (deferred):**
- Pausar adgroup in-place (PATCH `status=paused` sem mudar campanha)
- Ações em lote (selecionar N sugadores no Index e mover todos juntos)
- Botão "Reverter pra campanha original" permanente (persistir `campaign_id_anterior`)

</domain>

<decisions>
## Implementation Decisions

### UI — botão de ação

- **1 único botão "Mover pra SGI"** no header do `Show.jsx`, posicionado ao lado dos botões existentes "Ver anúncio no ML" / "Painel de Ads"
- Botão amarelo (ecf-yellow), primário — chama o modal de confirmação
- Visível APENAS para `sugador.tipo === 'adgroup'` e `sugador.status NOT IN ('movido', 'resolvido')` (não faz sentido mover o que já fechou ciclo)

### Modal de confirmação — fluxo completo

1. **Combobox com SGI da conta**: lista campanhas que batem o regex `QUARANTINE_NAME_REGEX = '/\b(sgi|sugadores?)\b/iu'` (heurística já usada em `SugadorAnalysisService:46` — reaproveitar)
2. **Botão "Criar nova SGI"** no combobox (último item ou ao lado):
   - Estado inicial da SGI: **`paused`** (garante que adgroup movido não gasta)
   - Nome pré-preenchido: **`SGI [YYYY-MM]`** (ano-mês corrente, ex: `SGI 2026-06`) — input editável
   - Operador pode alterar o nome antes de confirmar
   - Cria via API ML (endpoint a definir no plan 44-01 smoke) e adiciona ao combobox
3. **Aviso de SGI ativa**: se a campanha escolhida tem `status='active'`, mostra warning não-bloqueante no modal: "⚠ Esta SGI está ATIVA — o adgroup continuará gastando após o move. Pause manualmente no painel ML depois". Operador pode prosseguir conscientemente.
4. **Confirmação dupla** obrigatória: nome literal do adgroup + nome da SGI destino exibidos no modal; botão "Confirmar mover" vermelho/destacado

### Execução do move

- Backend: `MercadoLivreAdsService::moveAdgroupToCampaign(int $advertiserId, string $adgroupId, int $newCampaignId)` — chama `PATCH /advertising/.../product_ad_groups/{id}` com `{campaign_id: newCampaignId}` (endpoint exato a confirmar via smoke do plan 44-01)
- Try/catch em `\Throwable`: se PATCH falhar, **NÃO atualiza** `Sugador.status` (rollback implícito por não persistir)
- Em caso de sucesso: `Sugador.status = 'movido'`, registra em `activity_log` (Spatie — `log_name='sugadores_acoes'`, descrição em pt-BR: `"moveu adgroup X de campanha Y para SGI Z"`)
- Refresh da página via Inertia reload para refletir novo status

### Undo

- **Toast com "Desfazer" por 10 segundos** após o move bem-sucedido (padrão Gmail/material)
- Se operador clica "Desfazer" dentro da janela: chama o mesmo endpoint backend com `campaign_id` original (que ficou em memória JS no toast handler — NÃO persiste no DB)
- Após 10s o toast some — reverter passa a ser via painel ML manualmente
- **Sem nova coluna no DB**, sem `campaign_id_anterior` persistido. Simplifica a phase

### Status do Sugador após move

- `Sugador.status` muda de `pendente`/`em_acao` para `'movido'` (já existe no enum — sem migration necessária)
- A próxima re-análise NÃO vai re-detectar o adgroup como sugador porque a SGI está em `QUARANTINE_NAME_REGEX` (filtro `shouldSkipCampaign` já pula adgroups em quarentena — comportamento existente)

### Claude's Discretion

Áreas não discutidas na discuss-phase — Claude resolve no plan/research:

- **Feature flag inicial**: usar config `features.sugadores_mover_sgi: bool` (default false em prod no primeiro deploy, switchable via env ou painel `/dev/desenvolvimento`). Habilitar pra `role:admin` primeiro, depois ampliar conforme estabilidade
- **Escopo do token OAuth**: validar no plan 44-01 (smoke) se o token atual aceita PATCH em adgroup. Se exigir re-auth com novo scope, plan 44-04 trata da UX (banner "Conceda permissão de escrita em /sistema/ml-oauth")
- **Tratamento de erro PATCH**: 401 → tentar refresh do token + 1 retry; 403 → erro "Token sem permissão de escrita" (action item: re-auth); 404 → erro "Adgroup não existe mais no ML" (atualizar `Sugador.status='resolvido'` com motivo `auto_removido_externamente`); 5xx → backoff curto + 2 retries; timeout (>30s) → aborta com toast vermelho
- **Confirmação visual de sucesso**: trust HTTP 200 do PATCH (não polling). Se ML refletir o move com delay (~2-5min na UI deles), tudo bem — nosso registro é autoritativo via activity_log
- **i18n**: tudo pt-BR (decisão de projeto `feedback_gsd_language_pt_br.md`)
- **Acessibilidade**: modal foca no input do combobox; ESC fecha; Enter confirma só quando combobox preenchido + checkbox de confirmação marcado

</decisions>

<specifics>
## Specific Ideas

### Referência ao seed que originou a phase

O seed `260626-acoes-ml-mover-sgi-pausar-via-api.md` propunha 2 ações (mover SGI + pausar in-place) com salvaguardas (confirmação dupla, activity_log, undo 5min, feature flag, rollback). A discuss-phase reduziu escopo:

- **Mover pra SGI**: mantido como única ação da Phase 44
- **Pausar in-place**: deferido (vide `<deferred>` abaixo)
- **Undo 5min com persistência DB**: simplificado para toast 10s sem persistência
- **Confirmação dupla, activity_log, feature flag, rollback**: mantidos

### Heurística SGI já existente no codebase (reaproveitar)

- `app/Services/SugadorAnalysisService.php:46` — constante `QUARANTINE_NAME_REGEX = '/\b(sgi|sugadores?)\b/iu'`
- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php:145-146` — já filtra essas campanhas no path ML
- O combobox da Phase 44 reusa esse regex pra listar SGIs existentes na conta

### Activity log

- Reaproveitar `Spatie\LogsActivity` já configurado no `Sugador` model
- `log_name='sugadores_acoes'` (mesmo namespace dos commits existentes "marcou_em_acao", "marcou_resolvido" etc)
- Adicionar novo verbo `moveu_para_sgi` à constante `Sugador::ACOES_AUDIT_LABEL` (frontend já tem mapa equivalente em `Show.jsx:54-69`)

</specifics>

<canonical_refs>
## Canonical References

- `.planning/ROADMAP.md` — Phase 44 entry (linhas ~117 + ~1008)
- `.planning/todos/pending/260626-acoes-ml-mover-sgi-pausar-via-api.md` — seed original (Tasks 4-7 do quick `260626-qgf`)
- `.planning/quick/260626-qgf-exibir-todos-mlbs-do-adgroup-sugador-no-/260626-qgf-SUMMARY.md` — contexto da migração ML que precedeu esta phase
- `app/Services/Sugadores/MercadoLivreAdsService.php` — wrapper read-only atual (ganhará método `moveAdgroupToCampaign` no plan 44-02)
- `app/Services/SugadorAnalysisService.php:46` — `QUARANTINE_NAME_REGEX` que define o que conta como SGI
- `app/Models/Sugador.php` — enum de status (já tem `'movido'`); `LogsActivity` trait pronto
- `resources/js/Pages/Sugadores/Show.jsx` — onde o botão "Mover pra SGI" vai entrar (header, ao lado de "Ver anúncio no ML")
- Memory `project_ml_only_companies_adman_endpoints.md` — empresas ML-only são o caso primário aqui
- Memory `project_sugadores_unique_key_inclui_adgroup_id.md` — relevante se a Phase 44 mexer com sugador upsert (não deve mexer — só atualiza status)

</canonical_refs>

<deferred>
## Deferred Ideas (capturadas durante discuss, fora do escopo Phase 44)

- **Pausar adgroup in-place** (PATCH `status=paused` sem mudar campanha) — Phase 44b ou 45. Originalmente parte do seed; operador reduziu o escopo da Phase 44 pra focar só em SGI (canonical organizacional)
- **Ações em lote** (selecionar N sugadores no Index e mover todos pra uma SGI) — Phase futura. Útil quando o operador faz triagem mensal
- **Botão "Reverter pra campanha original" permanente** (persistir `campaign_id_anterior`) — Phase futura. Implica migration nova e UI extra; o toast 10s cobre 95% dos casos "cliquei errado"
- **Auto-pause se SGI escolhida está ativa** — descartado; aviso não-bloqueante é suficiente. Se virar pegadinha recorrente em prod, vira nova phase

</deferred>

<scope_warnings>
## Scope Warnings (pré-requisitos validação)

Antes de planejar (`/gsd-plan-phase 44`), o **plan 44-01 deve fazer smoke do PATCH na API ML** pra validar:

1. Endpoint exato e schema do payload do `PATCH` em adgroup (mudar campaign_id)
2. Endpoint pra criar campanha SGI (`POST /advertising/.../campaigns` com `status=paused`?)
3. Resposta do ML em 401/403/404/5xx (definir comportamento por código)
4. Token OAuth atual aceita PATCH ou exige scope adicional (`advertising:write` ou similar)

**Se o smoke falhar** em qualquer um desses pontos, replanejar a Phase 44 conforme limitações reais. Não planejar tudo no escuro.

</scope_warnings>
