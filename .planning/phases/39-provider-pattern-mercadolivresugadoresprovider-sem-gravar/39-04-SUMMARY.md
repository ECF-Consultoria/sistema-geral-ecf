---
phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
plan: 04
subsystem: sugadores
tags: [phase-39, sugadores, refactor, di, factory, zero-regression, ml-migration, tdd]

# Dependency graph
requires:
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    plan: 01
    provides: "Contract SugadoresAdsProvider + AdmanSugadoresProvider + factory minimal"
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    plan: 02
    provides: "MercadoLivreSugadoresProvider + factory branch ml — factory completo com 2 providers"
provides:
  - "SugadorAnalysisService refatorado: constructor recebe SugadoresAdsProviderFactory via DI ao invés de AdmanService direto"
  - "analyzeAll / analyzeCompany aceitam ?string \\$forceProvider opcional para override CLI (Plan 39-05)"
  - "buildCampaignsInfoFromProvider — método privado novo que substitui chamada direta ao AdmanService::fetchAllCampaigns dentro de analyzeCompany"
  - "Suite Feature Phase39\\SugadorAnalysisServiceRefactorTest — 8 tests cobrindo o refactor de DI (reflection + Mockery)"
  - "Baseline de regressão Sugador documentada (.planning/.../regression-baseline.txt) — 49 verdes pré-refactor; gate de aceitação"
affects:
  - "Phase 39 Plan 39-05 (comando sugadores:analyze --provider=) — agora pode passar \\$forceProvider via factory→service"
  - "Phase 42 (cut-over ml_primary) — quando ativar, basta enriquecer factory; analyzeCompany já preparado para receber \\$forceProvider de env"
  - "AnalyzeCompanySugadoresJob — pega o novo constructor automaticamente via DI Laravel (sem mudança no Job)"
  - "SugadorController — pega o novo constructor automaticamente (chamadas a analyzeCompany não passam \\$forceProvider, comportamento preservado)"
  - "CleanupSugadoresQuarentena command — continua usando loadCampaignsInfo legacy (não tocado) sem regressão"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Provider resolution via factory injection (Laravel DI) — substitui constructor com service concreto por factory que decide provider em runtime"
    - "Backward-compat preservada via AdmanService como segundo arg do constructor — loadCampaignsInfo legacy intocado para CleanupSugadoresQuarentena"
    - "RuntimeException do factory.for() convertida em skip estruturado — preserva semântica de retorno analyzeCompany (companies_skipped, não companies_failed)"
    - "Suite de tests reflexivos (ReflectionClass + ReflectionMethod) para validar contrato de DI sem rodar runtime — barato e robusto"
    - "Mock SugadoresAdsProviderFactory bindando no container via \\$this->app->instance() — DI resolve cadeia toda automaticamente (provider mock no lugar do real)"
    - "Mock AdmanService preservado por compat — tests Sugador legados que faziam mock(AdmanService::class) continuam funcionando porque AdmanSugadoresProvider resolve o mock via DI"

key-files:
  created:
    - ".planning/phases/39-.../regression-baseline.txt (146 linhas; baseline pré-refactor com cabeçalho pt-BR documentando gate)"
    - "tests/Feature/Phase39/SugadorAnalysisServiceRefactorTest.php (336 linhas; 8 tests + helpers Mockery; 18 assertions)"
  modified:
    - "app/Services/SugadorAnalysisService.php (+99 / -27 linhas; constructor + analyzeAll/analyzeCompany ganha \\$forceProvider; 2 chamadas Adman substituídas por provider; loadCampaignsInfo legacy comentário atualizado; novo método buildCampaignsInfoFromProvider)"

