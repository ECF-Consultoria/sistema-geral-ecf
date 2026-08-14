---
phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
plan: 05
subsystem: admin
tags: [laravel, inertia, react, clicksign, contratos, admin, tailwind]

# Dependency graph
requires:
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 01
    provides: "config('services.clicksign.painel_url') derivada de CLICKSIGN_ENV, colunas cancelamento_motivo/cancelamento_solicitado_por_user_id/cancelamento_solicitado_em em contrato_assinaturas"
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    plan: 04
    provides: "ContratoAdminController::show()/atualizarCadastro()/gerarContrato(), Admin/ContratoDetalhe.jsx com a coluna Ações preparada e vazia, prop contratos[].signatarios achatada"
provides:
  - "ContratoAdminController::reenviar() (CLICK-07) — reenvia o aviso de assinatura, trata 429 como resposta esperada em canal 'aviso'"
  - "ContratoAdminController::registrarCancelamento() (CLICK-10/D-13) — grava autor+motivo+data sem chamar a Clicksign, status intacto"
  - "Flash 'aviso' aditivo em HandleInertiaRequests — canal neutro/âmbar distinto de success/error"
  - "Prop painel_clicksign_url em show(), consumida pelo CTA 'Registrar e ir para a Clicksign'"
  - "Admin/ContratoDetalhe.jsx completo: reenviar por pessoa pendente, RAMO B (Ajustar), modal de cancelamento, aviso persistente de cancelamento solicitado, estado erro (D-05)"
