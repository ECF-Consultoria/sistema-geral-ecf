---
quick_id: 260909-lge
slug: fechamento-filtros-e-escopo
date: 2026-09-09
type: quick
status: in-progress
files_modified:
  - app/Http/Controllers/AdminController.php
  - resources/js/Pages/Admin/Financeiro.jsx
  - tests/Feature/Phase140/Phase140FechamentoEscopoTest.php
---

# Fechamento: filtro por serviço, escopo por setor e a conta explicada

Quatro pedidos do usuário em 2026-09-09, olhando a tela em produção.

---

## 1. Filtrar por serviço na listagem

Hoje não dá. Deve dar.

> A prop `servicos_disponiveis` já é enviada para a tela — conferir antes de criar coisa nova.

---

## 2. Serviços do grupo aparecem repetidos

Numa linha de grupo, o mesmo serviço aparece uma vez por empresa: `Gestão Gestão Gestão…`.
Agrupar com a quantidade ao lado: **`Gestão 5`**.

---

## 3. O escopo do fechamento é setor `performance` + `shopee`

Ainda aparecem empresas de Polos e Publicação — o usuário citou `aThshop`, `Cortês`, `JF assessoria`,
`Tobias teste`. A regra que ele deu: *"no fechamento só deve ter empresas do setor performance
(Gestão, Mentoria, Brigada e Gestão Shopee)"*.

Medido em produção (2026-09-09), os setores são:

| setor | serviços |
|---|---|
| **performance** | Gestão, Mentoria, Brigada |
| **shopee** | Gestão de ADS Shopee |
| publicacao | Publicação |
| polos | Polos |
| outros | Assessoria, Incubadora, Publicidade, Implantação |

O filtro atual (`fechamentoRemoverForaDeEscopo`, quick `260909-e8n`) exclui **apenas** `SETOR_POLOS`.
Ampliar para: serviço cobrável = setor **`performance` ou `shopee`**.

### ⚠️ A tensão a resolver, não a decidir em silêncio

O filtro existente tem uma válvula de segurança: a empresa **permanece** se tiver faturamento,
cobrança calculada, ou for de grupo/hierarquia. O comentário no código explica que esconder linha que
representa dinheiro é pior que a poluição.

Agora essas duas regras se chocam: uma empresa de **Publicação com faturamento** seria mantida pela
válvula, mas o usuário disse que ela não deve estar aqui.

**Resolver assim:** o setor manda sobre "tem faturamento" — se a empresa não tem serviço de
performance/shopee, ela não é cobrada neste fechamento e o faturamento dela é irrelevante aqui.

⚠️ **Mas a trava de GRUPO permanece inegociável:** tirar um membro muda a **soma do grupo** e pode
derrubar a faixa cobrada do grupo inteiro. O comentário do `260909-e8n` cita o caso real da Interior
Magazine dentro do grupo Utilar. Se a empresa é membro de `CompanyGroup`, ela continua contando para
a soma — mesmo que não apareça como linha própria.

---

## 4. A mensalidade precisa mostrar a conta

**Caso medido — BARAOSHOP VARIEDADES, agosto/2026:**

```
faturamento ............ R$ 488.262,90
faixa aplicada ......... faixa 1
valor da faixa ......... R$ 3.000,00
```

Mas a tela mostra **R$ 5.500,00**, porque a empresa tem dois serviços ativos:

```
Gestão (performance)          R$ 3.000   pela faixa
Gestão de ADS Shopee (shopee) R$ 2.500   valor de contrato
                              ────────
                              R$ 5.500
```

A regra hoje é *"faixa + soma dos contratos mensais"* (`CobrancaCalculator`). O número está certo como
total, mas a tela põe "Faixa 1" e "R$ 5.500" lado a lado sem dizer que são duas parcelas somadas —
e se lê como "faixa 1 = R$ 5.500".

**Mostrar a composição**, como já se faz com o faturamento (Mercado Livre + Shopee).

> ⚠️ **Isto é paliativo e está assim de propósito.** O usuário decidiu em 2026-09-09 que a tabela
> progressiva passa a ser **da empresa/grupo**, com o faturamento das plataformas somado e **uma
> única** faixa — a soma de mensalidades deixa de existir. Isso é uma fase própria. Aqui só se
> resolve a ilegibilidade, sem mexer no cálculo.

---

## Fora de escopo

- **Não** mudar `CobrancaCalculator` nem a regra de cobrança — é a fase seguinte.
- **Não** mexer no cadastro de tabela progressiva — é outra fase.

## Restrições

- Não regredir `Phase137*`, `Phase138*`, `Phase139*`, `Phase140*`.
- **Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140"` em **465 testes / 2281
  asserções / 0 falhas**.
- ⚠️ `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa...` é flaky pré-existente.
- ⚠️ Escala do Tailwind: `px-4.5`, `gap-4.5`, `py-5.5` **não existem** — build passa e nenhum CSS é
  gerado. Conferir no CSS compilado com script Node.
- Copy sem jargão, pt-BR. Árvore compartilhada: nunca `git add -A`/`git add .`/`git commit -a`/
  `git stash`. `npm run build` ao final. ⛔ Sem deploy, sem `.env`.
