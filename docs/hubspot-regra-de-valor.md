# Regra de valor operacional HubSpot (mensal x anual)

Este documento explica, de forma curta e sem jargão técnico não-explicado, como
o sistema decide o valor "operacional" de um contrato quando ele nasce a partir
de um deal do HubSpot. Fonte da verdade: `app/Services/Hubspot/HubspotValueResolver.php`.

## Por que a regra existe

Bug real que motivou esta regra: um deal do HubSpot trazia **R$ 36.000** como
valor do contrato — mas esse número era o valor **anual** do contrato, e o
serviço em questão é cobrado **mensalmente**. O valor mensal correto era
**R$ 3.000** (R$ 36.000 ÷ 12).

Quando o serviço é do tipo mensal, o valor gravado em
`contratos_servico.valor_contratado` (o campo que o resto do sistema usa para
cobrança, metas e relatórios) precisa ser **sempre o valor mensal** — nunca o
valor anual bruto do contrato. O `HubspotValueResolver` existe para calcular
esse valor mensal corretamente a partir dos dados variados que o HubSpot pode
enviar.

## Mensal x Anual (normalizacao)

O HubSpot pode informar, no "line item" (o item de linha do negócio/deal), uma
frequência de cobrança (`recurringbillingfrequency`):

- **`monthly`** (mensal) + preço unitário (`price`) numérico → o valor
  operacional é `price × quantity` direto, sem nenhuma conta extra. Confiança
  `high` (alta). Este é o comportamento histórico do sistema e é tratado como
  **invariante** — não pode regredir.
- **`annually`** (anual) + `price` numérico → o valor bruto é
  `price × quantity`, e o valor operacional (mensal) é esse bruto **dividido
  por 12**. A confiança só é `high` quando o período de cobrança
  (`hs_recurring_billing_period`) confirma que são realmente 12 meses (ver
  seção P1Y abaixo); caso contrário a confiança cai para `medium` (média) e um
  aviso é registrado.

### O que é "P1Y"

`hs_recurring_billing_period` é um campo do HubSpot que descreve a duração do
período de cobrança no formato ISO-8601 (um padrão internacional de duração).
`P1Y` significa "período de 1 ano" (**P**eríodo de **1** **Y**ear/ano). Quando
esse campo começa com `P1Y`, o sistema confirma que a divisão por 12 é segura
e marca a confiança como `high`. Quando o campo não confirma isso (ou está
ausente), o sistema ainda faz a divisão por 12, mas marca a confiança como
`medium`, sinalizando que o valor merece uma conferência humana eventual.

## MRR x ARR

- **MRR** = *Monthly Recurring Revenue* (receita mensal recorrente). Quando o
  HubSpot informa o campo `hs_mrr` no line item, esse valor é tratado como
  **fonte forte**: ele já É o valor mensal, então vira o `valor_operacional`
  diretamente, com confiança `high`, independente da frequência de cobrança
  declarada.
- **ARR** = *Annual Recurring Revenue* (receita anual recorrente). Quando o
  HubSpot também informa `hs_arr`, esse valor não é usado para calcular o
  valor mensal — ele é guardado apenas como referência do valor anual bruto
  (`valor_original`), para auditoria.

Resumindo: se `hs_mrr` existe, ele manda. `hs_arr` nunca é dividido por nada;
serve só de registro do valor anual observado.

## Servico unico x servico mensal

Todo serviço no sistema tem um `tipo_cobranca`: `mensal` ou outro tipo (aqui
chamado de "serviço único" — cobrança avulsa, não recorrente).

- **Serviço único**: o valor operacional **NUNCA** é dividido por 12. Usa o
  `amount` do line item se ele existir e for numérico; senão usa
  `price × quantity`; senão cai no `valor_padrao` cadastrado no serviço (com
  aviso de que faltam dados e confiança `low`, baixa).
- **Serviço mensal**: é o único caso em que a normalização (dividir por 12 ou
  usar MRR direto) se aplica, conforme descrito nas seções acima.

## Inferencia por tolerancia (5%)

Às vezes o HubSpot só informa o campo genérico `amount` do line item (ou,
no fluxo mais antigo sem line item, o campo `amount` do próprio deal) — sem
`price`, sem `hs_mrr`. Nesse caso o sistema não sabe se aquele número é o
valor mensal ou o valor anual do contrato.

Para decidir, ele compara `amount ÷ 12` com um valor de referência —
o `valor_padrao` cadastrado no serviço, ou o `price` do line item quando
disponível — dentro de uma **margem de tolerância de 5%**. Se os dois números
estiverem próximos o suficiente (diferença de até 5%), o sistema conclui que
`amount` era o valor anual bruto e usa `amount ÷ 12` como valor operacional,
com confiança `medium` e um aviso de que o valor foi inferido (deduzido, não
informado diretamente) — vale a pena confirmar.

## Quando marca valor_revisar

`valor_revisar` é uma marca de texto (não um valor booleano) que aparece
dentro do campo de aviso (`warning`) quando o sistema **não tem confiança
suficiente** para decidir o valor sozinho. Isso acontece em duas situações
principais:

1. **Inferência não bate na tolerância**: `amount ÷ 12` não se aproxima nem
   do `valor_padrao` do serviço nem do `price` do line item, dentro dos 5% de
   margem. O sistema ainda calcula um valor conservador, mas marca confiança
   `low` (baixa) e inclui `valor_revisar` no aviso.
2. **Faltam dados básicos**: o line item (ou o deal, no fluxo legado) não tem
   `price`, `amount` nem `hs_mrr` suficientes para calcular nada — o sistema
   cai para o `valor_padrao` do serviço como último recurso, também com
   `valor_revisar` no aviso.

Sempre que um contrato ativo tem confiança `low` ou qualquer aviso registrado,
a tela de listagem Comercial (só para empresas com origem HubSpot) mostra a
pendência **"valor_revisar"** para o time comercial conferir manualmente.

## Onde a proveniencia fica gravada

"Proveniência" aqui significa: de onde veio cada número e o quão confiável
ele é. Cada chave do retorno do `HubspotValueResolver` é persistida numa
coluna específica de `contratos_servico`:

| Chave de saída do resolver | Coluna em `contratos_servico` |
|---|---|
| `valor_operacional` | `valor_contratado` (o valor operacional usado pelo resto do sistema) |
| `valor_original` | `hubspot_valor_original` (o valor bruto observado no HubSpot, antes de normalizar) |
| `normalizado_mensal` | `hubspot_valor_normalizado_mensal` |
| `confidence` | `hubspot_valor_confidence` (`high` / `medium` / `low`) |
| `warning` | `hubspot_valor_warning` (contém `valor_revisar` quando aplicável) |
| `billing_frequency` | `hubspot_billing_frequency` (`monthly` / `annually` / nulo) |

Os testes que provam este comportamento (todos os ramos descritos acima)
estão em `tests/Unit/HubspotValueResolverTest.php`.
