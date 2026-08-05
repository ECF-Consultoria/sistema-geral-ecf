---
phase: quick-260805-eqk
plan: 01
type: execute
wave: 1
depends_on: []
autonomous: true
requirements: [QUICK-260805-EQK]
files_modified:
  - app/Services/HubspotApiClient.php
  - app/Http/Controllers/Api/HubspotWebhookController.php
  - app/Http/Controllers/ComercialController.php
  - app/Http/Controllers/CompanyController.php
  - app/Models/Company.php
  - config/services.php
  - .env.example
  - database/migrations/*_add_hubspot_notas_origem_lead_to_companies_table.php
  - database/migrations/*_drop_campos_comerciais_mortos_from_companies_table.php
  - app/Console/Commands/BackfillCompanyMarketplaces.php
  - resources/js/Pages/Companies/Show.jsx
  - resources/js/Pages/Companies/Index.jsx
  - resources/js/Pages/Comercial/EmpresasListagem.jsx
  - resources/js/Pages/Comercial/NovaEmpresa.jsx
  - resources/js/Pages/Comercial/AtribuirServico.jsx
  - tests/Feature/Phase34CompaniesCloseFieldsTest.php
  - tests/Feature/Phase34HubspotWebhookTest.php
  - tests/Feature/Phase35HubspotV2Test.php
  - tests/Feature/Phase35HubspotNotifyTest.php
  - tests/Feature/Phase37WebhookLineItemsTest.php
  - tests/Feature/Phase37CompaniesPerformanceFilterTest.php
  - tests/Feature/Phase37ComercialListagemTest.php
  - tests/Feature/Phase111HubspotConfigPropsTest.php
  - tests/Feature/Phase111HubspotSchemaTest.php
  - tests/Feature/Phase113HubspotDedupTest.php
  - tests/Feature/Phase114ComercialListagemEnrichmentTest.php
  - tests/Feature/Phase115HubspotInvariantesTest.php
  - tests/Feature/BackfillMarketplacesTest.php
  - tests/Feature/QuickEqkHubspotNotesOrigemLeadTest.php

must_haves:
  truths:
    - "O webhook HubSpot grava as Notes do deal na empresa (`companies.hubspot_notas`) e o texto consolidado em `hubspot_observacao`"
    - "Nota NOVA criada no HubSpot depois da empresa já existir aparece no ECF após reprocessamento (espelho, não 'só se vazio')"
    - "A Origem do lead vem do CONTATO principal e é gravada em `companies.origem_lead`, sem sobrescrever valor preenchido à mão"
    - "As 5 colunas comerciais mortas (nicho, dor, vende_ml, faturamento_mensal, marketplaces_extras) não existem mais no banco, nos controllers, na config nem na UI"
    - "`email_colaborador` continua existindo (coluna, dados, pendência `sem_email_colaborador` e form admin em /companies)"
    - "A página da empresa mostra Origem do lead + lista de Observações (HubSpot) com data"
    - "A suíte de testes passa e nenhum teste de webhook faz chamada real à api.hubapi.com"
  artifacts:
    - path: "app/Services/HubspotApiClient.php"
      provides: "fetchNotes(objectType, objectId) — associations + detalhe, resiliente, sanitizado, ordenado asc"
      contains: "public function fetchNotes"
    - path: "config/services.php"
      provides: "props.note.{body,timestamp} e props.contact.{origem_do_lead,campanha_origem,criativo_origem}"
      contains: "'note' =>"
    - path: "app/Http/Controllers/Api/HubspotWebhookController.php"
      provides: "captura de notes + origem_lead nos dois fluxos (processar e reprocessarEvento)"
      contains: "fetchNotes"
    - path: "resources/js/Pages/Companies/Show.jsx"
      provides: "Origem do lead + bloco Observações (HubSpot) na seção Informações comerciais"
    - path: "tests/Feature/QuickEqkHubspotNotesOrigemLeadTest.php"
      provides: "cobertura de fetchNotes + gravação no webhook + reenriquecimento"
  key_links:
    - from: "app/Http/Controllers/Api/HubspotWebhookController.php"
      to: "app/Services/HubspotApiClient.php::fetchNotes"
      via: "chamada em processar() e reprocessarEvento() dentro de try/catch resiliente"
      pattern: "fetchNotes\\('deals'"
    - from: "app/Http/Controllers/Api/HubspotWebhookController.php"
      to: "companies.hubspot_notas / hubspot_observacao"
      via: "gravação SEMPRE reescrita (fora da regra 'só se vazio')"
      pattern: "hubspot_notas"
    - from: "app/Http/Controllers/CompanyController.php::show"
      to: "resources/js/Pages/Companies/Show.jsx"
      via: "props hubspot_notas + origem_lead"
      pattern: "'origem_lead'"
---

<objective>
Passar a capturar do HubSpot o que realmente existe na conta da ECF — as **Notes** (observações) associadas ao DEAL e a **Origem do lead** (property do CONTATO) — e remover do sistema os 5 campos comerciais que nunca foram preenchidos porque as properties correspondentes não existem no HubSpot.

**Propósito:** hoje `companies.hubspot_observacao` está NULL nas 11 empresas de origem HubSpot porque o webhook lê uma property inexistente (`observacao`). O time Comercial perde o histórico de conversa que está nas Notes. Ao mesmo tempo, 5 colunas/campos de formulário existem só pesando na UI e nas pendências, com preenchimento residual (nicho=3, dor=1, vende_ml=5, faturamento_mensal=2, marketplaces_extras=3 — a maioria "teste").

**Saída:** webhook grava notes + origem do lead; UI da empresa e do Comercial exibem os dois; schema, config, controllers, formulários e testes limpos dos 5 campos mortos.
</objective>

<contexto_ja_validado>
O diagnóstico abaixo foi feito contra a **API real do HubSpot** e o **banco de produção**. É fato — **NÃO reinvestigar, NÃO chamar a API HubSpot, NÃO acessar o VPS**.

1. `observacao` **não existe** no HubSpot (nem deal, nem contact, nem company). As observações reais são **Notes (engagements) associadas ao DEAL**.
   - Caso real: empresa Metalform (id 406), deal `62661178491`, 2 notes: `113069990193` (ts `2026-07-16T12:33:06.512Z`) e `114141013579` (ts `2026-08-03T16:50:39.488Z`).
   - Associação (v3): `GET /crm/v3/objects/deals/{dealId}/associations/notes` → `{"results":[{"id":"113069990193","type":"deal_to_note"}, ...]}`
   - Detalhe: `GET /crm/v3/objects/notes/{noteId}?properties=hs_note_body,hs_timestamp,hs_createdate,hubspot_owner_id`
   - `hs_note_body` vem com HTML.
   - Notes associadas ao **CONTATO** retornaram 0 — as que importam estão no DEAL.
2. `origem_do_lead` (label "Origem do Lead", string/text) existe em **CONTACTS**. Metalform: contato `235433492313` → `"Parceiro de Polos"`. Irmãs, também em contacts: `campanha_origem`, `criativo_origem`.
3. O deal devolve: amount, hs_acv, hs_arr, hs_mrr, hs_tcv, dealname, pipeline, closedate, dealstage, createdate, description, hs_object_id, closed_won_reason, hs_lastmodifieddate, implicacao_do_problema, necessidade_de_solucao, situacao_atual_do_cliente, problema_principal_identificado. **NÃO** vieram `nicho`, `dor`, `vende_ml`, `faturamento_mensal`, `observacao`, `servico_ecf` — o webhook pede e o HubSpot ignora.
4. Produção (172 empresas): nicho=3 (1 é "teste"), dor=1 ("teste"), vende_ml=5, faturamento_mensal=2 (um é 0.00), marketplaces_extras=3 não-vazios (2 são teste), email_colaborador=7. `company_marketplaces` (161 linhas / 145 empresas) já substituiu `marketplaces_extras`.
5. **Decisão do usuário:** `companies.email_colaborador` **MANTÉM** coluna e dados (6 gmails reais de onboarding, não duplicados em nenhum outro lugar). Só sai da tela da empresa e do formulário do Comercial.
</contexto_ja_validado>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@CLAUDE.md

Arquivos-chave (ler sob demanda, não tudo de uma vez):
@app/Services/HubspotApiClient.php
@app/Http/Controllers/Api/HubspotWebhookController.php
@config/services.php
@app/Models/Company.php

**Padrões do arquivo `HubspotApiClient.php` que a Task 1 DEVE seguir** (já usados por `fetchDealLineItems`):
- `private const BASE = 'https://api.hubapi.com';`
- `Http::withToken($this->token)->get(self::BASE . "/crm/v3/objects/...")`
- Canal de log: `Log::channel('ecf-webhooks')->warning(...)` com contexto contendo **apenas IDs + status HTTP** — NUNCA o token nem a string `Bearer`.
- Encadeamento: chamada 1 = associations (`!$res->ok()` → warning + `return []`); chamada 2..N = detalhe individual (`!$res->ok()` → warning + `continue`).
- Extração de id na v3: `$r['id'] ?? $r['toObjectId']` (armadilha conhecida — v3 devolve `id`; ler só `toObjectId` zerou company/contato de todo handoff em produção).

**Onde a normalização de contato acontece no controller** (dois blocos IDÊNTICOS que precisam da mesma mudança):
- `processar()` — bloco `foreach ($mapaContatos as $contactId => $props)` (~linhas 199-209)
- `reprocessarEvento()` — bloco equivalente (~linhas 315-325)
Ambos montam `$contatos[]` com chaves LÓGICAS (`id`, `firstname`, `lastname`, `email`, `phone`, `mobilephone`, `jobtitle`) lendo `$props[$propsContact['x'] ?? 'x']`. A chave `origem_do_lead` **ainda não existe ali** — precisa ser adicionada nos dois. As properties pedidas ao HubSpot já são `array_values($propsContact)`, então basta acrescentar em `config/services.php`.

**Regra de enriquecimento existente** (`enriquecerEmpresaExistente`, ~682-733): percorre `$candidatos` e só grava quando o valor atual da coluna está `null`/`''`. `hubspot_snapshot` **não** passa por essa regra — é sempre reescrito no `$company->update([...])` no fim de `criarEmpresa()` (~658-668).
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Captura de Notes do deal e Origem do lead do contato</name>
  <files>
app/Services/HubspotApiClient.php,
config/services.php,
.env.example,
database/migrations/{timestamp}_add_hubspot_notas_origem_lead_to_companies_table.php,
app/Models/Company.php,
app/Http/Controllers/Api/HubspotWebhookController.php
  </files>
  <behavior>
    - `fetchNotes('deals', '62661178491')` com associations devolvendo 2 ids e detalhes OK → array de 2 itens `['id','body','timestamp']` ordenado por timestamp ASCENDENTE (mais antigo primeiro).
    - associations 404/500 → `[]` (com warning), sem exceção.
    - detalhe individual 404 → aquele id é pulado, os demais retornam.
    - `hs_note_body` = `"<p>primeira</p><p>segunda</p>"` → `body` === `"primeira\nsegunda"` (nunca `"primeirasegunda"`).
    - nota com `hs_note_body` vazio ou só HTML sem texto → não entra no retorno.
    - nenhum registro do canal `ecf-webhooks` contém o token nem a string `Bearer`.
    - Webhook de deal ganho com 2 notes → `companies.hubspot_notas` tem 2 itens e `hubspot_observacao` = bodies unidos por `"\n\n"` na ordem cronológica.
    - Webhook com contato tendo `origem_do_lead = "Parceiro de Polos"` → `companies.origem_lead === 'Parceiro de Polos'`.
    - `hubspot_snapshot` contém a chave `notes`.
  </behavior>
  <action>
**1.1 — `app/Services/HubspotApiClient.php`: novo método público `fetchNotes(string $objectType, string $objectId): array`.**

Seguir EXATAMENTE o padrão de `fetchDealLineItems` do mesmo arquivo (ver `<context>`): `Http::withToken($this->token)`, canal `ecf-webhooks`, resiliente, PHPDoc em pt-BR descrevendo o shape do retorno.

- Passo 1 — `GET {BASE}/crm/v3/objects/{$objectType}/{$objectId}/associations/notes`. `!$res->ok()` → warning (`object_type`, `object_id`, `status`) + `return []`. Extrair ids de `results[]` com `$r['id'] ?? $r['toObjectId']`, descartando vazios/nulos. Lista vazia → `return []` sem mais chamadas HTTP.
- Passo 2 — para cada id, `GET {BASE}/crm/v3/objects/notes/{$noteId}` com o parâmetro `properties` = `implode(',', array_values(config('services.hubspot.props.note')))` acrescido de `hs_createdate`. Falha individual → warning (`object_id`, `note_id`, `status`) + `continue` (nunca aborta o lote).
- Sanitização do corpo: ler a property pela chave lógica (`config('services.hubspot.props.note.body')`, fallback `'hs_note_body'`). Converter `<br>`, `<br/>`, `<br />`, `</p>` e `</div>` em `"\n"` **ANTES** do `strip_tags` (senão palavras de parágrafos distintos colam), depois `html_entity_decode`, `strip_tags` e `trim`. Body vazio após o trim → **pular a nota**.
- Timestamp: property lógica `timestamp` (fallback `hs_timestamp`), com fallback para `hs_createdate` quando ausente; `null` se nenhum dos dois vier.
- Ordenação final: **ASCENDENTE por timestamp** (mais antigo primeiro, mais recente por último). Notes sem timestamp vão para o fim, preservando a ordem de chegada.
- Retorno: `array<int, array{id: string, body: string, timestamp: ?string}>`.

**1.2 — `config/services.php`.** Dentro de `services.hubspot.props`, adicionar o bloco:

`'note' => ['body' => env('HUBSPOT_PROP_NOTE_BODY', 'hs_note_body'), 'timestamp' => env('HUBSPOT_PROP_NOTE_TIMESTAMP', 'hs_timestamp')]`

Em `props.contact`, ADICIONAR três chaves (sem remover nenhuma existente): `origem_do_lead` → env `HUBSPOT_PROP_CONTACT_ORIGEM_LEAD` default `origem_do_lead`; `campanha_origem` → env `HUBSPOT_PROP_CONTACT_CAMPANHA_ORIGEM` default `campanha_origem`; `criativo_origem` → env `HUBSPOT_PROP_CONTACT_CRIATIVO_ORIGEM` default `criativo_origem`.

Comentário em pt-BR acima de `props.contact` registrando que `origem_do_lead` vive no **CONTATO** (não em deal nem company) — validado contra a conta real da ECF. Documentar as 5 envs novas no `.env.example`, junto das demais `HUBSPOT_PROP_*`.

**1.3 — Migration nova** `add_hubspot_notas_origem_lead_to_companies_table`: em `companies`, `hubspot_notas` (json nullable) e `origem_lead` (string 255 nullable). Guardar cada `addColumn` com `Schema::hasColumn` (padrão do projeto) nos dois sentidos (`up` e `down`). Sem índice — não há consulta por essas colunas.

**1.4 — `app/Models/Company.php`:** `hubspot_notas` e `origem_lead` em `$fillable` (junto do bloco HubSpot, linhas ~45-48); `'hubspot_notas' => 'array'` em `$casts` (ao lado de `hubspot_snapshot`).

**1.5 — `app/Http/Controllers/Api/HubspotWebhookController.php`:**

a) Nos DOIS blocos de normalização de contato (`processar()` ~199-209 e `reprocessarEvento()` ~315-325), adicionar a chave lógica `'origem_do_lead' => $props[$propsContact['origem_do_lead'] ?? 'origem_do_lead'] ?? null`. As properties já são pedidas via `array_values($propsContact)`, então a mudança de config em 1.2 basta para o fetch.

b) Em `processar()` E em `reprocessarEvento()`, após o bloco de contatos, buscar as notes do deal em `try/catch (\Throwable)` **no mesmo padrão do bloco de contatos**: falha gera apenas `Log::channel('ecf-webhooks')->warning(...)` (em `reprocessarEvento` também acrescenta em `$warnings[]`) e segue com `[]` — **nunca quebra o webhook**. Passar o resultado a `criarEmpresa()` como novo parâmetro nomeado `notes` (adicionar à assinatura com default `[]`, ao final da lista de parâmetros, e ao `use (...)` da closure da `DB::transaction`).

c) Em `criarEmpresa()`, calcular o texto consolidado: bodies das notes unidos por `"\n\n"` na ordem recebida (mais recente por último); `null` quando não houver nota.

d) **REGRA CRÍTICA — espelho, não input humano.** `hubspot_notas` e `hubspot_observacao` são retrato do HubSpot e devem ser **SEMPRE reescritos**, inclusive no caminho de match forte, exatamente como `hubspot_snapshot` já é. Concretamente:
   - **REMOVER** `'hubspot_observacao' => ...` do array `$candidatos` de `enriquecerEmpresaExistente()` (~713) e remover o parâmetro `observacaoRaw` da assinatura e da chamada, já que a property `observacao` do deal não existe (o `$observacaoRaw` lido de `$dprops` em ~508 sai junto).
   - Gravar `hubspot_notas` e `hubspot_observacao` **fora** dessa regra: incluí-los no mesmo `$company->update([...])` que já reescreve `hubspot_snapshot` no fim de `criarEmpresa()` (~658-668) — assim ambos os caminhos (criação e match forte) recebem o valor novo.
   - No `Company::create()` do caminho de criação, `hubspot_observacao` pode ser omitido (o update final o grava) — se mantido, deve receber o texto consolidado das notes, não o antigo `$observacaoRaw`.
   - **NÃO** aplicar "só preenche se estiver vazio" a essas duas colunas. Motivo concreto: a Metalform ganhou uma nota em 03/08 **depois** de a empresa já existir; sob a regra antiga essa nota nunca apareceria.

e) `origem_lead`: extrair de `$contatoPrincipal['origem_do_lead']` (chave lógica). Esse **SIM** segue a regra normal de enriquecimento — vai no `Company::create` **e** no array `$candidatos` de `enriquecerEmpresaExistente()`, porque o Comercial pode querer corrigir à mão e dado manual é soberano.

f) `hubspot_snapshot`: adicionar a chave `'notes' => $notes` ao array do update final.

Comentários e PHPDoc em pt-BR. Não tocar nos 5 campos mortos nesta task (é a Task 2).
  </action>
  <verify>
    <automated>php artisan migrate --env=testing && php artisan test --filter="QuickEqkHubspotNotesOrigemLead"</automated>
    <nota>Os testes desta task são escritos na Task 4; nesta task, verificar com `php -l` nos arquivos alterados + `php artisan config:clear && php artisan tinker --execute="dd(array_keys(config('services.hubspot.props')));"` retornando deal/company/contact/note, e `php artisan test --filter=Phase111HubspotApiClient` (regressão do client).</nota>
  </verify>
  <done>`fetchNotes` existe e é resiliente; `config('services.hubspot.props.note')` e `props.contact.origem_do_lead` existem; migration aplicada com `hubspot_notas` (json) e `origem_lead` (string) em `companies`; webhook e replay buscam notes + origem do lead; `hubspot_notas`/`hubspot_observacao` são sempre reescritos e `origem_lead` respeita valor preenchido; `hubspot_snapshot` tem a chave `notes`. Commit atômico com `git commit -- <paths explícitos>`.</done>
</task>

<task type="auto">
  <name>Task 2: Remoção dos 5 campos comerciais mortos (backend)</name>
  <files>
database/migrations/{timestamp}_drop_campos_comerciais_mortos_from_companies_table.php,
app/Models/Company.php,
config/services.php,
.env.example,
app/Http/Controllers/Api/HubspotWebhookController.php,
app/Http/Controllers/ComercialController.php,
app/Http/Controllers/CompanyController.php,
app/Console/Commands/BackfillCompanyMarketplaces.php,
tests/Feature/Phase57/BackfillMarketplacesTest.php
  </files>
  <action>
Campos a remover: `nicho`, `dor`, `vende_ml`, `faturamento_mensal`, `marketplaces_extras`.

**⚠ `email_colaborador` NÃO ENTRA — fica em tudo** (coluna, dados, validate, props, pendência `sem_email_colaborador`, form admin de /companies).

**2.1 — Migration `drop_campos_comerciais_mortos_from_companies_table`.** `up()` dropa as 5 colunas de `companies`; `down()` recria com os tipos originais, todas nullable: `nicho` string(255), `dor` text, `vende_ml` tinyInteger, `faturamento_mensal` decimal(12,2), `marketplaces_extras` json. Guardar com `Schema::hasColumn` nos **dois sentidos** (idempotência — a árvore é compartilhada e o banco local pode estar em estados diferentes).

**2.2 — `app/Models/Company.php`:** remover as 5 de `$fillable` (linhas ~37-38, junto do comentário "Phase 34 Plan 34-01 — info do close comercial" — manter `email_colaborador`); remover `'vende_ml' => 'boolean'` (~58), `'marketplaces_extras' => 'array'` (~60) e `'faturamento_mensal' => 'decimal:2'` (~62) de `$casts`. Ajustar o docblock de `hubspotEventoOrigem()` (~473) que enumera as 5 pendências comerciais, retirando `dados_close_incompletos` e corrigindo a contagem no texto.

**2.3 — `config/services.php`:** remover de `props.deal` as chaves `nicho`, `dor`, `vende_ml`, `faturamento_mensal` (linhas ~129-132). Remover as linhas correspondentes (`HUBSPOT_PROP_DEAL_NICHO`, `_DOR`, `_VENDE_ML`, `_FATURAMENTO`) do `.env.example`.

**2.4 — `HubspotWebhookController`:** remover o parsing de `vende_ml`/`faturamento` (~478-484), as 4 chaves correspondentes do `Company::create` (~564-567), as 4 do array `$candidatos` de `enriquecerEmpresaExistente()` (~705-708) e os parâmetros `$faturamento` e `$vendeMl` da assinatura + das chamadas nomeadas.

**2.5 — `ComercialController`:** remover a pendência `dados_close_incompletos` **por completo**:
   - cálculo (~546-549, o `if ($c->nicho === null || $c->dor === null || $c->faturamento_mensal === null)`)
   - bloco de detalhe/`$faltam` (~293-299)
   - whitelist do filtro `pendencia` na query string (~195)
   - chave em `pendencia_counts` (~251)
   - docblock que enumera as 5 pendências (~168)

   E também: `nicho`/`dor`/`faturamento_mensal` das props da listagem (~360-362); as 5 do `validate()` do `store` (~624-629, incluindo a regra `marketplaces_extras.*`); as 5 do `Company::create` (~668-672) — **manter `email_colaborador` nas três**; as 5 do `validate()` do `update` (~802-807); e a prop `nicho` de `AtribuirServico` (~151) mais a menção no docblock (~106).

**2.6 — `CompanyController`:** remover as 5 das props do `index` (~151-155), das props do `show` (~507-511) e dos `validate()` do `update` (~676-681, incluindo `marketplaces_extras.*`). **⚠ MANTER `email_colaborador`** nas três posições (~156, ~512, ~684) e **MANTER a pendência `sem_email_colaborador`** (~196-212) intacta.

**2.7 — Deletar** `app/Console/Commands/BackfillCompanyMarketplaces.php` (fica órfão sem `marketplaces_extras`; `company_marketplaces` já está populado com 161 linhas) e o teste `tests/Feature/Phase57/BackfillMarketplacesTest.php`. Conferir com grep se o comando é referenciado em `routes/console.php` ou em algum scheduler e remover a referência se existir.

Após as remoções, rodar `grep -rn --include=*.php -w -e nicho -e vende_ml -e faturamento_mensal -e marketplaces_extras -e dados_close_incompletos app/ config/ database/migrations/` e confirmar que só sobram ocorrências nas migrations históricas (que **não** devem ser editadas) e na nova migration de drop. Comentários em pt-BR.
  </action>
  <verify>
    <automated>php artisan migrate --env=testing && php artisan tinker --execute="dd(\Illuminate\Support\Facades\Schema::hasColumn('companies','nicho'), \Illuminate\Support\Facades\Schema::hasColumn('companies','email_colaborador'));"</automated>
    <nota>Esperado: `false` para `nicho`, `true` para `email_colaborador`. A suíte completa só é exigida na Task 4 (os testes ainda referenciam os campos removidos).</nota>
  </verify>
  <done>As 5 colunas não existem mais em `companies`; nenhuma referência a elas em `app/`, `config/` ou `.env.example`; a pendência `dados_close_incompletos` sumiu do cálculo, do filtro, dos counts e dos detalhes; `BackfillCompanyMarketplaces` e seu teste deletados; `email_colaborador` e `sem_email_colaborador` preservados. Commit atômico com `git commit -- <paths explícitos>`.</done>
</task>

<task type="auto">
  <name>Task 3: Frontend — exibir notes/origem e limpar os campos mortos</name>
  <files>
app/Http/Controllers/CompanyController.php,
app/Http/Controllers/ComercialController.php,
resources/js/Pages/Companies/Show.jsx,
resources/js/Pages/Companies/Index.jsx,
resources/js/Pages/Comercial/EmpresasListagem.jsx,
resources/js/Pages/Comercial/NovaEmpresa.jsx,
resources/js/Pages/Comercial/AtribuirServico.jsx
  </files>
  <action>
**3.1 — Props.** `CompanyController::show()` (bloco ~505-512) e `ComercialController::listagem()` (bloco ~365-371, onde já vai `hubspot_observacao`) passam a expor `hubspot_notas` (array, default `[]`) e `origem_lead`.

**3.2 — `resources/js/Pages/Companies/Show.jsx`, seção "Informações comerciais" (~650-687):**
   - **REMOVER** os `InfoRow`: "Nicho", "Principal dor", "Vende no Mercado Livre", "Faturamento declarado", "Marketplaces extras", "E-mail do colaborador".
   - **MANTER** "E-mail do cliente" e "Telefone".
   - **ADICIONAR** `InfoRow` "Origem do lead" com `company.origem_lead`.
   - **ADICIONAR** bloco "Observações (HubSpot)": lista cada item de `company.hubspot_notas` com a data formatada `dd/mm/aaaa` e o corpo em `whitespace-pre-line`, seguindo o estilo do bloco SPIN logo abaixo (borda `border-white/[0.06]`, label `text-white/40 text-[11px] uppercase tracking-wide`). Se a lista estiver vazia, **não renderizar o bloco**.
   - **MANTER** o bloco existente de `company.notes` (observação manual local, ~666-671) e o bloco SPIN (~672-686).
   - Reequilibrar o grid `md:grid-cols-2` (~652-665), que agora tem só 3 campos — colapsar para coluna única ou redistribuir, seguindo o padrão visual do arquivo.
   - **⚠ ARMADILHA CONHECIDA (Rollup):** não usar variável do escopo do componente dentro do callback de `.map()` — computar flags booleanas **dentro** do callback, senão dá `ReferenceError` no bundle de produção.

**3.3 — `resources/js/Pages/Comercial/EmpresasListagem.jsx`:**
   - `DetalheHubspotModal`, bloco "Observação" (~418-424): passa a listar as notas de `empresa.hubspot_notas` com data (`dd/mm/aaaa`) + corpo; fallback `'—'` quando vazio (padrão do arquivo). Adicionar um bloco/linha "Origem do lead" com `empresa.origem_lead || '—'` — colocar junto do bloco "Contato" (~407-416), que é onde o dado nasce.
   - `EditarEmpresaModal`: remover `nicho`, `dor`, `faturamento_mensal` do `useForm` (~207-209), do sync de estado (~223-225) e dos inputs (~297-320, incluindo o hint "resolve a tag Close incompleto" da ~297).
   - Remover o label do mapa de pendências `dados_close_incompletos: 'Close incompleto'` (~38) e o badge correspondente na tabela (~50).

**3.4 — `resources/js/Pages/Comercial/NovaEmpresa.jsx`:** remover do formulário os 5 campos mortos **e também `email_colaborador`** (não é dado captado pelo Comercial). Limpar `useForm` (~96-101), o `transform` (~109-114 — a conversão de `vende_ml` e `faturamento_mensal` deixa de existir), o helper de toggle de `marketplaces_extras` (~139-143) e os inputs (~430-436, ~451-471, ~488-511, ~519-528). **Não confundir com `gmail_colaborador` (~105), que é outro campo e permanece.**

**3.5 — `resources/js/Pages/Companies/Index.jsx`:** remover os 5 campos mortos do modal admin de edição — `useForm` (~281-282), `transform` (~289-290), sync ao abrir (~309-314), toggle de marketplaces (~320-324) e os inputs (~781-806, ~828-842). **⚠ MANTER `email_colaborador`**: campo do form (~815), label de pendência `sem_email_colaborador` (~103) e contador (~232) permanecem — o admin continua podendo preencher.

**3.6 — `resources/js/Pages/Comercial/AtribuirServico.jsx`:** remover o badge de `nicho` (~225-227).

**3.7 — Rodar `npm run build`** (convenção obrigatória do projeto após mudança de frontend) e **reportar sucesso/falha real** — não presumir. Se o build falhar, corrigir antes de commitar.
  </action>
  <verify>
    <automated>npm run build</automated>
    <nota>Após o build, `grep -rn -e nicho -e vende_ml -e faturamento_mensal -e marketplaces_extras -e "Close incompleto" resources/js/` deve retornar zero ocorrências, e `grep -rn -e hubspot_notas -e origem_lead resources/js/Pages/Companies/Show.jsx` deve retornar as novas.</nota>
  </verify>
  <done>`npm run build` conclui sem erro; a página da empresa mostra "Origem do lead" e o bloco "Observações (HubSpot)" com data por nota; os 5 campos mortos sumiram de Show, Index, EmpresasListagem, NovaEmpresa e AtribuirServico; `email_colaborador` continua editável em `/companies` e sumiu da tela da empresa e do form do Comercial; badge "Close incompleto" removido. Commit atômico com `git commit -- <paths explícitos>` (incluir `public/build/` se versionado).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Testes — atualizar os afetados e cobrir o comportamento novo</name>
  <files>
tests/Feature/Phase34CompaniesCloseFieldsTest.php,
tests/Feature/Phase34HubspotWebhookTest.php,
tests/Feature/Phase35HubspotV2Test.php,
tests/Feature/Phase35HubspotNotifyTest.php,
tests/Feature/Phase37WebhookLineItemsTest.php,
tests/Feature/Phase37CompaniesPerformanceFilterTest.php,
tests/Feature/Phase37ComercialListagemTest.php,
tests/Feature/Phase111HubspotConfigPropsTest.php,
tests/Feature/Phase111HubspotSchemaTest.php,
tests/Feature/Phase112HubspotHandoffWebhookTest.php,
tests/Feature/Phase113HubspotDedupTest.php,
tests/Feature/Phase113HubspotEnrichmentTest.php,
tests/Feature/Phase114ComercialListagemEnrichmentTest.php,
tests/Feature/Phase114HubspotReplayTest.php,
tests/Feature/Phase115HubspotInvariantesTest.php,
tests/Feature/Phase116HubspotReenriquecerHandoffTest.php,
tests/Feature/QuickEqkHubspotNotesOrigemLeadTest.php
  </files>
  <behavior>
    - `fetchNotes` — 2 notes ordenadas asc por timestamp; associations 404 → `[]`; detalhe individual 404 → pula só aquela; `<p>a</p><p>b</p>` → `"a\nb"` (sem colar palavras); note com body vazio é pulada; nenhum log do canal `ecf-webhooks` contém o token nem `Bearer`.
    - Webhook — grava `hubspot_notas` com as 2 notas, `hubspot_observacao` com o texto consolidado na ordem cronológica, `origem_lead` do contato principal, e `hubspot_snapshot` com a chave `notes`.
    - Reprocessamento/enriquecimento — empresa já existente que ganha uma nota nova no HubSpot tem `hubspot_notas`/`hubspot_observacao` **ATUALIZADOS** (prova de que não caiu na regra "só se vazio"); `origem_lead` já preenchido à mão **NÃO** é sobrescrito.
  </behavior>
  <action>
**4.1 — Atualizar os testes quebrados pela remoção.** Alvos mapeados (linhas aproximadas):
   - `Phase34CompaniesCloseFieldsTest.php` — reescrever mantendo **só** a cobertura de `email_colaborador`.
   - `Phase34HubspotWebhookTest.php` (~206-209, ~246-249)
   - `Phase35HubspotV2Test.php` (~134-137)
   - `Phase35HubspotNotifyTest.php` (~229, ~253, ~286-299)
   - `Phase37WebhookLineItemsTest.php` (~576-579)
   - `Phase37CompaniesPerformanceFilterTest.php` (~74, ~276-288)
   - `Phase37ComercialListagemTest.php` (~341-356, ~400, ~418)
   - `Phase113HubspotDedupTest.php` (~303, ~331, ~336)
   - `Phase114ComercialListagemEnrichmentTest.php` (~497)
   - `Phase111HubspotConfigPropsTest.php` (~23-26) — hoje **exige** as 4 chaves removidas de `props.deal`; ajustar para não exigi-las e passar a exigir as novas `props.note.{body,timestamp}` e `props.contact.origem_do_lead`.
   - `Phase111HubspotSchemaTest.php` — colunas de `companies` mudaram (5 a menos, 2 a mais).
   - Todos os `setUp()` que fazem `config(['services.hubspot.props.deal' => [...]])` com as chaves removidas.

   Varredura obrigatória antes de rodar a suíte: `grep -rln -e "'nicho'" -e "'dor'" -e "'vende_ml'" -e "'faturamento_mensal'" -e "'marketplaces_extras'" -e dados_close_incompletos tests/` — a lista acima é o mapeamento conhecido, **não** necessariamente exaustivo.

**4.2 — `Http::fake()` dos novos endpoints (crítico).** O webhook agora chama 2 endpoints novos: `.../associations/notes` e `/crm/v3/objects/notes/{id}`. `Phase115HubspotInvariantesTest::test_nenhuma_chamada_hubspot_real_no_processamento_do_webhook` usa `Http::preventStrayRequests()` — qualquer chamada não mockada vira `StrayRequestException`. Portanto **todos** os fakes de testes que exercitam o webhook/replay precisam cobrir os 2 endpoints novos. Conjunto de arquivos com `Http::fake` + `api.hubapi.com`: `Phase34HubspotWebhookTest`, `Phase35HubspotV2Test`, `Phase35HubspotNotifyTest`, `Phase37WebhookLineItemsTest`, `Phase37LineItemsFetchTest`, `Phase111HubspotApiClientTest`, `Phase111InspectPropertiesTest`, `Phase112HubspotHandoffWebhookTest`, `Phase113HubspotDedupTest`, `Phase113HubspotEnrichmentTest`, `Phase114HubspotReplayTest`, `Phase115HubspotInvariantesTest` (helper `mockHubspotCompleto()`), `Phase116HubspotReenriquecerHandoffTest`. Atenção à **ordem dos padrões** no array do `Http::fake`: `*/objects/deals/*/associations/notes*` precisa vir antes de padrões mais genéricos de `deals/*`, e `*/objects/notes/*` antes de curingas amplos.

**4.3 — Novo arquivo `tests/Feature/QuickEqkHubspotNotesOrigemLeadTest.php`** cobrindo o `<behavior>` desta task. Reusar o padrão de setUp/HMAC/fakes de `Phase113HubspotEnrichmentTest` / `Phase114HubspotReplayTest` (não reinventar o disparo do webhook). Usar como fixture o caso real validado: deal `62661178491`, notes `113069990193` (ts `2026-07-16T12:33:06.512Z`) e `114141013579` (ts `2026-08-03T16:50:39.488Z`), contato com `origem_do_lead = "Parceiro de Polos"`. O teste de "nota nova em empresa existente" é o que prova a regra crítica da Task 1 — deve começar com a empresa já tendo `hubspot_notas` com 1 nota e terminar com 2. Nomes de teste e comentários em pt-BR.

**4.4 — Rodar a suíte completa ao final e reportar o resultado REAL** (contagem de passed/failed, colada da saída). Se algo falhar, corrigir; se não conseguir corrigir, **reportar honestamente** em vez de omitir. Rodar em SQLite (padrão do `phpunit.xml`) — o MariaDB local pode estar indisponível.
  </action>
  <verify>
    <automated>php artisan test --filter="QuickEqkHubspotNotesOrigemLead" && php artisan test</automated>
  </verify>
  <done>`php artisan test` roda com a contagem de passed/failed reportada explicitamente na saída do executor; o teste novo cobre os 3 grupos do `<behavior>`; nenhum teste dispara `StrayRequestException` nos endpoints de notes; testes dos 5 campos removidos atualizados ou reescritos. Commit atômico com `git commit -- <paths explícitos>`.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Descrição |
|----------|-----------|
| HubSpot API → HubspotApiClient | Conteúdo de terceiro (corpo de Note em HTML, escrito por humano) entra no sistema |
| companies.hubspot_notas → React (Show.jsx / EmpresasListagem.jsx) | Texto de origem externa renderizado na UI interna |
| HubspotWebhookController → migration de drop | Remoção destrutiva de colunas em produção |

## STRIDE Threat Register

| Threat ID | Categoria | Componente | Disposição | Mitigação |
|-----------|-----------|------------|------------|-----------|
| T-EQK-01 | Information Disclosure | `HubspotApiClient::fetchNotes` | mitigate | Warnings do canal `ecf-webhooks` carregam apenas `object_id`/`note_id`/`status`; nunca o token nem a string `Bearer`. Teste dedicado em 4.3 verifica o log. |
| T-EQK-02 | Tampering (XSS) | `hs_note_body` → `Show.jsx` | mitigate | `strip_tags` no backend (Task 1.1) remove todo markup antes de persistir; no React o valor entra como texto em `{}` (escapado por padrão) — **proibido** usar `dangerouslySetInnerHTML` nesta task. |
| T-EQK-03 | Denial of Service | loop de detalhe de notes no webhook | accept | N GETs sequenciais, mesmo padrão já aceito de `fetchDealLineItems`/`fetchContacts`; volume real observado é 2 notes por deal. Falha individual não aborta o lote. |
| T-EQK-04 | Repudiation / perda de dado | migration de drop das 5 colunas | mitigate | `down()` recria as colunas com os tipos originais; preenchimento residual em produção já auditado (nicho=3, dor=1, vende_ml=5, faturamento_mensal=2, marketplaces_extras=3 — maioria "teste"); `company_marketplaces` já substituiu `marketplaces_extras`. `email_colaborador` **explicitamente preservado** por decisão do usuário. |
| T-EQK-05 | Elevation / regressão silenciosa | remoção de `hubspot_observacao` de `$candidatos` | mitigate | Teste em 4.3 prova que nota nova em empresa existente atualiza `hubspot_notas`/`hubspot_observacao`, e que `origem_lead` manual não é sobrescrito. |
| T-EQK-SC | Tampering | instalação de pacotes | n/a | Nenhum pacote novo (npm/composer) é instalado nesta task. |
</threat_model>

<verification>
1. `php artisan migrate --env=testing` aplica as duas migrations sem erro; `Schema::hasColumn('companies','hubspot_notas')` e `('companies','origem_lead')` → `true`; `('companies','nicho')` → `false`; `('companies','email_colaborador')` → `true`.
2. `php artisan test` — suíte completa, resultado real reportado.
3. `npm run build` conclui sem erro.
4. `grep -rn --include=*.php -w -e nicho -e vende_ml -e faturamento_mensal -e marketplaces_extras -e dados_close_incompletos app/ config/` → zero ocorrências.
5. `grep -rn -e nicho -e vende_ml -e faturamento_mensal -e marketplaces_extras -e "Close incompleto" resources/js/` → zero ocorrências.
6. `git log --oneline -4` mostra 4 commits atômicos, um por task.
</verification>

<success_criteria>
- [ ] `fetchNotes` implementado, resiliente, sanitizando HTML e ordenando ascendente por timestamp
- [ ] `config('services.hubspot.props.note')` e `props.contact.origem_do_lead` existem e estão documentados no `.env.example`
- [ ] `companies.hubspot_notas` (json) e `companies.origem_lead` (string) criadas via migration idempotente
- [ ] Webhook **e** replay capturam notes + origem do lead em blocos `try/catch` que nunca quebram o processamento
- [ ] `hubspot_notas`/`hubspot_observacao` são SEMPRE reescritos (espelho); `origem_lead` respeita valor manual
- [ ] `hubspot_snapshot` contém a chave `notes`
- [ ] As 5 colunas mortas removidas do banco, model, config, `.env.example`, webhook, `ComercialController`, `CompanyController` e UI
- [ ] Pendência `dados_close_incompletos` removida por completo (cálculo, filtro, counts, detalhes, badge)
- [ ] `email_colaborador` preservado (coluna, dados, validate, pendência `sem_email_colaborador`, form admin de `/companies`) e removido apenas da tela da empresa e do form do Comercial
- [ ] `BackfillCompanyMarketplaces` e seu teste deletados
- [ ] `Show.jsx` exibe "Origem do lead" e o bloco "Observações (HubSpot)" com data por nota
- [ ] `npm run build` OK, resultado real reportado
- [ ] Suíte de testes rodada e resultado real (passed/failed) reportado
- [ ] Nenhum teste de webhook faz chamada real a `api.hubapi.com` (`preventStrayRequests` do Phase115 continua verde)
- [ ] 4 commits atômicos, todos com `git commit -- <paths explícitos>`
</success_criteria>

<regras_de_execucao>
- **Árvore compartilhada.** Outra sessão/outro dev editam a MESMA working tree. Nos commits usar **SEMPRE** `git commit -- <paths explícitos>`. **NUNCA** `git add -A`, **nunca** `git add .`, **nunca** `git stash`.
- **Sem deploy.** Não rodar `deploy.sh`, não executar comandos no VPS, não chamar a API do HubSpot.
- **Sem reinvestigação.** O bloco `<contexto_ja_validado>` é fato apurado contra a API real e o banco de produção.
- **pt-BR** em comentários, docblocks, nomes de teste e mensagens de commit.
- **SQLite nos testes** (`phpunit.xml`); o MariaDB local pode estar indisponível. Armadilha conhecida: SQLite não pega problemas de MariaDB (enum CHECK, FK nullable, nome de índice > 64 chars). Esta task só adiciona colunas simples e dropa colunas — risco baixo, mas as duas migrations devem ser idempotentes via `Schema::hasColumn`.
- **Honestidade de verificação.** Reportar saída real de `npm run build` e de `php artisan test`. Não presumir sucesso.
</regras_de_execucao>

<output>
Criar `.planning/quick/260805-eqk-handoff-hubspot-notes-origem-lead/260805-eqk-SUMMARY.md` ao concluir.
</output>
