---
phase: 104
plan: 104-02
subsystem: frontend · toggle de período (ranking + carteira)
tags: [performance, portfolio, ui, v18.0]
dependency-graph:
  requires: [104-01 (backend — payload periodo/bonus + ?modo=)]
  provides: [UIP-01, UIP-03, UIP-04 — toggle visual de período nas 3 telas]
  affects: []
tech-stack:
  added: []
  patterns:
    - "Segmento ativo do toggle lido de `new URLSearchParams(window.location.search).get('modo')` a cada render — nunca useState local (evita divergência entre o que o usuário clicou e o que o Inertia efetivamente carregou)"
    - "3 botões-segmento (Em curso/Bônus atual/Mês fechado) repetidos nos 3 arquivos, mesma convenção de CONTEXTO_OPTIONS já duplicada no projeto (sem módulo compartilhado pra só 3 usos)"
key-files:
  created: []
  modified:
    - resources/js/Pages/Performance/Index.jsx
    - resources/js/Pages/Portfolio/AdminCarteira.jsx
    - resources/js/Pages/Portfolio/Carteiras.jsx
decisions:
  - "Carteiras.jsx (consolidada) não tem `meses_disponiveis` no payload (escopo mínimo da Fase 103) — 'Mês fechado' usa um `<input type=\"month\">` nativo em vez do dropdown das outras 2 telas; o backend já valida `?mes=YYYY-MM` via regex, sem precisar de nova prop"
  - "Preservação de `?modo=` ao trocar cargo/contexto/mês foi tratada explicitamente (não só `?contexto=`) — sem isso, trocar de cargo/contexto enquanto em 'Bônus atual' perderia o modo e cairia silenciosamente em 'mês fechado' comum"
metrics:
  duration: "~40min"
  completed: 2026-07-21
---

# Phase 104 Plan 02: Frontend — toggle de período + indicador operacional/oficial Summary

Ranking `/performance`, carteira individual (`Portfolio/AdminCarteira`) e carteira consolidada (`Portfolio/Carteiras`) ganham um segmento de 3 botões — "Em curso" / "Bônus atual" / "Mês fechado" — que mapeia pro `?modo=`/`?mes=` do payload entregue pela 104-01, com rótulos 100% pt-BR sem jargão e indicador explícito de competência/pagamento no modo bônus.

## O que foi feito

### Task 1 — Toggle nas 3 telas (commit `ce4c002`)

- **`Performance/Index.jsx`**: `PeriodoToggle` adicionado ao lado do dropdown de mês já existente (`meses_disponiveis`). "Em curso" limpa `?mes`/`?modo` (volta ao mês corrente); "Bônus atual" envia `?modo=bonus_atual`; "Mês fechado" cai no mês fechado mais recente da lista já carregada quando clicado a partir de outro segmento. Badge do header trocou de "Mês em curso" para o texto travado "parcial · mês em andamento". Linha `Competência {mês}/{ano} · pago em {mês}` aparece só quando `segmentoAtivo === 'bonus_atual'` e `bonus` não é null. Subtítulo discreto de auditoria (`01/06–30/06 vs 02/05–31/05`) lido de `periodo.current_start/current_end/baseline_start/baseline_end`. Filtro de cargo (Geral/Analistas/Estrategistas) passou a preservar `?modo=bonus_atual` quando ativo (antes perderia o modo ao trocar de cargo).

- **`Portfolio/AdminCarteira.jsx`**: mesmo `PeriodoToggle` adicionado ao lado do seletor `?contexto=` já existente (Fase 90). Um `navigate()` unificado substitui as 2 construções manuais de querystring que existiam (contexto/mês) — agora os 3 controles (toggle, contexto, mês) preservam uns aos outros via `URLSearchParams`, com `preserveScroll: true`. Banner amarelo de competência/pagamento aparece logo abaixo do header no modo Bônus atual. O banner de contexto de período pré-existente (mês em curso vs fechado, com a explicação completa de janela dia-a-dia) foi preservado sem alteração — já cobria o requisito de indicador operacional/oficial (UIP-04).

