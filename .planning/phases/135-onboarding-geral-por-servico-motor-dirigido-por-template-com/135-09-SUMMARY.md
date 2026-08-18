---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 09
subsystem: api
tags: [laravel, inertia, permission-gate, agregacao-em-memoria, activity-log]

# Dependency graph
requires:
  - phase: 135-05
    provides: "OnboardingEngineService::confirmarResponsavel()/sugerirResponsavel()/podeIniciar() — transição rascunho→andamento com sugestão de responsável (D-17)"
  - phase: 135-08
    provides: "Rotas /onboarding/templates registradas ANTES do bloco do painel operacional — ordem que evita 'templates' ser capturado como {onboarding}"
provides:
  - "Permission Permissions::CORE_ONBOARDING ('core.onboarding') no catálogo Core (ECF Consultoria)"
  - "4 rotas do painel: onboarding.painel.index/show, onboarding.responsavel.confirmar, onboarding.passos.concluir — gate permission:core.onboarding, distinto do role:admin do CRUD de template"
  - "OnboardingController::index()/show() — payload agregado por empresa (D-01) respondendo 'o que trava / há quantos dias / de quem é a bola' (SC-11), com situacaoDe() de 6 estados calculada em memória sobre disponivel_em"
  - "OnboardingController::confirmarResponsavel()/concluirPasso() — ações da Coordenação com guarda de escopo por carteira e conversão de DomainException em erro de validação pt-BR"
