---
phase: 137
slug: m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
status: planned
nyquist_compliant: true
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
| 137-01-T1 | 137-01 | 0 | — | T-137-SC, T-137-08 | Ambiente: Feature tests conseguem renderizar Inertia | infra | `phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php` | ✅ existe | ⬜ pending |
| 137-01-T2 | 137-01 | 0 | — | — | Baseline verde registrada ANTES da migration | infra | `Baseline command` + grep em `137-BASELINE-TESTES.md` | ✅ criado na task | ⬜ pending |
| 137-02-T1 | 137-02 | 1 | ETAPA-01 | T-137-09 | Migration aditiva pura; `down()` derruba só `etapa` | schema | `artisan migrate` + `db:table companies --json` | n/a | ⬜ pending |
| 137-02-T2 | 137-02 | 1 | ETAPA-01 | T-137-01, T-137-10 | 9 valores na ordem do §10; `etapa` em `$fillable` documentado | unit | `phpunit tests/Unit/Phase137/CompanyEtapaConstantesTest.php` | 🔨 criado na task | ⬜ pending |
| 137-03-T1 | 137-03 | 2 | ETAPA-06 | T-137-11 | Histórico append-only com ator e instante | schema | `artisan migrate` + `db:table company_etapa_transicoes --json` | n/a | ⬜ pending |
| 137-03-T2 | 137-03 | 2 | ETAPA-06 | T-137-03 | Destino validado contra `Company::ETAPAS`; recusa nomeia o requisito | unit | `phpunit tests/Unit/Phase137/EtapaTransicaoServiceTest.php` | 🔨 criado na task | ⬜ pending |
| 137-03-T3 | 137-03 | 2 | ETAPA-03, ETAPA-06 | T-137-02, T-137-12 | Ator tipado `User`; grava só `etapa`; histórico transacional | unit | `phpunit tests/Unit/Phase137/EtapaTransicaoServiceTest.php` | 🔨 mesma suíte | ⬜ pending |
| 137-04-T1 | 137-04 | 2 | ETAPA-04 | — | 4 colunas de pendência, booleano NOT NULL default false | schema | `artisan migrate` + `db:table companies --json` | n/a | ⬜ pending |
| 137-04-T2 | 137-04 | 2 | ETAPA-04 | T-137-05, T-137-13, T-137-14 | Marcar/desmarcar pendência nunca move a etapa; leitura por ponto único | feature | `phpunit tests/Feature/Phase137/EtapaPendenciaParaleloTest.php` | 🔨 criado na task | ⬜ pending |
| 137-05-T1 | 137-05 | 3 | ETAPA-02 | T-137-07 | Dry-run por padrão; escrita delegada ao serviço | cli | `artisan etapa:backfill` | n/a | ⬜ pending |
| 137-05-T2 | 137-05 | 3 | ETAPA-02 | T-137-16 | Dois baldes; nenhuma etapa intermediária; payload de `/companies` idêntico | feature | `phpunit tests/Feature/Phase137/EtapaBackfillTest.php` | 🔨 criado na task | ⬜ pending |
| 137-05-T3 | 137-05 | 3 | ETAPA-02 | T-137-15 | Contagens conferidas por reconsulta ao banco (D-08) | feature + SQL | `phpunit .../EtapaBackfillTest.php` + grep em `137-BACKFILL-CONTAGENS.md` | 🔨 criado na task | ⬜ pending |
| 137-06-T1 | 137-06 | 3 | ETAPA-03 | T-137-01, T-137-03, T-137-14 | `PUT /companies/{company}` com `etapa` no payload NÃO grava; varredura estática de `app/` | feature + varredura | `phpunit tests/Feature/Phase137/EtapaPontoUnicoTest.php` | 🔨 criado na task | ⬜ pending |
| 137-06-T2 | 137-06 | 3 | ETAPA-03 | T-137-17 | Aviso no ponto exato da regressão, apontando o teste que cobra | feature | `phpunit .../EtapaPontoUnicoTest.php` + `Baseline command` | 🔨 mesma suíte | ⬜ pending |
| 137-07-T1 | 137-07 | 4 | ETAPA-05 | T-137-18, T-137-19 | Filtro server-side com allow-list; `sem_etapa` de primeira classe (D-22) | feature | `phpunit tests/Feature/Phase137/EtapaFiltroListagemTest.php` | 🔨 criado na task | ⬜ pending |
| 137-07-T2 | 137-07 | 4 | ETAPA-05 | T-137-20 | Controles server-side, nunca `Array.filter`; build do front | feature + build | `npm run build` + `phpunit .../EtapaFiltroListagemTest.php` | 🔨 mesma suíte | ⬜ pending |
| 137-07-T3 | 137-07 | 4 | ETAPA-05 | T-137-04 | Verificação humana da tela + suíte completa contra a baseline | checkpoint | `phpunit tests/Unit/Phase137 tests/Feature/Phase137` + baseline | 🔨 mesma suíte | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

