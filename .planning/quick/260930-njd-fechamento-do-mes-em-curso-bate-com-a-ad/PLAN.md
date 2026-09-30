---
quick_id: 260930-njd
slug: fechamento-do-mes-em-curso-bate-com-a-adman
date: 2026-09-30
type: quick
status: pending
---

# O fechamento do mês em curso passa a bater com a Adman

## O defeito, medido em produção em 2026-09-30

O usuário conferiu setembro (mês ainda aberto) contra a Adman e achou divergência em quase toda
empresa. Medido com chamadas reais à API, janela **01/09–29/09**:

| empresa | nossa soma diária | Adman | |
|---|---|---|---|
| CARAIBAALUMINIO alumen | 598.416,82 | 625.592,99 | **+4,54%** |
| TOMELIN ARAMADOS | 150.458,00 | 145.937,85 | **−3,00%** |
| amostra de 12 empresas | 12.468.923,94 | 12.910.546,59 | **+441.622,65 (+3,5%)** |

Na amostra: Adman **maior** em 9, **menor** em 2, igual em 1.

**Causa provada, dia a dia (mesma empresa):**

| dia | guardado (e quando coletamos) | Adman hoje | |
|---|---|---|---|
| 01/09 | 24.770,86 (02/09 11:06) | 26.506,96 | +7,0% |
| 08/09 | 21.014,07 (09/09 11:20) | 21.876,93 | +4,1% |
| 22/09 | 27.568,88 (23/09 11:06) | 27.568,88 | 0,0% |
| 28/09 | 15.933,13 (29/09 11:07) | 17.904,08 | **+12,4%** |

**A Adman revisa dias já passados** (para cima e para baixo) depois da nossa coleta, e nós nunca
voltamos para reler. `adman:sync` grava D-1 uma vez e pronto.

**Por que só aparece no mês em curso:** `FechamentoRollupService::porEmpresa()` tem
`$usarApi = $faturamentoDaApi && $mes !== now()->format('Y-m')` — a API é proibida no mês corrente.
E `AdminController::fechamentoDadosPorEmpresaAoVivo()` (~795) **nunca passa `faturamentoDaApi`**, em
nenhuma das três chamadas. Quem usa a API é só o `fechamento:consolidar-mes`.
⚠️ **A cobrança do mês fechado não está errada** — ela já vem da API. O defeito é de conferência.

**Decisão do usuário (2026-09-30):** fazer as duas coisas — o fechamento do mês em curso passa a usar
o **total do período** da Adman, e a coleta diária passa a **reler os últimos 5 dias**.

---

## Limites medidos — respeitar

- `AdmanService::ADMAN_RATE_LIMIT_RPM = 10` → **10 chamadas por minuto**. `adman:sync` já faz fan-out
  com **7 s** de intervalo por empresa.
- **84 das 187 empresas ativas** têm conta Adman. As demais (sem conta, Shopee, ou conta Adman ≠ conta
  do Mercado Livre) **continuam na soma diária** — a regra de quem pode usar a API é
  `podeUsarApiDaAdman()` e **não se toca**.
- `/performance` devolve `items` + `summarizedData` do período pedido — **não existe série por dia**.
  Logo: um dia relido = uma chamada. 5 dias × 84 = ~420 chamadas (~42 min no ritmo atual).
- Aquecer o total do período = **1 chamada por empresa** (~84, ~9 min).
- A Adman é **D-1**: a janela vai do dia 1º até **ontem**, nunca até hoje.

---

## T1 — Mês em curso lê o total do período da Adman (do cache)

1. Em `FechamentoRollupService::porEmpresa()`, **liberar o mês corrente** do bloqueio atual, mas com uma
   diferença essencial: no mês corrente a leitura é **só do cache**
   (`AdmanService::getCachedGrossBillingsMany()`), **nunca** chamada ao vivo — 84 chamadas dentro de um
   carregamento de tela é exatamente como o `cache:clear` derrubou a produção em 30/07.
   - cache quente → `faturamento_fonte = 'api'`
   - cache frio ou API sem resposta → mantém a soma diária com
     `faturamento_fonte = 'soma_diaria_fallback'` (o estado já existe; **não inventar estado novo**)
2. `AdminController::fechamentoDadosPorEmpresaAoVivo()` passa `faturamentoDaApi` conforme a chave
   `fechamento_faturamento_da_api_ativo` (use `FechamentoFonteFaturamento`, que já encapsula a chave)
   **nas três** chamadas de `porEmpresa()` (atual, mês anterior e a do faturamento bruto, ~795/798/806)
   — recortar só uma compararia réguas diferentes entre os meses e inventaria evolução de faixa falsa,
   que é a disciplina já registrada em comentário ali.
3. **A tela tem de dizer de onde veio o número.** O `faturamento_fonte` já viaja nas linhas; se a tela
   ainda não mostra, mostrar discretamente — e, quando for `soma_diaria_fallback`, dizer que o número da
   Adman não chegou e este é o nosso. ⚠️ **Sem jargão**: nada de "cache", "rollup", "fonte api".

