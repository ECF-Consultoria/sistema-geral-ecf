---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 07
subsystem: api
tags: [laravel, inertia, fechamento, faturamento, company-group, snapshot]

# Dependency graph
requires:
  - phase: 137-03
    provides: "FechamentoRollupService::janela()/porEmpresa() e FechamentoFaixaResolver::paraEmpresa()/classificar()"
  - phase: 137-05
    provides: "FechamentoSnapshot/FechamentoGrupoSnapshot congelados pelo comando fechamento:consolidar-mes"
provides:
  - "AdminController::fechamento() reescrito — mês-calendário fechado (D-06), grupos do Comercial (D-08/D-09/D-10), leitura do congelado (D-11), faixa do banco (D-01/D-02)"
  - "Contrato de props novo de /financeiro: faturamento_ml/shopee, faixa_ordem/label/limite_inferior/superior, tabela_origem/servico_nome, valor_faixa_e_piso, competencia_fechada(_em), faixas_por_servico"
affects: [137-08, 137-09, 137-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bifurcação explícita fechada x aberta dentro de um mesmo controller — nunca um 'if' espalhado: 2 métodos privados irmãos (fechamentoDadosPorEmpresaCongelados/AoVivo), mesmo shape de retorno"
    - "Agregação de grupo com 2 variantes irmãs (fechamentoAgregarGruposCongelados/AoVivo) reaproveitando o mesmo formato de linha do array individual"
    - "Progressão histórica sempre lida do congelado (fechamento_snapshots/fechamento_grupo_snapshots), nunca recalculada, independente do estado da competência selecionada"

key-files:
  created:
    - tests/Feature/Phase137/Phase137FinanceiroPropsTest.php
  modified:
    - app/Http/Controllers/AdminController.php
    - tests/Feature/AdminFechamentoControllerTest.php
    - tests/Feature/Phase14FechamentoUiTest.php
    - tests/Feature/Phase14AdminControllerCobrancaTest.php
    - .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/deferred-items.md

key-decisions:
  - "faixa_label é o mesmo valor de faixa (classificacao['label'] / faixa_aplicada do snapshot) — chave nova para a família faixa_ordem/faixa_label/faixa_limite_inferior/faixa_limite_superior; 'faixa' foi preservada com o valor idêntico por compatibilidade com a tela/suítes atuais, que não são tocadas neste plano"
  - "has_adman continua estritamente 'tem adman_account_id/ml_store_id' (cust_id !== null) — não foi generalizado para 'tem qualquer integração financeira' (que inclui Shopee); o estado sem_integracao usa o critério mais amplo internamente, mas a prop pública manteve o nome e a semântica antigos para não quebrar as 2 asserções existentes que a leem"
  - "filhas do grupo incluem a empresa-âncora — a linha de grupo é uma entidade nova (id=âncora, tipo=grupo), não mais 'o pai estendido com uma lista de filhas'; confirmado pela acceptance criteria do plano (\"as 2 empresas aparecem em filhas\" para um grupo de 2)"
  - "Empresa ativa sem linha de snapshot na competência fechada (ex.: entrou na carteira depois do fechamento) nunca inventa número — cai em estado sem_faturamento com todos os campos financeiros null, mesma disciplina de ausência visível do resto da fase"

requirements-completed: [D-02, D-06, D-08, D-09, D-10, D-11]

# Metrics
duration: ~110min
completed: 2026-09-03
---

# Phase 137 Plano 07: Fechamento mensal na tela /financeiro Summary

**`AdminController::fechamento()` reescrito para ler mês-calendário fechado (fim do acumulativo), agregar por CompanyGroup do Comercial em vez de parent_company_id, e nunca recalcular uma competência já congelada.**

## Performance

- **Duration:** ~110 min
- **Started:** 2026-09-03
- **Completed:** 2026-09-03
- **Tasks:** 3 de 3
- **Files modified:** 6 (1 controller reescrito, 3 suítes de teste atualizadas, 1 suíte nova, 1 deferred-items.md)

## Accomplishments

- **Fim do acumulativo (D-06), o pedido original do usuário.** `fechamento()` não usa mais `Carbon::now()->subDays(30)` — toda competência (corrente ou passada) é mês-calendário, via `FechamentoRollupService::janela()` (única implementação, delega ao `MetricPeriodResolver`).
- **Bifurcação explícita congelado x ao vivo (D-11).** Quando existe uma linha em `fechamento_snapshots` com `origem = consolidar_mes` para a competência selecionada, TODOS os números (empresa e grupo) vêm do congelado — provado por teste que altera `adman_metrics` depois do fechamento e confirma que o valor exibido não muda. Quando não existe, os números vêm de `FechamentoRollupService::porEmpresa()` + `FechamentoFaixaResolver`, com a MESMA precedência de estado (`sem_integracao` → `sem_faturamento` → `sem_tabela` → `ok`) e a mesma lógica de evolução do comando `fechamento:consolidar-mes`.
- **Grupos do Comercial, não `parent_company_id` (D-08/D-09/D-10).** Empresas com `company_group_id` viram 1 linha `tipo=grupo` (id = empresa-âncora de maior faturamento, empate pelo menor id); a soma das membros define a faixa; `tabelas_divergentes` sinaliza quando as membros resolvem tabelas diferentes. `parent_company_id` continua sendo devolvido como metadado, mas nunca participa de soma, faixa ou `conta_no_total`.
- **Faixa do banco (D-01/D-02), nunca mais a constante `FAIXAS`.** `fechamento()` não referencia `self::FAIXAS`, `calcularFaixa()`, `faixaNumero()` nem `faixaLabel()` — esses 4 símbolos continuam no arquivo só para `gerarRelatorio()`/`gerarRelatorioGeral()`, que migram no plano 08.
- **Progressão sem coluna acumulada.** Reconstruída a partir do histórico de `fechamento_snapshots`/`fechamento_grupo_snapshots` (nunca de `company_monthly_revenues`, que fica com valor rolling obsoleto após o mês virar — achado da pesquisa). Nenhum item tem a chave `acumulado`.
- **Props novas emitidas:** `faturamento_ml`, `faturamento_shopee`, `faixa_ordem`, `faixa_label`, `faixa_limite_inferior`, `faixa_limite_superior`, `tabela_origem`, `tabela_servico_nome`, `valor_faixa_e_piso` (por linha); `competencia_fechada`, `competencia_fechada_em`, `faixas_por_servico` (por página). Chaves antigas preservadas: `faixa`, `valor_mensal`, `cobranca_mensal`, `evolucao`, `estado`, `has_adman`, `recebido`, `servicos_contratados`, `periodo_inicio`, `periodo_fim`.
- Removida deste método a leitura de `CompanyMonthlyRevenue`, o cache batch `getCachedGrossBillingsMany` e o `RefreshGrossBillingCacheJob::dispatchIfQueued()` — o rollup é 100% local.

## Task Commits

1. **Tarefas 1+2: fechamento() — mês-calendário, faixa do banco, congelado e grupos** - `bc7deaaa` (feat)
2. **Tarefa 3: suítes atualizadas + contrato de props novo** - `5da32cde` (test)
3. **Correção de regressão (Rule 1) + deferred-items** - `e6035976` (fix)

**Plan metadata:** (este commit — SUMMARY + STATE)

## Files Created/Modified

- `app/Http/Controllers/AdminController.php` - `fechamento()` reescrito + 8 métodos privados novos (`fechamentoServicosContratados`, `fechamentoDadosPorEmpresaAoVivo`, `fechamentoDadosPorEmpresaCongelados`, `fechamentoAgregarGruposAoVivo`, `fechamentoAgregarGruposCongelados`, `fechamentoFaixasPorServico`) + construtor injeta `FechamentoRollupService`/`FechamentoFaixaResolver`
- `tests/Feature/AdminFechamentoControllerTest.php` - 4 testes de faixa passam a semear serviço Gestão + contrato + faixas; 2 asserções migradas de `sem_dados` para `sem_faturamento`; 2 testes ganham contrato Gestão para saírem de `sem_tabela`
- `tests/Feature/Phase14FechamentoUiTest.php` - cenário de grupo (2 empresas no mesmo `CompanyGroup`) provando `servicos_contratados` na linha de grupo; teste de cobrança ganha contrato Gestão
- `tests/Feature/Phase14AdminControllerCobrancaTest.php` - fixture do teste golden ganha contrato Gestão (Rule 1, fora dos `files_modified` originais — ver Deviations)
- `tests/Feature/Phase137/Phase137FinanceiroPropsTest.php` - **novo**, 10 testes cobrindo o contrato de props (8 pedidos pelo plano + 2 extras de `tabela_origem`/`valor_faixa_e_piso`)
- `.planning/phases/137.../deferred-items.md` - registra a baseline medida (git show HEAD temporário) e a mudança de comportamento esperada em `test_empresa_ok_recebe_periodo_coberto`

## Decisions Made

Ver `key-decisions` no frontmatter — resumo: `faixa_label` espelha `faixa` (não inventei copy de faixa por extenso, já que a régua agora é dado do banco, não texto fixo); `has_adman` manteve semântica estrita de Adman; `filhas` do grupo inclui a âncora (confirmado pela acceptance criteria literal do plano); empresa sem linha de snapshot na competência fechada vira `sem_faturamento` com tudo `null`, nunca um número inventado.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug/Regressão] `Phase14AdminControllerCobrancaTest` regrediu (arquivo fora do `files_modified` do plano)**
- **Found during:** Tarefa 3, verificação ampliada além do gate `Phase122|Phase136|Phase137` (rodei todas as suítes que batem em `/administrativo/financeiro` para checar regressão, não só as 4 do gate oficial).
- **Issue:** `test_cobranca_mensal_legacy_e_novo_modelo_batem_para_empresa_com_additional_service` esperava `cobranca_mensal = 4700` (faixa 4.500 + contrato 200), mas a empresa fixture só tinha contratos `Polos`/`Treinamento` — nenhum dos dois é "dono de tabela" sob D-01 (nem `plataforma` nem setor financeiro). Sem faixa resolvível, a empresa cai em `sem_tabela` e `cobranca_mensal` vira só 200.
- **Fix:** adicionado contrato `Gestão` (mesmo padrão usado nas 2 suítes do `files_modified`) via novo helper `vincularGestao()`.
- **Files modified:** `tests/Feature/Phase14AdminControllerCobrancaTest.php`
- **Commit:** `e6035976`

