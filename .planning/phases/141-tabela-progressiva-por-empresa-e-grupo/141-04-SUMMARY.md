---
phase: 141-tabela-progressiva-por-empresa-e-grupo
plan: 04
subsystem: payments
tags: [fechamento, cobranca, feature-flag, tdd, laravel]

# Dependency graph
requires:
  - phase: 141-tabela-progressiva-por-empresa-e-grupo (planos 01/02/03)
    provides: "FechamentoRegraTabela (interruptor), FechamentoRollupService::porEmpresa(somenteContratadas), CobrancaCalculator::mensalidade(), FechamentoSnapshot::ESTADO_VALOR_FIXO, EmpresaFaixaFaturamento com origem"
provides:
  - "FechamentoFaixaResolver resolve grupo → própria → nada com a flag ligada (tabela do serviço nunca mais classifica)"
  - "9ª chave procedencia no shape do resolver (manual/contrato/presumida_servico quando origem=propria)"
  - "ConsolidarMesFechamento cobra pela regra nova atrás da flag: rollup recortado, estado com ESTADO_VALOR_FIXO, cobrança via mensalidade(), gate de cobertura sem o efeito colateral de empresa sem régua"
  - "Trava provada por teste: competência congelada com a flag desligada é imune à virada de regra (D-11)"
