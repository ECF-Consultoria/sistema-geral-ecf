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

## Correções de cadastro aplicadas em produção (2026-09-09, autorizadas caso a caso)

Depois do deploy (feito por outra sessão às 10:43 BRT, que levou estes commits junto), a tela
passou de 201 para **134 empresas**. Sobraram três linhas que pareciam escapar do filtro; nenhuma
era defeito do filtro:

- **Dev 02 Teste** — tem Polos **e** Publicação, Gestão e Gestão de ADS Shopee. A regra só tira
  quem é exclusivamente Polos. Usuário optou por deixar como está.
- **ALCOMERCIOEIMPORTACAO** (id 251) — nunca teve contrato de serviço no sistema, embora fature
  ~R$ 170k/mês pelo ML desde maio e tenha consultor e estrategista atribuídos. Criado contrato
  **#364 de Gestão com `valor_contratado = 0`** (a mensalidade vem da tabela progressiva; 88 dos
  165 contratos de Gestão em produção seguem esse padrão). Cobrança resultante em setembro:
  faixa 1, R$ 3.000.
- **Interior Magazine** (id 370) — os contratos não estavam faltando: foram **desativados em
  27/08/2026 às 22:18**, os dois no mesmo minuto (Gestão R$ 3.000 e Gestão de ADS Shopee
  R$ 2.500), enquanto as três irmãs do grupo Utilar seguem com o par ativo. Reativados os
  contratos #252 e #272 (update dos existentes, sem criar novos). Cobrança individual voltou a
  R$ 8.500, idêntica a Utilarshop, Itadecor e Ita Prime — o padrão do grupo foi restaurado, não
  uma anomalia nova.

**`custId=Fuxicando` resolvido**: era o `ml_store_id` da Interior Magazine, preenchido com texto
em vez de ID — a única empresa ativa com cust_id não numérico. Campo limpo (`null`). Era uma das
fontes do erro 500 diário na API Adman; o faturamento dela é 100% Shopee, que não usa esse campo
(a Shopee resolve por `shopeeToken`).

⚠️ **Efeitos colaterais controlados.** `ContratoServico` tem dois observers: um gera contrato para
assinatura na Clicksign (o interruptor de congelamento de emissão estava **desligado** em
produção) e outro cria onboarding em rascunho. As escritas rodaram dentro de
`ContratoServicoGatilhoObserver::semDisparo()` — correção retroativa de cadastro não é venda
nova. Confirmado por reconsulta: **nenhum `ContratoAssinatura` criado** para 251 ou 370. Nasceu o
onboarding **#28 em rascunho** para ALCOMERCIO (serviço Gestão), inofensivo mas removível se o
time não quiser o ruído.

⚠️ **Agosto não muda sozinho**: a competência está congelada. ALCOMERCIO segue como "sem tabela"
no fechamento de agosto; para o contrato novo valer lá, seria preciso refazer o fechamento
daquela competência — decisão não tomada.
