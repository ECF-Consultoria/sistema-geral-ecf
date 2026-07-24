# Phase 114: UI Comercial (campos+pendências novas) + comando de replay - Context

**Gathered:** 2026-07-24
**Status:** Ready for planning
**Source:** Plano canônico `prompt-claude-otimizacao-comercial-hubspot.md` (Fases 8 e 9) — milestone v20.0. Depende de 111 (colunas), 112 (valor+confidence+warning), 113 (contato/dedup/snapshot).

<domain>
## Phase Boundary

Torna visível/acionável o que 111–113 passaram a capturar. Entrega:
1. **UI Comercial expõe os dados enriquecidos** — a listagem `/comercial` (empresas) mostra contato/cargo/observação/IDs HubSpot e o bloco de valor (operacional × original + frequência + confiança + warning), **sem lotar a tela** (detalhes em tooltip/drawer/modal leve).
2. **Pendências novas** — `sem_contato`, `valor_revisar`, `possivel_duplicidade`, apenas para empresas de origem HubSpot, coerentes com as 5 pendências já existentes.
3. **Comando de replay** — `php artisan hubspot:reprocess-event {id}` recria/atualiza contratos faltantes depois que o admin cadastra um mapping ausente, idempotente (não duplica company/contrato), com log estruturado.

**FORA do escopo:** suite E2E ampla + doc da regra de valor (Fase 115). Nenhuma mudança no motor de webhook/resolver/dedup — só leitura/exibição + o comando que reusa a lógica existente.

</domain>

<decisions>
## Implementation Decisions (LOCKED)

### INVARIANTE DE NÃO-REGRESSÃO
- As 5 pendências atuais (`sem_servico`, `sem_valor`, `servico_nao_reconhecido`, `sem_setor`, `dados_close_incompletos`) e as colunas/contadores existentes NÃO mudam. `Phase37ComercialListagemTest` DEVE continuar verde.
- Pendências novas e campos novos são ADITIVOS. Só empresas de origem HubSpot (`is_origem_hubspot`) recebem as pendências novas — mesma regra das atuais (`calcularPendencias` retorna vazio para não-HubSpot).

### Backend — payload da listagem (HUB-UI-01)
- Em `ComercialController::listagem()`, adicionar ao payload por empresa (bloco ~307-343): `nome_contato`, `cargo_contato`, `hubspot_observacao`, `hubspot_deal_id`, `hubspot_company_id` (colunas de `Company`, gravadas na 113).
- Em cada `contratos_servico` do payload (~330-340), adicionar: `hubspot_valor_original`, `hubspot_valor_normalizado_mensal`, `hubspot_valor_confidence`, `hubspot_valor_warning`, `hubspot_billing_frequency` (colunas de `ContratoServico`, gravadas na 112). `valor_contratado` continua sendo o valor operacional.
- Best-effort/nullable: campos ausentes vêm null; não quebrar empresas legadas sem esses dados.

### Backend — pendências novas (HUB-UI-02)
- Estender `calcularPendencias(Company $c)` (~406-490) — só quando `is_origem_hubspot`:
  - **`sem_contato`**: empresa de origem HubSpot sem `nome_contato` (contato principal não resolvido).
  - **`valor_revisar`**: algum contrato ativo com `hubspot_valor_confidence = 'low'` OU `hubspot_valor_warning` não-nulo (inferência insegura da 112 — inclui o caso `deal.amount` indecidível).
  - **`possivel_duplicidade`**: o `HubspotEvento` de origem tem warning `possivel_duplicidade` no `payload` (gravado pelo dedup fraco da 113) OU o `hubspot_snapshot` da empresa registra `possivel_duplicidade`.
- Adicionar as 3 chaves ao array `$pendenciaCounts` (~245-249) e à whitelist de filtro (~195). Detalhes em `pendencias_detalhes` quando útil (ex.: `valor_revisar` → lista de contratos afetados; `possivel_duplicidade` → nome da empresa candidata).

### Frontend — EmpresasListagem.jsx (HUB-UI-01/02) — CONTRATO DE DESIGN LEVE
- Estender `resources/js/Pages/Comercial/EmpresasListagem.jsx` (não criar página nova).
- **Não lotar a grade.** Os campos novos (contato/cargo/observação/IDs + bloco de valor) vão em **tooltip/drawer/modal leve** por linha (um ícone/expand), não em colunas fixas novas. Reusar padrões já presentes na página (mesma abordagem de detalhes das pendências atuais).
- **Bloco de valor** por contrato: mostrar `valor_contratado` (operacional) como principal; ao expandir, "Original HubSpot: R$ X (anual)", "Frequência: mensal/anual", "Confiança: alta/média/baixa", e o warning quando houver. Confiança com cor semântica (alta=verde, média=âmbar, baixa=vermelho) usando tokens `ecf-*` já existentes.
- **Badges de pendência**: as 3 novas seguem o MESMO componente/estilo das 5 atuais (chips no header + marcador por linha). Rótulos pt-BR sem jargão (regra sistêmica do projeto): "Sem contato", "Revisar valor", "Possível duplicidade".
- Design system: dark theme `ecf-*`, `cn()`, componentes shadcn/ui já usados na página. `npm run build` ao final (convenção do projeto).