affects: [141-05, 141-06, 141-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Early-return em vez de bloco condicional grande: paraEmpresa() retorna null direto quando a flag está ligada e não há grupo/própria, em vez de envolver o degrau antigo inteiro num if — o caminho antigo fica intacto e legível abaixo"
    - "Branch se/senão espelhado (regraNova ? novo : antigo) em vez de um único bloco condicional grande — deixa o grep de auditoria (`grep -n regraNova`) encontrar cada ponto de decisão isolado"
    - "Teste de flag mudando no meio da execução precisa de métodos SEPARADOS (não dois artisan() no mesmo teste) — o console Kernel de teste memoiza a instância do comando entre chamadas de `$this->artisan()` dentro do mesmo método, e `FechamentoRegraTabela::ativa()` é memoizado por instância; ligar a flag e rodar de novo no mesmo método leria o valor ANTIGO memoizado (falso positivo de teste, produção não tem esse problema — cada `artisan` é um processo novo)"

key-files:
  created:
    - tests/Feature/Phase141/Phase141ResolverCutoverTest.php
    - tests/Feature/Phase141/Phase141ConsolidarRegraNovaTest.php
    - tests/Feature/Phase141/Phase141CongeladoNaoMudaTest.php
  modified:
    - app/Services/Fechamento/FechamentoFaixaResolver.php
    - app/Console/Commands/ConsolidarMesFechamento.php

key-decisions:
  - "procedencia só é preenchida quando origem=propria, lida da PRIMEIRA linha de EmpresaFaixaFaturamento (all-or-nothing garante que todas as linhas compartilham a mesma origem) — grupo e serviço ficam null (grupo não tem coluna origem; serviço deixou de ser régua)"
  - "paraGrupo() não precisou de nenhuma mudança de código: como já delega para paraEmpresa(ancora), e paraEmpresa() já respeita a flag, a herança do grupo automaticamente para de olhar o serviço quando a flag liga — só a documentação foi atualizada"
  - "$temContratoMensal (empresa) e $temContratoMensalGrupo (grupo) foram movidos para ANTES do cálculo de estado, porque a precedência nova precisa deles para decidir ESTADO_VALOR_FIXO — sem duplicar o cálculo, só reordenando"
  - "Gate de cobertura exclui ESTADO_VALOR_FIXO do denominador incondicionalmente (não só quando regraNova) — é seguro porque ESTADO_VALOR_FIXO nunca é atribuído com a flag desligada, então o filtro é inofensivo nesse caso e evita duplicar a condição"
  - "Teste da trava D-11 (Tarefa 3) reconsolida sem --motivo= depois de já ter tentado quebrar a competência — a asserção de recusa (exit 1) independe do valor de $regraNova na tentativa, porque o RuntimeException do writer dispara ANTES de qualquer cálculo novo"

requirements-completed: [TPE-01, TPE-02, TPE-03, TPE-04, TPE-08]

# Metrics
duration: ~55min
completed: 2026-09-09
---

# Fase 141 Plano 04: A virada de regra no motor do fechamento Summary

**`FechamentoFaixaResolver` para de herdar a tabela do serviço e `ConsolidarMesFechamento` passa a cobrar só o valor da faixa da soma — os dois atrás da flag `fechamento_tabela_por_empresa_ativa`, provados pelo cenário real BARAOSHOP (R$ 5.500 ANTES → R$ 3.000 DEPOIS) e pela trava de que nenhuma competência já fechada se move.**

## Performance

- **Duration:** ~55 min
- **Tasks:** 3/3 completas
- **Files modified:** 5 (2 modificados, 3 testes novos)

## Accomplishments

- Com a flag ligada, `FechamentoFaixaResolver::paraEmpresa()` resolve `null` (nunca mais a tabela do serviço) quando não há tabela de grupo nem própria — o degrau antigo continua no código, intocado, só não é mais alcançado.
- O shape de retorno do resolver ganhou a 9ª chave `procedencia`, preenchida a partir da coluna `origem` de `EmpresaFaixaFaturamento` só quando `origem === 'propria'`.
- `paraGrupo()` não precisou de nenhuma mudança: por já delegar para `paraEmpresa($ancora)`, a herança do grupo automaticamente para de encontrar a tabela do serviço quando a flag liga.
- `ConsolidarMesFechamento` lê a flag UMA vez (`$regraNova`), passa `somenteContratadas: $regraNova` nas DUAS chamadas de `porEmpresa()` (competência atual e mês anterior — nunca só uma, para não inventar evolução de faixa falsa), grava `ESTADO_VALOR_FIXO` para empresa/grupo sem régua com contrato mensal ativo, e cobra via `CobrancaCalculator::mensalidade()` (só o valor da faixa) para empresa e grupo.
- O gate de cobertura de faturamento exclui `ESTADO_VALOR_FIXO` do denominador (além de `ESTADO_SEM_INTEGRACAO`) — sem isso, um punhado de empresas sem plataforma elegível (Mentoria e afins) derrubaria a cobertura abaixo de 0,7 e recusaria o fechamento de TODO MUNDO, um efeito colateral puro da regra nova, não um problema real de dado.
- Cenário BARAOSHOP provado ponta a ponta com valores reais: **ANTES R$ 5.500,00** (faixa R$ 3.000 + contrato de Shopee R$ 2.500, o bug que abriu a Fase 141) → **DEPOIS R$ 3.000,00** (só o valor da faixa, classificada sobre a soma R$ 488.262,90 das duas plataformas).
- Trava D-11 (Fase 137) reprovada sob a regra nova: congela com a flag desligada, liga a flag, cadastra tabela de empresa e altera métricas de origem — a reconsulta direta às tabelas de snapshot prova que nada se move, e reconsolidar sem `--motivo=` continua recusado mesmo com a flag ligada.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: A tabela do serviço deixa de classificar (resolver)** - `9a9a1905` (feat)
2. **Tarefa 2: A consolidação passa a cobrar pela regra nova** - `992149d9` (feat)
3. **Tarefa 3: Trava — competência congelada é imune à virada** - `d4427952` (test)

_Nenhuma tarefa teve commits `test`/`feat` separados apesar de `tdd="true"`: para cada tarefa o teste foi escrito e confirmado (RED → GREEN) antes do commit, mas o commit final junta teste + implementação — mesmo padrão pragmático já registrado nos planos 141-01/02/03 (árvore compartilhada com outra sessão ativa torna `git commit --amend`/rebase arriscado)._

## Files Created/Modified

- `app/Services/Fechamento/FechamentoFaixaResolver.php` - Construtor recebe `FechamentoRegraTabela`; `paraEmpresa()` retorna `null` direto (em vez de cair no degrau do serviço) quando a flag está ligada e não há tabela de grupo/própria; shape ganha `procedencia`; docblock da classe reescrito descrevendo as duas ordens de resolução (nova × antiga/transitória)
- `app/Console/Commands/ConsolidarMesFechamento.php` - Construtor recebe `FechamentoRegraTabela`; `$regraNova` lido uma vez no início de `handle()`; `somenteContratadas: $regraNova` nas duas chamadas de `porEmpresa()`; precedência de estado e cálculo de cobrança bifurcados por `$regraNova` para empresa E grupo; gate de cobertura exclui `ESTADO_VALOR_FIXO`; docblock da classe documenta a virada inteira
- `tests/Feature/Phase141/Phase141ResolverCutoverTest.php` - 7 testes: flag desligada intocada, flag ligada resolve null/própria/grupo, herança do grupo só com tabela própria da âncora, chave `procedencia` sempre presente
- `tests/Feature/Phase141/Phase141ConsolidarRegraNovaTest.php` - 7 testes: BARAOSHOP ANTES (2 métodos separados por causa da memoização de instância dentro do mesmo teste — ver Decisões), faixa da soma diferente da faixa de ML isolado, `valor_fixo` com/sem contrato mensal, grupo cobrando só a faixa da soma, gate de cobertura sem o efeito colateral
- `tests/Feature/Phase141/Phase141CongeladoNaoMudaTest.php` - 1 teste: congela com a flag desligada, vira a flag, cadastra tabela e altera métricas, reconsulta prova que nada mudou, reconsolidar sem motivo continua recusado

## Decisions Made

- `procedencia` só é preenchida quando `origem === 'propria'`, lida da PRIMEIRA linha de `EmpresaFaixaFaturamento` (all-or-nothing garante que todas as linhas compartilham a mesma origem).
- `paraGrupo()` não precisou de nenhuma mudança de comportamento — só documentação — porque delega para `paraEmpresa($ancora)`, que já respeita a flag.
- `$temContratoMensal`/`$temContratoMensalGrupo` foram movidos para ANTES do cálculo de estado (eram calculados só na hora da cobrança) porque a precedência nova precisa deles para decidir `ESTADO_VALOR_FIXO` — sem duplicar cálculo, só reordenando.
- Gate de cobertura exclui `ESTADO_VALOR_FIXO` do denominador de forma incondicional (não só quando `$regraNova`) — seguro porque esse estado nunca é atribuído com a flag desligada, evitando duplicar a condição.
- O cenário BARAOSHOP ficou em DOIS métodos de teste (não um só com dois `artisan()`), porque o console Kernel de teste memoiza a instância do comando entre chamadas de `$this->artisan()` dentro do MESMO método de teste — e como `FechamentoRegraTabela::ativa()` é memoizado por instância, ligar a flag no meio do mesmo teste e rodar de novo leria o valor ANTIGO. Isso é um artefato de teste (em produção cada `artisan` é um processo PHP novo), documentado com comentário extenso no arquivo de teste para a próxima pessoa não tropeçar de novo.

## Deviations from Plan

None - plano executado como escrito. A separação do teste BARAOSHOP em dois métodos (em vez de um método com dois `artisan()`) foi necessária para o teste refletir corretamente o comportamento real (Rule 1 — o teste original, com uma única execução, estava dando falso negativo por causa da memoização de instância do teste, não por um bug do código de produção); documentado acima em "Decisions Made", não é mudança de escopo do plano.

## Issues Encountered

- Duas armadilhas de fixture na Tarefa 2, ambas de construção de teste (não do código de produção): (1) o primeiro rascunho do teste "sem tabela e sem contrato mensal" usava um serviço com `usa_tabela_progressiva=false`, o que zerava o faturamento para `null` e fazia o estado cair em `ESTADO_SEM_FATURAMENTO` antes de chegar em `ESTADO_SEM_TABELA` — corrigido usando um serviço com plataforma elegível mas `tipo_cobranca=unica`, isolando exatamente o caso que o `<behavior>` pedia. (2) o teste do mesmo cenário sem as 3 empresas de cobertura extra derrubava o gate (denominador=1, cobertura=0) e retornava exit 1 — corrigido acrescentando companheiras de fatura com faturamento real, prática já usada nos testes da Fase 137.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- O motor (`FechamentoFaixaResolver` + `ConsolidarMesFechamento`) está pronto para o plano 141-05 comparar ANTES × DEPOIS empresa a empresa contra produção — a flag continua nascendo desligada, nada mudou em produção.
- `AdminController::fechamento()` (5 literais de linha: empresa ao vivo, congelada com/sem snapshot, grupo ao vivo, grupo congelado) **NÃO foi tocado neste plano** — não está em `files_modified` do 141-04. Fica registrado para o plano 141-06: sem essa mudança, a tela administrativa ainda calcularia pela regra antiga mesmo com a flag ligada (o motor já mudaria, mas a leitura ao vivo da tela não).
- Gate `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Quick260909"` rodado após as três tarefas: **528 testes / 2481 asserções / 0 falhas** — 15 testes novos desta fase (7+7+1), nenhuma regressão sobre o baseline informado de 513/2408/0.
- Verificação por leitura (`grep -n "regraNova" app/Console/Commands/ConsolidarMesFechamento.php`) confirma os 5 pontos de decisão: rollup (2 chamadas), estado de empresa, cobrança de empresa, estado de grupo, cobrança de grupo.

---
*Phase: 141-tabela-progressiva-por-empresa-e-grupo*
*Plan: 04*
*Completed: 2026-09-09*

## Self-Check: PASSED

Os 5 arquivos criados/modificados confirmados por leitura direta do filesystem/git diff; os 3 commits de tarefa (`9a9a1905`, `992149d9`, `d4427952`) confirmados em `git log --oneline`.
