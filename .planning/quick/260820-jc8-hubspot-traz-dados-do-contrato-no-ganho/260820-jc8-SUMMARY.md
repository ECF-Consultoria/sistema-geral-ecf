---
quick_id: 260820-jc8
slug: hubspot-traz-dados-do-contrato-no-ganho
created: 2026-08-20
completed: 2026-08-20
status: complete
---

# HubSpot traz os dados do contrato no ganho do negócio — SUMMARY

**O quê:** quando um deal do HubSpot vai para "ganho", o webhook passa a ler 8 properties novas
do **deal** (razão social, CNPJ, 5 partes de endereço, data da 1ª parcela — criadas pelo Comercial
especificamente para este handoff) e usá-las para preencher `companies.razao_social`,
`companies.endereco`, `companies.cnpj` (fallback) e `contratos_servico.data_primeira_parcela` /
`dia_vencimento` em cada `ContratoServico` criado.

## Commits

| Tarefa | Commit | O que entrou |
|---|---|---|
| 1 (config) | `29f1c5b2` | 8 props novas em `config('services.hubspot.props.deal')`, com `env()` override, no padrão das existentes |
| 1 + 2 (service) | `945a0226` | `HubspotDealHandoffService`: extração das 8 props, `comporEndereco()`, `parseDataHubspot()`, `HubspotHandoffData::$deal_contract_data` novo |
| 3 (gravação) | `ef117765` | `HubspotWebhookController`: handoff movido pra ANTES do match/criação de empresa; cnpj-fallback; `razao_social`/`endereco` em `enriquecerEmpresaExistente()`; `persistirContratos()` grava as 2 colunas de data |

Tarefas 1 e 2 saíram num commit só de serviço porque ficaram no **mesmo método**
(`HubspotDealHandoffService::montarContrato()`/`build()`) — separar o diff em dois commits exigiria
reescrever a mesma função duas vezes sem ganho real de rastreabilidade. O commit de config (Tarefa 1)
ficou isolado porque é autocontido.

## Formato da data — o que foi MEDIDO e o que ainda depende de prova real

⚠️ **Isto não foi confirmado contra um payload real do HubSpot nesta sessão** — a Maderatto (empresa
de referência já preenchida) segue **fora do ganho de propósito** (instrução explícita do plano: não
tocar no deal dela). A prova definitiva só acontece quando alguém mover um deal real para "Fechado
Ganho".

O que ORIENTOU a implementação:
- **Fato conferido no código-fonte desta sessão**: `config/app.php` roda com
  `'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo')` (UTC-3). Isso NÃO é específico do
  HubSpot, mas é a causa raiz do risco que o plano apontou.
- **Fato de conhecimento geral sobre a API v3 do HubSpot** (não medido nesta sessão, sem precedente
  no código): properties do tipo `date` (como `data_do_1_pagamento`) são retornadas como **epoch em
  milissegundos** (string), representando **meia-noite UTC** do dia — não `'Y-m-d'`.
