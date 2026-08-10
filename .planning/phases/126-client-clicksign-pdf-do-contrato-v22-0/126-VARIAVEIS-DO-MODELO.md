# Modelo `.docx` da Clicksign — variáveis propostas

**Origem:** contrato real assinado enviado pelo usuário em 2026-08-10
(`Contrato Gestão de ADS _ ECF - <cliente> LTDA.pdf`, 15 cláusulas), lido por `pdftotext`.
**Para quê:** você monta o `.docx` no Word seguindo esta lista; o código preenche as variáveis.

> **Regra da Clicksign, medida na API** (§9.4 do empírico): as variáveis vão em **chaves duplas**
> — `{{razao_social}}` — e o nome **não pode ter `@`, `#` ou `!`**. O arquivo tem que ser `.docx`,
> não `.doc` nem PDF.

---

## 1. O que vira variável e o que fica literal no modelo

O contrato real tem muito texto que **não muda de cliente para cliente** — as 15 cláusulas
inteiras, a qualificação da ECF, a tabela de faixas de faturamento, os nomes das testemunhas.
Tudo isso fica **escrito direto no `.docx`**, sem variável. Quanto menos variável, menos ponto
de falha.

Só vira variável o que muda por contrato:

| Variável | De onde o código tira | Existe hoje? |
|---|---|---|
| `{{razao_social}}` | `montarDados()['empresa']['razao_social']` ← `companies.name` | ⚠️ **mistura razão social e nome fantasia** (confirmado por você) |
| `{{cnpj}}` | `['empresa']['cnpj']` ← `companies.cnpj` | ✅ |
| `{{endereco}}` | `['empresa']['endereco']` | ❌ não existe no banco → sai `A DEFINIR` |
| `{{valor_mensal}}` | `['totais']['valor_mensal_formatado']` (ex.: `R$ 3.000,00`) | ✅ |
| `{{data_primeira_parcela}}` | — | ❌ não existe → `A DEFINIR` |
| `{{dia_vencimento}}` | `['pagamento']['dia_vencimento']` (ex.: `10`) | ❌ não existe → `A DEFINIR` |
| `{{data_assinatura}}` | data de geração, por extenso (`10 de Agosto de 2026`) | ✅ |
| `{{servico_contratado}}` | `['servicos'][0]['servico']` — ver tensão nº 2 abaixo | ✅ |

**Fica literal no `.docx`** (não faça variável): qualificação completa da ECF (razão social, CNPJ
`63.381.851/0001-41`, endereço de São José dos Pinhais/PR), a tabela de faixas de faturamento da
cláusula 2.1.1, a chave PIX `financeiro@ecfconsultoria.com.br`, o foro de São Paulo/SP, a vigência
de 12 meses, os nomes das duas testemunhas e as 15 cláusulas.

---

## 2. Três tensões que precisam de decisão sua antes do `.docx` ficar pronto

### 2.1. Metade dos campos sai `A DEFINIR`

`endereco`, `data_primeira_parcela` e `dia_vencimento` **não existem no banco** — são território da
Fase 131 (ADM-01). Você já decidiu manter o placeholder visível, então o contrato sairá com
`A DEFINIR` nesses três pontos até a 131 existir.

No contrato real esses três campos aparecem em lugares de peso: o endereço está na qualificação da
parte (primeira linha do documento) e o vencimento está na cláusula de pagamento. Vale conferir se
`A DEFINIR` no endereço da CONTRATANTE é aceitável, ou se esses três campos deveriam bloquear a
geração — o que empurraria a integração inteira para depois da Fase 131.

### 2.2. Contrato com mais de um serviço não cabe em chave dupla

O `servicos_snapshot` guarda **N serviços** (a empresa pode ter Mercado Livre e Shopee). Mas
variável em `.docx` **não faz loop** — `{{servico_contratado}}` rende uma linha só. O contrato real
que você mandou trata de um serviço só ("gestão de ADS para Mercado Livre").

Três saídas possíveis:
- **Um contrato por serviço** — N envelopes para empresa com N serviços. Mais fiel ao documento
  atual, e espelha o que o NPS já faz (dois NPS para empresa com dois serviços).
- **Uma variável com os serviços concatenados** — `{{servico_contratado}}` = "Gestão de ADS para
  Mercado Livre e Shopee". Um envelope só, mas a cláusula 1 e a 2 do contrato falam de Mercado
  Livre no corpo do texto, e teriam que ser reescritas de forma genérica.
- **Um modelo por serviço** — modelos separados na Clicksign, escolhidos pelo serviço do contrato.
  É o que dá o texto mais correto juridicamente, e o mais trabalhoso de manter.

### 2.3. As testemunhas do contrato real não batem com a D-08

O contrato traz **duas testemunhas nomeadas** (Emerson Faccioli e Jéssica de Oliveira). A decisão
D-08 da Fase 125 previu **4 signatários**: dois sócios como `contratada`, o Comercial como
`testemunha`, e o cliente. São arranjos diferentes. Como os signatários são criados via API (não
pelo modelo), isso não trava o `.docx` — mas os nomes escritos no rodapé do modelo precisam bater
com quem de fato assina, senão o documento diz uma coisa e a lista de assinaturas diz outra.

---

## 3. Como montar o `.docx` na prática

1. Abra o contrato real no Word e salve como `.docx`.
2. Troque os pedaços variáveis pelas chaves duplas da tabela do item 1 — exatamente com esses
   nomes, minúsculos e com underscore.
3. Não use `@`, `#` nem `!` em nome de variável (a API recusa).
4. Mantenha a tabela de faixas de faturamento como tabela do Word normal — ela é literal.
5. Me devolva o arquivo, ou suba você mesmo em Configurações → Modelos na Clicksign.

Com o modelo cadastrado, fecha o único item que ainda não deu para medir: **qual endpoint instancia
o modelo em documento** (§9.4 do empírico). É a primeira coisa que o plano novo vai fazer.
