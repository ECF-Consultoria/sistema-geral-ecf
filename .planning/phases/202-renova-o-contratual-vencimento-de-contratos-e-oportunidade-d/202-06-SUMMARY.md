---
phase: 202-renova-o-contratual-vencimento-de-contratos-e-oportunidade-d
plan: 06
subsystem: renovacao
tags: [renovacao, tela, contratos, inertia, react]
requires: ["202-01", "202-03", "202-04"]
provides:
  - "ContratoAdminController::index com bloco `renovacao` por linha, filtro `renovacao`, props renovacao_resumo e renovacao_config"
  - "ContratoRenovacaoController (sincronizar, dispensar, historico)"
  - "Rotas admin.contratos.renovacao.{sincronizar,dispensar,historico}"
  - "RenovacaoCelula.jsx (situação, links separados, ações, modais)"
key-files:
  created:
    - app/Http/Controllers/ContratoRenovacaoController.php
    - resources/js/Components/Contratos/RenovacaoCelula.jsx
    - tests/Feature/Phase202/ContratosTelaRenovacaoTest.php
  modified:
    - app/Http/Controllers/ContratoAdminController.php
    - routes/web.php
    - resources/js/Pages/Admin/Contratos.jsx
requirements-completed: [REN-06, REN-07, REN-08]
completed: 2026-11-06
---

# Fase 202 Plano 06: Tela de contratos com renovação Summary

A lista de contratos agora mostra término com dias restantes e origem, situação da renovação, links separados para negócio original e de renovação, 8 filtros novos e as ações "Sincronizar novamente", "Dispensar" e "Histórico". A tela só enfileira; nunca espera o HubSpot.

## Commits
- 322cf0247 — backend (index enriquecido, controller de ações, rotas, testes)
- a5e950a36 — frontend (filtros, célula, modais)

## O que foi feito
- `index` troca o closure `$grupoDoServico` por `ContratoGrupos::agrupar` (fonte única). Phase131 inteiro (123 testes) passou antes e depois: mesmas linhas e mesmo resumo de 7 situações.
- Uma query para os ciclos do universo e uma para o negócio original por empresa (sem N+1). O ID do portal vem de `services.hubspot.portal_id`, ou do evento HubSpot mais recente; sem nenhum, a tela mostra só o ID, sem link.
- Filtro `renovacao` por whitelist, aplicado depois do resumo; `filters.renovacao` devolve o valor saneado.
- `sincronizar`: `garantirCiclo(permitirVencido: true)`; erro de domínio volta como mensagem. `liberar_criacao` exige justificativa (min 10) e vai ao job. `dispensar` grava quem, quando, por quê e evento. `historico` filtra `dados` por whitelist.
- Rotas dentro do grupo `permission:admin.contratos`; teste de 403 nas três.
- Aviso de automação desligada e de modo simulação; textos explicam os termos em linguagem do administrativo.

## Testes (saída real)
- Phase131 antes da troca: 123 passed (473 assertions).
- `ContratosTelaRenovacaoTest`: 11 passed (68 assertions).
- Rodada final (Phase131 + Phase133/FaixaBloqueioContratosTest + Phase132/RotasCriticasExistemTest + Phase202): 254 passed (975 assertions).
- `npm run build` OK; `resources/js/Pages/Admin/Contratos.jsx` presente em `public/build/manifest.json`. `public/build` não é versionado (nada commitado).

## Deviations from Plan
- Nenhuma de regra 1-4. Detalhe: o `index` ignora filtro de renovação inválido (volta a `null`), como `situacao`/`servico`.
- `sincronizar` aceita `justificativa` só quando `liberar_criacao` é verdadeiro; sem liberar, ela é descartada.

## Known Stubs
Nenhum.

## Self-Check: PASSED
Arquivos criados existem; commits 322cf0247 e a5e950a36 presentes no `git log`.
