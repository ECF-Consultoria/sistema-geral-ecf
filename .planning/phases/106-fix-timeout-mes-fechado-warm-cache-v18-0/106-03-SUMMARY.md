---
phase: 106-fix-timeout-mes-fechado-warm-cache-v18-0
plan: 03
subsystem: frontend — Performance/Index.jsx (ranking de consultoria)
tags: [ux, poll, degradacao-graciosa, performance, inertia]
dependency-graph:
  requires:
    - "PerformanceController::index gate quente/frio + prop 'aquecendo' (Plan 106-02)"
    - "Linhas de ranking com 'calculando:true' (Plan 106-02)"
  provides:
    - "Estado visual 'calculando…' por linha do ranking (sem número/badge)"
    - "Poll parcial condicionado a 'aquecendo' com teto de tentativas"
  affects:
    - "Performance/Index.jsx (PerformanceIndex + RankingConsultoria) — ramo Polos intocado"
tech-stack:
  added: []
  patterns:
    - "router.reload({ only, preserveScroll, preserveState }) em setInterval — reusa padrão já em produção no Modo TV (Polos)"
    - "useRef como contador de tentativas fora do ciclo de render (não dispara re-render a cada tick)"
key-files:
  created: []
  modified:
    - resources/js/Pages/Performance/Index.jsx
decisions:
  - "Teto (20 tentativas / ~2min) implementado com useRef + clearInterval interno no próprio callback do setInterval, em vez de um segundo useEffect — evita condição de corrida entre dois efeitos que dependeriam do mesmo contador."
  - "Deltas/NPS/Var Fat/Var Margem forçados para null explicitamente quando calculando=true (em vez de confiar só em 'componentes' vir null do backend) — defensivo contra qualquer variação futura do payload que ainda marque calculando=true mas deixe algum componente parcialmente preenchido."
metrics:
  duration: "~20min"
  completed: "2026-07-21"
---

# Phase 106 Plan 03: Estado "calculando…" + poll no Ranking de Performance Summary

Fechou o loop de UX da degradação graciosa (SC2/SC3): consumindo `aquecendo` +
`calculando` por linha do payload do Plan 106-02, a tela `/performance` deixa de
correr risco de tela branca por timeout em mês fechado frio — em vez disso mostra
"calculando…" nas linhas ainda não aquecidas e faz poll automático até tudo
esquentar (ou até um teto de ~2min, com aviso e recarga manual).

## O que foi feito

### Task 1 — Prop `aquecendo`, poll condicional com teto, placeholder `calculando…` por linha
- Destructuring de `PerformanceIndex` (~204-226): nova prop `aquecendo = false`
  com comentário pt-BR (Fase 106 SC2).
- Novo `useEffect` (logo após o ESC do drawer, ~276-311): quando `aquecendo`
  é true, `setInterval` de 6s chama `router.reload({ only: ['ranking',
  'aquecendo'], preserveScroll: true, preserveState: true })` só com a aba
  visível (`!document.hidden`). Contador de tentativas via `useRef`
  (`tentativasAquecendoRef`) — ao atingir 20 (~2min), o próprio callback do
  interval faz `clearInterval` e seta `pollEsgotado=true`. Quando `aquecendo`
  vira `false`, o efeito reseta o contador e o estado de esgotado. Cleanup
  padrão no `return`.
- Botão `recarregarAquecendoManual`: zera contador, limpa `pollEsgotado` e
  dispara um reload imediato.
- Aviso de teto esgotado (~521-536, acima da tabela): bloco `amber-*` com
  texto "Demorando mais que o esperado para calcular este mês." + botão
  "Recarregar", só visível quando `pollEsgotado && aquecendo`.
- `RankingConsultoria` (~615-734): cada linha calcula `const calculando =
  u.calculando === true`. Quando true:
  - Linha inteira perde `hover`/`cursor-pointer` (vira `cursor-default`) e o
    `onClick` deixa de navegar para `performance.show`.
  - Coluna Nota mostra `"calculando…"` em `text-white/40 animate-pulse` com
    `title` explicando o estado transitório — sem número, sem badge de
    `score_status`.
  - Coluna Faixa mostra `—` em vez do badge de faixa/promovida.
  - Delta vs mês passado, NPS médio, Var Faturamento e Var Margem forçados
    para `—` (via `null` explícito passado aos componentes de célula, mesmo
    que o backend já zere `componentes`).
  - Coluna "Empresas" (composição de carteira, não resultado de cálculo) e
    Nome/cargo permanecem normais — não fazem parte do "resultado calculado".
- `npm run build` — exit 0 (só warnings pré-existentes de libs de terceiros:
  `duration-[900ms]` ambíguo no Tailwind e anotações `/*#__PURE__*/` do
  `@glideapps/glide-data-grid`, nenhum dos dois relacionado a este plano).
- Commit: `163dd4a` — `feat(106-03): estado calculando + poll condicional no ranking de Performance`

### Task 2 — checkpoint:human-verify (NÃO executado por este agente)
Conforme instrução do orquestrador, esta task é um checkpoint visual que exige
verificação humana (fila local rodando, cache limpo, clicar em "Mês fechado"
sem aquecer antes, observar `"calculando…"` + poll autorresolvente, e testar
o teto com o worker parado). Reportado como pronto para o checkpoint — não
avançado além da Task 1.

## Deviations from Plan

Nenhum desvio — Task 1 implementada à risca conforme o plano (prop, poll com
teto, placeholder por linha, build limpo).

## Fronteira preservada

`git diff` do commit `163dd4a` mostra 8 hunks, todos dentro de
`PerformanceIndex` (props/destructuring, useEffect de poll, aviso de teto,
render da tabela) e `RankingConsultoria` (linhas do ranking). O ramo
`isPolos`/`PolosDashboard` (Modo TV, ~1650-1700+) permanece byte-a-byte
idêntico — confirmado via `git diff --stat` (só 1 arquivo) e inspeção dos
hunks (`@@` markers todos nas linhas 221-750, nenhum na faixa 1650+ onde vive
`PolosDashboard`).

## Verificação executada

- `npm run build` — exit 0
- `git status --short` antes de `git add` — só `Index.jsx` staged, nenhum
  arquivo da sessão paralela (Dashboard*/Nps*/shopee*) tocado
- `git diff --diff-filter=D --name-only HEAD~1 HEAD` — vazio (nenhuma
  deleção acidental)
- Pré-flight obrigatório (`git status --porcelain resources/js/Pages/Performance/Index.jsx`
  antes de qualquer edição) — vazio, sessão paralela não estava mexendo no
  arquivo no momento da execução

## Self-Check: PASSED

- `resources/js/Pages/Performance/Index.jsx` — FOUND (modificado)
- Commit `163dd4a` — FOUND (`git log --oneline -1`)

## Aguardando

Checkpoint visual da Task 2 (ver `.planning/phases/106-fix-timeout-mes-fechado-warm-cache-v18-0/106-03-PLAN.md`,
seção `<task type="checkpoint:human-verify">`). Requer o usuário rodar
`queue:work` local, limpar cache, abrir `/performance` em mês fechado frio e
confirmar visualmente o estado "calculando…" + poll + teto de esgotamento.
