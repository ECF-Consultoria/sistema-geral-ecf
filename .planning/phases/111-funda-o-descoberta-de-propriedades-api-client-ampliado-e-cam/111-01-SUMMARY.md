---
phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam
plan: 01
subsystem: api
tags: [hubspot, config, artisan-command, http-fake, properties-api]

requires: []
provides:
  - "config('services.hubspot.props.deal/company/contact') com as chaves ampliadas do handoff Comercial v20.0"
  - "Comando `hubspot:inspect-properties` para descobrir nomes internos reais da conta ECF via Properties API"
affects: [111-02, 111-03, 112]

tech-stack:
  added: []
  patterns:
    - "Padrão de teste de comando Artisan: Artisan::call() + Artisan::output() (não usar $this->artisan()->run(), cujo mock de OutputStyle não expõe fetch())"

key-files:
  created:
    - app/Console/Commands/HubspotInspectProperties.php
    - tests/Feature/Phase111HubspotConfigPropsTest.php
    - tests/Feature/Phase111InspectPropertiesTest.php
  modified:
    - config/services.php

key-decisions:
  - "config/services.hubspot.props ganhou 10 chaves novas em deal, 6 em company e 3 em contact, todas via env() com default = nome interno padrão HubSpot — apenas ADIÇÃO, nenhuma chave antiga removida ou renomeada"
  - "Comando de diagnóstico isola cada objeto num try/catch próprio: falha de status HTTP (403/404/500) e ConnectionException (timeout/DNS/TLS) nunca abortam o comando — sempre retorna exit code 0"
  - "Mensagens de erro do comando expõem só objeto + status HTTP ou nome da classe da exceção (nunca a mensagem crua, que pode conter a URL da requisição) — nenhuma via de vazamento de token"

patterns-established:
  - "Comandos Artisan de diagnóstico HubSpot seguem: try/catch por item de loop + $this->warn() + continue, nunca $res->throw()"

requirements-completed: [HUB-API-01, HUB-API-02]

duration: ~35min
completed: 2026-07-24
---

# Phase 111 Plan 01: Fundação de config + comando de diagnóstico HubSpot Summary

**`config('services.hubspot.props')` ganhou 19 novas chaves (deal/company/contact) mapeáveis por env, e o comando `hubspot:inspect-properties` valida via Properties API os nomes internos reais da conta HubSpot — nunca vazando o access token e resiliente a falha de rede/403.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-07-24T11:55:00Z
- **Completed:** 2026-07-24T12:31:31Z
- **Tasks:** 2
- **Files modified:** 4 (1 modificado, 3 criados)

## Accomplishments
- `config/services.hubspot.props.deal` ganhou `observacao/description/closed_won_reason/closedate/pipeline/hs_mrr/hs_arr/hs_tcv/hs_acv/hs_currency`, preservando `nicho/dor/vende_ml/faturamento_mensal/servico`
- `config/services.hubspot.props.company` ganhou `domain/industry/annualrevenue/city/state/country`, preservando `name/cnpj/email/phone`
- `config/services.hubspot.props.contact` ganhou `mobilephone/jobtitle/additional_emails` (default `hs_additional_emails`), preservando `firstname/lastname/email/phone`
- Comando `hubspot:inspect-properties --objects=deals,companies,contacts,line_items` criado — GET `/crm/v3/properties/{objectType}` por objeto, imprime tabela nome interno/label/type/fieldType
- Comando resiliente: falha de status (403/404/500) e `ConnectionException` (timeout/DNS/TLS) são capturadas por objeto via try/catch, reportadas com `$this->warn()`, e o comando segue para o próximo objeto — sempre exit code 0
- Nenhum teste faz chamada real ao HubSpot (`Http::fake` em ambas as suites)

## Task Commits

Cada tarefa foi commitada atomicamente (Task 1 seguiu TDD estrito RED→GREEN):

1. **Task 1a: RED — suite de props HubSpot ampliadas** - `60e7c755` (test)
2. **Task 1b: GREEN — amplia services.hubspot.props** - `f350ca6e` (feat)
3. **Task 2: Comando hubspot:inspect-properties** - `98b3ef36` (feat, inclui a suite Http::fake)

**Plan metadata:** commit final deste SUMMARY + STATE + ROADMAP (a seguir)

## Files Created/Modified
- `config/services.php` - Bloco `services.hubspot.props` (deal/company/contact) ampliado com 19 novas chaves via `env()`, preservando as antigas
- `app/Console/Commands/HubspotInspectProperties.php` - Comando Artisan de diagnóstico da Properties API HubSpot, resiliente e sem vazamento de token
- `tests/Feature/Phase111HubspotConfigPropsTest.php` - 4 testes provando as novas chaves de config + defaults seguros
- `tests/Feature/Phase111InspectPropertiesTest.php` - 5 testes (`Http::fake`) provando caminho feliz, resiliência a status/rede, não-vazamento de token e `--help`

