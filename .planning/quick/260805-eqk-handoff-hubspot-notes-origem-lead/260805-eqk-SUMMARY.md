---
phase: quick-260805-eqk
plan: 01
subsystem: handoff-comercial-hubspot
tags: [hubspot, webhook, comercial, notes, origem-lead, limpeza-de-schema]
requires:
  - app/Services/HubspotApiClient.php
  - app/Http/Controllers/Api/HubspotWebhookController.php
provides:
  - "HubspotApiClient::fetchNotes(objectType, objectId)"
  - "companies.hubspot_notas (json) e companies.origem_lead (string)"
  - "config services.hubspot.props.note e props.contact.origem_do_lead"
affects:
  - app/Http/Controllers/ComercialController.php
  - app/Http/Controllers/CompanyController.php
  - resources/js/Pages/Companies/Show.jsx
  - resources/js/Pages/Comercial/EmpresasListagem.jsx
tech-stack:
  added: []
  patterns:
    - "Espelho vs. dado manual: colunas que retratam um sistema externo ficam FORA da regra de enriquecimento 'só preenche se vazio'"
key-files:
  created:
    - database/migrations/2026_08_05_100000_add_hubspot_notas_origem_lead_to_companies_table.php
    - database/migrations/2026_08_05_100001_drop_campos_comerciais_mortos_from_companies_table.php
    - tests/Feature/QuickEqkHubspotNotesOrigemLeadTest.php
  modified:
    - app/Services/HubspotApiClient.php
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - app/Http/Controllers/ComercialController.php
    - app/Http/Controllers/CompanyController.php
    - app/Models/Company.php
    - config/services.php
    - .env.example
    - resources/js/Pages/Companies/Show.jsx
    - resources/js/Pages/Companies/Index.jsx
    - resources/js/Pages/Comercial/EmpresasListagem.jsx
    - resources/js/Pages/Comercial/NovaEmpresa.jsx
    - resources/js/Pages/Comercial/AtribuirServico.jsx
  deleted:
    - app/Console/Commands/BackfillCompanyMarketplaces.php
    - tests/Feature/Phase57/BackfillMarketplacesTest.php
decisions:
  - "hubspot_notas e hubspot_observacao são espelho do HubSpot: sempre reescritos, inclusive no caminho de match forte"
  - "origem_lead segue a regra normal de enriquecimento (dado manual do Comercial é soberano)"
  - "email_colaborador preservado (coluna, dados, pendência sem_email_colaborador e form admin de /companies)"
metrics:
  duration: "~2h"
  completed: 2026-08-05
---

# Quick task 260805-eqk: Handoff HubSpot — Notes + Origem do lead

O webhook do HubSpot passou a capturar as **Notes do deal** (as observações que o
Comercial realmente escreve) e a **Origem do lead** (property do contato), e o
sistema perdeu os 5 campos comerciais que nunca foram preenchidos porque as
properties correspondentes não existem na conta da ECF.

## O que mudou

### Task 1 — Captura de Notes e Origem do lead (`6cf49602`)

- **`HubspotApiClient::fetchNotes(objectType, objectId)`** — encadeia
  `GET /objects/{tipo}/{id}/associations/notes` e
  `GET /objects/notes/{noteId}?properties=...`, no mesmo padrão resiliente de
  `fetchDealLineItems`: falha na associação devolve `[]` com warning; falha no
  detalhe individual pula **só** aquela nota. Extrai o id com
  `$r['id'] ?? $r['toObjectId']` (armadilha v3 vs. v4 conhecida do projeto).
- **Sanitização do `hs_note_body`** — decodifica entidades, converte `<br>`,
  `</p>` e `</div>` em `\n` **antes** do `strip_tags` (senão `<p>a</p><p>b</p>`
  vira `"ab"`), colapsa excesso de linhas em branco e descarta nota sem texto
  útil. A decodificação vem antes do `strip_tags` de propósito: markup escapado
  (`&lt;script&gt;`) também é removido em vez de virar tag depois (T-EQK-02).
- **Ordenação ascendente** por timestamp (mais antiga primeiro), com o índice de
  chegada como desempate — notas sem timestamp vão para o fim preservando a
  ordem original. Fallback de `hs_timestamp` para `hs_createdate`.
- **Config** — `props.note.{body,timestamp}` e, em `props.contact`,
  `origem_do_lead`, `campanha_origem` e `criativo_origem`, com comentário
  registrando que a origem do lead vive no **CONTATO**. 5 envs novas no
  `.env.example`.
