# Prompt Claude Code - Otimizacao da inteligencia Comercial integrada ao HubSpot

Data do estudo: 2026-07-23  
Projeto: `C:\xampp\htdocs\ecf_admin\ecf_admin`  
Objetivo: melhorar a chegada de empresas/negocios fechados no HubSpot para o sistema ECF, aproveitando o maximo de informacoes uteis ao operacional e corrigindo a inteligencia de valor mensal vs valor anual.

## Contexto executivo

O sistema ja recebe webhooks do HubSpot quando um negocio muda para "Fechado Ganho" e cria uma `Company` com contrato(s) de servico. A integracao atual funciona, mas ainda chega pobre em dados e pode interpretar valor de forma errada quando o HubSpot mostra o negocio como valor anual no kanban, enquanto o detalhe do line item mostra valor mensal.

Exemplo real informado pelo usuario:

- Servico de gestao mensal: R$ 3.000.
- No detalhe do negocio/line item aparece R$ 3.000.
- No kanban de negocios aparece R$ 36.000.
- O sistema precisa gravar o valor operacional correto do servico, preferencialmente R$ 3.000 mensais, e manter R$ 36.000 apenas como valor total/anual de auditoria quando for o caso.

## Fontes HubSpot consultadas

Use estas fontes como referencia primaria antes de alterar a integracao:

- Deals API: https://developers.hubspot.com/docs/api-reference/latest/crm/objects/deals/guide
- Line Items API: https://developers.hubspot.com/docs/api-reference/latest/crm/objects/line-items/guide
- Associations API: https://developers.hubspot.com/docs/api-reference/latest/crm/associations/overview
- Properties API: https://developers.hubspot.com/docs/api-reference/latest/crm/properties/guide
- Contacts API: https://developers.hubspot.com/docs/api-reference/latest/crm/objects/contacts/guide
- Webhooks API: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide
- Propriedades padrao de deals: https://knowledge.hubspot.com/properties/hubspots-default-deal-properties
- Calculo de line items em deals: https://knowledge.hubspot.com/records/use-line-items-with-deals
- Propriedades padrao de line items: https://knowledge.hubspot.com/properties/hubspots-default-line-item-properties

Resumo tecnico das fontes:

- Deals podem ser buscados por ID com `properties` e `associations`; propriedades solicitadas que nao existem nao sao retornadas ou voltam `null`.
- Empresas, contatos, deals e line items sao objetos CRM relacionados por associations.
- Line items representam os produtos/servicos ligados a um deal. Propriedades importantes: `name`, `description`, `quantity`, `price`, `amount`, `recurringbillingfrequency`, `hs_recurring_billing_period`, `hs_recurring_billing_start_date`, `hs_recurring_billing_end_date`, `hs_product_id`, `hs_sku`, `hs_line_item_currency_code`.
- Billing recorrente em line item usa `recurringbillingfrequency` e `hs_recurring_billing_period`. Frequencias validas incluem `weekly`, `biweekly`, `monthly`, `quarterly`, `per_six_months`, `annually`, `per_two_years`, `per_three_years`, `per_four_years`, `per_five_years`.
- No HubSpot, `amount` do deal pode representar o total do negocio. ARR/MRR/TCV/ACV sao calculados a partir dos line items recorrentes e nao devem ser confundidos com o valor operacional mensal do contrato.
- A documentacao de deals confirma que `amount` e o valor total do negocio na moeda do deal; MRR e ARR sao calculados pelos line items e nao usam o `amount` como fonte.
- Para conhecer os nomes internos reais da conta ECF, use a Properties API: `/crm/properties/{objectType}`. Nao assuma que campos customizados da conta tem exatamente o label visivel na UI.

## Estado atual encontrado no codigo

Arquivos principais:

- `routes/web.php`
  - Webhook: `POST /api/webhooks/hubspot` -> `App\Http\Controllers\Api\HubspotWebhookController::receive`.
  - UI admin de mapping: `/sistema/hubspot-line-items`.
  - Comercial/listagem/contratos: rotas em `/comercial/*` e `/empresas/{company}/contratos-servico/*`.

