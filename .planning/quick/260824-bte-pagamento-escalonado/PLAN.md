---
quick_id: 260824-bte
slug: pagamento-escalonado
date: 2026-08-24
status: in-progress
---

# Pagamento escalonado: as fases viram um contrato só, com a frase do parcelamento

## O que mudou de entendimento

A guarda `servicos_duplicados` (quick `260821-l8n`) trata "mesmo serviço em mais de uma linha"
como **erro de cadastro**. O usuário confirmou em 2026-08-24 que **é a forma legítima de
registrar pagamento escalonado** no HubSpot. A guarda está barrando um caso real.

Caso vivo: **Mons Bike, `company_id=431`**, serviço Gestão (`servico_id=6`), 3 parcelas de
R$ 5.500 + 9 de R$ 6.000.

## Os dados já estão todos gravados (medido em produção, 2026-08-24)

| | `ContratoServico` 329 | `ContratoServico` 330 |
|---|---|---|
| `valor_contratado` | 5500.00 | 6000.00 |
| `hs_recurring_billing_period` | `P3M` | `P9M` |
| `hs_recurring_billing_start_date` | `""` (vazio) | `"2026-12-01"` |
| `hubspot_valor_original` | 16500 (= 3x5500) | 54000 (= 9x6000) |
| `hubspot_line_item_id` | 57973834627 | 58210340910 |

Tudo isso vive em `contratos_servico.hubspot_snapshot` (JSON, chave `line_item`) e nas colunas
`hubspot_billing_period` / `hubspot_valor_original`.

**Consequências:**

- **quantidade de parcelas** vem de `hs_recurring_billing_period` (`P<N>M` -> N)
- **ordem das fases** vem de `hs_recurring_billing_start_date` (vazio/null primeiro, depois por data)
- **"as demais voltam à faixa"** é a fase sem período definido

## Riscos já descartados por medição

- **Ninguém soma `valor_contratado`** (busca por `sum('valor_contratado')` devolveu zero) e os
  serviços de desempenho/bônus **não leem `contratos_servico`**. Duas linhas **não** dobram
  nada no ranking nem no bônus.
- As duas linhas aparecem duplicadas nas listagens de serviços da empresa
  (`AdminController`, `ComercialController`, `CompanyController`) — cosmético, **fora de escopo**.

## Decisão do usuário (2026-08-24)

A frase do parcelamento é **mostrada e editável** na tela antes de gerar.

## A redação — precedente real do jurídico

De um contrato de Mentoria já assinado por eles:

> "...**3 (três) primeiras** no valor de R$ 1.500,00 (mil e quinhentos reais) e **outras 9
> (nove) parcelas** de R$ 2.000,00 (dois mil reais)."

E o modelo de Gestão (contrato KIVE, Cláusula 6ª §1º), que **não** usa valor por extenso:

> "A primeira parcela corresponderá à R$ 2.250,00, a segunda e a terceira parcela corresponderá
> a R$ 3.500,00 e as demais seguirão a faixa apurada na forma da Cláusula 2.1.2."

**Formato a adotar** (sem extenso, que é o do modelo de Gestão onde a variável vive):

- 1 fase: `ContratoVariaveisModeloService::PLANO_PARCELAS_CASO_SIMPLES` (constante atual, não mudar)
- N fases, todas com período: `As 3 (três) primeiras parcelas corresponderão a R$ 5.500,00 e as 9 (nove) demais a R$ 6.000,00.`
- última fase sem período: `... e as demais seguirão a faixa apurada na forma da Cláusula 2.1.2.`

Quantidade em dígito + por extenso entre parênteses. Valor no formato `R$ 0.000,00`.

---

## Tarefa 1 — a guarda deixa de recusar e passa a juntar

Em `ContratoClicksignService::iniciarParaEmpresa()`, o método `servicosDuplicados()` (leitura
pura, do quick `260821-l8n`) deixa de ser "lista de serviços a recusar" e vira a base do
agrupamento: **um `ContratoAssinatura` por `servico_id`**, não por linha.

O `servicos_snapshot` do contrato passa a carregar as **fases ordenadas** daquele serviço.

