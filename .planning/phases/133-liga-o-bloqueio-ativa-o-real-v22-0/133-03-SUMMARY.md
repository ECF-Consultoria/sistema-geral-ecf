---
phase: 133-liga-o-bloqueio-ativa-o-real-v22-0
plan: 03
subsystem: administrativo
tags: [laravel, inertia, react, kill-switch, contrato, ui]

# Dependency graph
requires:
  - phase: 131 (tela administrativa de contratos)
    provides: "ContratoAdminController::index() + Admin/Contratos.jsx — a lista de empresas retidas que já existia"
  - phase: 124 (refatoração EmpresaOperacionalRouter)
    provides: "EmpresaOperacionalRouter::bloqueioAtivo() — o único ponto autorizado de leitura do interruptor"
provides:
  - "Prop bloqueio_ativo em ContratoAdminController::index(), calculada no servidor via bloqueioAtivo()"
  - "Faixa âmbar condicional em Admin/Contratos.jsx explicando a consequência do bloqueio, sem jargão (UI-06)"
  - "Primeira cobertura de teste da prop nos três estados possíveis da chave (ligada, desligada, nunca gravada)"
affects: [133-04, 133-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Injeção de EmpresaOperacionalRouter por parâmetro de método (mesmo padrão de ContratosPresosService no mesmo index())"
    - "Prop booleana snake_case pronta do backend, componente só renderiza (nenhuma decisão de exibição migra pro client)"
    - "Faixa informativa sem <Card>, mesma variante âmbar de ContratoDetalhe.jsx (canal neutro/âmbar, nunca vermelho)"

key-files:
  created:
    - tests/Feature/Phase133/FaixaBloqueioContratosTest.php
  modified:
    - app/Http/Controllers/ContratoAdminController.php
    - resources/js/Pages/Admin/Contratos.jsx

key-decisions:
  - "D-04 (herdada do CONTEXT): faixa na tela que já existe, sem criar tela nova nem lista nova — a listagem de retidas já era da Fase 131."
  - "Texto da faixa fala do efeito (empresa aguardando assinatura, entra sozinha quando assinar), nunca do mecanismo (nenhuma das palavras proibidas do UI-06 aparece em texto visível)."

requirements-completed: [FLUXO-01, FLUXO-02]

# Metrics
duration: ~30min
completed: 2026-08-18
---

# Phase 133 Plano 03: Faixa de aviso na tela de Contratos (D-04) Summary

**A tela de Contratos do Administrativo, que já listava as empresas retidas desde a Fase 131, ganhou uma prop `bloqueio_ativo` calculada no servidor e uma faixa âmbar condicional que diz, em português comum, que a empresa não entra na operação até o contrato ser assinado — visível só quando o interruptor está ligado.**

## Performance

- **Duration:** ~30 min
- **Tasks:** 2/2 completed
- **Files modified:** 3 (1 criado, 2 modificados)

## Accomplishments
- `ContratoAdminController::index()` passou a injetar `EmpresaOperacionalRouter` (mesmo padrão de injeção por parâmetro já usado com `ContratosPresosService`) e a enviar `bloqueio_ativo => $router->bloqueioAtivo()` na prop Inertia — lida do único ponto autorizado, nunca `Configuracao::get()` direto no controller.
- Nenhuma outra prop mudou: `linhas`, `filters`, `resumo` e `sem_contrato_count` continuam exatamente como a Fase 131 entregou, provado por teste e pela regressão completa da suíte `Phase131` (72 testes verdes).
- `Admin/Contratos.jsx` ganhou a faixa âmbar (variante sem `<Card>`, mesmo canal neutro/âmbar de `ContratoDetalhe.jsx`), posicionada logo após o `<h1>` e antes do grid de resumo — sem empurrar o ponto focal da tela para baixo.
- Texto sem jargão (UI-06): fala da consequência (empresa aguardando assinatura, retomada automática quando o contrato for assinado), nunca do mecanismo — confirmado por grep contra a lista de palavras proibidas.
- `tests/Feature/Phase133/FaixaBloqueioContratosTest.php` prova os três estados da chave (`'1'`, `'0'`, ausente em `configuracoes`) e que as demais props continuam intactas, por reconsulta às props Inertia via `assertInertia`/`viewData`.

## Task Commits

Each task was committed atomically:

1. **Task 1: prop `bloqueio_ativo` no `index()` + o teste dos dois estados** - `f50ea949` (test)
2. **Task 2: a faixa na tela `/administrativo/contratos` (D-04, sem jargão)** - `b5596554` (feat)

_TDD: a task 1 combinou implementação e teste no mesmo commit — a checagem "RED antes do GREEN" não se aplicava aqui porque a leitura de `bloqueioAtivo()` já existia desde a Fase 124/128; o trabalho novo era só expor o dado como prop, sem comportamento condicional a provar em duas fases separadas. Os 4 testes passaram já na primeira execução, contra a implementação escrita no mesmo commit._

## Files Created/Modified
- `tests/Feature/Phase133/FaixaBloqueioContratosTest.php` - 4 testes: prop verdadeira com a chave ligada, falsa com a chave desligada, falsa quando a chave nunca foi gravada, e as demais props da tela intactas
- `app/Http/Controllers/ContratoAdminController.php` - `index()` ganha `EmpresaOperacionalRouter $router` na assinatura e a prop `bloqueio_ativo` no `Inertia::render`
- `resources/js/Pages/Admin/Contratos.jsx` - desestruturação de `bloqueio_ativo = false` + faixa âmbar condicional entre o `<h1>` e o grid de resumo

## Decisions Made
Nenhuma decisão nova além das já travadas no CONTEXT.md (D-04). O texto exato da faixa e sua posição na tela eram "Claude's Discretion" no CONTEXT — resolvidos seguindo o molde de linguagem de `ContratoDetalhe.jsx` (bloco "emissão pausada" da Fase 132) e a posição indicada no `<interfaces>` do plano.

## Deviations from Plan
Nenhuma funcional. Um ajuste de redação do comentário do JSX: a primeira versão do comentário citava literalmente `<Card>` para explicar a variante escolhida, o que fazia o `grep -c "<Card"` do critério de aceite contar uma ocorrência a mais (2→3) mesmo sem nenhum componente `<Card>` novo ter sido adicionado. Reescrito para "faixa informativa sem card" — comentário continua claro, grep volta a bater exatamente com o texto do `<acceptance_criteria>`. Sem impacto em comportamento ou nos testes.

## Known Stubs
Nenhum. A prop é lida do backend real (`EmpresaOperacionalRouter::bloqueioAtivo()`), não há dado hardcoded nem placeholder.

## Threat Flags
Nenhuma superfície nova além da já registrada no `<threat_model>` do próprio plano (T-133-12 a T-133-15, todos com disposição `accept`/`mitigate` já endereçada pelas asserções do teste e pelos greps de aceite: ausência de `Configuracao::get` no controller, ausência de jargão em texto visível, faixa que só aparece quando a chave está de fato ligada).

## Self-Check: PASSED

- `app/Http/Controllers/ContratoAdminController.php` — FOUND (modificado)
- `resources/js/Pages/Admin/Contratos.jsx` — FOUND (modificado)
- `tests/Feature/Phase133/FaixaBloqueioContratosTest.php` — FOUND
- Commit `f50ea949` — FOUND
- Commit `b5596554` — FOUND
- `tests/Feature/Phase133` — `Tests: 19 passed (84 assertions)`, confirmado por execução real (inclui os 3 arquivos de teste dos planos 01/02/03)
- `tests/Feature/Phase131` — `Tests: 72 passed (254 assertions)`, regressão confirmada por execução real