- **`Portfolio/Carteiras.jsx`** (consolidada): removido o seletor rolante legado `PERIOD_OPTIONS` (1/7/30/180) — o backend já ignorava essa janela desde a Fase 103 (known-gap 103↔104 fechado). No lugar: `PeriodoToggle` + (só quando `segmentoAtivo === 'mes_fechado'`) um `<input type="month">` nativo, já que esta tela não recebe `meses_disponiveis` no payload (decisão de escopo mínimo da 103-02). Banner de competência/pagamento no modo Bônus atual e banner "Parcial · mês em andamento" no modo Em curso, mesma linguagem das outras 2 telas. Subtítulo discreto de auditoria também adicionado ao header.

## Verificação

```
npm run build
```
Resultado: **exit 0** — build Vite completo em ~34s, sem erros/warnings novos. `git status --short` confirmado limpo de arquivos alheios antes e depois do commit (só os 3 `.jsx` da fronteira).

## Deviations from Plan

Nenhuma que exija Rule 4. Duas decisões dentro de Rule 2/3 (auto-fix de gap operacional, documentadas acima):

1. **[Rule 3 — blocking] `Carteiras.jsx` sem `meses_disponiveis`** — o plano dizia "usa o dropdown de mês existente", mas essa tela nunca teve um dropdown de mês (só o rolante 1/7/30/180 que está sendo removido). Resolvido com `<input type="month">` nativo, que exercita o mesmo `?mes=YYYY-MM` já validado pelo backend (104-01), sem precisar tocar o controller.
2. **[Rule 2 — funcionalidade crítica] Preservação de `?modo=` em filtros adjacentes** — não estava explícito no plano, mas sem isso trocar de cargo (Performance) ou contexto/mês (AdminCarteira/Carteiras) enquanto em "Bônus atual" silenciosamente voltaria pro modo "mês fechado" comum (mesmos números, rótulo errado) — corrigido nos 3 arquivos.

## Fronteira respeitada

Só os 3 `.jsx` da lista (`Performance/Index.jsx`, `Portfolio/AdminCarteira.jsx`, `Portfolio/Carteiras.jsx`). Nenhum arquivo de backend tocado (`104-01` fechado). Nenhum arquivo `Dashboard*`/`Nps*`/`shopee*` da sessão paralela staged ou commitado — confirmado via `git status --short` antes e depois do commit único desta plan.

## Known Stubs

Nenhum. Os 3 componentes consomem `periodo`/`bonus` diretamente do payload já entregue pela 104-01 — sem dado mockado/hardcoded.

## Threat Flags

Nenhum — mudança é puramente de apresentação (leitura de props já existentes + querystring), sem novo endpoint, sem novo acesso a dado sensível.

## Self-Check: PASSED

- `resources/js/Pages/Performance/Index.jsx` — FOUND, contém `PeriodoToggle`/`segmentoAtivo`/`formatCompetencia`.
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` — FOUND, contém `PeriodoToggle`/`navigate`/banner de competência.
- `resources/js/Pages/Portfolio/Carteiras.jsx` — FOUND, `PERIOD_OPTIONS` removido, `PeriodoToggle` presente.
- Commit `ce4c002` — FOUND em `git log --oneline -1`.
- `git diff --diff-filter=D HEAD~1 HEAD` vazio — nenhuma deleção acidental no commit.

## Próximo passo (Task 2 — checkpoint:human-verify)

**NÃO executado por este agente** — é um checkpoint visual (`autonomous: false` no frontmatter do plano). Requer deploy prévio (MySQL local está quebrado, conforme nota do plano) e validação manual do usuário nas 3 telas:

1. `/performance` — segmento Em curso/Bônus atual/Mês fechado; "Bônus atual" mostra "Competência {mês}/{ano} · pago em {mês}" e o ranking muda pros números do mês fechado; "Em curso" marca parcial.
2. Carteira individual (`/portfolio`) — toggle ao lado do `?contexto=`; trocar preserva o contexto; modo fechado mostra competência.
3. Carteira consolidada (`/portfolio` aba consolidada) — toggle no lugar do rolante antigo; números coerentes com a janela.
4. Conferir que nenhum slug cru aparece (`em_curso`/`official`/etc.) na tela.

Resume-signal do plano: usuário digita "aprovado" ou descreve ajustes.
