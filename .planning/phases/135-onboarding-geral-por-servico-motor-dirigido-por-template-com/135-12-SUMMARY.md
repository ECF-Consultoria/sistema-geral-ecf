---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 12
subsystem: ui
tags: [inertia, react, radix-select, painel-operacional, aguardando-coleta, selo-automacao]

# Dependency graph
requires:
  - phase: 135-09
    provides: "OnboardingController::index()/show() — payload agregado por empresa (situacao/passo_que_trava/contadores) e detalhe dos 13 passos (dias_parado/vencido/condicao legível/tem_auto_fonte), rotas onboarding.painel.*"
provides:
  - "resources/js/Pages/Onboarding/Painel.jsx — Tela 1: lista agrupada por empresa, chip único de situação (6 valores, SC-11), contadores StatChip clicáveis, sem nenhuma porcentagem"
  - "resources/js/Pages/Onboarding/Detalhe.jsx — Tela 1 Nível 2: página real que o show() do Plano 09 já renderiza (antes inalcançável — quebraria em runtime)"
  - "resources/js/Components/Onboarding/Painel/{SituacaoChip,EmpresaCard,DonoBadge,DetalheOnboarding}.jsx — componentes reaproveitáveis do painel"
  - "Item de navegação 'Onboarding' no grupo Comercial, gate permission:core.onboarding dedicada"
  - "OnboardingController::index() ganha prop 'usuarios' (fallback do Select de responsável) e detalhePasso() ganha 'id' (necessário pro botão Marcar como concluído)"
