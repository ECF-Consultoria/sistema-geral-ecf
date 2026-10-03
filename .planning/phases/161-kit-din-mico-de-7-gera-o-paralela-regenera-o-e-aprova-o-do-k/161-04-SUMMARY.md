---
phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k
plan: 04
subsystem: creative-engine
tags: [laravel, mercado-livre, publicacao, creative-engine]

requires:
  - phase: 161-03
    provides: "CreativeKitPublicacao::aplicarPictures()/limiteDaCategoria() (PUB-01/PUB-04); MlAnuncioCriativoKit::aprovadas()/minimo_aprovadas/STATUS_*"
provides:
  - "CreativeKitPublicacao::conferir() — gate de PUB-03: recusa publicar com kit não aprovado ou abaixo do mínimo, mensagem em pt-BR"
  - "kitDoRascunho() — critério único de 'o kit deste rascunho' compartilhado por conferir() e aplicarPictures()"
  - "MlPublicacaoService::publicar() chama conferir()+aplicarPictures() como as duas primeiras instruções do try — cobre rascunho único, par duplo e o job do lote, porque os três desembocam ali"
affects: ["161-05"]

tech-stack:
  added: []
  patterns:
    - "Gate DENTRO do try (não antes): a exceção cai no catch já existente, que grava validation_errors em pt-BR e põe o rascunho em erro — zero mudança no controller/front para o motivo chegar à tela"
    - "Re-aplicar antes de montar(): aplicarPictures() reconstrói payload.pictures do banco ANTES do builder ler o payload, neutralizando qualquer payload.pictures reduzido por autosave do wizard"
    - "No-op por construção, não por condicional espalhado: sem kit (kitDoRascunho()===null) ou com a chave creative_engine_ativo desligada, conferir() e aplicarPictures() não tocam em nada — a não-regressão é garantida pela MESMA função usada em todo lugar, não por um if duplicado no serviço de publicação"

key-files:
  created:
    - tests/Feature/Phase161/CriativoKitPublicacaoGateTest.php
  modified:
    - app/Services/Creative/CreativeKitPublicacao.php
    - app/Services/Mlb/Publicacao/MlPublicacaoService.php

key-decisions:
  - "kitDoRascunho() extraído de aplicarPictures() (161-03) para um método privado usado também por conferir() — elimina a possibilidade de as duas funções divergirem sobre o que é 'o kit deste rascunho' (o mais recente que não esteja em erro)."
  - "Mensagem de 'kit não aprovado' usa o formato literal do plano ('N de M imagens aprovadas... Aprove o kit antes de publicar'); mensagem de 'abaixo do mínimo' foi escrita por este executor (o plano não deu o texto literal, só a exigência de dizer quantas faltam) — ambas sem id interno, sem status técnico, só contagens e pt-BR, conferido por teste que afirma a AUSÊNCIA de 'RuntimeException' e do id do rascunho na mensagem."
  - "CreativeEngineAtivo não precisou de import `use`: já vive no mesmo namespace App\\Services\\Creative que CreativeKitPublicacao."

requirements-completed: [PUB-03]

duration: ~40min
completed: 2026-10-02
---

# Phase 161 Plan 04: Kit dinâmico de 7 — o guarda-corpo da publicação Summary

**`CreativeKitPublicacao::conferir()` recusa publicar um anúncio com kit de criativos por IA não aprovado ou abaixo do mínimo, e `aplicarPictures()` roda de novo no instante da publicação — plugados como as duas primeiras linhas do `try` do único serviço por onde toda publicação passa, então nenhum autosave do wizard consegue publicar menos imagens do que o operador aprovou.**

## Performance

- **Duration:** ~40min
- **Completed:** 2026-10-02
- **Tasks:** 2/2 completas
- **Files modified:** 3 (1 criado, 2 modificados)

## Accomplishments

