---
phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0
verified: 2026-07-24T23:10:00Z
status: human_needed
score: 7/7 verdades checáveis por código verificadas (1 checkpoint visual humano pendente)
overrides_applied: 0
human_verification:
  - test: "Validação visual da UI em /comercial/empresas/listagem"
    expected: "8 cards de pendência no header (5 antigos + Sem contato/Revisar valor/Possível duplicidade) clicáveis; botão Info (ícone) na coluna Ações só para empresas is_origem_hubspot; modal DetalheHubspotModal mostra Contato/Cargo/Observação/IDs HubSpot/bloco de valor por contrato com Confiança colorida (verde/âmbar/vermelho) e warning quando houver; tooltip de possivel_duplicidade mostra o nome real da empresa candidata; empresa Legacy não mostra o botão nem recebe pendências novas; rótulos em pt-BR sem jargão; visual dark coerente com tokens ecf-*"
    why_human: "Aparência renderizada (cores, alinhamento do grid 8 colunas, legibilidade do modal, comportamento de hover/tooltip) não é verificável por grep/análise estática de código — exige navegador. Já registrado como Task 3 PENDENTE no 114-02-SUMMARY.md"
---

# Phase 114: UI Comercial (campos+pendências novas) + comando de replay — Verificação

**Phase Goal:** A listagem Comercial expõe os dados enriquecidos (contato, cargo, observação, IDs HubSpot, valor operacional×original+frequência+confiança+warning) sem lotar a tela, com pendências novas (`sem_contato`/`valor_revisar`/`possivel_duplicidade`). Existe `hubspot:reprocess-event {id}` para recriar contratos faltantes depois que o admin cadastra um mapping ausente — sem duplicar company/contrato.

**Verificado em:** 2026-07-24
**Status:** human_needed (checkpoint visual pendente — veredito de código: PASSED-WITH-NOTES)
**Re-verificação:** Não — verificação inicial

## Metodologia

