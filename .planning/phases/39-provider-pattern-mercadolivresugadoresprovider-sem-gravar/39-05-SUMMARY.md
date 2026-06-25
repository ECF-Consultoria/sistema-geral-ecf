---
phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
plan: 05
subsystem: sugadores
tags: [phase-39, sugadores, command, dry-run, provider, tdd, ml-migration, guard, cli]

# Dependency graph
requires:
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    plan: 01
    provides: "Contract SugadoresAdsProvider + AdmanSugadoresProvider + factory minimal"
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    plan: 02
    provides: "MercadoLivreSugadoresProvider + factory branch ml — factory completo com 2 providers"
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    plan: 04
    provides: "SugadorAnalysisService::analyzeCompany aceita ?string \\$forceProvider (4º param)"
provides:
  - "Comando sugadores:analyze estendido com flag --provider={adman|ml}"
  - "Guard de segurança: --provider=ml sem --dry-run aborta com exit 1 (proteção pré-Phase 42)"
  - "Validação whitelist de provider (rejeita valores fora de adman|ml com mensagem clara)"
  - "Propagação de \\$forceProvider para SugadorAnalysisService::analyzeCompany via DI"
  - "Suite Feature Phase39\\AnalyzeSugadoresCommandTest — 8 tests cobrindo a matriz de behavior + signature"
affects:
  - "Operador agora consegue rodar smoke do path ML sem risco: php artisan sugadores:analyze --company=<id> --dry-run --provider=ml"
  - "Phase 40 (shadow mode): infraestrutura de CLI flag pronta — basta substituir o guard por upsert em tabela de shadow"
  - "Phase 42 (cut-over ml_primary): remover o guard simboliza a ativação; restante do código já está preparado"
  - "Phase 39 INTEIRA FECHADA — 5/5 plans entregues (Waves 1 + 2 + 3 + 4 todas verdes)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guard CLI baseado em combinação de flags — proteção contra estado destrutivo prematuro (gravação ML pré-Phase 42)"
    - "Whitelist explícita de valores aceitos para flag de string aberta — defesa em profundidade contra T-39-05-02 (injection)"
    - "BufferedOutput via Kernel para captura de output bruto em testes Feature — alternativa robusta ao expectsOutputToContain quando substring tem ambiguidades"
    - "Mockery do SugadorAnalysisService no container (\\$this->app->instance()) para asserções sobre o 4º argumento (\\$forceProvider) sem rodar pipeline real"

key-files:
  created:
    - "tests/Feature/Phase39/AnalyzeSugadoresCommandTest.php (313 linhas; 8 tests + 2 helpers; 12 assertions)"
  modified:
    - "app/Console/Commands/AnalyzeSugadores.php (+44 / -7 linhas; signature ganha --provider; handle() ganha validação + guard ml_primary + propagação \\$forceProvider para analyzeCompany; warning informativo quando --provider sem --company)"

key-decisions:
  - "Whitelist via in_array(\\$provider, ['adman', 'ml'], true) — apenas 2 valores aceitos. Default null (sem flag) preserva path Adman via capability detection (regra Plan 39-02 até Phase 42)"
  - "Guard ml_primary com mensagem explícita 'Modo ml_primary só disponível em Phase 42' — operador entende exatamente porque foi bloqueado e como destravar (--dry-run)"
  - "--provider afeta APENAS o path --company; path global (analyzeAll sem --company) loga warning informativo mas não force-overrida cada empresa (analyzeAll não recebe \\$forceProvider em Plan 39-04 — decisão de simetria limitada)"
  - "Output em pt-BR alinhado ao CLAUDE.md (Modo ml_primary…, Provider inválido…, Provider forçado: …) — operador brasileiro lê na própria língua"
  - "T7 (provider inválido) usa BufferedOutput via Kernel ao invés de expectsOutputToContain duplicado — robusto contra ambiguidade do PendingCommand quando duas substrings ('Provider inválido', 'adman, ml') aparecem na mesma linha do error()"
  - "Test 5 (dry-run não grava) e Test 6 (sem dry-run grava) usam Mockery do AdmanService no container ao invés de Mockery do SugadorAnalysisService — exercita o pipeline REAL ponta-a-ponta (factory → AdmanSugadoresProvider → AdmanService mockado → analyzeCompany → upsert ou bypass). Validação end-to-end mais forte que assertion sobre 4º argumento"
  - "Tests 2/3/4 (propagação do forceProvider) usam Mockery do SugadorAnalysisService — não precisam exercitar pipeline; só precisam afirmar que o 4º argumento chega correto a analyzeCompany. Custo de execução menor (microseg)"
  - "Test 8 (signature) usa ReflectionClass para ler propriedade \$signature crua — não depende de exec do command (independente de DB ou DI). Pattern já consagrado em Plan 39-04 T1/T2"
  - "ZERO modificação em SugadorAnalysisService.php — toda lógica de detecção segue intocada. Comando é puramente uma camada de orquestração CLI"
  - "Comportamento default (sem --provider) PRESERVA path Adman + gravação bit-a-bit — Test 6 garante via assertion Sugador::count() == count_antes + 1 após exec sem flags"

