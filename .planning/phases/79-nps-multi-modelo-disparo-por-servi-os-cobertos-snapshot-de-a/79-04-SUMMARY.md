---
phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
plan: 04
subsystem: nps
tags: [snapshot, atribuicao-por-servico, submit-v15, imutabilidade, multi-modelo]
requires:
  - "Tabelas de snapshot + models (Plano 79-01 - 2026_07_14_200001): NpsResponseScore, NpsResponseCoveredService, NpsScoreAssignment"
  - "NpsScoreCalculator::compute() (Phase 69) — média por dimensão SUM(peso)/N"
  - "Company::consultorDoServico/estrategistaDoServico + contratosServico()->active() (Phase 76)"
  - "NpsTemplate::serviceScopes() (Phase 68) — serviços cobertos do modelo"
  - "submitResponseV15 v15 vivo (Phase 69) — transação + answers snapshot per-row"
provides:
  - "NpsSnapshotService::registrar(NpsResponse): congela scores + covered_services + assignments no submit"
  - "1 nps_response_scores por dimensão calculada (estrategista/analista/empresa) com sum/count/average"
  - "nps_response_covered_services: serviços cobertos do modelo congelados (service_setor snapshot)"
  - "nps_score_assignments só na interseção cobertos ∩ contratos ativos; role = valor da pivot (consultor/estrategista)"
  - "Empresa fica só em scores (dimensao=empresa), sem assignment de pessoa"
  - "Responsável faltante → sem assignment vazio + Log::warning [NPS Snapshot] de pendência"
affects:
  - "submitResponseV15 grava snapshot dentro da transação após as answers (fluxo v15/legacy intacto)"
  - "Histórico de atribuição imutável — pronto para a Fase 80 consumir (bônus intocado, DEC-79-E)"
tech-stack:
  added: []
  patterns:
    - "Service stateless com NpsScoreCalculator injetado; roda DENTRO da transação do submit (sem transação própria)"
    - "Mapa dimensão→role da pivot: analista→consultor, estrategista→estrategista; empresa nunca vira assignment"
    - "Interseção cobertos ∩ contratosServico()->active() como blindagem de tampering (T-79-04-01)"
    - "Log::warning estruturado [NPS Snapshot] em vez de assignment com user_id null (T-79-04-05)"
key-files:
  created:
    - "app/Services/Nps/NpsSnapshotService.php"
    - "tests/Feature/V16/SubmitSnapshotTest.php"
    - "tests/Feature/V16/AtribuicaoPorServicoNpsTest.php"
  modified:
    - "app/Http/Controllers/NpsController.php"
decisions:
  - "DEC-79-D: snapshot no submit — scores por dimensão + covered_services + assignments (só interseção cobertos∩ativos)"
  - "Covered_services congela TODOS os serviços cobertos do template (não só a interseção); assignments só na interseção"
  - "role persiste o valor da pivot (consultor/estrategista) para JOIN direto na Fase 80 (A3 do RESEARCH)"
  - "Pitfall 3: registrar() DENTRO da transação, DEPOIS do foreach das answers (senão o calculator leria zero)"
  - "DEC-79-E: DesempenhoScoreService/->principal() intocados; display por serviço no respond() DEFERIDO (Fase 80)"
metrics:
  duration: "~25min"
  completed: "2026-07-15"
  tasks: 3
  files: 4
---

# Phase 79 Plan 04: Snapshot de atribuições por serviço no submit v15 — Summary

Congela o SNAPSHOT imutável do NPS no `submitResponseV15` (DEC-79-D): ao responder, o novo `NpsSnapshotService::registrar()` calcula as médias por dimensão via `NpsScoreCalculator`, grava-as em `nps_response_scores`, congela os serviços cobertos do modelo em `nps_response_covered_services`, e gera as atribuições média×pessoa×role×serviço em `nps_score_assignments` — **só para os responsáveis dos serviços cobertos ∩ contratos ATIVOS** da empresa. A dimensão empresa fica apenas em scores (nunca vira nota de pessoa). O bônus permanece intocado (DEC-79-E): as atribuições são aditivas, prontas para a Fase 80.

## O que foi construído

- **`app/Services/Nps/NpsSnapshotService.php` (novo):** service stateless com `NpsScoreCalculator` injetado. `registrar(NpsResponse $response): void`:
  1. Para cada dimensão em `[estrategista, analista, empresa]`: `compute()` != null → grava `NpsResponseScore` (`score_sum` = SUM dos pesos snapshot, `question_count` = nº de perguntas do template na dimensão, `average_score` = média). `compute()` == null (dimensão sem pergunta) → pula, sem linha.
  2. Congela **todos** os serviços cobertos do template (`serviceScopes()`) em `NpsResponseCoveredService` com `service_setor` snapshot.
  3. Interseção = serviços cobertos ∩ `contratosServico()->active()->pluck('servico_id')`. Para cada serviço da interseção e cada dimensão de pessoa (analista→`consultor`, estrategista→`estrategista`): se há score da dimensão, resolve os responsáveis via `consultorDoServico/estrategistaDoServico` e cria 1 `NpsScoreAssignment` por responsável (`role` = valor da pivot, `nps_response_score_id` amarrado ao score da dimensão). Sem responsável → `Log::warning('[NPS Snapshot] ...')` e nenhum assignment. Dimensão empresa não está no mapa → nunca gera assignment.
  - **Não abre transação própria** (roda dentro da transação do submit) e **não toca** `DesempenhoScoreService`/`->principal()`.