- `CreativeKitPublicacao::conferir()` prova os dois casos de não-regressão PRIMEIRO (sem kit; com a chave `creative_engine_ativo` desligada) — são eles que autorizam mexer num serviço (`MlPublicacaoService`) usado por toda publicação do módulo, não só pelo Creative Engine.
- Kit em `planejado`/`gerando`/`parcial`/`pronto` (existente e não aprovado) recusa com a mensagem literal do plano; kit `aprovado` mas abaixo de `minimo_aprovadas` recusa com mensagem própria dizendo quantas faltam; kit em `erro` é ignorado (mesmo critério de `aplicarPictures()`).
- `MlPublicacaoService::publicar()` ganhou `CreativeKitPublicacao` no construtor promovido (resolvido pelo container — `grep -rn "new MlPublicacaoService" app/ tests/` continua em 0) e chama `conferir()`+`aplicarPictures()` como as duas primeiras instruções DENTRO do `try`, antes de `montar()` — a exceção do gate cai no `catch` já existente, que grava `validation_errors` em pt-BR e põe o rascunho em `erro`, sem nenhuma mudança no controller nem no front.
- Prova da armadilha do autosave (T-161-24): um rascunho com `payload.pictures` reduzido a 1 item ANTES da chamada de publicar, com kit aprovado de 3 slots, publica com as 3 imagens do kit, na ordem dos slots — não com a foto única do autosave.
- Publicar com kit não aprovado devolve 422, mensagem do gate em `erros[0].mensagem`, rascunho em `erro`, e `Http::assertNothingSent()` — nem a detecção de modelo da conta chegou a rodar.
- Publicar sem kit continua byte a byte como antes (mesmo payload enviado, mesma sequência, mesmo status final).
- `PublicarAnuncioMlJob::handle()` herda o gate sem nenhuma mudança própria: rodar o job com um rascunho de kit não aprovado relança a mesma `RuntimeException` e deixa o rascunho em `erro` com a mesma mensagem.
- 159/159 testes verdes no conjunto pinado pelas restrições do executor (146 anteriores + 13 novos desta onda), 0 regressão.

## Task Commits

1. **Task 1: conferir() — o gate de PUB-03, com a não-regressão como primeiro teste** - `2247c2ea` (feat, com teste)
2. **Task 2: Plugar o gate no único chokepoint de publicação** - `1c97442f` (feat, com teste)

_Teste e implementação escritos e verificados juntos antes de cada commit, igual às três ondas anteriores desta fase — nenhum RED/GREEN em commits separados._

## Files Created/Modified

- `app/Services/Creative/CreativeKitPublicacao.php` - `conferir()` (gate de PUB-03) + `kitDoRascunho()` extraído e compartilhado com `aplicarPictures()`; construtor ganhou `CreativeEngineAtivo`
- `app/Services/Mlb/Publicacao/MlPublicacaoService.php` - construtor ganhou `CreativeKitPublicacao $kitPublicacao`; `publicar()` chama `conferir()`+`aplicarPictures()` como as duas primeiras instruções do `try`
- `tests/Feature/Phase161/CriativoKitPublicacaoGateTest.php` (novo) - 13 testes: 2 de não-regressão (Task 1, escritos primeiro), 4 casos de recusa com dataProvider, kit em erro ignorado, kit aprovado aplicando pictures em ordem (Task 1); 2 casos HTTP de recusa/sucesso via `POST publicar`, publicar sem kit idêntico, e o job de lote herdando o gate (Task 2)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: `kitDoRascunho()` ficou como critério único entre `conferir()` e `aplicarPictures()`; a mensagem de "abaixo do mínimo" foi escrita por este executor (o plano só deu o texto literal da mensagem de "não aprovado"); `CreativeEngineAtivo` não precisou de `use` por já estar no mesmo namespace.

## Deviations from Plan

Nenhuma. O plano foi executado como escrito — as duas decisões documentadas acima são preenchimento de detalhe que o próprio plano deixou a critério da implementação (texto exato da segunda mensagem), não desvio de comportamento.

### Observação fora do escopo deste plano (não é regressão)

A regressão ampla pedida pelo `<verification>` do plano
(`--filter="Publicacao|Publicar|Rascunho|MeusAnuncios"`, 247 testes) reportou **5 falhas**, todas
com a MESMA causa raiz: `MlbAnuncioController::atualizarRascunho()` (rota de **atualizar** o
rascunho via `PUT`, não `publicar()`) chama `MlCatalogoMetaService::categoria()` dentro de uma regra
de validação, que tenta uma chamada de rede real ao Mercado Livre
(`MlColetaService::getAppToken()` → `RuntimeException: [MLB Coleta] Falha ao obter app token: HTTP
400`) — nenhum desses testes usa `Http::fake()`/`Http::preventStrayRequests()` para essa chamada
específica. As 5 falhas: `Phase75\PublicarEmpresaNaoAtribuidaTest::test_admin_nao_recebe_403_no_update`,
`Phase75\PublicarEmpresaNaoAtribuidaTest::test_publicador_dono_nao_recebe_403`,
`Phase75\RascunhoCompanyIdImutavelTest::test_atualizar_rascunho_ignora_company_id_e_mlb_empresa_id`,
`Phase77\PayloadCompletoTest::test_atualizar_rascunho_preserva_payload_completo`,
`PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` (esta última
nem toca em rascunho — é uma rota de dashboard que só entrou na amostra porque o nome bate com o
filtro `Publicacao`). Nenhum desses caminhos passa por `MlPublicacaoService::publicar()` nem por
`CreativeKitPublicacao` — os dois arquivos tocados por este plano. Confirmado por leitura do stack
trace completo de cada falha (todas nascem em `atualizarRascunho()`/`MlCatalogoMetaService`, zero
menção a `publicar()`/`CreativeKitPublicacao`/`kitPublicacao`). Não corrigido (Rule de escopo: não é
código tocado por esta fase; é uma dependência de rede que esses testes já exercitavam antes deste
plano existir). Registrado aqui por honestidade de verificação — o conjunto PINADO pelas restrições
do executor (`tests/Feature/Phase160/`, `tests/Unit/Phase160/`, `tests/Feature/Phase161/`,
`tests/Unit/Phase161/`, `tests/Unit/GeminiImageProviderTest.php`), que é o que de fato não pode
regredir, ficou **159/159 verde**.