**2. [Rule 1 - Comportamento mudou como avisado] `test_empresa_ok_recebe_periodo_coberto` (AdminFechamentoControllerTest) — pré-existente, virou verde**
- **Found during:** Tarefa 3, medição de baseline (instrução explícita do plano).
- **Issue/Observação:** o `deferred-items.md` já documentava esta falha como "sensível à janela móvel de 30 dias" e previa que "outra tarefa/plano desta fase corrige". Este plano É essa correção — com `periodo_inicio` agora vindo do mês-calendário (D-06), o teste passou a verde sem eu tocar nele.
- **Fix:** nenhum — comportamento correto emergiu da correção de D-06. Registrado em `deferred-items.md` conforme instruído ("se mudar, registre o que observou").
- **Files modified:** `.planning/phases/137.../deferred-items.md` (só documentação)
- **Commit:** `e6035976`

Nenhuma outra alteração fora do escopo do plano foi necessária.

## Known Stubs

Nenhum. Todos os campos novos são preenchidos por dado real (rollup, resolver ou snapshot) — nenhum valor hardcoded/vazio foi introduzido nas props.

## Threat Flags

Nenhuma superfície nova fora do `threat_model` do plano — a rota `/financeiro` continua no grupo `role:admin`, nenhum endpoint novo foi adicionado (só props do já existente `Inertia::render`).