key-decisions:
  - "Constructor recebe AMBOS SugadoresAdsProviderFactory + AdmanService (não apenas factory) — preserva loadCampaignsInfo legacy bit-a-bit para CleanupSugadoresQuarentena (fora de escopo Phase 39). Phase 42+ remove AdmanService quando todos os call-sites migrarem"
  - "Param \\$forceProvider adicionado nos métodos públicos analyzeAll E analyzeCompany — analyzeAll precisa propagar adiante para que o comando (Plan 39-05) possa rodar análise global com --provider=ml. Backward-compat: default null preserva o caminho atual"
  - "RuntimeException do factory.for() convertida em skip estruturado (skipped=true, reason='sem provider compatível: ...') — não relançada como exceção. Preserva semântica: analyzeAll trata como companies_skipped (não companies_failed), igual ao skip antigo 'sem adman_account_id'"
  - "Derivação manual de \\$custId (\\$company->adman_account_id ?: \\$company->ml_store_id) DELETADA do analyzeCompany — provider sabe extrair internamente. Único call-site remanescente é loadCampaignsInfo legacy que recebe \\$custId puro como parâmetro (preservado)"
  - "Derivação manual de \\$marketplace (\\$company->marketplace ?? 'meli') DELETADA do analyzeCompany — provider concreto sabe lidar com marketplace internamente (AdmanSugadoresProvider faz \\$company->marketplace ?? 'meli' dentro de cada fetch). Removida duplicação"
  - "Skip 'config inativa' preservado (factory.for() vem antes — empresa sem provider compatível skipa primeiro). Ordem: provider resolve → config check → execução"
  - "buildCampaignsInfoFromProvider (novo, privado) substitui chamada loadCampaignsInfo(\\$custId, \\$marketplace) dentro de analyzeCompany — recebe \\$provider + \\$company, normaliza payload do contract §2.2 (campaign_id/campaign_name/campaign_status) para o shape interno (name/status). Fail-open preservado (Throwable → log warning → array vazio → análise segue sem filtro de quarentena)"
  - "loadCampaignsInfo legacy (público, recebe \\$custId puro) PRESERVADO bit-a-bit — usado por CleanupSugadoresQuarentena command line. Comentário PHPDoc atualizado para explicar coexistência Phase 39 vs legacy"
  - "Tests Feature (com RefreshDatabase) — não Unit — porque o pipeline DI factory→service envolve container Laravel e SugadorConfig::forCompany precisa de DB; helper makeCompanyWithConfig persiste Company+SugadorConfig"
  - "8 tests do refactor cobrem APENAS a troca de DI (constructor, parâmetro, chamadas) — lógica de detecção (evaluateMetrics, STATUS_TRAVADOS, auto-resolve, quarentena) NÃO é re-testada aqui; permanece nas 49 suites legadas (gate de zero regressão)"
  - "T3/T4/T6 com assertTrue(true) explícito + mensagem descritiva — evita PHPUnit 'risky' warning quando a única validação é expectativa Mockery ->once() (que tearDown valida via Mockery::close)"
  - "T7 usa Mockery shouldNotReceive em fetchAdsMetrics/fetchCampaignsRange/fetchAllCampaigns do AdmanService — garante que TODA chamada Adman dentro de analyzeCompany agora passa pelo provider"
  - "T8 (paridade end-to-end) usa AdmanSugadoresProvider REAL com AdmanService mockado — valida cadeia DI completa factory→AdmanSugadoresProvider→AdmanService e que o pipeline gera o mesmo 'detalhes' que o path antigo geraria"

patterns-established:
  - "Pattern: Refactor de DI com baseline-driven regression gate — captura output da suite antes do refactor, compara count exato após. Padrão replicável para futuros refactors arquiteturais (Phase 42 cut-over, Phase 43 rename)"
  - "Pattern: Coexistência factory + service legacy no constructor — durante migração, ambos no constructor; método público antigo (loadCampaignsInfo) preservado para callers externos; novo método privado (buildCampaignsInfoFromProvider) substitui interno"
  - "Pattern: Tests reflexivos (ReflectionClass/Method) para validar contrato de DI — barato (não roda runtime), robusto a refactors futuros, documenta a invariante arquitetural"

requirements-completed: [REQ-39-06]

# Metrics
duration: 35min
completed: 2026-06-25
---

# Phase 39 Plan 39-04: Refactor SugadorAnalysisService → SugadoresAdsProviderFactory via DI Summary

**Refactor crítico do núcleo de detecção entregue com zero regressão: constructor de `SugadorAnalysisService` agora recebe `SugadoresAdsProviderFactory` via DI (preservando `AdmanService` como segundo arg para compat do `loadCampaignsInfo` legacy usado por `CleanupSugadoresQuarentena`). `analyzeAll`/`analyzeCompany` ganham parâmetro opcional `?string $forceProvider`. Chamadas `$this->adman->fetchAdsMetrics` e `fetchCampaignsRange` dentro de `analyzeCompany` substituídas por `$provider->fetchAdgroupsMetrics` e `fetchCampaignsMetrics` resolvidos via factory. Lógica de detecção (`evaluateMetrics`, `buildRow`, `STATUS_TRAVADOS`, auto-resolve, regra % anúncios sugadores, regex de quarentena, upsert idempotente, dryRun bypass) PRESERVADA bit-a-bit. Suite Sugador continua em 49 verdes (gate de zero regressão atendido); somando os 8 novos tests do refactor, total subiu para 57 verdes.**

