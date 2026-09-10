---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 03
subsystem: fechamento
tags: [laravel, eloquent, tdd, faturamento, faixas-progressivas]

# Dependency graph
requires:
  - phase: 137-01
    provides: "servico_faixas_faturamento, empresa_faixas_faturamento (tabelas + models ServicoFaixaFaturamento/EmpresaFaixaFaturamento), Company::faixasFaturamento(), Servico::faixasFaturamento()"
provides:
  - "FechamentoFaixaResolver::paraEmpresa() — resolve a tabela de faixas aplicável a uma empresa (herança serviço→empresa, exceção all-or-nothing D-13, contrato combinado D-05)"
  - "FechamentoFaixaResolver::classificar() — classifica um faturamento na tabela, com faixa-piso (valor_e_piso) e null explícito quando não há faixa aberta"
  - "FechamentoRollupService::janela() — janela de mês-calendário fechado (D-06), delega ao MetricPeriodResolver"
  - "FechamentoRollupService::porEmpresa() — faturamento ML+Shopee somado por empresa (D-05, D-07), ausência distinta de zero"
affects: [137-04, 137-05, 137-06, 137-07, 137-08]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Resolução de serviço-dono por contrato combinado (grupo por chave = dono quando dono é candidato ativo, senão o próprio serviço) — mesmo desenho de ContratoClicksignService::iniciarParaEmpresa()"
    - "classificar() opera sobre Collection genérica (aceita tanto ServicoFaixaFaturamento quanto EmpresaFaixaFaturamento) por acessar só ordem/limite_superior/valor/valor_e_piso via property access"

key-files:
  created:
    - app/Services/Fechamento/FechamentoFaixaResolver.php
    - app/Services/Fechamento/FechamentoRollupService.php
    - tests/Feature/Phase137/Phase137FaixaResolverTest.php
    - tests/Feature/Phase137/Phase137RollupTest.php
  modified: []

key-decisions:
  - "Candidato a tabela de faixas é servico.plataforma preenchida OU servico.setor em SETORES_FINANCEIROS (OU, não só setor) — robustez contra setor mal configurado num serviço novo, sem alterar o resultado nos 3 serviços medidos em produção"
  - "Desempate entre grupos independentes (sem dono comum) segue plataforma Mercado Livre antes de Shopee, depois menor servicos.id — não coberto por teste (não ocorre nos 11 cenários do plano), documentado no código"
  - "porEmpresa() só inclui empresas sem nenhuma coluna de métrica quando $companies é passado explicitamente — evita que 'sem faturamento' vire ausência silenciosa de linha quando o caller já sabe quais empresas quer"

requirements-completed: [D-01, D-02b, D-05, D-06, D-07, D-13]

# Metrics
duration: 45min
completed: 2026-09-02
---

# Phase 137 Plano 03: FechamentoFaixaResolver e FechamentoRollupService Summary

**Dois serviços puros de leitura: um resolve qual tabela progressiva vale para uma empresa (herança serviço→empresa com exceção all-or-nothing e contrato combinado), outro soma o faturamento ML+Shopee em mês-calendário fechado — substituindo de vez a janela móvel de 30 dias e a fonte `company_monthly_revenues`.**

## Performance

- **Duration:** ~45 min
- **Started:** 2026-09-02 (wave 2, após 137-01/137-02 concluídos)
- **Completed:** 2026-09-02
- **Tasks:** 2
- **Files modified:** 4 (2 services criados, 2 testes criados)

## Accomplishments
- `FechamentoFaixaResolver::paraEmpresa()` resolve a tabela aplicável (própria da empresa ou herdada do serviço), respeitando D-13 (exceção all-or-nothing) e D-05 (contrato combinado Shopee→Gestão, dono vence quando ativo)
- `FechamentoFaixaResolver::classificar()` classifica um faturamento na tabela, devolvendo `valor_e_piso` para a faixa "a partir de R$ X" e `null` explícito quando nenhuma faixa cobre o valor (Shopee sem faixa aberta) — nunca a última faixa por aproximação
- `FechamentoRollupService::janela()` delega ao `MetricPeriodResolver` (implementação única já testada), eliminando qualquer `subDays(30)` do fechamento
- `FechamentoRollupService::porEmpresa()` soma `adman_metrics` + `shopee_metrics` por empresa em 2 queries agregadas (nunca N+1), com `whereDate` no lado Shopee (armadilha do datetime-persistido-como-string) e ausência de métrica devolvendo `null`, nunca `0.0`

## Task Commits

Cada tarefa seguiu RED → GREEN (TDD):

1. **Tarefa 1: FechamentoFaixaResolver**
   - `704db2d4` (test) — 11 cenários RED (D-01, D-13, D-05, faixa-piso, ausência de faixa aberta)
   - `7aa141f3` (feat) — implementação, 11/11 verdes
2. **Tarefa 2: FechamentoRollupService**
   - `88810f23` (test) — 7 cenários RED (D-06, D-05, D-07, dia 31 na janela)
   - `5794d008` (feat) — implementação, 7/7 verdes

_Sem commit de metadados do plano ainda — este SUMMARY é commitado junto na etapa final._

