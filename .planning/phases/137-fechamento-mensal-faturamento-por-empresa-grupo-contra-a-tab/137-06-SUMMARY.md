---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 06
subsystem: api
tags: [laravel, form-request, validation, rbac, artisan-call, fechamento]

# Dependency graph
requires:
  - phase: 137-01
    provides: "ServicoFaixaFaturamento / EmpresaFaixaFaturamento (models + migrations)"
  - phase: 137-03
    provides: "FechamentoFaixaResolver::paraEmpresa()/classificar()"
  - phase: 137-05
    provides: "comando fechamento:consolidar-mes (--mes, --motivo, --por, --se-ausente) e FechamentoSnapshotWriter"
provides:
  - "SalvarFaixasFaturamentoRequest — validação da tabela inteira de faixas (ordens únicas, faixa sem teto única e na última posição, valor_e_piso restrito à faixa sem teto, limites estritamente crescentes)"
  - "FechamentoController — CRUD all-or-nothing das faixas por serviço/empresa (D-04/D-13) + fecharCompetencia/refazerCompetencia (D-11/D-12) via Artisan::call pelo exit code"
  - "5 rotas novas dentro do grupo administrativo existente (12 rotas admin.financeiro.* no total)"
affects: [137-08, 137-09, 137-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "FormRequest::withValidator() com regra composta parando no primeiro conflito (molde UpdateBonusFaixaRequest), erros apontando faixas.{idx}.{campo} pelo índice ORIGINAL do payload"
    - "Controller decide resposta HTTP pelo exit code de Artisan::call(), nunca pela saída de texto — saída só entra em Log::error"

key-files:
  created:
    - app/Http/Requests/SalvarFaixasFaturamentoRequest.php
    - app/Http/Controllers/FechamentoController.php
    - tests/Feature/Phase137/Phase137FaixasCrudTest.php
    - tests/Feature/Phase137/Phase137CompetenciaEndpointTest.php
  modified:
    - routes/web.php

key-decisions:
  - "Mensagem de sobreposição usa <= (não <) — limites IGUAIS entre faixas consecutivas também são recusados, não só inversão"
  - "'Buraco' entre faixas não é representável neste schema — limite_inferior é DERIVADO do limite_superior da faixa anterior em FechamentoFaixaResolver::classificar(), nunca um campo de input; uma régua estritamente crescente é sempre contígua por construção"
  - "translatedFormat('F Y') com ->locale('pt_BR') explícito nas mensagens de erro de fechar/refazer, por robustez independente do APP_LOCALE do ambiente"
  - "2 commits em vez de 3 (Tarefas 1+2 combinadas): Phase137FaixasCrudTest.php nasceu como um arquivo só cobrindo validação (Tarefa 1) e persistência (Tarefa 2) — não fazia sentido dividir um teste que hoje é uma unidade coesa"

requirements-completed: [D-04, D-11, D-12, D-13]

# Metrics
duration: ~70min
completed: 2026-09-03
---

# Phase 137 Plano 06: Cadastro de faixas + fechar/refazer competência Summary

**FormRequest de validação de régua progressiva (sobreposição/faixa-piso/faixa-sem-teto) + FechamentoController com CRUD all-or-nothing das faixas e fechar/refazer competência decidido pelo exit code do Artisan::call, tudo protegido por guard duplo role:admin.**

## Performance

- **Duration:** ~70 min
- **Started:** 2026-09-03 (sessão de leitura de contexto + implementação)
- **Completed:** 2026-09-03
- **Tasks:** 3 de 3
- **Files modified:** 5 (2 novos arquivos de app, 2 novos arquivos de teste, 1 arquivo de rotas modificado)

## Accomplishments
- `SalvarFaixasFaturamentoRequest` valida a régua inteira de uma vez (D-13): ordens únicas, no máximo uma faixa sem teto (e ela precisa ser a de maior ordem), `valor_e_piso` só permitido na faixa sem teto, limites estritamente crescentes com a mensagem literal de sobreposição do UI-SPEC citando a ordem conflitante.
- `FechamentoController` expõe CRUD all-or-nothing das faixas (por serviço e por empresa, dentro de `DB::transaction`) e as ações `fecharCompetencia`/`refazerCompetencia`, que delegam o cálculo inteiro para `fechamento:consolidar-mes` e decidem 200/409 pelo **exit code** do comando (nunca pela saída de texto).
- 5 rotas novas registradas dentro do grupo `administrativo` já existente, antes de `/financeiro/{company}` — as 12 rotas `admin.financeiro.*` (7 antigas + 5 novas) confirmadas via `route:list`.
- Guard duplo (`role:admin` do grupo + `authorize()`/`abort_unless`) cobrindo os 5 endpoints — 403 testado explicitamente em cada um.

## Task Commits

1. **Tarefas 1+2: FormRequest de validação + CRUD das faixas** - `2b7f6945` (feat)
2. **Tarefa 3: endpoints de fechar/refazer + rotas** - `eb5fc3fc` (feat)

**Plan metadata:** (este commit — SUMMARY + STATE)

## Files Created/Modified
- `app/Http/Requests/SalvarFaixasFaturamentoRequest.php` - validação composta da régua inteira de faixas
- `app/Http/Controllers/FechamentoController.php` - CRUD de faixas (serviço/empresa) + fechar/refazer competência
- `routes/web.php` - 5 rotas novas dentro do grupo `administrativo` (`use App\Http\Controllers\FechamentoController` adicionado)
- `tests/Feature/Phase137/Phase137FaixasCrudTest.php` - 16 testes (validação + persistência + 403)
- `tests/Feature/Phase137/Phase137CompetenciaEndpointTest.php` - 9 testes (fechar/refazer + registro de rotas)

## Decisions Made
- **Combinei as Tarefas 1 e 2 num único commit.** `Phase137FaixasCrudTest.php` é um arquivo só desde a criação (o próprio plano manda "acrescentar" os casos de persistência da Tarefa 2 ao arquivo da Tarefa 1) — dividir esse arquivo em dois commits exigiria reescrevê-lo artificialmente duas vezes sem ganho real de rastreabilidade. Separei fisicamente o `FechamentoController` em duas versões (3 métodos → commit 1; +2 métodos → commit 2) para manter a Tarefa 3 num commit próprio e testável isoladamente.
- **Regra de sobreposição usa `<=`, não `<`.** Limites IGUAIS entre faixas consecutivas (`ordem 1 = 100.000`, `ordem 2 = 100.000`) são tratados como sobreposição, coberto por teste dedicado (`payload_com_limites_iguais_e_tratado_como_sobreposicao`) — evita ambiguidade de "em qual faixa cai o faturamento exatamente igual ao limite".
- **"Buraco" entre faixas não existe neste schema.** `limite_inferior` de cada faixa é sempre DERIVADO do `limite_superior` da faixa anterior dentro de `FechamentoFaixaResolver::classificar()` — nunca um campo de input. Uma régua que passa na validação de crescimento estrito é sempre contígua por construção; documentei isso no docblock do FormRequest e cobri com teste (`payload_com_ordens_nao_sequenciais_mas_crescentes_e_aceito`, ordens 1 e 3 pulando o 2, ainda contíguo).
- **Mensagens de erro de fechar/refazer usam `ucfirst($mes->locale('pt_BR')->translatedFormat('F Y'))`.** Segui a instrução explícita do `<action>` do plano (que pede `translatedFormat('F Y')`, produzindo "Agosto 2026") em vez do formato `{mês}/{ano}` com barra que aparece no exemplo do CTA do UI-SPEC (que é para o texto do BOTÃO, não da mensagem de erro).

## Deviations from Plan

None - plan executado como escrito, sem necessidade de Rule 1/2/3/4.

## Issues Encountered

Nenhum. A única complexidade real foi de sequenciamento de commit (ver "Decisions Made" acima) — resolvida sem impacto no código de produção.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Backend do plano 06 está completo e testado: cadastro manual de faixas (D-04) e fechar/refazer competência (D-11/D-12) já existem como ações HTTP protegidas por `role:admin`.
- **Pendente para as próximas fases (07+):** a superfície React (`TabelaFaixasSection`, `FaixaFormDialog`, `StatusCompetenciaBadge`, `FecharCompetenciaButton`, `RefazerFechamentoDialog` etc., especificados no `137-UI-SPEC.md` seções 4/5) ainda não consome estes endpoints — este plano só entregou o backend.
- `AdminController.php` está sendo migrado em paralelo pelo plano 137-07 nesta mesma wave — não foi tocado por este plano.
- Gate `Phase122|Phase136|Phase137`: **209 testes / 1009 asserções / 0 falhas** (era 184/926 antes deste plano — 25 testes novos, 83 asserções novas, sem regressão). `AdminFechamentoControllerTest` segue com as mesmas 5/16 falhas pré-existentes (documentadas em `deferred-items.md`, fora do escopo deste plano).

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-03*

## Self-Check: PASSED

Todos os 5 arquivos declarados (FormRequest, controller, 2 arquivos de teste, este SUMMARY)
confirmados no disco por reconsulta. Os 2 commits (`2b7f6945`, `eb5fc3fc`) confirmados em
`git log --oneline --all`.
