---
quick_id: 261005-sm1
slug: faturamento-da-adman-para-quem-tem-conta-la
date: 2026-10-05
status: complete
commits:
  - 1fc87fa6
  - 71ce77b6
---

# O faturamento vem da Adman para toda empresa que tem conta lá

> O executor **travou** (sem progresso por 600 s) exatamente ao escrever este arquivo, com os dois
> commits de código já feitos e a edição do learning em disco sem commit. O orquestrador revisou os
> commits, conferiu a edição do learning, rodou os dois gates e gravou este SUMMARY.
> ⚠️ Havia **outra sessão commitando na mesma árvore** no mesmo intervalo (quick 261005-si3, painel de
> criativos) — os commits dela aparecem intercalados no `git log` e não têm relação com este quick.

## O que mudou

**T1 — a regra** (`1fc87fa6`) · `app/Services/Fechamento/FechamentoRollupService.php`

`podeUsarApiDaAdman()` virou o critério mais simples possível: **tem `cust_id` → a Adman é a fonte;
não tem → soma diária.** Caíram os recortes por token ML e por "as duas contas são o mesmo id".

Tudo segue **atrás da chave `fechamento_faturamento_da_api_ativo`** e o mês corrente continua lendo
**só do cache** (quick 260930-njd) — sem HTTP dentro do request da tela, com teste provando.

**T2 — o aviso que substituiu a trava** (`71ce77b6`)

- `FechamentoRollupService::contasDivergem()` — empresa com as **duas** contas preenchidas e
  diferentes
- `fechamento:consolidar-mes` imprime a lista no resumo, com os dois ids
- `AdminController::fechamento()` manda uma **prop de página** e `Financeiro.jsx` mostra uma lista
  discreta (molde de `SemDataInicioAviso` / `NaoParticipamAviso` / `DadoMudouDepoisAviso`) —
  **nenhuma chave nova nos cinco literais de linha**
- o aviso **não muda valor nenhum**: existe para alguém conferir o cadastro

**T3 — o que já estava escrito**

- `tests/Feature/Quick260911/FaturamentoDaApiNoRollupTest.php` **reescrito** (205 linhas alteradas),
  preservando os invariantes que seguem valendo (quem não tem `cust_id` não chama a API; o fallback
  funciona; a chave desligada não muda nada) e trocando a expectativa que o usuário reverteu.
  Ajustados também `Quick260930/FechamentoMesCorrenteComTotalDaAdmanTest` e
  `Quick260930/WarmFechamentoFaturamentoTest`, que assumiam o escopo antigo.
- `.planning/learnings/fechamento-tabela-por-empresa.md` ganhou a **reversão de 2026-10-05** sem apagar
  o raciocínio de 11/09 nem a correção de 15/09.

## Por que a regra antiga caiu

Ela nasceu de **um** caso (LAURA LAR, quick 260911-jpx) e o defeito dela era de **cadastro** — o token
do Mercado Livre apontava para a conta da GRAN BELO (descoberto em 15/09; a empresa foi desativada em
16/09). Medido em produção em 2026-10-05, nas 187 empresas ativas:

| | |
|---|---|
| já usavam a Adman | 108 |
| sem `cust_id` (seguem na soma diária) | 62 |
| **tinham conta e eram recusadas** | **17** |

Das 17, **16 não têm `adman_account_id`** — só `ml_store_id`, que é justamente o id por onde o
`cust_id` consulta a Adman, e ela responde (OUZOR TIME: R$ 654.533,87 numa chamada real). Só a
MAXIGOLD SUPLEMENTOS tem as duas contas, diferentes.

| empresa | gravado em setembro | Adman |
|---|---|---|
| MAXIGOLD SUPLEMENTOS | 3.324,98 | **119.411,57** |
| OUZOR TIME | 583.611,24 | **654.533,87** |

## Gates (reconferidos pelo orquestrador, exit antes de qualquer pipe)

| filtro | resultado |
|---|---|
| `Phase137\|…\|Phase143\|Quick260911\|Quick260915\|Quick260916\|Quick260922\|Quick260930\|Quick261001\|Quick261005` | **828 passando** (3536 asserções), exit 0 |
| `Phase74\|Phase110` | **39 passando** (170), exit 0 |

Novos: `Quick261005/FaturamentoDaAdmanParaQuemTemContaTest` e
`Quick261005/AvisoContasDivergentesTest`.

## Consequência aceita

O aviso olha só "as duas contas preenchidas e diferentes", **sem** considerar o token ML — amarrá-lo ao
token recriaria a segunda régua que acabou de cair. Por isso empresas cuja divergência é conhecida e
intencional podem aparecer na lista. É aviso discreto e não bloqueia nada.

## Estado em produção

**Nada deployado.** ⚠️ **Isto muda dinheiro**: 17 empresas trocam de fonte e várias devem mudar de
faixa. A sequência combinada com o usuário é deploy → aquecer o cache → comparar empresa por empresa
contra a Adman → mostrar a lista → só então refazer setembro, junto com as seis tabelas novas
(Future, Milani, LOJA SHEEP, SS PET, ITUFARMA1, CLICK_DECOR).
