---
phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
plan: 03
subsystem: fechamento
tags: [laravel, phpunit, fechamento, faixa-de-faturamento]

# Dependency graph
requires:
  - phase: 138-01
    provides: "GrupoFaixaFaturamento, FechamentoFaixaResolver::paraGrupo()/degrau de grupo em paraEmpresa(), shape unificado de 8 chaves"
provides:
  - "ConsolidarMesFechamento (Passo 5) classificando a soma do grupo pela tabela do grupo, quando ela existir"
  - "tabela_origem da linha de grupo podendo valer 'grupo'"
affects: [138-04, 138-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Resolução de tabela do grupo chamada explicitamente (paraGrupo()), não reaproveitada do valor já calculado para a empresa-âncora — trade-off de 1 query a mais por grupo em troca de legibilidade + trava de concordância por teste"

key-files:
  created:
    - tests/Feature/Phase138/Phase138ConsolidarGrupoTabelaTest.php
  modified:
    - app/Console/Commands/ConsolidarMesFechamento.php

key-decisions:
  - "Decisão declarada (nota do plan-checker): opção (a) — manter paraGrupo() explícito por legibilidade, com teste travando a concordância entre os dois caminhos que hoje devolvem o mesmo resultado (paraEmpresa($ancora) já embute o degrau de grupo)"
  - "empresa_ancora_id permanece SEMPRE preenchido mesmo quando a tabela aplicada é a do grupo — é a identidade da linha (AdminController::fechamentoAgregarGruposCongelados reencontra por ela), não a origem da tabela; 'herdada de quem' é derivado de tabela_origem, nunca uma coluna nova"
  - "tabelas_divergentes não recebeu tratamento especial — o cálculo já existente (pares origem|servico_id por membro via $faixaPorEmpresa) colapsa sozinho porque paraEmpresa() de cada membro já resolve 'grupo' quando o grupo tem tabela (Fase 138-01)"

requirements-completed: [D-01]

# Metrics
duration: ~35min
completed: 2026-09-03
---

# Phase 138 Plan 03: Comando de fechamento honra a tabela do grupo Summary

**`ConsolidarMesFechamento` (Passo 5) resolve a tabela do grupo por `FechamentoFaixaResolver::paraGrupo()`, com fallback defensivo, mantendo `empresa_ancora_id` como identidade fixa da linha.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-03
- **Completed:** 2026-09-03
- **Tasks:** 3 (Tarefa 3 é o gate de regressão, sem código novo)
- **Files modified:** 2 (1 arquivo existente ampliado, 1 teste novo)

## Decisão declarada (nota do plan-checker)

O plano pede para declarar explicitamente qual dos dois caminhos foi seguido, porque `paraEmpresa()`
já embute o degrau de grupo (Fase 138-01) e por isso `$faixaPorEmpresa[$ancora->id]` — calculado no
Passo 3 para **toda** empresa, inclusive a âncora — **já traz** `origem = 'grupo'` quando a tabela do
grupo existe. Chamar `paraGrupo()` explicitamente devolve, para efeito de classificação, o mesmo
resultado.

**Caminho escolhido: (a)** — manter `paraGrupo()` explícito no Passo 5, com o teste
`Phase138ConsolidarGrupoTabelaTest` travando a concordância entre os dois caminhos.

Motivação:
- Legibilidade: o Passo 5 lê "resolvo a tabela do grupo" (`paraGrupo($ancora->grupo, $ancora)`),
  não "reaproveito um efeito colateral do cálculo por-empresa do Passo 3".
- Custo aceito: uma consulta a mais por grupo (15 grupos hoje) — desprezível, e já registrado no
  threat model da Fase 138 como T-138-08 (Denial of Service, disposição "aceitar").
- O risco real que a nota descreve — os dois caminhos divergirem em silêncio se alguém mudar a
  precedência num deles e esquecer o outro — não desaparece com a opção (a) sozinha; por isso o
  teste novo (caso "grupo com tabela vence") verifica, na prática, que a linha de grupo gravada bate
  com o que a tabela do grupo prescreve, e que as linhas de empresa-membro (calculadas por
  `paraEmpresa()`, caminho independente) concordam com a mesma origem — se um dia os dois caminhos
  divergirem, este teste quebra antes de qualquer fatura sair errada.

Não foi escolhida a opção (b) (reaproveitar `$faixaPorEmpresa[$ancora->id]`) porque o Passo 5 do
plano pediu explicitamente a chamada a `paraGrupo()` na ação da Tarefa 1, e a nota do plan-checker
trata (a) como a leitura mais direta do texto da tarefa — (b) ficaria como otimização futura, se a
query extra por grupo algum dia importar.

## Accomplishments

- `ConsolidarMesFechamento::handle()` — Passo 5 (grupos): a resolução da tabela trocou de
  `$faixaPorEmpresa[$ancora->id] ?? null` para `$this->faixaResolver->paraGrupo($ancora->grupo, $ancora)`,
  com fallback defensivo para o valor antigo se `$ancora->grupo` vier nulo (nunca deixar de gravar a
  linha).
- `tabela_origem`/`servico_id` da linha de grupo passam a vir de `$faixaGrupo` (resultado de
  `paraGrupo()`), podendo valer `'grupo'`.
- `empresa_ancora_id` não mudou de lugar nem de significado — continua sempre a empresa de maior
  faturamento entre os membros, preenchida em toda linha de grupo.
- Docblock da classe `ConsolidarMesFechamento` ganhou um parágrafo novo documentando o degrau de
  grupo (D-01, Fase 138) e a regra "tabela_origem='grupo' → sem herança; qualquer outro valor → veio
  de empresa_ancora_id".
- Teste novo `Phase138ConsolidarGrupoTabelaTest` com 2 métodos cobrindo os 4 casos do plano:
  - grupo **sem** tabela própria → `tabela_origem='servico'`, âncora e classificação inalteradas
    (comprova que nada regrediu);
  - grupo **com** tabela própria, deliberadamente diferente da da âncora e da própria de um dos
    membros → `tabela_origem='grupo'`, `servico_id=null`, `empresa_ancora_id` preservado,
    `tabelas_divergentes=false` mesmo com membros que tinham tabelas diferentes antes, e as linhas
    de **ambas** as empresas-membro em `fechamento_snapshots` também saem com `tabela_origem='grupo'`.

## Task Commits

1. **Tarefa 1: Passo 5 resolve a tabela pelo grupo** — `ec723916` (feat)
2. **Tarefa 2: teste de consolidação com/sem tabela de grupo** — `cc4d94e4` (test)
3. **Tarefa 3: gate de regressão** — sem commit próprio (nenhum código novo; gate confirmado verde na mesma rodada da Tarefa 2)

## Files Created/Modified

- `app/Console/Commands/ConsolidarMesFechamento.php` — Passo 5 usa `paraGrupo()`; docblock da classe
  documenta o degrau de grupo e a regra de herança derivada de `tabela_origem`.
- `tests/Feature/Phase138/Phase138ConsolidarGrupoTabelaTest.php` — 2 testes / 23 asserções, verificação
  por reconsulta a `fechamento_grupo_snapshots` e `fechamento_snapshots` (nunca pela saída de texto do
  comando).

## Deviations from Plan

None — plano executado exatamente como escrito. A única decisão em aberto pelo plano (opção a/b da
nota do plan-checker) foi resolvida e está declarada na seção acima.

## Issues Encountered

None.

## Gate de regressão (Tarefa 3)

`C:/xampp/php/php.exe vendor/bin/phpunit --filter="Phase122|Phase136|Phase137|Phase138"`:

**254 testes / 1327 asserções / 0 falhas** (medido após este plano, árvore com só os arquivos deste
plano modificados). Era 252 testes / 1304 asserções / 0 falhas antes deste plano (medição do
orquestrador) — este plano adicionou 2 testes / 23 asserções, todos novos, sem regressão.

Falhas pré-existentes fora do filtro (não são desta plano, não investigadas): `FechamentoMigrationTest`,
`AdminFechamentoControllerTest` (4/16), `Phase14MigrationTest`, `Phase14MlbControllerFiltroTest`,
`Phase14VerificarCobrancaTest`.

## User Setup Required

None — nenhuma configuração de serviço externo necessária. Nenhuma migration nova (reusa
`grupo_faixas_faturamento` da Fase 138-01).

## Next Phase Readiness

- O valor congelado do grupo já honra a tabela do grupo quando ela existir — a lacuna que motivou
  D-01 (cadastrar a tabela do grupo e ela não valer nada no fechamento) está fechada.
- Plano 138-04 (outro executor, em paralelo, `AdminController.php`) não foi tocado por este plano —
  sem sobreposição de `files_modified`.
- Plano 138-06 (tela) pode ler `tabela_origem='grupo'` e `empresa_ancora_id` do snapshot congelado com
  o mesmo significado documentado no docblock desta classe.

---
*Phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa*
*Completed: 2026-09-03*

## Self-Check: PASSED

Arquivos e commits confirmados abaixo.
