---
quick_id: 260821-odj
slug: salvar-cadastro-nao-recusa
date: 2026-08-21
status: complete
---

# O salvar do cadastro para de recusar, e a lista de pendências diz O QUE falta

## Incidente (produção, 2026-08-21, Mons Bike `company_id=430`)

O Administrativo preencheu razão social, CNPJ e os 5 campos de endereço e apertou
**Salvar cadastro duas vezes**. Nada foi gravado.

Provas:
- `companies.updated_at` == `created_at` (`16:47:44`) — nenhuma escrita aconteceu
- dois `PATCH /administrativo/contratos/empresa/430/cadastro` no access log
  (`19:55:00` e `19:58:29`), **ambos `303`** — redirect de erro de validação do Inertia

**Causa medida:** `companies.nome_contato` = `"Vitor"` — uma palavra só, veio do contato do
HubSpot. `ContratoAdminController::atualizarCadastro()` valida `nome_contato` com
`new NomeCompletoValido()`. O formulário reenvia esse campo **mesmo sem ninguém tocar nele**,
a Rule reprova, e `$request->validate()` rejeita a **requisição inteira** — razão social e
endereço, que não tinham problema nenhum, não são gravados junto.

**Alcance medido em produção:**

| | quantas | quais |
|---|---|---|
| `nome_contato` de uma palavra só | 2 | 430 Mons Bike (`Vitor`), 399 SOS Casa Loja (`Jaqueline`) |
| CNPJ inválido pelo dígito | 2 | 335 `teste`, 424 `TESTE CUTOVER 132` (ambas de teste) |

A armadilha do `new CnpjValido()` é **estruturalmente idêntica** — só não pegou cliente real
ainda.

## Por que é seguro tirar do salvar

`ContratoDadosMinimosService::faltantes()` — o gate da **geração** — já checa os dois
(verificado no código):

- linha 91: `! Cnpj::valido(...)` → `motivo: 'formato'`
- linha 111: `! NomeCompleto::valido(...)` → `motivo: 'formato'`

Nada chega à Clicksign sem validação. A validação no **salvar** é redundância que só perde
dado digitado.

⚠️ **Não afrouxar o gate da geração.** A exigência de nome completo existe porque a Clicksign
devolve `400 "name não está em um formato válido"` para palavra única **depois** de já ter
criado envelope e documento (~6 min até `status = erro`, caso real medido). O usuário foi
informado dessa distinção salvar/gerar e concordou.

## Tarefa 1 — o salvar aceita o que a pessoa digitou

Em `ContratoAdminController::atualizarCadastro()`, remover das regras de validação **apenas as
duas Rules semânticas**:

- `new CnpjValido()` do campo `cnpj`
- `new NomeCompletoValido()` do campo `nome_contato`

**Manter** `nullable`, `string`, `max:20` / `max:255`, e **manter** `'email'` em
`email_cliente` (é formato, não semântica, e o campo é digitado ali mesmo).

Ajustar os comentários pt-BR que hoje explicam a presença dessas Rules, registrando **por que
saíram**: redundantes com o gate da geração, e bloqueavam a gravação de campos não
relacionados.

- ⚠️ **Não apagar** as classes `CnpjValido` / `NomeCompletoValido` nem os helpers
  `App\Support\Cnpj` / `App\Support\NomeCompleto` — seguem em uso pelo gate da geração.
- ⚠️ **Não mexer** na guarda de IDOR (`$contratoServico->company_id !== $company->id` →
  `abort(422)`) nem na ordem "valida pertencimento de TODOS antes de gravar qualquer um".

## Tarefa 2 — a lista de pendências diz O QUE falta

`ContratoDetalhe.jsx` (~linha 294) hoje renderiza só `item.rotulo`. Com a Tarefa 1, essa lista
vira o **único** lugar que avisa — e ela não distingue "campo vazio" de "campo preenchido mas
em formato inválido".

Sem isso, a pessoa veria *"Nome de quem assina pela empresa"* numa lista de pendências com o
campo visivelmente preenchido na tela ao lado. **Isso é pior que o bug original.**

Diferenciar usando o `motivo` que `faltantes()` já devolve (`ausente` | `formato`), com copy
**sem jargão** (regra do projeto):

- `ausente` → o rótulo como está hoje
- `formato` → o rótulo + o que exatamente está errado, por campo:
  - `nome_contato`: precisa de nome e sobrenome (quem assina precisa do nome completo)
  - `cnpj`: o número não confere

- ⚠️ **Primeiro verifique** se `motivo` realmente chega ao componente — `faltantes` é prop do
  Inertia e o controller pode não estar repassando a chave. Se não chegar, faça chegar; mas
  existe **teste de whitelist de props (PII)** que fixa o conjunto exato exposto — rode e
  ajuste.
- ⚠️ `motivo` é contrato **público** limitado a `ausente|formato`. Não inventar valor novo.

## Testes

- Empresa com `nome_contato` de uma palavra só (`"Vitor"`): `atualizarCadastro()` **grava**
  razão social/CNPJ/endereço normalmente — é a regressão do incidente.
- Empresa com CNPJ inválido pelo dígito: idem, grava os demais campos.
- Regressão zero: `email_cliente` inválido continua recusado; a guarda de IDOR continua
  abortando 422 **sem gravar nada**.
- `faltantes()` **não mudou**: nome de uma palavra e CNPJ com dígito errado continuam
  bloqueando a **geração** com `motivo: 'formato'`.
- A tela mostra texto diferente para `ausente` e para `formato`.

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase126|Phase127|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (o outro dev acabou de mergear 28 commits de onboarding/portal):
  nunca `git add -A` / `git add .` / `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/` **sem**
  `--untracked-files=no`, e conferir os `??`.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- `npm run build` ao final (mexe em JSX). Comentários e copy em pt-BR. Commits atômicos.
