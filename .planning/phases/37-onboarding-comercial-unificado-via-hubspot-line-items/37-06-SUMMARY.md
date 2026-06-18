---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
plan: 06
subsystem: companies-refoco-performance
tags: [companies, performance, scope, pendencias, cleanup, ui]
requires:
  - 37-01  # servicos.setor (constante SETOR_PERFORMANCE usada no scope)
  - 37-05  # listagem comercial unificada (destino das empresas Publicacao/Outros)
provides:
  - "CompanyController::index filtra empresas por Servico::setor=performance + ContratoServico.ativo=true"
  - "Payload de /companies sem pendencia 'sem_servico' (migrou para /comercial/empresas/listagem)"
  - "Companies/Index.jsx com 2 abas (Empresas, Pendencias) — Grupos migrou para o Comercial"
  - "Phase37CompaniesPerformanceFilterTest cobrindo 12 cenarios (filtro + cleanup + zero regressao)"
affects:
  - "Plan 37-07 (reorg final sidebar: migrar menu Servicos e Grupos para Comercial)"
tech-stack:
  added: []
  patterns:
    - "whereHas aninhado em scope: contratosServico(ativo=true).whereHas('servico', setor=performance) — composicao Eloquent idiomatica"
    - "Pendencia removida via simples delete da linha no array_filter — sem migration; backend ja para de incluir a chave no payload e frontend deixa de pintar o card automaticamente"
    - "Aba removida via TABS array — deep-link antigo (?tab=grupos) cai silenciosamente em 'empresas' (whitelist no useState initializer)"
    - "Helper attachPerformanceContract() nos testes que validam pendencias/campos (foco diferente do filtro) — preserva isolamento de cada test"
key-files:
  created:
    - "tests/Feature/Phase37CompaniesPerformanceFilterTest.php"
  modified:
    - "app/Http/Controllers/CompanyController.php"
    - "resources/js/Pages/Companies/Index.jsx"
    - "tests/Feature/Phase34CompaniesCloseFieldsTest.php"
key-decisions:
  - "Scope Performance via whereHas('contratosServico') aninhado com whereHas('servico', setor=performance) — mesmo padrao do Plan 37-05 (consistencia)"
  - "Constante Servico::SETOR_PERFORMANCE usada em vez de string literal 'performance' — evita drift entre Plan 37-01 e 37-06 (T-37-16 mitigado)"
  - "Pendencia sem_servico removida do array de pendencias E do pendCounts JSX (5 chaves agora)"
  - "Aba Grupos removida — GruposManager import limpo; backend continua enviando prop 'grupos' (compat futura para reativar caso necessario, sem custo)"
  - "Botao inline 'Servico' (Briefcase) tambem removido — era gated em pendencia sem_servico que ja nao existe; atribuicao agora vive em /comercial/empresas/{id}/atribuir-servico"
  - "4 testes Phase34CompaniesCloseFieldsTest atualizados via Rule 1 (test obsoleto por mudanca intencional de contrato) — helper attachPerformanceContract() adiciona contrato Performance para empresa continuar aparecendo no payload"
  - "Whitelist de aba no useState initializer reduzido para ['empresas', 'pendencias'] — deep-links antigos com ?tab=grupos caem silenciosamente em 'empresas' (preserva navegacao sem 404)"
  - "Import Briefcase removido junto com botao Servico inline; import GruposManager removido junto com bloco da aba — cleanup de imports nao usados"
metrics:
  duration: ~18min
  completed: 2026-06-18
  task_count: 3  # RED tests + GREEN controller + GREEN JSX cleanup
  test_count: 12 testes novos (17 assertions) + 4 testes Phase34 ajustados; 37/37 verdes na suite combinada Phase 34+35+37 cleanup
  files_created: 1
  files_modified: 3
---

# Phase 37 Plan 37-06: Refoco /companies em Performance Summary

`/companies` passa a listar exclusivamente empresas com >=1 contrato ATIVO em `Servico::setor='performance'` (Gestao + Mentoria). Empresas com contratos APENAS em Publicacao/Outros migram para `/comercial/empresas/listagem` (Plan 37-05). Pendencia `sem_servico` e aba Grupos removidas da UI — `/companies` vira a porta operacional do time de Performance.