- **Migration** `add_hubspot_notas_origem_lead_to_companies_table` —
  `hubspot_notas` (json nullable) e `origem_lead` (string 255 nullable),
  idempotente via `Schema::hasColumn` nos dois sentidos.
- **Webhook e replay** buscam as notes em `try/catch` que nunca quebra o fluxo
  (no replay a falha também entra em `$warnings`), e os DOIS blocos de
  normalização de contato (`processar()` e `reprocessarEvento()`) ganharam a
  chave lógica `origem_do_lead`.
- **Regra crítica** — `hubspot_notas` e `hubspot_observacao` entram no mesmo
  `$company->update([...])` que já reescreve `hubspot_snapshot`, e
  `hubspot_observacao` **saiu** do array `$candidatos` de
  `enriquecerEmpresaExistente()`. Motivo concreto: a Metalform ganhou uma nota
  em 03/08 depois de a empresa já existir — sob a regra "só preenche se vazio"
  essa nota nunca apareceria. `origem_lead` faz o oposto: vai no
  `Company::create` **e** em `$candidatos`, porque o Comercial pode corrigir à
  mão. `hubspot_snapshot` ganhou a chave `notes`.

### Task 2 — Remoção dos 5 campos mortos (`d19ca1c3`)

`nicho`, `dor`, `vende_ml`, `faturamento_mensal` e `marketplaces_extras` saíram
do banco (migration de drop com `down()` que recria os tipos originais), do
`Company` (`$fillable`/`$casts`), da `config/services.php`, do `.env.example`,
do `HubspotWebhookController`, do `ComercialController` e do `CompanyController`.
A pendência `dados_close_incompletos` foi removida por completo — cálculo,
whitelist do filtro, `pendencia_counts` e bloco de detalhes. O comando órfão
`companies:backfill-marketplaces` e seu teste foram deletados.

`email_colaborador` e a pendência `sem_email_colaborador` foram preservados
integralmente.

### Task 3 — Frontend (`e10a6a6b`)

- `CompanyController::show` e `ComercialController::listagem` expõem
  `hubspot_notas` (default `[]`) e `origem_lead`.
- **`Companies/Show.jsx`** — a seção "Informações comerciais" perdeu os 5 campos
  mortos e o "E-mail do colaborador"; ganhou o `InfoRow` "Origem do lead" e o
  bloco "Observações (HubSpot)" com a data por nota (`dd/mm/aaaa`) e o corpo em
  `whitespace-pre-line`. O bloco não renderiza quando não há nota. Com só 3
  campos restantes, o grid de 2 colunas virou coluna única.
- **`EmpresasListagem.jsx`** — o modal "Detalhes HubSpot" lista as notas com
  data (fallback `'—'`) e mostra a origem do lead junto do bloco "Contato", que
  é onde o dado nasce. O form de edição perdeu os campos do close e o badge
  "Close incompleto" saiu do mapa de pendências.
- **`NovaEmpresa.jsx`** — o bloco "Informações do close" saiu inteiro do wizard,
  incluindo `email_colaborador` (não é dado captado pelo Comercial). O
  `gmail_colaborador` do wizard de Polos foi preservado.
- **`Companies/Index.jsx`** — o modal admin manteve só o `email_colaborador`.
- **`AtribuirServico.jsx`** — badge de `nicho` removido.

Nos dois `.map()` novos, as flags (`chave`, `data`) são computadas **dentro** do
callback, evitando a armadilha do Rollup já conhecida no projeto.

### Task 4 — Testes

Arquivo novo `tests/Feature/QuickEqkHubspotNotesOrigemLeadTest.php` com 13
testes cobrindo os 3 grupos do plano, usando o caso real como fixture (deal
`62661178491`, notes `113069990193` e `114141013579`, contato com
`origem_do_lead = "Parceiro de Polos"`). Os testes afetados pela remoção dos
campos foram atualizados; `Phase34CompaniesCloseFieldsTest` foi reduzido à
cobertura de `email_colaborador` mais uma guarda de schema, e
`Phase37ComercialListagemTest::test_pendencia_dados_close_incompletos` virou a
guarda de que a pendência **não volta**.

## Desvios do plano

### [Rule 1 - Bug] Colisão de variável `$notes` em `criarEmpresa()`

- **Encontrado durante:** Task 4, ao rodar o teste novo pela primeira vez.
- **Problema:** o bloco legado que anexa `"Contato (HubSpot): {nome}"` em
  `companies.notes` usava uma variável local chamada `$notes`, que **sobrescrevia
  o parâmetro `$notes`** (a lista de Notes do HubSpot) no mesmo escopo do
  closure. Resultado: `companies.hubspot_notas` recebia a string do campo `notes`
  em vez do array de notas, e `hubspot_snapshot.notes` idem. Silencioso — o
  webhook respondia 200 normalmente.
