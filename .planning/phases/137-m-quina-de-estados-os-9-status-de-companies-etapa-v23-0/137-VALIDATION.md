---
phase: 137
slug: m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-09-01
---

# Phase 137 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `137-RESEARCH.md` § "Validation Architecture" (linhas 787-846).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| **Config file** | `phpunit.xml` — testsuites `Unit` → `tests/Unit`, `Feature` → `tests/Feature`; DB de teste = SQLite `:memory:` |
| **Quick run command** | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase137 tests/Feature/Phase137 --colors=never` |
| **Baseline command** | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never` |
| **Full suite command** | `C:\xampp\php\php.exe artisan test` |
| **Estimated runtime** | Quick ~10s · Baseline ~15s (medido: 24 testes / 44 assertions) · Full ~vários minutos |

### ⚠ Pré-requisito de ambiente — BLOQUEIA qualquer teste Feature neste worktree

Toda Feature test que renderiza Inertia precisa de `public/build/manifest.json` **ou** de um
`public/hot`. Neste worktree **nenhum dos dois existe** (`node_modules/` também está ausente).
Sem o workaround, as suítes quebram por ambiente e o resultado é confundido com regressão.

Ver `137-RESEARCH.md` § "Common Pitfalls" → Pitfall 4 para o comando exato. A opção rápida é o
`public/hot` temporário; a completa é `npm install && npm run build`. **Este passo é uma task do
Wave 0**, não uma nota de rodapé.

---

## Sampling Rate

- **A cada commit de task:** `Quick run command` (suítes de `Phase137/`)
- **A cada fim de wave:** `Quick run command` + `Baseline command` (as duas suítes de `/companies`
  que provam o Success Criteria nº 1)
- **Antes de `/gsd:verify-work`:** `Full suite command` verde — exigência explícita do `CLAUDE.md`
  (§ "GSD obrigatório") para migration em tabela com dado de produção
- **Latência máxima de feedback:** ~15 segundos no ciclo de task

### Falhas pré-existentes — NÃO são regressão desta fase

`tests/Feature/Phase38/PolosControllerTest.php` e `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`
têm ~10 falhas antigas, documentadas em `.planning/learnings/painel-polos-status-e-meta.md` §2.
Se aparecerem no full suite, **não investigar** — comparar sempre contra a baseline registrada
antes da migration.

---

## Per-Task Verification Map

Os IDs de task são preenchidos pelo planner. As linhas abaixo são o contrato por requirement que
todo task derivado precisa satisfazer.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | TBD | 0 | — | — | Ambiente: Feature tests conseguem renderizar Inertia | infra | `Baseline command` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | ETAPA-01 | T-137-01 | `etapa` em `$fillable` não vira porta de mass assignment | unit | `phpunit tests/Unit/Phase137/CompanyEtapaConstantesTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | ETAPA-02 | — | Backfill não perde empresa da aba "Empresas" | feature | `phpunit tests/Feature/Phase137/EtapaBackfillTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | ETAPA-03 | T-137-01 | `PUT /companies/{company}` com `etapa` no payload NÃO grava | feature + grep | `phpunit tests/Feature/Phase137/EtapaPontoUnicoTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | 2 | ETAPA-04 | — | Marcar/desmarcar pendência nunca move a etapa | unit + feature | `phpunit tests/Feature/Phase137/EtapaPendenciaParaleloTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | 2 | ETAPA-05 | — | Filtro server-side; `sem_etapa` de primeira classe (D-22) | feature | `phpunit tests/Feature/Phase137/EtapaFiltroListagemTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | ETAPA-06 | T-137-03 | Destino validado contra `Company::ETAPAS`; recusa nomeia o requisito | unit | `phpunit tests/Unit/Phase137/EtapaTransicaoServiceTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] **Ambiente Inertia** — `public/hot` temporário OU `npm install && npm run build`, senão toda
      Feature test falha por ambiente (Pitfall 4). **Registrar a baseline verde ANTES da migration.**
- [ ] `tests/Unit/Phase137/CompanyEtapaConstantesTest.php` — ETAPA-01
- [ ] `tests/Feature/Phase137/EtapaBackfillTest.php` — ETAPA-02 (D-04 / D-05 / D-08)
- [ ] `tests/Feature/Phase137/EtapaPontoUnicoTest.php` — ETAPA-03, incluindo o caso do Pitfall 1
      (`PUT /companies/{company}` com `etapa` no payload)
- [ ] `tests/Feature/Phase137/EtapaPendenciaParaleloTest.php` — ETAPA-04
- [ ] `tests/Feature/Phase137/EtapaFiltroListagemTest.php` — ETAPA-05
- [ ] `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` — ETAPA-06
- [ ] Fixture opcional: trait `CriaEmpresaComEtapa` (molde: `tests/Feature/V16/CriaCenarioResponsaveis.php`)
- [ ] Framework: **nada a instalar** — PHPUnit já configurado

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Contagem por balde do backfill em **produção** (`total`, `etapa 9`, `etapa NULL`) | ETAPA-02 (D-08) | A base local tem 180 empresas contra ~500 em produção, e só 1 satisfaz o balde 1 localmente — o teste automatizado prova a **regra**, nunca o **volume real** | Antes e depois do backfill, rodar a contagem por **reconsulta ao banco de produção**, nunca por stdout do comando. Gravar os dois números no `VERIFICATION.md` |
| A aba "Empresas" de `/companies` mostra em produção exatamente o mesmo conjunto de antes | Success Criteria nº 1 | O recorte da tela é mais amplo que `em_operacao` (corta `whereDoesntHave('mlbEmpresa')` + exige contrato ativo de setor Performance); só a tela real prova o conjunto | Capturar a lista de empresas da aba antes do deploy do backfill e reconferir depois. Diferença de uma empresa já é falha |
| Armadilhas de MariaDB que o SQLite dos testes não pega | ETAPA-01 | `phpunit.xml` roda SQLite `:memory:`; produção é MariaDB — ver `.planning/learnings/desempenho-bonificacao.md` §6 | Conferir a migration aplicada em MariaDB antes de considerar a coluna entregue |

---

## Validation Sign-Off

- [ ] Todas as tasks têm verify `<automated>` ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: nunca 3 tasks consecutivas sem verify automatizado
- [ ] Wave 0 cobre todas as referências MISSING acima
- [ ] Nenhuma flag de watch-mode
- [ ] Latência de feedback < 15s no ciclo de task
- [ ] Baseline verde registrada **antes** da migration (exigência do `CLAUDE.md`)
- [ ] `nyquist_compliant: true` marcado no frontmatter

**Approval:** pending