- `config/services.php`
  - Config atual `services.hubspot`:
    - `client_secret`
    - `access_token`
    - `stage_fechado_ganho_id`
    - `props.deal`: `nicho`, `dor`, `vende_ml`, `faturamento_mensal`, `servico`
    - `props.company`: `name`, `cnpj`, `email`, `phone`
    - `props.contact`: `firstname`, `lastname`, `email`, `phone`

- `app/Http/Controllers/Api/HubspotWebhookController.php`
  - Valida HMAC v3 com `X-HubSpot-Signature-v3` e `X-HubSpot-Request-Timestamp`.
  - Filtra eventos `deal.propertyChange` em `dealstage`.
  - Aceita CSV em `HUBSPOT_STAGE_FECHADO_GANHO_ID`.
  - Busca deal, primeira company associada, primeiro contato associado e line items.
  - Cria `Company` com `name`, `cnpj`, `email_cliente`, `telefone`, `nicho`, `dor`, `vende_ml`, `faturamento_mensal`, `empresa_nova=true`, `status=pendente`, `active=true`.
  - Nome do contato e anexado em `Company.notes` como linha textual: `Contato (HubSpot): Nome Sobrenome`.
  - Line items tem prioridade sobre `servico_ecf` do deal.
  - Para cada line item, resolve `HubspotLineItemMapping::paraNome($nome)`.
  - Cria `ContratoServico` com `valor_contratado = price * quantity`.
  - Deriva `tipo_cobranca` por `recurringbillingfrequency`, mas grava apenas em `observacoes`.
  - Se nao houver line items, cai no fluxo legado: `servico_ecf` + `deal.amount`.
  - Nao busca todos os contatos; pega apenas o primeiro.
  - Nao persiste `hubspot_deal_id`, `hubspot_company_id`, `hubspot_contact_id` ou `hubspot_line_item_id` de forma estruturada nas tabelas de negocio.

- `app/Services/HubspotApiClient.php`
  - Usa `https://api.hubapi.com/crm/v3/objects/...`.
  - Metodos atuais:
    - `fetchDeal`
    - `fetchAssociatedCompanyId`
    - `fetchCompany`
    - `fetchAssociatedContactId`
    - `fetchContact`
    - `fetchDealLineItems`
  - `fetchDealLineItems` hoje busca apenas: `name,price,quantity,hs_product_id,recurringbillingfrequency`.

- `app/Models/Company.php`
  - Fillable ja contem: `email_cliente`, `telefone`, `nicho`, `dor`, `vende_ml`, `faturamento_mensal`, `marketplaces_extras`, `email_colaborador`, `empresa_nova`, etc.
  - Relacoes HubSpot:
    - `hubspotEventoOrigem()`
    - `hubspotEventos()`
  - Ainda nao ha campos estruturados para IDs HubSpot nem contato principal.

- `app/Models/ContratoServico.php`
  - Campos atuais: `company_id`, `servico_id`, `valor_contratado`, `data_contratacao`, `data_vencimento`, `ativo`, `observacoes`.
  - Ainda nao ha campos estruturados para origem HubSpot, valor bruto, valor mensal normalizado, frequencia, periodo, line item ID ou confianca da inferencia.

- `app/Models/HubspotLineItemMapping.php`
  - Mapeia `line_item_name` -> `Servico`.
  - `paraNome()` faz match exato case-insensitive e depois substring por tamanho desc.
  - Bom para tolerar variacoes, mas precisa de teste contra falso positivo em nomes curtos, especialmente `MAP` vs `MAP PREMIUM`.

- `app/Http/Controllers/ComercialController.php`
  - `listagem()` calcula pendencias comerciais apenas para empresas de origem HubSpot:
    - `sem_servico`
    - `sem_valor`
    - `servico_nao_reconhecido`
    - `sem_setor`
    - `dados_close_incompletos`
  - `dados_close_incompletos` hoje exige `nicho`, `dor`, `faturamento_mensal`.
  - A tela envia `email_cliente`, `telefone`, `faturamento_mensal`, `nicho`, `dor`, contratos e origem HubSpot.

- Migrations relevantes:
  - `2026_06_12_300001_add_close_fields_to_companies_table.php`
  - `2026_06_12_300002_create_hubspot_eventos_table.php`
  - `2026_06_18_100003_create_hubspot_line_item_mapping_table.php`
  - `2026_06_18_100004_seed_hubspot_line_item_mapping.php`
  - `2026_06_19_100001_seed_hubspot_line_item_mapping_variantes.php`

