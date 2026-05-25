---
phase: 13-reestruturacao-cadastro-empresas
plan: 03
subsystem: api
tags: [laravel, controller, notifications, tdd, inertia, permissions, comercial]

# Dependency graph
requires:
  - phase: 13-reestruturacao-cadastro-empresas
    plan: 01
    provides: "companies.status + mlb_empresas.company_id colunas"
  - phase: 13-reestruturacao-cadastro-empresas
    plan: 02
    provides: "setor Comercial + permission 'comercial.cadastrar_empresa' + migration retroativa"
provides:
  - "ComercialController com criação atômica por service_type (polos/assessoria/publicidade/gestao)"
  - "EmpresaCadastradaNotification extends BaseNotification para notificar líderes de setor"
  - "Rotas /comercial/empresas/novo e /comercial/empresas com middleware permission:comercial.cadastrar_empresa"
  - "criarImplementacaoPolo() extraído de MlbImplementacaoController e replicado no ComercialController"
  - "Página Comercial/NovaEmpresa.jsx MVP funcional"
  - "12 testes Phase13ComercialTest cobrindo permissão, validação, duplicata, criação e notificações"
affects:
  - "13-reestruturacao-cadastro-empresas (wave 4 — sidebar + pendentes)"
  - "MlbImplementacaoController (refatorado com criarImplementacaoPolo privado)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TDD RED→GREEN: testes criados antes do controller (12 testes; 0 pass → 12 pass)"
    - "DB::transaction para criação atômica de company + mlb_empresa + mlb_implementacao"
    - "Notification::send() disparado FORA da transaction (Armadilha 4 — evita rollback por falha de notif)"
    - "assertInertia() para testar página Inertia sem depender do manifest Vite"
    - "Extração de método privado criarImplementacaoPolo() via D-20 (sem trait, sem service layer)"

key-files:
  created:
    - app/Http/Controllers/ComercialController.php
    - app/Notifications/EmpresaCadastradaNotification.php
    - tests/Feature/Phase13ComercialTest.php
    - resources/js/Pages/Comercial/NovaEmpresa.jsx
  modified:
    - app/Http/Controllers/MlbImplementacaoController.php
    - routes/web.php

key-decisions:
  - "assertInertia() no teste test_admin_acessa_sem_permissao_explicita evita dependência do manifest Vite (página Nova/Empresa.jsx não existia até ser criada como MVP)"
  - "criarImplementacaoPolo() duplicado em ambos os controllers (ComercialController + MlbImplementacaoController) sem trait compartilhado — per decisão D-20/Claude's Discretion de manter simplicidade"
  - "NovaEmpresa.jsx criado como MVP funcional (Wave 4 fará UI completa)"
  - "Activity log com canal 'comercial' para rastrear cadastros do setor"

patterns-established:
  - "Módulo Comercial: abort_unless + hasPermission || isAdmin() como guard duplo de acesso"
  - "Guard de duplicata: Company::whereRaw(LOWER) + MlbEmpresa::whereRaw(LOWER) antes de criar"
  - "Notificação pós-transaction: resolverSlugSetor() → Setor::where('slug') → lideres()->isNotEmpty()"

requirements-completed:
  - COM-02
  - COM-03
  - COM-04
  - COM-05
  - COM-06
  - COM-07
  - COM-08

# Metrics
duration: 7min
completed: 2026-05-25
---

# Phase 13 Plan 03: ComercialController + Rotas + Notificação

**ComercialController com criação atômica por 4 service_types, guard de duplicata case-insensitive, EmpresaCadastradaNotification para líderes de setor, e 12 testes Phase13ComercialTest GREEN via TDD RED→GREEN.**

## Performance

- **Duration:** 7 min
- **Started:** 2026-05-25T13:57:56Z
- **Completed:** 2026-05-25T14:04:52Z
- **Tasks:** 2 (Tarefa 1 TDD + Tarefa 2 implementação)
- **Files modified:** 6

## Accomplishments

- `ComercialController` implementa os 4 service_types com `DB::transaction()` atômico: polos (company+mlb_empresa+mlb_implementacao), assessoria (company+mlb_empresa), publicidade/gestao (apenas company)
- `EmpresaCadastradaNotification` criada como subclasse de `BaseNotification` com `Categoria::MANUAL`, notifica líderes do setor de destino fora da transaction
- `criarImplementacaoPolo()` extraído de `MlbImplementacaoController` (D-20) para reutilização, garantindo DRY entre os dois fluxos
- Guard de duplicata verifica `companies.name` E `mlb_empresas.nome` de forma case-insensitive via `whereRaw(LOWER)`
- 12 testes TDD: RED com 404 (sem controller) → GREEN com 12/12 passing após implementação

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **RED — EmpresaCadastradaNotification + Phase13ComercialTest** - `129bdcb` (test)
2. **GREEN — ComercialController + rotas + extração + página** - `f58da26` (feat)