## What Got Built

### Backend (`CompanyController::index`)

Adicionado scope `whereHas` aninhado APOS o `whereDoesntHave('mlbEmpresa')` existente:

```php
->whereHas('contratosServico', fn($q) =>
    $q->where('contratos_servico.ativo', true)
      ->whereHas('servico', fn($qs) =>
          $qs->where('setor', Servico::SETOR_PERFORMANCE)
      )
)
```

Comportamento resultante:
- Empresa com >=1 contrato Performance ativo: APARECE
- Empresa com contratos APENAS Publicacao: NAO aparece (visivel em /comercial/empresas/listagem)
- Empresa com contratos APENAS Outros: NAO aparece (idem)
- Empresa com contrato Performance INATIVO: NAO aparece
- Empresa sem contratos: NAO aparece
- Empresa com contratos mistos (1 perf ativo + 1 outros): APARECE (basta 1)
- Empresa com MlbEmpresa: NAO aparece (filtro Phase 35 preservado)

Pendencia `sem_servico` removida do array `pendencias` por empresa. Demais 5 pendencias operacionais (sem_responsavel, sem_cust_id, sem_email_colaborador, sem_grant_ativo, empresa_nova) preservadas intactas. Filtros existentes (`?cust_id_status`, `?sort`) continuam funcionando sem mudanca.

### Frontend (`Companies/Index.jsx`)

- `PENDENCIAS` constant: chave `sem_servico` removida (5 cards agora)
- `pendCounts` init: chave `sem_servico` removida
- `TABS` array: chave `grupos` removida (2 abas: Empresas, Pendencias)
- Bloco `{tab === 'grupos' && <GruposManager .../>}` deletado completamente
- Import `GruposManager` removido (sem outro uso na pagina)
- Import icone `Briefcase` removido (sem outro uso na pagina)
- Botao inline "Servico" (atalho para `comercial.atribuir-servico`, condicional em `pendencias.includes('sem_servico')`) removido — pendencia ja nao existe
- `useState` initializer do `tab` deep-link: whitelist reduzido para `['empresas', 'pendencias']` — `?tab=grupos` cai em `empresas`
- `npm run build` verde

### Testes (`Phase37CompaniesPerformanceFilterTest`)

12 testes (17 assertions) cobrindo:

| # | Cenario | Resultado |
|---|---------|-----------|
| 1 | Contrato performance ativo | APARECE |
| 2 | Contrato publicacao ativo | NAO aparece |
| 3 | Contrato outros ativo | NAO aparece |
| 4 | Contrato performance INATIVO | NAO aparece |
| 5 | Sem contratos | NAO aparece |
| 6 | Mistos (1 perf + 1 outros) | APARECE |
| 7 | MlbEmpresa associada | NAO aparece (Phase 35 preservada) |
| 8 | Payload NAO contem 'sem_servico' | OK |
| 9 | Payload contem 'sem_responsavel' (quando aplicavel) | OK |
| 10 | Payload contem 'sem_email_colaborador' (quando aplicavel) | OK |
| 11 | `?cust_id_status=invalido` continua funcional | OK |
| 12 | `?sort=nova_recente` continua funcional | OK |

Padrao das fixtures: `criarServico($nome, $setor)` + `criarEmpresa()` + `criarContrato($empresa, $servico, $ativo)`. Helper `payloadCompanies($response)` extrai `viewData('page')['props']['companies']`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Phase34CompaniesCloseFieldsTest: 4 testes obsoletos pelo novo scope**

- **Encontrado durante:** Verificacao de regressao (Task 1 GREEN)
- **Issue:** 4 testes da suite Phase 34 falharam pos-fix porque criavam empresas sem contrato Performance, e o novo scope passou a excluir essas empresas do payload de `/companies`. Testes nao testam o filtro — testam pendencias/campos de close. Mudanca de contrato (Plan 37-06) os tornou tecnicamente quebrados.
- **Fix:** Adicionado helper privado `attachPerformanceContract(Company)` que cria um Servico Performance + ContratoServico ativo associado. Helper chamado em 4 testes que dependem de empresa aparecer no payload de `/companies`:
  - `test_pendencia_empresa_nova_aparece_quando_true`
  - `test_pendencia_empresa_nova_some_apos_marcar_visto`
  - `test_pendencia_sem_email_colaborador_olha_campo_correto`
  - `test_payload_inclui_campos_de_close`