- **Correção:** variável local renomeada para `$notesLegado`.
- **Arquivo:** `app/Http/Controllers/Api/HubspotWebhookController.php`
- **Commit:** incluído no commit da Task 4.

### [Rule 3 - Bloqueio] Parâmetros mortos em `enriquecerEmpresaExistente()`

Depois de remover `nicho`/`dor`/`faturamento_mensal`/`vende_ml` do array
`$candidatos`, os parâmetros `$dprops` e `$propsDeal` ficaram sem uso na
assinatura. Foram removidos junto com os argumentos nomeados na chamada.

### [Ajuste de teste] `Phase113HubspotDedupTest`

O teste "match forte por cnpj enriquece sem duplicar" usava `dor` como o campo
manual soberano. Como a coluna deixou de existir, a asserção passou a usar
`telefone`, que segue exatamente a mesma regra de enriquecimento.

### [Ajuste de teste] `Phase113HubspotEnrichmentTest`

A asserção sobre `hubspot_observacao` vinda da property `observacao` do deal
virou o oposto: o mock continua mandando a property preenchida e o teste prova
que ela é **ignorada** (`hubspot_observacao` nula, `hubspot_notas` vazia quando
o deal não tem nota). É a guarda de que a property fantasma não volta a ser lida.

## Verificação

| Verificação | Resultado |
|---|---|
| `npm run build` | ✅ `✓ built in 57.07s`, sem erro (re-rodado na verificação final) |
| Migrations aplicadas (SQLite dos testes) | ✅ `hubspot_notas`/`origem_lead` presentes; `nicho`/`dor`/`vende_ml`/`faturamento_mensal`/`marketplaces_extras` ausentes; `email_colaborador` presente |
| `grep` de campos mortos em `app/` e `config/` | ✅ só comentários explicativos |
| `grep` de campos mortos em `resources/js/` | ✅ só comentários explicativos |
| Teste novo (`QuickEqkHubspotNotesOrigemLead`) | ✅ 13 passed (48 assertions) |
| `StrayRequestException` nos endpoints novos | ✅ nenhum — `Phase115HubspotInvariantesTest` continua verde |
| Suíte completa | ver seção abaixo |

### Suíte completa — contagem REAL

```
Tests: 2431, Assertions: 12951, Failures: 100, Errors: 17, Skipped: 1
```

**Reporte honesto: a suíte deste repositório já chegava quebrada nesta task.**

Para não atribuir nem esconder culpa, rodei a mesma suíte num worktree do commit
imediatamente anterior aos 4 commits (`7ecf449f`) e comparei os **nomes** dos testes
que falham nos dois lados:

| | Baseline (`7ecf449f`) | Depois (`f77f9d68`) |
|---|---|---|
| Tests | 2418 | 2431 |
| Failures | 107 | 100 |
| Errors | 24 | 17 |
| Testes falhando (únicos) | **131** | **117** |

- **Falhas NOVAS introduzidas por esta task: ZERO.** O conjunto que falha hoje é
  subconjunto estrito do que já falhava antes (`comm -13 baseline atual` = vazio).
