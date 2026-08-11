---
phase: 136
slug: m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-11
---

# Phase 136 — Estratégia de Validação

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `136-RESEARCH.md` § *Validation Architecture*.

---

## Test Infrastructure

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11.x via `php artisan test` (Laravel 12.57.0) |
| **Config file** | `phpunit.xml` — testsuites `Unit` (`tests/Unit`) e `Feature` (`tests/Feature`) |
| **Quick run command** | `C:\xampp\php\php.exe artisan test --filter="Phase136"` |
| **Full suite command** | `C:\xampp\php\php.exe artisan test --testsuite=Feature` |
| **Estimated runtime** | quick ~30–60s · Phase119 (gate de aditividade) ~90s · suíte Feature completa ~vários minutos |

> **Ambiente:** `php` **não** está no PATH do Bash tool neste ambiente Windows/XAMPP — usar sempre o caminho absoluto `C:\xampp\php\php.exe`.

---

## Sampling Rate

- **Após cada commit de task:** `C:\xampp\php\php.exe artisan test --filter="Phase136"` **e** `C:\xampp\php\php.exe artisan test --filter="Phase119"` (gate de aditividade — deve continuar 100% verde).
- **Após cada wave:** suítes `V18`, `V16` e `DesempenhoShopeeScoreTest`, para confirmar que a **baseline de 9 falhas pré-existentes não cresceu**.
- **Antes de `/gsd:verify-work`:** `--testsuite=Feature` completo, verde exceto as 9 falhas pré-existentes já documentadas (learnings §0.02).
- **Max feedback latency:** ~180s (quick + Phase119).

### Baseline de falhas pré-existentes (medida no RESEARCH, HEAD atual, sem mudanças desta fase)

**9 failed / 18 passed.** Bate com learnings §0.02. Qualquer investigação de "regressão" deve comparar contra este número, não contra zero.

---

## Per-Task Verification Map

> Esta fase **não tem REQ-IDs mapeados no ROADMAP** (`Requirements: TBD` confirmado). A unidade de rastreabilidade é o **ID de decisão** do `136-CONTEXT.md` (D-01..D-12, D-EXC-01) — é o mecanismo real desta fase.
>
> A coluna `Task ID` é preenchida pelo planner ao criar os `*-PLAN.md`; até lá, a tabela abaixo é o **contrato de comportamento** que cada task deve amarrar.