## Performance

- **Duração:** ~35 min
- **Iniciado:** 2026-06-25T21:00Z (após leitura de PLAN + CONTEXT + 39-01/02/03 SUMMARYs + arquivo SugadorAnalysisService inteiro)
- **Concluído:** 2026-06-25T21:35Z
- **Tasks:** 3 (Baseline + RED + GREEN)
- **Files criados:** 2 (1 baseline regressão + 1 teste Feature Phase39)
- **Files modificados:** 1 (`app/Services/SugadorAnalysisService.php`)
- **Files do "não-tocar":** 0 modificações em `AdmanService`, `MercadoLivreService`, `MercadoLivreAdsService`, providers Plan 39-01/02, factory Plan 39-01/02, `AdgroupMlbMapRepository` (Plan 39-03), `Sugador`/`SugadorConfig`/`SugadorAcao` models, `AnalyzeCompanySugadoresJob`, `SugadorController`, `AnalyzeSugadores` command — validado via `git diff --quiet HEAD -- <protected paths>`.

## Accomplishments

- **Constructor refatorado**: `private SugadoresAdsProviderFactory $providers, private AdmanService $adman` — factory é a dependência primária, AdmanService preservado APENAS para o `loadCampaignsInfo` legacy (CleanupSugadoresQuarentena command line). Comentário PHPDoc explica a coexistência e Phase 42+ removerá o AdmanService quando todos os call-sites migrarem.
- **`analyzeAll(?Carbon $referenceDate = null, bool $dryRun = false, ?string $forceProvider = null)`** — terceiro parâmetro novo propagado para `analyzeCompany`. Permite Plan 39-05 chamar `analyzeAll(null, false, 'ml')` quando comando passar `--provider=ml`.
- **`analyzeCompany(Company $company, ?Carbon $referenceDate = null, bool $dryRun = false, ?string $forceProvider = null)`** — quarto parâmetro novo passado a `$this->providers->for($company, $forceProvider)`. Backward-compat: chamadas existentes (Job, Controller, Command) sem o param caem no default null → factory escolhe Adman por capability detection (regra "prefere Adman quando ambos suportam" estabelecida no Plan 39-02).
- **Resolução do provider no topo de analyzeCompany** (substitui derivação manual de `$custId`):
  ```php
  try {
      $provider = $this->providers->for($company, $forceProvider);
  } catch (\RuntimeException $e) {
      return $skip('sem provider compatível: ' . $e->getMessage());
  }
  ```
  RuntimeException convertida em skip estruturado — preserva semântica de retorno `analyzeAll` (companies_skipped, não companies_failed).
- **Chamadas Adman substituídas** dentro de `analyzeCompany`:
  - `$this->adman->fetchAdsMetrics($custId, $dateFrom, $dateTo, 50, $marketplace)` → `$provider->fetchAdgroupsMetrics($company, $periodoInicio, $periodoFim)`
  - `$this->adman->fetchCampaignsRange($custId, $dateFrom, $dateTo, $marketplace)` → `$provider->fetchCampaignsMetrics($company, $periodoInicio, $periodoFim)`
  - `$this->loadCampaignsInfo($custId, $marketplace)` → `$this->buildCampaignsInfoFromProvider($provider, $company)` (novo método privado)
