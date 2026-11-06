# Fase 202: Renovação contratual — Contexto

**Levantado:** 2026-11-06
**Status:** Pronto para planejamento
**Plano-fonte:** `PLANO_RENOVACAO_CONTRATUAL_ECF_ADMIN.md` (raiz do repo) — ler inteiro; ele é a especificação.
Esta fase cobre as Fases 0–4 do plano-fonte (a Fase 0, auditoria, está feita abaixo). Homologação e ativação
progressiva (Fases 5–6 do plano) ficam como checkpoint humano no fim: a automação nasce DESLIGADA.

<decisions>
## Decisões travadas (usuário, 2026-11-06)

- **D-01 Pipeline/etapa de destino:** pipeline "Renovações contrato" `958410144`, etapa inicial
  "Proximo do termino de contrato" `1480135340`. Outras etapas: "Negociando" `1480135342` (aberta),
  "Negócio fechado" `1480135341` (fechada/ganho). Medido em produção via `GET /crm/v3/pipelines/deals` em 06/11.
  Não há etapa "perdido" nesse pipeline hoje. IDs por config/env, nunca literais espalhados.
- **D-02 Negócio original Ganho:** preservado integralmente (nenhuma escrita nele); a renovação é um negócio
  NOVO associado à mesma empresa/contato, com referência rastreável ao original. Decisão do plano-fonte, não reabrir.
- **D-03 Data de término sem valor = CALCULAR automaticamente** (escolha explícita do usuário, ciente do risco):
  para contrato ativo sem `data_vencimento`, término = início + vigência, onde vigência = meses do
  `hubspot_billing_period` (`P<N>M`) quando existir, senão `hs_recurring_billing_end_date` do snapshot quando
  existir (data expressa vence o cálculo), senão 12 meses. Grupo de fases do mesmo serviço (pagamento escalonado)
  usa a regra já existente de `ContratoPdfService`/`ContratoAdminController` (máximo do grupo / soma das parcelas)
  — reutilizar, não reimplementar. A data calculada é GRAVADA com **origem rastreável**
  (ex.: `data_vencimento_origem` ∈ `manual|hubspot|calculada`) e a tela marca "calculada" para conferência;
  `manual` nunca é sobrescrita por cálculo. ⚠️ `data_contratacao` hoje é a data do webhook / do reimport, não
  a assinatura — registrar isso na tela/learning; quando houver `contrato_assinaturas.assinado_em` do mesmo
  serviço, preferir como início.
- **D-04 Proprietário do negócio novo:** dono do negócio original (`companies.hubspot_owner_id`); sem dono,
  cria sem `hubspot_owner_id` (o comercial distribui). Nunca inventar.
- **D-05 Renovação em "Negócio fechado":** o sistema ESTENDE o contrato — abre novo ciclo com término =
  término anterior + vigência (mesma regra D-03), preservando o ciclo anterior no histórico. Idempotente
  (evento repetido não estende duas vezes). Esse ganho NÃO pode passar pelo fluxo de "venda ganha" do webhook
  (não cria empresa/ContratoServico novo).
- **D-06 Antecedência:** 2 meses de CALENDÁRIO (`subMonthsNoOverflow(2)`), configurável em `configuracoes`.
- **D-07 Vencidos na ativação:** aparecem na tela (filtro "Vencido"), mas NÃO geram criação em massa
  automática; criação para vencido só por ação manual na tela.
- **D-08 Liga/desliga e simulação:** chave em `configuracoes` (padrão `Configuracao::get(...,'0') === '1'`),
  nasce DESLIGADA; modo simulação (dry-run) que registra a decisão sem escrever no HubSpot.

## Requisitos
- REN-01 Data de término para todo contrato ativo (backfill calculado + origem), e preenchimento na entrada
  (webhook HubSpot/Comercial) pela mesma regra.
- REN-02 Entidade de ciclo de renovação (tabela nova, ex. `contrato_renovacoes`): empresa, serviço/grupo do
  contrato, chave de ciclo estável e única, término capturado, status comercial e de integração, IDs separados
  (negócio original × negócio de renovação), chave de correlação, tentativas, último erro; + tabela de eventos
  (auditoria). Unicidade por (contrato/grupo, ciclo). Corrigir data NÃO cria ciclo novo.
- REN-03 Cliente HubSpot: criar deal (com associação a company/contato/deal original), buscar deal por property
  de correlação, ler pipeline; timeout, retry com backoff e respeito a 429. Property customizada de correlação
  (ex. `ecf_renovacao_chave`) — verificar existência; se não existir, comando para criá-la (rodado pelo usuário).
- REN-04 Orquestração idempotente: Job por ciclo (fila `high`, lock por ciclo), matriz de decisão do plano-fonte
  §4.3.4; resultado incerto (timeout pós-POST) → `resultado_incerto` + conciliação pela correlação, nunca
  recriação cega. Comando diário agendado + mesma lógica para o botão manual.
- REN-05 Webhook: etapas do pipeline de renovação NUNCA entram no fluxo de venda ganha; "Negócio fechado" do
  pipeline de renovação aciona D-05.
- REN-06 Tela `/administrativo/contratos`: filtros (vence ≤30d, ≤2 meses, vencido, renovado, sem término,
  erro de sync, revisão manual), colunas término/dias/origem da data/situação da renovação, links separados
  "Negócio original" e "Negócio de renovação", ações "Sincronizar novamente" e "Dispensar com justificativa".
