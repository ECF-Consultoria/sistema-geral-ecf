---
phase: 123
slug: telas-e-relat-rios-v21-0
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-03
---

# Phase 123 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `123-RESEARCH.md` §"Validation Architecture".

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework (PHP)** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), estilo `#[Test]` attributes |
| **Framework (JS)** | `node --test` nativo, via `npm run test:js` — testes em `tests/js/*.test.js`. Alcança apenas função pura (o projeto não tem harness de render React) |
| **Config file** | `phpunit.xml` (DB sqlite `:memory:`) |
| **Quick run command** | `php artisan test --filter=Phase123` + `npm run test:js` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~5s (quick) — nenhuma suíte desta fase pode fazer chamada de rede |

**Baseline conhecida:** `Phase110/Desempenho` têm falhas pré-existentes documentadas em `122-VERIFICATION.md`. Não confundir com regressão nova desta fase.

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=Phase123` (e `npm run test:js` quando a task tocar `resources/js/lib/`)
- **After every plan wave:** `php artisan test --filter=Phase122` + `php artisan test --filter=Phase123` + `npm run test:js` + `npm run build`
  *(Phase122 garante que a fonte de dados que esta fase apenas lê continua estável.)*
- **Before `/gsd:verify-work`:** `php artisan test` completo verde (contra a baseline acima)
- **Max feedback latency:** 30 segundos

---

## Per-Task Verification Map

> Preenchido pelo gsd-planner ao escrever os PLAN.md — uma linha por task.
> As linhas abaixo são o mapa **requisito → comportamento → comando** já derivado da pesquisa;
> o planner deve ancorar cada task a uma delas.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | TBD | TBD | UIEM-02 | — | Props Inertia de `show()` só expõem `empresas_score` em competência fechada com linhas persistidas | feature | `php artisan test --filter=PerformanceShowEmpresasScoreTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-02 | — | Render de `show()` com detalhe por empresa não emite nenhuma chamada HTTP (`Http::assertNothingSent()`) | feature | `php artisan test --filter=PerformanceShowEmpresasScoreTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-02 / D-02 | — | `meses_disponiveis` deriva dos dados; corte fixo `2026-08-01` removido | feature | `php artisan test --filter=PerformanceShowMesesDisponiveisTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-02 / D-06 | — | Denominador "entraram (N)" / "não entraram (M)" bate com `status==='complete'` vs resto | feature | `php artisan test --filter=PerformanceShowEmpresasScoreTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-02 / D-06 | — | `dividirPorEntrada()` particiona por `status==='complete'` preservando a ordem; caso Felipe: 3 entram, 27 não, seção nasce colapsada | unit (JS) | `npm run test:js` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-02 / D-07 | — | Empresa com `quality.margin_source='placeholder_shopee'` aparece **dentro** de "entraram (N)", nunca excluída | feature | `php artisan test --filter=PerformanceShowEmpresasScoreTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-02 / D-07 | — | `carteiraTodaShopeeNaEntrada()` devolve `true` só com carteira 100% placeholder (caso Matheus Estrela) e `false` com array vazio | unit (JS) | `npm run test:js` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-03 / D-03 | — | Competência fechada sem linha em `desempenho_company_score_snapshots` mostra aviso — não lista vazia, não erro | feature | `php artisan test --filter=PerformanceShowSemDetalheTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-03 / D-11 | — | `resultado` sem chave `empresas_score` renderiza o visual anterior (4 cards + faixa) sem 500 nem `undefined` | feature | `php artisan test --filter=RetrocompatSnapshotAntigoTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-04 / D-08 | — | `RelatorioBonificacaoController::index()` expõe `empresas_score` lendo `desempenho_company_score_snapshots` (não `breakdown_json`) | feature | `php artisan test --filter=RelatorioBonificacaoEmpresasTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-04 / D-09 | — | `RelatorioBonificacaoController::pdf()` permanece resumo — uma linha por profissional, sem template novo | feature (regressão) | `php artisan test --filter=RelatorioBonificacaoEmpresasTest` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | UIEM-04 / D-10 | — | `BonusAuditoriaController::index()` expõe `nota_empresa` pela mesma fonte do ranking | feature | `php artisan test --filter=AuditoriaBonusNotaEmpresaTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Nenhuma suíte `Phase123` existe ainda. Wave 0 precisa criar:

