---
phase: 141-tabela-progressiva-por-empresa-e-grupo
plan: 05
subsystem: payments
tags: [fechamento, cobranca, feature-flag, cli, laravel]

# Dependency graph
requires:
  - phase: 141-tabela-progressiva-por-empresa-e-grupo (planos 02/04)
    provides: "FechamentoRegraTabela (interruptor), FechamentoRollupService::porEmpresa(somenteContratadas), FechamentoFaixaResolver::paraEmpresa()/paraGrupo() respeitando a flag, CobrancaCalculator::novo()/mensalidade()"
provides:
  - "fechamento:comparar-mensalidade --mes= [--json] [--todas] — comparação ANTES × DEPOIS por empresa e por grupo, leitura pura"
  - "FechamentoRegraTabela::forcar(?bool) — calcula os dois lados da comparação no mesmo processo, sem persistir nada"
  - "Trava por teste: o lado DEPOIS do relatório é byte-a-byte o cobranca_mensal/faixa_ordem/faturamento_total que fechamento:consolidar-mes grava com a flag ligada (e o ANTES, com a flag desligada)"
affects: [141-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "forcar() só produz efeito de verdade se a MESMA instância de FechamentoRegraTabela for compartilhada por todo consumidor que decide comportamento (aqui: o comando E o FechamentoFaixaResolver) — o container Laravel não faz isso sozinho para classes sem binding singleton; a correção foi construir o FechamentoFaixaResolver manualmente (`new FechamentoFaixaResolver($this->regra)`) em vez de deixar a injeção automática resolver uma segunda instância"
    - "Seções de 'maiores altas'/'maiores quedas' num relatório de decisão precisam filtrar pelo SINAL antes de ordenar e recortar top-N — 'a menos negativa de todas' não é uma alta, e array_reverse() ingênuo sobre uma lista só-de-quedas produz exatamente esse erro"

key-files:
  created:
    - app/Console/Commands/CompararMensalidadeFechamento.php
    - tests/Feature/Phase141/Phase141CompararMensalidadeTest.php
  modified:
    - app/Services/Fechamento/FechamentoRegraTabela.php

key-decisions:
  - "O relatório NÃO replica o cálculo de `estado` (ESTADO_OK/SEM_TABELA/VALOR_FIXO/...) de ConsolidarMesFechamento — só precisa dos 3 campos que a consolidação de fato grava (faturamento_total, faixa_ordem, cobranca_mensal) mais a origem da régua para exibição; isso eliminou a necessidade de reconstruir `$temIntegracao`/ShopeeMetric só para o relatório"
  - "'sem régua' no rodapé é definido como faixaData === null (régua 'sem_tabela'), o mesmo critério do D-04 do CONTEXT (127/201 empresas) — inclui tanto quem passa a cobrar valor fixo por contrato quanto quem fica sem cobrança nenhuma definida; a distinção entre os dois já aparece via cobranca_mensal null ou não"
  - "ANTES e DEPOIS são dois testes SEPARADOS (mesmo padrão do Phase141ConsolidarRegraNovaTest/141-04): rodar fechamento:consolidar-mes duas vezes no MESMO método de teste reusa a mesma instância de comando (e do FechamentoRegraTabela nela injetado), cujo ativa() já está memoizado da primeira chamada — a segunda rodada leria o valor ANTIGO. Cada teste constrói o cenário do zero e roda a consolidação real UMA única vez."
  - "Maiores altas/maiores quedas filtram por sinal (diferenca > 0 / diferenca < 0) ANTES de ordenar e recortar top 10, e nunca preenchem uma lista vazia com o sinal errado — quando não há nenhuma linha do sinal esperado, o texto humano diz isso em palavras ('nenhuma empresa sobe/desce nesta comparação')"

requirements-completed: [TPE-07]

# Metrics
duration: ~2h (sessão interrompida pelo limite + retomada para corrigir defeito relatado após rodada em produção)
completed: 2026-09-09
---

# Fase 141 Plano 05: O comparativo ANTES × DEPOIS por empresa e grupo Summary

**`fechamento:comparar-mensalidade` — comando de leitura pura que mostra, empresa a empresa e grupo a grupo, quanto se cobra hoje e quanto se cobraria pela regra nova, com o motivo da mudança e o total de risco no rodapé; travado por teste contra o que `fechamento:consolidar-mes` de fato grava nos dois lados da flag.**

## Performance

- **Tasks:** 2/2 completas (mais 1 correção pós-rodada em produção)
- **Files modified:** 3 (1 modificado, 2 criados)

## Accomplishments

- `FechamentoRegraTabela::forcar(?bool)` calcula os dois lados (ANTES/DEPOIS) da comparação no MESMO processo PHP, sem tocar `configuracoes` — `forcar(null)` num `finally` devolve o leitor ao estado real mesmo se uma exceção estourar no meio do cálculo.
- `fechamento:comparar-mensalidade --mes= [--json] [--todas]` espelha deliberadamente os Passos 3 e 5 de `ConsolidarMesFechamento` (mesma precedência de cobrança, mesma regra de âncora de grupo) — a montagem de linha de grupo vive num método único (`linhaDeGrupo()`) para não abrir uma quarta cópia divergente dessa lógica (já são três: consolidação, tela administrativa, e este relatório).
- Rodapé com total a receber ANTES/DEPOIS/diferença, contagem de quantas sobem/descem/ficam iguais/mudam de faixa, quantas ficariam **sem régua** (com os nomes) e as 10 maiores quedas/altas em R$.
- Cenário BARAOSHOP provado NO RELATÓRIO sem valor fixado a dedo: o teste soma as duas plataformas e deixa `classificar()` decidir a faixa — o comando calcula R$ 3.000 (não afirma).
- **Defeito real encontrado numa rodada em produção** (competência 2026-08, 201 empresas, antes da materialização das tabelas de empresa — cenário em que TODA empresa cai): a seção "Maiores altas" preenchia com as quedas menos severas quando não havia nenhuma alta de verdade, invertendo o sentido do número. Corrigido: as duas seções agora filtram estritamente pelo sinal da diferença, e o texto diz em palavras quando uma das duas fica vazia.

## Task Commits

1. **Tarefa 1: Calcular os dois lados no mesmo processo** — `bc0e26e8` (feat) — commitado pelo orquestrador porque a sessão anterior foi interrompida pelo limite logo após o código ficar pronto e verde; a árvore compartilhada com outra sessão ativa já havia absorvido trabalho solto em commit alheio três vezes nesta fase.
2. **Tarefa 2: Travar a concordância com o que a consolidação grava** — incluída no mesmo commit `bc0e26e8` (teste + implementação, mesmo padrão pragmático dos planos 141-01/02/03/04).
3. **Correção pós-produção: "Maiores altas" nunca lista diferença negativa** — `a168b9f6` (fix)

## Files Created/Modified

- `app/Services/Fechamento/FechamentoRegraTabela.php` - `forcar(?bool)`: grava só a memória da instância, nunca `configuracoes`; docblock deixa explícito que existe para UM propósito (calcular os dois lados no mesmo processo) e não serve para ligar a regra em produção.
- `app/Console/Commands/CompararMensalidadeFechamento.php` - Comando `fechamento:comparar-mensalidade`; constrói `FechamentoFaixaResolver` manualmente com a MESMA instância de `FechamentoRegraTabela` do comando (não deixa o container resolver uma segunda instância — ver Decisions); `calcularLado()`/`linhaDeGrupo()` espelham `ConsolidarMesFechamento`; `montarResumo()` filtra altas/quedas por sinal; saída `--json` e texto humano.
- `tests/Feature/Phase141/Phase141CompararMensalidadeTest.php` - 5 testes: concordância ANTES (flag desligada) e DEPOIS (flag ligada) contra os 4 perfis exigidos (BARAOSHOP, herança de tabela de serviço, Mentoria, grupo com tabela própria); comando não escreve nada; flag persistida não é alterada; seção de altas fica vazia (com aviso em texto) quando todas as empresas caem.

## Decisions Made

- Ver `key-decisions` no frontmatter — destaque para a descoberta de que `forcar()` só funciona se a mesma instância de `FechamentoRegraTabela` for usada pelo comando E pelo `FechamentoFaixaResolver`; isso não é automático no container Laravel para classes sem binding singleton, e foi a causa do primeiro teste falhar (BARAOSHOP DEPOIS calculava a régua como se a flag estivesse desligada).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] "Maiores altas" listava diferenças negativas quando nenhuma empresa subia**
- **Encontrado em:** rodada manual em produção pelo orquestrador (competência 2026-08, 201 empresas), depois do commit da Tarefa 1/2.
- **Problema:** `montarResumo()` ordenava todas as diferenças e pegava os 10 maiores via `array_reverse()` sem filtrar por sinal — num cenário em que toda empresa cai, a seção "Maiores altas" mostrava as quedas menos severas (ex.: "RAVENA RESKALLA HOME — R$ -2.000,00"), invertendo o sentido do número num instrumento de decisão sobre cobrança de ~200 clientes.
- **Fix:** `montarResumo()` agora filtra `diferenca > 0` para altas e `diferenca < 0` para quedas ANTES de ordenar/recortar top 10; `imprimirRelatorioHumano()` diz em palavras ("nenhuma empresa sobe/desce nesta comparação") quando a seção correspondente fica vazia, em vez de omitir ou preencher com o sinal errado.
- **Arquivos modificados:** `app/Console/Commands/CompararMensalidadeFechamento.php`, `tests/Feature/Phase141/Phase141CompararMensalidadeTest.php`
- **Commit:** `a168b9f6`