affects: ["135-12"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "situacaoDe() como função pura sobre os passos já carregados (nenhuma query dentro do laço, T-135-09-06); prontoParaConcluir() é checado ANTES de vencido/aguardando na precedência — senão o passo administrativo (confirmacao_pagamento) vencido sozinho classificaria o onboarding como 'vencido'/'aguardando_interno', confundindo bloqueio administrativo com mapeamento parado (D-15)"
    - "diasParado()/passoQueTrava() como helpers puros reaproveitados tanto no resumo agregado (index) quanto no detalhe (show) — mesma fonte de verdade para 'quantos dias' nas duas telas"
    - "Filtro explícito de valor numérico em aguardando_coleta (detalhePasso()) — a ausência da chave 'valor' é código visível, não 'acontece de estar vazio' (D-11)"
    - "autorizarEscopo() duplica a mesma regra de carteira do index() (whereIn company_users) para show()/ações, que chegam por id direto sem passar pelo filtro da listagem (T-135-09-02, IDOR)"

key-files:
  created:
    - app/Http/Controllers/OnboardingController.php
    - tests/Feature/Phase135/OnboardingPainelAcoesTest.php
    - tests/Feature/Phase135/OnboardingPainelPropsTest.php
  modified:
    - app/Support/Permissions.php
    - routes/web.php

key-decisions:
  - "core.onboarding é permission DEDICADA (não role:admin) — o CRUD de template continua admin-only (D-04), mas o painel operacional serve Coordenação/consultor/estrategista também, seguindo a recomendação do 135-UI-SPEC.md (Open Question 2 do RESEARCH)"
  - "prontoParaConcluir() checado ANTES de vencido/aguardando na precedência de situacaoDe() — o único jeito de D-15 segurar quando o passo administrativo sozinho está vencido mas todo o resto do mapeamento já fechou"
  - "Nomes de componente Inertia (Onboarding/Painel para index, Onboarding/Detalhe para show) são escolha discricionária desta plan — o 135-UI-SPEC.md deixou aberto se o detalhe é drawer ou página própria ('funcionalmente equivalente'). O Plano 12 pode renomear o arquivo React sem quebrar o contrato de props; os testes usam component(nome, false) exatamente como o Plano 08 fez, pelo mesmo motivo (página ainda não existe nesta wave)"
  - "OnboardingController.php nasceu já na Task 1 (esqueleto index/show/confirmarResponsavel/concluirPasso com respostas mínimas) — as rotas não despacham pra uma classe que não existe; Task 2 substituiu o corpo de index()/show() pela lógica real, Task 3 substituiu confirmarResponsavel()/concluirPasso()"
  - "responsavel_sugerido foi incluído em resumoOnboarding() já no commit da Task 2 (não isolado na Task 3) — o campo é parte natural do mesmo método que monta o item de onboarding; o teste que prova D-17 (via index()) foi mantido na suíte de ações da Task 3, como o plano descreveu"

patterns-established:
  - "Escopo de leitura/escrita por carteira: index() filtra via whereIn(company_users), autorizarEscopo() reaplica a mesma regra em show()/confirmarResponsavel()/concluirPasso() — nenhuma ação por id confia soamente no filtro da listagem"
  - "Mensagem de erro pro usuário final é escrita fixa no controller (D-19: 'Este passo é verificado automaticamente pelo sistema...'), nunca repassa Exception::getMessage() — a mensagem de domínio é para log/depuração, não para o toast"

requirements-completed: [SC-01, SC-04, SC-11, D-01, D-05, D-11, D-14, D-15, D-17]

# Metrics
duration: ~55min
completed: 2026-08-12
---

# Fase 135 Plano 09: Painel operacional (backend) — permission, payload de "o que trava" e ações Summary

**O painel deixa de existir como conceito e ganha backend real: `core.onboarding` como gate próprio (distinto do `role:admin` do CRUD de template), um `index()` que agrupa por empresa e responde "o que está travando, há quantos dias, de quem é a bola" contando de `disponivel_em` — nunca uma porcentagem —, e as duas ações que a Coordenação precisa: confirmar responsável (liga o SLA) e concluir manualmente um passo, com o passo automático permanecendo imune ao clique manual (D-19).**

## Performance

- **Duration:** ~55 min (estimado — sessão não cronometrada desde o dispatch)
- **Completed:** 2026-08-12T17:21:10Z
- **Tasks:** 3
- **Files modified:** 5 (3 criados, 2 modificados) + `.planning/ROADMAP.md` (tracking da fase)

## Accomplishments

- `Permissions::CORE_ONBOARDING` (`core.onboarding`) no grupo "Core (ECF Consultoria)" do catálogo — admin passa pelo short-circuit já existente em `User::hasPermission()`, nenhum código novo de bypass precisou ser escrito.
- 4 rotas do painel registradas **depois** do bloco `/onboarding/templates` do Plano 08 (comentário de aviso preservado e reforçado em `routes/web.php`), confirmado por `route:list` e pelo número de linha (`onboarding.templates.index` < `onboarding.painel.show`).
- `situacaoDe()`: precedência de 6 valores sem nenhum campo de porcentagem (SC-11) — `rascunho` → `vencido` → `aguardando_{dono}` → `coletando` → `pronto_para_concluir` → `concluido`, com a checagem de `pronto_para_concluir` deliberadamente ANTES de vencido/aguardando pra D-15 nunca confundir "questão administrativa parada" (passo `confirmacao_pagamento`) com "mapeamento parado".
- `passoQueTrava()`: maior `dias_parado` entre os passos `aberto` (empate → menor `ordem`), reaproveitado tanto no resumo de `index()` quanto no `passo_que_trava` de cada item da lista.
- `diasParado()` conta de `disponivel_em`, nunca do `created_at` do onboarding — `null` (nunca `0`) quando o passo ainda não abriu.
- `detalhePasso()` (usado por `show()`) nunca expõe `valor['ativos']`/`['inativos']` enquanto o passo está em `aguardando_coleta` — filtro explícito no código, provado com uma fixture que grava um `valor` "sujo" de propósito pra confirmar que o controller de fato filtra (não que "acontece de estar vazio").
- `confirmarResponsavel()`/`concluirPasso()`: guarda de escopo por carteira (`autorizarEscopo()`) reaplicada nas duas ações — elas chegam por id direto, sem passar pelo filtro de `index()`; `DomainException` do engine vira erro de validação pt-BR em vez de 500 nos dois casos.
- 18 testes novos (3 + 9 + 6) cobrindo gate, payload agregado, ambas as ações e escopo de carteira — suíte `Phase135` completa foi de 129 para 147 testes, todos verdes.

## Task Commits

1. **Task 1: Permission core.onboarding + rotas do painel operacional** - `36d509cd` (feat)
2. **Task 2: index()/show() — o payload que responde "o que trava / há quantos dias / de quem é a bola"** - `8ceafa4e` (feat)
3. **Task 3: confirmar responsável + concluir passo manual** - `c40f1f67` (feat)

**Plan metadata:** (commit de documentação a seguir, via este SUMMARY)

## Files Created/Modified

- `app/Http/Controllers/OnboardingController.php` - Controller do painel: `index()`/`show()` (payload agregado, sem porcentagem), `confirmarResponsavel()`/`concluirPasso()` (ações), e os helpers privados `situacaoDe()`/`prontoParaConcluir()`/`passoQueTrava()`/`diasParado()`/`condicaoLegivel()`/`autorizarEscopo()`
- `app/Support/Permissions.php` - `CORE_ONBOARDING` no catálogo (só adição — `git diff -U0 | grep -c '^-[^-]'` = 0)
- `routes/web.php` - 4 rotas novas, registradas depois do bloco `/onboarding/templates` (Plano 08), com comentário de aviso de ordenação
- `tests/Feature/Phase135/OnboardingPainelAcoesTest.php` - Gate (403/200×2) + confirmar responsável (transição, erro de reconfirmação, D-17) + concluir passo (manual, D-19, 403 de escopo)
- `tests/Feature/Phase135/OnboardingPainelPropsTest.php` - Agrupamento por empresa, rascunho sem SLA, vencido, desempate por `dias_parado`, `aguardando_coleta` (D-11), `pronto_para_concluir` (D-15), `depende_de`/`condicao` legíveis, escopo de carteira, ausência de chaves de porcentagem (SC-11)

## Decisions Made

Ver `key-decisions` no frontmatter — resumo: gate por permission dedicada (não `role:admin`); `pronto_para_concluir` checado antes de vencido/aguardando na precedência de situação; nomes de componente Inertia (`Onboarding/Painel`/`Onboarding/Detalhe`) são escolha discricionária para o Plano 12 confirmar ou renomear; controller nasceu com esqueleto na Task 1 para as rotas terem classe pra despachar.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking issue] Rotas referenciam uma classe controller que a Task 1 do plano não listava para criação**
- **Found during:** Task 1 (registrar as 4 rotas do painel)
- **Issue:** O bloco `<interfaces>` do plano define as 4 rotas apontando para `OnboardingController`, mas o `<files>` da Task 1 só lista `app/Support/Permissions.php`, `routes/web.php` e o teste de gate — não o controller. Sem a classe existir, o dispatch das rotas (mesmo só para os testes de 403/200 desta Task) falharia com "Target class does not exist".
- **Fix:** Criado `app/Http/Controllers/OnboardingController.php` já na Task 1, com um esqueleto mínimo (`index()`/`show()` retornando `Inertia::render` com props vazias, `confirmarResponsavel()`/`concluirPasso()` retornando `back()`) — suficiente para os 3 testes de gate da Task 1 passarem. A Task 2 substituiu o corpo de `index()`/`show()` pela lógica real (payload agregado); a Task 3 substituiu `confirmarResponsavel()`/`concluirPasso()` pela lógica real (ações). Nenhum teste da Task 1 dependeu do payload real — só do código de status HTTP.
- **Files modified:** `app/Http/Controllers/OnboardingController.php` (criado)
- **Verification:** `--filter=OnboardingPainelAcoesTest` (3 testes da Task 1) passou 3/3 antes de qualquer lógica real existir.
- **Committed in:** `36d509cd`

