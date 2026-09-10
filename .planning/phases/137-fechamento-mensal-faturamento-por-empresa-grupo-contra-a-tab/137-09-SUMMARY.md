---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 09
subsystem: ui
tags: [react, inertia, tailwind, fechamento, faixas-faturamento]

# Dependency graph
requires:
  - phase: 137-06
    provides: "FechamentoController (CRUD de faixas por empresa/serviço) + SalvarFaixasFaturamentoRequest"
  - phase: 137-07
    provides: "AdminController::fechamento() com props novas (tipo, estado, faturamento_ml/shopee, faixa_limite_*, tabela_origem, tabela_servico_nome, valor_faixa_e_piso, competencia_fechada, faixas_por_servico)"
provides:
  - "Estados de ausência visíveis na tela (A DEFINIR / Sem faturamento neste mês), nunca R$ 0 ou traço mudo"
  - "Breakdown ML + Shopee aberto no accordion, nunca soma silenciosa"
  - "FaixaProgresso lendo faixa_limite_inferior/faixa_limite_superior do backend (sem mapa hardcoded no JSX)"
  - "TabelaFaixasSection: cadastro manual da tabela de faixas por empresa e por serviço (D-04), all-or-nothing (D-13)"
  - "Prefixo 'a partir de' em toda faixa-piso (linha, accordion, listagem da tabela)"
