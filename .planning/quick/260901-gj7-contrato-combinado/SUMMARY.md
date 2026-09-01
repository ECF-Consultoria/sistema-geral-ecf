---
quick_id: 260901-gj7
slug: contrato-combinado
date: 2026-09-01
status: complete
---

# Venda combinada Mercado Livre + Shopee gera UM contrato, não dois

## O pedido

> "caso o negócio for combinado mercado livre e shopee o mesmo contrato deve constar as duas
> plataformas, o modelo seria o modelo-contrato-gestao-ads-mercado-livre"

## O que acontecia

`iniciarParaEmpresa()` fazia `groupBy('servico_id')` — **um contrato por serviço**. Uma empresa
com Gestão (6) + Gestão de ADS Shopee (9) receberia dois contratos, cada um nomeando só a sua
plataforma.

Nunca aconteceu na prática: **zero empresas** tinham contrato de mais de um serviço.

## O que já funcionava sozinho

`{{plataformas}}` (quick `260825-fn0`) concatena as plataformas **distintas do snapshot**. Bastou
os dois serviços caírem no mesmo contrato para a variável imprimir "Mercado Livre e Shopee" — a
variável não foi tocada.

E o modelo de Gestão **não usa** `{{valor_mensal}}` (confrontado em 2026-08-26), então a soma dos
dois serviços não aparece impressa. Não houve decisão de dinheiro neste quick.

## A implementação

**Coluna** `servicos.contrato_junto_com_servico_id`, nullable, FK auto-referenciada com
`nullOnDelete()`. Semântica: *"quando este serviço aparecer junto com X na mesma empresa, os dois
compartilham UM contrato, que pertence a X"* — e é X quem define o modelo da Clicksign e o
`servico_id` gravado.

⚠️ `nullable()` **antes** do `nullOnDelete()`: sem isso o MariaDB recusa com erro 1830 e o SQLite
dos testes não pega. Armadilha já conhecida do projeto.

**Agrupamento:** a chave do laço deixou de ser o `servico_id` cru e passou a ser o serviço dono —
**só quando o dono também está ativo na empresa**.

⚠️ **Shopee sozinho continua com contrato e modelo próprios.** É o caso que acabou de entrar em
produção, e o teste que prova isso é a regressão mais importante do quick.

⚠️ A ordenação de fases do pagamento escalonado continua **por serviço original**; o grupo
combinado apenas concatena as fases já ordenadas de cada membro, o dono primeiro. Ordem ambígua em
qualquer membro barra o grupo inteiro — gerar metade de um contrato combinado é pior que não gerar.

**A lista:** `index()` tinha o mesmo `groupBy` e ganhou a mesma regra. Sem isso o Shopee viraria
uma linha **"aguardando administrativo" eterna**, de um contrato que nunca existiria por estar
coberto pelo combinado. `data_vencimento` da linha combinada é o maior valor entre as fases de
todos os membros.

## Testes

13 novos. Além do caminho feliz: Shopee sozinho com contrato próprio (a regressão), Gestão sozinho
igual a hoje, Gestão + Shopee + Mentoria = 2 contratos, ambiguidade em qualquer membro barrando o
grupo, fases não misturadas entre serviços, serviço isento continuando fora, e a lista sem
contrato fantasma.

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**562 testes, 1936 asserções**, verde (baseline 549).

## Commits

| Commit | Mensagem |
|---|---|
| `d3773be0` | coluna `contrato_junto_com_servico_id` |
| `556fe7cd` | venda combinada vira UM contrato |
| `56497969` | lista deixa de mostrar contrato fantasma |

## Passo FORA do código

Produção, pós-deploy: apontar **Gestão de ADS Shopee (9) → Gestão (6)** e conferir por reconsulta.
Enquanto a coluna estiver vazia, nada muda — todo serviço continua com contrato próprio.