---

**Total deviations:** 1 auto-fixed (Rule 3 — blocking issue de dispatch de rota, não uma mudança de escopo funcional)
**Impact on plan:** Nenhum. A antecipação da criação do arquivo é puramente estrutural (routing precisa da classe existir) — o CONTEÚDO de cada método só ganhou a lógica descrita na task correspondente do plano, na ordem que o plano descreveu.

## Issues Encountered

Nenhum bloqueio além da deviation acima. A suíte de regressão completa (`tests/Feature/Phase135`) permaneceu 100% verde do início ao fim (129 → 147 testes, todos passando), e o gate de Polos (`git status --porcelain | grep -i polos`) ficou vazio em todas as verificações antes de cada commit.

## User Setup Required

None — nenhuma configuração de serviço externo necessária. `core.onboarding` precisa ser concedida a um ou mais setores pela tela `/sistema/setores` para que alguém além do admin acesse o painel — isso é decisão operacional da Coordenação, não algo que esta fase deveria automatizar (mesma leitura do `135-09-PLAN.md`, Task 1).

## Next Phase Readiness

- O Plano 12 (Tela 1, React) recebe o contrato de props pronto: `empresas[].onboardings[]` do `index()` e `onboarding`/`passos[]` do `show()`, ambos sem nenhuma chave de porcentagem (SC-11 provado por teste). Os nomes de componente escolhidos (`Onboarding/Painel`, `Onboarding/Detalhe`) são discricionários — o Plano 12 pode renomear o arquivo React livremente; quando o `.jsx` existir, os testes que hoje usam `component($nome, false)` podem voltar a `true` (mesmo precedente do Plano 08 após o Plano 10).
- `responsavel_sugerido` (D-17) já está no payload de `index()` para onboardings em `rascunho` — o CTA "Confirmar responsável" do Plano 12 tem de onde ler a sugestão sem chamada adicional.
- `git diff --name-only` desta plan não toca nenhum arquivo de Polos nem do Plano 136 em curso em paralelo — `grep -c 'implementacao' routes/web.php` permanece em 24, idêntico à baseline do Plano 08.
- `.planning/STATE.md` não foi tocado por esta execução (owned pela sessão da Fase 136) — a atualização central fica para o orquestrador ao fim da fase, conforme instruído.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: `app/Http/Controllers/OnboardingController.php`
- FOUND: `app/Support/Permissions.php`
- FOUND: `routes/web.php`
- FOUND: `tests/Feature/Phase135/OnboardingPainelAcoesTest.php`
- FOUND: `tests/Feature/Phase135/OnboardingPainelPropsTest.php`
- FOUND: commit `36d509cd`
- FOUND: commit `8ceafa4e`
- FOUND: commit `c40f1f67`
