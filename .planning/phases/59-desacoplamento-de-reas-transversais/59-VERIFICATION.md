---
phase: 59-desacoplamento-de-reas-transversais
verified: 2026-07-06T00:00:00Z
status: passed
score: 7/7 must-haves verificados
overrides_applied: 0
---

# Phase 59: Desacoplamento de áreas transversais — Verification Report

**Phase Goal:** Auditar e generalizar Usuários, Setores, Comercial, Administrativo, NPS, Notificações — remover acoplamento ML-only. Confirmar Publicação como transversal.
**Verificado:** 2026-07-06
**Status:** passed
**Re-verification:** Não — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | `59-AUDIT.md` existe com tabelas Comercial + Company + Admin + seção Publicação | ✓ VERIFIED | Arquivo lido por inteiro (604 linhas). Contém `## Comercial (ComercialController.php)`, `## Company (CompanyController.php)`, `## Admin (AdminController.php)`, `## Publicação — CONFIRMED transversal`, `## Sumário`, `## Baseline pré-fix`, seções de execução Plan 02/03 |
| 2 | Cada linha da tabela AUDIT tem arquivo:linha, trecho, tipo, severidade, plano | ✓ VERIFIED | As 3 tabelas (Comercial 12 linhas, Company 13 linhas, Admin 8 linhas) têm as 5 colunas preenchidas em 100% das linhas amostradas |
| 3 | Ambos os itens `fix Phase 59` (2 MEDIUM) estão APLICADOS no código, com commit sha rastreável | ✓ VERIFIED | `CompanyController.php:132` → `'adman_account_id' => $c->cust_id` (commit `e816307`, confirmado `git show --stat`); `AdminController.php:548,714` → `$f->cust_id` em ambas rotas (commit `90a2afe`, confirmado `git show --stat`). Grep no código atual confirma ambas as substituições presentes e comentadas `// Phase 59 fix` |
| 4 | Reality-check do scout (0 refs ML nas 6 áreas nominais do ROADMAP) documentado e escopo reduzido de forma rastreável | ✓ VERIFIED | `59-CONTEXT.md §Domain` e §Locked Decision 1 documentam a redução; decisão está travada como aceita, não é gap silencioso |
| 5 | Publicação (`pub.*`) confirmada transversal com evidência grep + execução real de suite | ✓ VERIFIED | `app/Models/User.php:214-217` (`hasPubPermission` delega para `hasPermission("mlb.{$perm}")`, sem referência a `marketplace`/`ml_store_id`/`adman_account_id`); `app/Http/Middleware/EnsurePermission.php` lido por inteiro (40 linhas) — nenhum filtro de marketplace, apenas checagem de permission key literal. AUDIT também documenta evidência dinâmica (suites `Phase38Publicador`, `PublicacaoDesempenhoRouteTest`, etc. verdes pós-fix) |
| 6 | Suite de regressão sem novos vermelhos (delta = 0 vs. baseline) | ✓ VERIFIED | AUDIT documenta baseline 955 testes / 63 vermelhos (15 errors + 48 failures) e pós-fix idêntico (delta = 0). Spot-check independente: `php artisan test --filter=Phase57` → **20/20 passed** (26 assertions, 11.55s); `php artisan test --filter=Phase58` → **16/16 passed** (62 assertions, 38.70s) — batem exatamente com os números declarados no AUDIT/PHASE-SUMMARY. Spot-check adicional em `AdminFechamentoControllerTest` + `Phase14AdminControllerCobrancaTest` + `Phase14VerificarCobrancaTest` → **8 failed / 14 passed**, idêntico ao número documentado no AUDIT como "pré-existente, não relacionado ao fix" |
| 7 | CROSS-01/02/03 rastreáveis via `requirements` frontmatter dos plans + REQUIREMENTS.md | ✓ VERIFIED | `59-01-PLAN.md` → `requirements: [CROSS-01, CROSS-02]`; `59-02-PLAN.md` → `requirements: [CROSS-01]`; `59-03-PLAN.md` → `requirements: [CROSS-02, CROSS-03]`. `.planning/REQUIREMENTS.md` marca os 3 como `[x]` e tabela de status mostra `CROSS-01/02/03 | Phase 59 | Complete` |

