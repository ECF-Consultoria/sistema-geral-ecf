---
phase: quick-261027-m1y
plan: 01
subsystem: contratos / fechamento
tags: [clicksign, contrato, tabela-progressiva, gestao-ml, snapshot]
requires: []
provides:
  - tabela padrão nova do serviço Gestão (6 faixas)
  - tabela progressiva variável e congelada no contrato de Gestão (14 variáveis faixa_N_*)
  - assinatura grava a tabela impressa
affects: [fechamento, administrativo-contratos]
key-files:
  created:
    - database/migrations/2026_10_27_100000_add_tabela_progressiva_snapshot_to_contrato_assinaturas_table.php
    - database/migrations/2026_10_27_100001_atualizar_tabela_padrao_gestao_ml.php
    - app/Services/Contratos/TabelaDoContratoService.php
    - tests/Feature/Quick261027TabelaPadraoGestaoTest.php
    - tests/Feature/Quick261027TabelaDoContratoTest.php
    - tests/Feature/Quick261027TabelaAssinadaTest.php
    - modelo-contrato-gestao-v6-TABELA-VARIAVEL.docx
  modified:
    - app/Models/ContratoAssinatura.php
    - app/Jobs/GerarContratoAssinaturaJob.php
    - app/Services/ContratoPdfService.php
    - app/Services/Clicksign/ContratoVariaveisModeloService.php
    - app/Services/Contratos/ContratoDadosMinimosService.php
    - app/Services/Fechamento/TabelaDeContratoAssinadoService.php
metrics:
  completed: 2026-10-27
  commits: 4
---

# Quick 261027-m1y: tabela progressiva de Gestão (ML) variável no contrato

**Resumo:** a tabela padrão de Gestão passou a 6 faixas (R$ 3.700 até R$ 13.500) e o contrato Clicksign
de Gestão imprime a tabela da empresa (origem `manual`/`contrato`) ou, na falta dela, a padrão do serviço,
congelada em `contrato_assinaturas.tabela_progressiva_snapshot` na geração.

## O que foi feito

1. **Migrations** — coluna `tabela_progressiva_snapshot` (json, nula) e migration idempotente que troca a
   tabela do serviço `Gestão` (por nome exato), apaga a ordem 7 e, ANTES de trocar, congela a tabela antiga
   nos contratos de Gestão com envelope e em `rascunho`/`aguardando_assinaturas` (para não gravar uma tabela
   que o cliente não assinou quando eles forem assinados). Não toca `empresa_faixas_faturamento`, Brigada nem Shopee.
2. **`TabelaDoContratoService`** — `resolver` (manual/contrato senão serviço; `presumida_servico` nunca conta),
   `congelar` (idempotente, recusa >7 faixas antes de gravar), `linhasParaContrato` (puro, 7 linhas).
   Só vale para serviços em `SERVICOS_COM_TABELA_NO_MODELO = ['Gestão']` (Shopee tem 11 faixas).
3. **Job / PDF / variáveis** — o job congela a tabela antes de `montar()` (recusa >7 antes de qualquer HTTP);
   `montarDados()` expõe `tabela_progressiva`; `mapa()` ganhou as 14 entradas explícitas `faixa_N_faturamento`/`faixa_N_valor`.
4. **Assinatura** — `TabelaDeContratoAssinadoService` grava a tabela do snapshot (contrato antigo sem snapshot
   segue o caminho do serviço ao vivo); "ato humano vence" continua primeiro.
5. **Pré-check** — `ContratoDadosMinimosService` ganhou a pendência `tabela_progressiva` (`ausente` ou `formato` para >7 faixas).
6. **`.docx` v6** — `modelo-contrato-gestao-v6-TABELA-VARIAVEL.docx` na raiz, gerado de `modelo-contrato-gestao-v5-PLATAFORMA.docx`.

## Commits

- `11582143` tabela padrão nova + coluna de congelamento
- `96f1d7a3` resolução/congelamento/impressão (14 variáveis)
- `d4142420` assinatura grava a impressa, pré-check, `.docx` v6, ajuste de testes antigos
- `16a0ec71` ajuste dos testes de cobrança

## Deviations from Plan

**1. [Rule 3 - Blocking] `handle()` do job com parâmetro opcional.** A assinatura do plano (`TabelaDoContratoService` obrigatório) quebrava ~20 testes existentes que chamam `handle($client, $variaveis)` direto. Ficou `?TabelaDoContratoService $t = null` com fallback `app(...)`; o container continua injetando normalmente.