- **Novo método privado `buildCampaignsInfoFromProvider(SugadoresAdsProvider $provider, Company $company)`** — substitui `loadCampaignsInfo` dentro do fluxo novo. Recebe provider já resolvido pela factory; chama `$provider->fetchCampaigns($company)` e normaliza o payload do contrato §2.2 (`campaign_id`/`campaign_name`/`campaign_status`) para o shape interno do filtro de quarentena (`name`/`status`). Fail-open preservado: `Throwable` → `Log::warning` → array vazio → análise segue sem o filtro.
- **`loadCampaignsInfo` legacy** PRESERVADO funcionalmente, comentário PHPDoc atualizado: declarado como LEGACY Phase 30, explicando que `CleanupSugadoresQuarentena` (fora de escopo Phase 39) continua usando-o com `$custId` puro.
- **Lógica de detecção 100% PRESERVADA**: `evaluateMetrics`, `buildRow`, `STATUS_TRAVADOS`, auto-resolve via `DB::transaction` + `SugadorAcao` insert, regra "% anúncios sugadores → flag campanha", `QUARANTINE_NAME_REGEX`, `QUARANTINE_STATUSES`, `shouldSkipCampaign`, upsert idempotente em `sugadores`, dryRun bypass — tudo bit-a-bit como antes.
- **8/8 tests do refactor verdes** (`SugadorAnalysisServiceRefactorTest`, 18 assertions, 1.14s):
  - T1: constructor recebe SugadoresAdsProviderFactory
  - T2: analyzeCompany aceita ?string $forceProvider opcional
  - T3: factory.for() recebe forceProvider quando passado ('adman')
  - T4: factory.for() recebe null quando forceProvider omitido
  - T5: provider->fetchAdgroupsMetrics chamado quando incluir_anuncios=true
  - T6: provider->fetchCampaignsMetrics chamado quando incluir_campanhas=true
  - T7: AdmanService NÃO é chamado direto dentro de analyzeCompany (Mockery shouldNotReceive em fetchAdsMetrics/fetchCampaignsRange/fetchAllCampaigns)
  - T8: paridade end-to-end com AdmanSugadoresProvider REAL produz mesmo `detalhes` que o path antigo
- **Gate de zero regressão atendido**: suite Sugador legada (49 testes, 415 assertions) continua **verde com EXATAMENTE o mesmo count** — comparado bit-a-bit com a baseline pré-refactor (capturada em commit `09cd274`). Pós-refactor: 49+8 = **57 verdes** (433 assertions). Validado via `php artisan test --filter=Sugador` → `Tests: 57 passed`.
- **40/40 suite Phase 39 acumulada verde** (196 assertions, 2.35s): 8 AdmanProvider (39-01) + 10 MlProvider (39-02) + 6 Factory (39-01/02) + 8 Repository (39-03) + 8 Refactor (39-04).

## Task Commits

Cada task commitada atomicamente:

1. **Task 0 (BASELINE): captura regressão pré-refactor** — `09cd274` (chore)
   - Roda `php artisan test --filter=Sugador 2>&1` e grava em `.planning/.../regression-baseline.txt`
   - Adiciona cabeçalho pt-BR documentando: 49 total / 49 verdes / 0 falhas pré-existentes / 415 assertions
   - Define gate de aceitação para Task 2 (GREEN): mesmo count após refactor
   - Zero arquivo de produção tocado

2. **Task 1 (RED): suite de regressão do refactor** — `14eb676` (test)
   - Cria `tests/Feature/Phase39/SugadorAnalysisServiceRefactorTest.php` com 8 tests Mockery+Reflection
   - 8/8 FAIL esperado: `SugadoresAdsProviderFactory` ainda não está no constructor (Reflection retorna apenas `AdmanService`)
   - RED gate validado: tests realmente exercitam a invariante a refatorar

3. **Task 2 (GREEN): refactor SugadorAnalysisService** — `f23ba31` (refactor)
   - Edita `app/Services/SugadorAnalysisService.php` (+99 / -27 linhas):
     - Constructor agora recebe `SugadoresAdsProviderFactory + AdmanService` (compat)
     - `analyzeAll` e `analyzeCompany` ganham `?string $forceProvider = null`
     - Resolução de provider via factory.for() com try/catch RuntimeException → skip
     - Chamadas Adman substituídas por provider->fetch*
     - Novo método privado `buildCampaignsInfoFromProvider`
     - `loadCampaignsInfo` legacy preservado funcionalmente (comentário atualizado)
   - Ajusta `tests/Feature/Phase39/SugadorAnalysisServiceRefactorTest.php` com 3 `assertTrue(true)` em T3/T4/T6 para evitar PHPUnit 'risky' warnings
   - 8/8 tests refactor PASS (18 assertions, 1.14s)
   - **GATE CRÍTICO atendido**: 49/49 suite Sugador legada continua verde (zero regressão); 57=49+8 total

**Plan metadata commit:** será adicionado neste commit final junto com STATE.md/ROADMAP.md.

_TDD: Baseline (Task 0) → RED (Task 1) → GREEN (Task 2) — fluxo TDD adaptado para refactor com gate de regressão explícito._

## Files Created/Modified

### Criados