### Comando de replay (HUB-REPLAY-01)
- `php artisan hubspot:reprocess-event {hubspot_evento_id}` (App\Console\Commands, naming verbo-substantivo do projeto — ex.: classe `ReprocessHubspotEvent` / signature `hubspot:reprocess-event`).
- Comportamento: carrega o `HubspotEvento` pelo id; reprocessa o handoff do deal (reusar a lógica já extraída — `HubspotDealHandoffService` + `criarEmpresa`/`persistirContratos`/`HubspotCompanyMatcher`), criando/atualizando contratos que ficaram faltando (ex.: line item que estava sem mapping e o admin acabou de cadastrar).
- **Idempotente:** reusa o dedup da 113 (empresa existente por `hubspot_company_id`/`hubspot_deal_id` → não cria company nova) e o guard de contrato por `hubspot_line_item_id` (não duplica contrato). Segundo run não gera duplicata.
- Log estruturado (canal `ecf-webhooks`, tag `[Hubspot]`): evento id, deal id, company id, contratos criados/atualizados/ignorados, warnings. NUNCA loga token.
- Fonte dos dados: refetch via `HubspotApiClient` (preferível, dados frescos) OU o `hubspot_snapshot` gravado na 113 (fallback offline) — discrição; se refetch, usar `Http` real só em produção (testes com `Http::fake`).

### Claude's Discretion
- Se o replay refetch via API ou lê do `hubspot_snapshot`; como extrai a lógica compartilhada com o webhook (método reusável no controller vs. mover para o handoff service).
- Formato exato do drawer/tooltip; ícones; se `possivel_duplicidade` também bloqueia algo (provável: só sinaliza).
- Nome exato da classe do comando.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano canônico
- `prompt-claude-otimizacao-comercial-hubspot.md` — Fase 8 (UI/pendências) e Fase 9 (replay + inspect-properties já feito na 111).

### Código a estender (source of truth)
- `app/Http/Controllers/ComercialController.php` — `listagem()` (~178-392: payload por empresa + `$pendenciaCounts` + whitelist filtro + render) e `calcularPendencias()` (~394-490: 5 pendências atuais, só origem HubSpot).
- `resources/js/Pages/Comercial/EmpresasListagem.jsx` — página a estender (grade + badges de pendência + detalhes).
- `app/Models/Company.php` — colunas `nome_contato`/`cargo_contato`/`hubspot_deal_id`/`hubspot_company_id`/`hubspot_observacao`/`hubspot_snapshot` (113).
- `app/Models/ContratoServico.php` — colunas `hubspot_valor_original`/`hubspot_valor_normalizado_mensal`/`hubspot_valor_confidence`/`hubspot_valor_warning`/`hubspot_billing_frequency`/`hubspot_line_item_id` (112/111).
- `app/Http/Controllers/Api/HubspotWebhookController.php` — `criarEmpresa`/`persistirContratos`/uso de `HubspotDealHandoffService`/`HubspotCompanyMatcher` (a lógica que o replay reusa).
- `app/Models/HubspotEvento.php` — `object_id` (deal id), `payload` (warnings incl. `possivel_duplicidade`/`line_items_nao_mapeados`), `company_id_criada`.
- `app/Services/HubspotApiClient.php` — para refetch no replay (se escolhido).

### Testes de regressão (DEVEM continuar verdes)
- `tests/Feature/Phase37ComercialListagemTest.php` — as 5 pendências atuais + payload da listagem.
- `tests/Feature/Phase113HubspotDedupTest.php` — idempotência do dedup/guard que o replay reusa.

### Fases anteriores
- `.planning/phases/112-.../112-*-SUMMARY.md`, `.planning/phases/113-.../113-*-SUMMARY.md`.

</canonical_refs>

<specifics>
## Specific Ideas

- Casos de teste âncora (prompt Fase 10 — `PhaseHubspotComercialListagemEnrichmentTest` + `PhaseHubspotReplayTest`):
  1. Listagem expõe contato/observação/confiança de valor/warning para empresa origem HubSpot.
  2. Pendência `valor_revisar` aparece SÓ para origem HubSpot (não para empresa legada).
  3. `possivel_duplicidade` aparece quando o evento marcou dedup fraco.
  4. `sem_contato` aparece quando nome_contato é null.
  5. Replay: evento com line item SEM mapping não cria contrato → admin cadastra mapping → `hubspot:reprocess-event` cria o contrato → pendência some (efeito prático).
  6. Replay idempotente: rodar 2× não duplica company nem contrato.
- Regra sistêmica de UI: evitar jargão sem explicação (feedback do projeto) — rótulos pt-BR claros.

</specifics>

<deferred>
## Deferred Ideas

- Suite E2E ampla cobrindo os 10 critérios do brief + doc técnico da regra de valor → Fase 115.
- Ação de "resolver duplicidade" na UI (merge assistido) — não nesta fase; `possivel_duplicidade` só sinaliza.

</deferred>

---

*Phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0*
*Context gathered: 2026-07-24 — sintetizado do plano canônico + código real (lean; direção de UI leve embutida)*