**Score:** 7/7 truths verificados

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `.planning/phases/59-.../59-AUDIT.md` | Mapa exaustivo ML nos 3 controllers + seção Publicação | ✓ VERIFIED | 604 linhas, todas as seções exigidas presentes, substantivo (não stub) |
| `app/Http/Controllers/CompanyController.php` | Fix `cust_id` aplicado | ✓ VERIFIED | Linha 132: `'adman_account_id' => $c->cust_id` com comentário `// Phase 59 fix` na linha 129 |
| `app/Http/Controllers/AdminController.php` | Fix `cust_id` aplicado (2 rotas) | ✓ VERIFIED | Linha 548 (`fechamento()`) e linha 714 (`gerarRelatorioGeral()`) ambas usam `$f->cust_id`, comentários `// Phase 59 fix` presentes |
| `app/Http/Middleware/EnsurePermission.php` | Confirmar ausência de filtro ML | ✓ VERIFIED | Arquivo lido por inteiro — apenas `hasPermission($key)` OR-loop, zero referência a marketplace |
| `app/Models/User.php::hasPubPermission()` | Transversal (sem filtro de marketplace) | ✓ VERIFIED | Linha 214-217 — delega para `hasPermission("mlb.{$perm}")`, apenas naming histórico `mlb.` no prefixo da key (documentado como LOW/deferred v14+, não bloqueia) |
| `PHASE-SUMMARY.md` | Fechamento consolidado dos 3 plans | ✓ VERIFIED | Presente, consistente com AUDIT.md e commits reais |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `59-AUDIT.md` tabelas | `ComercialController.php`/`CompanyController.php`/`AdminController.php` | referências arquivo:linha | WIRED | Amostragem de linhas citadas (ex.: Company:129→132 pós-shift, Admin:545/709→548/714) bate com o código real, com nota explícita no AUDIT sobre o shift de numeração causado por outro trabalho não commitado no working tree |
| `59-AUDIT.md §Publicação` | `User.php`/`EnsurePermission.php` | grep evidence | WIRED | Evidência grep no AUDIT confere linha a linha com o código lido nesta verificação |
| Commits `e816307`/`90a2afe` | Itens "fix Phase 59" do AUDIT | git log + git show | WIRED | Ambos commits existem no histórico, mensagens referenciam explicitamente `59-AUDIT.md` seção correspondente, diffs batem com o texto descrito |
| `59-0X-PLAN.md requirements:` | `.planning/REQUIREMENTS.md` (CROSS-01/02/03) | frontmatter cross-reference | WIRED | União dos 3 plans cobre exatamente CROSS-01, CROSS-02, CROSS-03 sem lacuna nem sobreposição inconsistente |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 57 regressão zero | `php artisan test --filter=Phase57` | 20 passed, 26 assertions, 11.55s | ✓ PASS |
| Phase 58 regressão zero | `php artisan test --filter=Phase58` | 16 passed, 62 assertions, 38.70s | ✓ PASS |
| Vermelhos pré-existentes tocados pelo fix Admin (`fechamento()`) permanecem idênticos | `php artisan test tests/Feature/AdminFechamentoControllerTest.php tests/Feature/Phase14AdminControllerCobrancaTest.php tests/Feature/Phase14VerificarCobrancaTest.php` | 8 failed, 14 passed, 173 assertions | ✓ PASS (bate exatamente com número documentado no `59-02-SUMMARY.md`/AUDIT como pré-existente) |
| Fix `cust_id` presente no código (não apenas no texto do AUDIT) | `grep -n "cust_id" app/Http/Controllers/{Company,Admin}Controller.php` | `CompanyController.php:132: 'adman_account_id' => $c->cust_id`; `AdminController.php:548,714: 'adman_account_id' => $f->cust_id` | ✓ PASS |
| Commits do fix existem e batem com a descrição do AUDIT | `git show --stat e816307 / 90a2afe` | Ambos existem, diffs pequenos e cirúrgicos (1 arquivo cada, poucas linhas) | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| CROSS-01 | 59-01, 59-02 | Auditoria concluída mapeando cada acoplamento ML-only + plano de generalização | ✓ SATISFIED | `59-AUDIT.md` com 56 refs classificados, 2 fixes aplicados, commits verificados |
| CROSS-02 | 59-01, 59-03 | Publicação confirmada transversal — `pub.*` sem amarração ML | ✓ SATISFIED | Grep + leitura de código confirmam ausência de filtro ML em `hasPubPermission()`/`EnsurePermission`; único achado é naming histórico `mlb.` (LOW, deferred v14+, não bloqueia CROSS-02) |
| CROSS-03 | 59-03 | Testes de regressão passam após generalização | ✓ SATISFIED | Delta = 0 documentado no AUDIT (955 testes, 63 vermelhos pré-existentes idênticos); spot-check independente confirma Phase 57 (20/20) e Phase 58 (16/16) e a contagem exata de 8/14 no grupo Admin tocado |