Verificação goal-backward contra o código real (não contra as SUMMARYs). Lidas: ROADMAP.md (seção Phase 114), 114-CONTEXT.md, os 3 SUMMARYs, e o código-fonte completo de `ComercialController.php`, `EmpresasListagem.jsx`, `ReprocessHubspotEvent.php` e `HubspotWebhookController.php`. Rodada a suíte de regressão real via PHPUnit (não apenas lida a contagem reportada na SUMMARY).

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | Payload da listagem expõe `nome_contato`/`cargo_contato`/`hubspot_observacao`/`hubspot_deal_id`/`hubspot_company_id` por empresa + bloco de valor HubSpot (`hubspot_valor_original`/`normalizado_mensal`/`confidence`/`warning`/`billing_frequency`) por contrato, nullable/best-effort | ✓ VERIFIED | `ComercialController::listagem()` linhas 365-373 (campos da empresa) e 397-405 (bloco de valor por `contratos_servico`). `valor_contratado` (operacional) preservado como campo separado, não sobrescrito. Confirmado por 2 blocos de teste em `Phase114ComercialListagemEnrichmentTest` (`payload_expoe_campos_de_contato_e_ids_hubspot`, `contrato_expoe_bloco_de_valor_hubspot`, `contrato_bloco_de_valor_null_quando_ausente`) — todos verdes |
| 2 | `EmpresasListagem.jsx` mostra esses dados em modal/drawer leve, sem poluir a grade fixa | ✓ VERIFIED | Componente `DetalheHubspotModal` (linhas 394-486) — blocos Contato/Observação/IDs HubSpot/Valor por contrato, `Confiança` colorida via `CONFIANCA_CLS` (emerald/amber/red). Aberto por botão `Info` na coluna Ações, renderizado só quando `c.is_origem_hubspot` (linha 732). Nenhuma coluna nova fixa foi adicionada à tabela (`TableHead` continua com 6 colunas: Empresa/Origem/Serviços/Setor/Pendências/Ações) — cumpre "sem lotar a tela" |
| 3 | 3 pendências novas (`sem_contato`, `valor_revisar`, `possivel_duplicidade`) calculadas **apenas** para empresas de origem HubSpot, coerentes com as 5 existentes | ✓ VERIFIED | `calcularPendenciasComerciais()` linhas 549-580 — guarda de origem HubSpot no topo do método (linha 472) já isola TODAS as 8 pendências (não só as novas). Testes dedicados de isolamento: `empresa_legada_não_recebe_nenhuma_pendencia_nova` (verde), `sem_contato_ausente_quando_nome_contato_preenchido`, `valor_revisar_ausente_quando_confidence_high_sem_warning`, `possivel_duplicidade_ausente_sem_marcacao` — todos verdes. `pendencia_counts` (8 chaves) e whitelist do filtro `?pendencia=` (linhas 194-198) incluem as 3 novas |
| 4 | Badges/cards das pendências novas no frontend, mesmo padrão visual das 5 atuais | ✓ VERIFIED | `PENDENCIAS_LABELS`/`PENDENCIAS_CLS` (linhas 33-54) incluem as 3 chaves com rótulos pt-BR sem jargão ("Sem contato", "Revisar valor", "Possível duplicidade") e cores não repetidas das 5 atuais. Grid de cards ajustado para `grid-cols-2 md:grid-cols-4 xl:grid-cols-8` (linha 626) — comporta as 8 chaves. `PendenciaBadges` já itera `pendencias_comerciais` genericamente (linha 139-160), sem precisar de componente novo |
| 5 | `php artisan hubspot:reprocess-event {id}` reprocessa evento sem serviço por mapping ausente e cria/atualiza o contrato faltante, idempotente | ✓ VERIFIED | `ReprocessHubspotEvent::handle()` delega a `HubspotWebhookController::reprocessarEvento()` (público, linhas 288-401), que refaz o fetch e chama `criarEmpresa()` reusando `HubspotCompanyMatcher` (dedup company) + guard `hubspot_line_item_id` (dedup contrato). Comprovado por teste real (não simulado): `test_replay_cria_contrato_faltante_apos_mapping_cadastrado` — webhook cria empresa sem contrato (0 contratos), admin cadastra `Servico`+`HubspotLineItemMapping`, replay cria o contrato (1 contrato, `servico_id` correto). `test_replay_e_idempotente_rodando_2x` — 2ª execução mantém `Company::count()`=1 e `ContratoServico::count()`=1. Ambos rodados nesta verificação e passaram |
| 6 | Log estruturado (evento id, deal id, company id, contratos criados/ignorados, warnings) no canal `ecf-webhooks`; nenhum token no log | ✓ VERIFIED (nota) | `Log::channel('ecf-webhooks')->info('[Hubspot] Replay: reprocessamento concluido', $resumo)` (linha 375) grava `evento_id`/`deal_id`/`company_id`/`contratos_criados`/`contratos_ignorados`/`empresas_enriquecidas`/`warnings`. Grep por `secret`/`token`/`access_token` em `ReprocessHubspotEvent.php` e no método `reprocessarEvento()` não encontrou nenhuma ocorrência. **Nota:** o SC fala em "contratos criados/atualizados/ignorados", mas o replay nunca *atualiza* um contrato existente — `persistirContratos()` só cria ou pula (guard de dedup); o campo análogo reportado é `empresas_enriquecidas` (via `Company::wasRecentlyCreated`), que reflete quando a *empresa* foi enriquecida em vez de criada. Divergência semântica menor, não funcional — o comportamento real do sistema (contratos não são "atualizados", só criados/ignorados) torna a redação do SC levemente imprecisa, não o código |
| 7 | Regressão zero — `Phase37ComercialListagemTest` continua verde, sem alteração de asserção | ✓ VERIFIED | Rodado nesta verificação: `Phase37ComercialListagemTest` 17/17 verde (62 assertions). `git log`/`git diff` confirmam que o arquivo de teste não foi tocado desde sua criação original (commits `32aa9859`/`9227820c`, ambos anteriores à Fase 114) — nenhuma asserção alterada. Suíte HubSpot completa (`--filter=Hubspot`) rodada nesta verificação: **89/89 verde** (417 assertions), batendo com o relatado no 114-03-SUMMARY.md |

**Score:** 7/7 verdades checáveis por código verificadas (1 nota semântica sem impacto funcional) + 1 checkpoint visual humano pendente (não checável por código, conforme instrução da tarefa)

### Required Artifacts