- **`app/Http/Controllers/NpsController.php` (modificado):** dentro da `DB::transaction` de `submitResponseV15`, após o `foreach` que grava as answers e antes do `$survey->update(status='completed')`, invoca `app(NpsSnapshotService::class)->registrar($response)`. Ordem crítica (Pitfall 3): nunca antes das answers. Se o dedup 23000 estourar no update, o snapshot reverte junto (comportamento correto, catch existente preservado).
- **`tests/Feature/V16/SubmitSnapshotTest.php` (novo, 2 casos):** (1) submit congela 3 scores por dimensão (sum/count/average corretos) + `covered_services` com setor + empresa só em scores (sem assignment) + regressão das answers; (2) dimensão sem pergunta no template não gera score nem assignment de analista.
- **`tests/Feature/V16/AtribuicaoPorServicoNpsTest.php` (novo, 3 casos):** (1) NPS Shopee atribui ao analista/estrategista SHOPEE (servico_id shopee), nunca aos responsáveis ML; (2) serviço coberto ∩ ativo sem responsável → 0 assignment + `Log::warning` com tag `[NPS Snapshot]`; (3) interseção vazia (serviço coberto não é contrato ativo) → 0 assignment, mas scores por dimensão ainda congelados.

## Como funciona (fluxo)

```
POST /nps/{token} → submitResponseV15
  DB::transaction {
    NpsResponse::create
    foreach answers → NpsResponseAnswer::create   (snapshot per-row)
    NpsSnapshotService::registrar($response) {     ← NOVO (após answers, dentro da txn)
       scores por dimensão   → nps_response_scores
       serviços cobertos     → nps_response_covered_services
       cobertos ∩ ativos     → nps_score_assignments (analista→consultor, estrategista→estrategista)
       empresa               → só score; responsável faltante → Log::warning
    }
    survey->update(completed)                       ← pode 23000 → reverte tudo
  }
```

## Decisões e desvios

### Decisões de implementação

- **Covered_services congela TODOS os serviços cobertos do template**, não apenas a interseção com contratos ativos. A interseção é aplicada apenas para gerar `assignments`. Isso preserva a foto real de "o que o modelo cobria" no momento da resposta (o RESEARCH §Fluxo b confirma: "serviços cobertos = template.serviceScopes").
- **`role` persiste o valor da pivot** (`consultor`/`estrategista`), não `analyst`/`strategist`, para JOIN direto na Fase 80 (A3 do RESEARCH / DEC-79-C).
- **Display por serviço no `respond()` NÃO implementado** — DEFERIDO explicitamente pelo plano (Open Question b, com fallback) para a Fase 80/quick task.

### Deviations from Plan

None — plano executado exatamente como escrito (Tarefas 1→2→3 na ordem, TDD RED→GREEN).

## Verificação

- `tests/Feature/V16` — **54 passed** (231 assertions), inclui os 5 novos casos de snapshot/atribuição.
- Regressão `--filter=Nps` — **168 passed** (1062 assertions): submit legacy Phase 31, submit v15 e bônus `->principal()` inalterados (DEC-79-E verde).
- Regressão `--filter=Desempenho` — **55 passed** + **1 falha pré-existente fora de escopo**: `PublicacaoDesempenhoRouteTest::user com mlb dashboard acessa rota e recebe 200` (GET `/publicacao/desempenho` retorna 403). É problema de RBAC do módulo de publicação, sem relação com o snapshot NPS (este plano só toca `submitResponseV15`, o novo service e testes V16). Registrado em `deferred-items.md` para o dono do módulo de publicação.

## Deferred Issues

- **Falha pré-existente de RBAC** em `/publicacao/desempenho` (403 vs 200) — fora do escopo NPS, encaminhada em `deferred-items.md`.
- **Display por serviço no `respond()`** — deferido para a Fase 80 (polish, com assert dedicado).

## Threat surface

Nenhuma superfície nova além do mapeado no `<threat_model>` do plano. As mitigações T-79-04-01 (interseção cobertos∩ativos), T-79-04-03 (empresa sem assignment), T-79-04-04 (ordem após answers) e T-79-04-05 (Log::warning em vez de assignment vazio) estão implementadas e cobertas por teste.

## Self-Check: PASSED
