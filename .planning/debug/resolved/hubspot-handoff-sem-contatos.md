---
status: resolved
trigger: Empresa "Hollyfield LTDA - Novo(a) Deal" marcada como ganho no HubSpot apareceu no sistema SEM os contatos (email/telefone), apesar de contato inserido no HubSpot na criação do deal.
created: 2026-07-27
updated: 2026-07-27T14:00:00-03:00
milestone: v20.0 (Handoff Comercial HubSpot)
root_cause: BUG DE FIELD-NAME no HubspotApiClient — a API v3 /crm/v3/objects/deals/{id}/associations/{tipo} retorna cada item como {id, type}, mas fetchAssociatedCompanyId/ContactId(s) liam `toObjectId` (chave da API v4) → SEMPRE null em produção. Empresa nascia sem company/contato desde o deploy da milestone (line items funcionavam porque fetchDealLineItems já lia `id`). NÃO era race condition — a hipótese de race foi um erro do debugger, que leu o raw de fetchAssociations (que retorna {id}) e interpretou como "associação disponível", sem notar que o método consumidor lia toObjectId.
fix: (1) CORE — HubspotApiClient passa a ler `id` (fallback toObjectId) nos 4 métodos de associação. (2) Rede de segurança — varredura agendada hubspot:reenriquecer-handoff (3min) reprocessa handoffs incompletos via reprocessarEvento (backfill dos registros já quebrados + qualquer miss futuro); anti-duplicata via critério existing_company_id no HubspotCompanyMatcher; enriquece só colunas vazias (edição manual soberana).
verification: suíte Hubspot verde; Phase111 ganhou testes travando o shape REAL {id,type}; Phase116 usa o shape real end-to-end; testes antigos (mocks toObjectId) seguem verdes pelo fallback. Confirmado em produção via tinker: fetchAssociations retornava {id:56986195877} enquanto fetchAssociatedCompanyId retornava null.
---

# Debug: HubSpot handoff cria empresa sem contatos

## Symptoms

- **Expected:** Ao marcar o deal como ganho, a empresa criada no ECF deve vir com os contatos (email/telefone/nome) do contato que originou a empresa no HubSpot.
- **Actual:** A empresa "Hollyfield LTDA - Novo(a) Deal" foi criada, mas sem nenhum contato (email/telefone vazios), mesmo com contato inserido no HubSpot ao criar o deal.
- **Errors:** Nenhum erro visível — falha silenciosa. O fetch de contatos só loga warning em EXCEÇÃO, não quando a associação retorna vazia (200 OK, results=[]).
- **Timeline:** Feature nova da milestone v20.0, deployada hoje (2026-07-27). Primeiro teste real do usuário.
- **Reproduction:** Criar deal no HubSpot com contato associado → marcar deal como ganho (won) → webhook dispara handoff → empresa criada no ECF sem contatos.

## Domain context (informado pelo usuário)

No HubSpot, o contato (telefone+email) fica associado ao CONTATO que deu origem à empresa. Fluxo do CRM do usuário: o lead entra como uma pessoa (contact) com seus dados de contato; depois, ao virar empresa, os contatos da empresa são o contato inicial do lead. Ou seja, o contato provavelmente está associado à COMPANY (via o contato originador), e NÃO diretamente ao DEAL.

## Current Focus

- **hypothesis (NOVA, pós-confirmação empírica):** Não é um problema de "contato mora na company, não no deal". É uma CONDIÇÃO DE CORRIDA (eventual consistency) do lado do HubSpot: o webhook `deal.propertyChange`/`dealstage=closedwon` chega e é processado SINCRONAMENTE (dentro do próprio request HTTP) quase no mesmo instante em que o deal é fechado — porém as associações deal→company e deal→contact (e possivelmente os próprios objetos company/contact) ainda não estavam commitadas/indexadas no momento exato do fetch. Consultando as MESMAS associações minutos depois (agora), ambas retornam dados completos.
- **test:** Rodar `fetchAssociations('deals', '63087274361', 'companies')` e `fetchAssociations('deals', '63087274361', 'contacts')` via tinker em produção (somente leitura) e comparar com o snapshot gravado no momento do processamento (`companies.hubspot_snapshot`).
- **expecting (do teste que REFUTOU a hipótese antiga):** Se a hipótese antiga estivesse certa, deal→contacts deveria continuar vazio mesmo agora. Resultado real: deal→contacts JÁ retorna o contato (`237977565608`) e deal→companies retorna a company (`56986195877`) — ambos populados.
- **next_action:** Decidir estratégia de fix para o race condition (ver "Fix candidates" abaixo) — decisão arquitetural, não um simples ajuste de escopo de busca. Retornando CHECKPOINT (decision) para o orquestrador/usuário escolher a estratégia antes de implementar.

## Evidence

