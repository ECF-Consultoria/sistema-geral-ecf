---
phase: 138-rea-comercial-conectada-etapa-v23-0
plan: 06
subsystem: api
tags: [laravel, inertia, react, hubspot, comercial, contratos]

# Dependency graph
requires:
  - phase: 138-03
    provides: "colunas companies.hubspot_owner_id/hubspot_owner_nome/data_venda (nullable) e HubspotOwnerResolver"
  - phase: 138-05
    provides: "vocabulário de chaves da listagem Entrada (mesmos nomes de campo, para as duas listas não divergirem)"
provides:
  - "ContratoAdminController::index() com os 8 campos mínimos do §2 + etapa, nos dois ramos (com contrato e SEM_CONTRATO), sem alterar a query do universo"
  - "resources/js/Pages/Admin/Contratos.jsx com 6 colunas novas (CNPJ, Setor, Responsável comercial, Data da venda, Etapa, Pendências) somadas às 6 já existentes"
  - "COMERC-02 fechado por completo: as DUAS listagens (Entrada via 138-05, Contrato via este plano) expõem os 8 campos do §2"
affects: [139]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Vocabulário de chave de payload compartilhado entre listagens irmãs (Contrato/Entrada) — mesmos nomes de campo para o front não precisar de dois vocabulários"
    - "Duas pendências (pendencia_fluxo/pendencias_cadastro) sempre em chaves nomeadas separadas, nunca somadas (D-11)"

key-files:
  created:
    - tests/Feature/Phase138/ComercListagemContratoCamposTest.php
  modified:
    - app/Http/Controllers/ContratoAdminController.php
    - resources/js/Pages/Admin/Contratos.jsx

key-decisions:
  - "Fechamento (closeout) feito por um agente diferente do que executou as tasks — o executor original completou e commitou as duas tasks, mas morreu por erro de stream da API antes de escrever o SUMMARY e atualizar STATE/ROADMAP/REQUIREMENTS. Este SUMMARY foi escrito por um agente de closeout que RECONFERIU o estado real em disco (grep nos critérios de aceite, diff dos commits, e re-execução da suíte de testes) em vez de confiar no relato do agente que morreu"
  - "COMERC-02 marcado como completo neste plano: as duas pontas (Entrada e Contrato) foram verificadas lado a lado antes de marcar — Entrada já expunha os 8 campos desde o plano 138-05, e este plano fecha o lado Contrato"
  - "Confirmado por leitura direta do controller que a listagem Contrato NÃO ganhou corte por etapa — nenhuma ocorrência de whereIn('etapa'/where('etapa'/ETAPA_ dentro de ContratoAdminController.php. O universo continua sendo estado de contrato (whereHas contratosServico ativo com serviço que exige contrato), exatamente como antes desta task (grep de whereHas('contratosServico' devolve 1 tanto antes quanto depois do commit)"

patterns-established:
  - "Listagens que compartilham empresa (Contrato/Entrada) usam o mesmo vocabulário de chave de payload, mas universos de filtro independentes — uma por estado de contrato, outra por etapa do fluxo"

requirements-completed: [COMERC-02]

# Metrics
duration: não mensurável com precisão — commits distam ~6h17min (09:44 → 16:01 no mesmo dia), gap provavelmente por pausa entre tasks, não por trabalho contínuo
completed: 2026-09-03
---

# Phase 138 Plan 06: Listagem Contrato ganha os 8 campos do §2 Summary

**`ContratoAdminController::index()` e `Admin/Contratos.jsx` passam a expor os 8 campos mínimos do §2 (CNPJ, setor, origem, responsável comercial, data da venda, contato, pendências separadas) e a etapa, nos dois ramos da listagem, sem tocar na query do universo — fechando COMERC-02 junto com o lado Entrada já entregue pelo plano 138-05.**

> **Nota de proveniência:** este SUMMARY foi escrito por um agente de **closeout**, não pelo agente que executou as tasks. O executor original completou e commitou as duas tasks (`551117d0` e `f8e4044b`), mas encerrou por um **erro de stream da API** antes de escrever o SUMMARY e atualizar STATE/ROADMAP/REQUIREMENTS. Todos os números e afirmações abaixo foram **reconferidos do zero** contra o estado real em disco (grep nos critérios de aceite do plano, diff dos dois commits, leitura integral do `index()`, e re-execução da suíte de testes) — nada foi copiado do relato do agente anterior sem verificação.

## Performance

- **Commits:** `551117d0` (2026-09-03T09:44:44-03:00) e `f8e4044b` (2026-09-03T16:01:24-03:00)
- **Tasks:** 2/2
- **Files modified:** 3 (2 modificados, 1 criado)