affects: [131-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guards de IDOR/estado ANTES de qualquer I/O externo — mesmo molde de ContratoLiberacaoManualController::store() (T-131-05-01/02)"
    - "429 tratado como resposta ESPERADA (canal 'aviso'), nunca como erro — distinção que evita a tela mentir sobre o motivo da recusa"
    - "Registro de intenção sem execução real quando a API não permite a ação (D-13) — grava autor/motivo/data, instrui o próximo passo manual, nunca finge sucesso"
    - "Distinção 1ª/2ª falha por sessão de navegação (React local state), sem coluna nova no banco — reseta ao recarregar, aceitável porque o objetivo é orientar dentro da MESMA sessão de trabalho"
    - "CTA que promete navegação só abre a aba DEPOIS que o POST responde com sucesso, e degrada o rótulo quando a config vem vazia — nunca promete destino que não confirma"

key-files:
  created:
    - tests/Feature/Phase131/ContratoAdminReenviarTest.php
    - tests/Feature/Phase131/ContratoAdminCancelarTest.php
    - tests/Feature/Phase131/ContratoAdminAjustarSignatarioTest.php
  modified:
    - app/Http/Controllers/ContratoAdminController.php
    - routes/web.php
    - app/Http/Middleware/HandleInertiaRequests.php
    - resources/js/Pages/Admin/ContratoDetalhe.jsx

key-decisions:
  - "gerúndio/estado erro sem coluna nova: 'primeira falha' vs 'falha após tentar de novo' (D-05) rastreado em useState local, não persistido — o contrato de retorno do backend não muda, e recarregar a página volta ao estado 'primeira falha' (aceitável: o objetivo é orientar na mesma sessão de trabalho, não auditar tentativas)"
  - "'Ver detalhes técnicos' (2ª falha) mostra o flash.error genérico já produzido por gerarContrato() (T-131-04-05) — nunca a mensagem crua da Clicksign, que o backend já não expõe"
  - "docblocks reescritos para não conter literalmente 'cancelarEnvelope'/'webhook' — os greps de aceitação da fase são literais e não distinguem comentário de texto visível; reescrito sem perder a explicação técnica"

patterns-established:
  - "Ações por signatário (reenviar/ajustar) dentro da MESMA linha de contrato da tabela, via lista compacta filtrada por situacao==='pendente' — evita reestruturar a tabela para uma linha por pessoa"
  - "Blocos de estado full-width (cancelamento solicitado, erro) como TableRow adicional com colSpan, logo abaixo da linha do contrato — mantém a tabela como fonte única de verdade por contrato"

requirements-completed: [CLICK-07, CLICK-09, CLICK-10, UI-04, UI-06]

# Metrics
duration: ~90min
completed: 2026-08-14
---

# Phase 131 Plan 05: Ações do contrato — reenviar, ajustar e registrar cancelamento Summary

**`ContratoAdminController::reenviar()`/`registrarCancelamento()` mais os blocos correspondentes em `Admin/ContratoDetalhe.jsx` — as três ações do contrato exatamente como a API v3 da Clicksign permite: reenvio funcional com 429 tratado como resposta esperada, "Ajustar" que explica a impossibilidade de corrigir e-mail sem inventar uma bifurcação inexistente, e cancelamento que registra autor+motivo+data sem fingir que o sistema cancela.**

## Performance

- **Duration:** ~90 min
- **Started:** 2026-08-14 (sessão única)
- **Completed:** 2026-08-14
- **Tasks:** 3/3
- **Files modified:** 7 (4 modificados, 3 testes criados)

## Accomplishments

- `ContratoAdminController::reenviar()` (CLICK-07): 4 guards antes de qualquer I/O (IDOR do
  signatário, estado do contrato, situação do signatário, envelope/signer key presentes), chama
  `ClicksignClient::reenviarNotificacao()` e trata `httpStatus === 429` como resposta ESPERADA
  (`session('aviso')`, nunca `error`) — qualquer outro erro vira mensagem genérica, nunca a resposta
  crua da API
- `ContratoAdminController::registrarCancelamento()` (CLICK-10/D-13): valida `motivo` (min:10),
  guards de estado (só contrato vivo) e de registro duplicado, grava as 3 colunas via `fill()+save()`
  no model (nunca query builder — preserva o hook `saving`) e **nunca chama o client da Clicksign**
  — zero requisição HTTP sai desta action, provado por `Http::assertNothingSent()`
- Prop `painel_clicksign_url` em `show()`, vinda de `config('services.clicksign.painel_url')`
  (declarada no plano 131-01) — nenhuma URL literal no controller nem no JSX
- Flash `aviso` aditivo em `HandleInertiaRequests` — as 4 chaves antigas (`success`/`error`/
  `nps_link`/`workspace_url`) permanecem intactas
- `Admin/ContratoDetalhe.jsx`: por pessoa pendente (só em `aguardando_assinaturas`), botões
  "Reenviar aviso" (desabilita por 8s após o clique) e "Ajustar" (abre direto o RAMO B — sem
  bifurcação, D-14); "Registrar cancelamento" com textarea obrigatória (min 10) cujo CTA confirma
  **registra e só depois** abre o painel em nova aba — com o rótulo degradando para "Registrar
  cancelamento" se `painel_clicksign_url` vier vazio; aviso persistente âmbar quando
  `cancelamento_solicitado_em` está preenchido; estado `erro` (D-05) com "Tentar novamente" na
  primeira falha e "Continua sem enviar" + detalhes técnicos recolhidos na segunda (rastreado por
  sessão de navegação, sem coluna nova)
- `npm run build` verde, `Admin/ContratoDetalhe.jsx` confirmado no manifest do Vite
- 13 testes novos: `ContratoAdminReenviarTest` (corpo JSON:API + 429 como aviso + 3 guards),
  `ContratoAdminCancelarTest` (caminho feliz por reconsulta + sem chamada à API + motivo curto +
  registro duplicado + contrato terminal), `ContratoAdminAjustarSignatarioTest` (trava que impede
  reintroduzir "corrigir e-mail" — nenhuma rota, nenhuma chave `email` na prop)
- Suíte `Phase126|Phase129|Phase130|Phase131` = **339 testes verdes** (era 326 ao fim do 131-04;
  +13 testes novos, zero regressão)

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: actions reenviar()/registrarCancelamento() + rotas + flash `aviso` + URL do painel** - `356faca7` (feat)
2. **Task 2: Ações, modais e estados na tela de detalhe** - `b4768340` (feat)
3. **Task 3: Testes do reenvio (corpo + 429), do RAMO B e do cancelamento sem chamada à API** - `a24d8f6d` (test)

**Plan metadata:** commit deste SUMMARY + STATE.md + ROADMAP.md (a seguir)

## Files Created/Modified

- `app/Http/Controllers/ContratoAdminController.php` - `reenviar()` e `registrarCancelamento()`,
  mais a prop `painel_clicksign_url` em `show()`
- `routes/web.php` - rotas `admin.contratos.reenviar`/`admin.contratos.cancelamento`, dentro do
  grupo `permission:admin.contratos` existente
- `app/Http/Middleware/HandleInertiaRequests.php` - chave `aviso` aditiva no array `flash`
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` - ações por signatário, dois modais (RAMO B e
  cancelamento), aviso persistente, bloco de estado `erro`
- `tests/Feature/Phase131/ContratoAdminReenviarTest.php` - 5 testes: corpo JSON:API, 429 como
  aviso, IDOR, estado errado, signatário que já respondeu
- `tests/Feature/Phase131/ContratoAdminCancelarTest.php` - 5 testes: caminho feliz por reconsulta,
  sem chamada à API isolada, motivo curto, registro duplicado, contrato terminal
- `tests/Feature/Phase131/ContratoAdminAjustarSignatarioTest.php` - 3 testes: nenhuma rota de
  correção de e-mail, prop de signatários sem `email`, reforço por convenção de nome de rota

## Decisions Made

- Nenhuma decisão de produto nova além das já travadas pelo CONTEXT/UI-SPEC/PATTERNS — este plano
  seguiu D-05/D-06/D-07/D-13/D-14 literalmente, incluindo o texto de copy exato do UI-SPEC
- Decisões técnicas de execução: distinção 1ª/2ª falha rastreada em `useState` local (sem coluna
  nova, ver Deviations), e docblocks reescritos para não conter as palavras que os próprios greps
  de aceitação da fase proíbem (ver Deviations)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Verificação] Docblocks continham `cancelarEnvelope`/`webhook`, acionando os próprios greps de aceitação**
- **Found during:** Task 1 e Task 2
- **Issue:** O critério de aceitação da Task 1 exige `grep -c "cancelarEnvelope" app/Http/Controllers/ContratoAdminController.php` = 0, e o da Task 2 exige `grep -ci "envelope|signatário|webhook"` = 0 em `ContratoDetalhe.jsx`. O primeiro rascunho dos docblocks citava
  `ClicksignClient::cancelarEnvelope()` pelo nome (para documentar o que NÃO é chamado) e usava a
  palavra "webhook" em comentário técnico — os greps são literais e não distinguem comentário de
  texto visível ao usuário.
- **Fix:** Reescritos sem perder a explicação técnica: "o método de cancelamento do
  `ClicksignClient` (medido: 403 em `running`)" no lugar do nome literal, e "a confirmação da
  Clicksign" no lugar de "webhook". Confirmado manualmente que nenhum texto visível ao usuário foi
  afetado — a mudança é só de comentário/docblock.
- **Files modified:** `app/Http/Controllers/ContratoAdminController.php`,
  `resources/js/Pages/Admin/ContratoDetalhe.jsx`
- **Commit:** `356faca7` (Task 1), `b4768340` (Task 2)

**2. [Rule 1 - Bug de fixture] `clicksign_envelope_id`/`clicksign_signer_key` fixos colidiam com a coluna UNIQUE em testes com dois contratos**
- **Found during:** Task 3 (escrita do teste de IDOR)
- **Issue:** O helper `contratoComSignatarioPendente()` usava um UUID fixo para
  `clicksign_envelope_id`. O teste de IDOR cria DOIS contratos chamando o helper duas vezes — a
  segunda inserção quebrava a constraint UNIQUE da coluna, um erro de fixture, não do código de
  produção sendo testado.
- **Fix:** Trocado para `Str::uuid()` gerado por chamada, mantendo os `Http::fake()` com wildcard
  (`/envelopes/*/signers/*/...`) intactos.
- **Files modified:** `tests/Feature/Phase131/ContratoAdminReenviarTest.php`
- **Commit:** `a24d8f6d`

**3. [Rule 1 - Bug de fixture] Motivo de teste "muito curto" tinha 11 caracteres — passava a validação `min:10`**
- **Found during:** Task 3
- **Issue:** O primeiro rascunho do teste de motivo curto usava a string `'muito curto'` (11
  caracteres) esperando falha de validação `min:10` — o teste passava silenciosamente pelo motivo
  errado (a validação não disparava porque o texto na verdade era válido).
- **Fix:** Trocado para `'curto'` (5 caracteres), garantindo que o teste falha exatamente pela
  regra que deveria provar.
- **Files modified:** `tests/Feature/Phase131/ContratoAdminCancelarTest.php`
- **Commit:** `a24d8f6d`

---

**Total deviations:** 3 (1 Rule 1 de verificação em dois arquivos, 2 Rule 1 de bug de fixture)
**Impact on plan:** Nenhuma mudança de escopo de produto ou de comportamento do
`ContratoAdminController`/`ContratoDetalhe.jsx` além do que o plano já especificava — todas as três
são ajustes de comentário/fixture necessários para os testes provarem o que deveriam provar.

## Issues Encountered

Nenhum bloqueio real. As três situações acima foram resolvidas dentro da própria execução, sem
precisar de decisão do usuário.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. `CLICKSIGN_PAINEL_URL` já tem default
seguro por ambiente desde o plano 131-01.

## Next Phase Readiness

- As três ações do contrato (CLICK-07/CLICK-09/CLICK-10) estão completas dentro do que a API v3
  permite — nenhuma promessa que a Clicksign não entrega
- A coluna "Ações" da lista de contratos (`Admin/ContratoDetalhe.jsx`) segue com espaço reservado
  para "Liberar manualmente" (plano 131-06, D-10) — não construída aqui
- O padrão de blocos full-width via `TableRow colSpan` fica disponível para o plano 131-06 caso
  precise de um bloco de estado adicional na mesma tabela

Nenhum bloqueio identificado para o próximo plano.

---
*Phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer*
*Completed: 2026-08-14*

## Self-Check: PASSED

Todos os arquivos criados/modificados confirmados em disco e os 3 commits de task (`356faca7`,
`b4768340`, `a24d8f6d`) confirmados em `git log --oneline --all`.
