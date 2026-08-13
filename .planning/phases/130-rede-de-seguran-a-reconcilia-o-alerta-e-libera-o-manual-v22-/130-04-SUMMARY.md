---
phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-
plan: 04
subsystem: web
tags: [laravel, inertia, react, admin, contratos, clicksign]

# Dependency graph
requires:
  - phase: 130-01
    provides: "ContratoLiberacao::VIA_RECONCILIACAO, MOTIVOS_MANUAIS, MOTIVOS_MANUAIS_LABELS, motivo_slug, EmpresaOperacionalRouter::liberarEmpresa(motivoSlug:)"
  - phase: 130-02
    provides: "ContratosPresosService::listar()/causa()/diasParado() -- o recorte de 7 estados compartilhado com o alerta"
  - phase: 130-03
    provides: "ContratoLiberacao::VIA_RECONCILIACAO em uso real, prova de que lockDaEmpresa() cobre reconciliacao"
provides:
  - "ContratoLiberacaoManualController::index()/store() -- rota so-admin que libera empresa ao operacional com motivo obrigatorio"
  - "resources/js/Pages/Admin/ContratosLiberacaoManual.jsx -- tela minima e descartavel (D-10)"
  - "Prova de teste do SC4 para os pares manual x webhook e manual x reconciliacao (LiberacaoManualCorridaTest)"
affects: [130-05-alerta-contrato-preso, 131-permissao-admin-contratos]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Controller fino so-admin (index+store) reusando ContratosPresosService e EmpresaOperacionalRouter::liberarEmpresa() sem nenhuma logica nova de idempotencia/lock"
    - "Teste de corrida por decorator de lockDaEmpresa() (mesma tecnica de LiberarEmpresaCorridaConcorrenteTest da Fase 129), reaplicado para provar cobertura de novos pares de origem"

key-files:
  created:
    - app/Http/Controllers/ContratoLiberacaoManualController.php
    - resources/js/Pages/Admin/ContratosLiberacaoManual.jsx
    - tests/Feature/Phase130/LiberacaoManualTest.php
    - tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php
    - tests/Feature/Phase130/LiberacaoManualCorridaTest.php
  modified:
    - routes/web.php

key-decisions:
  - "Nenhuma decisao nova de dominio -- todas as decisoes (D-10/D-11/D-12) ja estavam travadas no PLAN.md e no 130-PATTERNS.md; a unica escolha livre foi de forma (mensagens de faixa vermelha por causa, cores/copy da tela)"

patterns-established:
  - "A tela de liberacao manual usa o MESMO ContratosPresosService::listar() do alerta -- nunca um recorte proprio, para nao divergir do que a Fase 130-05 mostra"

requirements-completed: [REDE-03, DADOS-05]

# Metrics
duration: ~20min
completed: 2026-08-13
---

# Phase 130 Plano 04: Rede de segurança — liberação manual Summary

**Rota `role:admin` (`ContratoLiberacaoManualController::index()/store()`) que libera uma empresa presa para o operacional via `EmpresaOperacionalRouter::liberarEmpresa(VIA_MANUAL)`, com motivo obrigatório (slug + detalhe), autor gravado, e tela React descartável que destaca em vermelho quando o contrato foi recusado/expirou/foi cancelado/deu erro antes de deixar confirmar**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-13T16:10:00Z (aprox.)
- **Completed:** 2026-08-13T16:30:00Z (aprox.)
- **Tasks:** 3 completadas
- **Files modified:** 6 (5 criados, 1 modificado)

## Accomplishments

- `ContratoLiberacaoManualController` — `index()` monta a lista de empresas presas (o MESMO recorte largo de 7 estados de `ContratosPresosService`, não o recorte estreito da reconciliação) num array achatado sem dado de signatário; `store()` valida `motivo_slug` com `Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)` e `motivo_detalhe` obrigatório mesmo com slug preenchido (D-12), verifica que um `contrato_assinatura_id` informado pertence à mesma empresa/serviço do POST (T-130-04-03), e libera chamando `EmpresaOperacionalRouter::liberarEmpresa()` com `VIA_MANUAL` + `liberadoPorUserId` + `motivo` + `motivoSlug` — sem nenhum lock, guard de idempotência ou chamada ao gate automático próprios (D-11).
- Duas rotas `role:admin` (`contratos.liberacao-manual.index`/`.store`), isoladas do menu do `AppLayout` e registradas logo abaixo do bloco do PDF assinado da Fase 129, mesma disciplina de comentário de intenção sobre a trava ser correta até a Fase 131 (D-10).
- `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` — tela mínima e descartável: lista as empresas presas com a causa traduzida em linguagem simples, exige seleção de motivo (`select` alimentado pela prop `motivos`, nunca lista hardcoded) + detalhe (`textarea` obrigatória), e mostra uma faixa vermelha obrigatória (D-11) quando o contrato selecionado está `recusado`/`expirado`/`cancelado`/`erro`, com texto explicando que a liberação ainda é possível mas fica registrada com autor e motivo. `npm run build` confirmado — a página aparece em `public/build/manifest.json`.
- `LiberacaoManualCorridaTest` — prova (não reimplementação) de que o `Cache::lock()` já herdado de `EmpresaOperacionalRouter::lockDaEmpresa()` cobre os pares manual×webhook (serviços diferentes e mesmo serviço) e manual×reconciliação, adaptando o decorator de `LiberarEmpresaCorridaConcorrenteTest` da Fase 129. Nenhum `Cache::lock()` novo foi escrito.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Controller + rotas só-admin com motivo obrigatório (D-10, D-11, D-12, DADOS-05)** - `808a7263` (feat)
2. **Task 2: Tela mínima com destaque do estado real antes de confirmar (D-10, D-11)** - `b1d19a95` (feat)
3. **Task 3: Prova do SC4 — corrida manual × webhook não duplica MlbEmpresa** - `7beff60d` (test)

