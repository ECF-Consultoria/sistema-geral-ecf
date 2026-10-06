---
quick_id: 261006-gf5
slug: tabela-confirmada-na-assinatura-do-contrato
date: 2026-10-06
type: quick
status: completo
---

# Contrato assinado pelo sistema grava a tabela como confirmada

## O pedido do usuário (2026-10-06)

*"A inteligência do sistema poderia melhorar no quesito de que empresas que tiveram o contrato gerado
através do próprio sistema o sistema já deveria saber a tabela progressiva da empresa. É o caso da
Maderatto, o contrato dela foi gerado pelo sistema, consta como assinado e também é possível consultar
no Clicksign."*

## O diagnóstico — medido em produção, não re-investigar

A inteligência **já existe pela metade**. Desde a Fase 141 / quick 260904-kwz, tabela de origem
`servico` conta como **confirmada** quando a empresa tem `ContratoAssinatura` com
`status = 'assinado'` — o docblock de `AdminController::fechamentoCompanyIdsComContratoAssinado()`
diz literalmente que *"é a tabela escrita nesse contrato que legitima a do serviço"*.

**O que derrota a regra:** a materialização (`fechamento:materializar-tabelas`, 2026-09-09 21:02)
copiou a tabela do serviço para `empresa_faixas_faturamento` com `origem = 'presumida_servico'` — selo
que, por decisão da Fase 141 (D-04/D-05), **nunca** pode passar por confirmado. Resultado: empresa com
contrato assinado pelo próprio sistema aparece como **presumida**.

Medido hoje em produção: **5** contratos assinados gerados pelo sistema, em 5 empresas — **3 com
tabela `presumida_servico`**, 1 `manual`, 1 sem tabela nenhuma. A MADERATTO MÓVEIS #446 é uma das 3,
com 7 faixas `presumida_servico` criadas em 2026-09-09 21:02:35 e contrato assinado em 2026-08-27.

⚠️ **Descoberta paralela, NÃO é escopo deste quick** (já registrada para o usuário): a varredura
`AcervoContratosClicksignService::pareceGestaoDeAds()` exige `gestao` **e** `ads` no nome do envelope.
Os contratos gerados pelo sistema se chamam `Contrato — Gestão — {empresa}`, **sem "ADS"** — testado:
`Contrato — Gestão — Mons Bike (1)` e `Contrato — Gestão — Maderatto Móveis` são **IGNORADOS**, e
`Contrato Gestão de ADS _ ECF - RESILFER LTDA (1)` é aceito. É por isso que nenhuma
`ContratoTabelaProposta` foi criada para eles. ⛔ **Não altere esse filtro neste quick** — alargá-lo
criaria proposta duplicada para o que este quick passa a resolver na origem.

## T1 — Na assinatura, gravar a tabela como `contrato`

Quando um `ContratoAssinatura` passa a `STATUS_ASSINADO`, gravar a tabela de faixas da empresa com
`EmpresaFaixaFaturamento::ORIGEM_CONTRATO`, usando as faixas do **serviço** daquele contrato
(`servico_faixas_faturamento`) — que são exatamente as que foram impressas no documento.

**Dois pontos marcam assinado** — leia os dois e chame o MESMO serviço novo dos dois, sem duplicar
regra:
- `app/Jobs/ProcessarEventoClicksignJob.php` (~linha 257)
- `app/Jobs/ReconciliarContratoClicksignJob.php` (~linha 136)

Use `GravarTabelaEmpresaService::gravar($company, $faixas, ORIGEM_CONTRATO, $servicoOrigemId, null, $feitoDe)`
— ele já tem transação, trilha de auditoria e a trava de precedência. ⛔ **Não reimplemente a
gravação** e ⛔ não mexa na trava.

Regras de borda, todas obrigatórias:

| situação | o que fazer |
|---|---|
| empresa já tem tabela `manual` ou `contrato` | **não sobrescrever**; `Log::info` e seguir (ato humano vence) |
| contrato cobre 2+ linhas do MESMO serviço (é o caso da Maderatto) | dedup por `servico_id`; grava uma vez |
| contrato cobre serviços DIFERENTES com tabelas diferentes | **não grava**, `Log::warning` com os ids — adivinhar qual vale é pior que deixar presumida |
| serviço sem faixas (cobrança fixa) | **não grava** tabela nenhuma |
| empresa pertence a grupo com tabela | grava igual; a precedência de `FechamentoFaixaResolver::paraEmpresa()` (grupo vence) decide sozinha — ⛔ **não toque na precedência** |
| gravação falha | o erro **não** pode derrubar o processamento do evento do Clicksign (mesmo espírito de `AdmanService::syncAll()`): `try/catch (\Throwable)` + `Log::error` |

⚠️ **Isto não muda valor de cobrança nenhum**: as faixas gravadas são as mesmas que já estavam como
presumidas. Muda o **selo** — de presunção para confirmada por contrato. Prove isso com teste.

## T2 — Comando de retroativo

`php artisan fechamento:tabelas-de-contratos-assinados [--dry-run] [--company=]`

Varre os `ContratoAssinatura` com `status = 'assinado'` e aplica a mesma regra do T1, reusando o
serviço do T1 (⛔ sem segunda cópia da regra). `--dry-run` é o **padrão** — escrever exige
`--aplicar`. Relatório por empresa: o que tinha, o que passa a ter, e o motivo de quem foi pulada.

## T3 — Testes

Novos, em `tests/Feature/Quick261006/`:

| caso | espera |
|---|---|
| contrato vira assinado, empresa com tabela `presumida_servico` | tabela passa a `origem = contrato`, **mesmas faixas** |
| mesma coisa, mas empresa com tabela `manual` | **nada muda**, log emitido |
| mesma coisa com tabela já `contrato` | **nada muda** |
| contrato com 2 linhas do mesmo serviço | grava uma vez, sem duplicar faixa |
| contrato com 2 serviços de tabelas diferentes | **não grava**, warning |
| serviço sem faixas | **não grava** |
| `GravarTabelaEmpresaService` lança | evento do Clicksign **não** quebra |
| valor de cobrança do fechamento | **idêntico** antes e depois (é só o selo) |
| comando sem `--aplicar` | não escreve nada (prove por reconsulta) |

**Gate:** `--filter="Phase137|Phase138|Phase140|Phase141|Phase142|Phase143|Quick260904|Quick261006|Clicksign|Contrato"`
— rodar **antes** de editar (para ter a referência) e ao final. Capture o exit **antes** de qualquer pipe.

## Fora de escopo

⛔ **A rampa de parcelas** ("as 3 primeiras parcelas a R$ 2.500 e as demais seguirão a faixa") é outro
problema, já levantado ao usuário, e **não entra aqui**. Não tente representá-la.
⛔ Não alterar `pareceGestaoDeAds()`, `FechamentoFaixaResolver::classificar()`, a precedência de
`paraEmpresa()`, `FechamentoSnapshotWriter`, `FechamentoEmpresasDoMes`, `GravarTabelaEmpresaService`
nem `podeUsarApiDaAdman()`.
⛔ Nada de produção, VPS, `plink`, `pscp`, `.env`, deploy ou `cache:clear`. O retroativo em produção é
passo do orquestrador.

## Restrições de árvore

⚠️ Árvore compartilhada com outro dev e com outras sessões. Nunca `git add -A` / `git add .` /
`git commit -a` / `git stash`. `git status --porcelain app/ tests/ database/ .planning/` antes de cada
commit e commitar **só** pelos seus caminhos; arquivo novo precisa de `git add -- <caminho>`.
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu. Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em pt-BR, terminando com
`Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

Ao final grave `SUMMARY.md` nesta pasta, com os números medidos (5 contratos assinados, 3 presumidas)
e o que o retroativo faria em cada uma das 5 empresas.
