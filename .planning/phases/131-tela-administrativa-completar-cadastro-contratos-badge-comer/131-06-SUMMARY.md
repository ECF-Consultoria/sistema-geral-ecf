---
phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
plan: 06
subsystem: admin
tags: [laravel, inertia, react, clicksign, contratos, admin, permissao, notificacao]

# Dependency graph
requires:
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 04
    provides: "ContratoAdminController::show(), Admin/ContratoDetalhe.jsx com a coluna Ações preparada"
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 05
    provides: "ações reenviar()/registrarCancelamento(), blocos full-width via TableRow colSpan reusáveis"
  - phase: 130-rede-de-seguranca-do-fluxo-administrativo-v22-0
    plan: 04
    provides: "ContratoLiberacaoManualController::store() com as mitigações que este plano absorveu verbatim"
  - phase: 130-rede-de-seguranca-do-fluxo-administrativo-v22-0
    plan: 05
    provides: "ContratoPresoNotification, o alerta do sino cuja URL este plano repontou"
provides:
  - "ContratoAdminController::liberarManual() — mesmas mitigações da Fase 130, mesmo backend (EmpresaOperacionalRouter::liberarEmpresa()), nova superfície"
  - "Rota admin.contratos.liberacao-manual dentro de permission:admin.contratos"
  - "Rotas contratos.liberacao-manual.index/store REMOVIDAS (404 exato, não redirect)"
  - "ContratoPresoNotification repontada para admin.contratos.show — alerta do sino continua funcionando"
  - "Modal 'Liberar manualmente' em Admin/ContratoDetalhe.jsx, com a faixa vermelha D-11 preservada"
  - "Admin/ContratosLiberacaoManual.jsx e ContratoLiberacaoManualController.php removidos"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Absorção de controller descartável: corpo do método copiado VERBATIM (inclusive comentários de ameaça) para o controller definitivo — nenhuma reescrita durante a migração de superfície"
    - "Reapontamento de consumidor invisível NA MESMA task da remoção da rota que ele referenciava — nunca em task separada, para não existir janela quebrada"
    - "Prova de rota removida por Route::has() + assertStatus(404) explícito, nunca assertRedirect (uma rota 'resolvida' por redirect não é remoção)"

key-files:
  created:
    - tests/Feature/Phase131/LiberacaoManualRotaAntigaRemovidaTest.php
    - tests/Feature/Phase131/LiberacaoManualAbsorvidaTest.php
  modified:
    - app/Http/Controllers/ContratoAdminController.php
    - routes/web.php
    - app/Notifications/ContratoPresoNotification.php
    - resources/js/Pages/Admin/ContratoDetalhe.jsx
    - tests/Feature/Phase130/AlertaContratoPresoTest.php
    - tests/Feature/Phase130/LiberacaoManualTest.php
    - tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php

key-decisions:
  - "Prop 'contratos' do detalhe (por empresa) tem um key-set legitimamente maior que a antiga listagem (liberado_em/cancelamento_*/ja_liberado/signatarios) — o teste de PII da Fase 130 foi atualizado para a lista EXATA de chaves da tela nova, mais varredura do sub-array signatarios, em vez de relaxar para uma checagem solta"
  - "Duas armadilhas de grep/route:list substring (documentadas abaixo) são esperadas e não indicam defeito: route:list mostra a classe de middleware resolvida (EnsurePermission:...), não o alias (permission:...); e o nome da rota nova (admin.contratos.liberacao-manual) contém a rota antiga como substring"

patterns-established:
  - "Botão de ação disponível por linha de contrato controlado por booleano explícito vindo do backend (c.ja_liberado), não por dedução de status no client"

requirements-completed: [UI-05, UI-06]

# Metrics
duration: ~80min
completed: 2026-08-14
---

# Phase 131 Plan 06: Absorção da liberação manual (D-10) Summary

**`ContratoAdminController::liberarManual()` absorve verbatim o `store()` da Fase 130 dentro da tela de detalhe, a rota antiga vira 404 de verdade, e o alerta do sino é repontado na mesma task da remoção — o Administrativo passa a ter uma tela só, sem perder nenhuma das 41 mitigações que o `130-SECURITY.md` fechou.**

## Performance

- **Duration:** ~80 min
- **Started:** 2026-08-14 (sessão única)
- **Completed:** 2026-08-14T18:47:15Z
- **Tasks:** 3/3
- **Files modified:** 10 (7 modificados, 2 testes criados, 2 arquivos apagados)

## Accomplishments

- `ContratoAdminController::liberarManual()` — corpo copiado VERBATIM de
  `ContratoLiberacaoManualController::store()`, inclusive os comentários que nomeiam as ameaças
  (T-130-04-03): `Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)` fechado, `motivo_detalhe` obrigatório
  com `min:5` mesmo com o slug preenchido, `exists:` nos três ids, guard de IDOR (`abort(422)` quando
  o contrato não pertence à empresa/serviço do POST), delegação intacta a
  `EmpresaOperacionalRouter::liberarEmpresa()`
