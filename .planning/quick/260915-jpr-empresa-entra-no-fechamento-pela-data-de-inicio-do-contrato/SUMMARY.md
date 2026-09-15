---
quick_id: 260915-jpr
slug: empresa-entra-no-fechamento-pela-data-de-inicio-do-contrato
date: 2026-09-15
status: complete
commits:
  - 4c090741
  - 70ed3c8d
---

# Quick 260915-jpr — SUMMARY

A empresa só entra no fechamento de um mês se o contrato já tinha começado.

> O executor não conseguiu gravar este arquivo (a ferramenta recusou `.md` em subagente). O
> orquestrador gravou a partir do relatório final do executor e da conferência que fez depois.

## O que mudou

**Ponto único de verdade: `app/Services/Fechamento/FechamentoEmpresasDoMes.php` (novo)**
- `situacao()` → `entra` / `pendente` / `fora`; `separar()` devolve as três listas; `filtrar()` só quem entra
- data = `contratos_servico.data_contratacao` — **nunca** `companies.created_at` (critério recusado pelo usuário)
- `fimDoMes()` usa o último dia do mês, nunca hoje
- não consulta o banco: lê os contratos ativos já carregados
- data nula ou zerada do MySQL conta como sem data

**Regra aplicada**
| situação dos contratos ativos | resultado |
|---|---|
| nenhum contrato ativo | entra (regressão zero) |
| algum contrato com início até o fim do mês (inclusive no meio do mês) | entra |
| nenhum começou até o fim do mês, mas algum não tem data | entra como pendência |
| todos com data e todas depois do fim do mês | sai |

**Onde passou a valer:** `ConsolidarMesFechamento`, `AdminController::fechamento()`,
`AdminController::gerarRelatorioGeral()` (um quinto lugar que o plano não listava), `EnviarRelatorioFechamentoJob`,
`CompararMensalidadeFechamento`.

**Resumo do comando:** `fechamento:consolidar-mes` imprime quem ficou de fora (nome, id, data de
início) e quantas entraram sem data.

**Tela:** prop de página `empresas_sem_data_inicio` (`id`, `name`, `url`) — nenhuma chave nova nos
cinco literais de linha — e o aviso `SemDataInicioAviso` em `Financeiro.jsx`, com link para
`/administrativo/contratos/empresa/{id}`.

**Remoção de linhas:** `FechamentoSnapshotWriter` intocado. Teste confirma que, ao refazer, a linha de
quem saiu some; grupo que perde uma empresa tem soma, contagem e faixa recalculadas; grupo que perde
todas some.

**Testes novos:** `tests/Feature/Quick260915/EmpresaEntraPelaDataDeInicioTest.php` (10 testes, todos
os casos do plano + trava de copy).

## Gates (conferidos pelo orquestrador, exit capturado antes do pipe)

| filtro | resultado |
|---|---|
| `Phase122\|…\|Phase143\|Quick260909\|Quick260910\|Quick260911\|Quick260915` | **831 passando** (3821 asserções), 0 falhas — eram 821 |
| `Phase74\|Phase110` | **39 passando** (170 asserções), 0 falhas |

`FechamentoFaixaResolver::classificar()` e `FechamentoSnapshotWriter` sem nenhuma linha alterada.

## Decisões do executor

1. **Mês já fechado mantém quem está gravado** na tela, no relatório geral e no job, até ser refeito.
   Sem isso, o total a receber de um mês fechado mudaria sem ninguém refazer nada. O comando aplica a
   regra a todos, porque refazer é o momento de corrigir. **Consequência:** julho continua mostrando
   as 39 empresas até ser refeito.
2. **Relatório geral coberto** — usa o mesmo pipeline da tela.
3. **A lista de pendência só inclui quem entrou por falta de data** — empresa com um contrato datado
   antes do mês e outro sem data não aparece.
4. **Fixtures:** a regra derrubou 62 testes do gate porque as fixtures criavam contrato com data de
   hoje (setembro) e fechavam agosto. `ContratoServicoFactory` passou a `'2025-01-01'` e seis
   `ContratoServico::create` com `Carbon::now()` passaram à mesma data. **Nenhuma asserção alterada.**
   Suítes de onboarding que usam a mesma factory: 403 passando e 1 falha **pré-existente**
   (`OnboardingDirigeEtapaTest::test_a_regua_do_onboarding_continua_na_versao_17` espera 17 e encontra
   20 — subido pelo commit `d4e12675`, de outra sessão, em 2026-09-14; conferido que `4c090741` não
   tocou em `DefinicaoOnboarding`).

## Fora do escopo — registrar

- ⚠️ **O webhook do HubSpot grava hoje como início do contrato**
  (`app/Http/Controllers/Api/HubspotWebhookController.php:1036`,
  `'data_contratacao' => now()->toDateString()`). Cliente antigo que entrar agora pelo HubSpot sai dos
  meses anteriores pela regra nova, até alguém corrigir a data.
- A migration diz NOT NULL, mas a data pode vir vazia na prática (`ContratoAdminController` valida
  `nullable`); a regra trata nulo e data zerada.
- A migration legada `2026_05_27_100002` preencheu `data_contratacao = contract_start ?? company.created_at`
  (reimport de 25/05/2026). Contratos antigos sem `contract_start` não entram em meses anteriores a
  25/05/2026 — julho e agosto não são afetados.

## Estado em produção

**Nada deployado, nenhuma competência refeita, nenhum dado alterado.** Medido em 2026-09-15: **nenhuma
empresa ativa tem contrato ativo sem data de início** — a lista de pendências nasce vazia. As 11
empresas fora dos 95% não têm contrato ativo nenhum e continuam entrando como hoje.
