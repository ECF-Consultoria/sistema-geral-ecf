---
quick_id: 260904-kwz
slug: lastro-da-tabela-progressiva
date: 2026-09-04
type: quick
status: in-progress
files_modified:
  - app/Http/Controllers/AdminController.php
  - resources/js/Pages/Admin/Financeiro.jsx
  - tests/Feature/Phase139/Phase139LastroTabelaTest.php
---

# A tela diz quando a tabela foi assumida, não confirmada

## A correção que o usuário fez (2026-09-04)

> "Não são todas empresas que têm tabela progressiva, são só as que têm contrato com tabela
> progressiva (isso pode consultar no Clicksign) ou nas empresas que nós cadastrarmos manualmente a
> tabela progressiva pelo sistema."

O sistema hoje faz o contrário: aplica a tabela do **serviço** a toda empresa que tem aquele serviço,
sem olhar se existe contrato ou cadastro. Foi assim que 127 empresas saíram com mensalidade no
fechamento de agosto.

## O tamanho do problema, medido em produção (2026-09-04)

| | |
|---|---|
| empresas no fechamento de agosto | 201 |
| com tabela vinda do **serviço** | 167 |
| cadastros manuais de tabela | **0** |
| empresas com contrato assinado no sistema | **3** |
| **com mensalidade hoje, sem contrato nem cadastro** | **127** |
| soma mensal sem lastro | **R$ 460.500,00** |

## Decisão do usuário

Entre aplicar a regra estrita agora (127 → 3 empresas com valor), cadastrar tudo de uma vez, ou
tornar a origem visível, ele escolheu **tornar a origem visível sem tirar o valor**.

O fechamento continua utilizável, a equipe vai cadastrando as tabelas reais, e ninguém cobra achando
que está confirmado o que na verdade foi assumido.

## A regra a implementar

Uma empresa tem tabela **com lastro** quando:

1. tem **cadastro manual** — `tabela_origem` é `empresa` ou `grupo`; **ou**
2. tem **contrato assinado no sistema** (`contrato_assinaturas.status = 'assinado'`) — nesse caso a
   tabela do serviço é legítima, porque é a tabela que está escrita no contrato.

Caso contrário, a tabela foi **assumida** a partir do serviço.

> ⚠️ Assumida **não** é erro: a maioria dessas empresas está em contrato de tabela progressiva no
> mundo real — o sistema é que não sabe. O ponto é parar de afirmar certeza que não existe.

## Tarefas

### 1. O backend diz de onde veio

Emitir, por empresa e por grupo, se a tabela tem lastro e qual é a origem, nos **dois ramos** de
`AdminController::fechamento()` (mês aberto e mês fechado) e em **todos os literais de linha**.

⚠️ São **cinco** literais de linha, já mapeados em fases anteriores — empresa ao vivo, empresa
congelada com snapshot, empresa congelada sem snapshot, grupo ao vivo, grupo congelado. Um dado que
esquece um ramo é o defeito recorrente desta linha de trabalho.

Emitir também, junto dos `totais`, **quantas empresas estão com tabela assumida**.

⚠️ Consultar contratos assinados **sem N+1** — uma consulta em massa antes do laço, como o
`FechamentoComparativoService` já faz.

### 2. A tela mostra, sem alarmar

- Na linha da empresa: distinguir tabela **confirmada** de **assumida**. Discreto — não é erro, é
  ausência de confirmação.
- No topo: contador de quantas estão assumidas, com caminho para resolver (o cadastro já existe).
- ⚠️ **Copy sem jargão**, em pt-BR. Nada de "lastro", "origem", "tabela_origem", "snapshot". A pessoa
  precisa entender que aquela tabela foi **presumida a partir do serviço** e que dá para cadastrar a
  real.
- ⚠️ Não confundir com os estados que já existem: `A DEFINIR` (não há tabela nenhuma) e
  "Sem faturamento neste mês" continuam significando outra coisa.

### 3. Testes

- A regra dos dois caminhos de lastro (cadastro manual **ou** contrato assinado).
- Empresa sem nenhum dos dois → marcada como assumida, **mas mantendo o valor**.
- O contador bate com a contagem real.
- Cobertura nos dois ramos (aberto e fechado), por reconsulta ao banco.

## Restrições

- Não regredir `Phase137*`, `Phase138*`, `Phase139*`.
- **Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139"` em **335 testes / 1717
  asserções / 0 falhas**.
- ⚠️ `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa...` é flaky pré-existente (registrado em
  `.planning/todos/pending/260904-teste-flaky-aviso-mudanca-faixa.md`) — se falhar, rodar isolado e
  **não consertar**.
- ⚠️ Escala do Tailwind: `px-4.5`, `gap-4.5`, `py-5.5` **não existem**; o build passa e nenhum CSS é
  gerado. Conferir no CSS compilado (com script Node — o shell escapa mal os colchetes).
- Árvore compartilhada: nunca `git add -A` / `git add .` / `git commit -a` / `git stash`.
  Não são seus: `tests/Feature/CompanyPortfolioAccessTest.php`, `public/images/*`, os `.docx` da
  raiz, `design_handoff_fechamento/`.
- `npm run build` ao final. PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em pt-BR.
- ⛔ Não fazer deploy. Não mexer no `.env`.