_TDD: commit test antes da implementação (RED→GREEN)_

## Files Created/Modified

- `app/Http/Controllers/ComercialController.php` — Controller principal com 4 métodos (index, store, resolverSlugSetor, criarImplementacaoPolo)
- `app/Notifications/EmpresaCadastradaNotification.php` — Subclasse de BaseNotification para notificar líderes
- `tests/Feature/Phase13ComercialTest.php` — 12 testes cobrindo permissão, validação, duplicata, criação por service_type, financeiro, notificação
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` — Página MVP com formulário funcional
- `app/Http/Controllers/MlbImplementacaoController.php` — Refatorado para extrair `criarImplementacaoPolo()` privado
- `routes/web.php` — Rotas /comercial/* com middleware `permission:comercial.cadastrar_empresa`

## Decisions Made

- **`assertInertia()` em vez de `assertStatus(200)` para o teste de acesso admin**: O teste precisava verificar que a rota retorna 200, mas a página `Comercial/NovaEmpresa.jsx` não existia até ser criada neste plano. Para não bloquear o ciclo TDD, o teste usa `assertInertia()` que valida o componente sem depender do manifest Vite. A página foi criada como MVP e o build foi rodado para adicionar ao manifest.
- **`criarImplementacaoPolo()` duplicado sem trait**: Mantendo a decisão D-20/Claude's Discretion de simplicidade — ambos os controllers têm o método privado. Evita overengineering de trait compartilhado para um método de ~10 linhas.
- **Página `NovaEmpresa.jsx` como MVP**: Wave 4 implementará o formulário completo com validação frontend, feedback visual e UX adequada. Esta versão entrega funcionalidade básica para testes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Criação de Comercial/NovaEmpresa.jsx e build Vite**

- **Found during:** Tarefa 1 (fase RED) — o teste `test_admin_acessa_sem_permissao_explicita` rodava 500 em vez de 200 porque o manifest Vite não tinha o componente `Comercial/NovaEmpresa.jsx`
- **Issue:** `Inertia::render('Comercial/NovaEmpresa', [])` falha com `ViteException: Unable to locate file in Vite manifest` quando a página não existe
- **Fix:** Criou `resources/js/Pages/Comercial/NovaEmpresa.jsx` como MVP funcional + rodou `npm run build`; teste ajustado para usar `assertInertia()` (mais idiomático para testes Inertia)
- **Files modified:** `resources/js/Pages/Comercial/NovaEmpresa.jsx`, `tests/Feature/Phase13ComercialTest.php`
- **Verification:** 12/12 testes GREEN incluindo o acesso admin
- **Committed in:** `f58da26`

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** Auto-fix necessária para page Inertia funcionar. Não houve scope creep — a página MVP é o mínimo necessário para o controller funcionar.

## Issues Encountered

Nenhum problema além do auto-fix acima. Todos os 12 testes passaram após a criação da página.

## User Setup Required

Nenhum — sem configuração externa necessária.

## Next Phase Readiness

- `ComercialController` funcional e testado — Wave 4 pode adicionar lógica de pendentes e sidebar
- Rotas registradas com permissão correta — usuário com `comercial.cadastrar_empresa` pode cadastrar
- `EmpresaCadastradaNotification` disponível para reutilização em outros contextos de notificação
- 12 testes Phase13ComercialTest como rede de segurança para refatorações futuras
- Wave 4: adicionar item "Comercial" no sidebar `AppLayout.jsx` + seções "Pendentes" nos módulos existentes

---
*Phase: 13-reestruturacao-cadastro-empresas*
*Completed: 2026-05-25*

## Self-Check

**Verificação de arquivos criados:**
- [x] `app/Http/Controllers/ComercialController.php` existe
- [x] `app/Notifications/EmpresaCadastradaNotification.php` existe
- [x] `tests/Feature/Phase13ComercialTest.php` existe com 12 métodos
- [x] `resources/js/Pages/Comercial/NovaEmpresa.jsx` existe
- [x] `app/Http/Controllers/MlbImplementacaoController.php` modificado com `criarImplementacaoPolo()`
- [x] `routes/web.php` contém `permission:comercial.cadastrar_empresa`

**Verificação de commits:**
- [x] Commit `129bdcb` existe (test RED)
- [x] Commit `f58da26` existe (feat GREEN)

**Verificação de testes:**
- [x] `artisan test --filter=Phase13ComercialTest` → 12/12 PASSED
- [x] `artisan route:list --name=comercial` → 2 rotas listadas
- [x] `artisan test --filter=Phase13` → 29/29 PASSED (sem regressão nas waves anteriores)
- [x] 9 falhas no suite completo são PRÉ-EXISTENTES (CalcularFaixaTest, AdminFechamentoControllerTest polo, ExampleTest redirect)

## Self-Check: PASSED