## Accomplishments
- `ContratoAdminController::index()` acrescenta `company_cnpj`, `setor_dominante`, `origem`, `hubspot_owner_nome`, `data_venda`, `email_cliente`, `telefone`, `nome_contato`, `pendencia_fluxo` e `pendencias_cadastro` nos dois ramos (`$linhas->push([...])` com contrato e `SEM_CONTRATO`), com o mesmo vocabulário de chave que `ComercialEntradaController::index()` já usa
- `pendencia_fluxo` lida exclusivamente por `Company::pendenciaAberta()` (0 ocorrências de `pendencia_aberta` cru no controller); `pendencias_cadastro` decide entre `calcular()`/`calcularUniversais()` conforme `is_origem_hubspot`
- `setor_dominante` deriva do primeiro `servico->setor` não nulo entre os `contratosServico` ativos da empresa (D-12), mesma disciplina de `ComercialController::listagem()`
- Query do universo intacta: `whereHas('contratosServico'` continua ocorrendo exatamente 1 vez no arquivo, idêntica antes/depois do commit (conferido por `git show 551117d0^:...`)
- Nenhuma string `signatario`/`cpf`/`signatarios` dentro de qualquer `$linhas->push([` do `index()` — as únicas ocorrências dessas palavras no arquivo pertencem ao método `show()` (não tocado por este plano)
- `resumo` do payload continua com exatamente 7 chaves (`STATUS_TODOS`)
- `resources/js/Pages/Admin/Contratos.jsx` ganha 6 colunas novas (CNPJ, Setor, Responsável comercial, Data da venda, Etapa, Pendências), somadas às 6 já existentes (Empresa, Serviço, Situação, Parado há, Término, Ações) — `colSpan` do estado vazio atualizado de 6 para 12, batendo com o total real de `<TableHead>`
- `hubspot_owner_nome` e `data_venda` nulos renderizam travessão (`—`), nunca estado de erro; `etapa` nula renderiza `Sem etapa (legado)`, mesmo vocabulário de `Pages/Companies/Index.jsx`
- Pendência do fluxo (badge destrutivo com motivo em `title`) e pendências de cadastro (badges por `PENDENCIAS_LABELS`, 7 chaves) ficam visualmente separadas na mesma célula — nenhuma expressão `.length +` no arquivo
- `npm run build` executado: `public/build/manifest.json` (09:47) é mais novo que `Contratos.jsx` (09:45) e contém 3 ocorrências de `Pages/Admin/Contratos.jsx`
- `tests/Feature/Phase138/ComercListagemContratoCamposTest.php` cobre os 7 casos do plano: ramo com contrato traz os campos novos; ramo `SEM_CONTRATO` traz exatamente as mesmas chaves; `hubspot_owner_nome` nulo não quebra o payload; `pendencia_fluxo`/`pendencias_cadastro` são chaves distintas sem chave agregada; cadastro manual recebe pendências universais (nunca array vazio); resumo continua com 7 chaves; universo da listagem permanece o mesmo (2 linhas no cenário do teste); nenhuma linha carrega `signatarios`/`cpf`

## Task Commits

Each task was committed atomically pelo executor original (verificados presentes em `git log --oneline --all`):

1. **Task 1: Payload da listagem Contrato ganha os 8 campos do §2 e a etapa** - `551117d0` (feat)
2. **Task 2: Colunas novas na tabela de contratos e build do front** - `f8e4044b` (feat)

**Plan metadata:** _(este commit do agente de closeout)_

## Files Created/Modified
- `app/Http/Controllers/ContratoAdminController.php` - `index()` ganha os 8 campos + etapa nos dois ramos; injeta `PendenciasComerciaisService`; eager load de `hubspotEventos`/`hubspotEventoOrigem` evita N+1
- `resources/js/Pages/Admin/Contratos.jsx` - 6 colunas novas na tabela, `colSpan` corrigido de 6 para 12, rótulos de etapa e pendências separadas
- `tests/Feature/Phase138/ComercListagemContratoCamposTest.php` - 7 casos cobrindo o shape do payload dos dois ramos

## Decisions Made
- Ver `key-decisions` no frontmatter — resumidamente: closeout por agente diferente do executor (documentado por transparência), COMERC-02 fechado só depois de verificar as duas pontas, e confirmação explícita de que não houve corte por etapa na listagem Contrato.

## Deviations from Plan

Nenhum desvio encontrado nas duas tasks em si — todo critério de aceite do `138-06-PLAN.md` foi conferido diretamente contra o código em disco e bateu (ver seção "Critérios de Aceite Reconferidos" abaixo). O único desvio é de **processo**, não de implementação:

### Auto-fixed Issues

**1. [Processo — sem código] SUMMARY.md e atualização de STATE/ROADMAP/REQUIREMENTS ausentes após a execução**
- **Found during:** Início do closeout (SUMMARY não existia, STATE.md/ROADMAP.md não refletiam o plano 06, COMERC-02 ainda desmarcado)
- **Issue:** O executor original morreu por erro de stream da API depois de commitar as duas tasks, sem chegar ao passo de fechamento do plano
- **Fix:** Este SUMMARY, mais a atualização de STATE.md/ROADMAP.md/REQUIREMENTS-v23.md nos commits seguintes
- **Files modified:** `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-06-SUMMARY.md`, `.planning/STATE.md`, `.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v23.md`
- **Verification:** Reconferência de todos os critérios de aceite do plano contra o disco (ver abaixo) + suíte de testes re-executada do zero

---