- Testes existentes relevantes:
  - `tests/Feature/Phase34HubspotWebhookTest.php`
  - `tests/Feature/Phase35HubspotV2Test.php`
  - `tests/Feature/Phase35HubspotNotifyTest.php`
  - `tests/Feature/Phase37LineItemsFetchTest.php`
  - `tests/Feature/Phase37WebhookLineItemsTest.php`
  - `tests/Feature/Phase37LineItemMappingTest.php`
  - `tests/Feature/Phase37HubspotLineItemMappingAdminTest.php`
  - `tests/Feature/Phase37ComercialListagemTest.php`

## Problemas a resolver

1. Dados de contato chegam pouco estruturados.
   - Hoje apenas o primeiro contato associado e usado.
   - Nome do contato vai para `notes`, nao para uma coluna propria.
   - Nao busca `mobilephone`, `jobtitle`, `hs_additional_emails`.
   - Nao ha criterio para escolher contato principal quando ha varios.

2. Dados de empresa/negocio chegam limitados.
   - Company: apenas `name`, `cnpj`, `email`, `phone`.
   - Deal: apenas custom props do close + `dealname`, `amount`, `dealstage`.
   - Observacao comercial nao esta mapeada de forma clara.
   - IDs HubSpot nao ficam estruturados no sistema, dificultando replay, deduplicacao e auditoria.

3. Valor do contrato pode ser interpretado errado.
   - Com line item, sistema usa `price * quantity`.
   - Sem line item, usa `deal.amount`.
   - Nao busca `amount` do line item, `hs_recurring_billing_period`, MRR/ARR/TCV/ACV ou moeda.
   - Nao registra se o valor veio mensal, anual ou inferido.
   - Caso R$ 36.000 anual vs R$ 3.000 mensal precisa virar R$ 3.000 em `contratos_servico.valor_contratado` quando o servico e mensal.

4. Deduplicacao e atualizacao de empresas existentes ainda sao fracas no webhook.
   - A idempotencia evita duplicar pelo mesmo deal, mas nao evita duplicar uma empresa que ja existe com mesmo CNPJ, dominio, email ou nome normalizado.

5. Observabilidade e replay precisam evoluir.
   - `hubspot_eventos.payload` guarda o evento original e warnings, mas nao guarda snapshot enriquecido completo do deal/company/contact/line_items normalizados.
   - Depois que um mapping e criado na UI, o sistema remove a pendencia na leitura, mas nao ha comando claro para reprocessar o evento e criar contrato faltante.

## Objetivo de implementacao

Transformar a integracao HubSpot -> Comercial em um fluxo de "handoff operacional":

- Criar ou enriquecer a empresa com dados maximos e confiaveis.
- Criar contratos de servico com valor operacional correto.
- Guardar dados brutos/normalizados do HubSpot para auditoria.
- Marcar pendencias quando a inferencia nao for segura.
- Preservar compatibilidade com o fluxo legado e todos os testes atuais.

## Plano de execucao sugerido

### Fase 1 - Ampliar configuracao e descoberta de propriedades

Atualizar `config/services.php` para aceitar mais propriedades mapeaveis por env, sem hardcode irreversivel:

