---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
plan: 07
subsystem: ui
tags: [nps, inertia, react, tailwind, ux-copy]

requires:
  - phase: 116-03
    provides: "NpsController::index() expõe cards.*.nao_respondidos + regra_nao_respondido"
  - phase: 116-05
    provides: "CompanyController::show() expõe company.nps_avg (média oficial com o piso do não respondido)"
provides:
  - "Nps/Index.jsx explica a regra do não respondido em linguagem simples, sem jargão, e separa respondidas × sem resposta no rodapé dos 3 cards"
  - "Companies/Show.jsx consome company.nps_avg do backend em vez de recalcular client-side — página da empresa não diverge mais das outras telas da fase"
  - "CompanyController::show() expõe nps_respondidos/nps_nao_respondidos (composição de nps_avg)"
affects: [companies-show, nps-index, dashboard-nps]

tech-stack:
  added: []
  patterns:
    - "Rótulo sem jargão aprovado pelo usuário: 'X respondida(s) · Y sem resposta (contam 1)' + linha 'NPS enviado e não respondido conta nota 1 na média.' — mesmo texto reusado em /nps e /companies/{id}"
    - "Prop naoRespondidos com default 0 em StatCard; segmento do rodapé só renderiza quando > 0 (nunca mostra '0 sem resposta')"

key-files:
  created: []
  modified:
    - resources/js/Pages/Nps/Index.jsx
    - app/Http/Controllers/CompanyController.php
    - resources/js/Pages/Companies/Show.jsx

key-decisions:
  - "Linha explicativa da regra ('NPS enviado e não respondido conta nota 1 na média.') posicionada uma única vez, logo abaixo de todo o bloco de stat cards (tanto no layout de 1 card filtrado por pessoa quanto no grid de 3 cards) — não duplicada por card, para não poluir a tela"
  - "Tarefa adicional AUTORIZADA PELO ORQUESTRADOR (fora do escopo original do 116-07): Companies/Show.jsx trocou o cálculo client-side de avgNps (só nps_surveys, respostas reais) pelo campo company.nps_avg do backend (Plan 116-05). Motivo: o Plan 116-05 passou a expor nps_avg com o piso do não respondido, mas nenhum plano da fase tocava o frontend da página da empresa — a tela continuava mostrando um número diferente de TODAS as outras telas da Fase 116 (exatamente o problema de telas discordando entre si que a fase existe para evitar)"
  - "CompanyController::show() ganhou 2 campos novos (nps_respondidos/nps_nao_respondidos) para a página da empresa poder explicar a composição de nps_avg no mesmo espírito da área NPS, sem duplicar a lógica de contagem — ambos são apenas ->count() das mesmas coleções já usadas para calcular nps_avg"
  - "nps_avg tratado como possivelmente null (empresa sem nenhum NPS disparado) — action do <Section> só aparece quando avgNps !== null, preservando o comportamento anterior para essa empresa"

requirements-completed: [NPSFLOOR-09]

duration: ~35min
completed: 2026-07-28
---

# Fase 116 Plano 07: NPS explica que não respondido conta nota mínima (1) Summary

**`Nps/Index.jsx` separa "respondida(s)" de "sem resposta (contam 1)" no rodapé dos 3 cards de média e explica a regra em uma linha sem jargão; `Companies/Show.jsx` passou a consumir a mesma média oficial (`company.nps_avg`) do backend em vez de recalcular só com respostas reais, eliminando a divergência entre a página da empresa e o resto da fase.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-07-28
- **Tasks:** 2 (Task 1 auto + Task 2 checkpoint humano) + 1 tarefa adicional autorizada
- **Files modified:** 3 (1 componente da área NPS, 1 controller, 1 componente da página da empresa)

## Accomplishments

- `StatCard` (área NPS) ganhou a prop `naoRespondidos` (default 0): o rodapé agora mostra `"{respondidas} respondida(s)"` calculado como `total - naoRespondidos` (antes mostrava o `total` cheio, que desde o Plan 116-03 já soma respostas reais + notas de não respondido — uma mentira sutil introduzida pela regra), e `"{naoRespondidos} sem resposta (contam 1)"` só quando `naoRespondidos > 0`. O contador de pendentes existente foi preservado sem duplicar informação.
- As 6 instâncias de `<StatCard>` (layout de 1 card filtrado por pessoa + grid de 3 cards) passam `naoRespondidos={cards.<dimensao>?.nao_respondidos ?? 0}`.
- Linha explicativa nova, logo abaixo do bloco de stat cards, renderizada quando `regra_nao_respondido` é `true`: `"NPS enviado e não respondido conta nota 1 na média."` — ícone `Info` de 13px, cor `rgba(255,255,255,0.45)`, sem destaque chamativo.
- **Tarefa adicional (desvio autorizado pelo orquestrador, fora do escopo original do 116-07):** `CompanyController::show()` ganhou os campos `nps_respondidos` e `nps_nao_respondidos` (contagens que já compunham o cálculo de `nps_avg` desde o Plan 116-05, agora expostas). `Companies/Show.jsx` trocou o `avgNps` recalculado no navegador (só `nps_surveys`, respostas reais) pelo campo `company.nps_avg` vindo pronto do backend, e passou a mostrar, na seção NPS, a mesma explicação sem jargão da área NPS quando há pelo menos 1 nota de não respondido.
- Zero termos técnicos (assignment, imputação, penalização, provisório, definitivo) em qualquer um dos 2 arquivos `.jsx` tocados — confirmado por grep.
- **Checkpoint visual aprovado pelo usuário sem nenhum ajuste de texto ou layout** — resposta literal: "aprovado — o texto da interface está aprovado exatamente como você escreveu, sem ajustes. Isso vale tanto para o rodapé dos 3 cards e a linha explicativa em /nps quanto para a seção NPS da página da empresa."

