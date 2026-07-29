---
slug: adman-retry-resiliente
status: complete
created: 2026-07-29
completed: 2026-07-29
---

# Leitura de margem da Adman resiliente a rate-limit — SUMMARY

## Resultado

**O fix funcionou.** Medido sob contenção real nos dois lados:

| | Pré-fix (11:02) | Pós-fix (12:17) |
|---|---|---|
| Empresas lidas | 53 | 53 |
| **Falhas de HTTP** | **15 (28,3%)** | **0** |
| Sem `prev` | 19 | 4 |
| **Cobertura de `prev`** | **64,2%** | **92,5%** |

A leitura de 12:17 rodou com **três jobs de produção em paralelo** (`SyncTodasVendasAdmanJob` e syncs), ou seja, contenção genuína. A cobertura voltou ao patamar de condição folgada e as falhas zeraram.

## O que mudou

`AdmanService::fetchAccountMetricsDetailedCached()`:

| | Antes | Depois |
|---|---|---|
| Tentativas | 4 | 6 |
| Backoff | 0,8s · 1,6s · 2,4s | exponencial `2^n`, teto 30s |
| Janela total | **~4,8 s** | **~60 s** |
| Jitter | não | **±40%** |
| `Retry-After` | ignorado | honrado, com precedência |

Também em `AdmanService::fetchAccountMetrics()`: captura do header `Retry-After` no 429 antes de lançar a exceção — sem isso não haveria como honrá-lo, já que o método descartava a resposta.

**O jitter é a parte não-negociável.** Sem ele, os processos concorrentes que causaram o 429 acordam em lockstep e recriam a congestão; aumentar o backoff sem dessincronizar só adia o problema.

## Decisão de teste

`dormirEntreTentativas()` é no-op sob `runningUnitTests()`. A janela de 60s somava **128 segundos** de sono real em dois testes. Não se perde cobertura: a lógica de TEMPO (backoff, teto, jitter) é verificada à parte, direto em `esperaDoRetry()` via Reflection, em milissegundos. Os testes que passam pelo loop verificam **contagem de tentativas**, não duração.

## Verificação

- **7/7 verdes** em `tests/Feature/Quick/AdmanRetryResilienteTest.php` (142 asserções), tudo com `Http::fake()` + `preventStrayRequests()`
- `--filter=Adman`: **135 passed, 1 failed** — a falha é `var margem usa adman como fonte canonica`, já documentada na baseline pré-existente em `.planning/phases/117-.../deferred-items.md:25`. Conferido antes de afirmar.
- Escopo respeitado: `fetchPerformance` e os outros 5 caminhos HTTP intocados.

## Commits

- `b27dcec5` — fix(quick): leitura de margem da Adman resiliente a rate-limit

Deployado em 2026-07-29. Verificado na VPS: `DETALHADO_TENTATIVAS = 6` presente, HEAD `b27dcec`.

## Nota do deploy

O output acusou `ERROR (abnormal termination)` nos workers. Investigado: estavam em `STOPPING`, não mortos — o supervisor pediu parada enquanto rodavam um `SyncTodasVendasAdmanJob` longo. Três processos vivos, **fila com 0 jobs pendentes**, e `--max-time=3600` os recicla sozinhos já com o código novo. Cosmético.

## Pendência — o gate ainda não mudou de veredito

A leitura de 12:17 está etiquetada `manual`, **não** `contencao_11h`, de propósito: ela prova que o fix funciona, mas o gate exige a etiqueta da janela determinística e etiquetar 12:17 como 11h seria falsificar a evidência.

**Para fechar o GATE MPP-04:** entre 11:00 e 12:00 BRT,

```
php artisan adman:probe-margem-prev --mes=2026-06 --janela=contencao_11h
php artisan adman:probe-margem-prev --relatorio --mes=2026-06
```

conferindo o veredito por reconsulta a `adman_probe_margem_prev_vereditos`, nunca por stdout.

O gate mede **cobertura da pior rodada**. A rodada reprovada de 11:02 (64,2%) **continua no histórico** e continuará sendo a pior — então o veredito só vira `aprovado` se o critério passar a considerar apenas leituras posteriores ao fix, ou se a rodada antiga for explicitamente marcada como pré-fix. **Isso precisa ser decidido antes de rodar o relatório amanhã.**
