# Phase 119: Score por empresa - Discussion Log

> **Trilha de auditoria apenas.** Decisões em `119-CONTEXT.md`.

**Date:** 2026-07-28
**Areas discussed:** 3 das 4 oferecidas (a 4ª delegada ao Claude)

---

## Seleção de áreas

| Opção | Selecionada |
|---|---|
| `nota_empresa` quando falta um componente | ✓ |
| Empresa Shopee conta como completa? | ✓ |
| Empresa sem fonte financeira nenhuma | ✓ |
| O que fazer com o blend da Fase 109 | — (delegada) |

---

## Q1 — `nota_empresa` quando falta um componente

| Opção | Selecionada |
|---|---|
| Reportar os dois números + status | ✓ |
| Só a estrita — null se faltar qualquer um | |
| Só a parcial — divide pelos presentes | |

**Notas:** a fase é aditiva e informativa; a política de denominador pertence à Fase 120, onde já está registrada como decisão aberta. O argumento decisivo contra "só a parcial": uma empresa com 2 componentes bons tiraria nota **maior** que uma completa — `(4,6+5)/2 = 4,80` contra `(4,6+5+4)/3 = 4,53` — sem nada sinalizando, num cálculo que paga bônus.

---

## Q2 — Empresa Shopee conta como completa?

| Opção | Selecionada |
|---|---|
| Completa, com `quality` marcando o placeholder | ✓ |
| Parcial — margem placeholder não é dado real | |

**Notas:** marcar parcial contradiria a trava da Fase 109 (*"profissional só-Shopee NÃO deve cair em blocked/partial por ausência de margem"*) e puniria duas vezes — o placeholder `1.0` já puxa a nota para baixo.

---

## Q3 — Empresa com NPS mas sem fonte financeira

| Opção | Selecionada |
|---|---|
| Fica listada, `nota_empresa` null, status `sem_fonte` | ✓ |
| Sai de `empresas_score` | |
| Entra com nota só de NPS | |

**Notas:** preserva a distinção entre "sem fonte financeira" e "fora da carteira", que a Fase 121 vai precisar para explicar deltas.

---

## Claude's Discretion

**Q4 — o blend `margemPontos()` da Fase 109.** Decisão: fica **intocado** nesta fase. Segue sendo o caminho vivo enquanto a flag da Fase 120 estiver desligada; o caminho novo simplesmente não o usa, porque no modelo por empresa a ponderação emerge da média das notas. Aposentadoria é decisão da Fase 120.

Também minha: assinatura e local do `CompanyScoreService` ficam a critério do planner.

---

## Risco registrado (consequência, não decisão)

Régua-da-média ≠ média-das-réguas. O docblock de `margemPontos()` declara como invariante testado em `DesempenhoShopeeScoreTest` que "só-performance devolve exatamente `reguaMargem($varMargemReal)` — regressão zero vs. pré-Fase 109". Esse invariante **não vale** no caminho novo.

Na Fase 119 não é problema (aditiva, caminho antigo intocado). Na **Fase 120** vira problema real quando a flag ligar — o plano da 120 terá de decidir se `DesempenhoShopeeScoreTest` ganha cenários para o modo flag-ligada ou se os invariantes são reescritos. Registrado para não ser descoberto na hora.

---

## Deferred Ideas

- Política de denominador — Fase 120
- Aposentar `margemPontos()` — Fase 120
- Reescrever invariantes do `DesempenhoShopeeScoreTest` — Fase 120
- Persistir a linha por empresa — Fase 122
- Exibir lista de empresas com nota — Fase 123