patterns-established:
  - "Pattern: Guard via flag-combination (--provider=ml + sem --dry-run = abort) — proteção pré-cut-over que vai ser removida em Phase 42. Replicável para outros features que precisam de fase de leitura segura antes da fase de gravação (ex: futuras migrações de provider/integração)"
  - "Pattern: BufferedOutput via Kernel em tests Feature — fallback robusto quando PendingCommand do Laravel Testing apresenta quirks com expectsOutputToContain. Particularmente útil para mensagens de error() que podem ter substring duplicação ou quebra de linha"
  - "Pattern: Mix de mock (service) + real pipeline (provider+AdmanService mock) na mesma suite — testes 2/3/4 mockam alto nível (service) para assertion de 4º arg; testes 5/6 deixam pipeline real para validar comportamento end-to-end. Custo mínimo, cobertura máxima"

requirements-completed: [REQ-39-07, REQ-39-08]

# Metrics
duration: 20min
completed: 2026-06-25
---

# Phase 39 Plan 39-05: Comando sugadores:analyze --provider + guard ml_primary Summary

**Comando Artisan estendido com infraestrutura completa de safety: `--provider={adman|ml}` permite operador forçar provider no path `--company`; guard rejeita `--provider=ml` sem `--dry-run` com exit 1 antes de chegar ao service (proteção contra gravação ML acidental pré-Phase 42 cut-over); validação whitelist rejeita valores fora da lista com mensagem clara. Comportamento default (sem flags) preserva path Adman + gravação bit-a-bit. ZERO modificação no `SugadorAnalysisService` (Plan 39-04 fechou). 8/8 tests Feature verdes cobrindo matriz completa: (1) guard ml_primary aborta exit 1, (2-4) propagação do `$forceProvider` para `analyzeCompany`, (5) --dry-run não grava, (6) sem flag grava, (7) provider inválido rejeita, (8) signature inclui --provider. Suite Phase 39 acumulada: 48/48 verdes (208 assertions). Suite Sugador acumulada: 65/65 verdes (445 assertions) — zero regressão sobre o gate do Plan 39-04. Phase 39 INTEIRA FECHADA — 5/5 plans entregues.**

## Performance

- **Duração:** ~20 min
- **Iniciado:** 2026-06-25T22:00Z
- **Concluído:** 2026-06-25T22:20Z
- **Tasks:** 2 (RED + GREEN)
- **Files criados:** 1 (`tests/Feature/Phase39/AnalyzeSugadoresCommandTest.php`)
- **Files modificados:** 1 (`app/Console/Commands/AnalyzeSugadores.php`)
- **Files do "não-tocar":** 0 modificações em `SugadorAnalysisService`, `AdmanService`, providers/factory Plans 39-01/02, `AdgroupMlbMapRepository` (Plan 39-03), models, Jobs, Controllers (validado via `git status` mostrando apenas 2 arquivos modificados).

## Accomplishments

