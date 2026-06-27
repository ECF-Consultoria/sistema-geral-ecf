# Phase 44 — Mover adgroup-sugador para SGI via API ML — Context

**Gathered:** 2026-06-26
**Updated:** 2026-06-26 (pós-research — 3 mudanças críticas marcadas com `[POST-RESEARCH]`)
**Status:** Ready for planning
**Escopo revisto na discuss-phase:** Phase 44 foca APENAS em "Mover pra SGI". A ação "Pausar in-place" originalmente proposta no seed virou deferred (Phase 44b/45).

## ⚠ Mudanças críticas descobertas no research (44-RESEARCH.md)

1. **`[POST-RESEARCH]` Não existe endpoint para mover "adgroup" no ML.** A unidade movível é o **ad/item (= MLB)**. `moveAdgroupToCampaign()` é um wrapper sobre **N PUTs** em `PUT /marketplace/advertising/{site_id}/product_ads/ads/{item_id}` (1 por MLB do adgroup) OU 1 PUT coletivo em `PUT .../product_ads/ads` com array. Reusar `AdgroupMlbMapRepository::getMlbsForAdgroup` (já existe — Plan 39-03) pra obter a lista.

2. **`[POST-RESEARCH]` BLOQUEIO OAuth — token atual não tem scope `write`.** `MercadoLivreService.php:53` gera token com `'read offline_access'`. **TODOS os PUT/POST darão 403** sem re-auth. Plan 44-01 começa atualizando o scope na linha 53 + re-autorizando manualmente Bymobille; Plan 44-04 vira responsável pela UX de re-auth global (banner amarelo no `Show.jsx` quando `MlToken.scope` não contém `write`).

3. **`[POST-RESEARCH]` Falha parcial é cenário real.** Se adgroup tem 10 MLBs e 2 falham (ex: 1 em `hold`, 1 com 404), backend trata como **sucesso parcial com warning** — `Sugador.status='movido'` se ≥1 sucesso, activity_log registra counter `(success, failed)`, toast amarelo "Movido 8 de 10". NÃO tentar rollback (pode falhar também e piorar). Decisão tomada em §7.2 do RESEARCH.

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

### Execução do move `[POST-RESEARCH atualizado]`

- Backend: `MercadoLivreAdsService::moveAdgroupToCampaign(Company $company, string $adgroupId, int $newCampaignId): array` — internamente:
  1. Resolve `item_ids` (MLBs) do adgroup via `AdgroupMlbMapRepository::getMlbsForAdgroup($company->id, $adgroupId)`
  2. Para cada `item_id`, chama `PUT /marketplace/advertising/{site_id}/product_ads/ads/{item_id}?channel=marketplace` com body `{campaign_id: newCampaignId}` (header `api-version: 2` — confirmar variante no smoke)
  3. **Alternativa preferida (se confirmar no smoke):** 1 PUT coletivo `PUT .../product_ads/ads` com body `{ads: [...], campaign_id: ...}` — menos chamadas, mais atômico
  4. Retorna `['success' => int, 'failed' => int, 'failures' => [...]]` — sem throw em falha parcial
- Try/catch em `\Throwable` no controller: erro de rede/exception não-HTTP marca `Sugador.status` intacto + toast vermelho
- **Falha parcial:** `Sugador.status = 'movido'` se `success >= 1`; activity_log obrigatório (`log_name='sugadores_acoes'`, verbo `moveu_para_sgi`, properties `{success, failed, sgi_id, sgi_nome, campaign_id_original}`)
- **Sucesso total:** toast verde + Desfazer 10s
- **Sucesso parcial:** toast amarelo "Movido N de M anúncios. K falharam — ver detalhes no painel ML"
- Refresh da página via Inertia reload (sem `router.reload({only: [...]})` por enquanto — simples)

### Undo

- **Toast com "Desfazer" por 10 segundos** após o move bem-sucedido (padrão Gmail/material)
- Se operador clica "Desfazer" dentro da janela: chama o mesmo endpoint backend com `campaign_id` original (que ficou em memória JS no toast handler — NÃO persiste no DB)
- Após 10s o toast some — reverter passa a ser via painel ML manualmente
- **Sem nova coluna no DB**, sem `campaign_id_anterior` persistido. Simplifica a phase

### Status do Sugador após move

- `Sugador.status` muda de `pendente`/`em_acao` para `'movido'` (já existe no enum — sem migration necessária)
- A próxima re-análise NÃO vai re-detectar o adgroup como sugador porque a SGI está em `QUARANTINE_NAME_REGEX` (filtro `shouldSkipCampaign` já pula adgroups em quarentena — comportamento existente)

