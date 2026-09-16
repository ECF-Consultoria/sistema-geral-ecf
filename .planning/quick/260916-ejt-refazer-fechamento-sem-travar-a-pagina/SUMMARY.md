---
quick_id: 260916-ejt
slug: refazer-fechamento-sem-travar-a-pagina
date: 2026-09-16
status: complete
commits:
  - d84180a0
  - 6a2e3c67
---

# Refazer o fechamento para de quebrar a página

> O executor não conseguiu gravar este arquivo (a ferramenta recusou `.md` em subagente). O
> orquestrador gravou a partir do relatório final, depois de conferir os dois commits e rodar o gate
> de novo (484 passando, exit 0).

O clique em "Refazer fechamento" rodava a consolidação **dentro da requisição web**. Ela busca o
faturamento de ~200 empresas na Adman e estourava o `memory_limit` de 512M do PHP do site — fatal em
`Http/Client/Response.php`, 500 na tela, **nada gravado**, e a pessoa seguia vendo os números antigos
achando que o cálculo estava errado (produção, 16/09/2026, competência de agosto).

Agora o clique só **encomenda** o trabalho: o cálculo roda na fila, onde não há esse limite, e a tela
acompanha até o fim. Nada do que o fechamento **calcula** foi tocado — mudou só **onde** o cálculo
roda e **como** é disparado.

## O que mudou

**T1 — `app/Jobs/ConsolidarMesFechamentoJob.php` (novo)**
- chama **o mesmo** `fechamento:consolidar-mes --mes= --motivo= --por=` do cron e do terminal; nenhuma
  linha da consolidação foi reimplementada
- `tries = 1` de propósito: refazer sozinho duas vezes é pior que falhar — cada execução regrava a
  competência e escreve outra linha em `fechamento_reconsolidacoes`; `timeout = 1800`
- exit ≠ 0 → andamento `failed` com as 3 últimas linhas da saída (máx. 500 caracteres); texto inteiro
  só no `Log::error`; saída vazia ainda rende mensagem legível (falha muda deixava a tela girando)
- `failed(\Throwable $e)` grava `failed` — **antes deste quick a falha sumia**

**T2 — `app/Http/Controllers/FechamentoController.php`**
- `refazerCompetencia()` troca `Artisan::call()` por `ConsolidarMesFechamentoJob::dispatch()`; guard de
  admin, validação de `mes`/`motivo` (mín. 10) e os 422 seguem idênticos
- resposta **202** com `{ message, status: 'running' }`; a mensagem deixou de dizer "refeito com
  sucesso"
- `set_time_limit(0)` saiu do refazer (o que estourava era memória, não tempo); segue no
  `fecharCompetencia()`, intocado
- `statusRefazerCompetencia()` (novo) devolve `{ status, started_at, completed_at, error }`

**Rota nova** (mesmo grupo `role:admin`):
`GET /administrativo/financeiro/competencia/refazer/status?mes=AAAA-MM` →
`admin.financeiro.competencia.refazer.status`

**T3 — `resources/js/Pages/Admin/Financeiro.jsx`**
- no 202 fecha o diálogo e limpa o motivo (travas do incidente 260903-la4 preservadas) mas **não
  declara vitória**: entra em "refazendo"
- consulta a cada 5 s; `ready` → sucesso + `router.reload()`; `failed` → erro e "o registro anterior
  continua valendo"
- **uma consulta ao montar**: quem recarrega a página no meio volta a acompanhar em vez de clicar de
  novo; `clearInterval` no unmount; botão desabilitado com "Refazendo..." enquanto corre

## Chave de andamento e trava anti-duplo-disparo

Chave **`fechamento:refazer:{AAAA-MM}`** (`ConsolidarMesFechamentoJob::statusCacheKeyFor($mes)`), uma
por mês, TTL 2h. Payload `{ status, mes, started_at, completed_at, error }`, `status` ∈
`running`/`ready`/`failed`. Chave ausente = **`idle`**, nunca erro.

A trava vive no controller: andamento `running` naquele mês → **409** ("Este fechamento já está sendo
refeito — aguarde terminar.") e **nada enfileirado**. O `running` é gravado **já na entrega**, não
quando o worker pega o job — entre um e outro cabem vários cliques. Meses diferentes não se bloqueiam.
Teste trava que controller e tela leem exatamente a mesma chave do job.

## Testes

29 casos novos em `tests/Feature/Quick260916/`:
- `RefazerFechamentoNaFilaTest` (13) — 202 + job na fila com mês/motivo/autor certos e nada
  consolidado na hora (conferido por reconsulta ao banco); 403; 422 de motivo curto e mês inválido;
  409 com **um único** job; outro mês não bloqueia; `idle`/`running`/`failed`; rota registrada
- `ConsolidarMesFechamentoJobTest` (7) — `running` antes e `ready` depois; mesmo comando do cron;
  exit ≠ 0 → `failed` com o registro anterior intacto; falha muda ainda é legível; `failed()`;
  `tries = 1`; chave por mês
- `TelaAcompanhaRefazerTest` (9) — trava de texto do `.jsx` (o projeto não tem runner de JS)

A consolidação real nunca roda nos testes: `Queue::fake()` no controller, `Artisan::shouldReceive('call')`
no job.

**Alterado:** `Phase137CompetenciaEndpointTest` — o teste do refazer passou de `assertOk()` para
`assertStatus(202)` (renomeado `..._devolve_202`). O efeito segue conferido por reconsulta ao banco;
nos testes a fila é `sync`.

## Gate `--filter="Phase137|Phase140|Phase142|Phase143|Quick260915|Quick260916"`

| momento | exit | testes | asserções | falhas |
|---|---|---|---|---|
| antes de editar | 0 | 455 | 2106 | 0 |
| ao final (executor) | 0 | 484 | 2209 | 0 |
| **reconferido pelo orquestrador** | **0** | **484** | **2209** | **0** |

`npm run build` exit 0 (1m50s); classes novas conferidas com `grep -F` no CSS compilado
(`cursor-wait`, `animate-spin`, `w-64`, `backdrop-blur-md`, `bg-ecf-card/95`, `bg-red-950/90`,
`text-white/50`, `bg-white/[0.03]`), e a rota de andamento e o texto "Refazendo..." no chunk
`Financeiro-*.js`.

## Desvio do plano

Um só, de organização: o teste de copy da tela ficou no commit do T3, não no do backend — ele lê o
`.jsx` e ficaria vermelho no commit anterior. Os dois commits ficam verdes isoladamente.

## Pendente

**Sem deploy.** Para valer em produção falta deployar **e reiniciar os workers `ecf-worker:00/01`**,
que precisam enxergar a classe nova do job. Enquanto isso, refazer uma competência continua sendo
feito pela linha de comando (foi assim que agosto foi refeito em 16/09).