## Issues Encountered

Nenhum bloqueio. A complexidade real foi de design: separar claramente os 4 caminhos (empresa ao vivo / empresa congelada / grupo ao vivo / grupo congelada) em métodos privados irmãos com o MESMO shape de retorno, para que o passo de progressão e o `array_values()` final não precisassem saber qual caminho gerou a linha.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Backend de `/financeiro` está completo: lê mês-calendário, faixa do banco, congelado quando fechado, grupos do Comercial. Frontend (`Financeiro.jsx`) **não foi tocado** — continua lendo `p.acumulado` (que agora vem `undefined`) e o filtro de estado `sem_dados` (que agora nunca ocorre, virou `sem_faturamento`); ambos ficam para o plano de UI que consome este contrato (UI-SPEC seções 1-3).
- `faixas_por_servico` já vai nas props, pronto para a seção de tabela (UI-SPEC seção 4, que o plano 09/10 constrói).
- Gate `Phase122|Phase136|Phase137`: **219 testes / 1068 asserções / 0 falhas** (era 209/1009 antes deste plano — +10 testes/+59 asserções, todos novos, sem regressão).
- `AdminFechamentoControllerTest`: **4/16 falhas pré-existentes** (era 5/16 — a 5ª virou verde por D-06, ver Deviations). As 4 restantes (`test_update_persiste_service_type`, `test_update_rejeita_service_type_invalido`, `test_update_persiste_datas_contrato`, `test_update_rejeita_contract_end_anterior`) são de `updateFechamento()` (Fase 14 Plano 14-06, colunas `service_type`/`contract_start`/`contract_end`) — não tocadas, fora do escopo deste plano.
- `AdminController.php` ainda tem `self::FAIXAS`/`calcularFaixa()`/`faixaNumero()`/`faixaLabel()` vivos, usados só por `gerarRelatorio()`/`gerarRelatorioGeral()` — plano 08 migra esses 2 métodos e apaga os 4 símbolos.

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-03*