| Artefato | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `app/Http/Controllers/ComercialController.php` | payload enriquecido + `calcularPendenciasComerciais` estendido | ✓ VERIFIED | Linhas 194-198 (whitelist), 246-256 (`$pendenciaCounts` 8 chaves), 313-349 (`pendencias_detalhes`), 365-406 (payload por empresa/contrato), 549-580 (3 pendências novas) |
| `resources/js/Pages/Comercial/EmpresasListagem.jsx` | badges novos + modal de detalhes HubSpot | ✓ VERIFIED | `PENDENCIAS_LABELS`/`CLS` (33-54), `CONFIANCA_CLS`/`LABEL`/`FREQUENCIA_LABEL` (57-72), `DetalheHubspotModal` (394-486), botão `Info` condicional (732-741), grid 8 colunas (626) |
| `app/Console/Commands/ReprocessHubspotEvent.php` | comando `hubspot:reprocess-event {id}` | ✓ VERIFIED | Existe, delega a `reprocessarEvento()`, exit codes corretos (`SUCCESS`/`FAILURE`), tabela de resumo no terminal |
| `app/Http/Controllers/Api/HubspotWebhookController.php` | método público `reprocessarEvento()` reusável | ✓ VERIFIED | Linhas 288-401 — reusa `criarEmpresa()`/`persistirContratos()`/`HubspotCompanyMatcher` sem duplicar lógica |
| `tests/Feature/Phase114ComercialListagemEnrichmentTest.php` | 18 testes (payload+pendências) | ✓ VERIFIED | 18/18 verde, rodado nesta verificação |
| `tests/Feature/Phase114HubspotReplayTest.php` | 3 testes (efeito prático+idempotência+erro) | ✓ VERIFIED | 3/3 verde, rodado nesta verificação, `Http::fake` em todos os cenários (nenhuma chamada real ao HubSpot) |
| Build do frontend (`public/build/assets/EmpresasListagem-*.js`) | bundle atualizado | ✓ VERIFIED | `EmpresasListagem-D9hDYBXY.js` com timestamp 19:28 (coerente com a conclusão do 114-02-SUMMARY.md às 19:30); nenhum diff pendente em `git status` no `.jsx` |

### Key Link Verification

| De | Para | Via | Status | Detalhes |
|----|------|-----|--------|----------|
| `ComercialController::listagem()` | `EmpresasListagem.jsx` | props Inertia (`companies.data[].nome_contato`, `.hubspot_valor_*`, `.pendencias_comerciais`) | ✓ WIRED | Frontend consome `c.nome_contato`/`c.hubspot_deal_id`/etc via `empresa.*` no `DetalheHubspotModal`, e `c.pendencias_comerciais`/`pendencias_detalhes` via `PendenciaBadges` — mesmos nomes de chave do payload do controller |
| Botão `Info` (linha 737) | `DetalheHubspotModal` | `onClick={() => abrirDetalhe(c)}` → `setDetalheEmpresa(c)` → `open={!!detalheEmpresa}` | ✓ WIRED | Estado React conecta clique → abertura do modal renderizado no fim da página (linha 800-804) |
| `ReprocessHubspotEvent::handle()` | `HubspotWebhookController::reprocessarEvento()` | injeção de dependência via `handle(HubspotWebhookController $controller, HubspotApiClient $api)` | ✓ WIRED | Chamada direta linha 51; resultado usado na tabela de saída do comando |
| `reprocessarEvento()` | `criarEmpresa()` (dedup+guard) | chamada direta linha 345 | ✓ WIRED | Mesmo método usado pelo webhook original (`processar()`, linha 225) — garante paridade de comportamento e reuso real do dedup, não uma reimplementação paralela |

### Requirements Coverage

| Requirement | Plano de origem | Descrição | Status | Evidência |
|-------------|-----------------|-----------|--------|-----------|
| HUB-UI-01 | 114-01, 114-02 | Novos campos na listagem (contato/IDs/valor) | ✓ SATISFIED | Payload backend + modal frontend confirmados (truths 1-2) |
| HUB-UI-02 | 114-01, 114-02 | Pendências novas origem-HubSpot | ✓ SATISFIED | Cálculo backend isolado + badges frontend confirmados (truths 3-4) |
| HUB-REPLAY-01 | 114-03 | Comando de replay idempotente | ✓ SATISFIED | Comando + método reusável + testes de efeito prático/idempotência confirmados (truth 5) |

Nenhum requirement órfão encontrado — os 3 requirements do ROADMAP (HUB-UI-01/02, HUB-REPLAY-01) estão todos declarados nos 3 planos e todos com evidência de implementação real.

### Anti-Patterns Found

Nenhum debt marker real encontrado nos arquivos modificados/criados da fase (`ComercialController.php`, `EmpresasListagem.jsx`, `ReprocessHubspotEvent.php`, `HubspotWebhookController.php`). Ocorrências de grep para `TODO`/`PLACEHOLDER` foram todas falsos positivos (substring de "TODAS"/"Todos" e atributo HTML legítimo `placeholder="..."` em inputs de busca/formulário).

### Comando de Replay — Execução Real

