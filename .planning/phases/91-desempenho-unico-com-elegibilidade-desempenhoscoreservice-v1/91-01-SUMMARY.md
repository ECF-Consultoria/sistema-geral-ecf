---
phase: 91-desempenho-unico-com-elegibilidade-desempenhoscoreservice-v1
plan: 01
subsystem: Desempenho (motor de cálculo de bônus)
tags: [desempenho, elegibilidade, carteira-por-servico, cache-versionado, tdd]
requires:
  - CarteiraContextService::forUser/contadores (Fase 88, fundação — não alterado)
provides:
  - computeUniverso derivado de vínculos de serviço (não mais de $user->companies())
  - score_status official/partial/blocked no shape de compute()
  - 6 metadados de elegibilidade (empresas_unicas, vinculos_servico, vinculos_financeiros, vinculos_sem_fonte_financeira, score_status, componentes_disponiveis)
  - cache desempenho.compute.v4
affects:
  - PerformanceController / dashboardCarteira (consomem o shape — Fase 92, fora de escopo)
  - ConsolidarMesDesempenho / snapshot mensal (grava breakdown_json com os campos novos)
tech-stack:
  added: []
  patterns:
    - "Delegação: computeUniverso usa CarteiraContextService, não reimplementa a query de vínculos"
    - "Bump de chave de cache versionada como mecanismo de invalidação de schema"
key-files:
  created:
    - tests/Feature/V16/DesempenhoElegibilidadeTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
    - tests/Feature/Phase74/DesempenhoScoreServiceTest.php
    - tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php
    - tests/Feature/V16/BonusDualPathRegressaoTest.php
decisions:
  - "D-91-01: blocked força nota_final=null + faixa_bonus=null (usuário 2026-07-16)"
  - "D-91-02: MISTO (Performance+Shopee) é official, não partial"
metrics:
  duration: "~2h30 (inclui 4 suítes de regressão pesadas)"
  completed: 2026-07-17
requirements: [DESEMP-01, DESEMP-04, DESEMP-05, DESEMP-06, DESEMP-07]
---

# Phase 91 Plan 01: Desempenho único com elegibilidade (DesempenhoScoreService v1) Summary

`computeUniverso` do `DesempenhoScoreService` agora deriva o universo de empresas dos VÍNCULOS DE SERVIÇO ativos (`CarteiraContextService::forUser()`) em vez da carteira consolidada por `company_id` (`$user->companies()`), eliminando o bug de prod onde um responsável só-Shopee "herdava" faturamento/margem ML de empresas que não gerencia. Score permanece ÚNICO por profissional (nenhum score por marketplace); ganhou `score_status` (official/partial/blocked) + 6 metadados de auditoria; chave de cache bumpada v3→v4.

## O que mudou no shape de `compute()`

Chaves ADITIVAS (nada removido; `computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` intocados):

- `empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira` — de `CarteiraContextService::contadores()`.
- `score_status` — `official`|`partial`|`blocked` (novo `computeScoreStatus()`).
- `componentes_disponiveis` — `{nps_medio, var_faturamento_pct, var_margem_pct}` booleanos.
- `empresas_carteira` passa a receber o valor de `empresas_unicas` (compat DESEMP-05 — Fase 92 consome sem recomputar).

Componentes financeiros (`computeVarFaturamento`/`computeVarMargem`) recebem só as empresas com pelo menos 1 vínculo `financial_metrics_eligible=true`, deduplicadas por `company_id` (Pitfall 4). Assinaturas dos dois métodos NÃO mudaram — só o conjunto de entrada.

## Regras de negócio travadas

- **blocked ⇒ nota_final=null + faixa_bonus=null** (D-91-01, decisão do usuário 2026-07-16). Sem tratamento especial de sort — `nota_final` null já vai pro fim do ranking existente (`sortByDesc(nota ?? -1)`).
- **MISTO (Performance+Shopee) é official** (D-91-02), não partial — o financeiro vem só do subconjunto elegível.
- **partial** = tem vínculo financeiro elegível, mas componente financeiro indisponível no período (nota calculada normalmente, sem zerar).
- **sem_carteira=true só com ZERO vínculos ativos** de qualquer setor (DESEMP-07). Só-Shopee permanece no ranking com `blocked`.
- **Bump v3→v4** obrigatório — sem ele o Redis serviria a nota da carteira consolidada por até 7 dias após o deploy.

