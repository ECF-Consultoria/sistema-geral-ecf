---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 01
subsystem: testing
tags: [phpunit, vite, npm, inertia, baseline, ci-local]

# Dependency graph
requires: []
provides:
  - "Ambiente de teste Feature/Inertia destravado neste worktree (public/build/manifest.json via npm install + npm run build)"
  - "137-BASELINE-TESTES.md — baseline verde registrada antes de qualquer migration da fase"
  - "Achado documentado: suíte Feature completa não termina neste ambiente offline (timeout de rede em cascata)"
affects: [137-02, 137-03, 137-04, 137-05, 137-06, 137-07, gsd:verify-work]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Baseline de testes escrita ANTES de migration em tabela com dado de produção (exigência CLAUDE.md § GSD obrigatório)"

key-files:
  created:
    - ".planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-BASELINE-TESTES.md"
  modified: []

key-decisions:
  - "Usado o caminho definitivo npm install + npm run build (não o atalho public/hot) — ambiente fica destravado de forma persistente para os planos 137-02..07, sem precisar recriar public/hot a cada task"
  - "package-lock.json revertido (git checkout --) depois do npm install para permanecer byte-idêntico ao versionado, conforme critério de aceite da Task 1 — o npm havia reescrito só o campo name (ecf_admin_onb → ecf_fluxo_entrada), sem adicionar pacote"
  - "Suíte completa (php artisan test) não capturável numa única execução neste ambiente — dividida em Unit (capturado integralmente) + Feature (achado documentado, não capturado por inteiro), conforme a contingência já prevista na Task 2 do plano"

patterns-established:
  - "137-BASELINE-TESTES.md é o documento 'antes' — comparação de regressão nos planos seguintes sempre contra os números ali registrados, nunca contra zero"

requirements-completed: [ETAPA-01, ETAPA-02]

# Metrics
duration: ~40min
completed: 2026-09-01
---

# Phase 137 Plan 01: Destravar ambiente de teste + baseline pré-migration Summary

**Ambiente Inertia/Feature-test destravado via `npm install && npm run build` (não o atalho `public/hot`); baseline verde de 24/44 testes registrada em `137-BASELINE-TESTES.md` antes de qualquer migration, com as falhas antigas de Polos (10) e um achado novo — cascata de timeout de rede que impede rodar a suíte Feature completa neste ambiente offline — nomeados por escrito.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-09-01T18:22:23Z (marcado pelo orquestrador ao abrir a execução)
- **Completed:** 2026-09-01T19:01:14Z
- **Tasks:** 2/2
- **Files modified:** 1 (criado)

## Accomplishments

- `public/build/manifest.json` existe — toda Feature test que renderiza Inertia passa sem
  `Vite manifest not found` (confirmado com `Phase37CompaniesPerformanceFilterTest`: 15 testes,
  26 assertions, verde).
- `137-BASELINE-TESTES.md` criado com: comando exato de cada execução, contagem literal
  (testes/assertions/erros/falhas), campo `caminho: npm-build`, e as falhas antigas de Polos
  nomeadas nominalmente com contagem isolada (10 no total, batendo com o learning).
- Achado novo, custoso e não dedutível do código, documentado por escrito para as próximas
  sessões: a suíte Feature completa (`--testsuite=Feature`, 531 arquivos) não termina neste
  worktree — trava numa cascata de timeout de rede real via Guzzle depois de ~300s e morre sem
  imprimir o resumo final. Registrado com a recomendação prática (usar `Quick run` +
  `Baseline command` por wave, nunca depender do `artisan test` completo).
- Confirmado que este plano não tocou nenhum arquivo de `app/`, `database/` ou `resources/`
  (`git status --porcelain app/ database/ resources/` vazio) — nenhuma das falhas encontradas é
  regressão da Fase 137.

## Task Commits

Task 1 (destravar ambiente) não gerou nenhum arquivo rastreado para commitar: o caminho
`npm-build` foi usado (não o atalho `public/hot`), e `node_modules/`/`public/build/` são
gitignored; `package.json`/`package-lock.json` ficaram idênticos ao versionado (o diff trivial
de `name` no lockfile foi revertido antes de qualquer commit). O resultado da Task 1 fica
registrado dentro do `137-BASELINE-TESTES.md` da Task 2.

1. **Task 1: Destravar o ambiente Inertia deste worktree** — sem commit próprio (nenhum arquivo
   rastreado alterado; ver nota acima)
2. **Task 2: Registrar a baseline verde antes de qualquer migration** — `96e62ed9` (docs)

**Plan metadata:** commit de fechamento pendente (SUMMARY + STATE + ROADMAP), feito na etapa
seguinte deste executor.

## Files Created/Modified