## Files Created/Modified
- `app/Services/Fechamento/FechamentoFaixaResolver.php` — resolução de tabela + classificação de faturamento em faixa
- `app/Services/Fechamento/FechamentoRollupService.php` — janela de mês-calendário + rollup ML/Shopee por empresa
- `tests/Feature/Phase137/Phase137FaixaResolverTest.php` — 11 testes
- `tests/Feature/Phase137/Phase137RollupTest.php` — 7 testes

## Decisions Made
- Critério de "serviço candidato" ficou em OU (`plataforma` preenchida OU `setor` financeiro) em vez de só `setor`, por robustez — decisão de implementação dentro do que o plano já instruía, não uma mudança de escopo
- `paraEmpresa()` devolve `servico_nome = null` no caso `origem = 'propria'` (não especificado explicitamente no plano, mas consistente com "não há serviço dono" nesse caso — nenhum teste do plano cobre esse campo especificamente)
- Não criei uma migration/servico "Shopee sozinho sem faixas" fictício separado — reusei o próprio nome de produção "Gestão de ADS Shopee" no cenário de teste "sem faixas cadastradas", simplesmente não rodando o seed de faixas para aquele fixture (mantém o teste fiel ao nome real do catálogo)

## Deviations from Plan

**1. [Ajuste de teste, sem impacto no serviço] Fixture de Gestão não pode re-semear as 7 faixas**

- **Encontrado durante:** Tarefa 1, primeira execução do RED (antes mesmo do GREEN)
- **Causa:** a migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` (criada no plano 01) roda automaticamente em todo `RefreshDatabase` e já semeia as 7 faixas do serviço "Gestão" (que existe desde a migration base da Fase 14). Meu helper de teste `semearFaixasGestaoBrigada()` tentava inserir as mesmas `ordem` de novo para "Gestão", estourando a unique `(servico_id, ordem)`.
- **Fix:** removi a chamada do helper para o serviço "Gestão" nos testes (ele já vem semeado pela migration); mantive a chamada manual só para "Brigada" e "Gestão de ADS Shopee", que não existem no catálogo base e por isso não são tocados pelo seed automático.
- **Arquivos:** `tests/Feature/Phase137/Phase137FaixaResolverTest.php`
- **Verificação:** RED voltou a falhar só por classe inexistente (sem erro de constraint); GREEN confirmou 11/11.
- **Commit:** parte de `704db2d4` (a correção foi feita antes do primeiro commit RED, não ficou registrada como commit separado)

**2. [Rule 1 - Bug] Comentários do serviço continham os literais proibidos pelo critério de aceite**

- **Encontrado durante:** Tarefa 2, verificação dos critérios de aceite (`grep -c` deveria retornar 0 para `subDays(30)`, `CompanyMonthlyRevenue`/`company_monthly_revenues` e `AdmanMetricDiffService`)
- **Issue:** o docblock da classe citava esses três nomes literalmente para explicar por que NÃO usá-los ("Nunca `Carbon::now()->subDays(30)`", "Não usa `AdmanMetricDiffService`", etc.) — o próprio comentário fazia o grep de "zero ocorrências" falhar.
- **Fix:** reescrevi os três trechos parafraseando sem os literais ("janela móvel retroativa de 30 dias", "serviço de diff HTTP-first do módulo de bônus", "tabela de faturamento mensal legada de ML"), preservando a explicação.
- **Arquivos modificados:** `app/Services/Fechamento/FechamentoRollupService.php`
- **Verificação:** `grep -c` dos três padrões voltou a 0; suíte Phase137RollupTest continuou 7/7 depois da edição.
- **Committed in:** `5794d008` (parte do commit da implementação — a correção foi feita antes do commit, não há commit de fix separado)

---

**Total deviations:** 2 (1 ajuste de fixture de teste, 1 correção de comentário para bater com critério de aceite) — nenhuma mudança de comportamento do código de produção além do texto de comentários.
**Impact on plan:** Nenhum. Ambos os desvios foram descobertos e corrigidos antes de qualquer commit GREEN; o comportamento entregue é exatamente o especificado no plano.

## Issues Encountered
None além dos dois itens documentados acima em "Deviations".

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- `FechamentoFaixaResolver` e `FechamentoRollupService` estão prontos para os 4 call-sites previstos nos planos 07/08 (tela de fechamento, comando de consolidação, snapshot de grupo).
- Nenhum call-site existente (`AdminController::fechamento()`) foi tocado — a janela móvel de 30 dias e `company_monthly_revenues` continuam em produção até os planos que os substituem (fora do escopo deste plano).
- Gate da fase (`Phase122|Phase136|Phase137`) confirmado em **156 testes, 836 asserções, 0 falhas** (era 138/787 antes deste plano — cresceu exatamente os 18 testes/49 asserções novos, sem regressão).
- `AdminFechamentoControllerTest` segue com as mesmas 5 falhas pré-existentes documentadas em `deferred-items.md` (não regressão desta wave).

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-02*

## Self-Check: PASSED

- FOUND: `app/Services/Fechamento/FechamentoFaixaResolver.php`
- FOUND: `app/Services/Fechamento/FechamentoRollupService.php`
- FOUND: `tests/Feature/Phase137/Phase137FaixaResolverTest.php`
- FOUND: `tests/Feature/Phase137/Phase137RollupTest.php`
- FOUND commit: `704db2d4`, `7aa141f3`, `88810f23`, `5794d008`
