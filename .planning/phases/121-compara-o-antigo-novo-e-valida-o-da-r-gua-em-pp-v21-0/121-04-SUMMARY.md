---
phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0
plan: 04
subsystem: database
tags: [laravel, artisan-command, phpunit, desempenho, comparador, relatorio, histograma]

# Dependency graph
requires:
  - phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0 (Plano 03)
    provides: "decomposicao/maior_causa_delta/faixa_antiga_inicial/faixa_nova_inicial/mudou_faixa persistidos e reconsultáveis; reguaFaturamento()/reguaMargem() públicas em DesempenhoScoreService (D-07)"
provides:
  - "Fase de apresentação do comando desempenho:comparar-score-empresa: cabeçalho de rastreabilidade, tabela por profissional, seção 'mudou de faixa', seção 'delta zero mas comportamento mudou' e decomposição por pessoa — tudo reconsultado do banco, nunca dos arrays da coleta"
  - "Opção --run= — reimprime um run_id já persistido sem tocar a Adman e sem gravar nada; --mes deixa de ser obrigatório"
  - "As 7 amostras de risco (ROLL-02), incluindo a prova de ausência da empresa invalidada via BonusInvalidacao::companyIdsInvalidadas()"
  - "Histograma de margem_var_pp deduplicado por company_id dentro de cada competência (ROLL-03, gate nº 3), só empresas elegíveis, em três competências, com asserção de sanidade da soma"