```php
'hubspot' => [
    'props' => [
        'deal' => [
            'observacao' => env('HUBSPOT_PROP_DEAL_OBSERVACAO', 'observacao'),
            'description' => env('HUBSPOT_PROP_DEAL_DESCRIPTION', 'description'),
            'closed_won_reason' => env('HUBSPOT_PROP_DEAL_CLOSED_WON_REASON', 'closed_won_reason'),
            'closedate' => env('HUBSPOT_PROP_DEAL_CLOSEDATE', 'closedate'),
            'pipeline' => env('HUBSPOT_PROP_DEAL_PIPELINE', 'pipeline'),
            'hs_mrr' => env('HUBSPOT_PROP_DEAL_MRR', 'hs_mrr'),
            'hs_arr' => env('HUBSPOT_PROP_DEAL_ARR', 'hs_arr'),
            'hs_tcv' => env('HUBSPOT_PROP_DEAL_TCV', 'hs_tcv'),
            'hs_acv' => env('HUBSPOT_PROP_DEAL_ACV', 'hs_acv'),
            'hs_currency' => env('HUBSPOT_PROP_DEAL_CURRENCY', 'hs_currency'),
        ],
        'company' => [
            'domain' => env('HUBSPOT_PROP_COMPANY_DOMAIN', 'domain'),
            'industry' => env('HUBSPOT_PROP_COMPANY_INDUSTRY', 'industry'),
            'annualrevenue' => env('HUBSPOT_PROP_COMPANY_ANNUAL_REVENUE', 'annualrevenue'),
            'city' => env('HUBSPOT_PROP_COMPANY_CITY', 'city'),
            'state' => env('HUBSPOT_PROP_COMPANY_STATE', 'state'),
            'country' => env('HUBSPOT_PROP_COMPANY_COUNTRY', 'country'),
        ],
        'contact' => [
            'mobilephone' => env('HUBSPOT_PROP_CONTACT_MOBILEPHONE', 'mobilephone'),
            'jobtitle' => env('HUBSPOT_PROP_CONTACT_JOBTITLE', 'jobtitle'),
            'additional_emails' => env('HUBSPOT_PROP_CONTACT_ADDITIONAL_EMAILS', 'hs_additional_emails'),
        ],
    ],
]
```

Importante:

- Antes de depender de `hs_mrr`, `hs_arr`, `hs_tcv`, `hs_acv` ou qualquer custom property, criar um mecanismo de diagnostico via Properties API para validar se existem na conta.
- Sugestao: comando Artisan `php artisan hubspot:inspect-properties --objects=deals,companies,contacts,line_items` que imprime nome interno, label, type e fieldType. Nao gravar tokens em log.
- Se alguma propriedade nao existir, o fluxo nao deve quebrar; apenas deve registrar ausencia no snapshot.

### Fase 2 - Criar DTO/enriquecedor de handoff HubSpot

Extrair a logica de normalizacao do `HubspotWebhookController` para classes testaveis:

- `app/Services/Hubspot/HubspotDealHandoffService.php`
- `app/Services/Hubspot/HubspotHandoffData.php`
- `app/Services/Hubspot/HubspotValueResolver.php`

Responsabilidade:

- Receber `dealId`.
- Buscar deal, companies associadas, contacts associados e line items.
- Normalizar tudo em um array/DTO unico.
- Calcular:
  - `company_data`
  - `contact_data`
  - `deal_data`
  - `line_items`
  - `contracts_to_create`
  - `warnings`
  - `confidence`

O controller deve ficar mais fino:

1. validar webhook;
2. checar filtro/idempotencia;
3. chamar handoff service;
4. persistir empresa/contratos;
5. atualizar `hubspot_eventos`;
6. notificar Comercial se houver pendencias.

### Fase 3 - Melhorar `HubspotApiClient`

Adicionar metodos sem quebrar os atuais:

- `fetchAssociatedCompanyIds(string $dealId): array`
- `fetchAssociatedContactIds(string $dealId): array`
- `fetchAssociations(string $fromObject, string $fromId, string $toObject): array`
- `fetchContacts(array $ids, array $properties): array`
- `fetchCompanies(array $ids, array $properties): array`
- `fetchDealLineItems(string $dealId): array` com propriedades ampliadas.

Propriedades minimas para line item:

```php
$properties = [
    'name',
    'description',
    'price',
    'amount',
    'quantity',
    'hs_product_id',
    'hs_sku',
    'recurringbillingfrequency',
    'hs_recurring_billing_period',
    'hs_recurring_billing_start_date',
    'hs_recurring_billing_end_date',
    'hs_line_item_currency_code',
    'hs_mrr',
    'hs_arr',
    'hs_tcv',
    'hs_acv',
];
```

Observacao:

- Validar os nomes internos com Properties API. Se a conta usar nomes diferentes, mapear via env.
- Manter `Http::fake` em todos os testes.
- Nao migrar agora de `/crm/v3/objects` para `/crm/objects/2026-03` se isso aumentar risco; documentar a decisao. O codigo atual usa v3 e os testes ja cobrem esse contrato.

### Fase 4 - Dados estruturados no banco

Criar migrations pequenas, idempotentes e com rollback.

#### Companies

Adicionar, se ainda nao existirem:

