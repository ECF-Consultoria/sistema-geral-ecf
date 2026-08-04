---
phase: 123-telas-e-relat-rios-v21-0
plan: 05
subsystem: desempenho
tags: [laravel, eloquent, react, inertia, phpunit, node-test, desempenho-por-empresa]

# Dependency graph
requires:
  - phase: 123-01
    provides: "CompanyScoreSnapshotReader (paraUsuarios/resumo) e desempenhoLabels.js (AVISO_SEM_DETALHE_TITULO, avisoSemDetalheFechado) — fundações compartilhadas da fase"
  - phase: 123-04
    provides: "EmpresasScoreTabela.jsx — componente reutilizável de duas seções, pronto para reuso fora de Performance/Show.jsx"
provides:
  - "RelatorioBonificacaoController::montarLinhas() com empresas_score/empresas_score_resumo/tem_detalhe_empresas por profissional contemplado, lidos de CompanyScoreSnapshotReader::paraUsuarios() numa única query"
  - "Desempenho/RelatorioBonificacao.jsx com linha expansível por profissional reusando EmpresasScoreTabela (D-08)"
  - "pdf() e a blade do relatório comprovadamente intocados (D-09)"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Detalhe por empresa sempre lido fora do map() de profissionais via paraUsuarios() — mesmo padrão dos Planos 02/03 (Performance/Show e Auditoria), agora replicado no terceiro e último controller da fase"
    - "Linha expansível em tabela HTML via Fragment com key no map(), coluna-gatilho reaproveitando a célula existente (#) em vez de adicionar coluna nova"

key-files:
  created:
    - tests/Feature/Phase123/RelatorioBonificacaoEmpresasTest.php
    - tests/js/estrutura-relatorio-bonificacao.test.js
  modified:
    - app/Http/Controllers/RelatorioBonificacaoController.php
    - resources/js/Pages/Desempenho/RelatorioBonificacao.jsx

key-decisions:
  - "pdf() não recebeu nenhuma linha tocada — apenas montarLinhas() e o construtor mudaram; confirmado por git diff isolado do método e por teste que lê a blade com assertStringNotContainsString"
  - "A seta de expansão reaproveita a célula '#' existente (envolvida num flex) em vez de abrir uma nona coluna — mantém as 8 colunas da D-08 ('a tabela continua uma linha por profissional')"
  - "A linha do profissional (<tr>) também aciona alternar() no onClick, com stopPropagation() no botão da seta para não disparar duas vezes"

requirements-completed: [UIEM-04]

# Metrics
duration: ~20min
completed: 2026-08-04
---

# Phase 123 Plan 05: Relatório de Bonificação — detalhe por empresa (UIEM-04) Summary

**`RelatorioBonificacaoController::montarLinhas()` passa a entregar `empresas_score`/`empresas_score_resumo`/`tem_detalhe_empresas` por profissional contemplado, lidos de `CompanyScoreSnapshotReader::paraUsuarios()` numa única query, e `Desempenho/RelatorioBonificacao.jsx` ganha linha expansível reusando `EmpresasScoreTabela` (o mesmo componente da tela individual) — o PDF e sua blade continuam comprovadamente intocados.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-04T14:35:00Z (aprox.)
- **Completed:** 2026-08-04T14:55:00Z (aprox.)
- **Tasks:** 2 completed
- **Files modified:** 4 (2 criados, 2 modificados)

## Accomplishments
- UIEM-04 fechada por inteiro: os três controllers da fase (`PerformanceController::show()`, `BonusAuditoriaController::index()`, `RelatorioBonificacaoController`) agora leem o detalhe por empresa da MESMA fonte única (`CompanyScoreSnapshotReader`), nunca do `breakdown_json['empresas_score']`
- `montarLinhas()` acrescenta as três chaves só para quem passa no filtro de bônus (faixa ≠ `sem_bonus`), com a leitura de `paraUsuarios()` feita UMA vez fora do `map()` — 1 query por página, independente do número de profissionais contemplados
- `pdf()` e `resources/views/pdf/relatorio-bonificacao.blade.php` ficam comprovadamente intocados (D-09): `git diff` vazio na blade, nenhuma linha alterada dentro do método `pdf()`, e teste dedicado lê a blade em disco e assere `assertStringNotContainsString('empresas_score', ...)` / `('nota_empresa', ...)`
- `Desempenho/RelatorioBonificacao.jsx` ganha linha expansível por profissional: nasce tudo fechado, a seta reaproveita a célula `#` existente (sem coluna nova, preservando as 8 colunas da D-08), e expande para `EmpresasScoreTabela` (com o mesmo formato `14,1% → 12,0% −2,1`, motivos traduzidos e selo Shopee da tela individual) ou para o aviso da D-03 quando `tem_detalhe_empresas` é `false`

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: montarLinhas() entrega o detalhe por profissional (D-08) e o PDF fica intocado (D-09)** - `b8f4e441` (feat)
2. **Task 2: linha expansível no Relatório de Bonificação reusando EmpresasScoreTabela** - `23485f14` (feat)