- **Signature do comando estendida (4ª flag)**:
  ```
  protected $signature = 'sugadores:analyze
      {--company=  : Analisa apenas uma empresa específica (ID)}
      {--date=     : Força reference_date (YYYY-MM-DD, padrão: hoje)}
      {--dry-run   : Mostra quem seria flagado sem gravar no banco}
      {--provider= : Força provider de dados (adman|ml). Default = capability detection. ml sem --dry-run aborta (Phase 39 — gravação ML disponível em Phase 42).}';
  ```
- **Description do comando atualizado**: "...via provider de anúncios (Adman ou Mercado Livre)" (era "via Adman API").
- **Validação whitelist (T-39-05-02)**: `in_array($provider, ['adman', 'ml'], true)` — aceita apenas os 2 valores. Qualquer outro string vira:
  ```
  Provider inválido: 'XXX'. Valores aceitos: adman, ml
  ```
  Exit code 1. **Test 7 valida com assertion sobre BufferedOutput**.
- **Guard ml_primary (T-39-05-01)**: `--provider=ml` SEM `--dry-run` aborta:
  ```
  Modo ml_primary só disponível em Phase 42 — use --dry-run para testar leitura sem gravação.
  ```
  Exit code 1. **Test 1 valida via `expectsOutputToContain` + `assertExitCode(1)`**.
- **Propagação do `$forceProvider`**: chamada `$service->analyzeCompany($company, $referenceDate, $dryRun)` passa a `$service->analyzeCompany($company, $referenceDate, $dryRun, $provider)` (4 params). Tests 2/3/4 cobrem cada cenário: 'ml', 'adman' e null (default).
- **Output informativo**: quando `--provider` é passado, imprime `"Provider forçado: {valor}"` após o reference_date. Quando passado sem `--company`, imprime warning explicando que analyzeAll usa capability detection.
- **Lógica de retorno preservada**: skip → return SUCCESS (warning), adgroups detectados → SUCCESS, exceção → FAILURE. Comportamento dos Plans 30/15/19 intacto.
- **8/8 tests Feature verdes** (`AnalyzeSugadoresCommandTest`, 12 assertions, 1.19s):
  - T1: `--provider=ml` sem `--dry-run` aborta exit 1 com mensagem ml_primary
  - T2: `--provider=ml + --dry-run` propaga 'ml' para `analyzeCompany` (Mockery `->once()->with(..., true, 'ml')`)
  - T3: `--provider=adman + --dry-run` propaga 'adman'
  - T4: sem `--provider` propaga `null` (default)
  - T5: `--dry-run` exec real (AdmanService mockado com 1 sugador) NÃO grava em `sugadores` (count antes == count depois)
  - T6: sem `--dry-run` exec real grava 1 row em `sugadores` (count antes + 1 == count depois)
  - T7: `--provider=invalid` rejeita com mensagem clara (Provider inválido: 'invalid'. Valores aceitos: adman, ml) + exit 1
  - T8: signature do command declara opção `--provider` (via ReflectionClass)
- **Suite Phase 39 acumulada verde**: 48/48 verdes (208 assertions, 2.51s) — 8 AdmanProvider (39-01) + 10 MlProvider (39-02) + 6 Factory (39-01/02) + 8 Repository (39-03) + 8 Refactor service (39-04) + 8 Command (39-05).
- **Suite Sugador acumulada verde**: 65/65 verdes (445 assertions, 42.20s) — 49 legadas + 8 do Plan 39-04 (refactor) + 8 do Plan 39-05 (command). **Zero regressão** sobre o gate crítico definido no Plan 39-04.
- **Help do comando renderiza as 4 flags corretamente**:
  ```
  --company[=COMPANY]    Analisa apenas uma empresa específica (ID)
  --date[=DATE]          Força reference_date (YYYY-MM-DD, padrão: hoje)
  --dry-run              Mostra quem seria flagado sem gravar no banco
  --provider[=PROVIDER]  Força provider de dados (adman|ml). Default = capability detection. ml sem --dry-run aborta (Phase 39 — gravação ML disponível em Phase 42).
  ```