affects: ["135-13"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guarda D-11 em duas redes independentes: EstadoPasso checa aguardando_coleta ANTES de qualquer if que leia passo.valor (ordem textual no arquivo), e o subcomponente Coletando nem recebe passo.valor como prop — mesmo um erro de composição futuro não teria como vazar número"
    - "DonoBadge como componente compartilhado entre EmpresaCard (passo que trava) e DetalheOnboarding (13 passos) — evita duas cópias divergentes do mapeamento dono→tom"
    - "Onboarding/Detalhe.jsx é página fina: só monta cabeçalho (empresa/serviço/situação/responsável) + link de volta; o conteúdo dos 13 passos vive inteiramente em DetalheOnboarding.jsx (Components/), reaproveitável fora do contexto de página se precisar"

key-files:
  created:
    - resources/js/Pages/Onboarding/Painel.jsx
    - resources/js/Pages/Onboarding/Detalhe.jsx
    - resources/js/Components/Onboarding/Painel/SituacaoChip.jsx
    - resources/js/Components/Onboarding/Painel/EmpresaCard.jsx
    - resources/js/Components/Onboarding/Painel/DonoBadge.jsx
    - resources/js/Components/Onboarding/Painel/DetalheOnboarding.jsx
  modified:
    - resources/js/Layouts/AppLayout.jsx
    - app/Http/Controllers/OnboardingController.php
    - tests/Feature/Phase135/OnboardingPainelPropsTest.php
    - tests/Feature/Phase135/OnboardingPainelAcoesTest.php

key-decisions:
  - "Onboarding/Detalhe.jsx foi criado como página real, não como drawer client-side, mesmo o plano listando DetalheOnboarding.jsx só como Components/. O show() do Plano 09 já faz Inertia::render('Onboarding/Detalhe', ...) — o resolver do Inertia (resources/js/app.jsx) só encontra páginas em ./Pages/**/*.jsx, então sem esse arquivo a rota onboarding.painel.show quebraria em runtime (T-135-12-04). O precedente dos testes (component('Onboarding/Detalhe', false) no 135-09-SUMMARY, com nota explícita 'quando o .jsx existir, os testes podem voltar a true') confirma que este arquivo era esperado nesta wave."
  - "'Drill-down' do Nível 1 é navegação Inertia normal (<Link href={route('onboarding.painel.show', id)}>), não um drawer com fetch manual do protocolo Inertia (X-Inertia header). A segunda opção não tem nenhum precedente no código-base e adiciona risco de versão/CSRF sem necessidade — o UI-SPEC explicita que 'drawer lateral ou página própria... este contrato não exige uma sobre a outra'."
  - "OnboardingController::index() ganha a prop 'usuarios' (lista id/name para o Select de fallback do CTA Confirmar responsável). Sem sugestão automática (sugerirResponsavel() vazio, D-17), o operador precisa de alguém pra escolher — o payload do Plano 09 não trazia essa lista porque a Tela 1 não existia ainda."
  - "detalhePasso() ganha o campo 'id' — sem ele o botão Marcar como concluído não tem como montar route('onboarding.passos.concluir', passo.id). Ambas as adições são aditivas (nenhum teste do Plano 09 assume lista fechada de chaves; os testes de passo usam ->etc())."

patterns-established:
  - "Página real (nunca re-export) confirmada no manifest do Vite após build — mesma disciplina de .planning/learnings/painel-polos-status-e-meta.md §4, agora com 4 páginas da fase confirmadas (Painel, Detalhe, Templates/Index, Publico)."

requirements-completed: [SC-04, SC-11, D-01, D-05, D-11, D-14, D-15, D-17, D-19]

# Metrics
duration: ~45min
completed: 2026-08-12
---

# Fase 135 Plano 12: Painel operacional (frontend) — lista por empresa, detalhe dos 13 passos e navegação Summary

**Tela 1 completa: lista agrupada por empresa com chip único de situação (6 valores, sem porcentagem), passo que mais trava com dono/dias/SLA, CTA "Confirmar responsável" pré-preenchido, detalhe dos 13 passos com selo de automação independente do dono e guarda dupla contra vazar número durante "aguardando coleta", e item de menu com permission própria.**

## Performance

- **Duration:** ~45 min
- **Completed:** 2026-08-12T18:34:51Z
- **Tasks:** 3/3
- **Files modified:** 10 (6 criados, 4 modificados)

## Accomplishments

- `Onboarding/Painel.jsx` — lista agrupada por empresa (D-01), 5 `StatChip` clicáveis que filtram a grade, empty state com a copy literal exata do Copywriting Contract. Nenhuma porcentagem, nenhum `Progress` como elemento central (SC-11) — confirmado por grep nos dois arquivos do Nível 1.
- `SituacaoChip.jsx` — os 6 valores do vocabulário próprio (`rascunho`/`vencido`/`aguardando_{dono}`/`coletando`/`pronto_para_concluir`/`concluido`), tom vindo só de mapeamento de apresentação — nenhum cálculo de situação no frontend, o backend já classifica.
- `EmpresaCard.jsx` — passo que trava (título + `DonoBadge` + "há X dias" + "{sla}d") e o CTA "Confirmar responsável": um clique quando há `responsavel_sugerido` (D-17), ou `Select` de usuários com sentinela `SEM_VALOR` quando não há sugestão.
- `DetalheOnboarding.jsx` — os 13 passos com os 7 estados da paleta semântica. Selo `Zap` de automação **separado** da badge de dono (D-19), com `aria-label`/`title` obrigatórios. Guarda D-11 em duas redes: checagem explícita de `aguardando_coleta` antes de qualquer leitura de `passo.valor`, e o subcomponente `Coletando` nem recebe essa prop. Watchdog `coleta_demorando` e a copy literal do indeterminado, nunca a palavra "Erro" em texto visível.
- `Onboarding/Detalhe.jsx` — página real que o `show()` do Plano 09 já renderiza; sem ela a rota quebraria em runtime (deviation documentada abaixo).
- Item "Onboarding" no grupo Comercial, `permission: 'core.onboarding'`, `page` como array (`Onboarding/Painel`/`Onboarding/Detalhe`) — não reutiliza a permission do item de Onboarding de Polos (D-02), diff confirmado como só-adição.
- `npm run build` verde; manifest do Vite confirma as 4 páginas da fase (as 3 exigidas pelo plano + `Onboarding/Detalhe`).
- Suíte `tests/Feature/Phase135` permaneceu 162/162 verde do início ao fim.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Painel.jsx nível 1 — lista agrupada por empresa e chip de situação** - `dd6c258a` (feat)
2. **Task 2: Detalhe do onboarding — 13 passos, selo de automação e o estado "aguardando coleta"** - `8d687fa8` (feat)
3. **Task 3: Item de navegação + build** - `b20bb54b` (feat)

## Files Created/Modified

- `resources/js/Pages/Onboarding/Painel.jsx` — página real (Nível 1), contadores + grade de `EmpresaCard`, empty state
- `resources/js/Pages/Onboarding/Detalhe.jsx` — página real (Nível 2), cabeçalho + `DetalheOnboarding`, link de volta ao painel
- `resources/js/Components/Onboarding/Painel/SituacaoChip.jsx` — chip único de situação, 6 tons
- `resources/js/Components/Onboarding/Painel/EmpresaCard.jsx` — card de empresa, passo que trava, CTA de responsável
- `resources/js/Components/Onboarding/Painel/DonoBadge.jsx` — badge de dono compartilhada (cliente/interno+setor/sistema)
- `resources/js/Components/Onboarding/Painel/DetalheOnboarding.jsx` — 13 passos, 7 estados, selo de automação, guarda D-11
- `resources/js/Layouts/AppLayout.jsx` — item "Onboarding" no grupo Comercial (só adição)
- `app/Http/Controllers/OnboardingController.php` — `index()` ganha prop `usuarios`; `detalhePasso()` ganha `id`
- `tests/Feature/Phase135/OnboardingPainelPropsTest.php` — `component('Onboarding/Painel'|'Onboarding/Detalhe', false)` → checagem de existência reativada (arquivo agora existe)
- `tests/Feature/Phase135/OnboardingPainelAcoesTest.php` — mesma reativação, 1 ocorrência

## Decisions Made

Ver `key-decisions` no frontmatter — resumo: `Onboarding/Detalhe.jsx` nasceu como página real (não drawer) porque o `show()` do Plano 09 já a espera; drill-down é `<Link>` Inertia normal, não fetch manual do protocolo; `usuarios` e `id` foram adicionados ao payload do controller como pré-requisitos estruturais que o Plano 09 não tinha como prever (a tela ainda não existia).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] `Onboarding/Detalhe.jsx` não estava no `files_modified` do plano, mas o `show()` já renderiza esse componente**
- **Found during:** Task 2 (leitura do `OnboardingController::show()` antes de escrever `DetalheOnboarding.jsx`)
- **Issue:** O plano lista só `resources/js/Components/Onboarding/Painel/DetalheOnboarding.jsx` (um componente) nas `<files>` da Task 2, mas `OnboardingController::show()` (Plano 09, já committado) faz `Inertia::render('Onboarding/Detalhe', ...)`. O resolver do Inertia (`resources/js/app.jsx`) só localiza páginas em `./Pages/**/*.jsx` — sem esse arquivo, visitar `/onboarding/{id}` quebraria com "Unable to locate file in Vite manifest" (a mesma armadilha documentada em `.planning/learnings/painel-polos-status-e-meta.md` §4 e no `threat_model` desta fase, T-135-12-04). Os testes do Plano 09 (`component('Onboarding/Detalhe', false)`) já sinalizavam essa expectativa via comentário no 135-09-SUMMARY.md.
- **Fix:** Criado `resources/js/Pages/Onboarding/Detalhe.jsx` como página fina (cabeçalho + `DetalheOnboarding` + link de volta), reaproveitando o componente que a Task 2 já construía.
- **Files modified:** `resources/js/Pages/Onboarding/Detalhe.jsx` (criado)
- **Verification:** `npm run build` verde; `grep -c "Onboarding/Detalhe" public/build/manifest.json` → 3; suíte completa 162/162.
- **Committed in:** `8d687fa8`