- **`.planning/phases/39-provider-pattern-mercadolivresugadoresprovider-sem-gravar/regression-baseline.txt`** (146 linhas) — Cabeçalho pt-BR + output completo de `php artisan test --filter=Sugador` pré-refactor. Documenta as 8 suites cobertas (4 Unit Phase39 + 4 Feature Sugador/Phase19/Phase30) totalizando 49 verdes e 415 assertions. Define o gate de aceitação: "pós-refactor: Tests: 49 passed (mesmo count)".
- **`tests/Feature/Phase39/SugadorAnalysisServiceRefactorTest.php`** (336 linhas) — Suite Feature com `RefreshDatabase`. 8 tests cobrindo o refactor de DI (reflexivos para constructor/parâmetro + Mockery para chamadas provider + Mockery shouldNotReceive para garantir que AdmanService não é chamado direto + um teste de paridade end-to-end com AdmanSugadoresProvider real). Helpers `makeCompanyWithConfig` (persiste Company+SugadorConfig) e `bindMockedProviderInFactory` (binda mock no container). Comentário de cabeçalho documenta que esta suite NÃO duplica a cobertura de detecção das suites legadas — só valida o refactor de DI.

### Modificados

- **`app/Services/SugadorAnalysisService.php`** (+99 / -27 linhas) — Mudanças cirúrgicas:
  1. **Imports** (linhas 3-13): adicionados `App\Contracts\SugadoresAdsProvider` (usado em type-hint do método privado `buildCampaignsInfoFromProvider`) e `App\Services\Sugadores\SugadoresAdsProviderFactory` (usado no constructor).
  2. **Constructor** (linhas 27-40): trocado de `(private AdmanService $adman)` para `(private SugadoresAdsProviderFactory $providers, private AdmanService $adman)`. PHPDoc explica a coexistência (factory primário + adman legacy preservado).
  3. **`analyzeAll`** (linhas 59-99): assinatura ganha `?string $forceProvider = null` no terceiro parâmetro; propaga para `analyzeCompany`.
  4. **`analyzeCompany`** (linhas 112-146): assinatura ganha `?string $forceProvider = null` no quarto parâmetro. Derivação manual de `$custId`/`$marketplace` REMOVIDA. Adicionada resolução do provider via `try/catch (\RuntimeException $e) { return $skip('sem provider compatível: ...'); }`. Chamada `loadCampaignsInfo` substituída por `buildCampaignsInfoFromProvider`.
  5. **Chamada Adman dentro do try `incluir_anuncios`** (linhas 162-168): `$this->adman->fetchAdsMetrics($custId, $dateFrom, $dateTo, 50, $marketplace)` → `$provider->fetchAdgroupsMetrics($company, $periodoInicio, $periodoFim)`. Comentário Phase 39 documentando a substituição.
  6. **Chamada Adman dentro do try `incluir_campanhas`** (linhas 238-244): `$this->adman->fetchCampaignsRange($custId, $dateFrom, $dateTo, $marketplace)` → `$provider->fetchCampaignsMetrics($company, $periodoInicio, $periodoFim)`. Comentário Phase 39 documentando a substituição.
  7. **`loadCampaignsInfo` legacy** (linhas 543-570): PHPDoc atualizado declarando LEGACY Phase 30 e explicando que o fluxo novo usa `buildCampaignsInfoFromProvider`. Lógica interna INALTERADA (continua chamando `$this->adman->fetchAllCampaigns`).
  8. **Novo método privado `buildCampaignsInfoFromProvider`** (linhas 572-592): recebe `SugadoresAdsProvider $provider, Company $company`. Chama `$provider->fetchCampaigns($company)` (contrato §2.2). Normaliza payload para shape interno do filtro de quarentena. Fail-open preservado.

**Diagnóstico**: validações via grep confirmam:
- `grep -c "SugadoresAdsProviderFactory" app/Services/SugadorAnalysisService.php` = 2 (use + constructor)
- `grep -c "?string \$forceProvider"` = 2 (analyzeAll + analyzeCompany)
- `grep -c "provider->fetchAdgroupsMetrics|fetchCampaignsMetrics|fetchCampaigns"` = 5 ocorrências funcionais
- `grep -nE '^\s*[^/].*\$this->adman->fetchAdsMetrics|fetchCampaignsRange'` = 0 (chamadas funcionais; as 2 ocorrências restantes são comentários explicativos)
- `grep -n 'this->adman'` = 5 totais: 4 comentários documentando o refactor + 1 chamada funcional remanescente (`fetchAllCampaigns` em `loadCampaignsInfo` legacy)