## Known Stubs

Nenhum introduzido por este plano.

## Threat Flags

Nenhum além do já registrado no `<threat_model>` do `161-04-PLAN.md` (T-161-24 a T-161-28, T-161-SC)
— a implementação seguiu as mitigações descritas ali: `aplicarPictures()` re-aplica a lista aprovada
dentro do chokepoint de publicação (T-161-24); `conferir()` recusa publicação com kit não aprovado,
APROV-05 da Fase 160 continua valendo porque só criativo aprovado tem `ml_picture_url` (T-161-25);
`conferir()`/`aplicarPictures()` são no-op sem kit e com a chave desligada, com os dois testes de
não-regressão escritos primeiro (T-161-26); mensagem em pt-BR sem id interno, sem status técnico e
sem nome de classe — provado por teste que afirma a ausência dessas strings (T-161-27); o motivo da
recusa fica em `validation_errors` do rascunho (code `publish_failed`) e no log, com o id do rascunho
só no log (T-161-28). Nenhum pacote novo foi instalado (T-161-SC).

## Verification Results

1. `phpunit --filter="CriativoKitPublicacaoGateTest"` — **13/13 verde** (47 assertions).
2. `phpunit tests/Unit/Phase161 tests/Feature/Phase161` — **93/93 verde** (421 assertions) — 80 das
   três ondas anteriores desta fase + 13 novas, zero regressão.
3. `phpunit --filter="Publicacao|Publicar|Rascunho|MeusAnuncios"` (247 testes) — **242/247 verde**;
   as 5 falhas são pré-existentes e fora de escopo (ver nota de observação acima — causa raiz em
   `atualizarRascunho()`/`MlCatalogoMetaService`, não em `publicar()`/`CreativeKitPublicacao`).
4. `grep -rn "new MlPublicacaoService" app/ tests/ | wc -l` → **0** (nenhuma construção manual que
   precisasse do argumento novo).
5. `grep -rn "pictures/items/upload" app/ | grep -v MlImagemService.php | wc -l` → **4** linhas em
   **2 arquivos** (`app/Services/Publicador/ClienteMlPublicador.php`,
   `app/Services/Publicador/ImagemAssetService.php`) — mesmo módulo "Publicador" já documentado como
   fora de escopo na 161-03-SUMMARY (código de outra sessão/milestone, anterior à Fase 161). Escopado
   ao Creative Engine (`app/Http/Controllers/MlbAnuncioController.php` + `app/Services/Creative/` +
   `app/Services/Mlb/Publicacao/`) → **0**.
6. `grep -n "CreativeKitPublicacao" app/Services/Mlb/Publicacao/MlPublicacaoService.php` — mostra o
   `use`, a injeção no construtor e as duas chamadas (`conferir()`/`aplicarPictures()`) dentro do
   `try`.
7. Conjunto pinado pelas restrições do executor (`tests/Feature/Phase160/`, `tests/Unit/Phase160/`,
   `tests/Feature/Phase161/`, `tests/Unit/Phase161/`, `tests/Unit/GeminiImageProviderTest.php`) —
   **159/159 verde** (682 assertions), nenhuma regressão sobre os 146 anteriores.

## Self-Check: PASSED

- `app/Services/Creative/CreativeKitPublicacao.php` — FOUND (método `conferir()` presente)
- `app/Services/Mlb/Publicacao/MlPublicacaoService.php` — FOUND (`CreativeKitPublicacao` injetado)
- `tests/Feature/Phase161/CriativoKitPublicacaoGateTest.php` — FOUND
- Commit `2247c2ea` — FOUND (git log)
- Commit `1c97442f` — FOUND (git log)

---
*Phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k*
*Completed: 2026-10-02*
