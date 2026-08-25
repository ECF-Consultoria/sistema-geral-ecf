---
quick_id: 260825-fn0
slug: plataforma-do-servico
date: 2026-08-25
status: complete
---

# A plataforma do contrato sai do serviço, em vez de estar fixa

## O problema

O modelo de Gestão citava **"Mercado Livre e Shopee"** em **11 parágrafos** — objeto, escopo,
tabela de faturamento, confidencialidade, limitação de responsabilidade.

Nem todo cliente contrata as duas:

> "isso é delicado porque nem todos contratos cliente fecham o serviço de gerirmos as duas
> plataformas"

Contrato assinado dizendo que a ECF gere uma plataforma que o cliente não contratou é exposição
jurídica, não detalhe cosmético.

## A regra, definida pelo usuário

A plataforma vem do **serviço** atribuído no item de linha do HubSpot:

| serviço | plataforma |
|---|---|
| Gestão de Ads | Mercado Livre |
| Gestão Shopee | Shopee |

## O modelo

`modelo-contrato-gestao-v5-PLATAFORMA.docx` (raiz do projeto), gerado e conferido:

- as 11 ocorrências viraram `{{plataformas}}`; **zero** sobra de "Mercado Livre" ou "Shopee"
- as tags de assinatura foram para **acima** do nome de cada empresa (antes, abaixo do CNPJ) — o
  Administrativo informou que é onde a assinatura costuma ficar
- 281 parágrafos, 2 tabelas, 13 variáveis

## Implementação

**Coluna** `servicos.plataforma`, string nullable, migration aditiva e idempotente. Nenhum serviço
preenchido pela migration — é passo de produção.

**Snapshot** (D-04): a plataforma entra no `servicos_snapshot` no momento da criação do contrato,
ao lado de `servico`/`valor_contratado`/`parcelas`. Os valores do contrato vêm do snapshot
congelado, nunca da tabela ao vivo.

**A variável:** `resolverPlataformas()` no `ContratoPdfService` concatena as plataformas
**distintas** (estilo `concatenarServicos()`), e o `mapa()` só lê a chave pronta —
`ContratoVariaveisModeloService` continua **pura** (T-126-40), verificado por teste estático.

**Ausência é visível.** Serviço sem plataforma, ou contrato antigo cujo snapshot não tem a chave,
imprime `A DEFINIR` e entra em `campos_pendentes` — nunca espaço em branco. Já perdemos uma rodada
de teste com o `plano_parcelas` saindo vazio sem aviso (quick `260821-m9h`); a Clicksign não acusa
erro para variável não preenchida, então a visibilidade tem que vir de nós.

Duas fases do mesmo serviço (pagamento escalonado) têm a mesma plataforma e aparecem **uma vez**.

## Regressão evitada durante a execução

Os helpers `contratoComSnapshot()` dos dois arquivos de teste montavam snapshots **sem** a chave
`plataforma`, o que faria `plataformas` entrar em `campos_pendentes` e quebrar testes
pré-existentes que fazem `assertSame` com lista **exata**. Corrigido nos helpers; os testes
específicos de ausência montam o próprio snapshot.

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**545 testes, 1852 asserções**, verde.

Medido depois da mudança: **16 variáveis** emitidas, `plataformas` entre elas, classe pura
(zero ocorrências de DB/Http/Log/Cache/Storage).

## Commits

| Commit | Mensagem |
|---|---|
| `46a1c959` | coluna `plataforma` + `Servico` |
| `0f717b68` | plataforma entra no snapshot congelado |
| `4a126bdb` | variável `{{plataformas}}` |

## Passos FORA do código

1. **Usuário:** subir `modelo-contrato-gestao-v5-PLATAFORMA.docx` na Clicksign, substituindo o
   arquivo do modelo `contrato-gestao-novo`.
2. **Produção, pós-deploy:** preencher `plataforma` dos serviços e conferir por reconsulta.
3. ⚠️ **Conferência do usuário:** o serviço no banco chama-se **"Gestão de ADS Shopee"** (id 9) e
   ele disse ter criado **"Gestão Shopee"** no HubSpot. Se o nome não bater, o item de linha não
   casa com o serviço existente.
