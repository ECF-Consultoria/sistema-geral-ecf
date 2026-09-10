---
phase: 141-tabela-progressiva-por-empresa-e-grupo
plan: 07
subsystem: payments
tags: [fechamento, cobranca, feature-flag, producao, gate-humano]

# Dependency graph
requires:
  - phase: 141-tabela-progressiva-por-empresa-e-grupo (planos 01-06)
    provides: "Motor completo (elegibilidade de plataforma, flag de corte, CobrancaCalculator::mensalidade(), comando de materialização, comparativo ANTES×DEPOIS, tela dos cinco ramos), tudo atrás da flag desligada"
provides:
  - "168 empresas com tabela própria presumida (origem='presumida_servico') materializadas em produção"
  - "Flag fechamento_tabela_por_empresa_ativa LIGADA em produção"
  - "Agosto/2026 reconsolidado sob a regra nova, com motivo registrado"
  - ".planning/learnings/fechamento-tabela-por-empresa.md — o registro que atravessa máquinas"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Ordem obrigatória materializar → conferir → comparar → ligar, com checkpoint humano bloqueante em cada degrau; inverter a ordem esvazia o fechamento (achado real do 141-05)"
    - "Reconsolidar mês fechado sob regra nova é decisão humana separada da virada da flag, exige --motivo= explícito (trava D-12 da Fase 137)"

key-files:
  created:
    - .planning/learnings/fechamento-tabela-por-empresa.md
  modified:
    - CLAUDE.md
    - .planning/STATE.md
    - .planning/ROADMAP.md

key-decisions:
  - "Decisão do usuário no checkpoint da Task 2: 'ligar-agora', com a ressalva de que a queda de R$ 1,75 milhão no total a receber é esperada e correta (caso Camillo Parts validado nominalmente)"
  - "Decisão do usuário, fora do roteiro original do plano: reconsolidar agosto/2026 sob a regra nova imediatamente, em vez de deixar só o mês corrente valer pela regra nova — 'fechado do jeito errado não adianta nada'"
  - "As 168 tabelas materializadas continuam com origem='presumida_servico' (não confirmadas) — conferência contra o contrato real fica para a tela da Fase 140, fora do escopo desta virada"

requirements-completed: [TPE-05, TPE-07]

# Metrics
duration: ~1h (registro; as ações de produção foram do orquestrador em sessão anterior)
completed: 2026-09-10
---

# Fase 141 Plano 07: Virada em produção com gate humano — registro dos números reais Summary

**A chave `fechamento_tabela_por_empresa_ativa` foi ligada em produção depois de materializar 168 tabelas presumidas e de o usuário aprovar o delta empresa a empresa (queda de R$ 1,75 milhão no total a receber, validada como correção da fórmula antiga) — números e procedimento de rollback registrados em `.planning/learnings/fechamento-tabela-por-empresa.md`.**

## Performance

- **Tasks:** 3/3 completas (Tasks 1 e 2 são checkpoints de produção, executados pelo orquestrador em sessão anterior; Task 3 — o registro — executada nesta sessão)
- **Files modified:** 4 (1 criado, 3 modificados)

## Accomplishments

- **Materialização** (`fechamento:materializar-tabelas --aplicar`): 168 empresas ganharam tabela própria presumida, 1 já tinha própria, 32 continuam sem tabela, 0 falhas. Conferido por reconsulta ao banco (nunca pela saída impressa): `empresa_faixas_faturamento` foi de 7 linhas/1 empresa para 1.207 linhas/169 empresas. A cobrança de agosto não mudou com este passo (permaneceu R$ 460.500,00/127 empresas) — confirmação de que a materialização é inerte por desenho.
- **Comparativo ANTES × DEPOIS** (`fechamento:comparar-mensalidade --mes=2026-08`, rodado depois da materialização): total a receber caiu de R$ 2.486.700,91 para R$ 736.450,97 (−R$ 1.750.249,94). 0 empresas sobem, 71 descem, 128 ficam iguais, **0 mudam de faixa** — a queda inteira é efeito de parar de somar contrato à faixa (D-03 da Fase 141), não de reclassificação. Maior queda isolada: grupo Camillo Parts, R$ 522.500,00 → R$ 12.000,00, faixa 7 para faixa 7.
- **Decisão humana**: usuário escolheu `ligar-agora` e validou nominalmente a queda do caso Camillo Parts como esperada — "o total a receber de antes da Camillo era exorbitante". Essa frase é o registro formal de que o "antes" estava errado, não o "depois".
- **Virada**: `fechamento_tabela_por_empresa_ativa` ligada em produção, confirmada por reconsulta e por `FechamentoRegraTabela::ativa()`.
- **Reconsolidação de agosto/2026 sob a regra nova**, a pedido explícito do usuário (fora do roteiro original de "só o mês corrente muda"): `fechamento:consolidar-mes --motivo=`. Soma de `valor_faixa` foi de R$ 460.500,00 para R$ 466.500,00 (127 → 129 empresas com faixa) — alta de faixas convivendo com queda de total a receber, explicado em detalhe no aprendizado (§4). Quarta reconsolidação da competência registrada, com `snapshot_anterior` de 158.215 bytes preservando o fechamento antigo.
- **Registro** (`.planning/learnings/fechamento-tabela-por-empresa.md`, ~180 linhas): por que a tabela do serviço deixou de ser régua e virou modelo de partida/semente; o que `origem='presumida_servico'` significa e por que nunca é tratada como confirmada; a ordem obrigatória materializar→conferir→comparar→ligar e por que inverter esvazia o fechamento (achado real do 141-05, antes deste plano); como desligar a chave sem deploy; por que competência congelada não muda sozinha com a virada; a armadilha do gate de cobertura que precisou excluir `valor_fixo` do denominador; e os números literais completos da virada. Referenciado em `CLAUDE.md` na seção "Conhecimento acumulado".

