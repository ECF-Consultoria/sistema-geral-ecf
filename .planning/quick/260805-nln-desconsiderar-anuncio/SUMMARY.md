---
id: 260805-nln
slug: desconsiderar-anuncio
date: 2026-08-05
status: complete
commits: [01b9a289, 39138fb6, 51fffd8b, 579b2ffe]
---

# Desconsiderar anúncio fora da metodologia

## O que passou a existir

Botão **Desconsiderar** na tela `/mlb/revisao` (linha e card), com descrição no
hover. Marca o anúncio como fora da metodologia: ele continua registrado e
visível, mas para de contar em vendas, meta, conversão, faturamento e score.

Reversível pelo mesmo botão, que vira **Voltar a contar**.

## Por que um flag e não apagar

Até aqui, a única forma de tirar da apuração um anúncio publicado fora do
método era excluir o registro — o que destruía a evidência de que ele foi
feito, justamente o que o líder precisa para cobrar. O flag separa "existiu"
de "conta".

## Decisões

**Ortogonal à revisão.** `definirDesconsiderado()` não passa por
`registrarEvento()`: um anúncio fora da metodologia ainda pode ter pendência e
ser aprovado. Se desconsiderar entrasse na máquina de estados, o botão apagaria
o veredicto do líder.

**Sai da fila e dos KPIs.** Não há veredicto a dar num anúncio que não conta, e
deixá-lo na coluna "Não revisado" cobraria trabalho sem efeito. Fica alcançável
pelo filtro `Lista · fora da metodologia`, que é por onde se desfaz. O KPI
próprio fica **fora** do total da competência de propósito.

**Autoria some ao desfazer.** `desconsiderado_por`/`_em` voltam a `null`:
manter o nome de quem marcou num anúncio que voltou a contar exibiria selo sem
fato por trás. A trilha permanente fica no `activity_log`.

**Permissão igual à do veredicto** (`checkPubAccess('revisao')` +
`checkPodeRevisar()`): líder, gestor ou admin.

**O sync de vendas não muda.** `VendasSyncService` continua atualizando
`vendido`/`vendas_qty` do anúncio desconsiderado — o dado segue verdadeiro; o
recorte acontece só na apuração.

## Onde o filtro foi aplicado

Scope `Publicacao::considerado()`. Todos os pontos que já excluíam
`tipo = variacao` — ou seja, que já tinham a noção de "o que conta como
anúncio" — mais os que somam `net_billing`:

- `MlbController`: `calcularKpis`, ranking do Dashboard, evolução diária e
  mensal, Meu Painel (pontualidade, evolução, top empresas, ticket,
  faturamento), Vendas (recortado na origem da query, alcançando stats, top
  MLBs, lista e lojas que venderam), alertas de anúncio com problema, Fila e
  Gráfico da Revisão.
- `PerformanceController`: `feito`/`vendas` nas duas visões de desempenho.
- `PlanoMetasPublicacaoService` (pontualidade), `PublicadorScoreService`
  (qualidade), `CalculateSetorGoalResults` (metas de setor).

Fora do escopo por decisão: Publicações, Histórico e a contagem de MLBs por
empresa continuam mostrando tudo — são telas de registro e inventário, não de
apuração.

## Gates

- 4 testes novos (`PublicacaoDesconsideradaApuracaoTest`) — **passam**.
- Regressão em `MeuPainelControllerTest | PlanoMetasPublicacaoServiceTest |
  PublicadorScoreServiceTest | PublicacaoDesempenhoRouteTest |
  Phase14MlbControllerFiltroTest | PerformanceAutorizacaoTest`: **26 passed,
  4 failed** — e as **mesmas 4 falham no baseline**, medido com as mudanças
  em `git stash`. Zero regressão.
- As 4 pré-existentes: `MeuPainel > props novas` e `> sem publicacoes` cobram a
  prop `score_publicador`, que **não existe no controller** (o teste ficou para
  trás); `Phase14 > companies index` e `PublicacaoDesempenhoRoute > 403` são de
  filtro/permissão, código que esta tarefa não tocou.
- `npm run build` verde, `Revisao-*.js` no manifest.
- `php artisan migrate` aplicado no local.

## DEPLOYADO 2026-08-05

Deploy isolado, `3456b7b8..46cab623` (push fast-forward + `deploy.sh`).

Os 5 commits foram **rebasados** sobre a `origin/main`, que tinha avançado 8
commits de outra sessão (handoff comercial HubSpot + coluna "Cadastrado em").
Conflito só no `STATE.md` — as duas sessões inseriram linha no topo da mesma
tabela; resolvido preservando as três. Testes revalidados sobre a base
rebasada: 4 verdes.

**A VPS já estava em `3456b7b8`** antes deste deploy, ou seja, o trabalho
comercial (incluindo a migration que dropa 5 colunas de `companies`) já tinha
ido ao ar pela outra sessão — por isso este deploy publicou só o desta tarefa.
A checagem vale sempre: `deploy.sh` publica exatamente `origin/main`, então é
preciso comparar `git log HEAD..origin/main` **e** o HEAD da VPS antes de
concluir que um deploy é isolado.

Conferido em produção por reconsulta, não por stdout do deploy:

- HEAD da VPS = `46cab62`.
- As 3 colunas presentes em `mlb_publicacoes`.
- Rota `mlb.revisao.desconsiderar` registrada.
- Bundle buildado na VPS (`Revisao-DMLzjQ-v.js`) contém "Fora da metodologia" e
  o texto do tooltip.
- Smoke HTTP: `/mlb/revisao` 302 (auth), `/login` 200 — sem 500.
- Workers reiniciaram limpos, sem travar em STOPPING.

O `ERROR The [public/storage] link already exists` na saída do deploy é
esperado e inofensivo.
