---
quick_id: 260731-pvk
subsystem: desempenho
tags: [desempenho, bonus, mediana, cache, adman]

requires:
  - phase: 121
    provides: "reguaFaturamento()/reguaMargem() públicas + shadow nota_final_por_empresa"
provides:
  - "computeVarFaturamento() agrega por mediana em vez de média (D-1)"
  - "cacheKey() bumpado para v15 (invalida notas antigas calculadas por média)"
  - "Manual do bônus descreve mediana no faturamento, média na margem"
affects: [desempenho, bonus, ranking, dashboard-carteira]

tech-stack:
  added: []
  patterns:
    - "Illuminate\\Support\\Collection::median() para agregação robusta a outlier, mesmo contrato null-safe de avg()"

key-files:
  created: []
  modified:
    - app/Services/DesempenhoScoreService.php
    - tests/Feature/Phase74/DesempenhoScoreServiceTest.php
    - tests/Feature/DesempenhoShopeeScoreTest.php
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php
    - tests/Feature/Phase116/NpsFloorDesempenhoTest.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
    - resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx

key-decisions:
  - "D-1 (usuário, travada): mediana em vez de piso/filtro/winsorização/cap — nenhuma empresa é excluída, só o peso muda"
  - "D-2 (usuário, travada): computeVarMargem() fica com média de propósito — impacto em faixa de bônus não simulado nesta rodada"

patterns-established:
  - "Bump de cacheKey() sempre acompanhado do fallout das strings hardcoded nos testes que fixam a versão como literal — grep -rn \"desempenho.compute.vN\" app tests é a fonte de verdade"

requirements-completed: []

duration: 55min
completed: 2026-07-31
---

# Quick 260731-pvk: Mediana no cálculo de variação de faturamento da carteira Summary

**`computeVarFaturamento()` troca `Collection::avg()` por `Collection::median()` (cache `v14→v15`), neutralizando o outlier de baseline residual que inflava a nota de faturamento da carteira sem excluir nenhuma empresa.**

## Performance

- **Duration:** ~55 min (mais um incidente de ambiente recuperado no meio do caminho — ver "Issues Encontrados")
- **Started:** 2026-07-31T21:46:00Z (18:46 BRT)
- **Completed:** 2026-07-31T22:40:00Z (19:40 BRT)
- **Tasks:** 3/3
- **Files modified:** 7

## Accomplishments
- `computeVarFaturamento()` (`app/Services/DesempenhoScoreService.php`) agrega as variações de faturamento por empresa via **mediana**, não mais média — reproduzido e provado com o caso concreto do debug `baseline-quase-zero-producao` (outlier +20.000% sobre baseline residual de R$50 não manda mais sozinho na carteira).
- `cacheKey()` bumpado `v14→v15` com docblock de histórico no padrão das entradas anteriores (v7..v14) — sem o bump o Redis serviria a nota antiga (média) por até 7 dias em mês fechado.
- Fallout do bump corrigido nos 4 arquivos de teste que fixavam a versão como string literal (7 ocorrências) — `grep -rn "desempenho.compute.v14" app tests` retorna zero.
- Manual (`DesempenhoBonificacao.jsx`) reescrito: faturamento agora descreve mediana (sem jargão), margem continua descrevendo média; frase obsoleta "empresas com menos de 2 meses" removida (o filtro foi retirado em 2026-07-21).
- `computeVarMargem()`, `reguaFaturamento()`, `reguaMargem()` confirmados intocados (`git diff` limpo nesses trechos, `grep -n "avg()\|median()"` mostra `avg()` intacto em `computeVarMargem` linha 1467).

## Task Commits

1. **Task 1: testes RED (outlier + recalibração da carteira [-2%,+7%,+4%])** — `f6e480d7` (test)
2. **Task 2: `avg()`→`median()` + bump `v14→v15` (GREEN)** — `baceacbe` (feat)
3. **Task 3: fallout das strings de cache + texto do Manual** — `9d8d1f55` (fix)

_Note: sem commit de metadata separado — o `git commit` final desta SUMMARY/STATE roda a seguir._

## Files Created/Modified
- `app/Services/DesempenhoScoreService.php` — `computeVarFaturamento()` usa `median()`; docblocks (método + `cacheKey()`) documentam motivo e a trava D-1/D-2 para o próximo leitor.
- `tests/Feature/Phase74/DesempenhoScoreServiceTest.php` — teste novo do outlier (5 empresas, `-4/-2,5/-1,5/+2/+20000` → mediana `-1,50`) + recalibração do teste de carteira `[-2%,+7%,+4%]` (média `3,00`→mediana `4,00`).
- `tests/Feature/DesempenhoShopeeScoreTest.php`, `tests/Feature/V18/DesempenhoMetadadosCacheTest.php`, `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`, `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` — 7 ocorrências de `v14` trocadas por `v15`.
- `resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx` — texto do card "Variação de Faturamento" reescrito (mediana, sem jargão, sem a regra obsoleta de 2 meses).