- [ ] `tests/Feature/Phase123/PerformanceShowEmpresasScoreTest.php` — UIEM-02, D-06, D-07 (+ `Http::fake()` / `Http::assertNothingSent()` para a regressão de fan-out)
- [ ] `tests/Feature/Phase123/PerformanceShowMesesDisponiveisTest.php` — UIEM-02 / D-02
- [ ] `tests/Feature/Phase123/PerformanceShowSemDetalheTest.php` — UIEM-03 / D-03
- [ ] `tests/Feature/Phase123/RetrocompatSnapshotAntigoTest.php` — UIEM-03 / D-11
- [ ] `tests/Feature/Phase123/RelatorioBonificacaoEmpresasTest.php` — UIEM-04 / D-08 + D-09
- [ ] `tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php` — UIEM-04 / D-10
- [ ] `tests/js/desempenhoLabels.test.js` — copy pt-BR, formatadores e **as três regras puras de particionamento** (`dividirPorEntrada`, `carteiraTodaShopeeNaEntrada`, `deveColapsarNaoEntraram`), com os casos Felipe (3/30, colapso em 27) e Matheus Estrela (carteira toda-Shopee)

**Fixtures:** reaproveitar o helper `seedLinha()` de `tests/Feature/Phase122/VerificarConsolidacaoTest.php`, promovido para a base `Phase123TestCase` no Plano 01. Ambos os frameworks já instalados — nada a instalar.

**Por que as regras de particionamento viraram função pura:** sem harness de render React, lógica de ramificação dentro do `EmpresasScoreTabela.jsx` só seria verificável por `grep` de presença e pelo checkpoint humano da wave 5 — o fim da cadeia. Extraídas para `desempenhoLabels.js`, as três decisões de maior risco da fase (quem entra na conta, carteira toda-Shopee, estado inicial do colapso) ficam travadas por teste já na wave 1.

---

## Manual-Only Verifications

**Competência obrigatória do checkpoint: 2026-06.** É a única com detalhe por empresa em produção (286 linhas, 11 profissionais). 2026-07 só existe a partir de 31/08 e **não** deve ser antecipada por reconsolidação manual (learnings §2 — recompute de mês fechado pode mexer em pagamento de quem está perto da fronteira).

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Card de margem sem jargão de campo de API (sai `percentageMargin`) | UIEM-01 | Texto de UI; asserção de string em PHPUnit não prova legibilidade | 1 verificação visual do card de margem em qualquer mês/modo. Confirmar linguagem simples ("quantos pontos percentuais a margem subiu ou caiu"). |
| Denominador pequeno visível — **Felipe** (margem sobre 3 de 30 empresas) | UIEM-02 / D-06 | Caso real que só o dado de produção expõe | "Entraram na conta" mostra N pequeno (não 30); "não entraram" lista as ~27 restantes **com motivo visível** — nem lista vazia, nem erro de contagem. |
| Selo Shopee — **Matheus Estrela** (carteira só-Shopee) | UIEM-02 / D-07 | Regra de placeholder invisível no código de UI | TODAS as empresas dele aparecem em "entraram na conta" (`status='complete'`) com o selo "Shopee: sem dado de margem" na célula de margem. Nunca excluídas do denominador. |
| Caso comum renderiza limpo | UIEM-02 | Confirma que o caminho feliz não regrediu | 1 profissional com maioria de empresas completas (ex.: Ana Julia ou Luiz Henrique, da tabela do rollout da Fase 122). |
| Ausência de detalhe não some silenciosamente | UIEM-03 / D-03 | Percepção do usuário, não comportamento de código | (a) Mês sem nenhum detalhe exibe o aviso da D-03; (b) card de margem em "Em curso" continua mostrando `var_margem_pct` normalmente. |
| **Débora Lima** ausente não quebra tela | UIEM-03 | Regressão de borda vinda de outro problema em aberto | Ela não deve ter linha em ranking, Relatório de Bonificação nem Auditoria — e a ausência **não** pode gerar 500 em nenhuma delas. Não aparecer não é bug desta fase; quebrar é. |
| Expansão do Relatório de Bonificação | UIEM-04 / D-08 | Interação (clique) não coberta por asserção de props | Expandir a linha de ao menos 1 profissional contemplado (ex.: Rubens, único com bônus `intermediario` em 2026-06) e ver empresas + nota + 3 componentes. |
| Coluna de nota na Auditoria de Bônus | UIEM-04 / D-10 | Leitura visual cruzada com o ranking | Conferir a coluna de nota por empresa em ao menos 1 competência, batendo com a fonte do ranking. |
| `npm run build` + aprovação visual | Critério 5 do ROADMAP | É literalmente um gate de aprovação humana | Rodar `npm run build` (obrigatório pelo CLAUDE.md) e obter aprovação explícita do usuário no checkpoint visual. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (6 suítes `Phase123` acima)
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] Checkpoint visual feito em **2026-06** com as 3 fixtures nomeadas (Felipe, Matheus Estrela, Débora Lima)
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
