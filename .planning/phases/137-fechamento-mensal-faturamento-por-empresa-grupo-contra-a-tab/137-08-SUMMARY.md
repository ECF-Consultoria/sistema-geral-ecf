---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 08
subsystem: api
tags: [laravel, fechamento, faturamento, company-group, snapshot, email, pdf]

# Dependency graph
requires:
  - phase: 137-03
    provides: "FechamentoRollupService::janela()/porEmpresa() e FechamentoFaixaResolver::paraEmpresa()/classificar()"
  - phase: 137-05
    provides: "FechamentoSnapshot/FechamentoGrupoSnapshot congelados pelo comando fechamento:consolidar-mes"
  - phase: 137-07
    provides: "fechamento() reescrito como molde de janela/faixa/snapshot + métodos privados reutilizáveis (fechamentoDadosPorEmpresaAoVivo/Congelados, fechamentoAgregarGruposAoVivo/Congelados)"
provides:
  - "gerarRelatorio()/gerarRelatorioGeral() lendo a mesma fonte central de fechamento() — mês-calendário fechado (D-06), grupos do Comercial (D-08/D-09/D-10), congelado quando fechado (D-11)"
  - "EnviarRelatorioFechamentoJob sem tabela própria, somando ML+Shopee (D-05, gap pré-existente fechado) e agrupando por CompanyGroup"
  - "valor_e_piso propagado às três superfícies (PDF por empresa, relatório geral, e-mail) com o prefixo 'a partir de' (D-02b)"
  - "AdminController::FAIXAS e EnviarRelatorioFechamentoJob::FAIXAS apagadas — zero cópias da tabela progressiva no código"
