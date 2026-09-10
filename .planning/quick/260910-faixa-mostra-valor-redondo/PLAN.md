---
quick_id: 260910-j1u
slug: faixa-mostra-valor-redondo
date: 2026-09-10
type: quick
status: done
---

# A faixa mostra o valor redondo, como no contrato

## O pedido do usuário (2026-09-10, conferindo a ficha em produção)

> "Na edição da tabela progressiva os valores de faturamento mostrados são `499.999,99`,
> `999.999,99` e etc. Eu entendi o conceito — que é a partir do número a seguir ser válido a
> mensalidade respectiva — mas para quem está vendo pode estranhar, por ser diferente do valor da
> tabela mostrado no contrato ou apresentado para o cliente na proposta, que é `500.000,00`,
> `1.000.000,00` e etc. Por isso, ao invés de mostrar da forma que está, mostre o valor inteiro e use
> a lógica do maior ou igual, tipo: se o faturamento for maior ou igual a 1.000.000,00 a mensalidade
> é X."

**A primeira faixa fica como "até R$ 500.000"** (decisão do usuário na mesma conversa).

---

## Isto NÃO muda cobrança — e isso foi verificado, não presumido

O motor classifica com `limite_superior >= faturamento` (`FechamentoFaixaResolver::classificar()`).
Ou seja: teto `499.999,99` na faixa 1 significa que **R$ 500.000,00 já cai na faixa 2** — que é
exatamente o que "maior ou igual a 500.000 → R$ 4.500" quer dizer.

**As duas formas são a mesma regra.** A conversão é `piso(n) = teto(n-1) + 0,01`.

⚠️ Isso só sai redondo se todos os tetos terminarem em `,99`. **Conferido em produção (2026-09-10):**

| tabela | tetos | terminam em `,99` |
|---|---|---|
| `empresa_faixas_faturamento` | 1.038 | **1.038** |
| `servico_faixas_faturamento` | 22 | **22** |
| `grupo_faixas_faturamento` | 0 | — |

Zero exceções.

---

## A convenção nova

| linha | mostra |
|---|---|
| primeira | **"até R$ 500.000"** — o teto redondo (`teto + 0,01`) |
| intermediárias | **"a partir de R$ X"** — onde `X = teto da linha anterior + 0,01` |
| última (sem teto) | **"a partir de R$ Z"** — já é piso hoje, não muda |

> ⚠️ Ambiguidade assumida: quem faturar **exatamente** R$ 500.000,00 aparece na faixa 2, embora a
> primeira linha diga "até R$ 500.000". **Essa ambiguidade já existe no contrato**, que escreve
> "Até R$ 500.000,00" e "A partir de R$ 500.000,00" em linhas seguidas. A tela passa a espelhar o
> contrato, inclusive nisso. Decisão do usuário, consistente com a convenção `,99` que ele unificou
> em 2026-09-03.

---

## O que fazer

**Guardar como está; converter na borda.** `limite_superior` continua sendo o teto com `,99`.
A conversão acontece ao **exibir** e ao **receber**.

⚠️ **Não toque no `FechamentoFaixaResolver` nem em nada do motor.** Ele está classificando 169
empresas em cobrança viva neste momento. Uma mudança de apresentação não pode chegar perto dele.

**Os três lugares onde a tabela aparece:**

| arquivo | papel |
|---|---|
| `resources/js/Components/Fechamento/TabelaProgressivaFaixas.jsx` | a grade compartilhada (leitura) |
| `resources/js/Pages/Admin/TabelaEmpresa.jsx` | a ficha de edição |
| `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` | o fechamento (só leitura) |

⚠️ Conferir também a tela de conferência das propostas do Clicksign
(`resources/js/Pages/Admin/TabelasContrato.jsx`) — se ela mostra faixas, entra na mesma convenção.
**Duas telas mostrando a mesma tabela em convenções diferentes seria pior que o problema original.**

**Na edição**, o campo passa a receber o piso redondo e gravar `piso − 0,01` como teto da linha
anterior. ⚠️ A **primeira** linha recebe o teto redondo e grava `valor − 0,01` — é a única que
funciona ao contrário das outras, e por isso é a mais fácil de errar.

---

## Travas

⚠️ **Um centavo errado aqui move empresa de faixa.** Trave com teste o caminho digitação → valor
gravado, com pelo menos: a primeira linha, uma intermediária e a última (sem teto).

⚠️ **A validação de sobreposição e buraco continua valendo** (`SalvarFaixasFaturamentoRequest`) e
opera sobre o teto gravado — a conversão acontece antes dela, não no lugar dela.

⚠️ **A máscara de dinheiro já existe** (`resources/js/lib/dinheiro.js`,
`Components/ui/campo-dinheiro.jsx`) — reusar, não recriar.

⚠️ **Copy sem jargão**: proibidos "snapshot", "competência", "reconsolidação", "rollup", "âncora",
"origem", "faixa piso", "presumida" como rótulo técnico. Há teste travando as sete primeiras.

⚠️ **Escala do Tailwind:** `px-4.5`, `gap-4.5`, `py-5.5` não existem — o build passa e nenhum CSS é
gerado. Ao conferir o CSS compilado, use **arquivo `.js` rodado com `node caminho.js`**, nunca
`node -e` inline: já houve falso negativo por escape de barras nesta linha de trabalho.

⚠️ **Árvore compartilhada, outra sessão ativa:** nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. ⚠️ **Não use `gsd-sdk query state.advance-plan`** — a última execução avançou o contador
de outra fase.

⛔ Sem deploy, sem `.env`, sem ligar/desligar a chave `fechamento_tabela_por_empresa_ativa`.
`npm run build` ao final. PHP: `C:\xampp\php\php.exe`. Comentários e commits em pt-BR.

**Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909"`
em **605 testes / 2833 asserções / 0 falhas**.