- REN-07 Liga/desliga + simulação (D-08) e logs `[Renovacao]` (Log::error nas falhas — produção roda LOG_LEVEL=error).
- REN-08 Testes Feature (SQLite, Http::fake com a forma REAL da resposta v3 — `id`, não `toObjectId`).
</decisions>

<code_context>
## Auditoria (Fase 0 do plano-fonte) — estado real em 06/11/2026

**Contratos**
- `contratos_servico` / `ContratoServico`: uma linha por empresa × serviço × fase. `data_contratacao` (NOT NULL),
  `data_vencimento` (nullable), `ativo` bool (sem status de cancelamento/rescisão), `hubspot_line_item_id`,
  `hubspot_billing_period` (`P<N>M`), `hubspot_snapshot` json (contém `line_item.hs_recurring_billing_end_date`),
  `data_primeira_parcela`, `dia_vencimento` (= dia de PAGAMENTO, não fim de vigência).
  `scopeActive` (l.120), `parcelas()`/`parcelasDoPeriodo()` (l.137/147).
- `HubspotWebhookController.php:1044-1045` e `ComercialController.php:615-616` gravam `data_vencimento=null`,
  `data_contratacao=now()`. Vazio = "prazo indeterminado" em `ContratoDadosMinimosService.php:165`.
- Vigência derivada só no PDF: `ContratoPdfService.php:288-356` (`montarVigencia`, `vigenciaEmMeses`,
  `VIGENCIA_MESES_PADRAO=12`), nunca persistida.
- `contrato_assinaturas` / `ContratoAssinatura`: envelope Clicksign; status rascunho/aguardando_assinaturas/
  assinado/recusado/expirado(=prazo de ASSINATURA)/cancelado/erro; `assinado_em`; sem FK para ContratoServico
  (vínculo por company_id+servico_id+servicos_snapshot). `ContratoLiberacao` é unique (company_id, servico_id).
- Tela: rotas `routes/web.php:1920-1971` (prefixo `administrativo/contratos`, `permission:admin.contratos`),
  `ContratoAdminController::index` (l.70-506; uma linha por empresa × grupo de serviço; término = max
  data_vencimento do grupo, l.232/293/343; paginação manual 50), `resources/js/Pages/Admin/Contratos.jsx`
  (cards l.129-167, coluna Término l.228/315), `ContratoDetalhe.jsx` (input data_vencimento l.742).
- **Produção 06/11:** 231 contratos ativos (exige_contrato, empresa ativa) em 187 empresas; só 38 com
  `data_vencimento`; 0 vencem em 2 meses; 17 com `hs_recurring_billing_end_date`; 38 com billing_period;
  52 com line item; 55 empresas com `hubspot_deal_id`; 7 empresas com contrato Clicksign assinado;
  `data_contratacao` de 2026-02-10 a 2026-12-10 (há datas futuras — tratar).

**HubSpot**
- `app/Services/HubspotApiClient.php`: base `https://api.hubapi.com`, Bearer `services.hubspot.access_token`
  (`HUBSPOT_ACCESS_TOKEN`). Só lê (deals/companies/contacts/line_items/notes/owners, associações v3 lendo `id`
  com fallback `toObjectId`); única escrita `atualizarEtapaDoDeal()` (PATCH dealstage). Sem POST de deal, sem
  associação de escrita, sem search, sem pipelines, sem retry/timeout/429.
- Webhook `Api/HubspotWebhookController.php` (síncrono, HMAC v3): dispara criação de empresa/contratos quando
  `dealstage` ∈ CSV `HUBSPOT_STAGE_FECHADO_GANHO_ID` (+ contrato pendente). ⚠️ RISCO DE LOOP: o ganho da
  renovação NÃO pode entrar nesse CSV nem no fluxo `criarEmpresa`/`persistirContratos`.
- Deal original: só `companies.hubspot_deal_id` (um por empresa) + `AvancarDealHubspotJob::resolverDealId`
  (`app/Jobs/AvancarDealHubspotJob.php:135-150`, último evento processado). Sem vínculo deal↔contrato.
  `companies.hubspot_company_id`, `hubspot_contact_id`, `hubspot_owner_id`.
- Modelo de job a copiar: `AvancarDealHubspotJob` (`high`, afterCommit, tries 5, backoff [30,120,600,1800],
  timeout 60, `failed()`, kill-switch). Log canal `ecf-webhooks`. Flag por `Configuracao::get/set`.
- Learnings: `.planning/learnings/hubspot-webhook-empresa-sem-etapa.md` (IDs de etapa mudam — medir; .env →
  `config:cache` + `queue:restart`, nunca `cache:clear`; escrita na VPS é do usuário).
- Testes: 17 `tests/Feature/*Hubspot*`; padrão `Http::fake([...]) + Http::recorded()`,
  `new HubspotApiClient('token-fake')` (`tests/Feature/Quick261103HubspotPipelineConsultoriaTest.php`).
  ⚠️ fakes usam `toObjectId` — os novos devem usar a forma real v3 (`id`).
- Escopos do token para criar deal/associação: NÃO verificados — verificar na execução com chamada real só
  após deploy, pelo usuário/orquestrador.
</code_context>

<deferred>
## Fora desta fase
- Ativar em produção (virar a chave) — decisão humana após simulação em produção (Fases 5–6 do plano-fonte).
- Registro formal de cancelamento/rescisão de contrato (hoje só `ativo=false`): tratar `ativo=false` como
  "não elegível"; oportunidade aberta de contrato desativado vai para `revisao_manual`.
- Reutilizar/mover negócios abertos de outros pipelines: lista de origens autorizadas nasce VAZIA (plano §4.3.3).
</deferred>
</content>
</invoke>