- `hubspot_deal_id` string nullable index.
- `hubspot_company_id` string nullable index.
- `hubspot_contact_id` string nullable index.
- `nome_contato` string nullable.
- `cargo_contato` string nullable.
- `hubspot_domain` string nullable.
- `hubspot_observacao` text nullable.
- `hubspot_snapshot` json nullable.

Opcional: se o projeto ja preferir nao aumentar `companies`, criar tabela `company_hubspot_handoffs`:

- `company_id`
- `deal_id`
- `hubspot_company_id`
- `primary_contact_id`
- `snapshot` json
- `warnings` json
- `processed_at`

Preferencia recomendada:

- Campos mais usados operacionalmente em `companies` (`nome_contato`, `cargo_contato`, `hubspot_deal_id`, `hubspot_company_id`, `hubspot_contact_id`, `hubspot_observacao`).
- Snapshot completo em tabela auxiliar ou JSON.

#### ContratosServico

Adicionar, se ainda nao existirem:

- `hubspot_line_item_id` string nullable index.
- `hubspot_product_id` string nullable.
- `hubspot_billing_frequency` string nullable.
- `hubspot_billing_period` string nullable.
- `hubspot_currency` string nullable.
- `hubspot_valor_original` decimal(12,2) nullable.
- `hubspot_valor_original_tipo` string nullable. Ex: `unit_price`, `net_price`, `mrr`, `arr`, `deal_amount`.
- `hubspot_valor_normalizado_mensal` decimal(12,2) nullable.
- `hubspot_valor_confidence` string nullable. Ex: `high`, `medium`, `low`.
- `hubspot_valor_warning` text nullable.
- `hubspot_snapshot` json nullable.

Regra:

- `valor_contratado` continua sendo o valor operacional usado pelo sistema.
- Para servicos mensais, `valor_contratado` deve ser o valor mensal normalizado.
- Para servicos unicos, `valor_contratado` deve ser o valor total/unico.
- Campos HubSpot guardam como esse valor foi obtido.

### Fase 5 - Escolha inteligente de contato principal

Trocar o uso de `fetchAssociatedContactId()` pelo fluxo com todos os contatos.

Regra de prioridade sugerida:

1. Se associations vierem com label util no futuro, preferir labels como `Decision maker`, `Billing contact`, `Primary`, `Proprietario`, `Financeiro` ou equivalentes configuraveis.
2. Preferir contato que tenha email e telefone.
3. Preferir contato que tenha email.
4. Preferir contato que tenha telefone/mobilephone.
5. Fallback para primeiro contato retornado.

Campos a preencher:

- `companies.nome_contato` = `firstname + lastname`.
- `companies.email_cliente` = email da company se existir; senao email do contato principal.
- `companies.telefone` = phone da company se existir; senao `phone` ou `mobilephone` do contato principal.
- `companies.cargo_contato` = `jobtitle`.
- `companies.notes` pode continuar recebendo uma linha textual, mas nao deve ser a unica fonte.

No snapshot, guardar todos os contatos associados, nao apenas o escolhido.

### Fase 6 - Resolver valor mensal vs anual

Criar `HubspotValueResolver` com testes unitarios. Nao deixar a regra espalhada dentro do controller.

Entrada:

- `Servico $servico`
- `array $lineItem`
- `array $dealProps`

Saida:

```php
[
    'valor_operacional' => 3000.00,
    'valor_original' => 36000.00,
    'valor_original_tipo' => 'arr_or_deal_amount',
    'normalizado_mensal' => 3000.00,
    'billing_frequency' => 'monthly',
    'billing_period' => 'P1Y',
    'confidence' => 'high',
    'warning' => null,
]
```

Regras sugeridas:

1. Se ha line item mapeado e `Servico.tipo_cobranca === mensal`:
   - Se `recurringbillingfrequency === monthly` e `price` numerico:
     - usar `price * quantity` como valor mensal.
     - `confidence=high`.
   - Se `recurringbillingfrequency === annually` e `price` numerico:
     - se houver `hs_mrr` numerico, usar `hs_mrr`.
     - senao usar `price * quantity / 12`.
     - `confidence=medium` ou `high` se `hs_recurring_billing_period` indicar 12 meses.
   - Se `hs_mrr` numerico existir:
     - usar `hs_mrr` como fonte forte para mensal.
   - Se apenas `amount` do line item existir e parecer anual:
     - se `amount / 12` se aproxima do `Servico.valor_padrao` ou de `price`, usar `amount / 12`.
     - registrar warning de inferencia.