## Decisions Made
- Task 1 seguiu TDD estrito porque o plano marcava `tdd="true"`: revertida a config, confirmado RED (4 falhas), reaplicada a config, confirmado GREEN (4 passes), 2 commits separados (`test` → `feat`)
- Task 2 não tinha `tdd="true"` no plano — comando + suite foram desenvolvidos e commitados juntos após ambos passarem
- Padrão de teste de comando Artisan corrigido para `Artisan::call()` + `Artisan::output()` (ver Deviations) — alinhado ao padrão já usado em `tests/Feature/Phase18/DiagnoseCustIdTest.php`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Chave `props.deal` duplicada durante a edição inicial do config**
- **Found during:** Task 1 (extensão de `config/services.php`)
- **Issue:** Uma primeira tentativa de `Edit` acrescentou um segundo array `'deal' => [...]` dentro de `services.hubspot.props`, o que faria o PHP sobrescrever silenciosamente a chave `deal` original (a segunda definição de uma chave de array literal vence) — as 5 props antigas de deal teriam sido substituídas pelas 15 versões consolidadas corretamente, mas por acidente de estrutura, não por design; o risco real era duplicar/perder chaves em edições futuras dentro do mesmo bloco.
- **Fix:** Reescrito o bloco `props` inteiro consolidando `deal`, `company` e `contact` em UM único array cada, com as chaves antigas e novas juntas na ordem correta, antes de rodar qualquer teste.
- **Files modified:** `config/services.php`
- **Verification:** `Phase111HubspotConfigPropsTest` (4 testes) + regressão `Phase34HubspotWebhookTest`/`Phase37WebhookLineItemsTest` verdes
- **Committed in:** `f350ca6e` (commit GREEN da Task 1 já contém a versão corrigida — o erro foi corrigido antes do primeiro commit chegar ao histórico)

**2. [Rule 1 - Bug] `Artisan::output()` retornava vazio ao usar `$this->artisan()->run()` nos testes do comando**
- **Found during:** Task 2 (suite `Phase111InspectPropertiesTest`)
- **Issue:** O helper `$this->artisan($cmd, $params)->assertExitCode(0)` do PHPUnit/Laravel monta um mock parcial de `Illuminate\Console\OutputStyle` (que não expõe `fetch()`) como buffer de saída; `Illuminate\Console\Application::output()` checa `method_exists($this->lastOutput, 'fetch')` antes de retornar o conteúdo — como o mock não tem esse método, `Artisan::output()` sempre voltava `''`, fazendo os testes 2-5 (que dependiam de inspecionar a string de saída) falharem mesmo com o comando funcionando corretamente (confirmado via script de depuração `Artisan::call()` direto, que produziu a tabela e as mensagens de warn esperadas).
- **Fix:** Reescrita toda a suite para usar `Illuminate\Support\Facades\Artisan::call()` + `Artisan::output()` diretamente — padrão já em uso em `tests/Feature/Phase18/DiagnoseCustIdTest.php` neste mesmo projeto — em vez do helper `$this->artisan()`.
- **Files modified:** `tests/Feature/Phase111InspectPropertiesTest.php`
- **Verification:** 5/5 testes passam de forma estável e repetida
- **Committed in:** `98b3ef36`

**3. [Rule 1 - Bug] Comentário no comando continha literalmente a string `throw()`, falhando o grep de aceite**
- **Found during:** Task 2 (verificação dos critérios de aceitação via grep)
- **Issue:** O docblock do comando mencionava `$res->throw()` como exemplo do que NÃO fazer; o critério de aceite do plano (`grep -c "throw()" ... == 0`) não distingue comentário de código e falhava por esse texto, mesmo o código não chamando `->throw()`.
- **Fix:** Reescrita a frase do comentário para descrever a regra sem usar a string literal `throw()`.
- **Files modified:** `app/Console/Commands/HubspotInspectProperties.php`
- **Verification:** `grep -c "throw()" app/Console/Commands/HubspotInspectProperties.php` → `0`; `grep -c "catch"` → `2`
- **Committed in:** `98b3ef36`

---

**Total deviations:** 3 auto-fixed (todos Rule 1 - correção de bug/erro de implementação, nenhuma mudança de escopo)
**Impact on plan:** Nenhum impacto negativo — todos os ajustes foram necessários para a config/comando funcionarem corretamente e para os critérios de aceitação do próprio plano serem satisfeitos. Sem scope creep.

## Issues Encountered
- Nenhum problema não resolvido. O único ponto de atenção documentado (mock de `OutputStyle` sem `fetch()`) já está registrado acima como deviation e resolvido.

## User Setup Required
None - nenhuma configuração externa necessária. As novas chaves `HUBSPOT_PROP_*` em `.env` são opcionais (todas têm default seguro = nome interno padrão HubSpot); só precisam ser setadas se a conta ECF usar nomes internos diferentes dos defaults — o que o comando `hubspot:inspect-properties` ajuda a descobrir.

## Next Phase Readiness
- `config('services.hubspot.props')` está pronto para ser consumido pelo `HubspotApiClient` ampliado (111-02) e pelas migrations estruturadas (111-03), que rodam na mesma Wave 1 em paralelo a este plano.
- O comando `hubspot:inspect-properties` pode ser rodado manualmente contra a conta real (fora dos testes) para validar antes da 112 (HubspotValueResolver) depender de `hs_mrr`/`hs_arr`/`hs_tcv`/`hs_acv`.
- Nenhum bloqueio para 111-02/111-03: nenhuma chave ou comportamento legado foi alterado, só adicionado.

---
*Phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam*
*Completed: 2026-07-24*