## Decisões Tomadas

- **Constructor mantém AdmanService como segundo arg** (não removido) — escopo Phase 39 era refatorar `SugadorAnalysisService` SEM tocar em `CleanupSugadoresQuarentena` command. Esse comando chama `$service->loadCampaignsInfo($custId)` direto (linha 31 do comando), e esse método internamente usa `$this->adman->fetchAllCampaigns`. Remover o AdmanService quebraria o command. Decisão: preservar via DI (Laravel resolve automaticamente) e documentar removal em Phase 42+ quando todos os call-sites migrarem.
- **Param `$forceProvider` adicionado em analyzeAll TAMBÉM** (não só em analyzeCompany) — Plan 39-05 vai querer rodar análise global passando `--provider=ml`. Sem o param em analyzeAll, o command teria que iterar empresas manualmente. Decisão de simetria entre os dois métodos públicos.
- **RuntimeException convertida em skip** (não relançada) — `factory.for()` lança `RuntimeException` quando empresa não tem provider compatível (sem `adman_account_id` E sem `mlToken` ativo). Antes, o skip era literal: `if (!$custId) return $skip('sem adman_account_id')`. Manter o pattern de retorno estruturado preserva o contrato implícito com `analyzeAll` (companies_skipped, não failed) e com os testes legados de "empresa sem cust_id" (se houvesse).
- **Derivação manual de `$custId` e `$marketplace` REMOVIDA** do analyzeCompany — o provider concreto extrai esses valores internamente (`AdmanSugadoresProvider` faz `$company->adman_account_id` e `$company->marketplace ?? 'meli'` dentro de cada `fetch*`). Manter a derivação no service seria duplicação morta. Linhas 96-101 do código antigo (comentário explicando o bug `ml_store_id ?: adman_account_id`) também removidas — bug já mitigado pela responsabilidade do provider.
- **`buildCampaignsInfoFromProvider` é privado** (não público) — só usado dentro de `analyzeCompany`. Diferente do `loadCampaignsInfo` legacy, que precisa ser público porque `CleanupSugadoresQuarentena` o chama de fora. Não há razão para expor o novo método ao mundo externo.
- **Mensagem de log em `buildCampaignsInfoFromProvider`** inclui `$provider->name()` ('adman' ou 'ml') — facilita debug quando provider ML começar a falhar mais que Adman (esperado pre-smoke Phase 38 Tarefa 3 destravar).
- **Tests usam `Feature/Phase39/`** (não `Unit/Phase39/`) — porque o pipeline DI factory→service envolve container Laravel real (`$this->app->make`), `SugadorConfig::forCompany` precisa de DB persistido (firstOrCreate), e o teste T8 (paridade) cria e executa o pipeline completo de upsert/skip/auto-resolve. Os tests do Plan 39-01/02/03 são Unit porque exercitam classes isoladas sem cadeia DI.
- **3 tests com `assertTrue(true)` explícito** (T3/T4/T6) — Mockery `->once()` é validado em `tearDown` via `Mockery::close()`, mas o PHPUnit avalia o teste como "risky" se não há assertion explícita no método. Adicionar um `assertTrue(true)` com mensagem descritiva é o pattern canônico recomendado pela documentação Laravel para esses casos. Cada um cita explicitamente a expectativa Mockery que está sendo validada.
- **T7 (`shouldNotReceive` em AdmanService)** prova que ZERO chamada direta ao Adman acontece dentro de `analyzeCompany` — cobre não só `fetchAdsMetrics` e `fetchCampaignsRange` (substituídos), mas também `fetchAllCampaigns` (substituído por `provider->fetchCampaigns` via `buildCampaignsInfoFromProvider`). Comentário no teste explica que `loadCampaignsInfo` legacy ainda pode chamar `fetchAllCampaigns`, mas só quando chamado de FORA (CleanupSugadoresQuarentena).
- **T8 (paridade end-to-end)** usa `AdmanSugadoresProvider` REAL com `AdmanService` mockado — valida a cadeia DI completa `factory.for()` → `AdmanSugadoresProvider::fetchAdgroupsMetrics` → `AdmanService::fetchAdsMetrics` (mockado) e que o pipeline de análise gera o mesmo `detalhes['motivos']` que o path antigo gerava. Cenário: 1 adgroup com investimento R$100 sem venda → `gasto_sem_venda` (default config `gasto_minimo_sem_venda=20.00`).