2. Se ha line item mapeado e `Servico.tipo_cobranca === unica`:
   - usar `amount` do line item se numerico; senao `price * quantity`.
   - nao dividir por 12.

3. Se nao ha line item e cai no fluxo legado com `deal.amount`:
   - Se servico mensal e `deal.amount / 12` bate com `Servico.valor_padrao` dentro de tolerancia configuravel, usar `deal.amount / 12`.
   - Se servico mensal e `deal.amount` parece mensal, usar `deal.amount`.
   - Se nao for possivel decidir, usar valor mais conservador e marcar pendencia `valor_revisar`.

4. Nunca sobrescrever um valor com baixa confianca sem deixar rastreio.

5. Tolerancia:
   - usar helper com margem percentual pequena, ex. 5%, para comparar `amount / 12` com `valor_padrao`.
   - exemplo: `36000 / 12 = 3000`, catalogo `3000`, usar `3000`.

6. Multi-line items:
   - resolver cada line item individualmente.
   - se dois line items mapearem para o mesmo `Servico`, criar contratos separados ou consolidar? Decidir pelo padrao atual do sistema. Recomendacao: manter contratos separados se line_item_id diferente, salvo se ja existir regra de consolidacao.

### Fase 7 - Deduplicacao e enriquecimento de empresa existente

Antes de criar uma nova `Company`, procurar empresa existente por:

1. `hubspot_company_id`
2. `cnpj`
3. `email_cliente`
4. `hubspot_domain`/domain
5. `name` normalizado

Comportamento recomendado:

- Se encontrar match forte (`hubspot_company_id` ou `cnpj`):
  - atualizar campos vazios com dados vindos do HubSpot.
  - nao sobrescrever campos preenchidos manualmente sem regra clara.
  - adicionar contrato novo se ainda nao existir `hubspot_line_item_id` igual.
  - marcar `empresa_nova=true` se fizer sentido operacional.
- Se encontrar match fraco por nome:
  - nao atualizar automaticamente campos criticos.
  - criar pendencia `possivel_duplicidade` na listagem Comercial.

Adicionar esta nova pendencia apenas se a UI conseguir exibir; caso contrario, guardar warning no `hubspot_eventos.payload`.

### Fase 8 - UI e pendencias comerciais

Atualizar `ComercialController::listagem()` e `resources/js/Pages/Comercial/EmpresasListagem.jsx` para expor:

- `nome_contato`
- `cargo_contato`
- `hubspot_observacao`
- `hubspot_deal_id`
- `hubspot_company_id`
- indicadores de valor:
  - valor operacional
  - valor original HubSpot
  - frequencia
  - confianca
  - warning

Novas pendencias sugeridas:

- `sem_contato`
- `valor_revisar`
- `possivel_duplicidade`
- `observacao_ausente` somente se for realmente obrigatorio para o operacional.

Nao lotar a tela. Mostrar detalhes em tooltip/drawer/modal leve.

### Fase 9 - Replay e manutencao

Criar comando:

```bash
php artisan hubspot:reprocess-event {hubspot_evento_id}
```

Funcoes:

- Reprocessar evento que ficou sem servico por mapping ausente.
- Recriar/atualizar contratos faltantes depois que admin cadastrar mapping.
- Nao duplicar company/contrato.
- Logar resumo:
  - evento id
  - deal id
  - company id
  - contratos criados/atualizados/ignorados
  - warnings

Tambem criar opcional:

```bash
php artisan hubspot:inspect-properties --object=deals
php artisan hubspot:inspect-properties --object=line_items
```

### Fase 10 - Testes obrigatorios

Manter os testes atuais verdes:

```bash
php artisan test --filter=Phase34HubspotWebhookTest
php artisan test --filter=Phase35HubspotV2Test
php artisan test --filter=Phase37LineItemsFetchTest
php artisan test --filter=Phase37WebhookLineItemsTest
php artisan test --filter=Phase37LineItemMappingTest
php artisan test --filter=Phase37ComercialListagemTest
```

Criar novos testes:

1. `HubspotValueResolverTest`
   - monthly price 3000 + deal amount 36000 -> `valor_operacional=3000`.
   - annually price 36000 + period P1Y -> `valor_operacional=3000`.
   - line item `hs_mrr=3000`, `hs_arr=36000` -> usa MRR.
   - servico unico com amount 36000 -> nao divide por 12.
   - sem line item, deal.amount 36000, servico.valor_padrao 3000 -> usa 3000 com warning/inferencia.
   - sem line item, deal.amount 35000, sem valor_padrao compativel -> marca `valor_revisar`.

2. `PhaseHubspotEnrichmentTest`
   - deal com varios contatos escolhe o contato com email+telefone.
   - company sem phone usa contact.mobilephone.
   - grava `nome_contato` estruturado.
   - grava `hubspot_deal_id`, `hubspot_company_id`, `hubspot_contact_id`.
   - snapshot contem deal/company/contact/line_items.

3. `PhaseHubspotDedupTest`
   - empresa existente por CNPJ e enriquecida, nao duplicada.
   - empresa existente por `hubspot_company_id` recebe novo contrato sem duplicar.
   - match fraco por nome gera warning/pendencia, nao merge automatico agressivo.

4. `PhaseHubspotReplayTest`
   - evento com line item sem mapping nao cria contrato.
   - admin cadastra mapping.
   - comando de replay cria contrato e remove efeito pratico da pendencia.

5. `PhaseHubspotComercialListagemEnrichmentTest`
   - listagem expõe contato, observacao, confianca de valor e warning.
   - pendencia `valor_revisar` aparece apenas para origem HubSpot.

## Criterios de aceite

- Quando um deal fechado ganho chega com line item mensal de R$ 3.000 e deal amount/ARR de R$ 36.000, `contratos_servico.valor_contratado` fica R$ 3.000.
- O valor anual/total do HubSpot fica guardado em campos de auditoria/snapshot.
- Empresa chega com telefone, email, faturamento, observacao e dados de contato sempre que esses dados existirem no HubSpot.
- Nome do contato nao fica apenas em `notes`; fica estruturado.
- Se houver varios contatos, a escolha do contato principal e deterministica.
- Webhook continua idempotente.
- Empresa existente nao e duplicada quando houver CNPJ ou HubSpot Company ID.
- Nenhum teste faz chamada real ao HubSpot.
- Tokens nunca aparecem em logs.
- Fluxo legado sem line items continua funcionando.
- Comercial consegue ver pendencias claras quando dado/valor nao for confiavel.

## Cuidados de implementacao

- Nao remover o fluxo legado atual.
- Nao alterar rotas publicas sem necessidade.
- Nao sobrescrever dados preenchidos manualmente sem regra clara.
- Manter migrations defensivas com `Schema::hasColumn`.
- Usar `DB::transaction` na criacao/atualizacao de empresa + contratos.
- Gravar warnings no `HubspotEvento.payload` e logs no canal `ecf-webhooks`.
- Evitar transformar `observacoes` de `ContratoServico` em fonte unica de dados estruturados. Criar colunas/JSON dedicadas.
- Se usar substring no mapping, adicionar testes contra falsos positivos.
- Se alguma propriedade HubSpot nao existir, tratar como null e registrar no snapshot. Nao falhar o webhook por propriedade ausente.

## Ordem recomendada para Claude Code

1. Rodar leitura rapida dos arquivos citados para confirmar que nao houve mudanca desde este prompt.
2. Criar testes do `HubspotValueResolver` primeiro.
3. Implementar `HubspotValueResolver`.
4. Ampliar `HubspotApiClient::fetchDealLineItems`.
5. Criar migrations de campos estruturados.
6. Refatorar `HubspotWebhookController` ou extrair service mantendo comportamento atual.
7. Atualizar listagem Comercial com os novos campos/pendencias.
8. Criar comando de replay.
9. Rodar testes focados.
10. Rodar suite relacionada ao HubSpot/Comercial.

## Resultado esperado do PR

Entregar um PR incremental, sem reescrever o modulo inteiro, contendo:

- Enriquecimento de dados HubSpot.
- Resolver de valor mensal/anual testado.
- Persistencia estruturada de origem HubSpot.
- Deduplicacao basica.
- UI/listagem com dados e pendencias novas.
- Replay para corrigir eventos depois de mapping.
- Documentacao curta em `CLAUDE.md` ou em novo doc tecnico explicando a regra de valor.
