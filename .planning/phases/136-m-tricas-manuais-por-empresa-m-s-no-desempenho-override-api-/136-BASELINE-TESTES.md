---
phase: 136
registrado_em: 2026-08-11
sha_head: f71f8aa9f4603c556908c6c7071a029dd6e937cf
comando: 'C:\xampp\php\php.exe artisan test --filter="CarteiraPeriodoDiffTest|DesempenhoPeriodoOficialTest|DesempenhoShopeeScoreTest|ConsolidarMesJanelaNpsTest|JanelaNpsBonusTest"'
total_failed: 9
total_passed: 18
---

# Baseline de falhas pré-existentes — Fase 136

Coletada **antes** de qualquer arquivo de aplicação desta fase existir, com o HEAD acima e
nenhuma mudança em stash. O objetivo é ter um número congelado para comparar contra: se a
suíte voltar a falhar exatamente esses 9 casos (ou menos) depois das Tasks 2/3, **não é
regressão desta fase**. É dívida antiga, coberta em detalhe em
`.planning/learnings/desempenho-bonificacao.md` §0.02.

## Resultado

| Suíte | Failed | Passed | Total |
|---|---|---|---|
| `Tests\Feature\DesempenhoShopeeScoreTest` | 3 | 5 | 8 |
| `Tests\Feature\V18\CarteiraPeriodoDiffTest` | 2 | 4 | 6 |
| `Tests\Feature\V18\ConsolidarMesJanelaNpsTest` | 2 | 0 | 2 |
| `Tests\Feature\V18\DesempenhoPeriodoOficialTest` | 1 | 5 | 6 |
| `Tests\Feature\V18\JanelaNpsBonusTest` | 1 | 4 | 5 |
| **Total** | **9** | **18** | **27** |

Comando exato (o mesmo em todas as 5 suítes):

```
C:\xampp\php\php.exe artisan test --filter="CarteiraPeriodoDiffTest|DesempenhoPeriodoOficialTest|DesempenhoShopeeScoreTest|ConsolidarMesJanelaNpsTest|JanelaNpsBonusTest"
```

Saída bruta: `Tests: 9 failed, 18 passed (91 assertions)`, `Duration: 29.32s`.

**Bate exatamente com o valor esperado pelo RESEARCH (9 failed / 18 passed, medido em
2026-08-11)** — nenhuma divergência a registrar.

## Testes que falham (nomes completos)

- `DesempenhoShopeeScoreTest > so performance regressao zero margem pontos e nota identicos ao baseline`
- `DesempenhoShopeeScoreTest > misto ml shopee margem pontos blend ponderado`
- `DesempenhoShopeeScoreTest > invalidacao empresa shopee nao infla denominador do blend`
- `V18\CarteiraPeriodoDiffTest > margem variacao pct fallback calculado mes fechado`
- `V18\CarteiraPeriodoDiffTest > variacao margem mes em curso byte identico ao calculo manual`
- `V18\ConsolidarMesJanelaNpsTest > cron no ultimo dia do mes congela competencia m com nps de m mais 1`
- `V18\ConsolidarMesJanelaNpsTest > override mes continua funcionando e idempotente`
- `V18\DesempenhoPeriodoOficialTest > var margem pct cai no calculated fallback quando diff ausente`
- `V18\JanelaNpsBonusTest > competencia fechada le nps de m mais 1`

Todas cobram a variação **relativa** de margem via `calculated_fallback` local (revogado pelo
hotfix de 2026-07-24) ou o comportamento de janela do NPS/consolidação anterior à Fase 118/122
— nada relacionado ao desempate de fonte financeira que esta fase corrige (D-10).

## Regra de comparação

Qualquer falha listada acima **não é regressão desta fase**. A comparação de "regressão" se
faz sempre contra este número (9 failed), nunca contra zero. Se, depois da Task 3, a mesma
suíte reportar **mais** de 9 falhas, ou falhar em teste que não está nesta lista, isso é
regressão real de D-10 e precisa ser investigado antes de fechar o plano.

Este documento é o "antes" — **não editar** depois de criado.