## Task Commits

1. **Tarefa 1: rodapé dos cards separa respondidas × sem resposta e linha explicativa da regra** — `7060911c` (feat)
2. **Tarefa 2 (checkpoint:human-verify, gate=blocking): conferência visual da tela de NPS** — aprovada pelo usuário sem ajustes (nenhum commit adicional necessário; nenhum código mudou após a Tarefa 1)
3. **Tarefa adicional (desvio autorizado pelo orquestrador): página da empresa usa a mesma média oficial de NPS da fase** — `b6774c38` (fix)

**Plan metadata:** (próximo commit) `docs: complete plan`

## Files Created/Modified

- `resources/js/Pages/Nps/Index.jsx` — `StatCard` com prop `naoRespondidos`; rodapé recalcula `respondidas = total - naoRespondidos`; segmento "sem resposta (contam 1)" condicional; linha explicativa da regra abaixo do bloco de stat cards; import de `Info` do `lucide-react`.
- `app/Http/Controllers/CompanyController.php` — `show()` expõe `nps_respondidos` e `nps_nao_respondidos` (contagens já usadas internamente para `nps_avg`, agora no payload).
- `resources/js/Pages/Companies/Show.jsx` — `avgNps` deixa de ser calculado client-side a partir de `nps_surveys` e passa a ler `company.nps_avg`; bloco novo na seção NPS explicando a composição da média (respondidas × sem resposta) quando aplicável.

## Texto exato aprovado na tela (rastreabilidade)

**Área NPS (`/nps`), rodapé de cada `StatCard`:**
- `"{X} respondida(s)"` (ex.: "8 respondidas")
- `"{Y} sem resposta (contam 1)"` — só quando Y > 0
- `"{Z} pendente(s)"` (inalterado)

**Área NPS (`/nps`), linha abaixo do bloco de cards, quando `regra_nao_respondido === true`:**
> "NPS enviado e não respondido conta nota 1 na média."

**Página da empresa (`/companies/{id}`), seção NPS, quando há ≥1 nota de não respondido:**
> "{X} respondida(s) · {Y} sem resposta (contam 1) — NPS enviado e não respondido conta nota 1 na média."

## Decisions Made

- Ver `key-decisions` no frontmatter — resumo: linha explicativa única (não duplicada por card); tarefa adicional do `Companies/Show.jsx` tratada como desvio autorizado e commitada separadamente das tarefas originais do plano; 2 campos novos no `CompanyController` para dar à página da empresa a mesma transparência de composição da média que a área NPS já tinha.

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio de correção/bug nas tarefas originais do plano — Task 1 executada como escrita, greps de aceitação batem exatamente com o especificado (`naoRespondidos` = 10 ocorrências ≥ 8 exigido; string exata da regra presente 1x; zero jargão).

### Desvio Autorizado pelo Orquestrador (fora das Regras 1-4 padrão)

**1. [Autorizado explicitamente] `Companies/Show.jsx` trocado para consumir `company.nps_avg` do backend**
- **Found during:** Instrução explícita do orquestrador, anexada ao prompt de execução deste plano (não descoberta durante a execução das tarefas do 116-07).
- **Issue:** O Plan 116-05 (já executado) fez `CompanyController::show()` passar a expor `company.nps_avg` (média oficial, com o piso do não respondido), mas `Companies/Show.jsx` continuava calculando `avgNps` 100% client-side a partir de `nps_surveys` (só respostas reais) — documentado como "limitação conhecida" no próprio SUMMARY do 116-05. Resultado: a página da empresa mostrava um número de NPS diferente de todas as outras telas da fase (área NPS, dashboards, ranking, meta) — exatamente o tipo de discordância entre telas que a Fase 116 existe para eliminar. Nenhum outro plano da fase (116-06/07/08) tinha esse arquivo em `files_modified`.
- **Fix:** `CompanyController::show()` ganhou `nps_respondidos`/`nps_nao_respondidos` (contagens que já alimentavam `nps_avg` internamente); `Companies/Show.jsx` passou a ler `company.nps_avg` em vez de recalcular, tratando `null` (empresa sem nenhum NPS disparado) sem quebrar a tela, e mostrando a mesma frase explicativa sem jargão da área NPS quando aplicável.
- **Files modified:** `app/Http/Controllers/CompanyController.php`, `resources/js/Pages/Companies/Show.jsx`.
- **Verification:** `npm run build` (2ª rodada) `✓ built in 29.59s`; `php artisan test --filter=NpsFloorDashboardsTest` 6/6 verde (inclui o teste `pagina da empresa nao suja lista de respondidos`, que cobre exatamente este call-site); `php artisan test --filter=CompanyPortfolioAccessTest` 4/4 verde; grep de jargão = 0 ocorrências.
- **Committed in:** `b6774c38` (commit separado das tarefas originais do plano, com mensagem explicando a motivação de coerência).