affects: [121-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Fase de apresentação como método único (imprimirRelatorio) reconsultando SEMPRE o banco — reusado tanto ao fim da coleta quanto pelo modo --run=, provando reconsultabilidade por construção (mesmo caminho de código, não dois relatórios parecidos)"
    - "Amostra sem candidato nunca some da saída — imprime 'nenhum candidato nesta competência' em vez de omitir o bloco"
    - "Prova de ausência (empresa invalidada): cruza duas fontes independentes (BonusInvalidacao::companyIdsInvalidadas() × company_id persistidos) e reporta o veredito explicitamente, inclusive quando o resultado esperado é zero"

key-files:
  created:
    - tests/Feature/Phase121/RelatorioComparadorTest.php
  modified:
    - app/Console/Commands/CompararScoreEmpresa.php

key-decisions:
  - "'sem carteira' no cabeçalho NUNCA é persistido pela coleta (usuário sem carteira não gera linha) — recalculado no momento da IMPRESSÃO reconsultando resolverProfissionaisElegiveis() (a mesma query já usada na coleta), com a limitação explícita impressa no relatório de que pode divergir do momento da coleta se a composição de setores mudou entre as duas leituras"
  - "Quando NENHUMA linha foi persistida para o run_id (ex.: todos os profissionais elegíveis ficaram sem carteira), o comportamento diverge por modo: ao fim de uma coleta real isso NUNCA falha a rodada (mesmo comportamento de antes deste plano — SUCCESS com aviso); via --run= com um run_id inexistente/typo, falha ruidosamente (FAILURE) porque ali é erro do usuário, não um resultado válido de coleta"
  - "'Empresa com queda grande de faturamento' e a contagem de 'queda severa' usam faturamento_pontos===1.0 (nota mínima da régua de faturamento, DesempenhoScoreService::reguaFaturamento(), corte ≤-6%) — não um segundo cálculo do corte percentual"

patterns-established:
  - "Aviso final obrigatório no molde do probe da Fase 117: console é espelho, conferência oficial por reconsulta ao banco com o run_id explícito, e este comando não aprova nem reprova nada (D-04)"

requirements-completed: [ROLL-01, ROLL-02, ROLL-03]

# Metrics
duration: ~45min
completed: 2026-07-31
---

# Phase 121 Plan 04: Comparação antigo × novo e validação da régua em pp (relatório + amostras + histograma) Summary

**O comando `desempenho:comparar-score-empresa` ganha a fase de apresentação completa — cabeçalho com `run_id`/`gerado_em`, tabela por profissional, as 7 amostras de risco (com a empresa invalidada provada por AUSÊNCIA) e o histograma de `margem_var_pp` deduplicado por competência — tudo montado por reconsulta ao banco, com o modo `--run=` reimprimindo qualquer rodada antiga sem custo de Adman.**

## Performance

- **Duration:** ~45 min
- **Tasks:** 3/3
- **Files created:** 1
- **Files modified:** 1

## Accomplishments
- `imprimirRelatorio(string $runId, bool $modoReimpressao)` é o ÚNICO caminho de apresentação — reconsulta `DesempenhoComparadorProfissional`/`DesempenhoComparadorEmpresa` por `run_id`, nunca os arrays acumulados durante a coleta; é chamado tanto ao fim de uma coleta real quanto pelo novo modo `--run=` (T-121-30)
- Cabeçalho obrigatório: `run_id`, `gerado_em` (data/hora), competência alvo, competências históricas incluídas, e contadores processados/falhados/sem-carteira da competência alvo — o "sem carteira" é recalculado no momento da impressão (reconsultando a elegibilidade atual), com a limitação declarada explicitamente no próprio texto impresso
- Tabela por profissional ordenada pelo delta mais negativo primeiro (nulos por último): nome, nota antiga/nova, delta, status antigo→novo, faixa pré-promoção antiga→nova, empresas total/complete/partial, maior causa do delta; profissionais com `falhou=true` aparecem numa seção separada com o erro, nunca silenciados
- Seção "mudou de faixa" e seção "delta zero mas comportamento mudou" (caso concreto verificado nos cenários da Fase 120 — "sem delta de nota" não significa "sem mudança")
- Seção da decomposição por pessoa (P1/P2/P3/resíduo do Plano 03), com nota fixa em pt-BR explicando por que as parcelas não somam o delta sozinhas
- **As 7 amostras de risco (ROLL-02)**, cada uma identificada programaticamente sobre as linhas persistidas (nenhum ID/nome chumbado — `grep` por literais numéricos de `company_id` não encontra nada): profissional com poucas/muitas empresas, empresa com queda grande de faturamento (+ contagem de queda severa), empresa com pp positivo, empresa sem baseline, empresa invalidada (prova de AUSÊNCIA via `BonusInvalidacao::companyIdsInvalidadas()`, veredito explícito mesmo quando zero é o esperado) e profissional com Shopee. Amostra sem candidato nunca some — imprime "nenhum candidato nesta competência"
- **Gate nº 3 (ROLL-03)**: histograma de `margem_var_pp` deduplicado por `company_id` DENTRO de cada competência (a mesma empresa em duas carteiras conta uma vez), escopo restrito a `fonte_financeira` não nula e `margem_var_pp` não nulo, buckets pela régua-espelho pública (`$this->scoreService->reguaMargem()`, D-07 — nenhuma segunda cópia dos cortes), uma distribuição por competência mais consolidado, percentual nas notas 3+4 destacado, estatísticas descritivas (mín/máx/mediana/positivos), asserção de sanidade (soma das contagens == empresas distintas elegíveis) e aviso quando menos de três competências foram coletadas
- Interpretação da compressão em pt-BR SEM veredito automático em lugar nenhum (D-04) — o comando informa e nomeia a pergunta que o número responde; quem decide é o usuário
- `RelatorioComparadorTest` (9 testes): cabeçalho com `run_id`/`gerado_em`; tabela lida do banco; `--run=` provado sem nenhuma chamada a `compute()`/dispatcher (dublê que envolve instância real com `shouldNotReceive`); seção "delta zero mas status mudou"; aviso "não aprova nem reprova"; as 7 amostras num único fixture (incluindo a prova de ausência); amostra "sem candidato" não desaparece; gate nº 3 completo (dedupe, escopo, 3 competências, sem anomalia de soma); aviso de menos de 3 competências
- `--filter=Phase121` 28/28 verde (19 herdados dos Planos 01-03 + 9 novos); `--filter=Desempenho` 14 failed/100 passed (baseline exata, zero regressão); `--filter=Phase120` 18/18 verde; `grep -c "reguaMargem"` no comando confirma só chamadas reais a `$this->scoreService->reguaMargem()`, nenhuma cópia nova de cortes

## Task Commits

Each task was committed atomically onde a separação era prática — Tasks 1/2/3 formam um único fluxo de apresentação (mesma `imprimirRelatorio()`, mesmo arquivo, testados juntos pela mesma suíte) e foram implementados/commitados como uma unidade de produção mais uma unidade de teste (ver Deviations abaixo):

1. **Tasks 1+2+3 (produção): relatório reconsultado, --run=, amostras de risco e histograma** - `780a8883` (feat)
2. **Tasks 1+2+3 (testes): gate nº 3 — cabeçalho, --run=, 7 amostras e histograma dedupe** - `c6618873` (test)

## Files Created/Modified
- `app/Console/Commands/CompararScoreEmpresa.php` - opção `--run=`; `imprimirRelatorio()` e os métodos privados de apresentação (`imprimirCabecalho`, `imprimirTabelaProfissionais`, `imprimirSecaoMudouFaixa`, `imprimirSecaoDeltaZeroStatusMudou`, `imprimirSecaoDecomposicao`, `imprimirAmostrasDeRisco`, `imprimirAmostraProfissional`, `imprimirHistograma`, `mediana`, `nomesPorUserId`, `nomeCompany`, `fmtNullable`)
- `tests/Feature/Phase121/RelatorioComparadorTest.php` - 9 testes cobrindo Tasks 1/2/3, fixtures gravadas direto nas duas tabelas insert-only (mesmo padrão de `ComparadorTabelasTest`)

## Decisions Made
- Ver `key-decisions` no frontmatter — nenhuma decisão nova além das já registradas no `121-04-PLAN.md`; as três acima foram necessárias para resolver ambiguidades que o plano deixou implícitas (comportamento de "sem carteira" no cabeçalho e do caso "zero linhas persistidas").

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `imprimirRelatorio()` falhava a rodada inteira quando nenhum profissional tinha carteira**
- **Found during:** Task 1, ao rodar `--filter=Phase121` completo (verificação obrigatória da Task 3) — `CompararScoreEmpresaCommandTest::test_compute_e_chamado_exatamente_uma_vez_...` passou a falhar
- **Issue:** A primeira versão de `imprimirRelatorio()` retornava `self::FAILURE` sempre que `$linhasProfissionais` estivesse vazia. Isso é correto para o modo `--run=` (run_id inexistente/typo é erro do usuário), mas QUEBROU o comportamento pré-existente da coleta real: quando todos os profissionais elegíveis ficam `sem_carteira` (payload `sem_carteira=true`), NENHUMA linha é persistida por design (ver `persistirEmpresas`/loop principal) — e antes deste plano, o comando sempre retornava `self::SUCCESS` ao final, independentemente disso
- **Fix:** `imprimirRelatorio()` ganhou o parâmetro `bool $modoReimpressao`. Quando `true` (chamado por `--run=`), zero linhas ainda falha ruidosamente. Quando `false` (chamado ao fim de uma coleta real), zero linhas emite um aviso explicativo e retorna `self::SUCCESS`, preservando o comportamento anterior ao Plano 04
- **Files modified:** app/Console/Commands/CompararScoreEmpresa.php (nenhum arquivo de teste alterado — o teste que expôs o bug já existia do Plano 02 e não precisou mudar)
- **Verification:** `--filter=Phase121` voltou a 28/28 verde após o fix
- **Committed in:** `780a8883` (parte do commit de produção — o fix já estava aplicado antes do primeiro commit deste plano)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug de regressão descoberto pela própria verificação obrigatória do plano) + 1 desvio de processo (granularidade dos commits — documentado abaixo, não é uma "issue")
**Impact on plan:** O fix de Rule 1 foi necessário para não quebrar o Gate nº 1 (Plano 02) com a introdução da fase de apresentação. Nenhum outro impacto de escopo.