O comando não foi executado manualmente contra o HubSpot real nesta verificação (não haveria evento válido em ambiente local sem seed específico), mas sua execução foi comprovada via `php artisan test` com `Http::fake` cobrindo o cenário-âncora completo (webhook original → pendência → mapping cadastrado → replay materializa contrato → replay 2x não duplica). Isso é evidência mais forte que uma execução manual isolada, pois cobre o ciclo completo com asserções de estado do banco.

### Comando de Regressão — Resultado Real (rodado nesta verificação)

| Suíte | Comando | Resultado |
|-------|---------|-----------|
| `Phase114ComercialListagemEnrichmentTest` | `php artisan test --filter=Phase114` | 18/18 verde |
| `Phase114HubspotReplayTest` | `php artisan test --filter=Phase114` | 3/3 verde |
| `Phase37ComercialListagemTest` (invariante) | `php artisan test --filter=Phase37ComercialListagemTest` | 17/17 verde |
| Suíte HubSpot completa | `php artisan test --filter=Hubspot` | 89/89 verde (417 assertions) |

Nenhuma falha relacionada à Fase 114. (Falhas pré-existentes em `Phase13ComercialTest`/`Phase14ComercialTest` — métodos legacy `store()`/`update()`, não tocados por esta fase — não foram re-executadas nesta verificação por estarem fora de escopo e já documentadas em `deferred-items.md` da Fase 111, conforme instrução da tarefa.)

### Human Verification Required

### 1. Validação visual da UI em `/comercial/empresas/listagem`

**Teste:** Acessar como admin, conferir os 8 cards de pendência no header, clicar num card novo (filtro), abrir o modal de detalhes (ícone Info) numa empresa origem HubSpot, conferir blocos Contato/Observação/IDs/Valor com confiança colorida, passar o mouse no badge "Possível duplicidade" e conferir o nome real da candidata no tooltip, e confirmar que uma empresa "Legacy" não mostra o botão Info nem recebe pendências novas.

**Esperado:** Interface renderiza corretamente, sem quebra visual, cores semânticas aplicadas (verde/âmbar/vermelho para confiança), grid de 8 cards alinhado, rótulos pt-BR sem jargão, tema dark coerente com tokens `ecf-*`.

**Por que humano:** Aparência renderizada (layout, cores, hover, tooltip) não é verificável por análise estática de código — é preciso abrir a tela no navegador. Este ambiente de execução não tem acesso a servidor/browser local. Já registrado como Task 3 PENDENTE no `114-02-SUMMARY.md` (checkpoint:human-verify, gate=blocking).

### Gaps Summary

Nenhum gap de código encontrado. Todos os 3 Success Criteria checáveis por código (payload enriquecido, pendências novas isoladas por origem HubSpot, comando de replay idempotente) estão implementados e cobertos por teste real, executado nesta verificação (não apenas lido da SUMMARY). O invariante de não-regressão (`Phase37ComercialListagemTest` 17/17 sem alteração de asserção + suíte HubSpot 89/89) foi confirmado por execução direta, não por confiança na narrativa da SUMMARY.

O único item pendente é o **checkpoint visual humano** (aparência renderizada da UI), que por definição não pode ser verificado por código/grep e está formalmente registrado como pendente desde o 114-02-SUMMARY.md. Isso não bloqueia o veredito de código, mas mantém a fase em `human_needed` até a aprovação visual.

Nota lateral (não-bloqueante): `.planning/STATE.md` está com o `stopped_at` desatualizado ("falta 114-02 (frontend) para fechar a Fase 114"), mas 114-02 já está completo e commitado (`d8398e4c`, build gerado). É inconsistência de bookkeeping do orquestrador, não do código da fase — vale corrigir no fechamento da fase, mas não é um gap funcional.

---

## Veredito

**PASSED-WITH-NOTES**

Todos os elementos checáveis por código dos 3 Success Criteria da Fase 114 (HUB-UI-01, HUB-UI-02, HUB-REPLAY-01) estão implementados, testados com evidência real (testes rodados nesta verificação, não apenas lidos da SUMMARY) e sem regressão (17/17 + 89/89 verdes, nenhuma asserção alterada). O checkpoint visual humano (aparência renderizada) está formalmente PENDENTE — não é possível validá-lo neste ambiente sem browser/servidor, e por definição do processo (`checkpoint:human-verify`) exige aprovação humana antes do fechamento definitivo da fase.

---

*Verificado: 2026-07-24*
*Verificador: Claude (gsd-verifier)*