- **Files modified:** `tests/Feature/Phase34CompaniesCloseFieldsTest.php`
- **Commit:** a8d7d64 (Task 1 GREEN, agrupado com controller)

**2. [Rule 1 - Bug] Botao inline "Servico" (Briefcase) orfao apos remocao da pendencia**

- **Encontrado durante:** Cleanup do JSX (Task 2)
- **Issue:** Plan original mencionava remover apenas a chave `sem_servico` do PENDENCIAS map. Mas o botao inline na aba Pendencias (linha ~598-610 antiga) era gated por `(c.pendencias || []).includes('sem_servico')` — ficaria codigo morto orfao.
- **Fix:** Botao removido completamente + import `Briefcase` limpo. Atribuicao de servico agora vive 100% em `/comercial/empresas/{id}/atribuir-servico` (Plan 36-02 + 37-05).
- **Files modified:** `resources/js/Pages/Companies/Index.jsx`
- **Commit:** e4b296e (Task 2)

## Trade-offs

- **`grupos`, `servico_counts`, `servicos_disponiveis` continuam sendo enviados do backend** mesmo apos a aba Grupos ser removida — props nao-usadas no frontend, mas zero custo (~10ms na maior empresa). Decisao: nao tocar no payload de saida para evitar quebrar consumidores nao identificados. Sera reavaliada no Plan 37-07 (reorg final).
- **Chip de filtro por servico continua visivel** na aba Empresas — ainda util para o admin de Performance filtrar dentro dos seus servicos (Gestao vs Mentoria), nao foi alterado.
- **Tabela `Servicos`/menu lateral nao foi tocado nesta plan** — escopo do Plan 37-07 (reorg final da sidebar, migrar `Servicos` para grupo `Comercial`).

## Acceptance Validation

| Criterio | Status |
|----------|--------|
| `/companies` filtra exclusivamente empresas com >=1 contrato Performance ativo | OK |
| Empresas com contratos APENAS Publicacao/Outros NAO aparecem | OK |
| Empresas com MlbEmpresa NAO aparecem (Phase 35 preservada) | OK |
| Pendencia `sem_servico` NAO existe no payload | OK |
| Demais pendencias operacionais preservadas | OK |
| Aba Grupos removida da UI | OK |
| Card "Sem servico" removido da aba Pendencias | OK |
| `?cust_id_status=invalido` continua funcional | OK |
| `?sort=nova_recente` continua funcional | OK |
| `npm run build` verde | OK |
| Phase37CompaniesPerformanceFilterTest 12/12 verdes | OK |
| Phase 35 zero regressao | OK (17/17) |
| Phase 34 zero regressao (apos ajuste 4 testes Rule 1) | OK (8/8) |
| Phase 37 acumulado zero regressao | OK |

## Test Run Results

```
Phase37CompaniesPerformanceFilterTest: 12/12 passed (17 assertions, ~19s)
Phase34CompaniesCloseFieldsTest:        8/8 passed
Phase35 (all):                          17/17 passed (188 assertions)
Phase 37+35+34 combinado (cleanup):     37/37 passed (235 assertions, ~37s)
Phase 34+35+37 acumulado:               105/105 passed (537 assertions, ~71s)
```

## Threat Flags

Nenhum flag novo. T-37-15 (Information Disclosure — empresas filtradas) mantido como `accept`: restricao positiva, nao vazamento. T-37-16 (Tampering — constant Servico::SETOR_PERFORMANCE) mitigado via uso direto da constante (zero string literal solta no controller).

## Self-Check: PASSED

- File `tests/Feature/Phase37CompaniesPerformanceFilterTest.php`: FOUND
- File `app/Http/Controllers/CompanyController.php`: FOUND
- File `resources/js/Pages/Companies/Index.jsx`: FOUND
- File `tests/Feature/Phase34CompaniesCloseFieldsTest.php`: FOUND
- Commit `024c5a1` (RED): FOUND
- Commit `a8d7d64` (Task 1 GREEN): FOUND
- Commit `e4b296e` (Task 2 GREEN): FOUND