**Wave 0 = plano `137-01`, e ele NÃO cria arquivos de teste.** A fase roda com `tdd_mode`
desligado, então cada arquivo de teste nasce **na mesma task** que a implementação que ele prova
— o `<verify><automated>` da task roda a suíte recém-criada e é auto-suficiente. Isso satisfaz o
Nyquist (feedback automatizado a cada task, latência ~10-15s) sem escrever seis suítes contra
código inexistente, que seria TDD.

O que o Wave 0 entrega, e que é pré-condição de todo o resto:

- [ ] **Ambiente Inertia** — `npm install && npm run build` (preferido, porque o plano 137-07
      altera `Companies/Index.jsx` e o `CLAUDE.md` exige build) ou, em caso de falha, `public/hot`
      temporário. Sem isso toda Feature test falha por ambiente (Pitfall 4)
- [ ] **Baseline verde registrada ANTES da migration**, em `137-BASELINE-TESTES.md`, com o campo
      `caminho:` dizendo qual dos dois foi usado e com as falhas pré-existentes de Polos nomeadas
- [ ] Framework: **nada a instalar** — PHPUnit já configurado; nenhuma dependência nova na fase

Arquivos de teste e o plano/task que os cria:

| Arquivo | Criado em | Requirement |
|---------|-----------|-------------|
| `tests/Unit/Phase137/CompanyEtapaConstantesTest.php` | 137-02 / T2 | ETAPA-01 |
| `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` | 137-03 / T2 e T3 | ETAPA-06, ETAPA-03 |
| `tests/Feature/Phase137/EtapaPendenciaParaleloTest.php` | 137-04 / T2 | ETAPA-04 |
| `tests/Feature/Phase137/EtapaBackfillTest.php` | 137-05 / T2 | ETAPA-02 |
| `tests/Feature/Phase137/EtapaPontoUnicoTest.php` | 137-06 / T1 | ETAPA-03 |
| `tests/Feature/Phase137/EtapaFiltroListagemTest.php` | 137-07 / T1 | ETAPA-05 |

Fixture opcional: trait `CriaEmpresaComEtapa` (molde `tests/Feature/V16/CriaCenarioResponsaveis.php`)
— extraível em 137-05 / T2 se a duplicação de setup incomodar.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Contagem por balde do backfill em **produção** (`total`, `etapa 9`, `etapa NULL`) | ETAPA-02 (D-08) | A base local tem 180 empresas contra ~500 em produção, e só 1 satisfaz o balde 1 localmente — o teste automatizado prova a **regra**, nunca o **volume real** | Antes e depois do backfill, rodar a contagem por **reconsulta ao banco de produção**, nunca por stdout do comando. Gravar os dois números no `VERIFICATION.md` |
| A aba "Empresas" de `/companies` mostra em produção exatamente o mesmo conjunto de antes | Success Criteria nº 1 | O recorte da tela é mais amplo que `em_operacao` (corta `whereDoesntHave('mlbEmpresa')` + exige contrato ativo de setor Performance); só a tela real prova o conjunto | Capturar a lista de empresas da aba antes do deploy do backfill e reconferir depois. Diferença de uma empresa já é falha |
| Armadilhas de MariaDB que o SQLite dos testes não pega | ETAPA-01 | `phpunit.xml` roda SQLite `:memory:`; produção é MariaDB — ver `.planning/learnings/desempenho-bonificacao.md` §6 | Conferir a migration aplicada em MariaDB antes de considerar a coluna entregue |

---

## Validation Sign-Off

- [x] Todas as tasks têm verify `<automated>` (17/17 tasks)
- [x] Continuidade de amostragem: nenhuma task sem verify automatizado
- [x] Wave 0 (137-01) cobre o bloqueio de ambiente e a baseline; suítes nascem com suas implementações
- [x] Nenhuma flag de watch-mode
- [x] Latência de feedback < 15s no ciclo de task
- [x] Baseline verde registrada **antes** da migration é task explícita (137-01 / T2), e a migration só acontece em 137-02
- [x] `nyquist_compliant: true` marcado no frontmatter

**Approval:** planejado em 2026-09-01 — 7 planos, 5 waves (0-4). Ver `137-01-PLAN.md`..`137-07-PLAN.md`.