| Task ID | Plan | Wave | Decisão | Threat Ref | Comportamento seguro / esperado | Test Type | Automated Command | File Exists | Status |
|---------|------|------|---------|------------|--------------------------------|-----------|-------------------|-------------|--------|
| TBD | TBD | TBD | D-10 | T-136-* | Empresa com vínculos performance+shopee **sem** `cust_id` resolve `'shopee'`, não `'adman'` | Feature | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-10 (regressão) | — | Empresa com vínculos performance+shopee **com** `cust_id` válido continua resolvendo `'adman'` | Feature | `artisan test --filter="CompanyScoreServiceFonteTest"` | ✅ existe | ⬜ pending |
| TBD | TBD | TBD | D-01 / D-07 | V5 | Lançamento manual de faturamento **não** afeta o eixo de margem da mesma célula, e vice-versa | Feature/Unit | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-02 | — | Célula `manual` **não** é sobrescrita quando a API passa a devolver dado parcial (caso Tuki Pet) | Feature | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-05 / D-06 / D-08 | — | Margem deriva do faturamento efetivo em **mês cheio**; sem faturamento em nenhuma fonte, margem fica ausente **com sinalização** | Unit | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-09 | V4 | Mês com linha `origem='consolidar_mes'` fica **read-only**; mês sem essa linha (mesmo antigo) permanece editável | Feature | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-03 | — | Snapshot gravado por `consolidar_mes` carrega o sinal de origem manual quando aplicável | Feature | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-04 | — | Linha da empresa com valor manual exibe o selo, **sem** nome de quem lançou | Feature (payload Inertia) | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-11 | — | Comando de relatório de impacto **não grava nada** (read-only comprovado) | Feature | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | D-12 | — | Edição grava em `activity_log` com autor + timestamp + valor anterior | Unit/Feature | `artisan test --filter="Phase136"` | ❌ W0 | ⬜ pending |
| TBD | TBD | TBD | Gate de hash (Fase 119) | — | `assertHashDesempenhoScoreServiceIntocado()` verde nos **5** arquivos após rotação da constante | Feature | `artisan test --filter="Phase119"` | ✅ existe | ⬜ pending |
| TBD | TBD | TBD | Bump de cache v19→v20 | — | Nenhum teste com chave hardcoded quebra (4 arquivos reais — ver divergência no RESEARCH) | Feature | `artisan test --testsuite=Feature` | ✅ existe | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase136/FinancialSourceResolverTest.php` — D-10, incluindo o caso **novo** (carteira mista **sem** `cust_id`) que `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php` não cobre hoje. Molde: `montarCenarioMisto()` em `CompanyScoreServiceFonteTest.php:123-136` (que sempre seta `adman_account_id` válido — a variante nova é sem `cust_id`).
- [ ] `tests/Feature/Phase136/MetricaManualLancamentoTest.php` — D-01 / D-02 / D-07 / D-08 (override + independência de eixos + cascata de base).
- [ ] `tests/Feature/Phase136/MetricaManualTravaConsolidacaoTest.php` — D-09 (read-only pós-consolidação), reaproveitando o padrão já testado de `CompanyScoreSnapshotWriter`.
- [ ] `tests/Feature/Phase136/RelatorioImpactoDesempateTest.php` — D-11 (comando read-only). Molde: `VerificarConsolidacaoDesempenho` (D-122-10, read-only comprovado).
- [ ] **Nenhuma factory nova.** Seguir o padrão já estabelecido: helpers da trait `CriaCenarioResponsaveis` (`tests/Feature/V16/CriaCenarioResponsaveis.php` — `criarServico()` / `criarContrato()` / `inserirPivot()`) + `Model::create()` direto. Não existem `AdmanMetricFactory`, `ShopeeMetricFactory` nem `DesempenhoCompanyScoreSnapshotFactory` — e isso é convenção do projeto, não lacuna.

---

## Manual-Only Verifications

| Comportamento | Decisão | Por que manual | Instruções |
|----------|-------------|------------|-------------------|
| Grade admin empresa × mês — edição em lote, estados `auto`/`manual`, sinalização de divergência (D-02) | D-01/D-02/D-07 | Interação de UI em lote; não há harness de browser no projeto | Abrir a rota nova como usuário `role:admin`, alternar um eixo para `manual` numa competência **não** consolidada, salvar, recarregar e conferir persistência + selo |
| Selo discreto em `/performance/{user}` (D-04) | D-04 | Verificação visual do ícone/tooltip; o **payload** é testável, o render não | Abrir `/performance/{user}?mes=YYYY-MM` com empresa de valor manual; conferir ícone + tooltip "valor lançado manualmente" e **ausência** do nome do autor |
| Efeito do CMV manual na nota de carteira só-Shopee (teto de 3,33) | Contexto/§0.00 | Depende de dado real de carteira; assert numérico fixo viraria teste frágil | Conferir por **reconsulta ao banco**, nunca por stdout (learnings §4) |
| Gate FIXMARG-03 (cobertura ≥ 0,7) após D-10 mover `margem_amostra.legado.n_elegivel` | Consequência medida | O veredito é o **exit code**, não o stdout | `C:\xampp\php\php.exe artisan desempenho:verificar-consolidacao --mes=YYYY-MM --json` e ler o **exit code** |
| Migration na MariaDB de produção (índice > 64 chars → erro 1059) | learnings §6 | SQLite dos testes não pega o erro | Rodar a migration contra MariaDB antes do deploy; conferir que a tabela **e** o índice existem (erro 1059 deixa a migration `Pending` com a tabela já criada) |

---

## Validation Sign-Off

- [ ] Toda task tem verificação `<automated>` ou dependência de Wave 0
- [ ] Continuidade de amostragem: sem 3 tasks consecutivas sem verificação automatizada
- [ ] Wave 0 cobre todas as referências MISSING
- [ ] Sem flags de watch-mode
- [ ] Feedback latency < 180s
- [ ] Baseline de 9 falhas pré-existentes reconfirmada antes de investigar qualquer "regressão"
- [ ] `nyquist_compliant: true` no frontmatter

**Approval:** pending
