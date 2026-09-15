---
quick_id: 260915-jpr
slug: empresa-entra-no-fechamento-pela-data-de-inicio-do-contrato
date: 2026-09-15
type: quick
status: pending
---

# A empresa só entra no fechamento de um mês se o contrato já tinha começado

## O defeito

O fechamento monta a lista de empresas com `Company::where('active', true)` — **toda empresa ativa
HOJE**, sem olhar se ela já era cliente no mês que está sendo fechado.

Medido em produção em 2026-09-15:

| competência | empresas no fechamento que só viraram cliente depois do mês |
|---|---|
| julho/2026 | **39** (R$ 100.001 de cobrança nas que não pertencem a grupo), incluindo cadastros de teste: "TESTE CUTOVER 132 - NAO E CLIENTE", "EMPRESA TESTE 2", "ZZ TESTE FLUXO DE ENTRADA" |
| agosto/2026 | 2 (e refazer agosto hoje colocaria mais 2) |

Refazer um mês passado puxa para dentro dele todo cliente que entrou depois.

---

## A regra — decidida pelo usuário em 2026-09-15

> "A data que a empresa entrou no sistema não significa a data em que iniciamos o trabalho para a
> empresa; tem empresas que já tínhamos como clientes e foram cadastradas bem depois. O mais
> adequado seria usar a data de início de contrato."

**A data é `contratos_servico.data_contratacao`.** É o início de contrato oficial do sistema — o
documento do contrato já usa esse campo como início da vigência (`ContratoPdfService::montarVigencia()`).
Preenchida em 193 das 204 empresas ativas, e editável pela tela.

⛔ **Nunca `companies.created_at`.** Foi exatamente o critério recusado pelo usuário.

### Quando a empresa entra na competência M

Seja `fim` o último dia de M.

| situação dos contratos ATIVOS da empresa | entra? |
|---|---|
| algum contrato com `data_contratacao ≤ fim` | **entra** |
| contrato que começou no meio do mês (ex.: 20/07 em julho) | **entra** — decidido; o fechamento não calcula proporcional, cobra o mês |
| nenhum contrato com data preenchida | **entra, marcada como pendência** — decidido |
| alguns com data (todas depois de `fim`) e algum sem data | **entra, marcada como pendência** — sem data não se prova que não era cliente |
| todos com data, e a menor é depois de `fim` | **sai** |
| nenhum contrato ativo | entra como hoje — **regressão zero** para este caso |

⚠️ **A única forma de sair é ter data preenchida em todos os contratos ativos e todas depois do
mês.** Na dúvida, entra. Sumir com cobrança sem ninguém perceber é o erro que esta regra não pode
introduzir.

---

## T1 — Uma regra só, usada nos quatro lugares

Hoje a lista de empresas é montada **separadamente** em quatro lugares. Se só o comando mudar, a
tela, o relatório e o comparativo passam a divergir dele.

| arquivo | onde |
|---|---|
| `app/Console/Commands/ConsolidarMesFechamento.php` | Passo 1, `Company::where('active', true)` (~linha 233) |
| `app/Http/Controllers/AdminController.php` | `$rawCompanies =` dentro de `fechamento()` (a busca por `\$rawCompanies =` acha) |
| `app/Jobs/EnviarRelatorioFechamentoJob.php` | `$rawCompanies =` |
| `app/Console/Commands/CompararMensalidadeFechamento.php` | `Company::where('active', true)` (~linha 108) |

Criar **um** ponto de verdade (sugestão: `app/Services/Fechamento/FechamentoUniversoEmpresas.php`,
ou scope no `Company`) que recebe a competência e devolve quem entra + quem entra como pendência.
Os quatro lugares passam a usá-lo.

⛔ **Não usar `VerificarConsolidacaoFechamento`** — ele só lê snapshot gravado, não monta lista.