⚠️ **Não tocar** em `podeUsarApiDaAdman()` nem em `FechamentoFaixaResolver::classificar()`.
⚠️ Com a chave desligada, **nada muda** — prove com teste.

---

## T2 — Aquecimento diário do total do período

Comando novo (sugestão `adman:warm-fechamento`), no molde do `adman:warm-diff` que já existe:

- para cada empresa que **pode** usar a Adman, busca `fetchGrossBilling(cust, 1º do mês, ontem)` e deixa
  no cache (TTL ≥ 24 h)
- espaçamento compatível com 10 rpm (reaproveite o padrão de 7 s do `adman:sync`)
- 429 ou erro em uma empresa **não derruba a rodada** (mesmo espírito de `AdmanService::syncAll()`);
  no fim, imprimir quantas aqueceram e quantas ficaram de fora, **com nomes**
- agendar em `routes/console.php` **depois** do `adman:sync` das 11:00 (ex.: 11:40) e uma segunda vez à
  tarde, como o `warm-diff` já faz
- ⚠️ fila: produção usa **Redis**; trabalho em lote **não** vai para a fila `high` (ela é dos jobs
  interativos e de webhook — 179 jobs de acervo já seguraram o webhook do Clicksign por horas)

---

## T3 — A coleta diária relê os últimos 5 dias

`adman:sync` já aceita `--from`/`--to`. A forma mais simples e menos invasiva é **agendar uma segunda
rodada** diária com `--from=<D-5> --to=<D-1>`, depois da rodada D-1 e do aquecimento.

- se preferir uma opção (`--dias=5`) dentro do comando, mantenha o comportamento atual como default
  (D-1) — **nenhum agendamento existente pode mudar de comportamento**
- verificar se `syncCompany()` dispara `syncCampaigns()` em cada dia relido; se dispara, a releitura
  gasta **mais** de uma chamada por dia e o número de 420 sobe — medir e, se for o caso, reler só as
  métricas (existe precedente: `syncCompanyMarginOnly()`)
- `AdmanMetric::updateOrCreate` já é idempotente por `(company_id, reference_date)` — releitura
  **atualiza**, não duplica. Confirmar com teste.
- ⚠️ Releitura mexe em `adman_metrics`, que alimenta **carteira, desempenho e bônus**. Isso é desejado
  (o dado fica mais próximo da verdade), mas **não** pode recalcular nem apagar snapshot de desempenho:
  ⛔ nada de `desempenho:*` neste quick.

---

## Testes

| caso | espera |
|---|---|
| chave desligada | tudo idêntico a hoje (mês corrente e fechado) |
| mês corrente, cache quente | `faturamento` = total da Adman, `fonte = api`, **zero** chamada ao vivo |
| mês corrente, cache frio | soma diária + `fonte = soma_diaria_fallback`, sem exceção |
| empresa que não pode usar a Adman | soma diária, como hoje (`podeUsarApiDaAdman` intocado) |
| mês fechado pela tela | seguir mostrando o gravado; sem regressão |
| as três chamadas do controller | recebem o mesmo `faturamentoDaApi` |
| comando de aquecimento | aquece só quem pode; erro em uma não derruba a rodada; resumo com nomes |
| releitura de 5 dias | atualiza a linha do dia (não duplica); respeita o espaçamento |
| copy | sem "cache", "rollup", "api", "fonte" como rótulo |

---

## Travas

⛔ **Sem deploy, sem `.env`, sem VPS, sem plink/pscp, sem alterar produção.** Quem deploya e roda o
aquecimento a primeira vez é o orquestrador.
⛔ Não tocar em `FechamentoFaixaResolver::classificar()`, `FechamentoSnapshotWriter`,
`FechamentoEmpresasDoMes`, `podeUsarApiDaAdman()`.
⛔ Nada de `cache:clear` em lugar nenhum (derrubou a produção em 2026-07-30).

⚠️ **OUTRO DEV ESTÁ TRABALHANDO NA MESMA ÁRVORE** e publicou 20 commits na semana passada (PPA,
anúncios por IA, Polos, Entrada, agenda). Nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. `git status --porcelain app/ tests/ resources/ routes/` antes de cada commit e commit só
pelos seus caminhos; arquivo novo precisa de `git add -- <caminho>`.
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu.

⚠️ Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. `npm run build` se mexer em `.jsx`. Comentários, copy e commits em pt-BR.

**Gate (exit capturado antes de qualquer pipe; rodar ANTES de editar e ao final):**
`--filter="Phase137|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260915|Quick260916|Quick260922|Quick260930"`
e, por causa de `adman_metrics`, `--filter="Phase74|Phase110"`.

Ao final, grave `SUMMARY.md` nesta pasta; se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final para o orquestrador gravar. Registre no SUMMARY **quantas chamadas por dia** cada parte
passa a custar.
