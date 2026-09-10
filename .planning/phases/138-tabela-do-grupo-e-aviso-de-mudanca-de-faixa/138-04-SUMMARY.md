---
phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
plan: 04
subsystem: backend
tags: [laravel, inertia, phpunit, fechamento, faixas-de-faturamento]

# Dependency graph
requires:
  - phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
    plan: "01"
    provides: "GrupoFaixaFaturamento, FechamentoFaixaResolver::paraGrupo()/paraEmpresa() com precedência grupo → empresa → serviço"
provides:
  - "AdminController::fechamentoAgregarGruposAoVivo() usando paraGrupo() com fallback defensivo"
  - "AdminController::fechamentoAgregarGruposCongelados() derivando herança do snapshot sem recálculo (D-11)"
  - "Chaves tabela_grupo_nome/tabela_herdada_de_nome sempre presentes na linha do grupo, nos dois ramos"
  - "fechamentoFaixasPorGrupo() + prop faixas_por_grupo em /administrativo/financeiro"
affects: [138-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Catálogo de grupo com 2 queries fixas (faixas + nomes) em vez do padrão N+1 de fechamentoFaixasPorServico() — molde melhorado, não copiado literalmente"
    - "Herança derivada de coluna existente (tabela_origem), nunca coluna nova — mesma disciplina do plano 03"

key-files:
  created:
    - tests/Feature/Phase138/Phase138FinanceiroGrupoPropsTest.php
  modified:
    - app/Http/Controllers/AdminController.php

key-decisions:
  - "fechamentoFaixasPorGrupo() NÃO copiou o padrão N+1 de fechamentoFaixasPorServico() (uma query ServicoFaixaFaturamento por serviço) — o plano exigia explicitamente 'uma consulta só, com eager loading', então usei 2 queries fixas (uma pra todas as faixas agrupadas por company_group_id, uma pra nomes dos grupos que têm alguma faixa), sem tocar em CompanyGroup.php (fora do files_modified)"
  - "tabela_grupo_nome e tabela_herdada_de_nome nunca vêm preenchidas juntas — mutuamente exclusivas por construção, refletindo a regra 'grupo OU herdado, nunca os dois'"
  - "No ramo ao vivo, \$grupo = \$ancora->grupo foi movido pra antes da chamada de paraGrupo() (precisava dele ali) e a atribuição duplicada mais abaixo no código foi removida — não é mudança de comportamento, só elimina uma reatribuição redundante"

requirements-completed: [D-01]

# Metrics
duration: ~35min
completed: 2026-09-03
---

# Phase 138 Plan 04: Tabela do Grupo e Herança Visível nas Props do Fechamento Summary

**`AdminController::fechamento()` alinhado à precedência grupo → empresa → serviço (D-01) nos dois ramos (ao vivo e congelado), com `tabela_herdada_de_nome` tornando visível de qual empresa a tabela do grupo foi herdada quando ele não tem tabela própria.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-03
- **Completed:** 2026-09-03
- **Tasks:** 3 (ramo ao vivo, ramo congelado + catálogo, teste)
- **Files modified:** 2 (1 controller ampliado, 1 teste novo)

## Accomplishments
- `fechamentoAgregarGruposAoVivo()` troca `paraEmpresa($ancora)` por `paraGrupo($ancora->grupo, $ancora)`, com fallback defensivo pra `paraEmpresa()` só se o grupo vier nulo — a soma do grupo agora é classificada pela tabela do PRÓPRIO grupo quando ela existe, não mais sempre pela tabela da âncora
- `fechamentoAgregarGruposCongelados()` deriva as mesmas chaves do snapshot (`$s->tabela_origem`, `$s->empresa_ancora_id`) sem nenhum recálculo — disciplina D-11 mantida; provado por teste que cadastrar tabela de grupo DEPOIS do fechamento não muda a origem já congelada
- Duas chaves novas, sempre presentes (null quando não se aplica) nos DOIS ramos: `tabela_grupo_nome` (pra tela escrever "Tabela deste grupo" sem adivinhar) e `tabela_herdada_de_nome` (nome da empresa de quem a tabela foi herdada) — é essa segunda chave que mata a herança invisível descrita em D-01
- `fechamentoFaixasPorGrupo()` novo: catálogo dos grupos com tabela própria cadastrada, com 2 queries fixas (nunca uma por grupo — mitigação de T-138-11), exposto como prop `faixas_por_grupo`
- Comentário no cálculo de `tabelas_divergentes` explicando por que não precisou de caso especial (mesmo raciocínio do plano 03: o degrau de grupo do 138-01 já colapsa o conjunto de pares)
- 6 testes novos cobrindo os 5 casos pedidos: herança em mês aberto, tabela própria em mês aberto, as duas variantes em mês fechado (com prova de não-recálculo), catálogo `faixas_por_grupo` e não-regressão das chaves da Fase 137

## Task Commits

1. **Tarefa 1: Ramo ao vivo usa paraGrupo() e expõe a herança** - `614df90b` (feat)
2. **Tarefa 2: Ramo congelado e catálogo de tabelas de grupo** - `bdfec959` (feat)
3. **Tarefa 3: Teste de props de grupo** - `7c21737c` (test)

## Files Created/Modified
- `app/Http/Controllers/AdminController.php` - `fechamentoAgregarGruposAoVivo()` usa `paraGrupo()`; `fechamentoAgregarGruposCongelados()` deriva herança do snapshot; `fechamentoFaixasPorGrupo()` novo; prop `faixas_por_grupo` no `Inertia::render`
- `tests/Feature/Phase138/Phase138FinanceiroGrupoPropsTest.php` - 6 testes / 50 asserções cobrindo os dois ramos

## Decisions Made
- `fechamentoFaixasPorGrupo()` não copiou literalmente o padrão de `fechamentoFaixasPorServico()` (que faz 1 query `ServicoFaixaFaturamento` por serviço candidato) porque o plano exigia explicitamente ausência de N+1 no catálogo de grupos (T-138-11); implementei com 2 queries fixas (faixas agrupadas + nomes dos grupos com alguma faixa) em vez de 1 query por grupo
- Não adicionei relação nova em `CompanyGroup.php` (ficaria fora do `files_modified` do plano) — a consulta de nomes usa `CompanyGroup::whereIn('id', ...)` direto, sem depender de relação eager-loaded no model
- `tabela_grupo_nome`/`tabela_herdada_de_nome` são mutuamente exclusivas por construção (nunca as duas preenchidas na mesma linha) — reflete a regra "grupo OU herdado, nunca os dois" do resolver

## Deviations from Plan

None - plano executado exatamente como escrito. As duas chaves novas, a assinatura de `paraGrupo()` reutilizada e a disciplina D-11 no ramo congelado seguem literalmente o `<objective>`/`<tasks>` do plano.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- `faixas_por_grupo` e `tabela_grupo_nome`/`tabela_herdada_de_nome` prontos pro plano 06 (CRUD/tela) consumir e exibir/pré-preencher a tabela do grupo
- `ConsolidarMesFechamento.php` não foi tocado (fora do escopo — plano 138-03 rodou em paralelo nesse arquivo, sem conflito de merge esperado)
- Gate combinado `Phase122|Phase136|Phase137|Phase138`: **260 testes / 1377 asserções / 0 falhas** (medido após os 3 commits deste plano; baseline antes deste plano era 254/1327 — sem regressão, todos os 6 testes/50 asserções novos são deste plano)

---
*Phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa*
*Completed: 2026-09-03*

## Self-Check: PASSED

Todos os arquivos-chave e os 3 commits do plano foram confirmados no disco/histórico do git.