**2. [Rule 2 — Funcionalidade crítica ausente] CTA "Confirmar responsável" sem sugestão não tinha de onde ler a lista de usuários**
- **Found during:** Task 1 (escrevendo o fallback do `Select` descrito na `<action>` da Task 1: "Onboarding em rascunho sem sugestão mostra um Select de usuários")
- **Issue:** `OnboardingController::index()` (Plano 09) só devolvia `empresas` — sem uma lista de usuários no payload, o `Select` de fallback não tinha opções pra oferecer quando `sugerirResponsavel()` não encontra ninguém (empresa sem vínculo em nenhum papel, D-17).
- **Fix:** Adicionada a prop `usuarios` (`User::query()->orderBy('name')->get(['id','name'])`) ao `index()`. Mudança aditiva — nenhum teste do Plano 09 assume lista fechada de chaves no payload.
- **Files modified:** `app/Http/Controllers/OnboardingController.php`
- **Verification:** Suíte `Phase135` permaneceu 162/162 após a mudança.
- **Committed in:** `dd6c258a`

**3. [Rule 3 — Blocking issue] Botão "Marcar como concluído" não tinha `id` do passo para montar a rota**
- **Found during:** Task 2 (escrevendo `LinhaPasso` em `DetalheOnboarding.jsx`)
- **Issue:** `detalhePasso()` (Plano 09) não incluía `id` no shape de cada passo — sem ele, `route('onboarding.passos.concluir', passo.id)` (ação já descrita na `<action>` da Task 2) não tinha como ser montada.
- **Fix:** Adicionado `'id' => $passo->id` a `detalhePasso()`. Os testes que verificam o shape de passo usam `->etc()` no fechamento (permitem chaves extras) — nenhuma assertiva quebrou.
- **Files modified:** `app/Http/Controllers/OnboardingController.php`
- **Verification:** Suíte `Phase135` permaneceu 162/162 após a mudança.
- **Committed in:** `dd6c258a`

