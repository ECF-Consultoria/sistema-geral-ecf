---
phase: 139-redesenho-da-tela-de-fechamento
plan: 07
subsystem: financeiro
tags: [fechamento, relatorio, email, pdf, inertia, laravel, blade]

# Dependency graph
requires:
  - phase: 139-05
    provides: Área expandida em três passos, tabela progressiva com faixa destacada — o `RecebidoToggle` ficou definido nesse plano sem chamador ativo
provides:
  - Marcador de "recebido" removido dos seis pontos onde vivia — tela, controller, rota, e-mail mensal e dois PDFs
  - Trava de teste (Phase139SemMarcadorRecebidoTest) impedindo o marcador de voltar em qualquer superfície visível/emitida
affects: [financeiro, admin-controller, relatorios-fechamento]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Trava por comportamento visível/emitido, não por string solta — necessário quando a tabela/model relacionados continuam existindo de propósito"

key-files:
  created:
    - tests/Feature/Phase139/Phase139SemMarcadorRecebidoTest.php
  modified:
    - resources/js/Pages/Admin/Financeiro.jsx
    - app/Http/Controllers/AdminController.php
    - routes/web.php
    - app/Jobs/EnviarRelatorioFechamentoJob.php
    - app/Mail/RelatorioFechamentoMail.php
    - resources/views/admin/relatorio-fechamento.blade.php
    - resources/views/admin/relatorio-geral.blade.php
    - resources/views/admin/relatorio-geral-pdf.blade.php
    - resources/views/emails/relatorio-fechamento.blade.php
    - tests/Feature/Phase137/Phase137CompetenciaEndpointTest.php
    - tests/Feature/Phase137/Phase137RelatoriosFechamentoTest.php

key-decisions:
  - "Removido de todos os 6 pontos, não só da tela — decisão do usuário 2026-09-04 baseada em dado medido (marcador usado 1 vez em produção, nunca mais)"
  - "Tabela fechamento_recebidos e model FechamentoRecebido preservados — linha histórica de abril/2026 intacta, sem migration de drop"
  - "Escopo ampliado além do mapa de interfaces do plano: relatorio-geral.blade.php e emails/relatorio-fechamento.blade.php são as views REAIS usadas pelo PDF/e-mail em produção, não as listadas no plano"

requirements-completed: [D-01]

# Metrics
duration: ~35min
completed: 2026-09-04
---

# Phase 139 Plan 07: Remoção do marcador de recebido Summary

**Marcador de pagamento "recebido" removido de oito arquivos (tela, controller, rota, job, mail e quatro blades) — incluindo dois arquivos não mapeados pelo plano que são as views reais usadas em produção pelo PDF geral e pelo corpo do e-mail mensal.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-04T12:05:00-03:00 (aprox.)
- **Completed:** 2026-09-04T12:42:05-03:00
- **Tasks:** 3/3
- **Files modified:** 11 (3 no Task 1, 8 no Task 2) + 1 criado (Task 3)

## Accomplishments
- Marcador de "recebido" removido de TODOS os pontos onde afirmava se um cliente pagou — tela, controller (5 emissões + `toggleRecebido()`), rota, job de e-mail, e quatro blades (2 mapeadas pelo plano + 2 descobertas em produção)
- `fechamento_recebidos`/`FechamentoRecebido` preservados intactos — linha histórica de abril/2026 continua no banco e continua gravável
- Trava de teste com 10 casos cobrindo cada um dos seis pontos pelo que é visível/emitido (rotas registradas, chaves de prop, HTML renderizado), não pela string solta — evita reprovar por causa da tabela/model que devem continuar existindo

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Tirar o marcador da tela e do backend que a alimenta** - `f2811636` (feat)
2. **Tarefa 2: Tirar o pagamento do e-mail mensal e dos PDFs** - `5ed80d1e` (feat)
3. **Tarefa 3: Trava para o marcador não voltar** - `bdbb6127` (test)

_Nenhuma tarefa TDD — commits únicos por tarefa._

## Files Created/Modified

**Tarefa 1:**
- `resources/js/Pages/Admin/Financeiro.jsx` - remove `RecebidoToggle` (sem chamador ativo — tinha um chamador real na área expandida, diferente do que o prompt de execução indicava), o filtro "Pagamento", contagens Recebidas/Pendentes do dropdown de PDF, import não usado do ícone `Check`
- `app/Http/Controllers/AdminController.php` - remove `toggleRecebido()`, a leitura de `FechamentoRecebido`, o parâmetro `$recebidos` nas 2 fontes de dados + 2 agregações de grupo (5 emissões da chave `'recebido'`), em `fechamento()`, `gerarRelatorio()` e `gerarRelatorioGeral()` (inclui o filtro `?recebido=sim/nao`), e o `use App\Models\FechamentoRecebido`
- `routes/web.php` - remove `POST /financeiro/{company}/recebido`