## Deviations from Plan

Nenhuma deviation autocorretiva inesperada. Pequenos refinamentos alinhados ao escopo:

- **Param `$forceProvider` adicionado em `analyzeAll` também** (plan original mencionava apenas `analyzeCompany`) — refinamento de simetria para destravar Plan 39-05 sem que ele precise iterar manualmente. Documentado no PHPDoc de `analyzeAll`.
- **3 `assertTrue(true)` explícitos em T3/T4/T6** — não previsto no plan original, mas necessário porque PHPUnit marca como 'risky' tests cuja única validação é Mockery `->once()`. Decisão alinhada com pattern Laravel canônico (Mockery::close no tearDown valida o `once`, mas o método precisa de uma assertion explícita).
- **Imports não-usados removidos do test em iteração rápida** — `AdmanSugadoresProvider`, `MercadoLivreAdsService`, `MercadoLivreSugadoresProvider`, `ReflectionMethod` foram importados inicialmente e removidos após o refactor do test mostrar que não eram necessários. Sem impacto funcional.

Total deviations: 0 auto-fixed (Rule 1-3) | 0 architectural (Rule 4) | Impact: nenhum funcional.

## Issues Encontrados

Nenhum bloqueador. Pontos relevantes do diagnóstico:

- **MariaDB local continua caído** (quick task `260625-mrd`) — mitigado integralmente: tests usam `RefreshDatabase` + SQLite em-memory (default `phpunit.xml`); zero dependência de MariaDB.
- **Tests RED iniciais falhavam com `PDOException "There is already an active transaction"`** nos tests T7 e T8 — sintoma colateral porque `analyzeCompany` chama `DB::transaction` para o auto-resolve, e quando o test ainda não tinha o refactor + Mockery não capturava as chamadas Adman, a Throwable que normalmente seria capturada pelo try-catch interno ricocheteou pra fora. Não-issue após o GREEN: transactions funcionam normalmente porque o provider mock devolve `[]` (sem ads, sem upsert, sem auto-resolve, sem transação).
- **Aviso PHPUnit metadata deprecation** em suites Phase18/33/35/36/37 — pré-existente, fora de escopo (SCOPE BOUNDARY).
- **IDE hints `Property $X accessed via magic method Model::__get(): mixed`** — pre-existente em todo o codebase Laravel/Eloquent. Severity: Hint, não erro. Fora de escopo.
- **Hint `Special function 'in_array' should be called in global namespace`** — pre-existente (linha 382). Micro-otimização sem ganho mensurável. Fora de escopo.

## User Setup Required

Nenhum — plan 39-04 só refatora dependência interna do `SugadorAnalysisService`. Sem mudança em contratos externos, env vars, schema, rotas, queues ou comandos. Consumidores via DI (`AnalyzeCompanySugadoresJob`, `SugadorController`, `AnalyzeSugadores` command) recebem o novo constructor automaticamente.

## Next Phase Readiness

- **Plan 39-05 (comando sugadores:analyze --provider= --dry-run) DESBLOQUEADO** — `analyzeCompany` e `analyzeAll` agora aceitam `?string $forceProvider`, e a factory ML está completa (Plan 39-02). O comando pode:
  - Estender `app/Console/Commands/AnalyzeSugadores.php` com flags `--company={id}`, `--provider={adman|ml}`, `--dry-run`
  - Quando `--dry-run`, chamar `analyzeCompany($company, null, true, $provider)`
  - Quando `--provider=ml` SEM `--dry-run`, abortar com exit 1 e mensagem "Modo ml_primary só disponível em Phase 42" (proteção)
  - Imprimir tabela CLI com motivos detectados
- **Wave 3 da Phase 39 FECHADA** — restam apenas Plan 39-05 (Wave 4).
- **Phase 42 (cut-over ml_primary)** continua dependendo de Phase 40 (shadow mode) e Phase 41 (onboarding ML por empresa). Quando ativar, `analyzeCompany` já está preparado: basta o factory ler env `SUGADORES_ML_PRIMARY_COMPANIES` e decidir o provider. Refactor desta phase é o destravamento arquitetural.
- **Risco residual conhecido**: shape do payload ML continua sendo hipótese pre-smoke (Phase 38 Tarefa 3 deferida por MariaDB local). Quando smoke rodar e a fixture especulativa for substituída pela real, os tests do Plan 39-02 (`MercadoLivreSugadoresProviderTest`) podem precisar de ajuste, mas o refactor desta phase NÃO precisa: o contrato `SugadoresAdsProvider` é estável.

