---
phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip
plan: 03
subsystem: api
tags: [hubspot, webhook, dedup, php]

# Dependency graph
requires:
  - phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip
    provides: "HubspotNameNormalizer (113-01) — normalização de nome para o match fraco; campos estruturados + hubspot_snapshot (113-02) — base sobre a qual o dedup enriquece/reescreve"
provides:
  - "HubspotCompanyMatcher::encontrar() — resolve empresa existente por hubspot_company_id > cnpj > email > domain > nome normalizado, classificando forte/fraco"
  - "HubspotWebhookController::criarEmpresa bifurca: match FORTE enriquece Company existente (só campos vazios) via enriquecerEmpresaExistente(); sem match forte cria empresa nova (113-02 intacto)"
  - "Guard anti-duplicidade de contrato em persistirContratos() (hubspot_line_item_id, ou servico_id ativo no fluxo legado)"
  - "Match FRACO grava warning possivel_duplicidade em hubspot_eventos.payload + no hubspot_snapshot da empresa nova, sem merge de campos críticos"
affects: [114-ui-comercial-pendencias, 115-e2e-doc]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Matcher de dedup compara CNPJ (dígitos) e nome normalizado em PHP, não SQL — volume baixo de companies (threat register T-113-03-04 aceita o custo); evita SQL não-portável entre SQLite (testes) e MySQL/MariaDB (produção)"
    - "Enriquecimento condicional genérico: loop sobre array campo=>valorNovo, só grava se valorAtual for null/'' — mesmo padrão reaplicável a outros dedups futuros"

key-files:
  created:
    - app/Services/Hubspot/HubspotCompanyMatcher.php
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - tests/Feature/Phase113HubspotDedupTest.php

key-decisions:
  - "Implementação do match FRACO (warning possivel_duplicidade) foi commitada JUNTO com a Tarefa 2 (match forte) — mesmo desvio de agrupamento de commit já documentado na 113-02: ambas tocam o MESMO método criarEmpresa numa única árvore de decisão (forte/fraco/null), separar exigiria reescrever o método duas vezes. Os testes E2E da Tarefa 3 foram escritos depois e passaram GREEN de primeira (o comportamento já existia) — sem ciclo RED formal para o ramo fraco, mas o <behavior> da Tarefa 3 foi 100% coberto pelos 4 testes E2E"
  - "empresa_nova NÃO é remarcada no match forte (empresa já existia) — decisão default do CONTEXT.md ('provavelmente não remarca')"
  - "notes NÃO recebe a linha 'Contato (HubSpot): {nome}' no caminho de enriquecimento (match forte) — só no caminho de CRIAÇÃO de empresa nova; a linha legada em notes é fonte só para empresa nova, e o campo estruturado nome_contato já cobre o enriquecimento"
  - "Guard de contrato duplicado (hubspot_line_item_id / servico_id ativo) foi adicionado de forma INCONDICIONAL em persistirContratos() (não só no ramo match-forte) — em empresa recém-criada na mesma transaction nunca há contrato prévio, então o guard nunca dispara ali; zero impacto de regressão, e o código fica mais simples (1 guard, não 2 caminhos)"
  - "hubspot_snapshot['company'] no caminho de enriquecimento usa $hubCompany['properties'] ?? null (não $cprops), preservando exatamente o mesmo shape do 113-02 (null quando não há company associada, em vez de array vazio)"

patterns-established:
  - "Resolução de dedup SEMPRE roda ANTES de qualquer escrita no banco (Company::create ou update) — nunca decide dedup depois de já ter criado a empresa"

requirements-completed: [HUB-DEDUP-01, HUB-DEDUP-02]

# Metrics
duration: ~35min
completed: 2026-07-24
---

# Phase 113 Plan 03: Dedup de Empresa Existente (Match Forte/Fraco) Summary