**2. [Rule 1] `.docx` v5 tem a tabela partida em DUAS tabelas** (cabeçalho + 4 linhas / 3 linhas), não uma de 7. O script trata as 7 linhas de dados das duas. Também removi `w:w`/`w:spacing` do primeiro run (escala de 90% encolheria o valor). v5 e v6 têm o mesmo conjunto de partes do zip; só `word/document.xml` muda.

**3. [Rule 1] Testes existentes que fixavam a tabela ANTIGA semeada por migration** foram atualizados (sem enfraquecer): Phase137 (ConsolidarMes, FaixaResolver, FinanceiroProps), Phase138 ConsolidarGrupoTabela, Phase139 ComparativoFaixa, Phase141 ResolverCutover, Quick260909 FechamentoEscopoAmpliado, AdminFechamentoController (fatura*), Phase14AdminControllerCobranca, Phase14FechamentoUi. Phase128 (GatilhoContratoPendencia, ReavaliacaoAutomatica) criam um SEGUNDO serviço "Gestão" sem faixas; ganharam uma faixa mínima porque o pré-check agora exige tabela.
`ContratoVariaveisModeloTest::campo_ausente...` passou a ignorar as `faixa_N_*` na regra "nenhuma variável vazia" (vazias de propósito).

## Pontos de atenção (decisões que o usuário deve saber)

- **Limite cheio × convenção ,99:** a tabela nova usa limites cheios (500.000,00...). `FechamentoFaixaResolver::classificar` trata o limite como inclusivo, então faturamento EXATO de R$ 500.000,00 agora cai na faixa 1 ("Até"), não na 2 como antes (limite 499.999,99). Só afeta quem cai na tabela padrão do serviço; com `fechamento_tabela_por_empresa_ativa` ligada a cobrança lê `empresa_faixas_faturamento`, que não mudou. Brigada segue com ,99.
- **Gestão sem tabela no serviço ou com tabela da empresa > 7 faixas:** o pré-check bloqueia a geração (`tabela_progressiva`) e o job recusa.
- **Tabela de GRUPO não entra** na regra "específica" (só a da empresa), conforme o CONTEXT.

## Falhas pré-existentes (NÃO são desta tarefa)

Confirmadas rodando o mesmo filtro SEM a migration 100001 (renomeada temporariamente e restaurada):
`Unit\CalcularFaixaTest` (9, ArgumentCountError), `AdminFechamentoControllerTest::update*` (4), `FechamentoMigrationTest` (1), `Phase14VerificarCobrancaTest::aborta_com_divergencia` (1).
Vistas também em suíte ampla e sem relação com contratos/tabela (desempenho/datas): `Phase120\ShadowRoteamentoTest` (1), `Phase123\CompanyScoreSnapshotReaderTest` (1), `Phase123\PerformanceShowEmpresasScoreTest` (1), `Phase123\PerformanceShowMesesDisponiveisTest` (2). Não rodei essas 5 sem a migration, apenas verifiquei que as mensagens não tocam em contrato/faixa.

## Verificação

- `Quick261027*` verde (4 + 11 + testes de assinatura/pré-check/docx).
- Suítes `Phase126–132`, `Phase137–143`, `Quick2608–2610`, `Quick261006`, `ContratoDadosMinimos`: verdes após os ajustes (último lote: 1208 passed, 1 falha corrigida depois — Quick260909).
- `.docx` v6 reaberto com `python -I`: 14 variáveis novas, cada uma num único `w:t`, as 13 antigas preservadas, `testzip()` OK.

## Operação pós-execução (para o usuário / orquestrador)

1. Confirmar que o v5 local é o modelo publicado hoje no Clicksign. O v5 local ainda tem "12 (doze)" fixo (sem `{{vigencia_meses}}`) e os valores da tabela antiga do v5 (R$ 3.500 / 4.500...) NÃO batem com os do banco (3.000...) — indício de que o publicado pode ser outro; se for, o v6 precisa partir dele.
2. Ordem do deploy: subir o v6 no Clicksign -> deploy -> trocar `clicksign_template_id` do serviço Gestão em /servicos -> `clicksign:sondar-modelo --template=<novo>`. Contrato de Gestão gerado entre o deploy e a troca do modelo sai com a tabela fixa do v5 e o snapshot da nova — não gerar nessa janela.
3. Registrar em `.planning/learnings/fechamento-tabela-por-empresa.md` a regra "contrato imprime a tabela congelada; assinatura grava a impressa" (não feito aqui).
4. `queue:restart` após o deploy (o job mudou).

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos criados e commits `11582143`, `96f1d7a3`, `d4142420`, `16a0ec71` verificados em `git log`; `.docx` v6 presente na raiz.
