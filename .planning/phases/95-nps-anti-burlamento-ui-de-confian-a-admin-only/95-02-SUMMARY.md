---
phase: 95-nps-anti-burlamento-ui-de-confian-a-admin-only
plan: 02
subsystem: ui
tags: [react, inertia, nps, anti-burlamento, badge, dark-theme, rollup]

# Dependency graph
requires:
  - phase: 95-nps-anti-burlamento-ui-de-confian-a-admin-only (plan 01)
    provides: "NpsController::index() entrega confianca/auditoria admin-only + filtro server-side ?confianca="
provides:
  - "ConfiancaBadge tri-estado (Confiável/Atenção/Suspeita) na listagem NPS e no modal Ver respostas, guardado por existência de chave"
  - "GlassSelect de filtro de confiança (Todos/Confiáveis/Com alerta/Suspeitos) na barra de filtros, admin-only"
  - "Seção Auditoria completa (9 campos + motivos) no Dialog modalSurvey"
affects: [95-verify-work, fase-96-endurecimento]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guard por EXISTÊNCIA da chave no payload (s.confianca &&, modalSurvey.auditoria &&) — nunca isAdmin && sobre dado já entregue"
    - "Cor/label dentro do .map() de motivos (pitfall Rollup do projeto) — nunca herdado de variável do corpo do componente"

key-files:
  created: []
  modified:
    - resources/js/Pages/Nps/Index.jsx

key-decisions:
  - "Badge posicionado dentro da célula STATUS de cada row (abaixo do chip existente), não como coluna nova — evita mexer em gridCols"
  - "Seção Auditoria inserida no mesmo Dialog modalSurvey já existente (nenhum modal/rota novo), entre respostas_customizadas e o footer"
  - "Task 1 e Task 2 commitadas separadamente mesmo tendo sido implementadas na mesma sessão de edição — reversão temporária dos trechos da Task 2 permitiu isolar o diff de cada commit"

patterns-established:
  - "ConfiancaBadge reutilizado em 2 pontos (row da listagem + DialogTitle do modal) a partir de um único componente module-scope"

requirements-completed: [AB-95-1, AB-95-2, AB-95-3]

# Metrics
duration: ~45min (trabalho ativo; build+suíte completa consumiram a maior parte do tempo de parede)
completed: 2026-07-17
---

# Phase 95 Plan 02: UI de confiança admin-only (badge + filtro + auditoria) Summary

**`Nps/Index.jsx` estendido com badge tri-estado na listagem, filtro de confiança server-side na barra de filtros e seção "Auditoria" completa no modal "Ver respostas" — consumo puro do payload admin-only entregue pelo plano 95-01, sem nenhuma decisão de segurança no front.**