**HubspotCompanyMatcher resolve empresa existente por hubspot_company_id→cnpj→email→domain→nome normalizado antes de todo Company::create; match forte enriquece só campos vazios sem duplicar (guard hubspot_line_item_id no contrato), match fraco cria empresa nova e grava warning `possivel_duplicidade` sem merge agressivo — 84/84 testes HubSpot verdes, regressão zero.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-07-24T~17:10Z
- **Completed:** 2026-07-24T~17:45Z
- **Tasks:** 3 (Tarefa 1 RED+GREEN; Tarefa 2 feat; Tarefa 3 test — ver Decisões sobre agrupamento)
- **Files modified:** 3 (1 classe nova + 1 controller + 1 teste)

## Accomplishments
- `HubspotCompanyMatcher::encontrar()` — unidade testável que resolve empresa existente na ordem hubspot_company_id > cnpj (dígitos) > email > domain > nome normalizado (via `HubspotNameNormalizer` da 113-01), classificando cada hit como `forte` ou `fraco`, parando no primeiro match. 10/10 testes unitários (precedência, anti-falso-positivo, critérios vazios).
- `HubspotWebhookController::criarEmpresa` resolve dedup ANTES de qualquer `Company::create` ou `update`: match FORTE delega a `enriquecerEmpresaExistente()` (grava só colunas vazias, dado manual do Comercial nunca é sobrescrito); sem match forte, cai no fluxo de criação idêntico à 113-02.
- Guard anti-duplicidade de contrato em `persistirContratos()`: pula contrato com `hubspot_line_item_id` já existente na empresa (ou `servico_id` ativo no fluxo legado sem line item) — cobre reprocessamento/novo deal de empresa já existente sem duplicar `ContratoServico`.
- Match FRACO (só nome normalizado bate): cria empresa nova normalmente (não perde o handoff) + grava warning `possivel_duplicidade` (candidate_company_id, via, nome_normalizado) em `hubspot_eventos.payload` e no `warnings` do `hubspot_snapshot` da empresa nova — candidata original permanece intocada.
- `hubspot_snapshot` sempre reescrito com o payload do evento novo (deal/company/contacts/line_items/warnings/captured_at), mesmo no caminho de match forte — sem deep-merge do snapshot antigo (regra explícita desta fase).
- Suite nova `Phase113HubspotDedupTest` (14 testes, 63 asserções): 10 unit-style do Matcher + 4 E2E via webhook real (match forte por CNPJ, guard de contrato por hubspot_company_id, match fraco por nome, DB fresco sem warning).

## Task Commits

1. **Tarefa 1: RED — testes unitários HubspotCompanyMatcher** - `dec0e5d7` (test)
2. **Tarefa 1: GREEN — implementa HubspotCompanyMatcher** - `e12c497e` (feat)
3. **Tarefa 2: integra dedup no criarEmpresa (match forte + guard contrato + match fraco)** - `9e484edd` (feat)
4. **Tarefa 3: E2E dedup match forte/fraco via webhook** - `ec449d1d` (test)

**Plan metadata:** (criado no commit final desta execução)

## Files Created/Modified
- `app/Services/Hubspot/HubspotCompanyMatcher.php` - classe nova; resolve empresa existente por precedência hubspot_company_id→cnpj→email→domain→nome
- `app/Http/Controllers/Api/HubspotWebhookController.php` - `criarEmpresa` bifurca forte/sem-forte; `enriquecerEmpresaExistente()` novo método privado; `persistirContratos()` ganha guard anti-duplicidade
- `tests/Feature/Phase113HubspotDedupTest.php` - 14 testes: 10 unit-style do Matcher (Tarefa 1) + 4 E2E via webhook (Tarefa 3)

## Decisions Made

### Agrupamento de commit: match forte + match fraco na Tarefa 2
Igual ao desvio já documentado na 113-02: a lógica de match forte (Tarefa 2) e a lógica de match fraco (Tarefa 3) vivem no MESMO método `criarEmpresa`, na MESMA árvore de decisão condicional (`if match==='forte' {...} else {...}` seguido de `if match==='fraco' {...}` mais adiante para o warning). Escrever a Tarefa 2 sem a ramificação fraca exigiria decidir "o que fazer quando match é fraco" de qualquer forma (a resposta é: comportamento idêntico ao null, mais o warning) — implementar as duas juntas evitou reescrever o método duas vezes. A verificação automatizada da Tarefa 2 (Phase34+Phase112HubspotHandoffWebhook) rodou e confirmou verde ANTES do commit único; nenhuma garantia do plano foi pulada, só o agrupamento do commit git mudou. Os testes E2E da Tarefa 3 (que exercitam o ramo fraco) foram escritos DEPOIS e passaram GREEN de primeira — documentado como desvio de **processo de commit**, não de comportamento ou cobertura.