**4. [Escopo adicional — mesmo precedente do Plano 10] Reativação da checagem de existência do `.jsx` nos testes**
- **Found during:** Task 3, após `Onboarding/Painel.jsx` e `Onboarding/Detalhe.jsx` existirem
- **Issue:** `OnboardingPainelPropsTest.php`/`OnboardingPainelAcoesTest.php` (Plano 09) usavam `component('Onboarding/Painel'|'Onboarding/Detalhe', false)` — o `false` desliga a checagem de existência do arquivo em disco, necessário só enquanto o `.jsx` não existia.
- **Fix:** Removido o `, false` das 10 ocorrências (9 em `OnboardingPainelPropsTest.php`, 1 em `OnboardingPainelAcoesTest.php`) — mesma disciplina que o `135-10-SUMMARY.md` já registrou para `OnboardingTemplateVersionamentoTest.php`.
- **Files modified:** `tests/Feature/Phase135/OnboardingPainelPropsTest.php`, `tests/Feature/Phase135/OnboardingPainelAcoesTest.php`
- **Verification:** Suíte completa 162/162 com a checagem de existência ativa.
- **Committed in:** `b20bb54b`

---

**Total deviations:** 4 auto-fixed (2 Rule 3 — blocking, 1 Rule 2 — funcionalidade crítica, 1 escopo adicional de teste)
**Impact on plan:** Todas as 4 são pré-requisitos estruturais para o que o próprio plano já pedia (o `show()` do Plano 09 e as `<action>` das Tasks 1/2 descreviam funcionalidade que dependia desses gaps sendo fechados). Nenhuma mudança de contrato visual, nenhum escopo novo além do que a Fase 135 já define.

## Issues Encountered

- Dois falsos-positivos nos próprios greps de aceite (auto-detectados antes do commit, não deviations de produto): um comentário citando literalmente "Progress" em `Painel.jsx` (reescrito para não usar a palavra) e outro citando "erro" em `DetalheOnboarding.jsx` (reescrito para "falha"). Ambos eram só texto de comentário explicando o motivo da ausência do padrão proibido — a correção foi de wording, não de comportamento.
- Um comentário inicial em `AppLayout.jsx` citava literalmente a permission `mlb.implementacao` do item de Polos, o que inflava o grep de contagem usado pelo critério de aceite ("continua igual ao valor anterior"). Reescrito para descrever o item por localização em vez de citar a key.

## User Setup Required

None — nenhuma configuração externa necessária.

## Next Phase Readiness

- As 3 telas da Fase 135 (Painel, Templates/Index, Publico) mais o Detalhe estão no manifest do Vite e a suíte `Phase135` está 162/162 — o Plano 13 (gate de regressão final) pode confirmar render no navegador sem bloqueio de arquivo ausente.
- O painel operacional cobre SC-04/SC-11/D-01/D-05/D-11/D-14/D-15/D-17/D-19 no frontend; o Plano 13 ainda precisa da verificação humana das 4 páginas no navegador e da comparação de baseline do Polos (D-02), que este plano só confirmou por `git status --porcelain | grep -i polos` vazio em cada commit.
- `.planning/STATE.md` não foi tocado por esta execução — fica para o orquestrador ao fim da fase.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: `resources/js/Pages/Onboarding/Painel.jsx`
- FOUND: `resources/js/Pages/Onboarding/Detalhe.jsx`
- FOUND: `resources/js/Components/Onboarding/Painel/SituacaoChip.jsx`
- FOUND: `resources/js/Components/Onboarding/Painel/EmpresaCard.jsx`
- FOUND: `resources/js/Components/Onboarding/Painel/DonoBadge.jsx`
- FOUND: `resources/js/Components/Onboarding/Painel/DetalheOnboarding.jsx`
- FOUND: `resources/js/Layouts/AppLayout.jsx`
- FOUND: `app/Http/Controllers/OnboardingController.php`
- FOUND: `tests/Feature/Phase135/OnboardingPainelPropsTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingPainelAcoesTest.php`
- FOUND: commit `dd6c258a`
- FOUND: commit `8d687fa8`
- FOUND: commit `b20bb54b`