## Confirmação das âncoras (obrigatórias)

- **Âncora Carlos 4.08/basico**: `test_fixture_carlos_retorna_nota_4_08_basico` VERDE sem nenhuma edição de expectativa. Ajuste foi 100% no fixture (`criarEmpresaNaCarteira` ganhou `contratos_servico` ativo + `servico_id` performance) — a matemática produziu o mesmo valor sob a régua nova.
- **Só-performance byte-idêntico**: todos os 14 testes do `DesempenhoScoreServiceTest` (várias notas exatas: 3.00, 4.00, 4.08, 4.67, 5.00, 10.00, 11.115, 50.00) passaram sem alterar nenhuma expectativa. Como só-performance tem 100% dos vínculos elegíveis, o conjunto de empresas de entrada é idêntico ao antigo — nota byte-idêntica confirmada.

## Resultados de regressão (foreground, php local)

| Suíte | Resultado |
|-------|-----------|
| `--filter=Phase74` (completo) | 32/32 verde |
| `tests/Feature/V16/` (completo) | 153/153 verde |
| `--filter=Desempenho` | 62/63 verde — 1 falha PRÉ-EXISTENTE (`PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200`, 403≠200) |
| `--filter=Nps` | 264/264 verde (dual-path da Fase 80 intocado) |
| `tests/Feature/Portfolio/` | 7/7 verde |
| Suíte-alvo desta fase (Phase74 + Elegibilidade + BonusDualPath) | 26/26 verde |

Total da suíte nova `DesempenhoElegibilidadeTest`: 7/7 (blocked, misto-official, dedup, partial, sem_carteira, 6 metadados, cache v4).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixture `ConsolidarMesDesempenhoCommandTest` sem `contratos_servico`**
- **Encontrado durante:** regressão obrigatória "Phase74 completo" (Task 2)
- **Issue:** O plano declarava só 1 arquivo de fixture Phase74 (`DesempenhoScoreServiceTest`), mas `ConsolidarMesDesempenhoCommandTest` tem seu PRÓPRIO helper local `criarEmpresaNaCarteira()` sem `contratos_servico`/`servico_id` — exatamente o Pitfall 1 do 91-RESEARCH, não detectado neste segundo arquivo. Sob a régua nova, os 5 testes que gravam snapshot caíam em `sem_carteira=true` (0 vínculos elegíveis resolvidos).
- **Fix:** mesmo padrão já aplicado no arquivo irmão — property lazy `$servicoPerfId`, `contratos_servico` performance ativo + `company_users.servico_id` preenchido. Nenhum valor de negócio esperado alterado (só setup/fixture).
- **Files modified:** `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php`
- **Commit:** `3e89988`

## Falhas honestamente classificadas

- `PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` (403≠200) — **PRÉ-EXISTENTE**, declarada explicitamente no prompt do plano. Ortogonal a esta fase: é permissão de rota (`mlb.dashboard`), não toca `computeUniverso`/`CarteiraContextService`/cache. Fora de escopo (Scope Boundary).
- Nenhuma falha de NPS/Fase 95 (sessão paralela) observada — `--filter=Nps` 264/264 verde.

## Fronteira respeitada

- Tocados APENAS: `DesempenhoScoreService.php` + os 3 arquivos de teste declarados + `ConsolidarMesDesempenhoCommandTest` (fixture, Rule 1).
- NÃO tocados: PerformanceController, PortfolioController, User.php, Company*, código NPS, `CarteiraContextService`, `resources/js/Pages/Nps/Index.jsx` (mod da sessão paralela deixado intacto/unstaged).
- `git add` sempre com caminhos explícitos; STATE.md não tocado; sem deploy.

## Self-Check: PASSED

- `app/Services/DesempenhoScoreService.php` — grep `desempenho.compute.v4` presente (linha 191); `$user->companies()` ausente de `computeUniverso` (só sobra em `notasLegado`, intocado por design).
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php` — existe, 7 testes verdes.
- Commits `2ed34af`, `541e767`, `3e89988` presentes no `git log`.