Nenhum requisito órfão encontrado — `.planning/REQUIREMENTS.md` mapeia apenas CROSS-01/02/03 para Phase 59, e os 3 aparecem distribuídos sem lacuna nos 3 plans.

### Anti-Patterns Found

Nenhum anti-padrão bloqueante encontrado nos arquivos tocados pela Phase 59 (`CompanyController.php`, `AdminController.php`, `59-AUDIT.md`):

- Sem `TODO`/`FIXME`/`HACK`/`TBD`/`XXX` introduzidos pelos commits `e816307`/`90a2afe`.
- Fixes são substituições de expressão (`$c->cust_id` / `$f->cust_id`), sem lógica stub ou placeholder.
- Comentários `// Phase 59 fix` são rastreáveis e explicam o racional, conforme convenção pt-BR do projeto.

**Observação não-bloqueante:** o working tree local tem alterações não commitadas em `CompanyController.php`/`AdminController.php`/outros arquivos, de uma frente de trabalho paralela não relacionada à Phase 59 (permissões de portfolio, goal metrics) — já documentado no próprio AUDIT.md como causa do shift de numeração de linha (129→109, 545→ mesma mas outros trechos deslocados). Isso não afeta a validade dos fixes desta phase (confirmados via `git show` dos commits específicos), mas é um lembrete de que o working tree atual não é um espelho limpo do estado pós-Phase-59 — outro trabalho está em andamento em paralelo.

### Human Verification Required

Nenhum item requer verificação humana. Todos os truths são verificáveis via grep/leitura de código/execução de suite, conforme metodologia do CONTEXT §2 (decisão de não criar teste dedicado para Publicação, usando grep + suite real como evidência suficiente).

### Gaps Summary

Nenhum gap encontrado. Os 3 requisitos (CROSS-01, CROSS-02, CROSS-03) têm evidência concreta e verificável no código, não apenas no texto do SUMMARY/AUDIT:

- CROSS-01: deliverable `59-AUDIT.md` existe, é substantivo (604 linhas, 33 linhas de tabela, metodologia explícita), e os 2 itens `fix Phase 59` foram confirmados aplicados no código real via `git show` + `grep`.
- CROSS-02: Publicação confirmada transversal via leitura direta de `EnsurePermission.php` (arquivo inteiro, 40 linhas, zero referência a marketplace) e `User::hasPubPermission()` — achado único é naming histórico `mlb.` no prefixo de permission key, corretamente classificado como LOW/deferred v14+ (não bloqueia o requisito, que fala de comportamento, não de naming).
- CROSS-03: spot-checks independentes (Phase 57 20/20, Phase 58 16/16, grupo Admin 8/14) batem exatamente com os números declarados no AUDIT/PHASE-SUMMARY, confirmando que a alegação de "delta = 0" não é apenas narrativa do SUMMARY, mas reproduzível.

A redução de escopo das 6 áreas nominais do ROADMAP para os 3 controllers hotspot está documentada e justificada com evidência de scout (`grep` retornando 0 refs), travada como decisão aceita em `59-CONTEXT.md §Locked Decision 1` — não é um gap silencioso, é uma descoberta válida documentada antes da execução.

---

*Verificado: 2026-07-06*
*Verificador: Claude (gsd-verifier)*
