---
phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k
plan: 01
subsystem: creative-engine
tags: [laravel, inertia, react, creative-engine, mercado-livre, llm-planner, cache-lock]

requires:
  - phase: 160-01
    provides: "ml_anuncio_criativos, MlAnuncioCriativo (estados/LIMITE_MINUTOS), CreativeEngineAtivo, ReferenciaEfemeraService, PainelCriativosIa.jsx"
  - phase: 160-02
    provides: "CreativeContext/ProductTruth DTOs, CreativeContextBuilder (fronteira única CTX-02), ProductTruthBuilder, ImageGenerationProvider::gerarTexto()"
provides:
  - "ml_anuncio_criativo_kits (agregação do kit de 7) + kit_id/slot_indice/slot_plano em ml_anuncio_criativos — migrations aditivas"
  - "MlAnuncioCriativoKit — estados, LIMITE_MINUTOS=25, recalcularStatus(), tetos de custo/regeneração"
  - "CreativeSlotCatalog — 8 tipos SEM_FATO (piso visual, PLAN-01) + 6 COM_FATO com requisito fechado (PLAN-03)"
  - "CreativePlanner — 3 camadas: elegibilidade → LLM curto em JSON → reconciliação determinística (PLAN-02)"
  - "CreativePermissao — 2ª camada de OPS-04 (permission key + lista creative_engine_usuarios), independente de role:admin"
  - "PlanejarKitCriativosJob — fila creative, grava plano + cria os 7 criativos numa transação"
  - "Endpoints mlb.anuncios.criativo.kit.planejar (com Cache::lock por rascunho_id) e .kit.status (whitelist T-161-05)"
  - "PainelCriativosIa.jsx — botão 'Planejar kit de 7' + grade dos 7 slots planejados"
affects: ["161-02"]

tech-stack:
  added: []
  patterns:
    - "Check-then-act sob Cache::lock()->block() com fallback por LockTimeoutException (molde MercadoLivreService::ml-refresh / ShopeeService)"
    - "Planner híbrido: elegibilidade determinística decide ANTES do LLM, reconciliação decide DEPOIS — nunca confia no meio"
    - "Permissão em duas camadas quando hasPermission() curto-circuita admin (molde replicável para outros módulos sob role:admin)"

key-files:
  created:
    - database/migrations/2026_10_03_090000_create_ml_anuncio_criativo_kits_table.php
    - database/migrations/2026_10_03_090100_add_kit_columns_to_ml_anuncio_criativos_table.php
    - app/Models/MlAnuncioCriativoKit.php
    - app/Services/Creative/CreativePermissao.php
    - app/Services/Creative/CreativeSlotCatalog.php
    - app/Services/Creative/CreativePlanner.php
    - app/Services/Creative/Dto/CreativePlan.php
    - app/Services/Creative/Dto/CreativeSlotPlan.php
    - app/Jobs/PlanejarKitCriativosJob.php
    - tests/Unit/Phase161/CreativePlannerTest.php
    - tests/Unit/Phase161/KitMigrationGuardaTest.php
    - tests/Feature/Phase161/CriativoKitPlanejamentoTest.php
    - tests/Feature/Phase161/CriativoPermissaoTest.php
  modified:
    - app/Models/MlAnuncioCriativo.php
    - app/Support/Permissions.php
    - app/Services/Creative/Dto/ProductTruth.php
    - app/Services/Creative/ProductTruthBuilder.php
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/mlb_anuncios.php
    - config/services.php
    - .env.example
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx

key-decisions:
  - "O portador da referência (criativo do upload, 160-01) nunca é reciclado como slot 1: o job cria 7 linhas novas (slot_indice 1..7) e aponta o kit para o portador via criativo_referencia_id; o portador ganha kit_id + slot='referencia' + slot_indice=NULL. A agregação do kit (slots()) filtra whereNotNull('slot_indice') e nunca confunde as duas coisas."
  - "PLAN-02 é híbrido com autoridade determinística final: CreativeSlotCatalog::elegiveis() decide ANTES de falar com o LLM (camada 1), o LLM escolhe/redige em JSON curto (camada 2), e CreativePlanner::reconciliar() não confia em nada do que voltou (camada 3) — tipo fora dos elegíveis, duplicado ou badge que não repita fato do cadastro é descartado antes de gravar."
  - "OPS-04 em duas camadas porque User::hasPermission() curto-circuita true para admin e o grupo de rotas é role:admin: a permission key (mlb.criativos_ia) só passa a valer por si só no dia em que o grupo trocar para permission:mlb.anunciar; até lá, quem de fato restringe é a lista creative_engine_usuarios em configuracoes (vazia = sem restrição, preenchida = só os ids dela, admin incluído)."
  - "Check-then-act do passo 'já existe kit?/criar kit' precisa de Cache::lock() por rascunho_id porque o ShouldBeUnique do job usa a chave do kit_id, que só existe DEPOIS da criação — ajuste aceito do gsd-plan-checker em 2026-10-02, com fallback por LockTimeoutException reaproveitando o kit que a requisição concorrente já criou."
  - "ProductTruth ganhou atributosIds como ÚLTIMO parâmetro com default (fora de paraPrompt()/paraAuditoria()) para o catálogo decidir elegibilidade por id de atributo, nunca por rótulo traduzido em pt-BR — preserva as 10 asserções de forma serializada do ProductTruthBuilderTest da Fase 160."

