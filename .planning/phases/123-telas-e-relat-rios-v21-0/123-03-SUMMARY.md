---
phase: 123-telas-e-relat-rios-v21-0
plan: 03
subsystem: desempenho
tags: [laravel, eloquent, inertia, react, phpunit, node-test, desempenho-por-empresa]

# Dependency graph
requires:
  - phase: 123-01
    provides: "CompanyScoreSnapshotReader (paraUsuarios/resumo) + desempenhoLabels.js + Phase123TestCase — fundações compartilhadas da fase"
provides:
  - "BonusAuditoriaController::index() com nota_empresa + 3 componentes por empresa, numa única query fora do map() de profissionais"
  - "tem_detalhe_empresas — os dois níveis de ausência da D-03 aplicados à Auditoria (banner de página × '—' silencioso por linha)"
  - "Desempenho/Auditoria.jsx com coluna de nota, selo Shopee (D-07) e banner de ausência"
  - "Phase123TestCase::darCarteira() corrigida para criar contrato de serviço performance ativo — pré-requisito para qualquer suíte que exercite CarteiraContextService::forUser()"
affects: [123-04, 123-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Leitura de detalhe por empresa SEMPRE via paraUsuarios() UMA vez fora do map() de profissionais — nunca uma query por profissional dentro do map()"
    - "Ausência tem dois níveis distintos numa tela multi-profissional: banner de página quando NENHUM profissional tem detalhe, '—' silencioso por linha quando só aquele profissional não tem"

key-files:
  created:
    - tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php
    - tests/js/estrutura-auditoria-desempenho.test.js
  modified:
    - app/Http/Controllers/BonusAuditoriaController.php
    - resources/js/Pages/Desempenho/Auditoria.jsx
    - tests/Feature/Phase123/Phase123TestCase.php

key-decisions:
  - "darCarteira() do Phase123TestCase (Plano 01) passou a criar um contrato de serviço 'performance' ativo para a empresa — sem ele CarteiraContextService::forUser() (ramo legado servico_id NULL) não resolvia a empresa, e o profissional ficava sem carteira nesta suíte. Bug de fixture não pego pelos Planos 01/02 porque suas suítes liam desempenho_company_score_snapshots direto, sem passar pela camada de contexto de carteira que BonusAuditoriaController::index() usa"
  - "computeCached() de BonusAuditoriaController::index() continua ao vivo (Pitfall 5 do 123-RESEARCH.md, comportamento anterior à fase, fora de escopo) — a suíte nova NÃO usa Http::assertNothingSent() por causa disso; Http::fake() só impede rede real. Confirmado que empresas de teste sem adman_account_id/ml_store_id fazem AdmanMetricDiffService::compute() devolver emptyMetrics() sem nenhum HTTP de fato"
  - "Asserções de valor numérico no teste de feature usam cast (float) explícito — AssertableInertia::fromTestResponse() faz round-trip por JSON, e float 'redondo' (4.0) vira int(4) na volta, quebrando comparação ==='"

requirements-completed: [UIEM-04, UIEM-03]

# Metrics
duration: ~20min
completed: 2026-08-04
---

# Phase 123 Plan 03: Auditoria de Bônus — nota por empresa (UIEM-04/D-10) Summary

**`BonusAuditoriaController::index()` passa a entregar `nota_empresa` e os 3 componentes de cada empresa já listada na Auditoria de Bônus, lendo `desempenho_company_score_snapshots` via `CompanyScoreSnapshotReader::paraUsuarios()` numa única query, e `Desempenho/Auditoria.jsx` exibe essa nota com selo de ressalva para Shopee e banner de ausência para competências sem detalhe gravado.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-04T13:35:00Z (aprox.)
- **Completed:** 2026-08-04T13:56:22Z
- **Tasks:** 2 completed
- **Files modified:** 5 (2 criados, 3 modificados)

## Accomplishments
- `BonusAuditoriaController::index()` lê `desempenho_company_score_snapshots` da MESMA fonte que `PerformanceController::show()` (Plano 02) — UIEM-04 satisfeita: nunca `breakdown_json['empresas_score']`, nunca `computeEmpresasScore()`, nunca `incluirEmpresasScore: true`
- `tem_detalhe_empresas` resolve os dois níveis de ausência da D-03 aplicados a uma tela multi-profissional: banner de página quando a competência inteira não tem nenhuma linha, `—` silencioso por linha quando só aquele profissional específico não tem
- `Desempenho/Auditoria.jsx` ganha `NotaEmpresaCell` (nota oficial/parcial/ausente com rótulo de status) e o selo "Shopee: sem dado de margem" (D-07) — texto 100% vindo de `@/lib/desempenhoLabels`, nenhuma string nova hardcoded no JSX
- Corrigido um bug de fixture herdado do Plano 01: `Phase123TestCase::darCarteira()` não criava contrato de serviço, então `CarteiraContextService::forUser()` (usado por `BonusAuditoriaController`) nunca resolvia a empresa — corrigido para todas as suítes futuras da fase que precisarem da camada de contexto de carteira

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: BonusAuditoriaController — nota e componentes por empresa (D-10)** - `e6be1c62` (feat)
2. **Task 2: Auditoria.jsx — coluna de nota, selo Shopee e banner de ausência** - `01b06acc` (feat)

_Nenhuma task teve TDD explícito (`tdd="true"` não estava setado no plano); ambas seguiram implementação direta + teste na mesma task, conforme especificado._

## Files Created/Modified
- `app/Http/Controllers/BonusAuditoriaController.php` - Injeta `CompanyScoreSnapshotReader`; `index()` monta `$detalhePorUser` UMA vez fora do `map()` de profissionais e acrescenta 11 chaves (nota, componentes, `quality`) a cada empresa já listada; `toggle()`/`bustarCacheDaEmpresa()` intocados
- `resources/js/Pages/Desempenho/Auditoria.jsx` - `NotaEmpresaCell` (nova) mostra nota/parcial/`—` com selo Shopee; banner de ausência entre "Resumo" e a lista de profissionais; `tem_detalhe_empresas = false` como default na desestruturação de props
- `tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php` - 6 testes: mesma fonte (mesmo com `breakdown_json['empresas_score'] = []`), empresa sem linha vem nula, competência inteira sem detalhe, Shopee com placeholder, exatamente 1 query contra a tabela com 3 profissionais em cena
- `tests/js/estrutura-auditoria-desempenho.test.js` - 7 gates estruturais via `lerSemComentarios`: import de `desempenhoLabels`, uso de `ehPlaceholderShopee`/`SELO_SHOPEE_TEXTO`/`AVISO_SEM_DETALHE_TITULO`/`avisoSemDetalheFechado`, default `false` de `tem_detalhe_empresas`, anti-hardcode do texto do aviso, zero regressão do fluxo de invalidar/reativar
- `tests/Feature/Phase123/Phase123TestCase.php` - `darCarteira()` agora também cria um contrato de serviço "performance" ativo (memoizado por instância de teste) para a empresa gerada

## Decisions Made
- `darCarteira()` corrigida para criar contrato de serviço ativo — ver key-decisions no frontmatter
- Testes de feature usam cast `(float)` explícito ao comparar valores numéricos vindos de `assertInertia` — o round-trip por JSON do helper de teste transforma float "redondo" (`4.0`) em `int(4)`, e a comparação `===` estrita quebraria sem o cast
- Suíte de `BonusAuditoriaController` não usa `Http::assertNothingSent()` (diferente das suítes do Plano 02) porque `computeCached()` continua ao vivo nesta tela por design pré-existente (Pitfall 5) — `Http::fake()` está lá só para impedir rede real

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `darCarteira()` não criava vínculo resolvível por `CarteiraContextService::forUser()`**
- **Found during:** Task 1, ao escrever o primeiro teste de feature contra `BonusAuditoriaController::index()`
- **Issue:** `Phase123TestCase::darCarteira()` (herdada do Plano 01) insere `company_users` com `servico_id` implicitamente `NULL` e nunca cria contrato de serviço. `CarteiraContextService::forUser()` (usado por `BonusAuditoriaController::index()` para montar `$empresas`) só resolve um vínculo `servico_id NULL` se a empresa tiver contrato de serviço "performance" ativo — sem isso, `$empresas` vinha vazio e o profissional era removido da lista por `->reject(fn ($p) => $p['empresas']->isEmpty())`. Os Planos 01/02 nunca pegaram esse bug porque suas suítes leem `desempenho_company_score_snapshots` diretamente, sem passar pela camada de contexto de carteira.
- **Fix:** `darCarteira()` passou a chamar um novo helper privado `garantirContratoPerformanceAtivo()`, que cria (memoizado por instância de teste) um serviço "performance" ativo e um `contrato_servico` ativo para a empresa nova
- **Files modified:** tests/Feature/Phase123/Phase123TestCase.php
- **Verification:** `php artisan test --filter=AuditoriaBonusNotaEmpresaTest` (6/6) e regressão completa de `php artisan test --filter=Phase123` (31/31, incluindo as 25 suítes herdadas dos Planos 01/02, que continuam intocadas em comportamento)
- **Committed in:** e6be1c62 (Task 1)

---

**Total deviations:** 1 auto-fixed (bug de fixture compartilhada, bloqueante para a task)
**Impact on plan:** Nenhum scope creep — a correção só adiciona linhas de `servicos`/`contratos_servico` de teste; nenhum comportamento de produção foi alterado. Os Planos 04/05, se usarem `darCarteira()` para exercitar telas que passem por `CarteiraContextService`, herdam a correção automaticamente.

## Issues Encountered
Nenhum além da deviation acima, resolvida dentro da própria task antes do commit.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `BonusAuditoriaController::index()` e `Desempenho/Auditoria.jsx` prontos; UIEM-04 (metade da Auditoria) e a fatia D-10 do UIEM-03 fechadas
- Padrão de correção de `darCarteira()` disponível para os Planos 04/05 caso precisem de `CarteiraContextService::forUser()` funcionando em teste
- Verificado: `git diff app/Services/` (entre o início e o fim desta sessão de 2 tasks) vazio — nenhum arquivo de `DesempenhoScoreService.php`/`CompanyScoreService.php`/`CompanyScoreSnapshotReader.php` tocado
- `--filter=AuditoriaBonusNotaEmpresaTest` 6/6, `--filter=Phase123` 31/31, `--filter=BonusInvalidacaoEmpresaTest` 5/5, `--filter=Phase122` 49/49, `--filter=Phase120` 18/18; `npm run test:js` 113/114 (1 falha pré-existente e não relacionada em `estrutura-grade-glide.test.js`, arquivo intocado nesta sessão, já documentada como baseline no 123-01-SUMMARY.md); `npm run build` sem erro

---
*Phase: 123-telas-e-relat-rios-v21-0*
*Completed: 2026-08-04*

## Self-Check: PASSED

Os 3 arquivos criados/verificados (`123-03-SUMMARY.md`, `AuditoriaBonusNotaEmpresaTest.php`, `estrutura-auditoria-desempenho.test.js`) confirmados em disco por `[ -f ... ]`; os 2 commits de task (`e6be1c62`, `01b06acc`) confirmados em `git log --oneline --all`.