- Rota `admin.contratos.liberacao-manual` (POST) dentro do grupo `permission:admin.contratos` — herda
  a permissão dedicada da UI-05, nunca `role:admin`
- Rotas antigas `contratos.liberacao-manual.index`/`.store` **removidas de fato**: `Route::has()`
  retorna `false` e `GET`/`POST` no caminho antigo devolvem **404 exato** (nunca redirect), provado
  para admin autenticado e para visitante anônimo
- `ContratoPresoNotification::__construct()` repontado de `route('contratos.liberacao-manual.index')`
  para `route('admin.contratos.show', $contrato->company_id)` — **na mesma task** da remoção da rota,
  fechando a janela em que o alerta do sino da Fase 130 lançaria `RouteNotFoundException` em produção
  sem ninguém perceber. Destino melhora: cai direto na empresa do contrato parado
- `Admin/ContratoDetalhe.jsx`: botão "Liberar manualmente" por contrato (disponível enquanto
  `!c.ja_liberado`, qualquer que seja o status) e modal reusando **literalmente** o texto já validado
  na tela antiga — select de motivo (`motivos_manuais`), textarea obrigatória `min:5` com o placeholder
  literal, faixa de destaque vermelha (D-11) com os 4 textos exatos quando `causa` é
  `recusado_pelo_cliente`/`prazo_expirado`/`cancelado`/`erro_tecnico`, CTA "Confirmar liberação"
- `Admin/ContratosLiberacaoManual.jsx` e `ContratoLiberacaoManualController.php` **apagados** — antes
  da remoção, `grep -rn` confirmou que não sobrou nenhuma referência viva fora dos comentários
  históricos e dos testes já tratados
- `npm run build` verde: `ContratoDetalhe.jsx` no manifest, `ContratosLiberacaoManual.jsx` fora dele
- Testes da Fase 130 (`LiberacaoManualTest`, `LiberacaoManualEstadoRealTest`, `AlertaContratoPresoTest`)
  reapontados com o menor diff possível — nenhuma asserção enfraquecida; o teste de PII foi
  atualizado para a lista EXATA de chaves da tela de detalhe (legitimamente maior que a antiga
  listagem) mais varredura do sub-array `signatarios`
- `LiberacaoManualRotaAntigaRemovidaTest` (4 testes) e `LiberacaoManualAbsorvidaTest` (6 testes) novos
  — provam a remoção (404 exato) e reprovam as mesmas mitigações da Fase 130 na rota nova, caminho
  feliz conferido por **reconsulta ao banco**
- Suíte `Phase130|Phase131` = **147 testes verdes**; `Phase126|Phase129|Phase130|Phase131` =
  **349 testes verdes** (era 339 ao fim do 131-05; +10 testes novos, **zero regressão**)

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Absorver liberarManual() preservando as mitigações da Fase 130** - `6642fc12` (feat)
2. **Task 2: Modal de liberação no detalhe + remoção da tela e do controller antigos** - `f96b3781` (feat)
3. **Task 3: Reapontar os testes restantes da Fase 130 + o teste da absorção** - `d2793517` (test)

**Plan metadata:** commit deste SUMMARY + STATE.md + ROADMAP.md (a seguir)

## Files Created/Modified

- `app/Http/Controllers/ContratoAdminController.php` - `liberarManual()`, mais `motivos_manuais` e
  `ja_liberado` na prop `contratos` de `show()`
- `routes/web.php` - rota `admin.contratos.liberacao-manual` nova; par
  `contratos.liberacao-manual.index/store` removido