requirements-completed: [PLAN-01, PLAN-02, PLAN-03, PLAN-04, OPS-04]

duration: ~2h40min
completed: 2026-10-02
---

# Phase 161 Plan 01: Kit dinâmico de 7 — planejamento Summary

**Botão "Planejar kit de 7" que, numa chamada de TEXTO (não de imagem), monta o Product Truth do produto e escolhe dinamicamente os slots 2-7 por um planner híbrido LLM+determinístico — nenhuma imagem é gerada nesta fatia.**

## Performance

- **Duration:** ~2h40min
- **Completed:** 2026-10-02
- **Tasks:** 3/3 completas
- **Files modified:** 22 (13 criados, 9 modificados)

## Accomplishments

- Duas migrations aditivas (tabela de kit + colunas no criativo), sem `enum`, com todas as FKs `nullOnDelete()` tendo `nullable()` explícito e índices nomeados dentro de 64 caracteres — provado por `KitMigrationGuardaTest` lendo o CONTEÚDO dos arquivos, não só rodando a migration no SQLite (que não pega os dois erros de MariaDB que já quebraram deploy aqui antes).
- `CreativeSlotCatalog` garante PLAN-01 (mínimo 7, sempre) e PLAN-03 (nenhum slot sem fato) ao mesmo tempo: 8 tipos puramente visuais são SEMPRE elegíveis (piso), e os 6 tipos com fato só entram quando o Product Truth sustenta o requisito fechado (id de atributo ou contagem mínima) — nunca por substring em texto livre.
- `CreativePlanner` prova PLAN-02/03 com o teste mais importante do plano: um produto SEM atributo de dimensão nunca recebe o slot `dimensions`, mesmo que o LLM o proponha explicitamente — a reconciliação descarta antes de gravar. Falha do provedor de texto (ou JSON inválido) nunca propaga exceção: o plano sai 100% determinístico, com 7 slots, `origem=deterministico`.
- `CreativePermissao` prova OPS-04 por teste: um ADMIN fora de uma lista `creative_engine_usuarios` não vazia é barrado — a prova de que `role:admin` sozinho não basta, mesmo com `User::hasPermission()` curto-circuitando `true` para qualquer admin.
- `PlanejarKitCriativosJob` roda na fila `creative` (nunca `high`/`default`), grava o plano e cria os 7 criativos numa única transação, e o teste mais crítico do plano (`CriativoKitPlanejamentoTest::test_lock_impede_dois_kits_para_o_mesmo_rascunho_sob_corrida`) segura o `Cache::lock()` DIRETAMENTE para provar que a tentativa concorrente devolve o kit já existente em vez de inserir uma segunda linha — exatamente o ajuste apontado pelo `gsd-plan-checker` em 2026-10-02.
- `PainelCriativosIa.jsx` ganha o botão "Planejar kit de 7" e a grade dos 7 slots, sem nenhuma linha nova em `AnunciarML.jsx` (confirmado por `git diff --stat` vazio) — o fluxo de 1 imagem da Fase 160 continua intacto e funcionando.

## Task Commits

1. **Task 1: Migration aditiva do kit, model do kit e permissão explícita** - `844172fb` (feat)
2. **Task 2: Catálogo de slots e CreativePlanner — escolha dinâmica com reconciliação determinística** - `22c12c96` (feat)
3. **Task 3: Job de planejamento, endpoints e o painel listando os 7 slots** - `c4ae2974` (feat)

_Nenhuma task foi TDD formal (RED/GREEN separados) — plano não marcou `tdd="true"` em nenhuma task; testes foram escritos e verificados junto com a implementação de cada task, todos verdes antes do commit._

## Files Created/Modified