## Decisions Made
- D-1 e D-2 vieram travadas do `260731-pvk-CONTEXT.md` (decisão do usuário) — nenhuma decisão nova tomada durante a execução, apenas a implementação literal delas.
- Renomeei os métodos de teste que continham `v14` no nome (`test_cache_key_bumpado_para_v14`→`_v15`, `test_cache_key_esta_na_v14`→`_v15`) para evitar nome de teste enganoso; os métodos de `DesempenhoMetadadosCacheTest` mantiveram o nome histórico `..._sob_v6...` de propósito (o comentário do arquivo já explica que o número no nome é histórico, não literal — só o valor asserted mudou).

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio de Rule 1/2/3/4 no código de produção — o plano foi executado literalmente. O único evento fora do escrito no plano foi operacional (ambiente de teste), documentado abaixo em "Issues Encontrados" porque não alterou nenhuma linha de código de produção nem de teste além do já previsto.

**Total deviations:** 0 no código. 1 incidente operacional autocontido e corrigido (ver abaixo).
**Impact on plan:** Nenhum — o plano foi cumprido exatamente como escrito, incluindo o gate de regressão da Task 3.

## Issues Encontrados

**Incidente de ambiente (fora do escopo do plano, sem impacto em código):** durante a investigação do baseline pré-existente da suíte `--filter=Desempenho`, tentei isolar uma execução "antes da quick" criando um `git worktree` num commit anterior e reaproveitando `vendor/` via **junction** do Windows (`mklink /J`) para evitar reinstalar dependências. Ao remover o worktree (`git worktree remove --force`), a limpeza seguiu através da junction e apagou o conteúdo real de `vendor/` no repositório principal (diretório fica vazio, não rastreado pelo git). Restaurado imediatamente com `php composer.phar install --ignore-platform-reqs` (o `composer.lock` exige `php-64bit ^8.3` por causa do `maennchen/zipstream-php`, mas o ambiente local roda PHP 8.2 — pré-existente, não criado por mim). Confirmado após a restauração: `composer.lock` sem diff, suíte de teste voltando ao mesmo resultado de antes do incidente (4 falhas pré-existentes, 12 passando em `DesempenhoScoreServiceTest`). Nenhum arquivo rastreado pelo git foi perdido — `vendor/` é gitignored. Lição registrada: não usar junctions apontando para diretórios compartilhados ao criar worktrees temporários; a investigação de baseline subsequente foi refeita com métodos seguros (raciocínio analítico sobre a contagem de testes + instrumentação pontual e revertida com `dump()`, nunca troca de arquivo via `git checkout <ref> -- <path>` nem cópia bruta).

**Determinação do baseline pré-existente (sem revert de arquivo):** em vez de reverter fisicamente `app/Services/DesempenhoScoreService.php`, usei aritmética de contagem de testes (114 testes antes + 1 novo = 115; observado 19 falhas pós-Task2 = 14 pré-existentes + 5 causadas pelo bump `v14→v15` ainda não corrigido, exatamente as 5 que a Task 3 existe para corrigir) e instrumentação pontual com `dump()` (adicionada e revertida no mesmo commit lógico, sem sobrar no `git diff`) para confirmar que as 6 falhas do gate ampliado fora do filtro `--filter=Desempenho` (`PerformanceIndexMetadadosTest`, `CarteiraPeriodoDiffTest`×2, `ConsolidarMesJanelaNpsTest`×2, `JanelaNpsBonusTest`) são todas causadas por `var_margem_pct=null` (código de margem, **não tocado por esta quick**) — nunca por `var_faturamento_pct`, que segue correto (`0.0`/`3.0 pts`) nesses cenários. Prova matemática complementar: mediana de uma coleção de 1 elemento é idêntica à média — o caso de carteira única do `JanelaNpsBonusTest` não poderia mudar por causa da mediana.

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

- Código pronto para deploy — mas **deploy não foi executado** (fora deste plano, D-4 do CONTEXT: o orquestrador conduz com o usuário depois).
- Pendente pós-deploy: `desempenho:consolidar-mes --mes=2026-06` para reconsolidar a competência de junho com a mediana — conferência obrigatória por reconsulta ao snapshot (gate FIXMARG-03 só reporta contagem no stdout).
- **NÃO rodar `cache:clear`** no VPS — o bump de chave já invalida as entradas antigas por TTL; `cache:clear` derrubou o site inteiro em 2026-07-30 (systemctl reload php8.2-fpm + adman:warm-diff se necessário).
- 14 falhas pré-existentes em `--filter=Desempenho` (todas relacionadas a `var_margem_pct`/`calculated_fallback`, não a faturamento) permanecem fora do escopo desta quick — candidatas a uma investigação própria futura (a instabilidade de margem já está documentada na memória do projeto).

---
*Quick: 260731-pvk*
*Completed: 2026-07-31*

## Self-Check: PASSED

- FOUND: `.planning/quick/260731-pvk-mediana-no-calculo-de-variacao-de-fatura/260731-pvk-SUMMARY.md`
- FOUND: commit `f6e480d7` (Task 1, RED)
- FOUND: commit `baceacbe` (Task 2, GREEN)
- FOUND: commit `9d8d1f55` (Task 3, fallout + Manual)
