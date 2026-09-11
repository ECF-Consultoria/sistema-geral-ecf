---
quick_id: 260911-kio
slug: fechamento-mostra-o-que-importa
date: 2026-09-11
type: quick
status: pending
---

# A tela do fechamento mostra o que é útil

## O pedido do usuário (2026-09-11)

> "Temos que melhorar a eficiência dessa tela e mostrar apenas o que é útil. Percebi que tem muitas
> empresas sem dados de faturamento... acho que não é interessante mostrar essas empresas, se não
> tem dados não serve de nada."

A investigação que respondeu a isso mediu as **81 empresas cobradas** que faturaram menos de
R$ 50 mil em agosto. Elas **não são um grupo só** — e por isso o corte não é "sem integração".

---

## O que a medição em produção encontrou (2026-09-11)

| grupo | quantas | o que é |
|---|---|---|
| **sem integração nenhuma** | **54** | cadastro novo, sem token e sem id: Ab4Store (duas), "Empresa Completa", vários "Novo(a) Deal" |
| **pequenas de verdade** | 25 | as duas fontes concordam — loja pequena mesmo |
| **loja errada** | 1 | MAXIGOLD (tratada fora deste quick) |
| **Mentoria** | 2 | BOX LISBOA e RICARDO SARDAGNA |

⚠️ **As 54 somam R$ 168 mil em cobrança.** Não podem simplesmente sumir: empresa cobrada e
invisível é o tipo de sumiço silencioso que este projeto já pagou caro.

---

## T1 — Mentoria deixa de parecer defeito

**BOX LISBOA** faturou R$ 115.965 em agosto e a tela mostra vazio. **Está certo**: Mentoria não tem
tabela progressiva, então o faturamento não entra na soma (regra D-02 da Fase 141) e a empresa é
cobrada pelo valor do contrato — `estado === 'valor_fixo'`. Mas quem confere lê como erro.

Em `ColunaFaturamento` (`resources/js/Pages/Admin/Financeiro.jsx`, ~linha 774) existe ramo para
`sem_faturamento` e `sem_integracao`; `valor_fixo` **cai no genérico** e imprime um valor vazio.

Dar-lhe ramo próprio, deixando claro que o faturamento **não se aplica** a essa cobrança.

⚠️ **Copy sem jargão** (regra do projeto, com teste travando os termos): proibidos "valor fixo" como
rótulo técnico, "estado", "tabela progressiva", "competência", "rollup", "snapshot". A pessoa
precisa entender que **essa empresa paga um valor combinado em contrato, e por isso o faturamento
não define quanto ela paga**.

---

## T2 — as 54 sem integração saem da lista principal

Lista principal mostra quem tem dado. As `sem_integracao` vão para uma **seção recolhida** no fim,
que abre com um clique, rotulada com **quantas são e quanto somam de cobrança**.

⚠️ **O "Total a receber" continua contando todas** — é dinheiro a receber de verdade. A seção
recolhida diz quanto do total vem dali, para a conta não parecer mentirosa. **Não mexer no cálculo
do total** (`AdminController::fechamentoTotais()`), que é somado sobre as mesmas linhas da tela de
propósito (T-139-05).

⚠️ **Já existe filtro por chip** (`sem_integracao` e `valor_fixo`, ~linha 1482). O recolhimento vale
só quando o filtro NÃO é `sem_integracao` — quem escolheu ver essas empresas tem de vê-las na
lista, não dentro de uma gaveta fechada.

⚠️ **Isto é recorte de exibição, não de dado.** Nenhuma empresa sai do fechamento, do relatório, do
snapshot ou do total. Só muda onde ela aparece na tela.

---

## T3 — queda brusca de faturamento fica visível

Dois casos medidos, ambos **cobrados hoje**, e as duas fontes concordam (não é sync quebrado — a
loja parou mesmo):

| empresa | julho | agosto | set 1-10 | cobrança |
|---|---|---|---|---|
| **MOVELOVEOFICIAL** | R$ 494.502,34 | R$ 22.493,45 | R$ 0,00 | R$ 4.000 |
| **ARMONARE** | R$ 112.467,67 | R$ 6.782,30 | R$ 0,00 | R$ 3.000 |

