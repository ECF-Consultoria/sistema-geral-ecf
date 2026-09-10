---
quick_id: 260904-kwz
slug: lastro-da-tabela-progressiva
date: 2026-09-04
status: done
---

# A tela diz quando a tabela foi assumida, não confirmada — Resumo

**Uma linha:** o backend passa a emitir `tabela_confirmada` (cadastro manual OU contrato assinado
no sistema confirma; senão a tabela do serviço foi só presumida) nos cinco literais de linha do
fechamento, e a tela mostra isso de forma discreta — sem alarmar e sem tirar o valor.

## O problema, medido em produção (2026-09-04)

O sistema aplicava a tabela do **serviço** a toda empresa que tem aquele serviço, sem olhar se
existe contrato assinado ou cadastro manual. Medido: 201 empresas no fechamento de agosto, 167 com
tabela vinda do serviço, **0** cadastros manuais, **3** contratos assinados no sistema — **127**
empresas com mensalidade sem nenhum dos dois, somando **R$ 460.500,00/mês** sem confirmação.

O usuário corrigiu a premissa: "não são todas empresas que têm tabela progressiva, são só as que
têm contrato com tabela progressiva ou as que nós cadastrarmos manualmente pelo sistema" — e
escolheu **tornar a origem visível sem tirar o valor**, em vez de aplicar a regra estrita (que
derrubaria 127 empresas para 3) ou cadastrar tudo de uma vez.

## A regra implementada

Uma tabela tem **confirmação** quando:

1. há **cadastro manual** — `tabela_origem` é `propria` (exceção da própria empresa) ou
   `grupo` (tabela do grupo); **ou**
2. a empresa **dona** da tabela (a própria empresa, ou a empresa-âncora quando a linha é de grupo
   sem tabela própria) tem um `ContratoAssinatura` com `status = assinado` no sistema.

Fora disso, a tabela foi **presumida** a partir do serviço — o que **não é erro**: a maioria dessas
empresas está em contrato de tabela progressiva no mundo real, o sistema é que não sabia disso
ainda. `tabela_confirmada` é `null` (não `false`) quando não existe tabela nenhuma — o estado
"A DEFINIR" continua sendo uma coisa diferente.

## Tarefa 1 — Backend (app/Http/Controllers/AdminController.php)

- `fechamentoTabelaConfirmada()` — extraída (mesmo raciocínio de `fechamentoDerivarUpgrade()`)
  para os cinco literais de linha nunca divergirem: empresa ao vivo, empresa congelada com
  snapshot, empresa congelada sem snapshot, grupo ao vivo, grupo congelado.
- `fechamentoCompanyIdsComContratoAssinado()` — consulta em massa (distinct + pluck + flip), UMA
  vez por request, ANTES do laço — mesmo padrão de FechamentoComparativoService (T-138-11).
  Reaproveitada pelos três endpoints que montam linha de fechamento (fechamento, gerarRelatorio,
  gerarRelatorioGeral).
- `totais.tabelas_assumidas` — contador de quantas linhas (respeitando conta_no_total, mesma
  disciplina das outras somas de fechamentoTotais()) estão sem confirmação.
- Decisão de execução: no ramo CONGELADO, tabela_confirmada lê o contrato assinado ATUAL (não
  fica preso ao que existia no momento do fechamento) — a pergunta é "existe confirmação HOJE",
  nunca "existia confirmação naquele mês". Isso não viola D-11 (nunca recalcula
  faturamento/faixa/mensalidade): tabela_confirmada é um campo novo, não um número congelado.
  Testado explicitamente.

## Tarefa 2 — Frontend (resources/js/Pages/Admin/Financeiro.jsx)

- TabelaPresumidaBadge — selo discreto (tom neutro white/40, nunca âmbar) na linha compacta da
  empresa/grupo quando tabela_confirmada === false.
- Card "2 · Faixa do contrato" do accordion ganha um link para a seção de cadastro que já existe
  quando a tabela foi presumida.
- Composição do grupo marca cada membro com "· presumida" quando cabe.
- TabelaPresumidaAviso — aviso no topo com o contador (totais.tabelas_assumidas) e botão "Ver
  quais", que liga o chip de filtro novo ("Tabela sem confirmação") — filtra a lista, a pessoa abre
  cada empresa e usa o cadastro que já existe no accordion.
- Copy sem jargão (pt-BR): nenhuma das palavras banidas aparece como texto visível.

## Tarefa 3 — Testes (tests/Feature/Phase139/Phase139LastroTabelaTest.php, novo)

13 testes cobrindo:

- Os dois caminhos de confirmação (cadastro manual próprio/do grupo; contrato assinado da empresa
  dona da tabela).
- Assumida mantém o valor — nunca vira erro nem zera a mensalidade.
- tabela_confirmada é null (não false) quando não há tabela nenhuma — não confunde com "A DEFINIR".
- Contrato assinado de OUTRA empresa não vaza confirmação; status diferente de assinado
  (ex.: aguardando_assinaturas) não confirma.