## Files Created/Modified

- `app/Http/Controllers/ContratoLiberacaoManualController.php` - `index()`/`store()`, docblock explícito sobre `role:admin` ser a trava correta até a Fase 131 e sobre D-11 (não chamar o gate)
- `routes/web.php` - duas rotas `contratos.liberacao-manual.index`/`.store` sob `['auth', 'verified', 'role:admin']`
- `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` - lista de empresas presas, faixa de destaque vermelha condicional, formulário motivo+detalhe, botão desabilitado até os dois campos estarem preenchidos
- `tests/Feature/Phase130/LiberacaoManualTest.php` - 8 testes: 401/403 em GET/POST, 200 com prop `contratos`, 422 sem detalhe, 422 com slug inválido, gravação de autor+motivo+slug, idempotência no reenvio, 422 com contrato de outra empresa (IDOR)
- `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` - 5 testes: props `recusado`/`erro` distintos, `motivos` com as 4 chaves rotuladas, liberação sucede em contrato recusado (D-11 ponta a ponta), nenhuma chave de signatário exposta na prop `contratos`
- `tests/Feature/Phase130/LiberacaoManualCorridaTest.php` - 3 testes: manual×webhook em serviços diferentes (1 `MlbEmpresa`), webhook×manual no mesmo serviço (1 `ContratoLiberacao`, via `cl_empresa_servico_uniq`), reconciliação×manual (1 `MlbEmpresa`)

## Decisions Made

Nenhuma decisão nova de domínio — todas as regras (D-10/D-11/D-12) já estavam travadas no `130-04-PLAN.md` e no `130-PATTERNS.md`. As únicas escolhas livres foram de forma na tela: os textos de destaque por causa (`CAUSA_DESTAQUE_TEXTO`) e a cor/ícone da faixa de aviso, seguindo o mesmo padrão `border-red-500/30 bg-red-500/[0.06] text-red-300` já especificado no plano.

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered

Duas fixtures dos testes de `LiberacaoManualEstadoRealTest` (contrato `recusado` e contrato `erro`) precisaram envelhecer `updated_at` via `forceFill(['updated_at' => now()->subDays(10)])->save()` para aparecer em `ContratosPresosService::listar()` — a coluna não é `$fillable` de propósito (T-125-01) e `dataBase()` usa `updated_at` como fallback para esses dois estados. Não é um desvio do plano, é o comportamento correto do serviço (já provado em `ContratosPresosServiceTest` do plano 130-02); só documentando a técnica usada para não confundir com um bug.

Tentei rodar `php artisan test` (suíte completa, item 4 da `<verification>` do plano) para confirmar zero regressão além de `Phase129`/`Phase130`. A suíte completa estourou o limite de 300s dentro de `MercadoLivreAdsService.php:215` (Sugadores/Adman), um serviço pré-existente e completamente fora do escopo deste plano (nenhum arquivo tocado por ele). Não é uma regressão introduzida aqui — é uma limitação conhecida do ambiente local (suíte muito grande + chamada real/lenta em um serviço não relacionado). `Phase130` (57/57) e `Phase129` (80/80, reconfirmado nesta execução) continuam a régua real de verificação deste plano.

`requirements.mark-complete REDE-03 DADOS-05` devolveu `not_found` — mesma limitação já registrada pelo plano 130-01 (os IDs vivem em `.planning/REQUIREMENTS-v22.md`, não no `REQUIREMENTS.md` raiz). Segui o mesmo precedente: não editei os checkboxes à mão neste plano — a fase 130 ainda tem 3 planos abertos (05/06/07) e os checkboxes devem ser marcados manualmente quando a fase fechar.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Rota `contratos.liberacao-manual.index`/`.store` pronta para ser linkada pela tela de alerta do plano 130-05 (D-10 menciona "o caminho de entrada é o link do alerta").
- Backend (`ContratoLiberacaoManualController`) é definitivo; a tela React é deliberadamente descartável para a Fase 131 reescrever e trocar `role:admin` por `admin.contratos` — rota, middleware e página ficam isolados, sem entrada no menu do `AppLayout` e sem componente compartilhado novo, então a troca é barata.
- SC4 provado para os três pares de origem (webhook, manual, reconciliação) — nenhum mecanismo de lock precisa ser tocado pelos próximos planos.
- Suíte `Phase130` completa: 57/57 testes verdes (41 herdados dos planos 01/02/03 + 16 novos deste plano).
- Nenhum bloqueio conhecido para o plano 130-05.

## Self-Check: PASSED

Os 5 arquivos criados (`ContratoLiberacaoManualController.php`, `ContratosLiberacaoManual.jsx`, `LiberacaoManualTest.php`, `LiberacaoManualEstadoRealTest.php`, `LiberacaoManualCorridaTest.php`) foram confirmados no disco e os 3 commits de task (`808a7263`, `b1d19a95`, `7beff60d`) foram confirmados em `git log`. `C:\xampp\php\php.exe artisan test --filter=Phase130` roda verde (57 testes, 198 assertions). `npm run build` concluído e `public/build/manifest.json` contém `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx`. `C:\xampp\php\php.exe artisan route:list --name=contratos.liberacao-manual` lista as 2 rotas com `role:admin`.

---
*Phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-*
*Completed: 2026-08-13*
