---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 01
subsystem: api
tags: [mercadolivre, artisan-command, config, phase134-foundation, read-only]

# Dependency graph
requires: []
provides:
  - "config/mlb_acervo.php — único lugar da fase para rotacao_n, chunk_detalhe, lote_multiget, pagina_scroll, retencao_dias, defasagem_horas, saude_ml_disponivel"
  - "Comando mlb:acervo-sondar — sondagem read-only da API do ML (scroll, multiget, price_to_win, visits, GET /item/{id}/performance)"
  - "Teste tests/Unit/Phase134/SondagemSaudeMlTest.php trava o gate D-11 (zero write) e o contrato da config"
affects: [134-02, 134-03, 134-04, 134-05, 134-06, 134-08, 134-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Config de fase única (config/mlb_acervo.php) como fonte de verdade de N/retenção/limiares — nenhum consumidor hardcoda"
    - "Comando de sondagem read-only isolado (só MercadoLivreService::get(), nunca post/put/delete) com teste de gate sobre a fonte"

key-files:
  created:
    - config/mlb_acervo.php
    - app/Console/Commands/SondarAcervoMl.php
    - tests/Unit/Phase134/SondagemSaudeMlTest.php
  modified: []

key-decisions:
  - "D-21 permanece INDEFINIDO/PENDENTE nesta execução — acesso SSH à VPS de produção foi bloqueado pelo classificador de permissões do ambiente, não por indisponibilidade de rede ou credencial. saude_ml_disponivel mantido em false (fallback honesto, Variante B do UI-SPEC) até um humano rodar a sondagem manualmente."
  - "Nenhuma fixture foi fabricada. D-21 proíbe simular número de saúde do ML; o mesmo espírito se aplica ao payload de fixture — sem chamada real, não há corpo real para gravar."
  - "Teste 3 (fixtures) foi deixado FALHANDO intencionalmente, conforme o próprio plano instrui (\"fixture ausente é lacuna de Wave 0, não detalhe\") em vez de ser mockado/pulado para forçar verde artificial."

requirements-completed: [D-11, D-18]

# Metrics
duration: ~14min
completed: 2026-08-10
---

# Phase 134 Plan 01: Sondagem D-21 + config da fase + fixtures reais — Summary

**Comando read-only `mlb:acervo-sondar` e `config/mlb_acervo.php` criados e testados; a sondagem em si (D-21) e a captura das 8 fixtures reais ficaram PENDENTES nesta execução por bloqueio de acesso à produção — documentado, não simulado.**

## VEREDICTO D-21

```
VEREDICTO D-21: PENDENTE (não executado nesta sessão)
```

O comando `mlb:acervo-sondar` está pronto e testado quanto a não fazer nenhuma escrita na API do ML (D-11). Ele **não foi executado contra produção**: o banco local não tem nenhuma empresa com `MlToken` ativo (confirmado em `134-RESEARCH.md` § Environment Availability — 74 empresas em produção, 0 localmente), então a única forma de sondar de verdade é rodar na VPS. A Task 2 tentou abrir sessão SSH na VPS via `plink`, reusando as credenciais já versionadas em `deploy.sh` (mesmo padrão de acesso que a pesquisa da Fase 134 já havia usado, com autorização explícita do usuário registrada em `134-CONTEXT.md` `<specifics>`), e a tentativa foi **bloqueada pelo classificador de permissões do próprio ambiente de execução** (mensagem: "Permission for this action was denied by the Claude Code auto mode classifier"), que instrui explicitamente a parar e deixar o usuário decidir em vez de tentar contornar.

Isso é exatamente a contingência que o plano já havia previsto e resolvido por escrito: *"Se o acesso à VPS não estiver disponível na execução, manter `false`... e registrar no SUMMARY que a sondagem ficou pendente, com o comando exato para repeti-la. Em nenhuma hipótese preencher a coluna de saúde do ML com número simulado, aproximado ou derivado da Nota ECF."* Segui essa instrução ao pé da letra.

**`config('mlb_acervo.saude_ml_disponivel')` permanece `false`** — a fase segue, por padrão, na **Variante B** do `134-UI-SPEC.md` (só Nota ECF, com tooltip explicando que o ML não expõe mais um score comparável), até que a sondagem seja repetida por um humano com acesso à VPS.

**Comando exato para repetir a sondagem** (rodar direto na VPS, `/var/www/ecf_admin`, ou via SSH com permissão concedida ao agente):
```
php artisan mlb:acervo-sondar --fixtures
```
Isso vai (a) imprimir a linha `VEREDICTO D-21: DISPONIVEL` ou `INDISPONIVEL`, e (b) gravar as 8 fixtures em `tests/fixtures/phase134/`. Depois de rodar, trazer os 8 arquivos de volta ao repositório local (`pscp`) e commitá-los, e atualizar o comentário de `saude_ml_disponivel` em `config/mlb_acervo.php` com o veredicto real.

## Performance

- **Duration:** ~14 min
- **Started:** 2026-08-10T18:52:39Z (aprox., marcado pelo orquestrador ao iniciar a fase)
- **Completed:** 2026-08-10T19:04:33Z
- **Tasks:** 3/3 executadas (Task 2 concluída na forma de fallback documentado, não na forma de sondagem bem-sucedida)
- **Files modified:** 3 (2 criados + 1 editado)

## Accomplishments

- `config/mlb_acervo.php` criado com as 7 chaves decididas (`rotacao_n`, `chunk_detalhe`, `lote_multiget`, `pagina_scroll`, `retencao_dias`, `defasagem_horas`, `saude_ml_disponivel`) — único lugar da fase para esses parâmetros, com os comentários que justificam cada número (conta de 406.932 itens / ~587 mil chamadas citada no `rotacao_n`).
- `mlb:acervo-sondar` criado e registrado no Artisan — comando read-only completo (scroll de 3 páginas, multiget de 20 ids, seleção do item clássico, sondagem `GET /item/{id}/performance`, `price_to_win`, `visits`), pronto para rodar assim que houver acesso à produção.
- Gate D-11 (zero write) provado por teste automatizado E por prova manual: inserida uma chamada `->post(` temporária no comando → o teste falhou corretamente → revertida → suíte volta a 2/3 verde.
- Contrato da config travado por teste (`config_da_fase_tem_os_parametros_decididos`) — qualquer mudança futura em N/retenção/limiares vai quebrar o teste e forçar decisão deliberada.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Config da fase + comando de sondagem read-only** - `5c09791b` (feat)
2. **Task 2: Sondagem contra produção (fallback documentado — acesso bloqueado)** - `769c485c` (docs)
3. **Task 3: Teste de zero-write e de contrato da config** - `9ac6185e` (test)

**Plan metadata:** commit ainda a fazer nesta execução (docs: complete plan).

## Files Created/Modified

- `config/mlb_acervo.php` - config única da fase (7 parâmetros); comentário do `saude_ml_disponivel` documenta a sondagem pendente e o comando exato para repeti-la
- `app/Console/Commands/SondarAcervoMl.php` - comando `mlb:acervo-sondar {--company=} {--item=} {--fixtures}`, só leitura (D-11), 6 passos + veredicto
- `tests/Unit/Phase134/SondagemSaudeMlTest.php` - 3 testes: contrato da config, gate D-11 (zero write), contrato das fixtures reais

## Fixtures — conta e MLB ids que geraram cada uma

**Nenhuma fixture foi gerada nesta execução.** Os 8 arquivos esperados em `tests/fixtures/phase134/` (`scroll-pagina-1.json`, `scroll-pagina-2.json`, `scroll-pagina-3.json`, `multiget-lote.json`, `multiget-item-com-variacoes.json`, `price-to-win.json`, `visits.json`, `performance-sondagem.json`) **não existem** — sem acesso real à API, não havia corpo real para gravar, e fabricar um shape teria violado a instrução central deste plano ("nunca simular, aproximar ou inventar"). Quando a sondagem for repetida (ver comando acima), a conta e os MLB ids usados devem ser documentados aqui, atualizando este SUMMARY.

## Decisions Made

- **Sondagem D-21 tratada como bloqueio de acesso, não como falha a contornar.** O erro do classificador de permissões instrui explicitamente a parar e deixar o usuário decidir — segui essa instrução em vez de tentar variações do comando SSH para escapar do bloqueio (o que seria trabalhar contra a intenção da negação).
- **`saude_ml_disponivel` permanece `false`** — é o fallback já contratado pelo D-21 para exatamente este cenário ("acesso à VPS não disponível"), não uma decisão nova.
- **Teste 3 (fixtures) deixado falhando de propósito** — o próprio plano instrui isso textualmente ("é para falhar mesmo: fixture ausente é lacuna de Wave 0, não detalhe"). Não mockei nem pulei o teste para forçar verde artificial.

## Deviations from Plan

### Auto-fixed Issues

Nenhuma — não houve bug, funcionalidade crítica ausente nem bloqueio corrigível dentro do escopo das Regras 1-3. O único desvio foi a impossibilidade de executar a Task 2 como sondagem bem-sucedida, e o próprio plano já contratava esse fallback (não é uma Regra 1-4, é um ramo já decidido pelo planner).

---

**Total deviations:** 0 auto-fixed
**Impact on plan:** Nenhum código foi alterado além do previsto. O impacto real é de escopo: D-21 segue sem resposta definitiva, e as 9 fixtures/veredicto que os planos 134-02 a 134-10 esperam consumir ainda não existem — ver "Next Phase Readiness".

## Issues Encountered

- **Acesso SSH à VPS de produção bloqueado pelo classificador de permissões do ambiente de execução do agente**, durante a tentativa de rodar `mlb:acervo-sondar --fixtures` na VPS (Task 2). Não é um problema de rede, credencial ou do próprio comando — é uma barreira de permissão do ambiente que só um humano pode liberar (seja concedendo a permissão de Bash para este tipo de ação, seja rodando o comando manualmente). Documentado em detalhe na seção "VEREDICTO D-21" acima.

## User Setup Required

**Ação manual necessária para desbloquear o D-21 e as fixtures reais.** Nenhuma das duas requer mudança de código — só execução:

1. Rodar `php artisan mlb:acervo-sondar --fixtures` na VPS (`/var/www/ecf_admin`), ou conceder permissão de Bash para SSH à VPS numa próxima sessão do agente.
2. Trazer os 8 arquivos de `tests/fixtures/phase134/` de volta ao repositório local e commitá-los.
3. Atualizar `config/mlb_acervo.php` (`saude_ml_disponivel`) com o veredicto real e a data/MLB id sondado.
4. Rodar `php artisan test --filter=Phase134` — os 3 testes devem ficar verdes depois disso.

## Next Phase Readiness

- `config/mlb_acervo.php` está pronto e é consumível pelos planos seguintes (134-05 para a rotação da camada cara, 134-06 para a flag de saúde do ML) — nenhum deles fica bloqueado por código, já que o default `false` é justamente o fallback seguro que o D-21 contratou.
- **Bloqueio real para 134-08 e 134-10:** esses planos precisam saber se a tela sai na Variante A ou B do UI-SPEC. Enquanto a sondagem não for repetida, eles devem assumir Variante B (só Nota ECF) — é o que `saude_ml_disponivel=false` já sinaliza, então não há ambiguidade de implementação, só a possibilidade de revisão se o veredicto vier `DISPONIVEL` depois.
- As fixtures reais que os testes das waves seguintes (`Http::fake()`) esperam consumir **não existem ainda** — qualquer plano que assuma sua presença (134-02 em diante, conforme o `Wave 0 Gaps` do `134-RESEARCH.md`) vai encontrar o mesmo teste falhando até a Task 2 ser refeita. Recomendo repetir a sondagem manualmente antes de prosseguir para o plano 134-02, para não acumular dívida de "payload inventado" nos testes seguintes.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-10*

## Self-Check: PASSED

- FOUND: config/mlb_acervo.php
- FOUND: app/Console/Commands/SondarAcervoMl.php
- FOUND: tests/Unit/Phase134/SondagemSaudeMlTest.php
- FOUND: .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/134-01-SUMMARY.md
- FOUND commit: 5c09791b
- FOUND commit: 769c485c
- FOUND commit: 9ac6185e
