---
tipo: quick
slug: uec-usar-estoque-calculado
data: 2026-10-09
status: complete
origem: design_handoff_publicador/ETAPA-3-produto-fases.md §6
---

# Quick 261009-uec — "Usar estoque calculado"

A ação explícita da §6 da Etapa 3 existe: um combo vinculado continua mostrando
`"estoque próprio · calculado do base: N"` e agora tem, **ao lado**, o botão que adota o
calculado — gravando a coluna **antes** de chamar o recálculo, por kit, sem recálculo
automático em lugar nenhum.

Era o **único item de código que faltava** para a Etapa 3 estar cumprida.

## Commits

| hash | o que |
|---|---|
| `4ee46175` | `usarEstoqueCalculado()` no `VinculoDeKitService` |
| `0383d5f3` | endpoint `POST .../estoque-calculado` |
| `1f1ffecf` | botão no cartão da fase |

**Nenhuma migration** — a coluna `estoque_calculado` existe desde a 175-01.

## Gates — medidos pelo executor e reconferidos pelo orquestrador

| gate | antes | depois |
|---|---|---|
| PHP `tests/{Feature,Unit}/Publicador` | 1364 passed | **1374 passed**, 0 falhas |
| JS `npm run test:js` | 1770 / 1768 pass / 2 fail | **1777 / 1775 pass / 2 fail** |
| `npm run build` | — | verde; `Produto.jsx` → `assets/Produto-BroFjRSH.js` |
| `route:list \| grep estoque-calculado` | ausente | presente, grupo `role:admin` |
| `git diff --stat -- database` | — | vazio |

As 2 falhas JS são as pré-existentes (`estrutura-grade-glide`, `polosEntrantes`).

## Decisões

- **A coluna é gravada ANTES do recálculo.** O `RecalculoEstoqueDoKitService` **pula** de
  propósito quem tem `estoque_calculado = false` (combo vinculado tem estoque digitado por
  uma pessoa). Chamar o recálculo primeiro não faria nada.
- **Recálculo resolvido por `app()` sob demanda e FORA da transação.** Injetar no construtor
  fecha o ciclo conhecido do módulo (`EditorRascunhoService` ↔ recálculo) e o container
  recursa; e o recálculo abre a própria transação por kit, com trava de linha — aninhar é
  corrida garantida.
- **A trava `VINC-00` não foi alargada**: `estoque_calculado` já estava na lista `$esperadas`.
- **Recusas novas:** `VINC-07` (não é kit), `VINC-08` (base apagado pelo SET NULL),
  `VINC-09` (base sem rascunho). Já em `true` ⇒ **idempotente**, não é erro.
- **`produtoDaConta()`, nunca `baseDaConta()`** — o segundo devolve `$p->base ?? $p` e num kit
  gravaria no produto errado (o bug que a 175-08 corrigiu).
- **Sem número calculado a ação não existe** no cartão (ação sem objeto); quando existe mas
  falta conta ou `produto_id`, aparece **desabilitada com o motivo** (D23).

## Provas que o plano exigia

**Divide por depósito ANTES de somar.** Fixture em que as duas contas dão números diferentes:
base com `{A:5, B:5}` (total 10) e N=3 ⇒ por depósito `{A:1, B:1}` = **2**; `floor(10÷3)` daria
**3**. O teste assere as duas coisas, então inverter a ordem o derruba nos dois pontos.

**SKU, preço, publicações e MLB do kit não mudam.** Três comparações antes/depois: o
`snapshot()` do rascunho com `estoque`/`estoque_depositos` removidos (byte a byte igual), as
linhas de `pub_publicacao_itens` com `ml_item_id`/`payload` (byte a byte iguais) e a linha de
`pub_produtos` sem `estoque_calculado`/`updated_at`. O que muda e o teste confirma: o estoque
da variante (11 → 2) e a `revisao` do rascunho (a conferência antiga não vale para o estoque
novo).

**Armadilha do Rollup conferida no bundle, não assumida.** O executor extraiu o chunk de
produção e mostrou as flags saindo na mesma cadeia de `const` dentro do callback do `.map()`,
nenhuma eliminada. O handler recebe conta e id **por argumento**, nunca por closure.

## ⚠️ Divergência achada e CORRIGIDA em seguida (quick `261009-div`)

**O número do cartão não era o número que a adoção gravava.**
`FamiliaDeFasesService::fases()` calculava `estoque_calculado_valor` como
`floor($estoqueBase / $quantidade)` sobre o **total** do base, enquanto a adoção divide **cada
depósito** e só depois soma. Em conta multidepósito os dois divergiam: com `{A:5, B:5}` e N=3 o
cartão prometia **3** e o botão gravava **2**.

Já existia antes desta tarefa (veio com a 175-10, que subiu em 09/10) e o executor
corretamente **não** mexeu — era fora do escopo do quick. Mas é número errado numa tela em
produção, contra a regra-mestra de acertividade do projeto, então foi corrigido logo depois:
ver `.planning/quick/261009-div-estoque-calculado-do-cartao/`.

## Divergências menores (a §6 não previa)

- A ação pode ser chamada por URL num **produto base de 1 unidade** — `VINC-07` recusa em vez
  de gravar em silêncio.
- A §6 não diz o que fazer com **base sem rascunho** — `VINC-09` recusa com o mesmo conselho
  do `KIT-01` ("Abra a Fase 1 no editor antes").
