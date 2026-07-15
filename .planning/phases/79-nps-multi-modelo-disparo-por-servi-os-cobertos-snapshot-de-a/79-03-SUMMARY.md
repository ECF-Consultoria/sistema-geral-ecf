---
phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
plan: 03
subsystem: nps
tags: [disparo-mensal, artisan-command, service-scopes, idempotencia, multi-modelo]
requires:
  - "Template NPS Shopee + scopes performance no NPS Padrão (Plano 79-02 - 2026_07_14_200002)"
  - "nps_template_service_scopes / NpsTemplate::serviceScopes (Phase 68)"
  - "Company::contratosServico()->active() (Módulo Serviços) + ContratoServico scope active()"
provides:
  - "Disparo mensal ESTRITO: 1 envio por modelo aplicável (serviços cobertos ∩ contratos ativos ≠ ∅)"
  - "Empresa ML+Shopee recebe 2 NPS separados (um por área)"
  - "Log::warning + contador puladosSemModelo p/ empresas sem cobertura (blindagem de rollout)"
  - "Dedup por (company_id, mês, template_id) — suporta N modelos sem bloquear o 2º"
  - "Trait Tests\\Concerns\\ContrataServicoNpsCoberto (fixture reusável de elegibilidade)"
affects:
  - "Comportamento do schedule nps:disparar-mensal (09:00 BRT) — mesmo comando, nova regra"
  - "Suites de disparo legadas (Phase 31/69/73) adaptadas ao novo contrato"
tech-stack:
  added: []
  patterns:
    - "Query de modelos aplicáveis: NpsTemplate::where(active,envio_automatico_mensal)->whereHas('serviceScopes', whereIn servico_id)"
    - "Loop por modelo dentro do loop por empresa; dedup composto com template_id"
    - "Trait de teste em Tests\\Concerns para reuso cross-namespace (Phase 31/69/73)"
key-files:
  created:
    - "tests/Feature/V16/DisparoEstritoTest.php"
    - "tests/Concerns/ContrataServicoNpsCoberto.php"
  modified:
    - "app/Console/Commands/NpsDispararMensal.php"
    - "tests/Feature/Phase31NpsDispararMensalTest.php"
    - "tests/Feature/NpsDigisac/NpsDispararMensalDigisacTest.php"
    - "tests/Feature/Phase69/NpsDispararMensalTemplateTest.php"
    - "tests/Feature/Phase69/NpsPhase69IntegrationTest.php"
    - "tests/Feature/Phase73/NpsV15E2ETest.php"
decisions:
  - "DEC-79-A estrito: sem serviço coberto → NENHUM NPS (sem fallback is_default)"
  - "OQ-a: assunto do email prefixado com $modelo->nome (MVP, sem campo novo por-template)"
  - "Pitfall 2: dedup passa a incluir template_id — move o guard pra dentro do loop de modelos"
  - "Testes de disparo que codificavam 'força principal' (interino 2026-07-13) foram reescritos p/ a matriz estrita"
metrics:
  duration: "~40min"
  completed: "2026-07-14"
  tasks: 2
  files: 8
---

# Phase 79 Plan 03: Disparo NPS estrito por serviços cobertos — Summary

Reescreve o loop do comando `nps:disparar-mensal` de "força o modelo principal (is_default) para TODAS as empresas" para o modo **ESTRITO por serviços cobertos** (DEC-79-A): para cada empresa elegível, gera 1 envio por modelo com `envio_automatico_mensal=true` cujos "Serviços cobertos" (`nps_template_service_scopes`) intersectam um contrato ATIVO da empresa. Empresa ML+Shopee passa a receber 2 NPS separados por área; empresa sem serviço coberto por nenhum modelo recebe 0 NPS e gera `Log::warning` estruturado (blindagem de rollout).

## O que foi construído