affects: [137-09, 137-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Consumidores derivados reusam os métodos privados de fechamento() (fechamentoDadosPorEmpresaAoVivo/Congelados, fechamentoAgregarGrupos*) em vez de recalcular — garante paridade numérica por construção, não por sincronização manual"
    - "Payload por linha (empresa/grupo/vinculada) reformatado por um único método (relatorioLinhaEmpresa no controller, linhaEmpresa no job) — faixa/cobrança nunca são recalculadas fora dos serviços centrais, só reformatadas pro Blade"
    - "Job resolve serviços via app() dentro de handle() em vez de injeção no construtor — jobs ShouldQueue são serializados na fila, services não são serializáveis"

key-files:
  created:
    - tests/Feature/Phase137/Phase137RelatoriosFechamentoTest.php
  modified:
    - app/Http/Controllers/AdminController.php
    - app/Jobs/EnviarRelatorioFechamentoJob.php
    - resources/views/admin/relatorio-fechamento.blade.php
    - resources/views/admin/relatorio-geral.blade.php
    - resources/views/admin/relatorio-geral-pdf.blade.php
    - tests/Feature/Phase14BladeRefactorTest.php

key-decisions:
  - "gerarRelatorio()/gerarRelatorioGeral() NÃO chamam fechamentoAgregarGrupos*() da mesma forma — gerarRelatorio() mantém $company como sujeito do relatório (empresas-irmãs como 'vinculadas', total separado), enquanto gerarRelatorioGeral() usa o pipeline completo de fechamento() (uma linha por grupo, ancorada na empresa de maior faturamento) porque lista TODAS as empresas de uma vez, igual à tela"
  - "servico_nome agora filtra no nível de empresa (Company::whereHas) antes do agrupamento, não mais só no 'pai' — mudança de comportamento documentada: antes, filhas apareciam mesmo sem contrato do serviço filtrado; efeito colateral aceitável da migração de parent_company_id para CompanyGroup"
  - "prefixo 'a partir de' implementado com um helper @php ($fmtPiso) inline em cada view, aplicado em TODOS os pontos onde a mensalidade é impressa (linha própria, vinculada, e a variante sem vinculadas) — não só no ponto mínimo do orçamento de diff do plano, porque restringir a um só branch deixaria a faixa-piso sem prefixo quando a empresa piso pertence a um grupo"
  - "Phase14BladeRefactorTest TEST 2 (fixture pai+filha via parent_company_id) recebeu um CompanyGroup para preservar a intenção original do teste sob a régua nova (D-08) — Rule 1, teste não estava no files_modified do plano mas ficaria estruturalmente incompatível com D-08 sem o ajuste"

requirements-completed: [D-02, D-02b, D-05, D-06, D-08, D-10, D-11]

# Metrics
duration: ~140min
completed: 2026-09-03
---

# Phase 137 Plano 08: Migração dos relatórios PDF/e-mail para a fonte central Summary

**Os três últimos consumidores do fechamento (PDF por empresa, relatório geral, e-mail mensal) passam a ler `FechamentoRollupService`/`FechamentoFaixaResolver` em vez de uma tabela `FAIXAS` duplicada, e o e-mail mensal passa a incluir Shopee — gap que existia desde sempre.**

## Performance

- **Duration:** ~140 min
- **Started:** 2026-09-03
- **Completed:** 2026-09-03
- **Tasks:** 3 de 3
- **Files modified:** 6 (1 controller, 1 job, 3 blades, 1 teste ajustado) + 1 teste novo

## Accomplishments

- **A duplicação de `FAIXAS` acabou.** `grep -c "FAIXAS"` retorna 0 em `AdminController.php` e em `EnviarRelatorioFechamentoJob.php`. As duas cópias da tabela progressiva (`AdminController::FAIXAS` + `calcularFaixa()`/`faixaNumero()`/`faixaLabel()`, e `EnviarRelatorioFechamentoJob::FAIXAS` + seu próprio `calcularFaixa()`) foram apagadas. `gerarRelatorio()`/`gerarRelatorioGeral()` agora reusam `fechamentoDadosPorEmpresaAoVivo()`/`Congelados()` e `fechamentoAgregarGruposAoVivo()`/`Congelados()` — os mesmos métodos privados que `fechamento()` (plano 07) já usa — então a paridade numérica com a tela é garantida por construção, não por sincronização manual entre cópias.
- **O e-mail mensal passa a incluir Shopee (D-05).** `EnviarRelatorioFechamentoJob` somava SÓ `adman_metrics` desde sempre — gap pré-existente medido pela pesquisa. Agora usa `FechamentoRollupService::porEmpresa()` (ML+Shopee somados) tanto no caminho ao vivo quanto no congelado. Testado: empresa com ML+Shopee tem `faturamento` maior que o valor só-ML no payload do job.
- **`parent_company_id` não agrupa mais em lugar nenhum dos três consumidores restantes (D-08/D-09).** `gerarRelatorio()` usa as empresas-irmãs do mesmo `CompanyGroup` como "vinculadas" (empresa sem grupo gera relatório sem vinculadas). `gerarRelatorioGeral()` e o job usam a MESMA regra de `fechamento()`: uma linha por `CompanyGroup` (âncora = empresa-membro de maior faturamento, empate pelo menor id) + uma linha por empresa sem grupo.
- **Congelamento respeitado nos quatro consumidores (D-11).** Quando a competência já foi fechada por `fechamento:consolidar-mes`, os três consumidores deste plano leem `fechamento_snapshots`/`fechamento_grupo_snapshots`, nunca recalculam — provado por teste que altera `adman_metrics` depois do fechamento e confirma que nenhum dos quatro consumidores muda o número exibido.
- **`valor_e_piso` chegou às três superfícies (D-02b).** A última faixa de Gestão/Brigada é piso ("a partir de R$ 12.000"), não valor fechado. As três views agora recebem `valor_e_piso` (empresa própria, vinculada e item de `relatorios`) e imprimem `a partir de R$ 12.000,00` em vez de `R$ 12.000,00` seco — testado nas três superfícies (PDF por empresa, relatório geral, PDF anexado ao e-mail), com contraponto explícito provando que o prefixo NÃO vaza pra empresa em faixa normal.
- **A chamada de rede à Adman saiu do caminho.** `gerarRelatorio()`/`gerarRelatorioGeral()` não chamam mais `AdmanService::fetchGrossBillingsBatch()`/`getCachedGrossBillingsMany()` — o rollup é 100% local sobre `adman_metrics`/`shopee_metrics`, mesma disciplina de `fechamento()`.

## Task Commits

Each task was committed atomically:

1. **Tarefa 1: gerarRelatorio()/gerarRelatorioGeral() + apaga FAIXAS do controller** - `41af03cc` (feat)
2. **Tarefa 2: EnviarRelatorioFechamentoJob sem tabela própria, com Shopee** - `29261bc4` (feat)
3. **Tarefa 3: teste de convergência dos quatro consumidores** - `bc49c176` (test)

**Plan metadata:** (este commit — SUMMARY + STATE)

## Files Created/Modified

- `app/Http/Controllers/AdminController.php` - `gerarRelatorio()`/`gerarRelatorioGeral()` reescritos; 4 métodos privados novos (`relatorioCompetenciaFechada`, `relatorioVinculadasDoGrupo`, `relatorioFaixaLabel`, `relatorioLinhaEmpresa`); `FAIXAS`/`calcularFaixa()`/`faixaNumero()`/`faixaLabel()` apagados; import `AdmanMetric` removido (não mais usado)
- `app/Jobs/EnviarRelatorioFechamentoJob.php` - reescrito por completo: `FAIXAS` e `calcularFaixa()` apagados; resolve `FechamentoRollupService`/`FechamentoFaixaResolver` via `app()`; agrupamento por `CompanyGroup` (ao vivo e congelado); métodos privados espelhando o payload de `gerarRelatorioGeral()`
- `resources/views/admin/relatorio-fechamento.blade.php` - título usa `$titulo ?? $company->name`; helper `@php $fmtPiso` aplicado nos 4 pontos onde a mensalidade é impressa
- `resources/views/admin/relatorio-geral.blade.php` - mesma mudança de título (`$r['titulo']`) e prefixo de piso
- `resources/views/admin/relatorio-geral-pdf.blade.php` - mesma mudança, usando `valor_mensal` (campo que esta view já exibia, diferente de `cobranca_mensal` das outras duas — preservado)
- `tests/Feature/Phase137/Phase137RelatoriosFechamentoTest.php` - **novo**, 7 testes cobrindo (a)-(h) do plano
- `tests/Feature/Phase14BladeRefactorTest.php` - TEST 2 ganha `CompanyGroup` (Rule 1, ver Deviations)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: os dois métodos do controller NÃO usam o mesmo pipeline de agregação — `gerarRelatorio()` preserva a semântica "pai + vinculadas + total separado" (só troca a fonte do grupo), enquanto `gerarRelatorioGeral()` adota o pipeline completo de `fechamento()` (uma linha por grupo, ancorada); o filtro `servico_nome` passou a filtrar no nível de empresa antes do agrupamento (mudança de comportamento documentada); o prefixo de piso foi implementado em TODOS os pontos de exibição de mensalidade, não só no orçamento mínimo do plano, porque restringir cobriria só o caso "empresa sem grupo".

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug/Incompatibilidade estrutural] `Phase14BladeRefactorTest` TEST 2 ficaria incompatível com D-08**
- **Found during:** Tarefa 1, ao migrar `gerarRelatorio()` para agrupar por `CompanyGroup` em vez de `parent_company_id`/`filhas`.
- **Issue:** O TEST 2 (`relatorio_fechamento_renderiza_servicos_contratados_de_empresa_filha`) cria um "pai" e uma "filha" ligados só por `parent_company_id`, sem `company_group_id`, e espera que os serviços contratados da filha apareçam no relatório do pai como "vinculada". Sob D-08 (grupo vem do `CompanyGroup`, `parent_company_id` nunca participa), essa filha deixaria de aparecer — o teste quebraria não por bug, mas porque sua fixture representa exatamente o mecanismo que a fase substitui.
- **Fix:** adicionado um `CompanyGroup` ligando pai e filha, preservando a intenção original do teste (verificar que `servicos_contratados` da vinculada renderiza) sob a régua nova.
- **Files modified:** `tests/Feature/Phase14BladeRefactorTest.php`
- **Commit:** `41af03cc`