## Task Commits

Cada task commitada atomicamente seguindo TDD:

1. **Task 1 (RED): suite Feature do command estendido** — `4318d9a` (test)
   - Cria `tests/Feature/Phase39/AnalyzeSugadoresCommandTest.php` (290 linhas; 8 tests + 2 helpers `makeCompanyWithConfig` e `bindAdmanMockWithOneSugadorAd`)
   - 8/8 FAIL esperado: comando ainda não declara `--provider` (Symfony `InvalidOptionException`)
   - RED gate validado: tests realmente exercitam invariantes a serem implementadas
   - Documenta no header que esta suite NÃO duplica cobertura legada — só valida as extensões do Plan 39-05

2. **Task 2 (GREEN): comando estendido + ajuste defensivo do Test 7** — `e605397` (feat)
   - Edita `app/Console/Commands/AnalyzeSugadores.php` (+44 / -7 linhas):
     - Signature ganha `--provider`
     - Description atualizado
     - Validação whitelist + guard ml_primary no topo de handle()
     - Output informativo (Provider forçado, warning sem --company)
     - 4º param propagado a `analyzeCompany`
   - Ajusta Test 7 para usar BufferedOutput via Kernel (`expectsOutputToContain` apresentou quirk com substring 'adman, ml' duplicada quando 'Provider inválido' também era esperado — switch para `assertStringContainsString` em output bruto é robusto)
   - 8/8 tests GREEN (12 assertions, 1.19s)
   - 48/48 Phase 39 acumulado verde, 65/65 Sugador acumulado verde (zero regressão)

**Plan metadata commit:** será adicionado no commit final junto com STATE.md/ROADMAP.md.

_TDD: RED (Task 1) → GREEN (Task 2) — fluxo TDD canônico para feature CLI sem refactor estrutural._

## Files Created/Modified

### Criados

- **`tests/Feature/Phase39/AnalyzeSugadoresCommandTest.php`** (313 linhas) — Suite Feature com `RefreshDatabase`. 8 tests cobrindo: signature, validação whitelist, guard ml_primary, propagação do `$forceProvider` (3 casos: 'ml', 'adman', null), `--dry-run` não grava (exec real com AdmanService mockado), sem `--dry-run` grava (exec real). 2 helpers: `makeCompanyWithConfig` (persiste Company + SugadorConfig com defaults — `incluir_anuncios=true`, `incluir_campanhas=false` para reduzir noise) e `bindAdmanMockWithOneSugadorAd` (mocka AdmanService retornando 1 ad com `investment=100, sold_quantity=0` que bate critério `gasto_sem_venda` default 20.00).

### Modificados

- **`app/Console/Commands/AnalyzeSugadores.php`** (+44 / -7 linhas) — Mudanças cirúrgicas:
  1. **Signature** (linhas 12-16): ganha `{--provider= : ...}` como 4ª flag com descrição completa explicando matriz de behavior e referência à Phase 42.
  2. **Description** (linha 18): "...via Adman API" → "...via provider de anúncios (Adman ou Mercado Livre)".
  3. **handle() topo** (linhas 22-40): extrai `$provider = $this->option('provider')`. Validação whitelist (`in_array` com flag `true` de strict). Guard ml_primary (`if ($provider === 'ml' && !$dryRun)`). Ambas validações retornam `self::FAILURE` com mensagem clara via `$this->error()`.
  4. **handle() output informativo** (linhas 48-58): após `$this->info("Reference date: ...")`, imprime `Provider forçado: {valor}` quando flag passada. Warning quando `--provider` sem `--company` (path global ignora forceProvider).
  5. **Chamada analyzeCompany** (linha 67): `$service->analyzeCompany($company, $referenceDate, $dryRun, $provider)` — 4º arg propagado para factory→service.
  6. **Resto do código (path global, printDetalhes, table summary)** PRESERVADO bit-a-bit.