**Tarefa 2:**
- `app/Jobs/EnviarRelatorioFechamentoJob.php` - remove leitura de `FechamentoRecebido`, parâmetro repassado pelas 4 fontes de dados, chave `'recebido'`, totais `total_recebido`/`total_pendente`
- `app/Mail/RelatorioFechamentoMail.php` - remove `filtro_recebido` morto repassado pro Browsershot
- `resources/views/admin/relatorio-fechamento.blade.php` (PDF por empresa) - remove selo de status e estilo associado
- `resources/views/admin/relatorio-geral.blade.php` - **não estava no mapa do plano**; é a view REAL usada pelo dropdown "Gerar relatórios" e pelo anexo PDF do e-mail (`RelatorioFechamentoMail::gerarPdf()` via Browsershot). Remove título condicional por filtro, contadores Recebidas/Pendentes, selo por empresa
- `resources/views/admin/relatorio-geral-pdf.blade.php` - arquivo listado no plano; confirmado **órfão** (nenhum `view()` no código aponta pra ele) — limpo por consistência, não por uso ativo
- `resources/views/emails/relatorio-fechamento.blade.php` - **não estava no mapa do plano**; é o CORPO do e-mail (`RelatorioFechamentoMail::content()`). Remove coluna Status da tabela e card "Total Recebido"/"Total Pendente"
- `tests/Feature/Phase137/Phase137CompetenciaEndpointTest.php` - atualizado: a rota `admin.financeiro.recebido` saiu da lista de "rotas antigas que continuam"; assert explícito de que foi removida
- `tests/Feature/Phase137/Phase137RelatoriosFechamentoTest.php` - helper de PDF do job para de repassar `filtro_recebido`

**Tarefa 3:**
- `tests/Feature/Phase139/Phase139SemMarcadorRecebidoTest.php` - 10 testes cobrindo os seis pontos

## Decisions Made
- **Escopo ampliado além do mapa do plano.** O plano listava `relatorio-geral-pdf.blade.php` como um dos "dois PDFs", mas a investigação mostrou que esse arquivo está órfão — `AdminController::gerarRelatorioGeral()` e `RelatorioFechamentoMail::gerarPdf()` (via Browsershot) renderizam `admin.relatorio-geral.blade.php`, um arquivo diferente e não mapeado. Da mesma forma, `resources/views/emails/relatorio-fechamento.blade.php` (o corpo do e-mail) também não estava no mapa. Removê-los ficaria fora do objetivo da fase: o e-mail e o PDF real de produção continuariam afirmando "tudo pendente" — exatamente o defeito que esta fase existe para eliminar. Apliquei Rule 1/2 (bug + funcionalidade crítica ausente) e tratei os dois como parte da Tarefa 2.
- **`RecebidoToggle` tinha um chamador ativo**, ao contrário do que o prompt de execução indicava ("sem chamador — plano 04 já tirou a chamada"). Encontrado em `resources/js/Pages/Admin/Financeiro.jsx:1006` na barra de ações da área expandida. Removido junto com a função.
- Dois testes pré-existentes da Fase 137 precisaram de atualização porque afirmavam explicitamente que `admin.financeiro.recebido` "continua presente" — comportamento que esta fase reverte intencionalmente por decisão do usuário.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1/2 - Bug + Funcionalidade crítica ausente] `relatorio-geral.blade.php` e `emails/relatorio-fechamento.blade.php` não mapeados pelo plano**
- **Found during:** Tarefa 2
- **Issue:** O plano mapeava `relatorio-geral-pdf.blade.php` como um dos dois PDFs, mas esse arquivo está órfão (nenhum `view()` aponta pra ele). A view real usada pelo dropdown "Gerar relatórios" E pelo anexo PDF do e-mail (via Browsershot) é `admin.relatorio-geral.blade.php` — que também tinha selo de recebido, contadores e filtro por pagamento. O corpo do e-mail (`emails/relatorio-fechamento.blade.php`, renderizado por `RelatorioFechamentoMail::content()`) também tinha coluna de Status e totais Recebido/Pendente e não estava no mapa.
- **Fix:** Limpeza aplicada aos dois arquivos reais, além dos dois mapeados pelo plano (`relatorio-fechamento.blade.php` e o órfão `relatorio-geral-pdf.blade.php`, mantido por consistência).
- **Files modified:** `resources/views/admin/relatorio-geral.blade.php`, `resources/views/emails/relatorio-fechamento.blade.php`, `app/Mail/RelatorioFechamentoMail.php` (removeu `filtro_recebido` morto)
- **Verification:** `Phase137RelatoriosFechamentoTest` (7/7) + `Phase139SemMarcadorRecebidoTest` renderiza as duas views reais com dados do job e assere ausência de "recebido"/"pendente"
- **Committed in:** `5ed80d1e` (Tarefa 2)