## Task Commits

1. **Tarefa 1 (checkpoint de produção — materialização)** — sem commit de código; ações de produção puras (deploy do que já estava commitado, comandos `artisan`). Executada pelo orquestrador em sessão anterior a esta.
2. **Tarefa 2 (checkpoint de decisão — delta e virada da flag)** — sem commit de código; comando `artisan` + decisão do usuário. Executada pelo orquestrador em sessão anterior a esta.
3. **Tarefa 3 (registro)** — commit pendente desta sessão: `.planning/learnings/fechamento-tabela-por-empresa.md` (criado), `CLAUDE.md`, `.planning/STATE.md`, `.planning/ROADMAP.md` (modificados). Ver commit no final desta SUMMARY.

## Files Created/Modified

- `.planning/learnings/fechamento-tabela-por-empresa.md` - Aprendizado completo sobre a modelagem nova da tabela progressiva e os números reais da virada.
- `CLAUDE.md` - Referência ao aprendizado novo na seção "Conhecimento acumulado", mesmo padrão dos outros três arquivos de `.planning/learnings/`.
- `.planning/STATE.md` - Entradas 141-05/141-06/141-07 no bloco paralelo da Fase 141, com os números literais e a marcação de fase fechada.
- `.planning/ROADMAP.md` - Checkboxes 141-05/06/07 marcados, nota de fechamento da fase com os números da virada, footer de roadmap padrão.

## Decisions Made

Ver `key-decisions` no frontmatter — destaque para a decisão do usuário de reconsolidar agosto/2026 imediatamente sob a regra nova, que não estava no roteiro original do plano (o `<context>` da Task 2 previa que só o mês corrente mudaria e que reconsolidar um mês fechado seria "decisão separada, fora desta fase") — o usuário tomou essa decisão separada na mesma sessão, e o orquestrador a executou com `--motivo=` registrado.

## Deviations from Plan

Nenhuma no sentido das Regras 1-3 (nenhum bug corrigido, nenhuma funcionalidade crítica ausente adicionada, nenhum bloqueio contornado). A única divergência do roteiro escrito no plano é a decisão humana de reconsolidar agosto imediatamente (ver acima) — não é uma deviation de execução, é uma decisão de produto tomada pelo usuário dentro do checkpoint previsto para isso.

**Total deviations:** 0
**Impact on plan:** Nenhum — a Task 3 (registro) capturou a decisão adicional do usuário como parte do que precisava ser registrado, sem alterar escopo de código.

## Issues Encountered

- `141-06-SUMMARY.md` não existe apesar de o checkpoint visual da Task 3 do plano 141-06 ter sido aprovado pelo usuário em 2026-09-10 e o commit `eb45a3e5` (feat(141-06)) já estar na árvore. Registrado como pendência em `STATE.md` — mesma classe de lacuna já existente para `140-03-SUMMARY.md`. Fora do escopo desta Task 3 (que cobre apenas o registro do 141-07); não foi criado aqui para não inventar detalhes de uma execução que não foi desta sessão.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. A virada em si (flag, materialização, reconsolidação) já foi executada pelo orquestrador antes desta sessão de registro.

## Next Phase Readiness

- **Fase 141 fechada.** Não há plano 141-08.
- Pendências que sobrevivem, todas documentadas em `.planning/learnings/fechamento-tabela-por-empresa.md` §8: as 168 tabelas presumidas seguem sem conferência humana contra o contrato real (a tela `/administrativo/contratos/tabelas` da Fase 140 existe para isso); as 32 empresas sem tabela são, pela inspeção do nome, majoritariamente cadastro de teste; e os 3 contratos de R$ 250.000 (GENUINEAUTOMOTIVE, Lenonn Milani, CAMILLOPARTS FILIAL RS) seguem reportados ao usuário como provável erro de cadastro, não investigados.
- A Fase 142 (`.planning/phases/142-cadastro-da-tabela-progressiva-no-contrato/`) já aparece como diretório na árvore compartilhada — fora do escopo deste plano, não tocada aqui.

---
*Phase: 141-tabela-progressiva-por-empresa-e-grupo*
*Plan: 07*
*Completed: 2026-09-10*

## Self-Check: PASSED

- `.planning/learnings/fechamento-tabela-por-empresa.md` — FOUND (escrito nesta sessão).
- `CLAUDE.md` contém `fechamento-tabela-por-empresa` na seção "Conhecimento acumulado" — FOUND.
- Gate completo (`Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Quick260909`): **553 testes / 2642 asserções / 0 falhas** — confirmado por execução direta nesta sessão (rodada em background, log em `by48eml2s.output`).
- `.planning/STATE.md` e `.planning/ROADMAP.md` contêm as entradas 141-05/141-06/141-07 — FOUND.
