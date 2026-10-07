---
quick_id: 261007-m0t
slug: cnpj-repetido-nao-bloqueia-o-cadastro
date: 2026-10-07
type: quick
status: pending
---

# CNPJ repetido deixa de dar erro 500 e passa a ser aviso

## O incidente, medido em produção (2026-10-07)

O Administrativo está completando o cadastro das empresas em
`/administrativo/contratos/empresa/{id}` e o botão **Salvar cadastro** devolve **erro 500**. Log de
produção, três tentativas seguidas:

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
'38.196.897/0001-43' for key 'companies.companies_cnpj_unique'
(SQL: update `companies` set ...)
```

`companies.cnpj` tem índice **unique** desde a migration original
(`2026_04_26_152217_create_companies_table.php`, linha 17). `ContratoAdminController::atualizarCadastro()`
(~linha 1137) valida `cnpj` só como `['nullable','string','max:20']` e chama `$company->save()` — a
violação de unicidade sobe como `QueryException` e virou 500 sem mensagem nenhuma.

## Por que a correção NÃO é validar e bloquear

**CNPJ repetido é legítimo neste negócio.** Uma empresa jurídica opera várias lojas de marketplace, e
cada loja é um registro de `companies`. Medido hoje: **12 CNPJs aparecem em 2 empresas cada**, vários
no MESMO grupo — KAITONCOMERCIO #191 / LOJAELASTIM #447 (grupo 14), MAXIGOLD #234 / Nutrifour #426
(grupo 11), Utilarshop #368 / Ita Prime #384 (grupo 10), ELLITE #137 / UNIQPRIME #314 (grupo 12).
O usuário confirmou o padrão com as próprias palavras sobre a LOJAELASTIM: *"usa o mesmo contrato do
Kaiton, é uma empresa secundária da Kaiton"*.

O caso do incidente também é legítimo: **#184 Prensar - ZURCDECOR** (loja ML 649444951, R$ 482.886,81
em setembro) e **#249 FUTURE RCLCOMERCIO** (loja ML 1201778632, R$ 108.510,11) são **duas lojas reais**
da mesma razão social, cada uma com conta própria e 134 dias de métricas.

⚠️ E o índice **já não funcionava**: em TODOS os 12 pares um registro guarda só dígitos
(`38196897000143`) e o outro com pontuação (`38.196.897/0001-43`), então as strings diferem e o unique
nunca pegou. Ele só aparece quando alguém digita no formato do outro registro — ou seja, bloqueia
trabalho legítimo de forma aleatória.

**Decisão do usuário (2026-10-07):** remover a exigência de CNPJ único e colocar no lugar um **aviso
visível** quando outra empresa já tiver o mesmo CNPJ.

## T1 — Migration: o unique sai, um índice comum entra

- dropar o índice `companies_cnpj_unique` e criar um índice **não-único** em `cnpj` (a coluna é usada
  em busca; sem índice nenhum a consulta do aviso do T2 fica ruim)
- ⚠️ **idempotente** e com **branch de SQLite**: em SQLite o `dropUnique` por nome falha diferente, e
  a suíte roda em SQLite. Use `Schema::hasIndex`/try-catch como as migrations irmãs do projeto fazem.
- ⚠️ o `down()` tem de recriar o unique — e **vai falhar** enquanto existirem os 12 pares. Deixe isso
  escrito no docblock: a volta exige limpar os duplicados primeiro. Não tente "consertar" dados no
  `down()`.
- ⛔ **Não** normalize o formato do CNPJ gravado nesta migration. Mexer em 12 pares de dado de
  produção é passo humano separado, e mudaria o que a tela mostra hoje.

## T2 — O aviso na tela

Em `ContratoAdminController::show()` (renderiza `Admin/ContratoDetalhe`, ~linha 666): nova **prop de
página** com as OUTRAS empresas que têm o mesmo CNPJ da empresa aberta, comparando por
**dígitos** (`App\Support\Cnpj::digitos()`, que já existe) — é a comparação que enxerga os 12 pares
que o unique não via.

Cada item: `id`, `name`, `active`. Lista vazia → **nenhum aviso aparece** (sem alarme quando não há
nada).

No JSX da tela, um aviso **discreto**, no molde dos avisos que já existem no fechamento
(`SemDataInicioAviso` / `NaoParticipamAviso` / `DadoMudouDepoisAviso` em `Admin/Financeiro.jsx`):
copy **sem jargão**, algo como *"Este CNPJ também está cadastrado em: X, Y. Isso é normal quando a
mesma empresa tem mais de uma loja — confira se é esse o caso."* ⛔ **Não** bloqueia nada, ⛔ não
desabilita o botão.

## T3 — Rede de segurança no salvar

`atualizarCadastro()` passa a envolver a gravação em `try/catch (\Illuminate\Database\QueryException)`
→ `Log::error` com `company_id` e `user_id` + `return back()->with('error', ...)` com copy sem jargão.
Motivo: **nunca mais um 500 em cima do formulário do Administrativo**, mesmo que apareça outra
restrição no futuro. ⚠️ Mantenha o `abort(422)` dos dois guards de pertencimento (IDOR) exatamente
como está — eles não são erro de banco.

## T4 — Testes

Em `tests/Feature/Quick261007/`:

| caso | espera |
|---|---|
| salvar cadastro com CNPJ que outra empresa já tem | **grava** (sem 500, sem erro de validação) |
| duas empresas com o mesmo CNPJ, formatos diferentes | as duas convivem no banco |
| a tela lista a outra empresa do mesmo CNPJ | prop presente, com id e nome |
| empresa com CNPJ exclusivo | prop **vazia** (nenhum aviso) |
| comparação por dígitos | `38196897000143` casa com `38.196.897/0001-43` |
| erro de banco no salvar | volta com mensagem, **sem 500**, e loga |
| guards de IDOR | seguem devolvendo 422 |

**Gate:** `--filter="Phase131|Phase132|Phase133|Phase152|Quick260819|Quick260821|Quick260824|Quick261007|Contrato"`
— rodar **antes** de editar e ao final, capturando o exit **antes** de qualquer pipe. Registre no
SUMMARY as falhas pré-existentes, se houver, para não serem confundidas com regressão.

## Fora de escopo

⛔ Nada de produção, VPS, `plink`, `pscp`, `.env`, deploy, `cache:clear`. A migration em produção é
passo do orquestrador.
⛔ Não normalizar os CNPJs já gravados. ⛔ Não mexer em `FechamentoFaixaResolver`,
`FechamentoSnapshotWriter`, `FechamentoEmpresasDoMes`, `podeUsarApiDaAdman()`,
`ShopeeService`, `TabelaDeContratoAssinadoService`.
⛔ Não decidir nada sobre o grupo da #184/#249 (há uma questão de cobrança em aberto com o usuário).

## Restrições de árvore

⚠️ Árvore compartilhada com outro dev e outras sessões. Nunca `git add -A` / `git add .` /
`git commit -a` / `git stash`. `git status --porcelain app/ tests/ database/ resources/ .planning/`
antes de cada commit e commitar **só** pelos seus caminhos; arquivo novo precisa de
`git add -- <caminho>`. `tests/Feature/CompanyPortfolioAccessTest.php` não é seu.
Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. `npm run build` ao final (mexe em JSX). Comentários, copy e commits em
pt-BR, terminando com `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

Grave `SUMMARY.md` nesta pasta (via Bash — o hook bloqueia a ferramenta Write para `.md`).
