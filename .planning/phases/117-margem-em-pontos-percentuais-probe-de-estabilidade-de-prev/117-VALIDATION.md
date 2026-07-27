---
phase: 117
slug: margem-em-pontos-percentuais-probe-de-estabilidade-de-prev
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-27
---

# Phase 117 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `117-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=AdmanMetricDiffServiceTest` |
| **Full suite command** | `php artisan test --filter=AdmanMetricDiff && php artisan test --filter=ShopeeMetricDiff && php artisan test --filter=ProbeMargemPrev` |
| **Estimated runtime** | ~15-25 s (quick) · ~60 s (domínio completo) |

Todos os testes usam `Http::fake()` — **nenhum teste chama a Adman real**. O probe é o único artefato que toca a API de verdade, e só quando executado manualmente na VPS.

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=AdmanMetricDiffServiceTest`
- **After every plan wave:** suíte completa do domínio (comando acima)
- **Before `/gsd:verify-work`:** suíte completa verde
- **Max feedback latency:** ~25 s

---

## Per-Task Verification Map

IDs de task a serem preenchidos pelo planner. O mapeamento requirement → comportamento → comando já está fechado:

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| _(planner)_ | 01 | 1 | MPP-01 | — | N/A | feature | `php artisan test --filter=test_e_shape_e_quality_completos` | ⚠️ existe, **precisa ser atualizado** | ⬜ pending |
| _(planner)_ | 01 | 1 | MPP-02 | — | N/A | feature | `php artisan test --filter=AdmanMetricDiffServiceTest` | ❌ W0 — cenário novo | ⬜ pending |
| _(planner)_ | 01 | 1 | MPP-03 | — | N/A | feature | `php artisan test --filter=AdmanMetricDiffServiceTest` | ❌ W0 — cenário novo (cache pré-populado em `v5`) | ⬜ pending |
| _(planner)_ | 01 | 1 | MPP-06 | — | N/A | feature | `php artisan test --filter=AdmanMetricDiffServiceTest` | ❌ W0 — cenário novo sobre a fixture âncora existente | ⬜ pending |
| _(planner)_ | 01 | 1 | MPP-05 | — | N/A | feature | `php artisan test --filter=ShopeeMetricDiff` | ❌ W0 — **arquivo de teste não existe**, criar | ⬜ pending |
| _(planner)_ | 02 | 2 | MPP-04 | T-117-01 | Nenhum token/credencial Adman em log ou na tabela de leituras | feature | `php artisan test --filter=ProbeMargemPrev` | ❌ W0 — comando + model + migration + teste inteiros novos | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

**Atenção de escopo (MPP-01):** `tests/Feature/V18/AdmanMetricDiffServiceTest.php:365-368` faz `array_keys()` **estrito** sobre o shape, asserindo exatamente `['value', 'diff_pct', 'diff_source']`. É o **único** lugar do código que faz essa asserção. Ele vai ficar vermelho por design ao adicionar `prev_value`/`diff_pp` — atualizar a asserção **não é** mascarar regressão, é acompanhar a mudança de contrato. Qualquer outro teste que fique vermelho **é** regressão e deve ser investigado, não ajustado.

---

## Wave 0 Requirements

- [ ] `tests/Feature/V21/AdmanMetricDiffPrevValueTest.php` (ou cenários novos dentro de `V18/AdmanMetricDiffServiceTest.php` — discrição do planner) — cobre MPP-02, MPP-03, MPP-06
- [ ] `tests/Feature/V21/ShopeeMetricDiffDiffPpTest.php` — cobre MPP-05 (**não existe suíte de `ShopeeMetricDiffService` hoje**; `margemNula()` em `app/Services/Metrics/ShopeeMetricDiffService.php:187` é o ponto de mudança)
- [ ] `tests/Feature/V21/ProbeMargemPrevStabilityCommandTest.php` — cobre a MECÂNICA de MPP-04 com `Http::fake()`
- [ ] Migration + model da tabela de leituras do probe (dependência de W0 para o teste de MPP-04 existir)
- [ ] Atualizar `tests/Feature/V18/AdmanMetricDiffServiceTest.php:365-368` (asserção estrita de `array_keys`)

Framework já instalado — nenhuma instalação necessária.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| **Veredito de estabilidade do `prev`** | MPP-04 | É julgamento humano sobre dados de produção colhidos ao longo de 24-48h contra a API real. CI não pode nem deve emitir esse veredito. Os testes automatizados cobrem só a mecânica do comando. | 1. Deploy do comando na VPS. 2. Executar ≥5 leituras em 24-48h, **obrigatoriamente uma dentro de 11:00-12:00 BRT** (janela determinística de contenção — ver D-02 corrigido). 3. Rodar a agregação. 4. Apresentar o relatório ao usuário. 5. Usuário aprova ou reprova. |

### Sinal observável de aprovação / reprovação (MPP-04)

**PASSA** — para todas as empresas da amostra (carteiras do Luiz `user 3` e Danilo `user 15`, `financial_source = adman`, competência fechada):
- cobertura de `prev` não-nulo **≥ 80%** das leituras (D-03), **e**
- `nota_regua` **idêntica em todas** as leituras da mesma empresa (D-01, zero flip), **e**
- ao menos uma leitura registrada dentro de 11:00-12:00 BRT.

**REPROVA** — qualquer uma:
- alguma empresa com `nota_regua` diferente entre duas leituras quaisquer (mesmo que as duas notas pareçam plausíveis isoladamente), **ou**
- cobertura de `prev` não-nulo **< 80%**.

**SINAL AMBÍGUO — tratar como falha de instrumentação, não como sucesso:** se todas as leituras devolverem valores **idênticos bit-a-bit** (o mesmo float, não apenas a mesma nota da régua), isso é forte indício de que o probe está lendo cache em vez da Adman. O relatório final **deve** incluir essa verificação de sanidade e sinalizar o padrão explicitamente.
Esta é a armadilha central da fase: `AdmanMetricDiffService::compute()` cacheia por dia BRT com TTL de até 1440 min e ainda tem memo por request. Ver D-11b no CONTEXT — o probe usa `AdmanService::fetchAccountMetricsDetailedCached(..., forceRefresh: true)`.

---

## Validation Sign-Off

- [ ] Todas as tasks têm verify `<automated>` ou dependência de Wave 0
- [ ] Continuidade de amostragem: sem 3 tasks consecutivas sem verify automatizado
- [ ] Wave 0 cobre todas as referências MISSING
- [ ] Nenhuma flag de watch-mode
- [ ] Latência de feedback < 25 s
- [ ] Verificação de sanidade anti-cache presente no relatório do probe
- [ ] `nyquist_compliant: true` marcado no frontmatter

**Approval:** pending
