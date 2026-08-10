---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 06
status: descartado
outcome: superseded-by-reversal
superseded_by: ["126-11"]
date: 2026-08-10
---

# Plano 126-06 — DESCARTADO pela reversão da D-01/D-02

**Este plano não foi concluído e não será.** Ele existe aqui para o `execute-phase` não tentar
executá-lo e para a próxima sessão entender o porquê sem reabrir a discussão.

O registro completo do que aconteceu está em **`126-06-CHECKPOINT.md`** — leia lá, não aqui.

## Por que foi descartado

O plano concentrava 3 gates humanos. No checkpoint, dois deles perderam o sentido e um só fechou
pela metade:

| Gate | Destino |
|---|---|
| Task 1 — inspeção visual do PDF renderizado por nós | **Perdeu o sentido.** O usuário apontou que o documento não se parece com o contrato real da ECF e decidiu usar o modelo da Clicksign (D-16). O PDF inspecionado deixou de ser o artefato que vai ao cliente. O gate equivalente renasce como Task 3 do **plano 126-11**, agora sobre o documento que a Clicksign devolve |
| Task 2 — as 2 confirmações jurídicas | **RESPONDIDO.** `companies.name` mistura razão social e nome fantasia (→ entrada da Fase 131); placeholder `A DEFINIR` mantido |
| Task 3 — gate #5, pontos NÃO MEDIDO e migration | **PARCIAL.** Ver abaixo |

## O que este plano entregou de fato, apesar de descartado

Ele pagou por si mesmo. A medição contra o sandbox real derrubou os dois pontos que o código
carregava como `NÃO MEDIDO`, e **os dois estavam errados**:

1. `adicionarSignatario()` mandava `communicate_by` — a API recusa com `400`. Quebraria **100% dos
   envelopes**, no primeiro signatário.
2. `cancelarEnvelope()` fazia `PATCH status=canceled` — `"canceled"` não existe no vocabulário. O
   certo é `DELETE` → `204`.

Ambos corrigidos em `d5256f3a`, com testes que travam o comportamento medido. A causa raiz virou
lição registrada em `CLICKSIGN-SANDBOX-EMPIRICO.md` §9.1: **forma de resposta da API não é contrato
de entrada** — a fixture foi modelada a partir da resposta, e o `Http::fake()` confirmava
alegremente o payload errado.

Mediu também, no mesmo esforço, a §9.4/§9.6 do empírico — a forma exata do payload de instanciação
de modelo, que é a base dos planos 126-07 a 126-12.

## O que ficou pendente e para onde foi

| Pendência | Para onde |
|---|---|
| Gate #5 — limite real de upload acima de 10 MB | Deixou de ser risco prático (contrato real ~180 KB, ~55× de folga). Anotado na §9.3 do empírico; `max_upload_bytes` segue palpite |
| `DELETE` em envelope já ativado (`running`) | Não medido. Anotado no docblock de `cancelarEnvelope()`; medir antes de a Fase 127 cancelar pós-ativação |
| Migration da fase no MariaDB de produção | **Continua pendente** — sem autorização do usuário. Anotada na `<verification>` do plano 126-12 para não se perder |