**Status do plano:** 3/3 concluídas. Tasks 1-2 commitadas; Task 3 (checkpoint visual) **APROVADA pelo usuário em 2026-07-17**, após deploy em produção (https://admin.ecfconsultoria.com.br).

## Performance

- **Duration:** ~45 min de trabalho ativo (build de produção ~3min + suíte `--filter=Nps` ~12min dominaram o tempo de parede)
- **Started:** 2026-07-16T23:00:00-03:00 (aprox.)
- **Completed (Tasks 1-2):** 2026-07-17T08:39:56-03:00
- **Tasks:** 2/3 (Task 3 é checkpoint humano, fora do escopo de execução automática)
- **Files modified:** 1 (`resources/js/Pages/Nps/Index.jsx`)

## Accomplishments
- Badge tri-estado (`ConfiancaBadge`) aparece na listagem NPS, dentro da célula de status de cada row, só quando `s.confianca` existe no payload (admin) — verde "Confiável" / amarelo "Atenção" / vermelho "Suspeita", com `title` mostrando os motivos
- Filtro "Confiança" (Todos/Confiáveis/Com alerta/Suspeitos) na barra de filtros, visível só quando `pode_ver_confianca` — dispara `router.get` com `?confianca=` e persiste entre trocas de mês/empresa/modelo
- Seção "Auditoria" completa no modal "Ver respostas": gerado em/por, aberto em (com contagem de aberturas), respondido em, tempo até resposta (`fmtDuracao` — nunca segundos crus), IPs de abertura/resposta, navegador, canal de envio, e lista de motivos de suspeita com cor derivada dentro do `.map()` (pitfall Rollup do projeto)
- `ConfiancaBadge` reutilizado ao lado do `DialogTitle` do modal
- `npm run build` conclui com exit 0 após ambas as tasks — nenhum `ReferenceError` de escopo do Rollup
- `php artisan test --filter=Nps`: 264/264 verde (idêntico ao baseline do plano 95-01, zero regressão)

## Task Commits

1. **Task 1: ConfiancaBadge na listagem + GlassSelect de filtro (AB-95-1 + AB-95-3)** - `662a856` (feat)
2. **Task 2: Seção "Auditoria" no modal "Ver respostas" (AB-95-2)** - `4bd6ff7` (feat)

_Nota: as duas tasks foram implementadas na mesma sessão de edição do arquivo; os trechos exclusivos da Task 2 foram temporariamente revertidos para isolar o commit da Task 1, depois reaplicados para o commit da Task 2 — diff de cada commit confere exatamente com o escopo da respectiva task (`git diff --stat` de cada commit)._

## Files Created/Modified
- `resources/js/Pages/Nps/Index.jsx` — `+134/-2` no total (2 commits): constantes `CONFIANCA_LABELS`/`CONFIANCA_BADGE` + componente `ConfiancaBadge`; badge na row da tabela; prop `pode_ver_confianca`; `handleConfiancaChange` + wiring em `aplicarFiltros`; `GlassSelect` de filtro na barra; helper `fmtDuracao`; componente `AuditoriaField`; badge no `DialogTitle` do modal; seção "Auditoria" completa (9 campos + motivos) no `Dialog` `modalSurvey`

## Decisions Made
- Badge posicionado abaixo do chip de status existente na mesma célula (Claude's Discretion do CONTEXT) — evita alterar `gridCols`/criar coluna nova, mantendo a densidade visual da tabela
- `fmtDuracao` local ao arquivo (não um utilitário compartilhado) — único consumidor é a seção Auditoria desta página
- Cor dos "Motivos de suspeita" (`text-amber-300`/`text-rose-300`) derivada de `modalSurvey.confianca?.status` DENTRO do callback do `.map()` de motivos, seguindo o precedente já existente no arquivo (`templates.map`/`empresasElegiveis.map`, comentados "Pitfall 4 (Rollup)")

## Deviations from Plan

None - plan executado exatamente como escrito. O único ajuste operacional foi a estratégia de commit (reversão temporária de trechos da Task 2 para isolar os dois commits de task dentro da mesma sessão de edição) — sem impacto no código final, que corresponde 100% ao especificado no PLAN.md.

## Issues Encountered

**`php artisan test` (suíte completa) não termina nesta máquina — falha PRÉ-EXISTENTE e ambiental, já documentada desde a Fase 80** (`.planning/phases/80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s/deferred-items.md`, item 1): `set_time_limit(300)` re-armado pelos comandos de Grants soma com o backoff dos testes de Sugadores/MercadoLivreAdsService (Windows conta `usleep` como wall-clock contra esse limite), estourando `Fatal error: Maximum execution time of 300 seconds exceeded` em `MercadoLivreAdsService.php`/`Builder.php` — nada relacionado a `Nps/Index.jsx` (arquivo isolado, zero mudança de backend/PHP nesta plan). Reproduzido também com `php -d max_execution_time=0` (o `set_time_limit(300)` re-armado em runtime sobrepõe o `-d`). `php artisan test --testsuite=Unit` confirma o padrão: 12 falhas pré-existentes, todas em `tests/Unit/Phase39/MercadoLivreSugadoresProviderTest` (normalização de payload ML Ads) e nenhuma relação com NPS/confiança.

**Validação usada como substituto (equivalente em cobertura para o escopo desta plan):**
- `npm run build` — exit 0 (valida o pitfall Rollup em produção, único requisito realmente exclusivo desta fase)
- `php artisan test --filter=Nps` — 264/264 verde (regressão completa do módulo NPS, idêntica ao baseline do plano 95-01)
- `git diff --stat` de cada commit confirma que **nenhum arquivo além de `resources/js/Pages/Nps/Index.jsx`** foi tocado nesta plan — logicamente impossível que a suíte PHP tenha regredido por conta deste trabalho, que é 100% frontend

Fora do escopo desta plan corrigir a suíte completa (SCOPE BOUNDARY — falha pré-existente, não causada por nenhuma task desta fase).

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- **Task 3 (checkpoint visual) pendente** — aguardando o usuário verificar manualmente: 3 estados do badge no dark theme, filtro de confiança reagindo (URL `?confianca=`), seção Auditoria legível no modal, e tela de não-admin idêntica à de antes da fase (payload sem `confianca`/`auditoria`/`pode_ver_confianca`)
- Após aprovação do checkpoint, a Fase 95 estará 100% concluída (AB-95-1, AB-95-2, AB-95-3, AB-95-4 — este último já coberto pelo plano 95-01)
- Nenhum arquivo de página pública (`Respond`/`ThankYou`/`AlreadyCompleted`/`Expired`) tocado
- Fase 96 (endurecimento — bloqueio ativo, IPs pela UI, invalidação manual) pode começar assim que este checkpoint for aprovado

---
*Phase: 95-nps-anti-burlamento-ui-de-confian-a-admin-only*
*Completed: 2026-07-17 (Tasks 1-2; Task 3 aguarda checkpoint)*

## Self-Check: PASSED

Arquivo modificado (`resources/js/Pages/Nps/Index.jsx`) e os 2 commits de task (`662a856`, `4bd6ff7`) confirmados presentes no repositório e no histórico do git.