**2. [Rule 1 - Comentários com strings literais] Grep-count de `FAIXAS`/`parent_company_id` também flagava comentários explicativos**
- **Found during:** Tarefa 1 e 2, ao rodar as acceptance criteria do plano (`grep -c "FAIXAS"` / `grep -c "parent_company_id"` devem retornar 0).
- **Issue:** Comentários no código (ex.: "nunca `parent_company_id`") continham a própria string proibida, fazendo o grep literal (que não distingue código de comentário) falhar mesmo com a lógica correta.
- **Fix:** reescritos os comentários para descrever o comportamento sem repetir a string literal (ex.: "a hierarquia legada de pai/filha" em vez de citar o nome da coluna).
- **Files modified:** `app/Http/Controllers/AdminController.php`, `app/Jobs/EnviarRelatorioFechamentoJob.php`
- **Commit:** `41af03cc`, `29261bc4`

Nenhuma outra alteração fora do escopo do plano foi necessária.

## Known Stubs

Nenhum. Todos os campos novos (`titulo`, `valor_e_piso`) são preenchidos por dado real (rollup, resolver ou snapshot).

## Threat Flags

Nenhuma superfície nova fora do `threat_model` do plano. `T-137-32` (chamada de rede à Adman em `gerarRelatorioGeral()`/job para ~190 empresas) foi mitigada: a chamada `fetchGrossBillingsBatch`/`getCachedGrossBillingsMany` saiu do caminho — o rollup é 100% local.

