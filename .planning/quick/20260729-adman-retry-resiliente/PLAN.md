---
slug: adman-retry-resiliente
created: 2026-07-29
type: fix
origin: GATE MPP-04 reprovado (milestone v21.0, Fase 120)
---

# Leitura de margem da Adman resiliente a rate-limit

## Problema

O **GATE MPP-04** reprovou em 2026-07-29. Sob contenção real — a rajada de syncs entre 11:00 e 11:45 disputando a mesma API-key da Adman — a cobertura de `percentageMargin.prev` cai de **92,5% para 64,2%**, com **15 de 53 empresas** falhando com HTTP 429.

O retry existente em `AdmanService::fetchAccountMetricsDetailedCached()` (adicionado em 24/07) é curto demais:

| Hoje | |
|---|---|
| Tentativas | 4 |
| Backoff | 0,8s · 1,6s · 2,4s |
| Espera total | **~4,8 s** |
| Jitter | não |
| Honra `Retry-After` | não |

A janela de rate-limit dura **minutos**; o retry desiste antes de 5 segundos. E sem jitter, processos concorrentes retentam em lockstep, reforçando a congestão que causou o 429.

Evidência: 93 ocorrências de 429 nos logs de hoje, contra 2 timeouts — é rate-limit, não instabilidade de rede.

## Escopo

**Alterar:**
- `AdmanService::fetchAccountMetricsDetailedCached()` — o loop de retry
- `AdmanService::fetchAccountMetrics()` — captura do header `Retry-After` (acréscimo mínimo; sem ele não há como honrar o header, já que o método lança exceção genérica e descarta a resposta)

**NÃO alterar:** `fetchPerformance` (tem retry próprio, fora do escopo), os outros 5 caminhos HTTP, nem `AdmanMetricDiffService`.

## Desenho

1. **Backoff exponencial com jitter**, janela total de ~60-90s:
   `base = 2^n segundos`, com jitter aleatório de ±40%, teto por tentativa de ~30s.
   Sequência aproximada: 2s · 4s · 8s · 16s · 30s → ~60s de janela, 6 tentativas.

2. **`Retry-After` tem precedência.** Quando a Adman envia o header, esperar exatamente o que ela pede (com teto de segurança de 60s por espera) em vez de adivinhar. Aceitar tanto o formato em segundos quanto data HTTP.

3. **Jitter é obrigatório.** É ele que dessincroniza os processos concorrentes; sem jitter, aumentar o backoff só faz todos baterem juntos mais tarde.

4. **Preservar o comportamento de sucesso.** Nada muda quando a chamada passa de primeira — que é o caso em condição folgada (0 falhas em 212 leituras).

## Critério de aceite

- Testes com `Http::fake()` provando: retry em 429, respeito ao `Retry-After`, jitter dentro da faixa, e desistência após esgotar as tentativas
- Nenhuma chamada real à Adman em teste
- `--filter=Adman` sem regressão nova
- **Verificação real:** nova rodada `contencao_11h` entre 11:00 e 12:00 BRT e novo `--relatorio`; a cobertura da **pior rodada** precisa passar de 80% para o gate mudar de veredito

## Observação sobre o critério

O gate mede **cobertura da pior rodada**, não média — corrigido hoje em `48aa1b30`/`fe0f6e91` depois de ter aprovado indevidamente por diluição. Então a remedição precisa ser sob contenção real; rodada folgada não diz nada sobre este fix.