- `database/migrations/2026_10_03_090000_create_ml_anuncio_criativo_kits_table.php` - tabela de agregação do kit
- `database/migrations/2026_10_03_090100_add_kit_columns_to_ml_anuncio_criativos_table.php` - `kit_id`/`slot_indice`/`slot_plano` aditivos
- `app/Models/MlAnuncioCriativoKit.php` - estados, `LIMITE_MINUTOS=25`, `recalcularStatus()`, tetos de custo/regeneração, agregação por slot
- `app/Models/MlAnuncioCriativo.php` - `kit()`, `portadorDeReferencia()`, `kit_id`/`slot_indice`/`slot_plano` no fillable/casts
- `app/Support/Permissions.php` - `MLB_CRIATIVOS_IA`
- `app/Services/Creative/CreativePermissao.php` - 2ª camada de OPS-04 (permission key + lista `creative_engine_usuarios`)
- `app/Services/Creative/CreativeSlotCatalog.php` - 14 tipos de slot, elegibilidade determinística (PLAN-03)
- `app/Services/Creative/CreativePlanner.php` - 3 camadas (elegibilidade → LLM → reconciliação)
- `app/Services/Creative/Dto/CreativePlan.php` / `Dto/CreativeSlotPlan.php` - DTOs do plano
- `app/Services/Creative/Dto/ProductTruth.php` / `ProductTruthBuilder.php` - `atributosIds` (último parâmetro, com default)
- `app/Jobs/PlanejarKitCriativosJob.php` - planejamento fora da request, fila `creative`
- `app/Http/Controllers/MlbAnuncioController.php` - `criativoKitPlanejar()`, `criativoKitStatus()`, `checarEscopoDoRascunho()` extraído
- `routes/mlb_anuncios.php` - `criativo.kit.planejar` (throttle:6,1), `criativo.kit.status`
- `config/services.php` / `.env.example` - sub-bloco `creative.kit` com defaults medidos no spike
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` - botão "Planejar kit de 7" + grade dos slots
- `tests/Unit/Phase161/KitMigrationGuardaTest.php` / `CreativePlannerTest.php` - guardas de migration + PLAN-01/02/03
- `tests/Feature/Phase161/CriativoPermissaoTest.php` / `CriativoKitPlanejamentoTest.php` - OPS-04 + fluxo completo do kit + lock

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: portador nunca é slot (Decisão 1b), planner híbrido com autoridade determinística final (Decisão 2), OPS-04 em duas camadas por causa do curto-circuito de admin (Decisão 4), lock por `rascunho_id` no check-then-act do kit (ajuste do checker), e `ProductTruth::atributosIds` como último parâmetro para não quebrar a forma serializada da Fase 160.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 4 → resolvido sem mudança arquitetural] Teste de OPS-04 com não-admin removido do `CriativoKitPlanejamentoTest`**
- **Found during:** Task 3, ao rodar a suíte pela primeira vez
- **Issue:** Escrevi um teste (`test_usuario_com_permissao_via_setor_consegue_planejar`) esperando que um usuário não-admin, com a permission `mlb.criativos_ia` concedida via setor, conseguisse chamar `criativoKitPlanejar` com 202. Ele falhou com 403 — porque o GRUPO DE ROTAS (`routes/mlb_anuncios.php`) ainda está sob `role:admin` (hipótese documentada no próprio cabeçalho do arquivo: a troca para `permission:mlb.anunciar` é futura). Um não-admin nunca alcança o controller hoje, independente de qualquer permission key.
- **Fix:** Removido o teste (e os imports/helper que só ele usava — `Setor`, `SetorPermissao`, `Permissions`, `userComPermissao()`). A prova de que a permission key passa a valer SOZINHA no dia da troca de middleware já está coberta corretamente em `CriativoPermissaoTest` (nível de serviço, sem depender do gate de rota). O comportamento do sistema não mudou — era o teste que estava testando um cenário que a arquitetura atual não permite.
- **Files modified:** `tests/Feature/Phase161/CriativoKitPlanejamentoTest.php`
- **Verification:** Suíte completa de `Phase161` verde (29/29) depois da remoção.
- **Commit:** `c4ae2974` (o teste nunca foi commitado isolado — a remoção aconteceu antes do commit da Task 3)

---

**Total deviations:** 1 (ajuste de teste, sem impacto em código de produção)
**Impact on plan:** Nenhum — o ajuste corrigiu uma expectativa errada do próprio teste, escrita por mim ao interpretar OPS-04 de forma mais ampla do que a arquitetura atual permite. Nenhuma linha de `app/` foi alterada por causa disso.

### Divergência de documentação (não é bug)

O `<done>` da Task 3 e a nota do `160-02-SUMMARY.md` descrevem a contagem de rotas de criativo da Fase 160 como "5" (referencia, referencia.ver, gerar, status, imagem). Essa contagem já estava defasada ANTES deste plano: a 160-03 acrescentou `criativo.aprovar`, levando o total da Fase 160 para 6. Este plano acrescenta exatamente 2 rotas novas (`criativo.kit.planejar`, `criativo.kit.status`), então o total real e correto depois deste plano é **8** (6 + 2), não 7 como o texto da Task 3 diz literalmente. Confirmado por `php artisan route:list --name=mlb.anuncios.criativo` → 8 rotas, todas as esperadas, nenhuma faltando ou duplicada. Não é uma regressão nem um comportamento incorreto — é só a aritmética do texto do plano estar um passo atrás da Fase 160-03.

## Known Stubs

Nenhum. O botão "Gerar imagem com IA" (fluxo de 1 imagem, Fase 160) permanece intocado e funcional — a Fase 161 roda em paralelo a ele nesta fatia, sem substituí-lo (a troca de destaque é objetivo do 161-02).

## Threat Flags

Nenhum além do já registrado no `<threat_model>` do `161-01-PLAN.md` (T-161-01 a T-161-08, T-161-SC) — a implementação seguiu as mitigações descritas ali: `role:admin` + double-check de empresa + `CreativePermissao::exigir()` nesta ordem (T-161-01), `throttle:6,1` + idempotência de kit + `ShouldBeUnique` (T-161-02), reconciliação determinística obrigatória (T-161-03), só campos conhecidos no prompt via `ProductTruth` (T-161-04), whitelist estrita na resposta de status (T-161-05), log só com slots/origem/modelo/latência (T-161-06), plano/origem/modelo/latência/tentativas gravados para auditoria (T-161-07), `LIMITE_MINUTOS=25` com `encerrarSeTravado()` (T-161-08). Nenhum pacote novo foi instalado (T-161-SC).

## Verification Results

1. `tests/Unit/Phase161 tests/Feature/Phase161` — **29/29 verde** (124 assertions).
2. `phpunit --filter=Criativo` (Fase 160 + 161 juntas) — **59/59 verde** (269 assertions) — nenhuma regressão de schema nem de endpoint.
3. `phpunit --filter=GeminiImageProviderTest` (spike) — **11/11 verde**, intocado.
4. `artisan migrate --pretend` — as duas migrations novas aparecem; **0** ocorrências de `enum`.
5. `grep -rn "payload" app/Services/Creative/ | grep -v CreativeContextBuilder | wc -l` → **0** (fronteira CTX-02 intacta).
6. `npm run build` — concluído sem erro.
7. `git diff --stat resources/js/Pages/Mlb/AnunciarML.jsx` — **vazio**.
8. `grep -c "onQueue('creative')" app/Jobs/PlanejarKitCriativosJob.php` → **1**; `grep -cE 'public \$queue|protected \$queue'` → **0**.
9. `artisan route:list --name=mlb.anuncios.criativo` → **8 rotas** (6 da Fase 160 + 2 novas — ver nota de divergência de documentação acima).
10. Regressão adicional (fora do `<verification>` do plano, por segurança, mesmo escopo das Summaries anteriores): `UploadImagemTest` + `AnuncioIaAnaliseTest` + `MeusAnunciosTest` + `RascunhosMeusAnunciosTest` — **48/48 verde** (163 assertions).

## Self-Check: PASSED

- `database/migrations/2026_10_03_090000_create_ml_anuncio_criativo_kits_table.php` — FOUND
- `database/migrations/2026_10_03_090100_add_kit_columns_to_ml_anuncio_criativos_table.php` — FOUND
- `app/Models/MlAnuncioCriativoKit.php` — FOUND
- `app/Services/Creative/CreativePermissao.php` — FOUND
- `app/Services/Creative/CreativeSlotCatalog.php` — FOUND
- `app/Services/Creative/CreativePlanner.php` — FOUND
- `app/Jobs/PlanejarKitCriativosJob.php` — FOUND
- `tests/Unit/Phase161/CreativePlannerTest.php` — FOUND
- `tests/Unit/Phase161/KitMigrationGuardaTest.php` — FOUND
- `tests/Feature/Phase161/CriativoKitPlanejamentoTest.php` — FOUND
- `tests/Feature/Phase161/CriativoPermissaoTest.php` — FOUND
- Commit `844172fb` — FOUND (git log)
- Commit `22c12c96` — FOUND (git log)
- Commit `c4ae2974` — FOUND (git log)

---
*Phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k*
*Completed: 2026-10-02*