## Issues Encountered

**`Phase14VerificarCobrancaTest > aborta_com_divergencia` e outras 11 falhas fora do gate.** Ao rodar a verificação ampliada do plano (`--filter="Phase137|Phase14|AdminFechamentoController"`), 12 testes falharam: 3 em `Phase14MigrationTest` (erro de parse de timezone do Carbon, ambiente Windows), 1 em `Phase14MlbControllerFiltroTest` (prop Inertia ausente, módulo de empresas), 4 em `AdminFechamentoControllerTest` (já documentadas pelo usuário como pré-existentes — `updateFechamento()`/`service_type`/`contract_start`/`contract_end`), e 4 em `Phase14VerificarCobrancaTest` (comando standalone `phase14:verificar-cobranca`, com sua PRÓPRIA cópia intocada de `FAIXAS`). Confirmado via `git status`/diff que nenhum destes arquivos foi tocado por este plano — são falhas pré-existentes, fora de escopo. Documentado aqui em vez de "consertado" (SCOPE BOUNDARY).

**Achado fora de escopo: existe uma QUARTA cópia de `FAIXAS`.** `app/Console/Commands/Phase14VerificarCobranca.php` tem sua própria constante `FAIXAS`/`calcularFaixa()` (linhas 34-42), documentada no próprio arquivo como "duplicação intencional e temporária... comando é deletado após a conclusão da Fase 14". A Fase 14 já terminou (colunas legadas já foram dropadas, per comentários espalhados pelo `AdminController`), então este comando é código morto que nunca foi removido. Não está em nenhum dos `files_modified` deste plano (nem do objetivo — o plano fala explicitamente dos "três consumidores restantes": `gerarRelatorio()`, `gerarRelatorioGeral()`, `EnviarRelatorioFechamentoJob`). Fica registrado aqui para o dono do projeto decidir se apaga o comando.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Todos os quatro consumidores do fechamento (tela, PDF por empresa, relatório geral, e-mail mensal) leem a mesma fonte central. Não há mais nenhuma cópia de `FAIXAS` nos arquivos deste plano nem em `fechamento()` (plano 07).
- `EnviarRelatorioFechamentoJob` está pronto para o scheduler existente (`routes/console.php`, não alterado) disparar o envio mensal já com Shopee incluído.
- Gate `Phase122|Phase136|Phase137`: **233 testes / 1177 asserções / 0 falhas** (medido após os 3 commits deste plano, árvore limpa). O plano 137-09 (paralelo) já havia landado antes desta medição final — a baseline informada no início da execução (219/1068) não incluía o trabalho dele; a comparação direta é 226 (219 + os 7 testes deste plano) esperados vs. 233 medidos, diferença de +7 explicada pelo plano 09 já mesclado. Zero falhas confirma ausência de regressão de ambos os planos.
- `AdminFechamentoControllerTest`: continua **4/16 falhas pré-existentes**, inalterado por este plano (`updateFechamento()`/`service_type`/`contract_start`/`contract_end`, Fase 14 Plano 14-06 — fora de escopo).
- Achado registrado para follow-up: `app/Console/Commands/Phase14VerificarCobranca.php` é código morto da Fase 14 com sua própria cópia de `FAIXAS` — candidato a remoção numa limpeza futura (fora do escopo desta fase).

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-03*
