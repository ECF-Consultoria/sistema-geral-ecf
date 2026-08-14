---
phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
plan: 02
subsystem: comercial
tags: [laravel, inertia, react, n+1, badge, contratos, comercial]

# Dependency graph
requires:
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 01
    provides: "resources/js/lib/contratoStatus.js (rótulos/cores/formatação dos 7 estados + SEM_CONTRATO)"
provides:
  - "Prop `contrato_badge` por empresa em ComercialController::listagem(), montada em lote (query única por página)"
  - "Componente ContratoBadge em Comercial/EmpresasListagem.jsx (situação + 'há N dias', sem link)"
affects: [131-03, 131-04, 131-05, 131-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Badge derivado em memória sobre coleção já materializada (mesmo padrão de pendências comerciais do próprio método)"
    - "Teste de ausência de N+1 filtrado por tabela (str_contains no SQL do query log), não pela contagem TOTAL da resposta — necessário porque a resposta já tem N+1 pré-existente alheio a esta fase"

key-files:
  created:
    - tests/Feature/Phase131/EmpresasListagemBadgeContratoTest.php
    - .planning/phases/131-tela-administrativa-completar-cadastro-contratos-badge-comer/deferred-items.md
  modified:
    - app/Http/Controllers/ComercialController.php
    - resources/js/Pages/Comercial/EmpresasListagem.jsx

key-decisions:
  - "Teste de N+1 filtra a contagem de queries pela tabela contrato_assinaturas, não pela contagem TOTAL da resposta — a listagem já tem um N+1 pré-existente e alheio a este plano (accessors adman_account_id/ml_store_id fazem 2 queries em company_marketplaces por empresa). Documentado em deferred-items.md, não corrigido (fora do escopo desta task)"

patterns-established:
  - "Query em lote whereIn(company_id)->whereHas('servico', exige_contrato)->orderByDesc('id')->groupBy('company_id')->map(first) para resolver 'o mais recente por empresa' sem N+1"

requirements-completed: [UI-03, UI-06]

# Metrics
duration: ~55min
completed: 2026-08-14
---

# Phase 131 Plan 02: Badge de contrato na listagem do Comercial (D-08) Summary

**Prop `contrato_badge` montada em lote (query única por página, sem N+1) em `ComercialController::listagem()` + coluna "Contrato" com situação e "há N dias" em `Comercial/EmpresasListagem.jsx`, sem link — o Comercial volta a enxergar para onde a empresa foi depois do fechamento.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-08-14 (sessão única, continuação do 131-01)
- **Completed:** 2026-08-14
- **Tasks:** 3/3
- **Files modified:** 4 (2 modificados, 2 criados — incluindo deferred-items.md)

## Accomplishments

- `ComercialController::listagem()` monta `contrato_badge` para cada empresa da página com os três
  casos exigidos pela D9/D-08: contrato encontrado (status + dias via `ContratosPresosService::diasParado()`,
  puro), sem contrato mas serviço exige (`aguardando_administrativo` com base em `companies.created_at`),
  e serviço isento — Polos — (`null`, nunca fila fantasma)
- A busca dos contratos da página é **uma query única** (`whereIn('company_id', ...)`), indexada em
  memória por `company_id`, resolvendo "o contrato mais recente por empresa" com `orderByDesc('id')`
  + `groupBy` + `first()` por grupo — nunca `ContratosPresosService::listar()`, que esconderia
  contratos saudáveis
- `Comercial/EmpresasListagem.jsx` ganhou a coluna "Contrato" com o componente `ContratoBadge`
  (molde literal de `SetorBadge`), consumindo `rotuloContrato`/`classeContrato`/`formatarHaDias`/
  `SEM_CONTRATO`/`SEM_CONTRATO_LABEL` de `resources/js/lib/contratoStatus.js` (131-01) — nenhum texto
  novo inventado, tudo vindo da tabela de copy do UI-SPEC
- O badge nunca navega (sem `<Link>`/`<a>`/`onClick` de navegação) — o Comercial não tem
  `admin.contratos` e um clique daria 403
- `npm run build` verde, `Comercial/EmpresasListagem` presente no manifest do Vite

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Prop `contrato_badge` montada em lote + teste com os 3 casos de conteúdo** - `372099c0` (feat)
2. **Task 2: Coluna "Contrato" com o badge na listagem** - `7ff93298` (feat)
3. **Task 3: Completar o teste — contrato mais recente e ausência de N+1** - `da7b9679` (test)

**Plan metadata:** commit deste SUMMARY + STATE.md + deferred-items.md (a seguir)

## Files Created/Modified

- `app/Http/Controllers/ComercialController.php` - injeta `ContratosPresosService`, monta
  `$contratosPorEmpresa` (query em lote) antes do `.map()` do payload, acrescenta `contrato_badge`
  com os três casos, constante privada `CONTRATO_BADGE_SEM_CONTRATO`
- `resources/js/Pages/Comercial/EmpresasListagem.jsx` - import de `@/lib/contratoStatus`, componente
  `ContratoBadge`, `<TableHead>Contrato</TableHead>` + `<TableCell>` na linha, `colSpan` do estado
  vazio ajustado de 7 para 8
- `tests/Feature/Phase131/EmpresasListagemBadgeContratoTest.php` - 5 testes: os 3 casos de conteúdo
  (Task 1) + contrato mais recente + ausência de N+1 filtrada por tabela (Task 3)
- `.planning/phases/131-.../deferred-items.md` - registro do N+1 pré-existente e alheio encontrado
  durante a Task 3 (accessors de marketplace)

## Decisions Made

- Nenhuma decisão nova além das já travadas pelo CONTEXT/UI-SPEC/PATTERNS — este plano seguiu a
  trilha do análogo (`ComercialController::listagem()` já resolvia N+1 de pendências comerciais com
  o mesmo padrão: eager load + cálculo em PHP sobre coleção materializada)
- Adaptação do teste de N+1 (ver Deviations abaixo) — não é decisão de produto, é decisão de como
  provar a ausência de N+1 diante de um problema pré-existente descoberto durante a task

## Deviations from Plan

### Auto-fixed Issues

**1. [Scope Boundary — teste, não bug de produção] Teste de N+1 adaptado para filtrar por tabela em vez de contagem total**
- **Found during:** Task 3
- **Issue:** O texto literal do plano pedia "assertar que a diferença de contagem [TOTAL] entre os
  dois cenários é zero". Ao implementar isso literalmente, o teste falhou: 22 queries com 2 empresas
  vs. 29 com 6 (diferença de 7, não de 0). Investigado com `DEBUG_QUERY_LOG`: a causa é um N+1
  **pré-existente e alheio a este plano** — `Company::getAdmanAccountIdAttribute()` e
  `getMlStoreIdAttribute()` fazem `->marketplaces()->where('marketplace','meli')->first()` (query em
  `company_marketplaces`) CADA UM, e o payload da listagem lê os dois campos por empresa. 12 queries
  nesta tabela para 6 empresas confirmam 2 por empresa — comportamento que já existia antes deste
  plano, em código que este plano não toca.
- **Fix:** Em vez de "afrouxar" a asserção genérica, segui o padrão já estabelecido no projeto
  (`RelatorioBonificacaoEmpresasTest::leitura_do_detalhe_por_empresa_e_uma_query_so_com_tres_profissionais`,
  Phase 123) — filtrar a contagem de queries pela tabela relevante (`contrato_assinaturas`) em vez
  de pela resposta HTTP inteira. O teste agora assere que a contagem filtrada é **exatamente 1** em
  ambos os cenários (mais forte que "diferença zero"), provando especificamente que a montagem do
  BADGE não escala — que é o que a D-08/T-131-02-03 exige. Confirmei a eficácia do teste revertendo
  temporariamente a query em lote do controller para uma chamada por linha: o teste falhou
  (`Failed asserting that 3 is identical to 1`), confirmando que ele realmente pega regressão; a
  alteração temporária foi desfeita antes de commitar (`git diff` vazio).
- **Não corrigido (fora do escopo desta task):** o N+1 de `company_marketplaces` continua existindo,
  registrado em `.planning/phases/131-.../deferred-items.md` com a medição exata e uma sugestão de
  correção futura (eager-load de `marketplaces` no `Company::with([...])` de `listagem()`).
- **Files modified:** `tests/Feature/Phase131/EmpresasListagemBadgeContratoTest.php`,
  `.planning/phases/131-.../deferred-items.md`
- **Commit:** `da7b9679`

### Não-issues confirmados

Comentário de código na Task 1 continha a string literal `ContratosPresosService::listar()` (para
explicar por que o método NÃO é usado) — o próprio `grep -c` de acceptance criteria pegou essa
menção como falso positivo, mesma classe de problema já documentada no SUMMARY do 131-01. Corrigido
reescrevendo o comentário sem repetir o identificador, mesmo critério de aceitação.

## Issues Encountered

Nenhum bloqueio. A única surpresa foi o N+1 pré-existente descrito acima, tratado como Scope
Boundary (não como bug desta task) e documentado, não corrigido.

## User Setup Required

Nenhum.

## Next Phase Readiness

- O badge de contrato está no ar na listagem do Comercial, consumindo o módulo compartilhado
  `resources/js/lib/contratoStatus.js` — os planos 131-03/131-04/131-05 (telas `Admin/Contratos.jsx`
  e `Admin/ContratoDetalhe.jsx`) devem importar o MESMO módulo, não redeclarar rótulos/cores
- O padrão de query em lote (`whereIn` + `groupBy` + `first()` por grupo) fica disponível como
  referência para qualquer tela futura que precise de "o registro mais recente por empresa" sem N+1
- `.planning/phases/131-.../deferred-items.md` criado — próximos planos desta fase (ou uma fase de
  otimização futura) devem conferir esse arquivo antes de mexer em `ComercialController::listagem()`
  novamente

Nenhum bloqueio identificado para os próximos planos.

---
*Phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer*
*Completed: 2026-08-14*

## Self-Check: PASSED

Todos os arquivos criados/modificados confirmados em disco e os 3 commits de task confirmados em
`git log --oneline --all` (`372099c0`, `7ff93298`, `da7b9679`).
