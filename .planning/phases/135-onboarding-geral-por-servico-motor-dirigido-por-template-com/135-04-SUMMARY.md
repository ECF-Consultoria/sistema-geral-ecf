---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 04
subsystem: services
tags: [laravel, eloquent, state-machine, dependency-graph, seeder, testing]

# Dependency graph
requires:
  - phase: 135-02
    provides: "5 tabelas + 5 models (TemplatePasso::AUTO_FONTES/DONOS/CONDICOES, Onboarding/OnboardingPasso com 6 estados, disponivel_em/coleta_iniciada_em no schema)"
  - phase: 135-03
    provides: "OnboardingResolverResultado (3 estados + chave reservada coleta_em_andamento)"
provides:
  - "Template de Gestão v1 publicado e idempotente (OnboardingTemplateGestaoSeeder) — 13 passos, 5 com auto_fonte"
  - "OnboardingEngineService completo: criarParaContrato/montarPassos/reavaliar/aplicarResultado/avaliarCondicao/concluirManualmente"
  - "Motor de dependências que destrava, carimba disponivel_em uma única vez e resolve passo condicional para nao_aplicavel"
  - "Tradução dos 3 estados do resolver em status/valor/coleta_iniciada_em, com guarda de carimbo único (regra 10b)"
affects: [135-05, 135-06, 135-07, 135-08, 135-09, 135-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "reavaliar() em laço limitado ao número de passos — defesa contra depende_de cíclico que escapasse da guarda do CRUD de template (Plano 08)"
    - "Guard de carimbo único (grava só quando ainda null) reaplicado em dois campos independentes — disponivel_em (regra 4) e coleta_iniciada_em (regra 10b) — mesmo padrão, dois propósitos"
    - "Estado do passo condicional resolvido a partir de outro passo do MESMO onboarding via query pela chave denormalizada, nunca por lógica livre — catálogo fechado + RuntimeException para tipo desconhecido"

key-files:
  created:
    - database/seeders/OnboardingTemplateGestaoSeeder.php
    - app/Services/Onboarding/OnboardingEngineService.php
    - tests/Feature/Phase135/OnboardingTemplateGestaoSeederTest.php
    - tests/Feature/Phase135/OnboardingEngineMontagemTest.php
    - tests/Feature/Phase135/OnboardingEngineDependenciasTest.php
  modified:
    - database/seeders/DatabaseSeeder.php

key-decisions:
  - "Seeder resolve o Servico de Gestão por consulta (setor=performance + nome contendo 'Gestão'), nunca por id fixo — e reaproveita a linha REAL já publicada pelas migrations de catálogo (2026_05_27_100001_seed_servicos_catalog + 2026_06_18_100002_seed_servicos_setor), nunca cria uma segunda 'Gestão'"
  - "condicao armazenado como {'tipo': <constante>} (objeto, não a constante solta) — molde que o FormRequest do Plano 08 (Rule::in em condicao.tipo, já lido no PATTERNS) também vai consumir"
  - "aplicarResultado() sempre termina chamando reavaliar() — idempotente em todos os 3 ramos (concluido/nao_coletado/indeterminado), mais simples do que decidir caso a caso quando cascatear"
  - "avaliarConclusaoDoOnboarding() é privado, chamado só de dentro de reavaliar() — nenhum consumidor externo decide sozinho quando o onboarding fecha"

patterns-established:
  - "Toda transição de OnboardingPasso passa por save() + reavaliar() do onboarding pai — nunca deixa o motor em estado parcialmente propagado"

requirements-completed: [SC-01, SC-05, SC-11, D-01, D-03, D-05, D-08, D-11, D-12, D-14, D-15, D-16, D-19]

# Metrics
duration: ~45min
completed: 2026-08-11
---

# Fase 135 Plano 04: Template de Gestão v1 + motor de dependências do Onboarding Summary

**Seeder idempotente do template de Gestão (13 passos, 5 automáticos) e `OnboardingEngineService` completo — monta o onboarding da versão congelada, destrava passo por dependência com `disponivel_em` carimbado uma única vez, resolve passo condicional para `nao_aplicavel`, e traduz os 3 estados do resolver respeitando a guarda de `coleta_iniciada_em`.**

## Performance

- **Duration:** ~45 min (inclui um desvio de depuração na Task 1 — ver Issues Encountered)
- **Started:** 2026-08-11T18:08:00Z (aprox., após leitura de contexto)
- **Completed:** 2026-08-11T18:49:44Z
- **Tasks:** 3
- **Files modified:** 6 (2 criados em `app/`+`database/seeders/`, 1 seeder modificado, 3 suítes de teste criadas)

## Accomplishments

- `OnboardingTemplateGestaoSeeder` publica a v1 do template de Gestão — as 13 linhas exatas do `<interfaces>` do plano, com `dono`/`auto_fonte`/`condicao` sempre vindos de constantes de `TemplatePasso` (nenhum literal solto, provado por grep), idempotente (rodar duas vezes mantém 1 template + 13 passos) e registrado em `DatabaseSeeder`.
- `OnboardingEngineService::criarParaContrato()`/`montarPassos()` — cria o onboarding em rascunho a partir da versão ativa congelada do template, com guard de duplicidade em duas camadas (por contrato e por par empresa×serviço) sem lançar exceção, e nenhuma chamada de rede (provado por grep).
- `reavaliar()` — destrava passo sem dependência quando o onboarding entra em `andamento`, propaga em cascata (laço limitado ao número de passos) e carimba `disponivel_em` **uma única vez**, provado por teste de idempotência com igualdade exata de timestamp.
- `avaliarCondicao()` — passo condicional (`excluir_anuncios_inativos`) lê o passo `anuncios_ativos_inativos` do mesmo onboarding: `inativos=0` → `nao_aplicavel`; `inativos>0` → `aberto`; passo 8 ainda não concluído → `null`/`bloqueado` (nunca decide por omissão). Condição fora do catálogo lança `\RuntimeException`.
- `aplicarResultado()` — traduz os 3 estados do resolver: `concluido` grava `valor`; `indeterminado` incrementa `tentativas` sem tocar `valor`; `nao_coletado` só vira `aguardando_coleta` quando `sinalizouColetaEmAndamento()` é verdadeiro, carimbando `coleta_iniciada_em` **uma única vez** (regra 10b — provado com 2 chamadas seguidas preservando o carimbo original). O motor nunca lê `assincrono()` do resolver (provado por grep).
- `concluirManualmente()` lança `\DomainException` para passo com `auto_fonte` (D-19). Onboarding fecha (`concluido_em` + `activity('onboarding')`) quando todo passo obrigatório está `concluido`/`nao_aplicavel`; pagamento (`confirmacao_pagamento`) nunca bloqueia os passos de mapeamento (D-15), provado por um teste dedicado que conclui 7/8/12 e mantém o onboarding em `andamento`.
- 40 testes novos (8 seeder + 5 montagem + 18 dependências + 9 já existentes reconfirmados), suíte `--filter=Phase135` completa em 63/63, os 4 arquivos de risco do Observer em 52/52, e o gate de regressão de Polos na baseline exata (10 falhas pré-existentes: 6 `PolosControllerTest` + 4 `PolosFaturamentoSnapshotTest`, zero novas).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Seeder do template de Gestão v1 (13 passos)** - `d1cba6ae` (feat)
2. **Task 2: OnboardingEngineService — montar o onboarding a partir da versão congelada** - `d85cea87` (feat)
3. **Task 3: Avaliação de dependências, disponivel_em e condições** - `aab3a66a` (feat)

**Plan metadata:** (a ser adicionado no commit final de documentação)

## Files Created/Modified

- `database/seeders/OnboardingTemplateGestaoSeeder.php` - publica a v1 do template (13 passos, idempotente)
- `database/seeders/DatabaseSeeder.php` - encadeia o novo seeder
- `app/Services/Onboarding/OnboardingEngineService.php` - `criarParaContrato`/`montarPassos`/`reavaliar`/`aplicarResultado`/`avaliarCondicao`/`concluirManualmente`
- `tests/Feature/Phase135/OnboardingTemplateGestaoSeederTest.php` - 8 testes do seeder
- `tests/Feature/Phase135/OnboardingEngineMontagemTest.php` - 5 testes da montagem
- `tests/Feature/Phase135/OnboardingEngineDependenciasTest.php` - 18 testes das 11 regras do motor

## Decisions Made

- Seeder resolve o Servico de Gestão por consulta (nunca id fixo) e reaproveita a linha real que as migrations de catálogo já publicam — nenhum teste cria uma segunda "Gestão" (o que ambiguaria a resolução por nome).
- `condicao` gravado como `{'tipo': <constante>}` — consistente com o `Rule::in(condicao.tipo, ...)` que o `135-08-PLAN.md` já especifica para o FormRequest do CRUD de template.
- `aplicarResultado()` chama `reavaliar()` incondicionalmente ao final (idempotente nos 3 ramos), evitando lógica condicional duplicada sobre quando cascatear.

## Deviations from Plan

None — plano executado exatamente como escrito nas 3 tasks. Os itens abaixo foram correções feitas **antes** de qualquer commit (nunca chegaram a ficar versionados incorretos), documentadas em Issues Encountered por transparência.

## Issues Encountered

- **Suposição de banco vazio nos testes do seeder estava errada.** As migrations de catálogo (`2026_05_27_100001_seed_servicos_catalog` + `2026_06_18_100002_seed_servicos_setor`) já publicam um `Servico` real "Gestão" com `setor=performance`/`ativo=true` em **qualquer** banco migrado do zero — inclusive o SQLite de teste via `RefreshDatabase`. Meus primeiros testes criavam uma segunda "Gestão" e assumiam tabela vazia no caso negativo; ambos os testes falhavam de forma reveladora (não do jeito que eu esperava). Corrigido antes do commit: os testes reaproveitam a linha real (`servicoDeGestao()` busca em vez de criar) e o caso negativo desativa a linha existente em vez de assumir ausência. Nenhum código de produção mudou — só a fixture dos testes. **Relevante para os Planos 05-13**, que também criam `ContratoServico`/`Onboarding` em teste: o catálogo de `Servico` nunca está vazio num banco migrado.
- **Grep de escopo D-08 pegou o próprio docblock explicativo.** O primeiro rascunho do seeder citava os nomes dos outros serviços ("Publicação, Shopee, Assessoria...") para explicar o que **não** está no escopo — o `grep -rn "Publicação\|Shopee\|..." database/seeders/OnboardingTemplateGestaoSeeder.php` do bloco `<verification>` do plano não distingue prosa de código e apontou a violação. Reescrito sem citar os nomes antes do commit da Task 3 (mesmo cuidado já registrado no `135-03-SUMMARY.md` para os greps de aceite).
- **Grep de "sem chamada de rede" também pegou o próprio docblock.** O primeiro rascunho de `OnboardingEngineService::criarParaContrato()` explicava a restrição citando `` `Http::` `` e `` `Artisan::call` `` entre crases — o grep de aceite da Task 2 (`Http::|Artisan::call|...`) não diferencia comentário de código. Reescrito em prosa sem os literais antes do commit.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `OnboardingEngineService` pronto para o `ContratoServicoObserver` do Plano 05 chamar `criarParaContrato()` nos 4 call-sites de `ContratoServico::create()` — método já cobre o cenário de loop sem transação do `CompanyGroupController` (guard de duplicidade em duas camadas, nenhuma exceção lançada).
- `aplicarResultado()` pronto para os resolvers de rede dos Planos 06/07 (`AdmanGrantResolver`, `AnunciosAtivosInativosResolver`, `MetricasContaResolver`) chamarem após resolver — só precisam produzir um `OnboardingResolverResultado` e passar o passo correspondente.
- `reavaliar()` pronto para o comando de reavaliação periódica do Plano 12 chamar sobre onboardings com passo `aguardando_coleta`/`indeterminado`.
- `concluirManualmente()` pronto para o controller do painel operacional (Plano 09) expor a conclusão manual de passo `dono=cliente`/`interno` sem `auto_fonte`.
- Gate de regressão de Polos conferido nesta sessão: 10 falhas pré-existentes (6 `PolosControllerTest` + 4 `PolosFaturamentoSnapshotTest`), batendo exatamente com a baseline. Zero falha nova.
- Os 4 arquivos de risco do Observer (`Phase112HubspotHandoffWebhookTest`, `Phase113HubspotDedupTest`, `Phase37ComercialListagemTest`, `Phase37CompaniesPerformanceFilterTest`) seguem 100% verdes (52/52) — nenhum Observer foi criado nesta plan ainda (nasce no Plano 05).
- `git status --porcelain` sem nenhum arquivo de Polos (`mlb_implementacoes`/`MlbImplementacao`/`Pages/MlbImplementacao`/`Pages/Mlb/ImplementacaoPublica.jsx`) — D-02 intacto.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-11*

## Self-Check: PASSED

- FOUND: `database/seeders/OnboardingTemplateGestaoSeeder.php`
- FOUND: `database/seeders/DatabaseSeeder.php`
- FOUND: `app/Services/Onboarding/OnboardingEngineService.php`
- FOUND: `tests/Feature/Phase135/OnboardingTemplateGestaoSeederTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingEngineMontagemTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingEngineDependenciasTest.php`
- FOUND: commit `d1cba6ae`
- FOUND: commit `d85cea87`
- FOUND: commit `aab3a66a`