**Diagnóstico**: validações via grep confirmam:
- `grep -c "\-\-provider" app/Console/Commands/AnalyzeSugadores.php` = 5 ocorrências (signature + 4 usos em handle/comentários)
- `grep -c "ml_primary só disponível em Phase 42" app/Console/Commands/AnalyzeSugadores.php` = 1 (guard)
- `grep -c "Provider inválido" app/Console/Commands/AnalyzeSugadores.php` = 1 (whitelist)
- `grep -nE "in_array\(.provider, \['adman', 'ml'\]"` = 1 ocorrência (linha 30, com `\in_array` namespace global — preferência do codebase Laravel)
- `grep -E "analyzeCompany\(.company, .referenceDate, .dryRun, .provider\)"` = 1 ocorrência (propagação correta dos 4 params)

## Decisões Tomadas

- **Whitelist explícita com `in_array(..., true)`** — defesa em profundidade contra T-39-05-02 (provider value injection). Usar `match` ou `switch` seria equivalente mas menos legível. `true` no 3º arg força comparação estrita (não converte 0/false).
- **`\in_array` (namespace global)** — micro-otimização recomendada pelo IDE/PSR no codebase Laravel. Sem ganho mensurável mas alinhado a convenções existentes.
- **Mensagem do guard MENCIONA Phase 42 explicitamente** — operador que tentar `--provider=ml` vai entender que o feature existe mas está bloqueado por timing. Sugestão de uso (`--dry-run`) está na mesma mensagem. Reduz pings para o time de dev.
- **Warning quando `--provider` é passado sem `--company`** — analyzeAll do Plan 39-04 NÃO recebeu `$forceProvider` por decisão de escopo (símetria parcial). Ao invés de aceitar a flag silenciosamente e ignorar, o comando avisa o operador que aquele path usa capability detection. Reduz confusão.
- **Test 7 usa BufferedOutput via Kernel ao invés de `expectsOutputToContain` duplicado** — durante implementação descobri que o `PendingCommand` do Laravel Testing parece avaliar `expectsOutputToContain` de forma sequencial e a primeira chamada (`"Provider inválido: 'invalid'"`) consumiu o output, fazendo a segunda (`'adman, ml'`) falhar mesmo com a mensagem inteira sendo única linha. Switch para `assertStringContainsString` em output bruto via Kernel é o pattern mais robusto e está agora documentado para os próximos testes (Phase 40+).
- **Tests 2/3/4 mockam SugadorAnalysisService** ao invés do AdmanService — só precisam afirmar que o 4º arg chega correto. Não precisam exercitar pipeline real. Tempo de exec por test: ~30ms.
- **Tests 5/6 mockam AdmanService e deixam o pipeline real** — validam comportamento end-to-end (gravação ou não em `sugadores`). Tempo de exec por test: ~40ms.
- **Test 8 (signature)** usa ReflectionClass para ler propriedade `$signature` crua — independente de execução do command. Pattern já consagrado em Plan 39-04 T1/T2.
- **Helper `makeCompanyWithConfig`** usa `random_int` no CNPJ para evitar collision em testes paralelos (similar ao helper do Plan 39-04). `incluir_campanhas=false` por default para reduzir noise (cada teste só precisa exercitar o path adgroup).
- **Helper `bindAdmanMockWithOneSugadorAd`** retorna 1 ad com `investment=100, sold_quantity=0` — bate critério default `gasto_minimo_sem_venda=20.00`. `revenue=0` evita acos/roas non-null que poderiam disparar outros critérios e cair em cenário ambíguo.
- **Output do `error()` em pt-BR alinhado a CLAUDE.md** — operador brasileiro lê na própria língua. Termos técnicos preservados (Provider, Phase, Adman, ML).

## Deviations from Plan

Pequenos refinamentos alinhados ao escopo. Nenhuma Rule 4 (architectural).

