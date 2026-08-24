---
quick_id: 260824-kbk
slug: lista-contratos-duplica-fase
date: 2026-08-24
status: complete
---

# A lista de contratos duplicava a empresa quando o pagamento é escalonado

## O que o usuário viu

> "Inclusive vi que ela está aparecendo duas vezes no sistema"

Medido: existia **um único** registro de empresa (`company_id=435`, Mons Bike). O que duplicava
era a **linha na lista** de `/administrativo/contratos`.

## Causa: invariante quebrada pela consolidação de fases

`ContratoAdminController::index()` já trazia o comentário

> `// (4) Linhas — uma por par (empresa, serviço que exige contrato).`

mas o laço percorria `$company->contratosServico`, que desde o quick `260824-bte` tem **uma linha
por FASE**, não por serviço. Com pagamento escalonado são dois `ContratoServico` do mesmo
`servico_id`.

E os dois resolviam a **mesma** chave `company_id:servico_id` em `$contratosPorPar` — ou seja, a
lista exibia o **mesmo `contrato_id` duas vezes**.

A intenção do comentário sempre esteve certa; o código é que deixou de cumpri-la quando a
consolidação entrou. Este quick restaura a invariante.

## A correção

Em `index()`, `$company->contratosServico` passa a ser filtrado por `exigeContrato()` e
**agrupado por `servico_id`** antes do laço, nos dois ramos (com contrato e `SEM_CONTRATO`).

`data_vencimento` da linha passa a ser o **maior valor não-nulo** do grupo — o serviço termina
quando a última fase termina. Todas nulas → `null`, o "Sem prazo" legítimo de sempre.

Nenhuma chave do array de linha mudou: a tela consome esse formato e o resumo de 7 contagens
(D-04) depende dele.

## Não tocado, de propósito

`show()` e a seção "Datas por serviço" — lá as fases **devem** continuar separadas, uma por fase.
É onde a pessoa preenche as datas de cada uma, e onde o título ganhou valor e quantidade de
parcelas (quick `260824-r4k`) justamente para distingui-las.

## Testes

Em `tests/Feature/Phase131/ContratoAdminListaTest.php`:

- duas fases do mesmo serviço → **uma** linha, com `data_vencimento` = o maior valor
- ramo `SEM_CONTRATO` também deduplica, e `sem_contrato_count` não conta a empresa duas vezes
- regressão zero: serviços diferentes continuam com uma linha cada

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**510 testes, 1704 asserções**, verde.

## Commits

| Commit | Mensagem |
|---|---|
| `c42515e9` | agrupa fases do mesmo serviço em uma linha na lista |