_Nenhuma task teve TDD explícito (`tdd="true"` não estava setado no plano); ambas seguiram implementação direta + teste na mesma task, conforme especificado._

## Files Created/Modified
- `app/Http/Controllers/RelatorioBonificacaoController.php` - Injeta `CompanyScoreSnapshotReader`; `montarLinhas()` lê `paraUsuarios()` uma vez fora do `map()` e acrescenta `empresas_score`/`empresas_score_resumo`/`tem_detalhe_empresas` a cada profissional contemplado; `pdf()` e `index()` inalterados em comportamento
- `resources/js/Pages/Desempenho/RelatorioBonificacao.jsx` - Estado `abertos` (Set) + `alternar(id)`; seta na célula `#` (ChevronRight/ChevronDown); linha de expansão com `EmpresasScoreTabela` ou aviso da D-03; defaults defensivos (`?? []`, `?? { entraram: 0, nao_entraram: 0 }`, `=== true`); rodapé com a dica da seta; 8 colunas, formatadores locais, `FaixaBadge` e botão de exportar PDF intocados
- `tests/Feature/Phase123/RelatorioBonificacaoEmpresasTest.php` - 6 testes: caminho comum (3 linhas, resumo batendo com `status === 'complete'`), mesma fonte (blob JSON vazio não afeta a lista), ausência de detalhe (D-03), regressão do PDF (200 + Content-Type + blade sem `empresas_score`/`nota_empresa`), 1 query só com 3 profissionais, não-contemplado não vaza mesmo com linhas gravadas
- `tests/js/estrutura-relatorio-bonificacao.test.js` - 5 gates estruturais via `lerSemComentarios`: uso de `EmpresasScoreTabela`/`useState`, leitura de `tem_detalhe_empresas`/`empresas_score_resumo`, texto do aviso vindo do módulo compartilhado, anti-hardcode do texto de ausência, botão de PDF apontando pra rota correta

## Decisions Made
- `pdf()` não sofreu nenhuma edição — apenas `montarLinhas()` (chamada por ambos os métodos) e o construtor mudaram; a garantia foi verificada tanto por `git diff` isolado quanto por teste que lê a blade em disco
- A seta de expansão foi encaixada dentro da célula `#` existente (não como coluna nova) para preservar literalmente "a tabela continua uma linha por profissional" e as 8 colunas já testadas
- `<tr>` do profissional também dispara `alternar()` no `onClick` (clicar em qualquer parte da linha expande), com `stopPropagation()` no botão da seta para não alternar duas vezes por clique

## Deviations from Plan

None - plano executado exatamente como escrito.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- UIEM-04 fechada por completo nesta fase (Planos 03 e 05 juntos cobriram Auditoria e Relatório)
- `git diff --stat app/Services/` vazio nesta sessão — nenhum arquivo de serviço tocado
- `--filter=RelatorioBonificacaoEmpresasTest` 6/6, `--filter=RelatorioBonificacaoTest` (preexistente) 4/4, `--filter=Phase123` 41/41; `npm run test:js` 124/125 (1 falha pré-existente e não relacionada em `estrutura-grade-glide.test.js`, arquivo intocado nesta sessão, já documentada como baseline desde o 123-01-SUMMARY.md); `npm run build` sem erro
- Fase 123 (v21.0) fica pronta para fechamento — os 5 planos (fundações, Performance/Show, Auditoria, componente reutilizável, Relatório de Bonificação) estão completos

---
*Phase: 123-telas-e-relat-rios-v21-0*
*Completed: 2026-08-04*

## Self-Check: PASSED

Os 4 arquivos criados/modificados confirmados em disco (`RelatorioBonificacaoController.php`, `RelatorioBonificacao.jsx`, `RelatorioBonificacaoEmpresasTest.php`, `estrutura-relatorio-bonificacao.test.js`); os 2 commits de task (`b8f4e441`, `23485f14`) confirmados em `git log --oneline --all`.