- **[Rule 1 - Test brittleness] Test 7 ajustado de `expectsOutputToContain` duplicado para `assertStringContainsString` em BufferedOutput via Kernel.**
  - **Found during:** Task 2 GREEN execution
  - **Issue:** `->expectsOutputToContain("Provider inválido: 'invalid'")->expectsOutputToContain('adman, ml')` falhou com "Output does not contain 'adman, ml'" mesmo a mensagem sendo `"Provider inválido: 'invalid'. Valores aceitos: adman, ml"` numa única linha (output bruto via BufferedOutput tem 59 bytes). PendingCommand parece consumir/avançar o cursor entre chamadas consecutivas a `expectsOutputToContain`.
  - **Fix:** Test 7 agora chama `$kernel->call('sugadores:analyze', [...], $out)` capturando output em `BufferedOutput`, depois usa 2× `assertStringContainsString` no string puro. Robustez total.
  - **Files modified:** `tests/Feature/Phase39/AnalyzeSugadoresCommandTest.php` (Test 7 apenas; 7 tests restantes inalterados)
  - **Commit:** Incluído no GREEN commit `e605397`
  - **Impact:** zero impacto funcional na implementação do comando — somente ajuste de assertion pattern no test.

Total deviations: 1 auto-fixed (Rule 1) | 0 architectural (Rule 4) | Impact: nenhum funcional.

## Issues Encontrados

Nenhum bloqueador. Pontos relevantes do diagnóstico:

- **Tests RED iniciais falharam com `PDOException "There is already an active transaction"`** nos tests 4, 7 e 8 — sintoma colateral análogo ao do Plan 39-04: ocorre quando a flag `--provider` ainda não existe na signature, `Symfony InvalidOptionException` é throw'd ANTES de `RefreshDatabase` fechar a transaction inicial do test, ricocheteia, e o seguinte test tenta abrir transaction nova. **Não-issue após o GREEN**: signature válida, exceção não acontece, transactions funcionam normalmente.
- **Quirk do `expectsOutputToContain`** quando substring curta aparece após substring mais longa no mesmo error line — documentado em "Decisões Tomadas". Workaround robusto via BufferedOutput aplicado.
- **Hints IDE pré-existentes** (`Property $id accessed via magic method`, `setAccessible deprecated`) — fora de escopo (SCOPE BOUNDARY), todos severity=Hint, codebase Laravel inteiro tem o mesmo padrão.
- **Aviso PHPUnit metadata deprecation** em suites Phase18/33/35/36/37 — pré-existente, fora de escopo (SCOPE BOUNDARY).
- **MariaDB local continua caído** (quick task `260625-mrd`) — mitigado integralmente: tests usam SQLite em-memory; smoke real do path ML (`--provider=ml --dry-run` contra bymobille) fica como DEFERRED item, testado em ambiente futuro quando MariaDB voltar.

## User Setup Required

Nenhum — plan 39-05 só estende o comando Artisan com novas flags. Sem mudança em contratos externos, env vars, schema, rotas, queues. Operador admin pode imediatamente rodar:

```bash
# Path adman explícito + dry-run (lista motivos sem gravar)
php artisan sugadores:analyze --company=<id> --dry-run --provider=adman

# Path ml dry-run (REQUER MariaDB + token ML ativo)
php artisan sugadores:analyze --company=<id_bymobille> --dry-run --provider=ml

# Proteção (vai abortar)
php artisan sugadores:analyze --company=<id_bymobille> --provider=ml
# → Exit 1 + "Modo ml_primary só disponível em Phase 42…"

# Default (production cron)
php artisan sugadores:analyze --company=<id>
```

## Next Phase Readiness

- **Phase 39 INTEIRA FECHADA** — 5/5 plans entregues:
  - Plan 39-01 ✓ Contract `SugadoresAdsProvider` + `AdmanSugadoresProvider` + factory minimal
  - Plan 39-02 ✓ `MercadoLivreSugadoresProvider` + factory branch ml (factory completo com 2 providers)
  - Plan 39-03 ✓ `AdgroupMlbMapRepository`
  - Plan 39-04 ✓ Refactor `SugadorAnalysisService` para usar factory via DI
  - Plan 39-05 ✓ (este) — Comando `sugadores:analyze --provider=` + guard ml_primary
