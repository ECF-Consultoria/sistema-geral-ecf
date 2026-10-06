---
quick_id: 261005-sm1
slug: faturamento-da-adman-para-quem-tem-conta-la
date: 2026-10-05
type: quick
status: pending
---

# O faturamento vem da Adman para toda empresa que tem conta lá

## Por que mudar uma regra que foi criada de propósito

Hoje `FechamentoRollupService::podeUsarApiDaAdman()` só aceita o número da Adman quando a empresa
**não é ml_driven** ou quando `adman_account_id === ml_store_id`. A regra nasceu no quick 260911-jpx,
a partir de **um** caso: a LAURA LAR, cujo número da API divergia 99,5% do nosso.

**Descobrimos depois que o problema da LAURA LAR era o token do Mercado Livre**, que apontava para a
conta da GRAN BELO — não a API da Adman (registrado no learning
`fechamento-tabela-por-empresa.md`, correção de 2026-09-15). A empresa foi desativada em 16/09.

Medido em produção hoje (2026-10-05), entre as 187 empresas ativas:

| | |
|---|---|
| já usam a Adman | 108 |
| sem `cust_id` nenhum (seguem na soma diária, e isso não muda) | 62 |
| **têm conta e a regra recusa** | **17** |

Das 17, **16 não têm `adman_account_id`** — só `ml_store_id`, e é por ele que o `cust_id` consulta a
Adman (a API responde: OUZOR TIME devolveu R$ 654.533,87 numa chamada real hoje). **Só a MAXIGOLD
SUPLEMENTOS tem as duas contas, e elas são diferentes.**

O custo disso em setembro, com os números gravados hoje:

| empresa | gravado (nossa soma) | Adman |
|---|---|---|
| MAXIGOLD SUPLEMENTOS | 3.324,98 | **119.411,57** |
| OUZOR TIME | 583.611,24 | **654.533,87** |
| DINMAP | 1.133.896,86 | a medir |
| Calhas atibaia | 635.694,90 | a medir |
| MASTER SHOP | 246.951,45 | a medir |
| … 12 outras | | |

**Decisão do usuário (2026-10-05):** usar o número da Adman para **toda empresa que tenha conta lá**,
e tratar divergência de contas como **aviso visível**, nunca como troca silenciosa por um número pior.

---

## T1 — A regra

Em `podeUsarApiDaAdman()` (⚠️ **é a única porta** — não espalhe a decisão):

- `cust_id` nulo → `false` (segue na soma diária, como hoje; 62 empresas)
- `cust_id` preenchido → `true`

Ou seja: cai a exigência de `adman_account_id === ml_store_id`.

⚠️ **Tudo continua dentro da chave `fechamento_faturamento_da_api_ativo`.** Com a chave desligada,
nada muda — prove com teste.

⚠️ O fallback já existe e continua valendo: API sem resposta → soma diária com
`faturamento_fonte = soma_diaria_fallback`. Empresa cujo `cust_id` a Adman não reconhece **não** fica
sem número.

---

## T2 — O aviso que substitui a trava

Quando a empresa tem **as duas** contas preenchidas e elas **diferem** (hoje: só a MAXIGOLD), o
sistema passa a avisar — porque uma das duas provavelmente está errada, e foi exatamente isso que
aconteceu com a LAURA LAR:

- `fechamento:consolidar-mes` imprime, no resumo, a lista dessas empresas com os dois ids
- a tela do fechamento mostra em uma lista discreta (**prop de página**, molde dos avisos que já
  existem: `SemDataInicioAviso`, `NaoParticipamAviso`, `DadoMudouDepoisAviso`) — ⛔ **nenhuma chave
  nova nos cinco literais de linha**
- copy sem jargão: algo como *"A conta da Adman e a conta do Mercado Livre desta empresa são
  diferentes. O faturamento está vindo da Adman; confira se as duas contas estão certas."*

⚠️ O aviso **não** muda número nenhum. Ele existe para alguém conferir o cadastro.

---

## T3 — O que já está escrito e precisa ser corrigido

1. **Testes do quick 260911-jpx** afirmam o comportamento ANTIGO ("ids diferentes → recusa a API").
   ⛔ **Não apague**: reescreva, preservando o invariante que ainda vale (quem não tem `cust_id` não
   chama a API; o fallback funciona) e trocando a expectativa que o usuário reverteu hoje. Deixe no
   docblock **por que** mudou, com a data e o motivo (token da LAURA LAR, não a API).
2. **Learning `.planning/learnings/fechamento-tabela-por-empresa.md`** descreve a regra `ids-iguais`
   como a lição do caso. Acrescente a reversão: o recorte protegia de um defeito de **cadastro** e
   custava o número certo de 17 empresas. ⚠️ Não reescreva a história — **acrescente** o episódio,
   como já foi feito com a correção de 15/09.

---

## Testes

| caso | espera |
|---|---|
| chave desligada | nada muda, nem para as 17 |
| empresa só com `ml_store_id` (as 16) | usa a API; `fonte = api` |
| empresa com os dois ids iguais | usa a API, como hoje |
| empresa com os dois ids **diferentes** | usa a API **e** entra na lista de aviso |
| empresa sem `cust_id` | soma diária, sem chamar a API |
| API sem resposta | `soma_diaria_fallback`, sem exceção |
| resumo do comando | lista as empresas de contas divergentes, com os dois ids |
| tela | aviso discreto; nenhuma chave nova nos cinco literais |
| mês corrente | segue lendo só do cache (quick 260930-njd), sem HTTP no request |
| copy | sem jargão |

---

## Travas

⛔ **Mudar `podeUsarApiDaAdman()` é o objetivo deste quick** — mas é a ÚNICA função de decisão que
pode mudar. `FechamentoFaixaResolver::classificar()`, `FechamentoSnapshotWriter`,
`FechamentoEmpresasDoMes` e `FechamentoConferenciaFaturamentoService` ficam intactos.

⛔ **Sem deploy, sem `.env`, sem VPS, sem produção, sem `cache:clear`.** Quem mede em produção, roda o
aquecimento e refaz setembro é o orquestrador — e **só depois** de comparar empresa por empresa.

⚠️ **Isto muda dinheiro.** 17 empresas trocam de fonte e várias devem mudar de faixa. O quick entrega
o código; a conferência antes/depois em produção é passo separado e humano.

⚠️ Outro dev ativo na mesma árvore (Fase 159 e Portal nos últimos dias). Nunca `git add -A` /
`git add .` / `git commit -a` / `git stash`; `git status --porcelain app/ tests/ resources/` antes de
cada commit; arquivo novo precisa de `git add -- <caminho>`.
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu.

⚠️ Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. `npm run build` se mexer em `.jsx`. Comentários, copy e commits em pt-BR.

**Gates (exit antes de qualquer pipe; antes de editar e ao final):**
`--filter="Phase137|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260911|Quick260915|Quick260916|Quick260922|Quick260930|Quick261001|Quick261005"`
e `--filter="Phase74|Phase110"`.

Ao final, grave `SUMMARY.md` nesta pasta; se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final. Registre no SUMMARY **quais testes do 260911-jpx foram reescritos e por quê**.
