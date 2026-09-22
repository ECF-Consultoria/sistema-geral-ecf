---
quick_id: 260922-gn1
slug: faturamento-por-empresa-ao-expandir-o-grupo
date: 2026-09-22
type: quick
status: pending
---

# Ao expandir um grupo, o fechamento mostra o faturamento de cada empresa

## O pedido (usuário, 2026-09-22)

> "No fechamento, nos casos dos grupos, seria legal que ao expandir o grupo mostrasse o faturamento
> de cada empresa do grupo para poder fazer a conferência."

Hoje a seção **"Composição do grupo"** (`resources/js/Pages/Admin/Financeiro.jsx`, ~linha 1280) lista
cada empresa com **nome**, **origem da tabela** e **cobrança** — mas **não mostra o faturamento**, que é
justamente o número que a pessoa quer somar para conferir o total do grupo.

O dado **já chega na tela**: o backend monta `filhas[]` com a linha inteira de cada empresa, incluindo
`faturamento`, `faturamento_ml` e `faturamento_shopee`
(`AdminController::fechamentoAgregarGruposAoVivo()` ~1240 e `...Congelados()` ~1415 — `$linhasMembros`).
**Nenhuma chave nova é necessária no backend**; confirme antes de mexer nele.

---

## T1 — Faturamento por empresa na composição

Em cada linha da composição, ao lado da cobrança, mostrar o **faturamento daquela empresa** no mês.

- formato igual ao resto da tela (`fmtBRL`), alinhado em coluna, fonte mono
- empresa sem faturamento (`null`) mostra `—`, **nunca R$ 0,00** — "não temos o dado" e "vendeu zero"
  não podem ficar iguais (é a mesma disciplina de `pontos_componentes` do desempenho)
- a linha **"Total do grupo"** (~1345) ganha o faturamento total do grupo na mesma coluna, para a soma
  fechar à vista. O total já está em `empresa.faturamento` da linha-mãe
- se a empresa tem faturamento de duas plataformas, mostrar o total; ML e Shopee separados podem ir no
  `title` (tooltip), sem poluir

⚠️ **Não recalcule nada no cliente.** O total do grupo é o que o backend mandou; a tela **não** deve
somar as filhas para exibir o total (se um dia divergir, quem precisa aparecer é a divergência, não uma
soma inventada pela tela).

---

## T2 — A composição para de repetir o grupo

Hoje a lista é `[empresa, ...empresa.filhas]` (~1315), mas **`filhas` já contém TODAS as empresas do
grupo, inclusive a âncora**. Como a linha-mãe de um grupo é sintética e usa o **nome do grupo**, o
resultado é:

```
MPozenato (este)        ← a linha do grupo
↳ MPozenato             ← a empresa MPozenato, de novo
↳ POZELAR
...
```

e o selo da listagem (~964) diz **`Grupo · {filhas.length + 1}`** → "Grupo · 11" para 10 empresas.

**Corrigir na tela** (não no backend — `filhas` é consumida em mais de um lugar):
- a composição de um grupo lista **só as empresas** (`empresa.filhas`), sem a linha "(este)"
- o selo passa a contar `filhas.length`
- ⚠️ `GrupoServicosDivergentesBanner` (~688) faz o **mesmo** `[empresa, ...filhas]` — conferir e
  corrigir junto, senão o aviso de tabelas divergentes conta o grupo como se fosse empresa

⚠️ **Cuidado com o nome `filhas`:** existe OUTRO `filhas` nesta base, em
`AdminController` ~linha 61, que são **empresas-filhas por `parent_company_id`** (só `id` e `name`),
coisa diferente. Confirme qual delas cada trecho da tela está lendo antes de mexer.

⚠️ Linha de empresa comum (sem grupo) não pode mudar em nada.

---

## T3 — Continua verdadeira a ressalva do mês fechado

O bloco de subgrupos (~1289) e o aviso `subgrupos_sao_de_hoje` **não mudam**. Mês fechado continua
dizendo que a divisão mostrada é a de hoje.

---

## Testes

Não há runner de JS no projeto: as travas de tela são testes PHP que leem o `.jsx`
(molde: `tests/Feature/Quick260916/TelaAcompanhaRefazerTest.php`).

| caso | espera |
|---|---|
| composição mostra faturamento por empresa | o `.jsx` lê `faturamento` dentro do map das filhas |
| faturamento ausente | mostra `—`, não `R$ 0,00` |
| total do grupo com faturamento | a linha de total exibe o faturamento da linha-mãe |
| a tela não soma as filhas | nenhuma soma/`reduce` de faturamento no JSX |
| composição não repete o grupo | o map é sobre `filhas`, sem `[empresa, ...]` |
| selo conta certo | `filhas.length`, sem `+ 1` |
| `GrupoServicosDivergentesBanner` | mesma correção |
| copy | sem jargão ("snapshot", "rollup", "âncora", "raiz", "competência") |

Se der para cobrir por teste de props/render server-side já existente (`Phase143ComposicaoUiTest`), use
o mesmo molde em vez de criar um novo estilo.

---

## Travas

⛔ **Backend só se for indispensável.** A expectativa é **zero** mudança em `AdminController` — o dado
já vai. Se precisar mexer, a chave tem de sair nos **cinco** literais de linha (empresa ao vivo,
congelada com e sem snapshot, grupo ao vivo, grupo congelado) — faltar em um cria propriedade fantasma.

⛔ Não tocar em `FechamentoFaixaResolver::classificar()`, `FechamentoRollupService`,
`FechamentoSnapshotWriter`, `FechamentoEmpresasDoMes`.

⚠️ **Escala do Tailwind:** `px-4.5`, `gap-4.5`, `py-5.5` não existem; conferir com `grep -F` no CSS.
⚠️ A linha da listagem é `<div role="button">` com acessibilidade reposta à mão — não reverter para
`<button>`.

⚠️ Árvore compartilhada com outra sessão. Nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. `git status --porcelain app/ tests/ resources/` antes de cada commit; arquivo novo precisa
de `git add -- <caminho>` explícito. `tests/Feature/CompanyPortfolioAccessTest.php` não é seu.

⚠️ Não use `gsd-sdk query state.advance-plan`. ⛔ Sem deploy, sem `.env`, sem VPS, sem alterar produção.

`npm run build` ao final. PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em **pt-BR**.

**Gate (nenhum pode regredir, exit capturado antes de qualquer pipe):**
`--filter="Phase137|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260915|Quick260916|Quick260922"`
— rode ANTES de editar para registrar a referência, e de novo ao final.

Ao final, grave `SUMMARY.md` nesta pasta; se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final para o orquestrador gravar.
