---
id: 260909-e8n
slug: fechamento-escopo-e-legibilidade
date: 2026-09-09
status: complete
commits:
  - ee45f792 feat(260909-e8n) escopo da lista de fechamento, com travas
  - bd0ad9a3 fix(260909-e8n) período apurado, badge de integração e tipografia
---

# Fechamento — escopo da lista, período visível e legibilidade

## O que mudou

**Escopo da lista** (`AdminController::fechamentoRemoverForaDeEscopo()`, aplicado em
`fechamento()` e `gerarRelatorioGeral()`): saem da tela cadastro `status = 'pendente'`,
empresa sem contrato de serviço ativo e empresa só do setor Polos. Em produção isso tira
72 de 201 linhas.

**Travas de segurança** (decisão do usuário): a empresa permanece, mesmo caindo nas regras
acima, se tiver faturamento apurado, cobrança calculada, for membro de `CompanyGroup` ou
tiver `parent_company_id`. Sem elas sumiriam da tela três clientes Shopee com cobrança ativa
(R$ 2.000/mês cada) e Interior Magazine levaria R$ 204.427 para fora da soma do grupo Utilar
— o que pode derrubar a faixa cobrada do grupo inteiro.

**Período apurado**: prop nova `periodo` e banner mostrando `Período 01/08/2026 a 31/08/2026 ·
fechado em 03/09/2026`. A data de execução do fechamento é sempre do mês seguinte ao apurado.

**Badge "Sem integração"**: seguia `!has_adman` (só ML); passa a seguir `estado === 'sem_integracao'`,
que também considera Shopee.

**Tipografia**: escala um degrau acima na tela inteira.

## Testes

`tests/Feature/Quick260909/FechamentoEscopoDaListaTest.php` — 7 testes cobrindo as três regras
de corte e as três travas (faturamento, ausência de serviço com faturamento, e soma de grupo).

Suíte de fechamento: 269 testes, 6 falhas **pré-existentes** — todas exigem as colunas
`service_type` / `contract_*` removidas pela migration
`2026_05_27_100003_drop_legacy_service_columns_from_companies`. Nenhuma passa pelo código
alterado aqui (4 testam a rota PATCH, 1 testa o schema, 1 afirma chave legacy nas props).

## Diagnóstico do sync Adman — NÃO consertado, decisão pendente

O usuário pediu para investigar a causa antes de qualquer UI. A causa está achada:

`SyncAdmanCompanyJob` **falhou 95 vezes em agosto/2026** — 59 por `Adman API erro 500` e 35 por
`rate limit (429) após 3 tentativas` —, de 2 a 8 falhas **todos os dias**. Esgotadas as 3
tentativas, o job vai para `failed_jobs` e **nada reprocessa**: não existe comando de backfill de
lacunas de `adman_metrics` no projeto.

A correlação fecha: o sync roda em D para o dado de D-1; 8 falhas em 04/08 correspondem ao dia
03/08, que é o mais furado (6 empresas sem linha).

Consequência no fechamento: 21 empresas fecharam agosto com 28–30 dias em vez de 31. Cada dia
faltando subtrai faturamento real e pode derrubar a faixa cobrada. Nenhum dia de agosto teve
todas as empresas (117–127 de 127).

Sujeira de cadastro relacionada: pelo menos uma empresa tem `adman_account_id` com texto em vez
do ID (`custId=Fuxicando` no log), o que dá 500 todo dia.

Casos que **não** são furo de sync (dado correto, mês parcial legítimo): Chikweb (contrato 18/08,
13 dias), Nutrifour e MAXIWEB (18/08, 14 dias), TUNNING COUROS (24/08, 8 dias) — entraram no meio
do mês e são classificadas na faixa como se fosse mês cheio.

Próximo passo sugerido, ainda **não autorizado**: comando que varre lacunas de `adman_metrics`
numa janela e reenfileira `SyncAdmanCompanyJob(company, date)`, mais um alerta quando a
competência tem cobertura incompleta. Backfill retroativo de agosto exigiria refazer o
fechamento já congelado.
