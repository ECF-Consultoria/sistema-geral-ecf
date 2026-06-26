---
phase: 38
slug: painel-do-publicador-evoluir-meu-painel-para-formato-score-r
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-26
---

# Phase 38 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `38-RESEARCH.md` (seção "Validação Architecture").

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| **Config file** | `phpunit.xml` (SQLite em memória) |
| **Quick run command** | `php artisan test --filter=PublicadorScoreServiceTest` |
| **Full suite command** | `php artisan test --filter=Phase38` |
| **Estimated runtime** | ~15 s (subset) / suite completa conforme projeto |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=PublicadorScoreServiceTest`
- **After every plan wave:** Run `php artisan test --filter=Phase38`
- **Before `/gsd:verify-work`:** Full suite must be green (`php artisan test`)
- **Max feedback latency:** ~15 s

---

## Per-Task Verification Map

> Task IDs (`38-NN-NN`) serão atribuídos pelo planner. Mapeamento por requisito de validação (PUB-*).

| Req | Behavior | Test Type | Automated Command | File Exists | Status |
|-----|----------|-----------|-------------------|-------------|--------|
| PUB-01 | Score 0–100 calculado corretamente para publicador com dados completos | unit | `php artisan test --filter=PublicadorScoreServiceTest` | ❌ W0 | ⬜ pending |
| PUB-02 | Eixo com `feito=0` → `valor=null` e peso redistribuído (espelha `scoreFinal()`) | unit | `php artisan test --filter=PublicadorScoreServiceTest::test_eixo_null_redistribui_peso` | ❌ W0 | ⬜ pending |
| PUB-03 | Pontualidade = `null` quando publicador sem empresas responsáveis | unit | `php artisan test --filter=PublicadorScoreServiceTest::test_pontualidade_sem_empresas` | ❌ W0 | ⬜ pending |
| PUB-04 | `meuPainel()` passa `score_publicador`, `faturamento_mes`, `net_billing_timeseries`, `pontos[]` | feature | `php artisan test --filter=MeuPainelControllerTest` | ❌ W0 | ⬜ pending |
| PUB-05 | `meuPainel()` com publicador sem publicações no mês → props vazias válidas (sem exceção) | feature | `php artisan test --filter=MeuPainelControllerTest::test_sem_publicacoes` | ❌ W0 | ⬜ pending |
| PUB-06 | `net_billing` null em todas as publicações → `faturamento_mes = 0` (sem divisão por zero) | feature | `php artisan test --filter=MeuPainelControllerTest::test_net_billing_null` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/PublicadorScoreServiceTest.php` — stubs para PUB-01, PUB-02, PUB-03
- [ ] `tests/Feature/Phase38Publicador/MeuPainelControllerTest.php` — stubs para PUB-04, PUB-05, PUB-06
- [ ] Factories sintéticas: `Publicacao::factory()` com `vendido`/`problema`/`net_billing`; `MlbEmpresa` com `skus_estagio*` + `responsavel_id`

*Padrão verificado: factories Eloquent / arrays simples (ex.: `tests/Unit/CalcularFaixaTest.php`). O `PublicadorScoreService` não injeta dependências externas (sem Adman/HTTP) — testável com SQLite em memória.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Render visual do RadialBar (score) + RadarChart (5 eixos) + LineChart (faturamento) sem quebra de layout em dark theme | PUB-04 | Render Recharts/React não coberto por teste de unidade PHP | Abrir `/mlb/meu-painel` logado como publicador; conferir score, radar de 5 eixos e evolução do faturamento renderizando; `npm run build` verde |
| Estado vazio (publicador sem dados) exibe placeholders, não erro | PUB-05 | Percepção visual do estado vazio | Logar como publicador recém-criado sem publicações; conferir cards/gráficos em estado vazio |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (2 arquivos de teste)
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