⚠️ O comando e a tela já fazem eager loading de `contratosServico` com `ativo = true`. A regra pode
filtrar sobre isso sem query por empresa dentro do laço de ~200 empresas.

⚠️ Mês corrente na tela ao vivo: `fim` é o **último dia do mês corrente**, não hoje — contrato que
começa no fim deste mês já entra neste mês.

---

## T2 — Quem saiu tem que ser visível

**Nada some em silêncio.**

- `fechamento:consolidar-mes` imprime no resumo quantas empresas **ficaram de fora pela data de
  início** e quantas **entraram como pendência**, com os nomes das que saíram
- a remoção das linhas já existe: `FechamentoSnapshotWriter` apaga empresas e grupos fora da lista
  (`whereNotIn(...)->delete()`, ~linhas 176 e 227). **Confirme com teste** que, ao refazer uma
  competência, a empresa que deixou de entrar sai do snapshot e que a linha de grupo é recontada
  (grupo que perde todas as empresas some; grupo que perde uma muda soma, faixa e contagem)

---

## T3 — A pendência na tela

Na página do fechamento, uma seção listando as **empresas sem data de início de contrato**, com
link para `/administrativo/contratos/empresa/{id}`, onde a data se corrige.

⚠️ **Não crie chave nova nos cinco literais de linha** de `AdminController::fechamento()` para isso.
É uma lista da página, calculada a partir do dado atual — não um atributo de cada linha. (Chave
nova nos cinco literais é exatamente o tipo de mudança em que um literal esquecido cria
propriedade fantasma.)

⚠️ **Copy sem jargão** (há teste travando termos): nada de `data_contratacao`, "competência",
"snapshot", "universo". Algo como *"Empresas sem data de início do contrato — preencha para o
fechamento saber em que mês elas começaram"*.

---

## Testes

| caso | espera |
|---|---|
| contrato com início antes do mês | entra |
| contrato com início no meio do mês | entra |
| contrato com início depois do mês | **sai** |
| empresa sem data em nenhum contrato | entra e aparece como pendência |
| uma data depois do mês + um contrato sem data | entra, pendência |
| duas datas, uma antes e uma depois do mês | entra |
| empresa sem contrato ativo | entra (como hoje) |
| `created_at` recente com `data_contratacao` antiga | **entra** — a data de cadastro não pode influenciar |
| refazer competência em que uma empresa deixou de entrar | a linha dela some do snapshot |
| grupo que perde uma empresa ao refazer | soma, contagem e faixa recalculadas |
| os quatro lugares | mesma lista para a mesma competência |
| resumo do comando | nomes de quem saiu + contagem de pendências |

---

## Travas

⛔ **`FechamentoFaixaResolver::classificar()` não se toca.** ⛔ **O NPS não sente nada.**

⚠️ **Não alterar dado de produção.** A correção das datas de início das empresas antigas é feita
pelo usuário, na tela. Este quick só muda a regra.

⚠️ **Não refazer nenhuma competência.** Julho e agosto são refeitos pelo orquestrador, depois do
deploy e depois de o usuário corrigir as datas.

⚠️ Árvore compartilhada, outra sessão ativa. Nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. `git status --porcelain app/ tests/ resources/` antes de cada commit. **Arquivo novo
precisa de `git add -- <caminho>` explícito antes do `git commit -- <caminhos>`** — o pathspec do
commit não rastreia arquivo novo, e já houve commit que falhou inteiro por isso.

⚠️ **Não use `gsd-sdk query state.advance-plan`.** ⛔ Sem deploy, sem `.env`.

PHP: `C:\xampp\php\php.exe`. `npm run build` se mexer em `.jsx`. Comentários e commits em **pt-BR**.

**Gates (nenhum pode regredir):**
`--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260909|Quick260910|Quick260911|Quick260915"`
— referência **821 passando / 0 falhas**; e `--filter="Phase74|Phase110"` em **39 passando / 0 falhas**.
