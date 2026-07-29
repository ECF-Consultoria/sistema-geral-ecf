---
phase: 119
slug: score-por-empresa-v21-0
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-07-28
---

# Phase 119 — Validation Strategy

> Derivado de `119-RESEARCH.md` § Validation Architecture, com os gates específicos da fase.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x, SQLite `:memory:` |
| **Quick run** | `C:\xampp\php\php.exe artisan test --filter=Phase119` |
| **Regressão ampla** | `C:\xampp\php\php.exe artisan test --filter=Desempenho` |
| **Baseline conhecida** | **14 falhas pré-existentes** em `--filter=Desempenho` (debug de margem já aberto). Qualquer número acima é regressão. |

⚠️ **Nunca `php artisan test` sem `--filter`** (trava por HTTP real não mockada, documentado na 116-08).
⚠️ **PHP não está no PATH do bash** — use o caminho completo do XAMPP.

---

## Requirements → Test Map

| Req | Comportamento | Caso âncora | Arquivo |
|---|---|---|---|
| EMPS-01 | Linha por empresa com todas as chaves do contrato §3.1 | — | ❌ W0 |
| EMPS-02 | Régua de faturamento **por empresa**, antes da média | A −20% → 1, B +2% → 4 ⇒ média **2,5**, contra `reguaFaturamento(−9%) = 1` da regra antiga | ❌ W0 |
| EMPS-03 | Margem lê `diff_pp`, **nunca** `diff_pct` | fixture MPP-06: `value=27,47` / `prev=24,08` ⇒ `diff_pp=3,39` ⇒ **4 pontos**, enquanto `diff_pct=14,09` daria **5** | ❌ W0 |
| EMPS-04 | `nota_empresa = round((nps+fat+margem)/3, 2)` | `(4,6 + 5 + 4)/3 = 4,53` | ❌ W0 |
| EMPS-05 | Dispatcher chamado **1×** por empresa | spy/mock ou `Http::fake` + contagem | ❌ W0 |
| EMPS-06 | Adman vence Shopee; Shopee com `margem_pontos=1.0` e `quality.margin_source=placeholder_shopee` | `(4,2 + 4 + 1,0)/3 = 3,07`, `status='complete'` | ❌ W0 |
| EMPS-07 | `status`/`quality` testáveis em `complete/partial/sem_fonte/sem_dados` | D-01: `nota_empresa=null`, `parcial=4,80`, `componentes=2`; D-03: `sem_fonte`, `parcial=4,10`, `componentes=1` | ❌ W0 |

**EMPS-03 é o teste mais importante da fase** — é ele que prova que a milestone de fato trocou a unidade da margem. A fixture escolhida diverge de propósito: `diff_pp` e `diff_pct` dão notas **diferentes** (4 vs 5), então um erro de fio invisível vira teste vermelho.

---

## Gates específicos desta fase

1. **Aditividade (toda task):**
   `sha256sum app/Services/DesempenhoScoreService.php` = `cfc16da2a8404fba0d4a9a2bc62cd1a6f668bd17fe390fe6405cebd4e71a9edd`
   e `git diff --name-only` sem esse arquivo.

2. **Equivalência das réguas (C-03):** teste via Reflection comparando `CompanyScoreService` com `DesempenhoScoreService::reguaFaturamento()`/`reguaMargem()` em **todos os pontos de corte e boundaries** — `-6, -5, -2, -1, 0, 1, 4, 5` e `null`. Molde: `tests/Feature/Phase118/NpsJanelaResolverTest::test_resolver_concorda_com_computeNpsWindow_nos_tres_casos`.
   Sem esse teste, a duplicação byte-a-byte vira dívida silenciosa.

3. **Nenhuma chamada real à Adman:** `Http::preventStrayRequests()` + `Http::fake()` em toda suíte. **O GATE MPP-04 está pendente** — a fase não pode depender de comportamento real da API.

4. **Guard da fonte nula (C-04):** teste provando que empresa com `status='sem_fonte'` **não** chega ao `MetricDiffDispatcher::compute()` (que lançaria `InvalidArgumentException`). O guard vem antes, não no `catch`.

---

## Wave 0 Requirements

Convenção: `tests/Feature/Phase119/` (padrão confirmado por Phase117 e Phase118).

- [ ] Suíte cobrindo EMPS-01..EMPS-07 — organização em arquivos a critério do planner
- [ ] Teste de equivalência das réguas (gate 2 acima)
- [ ] Teste do guard de fonte nula (gate 4 acima)
- [ ] Fixtures financeiras reusando a fixture MPP-06 já validada de `tests/Feature/V18/AdmanMetricDiffServiceTest.php`

---

## Manual-Only Verifications

Nenhuma. Serviço de leitura puro, sem integração externa em runtime de teste.

⚠️ **Mas a fase inteira está atrás do GATE MPP-04**, que é humano e externo a ela — ver `<blocking_dependency>` no CONTEXT. Testes verdes **não** liberam a fase.

---

## Sinal de aprovação / reprovação

**PASSA:** `--filter=Phase119` verde · `--filter=Desempenho` dentro da baseline de 14 · hash inalterado · nenhum consumidor de produção referencia `CompanyScoreService`.

**REPROVA:** `DesempenhoScoreService` modificado · qualquer número de produção mudando · teste de equivalência das réguas ausente · chamada real à Adman em teste.

---

## Validation Sign-Off

- [x] Todas as tasks com verify `<automated>` ou dependência de Wave 0
- [x] Gate de aditividade explícito
- [x] Gate de equivalência das réguas explícito
- [x] Sem watch-mode, sem rede real
- [x] `nyquist_compliant: true`

**Approval:** approved 2026-07-28
