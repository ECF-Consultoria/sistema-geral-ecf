---
phase: 133-liga-o-bloqueio-ativa-o-real-v22-0
plan: 01
subsystem: operacional
tags: [laravel, eloquent, kill-switch, roteamento, contrato, fail-safe]

# Dependency graph
requires:
  - phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
    provides: "servicos.exige_contrato + Servico::exigeContrato()/scopeExigeContrato() — o dado que esta fase consulta"
  - phase: 124 (refatoração EmpresaOperacionalRouter)
    provides: "o interruptor administrativo_bloqueio_ativo e o ponto único de leitura rotear()"
provides:
  - "Exceção por serviço dentro de EmpresaOperacionalRouter::rotear() — Polos passa mesmo com a chave ligada, quem exige contrato é retido"
  - "Fail-safe: nome de serviço fora do catálogo é tratado como se exigisse contrato"
  - "Log::warning com servicos_retidos — rastreabilidade de QUAIS serviços foram retidos por empresa"
  - "Cobertura de teste dos dois pontos de entrada não-HTTP (rotearCadastro/rotearServico) e dos dois caminhos HTTP (Comercial/Webhook) para o caso Polos-com-chave-ligada"
affects: [133-02, 133-03, 133-04, 133-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Filtro em lote (whereIn->where->pluck) para decisão por serviço, nunca N+1 dentro de foreach"
    - "Fail-safe por ausência de dado (nome não encontrado = tratado como exige contrato), mesmo espírito do default(true) da migration 2026_08_13_100001"
    - "Fixture de teste com exige_contrato declarado por update() explícito após firstOrCreate, nunca herdado do array de criação"

key-files:
  created:
    - tests/Feature/Phase133/RoteamentoExcecaoServicoTest.php
  modified:
    - app/Services/Operacional/EmpresaOperacionalRouter.php
    - tests/Feature/Phase124KillSwitchTest.php

key-decisions:
  - "D-02 (herdada do CONTEXT): decisão por serviço, dentro do laço — nunca por empresa. Confirmado pelo teste 'com_polos_e_assessoria_apenas_polos_e_roteado'."
  - "Fail-safe: nome fora do catálogo cai fora de $isentos e é retido — nunca tratado como isento por semelhança de nome (⛔ nenhum 'Polos' literal em produção)."
  - "Os 4 testes do Phase124KillSwitchTest.php trocaram de cenário (Polos → Assessoria) em vez de serem apagados — a intenção original (retenção com a chave ligada) continua provada, agora pelo motivo certo."

requirements-completed: [FLUXO-01, FLUXO-02]

# Metrics
duration: ~35min
completed: 2026-08-18
---

# Phase 133 Plano 01: Exceção por serviço no roteamento (D-02) Summary

**A checagem `if ($this->bloqueioAtivo())` que hoje bloqueava tudo virou um filtro em lote por `servicos.exige_contrato`: Polos passa mesmo com o interruptor ligado, qualquer serviço que exige contrato fica retido, e nome fora do catálogo cai no lado seguro.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 2/2 completed
- **Files modified:** 3 (1 criado, 2 modificados)

## Accomplishments
- O ponto de extensão que era comentário em `EmpresaOperacionalRouter::rotear()` agora é código: consulta em lote (`Servico::whereIn('nome', $nomes)->where('exige_contrato', false)->pluck('nome')`), sem N+1, com log de `servicos_retidos`.
- Prova, por teste, do fail-safe: um nome livre como `'Polos Fantasma Não Catalogado'` (que casa por `str_contains` no mapa `servicoDisparaImplementacao()`) não vira passe livre — é retido.
- Prova, por teste, do caso central da fase (D-02): empresa com Polos + Assessoria na mesma submissão tem só o Polos roteado.
- Os 4 testes que ficariam obsoletos com a mudança (`Phase124KillSwitchTest`) foram trocados de cenário para `Assessoria` (que continua exigindo contrato) em vez de apagados, e ganharam 2 testes-irmãos que provam a exceção de Polos nos dois caminhos HTTP (`POST /comercial/empresas` e webhook HubSpot).

## Task Commits

Each task was committed atomically:

1. **Task 1: fixture com `exige_contrato` explícito + testes RED** - `f17a85e9` (test)
2. **Task 2: exceção por serviço em `rotear()` + testes do Phase124 trocados de cenário** - `69eb7bc0` (feat)

_TDD: Task 1 é o RED (3 dos 7 testes falham por ausência da implementação); Task 2 é o GREEN (implementação + testes existentes atualizados no mesmo commit, para a suíte nunca ficar vermelha entre commits, conforme instrução explícita do plano)._

## Files Created/Modified
- `tests/Feature/Phase133/RoteamentoExcecaoServicoTest.php` - 7 testes: sentinela de fixture, Polos passando pelos 2 pontos de entrada não-HTTP, Assessoria retida, misto Polos+Assessoria, nome fora do catálogo, chave desligada
- `app/Services/Operacional/EmpresaOperacionalRouter.php` - `rotear()`: filtro em lote substitui o `return` incondicional; log passa a incluir `servicos_retidos`
- `tests/Feature/Phase124KillSwitchTest.php` - fixture com `exige_contrato` explícito; 4 testes renomeados e trocados para `Assessoria`; 2 testes novos provando Polos via HTTP com a chave ligada

## Decisions Made
- Nenhuma decisão nova além das já travadas no CONTEXT.md (D-02). A implementação seguiu exatamente o trecho de código já validado pela pesquisa (`133-RESEARCH.md`), sem desvio de desenho.

## Deviations from Plan

### Nota de acompanhamento (não é bug, não precisou de correção)

**Contagem de falhas do RED (Task 1) divergiu do texto do plano — sem impacto no resultado.**
- **Encontrado durante:** Task 1, ao rodar a suíte pela primeira vez.
- **O que o plano previa:** "Falham exatamente 4 testes: os dois de Polos com a chave ligada, o misto e o de nome fora do catálogo."
- **O que aconteceu:** falharam 3, não 4. O teste `test_nome_de_servico_fora_do_catalogo_e_retido_mesmo_parecendo_polos` afirma que **0** `MlbEmpresa` nascem para um nome fora do catálogo com a chave ligada — e como o comportamento ANTES desta fase já bloqueia **tudo** com a chave ligada, essa asserção de "0" já era verdadeira antes de qualquer implementação. A asserção continua verdadeira depois da Task 2, agora pelo motivo certo (fail-safe), mas não podia ter sido vermelha na Task 1 — não há implementação que a torne falsa nesse estado.
- **Ação:** nenhuma. Não é um bug de código nem de teste; é uma imprecisão de contagem no texto do plano. O teste em si está correto e continua verde depois da Task 2.
- **Verificação:** `Tests: 7` no arquivo novo, com a sentinela + `test_interruptor_ligado_retem_servico_que_exige_contrato` + `test_interruptor_desligado_roteia_assessoria_como_sempre` passando já na Task 1, exatamente como o plano previu para esses três.

## Known Stubs
Nenhum. Nenhum dado hardcoded, placeholder ou componente sem fonte de dado foi introduzido.

## Threat Flags
Nenhuma superfície nova. A mudança consulta um dado já existente (`servicos.exige_contrato`, Fase 128) no único ponto que já lia o interruptor (`rotear()`); nenhuma rota, endpoint ou caminho de auth novo foi criado. Os 5 threats do `<threat_model>` do plano (T-133-01 a T-133-05) foram todos mitigados dentro do escopo desta task — ver testes correspondentes: fail-safe (T-133-01), Polos não bloqueado (T-133-02), sentinela de fixture (T-133-03), `servicos_retidos` no log (T-133-04), nenhum `'Polos'` literal em produção (T-133-05, confirmado por grep).

## Self-Check: PASSED

- `tests/Feature/Phase133/RoteamentoExcecaoServicoTest.php` — FOUND
- `app/Services/Operacional/EmpresaOperacionalRouter.php` — FOUND (modificado)
- `tests/Feature/Phase124KillSwitchTest.php` — FOUND (modificado)
- Commit `f17a85e9` — FOUND
- Commit `69eb7bc0` — FOUND
- `git status --porcelain` confirma apenas os 3 arquivos do plano entre os arquivos rastreados por este plano (demais entradas untracked são de outras sessões/trabalho em andamento na árvore compartilhada, fora de escopo)