**Limitação herdada do Plan 116-05 (não corrigida nesta plano, documentada):** `nps_respondidos` (parte real de `nps_avg` na página da empresa) reflete apenas os 10 surveys `completed` mais recentes — a mesma janela do eager-load `nps_surveys` que a lista "NPS Respondidos" já usava antes desta fase. `nps_nao_respondidos` (parte imputada), por outro lado, não tem corte de período (mesmo racional do Plan 116-04/116-05 para `notasDaEmpresa()` sem filtro de data). Isso significa que, em empresas com histórico de NPS muito longo (mais de 10 respostas reais), a composição exibida (`X respondida(s) · Y sem resposta`) pode não somar exatamente o número total de surveys da empresa — é a mesma limitação já aceita e documentada no `nps_avg` desde o 116-05, apenas agora VISÍVEL na tela em vez de invisível no cálculo. Não é uma regressão desta plano.

---

**Total deviations:** 1 desvio autorizado explicitamente pelo orquestrador (tarefa adicional de coerência entre telas), 0 desvios das Regras 1-4 dentro das tarefas originais do plano.
**Impact on plan:** Escopo da fase reforçado (menos uma tela discordando) sem alterar nenhuma regra de negócio já testada nos Plans 116-01 a 116-06.

## Issues Encountered

Nenhum. Build e testes verdes em ambas as rodadas (após Task 1 e após a tarefa adicional).

## Contagens de teste rodadas

| Comando | Resultado |
|---|---|
| `npm run build` (após Task 1) | `✓ built in 39.98s`, exit 0 |
| `npm run build` (após tarefa adicional) | `✓ built in 29.59s`, exit 0 |
| `php artisan test --filter=NpsFloorAreaNpsTest` | 9/9 passed, 120 assertions |
| `php artisan test --filter=NpsFloorDashboardsTest` | 6/6 passed, 56 assertions |
| `php artisan test --filter=CompanyPortfolioAccessTest` | 4/4 passed, 41 assertions |
| `grep -c "naoRespondidos" Nps/Index.jsx` | 10 (≥ 8 exigido pelo plano) |
| `grep -c` string exata da regra em Nps/Index.jsx | 1 |
| `grep -ci` jargão (assignment/imputa/penaliza/provisorio/definitivo) em Nps/Index.jsx | 0 |
| `grep -ci` jargão em Companies/Show.jsx | 0 |

## User Setup Required

**`public/build/` está no `.gitignore` do projeto** (`/public/build` — confirmado via `git check-ignore -v`). Os artefatos gerados pelo `npm run build` desta sessão NÃO entram em nenhum commit (comportamento já existente do repositório, não uma omissão desta execução). **O deploy precisa rodar `npm run build` no servidor** (ou no pipeline de deploy) para que as mudanças de `Nps/Index.jsx` e `Companies/Show.jsx` cheguem ao bundle servido em produção — sem isso, o código-fonte muda no git mas a tela em produção continua com o JS antigo.

## Next Phase Readiness

- Fase 116 (NPS não respondido conta como nota mínima 1) tem agora `116-01` a `116-07` completos. Falta `116-08` (não lido neste plano, mas mencionado como pendente no STATE.md antes desta execução).
- `Companies/Show.jsx` e `Nps/Index.jsx` já refletem a regra de piso do não respondido de forma coerente entre si e com os demais consumidores da fase (dashboards, ranking, meta) — nenhum call-site de UI conhecido continua mostrando o número "antigo" (sem o piso).
- Nenhum bloqueador introduzido por este plano.

---
*Phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1*
*Completed: 2026-07-28*

## Self-Check: PASSED

Os 3 arquivos modificados (`resources/js/Pages/Nps/Index.jsx`, `app/Http/Controllers/CompanyController.php`, `resources/js/Pages/Companies/Show.jsx`) confirmados no disco; os 2 hashes de commit (`7060911c`, `b6774c38`) confirmados via `git log --oneline`.