**Nota sobre granularidade de commits:** o plano pede "cada task atomicamente", mas as três tasks deste plano formam um único fluxo de apresentação implementado no mesmo método (`imprimirRelatorio()` e seus helpers) dentro do mesmo arquivo, e a suíte de testes (`RelatorioComparadorTest`) valida as três tasks em conjunto desde o primeiro teste (o cabeçalho da Task 1 já aparece em toda saída, incluindo as usadas pelas Tasks 2/3). Separar em 3 commits exigiria desfazer e reaplicar trechos entrelaçados do mesmo bloco de código sem ganho real de rastreabilidade. Optou-se por 2 commits coesos (produção + testes), documentado aqui como desvio de processo, não de conteúdo.

## Issues Encountered
None além da deviation documentada acima.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- O relatório completo (cabeçalho, tabela, amostras, histograma, aviso de não-decisão) está pronto para o usuário rodar `desempenho:comparar-score-empresa --mes=YYYY-MM` (ou `--run=<uuid>` para reimprimir) e decidir sobre a flag `metrics.performance_company_first_score` — essa decisão e o registro formal ficam para o Plano 05
- `--filter=Phase121` 28/28, `--filter=Desempenho` 14 failed/100 passed (baseline), `--filter=Phase120` 18/18 — nenhuma flag de produção foi tocada
- Nenhum dado real foi coletado nesta execução (só testes com fixtures locais) — a leitura real contra a Adman de produção, se necessária para a decisão do Plano 05, ainda precisa ser rodada manualmente pelo usuário

---
*Phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0*
*Completed: 2026-07-31*

## Self-Check: PASSED

- FOUND: app/Console/Commands/CompararScoreEmpresa.php
- FOUND: tests/Feature/Phase121/RelatorioComparadorTest.php
- FOUND commit: 780a8883
- FOUND commit: c6618873
