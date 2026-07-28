# Phase 118: NPS por empresa - Discussion Log

> **Trilha de auditoria apenas.** Decisões estão em `118-CONTEXT.md`.

**Date:** 2026-07-28
**Areas discussed:** todas as 4 oferecidas

---

## Q1 — Qual dimensão vira o NPS da `nota_empresa`

| Opção | Selecionada |
|---|---|
| A nota do papel do profissional | ✓ |
| A nota da dimensão `empresa` | |
| Média das três dimensões | |

**Notas:** preserva a semântica do bônus atual (`nps_score_assignments` já é por papel). A milestone muda granularidade, não de quem é o NPS. Consequência aceita: `nota_empresa` difere entre estrategista e analista da mesma empresa.

---

## Q2 — Profissional com dois papéis na mesma empresa

| Opção | Selecionada |
|---|---|
| Média dos papéis que ele exerce | ✓ |
| A menor das duas | |
| Manter as duas como hoje | |

**Notas:** hoje as duas notas entram separadas e fazem a empresa pesar dobrado na carteira. Uma empresa, um peso.

---

## Q3 — Empresa com mais de um survey na competência

| Opção | Selecionada |
|---|---|
| Média simples de todos os surveys | |
| Só o survey do serviço do vínculo | ✓ |
| O mais recente da competência | |

**Notas:** eu havia sinalizado no preview que essa opção falha para o responsável consolidado (`servico_id NULL`) e recriaria o bug de `project_nps_assignment_consolidado_gap`. Levei o ponto de volta ao usuário como pergunta dedicada, e ele escolheu o fallback de **média de todos os surveys da empresa** para esse caso. A decisão ficou completa: precisão por serviço quando há vínculo específico, média quando é consolidado.

---

## Q4 — Empresa sem NENHUM NPS na competência

| Opção | Selecionada |
|---|---|
| NPS `null`, sai do cálculo | |
| Empresa some do `empresas_score` | |
| Trata como 1 (piso) | ✓ |

**Notas:** esta opção **reverte a D3 da Fase 116**, que era decisão travada e estava marcada no próprio CONTEXT da 116 como *"o invariante mais importante da fase"*. Levantei a contradição explicitamente, citei o texto original, apontei o alcance retroativo e ofereci três caminhos (manter, reverter, ou meio-termo condicionado a "deveria ter disparo"). **O usuário reafirmou a reversão.** Registrada como D-04 no CONTEXT, com o histórico da decisão original preservado.

Esclarecimento apurado durante a discussão: as 245 imputações aplicadas em produção hoje **não** ficam inválidas — elas cobrem *disparado e não respondido*, caso distinto e inalterado. A reversão atinge apenas *nunca disparado*, que `nps_imputed_assignments` não modela.

---

## Perguntas de acompanhamento (rodada 2)

Feitas porque duas respostas da rodada 1 tinham consequência não resolvida:

| Pergunta | Resposta |
|---|---|
| Confirma reverter a D3 da Fase 116? | **Sim — reverter** |
| Fallback do responsável consolidado (`servico_id NULL`) | **Média de todos os surveys da empresa** |

---

## Claude's Discretion

- Assinatura e localização de `NpsPorEmpresaService` (D-05)
- Forma exata do retorno, desde que permita auditar origem por ramo/dimensão/survey
- Confirmação de que a fase é aditiva e não altera consumidores (D-06)

## Risco registrado (não é decisão — é consequência a resolver no plano)

A D-04 faz o bônus e a área de NPS divergirem para empresa sem disparo. A Fase 116 entregou um teste de coerência entre call-sites (116-08) e NPSE-06 exige mantê-lo verde. O plano precisa decidir explicitamente se o teste tolera a divergência (recomendado) ou se a área de NPS também muda (o que sai do escopo desta fase).

## Deferred Ideas

- Alinhar a área de NPS ao fallback da D-04 — fase própria
- Materializar "sem disparo" na tabela de imputação — deliberadamente fora
- Corrigir o gap do consolidado na origem do disparo — outro escopo