Hoje isso passa despercebido no meio de 202 linhas. A tela já destaca quem **sobe** de faixa
(`subiu_de_faixa` + widget); falta o irmão simétrico.

**Regra sugerida:** faturamento do mês **abaixo de 50%** do mês anterior **e** mês anterior de pelo
menos **R$ 10.000**. O piso existe para a badge não gritar com ruído de loja minúscula — sem ele,
R$ 200 caindo para R$ 50 vira alarme.

⚠️ **A armadilha desta tarefa:** `subiu_de_faixa` é emitido em **CINCO literais de linha** de
`AdminController::fechamento()` (~825, ~926, ~997, ~1229, ~1371 — empresa ao vivo, congelada com e
sem snapshot, grupo ao vivo, grupo congelado), alimentados pelo helper `derivarUpgrade()` (~331).
**A chave nova precisa sair nos cinco.** Faltar em um produz o defeito clássico desta tela: a
propriedade existe no JSX e nunca chega em algumas linhas — foi exatamente assim que nasceu o
`cobranca_mensal_grupo` fantasma, usado 7× no JSX e nunca emitido pelo backend.

O dado já está à mão: `AdminController::fechamento()` já calcula `$rollupAnterior` (~linha 648).
Não abrir consulta nova.

Escopo: **linhas de empresa**. Grupo fica de fora deste quick (a soma do grupo mistura empresas que
podem ter entrado e saído).

---

## Testes

| caso | espera |
|---|---|
| empresa `valor_fixo` | ramo próprio, sem o valor vazio; copy sem os termos proibidos |
| empresa `ok` | coluna de faturamento inalterada (regressão) |
| lista sem filtro | `sem_integracao` fora da lista principal, dentro da seção recolhida |
| filtro chip `sem_integracao` | empresas aparecem na lista normalmente |
| "Total a receber" | **não muda** com o recolhimento |
| queda ≥50% com mês anterior ≥ R$ 10.000 | marcada |
| queda ≥50% com mês anterior < R$ 10.000 | **não** marcada |
| mês anterior ausente | não marcada, sem erro |
| a chave nova sai nos **5** literais de linha | teste que cubra os cinco ramos |

---

## Travas

⚠️ **Nada de cobrança muda.** `FechamentoFaixaResolver`, `CobrancaCalculator`,
`FechamentoRollupService` e `ConsolidarMesFechamento` **não se tocam**. Este quick é exibição.

⚠️ **Escala do Tailwind:** `px-4.5`, `gap-4.5`, `py-5.5` não existem — o build passa e nenhum CSS é
gerado. Ao conferir o CSS compilado, **`grep -F` sobre o seletor completo**; literal JS com barra
invertida (`hover\:underline`) dá falso negativo tanto em `node -e` quanto em arquivo `.js` com
`String.includes` — essa armadilha já mordeu duas vezes nesta linha de trabalho.

⚠️ **O nome da empresa virou link** (quick `260911-exe`): a linha da listagem é
`<div role="button">` com acessibilidade reposta à mão (`tabIndex`, `aria-expanded`, `onKeyDown`
com `if (e.target !== e.currentTarget) return`). **Não reverter para `<button>`** — âncora dentro de
botão é HTML inválido.

⚠️ **Árvore compartilhada, e o outro dev integrou a milestone v23.0 hoje.** Nunca `git add -A` /
`git add .` / `git commit -a` / `git stash`. `git status --porcelain app/ tests/ resources/` (sem
`--untracked-files=no`) antes de cada commit.

⚠️ **A suíte está vermelha por motivo alheio:** a migration `seed_setor_performance` (do outro dev)
colide com 42 arquivos de teste no `setores.nome` UNIQUE. **Não conserte aqui.** Ao ler o gate,
separe as falhas com `UNIQUE constraint failed: setores.nome` — só as demais contam.

⚠️ **Não use `gsd-sdk query state.advance-plan`.**

⛔ Sem deploy, sem `.env`, sem tocar em `fechamento_faturamento_da_api_ativo` (ligada em produção).

`npm run build` ao final. PHP: `C:\xampp\php\php.exe`. Comentários e commits em **pt-BR**.

**Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910|Quick260911"`
— referência: 657 testes, **28 errors alheios** (`setores.nome`), **zero failures**. Falhas novas
atribuíveis a este quick: **0**.