- `.planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-BASELINE-TESTES.md` — baseline de testes pré-migration, com achados de ambiente e falhas pré-existentes nomeadas
- `node_modules/` (gitignored, não commitado) — restaurado via `npm install` a partir do `package-lock.json` já versionado, 511 pacotes, nenhum novo
- `public/build/` (gitignored, não commitado) — gerado via `npm run build`

## Decisions Made

- **Caminho `npm-build` em vez de `public/hot`:** o plano permitia os dois; `npm install` e
  `npm run build` funcionaram de primeira (sem erro de rede/versão/permissão), então o atalho
  nunca foi necessário. Isso também deixa o worktree pronto para a regra do `CLAUDE.md` de
  `npm run build` quando o plano 137-07 alterar `Companies/Index.jsx`.
- **Reverter o diff trivial de `package-lock.json`:** o `npm install` sincronizou o campo
  `"name"` do lockfile (herdado de um worktree anterior, `ecf_admin_onb`) para o nome real deste
  diretório. Como o critério de aceite da Task 1 exige `git status --porcelain package.json
  package-lock.json` vazio, e a árvore é compartilhada entre sessões, o diff foi revertido com
  `git checkout -- package-lock.json` — não afeta `node_modules/` já instalado.
- **Suíte completa dividida em Unit + Feature parcial:** `php artisan test` estourou 600s sem
  terminar; ao isolar `--testsuite=Feature`, o processo trava numa cascata de erro de rede
  (ver "Issues Encontrados"). A contingência já estava prevista na Task 2 do plano
  ("rode em duas partes"), então isso não é desvio — é o caminho alternativo explicitamente
  desenhado, só que a metade Feature não fechou nem nessa forma dividida.

## Deviations from Plan

None (Regras 1-4) — nenhum bug corrigido, nenhuma funcionalidade crítica adicionada, nenhum
bloqueio resolvido fora do previsto, e nenhuma mudança arquitetural. O único ajuste foi
operacional: reverter o diff trivial de `package-lock.json` gerado pelo próprio `npm install`
para satisfazer o critério de aceite da Task 1 (não é uma "correção de bug" no sentido das
Regras 1-3 — é higiene de árvore compartilhada, já coberta pela disciplina do próprio
`CLAUDE.md`).

## Issues Encountered

- **Suíte Feature completa (`--testsuite=Feature`) não termina neste ambiente.** Trava numa
  cascata de `Fatal error: Maximum execution time of 300 seconds exceeded` (Guzzle
  `CurlFactory.php:695`) depois de uma chamada de rede real não mockada, sem alcançar o alvo
  externo (ambiente offline). Duas tentativas de bissecar via `--stop-on-failure`/
  `--stop-on-error` pararam antes, em falhas rápidas e não relacionadas
  (`AdminFechamentoControllerTest`). **O teste ofensor exato não foi identificado** — custo de
  reprodução de vários minutos por tentativa tornou a busca exaustiva fora do orçamento desta
  task. Documentado em detalhe no `137-BASELINE-TESTES.md`, com recomendação para as próximas
  sessões (não depender do `artisan test` completo; usar `Quick run` + `Baseline command`).
- Duas suítes já sabidamente quebradas (`CalcularFaixaTest`, unitária, `ArgumentCountError` em
  `AdminController`) e o resto do Unit suite (`CompanyServiceTypeTest`,
  `MercadoLivreSugadoresProviderTest`) confirmadas como pré-existentes e fora do escopo deste
  plano — nomeadas no baseline, não corrigidas (regra de escopo: este plano não toca `app/`).

## User Setup Required

None — nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Ambiente de teste Feature/Inertia está pronto para os planos 137-02..07 rodarem suas próprias
  suítes (`tests/Unit/Phase137`, `tests/Feature/Phase137`) sem bloqueio de manifest do Vite.
- Baseline registrada e datada ANTES da migration — 137-02 pode prosseguir com a migration de
  `companies.etapa` sabendo exatamente quais falhas já existiam antes dela.
- **Atenção para 137-02..07 e para `/gsd:verify-work`:** não tentar `php artisan test`
  completo esperando resultado — vai travar na mesma cascata de rede. Usar os comandos
  registrados em `137-BASELINE-TESTES.md` (`Quick run` por task, `Baseline command` por wave).
- Nenhum bloqueio para o plano 137-02.

---
*Phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Completed: 2026-09-01*

## Self-Check: PASSED

- FOUND: `.planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-BASELINE-TESTES.md`
- FOUND: `.planning/phases/137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/137-01-SUMMARY.md`
- FOUND commit: `96e62ed9` (docs(137-01): registra baseline de testes antes da migration da fase)
- FOUND commit: `4429af7e` (docs(137-01): SUMMARY do plano 01)