**2. [Rule 1 - Bug] Testes pré-existentes afirmavam que a rota removida "continua presente"**
- **Found during:** Tarefa 2 (rodada do gate completo)
- **Issue:** `Phase137CompetenciaEndpointTest::as_5_rotas_novas_de_financeiro_estao_registradas` incluía `admin.financeiro.recebido` na lista de "rotas antigas que continuam presentes (nada foi removido)" — comportamento que este plano reverte por decisão explícita do usuário.
- **Fix:** Rota removida da lista de "continuam"; adicionada assertNotContains explícita com o motivo.
- **Files modified:** `tests/Feature/Phase137/Phase137CompetenciaEndpointTest.php`
- **Verification:** `--filter="Phase137"` verde (117/117)
- **Committed in:** `5ed80d1e` (Tarefa 2)

---

**Total deviations:** 2 auto-fixed (1 funcionalidade crítica ausente do mapa do plano, 1 bug em teste pré-existente)
**Impact on plan:** Ambos essenciais para cumprir o objetivo da fase ("nenhuma tela, PDF ou e-mail afirma se um cliente pagou"). Sem eles, o e-mail e o PDF real de produção continuariam com o defeito que motivou a fase. Sem scope creep além disso — nenhum arquivo fora dos seis pontos + suas duas dependências reais foi tocado.

## Issues Encountered
None além das deviations documentadas acima.

## Known Stubs
Nenhum stub introduzido.

## Threat Flags
Nenhuma superfície nova (endpoint, auth, schema) introduzida — plano é de remoção pura.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Self-Check

- `resources/js/Pages/Admin/Financeiro.jsx` — FOUND, `grep -c "recebido"` = 0
- `app/Http/Controllers/AdminController.php` — FOUND, `grep -c "recebido"` = 0
- `routes/web.php` — FOUND, rota `admin.financeiro.recebido` ausente de `route:list`
- `app/Jobs/EnviarRelatorioFechamentoJob.php` — FOUND, `grep -c "recebido"` = 0 (exceto comentário explicativo sem a palavra)
- `resources/views/admin/relatorio-fechamento.blade.php` — FOUND, `grep -c "recebido"` = 0
- `resources/views/admin/relatorio-geral.blade.php` — FOUND, `grep -c "recebido"` = 0
- `resources/views/admin/relatorio-geral-pdf.blade.php` — FOUND, `grep -c "recebido"` = 0
- `resources/views/emails/relatorio-fechamento.blade.php` — FOUND, `grep -c "recebido"` = 0
- `tests/Feature/Phase139/Phase139SemMarcadorRecebidoTest.php` — FOUND, 10 testes
- Commit `f2811636` — FOUND em `git log`
- Commit `5ed80d1e` — FOUND em `git log`
- Commit `bdbb6127` — FOUND em `git log`
- `fechamento_recebidos` (tabela) e `FechamentoRecebido` (model) — preservados, testados em `a_tabela_fechamento_recebidos_continua_existindo_e_nao_vaza_pra_nenhuma_superficie`

## Self-Check: PASSED

## Next Phase Readiness
- Plano 07 completo — os seis pontos do marcador de recebido foram eliminados e travados por teste.
- Gate `--filter="Phase122|Phase136|Phase137|Phase138|Phase139"`: **312 testes / 1614 assertivas / 0 falhas** (baseline 302/1584 + 10 testes novos / 30 assertivas).
- `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa_de_uma_empresa_gera_aviso_novo_so_sobre_ela` confirmado flaky pré-existente (passa isolado) — não é regressão deste plano.
- `npm run build` — exit code 0.
- Sem bloqueios para os próximos planos da Fase 139.

---
*Phase: 139-redesenho-da-tela-de-fechamento*
*Completed: 2026-09-04*