- **14 testes deixaram de falhar.** 9 foram genuinamente consertados pela Task 4 —
  falhavam justamente porque afirmavam coisas como `$company->nicho === 'Moda feminina'`
  e recebiam `null`. Ou seja: o diagnóstico do plano ("o webhook pede e o HubSpot
  ignora") estava provado por teste vermelho desde antes, e ninguém tinha ligado os
  pontos. 4 sumiram junto com `Phase57/BackfillMarketplacesTest` (arquivo deletado,
  que também já falhava). 1
  (`Phase70\NpsTemplateCrudTest::test_toggle_active_bloqueia_desativacao_do_is_default`)
  não tem relação com esta task — provavelmente flaky.
- **Nenhum** teste de HubSpot (`Phase34*`, `Phase35*`, `Phase111`–`Phase116`) nem de
  listagem do Comercial (`Phase37ComercialListagem`, `Phase114`) está na lista de
  falhas. `Phase115HubspotInvariantesTest` (o do `preventStrayRequests`) passa — os 2
  endpoints novos estão mockados em todos os fakes.

As 117 falhas remanescentes se concentram em domínios sem relação com esta task:
desempenho/bonificação (`Phase119\CompanyScoreService*`, `Phase74`, `V16`, `V18`,
`Polos`, `DesempenhoShopeeScore`), sugadores ML (`Phase42`), fechamento
(`AdminFechamentoController`, `FechamentoMigration`, `Phase13/14Migration`) e a
família legada `Phase13/Phase14Comercial` — que ainda posta `service_type`, campo
substituído por `servicos[]` numa fase anterior (confirmado: `service_type` já não
existia no `ComercialController` em `7ecf449f`).

### Desvio [Regra 3 - bloqueio] — a suíte não roda num único processo PHP

`php artisan test` morre com `Maximum execution time of 300 seconds exceeded` em
`MercadoLivreAdsService.php:215`. A causa **não** é aquele arquivo: comandos
exercitados pela suíte (`SyncGrantsFromEcfDrive`, `SyncGrantsFromSftp`) chamam
`set_time_limit(300)`, o que reinicia o limite para o processo inteiro do phpunit;
como a suíte leva bem mais que isso (há `usleep` real de backoff nos testes de
Sugadores), o processo é morto antes do relatório final. Nem `-d max_execution_time=0`
resolve, porque `set_time_limit` em runtime sobrepõe a flag de CLI.

**Contorno:** suíte rodada em 15 blocos de 25 arquivos, cada bloco num processo novo
(limite renovado), com os totais somados. É por isso que a contagem acima é um
agregado e não uma única linha de saída do PHPUnit. **Condição pré-existente**, não
introduzida por esta task — registrada em `deferred-items.md`.

## Incidente durante a verificação (meu erro, já corrigido)

Para produzir a comparação com o baseline criei um worktree git fora do repositório e
liguei o `vendor/` dele por *junction* do Windows. Ao desfazer, usei `Remove-Item` do
PowerShell no caminho da junction — o PowerShell **atravessou** a junction e apagou
parte do `vendor/` REAL do projeto (as pastas em ordem alfabética até onde o comando
abortou, `vendor/autoload.php` inclusive).

**Correção aplicada:** `php composer.phar install --ignore-platform-req=php-64bit`,
que restaurou o `vendor/` exatamente ao estado do `composer.lock` (136 pacotes,
autoload regenerado, `package:discover` OK). Verificado em seguida: `vendor/autoload.php`
presente e `QuickEqkHubspotNotesOrigemLeadTest` voltou a rodar 13/13.

**Impacto no repositório: nenhum** — `vendor/` é ignorado pelo git, `composer.lock` não
foi tocado e `git status --porcelain` ficou idêntico ao do início da sessão. **Aviso
para a outra sessão / o outro dev na mesma máquina:** se algo em PHP rodou nessa
janela, pode ter dado "class not found"; basta repetir o comando agora.

**Nota para o futuro:** a flag necessária é `--ignore-platform-req=php-64bit` — o
`composer.lock` trava `maennchen/zipstream-php 3.2.2`, que exige PHP 8.3, enquanto o
PHP do XAMPP local é 8.2.12. Sem a flag, `composer install` se recusa a instalar.

## Notas para o deploy

- Duas migrations novas. A de **drop** é destrutiva em produção: remove
  `nicho` (3 valores, 1 "teste"), `dor` (1, "teste"), `vende_ml` (5),
  `faturamento_mensal` (2, um 0.00) e `marketplaces_extras` (3 não-vazios, 2
  "teste"). `down()` recria as colunas com os tipos originais, mas **não** os
  dados. `company_marketplaces` (161 linhas / 145 empresas) já substituiu
  `marketplaces_extras`.
- As 11 empresas de origem HubSpot só terão `hubspot_notas`/`origem_lead`
  preenchidos depois de um replay (`php artisan hubspot:reprocess-event {id}`)
  ou de um novo webhook. **Não** há backfill automático nesta task.
- Nenhum pacote npm/composer novo.
- **Nenhum deploy foi executado** — conforme instrução. Nenhum comando no VPS,
  nenhuma chamada real a `api.hubapi.com` (todos os testes usam `Http::fake`).

## Auto-checagem

| Item | Resultado |
|---|---|
| Arquivos declarados como criados existem em disco | ✅ 3/3 |
| Arquivos declarados como deletados sumiram | ✅ 2/2 |
| Commits `6cf49602`, `d19ca1c3`, `e10a6a6b`, `f77f9d68` em `git log` | ✅ 4/4 |
| `git status --porcelain` idêntico ao início da sessão | ✅ nenhum arquivo rastreado modificado, nada de outra sessão tocado |
| Worktree de baseline removido e `git worktree prune` executado | ✅ só o worktree principal permanece |

## Auto-checagem: PASSOU
