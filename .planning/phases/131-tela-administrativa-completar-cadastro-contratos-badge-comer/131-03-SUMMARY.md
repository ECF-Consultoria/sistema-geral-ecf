---
phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
plan: 03
subsystem: admin
tags: [laravel, inertia, react, permissions, contratos, admin, tailwind]

# Dependency graph
requires:
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 01
    provides: "Permissions::ADMIN_CONTRATOS (short-circuit hasPermission), resources/js/lib/contratoStatus.js (rótulos/cores/formatação dos 7 estados)"
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 02
    provides: "padrão de query em lote whereIn+groupBy+first() para 'contrato mais recente por empresa' sem N+1, já aplicado em ComercialController::listagem()"
provides:
  - "ContratoAdminController::index() — universo filtrado por Servico::exigeContrato() (D9), resumo de 7 contagens (D-04), filtro/busca/ordenação"
  - "Grupo de rotas admin/contratos FORA do grupo role:admin, sob permission:admin.contratos (UI-05/D-09)"
  - "Tela Admin/Contratos.jsx — grid de resumo/filtro (ponto focal), busca, tabela, paginação"
  - "Item 'Contratos' no menu Administrativo, gateado por permission"
affects: [131-04, 131-05, 131-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Universo de uma lista administrativa filtrado no backend por regra de negócio (Servico::exigeContrato()), nunca escondido no client"
    - "Linha derivada quando não há registro ainda (par empresa+serviço sem ContratoAssinatura vira 'aguardando_administrativo', estado só de exibição fora do enum)"
    - "Resumo de contagens fixas (7 chaves) calculado ANTES do filtro de situação, sobre a coleção completa — mesmo padrão de pendencia_counts do ComercialController"

key-files:
  created:
    - app/Http/Controllers/ContratoAdminController.php
    - tests/Feature/Phase131/ContratoAdminListaTest.php
    - tests/Feature/Phase131/ContratoAdminPermissaoTest.php
    - tests/Feature/Phase131/ContratoAdminListaExcluiPolosTest.php
  modified:
    - routes/web.php
    - resources/js/Pages/Admin/Contratos.jsx
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Placeholder mínimo de Admin/Contratos.jsx criado já na Task 1 (fora do escopo declarado da task) — sem ele o Inertia não resolve o componente e o manifest do Vite não tem a entrada, e o teste da própria Task 1 não passaria nem de 200; a Task 2 substituiu o placeholder pela tela completa no mesmo arquivo"
  - "Verificação do middleware da rota adaptada: 'artisan route:list -v' nesta versão do Laravel imprime 'App\\Http\\Middleware\\EnsurePermission:admin.contratos', não 'permission:admin.contratos' — confirmado comparando com uma rota já existente (core.empresas). A garantia real (permission certa presente, role:admin ausente) foi verificada com o padrão real da saída, e reforçada por uma asserção de código sobre Route::getRoutes()->gatherMiddleware(), mais robusta que grep de texto"

patterns-established:
  - "Asserção de fonte sobre middleware de rota via Route::getRoutes()->getByName(...)->gatherMiddleware() em vez de grep de arquivo — pega também o caso de a rota ser envolvida por um grupo externo"

requirements-completed: [UI-01, UI-05, UI-06]

# Metrics
duration: ~70min
completed: 2026-08-14
---

# Phase 131 Plan 03: Lista de contratos do Administrativo — permission, resumo e tela Summary

**`ContratoAdminController::index()` com universo filtrado por `Servico::exigeContrato()` (D9), resumo travado em 7 contagens (D-04), grupo de rotas `admin/contratos` fora do grupo `role:admin` sob `permission:admin.contratos` (UI-05/D-09), e a tela `Admin/Contratos.jsx` com grid de resumo/filtro como ponto focal.**

## Performance

- **Duration:** ~70 min
- **Started:** 2026-08-14 (sessão única)
- **Completed:** 2026-08-14
- **Tasks:** 3/3
- **Files modified:** 7 (3 criados, 1 controller novo, 3 testes novos)

## Accomplishments

- `ContratoAdminController::index()` monta o universo (empresas ativas com pelo menos um
  `ContratoServico` ativo cujo `Servico::exigeContrato()` é verdadeiro), busca os contratos de
  todas elas numa única query (`whereIn` + `groupBy` + `first()` por par empresa+serviço, sem
  N+1), e devolve linha achatada por par — nunca o model inteiro, nunca dado de signatário
- Resumo com EXATAMENTE as 7 chaves de `ContratoAssinatura::STATUS_TODOS`, calculado sobre a
  coleção completa antes do filtro de situação (contagens absolutas); `sem_contrato_count` é uma
  prop escalar separada, fora do resumo (D-04)
- Grupo de rotas `admin/contratos` criado DELIBERADAMENTE fora do grupo `role:admin` existente
  (`Módulo Administrativo`), sob `permission:admin.contratos` — validado manualmente que mover a
  rota para dentro do grupo `role:admin` faz `ContratoAdminPermissaoTest` falhar (e revertido)
- `Admin/Contratos.jsx`: grid de 7 cards clicáveis (resumo + filtro, ponto focal da tela por
  UI-SPEC), linha de `sem_contrato_count` fora do grid, busca por empresa, tabela compacta e
  paginação manual — consome `resources/js/lib/contratoStatus.js` do plano 131-01, sem duplicar
  rótulos/cores
- Item "Contratos" no menu Administrativo, gateado por `permission: 'admin.contratos'`
- `npm run build` verde, `Admin/Contratos.jsx` confirmado no manifest do Vite
- Suíte `Phase126|Phase129|Phase130|Phase131` = **315 testes verdes** (era 299 ao fim do 131-02;
  +16 testes novos desta task, zero regressão)

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: ContratoAdminController::index() + grupo de rotas com permission:admin.contratos** - `b1797569` (feat)
2. **Task 2: Tela Admin/Contratos.jsx + item de menu** - `1adc356d` (feat)
3. **Task 3: Testes de gate da permission, da isenção Polos e o restante da lista** - `c7e91310` (test)

**Plan metadata:** commit deste SUMMARY + STATE.md + ROADMAP.md (a seguir)

## Files Created/Modified

- `app/Http/Controllers/ContratoAdminController.php` - `index()`: universo por `Servico::exigeContrato()`,
  contratos em lote por par empresa+serviço, resumo de 7 contagens, filtro/busca/ordenação,
  paginação manual via `LengthAwarePaginator`
- `routes/web.php` - import de `ContratoAdminController` + grupo `admin/contratos` sob
  `permission:admin.contratos`, fora do grupo `role:admin` do "Módulo Administrativo"
- `resources/js/Pages/Admin/Contratos.jsx` - tela completa: grid de resumo/filtro (7 estados),
  linha de sem-contrato, busca, tabela compacta, coluna "Ações" preparada e vazia (link entra no
  plano 131-04), paginação manual
- `resources/js/Layouts/AppLayout.jsx` - import de `FileSignature` + item "Contratos" no grupo
  "Administrativo"
- `tests/Feature/Phase131/ContratoAdminListaTest.php` - 10 testes: núcleo da Task 1 (200 +
  componente, resumo de 7 chaves, `sem_contrato_count` fora dele) + Task 3 (contagens por
  reconsulta, contagens fixas sob filtro, filtro de situação, whitelist, busca, ordenação,
  ausência de dado de signatário)
- `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` - 4 testes: 403 sem a key, 200 via
  `role:admin`, 200 via `SetorPermissao`, e asserção de fonte sobre o middleware da rota nomeada
- `tests/Feature/Phase131/ContratoAdminListaExcluiPolosTest.php` - 2 testes: empresa só-Polos
  nunca aparece; empresa mista (Polos + serviço que exige) aparece só com a linha que exige

## Decisions Made

- Nenhuma decisão de produto nova além das já travadas pelo CONTEXT/UI-SPEC/PATTERNS — este plano
  seguiu D-01/D-04/D-09/D-10 literalmente
- Decisão técnica de execução: placeholder mínimo de `Admin/Contratos.jsx` já na Task 1 (ver
  Deviations) — não é decisão de produto, é decisão de como destravar um teste que dependia de um
  arquivo formalmente atribuído à Task 2

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Placeholder mínimo de `Admin/Contratos.jsx` criado na Task 1**
- **Found during:** Task 1
- **Issue:** O teste `ContratoAdminListaTest` (que a própria Task 1 exige, pela regra "o teste
  nasce na mesma task do código que ele prova") faz `GET` na rota e espera `200`. O
  `Inertia::render('Admin/Contratos', ...)` precisa resolver o componente no manifest do Vite —
  que só existiria depois da Task 2 criar e buildar o arquivo. Sem isso, o teste falha com
  `Unable to locate file in Vite manifest` antes mesmo de testar o resumo/contagens que é o
  objetivo real da Task 1.
- **Fix:** Criado um placeholder mínimo (`AppLayout` + `<main className="p-6" />`, sem lógica) em
  `resources/js/Pages/Admin/Contratos.jsx` na Task 1, seguido de `npm run build` para popular o
  manifest. A Task 2 substituiu o conteúdo pelo layout completo no MESMO arquivo (mesmo diff de
  arquivo, sem duplicação de trabalho) e rodou `npm run build` de novo.
- **Files modified:** `resources/js/Pages/Admin/Contratos.jsx` (Task 1: criação mínima; Task 2:
  conteúdo completo)
- **Commit:** `b1797569` (Task 1), substituído em `1adc356d` (Task 2)

**2. [Rule 1 - Verificação] `route:list -v` não imprime o texto `permission:admin.contratos`**
- **Found during:** Task 1
- **Issue:** O `<verify>` do plano pedia `route:list --name=admin.contratos.index -v | grep -c
  "permission:admin.contratos"`. Medido: nesta versão do Laravel, `route:list -v` imprime o
  middleware RESOLVIDO (`App\Http\Middleware\EnsurePermission:admin.contratos`), não o alias
  literal usado no `routes/web.php`. Confirmado comparando com uma rota já correta e antiga
  (`companies.index`, `permission:core.empresas`) — o mesmo formato aparece lá, então não é bug
  desta rota, é o formato de saída do comando nesta versão.
- **Fix:** Adaptei a verificação para o padrão real
  (`grep -c "EnsurePermission:admin.contratos"`, que retorna 1) e mantive a checagem de ausência
  de `role:admin` (retorna 0). Reforcei com uma asserção de CÓDIGO em
  `ContratoAdminPermissaoTest` (`Route::getRoutes()->getByName(...)->gatherMiddleware()`), mais
  robusta que grep de texto porque pega também o caso de a rota ser envolvida por um grupo
  externo — e validei manualmente que essa asserção fica vermelha se a rota for movida para
  `role:admin` (revertido antes de commitar, `git diff` vazio).
- **Files modified:** nenhum arquivo de produção — só o comando de verificação executado
  manualmente durante a task
- **Commit:** não aplicável (mudança de verificação, não de código)

**3. [Não-issue confirmado] `FileSignature` (ícone) aciona falso positivo no grep de jargão (UI-06)**
- **Found during:** Task 2
- **Issue:** O critério de aceitação pede
  `grep -ci "envelope|signat|webhook|clicksign" resources/js/Pages/Admin/Contratos.jsx` = 0. O
  ícone `FileSignature` — explicitamente pedido pelo `131-03-PLAN.md` e pelo `131-PATTERNS.md`
  para o cabeçalho da tela — contém a substring `signat` (case-insensitive), então o grep acusa 2
  ocorrências (import + uso). Mesma classe de falso positivo já documentada nos SUMMARYs do
  131-01/131-02 (identificador de código sendo confundido com jargão de texto visível).
- **Fix:** Nenhum — não há como usar o ícone `FileSignature` (nome fixo exportado pelo
  `lucide-react`, exigido pelo plano) sem a substring aparecer no arquivo. Confirmado que as duas
  ocorrências são exclusivamente o import e o uso do ícone (`grep -ni`), e que NENHUM texto
  visível ao usuário (copy, labels, placeholders) contém "envelope", "signatário", "webhook" ou
  "Clicksign" — conferido manualmente linha a linha contra a tabela de copy do UI-SPEC.
- **Files modified:** nenhum
- **Commit:** `1adc356d`

**4. [Rule 1 - Bug] Comentário do grid continha a substring `xl:grid-cols-8` literalmente**
- **Found during:** Task 2 (durante a própria implementação, antes de commitar)
- **Issue:** O primeiro rascunho do comentário explicando por que o grid usa 7 colunas (não 8)
  citava o valor antigo por extenso (`xl:grid-cols-8`), acionando o próprio grep de aceitação que
  existe para garantir que o grid NÃO usa 8 colunas.
- **Fix:** Reescrito para descrever a diferença sem repetir a classe Tailwind literal ("o número
  de colunas... lá são 8 pendências, aqui são 7 estados"). Mesmo critério de aceitação, sem
  mudança de comportamento.
- **Files modified:** `resources/js/Pages/Admin/Contratos.jsx`
- **Commit:** `1adc356d`

---

**Total deviations:** 4 (1 Rule 3, 1 Rule 1 de verificação, 1 não-issue documentado, 1 Rule 1 de comentário)
**Impact on plan:** Nenhuma mudança de escopo de produto. O placeholder da Task 1 antecipou em uma
task um arquivo que a Task 2 já ia criar de qualquer forma; as demais são ajustes de verificação
ou correções textuais sem efeito em comportamento.

## Issues Encountered

Nenhum bloqueio real. As quatro situações acima foram resolvidas dentro da própria execução, sem
precisar de decisão do usuário.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `ContratoAdminController` pronto para receber `show()` no plano 131-04 (detalhe da empresa),
  que vai adicionar a rota `admin.contratos.show` e então ligar o link da coluna "Ações" desta
  tela (hoje deliberadamente vazia)
- `admin.contratos` provada funcional via `SetorPermissao` (não só via `role:admin`) — a UI-05
  está de fato operante, não só declarada
- Padrão de query em lote (par empresa+serviço, `whereIn` + `groupBy` + `first()`) documentado e
  reaproveitável pelos planos seguintes que precisarem do mesmo recorte
- Asserção de middleware via `Route::getRoutes()->gatherMiddleware()` disponível como padrão mais
  robusto que grep de texto para os planos seguintes que também gatearem rotas por `permission:`

Nenhum bloqueio identificado para os próximos planos.

---
*Phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer*
*Completed: 2026-08-14*

## Self-Check: PASSED

Todos os arquivos criados confirmados em disco e os 3 commits de task (`b1797569`, `1adc356d`,
`c7e91310`) confirmados em `git log --oneline --all`.
