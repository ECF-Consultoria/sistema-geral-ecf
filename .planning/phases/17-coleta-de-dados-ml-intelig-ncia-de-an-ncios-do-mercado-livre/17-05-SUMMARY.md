---
phase: 17-coleta-de-dados-ml
plan: 05
subsystem: ui
tags: [react, inertia, polling, tailwind, ecf-design, mlb, coleta]

requires:
  - phase: 17-04
    provides: rotas mlb.coleta.* + props coletas/coleta (Inertia)
provides:
  - Página Mlb/Coleta.jsx (formulário + polling 3s + histórico + relatório colapsável)
  - Item de navegação 'Int. Anúncios' no AppLayout (gated mlb.coleta)
affects: [fase-2-ia-mag-t8]

tech-stack:
  added: []
  patterns:
    - "Polling fetch+setInterval 3s com deadline 10min e router.reload({only}) — padrão Grants/Index.jsx"
    - "Relatório em cards colapsáveis (componente local Secao, chevron)"

key-files:
  created:
    - resources/js/Pages/Mlb/Coleta.jsx
  modified:
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Recomendação permanece heurística (D-05) — a versão ciente do produto (MAG T8/IA) é a Fase 2"
  - "categoria_id como input de texto (opcional) em vez de <select> — não há lista de categorias; domain_discovery resolve automaticamente"

patterns-established:
  - "Card colapsável Secao para não despejar dados 'soltos' (feedback do checkpoint)"
  - "Navegação do histórico com preserveScroll/preserveState para não reiniciar o polling"

requirements-completed: [D-06, D-07]

duration: ~40min (inclui checkpoint humano + 2 rodadas de fix)
completed: 2026-06-02
---

# Phase 17 / Plan 05: Página de Coleta (UI) Summary

**Página `/mlb/coleta` (Inteligência de Anúncios) com formulário, polling de status 3s, histórico e relatório heurístico em cards colapsáveis — design ecf-* dark; verificada de ponta a ponta contra a API real do ML.**

## Performance

- **Duration:** ~40 min (inclui o checkpoint visual humano + 2 rodadas de correção)
- **Completed:** 2026-06-02
- **Tasks:** 3 (2 auto + 1 checkpoint human-verify)
- **Files modified:** 2

## Accomplishments
- `Mlb/Coleta.jsx`: formulário (keyword + categoria + condição), barra de progresso com spinner, histórico em tabela com badges de status, relatório (ranking, dúvidas, recomendação, produtos)
- Item de nav 'Int. Anúncios' no AppLayout, gated por `mlb.coleta`
- Polling 3s (deadline 10min, cleanup no unmount); `router.reload({ only: ['coleta'] })` ao concluir
- Tipografia travada do UI-SPEC (11/13/20/24, normal/bold); copy exata; build verde
- **Verificação E2E real:** coleta "Cadeira Escritório" processada contra a API ML → concluido, 30 keywords, questions_disponivel=true, 10 produtos, categoria MLB193945

## Task Commits

1. **Task 1: Nav item AppLayout** - `98519b0` (feat)
2. **Task 2: Página Coleta.jsx** - `a6dfaac` (feat)
3. **Task 3: Checkpoint humano** — aprovado ("funcionou") com 2 ajustes solicitados:
   - `9d6c7d4` fix: "Ver relatório" preserva scroll/state (não reinicia o polling)
   - `(fix)` relatório em cards colapsáveis (não mais "solto")

## Files Created/Modified
- `resources/js/Pages/Mlb/Coleta.jsx` - Página completa (form + polling + histórico + relatório colapsável)
- `resources/js/Layouts/AppLayout.jsx` - Item de nav 'Int. Anúncios' + import Search

## Decisions Made
- **Recomendação heurística mantida (D-05).** O feedback do checkpoint (recomendação genérica, não conhece o produto do usuário) confirma que a versão de qualidade exige IA cruzando specs do produto + concorrentes — registrada como **Fase 2 (IA / framework MAG T8)**, ver `.planning/todos/pending`.
- `categoria_id` como input de texto opcional (não há catálogo de categorias; o `domain_discovery` já resolve a categoria automaticamente).

## Deviations from Plan

### Ajustes pós-checkpoint (feedback humano)
**1. [Checkpoint] "Ver relatório" recarregava e parecia reiniciar a coleta**
- **Fix:** `router.get(..., { preserveScroll: true, preserveState: true })`
- **Causa do loop "Aguardando na fila":** ambiental — não havia `queue:work` consumindo a fila `database` (50 jobs empilhados). Não era bug de código.

**2. [Checkpoint] Dados do relatório saíam "soltos"**
- **Fix:** relatório convertido em cards colapsáveis (componente `Secao`, clique no cabeçalho).

**Total deviations:** 2 ajustes de UX pós-checkpoint. Sem scope creep — recomendação ciente do produto foi corretamente diferida para a Fase 2.

## Issues Encountered
- O loop em "Aguardando na fila…" era falta de queue worker (ambiente dev), não bug. A coleta pendente foi processada manualmente (`dispatchSync`) para validar o E2E sem drenar o backlog de 49 jobs não relacionados (que incluem envios de e-mail e syncs Adman).

## User Setup Required
- **Queue worker:** para processar coletas em dev, rodar `php artisan queue:work` (ou `composer dev`, que sobe server+queue+vite+pail).
- **Permissão:** conceder `mlb.coleta` ao setor Publicação para usuários não-admin.

## Next Phase Readiness
- Fase 1 (sem IA) completa. **Fase 2 = recomendação por IA no framework MAG T8** (entrada de specs do produto + análise Claude cruzando a coleta) — contexto em memória do projeto e todo em `.planning/todos/pending`.

---
*Phase: 17-coleta-de-dados-ml*
*Completed: 2026-06-02*