- **Pronto para Phase 40 (shadow mode)** — infraestrutura completa: factory, providers, repository, service refatorado, comando CLI com flag. Phase 40 adiciona tabelas `sugador_provider_runs`/`_items` e altera o comando para escrever em shadow ao invés de bloquear quando `--provider=ml` é passado.
- **Pronto para Phase 41 (onboarding ML por empresa)** — UI de cadastro do `mlToken` por empresa, rate limiter `ml-api:{seller_id}`. Provider ML já implementado (Plan 39-02), basta cadastro de credenciais.
- **Pronto para Phase 42 (cut-over ml_primary)** — basta remover o guard do comando (linhas 32-35) e adicionar a leitura de envs `SUGADORES_PROVIDER_MODE`/`SUGADORES_ML_PRIMARY_COMPANIES` no `SugadoresAdsProviderFactory::for()`. `analyzeCompany` já está preparado.
- **Smoke real do path ML continua DEFERRED** — Phase 38 Tarefa 3 (bymobille) bloqueada por MariaDB local. Quando MariaDB voltar, rodar:
  ```
  php artisan sugadores:analyze --company=<id_bymobille> --dry-run --provider=ml
  ```
  E comparar output com `php artisan sugadores:analyze --company=<id_bymobille> --dry-run --provider=adman` (que JÁ funciona porque bymobille tem `ml_store_id` mas o factory cai em ML quando Adman não suporta — ver Plan 39-02 SUMMARY).

## TDD Gate Compliance

- ✅ **RED gate**: commit `4318d9a` (`test(39-05): adiciona suite Feature command sugadores:analyze --provider (RED)`) com 8/8 tests vermelhos antes da implementação. Falhas eram do tipo esperado (`Symfony\Component\Console\Exception\InvalidOptionException: The "--provider" option does not exist.`).
- ✅ **GREEN gate**: commit `e605397` (`feat(39-05): estende sugadores:analyze com --provider + guard ml_primary (GREEN)`) com 8/8 tests verdes (12 assertions, 1.19s). Suite Phase 39 acumulada continua em 48/48 (208 assertions). Suite Sugador acumulada continua em 65/65 (445 assertions) — zero regressão.
- ⏭️ **REFACTOR gate**: não necessário — código nasceu limpo, sem duplicação, alinhado às convenções do command base.

## Self-Check: PASSED

Verificações automáticas após escrita do SUMMARY:

- ✅ FOUND: `tests/Feature/Phase39/AnalyzeSugadoresCommandTest.php`
- ✅ MODIFIED: `app/Console/Commands/AnalyzeSugadores.php` (+44 / -7 linhas)
- ✅ FOUND commit `4318d9a` (RED test)
- ✅ FOUND commit `e605397` (GREEN feat)
- ✅ Signature contém `--provider`: validado via `grep` + `php artisan sugadores:analyze --help`
- ✅ Guard ml_primary presente: `grep "ml_primary só disponível em Phase 42"` = 1
- ✅ Whitelist presente: `grep "Provider inválido"` = 1
- ✅ 4 params em analyzeCompany: `grep "analyzeCompany.*\$provider"` confirma propagação
- ✅ ZERO modificação em `app/Services/SugadorAnalysisService.php` (validado via `git diff HEAD~2 HEAD` mostrando apenas command + test)
- ✅ ZERO modificação em providers/factory/repository/models/jobs/controllers
- ✅ Command listado: `php artisan list | grep sugadores:analyze` retorna linha com nova description
- ✅ Help mostra `--provider`: `php artisan sugadores:analyze --help | grep -c "\-\-provider"` >= 1
- ✅ Tests 39-05: 8/8 verdes (12 assertions, 1.19s)
- ✅ Phase 39 acumulado: 48/48 verdes (208 assertions, 2.51s) — 8 + 10 + 6 + 8 + 8 + 8
- ✅ Suite Sugador: 65/65 verdes (445 assertions, 42.20s) — gate de zero regressão Plan 39-04 preservado (57 → 65 = +8 novos do command)

---
*Phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar — INTEIRA FECHADA (5/5 plans)*
*Completed: 2026-06-25*