### Claude's Discretion + Decisões `[POST-RESEARCH]`

- **Feature flag inicial**: config `features.sugadores_mover_sgi: bool` (default false). Habilitar pra `role:admin` primeiro, depois ampliar. Frontend lê via prop Inertia
- **`[POST-RESEARCH]` Escopo OAhttps://** atualizar `MercadoLivreService.php:53` para `'read write offline_access'` no Plan 44-01 Tarefa 2 (code change + re-auth manual Bymobille); Plan 44-04 vira responsável pelo banner amarelo "Reconectar com permissão" em `Show.jsx` quando `MlToken.scope` da empresa não contém `write`
- **`[POST-RESEARCH]` Tratamento de erro por código HTTP** — mapeamento detalhado em RESEARCH.md §3. Resumo:
  - 401 → refresh token (já via `callWithBackoff`) + 1 retry transparente; se persistir, toast "Reconectar conta ML"
  - 403 → NÃO retentar; toast "Token sem permissão de escrita" + botão Reconectar
  - 404 em PUT → `Sugador.status='auto_resolvido'` motivo `auto_removido_externamente`; toast neutro
  - 409 → tratar como sucesso silencioso (idempotência defensiva)
  - 422 → toast vermelho com `message` do ML (ad em `hold`? campaign_id mal formatado?)
  - 5xx → `callWithBackoff` faz exponencial (max 5); estourou, toast vermelho
  - Timeout 30s → adicionar `Http::timeout(30)` no service; abort com toast
- **`[POST-RESEARCH]` Confirmação visual:** trust HTTP 2xx; sem polling. Cache local (combobox SGI no modal) atualizado via `router.reload({only: ['campanhas_sgi']})` após criar nova SGI
- **`[POST-RESEARCH]` Guard sugador Adman**: `moveAdgroupToCampaign` valida `Sugador::isOrigemMl()` antes de prosseguir; sugadores Adman antigos (raw_data ausente) abortam com 422 "Ação disponível apenas para sugadores Mercado Livre"
- **`[POST-RESEARCH]` Pegadinha api-version**: smoke 44-01 deve testar `api-version: 2` (minúsculo, valor 2) vs `Api-Version: 1` (atual nos GETs). Documentar qual funcionou
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

- **`44-RESEARCH.md`** — `[POST-RESEARCH]` documenta endpoints, scopes, mapeamento de erro, pegadinhas, recomendação de estrutura dos 4 plans. **Planner MUST ler.**
- **`44-UI-SPEC.md`** — design contract para o modal (combobox + criar SGI + double-confirm) e toast Desfazer. Decisão central: EVOLUIR `MoveToSgiModal` existente, não substituir
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
## Scope Warnings (pré-requisitos validação) `[POST-RESEARCH atualizado]`

O research fechou parcialmente a incerteza original. **Plan 44-01 continua obrigatório como smoke**, mas com escopo concreto vindo do RESEARCH:

1. **OAuth scope (BLOQUEIO)** — atualizar `MercadoLivreService.php:53` de `'read offline_access'` → `'read write offline_access'`; re-autorizar Bymobille manualmente; verificar `MlToken.scope` contém `write`. Sem isso, todos os PUTs darão 403 e o smoke trava
2. **Endpoint PUT em ad** — confirmar `PUT /marketplace/advertising/{site_id}/product_ads/ads/{item_id}?channel=marketplace` com body `{campaign_id: N}` retorna 2xx; testar header `api-version: 2` vs `Api-Version: 1`
3. **Endpoint POST campanha** — confirmar Variante A (`/marketplace/.../advertisers/{aid}/product_ads/campaigns`) com `status: paused` direto na criação; se 404, testar Variante B (`/product_ads_2/campaigns`)
4. **Endpoint coletivo (PUT array)** — opcional: confirmar `PUT .../product_ads/ads` com array de `ads` aceita; se sim, backend usa esse em vez de N PUTs (mais simples)
5. **Permissão funcional "Advertising" na app no DevCenter ML** — verificar manualmente no painel `developers.mercadolivre.com.br`; se ausente, 403 persiste mesmo com scope=write

**Plan 44-01 ENTREGA:** comando Artisan `sugadores:ml-write-smoke --company={id}` que executa GET advertiser → POST criar SGI teste → PUT mover ad teste pra SGI → PUT reverter; grava fixture JSON em `storage/app/sugadores/ml-write-smoke/{company_id}-{ts}.json` com headers usados, status codes, payloads, advertiser_id.

**Critério de aprovação plan 44-01:** fixture mostra 5/5 passos verdes. Se falhar, replanejar 44-02/03/04 conforme limitação real (não codar sobre suposição).

</scope_warnings>