- Combinando os dois: se o parse não fixar a timezone em UTC, meia-noite UTC de um dia vira **21h
  do dia anterior** em America/Sao_Paulo — o dia lido fica ERRADO por 1 dia. Esse é exatamente o
  risco que o plano descreveu ("dia errado aqui vira dia de vencimento errado num contrato
  assinado").

**Implementação:** `HubspotDealHandoffService::parseDataHubspot()` usa
`Carbon::createFromTimestampMs((int) $valor, 'UTC')` quando o valor é numérico, forçando UTC
explicitamente — e tem um fallback defensivo (`Carbon::parse($valor, 'UTC')`) caso o HubSpot venha
a mandar `'Y-m-d'` no futuro. O teste
`test_data_primeira_parcela_epoch_ms_meia_noite_utc_prova_o_dia_correto` prova o dia certo usando
epoch calculado via `gmmktime(0, 0, 0, 8, 31, 2025)` — 31/08 escolhido de propósito porque é
justamente o caso em que, sem forçar UTC, o teste pegaria dia 30 (o bug que o plano queria evitar).

**Prova real pendente:** quando um deal com `data_do_1_pagamento` preenchida for para ganho de
fato (ex.: a própria Maderatto, quando for liberada), conferir no log `hubspot_snapshot.deal` o
valor bruto recebido e o `data_primeira_parcela`/`dia_vencimento` gravados no `ContratoServico`
resultante, comparando com a data que o Comercial esperava.

## Decisões da implementação

- **`deal_contract_data` é um DTO novo, separado de `company_data`.** `company_data` vem da HubSpot
  COMPANY (objeto diferente); os 8 campos novos são do DEAL. Misturar os dois teria violado a
  distinção que o plano fez questão de marcar (`razao_social`, `cidade`, `estado`, `cep` existem
  como props parecidas em `props.company` — objetos diferentes).
- **Handoff (`HubspotDealHandoffService::build()`) movido para ANTES do bloco de match/criação de
  empresa** em `criarEmpresa()`. Antes ficava depois (Fase 112). A composição de
  `deal_contract_data` não depende de `$company` nem do resultado do matcher — só de
  deal/lineItems/hubCompany/contatos, todos já disponíveis mais cedo — então reordenar não muda
  comportamento de nenhum fluxo existente (confirmado pelos 279 testes do gate). Isso permitiu usar
  `$handoff->deal_contract_data['cnpj']` como fallback de `$cnpjRaw` ANTES do matcher rodar
  (`HubspotCompanyMatcher::encontrar()` usa `cnpj` como critério de match forte) — efeito colateral
  desejável: um deal cujo CNPJ só existe na property do deal (não na company HubSpot) agora também
  pode casar por CNPJ com uma empresa já existente.
- **`comporEndereco()`** junta `logradouro, bairro, Cidade - Estado, CEP 00000-000`, pulando partes
  vazias. `Cidade - Estado` vira só `Cidade` ou só `Estado` quando falta um dos dois — nunca um
  hífen solto. Testado com endereço completo, parcial (só cidade+estado) e totalmente vazio (→
  `null`).
- **`enriquecerEmpresaExistente()` ganhou 2 parâmetros** (`razaoSocialRaw`, `enderecoRaw`) e os 2
  campos entram no `$candidatos` existente — reusa 100% a regra "só preenche se vazio" já testada
  em Fase 113 (T-113-03-02), sem lógica nova de dedup.

## Testes

- `tests/Feature/Phase112HandoffServiceTest.php` — 8 testes novos no service isolado (endereço
  completo/parcial/vazio; data com epoch ms provando o dia; data vazia sem default; 2 line items
  com a mesma data).
- `tests/Feature/Quick260820Jc8HubspotContratoTest.php` (novo) — 5 testes ponta a ponta via
  `Http::fake` + assinatura HMAC v3 real (mesmo padrão de `Phase113HubspotEnrichmentTest`): deal
  com as 8 props → Company + ContratoServico corretos; CNPJ do deal como fallback; dado
  preenchido à mão NÃO sobrescrito por replay; props ausentes não quebram o fluxo; 2 line items →
  mesma data nos 2 contratos.
- **Gate do plano** `--filter=Phase111|Phase112|Phase113|Phase127|Phase128|Hubspot`: **Tests: 284,
  Assertions: 1070, Failures: 0** (inclui os 13 testes novos deste quick task — o novo arquivo
  também casa em `Hubspot` pelo nome da classe).
- Rodada mais ampla (`Phase111..116|124|127|128|Hubspot`, 387 testes) expôs 1 falha
  pré-existente e **não relacionada**: `Phase116\NpsMaterializarNaoRespondidosCommandTest` (comando
  de NPS, fora do escopo de HubSpot/contrato; confirmado pelo último commit que tocou o arquivo,
  `b0ad138a`, sem relação com este trabalho). Fora do gate exigido pelo plano — não investigado
  aqui.

## Verificação antes de cada commit

`git status --porcelain app/ config/ tests/ database/` (sem `--untracked-files=no`) rodado antes
dos 3 commits. `tests/Feature/CompanyPortfolioAccessTest.php` apareceu como `??` nas 3 vezes e foi
deixado de fora — não é deste trabalho (já estava assim no início da sessão, conforme
`git status` do início da conversa).

## Fora de escopo

- **Nenhuma migration nova** — `razao_social`/`endereco` (companies) e
  `data_primeira_parcela`/`dia_vencimento` (contratos_servico) já existiam (quick 260819-guy).
- **Tela do Administrativo intocada** — o campo "Endereço" único e a validação de obrigatoriedade
  em `ContratoDadosMinimosService::faltantes()` já existiam; este quick só adicionou uma fonte a
  mais (HubSpot) para os mesmos campos.
- **`.docx` da Clicksign intocado** — variáveis já mapeadas desde 260819-guy.

## Não deployado

Nada foi para produção nesta sessão. Deploy requer autorização explícita separada.