- timestamp: 2026-07-27 — [leitura de código, estática] `app/Http/Controllers/Api/HubspotWebhookController.php` `processar()` (~L194-217) e `reprocessarEvento()` (~L310-334) buscam contatos SÓ do deal. Nenhuma chamada a company→contacts em nenhum dos dois ramos.
- timestamp: 2026-07-27 — [leitura de código] `app/Services/HubspotApiClient.php:344` `fetchAssociatedContactIds()` → `fetchAssociations('deals', $dealId, 'contacts')` (L305-315) → `GET /crm/v3/objects/deals/{id}/associations/contacts`. Existe `fetchCompany()` mas NÃO existe método company→contacts.
- timestamp: 2026-07-27 — [leitura de código] `criarEmpresa()` (~L466-506): email/telefone/nome_contato saem de `$contatoPrincipal` (escolhido por `HubspotContactSelector::selecionar($contatos)`). Se `$contatos=[]`, `$contatoPrincipal=null` → empresa sem contato. Fallback só olha company email/phone props, não busca contatos da company.
- timestamp: 2026-07-27 — [dado real, produção, SOMENTE LEITURA via tinker VPS] Company 396 (Hollyfield): `hubspot_deal_id=63087274361`, mas `hubspot_company_id` e `hubspot_contact_id` estão VAZIOS. `hubspot_snapshot` gravado no momento do processamento mostra `"company": null, "contacts": [], "primary_contact_id": null` — ou seja, no momento do fetch NEM a company NEM o contato vieram associados ao deal (não é so o contato que faltou).
- timestamp: 2026-07-27 — [dado real, produção] `hubspot_eventos` para object_id=63087274361: evento 1935 (dealstage=closedwon) `created_at`/`processado_em` = 2026-07-27 09:44:26 BRT (= 12:44:26 UTC). Deal `closedate` no snapshot = 12:44:25.660Z UTC. Ou seja, o webhook foi processado ~1 segundo (ou menos) depois do fechamento do deal — praticamente em tempo real.
- timestamp: 2026-07-27 — [teste empírico REAL, SOMENTE LEITURA, via `HubspotApiClient::fetchAssociations` em tinker no VPS] Consultando AGORA (minutos depois) as mesmas associações do deal 63087274361: `deal→companies = [{"id":"56986195877","type":"deal_to_company"}]` e `deal→contacts = [{"id":"237977565608","type":"deal_to_contact"}]`. Ambas POPULADAS — refuta a hipótese de que o contato só existiria via company→contacts (a associação deal→contacts existe e tem o contato certo).
- timestamp: 2026-07-27 — [dado real, produção] `fetchContact('237977565608', ...)`: `email=hollyteste@gmail.com`, `phone=+5511960821967` — é exatamente o contato esperado. `lastmodifieddate = 2026-07-27T12:44:58.881Z` (32s DEPOIS do webhook ter processado às 12:44:26Z).
- timestamp: 2026-07-27 — [dado real, produção] `fetchCompany('56986195877', ...)`: `hs_lastmodifieddate = 2026-07-27T12:44:33.418Z` (7s depois do processamento do webhook às 12:44:26Z). `createdate` da company = 12:42:38Z (antes do deal fechar), mas a associação/atualização relevante só se consolidou depois do webhook.

## Eliminated

- hypothesis: "O contato está associado à COMPANY (não ao deal), então buscar só deal→contacts explica o bug; basta também buscar company→contacts."
  evidence: Consultando `fetchAssociations('deals', dealId, 'contacts')` em produção (fora da janela do bug), a associação deal→contacts RETORNA o contato certo (id 237977565608, com email/phone corretos). Se o contato só existisse na company, deal→contacts continuaria vazio permanentemente — não é o caso. O problema não é ONDE o contato está associado, é QUANDO a associação fica disponível via API.
  timestamp: 2026-07-27

## Fix candidates (decisão arquitetural — aguardando escolha antes de implementar)

Causa raiz confirmada: RACE CONDITION / eventual consistency do HubSpot. O webhook de `dealstage=closedwon` chega e é processado de forma síncrona quase instantaneamente após o fechamento do deal, mas as associações deal→company e deal→contact (e a atualização dos próprios objetos) ainda podem não estar commitadas/indexadas nesse exato instante — ficam disponíveis segundos depois (7s para company, 32s para contact neste caso real).

Duas estratégias possíveis (nenhuma aplicada ainda — decisão pendente):

- **A. Retry com backoff curto dentro de `processar()`:** se `fetchAssociatedCompanyId`/`fetchAssociatedContactIds` voltarem vazios, tentar de novo 1-2x com pequeno delay (ex. 2s/4s) antes de desistir. Simples, mas aumenta a latência da resposta ao webhook do HubSpot (risco se o delay total se aproximar do timeout de retry do HubSpot) e pode não ser tempo suficiente (nesse caso real, o contato só ficou disponível 32s depois).
- **B. Auto-reprocessamento adiado (job):** se `criarEmpresa` rodar com company/contatos vazios, despachar um Job adiado (ex. 2-3 min) que chama `reprocessarEvento()` automaticamente (reusa o mecanismo de replay já existente/testado, sem bloquear a resposta do webhook). Mais robusto a variação de delay do HubSpot, não impacta latência do webhook, mas é mudança maior (novo Job + agendamento + guarda de idempotência para não reprocessar community já enriquecida por edição manual do Comercial no meio tempo).

Ambas resolvem a causa raiz (timing), diferente do fix original (que resolvia uma causa raiz incorreta). Reprocessar manualmente o evento Hollyfield via `hubspot:reprocess-event` (já disponível) resolve o caso pontual independente da escolha entre A/B.
