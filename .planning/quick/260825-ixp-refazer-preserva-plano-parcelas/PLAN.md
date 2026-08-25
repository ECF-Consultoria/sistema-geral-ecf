---
quick_id: 260825-ixp
slug: refazer-preserva-plano-parcelas
date: 2026-08-25
status: done
---

# "Refazer contrato" perde a frase do parcelamento que a pessoa escreveu

## O incidente (produção, 2026-08-25, Maderatto)

O Administrativo editou a frase do parcelamento na tela, salvou, clicou em **Refazer contrato** —
e a frase editada **não apareceu** no contrato novo.

Medido:

| contrato | status | `plano_parcelas_texto` |
|---|---|---|
| 23 (atual) | rascunho | **null** — voltou ao texto composto |
| 22 | cancelado | tem o texto editado |
| 21 | cancelado | tem o texto editado |

## A causa

`plano_parcelas_texto` é coluna de **`contrato_assinaturas`** (quick `260824-bte`, Tarefa 3a) — o
override pertence ao contrato, e isso está certo: é texto que aquele contrato congelou.

Mas `refazer()` (quick `260825-dap`) cria um `ContratoAssinatura` **novo**, que nasce com a coluna
`null`. A edição fica no contrato cancelado.

E o fluxo natural é justamente **editar → refazer**: enquanto o envelope já existe, editar o texto
não muda nada na Clicksign. Ou seja, o único caminho para a edição valer é o refazer — que é
exatamente onde ela se perde.

## A correção

Em `ContratoAdminController::refazer()`, depois de gerar o novo contrato: **transportar
`plano_parcelas_texto`** do contrato antigo para o novo.

⚠️ `dispararSeElegivel()` pode criar **mais de um** contrato (um por serviço). Só o contrato novo
do **mesmo `servico_id`** do antigo herda o texto — nunca todos.

⚠️ Se o antigo não tinha override (`null`), o novo continua `null` e usa o texto composto. Não
inventar valor.

⚠️ Transporte **literal**, sem recompor: se o texto composto mudou (porque as fases mudaram), quem
manda é o que a pessoa escreveu — ela vê o campo na tela e pode corrigir antes de enviar.

Registrar no activity log que o texto foi transportado, junto do rastro que o `refazer()` já grava
ligando contrato antigo → novo.

## Testes

- refazer com override preenchido → o contrato **novo** nasce com o **mesmo texto**, literal
- refazer sem override (`null`) → novo continua `null` e usa o composto (regressão zero)
- empresa com **dois serviços**, override só em um → só o contrato do mesmo `servico_id` herda; o
  outro nasce `null`
- o texto transportado é o que sai em `{{plano_parcelas}}` do contrato novo (a prova de ponta a
  ponta, que é o que falhou para o usuário)

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- Provavelmente sem mudança de JSX → só rode `npm run build` se mexer em `.jsx`.
- Comentários em pt-BR. Commits atômicos.