---

**Total deviations:** 1 auto-fixed (1 bug, Rule 1)
**Impact on plan:** Correção pontual na formatação do rodapé — não muda nenhuma das interfaces do plano (`--json`, `forcar()`, os 3 campos travados por teste). Sem escopo adicional.

## Issues Encountered

- Primeira versão do comando deixava o container Laravel resolver `FechamentoFaixaResolver` via injeção automática, o que criava uma SEGUNDA instância de `FechamentoRegraTabela` — diferente da que o comando chamava `forcar()`. Resultado: o lado DEPOIS calculava a cobrança certa (`mensalidade()`, branch decidido pela variável local `$regraNova`) mas a régua errada (`paraEmpresa()` lendo a flag persistida, sempre desligada). Corrigido construindo `FechamentoFaixaResolver` manualmente no construtor do comando, com a mesma instância de `$this->regra`.
- O teste original de concordância rodava `fechamento:consolidar-mes` duas vezes (flag desligada, depois ligada) no MESMO método — mesma armadilha de memoização de instância de comando já documentada no plano 141-04 (Phase141ConsolidarRegraNovaTest). Corrigido separando em dois testes, cada um construindo o cenário do zero e rodando a consolidação real uma única vez.
- `valor_contratado` do contrato de "herança de tabela de serviço" precisou ser fixado explicitamente (a factory usa `randomFloat` por padrão) para o teste do estado `ESTADO_VALOR_FIXO` ficar determinístico.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- O plano 141-07 (gate humano de virada da flag) tem agora o instrumento de decisão pronto: `fechamento:comparar-mensalidade --mes= --json` para rodar contra a competência real antes de autorizar a virada.
- ⚠️ Uma rodada real em produção (2026-09-09, competência 2026-08, **antes** da materialização das tabelas de empresa do plano 141-03) mostrou o cenário "chave ligada sem a ponte": praticamente toda empresa cai para `sem_tabela`. Isso é esperado nessa ordem — a materialização (simulada) mostra 168 empresas ganhando tabela própria, 1 já com própria, e 32 continuando sem. O plano 141-07 precisa rodar a comparação DEPOIS da materialização, não antes.
- Dois casos aparecem com mensalidade vindo de contrato de R$ 250.000 (GENUINEAUTOMOTIVE e Lenonn Milani) — sinal de contrato atípico ou erro de cadastro, já reportado ao usuário pelo orquestrador; não é defeito deste comando.
- Gate `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Quick260909"` rodado após a correção: **553 testes / 2642 asserções / 0 falhas** — sem regressão sobre o baseline informado de 552/2631.
- Verificação de leitura pura confirmada: `grep -n "save()\|create(\|update(\|::set(" app/Console/Commands/CompararMensalidadeFechamento.php` não retorna nenhuma linha.

---
*Phase: 141-tabela-progressiva-por-empresa-e-grupo*
*Plan: 05*
*Completed: 2026-09-09*

## Self-Check: PASSED

- `app/Console/Commands/CompararMensalidadeFechamento.php` — FOUND (leitura direta do arquivo).
- `app/Services/Fechamento/FechamentoRegraTabela.php` com `forcar()` — FOUND.
- `tests/Feature/Phase141/Phase141CompararMensalidadeTest.php` (5 testes, 75 asserções) — FOUND, verde.
- Commits `bc0e26e8` e `a168b9f6` confirmados em `git log --oneline`.
- Gate completo (`Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Quick260909`): **553 testes / 2642 asserções / 0 falhas**.
