---
quick_id: 260825-dap
slug: refazer-contrato
date: 2026-08-25
status: complete
---

# "Refazer contrato" — o Administrativo corrige um contrato errado sem pedir socorro

## O problema

O contrato é criado **sozinho** no ganho do HubSpot. A partir daí os dados ficam **congelados no
envelope da Clicksign**: e-mail, razão social, endereço, valor. A tela deixa editar o cadastro,
mas isso não chega lá — e a Clicksign não permite editar signatário de envelope criado por API
(a API tem criar, listar, ver e excluir signatário, **não tem editar**).

Relato do usuário (2026-08-25):

> "eu troquei email do cliente para o meu cliquei em salvar cadastro e não mudou nada no
> clicksign, ao tentar alterar o email do cliente pelo clicksign também não dá"

Foi por isso que, ao longo de 24–25/08, o desenvolvedor apagou a empresa direto no banco **onze
vezes**. Isso não é acessível a quem opera o Administrativo.

## O que foi construído

Ação **Refazer contrato** na tela de detalhe: cancela o envelope atual na Clicksign e gera um novo
com os dados que estão no cadastro **agora**. Resolve qualquer campo errado, não só o e-mail.

Exige `motivo` (mín. 10 caracteres) — é ação para fora, o cliente pode já ter recebido o anterior.

### A sequência

1. Cancela o envelope (`ClicksignClient::cancelarEnvelope()`, que não lança — devolve `bool`).
   Sem `clicksign_envelope_id`, pula.
2. ⚠️ **Se o cancelamento falhar, PARA.** Nenhum contrato novo nasce, o antigo fica intacto. Um
   envelope antigo vivo significaria **dois contratos válidos da mesma empresa circulando**, e o
   cliente podendo assinar o errado.
3. Fecha o antigo como `cancelado` via `fill()`+`save()` (nunca `updateQuietly()` — é o hook
   `saving` que alimenta a coluna sombra da trava `ca_empresa_servico_andamento_uniq`).
4. Gera o novo pelo **funil único** que já existe (`dispararSeElegivel()`), sem duplicar lógica.

⚠️ O passo 3 **contradiz de propósito** o docblock de `registrarCancelamento()` ("NÃO alterar
`status`: quem fecha o estado é o webhook `cancel`"). Lá o cancelamento é pedido no painel e o
webhook confirma; aqui **nós** cancelamos pela API e o status precisa fechar **agora** para
liberar a trava — senão o contrato novo não nasce. Documentado no código para não parecer
incoerência.

## Limitação herdada, importante

O docblock da tela registra medição de **2026-08-14**: a Clicksign **recusa cancelar por API o que
já foi enviado** (`DELETE → 403`).

Então, na prática:

| estado do contrato | Refazer |
|---|---|
| rascunho (ainda não enviado) | **funciona** — cancela e refaz |
| já enviado ao cliente | **para e orienta** a cancelar pelo painel primeiro |
| assinado | **recusado** — documento jurídico |

Não é uma falha da implementação: é a API do fornecedor. O ganho é que a tela agora **diz o que
fazer** em vez de a pessoa descobrir que salvar o cadastro não surtiu efeito.

## Webhook tardio

`ProcessarEventoClicksignJob` ganhou guarda: evento `cancel` chegando depois, para um contrato já
`cancelado` (envelope já deletado, reconsulta bateria 404), é marcado `ignorado` **sem nenhuma
chamada HTTP**, sem estragar o registro e sem tocar no contrato novo.

## Rastro

Activity log (`activity('administrativo')`) amarrando contrato antigo → novo, com autor e motivo.
Sem isso ninguém reconstrói depois por que existem dois contratos do mesmo serviço.

## Testes

`tests/Feature/Phase131/ContratoRefazerTest.php` (9 casos) + 1 em
`tests/Feature/Phase129/ProcessarEventoClicksignJobTest.php`:

- rascunho e `aguardando_assinaturas` → antigo cancelado, novo criado
- **assinado → recusado**, nada acontece
- **`cancelarEnvelope()` falso → PARA**, contrato antigo intacto, nenhum novo (a proteção contra
  dois contratos circulando)
- sem envelope → pula o cancelamento e gera o novo
- o novo nasce com o **e-mail atual** do cadastro (o caso que originou o pedido)
- `motivo` ausente/curto → recusado
- webhook `cancel` tardio não estraga nada

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**534 testes, 1824 asserções**, verde. `npm run build` executado.