- **`app/Console/Commands/NpsDispararMensal.php` reescrito:**
  - Removida a resolução única `$principal = NpsTemplate::where('is_default',true)` e o abort-cedo por ausência de principal. Preservados: abort de "ambos canais off", query base de empresas (guards email/digisac), clamp do dia-alvo, guard de estrategista obrigatório, envio email + Digisac isolados, `catch \Throwable` por empresa, tag `[NPS Mensal]`.
  - Dentro do loop por empresa (após guards), calcula `$servicoIds = $empresa->contratosServico()->active()->pluck('servico_id')` e `$modelosAplicaveis = NpsTemplate::where(active,envio_automatico_mensal)->whereHas('serviceScopes', whereIn servico_id)->get()`.
  - `$modelosAplicaveis` vazio → `Log::warning("... sem modelo aplicável ...")` + `$puladosSemModelo++` + `continue` (sem fallback).
  - `foreach ($modelosAplicaveis as $modelo)`: **dedup por template** — `NpsSurvey::where(company_id)->whereDate(month_reference)->where('template_id', $modelo->id)->exists()` (corrige o Pitfall 2 que bloqueava o 2º modelo). Cria o survey com `template_id => $modelo->id`; assunto do email prefixado com `$modelo->nome` (OQ-a MVP); Digisac recebe `$modelo`.
  - Output final e log de conclusão reportam o novo contador `$puladosSemModelo`.
- **`tests/Feature/V16/DisparoEstritoTest.php` (novo, 5 casos):** matriz (a) só performance → 1 NPS Padrão; (b) só shopee → 1 NPS Shopee; (c) performance+shopee → 2 surveys; (d) sem cobertura → 0 + `Log::warning`; (e) dedup por template não duplica nem bloqueia o 2º modelo.
- **`tests/Concerns/ContrataServicoNpsCoberto.php` (novo):** trait de fixture que torna uma empresa elegível ao disparo estrito (serviço performance ativo + contrato + scope no template alvo).

## Verificação

- `tests/Feature/V16/DisparoEstritoTest.php` — **5 passed (11 assertions)**.
- Regressão `--filter=Nps` — **165 passed (1050 assertions)** (submit legacy/v15 e bônus `->principal()` intocados; suites de disparo adaptadas ao novo contrato).

## Desvios do plano

### Correções automáticas (Rule 1/3 — falhas de regressão causadas pela mudança de contrato)

**1. [Rule 3 - Blocking] Suites de disparo legadas quebraram com o modo estrito**
- **Encontrado em:** verificação `--filter=Nps` após a reescrita (Tarefa 2).
- **Problema:** Phase 31, Phase 69 (template + integração), Phase 73 e NpsDigisac montavam empresas elegíveis SEM contrato de serviço. No modo estrito isso resulta em 0 surveys, quebrando 16 testes. Além disso, Phase 69 template T2/T3/T4 e integração fluxo 3 assertavam explicitamente o comportamento interino (2026-07-13) de "força o principal / ignora scope / aborta sem principal" — semântica revertida por DEC-79-A.
- **Correção:** criado o trait `ContrataServicoNpsCoberto` (contrato performance coberto). Phase 31/Digisac/Phase 73/Phase 69-fluxo1 ganham o contrato coberto para elegibilidade. Phase 69 template T2/T3/T4 e integração fluxo 3 reescritos para a matriz estrita (modelo por scope, empresa sem cobertura → 0 + `warning "sem modelo aplicável"`).
- **Arquivos:** 5 arquivos de teste + trait novo.
- **Commit:** `756d943`.

**2. [Rule 1 - Fixture] Serviço shopee scopado ≠ serviço contratado no DisparoEstritoTest**
- **Encontrado em:** primeira execução verde parcial (casos b/c/e falhando).
- **Problema:** o seed 79-02 linka o NPS Shopee a UM serviço shopee (`value('id')`); o teste criava um segundo serviço shopee e contratava esse (não scopado).
- **Correção:** helpers `servicoPerformanceId()/servicoShopeeId()` resolvem o serviço já semeado em vez de duplicar.
- **Commit:** `2dd2620`.

## Itens deferidos (fora de escopo)

Suite completa (`php artisan test`) tem falhas **pré-existentes** em domínios não tocados (Phase13/14/18/33/37/38/42 — setores/Shopee/polos do dev paralelo; ex.: `Servico::SETORES` agora com 5 entradas) + fatal por chamada HTTP real em `MercadoLivreAdsService` (ambiental). Não causadas por este plano. Registradas em `deferred-items.md`.

## Self-Check: PASSED