affects: [137-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Componente extraído para resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx (arquivo-pai já com >1300 linhas)"
    - "Dialog all-or-nothing: form sempre envia a tabela inteira ({ faixas: [...] }), nunca uma linha isolada"

key-files:
  created:
    - resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx
    - tests/Feature/Phase137/Phase137FinanceiroUiContratoTest.php
  modified:
    - resources/js/Pages/Admin/Financeiro.jsx

key-decisions:
  - "AusenciaTabelaPendencia/AusenciaFaturamentoBadge/FaturamentoCombinadoBreakdown/GrupoServicosDivergentesBanner não usam ecf-yellow (accent é reservado a ação/status, ausência de dado não é nenhum dos dois)"
  - "faixaNome() deriva 'Faixa N'/'Faixa máxima' a partir da chave crua do backend (faixa_N/maxima) por regex — substitui FAIXAS_LIMITES/FAIXA_NOMES sem reintroduzir mapa fixo, já que a tabela agora é dinâmica por serviço/empresa"
  - "Estado 'tabela própria já cadastrada': AdminController::fechamento() não expõe as LINHAS da exceção própria de uma empresa (só origem/nome do serviço substituído) — não adicionado aqui por estar fora do files_modified do plano e por AdminController.php estar em edição paralela (plano 137-08). 'Substituir tabela própria' abre formulário em branco com aviso explícito, nunca finge mostrar valores que não temos"
  - "Nome do serviço substituído por uma tabela própria é inferido no front (cruzamento entre servicos_contratados da empresa e faixas_por_servico) só para exibição — nunca usado no payload salvo"
  - "'Editar tabela do serviço' não mostra contagem de empresas afetadas (dado não exposto pelo backend) — usa aviso textual genérico + confirm() nativo antes de salvar"

requirements-completed: [D-04, D-05, D-13]

# Metrics
duration: ~35min
completed: 2026-09-03
---

# Phase 137 Plano 09: Estados de ausência, breakdown ML+Shopee e cadastro manual da tabela de faixas Summary

**`Financeiro.jsx` ganha 4 componentes de estado de ausência (nunca R$ 0/traço mudo) e `FaixaProgresso` lê limites do backend; novo `TabelaFaixasSection.jsx` cadastra/edita/remove a tabela de faixas por empresa e por serviço direto no accordion.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-03T09:20:00-03:00 (aprox.)
- **Completed:** 2026-09-03T10:10:00-03:00
- **Tasks:** 3/3
- **Files modified:** 3 (1 criado no diretório `Financeiro/`, 1 editado, 1 teste criado)

## Accomplishments

- Empresa sem tabela de faixas aparece como `Tabela de faixas: A DEFINIR` (compacto na linha, completo no `TabelaFaixasSection` com CTA de cadastro) — nunca R$ 0.
- Empresa sem faturamento no mês mostra `Sem faturamento neste mês` em vez de traço mudo, e a barra de progresso de faixa não é desenhada.
- Faturamento combinado ML + Shopee abre a composição (`Mercado Livre {valor} + Shopee {valor} = {total}`) dentro do accordion — nunca soma silenciosa.
- `FaixaProgresso` não depende mais de tabela hardcoded no JSX (`FAIXAS_LIMITES`/`FAIXA_NOMES` apagadas) — lê `faixa_limite_inferior`/`faixa_limite_superior`/`faixa_label` do backend.
- Toda faixa-piso (`valor_faixa_e_piso`) é exibida como `a partir de {valor}` na linha, no accordion e na listagem da tabela — nunca o valor seco.
- `TabelaFaixasSection.jsx`: cadastro manual completo da tabela de faixas — cria/edita/remove exceção própria de empresa, cria/edita tabela de serviço (é por aí que Gestão de ADS Shopee e Brigada entram no sistema), tudo all-or-nothing (D-13), com edição bloqueada quando a competência está fechada.
- 7 testes novos de contrato (props + regressão de arquivo), 47 asserções, todos passando.

## Task Commits

1. **Tarefa 1: Estados de ausência, breakdown ML+Shopee e FaixaProgresso sem tabela hardcoded** - `d33e07c8` (feat)
2. **Tarefa 2: TabelaFaixasSection e FaixaFormDialog — cadastro manual (D-04)** - `e4557be3` (feat)
3. **Tarefa 3: Teste de contrato da tela** - `729af224` (test)

## Files Created/Modified

- `resources/js/Pages/Admin/Financeiro.jsx` - Remove `FAIXAS_LIMITES`/`FAIXA_NOMES`; adiciona `faixaNome()`/`fmtValorFaixa()`; `FaixaProgresso` passa a receber limites/rótulo por prop; 4 componentes novos (`AusenciaTabelaPendencia`, `AusenciaFaturamentoBadge`, `FaturamentoCombinadoBreakdown`, `GrupoServicosDivergentesBanner`) plugados em `FechamentoRow`/`FechamentoAccordion`; renderiza `TabelaFaixasSection` dentro do accordion
- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` (novo) - Bloco de tabela de faixas por empresa/serviço + `FaixaFormDialog` (form all-or-nothing, checkbox de piso, validação de sobreposição delegada ao backend)
- `tests/Feature/Phase137/Phase137FinanceiroUiContratoTest.php` (novo) - Contrato de props (`tipo`, `estado`, `faturamento_ml/shopee`, `faixa_label`, `faixa_limite_inferior/superior`, `tabela_origem`, `tabela_servico_nome`, `valor_faixa_e_piso`, `faixas_por_servico`, `competencia_fechada`) + trava de regressão de arquivo (constantes hardcoded fora, componentes reaproveitados presentes)

## Decisions Made

- **`faixaNome()` por regex, não por mapa fixo**: como a tabela de faixas agora é dinâmica por serviço/empresa (Gestão pode ter N faixas, Brigada outro N), um mapa fixo tipo `FAIXA_NOMES` voltaria a ficar errado assim que uma tabela tivesse um número de faixas diferente de 6. A função deriva `Faixa {N}` / `Faixa máxima` a partir da chave crua (`faixa_N`/`maxima`) que o backend já produz.
- **Nenhum dos 4 componentes de ausência usa `ecf-yellow`**: o Color Contract do UI-SPEC reserva o accent a ação/status; ausência de dado é estado neutro/âmbar, não um dos dois. O botão "Cadastrar tabela de faixas" (que usa accent, por estar na lista fechada) mora em `TabelaFaixasSection`, não nesses 4 componentes.
- **Limitação documentada, não contornada por fora do escopo**: editar uma tabela própria já cadastrada não pré-preenche os valores atuais porque `AdminController::fechamento()` não os expõe hoje (só resolve `origem`/`servico_nome`, descarta o array de faixas da exceção). Corrigir isso exigiria tocar `AdminController.php`, que está fora do `files_modified` deste plano e em edição paralela pelo plano 137-08. A UI é honesta sobre a limitação (aviso explícito, formulário em branco) em vez de fingir mostrar dados que não tem — evita o risco de sobrescrever uma tabela real com números que parecem corretos mas não são.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] `TabelaFaixasSection` precisa de dados que o UI-SPEC assume disponíveis mas o backend não expõe**
- **Found during:** Tarefa 2
- **Issue:** O UI-SPEC/plano descrevem "lista editável" da tabela própria de uma empresa e "contagem de empresas afetadas" ao editar a tabela de um serviço. Nenhum dos dois dados está nas props de `AdminController::fechamento()` (`faixas_por_servico` só cobre serviços; a linha da empresa não carrega o array de faixas da própria exceção; nenhuma prop traz contagem de empresas por serviço).
- **Fix:** Em vez de tocar `AdminController.php` (fora de escopo, arquivo do plano 137-08 em execução paralela), a UI foi desenhada para ser honesta com o que tem: "Substituir tabela própria" abre formulário em branco com aviso explícito (nunca finge mostrar valores desatualizados); "Editar tabela do serviço" usa aviso textual genérico ("afeta todas as empresas que usam esta tabela") + `confirm()` nativo em vez de um número exato.
- **Files modified:** `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx`
- **Verification:** Build passa; testes de contrato cobrem as props que EXISTEM; comportamento documentado nesta seção e no docblock do componente.
- **Committed in:** `e4557be3` (Tarefa 2)

---

**Total deviations:** 1 auto-fixed (Rule 2, sem tocar arquivo fora de escopo)
**Impact on plan:** D-04/D-05/D-13 entregues para os casos com dado disponível (criar tabela própria a partir do serviço, editar tabela de serviço, remover exceção própria, estado A DEFINIR). O único caso degradado é reeditar uma tabela própria já existente sem visibilidade das linhas atuais — funcional mas exige que quem edita saiba os valores de cor ou confira em outro lugar antes de salvar. Fica registrado para o plano 137-08/AdminController expor essas linhas numa passada futura.

## Issues Encountered

- Nenhum bloqueio de execução. O gap de dados acima foi a única fricção, resolvida via design defensivo em vez de checkpoint (não havia decisão arquitetural a pedir ao usuário — só ausência de um campo).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `TabelaFaixasSection` já expõe `route('admin.financeiro.faixas.servico', ...)` — pré-requisito citado pelo plano 137-10 ("sem isso o checkpoint do plano 10 não é executável") está presente.
- Gap conhecido para uma sessão futura: expor as linhas da exceção própria de uma empresa via `AdminController::fechamento()` (ou endpoint dedicado) para permitir edição pré-preenchida; e expor contagem de empresas por serviço para o aviso de "Editar tabela do serviço" virar um número exato.
- Suíte de testes: `Phase122|Phase136|Phase137` = **226 testes passando, 1115 asserções, 0 falhas** (baseline informado era 219/1068 — cresceu exatamente pelos 7 testes/47 asserções deste plano, nenhuma outra alteração de contagem). Falhas pré-existentes em `FechamentoMigrationTest`/`AdminFechamentoControllerTest` seguem fora do filtro, não tocadas.

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-03*

## Self-Check: PASSED

- FOUND: resources/js/Pages/Admin/Financeiro.jsx
- FOUND: resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx
- FOUND: tests/Feature/Phase137/Phase137FinanceiroUiContratoTest.php
- FOUND: commits d33e07c8, e4557be3, 729af224