### `empresa_nova` não remarcada em match forte
Confirma a decisão-padrão do CONTEXT.md ("provavelmente não remarca como nova se já existia"): o teste `test_match_forte_por_cnpj_enriquece_sem_duplicar` cria a empresa com `empresa_nova=false` explicitamente e assere que permanece `false` após o webhook — provando que o enriquecimento nunca reabre a flag "empresa nova" para uma empresa que o Comercial já viu.

### Guard de contrato incondicional (não só no ramo match-forte)
O guard anti-duplicidade (`hubspot_line_item_id` / `servico_id` ativo) foi colocado em `persistirContratos()` sem condicionar ao resultado do match — roda para TODO contrato, criado em empresa nova ou existente. Em empresa recém-criada na mesma transaction nunca existe contrato prévio, então o guard nunca dispara nesse caminho (zero risco de regressão comprovado pelas suites Phase34/35/112). Isso simplificou o código (1 guard central em vez de 2 caminhos condicionais) sem alterar nenhum comportamento observável fora do dedup.

### `notes` não recebe linha de contato no caminho de enriquecimento
A linha legada `"Contato (HubSpot): {nome}"` em `notes` só é anexada no fluxo de CRIAÇÃO de empresa nova (113-02/Phase 35), não no enriquecimento de empresa existente — o plano não listou `notes` entre os campos elegíveis a enriquecimento, e a coluna estruturada `nome_contato` já cobre esse dado de forma auditável, evitando poluir um campo de texto livre que pode ter anotações manuais do Comercial.

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio de comportamento (Regras 1-4). Único desvio é de **processo de commit** (agrupamento Tarefa 2+3), documentado acima e já precedente na 113-02.

---

**Total deviations:** 0 auto-fixes (Rules 1-4); 1 desvio de processo de commit (documentado, sem impacto em cobertura/regressão).
**Impact on plan:** Nenhum. Todas as verificações automatizadas do plano rodaram e passaram antes de cada commit.

## Issues Encountered
- Mesmo padrão de índice git observado na 113-02: `git commit -- <arquivo>` falhou com "pathspec did not match" na primeira tentativa em um arquivo recém-criado (`Phase113HubspotDedupTest.php`), mesmo existindo em disco e não ignorado. Resolvido com `git add <arquivo>` explícito antes do commit (sem pathspec no commit). Suspeita mantida: árvore de trabalho compartilhada entre sessões paralelas (ver `project_sessoes_paralelas_working_tree` na memória do projeto). Não é mudança de código.
- Um teste E2E inicial (`empresa_nova NÃO remarcada`) falhou na primeira rodada porque a `CompanyFactory` não define `empresa_nova` e a coluna tem `default(1)` no banco — a asserção original testava um falso positivo (a empresa já nascia com `empresa_nova=true` por default, então "continuar true" não provava nada sobre não-remarcação). Corrigido setando `empresa_nova: false` explicitamente na criação da empresa candidata, tornando a asserção significativa.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- HUB-DEDUP-01 e HUB-DEDUP-02 completos e testados — Fase 113 (Enriquecimento de contato/empresa + escolha de contato principal + dedup) está 3/3 COMPLETA.
- `hubspot_eventos.payload['possivel_duplicidade']` e `hubspot_snapshot['warnings']` já disponíveis para a Fase 114 renderizar a pendência `possivel_duplicidade` na listagem Comercial (fora do escopo desta fase, conforme CONTEXT.md).
- `HubspotCompanyMatcher` é reutilizável tal como está para o comando `hubspot:reprocess-event` planejado para a Fase 114 (mesma lógica de resolução de empresa existente).
- Nenhum bloqueio. Regressão HubSpot completa: 84/84 testes verdes (`--filter=Hubspot`).

---
*Phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip*
*Completed: 2026-07-24*

## Self-Check: PASSED
