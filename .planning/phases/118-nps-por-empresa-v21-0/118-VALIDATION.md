---
phase: 118
slug: nps-por-empresa-v21-0
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-07-28
---

# Phase 118 — Validation Strategy

> Derivado de `118-RESEARCH.md` § Validation Architecture, com as correções verificadas no código.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x, atributo `#[Test]` (padrão de `NpsFloorRegressaoTest.php`) |
| **Config file** | `phpunit.xml` — SQLite in-memory, `RefreshDatabase` |
| **Quick run command** | `php artisan test --filter=Phase118` |
| **Full suite command** | `php artisan test --filter="Nps\|Desempenho\|Phase116\|Phase118"` |
| **Estimated runtime** | ~30-60 s |

⚠️ **Não rodar `php artisan test` sem filtro** — há travamento por chamada HTTP real não mockada em clusters não relacionados a esta fase, já documentado em `116-08-SUMMARY.md`.

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=Phase118`
- **After every plan wave:** suíte combinada acima
- **Before `/gsd:verify-work`:** combinada verde, ou falhas pré-existentes **nominalmente provadas** (mesmo padrão da 116-08 e da 117)

---

## Per-Task Verification Map

| Task ID | Plan | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|-------------|-----------|-------------------|-------------|--------|
| _(planner)_ | 01 | NPSE-01 | feature | `php artisan test --filter=Phase118` | ❌ W0 | ⬜ |
| _(planner)_ | 01 | NPSE-02 (ramos 1 e 3) | feature | `php artisan test --filter=Phase118` | ❌ W0 | ⬜ |
| _(planner)_ | 01 | NPSE-03 (3 casos de janela) | feature | `php artisan test --filter=Phase118` | ❌ W0 | ⬜ |
| _(planner)_ | 01 | NPSE-04 | feature | `php artisan test --filter=Phase118` | ❌ W0 | ⬜ |
| _(planner)_ | 01 | NPSE-05 + D-03 fallback consolidado | feature | `php artisan test --filter=Phase118` | ❌ W0 | ⬜ |
| _(planner)_ | 01 | NPSE-06 | feature | `php artisan test --filter=NpsFloorRegressaoTest` | ⚠️ existe, **acrescentar método** | ⬜ |

---

## Correções ao `118-RESEARCH.md` (verificadas no código)

1. **`NpsFloorRegressaoTest.php` já tem SETE métodos, não seis.** A pesquisa fala em "ganha o 7º método" — o 7º já existe (`test_cenario_espelho_sem_survey_preserva_sentinela_em_todos_consumidores`, linha 455). O que a NPSE-06 pede é o **8º**, cobrindo o call-site novo.

2. **As 7 asserções existentes NÃO podem ser tocadas.** Em particular a asserção 2 do teste 7 (`assertSame(0.0, invocarComputeNpsMedio(...))`) continua válida — ver a correção da D-04 no CONTEXT: o bônus já trata "nenhum NPS" como `0.0` → clamp `1.0` no nível do profissional, e esta fase é aditiva.

3. **Não unificar a régua de janela M+1** (C-01 do CONTEXT). A divergência `gte`/`gt` entre `computeNpsWindow()` e `NpsImputationService::materializar()` é deliberada e documentada. Reusar a de **leitura** (`gte`).

---

## Wave 0 Requirements

Convenção de diretório: `tests/Feature/Phase118/` (confirmada pelo precedente `tests/Feature/Phase117/`).

- [ ] Suíte nova em `tests/Feature/Phase118/` cobrindo NPSE-01 a NPSE-05 — organização em um ou vários arquivos fica a critério do planner
- [ ] Método novo (o **8º**) em `tests/Feature/Phase116/NpsFloorRegressaoTest.php` para NPSE-06, **sem alterar os 7 existentes**
- [ ] Fixtures reaproveitando `montarCenarioVazio()` e o cenário-base do `NpsFloorRegressaoTest`

Framework já instalado — nenhuma instalação necessária.

---

## Manual-Only Verifications

Nenhuma. Esta fase é um serviço de leitura puro, sem integração externa e sem gate humano — diferente da 117, que tinha o probe de 24-48h.

*Todos os comportamentos da fase têm verificação automatizada.*

---

## Sinal de aprovação / reprovação

**PASSA:**
- `php artisan test --filter=Phase118` verde
- `php artisan test --filter=NpsFloorRegressaoTest` verde com **8** métodos
- `--filter="Nps|Desempenho|Phase116"` sem regressão nova (comparar com a baseline de 14 falhas pré-existentes já documentada)
- `git diff --name-only` **não** inclui `app/Services/DesempenhoScoreService.php` — a fase é aditiva

**REPROVA:**
- qualquer uma das 7 asserções existentes do `NpsFloorRegressaoTest` alterada para passar
- `DesempenhoScoreService` modificado
- número de produção mudando (a fase não deve alterar nenhuma nota)

---

## Débito técnico aceito (plan-check 2026-07-28, warning não-bloqueante)

**A proteção contra divergência `gte`/`gt` é um teste, não uma extração.** A C-01 proíbe unificar as duas réguas, e o plano cria `NpsJanelaResolver` com `gte` **sem** refatorar `DesempenhoScoreService` para chamá-lo — o gate de aditividade da fase é mais forte que o ganho. A duplicação fica vigiada por `test_resolver_concorda_com_computeNpsWindow_nos_tres_casos`, que invoca o método original por reflection e compara com a implementação nova nos 3 casos de boundary.

O plan-checker confirmou que **o teste não é tautológico** — são duas implementações fisicamente separadas sendo comparadas, e uma divergência futura quebra o teste. Mas a proteção depende de esse teste continuar rodando sempre que qualquer um dos dois arquivos mudar.

**Follow-up para a Fase 119 ou 120:** quando o gate de aditividade não estiver mais em vigor, fazer `computeNpsWindow()` passar a chamar `NpsJanelaResolver` (a Opção B completa do `118-PATTERNS.md` §4), eliminando a duplicação. Registrado aqui porque o SUMMARY sozinho não é rastreável entre fases.

## Validation Sign-Off

- [x] Todas as tasks têm verify `<automated>` ou dependência de Wave 0
- [x] Continuidade de amostragem garantida
- [x] Wave 0 cobre todas as referências MISSING
- [x] Nenhuma flag de watch-mode
- [x] Gate de aditividade explícito (`DesempenhoScoreService` intocado)
- [x] `nyquist_compliant: true`

**Approval:** approved 2026-07-28