**Total deviations:** 1 (processo, não código). Nenhum gap de implementação encontrado — as duas tasks bateram integralmente com o plano.
**Impact on plan:** Nenhum. O código entregue pelo executor original está correto e completo; faltava só o fechamento administrativo.

## Critérios de Aceite Reconferidos (pelo agente de closeout)

### Task 1 — `ContratoAdminController.php`
- `ComercListagemContratoCamposTest.php` sai com exit code 0, cobrindo os 7 casos nomeados — **CONFIRMADO** (53 testes/218 assertions no comando exato do orquestrador; 171 testes/678 assertions no comando mais amplo, ambos OK)
- Suíte `tests/Feature/Phase131` sem nenhum arquivo modificado — **CONFIRMADO** (`git status --porcelain tests/Feature/Phase131/` vazio)
- `grep -c "hubspot_owner_nome"` = 2 — **CONFIRMADO**
- `grep -c "pendencia_fluxo"` = 2 — **CONFIRMADO**
- `grep -c "pendencias_cadastro"` = 2 — **CONFIRMADO**
- `grep -c "pendencia_aberta"` = 0 — **CONFIRMADO**
- `grep -c "whereHas('contratosServico'"` idêntico a antes da task (1 = 1) — **CONFIRMADO** (comparado via `git show 551117d0^:...`)
- Nenhuma string `signatario`/`cpf`/`signatarios` dentro de `$linhas->push([` do `index()` — **CONFIRMADO** (todas as ocorrências pertencem ao `show()`)
- `resumo` com exatamente 7 chaves — **CONFIRMADO** (teste `test_resumo_continua_com_exatamente_7_chaves` verde)

### Task 2 — `Contratos.jsx`
- `npm run build` saiu 0 e `public/build/manifest.json` existe — **CONFIRMADO** (manifest 09:47, arquivo fonte 09:45, 3 ocorrências de `Pages/Admin/Contratos.jsx` no manifest)
- `grep -c "hubspot_owner_nome"` ≥ 1 — **CONFIRMADO** (2)
- `grep -c "data_venda\|setor_dominante\|company_cnpj"` ≥ 3 — **CONFIRMADO** (3)
- `grep -c "pendencia_fluxo"` ≥ 1 e `grep -c "pendencias_cadastro"` ≥ 1 — **CONFIRMADO** (3 e 2)
- Nenhuma expressão `.length +` — **CONFIRMADO**
- `colSpan` do estado vazio bate com o total de `<TableHead>` — **CONFIRMADO** (12 = 12)
- `grep -c "Sem etapa (legado)"` ≥ 1 — **CONFIRMADO** (1)
- `git status --porcelain package.json package-lock.json` vazio — **CONFIRMADO**

### Verificação crítica de escopo — sem corte por etapa
- `grep -n "whereIn('etapa'\|where('etapa'\|ETAPA_" app/Http/Controllers/ContratoAdminController.php` não devolveu nenhuma linha — **CONFIRMADO: a listagem Contrato não ganhou corte por etapa.** O universo continua sendo definido só por estado de contrato (`whereHas('contratosServico', ...)`), exatamente como a D-06/D-07 do CONTEXT exige. `etapa` entra no payload só como campo exibido, nunca como filtro de universo.

## Issues Encountered
Nenhum problema de implementação encontrado durante o closeout. O único "issue" foi de processo (agente anterior morreu antes de fechar o plano), já registrado em "Deviations from Plan".

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- COMERC-02 fechado por completo: Entrada (138-05) e Contrato (este plano) expõem os 8 campos do §2, cada uma com seu próprio universo (etapa vs. estado de contrato) — nenhuma das duas ganhou corte cruzado
- O plano 138-07/138-08 (wave 3) pode seguir sem qualquer bloqueio deste plano
- Nenhum stub ou dado mockado introduzido: todos os campos novos leem de colunas reais (`companies.hubspot_owner_nome`, `companies.data_venda`, etc.) já persistidas pelo plano 138-03/138-04

---
*Phase: 138-rea-comercial-conectada-etapa-v23-0*
*Completed: 2026-09-03*

## Self-Check: PASSED

- `app/Http/Controllers/ContratoAdminController.php` — FOUND (lido integralmente, 8 campos + etapa presentes nos dois ramos)
- `resources/js/Pages/Admin/Contratos.jsx` — FOUND (6 colunas novas presentes, colSpan=12)
- `tests/Feature/Phase138/ComercListagemContratoCamposTest.php` — FOUND (7 métodos de teste, todos verdes)
- Commit `551117d0` — FOUND em `git log --oneline --all`
- Commit `f8e4044b` — FOUND em `git log --oneline --all`
- Suíte re-executada pelo agente de closeout (não copiada do relato anterior): `tests/Unit/Phase138 tests/Feature/Phase138 tests/Feature/Phase131/ContratoAdminPermissaoTest.php` → **53 testes, 218 assertions, OK**; `tests/Unit/Phase138 tests/Feature/Phase138 tests/Feature/Phase131` (suíte completa, mais ampla que o pedido) → **171 testes, 678 assertions, OK**
