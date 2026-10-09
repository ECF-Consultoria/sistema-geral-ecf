---
tipo: quick
slug: div-estoque-calculado-do-cartao
data: 2026-10-09
status: complete
origem: divergência achada no quick 261009-uec (o defeito nasceu na 175-10)
files_modified:
  - app/Services/Publicador/FamiliaDeFasesService.php
  - tests/Feature/Publicador/TelaDoProdutoTest.php
---

# Quick 261009-div — o cartão e o botão passam a prometer o MESMO número

O cartão de kit mostrava `"estoque próprio · calculado do base: N"` com um N que o botão
**"Usar estoque calculado"** não gravava. Agora os dois saem da **mesma fonte**:
`PreviaDaFaseService::estoqueDoKit()`, dividindo por depósito antes de somar.

O texto do cartão continua idêntico — só o **número** ficou certo.

## Commits

| hash | o que |
|---|---|
| `cc767a6d` | teste RED: cartão (3) × adoção (2) no mesmo cenário multidepósito |
| `768dea2e` | o cartão passa a usar a MESMA regra da adoção |

**Nenhuma migration, nenhum `.jsx`** — é só servidor, então `npm run build` foi dispensado
(`git diff --stat -- resources/js database` conferido literalmente: vazio).

## O defeito

`FamiliaDeFasesService::fases()` calculava `estoque_calculado_valor` como
`(int) floor($estoqueBase / $quantidade)` sobre o **total** do base. A adoção
(`RecalculoEstoqueDoKitService` → `PreviaDaFaseService::estoqueDoKit()`) faz `intdiv` **por
depósito** e só depois `array_sum`.

Com `{A:5, B:5}` e N=3 o cartão prometia **3** e o botão gravava **2**.

Veio com a 175-10 (produção desde 09/10) e só ficou visível quando o botão passou a existir,
no quick `261009-uec`, horas depois. Ninguém havia percebido porque **conta de um depósito com
uma variante só nunca divergiu** — os dois caminhos dão o mesmo valor.

## O conserto

`estoqueTotal()` já consultava as variantes ativas do base, pedindo `['estoque']`. A correção é
**uma coluna a mais no select que já existia** (`estoque_depositos`) mais a divisão pela regra
da adoção — **nenhuma consulta nova** na tela:

- **`estoqueDasVariantesDoBase()`** (nova) lê as variantes ativas no shape que
  `PreviaDaFaseService::estoqueDoKit()` espera. Uma leitura serve aos **dois** números: o total
  do cabeçalho e o calculado de cada cartão.
- **`estoqueTotal()`** passou a somar o array já carregado em vez de consultar. O número do
  cabeçalho do produto **não mudou**.
- **`estoqueCalculadoDoKit()`** (nova) chama `PreviaDaFaseService::estoqueDoKit()` por variante
  e soma. A regra do `floor` **não foi reimplementada** — duas implementações da mesma divisão
  é exatamente como o defeito nasceu. Conferido: `floor($estoqueBase` tem **zero** ocorrências
  no arquivo agora.
- `PreviaDaFaseService::estoqueDoKit()` é **estático**, então nem `app()` foi necessário: zero
  dependência nova no construtor. Importava evitar isso — o módulo tem ciclo conhecido no
  container (`EditorRascunhoService` ↔ recálculo, `ProgramasPublicadorService` ↔
  `SugestaoDeKitService`).

## ⚠️ O conserto é mais amplo do que o plano descrevia

O plano falava só do caso multidepósito. A conta nova também corrige **base sem depósitos mas
com VÁRIAS variantes ativas**: duas variantes de 5 com N=3 dava `floor(10÷3) = 3` e agora dá
`1 + 1 = 2`. É a mesma classe de defeito (a adoção grava **por variante**) e o valor novo é o
que o botão realmente grava. **Variante única, com ou sem depósitos, não muda.**

## Provas

**O cartão promete o que o botão grava.** A prova está num teste só, no mesmo cenário — não em
dois lugares separados: `test_estoque_calculado_do_cartao_divide_cada_deposito_como_a_adocao`
lê `estoque_calculado_valor` do payload da tela, assere **2**, chama
`VinculoDeKitService::usarEstoqueCalculado()` e assere que o estoque gravado na variante do kit
é **exatamente o número do cartão**. Antes do conserto esse assert caía:
`Failed asserting that 3 is identical to 2`.

**Não-regressão.** `test_estoque_calculado_de_um_deposito_so_ou_sem_deposito_segue_o_mesmo`:
dois bases, um com `{A:10}` e um sem depósitos, ambos `estoque = 10`, kit N=3 — cartão **3** nos
dois e adoção gravando **3** nos dois. Esse teste **já passava antes do conserto**, e é por isso
que ele serve de não-regressão.

**O total do cabeçalho não mudou:** o mesmo teste assere `base.estoque_total === 10`.

## Gates

| gate | antes | depois |
|---|---|---|
| PHP `tests/{Feature,Unit}/Publicador` | 1374 passed | **1376 passed**, 0 falhas |
| `TelaDoProdutoTest` + `VinculoDeKitTest` | — | **59 passed** (312 asserções), reconferido pelo orquestrador |
| `git diff --stat -- resources/js database` | — | **vazio** |
| `vendor/bin/pint` | não rodado (proibido neste módulo) | não rodado |

## Fora de escopo, não tocado

`PreviaDaFaseService` (é a fonte da regra correta — só consumida), `RecalculoEstoqueDoKitService`,
`VinculoDeKitService`, `App\Support\Publicador`, `MlPublicacaoService`, os `.jsx` do assistente
antigo e `usePublicador.js`. Nenhum deploy, nenhum push, nada no VPS.