⚠️ **A recusa NÃO some — ela vira o caso de ordem ambígua.** Se duas fases do mesmo serviço
tiverem `hs_recurring_billing_start_date` vazio/nulo/igual, a ordem não é derivável, e inverter
a frase num contrato assinado é pior que não gerar. Nesse caso mantém-se tudo que existe hoje:
`pulados` com motivo `servicos_duplicados`, `Log::warning` com os `hubspot_line_item_id`, `ok`
deixa de ser `true`, e a tela bloqueia.

⚠️ **Adapte a copy do JSX** (`MOTIVO_BLOQUEIO_TEXTO.servicos_duplicados`) para falar de "não foi
possível saber a ordem das parcelas" em vez de "corrija no HubSpot deixando só um lançamento por
serviço" — que agora seria conselho errado.

⚠️ Regressão zero para empresa com um serviço por linha e para empresa com serviços diferentes.
Os dois caminhos (gatilho automático via Observer e botão) passam por aqui.

## Tarefa 2 — o snapshot carrega as fases, e `valor_mensal` para de somar fase

`ContratoPdfService::montarDados()` hoje faz `somarValores($snapshot)` para
`totais.valor_mensal_formatado`.

Somar **serviços diferentes** continua certo (Gestão + Mentoria = soma). Somar **fases do mesmo
serviço** está errado: hoje daria R$ 11.500,00 para a Mons Bike, valor que nunca é cobrado.

Regra: **por serviço, vale a primeira fase** (o valor em vigor — o usuário respondeu "o valor do
mês corrente"); a soma continua atravessando serviços distintos.

⚠️ **Compatibilidade:** contratos já gravados têm snapshot de uma fase só. A leitura nova precisa
continuar funcionando com eles sem migração de dado.

## Tarefa 3 — `{{plano_parcelas}}` composto, e editável na tela

**3a. Coluna nova** em `contratos_assinatura`: `plano_parcelas_texto`, `nullable`.
`null` = usar o texto composto das fases. Preenchido = usar **literalmente** o que a pessoa
escreveu. Guardar o override como fato, sem sobrescrever o composto.

**3b. A composição** — `ContratoVariaveisModeloService` é **PURA por decisão** (T-126-40): não
consulta banco, `Http`, `Log`, `Cache` nem `Storage`. Ela lê o que `montarDados()` entrega.
Então **`montarDados()` é quem resolve** override-ou-composto e entrega o texto pronto; o
`mapa()` só lê a chave. Não quebrar a pureza.

**3c. A tela** — `ContratoDetalhe.jsx`: campo de texto com o valor atual (override ou composto),
salvo pelo mesmo `atualizarCadastro()`. Copy **sem jargão**: deixar claro que esse texto vai
impresso no contrato que o cliente assina.

⚠️ Existe teste de whitelist de props (PII) que fixa o conjunto EXATO exposto — rode e ajuste.

## Testes

- **Mons Bike reproduzida** (2 fases, `P3M` início vazio + `P9M` início `2026-12-01`): UM
  contrato criado, snapshot com as duas fases na ordem certa, e o texto composto sai
  `As 3 (três) primeiras parcelas corresponderão a R$ 5.500,00 e as 9 (nove) demais a R$ 6.000,00.`
- **Ordem invertida na entrada** (a fase `P9M` gravada primeiro): a frase sai IGUAL — quem ordena
  é a data de início, não a ordem de leitura.
- **Ordem ambígua** (duas fases sem `start_date`): NÃO cria contrato, `pulados` com
  `servicos_duplicados`, `ok` diferente de `true`, log emitido.
- **Última fase sem período**: frase termina em "e as demais seguirão a faixa apurada na forma da
  Cláusula 2.1.2."
- **Uma fase só**: frase igual a `PLANO_PARCELAS_CASO_SIMPLES`, comportamento idêntico ao de hoje.
- **Dois serviços diferentes**: continua criando 2 contratos, `valor_mensal` continua somando.
- **`valor_mensal` com fases**: R$ 5.500,00 (primeira fase), **não** R$ 11.500,00.
- **Override**: com `plano_parcelas_texto` preenchido, a variável usa o texto literal.
- **Snapshot legado** (uma fase, contrato antigo): continua montando sem erro.
- Caminho **automático** (Observer, dentro do mesmo `DB::transaction()` que
  `HubspotWebhookController::persistirContratos()` usa) também consolida.

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase126|Phase127|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- `npm run build` ao final (mexe em JSX). Comentários e copy em pt-BR. Commits atômicos.