## TDD Gate Compliance

- ✅ **BASELINE gate**: commit `09cd274` (`chore(39-04): captura baseline regressão Sugador pré-refactor`) com 49 verdes documentados ANTES de qualquer modificação.
- ✅ **RED gate**: commit `14eb676` (`test(39-04): adiciona suite regressão refactor SugadorAnalysisService (RED)`) com 8/8 tests vermelhos antes da implementação. Falhas eram do tipo esperado (Reflection assertion negativa porque constructor antigo só tinha AdmanService).
- ✅ **GREEN gate**: commit `f23ba31` (`refactor(39-04): SugadorAnalysisService usa SugadoresAdsProviderFactory via DI (GREEN)`) com 8/8 tests refactor verdes (18 assertions, 1.14s) E suite Sugador legada continuando em 49 verdes (zero regressão), totalizando 57=49+8 verdes (433 assertions, 28.27s).
- ⏭️ **REFACTOR gate**: não necessário — código nasceu limpo, sem duplicação, alinhado às convenções do código existente.

## Self-Check: PASSED

Verificações automáticas após escrita do SUMMARY:

- ✅ FOUND: `.planning/phases/39-provider-pattern-mercadolivresugadoresprovider-sem-gravar/regression-baseline.txt`
- ✅ FOUND: `tests/Feature/Phase39/SugadorAnalysisServiceRefactorTest.php`
- ✅ MODIFIED: `app/Services/SugadorAnalysisService.php` (+99 / -27 linhas)
- ✅ FOUND commit `09cd274` (BASELINE chore)
- ✅ FOUND commit `14eb676` (RED test)
- ✅ FOUND commit `f23ba31` (GREEN refactor)
- ✅ EMPTY diff: `app/Contracts/SugadoresAdsProvider.php` (Plan 39-01 intocado)
- ✅ EMPTY diff: `app/Services/AdmanService.php` (intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/AdmanSugadoresProvider.php` (Plan 39-01 intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` (Plan 39-02 intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (Plan 39-02 intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/MercadoLivreAdsService.php` (Phase 38 intocado)
- ✅ EMPTY diff: `app/Services/MercadoLivreService.php` (Phase 20 intocado)
- ✅ EMPTY diff: `app/Repositories/AdgroupMlbMapRepository.php` (Plan 39-03 intocado)
- ✅ EMPTY diff: `app/Models/Sugador.php` (intocado)
- ✅ EMPTY diff: `app/Models/SugadorConfig.php` (intocado)
- ✅ EMPTY diff: `app/Models/SugadorAcao.php` (intocado)
- ✅ EMPTY diff: `app/Jobs/AnalyzeCompanySugadoresJob.php` (intocado — DI resolve novo constructor automaticamente)
- ✅ EMPTY diff: `app/Http/Controllers/SugadorController.php` (intocado — DI resolve novo constructor; chamadas a analyzeCompany sem $forceProvider preservam comportamento)
- ✅ EMPTY diff: `app/Console/Commands/AnalyzeSugadores.php` (intocado — Plan 39-05 estende)
- ✅ EMPTY diff: `app/Console/Commands/CleanupSugadoresQuarentena.php` (intocado — continua usando loadCampaignsInfo legacy preservado)
- ✅ Refactor tests: 8/8 verdes (18 assertions, 1.14s)
- ✅ Phase 39 acumulado: 40/40 verdes (196 assertions, 2.35s)
- ✅ Suite Sugador: 57/57 verdes (433 assertions, 28.27s) — 49 legadas (zero regressão) + 8 novos do refactor
- ✅ Baseline match: 49 verdes pré-refactor = 49 verdes pós-refactor (gate crítico atendido)
- ✅ ZERO `$this->adman->fetchAdsMetrics` ou `fetchCampaignsRange` chamadas funcionais (validado via grep)
- ✅ Lógica de detecção preservada: `evaluateMetrics`, `buildRow`, `STATUS_TRAVADOS`, `STATUS_AUTO_RESOLVIDO`, `QUARANTINE_NAME_REGEX` (validado via grep)

---
*Phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar*
*Completed: 2026-06-25*