- Grupo: tabela própria do grupo confirma sozinha; sem tabela própria, herda a confirmação do
  contrato assinado da âncora.
- totais.tabelas_assumidas bate com a contagem real das linhas.
- Consulta de contratos assinados é feita uma única vez, independente da quantidade de empresas
  (sem N+1, verificado filtrando o query log por contrato_assinaturas).
- Cobertura no ramo congelado por reconsulta ao banco (nova requisição HTTP + verificação direta
  em fechamento_snapshots) — nunca por stdout: contrato assinado depois do fechamento passa a
  confirmar a competência já congelada, sem recalcular o valor já cobrado.

Também foi necessário atualizar Phase139FechamentoUiContratoTest (o teste que fazia checagem
estrita das chaves de totais), renomeado de "onze" para "doze_chaves" — mudança de contrato
esperada pela própria Tarefa 1.

## Gate (--filter="Phase122|Phase136|Phase137|Phase138|Phase139")

- Antes: 335 testes / 1717 asserções / 0 falhas (valor do PLAN.md, confirmado no início desta
  execução).
- Depois: 348 testes / 1765 asserções / 0 falhas (335+13 testes novos; nenhuma regressão).

Durante a execução, adicionar o 5º parâmetro às quatro funções fechamentoDadosPorEmpresa*/
fechamentoAgregarGrupos* quebrou outros dois call sites que eu não tinha visto na primeira leitura
(gerarRelatorio() e gerarRelatorioGeral(), usados para os PDFs) — 10 falhas na primeira rodada do
gate (ArgumentCountError). Corrigido propagando companyIdsComContratoAssinado também para esses
dois endpoints, e extraindo a consulta repetida em fechamentoCompanyIdsComContratoAssinado() para
os três call sites nunca divergirem.

Phase138AvisoMudancaFaixaTest (flaky pré-existente) rodado isolado após o gate: passou, não foi
tocado.

## npm run build

Passou limpo. Confirmado por script Node lendo o CSS compilado que nenhuma classe da armadilha de
escala do Tailwind (px-4.5, gap-4.5, py-5.5) foi introduzida, e que as classes novas usadas
(underline-offset-2, rounded-xl, rounded-lg, whitespace-nowrap) geraram CSS de verdade. public/build
é gitignored, nada para commitar ali.

## Arquivos modificados

- app/Http/Controllers/AdminController.php — tabela_confirmada nos cinco literais,
  fechamentoTabelaConfirmada(), fechamentoCompanyIdsComContratoAssinado(), totais.tabelas_assumidas.
- resources/js/Pages/Admin/Financeiro.jsx — TabelaPresumidaBadge, TabelaPresumidaAviso, link de
  cadastro no accordion, marcação na composição do grupo, chip de filtro novo.
- tests/Feature/Phase139/Phase139FechamentoUiContratoTest.php — contrato de totais atualizado para
  12 chaves.
- tests/Feature/Phase139/Phase139LastroTabelaTest.php — novo, 13 testes.

## Deviations from Plan

### Auto-fixed Issues

1. [Rule 3 - Blocking] Dois call sites do PDF não vistos na primeira leitura do arquivo
   - Encontrado durante: primeira rodada do gate, após a Tarefa 1.
   - Problema: gerarRelatorio() (PDF por empresa) e gerarRelatorioGeral() (PDF geral) chamavam as
     mesmas quatro funções privadas com a assinatura antiga (4 argumentos) — ArgumentCountError em
     produção de PDF assim que a Tarefa 1 fosse ao ar.
   - Fix: os dois passaram a computar/receber companyIdsComContratoAssinado também, via o novo
     helper fechamentoCompanyIdsComContratoAssinado().
   - Arquivos: app/Http/Controllers/AdminController.php.
   - Commit: dedabd96.

2. [Rule 3 - Blocking] Teste de contrato de totais com checagem estrita de chaves
   - Encontrado durante: primeira rodada do gate.
   - Problema: Phase139FechamentoUiContratoTest afirmava a lista EXATA de chaves de totais
     (Unexpected properties were found in scope) — a chave nova tabelas_assumidas (exigida pela
     própria Tarefa 1 do plano) quebrava esse teste.
   - Fix: adicionada a chave à lista esperada; teste renomeado de "onze" para "doze_chaves".
   - Arquivos: tests/Feature/Phase139/Phase139FechamentoUiContratoTest.php.
   - Commit: dedabd96.

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície nova de rede/auth/schema. ContratoAssinatura já existe (Fase 125) e a consulta
nova é só leitura (SELECT ... WHERE status = assinado), sem escrita, sem endpoint novo, sem
mudança de autorização.

## Self-Check: PASSED

- app/Http/Controllers/AdminController.php — FOUND
- resources/js/Pages/Admin/Financeiro.jsx — FOUND
- tests/Feature/Phase139/Phase139FechamentoUiContratoTest.php — FOUND
- tests/Feature/Phase139/Phase139LastroTabelaTest.php — FOUND
- Commit dedabd96 — FOUND (git log --oneline --all)
- Commit 254cc766 — FOUND
- Commit 32d58193 — FOUND
