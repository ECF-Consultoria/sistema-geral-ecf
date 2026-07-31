---
phase: 121
slug: compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-07-31
---

# Phase 121 — Validation Strategy

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x, SQLite `:memory:` |
| **Quick run** | `C:\xampp\php\php.exe artisan test --filter=Phase121` |
| **Regressão** | `--filter=Desempenho` · `--filter=Phase120` |
| **Baseline** | **14 falhas pré-existentes** em `--filter=Desempenho` |

⚠️ Nunca `artisan test` sem `--filter`. PHP não está no PATH do bash. MySQL local está fora — testes rodam em SQLite, sem impacto.

---

## Requirements → Test Map

| Req | Comportamento | Arquivo |
|---|---|---|
| ROLL-01 | Comando produz por profissional `nota_antiga`, `nota_nova`, `delta`, contadores e `maior_causa_delta`, **persistidos e reconsultáveis** | ❌ W0 |
| ROLL-02 | As 7 amostras de risco identificáveis programaticamente — 6 filtros + 1 **checagem de ausência** (invalidada) | ❌ W0 |
| ROLL-03 | Histograma de `margem_var_pp` **deduplicado por `company_id`**, só elegíveis a financeiro, 3 competências | ❌ W0 |

---

## Gate nº 1 — a comparação tem de ser justa

O valor inteiro desta fase depende de o delta refletir **mudança de fórmula**, não ruído de API.

**Provar:**
1. `compute()` é chamado **uma única vez** por profissional (D-01) — spy/dublê contando, não contagem de HTTP.
2. A releitura do dispatcher para o `diff_pct` da margem acontece **no mesmo loop, por empresa** (interleaved), nunca numa segunda passada.

O item 2 é o que impede o ruído de voltar pela porta dos fundos: leitura `partial` tem TTL de 10 min, então uma passada tardia leria a Adman de novo.

---

## Gate nº 2 — a decomposição não pode inventar precisão

As três parcelas **não somam** o delta total, porque os efeitos interagem.

**Provar:** o relatório reporta o resíduo explicitamente. Um teste com números conhecidos onde parcelas + resíduo = delta, e o resíduo aparece no output.

Esconder o resíduo seria pior que não decompor.

---

## Gate nº 3 — o histograma mede a população certa

**Provar:**
- só empresas com fonte financeira (proxy de `financial_metrics_eligible`)
- **dedupe por `company_id`** — a mesma empresa em duas carteiras não pode contar duas vezes; `margem_var_pp` não muda por quem avalia
- 3 competências, para distinguir "a régua comprime" de "o mês foi atípico"

É este histograma que responde se a **D2 da milestone** (régua reusada como pp) comprime a distribuição na faixa 3-4.

---

## Gate nº 4 — a mudança no shadow é aditiva (D-05)

Esta fase acrescenta `nota_final_por_empresa` e `score_status_por_empresa` ao payload do shadow.

**Provar:** o **teste dourado da Fase 120** (`PayloadBaselineFlagOffTest`) continua verde sem nenhum valor congelado mudar. Com flag e shadow desligados, as chaves novas nem existem.

---

## Wave 0 Requirements

Convenção: `tests/Feature/Phase121/`.

- [ ] Suíte do comando cobrindo ROLL-01..03
- [ ] Teste da chamada única + interleaving (gate 1)
- [ ] Teste do resíduo da decomposição (gate 2)
- [ ] Teste de dedupe e escopo do histograma (gate 3)
- [ ] Migration + models das 2 tabelas insert-only
- [ ] `PayloadBaselineFlagOffTest` verde após a mudança do shadow (gate 4)

---

## Manual-Only Verifications

| Comportamento | Por que manual |
|---|---|
| **A decisão do gate do delta** | D-04: sem limiar automático. O comando informa; o usuário decide olhando o relatório. O SUMMARY registra a decisão e o número em que se baseou. |

⚠️ Testes verdes **não** liberam ligar a flag. Isso exige o GATE MPP-04 aprovado **e** esta decisão.

---

## Sinal de aprovação / reprovação

**PASSA:** `--filter=Phase121` verde · teste dourado da 120 intacto · `--filter=Desempenho` dentro da baseline de 14 · uma chamada de `compute()` por profissional · resíduo visível no relatório · histograma deduplicado.

**REPROVA:** duas chamadas de `compute()` · releitura em segunda passada · resíduo escondido · histograma contando empresa duplicada · qualquer valor congelado do teste dourado mudando.

---

## Validation Sign-Off

- [x] Gate de comparação justa (chamada única + interleaving)
- [x] Gate do resíduo explícito
- [x] Gate de população do histograma
- [x] Gate de aditividade do shadow
- [x] `nyquist_compliant: true`

**Approval:** approved 2026-07-31