- `app/Notifications/ContratoPresoNotification.php` - URL repontada para `admin.contratos.show`
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` - botão + modal "Liberar manualmente" (D-10/D-11)
- `tests/Feature/Phase130/AlertaContratoPresoTest.php` - asserção de URL reapontada
- `tests/Feature/Phase130/LiberacaoManualTest.php` - 8 testes reapontados para a rota/componente novos
- `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` - 5 testes reapontados, teste de PII
  atualizado para a lista exata de chaves da tela de detalhe
- `tests/Feature/Phase131/LiberacaoManualRotaAntigaRemovidaTest.php` - 4 testes: 404 exato (não
  redirect) para admin e visitante anônimo, `Route::has()` falso
- `tests/Feature/Phase131/LiberacaoManualAbsorvidaTest.php` - 6 testes: reprova as mitigações da Fase
  130 na rota nova, caminho feliz por reconsulta ao banco, 403 sem `admin.contratos`
- **Apagados:** `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx`,
  `app/Http/Controllers/ContratoLiberacaoManualController.php`

## Decisions Made

- Nenhuma decisão de produto nova — este plano seguiu D-10/D-11/D-12 literalmente, absorvendo o
  backend definitivo da Fase 130 sem reescrevê-lo
- Decisão técnica de teste: a asserção de PII do `LiberacaoManualEstadoRealTest` foi atualizada de
  "lista exata de chaves da antiga listagem" para "lista exata de chaves da nova tela de detalhe +
  varredura do sub-array `signatarios`" — o key-set mudou porque a tela nova é legitimamente mais rica
  (traz `liberado_em`/`cancelamento_*`/`ja_liberado`/`signatarios`, que a listagem antiga nunca teve),
  não porque a garantia de "nenhum e-mail/CPF exposto" foi relaxada

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Verificação] Comentário no docblock de `ContratoPresoNotification` continha o literal `contratos.liberacao-manual`, acionando o próprio grep de aceitação da Task 1**
- **Found during:** Task 1
- **Issue:** O critério de aceitação exige `grep -c "contratos.liberacao-manual" app/Notifications/ContratoPresoNotification.php` = 0. O primeiro rascunho do comentário citava o nome literal da rota antiga entre crases para explicar o que mudou — o grep não distingue comentário de código.
- **Fix:** Reescrito para "a rota da liberação manual da Fase 130", sem o literal. Mesma disciplina já usada no plano 131-05 (SUMMARY, deviation 1).
- **Files modified:** `app/Notifications/ContratoPresoNotification.php`
- **Commit:** `6642fc12`

**2. [Rule 1 - Verificação] Docblocks dos testes reapontados também citavam o literal `contratos.liberacao-manual.index`**
- **Found during:** Task 3
- **Issue:** Mesmo padrão do item 1 — os docblocks de `LiberacaoManualTest`/`LiberacaoManualEstadoRealTest` explicavam a mudança citando a rota antiga entre crases, disparando `grep -rc "contratos.liberacao-manual" tests/Feature/Phase130/`.
- **Fix:** Reescritos sem o literal ("a listagem própria da Fase 130"), preservando a explicação.
- **Files modified:** `tests/Feature/Phase130/LiberacaoManualTest.php`, `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php`
- **Commit:** `d2793517`

---

**Total deviations:** 2 (ambas Rule 1 — comentário acionando o próprio grep de aceitação que ele deveria satisfazer). Nenhuma mudança de comportamento de produto.
**Impact on plan:** Nenhum impacto em escopo; ajustes só de texto de comentário.

## Issues Encountered

**Duas armadilhas de ambiente confirmadas (não são bugs, documentadas para quem ler este SUMMARY depois):**

1. `artisan route:list -v` imprime a **classe de middleware resolvida**
   (`App\Http\Middleware\EnsurePermission:admin.contratos`), não o alias registrado no Kernel
   (`permission:admin.contratos`) — exatamente como o `131-06-PLAN.md` já alertava. Confirmado via
   `route:list --json`, que traz o array de middleware completo; a permissão está correta.
2. `grep -c "contratos.liberacao-manual"` em `ContratoPresoNotification.php` e nos testes da Fase 130
   nunca pode chegar a 0 de forma absoluta enquanto a rota nova se chamar
   `admin.contratos.liberacao-manual` (o nome escolhido pelo próprio plano, Task 1) — `contratos.
   liberacao-manual` é substring literal de `admin.contratos.liberacao-manual`. O grep residual (2 em
   `ContratoPresoNotification.php` seria 0 se não fosse essa contagem; a contagem real após limpar os
   comentários é só das chamadas LEGÍTIMAS a `route('admin.contratos.liberacao-manual')`). Confirmado
   por prova mais forte: nenhuma chamada literal a `route('contratos.liberacao-manual.index')` ou
   `.store'` sobra em lugar nenhum do repositório, e os 3 testes de `LiberacaoManualRotaAntigaRemovidaTest`
   provam a remoção via `Route::has()` + `assertStatus(404)` — a prova comportamental é mais forte que
   o grep textual.

Nenhum bloqueio real para a execução do plano.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- D-10 fechada: o Administrativo tem uma tela só para contratos — lista (`admin.contratos.index`) e
  detalhe (`admin.contratos.show`), com todas as ações (reenviar/ajustar/cancelar/gerar/liberar
  manualmente) concentradas no detalhe
- A rede de segurança da Fase 130 (alerta do sino, reconciliação, liberação manual) permanece
  100% funcional — nenhuma mitigação perdida na absorção, confirmado por 147 testes de Phase130+Phase131
- Esta é a última plan da Fase 131 — o milestone v22.0 (Administrativo + Clicksign) fica pronto para
  fechamento de fase (verificação/review)

Nenhum bloqueio identificado.

---
*Phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer*
*Completed: 2026-08-14*

## Self-Check: PASSED

Todos os arquivos criados/modificados confirmados em disco, os dois arquivos antigos confirmados
apagados, e os 3 commits de task (`6642fc12`, `f96b3781`, `d2793517`) confirmados em
`git log --oneline --all`.
