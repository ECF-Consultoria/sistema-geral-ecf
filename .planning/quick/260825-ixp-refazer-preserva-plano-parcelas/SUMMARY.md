---
quick_id: 260825-ixp
slug: refazer-preserva-plano-parcelas
date: 2026-08-25
status: complete
---

# "Refazer contrato" perdia a frase do parcelamento que a pessoa escreveu

## O incidente (produção, 2026-08-25, Maderatto)

O Administrativo editou a frase do parcelamento na tela, salvou, clicou em **Refazer contrato** —
e a frase editada **não apareceu** no contrato novo.

Medido:

| contrato | status | `plano_parcelas_texto` |
|---|---|---|
| 23 (atual) | rascunho | **null** — voltou ao texto composto |
| 22 | cancelado | tem o texto editado |
| 21 | cancelado | tem o texto editado |

## A causa — defeito introduzido no mesmo dia

`plano_parcelas_texto` é coluna de `contrato_assinaturas` (quick `260824-bte`), e isso está certo:
é texto que **aquele** contrato congelou.

Mas `refazer()` (quick `260825-dap`, construído horas antes) cria um `ContratoAssinatura` **novo**,
que nasce com a coluna `null`. A edição ficava no contrato cancelado.

E o fluxo natural é exatamente **editar → refazer**: com o envelope já criado, editar o texto não
muda nada na Clicksign. O único caminho para a edição valer era o refazer — que era justamente
onde ela se perdia.

## A correção

`refazer()` transporta `plano_parcelas_texto` do contrato antigo para o novo:

- **do mesmo `servico_id`** — `dispararSeElegivel()` pode criar mais de um contrato (um por
  serviço), e só o correspondente herda
- **literal**, sem recompor: se as fases mudaram e o texto ficou desatualizado, quem manda é o que
  a pessoa escreveu — ela vê o campo na tela e corrige antes de enviar
- override `null` no antigo → novo continua `null` e usa o composto (regressão zero)
- o `activity()` log do refazer ganhou `plano_parcelas_transportado`, junto do rastro
  antigo → novo que já existia

## Testes

Quatro casos novos em `ContratoRefazerTest.php`, incluindo o de **ponta a ponta** —
`planoParcelas($novo)` devolve o texto transportado — que é exatamente o que falhou para o
usuário.

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**549 testes, 1869 asserções**, verde.

## Commits

| Commit | Mensagem |
|---|---|
| `579f9735` | refazer transporta `plano_parcelas_texto` pro contrato novo |

## O que este episódio mostra

O `refazer()` foi construído no mesmo dia e os testes cobriam o que ele deveria fazer — cancelar,
fechar o antigo, criar o novo. Nenhum deles perguntava **o que o contrato novo herda do antigo**.

O campo editável tinha sido criado num quick anterior, e a interação entre os dois só apareceu
quando alguém usou os dois em sequência, que é o uso normal.
